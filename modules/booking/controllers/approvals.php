<?php
/**
 * @filesource modules/booking/controllers/approvals.php
 */

namespace Booking\Approvals;

use Booking\Booking\Model as BookingModel;
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
    protected $allowedSortColumns = ['member_id', 'room_id', 'begin', 'end', 'created_at', 'status'];

    /**
     * Ensure approver access.
     *
     * @param Request $request
     * @param object $login
     *
     * @return true|\Kotchasan\Http\Response
     */
    protected function checkAuthorization(Request $request, $login)
    {
        if (!Helper::canApproveRequests($login)) {
            return $this->errorResponse('Forbidden', 403);
        }

        return true;
    }

    /**
     * Custom table parameters.
     *
     * @param Request $request
     * @param object $login
     *
     * @return array
     */
    protected function getCustomParams(Request $request, $login): array
    {
        return [
            'member_id' => $request->get('member_id')->filter('0-9'),
            'room_id' => $request->get('room_id')->filter('0-9'),
            'status' => $request->get('status')->filter('0-9'),
            'from' => $request->get('from')->date(),
            'to' => $request->get('to')->date()
        ];
    }

    /**
     * Query
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
     * Filters
     *
     * @param array $params
     * @param object|null $login
     *
     * @return array
     */
    protected function getFilters($params, $login = null)
    {
        return [
            'status' => Helper::getStatusOptions(),
            'room_id' => Helper::getBookingRoomOptions($login),
            'member_id' => \Index\Users\Model::toOptions()
        ];
    }

    /**
     * Format rows for display.
     *
     * @param array $datas
     * @param object|null $login
     *
     * @return array
     */
    protected function formatDatas(array $datas, $login = null): array
    {
        $summaries = BookingModel::getOccurrenceSummaries(array_map(static function ($item) {
            return (int) ($item->id ?? 0);
        }, $datas));

        foreach ($datas as $item) {
            $item->first_image_url = Helper::getRoomFirstImageUrl((int) ($item->room_id ?? 0));
            $item->reservation_text = Helper::formatBookingTime($item);
            $summary = $summaries[(int) $item->id] ?? ['active' => 0, 'total' => 0];
            $item->occurrence_summary = $summary['active'].' / '.$summary['total'];
        }

        return $datas;
    }

    /**
     * Runtime table options.
     *
     * @param array $params
     * @param object|null $login
     *
     * @return array
     */
    protected function getOptions(array $params, $login)
    {
        $isSuperAdmin = ApiController::isSuperAdmin($login);
        $canManageBookings = $isSuperAdmin || ApiController::hasPermission($login, 'can_manage_booking', false);

        return [
            '_table' => [
                'showCheckbox' => $canManageBookings,
                'actions' => $canManageBookings ? [
                    'delete' => 'Delete'
                ] : [],
                'actionButton' => $canManageBookings ? 'Process|btn-success' : null
            ]
        ];
    }

    /**
     * Review action.
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleReviewAction(Request $request, $login)
    {
        $id = $request->post('id')->toInt();
        if ($id <= 0 || !Helper::canApproveRequests($login)) {
            return $this->errorResponse('Permission required', 403);
        }

        $booking = \Booking\Review\Model::get($id);
        if (!$booking || !Helper::isRoomInBookingScope($login, (int) $booking->room_id)) {
            return $this->errorResponse('Permission required', 403);
        }

        return $this->redirectResponse('/booking-review?id='.$id);
    }

    /**
     * Deleted by admin. All items can be deleted.
     *
     * @param Request $request
     * @param object $login
     *
     * @return \Kotchasan\Http\Response
     */
    protected function handleDeleteAction(Request $request, $login)
    {
        if (
            !ApiController::isSuperAdmin($login)
            && !ApiController::hasPermission($login, 'can_manage_booking', false)
        ) {
            return $this->errorResponse('Permission required', 403);
        }

        $ids = $request->request('ids', [])->toInt();
        if (empty($ids)) {
            return $this->errorResponse('No data to delete', 400);
        }

        Model::remove($ids);

        // Log deletion
        \Index\Log\Model::add(0, 'booking', 'Delete', 'Deleted room reservation ID(s): '.implode(', ', $ids), $login->id);

        // Broadcast real-time update via Pusher
        \Booking\Pusher\Model::trigger('private-booking', 'updated', [
            'action' => 'delete',
            'ids' => $ids,
            'message' => 'Booking removed'
        ]);

        // Return success response
        return $this->redirectResponse('reload', 'Deleted room reservation(s) successfully', 200, 0, 'table');
    }
}
