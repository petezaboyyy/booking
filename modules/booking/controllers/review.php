<?php
/**
 * @filesource modules/booking/controllers/review.php
 */

namespace Booking\Review;

use Booking\Booking\Model as BookingModel;
use Booking\Helper\Controller as Helper;
use Gcms\Api as ApiController;
use Kotchasan\Date;
use Kotchasan\Http\Request;
use Kotchasan\Language;

class Controller extends ApiController
{
    /**
     * Get review details.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function get(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!Helper::canApproveRequests($login)) {
                return $this->errorResponse('Forbidden', 403);
            }

            $row = Model::get($request->get('id')->toInt());
            if ($row === null) {
                return $this->redirectResponse('/404', 'No data available', 404);
            }
            if (!Helper::isRoomInBookingScope($login, (int) $row->room_id)) {
                return $this->errorResponse('Forbidden', 403);
            }

            $canProcess = Model::canProcess($row);
            $canApproveAction = $canProcess && Helper::canApproveStep($login, $row);
            $occurrences = array_map(static function ($occurrence) {
                return [
                    'id' => (int) $occurrence->id,
                    'date' => substr((string) $occurrence->begin, 0, 10),
                    'begin_time' => substr((string) $occurrence->begin, 11, 5),
                    'end_time' => substr((string) $occurrence->end, 11, 5),
                    'status' => (int) $occurrence->status,
                    'status_text' => Helper::getStatusLabel((int) $occurrence->status),
                    'cancellation_reason' => (string) ($occurrence->cancellation_reason ?? ''),
                    'can_cancel' => (int) $row->status === Helper::STATUS_APPROVED
                        && (int) $occurrence->status === Helper::STATUS_APPROVED
                ];
            }, BookingModel::getOccurrences((int) $row->id));

            return $this->successResponse([
                'id' => (int) $row->id,
                'member_name' => (string) $row->member_name,
                'room_name' => (string) $row->room_name,
                'room_number' => (string) ($row->room_number ?? ''),
                'reason' => (string) $row->reason,
                'topic' => (string) $row->topic,
                'comment' => (string) $row->comment,
                'attendees' => (int) $row->attendees,
                'reservation_text' => Helper::formatBookingTime($row),
                'begin_text' => Date::format($row->begin, 'd M Y H:i'),
                'end_text' => Date::format($row->end, 'd M Y H:i'),
                'status_text' => Helper::getStatusText($row),
                'use_text' => (string) $row->use_text,
                'accessories_text' => (string) $row->accessories_text,
                'approval_reason' => (string) $row->reason,
                'canProcess' => $canProcess,
                'canApproveAction' => $canApproveAction,
                'canCancelAction' => Helper::canCancelBookingByOfficer($row),
                'version' => (int) ($row->version ?? 1),
                'occurrence_count' => count($occurrences),
                'occurrences' => $occurrences
            ], 'Review data retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Approve reservation.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function approve(Request $request)
    {
        return $this->processDecision($request, Helper::STATUS_APPROVED, 'Approved room booking', false);
    }

    /**
     * Reject reservation.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function reject(Request $request)
    {
        return $this->processDecision($request, Helper::STATUS_REJECTED, 'Rejected room booking', true);
    }

    /**
     * Return reservation for correction.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function returnedit(Request $request)
    {
        return $this->processDecision($request, Helper::STATUS_RETURNED_FOR_EDIT, 'Returned room booking for correction', true);
    }

    /**
     * Cancel reservation by officer.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function cancelofficer(Request $request)
    {
        return $this->processDecision($request, Helper::STATUS_CANCELLED_BY_OFFICER, 'Cancelled room booking by officer', true, true);
    }

    /**
     * Cancel one date from an approved recurring reservation.
     */
    public function canceloccurrence(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);
            $login = $this->authenticateRequest($request);
            if (!$login || !Helper::canApproveRequests($login)) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->post('id')->toInt();
            $occurrenceId = $request->post('occurrence_id')->toInt();
            $version = $request->post('version')->toInt();
            $reason = trim($request->post('approval_reason')->textarea());
            if ($reason === '') {
                return $this->errorResponse('Decision note is required', 400);
            }
            if (mb_strlen($reason) > Helper::MAX_CANCELLATION_REASON_LENGTH) {
                return $this->errorResponse(Language::replace(
                    'The decision note must not exceed :length characters',
                    [':length' => Helper::MAX_CANCELLATION_REASON_LENGTH]
                ), 400);
            }
            if (!BookingModel::cancelOccurrence($id, $occurrenceId, (int) $login->id, $reason, $version)) {
                return $this->errorResponse('No data available', 404);
            }

            \Index\Log\Model::add($id, 'booking', 'Cancel date', 'Cancelled one booking date: '.$occurrenceId, $login->id, $reason, [
                'occurrence_id' => $occurrenceId
            ]);
            \Booking\Pusher\Model::trigger('private-booking', 'updated', [
                'action' => 'cancel-occurrence',
                'id' => $id,
                'occurrence_id' => $occurrenceId,
                'message' => 'Booking date cancelled'
            ]);

            return $this->redirectResponse('/booking-review?id='.$id, 'Booking date cancelled');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Shared decision handler.
     *
     * @param Request $request
     * @param int $status
     * @param string $logTopic
     * @param bool $requireReason
     * @param bool $allowOfficerCancel
     *
     * @return \Kotchasan\Http\Response
     */
    protected function processDecision(Request $request, int $status, string $logTopic, bool $requireReason, bool $allowOfficerCancel = false)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            if (!Helper::canApproveRequests($login)) {
                return $this->errorResponse('Permission required', 403);
            }

            $id = $request->post('id')->toInt();
            $approvalReason = trim($request->post('approval_reason')->textarea());
            $expectedVersion = $request->post('version')->toInt();

            $row = Model::get($id);
            if ($row === null) {
                return $this->errorResponse('No data available', 404);
            }
            if (!Helper::isRoomInBookingScope($login, (int) $row->room_id)) {
                return $this->errorResponse('Permission required', 403);
            }

            if ($allowOfficerCancel) {
                if (!Helper::canCancelBookingByOfficer($row)) {
                    return $this->errorResponse('This booking can no longer be cancelled', 400);
                }
            }
            if (!$allowOfficerCancel && !Model::canProcess($row)) {
                return $this->errorResponse('This booking has already been processed', 400);
            }
            if (!$allowOfficerCancel && !Helper::canApproveStep($login, $row)) {
                return $this->errorResponse('You are not allowed to approve this step', 403);
            }

            if ($requireReason && $approvalReason === '') {
                return $this->errorResponse('Decision note is required', 400);
            }
            if (mb_strlen($approvalReason) > Helper::MAX_CANCELLATION_REASON_LENGTH) {
                return $this->errorResponse(Language::replace(
                    'The decision note must not exceed :length characters',
                    [':length' => Helper::MAX_CANCELLATION_REASON_LENGTH]
                ), 400);
            }

            $currentApprove = (int) ($row->approve ?? 1);
            $closedLevel = (int) ($row->closed ?? 1);

            $save = [];
            $finalStatus = $status;
            if ($status === Helper::STATUS_APPROVED) {
                if ($currentApprove >= $closedLevel || \Gcms\Api::isAdmin($login)) {
                    $save['approve'] = $closedLevel;
                } else {
                    $finalStatus = Helper::STATUS_PENDING_REVIEW;
                    $nextApprove = Helper::getNextApprovalStep($currentApprove);
                    $save['approve'] = $nextApprove > 0 ? $nextApprove : $closedLevel;
                }
                $save['reason'] = '';
            } else {
                $save['approve'] = $currentApprove;
                $save['reason'] = $approvalReason;
            }

            if ($finalStatus === Helper::STATUS_APPROVED) {
                $conflicts = BookingModel::approveReservation($id, $expectedVersion, $save);
                if (!empty($conflicts)) {
                    return $this->errorResponse($this->formatConflictMessage($conflicts), 409);
                }
            } else {
                BookingModel::updateStatus($id, $finalStatus, $save, [], $expectedVersion);
            }
            \Index\Log\Model::add($id, 'booking', 'Status', $logTopic.': '.$id, $login->id, $approvalReason, [
                'status' => $finalStatus
            ]);

            // Broadcast real-time update via Pusher
            \Booking\Pusher\Model::trigger('private-booking', 'updated', [
                'action' => 'review',
                'id' => $id,
                'status' => $finalStatus,
                'message' => 'Booking status updated'
            ]);

            $message = \Booking\Email\Controller::sendByBookingId($id);

            return $this->redirectResponse('/approvals', $message);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    protected function formatConflictMessage(array $conflicts): string
    {
        $slots = array_map(static function ($conflict) {
            return substr($conflict['begin'], 0, 10)
                .' '.substr($conflict['begin'], 11, 5)
                .'-'.substr($conflict['end'], 11, 5);
        }, array_slice($conflicts, 0, 10));
        return Language::get('Cannot approve because these dates are already booked').': '.implode(', ', $slots);
    }
}
