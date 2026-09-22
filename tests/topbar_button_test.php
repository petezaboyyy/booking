<?php
/** CLI only. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/** Regression check: topbar controls must not submit forms they are rendered inside. */

$topbar = file_get_contents(dirname(__DIR__).'/js/components/topbar.js');
preg_match_all('/<button\b[^>]*>/i', $topbar, $matches);

$missingType = [];
foreach ($matches[0] as $button) {
    if (!preg_match('/\btype\s*=/i', $button)) {
        $missingType[] = $button;
    }
}

if ($missingType !== []) {
    echo 'FAIL topbar buttons must declare a non-submit type'.PHP_EOL;
    exit(1);
}

echo 'ok   topbar buttons declare an explicit type'.PHP_EOL;
