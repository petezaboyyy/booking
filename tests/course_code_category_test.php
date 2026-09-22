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
$calendar = file_get_contents($root.'/modules/booking/controllers/calendar.php');
$bookingAdmin = file_get_contents($root.'/modules/booking/admin.js');
$bookingModel = file_get_contents($root.'/modules/booking/models/booking.php');
$bookingView = file_get_contents($root.'/modules/booking/views/view.php');
$index = file_get_contents($root.'/index.html');
$categories = file_get_contents($root.'/modules/booking/models/categories.php');
$schema = file_get_contents($root.'/install/database.sql');
$tableManagerSrc = file_get_contents($root.'/Now/js/TableManager.js');
$tableManagerDist = file_get_contents($root.'/Now/dist/now.table.min.js');
$adminBookingForm = file_get_contents($root.'/templates/booking/booking-recurring-admin.html');
$roomlyAdminJs = file_get_contents($root.'/ui-handoff/modules/booking/admin.js');

$checks = [
    [strpos($admin, "categoryId === '1'") === false, 'frontend has no fixed category ID'],
    [strpos($admin, 'Boolean(opt.requires_course_code)') !== false, 'frontend reads the selected category setting'],
    [strpos($booking, 'Model::requiresCourseCode') !== false, 'server validation reads the same setting'],
    [strpos($calendar, "'R.course_code'") !== false && strpos($calendar, "'course_code' => trim") !== false, 'calendar events include the course code'],
    [strpos($bookingAdmin, "Now.translate('Course code')") !== false && strpos($bookingAdmin, 'event?.data?.course_code') !== false, 'calendar day modal reads the normalized event course code'],
    [strpos($index, 'course-code-fix-1') !== false, 'calendar script cache is refreshed'],
    [strpos($bookingModel, "'R.course_code'") !== false && strpos($bookingModel, "'course_code' => trim") !== false, 'booking details include the course code'],
    [strpos($bookingView, 'LNG_Course code') !== false && strpos($bookingView, "course_code']") !== false, 'booking detail modal displays a provided course code'],
    [strpos($categories, "'cellElement' => 'checkbox'") !== false && strpos($categories, 'category-course-code-toggle') !== false, 'admin page renders a per-row toggle'],
    [strpos($categories, "'cellClass' => 'checkbox'") !== false, 'toggle cell uses the checkbox cell class so its wrapper renders as a switch, not an empty box'],
    [strpos($schema, '`requires_course_code` tinyint(1) NOT NULL DEFAULT 0') !== false, 'schema stores the setting'],
    [strpos($tableManagerSrc, "value === true || value === 1 || value === '1' || value === 'true'") !== false, 'TableManager source treats numeric 1 as checked'],
    [strpos($tableManagerDist, '!0===l||1===l||"1"===l||"true"===l') !== false, 'built now.table.min.js bundle matches the source fix (bundle must be rebuilt after TableManager.js changes)'],
    [strpos($adminBookingForm, "id='course-code-field'") !== false && strpos($adminBookingForm, "id='course_code'") !== false, 'admin booking form has a course code field (it used to be missing, so admins could not save bookings for categories that require one)'],
    [strpos($roomlyAdminJs, 'topicCourseCodeRequirementMap') !== false && strpos($roomlyAdminJs, 'updateCourseCodeVisibility') !== false, 'admin booking form JS wires up the course-code requirement/visibility logic']
];

$failed = 0;
foreach ($checks as [$passed, $message]) {
    echo ($passed ? 'ok   ' : 'FAIL ').$message.PHP_EOL;
    if (!$passed) {
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
