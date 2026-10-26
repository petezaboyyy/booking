<?php
/** CLI only: this writes to the database. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../Kotchasan/KBase.php';
require_once __DIR__.'/../Kotchasan/Model.php';
require_once __DIR__.'/../modules/booking/models/pusher.php';

final class TestPusherModel extends \Booking\Pusher\Model
{
    public static function configure(object $config): void
    {
        self::$cfg = $config;
    }
}

TestPusherModel::configure((object) [
    'pusher_key' => 'test-key',
    'pusher_secret' => 'test-secret'
]);

$socketId = '123.456';
$channel = 'private-booking';
$expected = 'test-key:'.hash_hmac('sha256', $socketId.':'.$channel, 'test-secret');
$actual = TestPusherModel::authorizePrivateChannel($socketId, $channel);

if ($actual !== ['auth' => $expected]) {
    fwrite(STDERR, "Pusher private-channel signature test failed\n");
    exit(1);
}

echo "Pusher private-channel signature test passed\n";

// Where events are sent: Pusher's cloud unless PUSHER_HOST names our own server.
$cases = [
    'cloud, default cluster' => [[], 'https://api-ap1.pusher.com'],
    'cloud, other cluster' => [['pusher_cluster' => 'eu'], 'https://api-eu.pusher.com'],
    'self-hosted in Docker' => [
        ['pusher_host' => 'sockudo', 'pusher_port' => '6001', 'pusher_scheme' => 'http'],
        'http://sockudo:6001'
    ],
    'self-hosted, scheme defaults to https' => [['pusher_host' => 'ws.example.com'], 'https://ws.example.com'],
    'self-hosted, unknown scheme is https' => [
        ['pusher_host' => 'ws.example.com', 'pusher_port' => '8443', 'pusher_scheme' => 'ftp'],
        'https://ws.example.com:8443'
    ]
];
foreach ($cases as $name => [$config, $expectedUrl]) {
    TestPusherModel::configure((object) array_merge(
        ['pusher_host' => '', 'pusher_port' => '', 'pusher_scheme' => '', 'pusher_cluster' => 'ap1'],
        $config
    ));
    $url = TestPusherModel::apiBaseUrl();
    if ($url !== $expectedUrl) {
        fwrite(STDERR, "Pusher API URL test failed ($name): got $url, expected $expectedUrl\n");
        exit(1);
    }
}

echo "Pusher API URL test passed\n";
