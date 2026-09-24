<?php
/** CLI only, like the rest of the suite. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Saving settings must not write environment secrets into the config file.
 *
 * settings/config.php reads its secrets with getenv(). Config::load() includes
 * the file, so the values a settings page gets back are the secrets
 * themselves, and Config::save() used to var_export() them: one click on any
 * settings page left pusher_secret in plain text in a git-tracked file, and
 * froze it there so a later change to the environment did nothing.
 *
 * Every test here works on a temporary copy; settings/config.php is only read.
 *
 * Usage: docker compose exec web php tests/config_env_preserve_test.php
 */

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Kotchasan\Config;

$fail = 0;
$temps = [];

function check($condition, string $message): void
{
    global $fail;
    if ($condition) {
        echo "  ok   $message\n";
    } else {
        $fail++;
        echo "  FAIL $message\n";
    }
}

function tempConfig(string $source): string
{
    global $temps;
    $file = tempnam(sys_get_temp_dir(), 'cfg').'.php';
    file_put_contents($file, $source);
    $temps[] = $file;

    return $file;
}

/** What save() wrote, and what including it again gives. */
function roundTrip(string $file, callable $edit): array
{
    $config = Config::load($file);
    $edit($config);
    Config::save($config, $file);
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($file, true);
    }

    return [file_get_contents($file), include $file];
}

putenv('CFG_TEST_SECRET=s3cr3t-from-env');
putenv('CFG_TEST_EMPTY');

$fixture = <<<'SRC'
<?php
return array (
  'web_title' => 'Old title',
  'nested' => 
  array (
    0 => 'a',
  ),
  'test_secret' => getenv('CFG_TEST_SECRET') ?: '',
  'test_unset' => getenv('CFG_TEST_EMPTY') ?: 'fallback',
);
SRC;

try {
    // =====================================================================
    echo "\n[1] Saving an unrelated setting keeps the expressions\n";
    // =====================================================================
    $file = tempConfig($fixture);
    list($written, $reloaded) = roundTrip($file, function ($c) {
        $c->web_title = 'New title';
    });
    check(strpos($written, "'test_secret' => getenv('CFG_TEST_SECRET') ?: '',") !== false, 'test_secret is still written as getenv()');
    check(strpos($written, 's3cr3t-from-env') === false, 'the secret itself never reaches the file');
    check(strpos($written, "'test_unset' => getenv('CFG_TEST_EMPTY') ?: 'fallback',") !== false, 'an entry whose variable is unset keeps its expression too');
    check($reloaded['web_title'] === 'New title', 'the setting that was edited is saved');
    check($reloaded['test_secret'] === 's3cr3t-from-env', 'the file still resolves the secret from the environment');
    check($reloaded['nested'] === [0 => 'a'], 'nested arrays survive unchanged');
    check(strpos($written, '__kotchasan_env_') === false, 'no placeholder leaks into the file');

    // =====================================================================
    echo "\n[2] The environment stays in charge after a save\n";
    // =====================================================================
    putenv('CFG_TEST_SECRET=rotated-secret');
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($file, true);
    }
    $again = include $file;
    check($again['test_secret'] === 'rotated-secret', 'rotating the variable takes effect without touching the file');
    putenv('CFG_TEST_SECRET=s3cr3t-from-env');

    // =====================================================================
    echo "\n[3] A value an admin actually typed is still saved\n";
    // =====================================================================
    $file = tempConfig($fixture);
    list($written, $reloaded) = roundTrip($file, function ($c) {
        $c->test_secret = 'typed-by-admin';
    });
    check($reloaded['test_secret'] === 'typed-by-admin', 'a changed env-backed value is written, as before');
    check(strpos($written, "getenv('CFG_TEST_SECRET')") === false, 'and it replaces the expression it overrode');
    check(strpos($written, "getenv('CFG_TEST_EMPTY')") !== false, 'the untouched neighbour keeps its expression');

    // =====================================================================
    echo "\n[4] Saving twice does not drift\n";
    // =====================================================================
    $file = tempConfig($fixture);
    roundTrip($file, function ($c) {
        $c->web_title = 'First';
    });
    list($written) = roundTrip($file, function ($c) {
        $c->web_title = 'Second';
    });
    check(substr_count($written, 'getenv(') === 2, 'both expressions are still there after a second save');

    // =====================================================================
    echo "\n[5] The real settings/config.php\n";
    // =====================================================================
    $real = ROOT_PATH.'settings/config.php';
    $expected = ['pusher_app_id', 'pusher_key', 'pusher_secret', 'pusher_cluster', 'microsoft_client_secret'];
    $method = new \ReflectionMethod(Config::class, 'envBackedEntries');
    $method->setAccessible(true);
    $found = array_keys($method->invoke(null, $real));
    sort($found);
    $sorted = $expected;
    sort($sorted);
    check($found === $sorted, 'all five env-backed entries are recognised ('.implode(', ', $found).')');

    // What a booking-settings save does, on a copy.
    $file = tempConfig(file_get_contents($real));
    list($written, $reloaded) = roundTrip($file, function ($c) {
        $c->booking_approving = 1;
    });
    foreach ($expected as $key) {
        check(preg_match("/^  '$key' => [^\\n]*getenv\\(/m", $written) === 1, $key.' survives a booking-settings save as getenv()');
    }
    $liveSecret = (string) getenv('PUSHER_SECRET');
    check($liveSecret === '' || strpos($written, $liveSecret) === false, 'the live PUSHER_SECRET is not in the saved copy');
    check($reloaded['booking_approving'] === 1, 'the booking setting itself was saved');
} finally {
    foreach ($temps as $temp) {
        @unlink($temp);
        @unlink(substr($temp, 0, -4));
    }
}

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILURES\n";
exit($fail === 0 ? 0 : 1);
