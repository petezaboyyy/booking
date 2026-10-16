<?php
/** CLI only. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Stylesheets do not depend on the charset the host sends.
 *
 * A host that serves text/css as ISO-8859-1 turns an arrow or tick mark written
 * straight into a stylesheet into "â†•" (the table sort arrows did exactly that).
 * Symbols in generated content are therefore written as escapes ("\2195"), the
 * built bundles are pure ASCII, and .htaccess states the charset outright.
 *
 * Usage: docker compose exec web php tests/css_ascii_test.php
 */

$root = dirname(__DIR__);
$failed = 0;
$check = static function (bool $passed, string $message) use (&$failed): void {
    echo ($passed ? '  ok   ' : '  FAIL ').$message."\n";
    if (!$passed) {
        $failed++;
    }
};

// 1. Built bundles are pure ASCII
foreach (['Now/dist/now.core.min.css', 'Now/dist/eventcalendar.min.css'] as $file) {
    $css = file_get_contents($root.'/'.$file);
    $check(preg_match('/[^\x00-\x7F]/', $css) === 0, "$file is pure ASCII (rebuild with npm run build if this fails)");
}
$core = file_get_contents($root.'/Now/dist/now.core.min.css');
$check(strpos($core, 'content:"\2195 "') !== false, 'the sort arrow is an escape in the core bundle');

// 2. Hand-written stylesheets keep symbols out of generated content
$files = ['css/styles.css', 'css/dashboard.css', 'ui-handoff/css/dashboard.css', 'templates/booking/style.css', 'ui-handoff/templates/booking/style.css', 'modules/download/style.css'];
foreach (glob($root.'/Now/css/*.css') as $path) {
    $files[] = 'Now/css/'.basename($path);
}
foreach ($files as $file) {
    $css = preg_replace('#/\*.*?\*/#s', '', file_get_contents($root.'/'.$file));
    $check(preg_match('/content\s*:\s*(["\'])[^"\']*[^\x00-\x7F]/u', $css) === 0, "$file writes no symbol straight into content:");
}

// 3. The server says UTF-8 for styles and scripts
$htaccess = file_get_contents($root.'/.htaccess');
$check(preg_match('/AddCharset\s+UTF-8\s+\.css\s+\.js/', $htaccess) === 1, '.htaccess serves .css and .js as UTF-8');

// 4. The build keeps it that way
$vite = file_get_contents($root.'/vite.config.js');
$check(strpos($vite, 'asciiCss') !== false && strpos($vite, "enforce: 'post'") !== false, 'vite.config.js turns built CSS into ASCII');

echo $failed === 0 ? "ALL PASS\n" : "$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
