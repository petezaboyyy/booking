<?php
namespace Index\Microsoftcallback;

use Gcms\MicrosoftOAuth;
use Kotchasan\Http\Request;

class Controller extends \Gcms\Api
{
    public function index(Request $request)
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        $message = 'Microsoft sign-in failed. Please try again or contact your administrator.';
        try {
            $cfg = self::$cfg;
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !MicrosoftOAuth::configured($cfg)) {
                throw new \RuntimeException('Not configured');
            }
            $_SESSION['microsoft_states'] = $_SESSION['microsoft_states'] ?? [];
            $transaction = MicrosoftOAuth::consumeState($_SESSION['microsoft_states'], $request->get('state', '')->toString());
            session_write_close();
            if ($transaction['tenant'] !== $cfg->microsoft_tenant || $transaction['client'] !== $cfg->microsoft_client_id
                || $transaction['redirect'] !== $cfg->microsoft_redirect_uri
                || ($transaction['authority'] ?? $cfg->microsoft_tenant) !== MicrosoftOAuth::authority($cfg)
                || ($transaction['demo_email'] ?? '') !== MicrosoftOAuth::demoEmail()) {
                throw new \RuntimeException('Configuration changed');
            }
            if ($request->get('error', '')->toString() !== '') {
                $message = 'Microsoft sign-in was cancelled or denied. Please try again.';
                throw new \RuntimeException('Provider denied');
            }
            $code = $request->get('code', '')->toString();
            if ($code === '') {
                throw new \RuntimeException('Missing code');
            }
            $authority = MicrosoftOAuth::authority($cfg);
            $tokens = MicrosoftOAuth::request('https://login.microsoftonline.com/'.$authority.'/oauth2/v2.0/token', [
                'grant_type' => 'authorization_code',
                'client_id' => $cfg->microsoft_client_id,
                'client_secret' => $cfg->microsoft_client_secret,
                'redirect_uri' => $cfg->microsoft_redirect_uri,
                'code' => $code,
                'code_verifier' => $transaction['verifier']
            ]);
            $keys = MicrosoftOAuth::request('https://login.microsoftonline.com/'.$authority.'/discovery/v2.0/keys');
            $claims = MicrosoftOAuth::validateIdToken($tokens['id_token'] ?? '', $keys, $cfg->microsoft_client_id, $cfg->microsoft_tenant, $transaction['nonce'], MicrosoftOAuth::demoEmail() !== '');
            $personalIdentity = [
                'microsoft_tenant_id' => $claims['tid'],
                'microsoft_id' => $claims['oid']
            ];
            $isDemo = MicrosoftOAuth::isDemoIdentity($personalIdentity);
            if ($claims['tid'] === MicrosoftOAuth::PERSONAL_TENANT && !$isDemo) {
                // Enrollment only: email is a display hint, never an authorization key.
                // A deployment operator must bind the verified oid before granting access.
                if (getenv('BOOKING_MICROSOFT_DEMO_OID')) {
                    $message = 'This Microsoft account is not allowed to access Booking.';
                    throw new \RuntimeException('Unapproved personal account');
                }
                header('Content-Type: text/html; charset=UTF-8');
                $hint = htmlspecialchars((string) ($claims['email'] ?? $claims['preferred_username'] ?? ''), ENT_QUOTES, 'UTF-8');
                $oid = htmlspecialchars($claims['oid'], ENT_QUOTES, 'UTF-8');
                // Shown in the language the page was using (the my_lang cookie).
                $t = static function ($key) {
                    return htmlspecialchars(\Kotchasan\Language::get($key), ENT_QUOTES, 'UTF-8');
                };
                $lang = htmlspecialchars(\Kotchasan\Language::name() ?: 'th', ENT_QUOTES, 'UTF-8');
                echo '<!doctype html><html lang="'.$lang.'"><meta charset="utf-8"><title>'.$t('Booking account setup').'</title><body>'
                    .'<h1>'.$t('Microsoft account verified').'</h1>'
                    .'<p>'.$t('Account').': '.$hint.'</p>'
                    .'<p>'.$t('Account ID for admin setup').':</p><code>'.$oid.'</code>'
                    .'<p>'.$t('This account has not been granted access to Booking yet. Ask the installer to verify and bind this account ID first.').'</p>'
                    .'<a href="../login">'.$t('Back to login').'</a></body></html>';
                return;
            }
            if (empty($tokens['access_token']) || !is_string($tokens['access_token'])) {
                throw new \RuntimeException('Missing access token');
            }
            try {
                if ($isDemo) {
                    $identity = $personalIdentity + [
                        'username' => MicrosoftOAuth::demoEmail(),
                        'name' => MicrosoftOAuth::demoEmail(),
                        'social' => 'microsoft',
                        'status' => 0
                    ];
                } else {
                    $user = MicrosoftOAuth::request('https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName,userType', null, $tokens['access_token']);
                    $identity = MicrosoftOAuth::profile($user, $claims, $cfg->microsoft_allowed_domains);
                }
            } catch (\RuntimeException $e) {
                $message = $e->getMessage();
                throw $e;
            }
            $result = \Index\Social\Model::authenticate($identity,
                MicrosoftOAuth::baseUrl($cfg->microsoft_redirect_uri), 'Microsoft', $request->getClientIp());
            if (empty($result['success']) || empty($result['token'])) {
                $message = 'Unable to sign in. Your account may be disabled or this email may already belong to an existing account. Please contact your administrator.';
                throw new \RuntimeException('Local account denied');
            }
            \Index\Auth\Model::setCookie('auth_token', $result['token'], 'Lax');
            header('Location: '.$transaction['return']);
            exit;
        } catch (\Throwable $e) {
            error_log('Microsoft SSO: authentication rejected: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            http_response_code(400);
            header('Content-Type: text/html; charset=UTF-8');
            $debugNotice = '';
            if (MicrosoftOAuth::demoEmail() !== '' || (defined('DEBUG_MODE') && DEBUG_MODE)) {
                $debugNotice = '<p style="color:#a94442;background:#f2dede;border:1px solid #ebccd1;padding:12px;border-radius:4px;font-family:monospace;margin:15px 0;"><strong>Debug Error:</strong> '.htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8').'</p>';
            }
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Microsoft sign-in</title><body><h1>Microsoft sign-in</h1><p>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'</p>'.$debugNotice.'<p><a href="../">Return to Booking</a></p></body></html>';
        }
    }
}
