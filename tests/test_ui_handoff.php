<?php

$files = [
    'templates/booking/calendar.html',
    'templates/booking/catalog.html',
    'templates/booking/my-bookings.html',
    'templates/booking/booking-recurring.html',
    'templates/login.html',
    'templates/index.html',
    'css/dashboard.css',
    'language/th.json',
    'language/th.php',
    'language/en.json',
    'language/en.php',
    'images/logo.png',
    'images/login-bg-dark.jpg',
    'images/login-bg-light.jpg',
    'images/login-visual-bg.jpg',
    'js/main.js',
    'js/components/GallerySlideshow.js',
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

