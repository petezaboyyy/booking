<?php
/**
 * @filesource modules/booking/controllers/pusher.php
 *
 * Authorizes authenticated users to subscribe to booking updates.
 */

namespace Booking\Pusher;

use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;

class Controller extends ApiController
{
    /**
     * POST /api/booking/pusher/authorize
     */
    public function authorize(Request $request)
    {
        ApiController::validateMethod($request, 'POST');
        $this->validateCsrfToken($request);

        $login = $this->authenticateRequest($request);
        if (!$login || empty($login->id)) {
            return $this->jsonResponse(['error' => 'Forbidden'], 403);
        }

        $socketId = trim($request->post('socket_id')->toString());
        $channel = trim($request->post('channel_name')->toString());

        if ($channel !== 'private-booking' || !preg_match('/^\d+\.\d+$/D', $socketId)) {
            return $this->jsonResponse(['error' => 'Invalid channel authorization request'], 400);
        }

        $authorization = Model::authorizePrivateChannel($socketId, $channel);
        if ($authorization === null) {
            return $this->jsonResponse(['error' => 'Pusher is not configured'], 503);
        }

        return $this->jsonResponse($authorization, 200);
    }

    private function jsonResponse(array $data, int $status): Response
    {
        return Response::create(
            json_encode($data, JSON_UNESCAPED_SLASHES),
            $status,
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store'
            ]
        );
    }
}
