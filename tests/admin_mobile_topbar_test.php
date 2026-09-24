<?php
/** CLI only. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Regression check: on a phone the admin topbar's hamburger is the only way to
 * reach the side menu, and the topbar is position:fixed - anything pushed past
 * the right edge cannot be scrolled to. A long user name (a full Thai name is
 * easily long enough) used to shove it off screen, so admins had no menu at all.
 */

$root = dirname(__DIR__);
$styles = file_get_contents($root.'/css/styles.css');
$topbar = file_get_contents($root.'/js/components/topbar.js');
$index = file_get_contents($root.'/index.html');

// Isolate the phone breakpoint so a desktop rule can't satisfy these checks.
$mobileBlock = '';
if (preg_match('/@media\s*\(max-width:\s*768px\)\s*\{(.+?)\n\}/s', $styles, $m)) {
    $mobileBlock = $m[1];
}

$checks = [
    [$mobileBlock !== '', 'css/styles.css still has a max-width:768px block'],
    [
        strpos($topbar, 'menu-toggle sidemenu-toggle') !== false,
        'the topbar still renders the side-menu toggle'
    ],
    [
        preg_match('/\.topbar-left\s*\{[^}]*min-width:\s*0/', $mobileBlock) === 1,
        'the topbar text side may shrink (min-width:0) - without it a flex item refuses to shrink below its content and pushes the controls off screen'
    ],
    [
        preg_match('/\.topbar-right\s*\{[^}]*flex-shrink:\s*0/', $mobileBlock) === 1,
        'the topbar control group keeps its size instead of being squeezed'
    ],
    [
        preg_match('/\.topbar-right\s+\.user-name\s*\{[^}]*display:\s*none/', $mobileBlock) === 1,
        'the user name is hidden on a phone (it is what overflowed; the avatar and profile menu still identify the user)'
    ],
    [
        preg_match('/\.topbar-right\s+\.menu-toggle\s*\{[^}]*order:\s*-1/', $mobileBlock) === 1,
        'the menu toggle is ordered first in the control group, so it is the last thing to ever clip'
    ],
    [
        preg_match('/\.main-content\s+\.form-group\s*\{[^}]*flex-direction:\s*column/', $mobileBlock) === 1,
        'admin form rows stack on a phone instead of two ~150px columns'
    ],
    [
        strpos($index, 'css/styles.css?v=20260924-admin-mobile-topbar-1') !== false,
        'index.html cache-busts the stylesheet so the fix is not served from cache'
    ]
];

$failed = 0;
foreach ($checks as [$passed, $message]) {
    echo ($passed ? 'ok   ' : 'FAIL ').$message.PHP_EOL;
    if (!$passed) {
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
