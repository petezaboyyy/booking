<?php

require_once __DIR__.'/../Kotchasan/KBase.php';
require_once __DIR__.'/../Kotchasan/ApiController.php';
require_once __DIR__.'/../Gcms/Api.php';
require_once __DIR__.'/../Gcms/Controller.php';
require_once __DIR__.'/../modules/booking/controllers/helper.php';

use Booking\Helper\Controller as Helper;

final class TestBookingHelper extends Helper
{
    public static function configure(object $config): void
    {
        self::$cfg = $config;
    }
}

TestBookingHelper::configure((object) [
    'booking_approve_level' => 1,
    'booking_approve_status' => [1 => 1],
    'booking_approve_department' => [1 => '']
]);

$assert = static function ($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, $message."\n");
        exit(1);
    }
};

$limited = (object) [
    'status' => 0,
    'permission' => ['can_manage_booking'],
    'metas' => ['booking_rooms' => ['3', '2', '3', 'invalid', '0']]
];
$allRooms = (object) [
    'status' => 0,
    'permission' => ['can_manage_booking'],
    'metas' => []
];
$approver = (object) [
    'status' => 0,
    'permission' => [],
    'metas' => ['booking_rooms' => ['2']]
];
$bookingManager = (object) [
    'status' => 2,
    'permission' => ['can_manage_booking'],
    'metas' => ['booking_rooms' => ['2']]
];

$assert(Helper::normalizeRoomIds(['3', '2', '3', 'invalid', '0']) === [2, 3], 'Room ID normalization failed');
$assert(Helper::getBookingRoomScope($limited) === [2, 3], 'Limited room scope failed');
$assert(Helper::isRoomInBookingScope($limited, 2), 'Allowed room was rejected');
$assert(!Helper::isRoomInBookingScope($limited, 1), 'Room outside scope was allowed');
$assert(Helper::getBookingRoomScope($allRooms) === null, 'Empty selection should allow all rooms');
$assert(Helper::isRoomInBookingScope($allRooms, 99), 'All-room scope rejected a room');
$assert(Helper::getBookingRoomScope($approver) === null, 'Non-manager scope should be unrestricted');
$assert(Helper::canAccessApprovalArea($bookingManager), 'Booking manager cannot access approvals');
$assert(Helper::canApproveRequests($bookingManager), 'Booking manager cannot approve requests');
$assert(Helper::canApproveStep($bookingManager, (object) ['approve' => 1]), 'Booking manager cannot approve a step');

echo "Booking room scope test passed\n";
