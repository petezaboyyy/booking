<?php
/**
 * @filesource modules/booking/controllers/calendar.php
 */

namespace Booking\Calendar;

use Booking\Helper\Controller as Helper;
use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

class Controller extends ApiController
{
    /**
     * Get reservation events for EventCalendar.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function index(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $start = $request->get('start')->date();
            $end = $request->get('end')->date();

            $where = [
                ['O.begin', '<=', $end.' 23:59:59'],
                ['O.end', '>=', $start.' 00:00:00']
            ];
            // Honour the configured calendar statuses; an empty setting means
            // every status is shown.
            $calendarStatuses = Helper::getCalendarStatuses();
            if (!empty($calendarStatuses)) {
                $where[] = ['O.status', $calendarStatuses];
            }

            $query = \Kotchasan\Model::createQuery()
                ->select(
                    'R.id',
                    'O.id occurrence_id',
                    'O.begin',
                    'O.end',
                    'O.status',
                    'R.topic',
                    'Room.name room_name',
                    'Room.color room_color',
                    'RoomNumber.value room_number',
                    'U.name member_name'
                )
                ->from('reservation R')
                ->join('reservation_occurrence O', ['O.reservation_id', 'R.id'])
                ->join('rooms Room', ['Room.id', 'R.room_id'], 'LEFT')
                ->join('rooms_meta RoomNumber', [['RoomNumber.room_id', 'Room.id'], ['RoomNumber.name', 'number']], 'LEFT')
                ->join('user U', ['U.id', 'R.member_id'], 'LEFT')
                ->where($where)
                ->orderBy('O.begin');

            $events = [];
            foreach ($query->fetchAll() as $item) {
                $label = !empty($item->room_number) ? $item->room_number : $item->room_name;
                $events[] = [
                    // The calendar looks events up by id, so it must be unique
                    // per occurrence — a recurring request shares one R.id
                    // across every date.
                    'id' => (string) $item->occurrence_id,
                    'occurrenceId' => (string) $item->occurrence_id,
                    'reservationId' => (string) $item->id,
                    'title' => $label.', '.substr((string) $item->begin, 11, 5).'-'.substr((string) $item->end, 11, 5),
                    'description' => (string) $item->topic,
                    'start' => $item->begin,
                    'end' => $item->end,
                    'status' => (int) $item->status,
                    'scheduleType' => 'occurrence',
                    'allDay' => false,
                    'color' => $item->room_color ?: '#4285F4'
                ];
            }

            return $this->successResponse([
                'data' => $events
            ], 'Calendar data retrieved')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
