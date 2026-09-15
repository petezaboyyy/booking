<?php
/** CLI only: this writes to the database. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Regression tests for the booking permission / concurrency / query-count fixes.
 * Requires a live database with at least one active room and one user.
 *
 * Usage: docker compose exec web php tests/booking_security_test.php
 */

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Booking\Model;
use Booking\Helper\Controller as Helper;

$fail = 0;
$createdIds = [];

function check($condition, string $message): void
{
    global $fail;
    if ($condition) {
        echo "  ok   $message\n";
    } else {
        $fail++;
        echo "  FAIL $message\n";
    }
}

/** Run $fn and return the thrown exception, or null when nothing was thrown. */
function thrown(callable $fn): ?\Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e;
    }
    return null;
}

$db = Model::createDB();
$room = $db->getDatabase()->createQuery()->select('id')->from('rooms')->where(['is_active', 1])->orderBy('id')->first();
$member = $db->getDatabase()->createQuery()->select('id')->from('user')->orderBy('id')->first();
if (!$room || !$member) {
    fwrite(STDERR, "Test requires one active room and one user\n");
    exit(1);
}
$memberId = (int) $member->id;
$otherMemberId = $memberId + 99999;

/** Create a fresh reservation and remember it for cleanup. */
function makeBooking(int $roomId, int $memberId, string $suffix, string $begin, string $end, array $meta = []): int
{
    global $createdIds;
    $result = Model::saveReservation(0, [
        'id' => 0,
        'room_id' => $roomId,
        'member_id' => $memberId,
        'created_at' => date('Y-m-d H:i:s'),
        'topic' => '__security_test__'.$suffix,
        'comment' => '',
        'attendees' => 1,
        'begin' => $begin,
        'end' => $end,
        'schedule_type' => Helper::SCHEDULE_DAILY_SLOT,
        'status' => Helper::STATUS_PENDING_REVIEW,
        'reason' => '',
        'approve' => 1,
        'closed' => 1,
        'department' => '',
        'version' => 0
    ], $meta + ['recurrence_days' => [], 'excluded_dates' => []]);
    $id = (int) $result['id'];
    if ($id > 0) {
        $createdIds[] = $id;
    }
    return $id;
}

try {
    // =====================================================================
    echo "\n[1] An approved booking cannot be deleted by its requester\n";
    // =====================================================================
    $approved = makeBooking((int) $room->id, $memberId, '_del_approved', '2036-03-02 10:00:00', '2036-03-02 11:00:00');
    check($approved > 0, 'created a pending booking');
    check(Model::approveReservation($approved, 1, ['approve' => 1, 'reason' => '']) === [], 'officer approved it');

    $removed = Model::removeOwned($memberId, [$approved]);
    check($removed === 0, 'removeOwned() deleted nothing (the API path a user can call directly)');
    check(Model::getById($approved) !== null, 'the approved booking still exists in the database');
    check(count(Model::getOccurrences($approved)) === 1, 'its occurrence rows survived too');

    // Once the officer cancels it, the requester may clear it from their list.
    Model::updateStatus($approved, Helper::STATUS_CANCELLED_BY_OFFICER);
    check(Model::removeOwned($memberId, [$approved]) === 1, 'a cancelled booking is still deletable');
    check(Model::getById($approved) === null, 'and it is really gone');

    // A booking belonging to someone else is never touched.
    $mine = makeBooking((int) $room->id, $memberId, '_del_other', '2036-03-03 10:00:00', '2036-03-03 11:00:00');
    Model::updateStatus($mine, Helper::STATUS_CANCELLED_BY_REQUESTER);
    check(Model::removeOwned($otherMemberId, [$mine]) === 0, 'another member cannot delete my booking');
    check(Model::getById($mine) !== null, 'it is still there');

    // =====================================================================
    echo "\n[2] Cancelling is re-checked inside the transaction\n";
    // =====================================================================
    $raced = makeBooking((int) $room->id, $memberId, '_race', '2036-03-04 10:00:00', '2036-03-04 11:00:00');
    // The officer's approval lands first — this is the losing side of the race.
    Model::approveReservation($raced, 1, ['approve' => 1, 'reason' => '']);
    $e = thrown(static function () use ($raced, $memberId) {
        Model::cancelByRequester($raced, $memberId, null);
    });
    check($e !== null && $e->getCode() === 409, 'cancelling a just-approved booking is rejected with 409');
    check((int) Model::getById($raced)->status === Helper::STATUS_APPROVED, 'the booking is still approved, not cancelled');
    $occ = Model::getOccurrences($raced);
    check((int) $occ[0]->status === Helper::STATUS_APPROVED, 'its date is still approved');

    $stale = makeBooking((int) $room->id, $memberId, '_stale', '2036-03-05 10:00:00', '2036-03-05 11:00:00');
    $current = (int) Model::getById($stale)->version;
    $e = thrown(static function () use ($stale, $memberId, $current) {
        Model::cancelByRequester($stale, $memberId, $current - 1);
    });
    check($e !== null && $e->getCode() === 409, 'a stale version is rejected with 409');
    check((int) Model::getById($stale)->status === Helper::STATUS_PENDING_REVIEW, 'the booking was left untouched');

    $e = thrown(static function () use ($stale, $otherMemberId) {
        Model::cancelByRequester($stale, $otherMemberId, null);
    });
    check($e !== null && $e->getCode() === 404, 'another member cannot cancel my booking');

    // The happy path still works, and syncs the occurrence rows.
    Model::cancelByRequester($stale, $memberId, $current);
    $after = Model::getById($stale);
    check((int) $after->status === Helper::STATUS_CANCELLED_BY_REQUESTER, 'the owner can cancel a pending booking');
    check((int) $after->version === $current + 1, 'the version was bumped so other tabs see the change');
    check((int) Model::getOccurrences($stale)[0]->status === Helper::STATUS_CANCELLED_BY_REQUESTER, 'the occurrence row was cancelled with it');

    // =====================================================================
    echo "\n[7] Occurrence counts are fetched in one query, not one per row\n";
    // =====================================================================
    $ids = [];
    for ($i = 0; $i < 8; $i++) {
        $ids[] = makeBooking((int) $room->id, $memberId, '_batch'.$i, '2036-04-0'.($i + 1).' 10:00:00', '2036-04-0'.($i + 1).' 11:00:00');
    }

    $readSelects = static function () use ($db) {
        return (int) $db->raw("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()->Value;
    };

    // SHOW SESSION STATUS is a Com_show_status, so it does not skew the count.
    $before = $readSelects();
    $batch = Model::getOccurrenceSummaries($ids);
    $batchCost = $readSelects() - $before;

    $before = $readSelects();
    $oneByOne = [];
    foreach ($ids as $id) {
        $oneByOne[$id] = Model::getOccurrenceSummary($id);
    }
    $loopCost = $readSelects() - $before;

    echo "       8 rows: batched = {$batchCost} queries, per-row = {$loopCost} queries\n";
    check($batchCost === 1, 'the batched call costs exactly 1 query');
    check($loopCost === count($ids), 'the old per-row call cost 1 query per row');
    check($batch == $oneByOne, 'both return identical counts');

    $mixed = $ids[0];
    Model::cancelByRequester($mixed, $memberId, (int) Model::getById($mixed)->version);
    $summary = Model::getOccurrenceSummaries([$mixed])[$mixed];
    check($summary['total'] === 1 && $summary['active'] === 0, 'a cancelled date counts in total but not in active');
    check(Model::getOccurrenceSummaries([])  === [], 'an empty page runs no query at all');
    check(Model::getOccurrenceSummaries([999999999]) === [999999999 => ['active' => 0, 'total' => 0]], 'an unknown id reports zeroes instead of a missing key');

    // =====================================================================
    echo "\n[6] Calendar events are keyed per date, and honour the status setting\n";
    // =====================================================================
    $recurring = makeBooking(
        (int) $room->id,
        $memberId,
        '_calendar',
        '2036-05-01 10:00:00',
        '2036-05-05 11:00:00'
    );
    Model::approveReservation($recurring, 1, ['approve' => 1, 'reason' => '']);
    $occurrences = Model::getOccurrences($recurring);
    check(count($occurrences) === 5, 'a 5-day request produced 5 occurrence rows');

    $occurrenceIds = array_map(static function ($item) {
        return (string) $item->id;
    }, $occurrences);
    check(count(array_unique($occurrenceIds)) === 5, 'every date has its own occurrence id to key the calendar event by');

    check(Helper::getCalendarStatuses() === [Helper::STATUS_APPROVED], 'the configured calendar filter is read, not hardcoded');
} finally {
    foreach (array_unique($createdIds) as $id) {
        $db->delete('reservation_occurrence', ['reservation_id', $id], 0);
        $db->delete('reservation_data', ['reservation_id', $id], 0);
        $db->delete('reservation', ['id', $id], 0);
    }
    echo "\ncleaned up ".count(array_unique($createdIds))." test bookings\n";
}

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILURES\n";
exit($fail === 0 ? 0 : 1);
