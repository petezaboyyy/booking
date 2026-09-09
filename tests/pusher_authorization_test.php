<?php

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
