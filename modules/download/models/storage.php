<?php
/**
 * Supabase Storage helpers for room images.
 */

namespace Download\Storage;

class Model
{
    private const BUCKET = 'room-photos';

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
    }

    public static function delete(string $path): void
    {
        self::request('DELETE', '/object/'.self::BUCKET, json_encode(['prefixes' => [$path]]), [
            'Content-Type: application/json'
        ]);
    }

    public static function list(string $prefix): array
    {
        $result = self::request('POST', '/object/list/'.self::BUCKET, json_encode([
            'prefix' => $prefix,
            'limit' => 100,
            'offset' => 0,
            'sortBy' => ['column' => 'name', 'order' => 'asc']
        ]), ['Content-Type: application/json']);

        return is_array($result) ? $result : [];
    }

    public static function publicUrl(string $path): string
    {
        return self::url().'/storage/v1/object/public/'.self::BUCKET.'/'.self::encodePath($path);
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
