<?php
/** CLI only: this writes to the database. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * One session per signed-in device.
 *
 *  - signing in on a second device does not sign the first one out;
 *  - signing out ends only the device that asked;
 *  - a device idle for an hour, or signed in for twelve, is signed out;
 *  - a refresh (it runs on a timer) neither counts as activity nor extends the twelve hours;
 *  - a deactivated account is refused on its next request;
 *  - a token is never accepted on its signature alone.
 *
 * Signs in through the Microsoft path, the only one left once password login is
 * off. Fixtures live in a transaction that is rolled back.
 *
 * Usage: docker compose exec web php tests/auth_session_test.php
 */

require_once __DIR__.'/../load.php';
require_once __DIR__.'/../scripts/migrations/connection.php';
require_once __DIR__.'/../scripts/migrations/user_session.php';

Kotchasan::createWebApplication('Gcms\Config');

use Gcms\MicrosoftOAuth as OAuth;
use Index\Auth\Model as Auth;

$fail = 0;

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

// Schema first: DDL would end the transaction the fixtures live in.
$dbCfg = (require __DIR__.'/../settings/database.php')['mysql'];
booking_apply_user_session_migration(booking_migration_pdo($dbCfg), (string) $dbCfg['prefix']);
Index\Social\Model::ensureSchema();

$property = new ReflectionProperty('Kotchasan\\KBase', 'cfg');
$property->setAccessible(true);
$appCfg = $property->getValue();
$tenant = '11111111-1111-4111-8111-111111111111';
$appCfg->microsoft_tenant = $tenant;

$guid = static function () {
    return sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6)));
};
$oid = $guid();
$claims = ['tid' => $tenant, 'oid' => $oid];
$profile = ['id' => $oid, 'userType' => 'Member', 'mail' => 'session-probe-'.bin2hex(random_bytes(4)).'@university.ac.th', 'displayName' => 'Session Probe'];
$identity = OAuth::profile($profile, $claims, 'university.ac.th');

$db = Kotchasan\DB::create();
$session = static function ($token) use ($db) {
    $payload = Kotchasan\Jwt::decode($token, $GLOBALS['appCfg']->jwt_secret ?? $GLOBALS['appCfg']->password_key, ['HS256']);
    return $payload ? $db->first('user_session', ['id', $payload['sid'] ?? '']) : null;
};
$setSession = static function ($token, array $data) use ($db, $session) {
    $db->update('user_session', ['id', $session($token)->id], $data);
};
$signIn = static function () use ($identity) {
    return Index\Social\Model::authenticate($identity, 'http://localhost/', 'Microsoft', '203.0.113.7');
};
$ago = static function ($seconds) {
    return date('Y-m-d H:i:s', time() - $seconds);
};

// What every API controller runs (Kotchasan\ApiController::authenticateRequest).
class SessionProbeApi extends Gcms\Api
{
    public function authenticate($token)
    {
        $request = (new Kotchasan\Http\Request())->withHeader('Authorization', 'Bearer '.$token);

        return $this->authenticateRequest($request);
    }
}
$api = new SessionProbeApi();

$db->beginTransaction();
try {
    echo "\n[1] Two devices\n";
    $first = $signIn();
    check(!empty($first['success']) && !empty($first['token']), 'a new Microsoft user signs in (device A)');
    $memberId = (int) $first['user']['id'];
    $tokenA = $first['token'];
    $second = $signIn();
    $tokenB = $second['token'];
    check(!empty($second['success']) && $second['user']['id'] == $memberId, 'the same user signs in again (device B)');
    check(Auth::getUserByToken($tokenA) !== null && Auth::getUserByToken($tokenB) !== null, 'both devices stay signed in');
    check($api->authenticate($tokenA) !== null && $api->authenticate($tokenB) !== null, 'and both are accepted by the API');
    check(count($db->select('user_session', ['member_id', $memberId])) === 2, 'one session row per device');
    $row = $session($tokenA);
    check($session($tokenB)->ip === '203.0.113.7', 'the session records where it signed in from');
    $exp = Kotchasan\Jwt::decode($tokenA, $appCfg->jwt_secret ?? $appCfg->password_key, ['HS256'])['exp'];
    check(abs($exp - (time() + 12 * 3600)) <= 5 && abs(strtotime($row->expires_at) - $exp) <= 1, 'the token and the session end 12 hours after sign-in');

    echo "\n[2] Signing out\n";
    Auth::logout($tokenA);
    check(Auth::getUserByToken($tokenA) === null && $api->authenticate($tokenA) === null, 'a signed-out token is refused, by the API too (not accepted on its signature)');
    check(Auth::getUserByToken($tokenB) !== null, 'the other device stays signed in');

    echo "\n[3] Idle for an hour\n";
    $setSession($tokenB, ['last_seen' => $ago(3500)]);
    check(Auth::getUserByToken($tokenB) !== null, 'idle for 58 minutes: still signed in');
    check(strtotime($session($tokenB)->last_seen) >= time() - 5, 'and the request counts as activity, so the hour starts again');
    $setSession($tokenB, ['last_seen' => $ago(3601)]);
    $deadId = $session($tokenB)->id;
    check(Auth::getUserByToken($tokenB) === null, 'idle for over an hour: signed out');
    check($db->first('user_session', ['id', $deadId]) === false || $db->first('user_session', ['id', $deadId]) === null, 'and the dead session is deleted');

    echo "\n[4] Twelve hours however busy\n";
    $tokenC = $signIn()['token'];
    $setSession($tokenC, ['expires_at' => $ago(1), 'last_seen' => $ago(0)]);
    check(Auth::getUserByToken($tokenC) === null, 'a session past its 12 hours is refused even while in use');

    echo "\n[5] Refresh\n";
    $signed = $signIn();
    $tokenD = $signed['token'];
    $setSession($tokenD, ['last_seen' => $ago(1800)]);
    $before = $session($tokenD);
    $refreshed = Auth::refreshAccessToken($signed['refresh_token']);
    check(!empty($refreshed['success']) && $session($refreshed['token'])->id === $before->id, 'a refresh on a live session issues tokens for the same device');
    check($session($tokenD)->last_seen === $before->last_seen, 'a refresh is not activity: it leaves the idle clock alone');
    $newExp = Kotchasan\Jwt::decode($refreshed['token'], $appCfg->jwt_secret ?? $appCfg->password_key, ['HS256'])['exp'];
    check(abs($newExp - strtotime($before->expires_at)) <= 1, 'and the new token still ends when the session ends (no extension)');
    check(Auth::getUserByToken($refreshed['token']) !== null, 'the new token works');
    $setSession($tokenD, ['last_seen' => $ago(3601)]);
    check(empty(Auth::refreshAccessToken($refreshed['refresh_token'])['success']), 'an idle session cannot be refreshed back to life');

    echo "\n[6] Deactivated account\n";
    $tokenE = $signIn()['token'];
    $db->update('user', ['id', $memberId], ['active' => 0]);
    check(Auth::getUserByToken($tokenE) === null && $api->authenticate($tokenE) === null, 'deactivating the account refuses its next request');
    $db->update('user', ['id', $memberId], ['active' => 1]);
    check(Auth::getUserByToken($tokenE) !== null, 'reactivating it lets the device back in');

    echo "\n[7] Forged and borrowed tokens\n";
    $secret = $appCfg->jwt_secret ?? $appCfg->password_key;
    $noSession = Kotchasan\Jwt::encode(['sub' => $memberId, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access'], $secret, 'HS256');
    check(Auth::getUserByToken($noSession) === null && $api->authenticate($noSession) === null, 'a correctly signed token with no session is refused');
    $borrowed = Kotchasan\Jwt::encode(['sub' => $memberId + 99999, 'iat' => time(), 'exp' => time() + 3600, 'type' => 'access', 'sid' => $session($tokenE)->id], $secret, 'HS256');
    check(Auth::getUserByToken($borrowed) === null, 'a session id cannot be used under another user id');
} finally {
    $db->rollback();
}

echo $fail ? "\n$fail check(s) failed\n" : "\nAll checks passed\n";
exit($fail ? 1 : 0);
