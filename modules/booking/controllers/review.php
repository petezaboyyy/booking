<?php
/**
 * @filesource modules/booking/controllers/review.php
 */

namespace Booking\Review;

use Booking\Booking\Model as BookingModel;
use Booking\Competition\Model as CompetitionModel;
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

            $approvedOccurrences = count(array_filter($occurrences, static function ($occurrence) {
                return $occurrence['status'] === Helper::STATUS_APPROVED;
            }));

            // A pending request whose room and time went to another request can
            // no longer be approved (the server refuses it), so say so and turn
            // the Approve button off instead of letting it fail on click.
            $blocked = (int) $row->status === Helper::STATUS_PENDING_REVIEW ? $this->blockedPayload($row) : null;
            // On the winning request: the others it left behind, with a way to reject them together.
            $clashing = (int) $row->status === Helper::STATUS_APPROVED
                ? CompetitionModel::pendingClashingWith((int) $row->id)
                : [];

            return $this->successResponse([
                'id' => (int) $row->id,
                'member_name' => (string) $row->member_name,
                'room_name' => (string) $row->room_name,
                'room_number' => (string) ($row->room_number ?? ''),
                'reason' => (string) $row->reason,
                'topic' => (string) ($row->use_text !== '' ? $row->use_text : $row->topic),
                'comment' => (string) $row->comment,
                'attendees' => (int) $row->attendees,
                'reservation_text' => Helper::formatBookingTime($row),
                'begin_text' => Date::format($row->begin, 'd M Y H:i'),
                'end_text' => Date::format($row->end, 'd M Y H:i'),
                'status_text' => Helper::getStatusText($row),
                'use_text' => (string) $row->use_text,
                'approval_reason' => (string) $row->reason,
                'canProcess' => $canProcess,
                'canApproveAction' => $canApproveAction,
                // Reject and Return stay available; only Approve is blocked by a clash.
                'canApproveButton' => $canApproveAction && $blocked === null,
                'blocked' => $blocked,
                'clashing' => $clashing,
                'canRejectClashing' => !empty($clashing),
                'canCancelAction' => Helper::canCancelBookingByOfficer($row),
                'version' => (int) ($row->version ?? 1),
                'occurrence_count' => count($occurrences),
                'approved_occurrence_count' => $approvedOccurrences,
                'occurrences' => $occurrences,
                'competition' => $canProcess ? $this->competitionPayload((int) $row->id) : ['seats' => 0, 'items' => []]
            ], 'Review data retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Other pending requests for the same room at overlapping times, ranked by
     * the rules in Booking\Competition\Model. Empty when nothing competes.
     *
     * @param int $id
     *
     * @return array{seats:int,items:array}
     */
    protected function competitionPayload(int $id): array
    {
        $group = CompetitionModel::competitors($id);
        if (empty($group['items'])) {
            return ['seats' => 0, 'items' => []];
        }

        $items = array_map(static function ($item) {
            // Only what the screen shows. The category id is internal.
            unset($item['use_id']);
            return $item;
        }, CompetitionModel::rank($group['items'], $group['seats']));

        return ['seats' => $group['seats'], 'items' => $items];
    }

    /**
     * The dates of a pending request that an approved booking already holds.
     * Null when none do.
     *
     * @param object $row the pending request (Review Model::get)
     *
     * @return array{conflicts:array,approved_ids:int[]}|null
     */
    protected function blockedPayload($row): ?array
    {
        $pending = BookingModel::getOccurrences((int) $row->id, Helper::STATUS_PENDING_REVIEW);
        $conflicts = BookingModel::findApprovedConflicts((int) $row->room_id, array_map(static function ($occurrence) {
            return ['begin' => (string) $occurrence->begin, 'end' => (string) $occurrence->end];
        }, $pending), (int) $row->id);
        if (empty($conflicts)) {
            return null;
        }

        return [
            'conflicts' => array_map(static function ($conflict) {
                return [
                    'date' => $conflict['date'],
                    'begin_time' => substr($conflict['begin'], 11, 5),
                    'end_time' => substr($conflict['end'], 11, 5),
                    'approved_begin_time' => substr($conflict['approved_begin'], 11, 5),
                    'approved_end_time' => substr($conflict['approved_end'], 11, 5),
                    'reservation_id' => $conflict['reservation_id']
                ];
            }, $conflicts),
            'approved_ids' => array_values(array_unique(array_column($conflicts, 'reservation_id')))
        ];
    }

    /**
     * Reject, in one go, the pending requests left behind by an approved booking.
     *
     * Each request goes through the same checks as rejecting it on its own page
     * (still pending, inside the approval window, the approver's step and room
     * scope, not changed by someone else meanwhile). A request that fails a check
     * is skipped and reported, never forced.
     *
     * @param object        $login
     * @param int           $approvedId the approved request that won the room
     * @param string        $reason     shown to each requester as the decision note
     * @param callable|null $after      function(int $id): void, called for every request rejected (log, push, e-mail)
     *
     * @return array{rejected:int[],skipped:array<int,string>}
     */
    protected function rejectPendingClashes($login, int $approvedId, string $reason, ?callable $after = null): array
    {
        $result = ['rejected' => [], 'skipped' => []];
        foreach (CompetitionModel::pendingClashingWith($approvedId) as $item) {
            $id = (int) $item['id'];
            $row = Model::get($id);
            if ($row === null || !Model::canProcess($row)) {
                $result['skipped'][$id] = 'This booking has already been processed';
                continue;
            }
            if (!Helper::isRoomInBookingScope($login, (int) $row->room_id) || !Helper::canApproveStep($login, $row)) {
                $result['skipped'][$id] = 'You are not allowed to approve this step';
                continue;
            }
            try {
                BookingModel::updateStatus($id, Helper::STATUS_REJECTED, [
                    'approve' => (int) ($row->approve ?? 1),
                    'reason' => $reason
                ], [], (int) ($row->version ?? 1));
            } catch (\RuntimeException $e) {
                $result['skipped'][$id] = $e->getMessage();
                continue;
            }
            $result['rejected'][] = $id;
            if ($after !== null) {
                $after($id);
            }
        }

        return $result;
    }

    /**
     * Reject the pending requests that clash with this approved booking.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function rejectconflicts(Request $request)
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
            $reason = trim($request->post('approval_reason')->textarea());
            $row = Model::get($id);
            if ($row === null) {
                return $this->errorResponse('No data available', 404);
            }
            if (!Helper::isRoomInBookingScope($login, (int) $row->room_id)) {
                return $this->errorResponse('Permission required', 403);
            }
            if ((int) $row->status !== Helper::STATUS_APPROVED) {
                return $this->errorResponse('This booking is not approved', 400);
            }
            if ($reason === '') {
                return $this->errorResponse('Decision note is required', 400);
            }
            if (mb_strlen($reason) > Helper::MAX_CANCELLATION_REASON_LENGTH) {
                return $this->errorResponse(Language::replace(
                    'The decision note must not exceed :length characters',
                    [':length' => Helper::MAX_CANCELLATION_REASON_LENGTH]
                ), 400);
            }

            $result = $this->rejectPendingClashes($login, $id, $reason, static function (int $rejectedId) use ($login, $reason, $id) {
                \Index\Log\Model::add($rejectedId, 'booking', 'Status', 'Rejected room booking (room approved for request '.$id.'): '.$rejectedId, $login->id, $reason, [
                    'status' => Helper::STATUS_REJECTED
                ]);
                \Booking\Pusher\Model::trigger('private-booking', 'updated', [
                    'action' => 'review',
                    'id' => $rejectedId,
                    'status' => Helper::STATUS_REJECTED,
                    'message' => 'Booking status updated'
                ]);
                // Tells the requester, the same way a single rejection does.
                \Booking\Email\Controller::sendByBookingId($rejectedId);
            });

            if (empty($result['rejected']) && empty($result['skipped'])) {
                return $this->errorResponse('No clashing requests', 400);
            }
            $message = Language::replace('Rejected :count clashing request(s)', [':count' => count($result['rejected'])]);
            if (!empty($result['skipped'])) {
                $message .= '. '.Language::replace(':count could not be rejected and were left as they are', [':count' => count($result['skipped'])]);
            }

            return $this->redirectResponse('/booking-review?id='.$id, $message);
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
