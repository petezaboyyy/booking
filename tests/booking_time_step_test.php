<?php
/** CLI only. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Booking times are chosen in 5-minute steps.
 *
 * Free minutes let two requests overlap by odd amounts (08:00-09:52 against
 * 09:47-11:00) and leave ragged gaps in the timetable. The form's time wheels
 * offer only the steps, and the server refuses anything else, so a request sent
 * around the form cannot break the rule either.
 *
 * Usage: docker compose exec web php tests/booking_time_step_test.php
 */

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Helper\Controller as Helper;

$failed = 0;
$check = static function (bool $passed, string $message) use (&$failed): void {
    echo ($passed ? '  ok   ' : '  FAIL ').$message."\n";
    if (!$passed) {
        $failed++;
    }
};
$root = dirname(__DIR__);

// 1. The rule
$check(Helper::BOOKING_TIME_STEP_MINUTES === 5, 'the step is 5 minutes');
$check(60 % Helper::BOOKING_TIME_STEP_MINUTES === 0, 'and it divides the hour');
foreach (['00:00', '08:05', '08:10', '08:30', '08:55', '09:00', '12:45', '23:55', '8:30', '08:30:00', '23:00:00'] as $time) {
    $check(Helper::isBookingTimeOnStep($time), "$time is a valid booking time");
}
foreach (['08:01', '08:03', '08:07', '08:59', '09:01', '08:12', '08:56', '08:30:15', '24:00', '23:60', '08:3', '', 'abc', '8', '08-30'] as $time) {
    $check(!Helper::isBookingTimeOnStep($time), '"'.$time.'" is refused');
}
$check(!Helper::isBookingTimeOnStep(null), 'no time is refused');

// 2. The wheels use the same step as the server
$admin = file_get_contents($root.'/ui-handoff/modules/booking/admin.js');
$check(
    preg_match('/const BOOKING_TIME_STEP_MINUTES = (\d+);/', $admin, $m) === 1 && (int) $m[1] === Helper::BOOKING_TIME_STEP_MINUTES,
    'the wheels in admin.js step by the same number of minutes as the server'
);

// 3. The save path refuses it too
$controller = (new \ReflectionClass(\Booking\Booking\Controller::class))->newInstanceWithoutConstructor();
$validate = new \ReflectionMethod(\Booking\Booking\Controller::class, 'validateAndPrepareSaveData');
$validate->setAccessible(true);
$run = static function (?string $begin, ?string $end) use ($controller, $validate): array {
    $save = [
        'begin_date' => '2036-03-03', 'begin_time' => $begin,
        'end_date' => '2036-03-03', 'end_time' => $end,
        'schedule_type' => Helper::SCHEDULE_DAILY_SLOT, 'recurrence_days' => [], 'excluded_dates' => [],
        'version' => 0, 'comment' => '', 'topic' => 'time step test', 'attendees' => 1, 'room_id' => 1, 'use' => ''
    ];
    $booking = (object) ['id' => 0, 'member_id' => 1, 'status' => Helper::STATUS_PENDING_REVIEW, 'closed' => 1, 'approve' => 1];

    return $validate->invokeArgs($controller, [&$save, $booking]);
};
$stepMessage = \Kotchasan\Language::replace('Time must be in steps of :minutes minutes', [':minutes' => Helper::BOOKING_TIME_STEP_MINUTES]);

$errors = $run('08:30', '10:00');
$check(empty($errors), 'a request on whole and half hours is accepted');
$errors = $run('08:05', '09:50');
$check(empty($errors), 'and so is one on any 5-minute step (08:05 to 09:50)');
$errors = $run('00:00', '23:55');
$check(empty($errors), 'the first and last times of the day are accepted');

$errors = $run('08:07', '10:00');
$check(($errors['begin_time'] ?? '') === $stepMessage && !isset($errors['end_time']), 'a start time off the step is refused, on the start field');
$errors = $run('08:30', '10:48');
$check(($errors['end_time'] ?? '') === $stepMessage && !isset($errors['begin_time']), 'an end time off the step is refused, on the end field');
$errors = $run('08:01', '09:59');
$check(($errors['begin_time'] ?? '') === $stepMessage && ($errors['end_time'] ?? '') === $stepMessage, 'both are reported when both are off the step');
$errors = $run('09:47', '11:00');
$check(isset($errors['begin_time']), 'the 09:47 start that would overlap a 08:00-09:52 booking by odd minutes is refused');
$errors = $run('08:30:20', '10:00');
$check(isset($errors['begin_time']), 'seconds are not a way around it');
$errors = $run('', '10:00');
$check(($errors['begin_time'] ?? '') === 'Please fill in', 'an empty time still says "Please fill in", not the step message');
$errors = $run(null, '10:00');
$check(isset($errors['begin_time']), 'a time that is not a time at all is refused');
$errors = $run('10:00', '09:55');
$check(($errors['end_time'] ?? '') === 'End time must be greater than begin time', 'times on the step are still compared (end after start)');
$check(strpos($stepMessage, '5') !== false, 'the message names the step ('.$stepMessage.')');

// 4. The forms hold the time in a field the wheels fill in
$forms = [
    'templates/booking/booking-recurring.html' => true,
    'templates/booking/booking-recurring-admin.html' => false,
    'ui-handoff/templates/booking/booking-recurring.html' => true,
    'ui-handoff/templates/booking/booking-recurring-admin.html' => false
];
foreach ($forms as $file => $required) {
    $html = file_get_contents($root.'/'.$file);
    foreach (['begin_time', 'end_time'] as $id) {
        $name = "$file $id";
        $found = preg_match('/<input\b[^>]*\bid=([\'"])'.$id.'\1[^>]*>/s', $html, $m) === 1;
        $check($found, "$name is a field");
        if (!$found) {
            continue;
        }
        $tag = $m[0];
        $check(strpos($tag, 'data-time-wheel') !== false, "$name is picked on the time wheels");
        $check(strpos($tag, 'name="'.$id.'"') !== false || strpos($tag, "name='".$id."'") !== false, "$name keeps its field name");
        $check(preg_match('/data-attr=([\'"])value:'.$id.'\1/', $tag) === 1, "$name is still filled from the loaded booking");
        $check($required === (strpos($tag, ' required') !== false), "$name keeps its required flag as before");
        $check(strpos($tag, 'pattern=') === false && strpos($tag, 'maxlength') === false, "$name no longer carries the free-typing pattern and mask");
    }
    $check(preg_match('/<select\b[^>]*id=[\'"](begin|end)_time/', $html) === 0, "$file has no dropdown for the times");
}

echo $failed === 0 ? "ALL PASS\n" : "$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
