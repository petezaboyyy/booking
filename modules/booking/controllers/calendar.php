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
            $roomFilter = $request->get('room')->topic();
            if (empty($roomFilter)) {
                $roomFilter = $request->get('q')->topic();
            }

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
                    'R.course_code',
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
                ->where($where);

            if (!empty($roomFilter)) {
                $tokens = array_filter(preg_split('/\s+/', trim($roomFilter)));
                if (!empty($tokens)) {
                    foreach ($tokens as $token) {
                        $search = '%'.$token.'%';
                        $query->where([
                            ['RoomNumber.value', 'LIKE', $search],
                            ['Room.name', 'LIKE', $search]
                        ], 'OR');
                    }
                }
            }

            $query->orderBy('O.begin');
            $rows = $query->fetchAll();

            // Staff identity only for signed-in users: this endpoint also serves
            // the public calendar (see Booking\View\Controller for the same rule).
            $approvers = $this->authenticateRequest($request)
                ? \Booking\Booking\Model::getApproverNames(array_map(static function ($row) {
                    return (int) $row->id;
                }, $rows))
                : [];

            // The category filter compares an event's `description` with the
            // category's current name. A booking stores the name it was made
            // under, so renaming a category would hide its older bookings from
            // that filter. Resolve the name through the stable category id the
            // booking also stores; fall back to the stored text if it has none.
            $categories = Helper::getTopicOptions();
            $categoryNames = [];
            foreach ($categories as $category) {
                if ($category['category_id'] !== '') {
                    $categoryNames[$category['category_id']] = $category['value'];
                }
            }
            $useByReservation = [];
            $reservationIds = array_values(array_unique(array_map(static function ($row) {
                return (int) $row->id;
            }, $rows)));
            if (!empty($categoryNames) && !empty($reservationIds)) {
                $useRows = \Kotchasan\Model::createQuery()
                    ->select('reservation_id', 'value')
                    ->from('reservation_data')
                    ->where([
                        ['reservation_id', $reservationIds],
                        ['name', 'use']
                    ])
                    ->fetchAll();
                foreach ($useRows as $useRow) {
                    $useByReservation[(int) $useRow->reservation_id] = (string) $useRow->value;
                }
            }

            $events = [];
            foreach ($rows as $item) {
                $categoryName = $categoryNames[$useByReservation[(int) $item->id] ?? ''] ?? null;
                $label = !empty($item->room_number) ? $item->room_number : $item->room_name;
                $events[] = [
                    // The calendar looks events up by id, so it must be unique
                    // per occurrence — a recurring request shares one R.id
                    // across every date.
                    'id' => (string) $item->occurrence_id,
                    'occurrenceId' => (string) $item->occurrence_id,
                    'reservationId' => (string) $item->id,
                    'room_number' => (string) ($item->room_number ?? ''),
                    'room_name' => (string) ($item->room_name ?? ''),
                    'member_name' => (string) ($item->member_name ?? ''),
                    'approver_name' => $approvers[(int) $item->id] ?? '',
                    'course_code' => trim((string) ($item->course_code ?? '')),
                    'title' => $label.', '.substr((string) $item->begin, 11, 5).'-'.substr((string) $item->end, 11, 5),
                    // The calendar's category filter matches on this id.
                    'categoryId' => $useByReservation[(int) $item->id] ?? '',
                    'description' => $categoryName ?? (string) $item->topic,
                    'start' => $item->begin,
                    'end' => $item->end,
                    'status' => (int) $item->status,
                    'scheduleType' => 'occurrence',
                    'allDay' => false,
                    'color' => $item->room_color ?: '#4285F4'
                ];
            }

            return $this->successResponse([
                'data' => $events,
                // The category filter dropdown needs the real "use" category
                // list (e.g. การเรียน/ประชุม/สัมมนา/จัดเลี้ยง), not whatever
                // one-off text has ended up as a booking's topic - the same
                // options the booking form's Topic field uses, so the value
                // stored in `description` above always matches an option here.
                'categories' => $categories
            ], 'Calendar data retrieved')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
