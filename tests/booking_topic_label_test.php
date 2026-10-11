<?php
/**
 * CLI only: this writes to the database. Never reachable over HTTP.
 *
 * A booking stores the "use" category's name as `topic` at booking time and
 * also the category's id. Screens that show the topic must follow the id, so a
 * renamed category shows its current name instead of the stale stored text.
 * Bookings with no stored id keep showing their own text.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Booking\Model;
use Booking\Helper\Controller as Helper;

function assertTopicTest($condition, string $message): void
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
assertTopicTest($room && $member && $category, 'test data: one active room, one user and one "use" category exist');

$base = [
    'id' => 0,
    'room_id' => (int) $room->id,
    'member_id' => (int) $member->id,
    'created_at' => date('Y-m-d H:i:s'),
    'topic' => '__name_before_rename__',
    'comment' => '',
    'attendees' => 1,
    'begin' => '2036-04-07 09:00:00',
    'end' => '2036-04-07 10:00:00',
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
    $withId = Model::saveReservation(0, $base, ['use' => (string) $category->category_id, 'recurrence_days' => [], 'excluded_dates' => []]);
    $createdIds[] = (int) $withId['id'];

    $legacy = $base;
    $legacy['begin'] = '2036-04-07 11:00:00';
    $legacy['end'] = '2036-04-07 12:00:00';
    $legacy['topic'] = '__legacy_text_only__';
    $noId = Model::saveReservation(0, $legacy, ['recurrence_days' => [], 'excluded_dates' => []]);
    $createdIds[] = (int) $noId['id'];

    $labels = Helper::getUseLabelsByReservation($createdIds);
    assertTopicTest(($labels[(int) $withId['id']] ?? '') === $category->topic, 'a booking is labelled with its category\'s current name, found by id');
    assertTopicTest(!array_key_exists((int) $noId['id'], $labels), 'a booking without a stored category is left out so callers keep its own text');
    assertTopicTest(Helper::getUseLabelsByReservation([]) === [], 'no ids, no lookup');

    $detail = Model::getDetailData((int) $withId['id']);
    assertTopicTest($detail['topic'] === $category->topic, 'the detail view shows the current category name, not the stale stored text');
    $legacyDetail = Model::getDetailData((int) $noId['id']);
    assertTopicTest($legacyDetail['topic'] === '__legacy_text_only__', 'the detail view falls back to the stored text when there is no category id');

    echo "ALL PASS\n";
} finally {
    if (!empty($createdIds)) {
        $db->delete('reservation_occurrence', ['reservation_id', $createdIds], 0);
        $db->delete('reservation_data', ['reservation_id', $createdIds], 0);
        $db->delete('reservation', ['id', $createdIds], 0);
    }
}
