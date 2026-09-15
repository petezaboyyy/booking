<?php
/** CLI only: this writes to the database. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__.'/../load.php';
Kotchasan::createWebApplication('Gcms\Config');

use Booking\Helper\Controller as Helper;

$fail = 0;
function check($cond, $msg) { global $fail; if ($cond) { echo "  ok  $msg\n"; } else { $fail++; echo "  FAIL $msg\n"; } }

// countBookingRangeDays
check(Helper::countBookingRangeDays('2026-01-01', '2026-01-01') === 1, 'same day = 1');
check(Helper::countBookingRangeDays('2026-01-01', '2026-01-30') === 30, '30-day span');
check(Helper::countBookingRangeDays('2026-01-30', '2026-01-01') === 0, 'reversed = 0');
check(Helper::countBookingRangeDays('2026-01-01', '2029-01-01') === 1097, 'multi-year span');
check(Helper::countBookingRangeDays('2026-01-01', '2029-01-01') > Helper::MAX_BOOKING_RANGE_DAYS, 'multi-year exceeds cap');

// buildBookingOccurrences must stay bounded even for an absurd range
$schedule = [
    'begin_date' => '2026-01-01',
    'end_date' => '2036-01-01',
    'begin_time' => '09:00:00',
    'end_time' => '10:00:00',
    'schedule_type' => Helper::SCHEDULE_DAILY_SLOT,
    'recurrence_days' => []
];
$t = microtime(true);
$occ = Helper::buildBookingOccurrences($schedule, []);
$elapsed = microtime(true) - $t;
check(count($occ) <= Helper::MAX_BOOKING_OCCURRENCES, 'ten-year range capped at '.count($occ).' occurrences');
check($elapsed < 1.0, sprintf('expansion stays fast (%.3fs)', $elapsed));

// normal range still expands fully
$schedule['end_date'] = '2026-01-30';
$occ = Helper::buildBookingOccurrences($schedule, ['2026-01-04']);
check(count($occ) === 29, '30-day range minus 1 excluded = '.count($occ));

// excluded_dates payload length that used to overflow varchar(150)
$dates = [];
for ($i = 1; $i <= 30; $i++) { $dates[] = sprintf('2026-01-%02d', $i); }
$payload = implode(',', $dates);
check(strlen($payload) === 329, 'a 30-day exclusion list is '.strlen($payload).' chars');
check(strlen($payload) > 150, 'which overflows the old varchar(150)');

// approved bookings can never be deleted by the requester
$statuses = Helper::getBookerDeleteStatuses();
check(!in_array(Helper::STATUS_APPROVED, $statuses, true), 'approved not in booker delete statuses');
check(Helper::canDeleteBookingByRequester((object) ['status' => Helper::STATUS_APPROVED]) === false, 'requester cannot delete approved');
check(Helper::canDeleteBookingByRequester((object) ['status' => Helper::STATUS_CANCELLED_BY_REQUESTER]) === true, 'requester can delete own cancelled');
check(Helper::canCancelBookingByRequester((object) ['status' => Helper::STATUS_APPROVED]) === false, 'requester cannot cancel approved');

foreach (Helper::getBookerDeleteStatusOptions() as $option) {
    check((int) $option['value'] !== Helper::STATUS_APPROVED, 'settings option '.$option['value'].' is not approved');
}

// calendar statuses come from config instead of being hardcoded
check(Helper::getCalendarStatuses() === [1], 'calendar statuses read from config');

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILURES\n";
exit($fail === 0 ? 0 : 1);
