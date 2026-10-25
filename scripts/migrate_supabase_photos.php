<?php
/**
 * One-off: copy room photos from Supabase Storage to datas/booking/{id}/.
 *
 * Room images used to live in the Supabase bucket "room-photos" under
 * booking/{room id}/. The app now serves them from datas/ again, so photos
 * uploaded while Supabase was in use have to be brought down once. Files that
 * already exist locally are skipped, so it is safe to re-run. Nothing is
 * deleted from Supabase.
 *
 * Run as the web server user so the app can delete the files later:
 *   docker compose exec -u www-data \
 *     -e SUPABASE_URL=https://xxxx.supabase.co \
 *     -e SUPABASE_SECRET_KEY=... \
 *     web php scripts/migrate_supabase_photos.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const BUCKET = 'room-photos';
const PAGE = 100;

$url = rtrim((string) getenv('SUPABASE_URL'), '/');
$key = (string) (getenv('SUPABASE_SECRET_KEY') ?: getenv('SUPABASE_SERVICE_ROLE_KEY'));
if ($url === '' || $key === '') {
    fwrite(STDERR, "Set SUPABASE_URL and SUPABASE_SECRET_KEY (or SUPABASE_SERVICE_ROLE_KEY).\n");
    exit(1);
}
$target = dirname(__DIR__).'/datas/booking/';

/**
 * @return array{0:int,1:string} HTTP status and body
 */
function supabase(string $url, string $key, string $path, ?array $json = null): array
{
    $ch = curl_init($url.'/storage/v1'.$path);
    $headers = ['apikey: '.$key, 'Authorization: Bearer '.$key];
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$status, $body === false ? '' : (string) $body];
}

/**
 * Every entry directly under $prefix. Folders come back with a null id.
 */
function listAll(string $url, string $key, string $prefix): array
{
    $items = [];
    for ($offset = 0;; $offset += PAGE) {
        [$status, $body] = supabase($url, $key, '/object/list/'.BUCKET, [
            'prefix' => $prefix,
            'limit' => PAGE,
            'offset' => $offset,
            'sortBy' => ['column' => 'name', 'order' => 'asc']
        ]);
        $page = json_decode($body, true);
        if ($status < 200 || $status >= 300 || !is_array($page)) {
            throw new RuntimeException('Listing '.$prefix.' failed (HTTP '.$status.'): '.$body);
        }
        $items = array_merge($items, $page);
        if (count($page) < PAGE) {
            return $items;
        }
    }
}

$copied = $skipped = $failed = 0;
foreach (listAll($url, $key, 'booking/') as $folder) {
    $id = (string) ($folder['name'] ?? '');
    if (!ctype_digit($id)) {
        continue;
    }
    $dir = $target.$id.'/';
    foreach (listAll($url, $key, 'booking/'.$id.'/') as $item) {
        $name = (string) ($item['name'] ?? '');
        // Skip sub-folders, Supabase's .emptyFolderPlaceholder and odd names.
        if (empty($item['id']) || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/D', $name)) {
            continue;
        }
        if (is_file($dir.$name)) {
            ++$skipped;
            continue;
        }
        [$status, $body] = supabase($url, $key, '/object/'.BUCKET.'/booking/'.$id.'/'.rawurlencode($name));
        if ($status < 200 || $status >= 300 || $body === '') {
            fwrite(STDERR, "  failed booking/$id/$name (HTTP $status)\n");
            ++$failed;
            continue;
        }
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            fwrite(STDERR, "Cannot create $dir\n");
            exit(1);
        }
        file_put_contents($dir.$name, $body);
        echo "  booking/$id/$name\n";
        ++$copied;
    }
}

// The Supabase listing cache is no longer read by anything.
foreach (glob(dirname(__DIR__).'/datas/cache/storage/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir(dirname(__DIR__).'/datas/cache/storage');

echo "Copied $copied, already present $skipped, failed $failed.\n";
exit($failed > 0 ? 1 : 0);
