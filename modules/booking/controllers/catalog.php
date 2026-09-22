<?php
/**
 * @filesource modules/booking/controllers/catalog.php
 */

namespace Booking\Catalog;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;

class Controller extends ApiController
{
    /**
     * Return active rooms for the room catalog.
     *
     * Open to anonymous visitors, like the calendar. What comes back is the
     * room itself — name, building, number, seats, description, photos — which
     * is the same public information a sign on the door carries. No booking,
     * no member and no internal note is joined in, and only rooms marked
     * active are selected, so a room taken out of service stays hidden.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function lists(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            return $this->successResponse([
                'items' => Model::getItems(true)
            ], 'Room catalog retrieved')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Return a single room detail modal payload.
     *
     * Public for the same reason lists() is, and the second argument keeps it
     * to active rooms so an id typed into the URL cannot reveal a retired one.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response
     */
    public function detail(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            $data = Model::get($request->get('id')->toInt(), true);
            if ($data === null) {
                return $this->redirectResponse('/404', 'No data available', 404);
            }

            return $this->successResponse([
                'data' => $data,
                'actions' => [
                    [
                        'type' => 'modal',
                        'action' => 'show',
                        'template' => '/booking/catalog-detail.html',
                        'title' => '{LNG_Room} '.$data->name,
                        'titleClass' => 'icon-office'
                    ]
                ]
            ], 'Room details retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }
}