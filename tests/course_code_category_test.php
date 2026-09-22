<?php
/** CLI only. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/** CLI regression check for the per-category course-code setting. */

$root = dirname(__DIR__);
$admin = file_get_contents($root.'/modules/booking/admin.js');
$booking = file_get_contents($root.'/modules/booking/controllers/booking.php');
$categories = file_get_contents($root.'/modules/booking/models/categories.php');
$schema = file_get_contents($root.'/install/database.sql');
$tableManagerSrc = file_get_contents($root.'/Now/js/TableManager.js');
$tableManagerDist = file_get_contents($root.'/Now/dist/now.table.min.js');

$checks = [
    [strpos($admin, "categoryId === '1'") === false, 'frontend has no fixed category ID'],
    [strpos($admin, 'Boolean(opt.requires_course_code)') !== false, 'frontend reads the selected category setting'],
    [strpos($booking, 'Model::requiresCourseCode') !== false, 'server validation reads the same setting'],
    [strpos($categories, "'cellElement' => 'checkbox'") !== false && strpos($categories, 'category-course-code-toggle') !== false, 'admin page renders a per-row toggle'],
    [strpos($categories, "'cellClass' => 'checkbox'") !== false, 'toggle cell uses the checkbox cell class so its wrapper renders as a switch, not an empty box'],
    [strpos($schema, '`requires_course_code` tinyint(1) NOT NULL DEFAULT 0') !== false, 'schema stores the setting'],
    [strpos($tableManagerSrc, "value === true || value === 1 || value === '1' || value === 'true'") !== false, 'TableManager source treats numeric 1 as checked'],
    [strpos($tableManagerDist, '!0===l||1===l||"1"===l||"true"===l') !== false, 'built now.table.min.js bundle matches the source fix (bundle must be rebuilt after TableManager.js changes)']
];

$failed = 0;
foreach ($checks as [$passed, $message]) {
    echo ($passed ? 'ok   ' : 'FAIL ').$message.PHP_EOL;
    if (!$passed) {
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
