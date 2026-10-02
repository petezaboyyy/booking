<?php
/** CLI only: this writes to the database. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Rules for the approved-booking CSV export.
 *
 * Usage: docker compose exec web php tests/booking_csv_export_test.php
 */

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Booking\Model;
use Booking\Helper\Controller as Helper;
use Gcms\Api as ApiController;

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

/** Reach the controller's private cell sanitiser. */
function cell($value): string
{
    static $method = null;
    if ($method === null) {
        $method = new \ReflectionMethod(\Booking\Export\Controller::class, 'cell');
        $method->setAccessible(true);
    }
    return $method->invoke(null, $value);
}

$db = Model::createDB();
$room = $db->getDatabase()->createQuery()->select('id')->from('rooms')->where(['is_active', 1])->orderBy('id')->first();
$member = $db->getDatabase()->createQuery()->select('id')->from('user')->orderBy('id')->first();
if (!$room || !$member) {
    fwrite(STDERR, "Test requires one active room and one user\n");
    exit(1);
}

/** A five-day request, one row per day once approved. */
function makeBooking(int $roomId, int $memberId, string $suffix, string $begin, string $end): int
{
    global $createdIds;
    $result = Model::saveReservation(0, [
        'id' => 0,
        'room_id' => $roomId,
        'member_id' => $memberId,
        'created_at' => date('Y-m-d H:i:s'),
        'topic' => '__csv_test__'.$suffix,
        'comment' => '',
        'attendees' => 3,
        'begin' => $begin,
        'end' => $end,
        'schedule_type' => Helper::SCHEDULE_DAILY_SLOT,
        'status' => Helper::STATUS_PENDING_REVIEW,
        'reason' => '',
        'approve' => 1,
        'closed' => 1,
        'department' => '',
        'version' => 0
    ], ['recurrence_days' => [], 'excluded_dates' => []]);
    $id = (int) $result['id'];
    if ($id > 0) {
        $createdIds[] = $id;
    }
    return $id;
}

try {
    // =====================================================================
    echo "\n[1] Only an approved request exports\n";
    // =====================================================================
    $pending = makeBooking((int) $room->id, (int) $member->id, '_pending', '2037-03-02 09:00:00', '2037-03-06 10:00:00');
    check($pending > 0, 'created a five-day pending request');
    check(Model::getApprovedExportRows($pending) === [], 'a pending request exports nothing');

    check(Model::approveReservation($pending, 1, ['approve' => 1, 'reason' => '']) === [], 'officer approved it');
    $rows = Model::getApprovedExportRows($pending);
    check(count($rows) === 5, 'an approved five-day request gives one row per day ('.count($rows).')');

    $approved = $pending;
    $rejected = makeBooking((int) $room->id, (int) $member->id, '_rejected', '2037-04-02 09:00:00', '2037-04-03 10:00:00');
    Model::updateStatus($rejected, Helper::STATUS_REJECTED);
    check(Model::getApprovedExportRows($rejected) === [], 'a rejected request exports nothing');

    check(Model::getApprovedExportRows(0) === [], 'id 0 exports nothing');
    check(Model::getApprovedExportRows(999999999) === [], 'an unknown id exports nothing');

    // =====================================================================
    echo "\n[2] Cancelling single dates removes only those rows\n";
    // =====================================================================
    $occurrences = Model::getOccurrences($approved, Helper::STATUS_APPROVED);
    $version = (int) Model::getById($approved)->version;
    Model::cancelOccurrence($approved, (int) $occurrences[0]->id, (int) $member->id, 'CSV test', $version);

    $rows = Model::getApprovedExportRows($approved);
    check(count($rows) === 4, 'cancelling one of five leaves four rows ('.count($rows).')');
    $cancelledDate = substr((string) $occurrences[0]->begin, 0, 10);
    $exportedDates = array_map(static function ($row) {
        return substr((string) $row->begin, 0, 10);
    }, $rows);
    check(!in_array($cancelledDate, $exportedDates, true), 'the cancelled date is absent from the export');
    check(count($exportedDates) === count(array_unique($exportedDates)), 'no date is duplicated by the joins');

    $sorted = $exportedDates;
    sort($sorted);
    check($exportedDates === $sorted, 'rows come back in date order');

    // =====================================================================
    echo "\n[3] Cancelling every date stops the export\n";
    // =====================================================================
    foreach (Model::getOccurrences($approved, Helper::STATUS_APPROVED) as $occurrence) {
        $current = Model::getById($approved);
        if ((int) $current->status !== Helper::STATUS_APPROVED) {
            break;
        }
        Model::cancelOccurrence($approved, (int) $occurrence->id, (int) $member->id, 'CSV test', (int) $current->version);
    }
    check(Model::getApprovedExportRows($approved) === [], 'with every date cancelled there is nothing to export — no empty file');

    // =====================================================================
    echo "\n[3b] Several requests export together\n";
    // =====================================================================
    $a = makeBooking((int) $room->id, (int) $member->id, '_multi_a', '2037-06-01 08:00:00', '2037-06-03 09:00:00');
    $b = makeBooking((int) $room->id, (int) $member->id, '_multi_b', '2037-07-01 10:00:00', '2037-07-02 11:00:00');
    Model::approveReservation($a, 1, ['approve' => 1, 'reason' => '']);
    Model::approveReservation($b, 1, ['approve' => 1, 'reason' => '']);

    $both = Model::getApprovedExportRows([$a, $b]);
    check(count($both) === 5, 'three days plus two days gives five rows ('.count($both).')');
    $idsSeen = array_values(array_unique(array_map(static function ($row) {
        return (int) $row->id;
    }, $both)));
    sort($idsSeen);
    $expected = [$a, $b];
    sort($expected);
    check($idsSeen === $expected, 'both request ids appear, so rows stay attributable');

    $dates = array_map(static function ($row) {
        return (string) $row->begin;
    }, $both);
    $sortedDates = $dates;
    sort($sortedDates);
    check($dates === $sortedDates, 'the combined rows are still in date order');

    $pendingC = makeBooking((int) $room->id, (int) $member->id, '_multi_c', '2037-08-01 10:00:00', '2037-08-01 11:00:00');
    check(
        count(Model::getApprovedExportRows([$a, $pendingC])) === 3,
        'mixing an approved and a pending request exports only the approved one'
    );
    check(Model::getApprovedExportRows([$pendingC, 0, -5]) === [], 'a selection with nothing approved exports nothing');
    check(count(Model::getApprovedExportRows([$a, $a, $a])) === 3, 'the same id ticked twice is not duplicated');

    // =====================================================================
    echo "\n[4] Who is allowed to press the button\n";
    // =====================================================================
    check(ApiController::isAdmin((object) ['status' => 1]) === true, 'an admin is allowed');
    check(ApiController::isAdmin((object) ['status' => 0]) === false, 'an ordinary member is refused');
    check(ApiController::isAdmin((object) ['status' => 2]) === false, 'an approver who is not an admin is refused');
    check(ApiController::isAdmin(null) === false, 'an anonymous caller is refused');

    // =====================================================================
    echo "\n[4b] Checkboxes stay on every row so Delete keeps working\n";
    // =====================================================================
    $options = (new \ReflectionMethod(\Booking\Approvals\Controller::class, 'getOptions'));
    $options->setAccessible(true);
    $table = $options->invoke(
        (new \ReflectionClass(\Booking\Approvals\Controller::class))->newInstanceWithoutConstructor(),
        [],
        (object) ['id' => 1, 'status' => 1, 'permission' => ['super_admin']]
    )['_table'];
    check(!empty($table['showCheckbox']), 'an admin gets checkboxes');
    check(
        empty($table['checkboxCondition']),
        'no row is locked out of selection — cancelled and rejected requests must stay deletable'
    );
    check(
        ($table['actions']['delete'] ?? '') === 'Delete',
        'bulk delete is still offered'
    );
    // The export, not the checkbox, is what refuses a non-approved request.
    $mixed = Model::getApprovedExportRows([$a, $rejected, $pendingC]);
    check(
        count($mixed) === 3 && (int) $mixed[0]->id === $a,
        'ticking approved, rejected and pending together exports only the approved one'
    );

    // =====================================================================
    echo "\n[5] Cells are safe for a spreadsheet\n";
    // =====================================================================
    check(cell('=cmd|calc') === "'=cmd|calc", 'a leading = is neutralised');
    foreach (['+', '-', '@'] as $lead) {
        check(cell($lead.'SUM(A1)') === "'".$lead.'SUM(A1)', 'a leading '.$lead.' is neutralised');
    }
    check(cell('ห้องประชุม 1') === 'ห้องประชุม 1', 'ordinary Thai text is untouched');
    check(cell("บรรทัด1\nบรรทัด2") === 'บรรทัด1 บรรทัด2', 'a newline in a note becomes a space, keeping one row');
    check(cell("a\r\nb\tc") === 'a b c', 'CRLF and tabs collapse too');
    check(cell('ก, ข, ค') === 'ก, ข, ค', 'commas survive — fputcsv quotes them');
    check(cell(null) === '', 'null becomes an empty cell');
    check(cell(42) === '42', 'a number becomes its text');

    // =====================================================================
    echo "\n[6] The sheet carries what a schedule needs and no more\n";
    // =====================================================================
    $fresh = makeBooking((int) $room->id, (int) $member->id, '_fields', '2037-05-04 13:00:00', '2037-05-04 14:00:00');
    Model::approveReservation($fresh, 1, ['approve' => 1, 'reason' => '']);
    $row = Model::getApprovedExportRows($fresh)[0];
    foreach (['password', 'salt', 'token', 'line_uid', 'telegram_id', 'permission', 'activatecode'] as $secret) {
        check(!property_exists($row, $secret), 'the query never selects '.$secret);
    }
    foreach (['member_name', 'member_email', 'room_name', 'room_number', 'room_building', 'begin', 'end', 'course_code', 'topic', 'use_id', 'attendees', 'comment'] as $needed) {
        check(property_exists($row, $needed), 'the sheet can show '.$needed);
    }
    // Columns dropped as noise are no longer fetched either.
    foreach (['created_at', 'approved_at', 'department', 'member_department'] as $dropped) {
        check(!property_exists($row, $dropped), 'no longer queries '.$dropped.', which the sheet stopped showing');
    }
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
