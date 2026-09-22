<?php
/** CLI regression check for the per-category course-code setting. */

$root = dirname(__DIR__);
$admin = file_get_contents($root.'/modules/booking/admin.js');
$booking = file_get_contents($root.'/modules/booking/controllers/booking.php');
$categories = file_get_contents($root.'/modules/booking/models/categories.php');
$schema = file_get_contents($root.'/install/database.sql');

$checks = [
    [strpos($admin, "categoryId === '1'") === false, 'frontend has no fixed category ID'],
    [strpos($admin, 'Boolean(opt.requires_course_code)') !== false, 'frontend reads the selected category setting'],
    [strpos($booking, 'Model::requiresCourseCode') !== false, 'server validation reads the same setting'],
    [strpos($categories, "'cellElement' => 'checkbox'") !== false && strpos($categories, 'category-course-code-toggle') !== false, 'admin page renders a per-row toggle'],
    [strpos($schema, '`requires_course_code` tinyint(1) NOT NULL DEFAULT 0') !== false, 'schema stores the setting']
];

$failed = 0;
foreach ($checks as [$passed, $message]) {
    echo ($passed ? 'ok   ' : 'FAIL ').$message.PHP_EOL;
    if (!$passed) {
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
