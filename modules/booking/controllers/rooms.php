<?php
/**
 * @filesource modules/booking/controllers/rooms.php
 */

namespace Booking\Rooms;

use Booking\Helper\Controller as Helper;
use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

class Controller extends \Gcms\Table
{
    /**
     * Allowed sort columns.
     *
     * @var array
     */
    protected $allowedSortColumns = [
        'id',
        'name',
        'number',
        'building',
        'seats',
        'is_active'
    ];

    /**
     * Authorization for room management.
     *
     * @param Request $request
     * @param object $login
     *
     * @return mixed
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, ['can_manage_booking', 'can_config'])) {
            return $this->errorResponse('Permission required', 403);
        }

        return true;
    }

    /**
     * Query data to send to DataTable.
     *
     * @param array $params
     * @param object|null $login
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    protected function toDataTable($params, $login = null)
    {
        return Model::toDataTable($params, $login);
    }

    /**
     * Append the first room image to each row.
     *
     * @param array $datas
     * @param object|null $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        foreach ($datas as $item) {
            $item->first_image_url = \Booking\Helper\Controller::getRoomFirstImageUrl((int) $item->id);
            if (isset($item->seats)) {
                $rawSeats = (string) $item->seats;
                $item->seats = preg_match('/^\s*(\d+)/', $rawSeats, $m) ? $m[1] : trim(preg_replace('/(\s*(ที่นั่ง|คน|seats?))+/iu', '', $rawSeats));
            }
        }

        return $datas;
    }

    /**
     * Handle edit action.
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function handleEditAction(Request $request, $login)
    {
        if (!ApiController::hasPermission($login, ['can_manage_booking', 'can_config'])) {
            return $this->errorResponse('Failed to process request', 403);
        }

        $id = $request->post('id')->toInt();
        if (!Helper::isRoomInBookingScope($login, $id)) {
            return $this->errorResponse('Permission required', 403);
        }

        $room = \Booking\Room\Model::get($id);
        if ($room === null) {
            return $this->errorResponse('No data available', 404);
        }

        return $this->successResponse([
            'data' => (array) $room,
            'actions' => [
                'type' => 'modal',
                'template' => 'booking/room.html',
                'title' => ($room->id > 0 ? '{LNG_Edit} {LNG_Room}' : '{LNG_Add} {LNG_Room}'),
                'titleClass' => 'icon-office'
            ]
        ], 'Room details retrieved');
    }

    /**
     * Handle delete action.
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleDeleteAction(Request $request, $login)
    {
        if (!ApiController::canModify($login, ['can_manage_booking', 'can_config'])) {
            return $this->errorResponse('Failed to process request', 403);
        }

        $ids = $request->request('ids', [])->toInt();
        foreach ($ids as $id) {
            if (!Helper::isRoomInBookingScope($login, (int) $id)) {
                return $this->errorResponse('Permission required', 403);
            }
        }
        $removeCount = \Booking\Room\Model::remove($ids);
        if (empty($removeCount)) {
            return $this->errorResponse('Delete action failed', 400);
        }

        \Index\Log\Model::add(0, 'booking', 'Delete', 'Deleted room ID(s): '.implode(', ', $ids), $login->id);

        // Broadcast real-time update via Pusher
        \Booking\Pusher\Model::trigger('private-booking', 'updated', [
            'action' => 'room_delete',
            'ids' => $ids,
            'message' => 'Room deleted'
        ]);

        return $this->redirectResponse('reload', \Kotchasan\Language::replace('Deleted :count room(s) successfully', [':count' => $removeCount]));
    }

    /**
     * Handle active action.
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleActiveAction(Request $request, $login)
    {
        if (!ApiController::canModify($login, ['can_manage_booking', 'can_config'])) {
            return $this->errorResponse('Failed to process request', 403);
        }

        $id = $request->post('id')->toInt();
        if (!Helper::isRoomInBookingScope($login, $id)) {
            return $this->errorResponse('Permission required', 403);
        }

        $room = \Booking\Room\Model::toggleActive($id);
        if ($room === null) {
            return $this->errorResponse('Room not found', 404);
        }

        $msg = (int) $room->is_active === 1 ? 'Activated: '.$room->name : 'Deactivated: '.$room->name;
        \Index\Log\Model::add($room->id, 'booking', 'Active', $msg, $login->id);

        // Broadcast real-time update via Pusher
        \Booking\Pusher\Model::trigger('private-booking', 'updated', [
            'action' => 'room_status',
            'id' => $room->id,
            'is_active' => (int) $room->is_active,
            'message' => $msg
        ]);

        return $this->redirectResponse('reload', $msg, 200, 0, 'table');
    }
}
