<?php
/**
 * @filesource modules/booking/models/pusher.php
 *
 * Pusher WebSocket Event Dispatcher
 *
 * Provides zero-dependency cURL-based event publishing to Pusher Channels REST API.
 * Uses HMAC-SHA256 authentication as per Pusher specification.
 *
 * The same requests work against a self-hosted Pusher-compatible server
 * (Sockudo): set PUSHER_HOST and events go there instead of Pusher's cloud.
 */

namespace Booking\Pusher;

use Kotchasan\Logger;

class Model extends \Kotchasan\Model
{
    /**
     * Create the authorization response for a private channel subscription.
     *
     * @return array|null Null when credentials are not configured
     */
    public static function authorizePrivateChannel(string $socketId, string $channel): ?array
    {
        $key = self::$cfg->pusher_key ?? getenv('PUSHER_KEY');
        $secret = self::$cfg->pusher_secret ?? getenv('PUSHER_SECRET');

        if (empty($key) || empty($secret)) {
            return null;
        }

        $signature = hash_hmac('sha256', $socketId.':'.$channel, $secret);

        return ['auth' => $key.':'.$signature];
    }

    /**
     * Where the REST API lives, without a trailing slash.
     *
     * PUSHER_HOST empty means Pusher's cloud for the configured cluster.
     * Otherwise it names a self-hosted server, normally reached inside the
     * Docker network (http://sockudo:6001), so it never needs to go through
     * the public reverse proxy.
     */
    public static function apiBaseUrl(): string
    {
        $host = self::$cfg->pusher_host ?? getenv('PUSHER_HOST');
        if (empty($host)) {
            $cluster = self::$cfg->pusher_cluster ?? getenv('PUSHER_CLUSTER');

            return 'https://api-'.(empty($cluster) ? 'ap1' : $cluster).'.pusher.com';
        }
        $scheme = self::$cfg->pusher_scheme ?? getenv('PUSHER_SCHEME');
        $scheme = $scheme === 'http' ? 'http' : 'https';
        $port = (int) (self::$cfg->pusher_port ?? getenv('PUSHER_PORT'));

        return $scheme.'://'.$host.($port > 0 ? ':'.$port : '');
    }

    /**
     * Trigger a Pusher event on a channel
     *
     * @param string $channel Channel name (e.g. 'booking')
     * @param string $event   Event name (e.g. 'updated')
     * @param array  $data    Payload data
     *
     * @return bool True if successful, false otherwise
     */
    public static function trigger(string $channel, string $event, array $data = []): bool
    {
        $appId = self::$cfg->pusher_app_id ?? getenv('PUSHER_APP_ID');
        $key = self::$cfg->pusher_key ?? getenv('PUSHER_KEY');
        $secret = self::$cfg->pusher_secret ?? getenv('PUSHER_SECRET');

        // Silently return if Pusher credentials are not configured
        if (empty($appId) || empty($key) || empty($secret)) {
            return false;
        }

        try {
            $payload = [
                'name' => $event,
                'channel' => $channel,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE)
            ];
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

            $path = "/apps/{$appId}/events";
            $authTimestamp = time();
            $authVersion = '1.0';
            $bodyMd5 = md5($body);

            // Params must be sorted alphabetically by key
            $queryString = "auth_key={$key}&auth_timestamp={$authTimestamp}&auth_version={$authVersion}&body_md5={$bodyMd5}";
            $stringToSign = "POST\n{$path}\n{$queryString}";
            $authSignature = hash_hmac('sha256', $stringToSign, $secret);

            $url = self::apiBaseUrl()."{$path}?{$queryString}&auth_signature={$authSignature}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($body)
                ],
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_SSL_VERIFYPEER => (DIRECTORY_SEPARATOR !== '\\' || !empty(ini_get('curl.cainfo')))
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if (!empty($curlError)) {
                Logger::error("Pusher cURL error: {$curlError}");
                return false;
            }

            if ($httpCode !== 200) {
                Logger::error("Pusher HTTP {$httpCode}: {$response}");
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Logger::error("Pusher exception: " . $e->getMessage());
            return false;
        }
    }
}
