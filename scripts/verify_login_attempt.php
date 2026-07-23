<?php
/**
 * Integration check for Gcms\LoginAttempt against the real database.
 * Run from project root: php scripts/verify_login_attempt.php
 * Exit code 0 = pass, 1 = fail. Cleans up its own rows.
 */
chdir(dirname(__DIR__));
require 'load.php';
Kotchasan::createWebApplication('Gcms\\Config');

function check($label, $cond)
{
    echo ($cond ? "PASS" : "FAIL")."  $label\n";
    if (!$cond) {
        $GLOBALS['__failed'] = true;
    }
}

$ip = '203.0.113.250';
$user = 'bruteforce_probe@example.test';
$cfg = file_exists('settings/config.php') ? require 'settings/config.php' : [];
$max = (int) ($cfg['max_login_attempts'] ?? 5);

\Gcms\LoginAttempt::clear($user, $ip);
\Kotchasan\DB::create()->delete('login_attempt', [['ip_address', $ip]], 0);

check("max_login_attempts is configured (got $max)", $max > 0);
check('not locked initially', \Gcms\LoginAttempt::isLocked($user, $ip) === false);

for ($i = 0; $i < $max; $i++) {
    \Gcms\LoginAttempt::record($user, $ip, 'phpunit-agent');
}
check("locked after $max failed attempts", \Gcms\LoginAttempt::isLocked($user, $ip) === true);
check('remaining lock time > 0', \Gcms\LoginAttempt::getRemainingLockTime($user, $ip) > 0);

\Gcms\LoginAttempt::clear($user, $ip);
check('unlocked after clear()', \Gcms\LoginAttempt::isLocked($user, $ip) === false);

\Kotchasan\DB::create()->delete('login_attempt', [['ip_address', $ip]], 0);

echo (empty($GLOBALS['__failed']) ? "\nALL PASSED\n" : "\nFAILURES PRESENT\n");
exit(empty($GLOBALS['__failed']) ? 0 : 1);
