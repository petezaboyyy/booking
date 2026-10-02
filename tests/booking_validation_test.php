<?php
/** CLI only: this writes to the database. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Boundary tests for the booking date-range and note-length limits.
 * Calls the controller's own validation so the rules are tested where they run.
 *
 * Usage: docker compose exec web php tests/booking_validation_test.php
 */

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Helper\Controller as Helper;

$fail = 0;

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

$controller = (new \ReflectionClass(\Booking\Booking\Controller::class))->newInstanceWithoutConstructor();
$validate = new \ReflectionMethod(\Booking\Booking\Controller::class, 'validateAndPrepareSaveData');
$validate->setAccessible(true);

/** Run the controller validation for one begin/end date pair. */
function validateRange(string $beginDate, string $endDate, int $roomId = 1): array
{
    global $controller, $validate;
    $save = [
        'begin_date' => $beginDate,
        'begin_time' => '10:00',
        'end_date' => $endDate,
        'end_time' => '11:00',
        'schedule_type' => Helper::SCHEDULE_DAILY_SLOT,
        'recurrence_days' => [],
        'excluded_dates' => [],
        'version' => 0,
        'comment' => '',
        'topic' => 'range test',
        'attendees' => 1,
        'room_id' => $roomId,
        'use' => ''
    ];
    $booking = (object) ['id' => 0, 'member_id' => 1, 'status' => Helper::STATUS_PENDING_REVIEW, 'closed' => 1, 'approve' => 1];
    return $validate->invokeArgs($controller, [&$save, $booking]);
}

echo "\n[4] The reservation period is capped before anything expands it\n";
$max = Helper::MAX_BOOKING_RANGE_DAYS;
echo "       limit = $max days\n";

$end = date('Y-m-d', strtotime('2036-01-01 +'.($max - 1).' days'));
$t = microtime(true);
$errors = validateRange('2036-01-01', $end);
check(empty($errors), "a $max-day range (exactly the limit) is accepted");

$end = date('Y-m-d', strtotime('2036-01-01 +'.$max.' days'));
$errors = validateRange('2036-01-01', $end);
check(isset($errors['end_date']), 'one day over the limit is rejected');
echo "       message: ".($errors['end_date'] ?? '-')."\n";

$errors = validateRange('2036-01-01', '2046-01-01');
$elapsed = microtime(true) - $t;
check(isset($errors['end_date']), 'a ten-year range is rejected');
check($elapsed < 1.0, sprintf('all three ran in %.3fs — no runaway day loop', $elapsed));

$errors = validateRange('2036-01-01', '2036-01-01');
check(empty($errors), 'a single-day booking still works');

$errors = validateRange('2036-01-01', '2036-01-30');
check(empty($errors), 'an ordinary 30-day booking still works');

echo "\n[5] The decision note fits the column that stores it\n";
$limit = Helper::MAX_CANCELLATION_REASON_LENGTH;
echo "       limit = $limit characters\n";
check($limit === 255, 'the constant matches cancellation_reason varchar(255)');

$template = file_get_contents(__DIR__.'/../templates/booking/review.html');
check(
    strpos($template, 'id="approval_reason" name="approval_reason" rows="4" maxlength="255"') !== false,
    'the textarea carries maxlength="255" so the browser stops at the limit'
);

$controllerSource = file_get_contents(__DIR__.'/../modules/booking/controllers/review.php');
check(
    substr_count($controllerSource, 'mb_strlen($reason) > Helper::MAX_CANCELLATION_REASON_LENGTH') === 1,
    'the per-date cancellation path checks the length server-side'
);
check(
    substr_count($controllerSource, 'mb_strlen($approvalReason) > Helper::MAX_CANCELLATION_REASON_LENGTH') === 1,
    'the approve/reject path checks it too'
);
check(mb_strlen(str_repeat('ก', 255)) === 255, 'the check counts characters, not bytes (Thai text is 3 bytes each)');

echo "\n[6] Only booking purposes remain configurable\n";
check(array_keys(\Booking\Category\Controller::items()) === ['use'], 'the booking menu has no equipment category');

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILURES\n";
exit($fail === 0 ? 0 : 1);
