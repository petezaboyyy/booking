<?php
/**
 * CLI only: this writes (and removes) rows in the logs table. Never reachable over HTTP.
 *
 * "Approver" column on the My bookings table: the name of whoever made the
 * decision the booking's current status stands on.
 *
 * Usage: docker compose exec web php tests/booking_decider_names_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Booking\Model as BookingModel;
use Booking\Helper\Controller as Helper;

$failed = 0;
$check = static function (bool $passed, string $message) use (&$failed): void {
    echo ($passed ? '  ok   ' : '  FAIL ').$message."\n";
    if (!$passed) {
        $failed++;
    }
};
$root = dirname(__DIR__);
$db = BookingModel::createDB();
$users = $db->getDatabase()->createQuery()->select('id', 'name')->from('user')->orderBy('id')->limit(2)->fetchAll();
if (count($users) < 2) {
    fwrite(STDERR, "FAIL test needs two users\n");
    exit(1);
}
[$first, $second] = $users;

// Reservation ids no real booking has, so nothing real is touched
$approved = 2000000001;
$rejected = 2000000002;
$returned = 2000000003;
$again = 2000000004;
$none = 2000000005;
$ids = [$approved, $rejected, $returned, $again, $none];
$log = static function (int $id, int $by, int $status) use ($db) {
    \Index\Log\Model::add($id, 'booking', 'Status', 'test: '.$id, $by, '', ['status' => $status]);
};

try {
    $log($approved, (int) $first->id, Helper::STATUS_PENDING_REVIEW); // a step that did not decide
    $log($approved, (int) $first->id, Helper::STATUS_APPROVED);
    $log($rejected, (int) $second->id, Helper::STATUS_REJECTED);
    $log($returned, (int) $first->id, Helper::STATUS_RETURNED_FOR_EDIT);
    $log($again, (int) $first->id, Helper::STATUS_RETURNED_FOR_EDIT); // returned, then...
    $log($again, (int) $second->id, Helper::STATUS_APPROVED);         // ...approved by someone else
    // $none has no decision at all

    $names = BookingModel::getDeciderNames($ids);
    $check(($names[$approved][Helper::STATUS_APPROVED] ?? '') === $first->name, 'an approval names who approved it');
    $check(($names[$rejected][Helper::STATUS_REJECTED] ?? '') === $second->name, 'a rejection names who rejected it');
    $check(($names[$returned][Helper::STATUS_RETURNED_FOR_EDIT] ?? '') === $first->name, 'a return for edit names who returned it');
    $check(!isset($names[$approved][Helper::STATUS_PENDING_REVIEW]), 'a log that did not decide anything is ignored');
    $check(($names[$again][Helper::STATUS_RETURNED_FOR_EDIT] ?? '') === $first->name && ($names[$again][Helper::STATUS_APPROVED] ?? '') === $second->name, 'each status keeps its own decider, so the list can pick the one for the current status');
    $check(!isset($names[$none]), 'a booking nobody decided is left out');
    $check(BookingModel::getDeciderNames([]) === [], 'no ids is not an error');

    $log($again, (int) $first->id, Helper::STATUS_APPROVED);
    $check((BookingModel::getDeciderNames([$again])[$again][Helper::STATUS_APPROVED] ?? '') === $first->name, 'the latest decision of a kind wins');
} finally {
    $db->getDatabase()->createQuery()->delete('logs', [['module', 'booking'], ['action', 'Status'], ['src_id', $ids]])->execute();
}
$check($db->getDatabase()->createQuery()->selectCount()->from('logs')->where([['src_id', $ids], ['module', 'booking']])->first()->count == 0, 'the test rows are removed again');

// The table and the list
$controller = file_get_contents($root.'/modules/booking/controllers/mybookings.php');
$check(strpos($controller, 'BookingModel::getDeciderNames(') !== false && strpos($controller, '$item->approver_name') !== false, 'the list sends each row\'s approver name, read in one batch for the page');
foreach ([
    'templates/booking/my-bookings.html',
    'templates/booking/my-bookings-admin.html',
    'ui-handoff/templates/booking/my-bookings.html',
    'ui-handoff/templates/booking/my-bookings-admin.html'
] as $file) {
    $html = file_get_contents($root.'/'.$file);
    $reason = strpos($html, "data-field='reason'");
    $approver = strpos($html, "data-field='approver_name'");
    $check($reason !== false && $approver !== false && $approver > $reason, "$file has the Approver column");
    $between = $reason !== false && $approver !== false ? substr($html, $reason, $approver - $reason) : 'x';
    $check(substr_count($between, '<th') === 1, "$file puts it right after Reason");
}
$th = json_decode(file_get_contents($root.'/language/th.json'), true);
$check(($th['Approver'] ?? '') === 'ผู้อนุมัติ', 'the heading is translated');

echo $failed === 0 ? "ALL PASS\n" : "$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
