<?php
/**
 * Test real-time conflict checking API.
 */
require_once __DIR__ . '/../load.php';

use Booking\Booking\Controller as BookingController;
use Kotchasan\Http\Request;

echo "[Conflict API Test]\n";

$controller = new BookingController();

// 1. Missing fields should return has_conflict = false
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$res = $controller->checkConflicts(new Request());
$data = json_decode($res->getContent(), true)['data'];
assert($data['has_conflict'] === false, 'Missing params must report no conflict');
echo "  ok   missing params handled\n";

// 2. Query known approved conflict (Room 1 on 2026-09-10 from 09:30 to 12:00)
$_GET = [
    'room_id' => 1,
    'begin_date' => '2026-09-10',
    'begin_time' => '10:00',
    'end_date' => '2026-09-10',
    'end_time' => '11:00',
    'schedule_type' => 'daily-slot'
];
$res = $controller->checkConflicts(new Request());
$data = json_decode($res->getContent(), true)['data'];
assert($data['has_conflict'] === true, 'Overlapping time slot must report conflict');
assert(!empty($data['conflicts']), 'Conflicts array must not be empty');
assert(strpos($data['message'], '2026-09-10') !== false, 'Message must mention conflicting date');
echo "  ok   approved overlap detected\n";

// 3. Query non-conflicting time slot (Room 1 on 2026-09-10 from 05:00 to 06:00)
$_GET = [
    'room_id' => 1,
    'begin_date' => '2026-09-10',
    'begin_time' => '05:00',
    'end_date' => '2026-09-10',
    'end_time' => '06:00',
    'schedule_type' => 'daily-slot'
];
$res = $controller->checkConflicts(new Request());
$data = json_decode($res->getContent(), true)['data'];
assert($data['has_conflict'] === false, 'Free slot must report no conflict');
assert(empty($data['conflicts']), 'Conflicts array must be empty for free slot');
echo "  ok   free slot reports no conflict\n";

// 4. Exclude own reservation id (reservation_id 5 for room 1)
$_GET = [
    'room_id' => 1,
    'begin_date' => '2026-09-10',
    'begin_time' => '10:00',
    'end_date' => '2026-09-10',
    'end_time' => '11:00',
    'schedule_type' => 'daily-slot',
    'id' => 5
];
$res = $controller->checkConflicts(new Request());
$data = json_decode($res->getContent(), true)['data'];
assert($data['has_conflict'] === false, 'Self-reservation must be excluded');
echo "  ok   self-reservation exclusion works\n";

echo "\nALL PASS\n";
