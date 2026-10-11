<?php
/**
 * Supabase Storage helpers for room images.
 */

namespace Download\Storage;

class Model
{
    private const BUCKET = 'room-photos';

    /**
     * Seconds a folder listing is reused. Uploads and deletes made through the
     * app clear their own folder at once, so this only bounds how long a file
     * added directly in the Supabase dashboard can stay invisible.
     */
    private const LIST_TTL = 600;

    public static function isConfigured(): bool
    {
        return self::url() !== '' && self::key() !== '';
    }

    public static function upload(string $path, string $contents, string $mime): void
    {
        self::request('POST', '/object/'.self::BUCKET.'/'.self::encodePath($path), $contents, [
            'Content-Type: '.$mime,
            'x-upsert: true'
        ]);
        self::forgetList(self::folderOf($path));
    }

    public static function delete(string $path): void
    {
        self::request('DELETE', '/object/'.self::BUCKET, json_encode(['prefixes' => [$path]]), [
            'Content-Type: application/json'
        ]);
        self::forgetList(self::folderOf($path));
    }

    public static function list(string $prefix): array
    {
        $cached = self::readList($prefix);
        if ($cached !== null) {
            return $cached;
        }

        $result = self::request('POST', '/object/list/'.self::BUCKET, json_encode(self::listBody($prefix)), [
            'Content-Type: application/json'
        ]);
        $items = is_array($result) ? $result : [];
        self::writeList($prefix, $items);

        return $items;
    }

    /**
     * Warm the listing cache for many folders at once.
     *
     * A page that shows N rooms would otherwise call list() N times, one
     * Supabase round trip after another. Fetching the uncached folders
     * together costs about one round trip in total, and the later list()
     * calls are then served from the cache. Best effort: a folder that fails
     * here is simply fetched (and reported) by list() as before.
     *
     * @param array<string> $prefixes e.g. ['booking/3/', 'booking/4/']
     */
    public static function prefetch(array $prefixes): void
    {
        if (!self::isConfigured() || !function_exists('curl_multi_init')) {
            return;
        }
        $pending = [];
        foreach ($prefixes as $prefix) {
            $prefix = (string) $prefix;
            if ($prefix !== '' && !isset($pending[$prefix]) && self::readList($prefix) === null) {
                $pending[$prefix] = true;
            }
        }
        if (count($pending) < 2) {
            // One folder gains nothing from batching; list() will fetch it.
            return;
        }

        $multi = curl_multi_init();
        if (defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
            curl_multi_setopt($multi, CURLMOPT_MAX_HOST_CONNECTIONS, 10);
        }
        $handles = [];
        foreach (array_keys($pending) as $prefix) {
            $ch = curl_init(self::url().'/storage/v1/object/list/'.self::BUCKET);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(self::listBody($prefix)),
                CURLOPT_HTTPHEADER => [
                    'apikey: '.self::key(),
                    'Authorization: Bearer '.self::key(),
                    'Content-Type: application/json'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$prefix] = $ch;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $prefix => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($code >= 200 && $code < 300) {
                $decoded = json_decode((string) curl_multi_getcontent($ch), true);
                self::writeList($prefix, is_array($decoded) ? $decoded : []);
            }
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);
    }

    public static function publicUrl(string $path): string
    {
        return self::url().'/storage/v1/object/public/'.self::BUCKET.'/'.self::encodePath($path);
    }

    private static function listBody(string $prefix): array
    {
        return [
            'prefix' => $prefix,
            'limit' => 100,
            'offset' => 0,
            'sortBy' => ['column' => 'name', 'order' => 'asc']
        ];
    }

    /**
     * Folder part of an object path, in the form list() is called with
     * ('booking/3/photo.jpg' -> 'booking/3/').
     */
    private static function folderOf(string $path): string
    {
        $dir = dirname(ltrim($path, '/'));

        return $dir === '.' ? '' : $dir.'/';
    }

    private static function listCacheFile(string $prefix): string
    {
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3).'/';
        $data = defined('DATA_FOLDER') ? DATA_FOLDER : 'datas/';

        return $root.$data.'cache/storage/'.md5($prefix).'.json';
    }

    private static function readList(string $prefix): ?array
    {
        $file = self::listCacheFile($prefix);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || !isset($data['t']) || !is_array($data['items'] ?? null) || time() - (int) $data['t'] > self::LIST_TTL) {
            return null;
        }

        return $data['items'];
    }

    private static function writeList(string $prefix, array $items): void
    {
        $file = self::listCacheFile($prefix);
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        // Write then rename so a concurrent reader never sees half a file.
        $tmp = $file.'.'.getmypid().'.tmp';
        if (@file_put_contents($tmp, json_encode(['t' => time(), 'items' => $items])) !== false) {
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
            }
        }
    }

    private static function forgetList(string $prefix): void
    {
        if ($prefix !== '') {
            @unlink(self::listCacheFile($prefix));
        }
    }

    private static function request(string $method, string $path, string $body = '', array $headers = [])
    {
        $headers = array_merge([
            'apikey: '.self::key(),
            'Authorization: Bearer '.self::key()
        ], $headers);
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 30
        ]]);
        $response = @file_get_contents(self::url().'/storage/v1'.$path, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
                $status = (int) $match[1];
            }
        }
        if ($response === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Supabase Storage request failed (HTTP '.$status.')');
        }

        return $response === '' ? null : json_decode($response, true);
    }

    private static function url(): string
    {
        return rtrim((string) getenv('SUPABASE_URL'), '/');
    }

    private static function key(): string
    {
        return (string) (getenv('SUPABASE_SECRET_KEY') ?: getenv('SUPABASE_SERVICE_ROLE_KEY'));
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }
}
