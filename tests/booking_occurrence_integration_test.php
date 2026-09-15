<?php
/** CLI only: this writes to the database. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Booking\Model;
use Booking\Helper\Controller as Helper;

function assertBookingTest($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = Model::createDB();
$room = $db->getDatabase()->createQuery()
    ->select('id')
    ->from('rooms')
    ->where(['is_active', 1])
    ->orderBy('id')
    ->first();
$member = $db->getDatabase()->createQuery()
    ->select('id')
    ->from('user')
    ->orderBy('id')
    ->first();

assertBookingTest($room && $member, 'Integration test requires one active room and one user');

$createdIds = [];
$baseSave = [
    'id' => 0,
    'room_id' => (int) $room->id,
    'member_id' => (int) $member->id,
    'created_at' => date('Y-m-d H:i:s'),
    'topic' => '__occurrence_integration_test__',
    'comment' => '',
    'attendees' => 1,
    'begin' => '2035-01-01 10:00:00',
    'end' => '2035-01-31 11:00:00',
    'schedule_type' => Helper::SCHEDULE_WEEKLY_SLOT,
    'status' => Helper::STATUS_PENDING_REVIEW,
    'reason' => '',
    'approve' => 1,
    'closed' => 1,
    'department' => '',
    'version' => 0
];
$meta = [
    'recurrence_days' => [1, 4],
    'excluded_dates' => ['2035-01-04']
];

try {
    $a = Model::saveReservation(0, $baseSave, $meta);
    assertBookingTest(empty($a['conflicts']), 'First pending request must be accepted');
    $createdIds[] = (int) $a['id'];
    assertBookingTest(count(Model::getOccurrences((int) $a['id'])) === 8, 'Excluded date must not create an occurrence');

    $bSave = $baseSave;
    $bSave['topic'] .= '_b';
    $b = Model::saveReservation(0, $bSave, $meta);
    assertBookingTest(empty($b['conflicts']), 'Overlapping pending requests must both be accepted');
    $createdIds[] = (int) $b['id'];

    $aConflicts = Model::approveReservation((int) $a['id'], 1, ['approve' => 1, 'reason' => '']);
    assertBookingTest(empty($aConflicts), 'First request must be approved');

    $bConflicts = Model::approveReservation((int) $b['id'], 1, ['approve' => 1, 'reason' => '']);
    assertBookingTest(count($bConflicts) === 8, 'Second request must fail final approval on every overlapping date');

    $cSave = $baseSave;
    $cSave['topic'] .= '_c';
    $c = Model::saveReservation(0, $cSave, $meta);
    assertBookingTest(count($c['conflicts']) === 8 && (int) $c['id'] === 0, 'Submission must fail before creating a conflicting request');

    $adjacentSave = $baseSave;
    $adjacentSave['topic'] .= '_adjacent';
    $adjacentSave['begin'] = '2035-01-01 09:00:00';
    $adjacentSave['end'] = '2035-01-31 10:00:00';
    $adjacent = Model::saveReservation(0, $adjacentSave, $meta);
    assertBookingTest(empty($adjacent['conflicts']), 'Adjacent slots ending at the next start time must be accepted');
    $createdIds[] = (int) $adjacent['id'];

    $aOccurrences = Model::getOccurrences((int) $a['id'], Helper::STATUS_APPROVED);
    $released = $aOccurrences[0];
    Model::cancelOccurrence((int) $a['id'], (int) $released->id, (int) $member->id, 'Integration test', 2);
    $aAfterCancellation = Model::getById((int) $a['id']);
    assertBookingTest((int) $aAfterCancellation->status === Helper::STATUS_APPROVED, 'Cancelling one date must keep the remaining parent booking approved');
    assertBookingTest(!Helper::canCancelBookingByRequester($aAfterCancellation), 'Requester must not cancel an approved request');

    $singleDate = substr((string) $released->begin, 0, 10);
    $dSave = $baseSave;
    $dSave['topic'] .= '_released_date';
    $dSave['begin'] = $singleDate.' 10:00:00';
    $dSave['end'] = $singleDate.' 11:00:00';
    $dSave['schedule_type'] = Helper::SCHEDULE_DAILY_SLOT;
    $d = Model::saveReservation(0, $dSave, ['recurrence_days' => [], 'excluded_dates' => []]);
    assertBookingTest(empty($d['conflicts']), 'An individually cancelled date must become available again');
    $createdIds[] = (int) $d['id'];

    $oneDaySave = $baseSave;
    $oneDaySave['topic'] .= '_single_cancel';
    $oneDaySave['begin'] = '2035-02-01 13:00:00';
    $oneDaySave['end'] = '2035-02-01 14:00:00';
    $oneDaySave['schedule_type'] = Helper::SCHEDULE_DAILY_SLOT;
    $oneDay = Model::saveReservation(0, $oneDaySave, ['recurrence_days' => [], 'excluded_dates' => []]);
    $createdIds[] = (int) $oneDay['id'];
    Model::approveReservation((int) $oneDay['id'], 1, ['approve' => 1, 'reason' => '']);
    $oneDayOccurrence = Model::getOccurrences((int) $oneDay['id'], Helper::STATUS_APPROVED)[0];
    Model::cancelOccurrence((int) $oneDay['id'], (int) $oneDayOccurrence->id, (int) $member->id, 'Integration test', 2);
    assertBookingTest((int) Model::getById((int) $oneDay['id'])->status === Helper::STATUS_CANCELLED_BY_OFFICER, 'Cancelling the final date must cancel the parent booking');

    echo "Booking occurrence integration test passed\n";
} finally {
    if (!empty($createdIds)) {
        $db->delete('reservation_occurrence', ['reservation_id', $createdIds], 0);
        $db->delete('reservation_data', ['reservation_id', $createdIds], 0);
        $db->delete('reservation', ['id', $createdIds], 0);
    }
}
