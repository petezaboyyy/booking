<?php

$files = [
    'templates/booking/calendar.html',
    'templates/index.html',
    'templates/booking/style.css',
    'css/dashboard.css',
    'modules/booking/admin.js',
    'language/th.json',
    'language/th.php',
    'language/en.json',
    'language/en.php',
    'index.html'
];

$allPassed = true;
$rootDir = realpath(__DIR__ . '/..');

foreach ($files as $file) {
    $src = $rootDir . '/' . $file;
    $dst = $rootDir . '/ui-handoff/' . $file;

    if (!file_exists($src)) {
        fwrite(STDERR, "SOURCE MISSING: $file\n");
        $allPassed = false;
        continue;
    }

    if (!file_exists($dst)) {
        fwrite(STDERR, "DESTINATION MISSING: ui-handoff/$file\n");
        $allPassed = false;
        continue;
    }

    $hashSrc = hash_file('sha256', $src);
    $hashDst = hash_file('sha256', $dst);

    if ($hashSrc !== $hashDst) {
        fwrite(STDERR, "HASH MISMATCH: $file ($hashSrc vs $hashDst)\n");
        $allPassed = false;
    } else {
        echo "MATCH: $file ($hashSrc)\n";
    }
}

if (!$allPassed) {
    exit(1);
}

echo "\nALL UI-HANDOFF FILES VERIFIED AND IDENTICAL!\n";

