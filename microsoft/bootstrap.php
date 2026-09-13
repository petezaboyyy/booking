<?php
require dirname(__DIR__).'/load.php';

// A separate session binds short-lived OAuth state to this browser. Lax permits GET callbacks.
if (session_status() !== PHP_SESSION_NONE) {
    throw new RuntimeException('OAuth session already started');
}
session_name('booking_microsoft_oauth');
session_start([
    'use_strict_mode' => 1,
    'use_only_cookies' => 1,
    'cookie_httponly' => true,
    'cookie_secure' => defined('HTTPS') && HTTPS,
    'cookie_samesite' => 'Lax',
    'cookie_path' => '/',
    'cookie_lifetime' => 0
]);
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
