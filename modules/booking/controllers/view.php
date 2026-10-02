<?php
/**
 * @filesource modules/booking/controllers/view.php
 */

namespace Booking\View;

use Booking\Booking\Model as BookingModel;
use Booking\Helper\Controller as Helper;
use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

class Controller extends ApiController
{
    /**
     * Return a modal action with full booking details.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function index(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $data = BookingModel::getDetailData($request->get('id')->toInt());
            if ($data === null) {
                return $this->errorResponse('No data available', 404);
            }

            // The public calendar links here, so this endpoint answers guests.
            // They get what the calendar already shows them and nothing more:
            // no requester identity, no contact handles, no internal notes.
            if (!$this->authenticateRequest($request)) {
                $visible = Helper::getCalendarStatuses();
                if (!empty($visible) && !in_array((int) ($data['status'] ?? -1), $visible, true)) {
                    return $this->errorResponse('No data available', 404);
                }
                foreach (['member_id', 'member_name', 'member_email', 'member_line_uid', 'member_telegram_id', 'approver_name', 'reason', 'comment', 'created_at', 'approve', 'closed'] as $field) {
                    unset($data[$field]);
                }
            }

            return $this->successResponse([
                'data' => $data,
                'actions' => [
                    View::buildModalAction($data)
                ]
            ], 'Booking details retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}
