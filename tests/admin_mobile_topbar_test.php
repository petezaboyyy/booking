<?php
/** CLI only. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Regression check for using the admin UI on a phone.
 *
 * Two separate faults lived here. The topbar's hamburger is the only way to
 * reach the side menu, and the topbar is position:fixed - anything pushed past
 * the right edge cannot be scrolled to. A long user name (a full Thai name is
 * easily long enough) used to shove it off screen, so admins had no menu at
 * all. Once it opened, the only way back out was that same small button:
 * MenuManager creates .menu-backdrop and binds the close on it, but no CSS
 * ever made it visible, and visibility:hidden takes no taps.
 */

$root = dirname(__DIR__);
$styles = file_get_contents($root.'/css/styles.css');
$topbar = file_get_contents($root.'/js/components/topbar.js');
$index = file_get_contents($root.'/index.html');
$mirror = file_get_contents($root.'/ui-handoff/index.html');

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
        preg_match('/\.sidemenu-close\s+\.menu-backdrop\s*\{[^}]*visibility:\s*visible/', $mobileBlock) === 1,
        'the menu backdrop is actually shown while the menu is open, so tapping beside the menu closes it (MenuManager already binds the close on it)'
    ],
    [
        preg_match('/\.sidemenu-close\s+\.menu-backdrop\s*\{[^}]*z-index:\s*-1/', $mobileBlock) === 1,
        'the backdrop sits behind the panel content, so the menu links stay tappable rather than being covered by the dim'
    ],
    [
        preg_match('/\.sidemenu-close\s+\.sidebar\s*>\s*div\s*\{[^}]*background:\s*inherit/', $mobileBlock) === 1,
        'the panel keeps its own colour instead of being washed out by the dim (a negative z-index child still paints over its parent background)'
    ],
    // Pinning the literal version would fail on every bump, which teaches you
    // to edit the test instead of reading it. What has to hold is that both
    // shells ask for the same stylesheet build - they have drifted before.
    [
        preg_match('/css\/styles\.css\?v=([^"\']+)/', $index, $a) === 1
        && preg_match('/css\/styles\.css\?v=([^"\']+)/', $mirror, $b) === 1
        && $a[1] === $b[1],
        'index.html and ui-handoff/index.html cache-bust the stylesheet to the same version'
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
