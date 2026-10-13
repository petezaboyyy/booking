<?php
/**
 * CLI only: this writes to the database. Never reachable over HTTP.
 *
 * Competing requests: several pending requests for the same room at overlapping
 * times. Covers finding the group, ranking it with rules that can be explained,
 * and the count shown in the approvals table.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Booking\Model as BookingModel;
use Booking\Competition\Model;
use Booking\Helper\Controller as Helper;

$failed = 0;
$check = static function (bool $passed, string $message) use (&$failed): void {
    echo ($passed ? '  ok   ' : '  FAIL ').$message."\n";
    if (!$passed) {
        $failed++;
    }
};
$db = BookingModel::createDB();
$rooms = $db->getDatabase()->createQuery()->select('id')->from('rooms')->where(['is_active', 1])->orderBy('id')->limit(2)->fetchAll();
$member = $db->getDatabase()->createQuery()->select('id')->from('user')->orderBy('id')->first();
if (count($rooms) < 2 || !$member) {
    fwrite(STDERR, "FAIL test needs two active rooms and one user\n");
    exit(1);
}
[$roomA, $roomB] = [(int) $rooms[0]->id, (int) $rooms[1]->id];

$base = [
    'id' => 0,
    'room_id' => $roomA,
    'member_id' => (int) $member->id,
    'topic' => '__competition_test__',
    'comment' => '',
    'attendees' => 1,
    'schedule_type' => Helper::SCHEDULE_DAILY_SLOT,
    'status' => Helper::STATUS_PENDING_REVIEW,
    'reason' => '',
    'approve' => 1,
    'closed' => 1,
    'department' => '',
    'version' => 0
];
$none = ['recurrence_days' => [], 'excluded_dates' => []];
$created = [];
$make = static function (array $over, array $meta = []) use ($base, $none, &$created): int {
    $result = BookingModel::saveReservation(0, array_merge($base, $over), array_merge($none, $meta));
    $created[] = (int) $result['id'];

    return (int) $result['id'];
};

try {
    // 1. Finding the group
    $seats = Model::roomSeats($roomA);
    $a = $make(['created_at' => '2037-01-01 08:00:00', 'begin' => '2037-05-04 09:00:00', 'end' => '2037-05-04 11:00:00', 'attendees' => 40, 'course_code' => '204321']);
    $b = $make(['created_at' => '2037-01-01 07:00:00', 'begin' => '2037-05-04 09:00:00', 'end' => '2037-05-04 11:00:00', 'attendees' => 5]);
    $c = $make(['created_at' => '2037-01-01 09:00:00', 'begin' => '2037-05-04 10:00:00', 'end' => '2037-05-04 12:00:00', 'attendees' => 30]);
    $other = [
        'next day' => $make(['created_at' => '2037-01-01 07:30:00', 'begin' => '2037-05-05 09:00:00', 'end' => '2037-05-05 11:00:00']),
        'back to back' => $make(['created_at' => '2037-01-01 07:31:00', 'begin' => '2037-05-04 11:00:00', 'end' => '2037-05-04 12:00:00']),
        'other room' => $make(['room_id' => $roomB, 'created_at' => '2037-01-01 07:32:00', 'begin' => '2037-05-04 09:00:00', 'end' => '2037-05-04 11:00:00']),
        'rejected' => $make(['status' => Helper::STATUS_REJECTED, 'created_at' => '2037-01-01 07:33:00', 'begin' => '2037-05-04 09:00:00', 'end' => '2037-05-04 11:00:00'])
    ];

    $group = Model::competitors($a);
    $ids = array_column($group['items'], 'id');
    sort($ids);
    $expected = [$a, $b, $c];
    sort($expected);
    $check($ids === $expected, 'the group is every pending request for the same room at an overlapping time, including this one');
    $check($group['items'][0]['id'] === $a && $group['items'][0]['is_current'] === true, 'the request being reviewed comes first');
    foreach ($other as $why => $id) {
        $check(!in_array($id, $ids, true), "a request is left out when it is: $why");
    }
    $check(Model::competitors($other['next day'])['items'] === [], 'a request nobody else wants has no group');
    $check(Model::competitors($other['rejected'])['items'] === [], 'a request that is no longer pending has no group');

    // The badge in the approvals table
    $counts = Model::countCompetitors(array_merge([$a, $b, $c], array_values($other)));
    ksort($counts); // the order of the keys is not part of the contract
    // "back to back" (11-12) misses A and B (9-11) but does overlap C (10-12), so
    // C has three rivals and "back to back" has one.
    $expected = [$a => 2, $b => 2, $c => 3, $other['back to back'] => 1];
    ksort($expected);
    $check($counts === $expected, 'the approvals table counts each pending request\'s competitors'.($counts === $expected ? '' : ' - got '.json_encode($counts)));
    $check(Model::countCompetitors([]) === [] && Model::countCompetitors([$other['next day']]) === [], 'no ids, or a request nobody competes with, gives no count');
    $check(!isset($counts[$other['rejected']]) && !isset($counts[$other['other room']]) && !isset($counts[$other['next day']]), 'a rejected request, one in another room and one on another day are not flagged');

    $table = new class extends \Booking\Approvals\Controller {
        public function rows(array $rows): array
        {
            return $this->formatDatas($rows);
        }
    };
    $rows = array_map(static function ($id) use ($db) {
        return $db->first('reservation', ['id', $id]);
    }, [$a, $other['next day'], $other['rejected']]);
    $rows = $table->rows($rows);
    $check($rows[0]->competing_text !== '' && strpos($rows[0]->competing_text, '2') !== false, 'a competing row gets a text saying how many it competes with');
    $check($rows[1]->competing_text === '' && $rows[2]->competing_text === '', 'rows that do not compete, or are not pending, stay blank');

    // 2. Ranking
    $ranked = Model::rank($group['items'], 50);
    $order = array_column($ranked, 'id');
    $score = array_column($ranked, 'score', 'id');
    $check($order === [$a, $b, $c], 'ranking: course code + good room fit outweigh being earlier (A, then B, then C)');
    $check($score[$a] === 60 && $score[$b] === 30 && $score[$c] === 25, 'scores add up as documented (A 15+25+20, B 30+0, C 0+25)');
    $check(array_column($ranked, 'rank') === [1, 2, 3], 'each request gets its rank');
    $check(count($ranked[0]['reasons']) >= 3 && count($ranked[1]['reasons']) >= 2, 'every score comes with the reasons behind it');

    // What the review page receives
    $reviewController = new class extends \Booking\Review\Controller {
        public function payload(int $id): array
        {
            return $this->competitionPayload($id);
        }

        public function blocked($row): ?array
        {
            return $this->blockedPayload($row);
        }

        public function rejectClashes($login, int $approvedId, string $reason, ?callable $after = null): array
        {
            return $this->rejectPendingClashes($login, $approvedId, $reason, $after);
        }
    };
    $payload = $reviewController->payload($a);
    $check($payload['seats'] === $seats && array_column($payload['items'], 'id') === [$a, $b, $c], 'the review page gets the ranked group and the room\'s seats');
    $check(!array_key_exists('use_id', $payload['items'][0]) && isset($payload['items'][0]['score'], $payload['items'][0]['reasons'], $payload['items'][0]['overlap_dates']), 'without internal fields, with score, reasons and overlap');
    $check($reviewController->payload($other['next day']) === ['seats' => 0, 'items' => []], 'a request nobody competes with gets an empty group');

    $over = Model::rank([
        ['id' => 1, 'created_at' => '2037-01-01 08:00:00', 'attendees' => 60, 'course_code' => '', 'use_id' => '', 'overlap_dates' => 1, 'topic' => '', 'comment' => '', 'reservation_text' => '', 'member_name' => '', 'is_current' => true],
        ['id' => 2, 'created_at' => '2037-01-01 09:00:00', 'attendees' => 10, 'course_code' => '', 'use_id' => '', 'overlap_dates' => 1, 'topic' => '', 'comment' => '', 'reservation_text' => '', 'member_name' => '', 'is_current' => false]
    ], 50);
    $check($over[0]['id'] === 2, 'a request for more people than the room holds ranks below a later one that fits');

    $tie = Model::rank([
        ['id' => 5, 'created_at' => '2037-01-01 09:00:00', 'attendees' => 0, 'course_code' => '', 'use_id' => '', 'overlap_dates' => 1, 'topic' => '', 'comment' => '', 'reservation_text' => '', 'member_name' => '', 'is_current' => false],
        ['id' => 4, 'created_at' => '2037-01-01 08:00:00', 'attendees' => 0, 'course_code' => '', 'use_id' => '', 'overlap_dates' => 1, 'topic' => '', 'comment' => '', 'reservation_text' => '', 'member_name' => '', 'is_current' => false]
    ], 0);
    $check(array_column($tie, 'id') === [4, 5], 'with nothing else to separate them, the earlier submission goes first');
    $check(Model::rank([], 10) === [], 'nothing to rank is not an error');

    // 3. Requests made by staff come first
    $mk = static function (int $id, string $at, int $people, string $code, int $status): array {
        return ['id' => $id, 'created_at' => $at, 'attendees' => $people, 'course_code' => $code, 'use_id' => '', 'overlap_dates' => 1,
            'topic' => '', 'comment' => '', 'reservation_text' => '', 'member_name' => '', 'is_current' => false, 'member_status' => $status];
    };
    $ids = static function (array $ranked): array {
        return array_column($ranked, 'id');
    };
    // member: first + good fit (30 + 25 = 55); staff: last, 2 of 50 seats, no code (just the role: 60)
    $bareStaff = Model::rank([$mk(1, '2037-01-01 08:00:00', 40, '', 0), $mk(2, '2037-01-01 09:00:00', 2, '', 2)], 50);
    $check($ids($bareStaff) === [2, 1] && $bareStaff[0]['score'] === 60, 'a staff request outranks a member who is first and fills the room (60 against 55)');
    // member: first + good fit + course code (75)
    $fullMember = Model::rank([$mk(1, '2037-01-01 08:00:00', 40, '204321', 0), $mk(2, '2037-01-01 09:00:00', 2, '', 2)], 50);
    $check($ids($fullMember) === [1, 2], 'a member ahead on every other rule (75) still outranks a bare staff request (60)');
    // the room cannot seat the staff request: 60 - 35 = 25 against a plain member who is first (30)
    $tooMany = Model::rank([$mk(1, '2037-01-01 08:00:00', 5, '', 0), $mk(2, '2037-01-01 09:00:00', 80, '', 2)], 50);
    $check($ids($tooMany) === [1, 2], 'a staff request the room cannot seat does not jump the queue');
    $check(strpos(implode(' ', $bareStaff[0]['reasons']), 'เจ้าหน้าที่') !== false, 'the reason names the role, as the site calls it');
    $check(strpos(implode(' ', $bareStaff[1]['reasons']), 'เจ้าหน้าที่') === false, 'and a member gets no such line');
    $plain = Model::rank([$mk(1, '2037-01-01 08:00:00', 40, '', 0), $mk(2, '2037-01-01 09:00:00', 40, '', 1)], 50);
    $check($ids($plain) === [1, 2], 'an administrator is not a priority role unless the site lists that status');

    // 4. End to end with a real staff account
    $officerId = (int) $db->insert('user', [
        'username' => '__test_officer__', 'name' => '__test_officer__', 'password' => 'x', 'salt' => 'x',
        'status' => 2, 'active' => 1, 'social' => 'user', 'created_at' => date('Y-m-d H:i:s')
    ]);
    $slot = ['begin' => '2037-06-01 09:00:00', 'end' => '2037-06-01 11:00:00', 'attendees' => 10];
    $memberBooking = $make($slot + ['created_at' => '2037-01-02 08:00:00']);
    $officerBooking = $make($slot + ['member_id' => $officerId, 'created_at' => '2037-01-02 09:00:00']);
    $real = Model::competitors($memberBooking);
    $statuses = array_column($real['items'], 'member_status', 'id');
    $check(($statuses[$officerBooking] ?? null) === 2, 'the group carries each requester\'s role');
    $realRanked = Model::rank($real['items'], $real['seats']);
    $check($realRanked[0]['id'] === $officerBooking, 'a staff booking submitted later still comes first against a member\'s earlier one');

    // 5. A request is approved: the ones it beat are flagged, and can be rejected together
    $day = ['room_id' => $roomA, 'attendees' => 10];
    $winner = $make($day + ['created_at' => '2037-02-01 08:00:00', 'begin' => '2037-07-01 09:00:00', 'end' => '2037-07-01 11:00:00']);
    $loserSame = $make($day + ['created_at' => '2037-02-01 09:00:00', 'begin' => '2037-07-01 09:00:00', 'end' => '2037-07-01 11:00:00']);
    $loserPartly = $make($day + ['created_at' => '2037-02-01 10:00:00', 'begin' => '2037-07-01 10:00:00', 'end' => '2037-07-01 12:00:00']);
    $nextDay = $make($day + ['created_at' => '2037-02-01 11:00:00', 'begin' => '2037-07-02 09:00:00', 'end' => '2037-07-02 11:00:00']);
    $elsewhere = $make(['room_id' => $roomB, 'attendees' => 10, 'created_at' => '2037-02-01 12:00:00', 'begin' => '2037-07-01 09:00:00', 'end' => '2037-07-01 11:00:00']);

    $beforeApproval = Model::conflictingWithApproved([$winner, $loserSame, $loserPartly, $nextDay, $elsewhere]);
    $check($beforeApproval === [], 'while nothing is approved, nothing is flagged as clashing with an approved booking');

    $check(BookingModel::approveReservation($winner, 1, ['approve' => 1, 'reason' => '']) === [], 'the first request is approved');
    $flags = Model::conflictingWithApproved([$winner, $loserSame, $loserPartly, $nextDay, $elsewhere]);
    ksort($flags);
    $expectedFlags = [$loserSame => 1, $loserPartly => 1];
    ksort($expectedFlags);
    $check($flags === $expectedFlags, 'afterwards the requests that clash with it are flagged (and not the winner, another day or another room)'.($flags === $expectedFlags ? '' : ' - got '.json_encode($flags)));
    $check(Model::conflictingWithApproved([]) === [], 'no ids, no flags');

    $clash = Model::pendingClashingWith($winner);
    $clashIds = array_column($clash, 'id');
    sort($clashIds);
    $expectedClash = [$loserSame, $loserPartly];
    sort($expectedClash);
    $check($clashIds === $expectedClash && $clash[0]['overlap_dates'] === 1 && $clash[0]['reservation_text'] !== '', 'the approved request lists what it left behind, with the overlap and the period');
    $check(Model::pendingClashingWith($loserSame) === [], 'a request that is not approved has nothing left behind');

    // what the approvals table shows
    $rows = $table->rows(array_map(static function ($id) use ($db) {
        return $db->first('reservation', ['id', $id]);
    }, [$loserSame, $nextDay]));
    $check($rows[0]->blocked_text !== '' && $rows[0]->competing_text === '', 'a request the room was taken from shows the warning instead of the competition count');
    $check($rows[1]->blocked_text === '' && $rows[1]->competing_text === '', 'a request nobody clashes with shows neither');

    // what the review page shows on such a request
    $lost = $reviewController->blocked(\Booking\Review\Model::get($loserSame));
    $check($lost !== null && count($lost['conflicts']) === 1 && $lost['approved_ids'] === [$winner], 'the review page of a request that lost the room names the approved booking and the date');
    $check($reviewController->blocked(\Booking\Review\Model::get($nextDay)) === null, 'and a request that did not lose it shows no warning');

    // rejecting them together
    $member2 = (object) ['id' => (int) $member->id, 'status' => 0, 'permission' => '', 'metas' => []];
    $noRights = $reviewController->rejectClashes($member2, $winner, 'no rights');
    $check($noRights['rejected'] === [] && count($noRights['skipped']) === 2, 'someone who may not approve those requests rejects none and is told they were skipped');
    $check((int) $db->first('reservation', ['id', $loserSame])->status === Helper::STATUS_PENDING_REVIEW, 'and they stay pending');

    $admin = (object) ['id' => (int) $member->id, 'status' => 1, 'permission' => '', 'metas' => []];
    $notified = [];
    $done = $reviewController->rejectClashes($admin, $winner, 'room taken', static function (int $id) use (&$notified) {
        $notified[] = $id;
    });
    sort($done['rejected']);
    $check($done['rejected'] === $expectedClash && $done['skipped'] === [], 'an approver rejects every clashing request');
    sort($notified);
    $check($notified === $expectedClash, 'and each rejection is reported for logging and for telling the requester');
    $afterRow = $db->first('reservation', ['id', $loserSame]);
    $check((int) $afterRow->status === Helper::STATUS_REJECTED && $afterRow->reason === 'room taken', 'a rejected request carries the status and the reason');
    $check(array_unique(array_column(BookingModel::getOccurrences($loserSame), 'status')) === [Helper::STATUS_REJECTED], 'and so do its dates');
    $check((int) $db->first('reservation', ['id', $winner])->status === Helper::STATUS_APPROVED, 'the approved booking is untouched');
    $check((int) $db->first('reservation', ['id', $nextDay])->status === Helper::STATUS_PENDING_REVIEW
        && (int) $db->first('reservation', ['id', $elsewhere])->status === Helper::STATUS_PENDING_REVIEW, 'requests that did not clash are left pending');
    $check($reviewController->rejectClashes($admin, $winner, 'again')['rejected'] === [], 'doing it again finds nothing left to reject');
} finally {
    if (!empty($officerId)) {
        $db->delete('user', ['id', $officerId], 0);
    }
    if (!empty($created)) {
        $db->delete('reservation_occurrence', ['reservation_id', $created], 0);
        $db->delete('reservation_data', ['reservation_id', $created], 0);
        $db->delete('reservation', ['id', $created], 0);
    }
}

echo $failed === 0 ? "ALL PASS\n" : "$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
