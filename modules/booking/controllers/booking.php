<?php
/**
 * @filesource modules/booking/controllers/booking.php
 */

namespace Booking\Booking;

use Booking\Helper\Controller as Helper;
use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Language;

class Controller extends ApiController
{
    /**
     * Get booking details.
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

            $data = Model::get(
                $login,
                $request->get('id')->toInt(),
                $request->get('room_id')->toInt()
            );
            if ($data === null) {
                return $this->redirectResponse('/404', 'No data available', 404);
            }

            $includeRoomId = !empty($data->room_id) ? (int) $data->room_id : null;

            $data->options = [
                'room_id' => Helper::getRoomOptions(true, $includeRoomId),
                'use' => Helper::getUseOptions(),
                'accessories' => Helper::getAccessoryOptions(),
                'recurrence_days' => Helper::getWeekdayOptions()
            ];

            return $this->successResponse($data, 'Booking details retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

/**
 * Save booking.
 *
 * @param Request $request
 *
 * @return \Kotchasan\Http\Response
 */
    public function save(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            // Parse input data
            $save = $this->parseInput($request);

            // Check if booking exists and user has permission to edit
            $booking = Model::get($login, $request->post('id')->toInt());
            if (!$booking) {
                return $this->errorResponse('No data available', 404);
            }

            // If booking exists, check if it can be edited
            if (!$booking->canEdit) {
                return $this->errorResponse('This booking can no longer be edited', 403);
            }
            // Validate input data and prepare save data
            $errors = $this->validateAndPrepareSaveData($save, $booking);
            if (!empty($errors)) {
                // Error response
                return $this->formErrorResponse($errors, 400);
            }

            $meta = [
                'use' => $save['use'],
                'accessories' => array_values(array_filter(array_map('intval', $save['accessories']))),
                'recurrence_days' => $save['recurrence_days'],
                'excluded_dates' => $save['excluded_dates']
            ];
            unset($save['use'], $save['accessories'], $save['recurrence_days'], $save['excluded_dates']);

            $result = Model::saveReservation($booking->id, $save, $meta);
            if (!empty($result['conflicts'])) {
                return $this->formErrorResponse([
                    'begin_date' => $this->formatConflictMessage($result['conflicts'])
                ], 409);
            }
            $id = (int) $result['id'];
            \Index\Log\Model::add($id, 'booking', 'Save', 'Saved room booking: '.$id, $login->id);

            // Broadcast real-time update via Pusher
            \Booking\Pusher\Model::trigger('private-booking', 'updated', [
                'action' => 'save',
                'id' => $id,
                'message' => 'New booking submitted'
            ]);

            if ($booking->id === 0 || $booking->status !== $save['status']) {
                $message = \Booking\Email\Controller::sendByBookingId($id);
            } else {
                $message = 'Saved successfully';
            }

            return $this->redirectResponse('/my-bookings', $message);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Parse user input from request
     *
     * @param Request $request
     *
     * @return array
     */
    protected function parseInput(Request $request): array
    {
        return [
            'begin_date' => $request->post('begin_date')->date(),
            'begin_time' => $request->post('begin_time')->time(),
            'end_date' => $request->post('end_date')->date(),
            'end_time' => $request->post('end_time')->time(),
            'schedule_type' => $request
                ->post('schedule_type')
                ->topic(),

            'recurrence_days' => $request
                ->post('recurrence_days', [])
                ->toInt(),
            'excluded_dates' => Model::normalizeExcludedDates(
                $request->post('excluded_dates')->toString()
            ),
            'version' => $request->post('version')->toInt(),
            'accessories' => $request->post('accessories', [])->toInt(),
            'comment' => $request->post('comment')->textarea(),
            'topic' => $request->post('topic')->topic(),
            'attendees' => $request->post('attendees')->toInt(),
            'room_id' => $request->post('room_id')->toInt(),
            'use' => $request->post('use')->topic()
        ];
    }

    /**
     * Validate booking input and prepare the save payload.
     *
     * @param array &$save Save data (modified by reference)
     * @param object $booking Existing booking
     *
     * @return array Validation errors, empty if valid
     */
    protected function validateAndPrepareSaveData(&$save, $booking)
    {
        $errors = [];
        if ($save['room_id'] <= 0) {
            $errors['room_id'] = 'Please select';
        }
        if ($save['attendees'] <= 0) {
            $errors['attendees'] = 'Please fill in';
        }
        if ($save['topic'] === '') {
            $errors['topic'] = 'Please fill in';
        }
        if ($save['begin_date'] === '') {
            $errors['begin_date'] = 'Please fill in';
        }
        if ($save['begin_time'] === '') {
            $errors['begin_time'] = 'Please fill in';
        }
        if ($save['end_date'] === '') {
            $errors['end_date'] = 'Please fill in';
        }
        if ($save['end_time'] === '') {
            $errors['end_time'] = 'Please fill in';
        }

        if (empty($errors)) {
            if ($save['end_date'] >= $save['begin_date']) {
                if ($save['end_time'] <= $save['begin_time']) {
                    $errors['end_time'] = 'End time must be greater than begin time';
                }

                // Every day in the range becomes an occurrence row and a day of
                // loop work, so cap the span before anything expands it.
                $rangeDays = Helper::countBookingRangeDays($save['begin_date'], $save['end_date']);
                if ($rangeDays < 1 || $rangeDays > Helper::MAX_BOOKING_RANGE_DAYS) {
                    $errors['end_date'] = Language::replace(
                        'The reservation period must not exceed :days days',
                        [':days' => Helper::MAX_BOOKING_RANGE_DAYS]
                    );
                }

                if (!empty($errors)) {
                    return $errors;
                }

                $save['begin'] = $save['begin_date'].' '.$save['begin_time'].':00';
                $save['end'] = $save['end_date'].' '.$save['end_time'].':00';

                $save['schedule_type'] = Helper::normalizeScheduleType(
                    $save['schedule_type'],
                    Helper::SCHEDULE_DAILY_SLOT
                );

                if (!in_array($save['schedule_type'], [
                    Helper::SCHEDULE_DAILY_SLOT,
                    Helper::SCHEDULE_WEEKLY_SLOT
                ], true)) {
                    $save['schedule_type'] = Helper::SCHEDULE_DAILY_SLOT;
                }

                $save['recurrence_days'] = Helper::normalizeRecurrenceDays(
                    $save['recurrence_days']
                );

                if ($save['schedule_type'] === Helper::SCHEDULE_WEEKLY_SLOT) {
                    if (empty($save['recurrence_days'])) {
                        $errors['recurrence_days'] = 'Please select';

                        return $errors;
                    }
                } else {
                    $save['recurrence_days'] = [];
                }

                $schedule = Helper::buildBookingSchedule(
                    $save['begin'],
                    $save['end'],
                    $save['schedule_type'],
                    $save['recurrence_days']
                );
                if ($schedule === null || !Helper::bookingScheduleHasOccurrence($schedule)) {
                    $errors['recurrence_days'] = 'Selected weekdays do not occur in the reservation period';

                    return $errors;
                }

                $save['excluded_dates'] = Model::normalizeExcludedDates($save['excluded_dates']);
                $occurrences = Helper::buildBookingOccurrences($schedule, $save['excluded_dates']);
                if (empty($occurrences)) {
                    $errors['excluded_dates'] = 'At least one booking date must be selected';

                    return $errors;
                }
                if (count($occurrences) > Helper::MAX_BOOKING_OCCURRENCES) {
                    $errors['end_date'] = Language::replace(
                        'A reservation cannot contain more than :days dates',
                        [':days' => Helper::MAX_BOOKING_OCCURRENCES]
                    );

                    return $errors;
                }

                $save['id'] = $booking->id;
                $save['member_id'] = $booking->member_id;

                unset($save['begin_date']);
                unset($save['begin_time']);
                unset($save['end_date']);
                unset($save['end_time']);

                if ($booking->id === 0) {
                    $save['created_at'] = date('Y-m-d H:i:s');
                }
            } else {
                $errors['end_date'] = 'End date must be greater than or equal to begin date';
            }

            if ($booking->id > 0 && $booking->status === Helper::STATUS_RETURNED_FOR_EDIT) {
                // comes from editing return to pending approval status again.
                $save['status'] = Helper::STATUS_PENDING_REVIEW;
            } else {
                $save['status'] = $booking->status;
                $save['closed'] = $booking->closed;
                $save['approve'] = $booking->approve;
            }
        }

        return $errors;
    }

    /**
     * Explain exactly which requested dates conflict with approved bookings.
     */
    protected function formatConflictMessage(array $conflicts): string
    {
        $slots = array_map(static function ($conflict) {
            return substr($conflict['begin'], 0, 10)
                .' '.substr($conflict['begin'], 11, 5)
                .'-'.substr($conflict['end'], 11, 5);
        }, array_slice($conflicts, 0, 10));
        $suffix = count($conflicts) > 10 ? ' (+'.(count($conflicts) - 10).')' : '';

        return Language::get('Conflicts with an approved booking').': '.implode(', ', $slots).$suffix;
    }

    /**
     * Cancel booking.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function cancel(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            $id = $request->post('id')->toInt();
            $row = Model::getRecord((int) $login->id, $id);
            if ($row === null) {
                return $this->errorResponse('No data available', 404);
            }
            if (!Helper::canCancelBookingByRequester($row)) {
                return $this->errorResponse('This booking can no longer be cancelled', 403);
            }

            Model::updateStatus($id, Helper::STATUS_CANCELLED_BY_REQUESTER);

            // Log cancellation
            \Index\Log\Model::add($id, 'booking', 'Cancel', 'Cancelled room booking: '.$id, $login->id);

            // Broadcast real-time update via Pusher
            \Booking\Pusher\Model::trigger('private-booking', 'updated', [
                'action' => 'cancel',
                'id' => $id,
                'message' => 'Booking cancelled'
            ]);

            // Send notification
            $message = \Booking\Email\Controller::sendByBookingId($id);

            return $this->redirectResponse('/my-bookings', $message);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
