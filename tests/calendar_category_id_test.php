<?php
/**
 * CLI only: this writes to the database. Never reachable over HTTP.
 *
 * The calendar API tags every event with the id of its "use" category and
 * reports that category's current name as the description. A booking keeps the
 * text it was made under, so without this a renamed category would no longer
 * match its older bookings in the calendar's category filter.
 *
 * Needs the docker compose stack up (it calls the running site).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Booking\Model;
use Booking\Helper\Controller as Helper;

function assertCalendarTest($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "  ok   $message\n";
}

$db = Model::createDB();
$room = $db->getDatabase()->createQuery()->select('id')->from('rooms')->where(['is_active', 1])->orderBy('id')->first();
$member = $db->getDatabase()->createQuery()->select('id')->from('user')->orderBy('id')->first();
$category = $db->getDatabase()->createQuery()->select('category_id', 'topic')->from('category')->where([['type', 'use'], ['language', '']])->orderBy('category_id')->first();
assertCalendarTest($room && $member && $category, 'test data: one active room, one user and one "use" category exist');

$staleName = '__name_before_rename__';
$base = [
    'id' => 0,
    'room_id' => (int) $room->id,
    'member_id' => (int) $member->id,
    'created_at' => date('Y-m-d H:i:s'),
    'topic' => $staleName,
    'comment' => '',
    'attendees' => 1,
    'begin' => '2036-03-03 09:00:00',
    'end' => '2036-03-03 10:00:00',
    'schedule_type' => Helper::SCHEDULE_DAILY_SLOT,
    'status' => Helper::STATUS_PENDING_REVIEW,
    'reason' => '',
    'approve' => 1,
    'closed' => 1,
    'department' => '',
    'version' => 0
];

$createdIds = [];
try {
    // Made under a name the category no longer has; the id still links it.
    $withId = Model::saveReservation(0, $base, ['use' => (string) $category->category_id, 'recurrence_days' => [], 'excluded_dates' => []]);
    $createdIds[] = (int) $withId['id'];
    Model::approveReservation((int) $withId['id'], 1, ['approve' => 1, 'reason' => '']);

    // A legacy row that never stored a category id.
    $legacy = $base;
    $legacy['begin'] = '2036-03-03 11:00:00';
    $legacy['end'] = '2036-03-03 12:00:00';
    $legacy['topic'] = '__legacy_text_only__';
    $noId = Model::saveReservation(0, $legacy, ['recurrence_days' => [], 'excluded_dates' => []]);
    $createdIds[] = (int) $noId['id'];
    Model::approveReservation((int) $noId['id'], 1, ['approve' => 1, 'reason' => '']);

    $ch = curl_init('http://localhost/api/booking/calendar?start=2036-03-01&end=2036-03-31');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $body = curl_exec($ch);
    curl_close($ch);
    $events = json_decode((string) $body, true)['data']['data'] ?? [];

    $byReservation = [];
    foreach ($events as $event) {
        $byReservation[(int) $event['reservationId']] = $event;
    }
    $a = $byReservation[(int) $withId['id']] ?? null;
    $b = $byReservation[(int) $noId['id']] ?? null;
    assertCalendarTest($a !== null && $b !== null, 'both approved bookings are in the calendar');
    assertCalendarTest($a['categoryId'] === (string) $category->category_id, 'an event carries the id of its category');
    assertCalendarTest($a['description'] === $category->topic, 'its description is the category\'s current name, not the stale stored text');
    assertCalendarTest($b['categoryId'] === '', 'a booking without a stored category has an empty id');
    assertCalendarTest($b['description'] === '__legacy_text_only__', 'and keeps its own text so the name fallback still works');

    echo "ALL PASS\n";
} finally {
    if (!empty($createdIds)) {
        $db->delete('reservation_occurrence', ['reservation_id', $createdIds], 0);
        $db->delete('reservation_data', ['reservation_id', $createdIds], 0);
        $db->delete('reservation', ['id', $createdIds], 0);
    }
}
