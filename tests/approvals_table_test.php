<?php
/** CLI only, like the rest of the suite. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * The booking approvals table.
 *
 * Its checkbox column is switched on by the API response (_table.showCheckbox),
 * after TableManager has already set up column resizing. Resizing had captured
 * the headers without that column, so the first drag of any column border
 * froze every width except the checkbox column's, which collapsed to 0 under
 * table-layout: fixed: the checkboxes were drawn over "Requester" and the whole
 * table jumped left while being dragged right. Reproduced and verified in
 * headless Chrome; these checks keep the pieces of the fix in place.
 *
 * Usage: docker compose exec web php tests/approvals_table_test.php
 */

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

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

$root = __DIR__.'/../';
$source = file_get_contents($root.'Now/js/TableManager.js');
$bundle = file_get_contents($root.'Now/dist/now.table.min.js');

// =========================================================================
echo "\n[1] Column resizing knows about a checkbox column added at runtime\n";
// =========================================================================
check(
    preg_match('/checkboxChanged && table\.config\.showCheckbox\)\s*\{\s*this\.setupCheckboxes\(table, tableId\);[^}]*this\.setupColumnResizing\(tableId\);/s', $source) === 1,
    'after the API turns checkboxes on, column resizing is set up again'
);
check(
    strpos($source, "const currentHeaders = Array.from(headerRow.querySelectorAll('th'));") !== false,
    'freezing widths pins every column present at drag time, not the list captured at setup'
);
// Only the restore step inside setupColumnResizing; clearTableCache() still
// clears the widths on purpose.
$between = static function ($text, $from, $to) {
    $start = strpos($text, $from);
    $end = $start === false ? false : strpos($text, $to, $start);
    return $start === false || $end === false ? null : substr($text, $start, $end - $start);
};
$restore = $between($source, 'setupColumnResizing(tableId) {', 'clearTableCache(tableId)');
check(
    $restore !== null && strpos($restore, 'removeItem') === false,
    'saved widths are not thrown away when the first setup pass sees one column fewer'
);
// Minified names change, so look for the shape of each change instead.
check(
    preg_match('/setupCheckboxes\(\w+,\w+\),this\.setupColumnResizing\(\w+\)/', $bundle) === 1
        && strpos($bundle, '):localStorage.removeItem(`table_') === false,
    'the built now.table.min.js carries the same fix (rebuild with: npm run build:table)'
);

// =========================================================================
echo "\n[1b] No column can be dragged narrower than its content\n";
// =========================================================================
// Under table-layout: fixed a cell stops growing with its content, so a
// status badge or a room name that cannot wrap was drawn over the next
// column. Headless Chrome, every column dragged 500px narrower: before, all
// nine overflowed; after, none did, also after switching language.
check(strpos($source, 'measureMinColumnWidths(tableEl) {') !== false, 'TableManager measures each column\'s min-content width');
check(
    strpos($source, 'Math.max(minWidth, startWidth + (ev.clientX - startX))') !== false,
    'a drag stops at that width instead of at a flat 30px'
);
check(
    preg_match('/this\.fitFixedColumnWidths\(table\.element\);\s*EventManager\.emit\(\'table:render\'/', $source) === 1,
    'after each render (next page, search, language switch) a resized table widens any column its new content no longer fits'
);
check(
    strpos($bundle, 'measureMinColumnWidths(') !== false && strpos($bundle, 'fitFixedColumnWidths(') !== false,
    'and the built now.table.min.js has both (rebuild with: npm run build:table)'
);

// =========================================================================
echo "\n[2] Course code has its own column\n";
// =========================================================================
$template = file_get_contents($root.'templates/booking/approvals.html');
check(strpos($template, 'data-field="course_code"') !== false, 'the approvals table has a course code column');
check(
    strpos($template, 'data-field="topic"') < strpos($template, 'data-field="course_code"'),
    'it sits right after the usage type'
);
check(strpos($template, 'course_code,comment') !== false, 'and is searchable from the table');

$model = file_get_contents($root.'modules/booking/models/approvals.php');
$th = json_decode(file_get_contents($root.'language/th.json'), true);
check(strpos($model, "'R.course_code',") !== false, 'the query selects the course code');
check(strpos($model, "['R.course_code', 'LIKE', \$search]") !== false, 'the search box matches on it');

// =========================================================================
echo "\n[2b] The search box finds room numbers and says what it searches\n";
// =========================================================================
// The room number is printed under every room name, so "R-0001" has to find
// those rows; the dashboard search already did.
check(strpos($model, "['RoomNumber.value', 'LIKE', \$search]") !== false, 'the search box matches the room number');
check(strpos($template, 'room_name,room_number,') !== false, 'and the table lists it as searchable');
check(
    strpos($template, 'placeholder="{LNG_Search room, room number, course code or usage type...}"') !== false,
    'the placeholder names what is searched'
);
check(
    ($th['Search room, room number, course code or usage type...'] ?? '') === 'ค้นหาห้อง เลขห้อง รหัสวิชา หรือลักษณะการใช้งาน...',
    'in Thai too'
);
$style = file_get_contents($root.'templates/booking/style.css');
check(strpos($style, '.booking-approvals-search {') !== false, 'the box is wide enough for that text');

// =========================================================================
echo "\n[3] \"Topic\" reads as the usage type in Thai\n";
// =========================================================================
$th = json_decode(file_get_contents($root.'language/th.json'), true);
check(($th['Topic'] ?? '') === 'ลักษณะการใช้งาน', 'th.json: Topic = ลักษณะการใช้งาน');
$thPhp = include $root.'language/th.php';
check(($thPhp['Topic'] ?? '') === 'ลักษณะการใช้งาน', 'th.php: Topic = ลักษณะการใช้งาน (CSV header)');

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILURES\n";
exit($fail === 0 ? 0 : 1);
