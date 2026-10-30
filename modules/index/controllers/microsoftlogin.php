<?php
namespace Index\Microsoftlogin;

use Gcms\MicrosoftOAuth;
use Kotchasan\Http\Request;

class Controller extends \Gcms\Api
{
    public function index(Request $request)
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !MicrosoftOAuth::configured(self::$cfg)) {
            http_response_code(400);
            echo 'Microsoft sign-in is not configured. Please contact your administrator.';
            return;
        }
        $_SESSION['microsoft_states'] = array_filter($_SESSION['microsoft_states'] ?? [], function ($entry) {
            return ($entry['expires'] ?? 0) >= time();
        });
        // Bound storage while allowing a few concurrent login tabs.
        $_SESSION['microsoft_states'] = array_slice($_SESSION['microsoft_states'], -4, null, true);
        $state = MicrosoftOAuth::encode(random_bytes(32));
        $nonce = MicrosoftOAuth::encode(random_bytes(32));
        $verifier = MicrosoftOAuth::encode(random_bytes(32));
        $cfg = self::$cfg;
        $_SESSION['microsoft_states'][$state] = [
            'expires' => time() + 600,
            'nonce' => $nonce,
            'verifier' => $verifier,
            'tenant' => $cfg->microsoft_tenant,
            'client' => $cfg->microsoft_client_id,
            'redirect' => $cfg->microsoft_redirect_uri,
            'authority' => MicrosoftOAuth::authority($cfg),
            'demo_email' => MicrosoftOAuth::demoEmail(),
            'profile_api' => MicrosoftOAuth::profileApi($cfg),
            'return' => MicrosoftOAuth::returnUrl($request->get('intended_url', '/')->toString(), MicrosoftOAuth::baseUrl($cfg->microsoft_redirect_uri))
        ];
        session_write_close();
        $params = [
            'client_id' => $cfg->microsoft_client_id,
            'redirect_uri' => $cfg->microsoft_redirect_uri,
            'response_type' => 'code',
            'response_mode' => 'query',
            'prompt' => 'select_account',
            'scope' => MicrosoftOAuth::scope($cfg),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => MicrosoftOAuth::encode(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256'
        ];
        header('Location: https://login.microsoftonline.com/'.MicrosoftOAuth::authority($cfg).'/oauth2/v2.0/authorize?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        exit;
    }
}
