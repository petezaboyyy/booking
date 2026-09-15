<?php
/** Offline cryptographic/security tests; optional real DB integration with --database. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/Gcms/MicrosoftOAuth.php';
use Gcms\MicrosoftOAuth as OAuth;
$checks = 0;
function check($label, $condition) {
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAIL: '.$label);
    }
    ++$checks;
    echo "PASS: $label\n";
}
function rejects($label, $callback) {
    try { $callback(); } catch (Throwable $e) { check($label, true); return; }
    check($label, false);
}
$tenant = '11111111-1111-4111-8111-111111111111';
$client = '22222222-2222-4222-8222-222222222222';
$oid = '33333333-3333-4333-8333-333333333333';
$nonce = OAuth::encode(random_bytes(32));
$cfg = (object) [
    'microsoft_tenant' => $tenant, 'microsoft_client_id' => $client,
    'microsoft_client_secret' => 'test-only', 'microsoft_allowed_domains' => 'University.ac.th, staff.university.ac.th',
    'microsoft_redirect_uri' => 'https://booking.example.test/booking/microsoft/callback.php'
];
check('complete configuration enabled', OAuth::configured($cfg));
putenv('BOOKING_MICROSOFT_DEMO_EMAIL=petezahuddd@gmail.com');
check('demo uses common authority', OAuth::authority($cfg) === 'common');
putenv('BOOKING_MICROSOFT_DEMO_OID='.$oid);
check('pinned personal identity accepted', OAuth::isDemoIdentity(['microsoft_tenant_id' => OAuth::PERSONAL_TENANT, 'microsoft_id' => $oid]));
check('same oid in university is not demo admin', !OAuth::isDemoIdentity(['microsoft_tenant_id' => $tenant, 'microsoft_id' => $oid]));
check('different personal oid denied', !OAuth::isDemoIdentity(['microsoft_tenant_id' => OAuth::PERSONAL_TENANT, 'microsoft_id' => $client]));
putenv('BOOKING_MICROSOFT_DEMO_OID');
check('email alone grants no access', !OAuth::isDemoIdentity(['microsoft_tenant_id' => OAuth::PERSONAL_TENANT, 'microsoft_id' => $oid]));
putenv('BOOKING_MICROSOFT_DEMO_EMAIL');
check('production retains university authority', OAuth::authority($cfg) === $tenant);
$cfg->microsoft_tenant = 'common';
check('common tenant rejected', !OAuth::configured($cfg));
$cfg->microsoft_tenant = $tenant;
$cfg->microsoft_allowed_domains = '';
check('empty domain list disables login', !OAuth::configured($cfg));
$cfg->microsoft_allowed_domains = 'university.ac.th';
check('localhost HTTP supported', OAuth::validRedirect('http://localhost:8080/microsoft/callback.php'));
check('remote HTTP rejected', !OAuth::validRedirect('http://booking.example.test/microsoft/callback.php'));
check('userinfo in redirect rejected', !OAuth::validRedirect('https://evil@booking.example.test/microsoft/callback.php'));
$base = OAuth::baseUrl($cfg->microsoft_redirect_uri);
check('subdirectory retained', $base === 'https://booking.example.test/booking/');
check('internal route retained', OAuth::returnUrl('/my-bookings?status=1', $base) === $base.'my-bookings?status=1');
foreach (['https://evil.test', '//evil.test', '/\\evil.test', '%2f%2fevil.test', '/%0d%0aLocation:evil', '../outside'] as $bad) {
    check('unsafe redirect rejected: '.$bad, OAuth::returnUrl($bad, $base) === $base);
}
$states = ['one' => ['expires' => time()+60], 'expired' => ['expires' => time()-1]];
check('valid state consumed', OAuth::consumeState($states, 'one')['expires'] > time());
rejects('state replay rejected', function () use (&$states) { OAuth::consumeState($states, 'one'); });
rejects('expired state rejected', function () use (&$states) { OAuth::consumeState($states, 'expired'); });
rejects('foreign browser state rejected', function () { $empty=[]; OAuth::consumeState($empty, 'one'); });
check('PKCE RFC7636 vector', OAuth::encode(hash('sha256', 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', true)) === 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM');
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'SSO test'], $key, ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
openssl_x509_export($cert, $pem);
$x5c = preg_replace('/-----[^-]+-----|\s/', '', $pem);
$jwks = ['keys' => [['kid' => 'test', 'kty' => 'RSA', 'use' => 'sig', 'x5c' => [$x5c]]]];
$claims = ['aud' => $client, 'tid' => $tenant, 'iss' => 'https://login.microsoftonline.com/'.$tenant.'/v2.0', 'ver' => '2.0', 'oid' => $oid, 'exp' => time()+300, 'nbf' => time()-10, 'nonce' => $nonce];
function signedToken($claims, $key, $alg = 'RS256') {
    $input = OAuth::encode(json_encode(['alg' => $alg, 'kid' => 'test'])).'.'.OAuth::encode(json_encode($claims));
    openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);
    return $input.'.'.OAuth::encode($signature);
}
$token = signedToken($claims, $key);
check('valid signed ID token accepted', OAuth::validateIdToken($token, $jwks, $client, $tenant, $nonce)['oid'] === $oid);
$personal = $claims;
$personal['tid'] = OAuth::PERSONAL_TENANT;
$personal['iss'] = 'https://login.microsoftonline.com/'.OAuth::PERSONAL_TENANT.'/v2.0';
check('signed personal token accepted in demo', OAuth::validateIdToken(signedToken($personal, $key), $jwks, $client, $tenant, $nonce, true)['tid'] === OAuth::PERSONAL_TENANT);
rejects('personal token rejected outside demo', function () use ($personal,$key,$jwks,$client,$tenant,$nonce) { OAuth::validateIdToken(signedToken($personal,$key),$jwks,$client,$tenant,$nonce); });
$foreign=$claims; $foreign['tid']=$client; $foreign['iss']='https://login.microsoftonline.com/'.$client.'/v2.0';
rejects('demo rejects unrelated organization', function () use ($foreign,$key,$jwks,$client,$tenant,$nonce) { OAuth::validateIdToken(signedToken($foreign,$key),$jwks,$client,$tenant,$nonce,true); });
foreach (['aud' => $oid, 'tid' => $client, 'iss' => 'https://evil.test', 'exp' => time()-1, 'nbf' => time()+600, 'nonce' => 'wrong', 'oid' => '', 'ver' => '1.0'] as $field => $bad) {
    $modified = $claims; $modified[$field] = $bad;
    rejects('wrong '.$field.' rejected', function () use ($modified,$key,$jwks,$client,$tenant,$nonce) { OAuth::validateIdToken(signedToken($modified,$key),$jwks,$client,$tenant,$nonce); });
}
rejects('unsigned algorithm rejected', function () use ($claims,$key,$jwks,$client,$tenant,$nonce) { OAuth::validateIdToken(signedToken($claims,$key,'none'),$jwks,$client,$tenant,$nonce); });
$parts = explode('.', $token); $tampered=$claims; $tampered['oid']=$client; $parts[1]=OAuth::encode(json_encode($tampered));
rejects('tampered signature rejected', function () use ($parts,$jwks,$client,$tenant,$nonce) { OAuth::validateIdToken(implode('.',$parts),$jwks,$client,$tenant,$nonce); });
rejects('unknown signing key rejected', function () use ($token,$client,$tenant,$nonce) { OAuth::validateIdToken($token,['keys'=>[]],$client,$tenant,$nonce); });
$profile = ['id'=>$oid,'userType'=>'Member','mail'=>'Staff@University.ac.th','displayName'=>'Test Staff'];
check('profile email normalized', OAuth::profile($profile,$claims,'university.ac.th')['username'] === 'staff@university.ac.th');
$fallback=$profile; $fallback['mail']=null; $fallback['userPrincipalName']='staff@university.ac.th';
check('UPN fallback supported', OAuth::profile($fallback,$claims,'university.ac.th')['username'] === 'staff@university.ac.th');
foreach (['userType'=>'Guest','id'=>$client,'mail'=>'user@eviluniversity.ac.th'] as $field=>$bad) {
    $modified=$profile; $modified[$field]=$bad;
    rejects('profile '.$field.' mismatch rejected', function () use ($modified,$claims) { OAuth::profile($modified,$claims,'university.ac.th'); });
}
if (in_array('--database', $argv, true)) {
    require dirname(__DIR__).'/load.php';
    Kotchasan::createWebApplication('Gcms\\Config');
    check('ID 1 alone does not grant SuperAdmin', !Gcms\Api::isSuperAdmin((object) ['id'=>1,'status'=>1,'permission'=>[]]));
    check('legacy ID 1 alone does not grant SuperAdmin', \Kotchasan\Login::isSuperAdmin((object) ['id'=>1,'status'=>1,'permission'=>[]]) === null);
    $property = new ReflectionProperty('Kotchasan\\KBase', 'cfg');
    $property->setAccessible(true);
    $appCfg = $property->getValue();
    $appCfg->microsoft_tenant = $tenant;
    $settings = new Index\Settings\Controller();
    $getGeneral = new ReflectionMethod($settings, 'getGeneralData');
    $getGeneral->setAccessible(true);
    $appCfg->microsoft_client_secret = 'fixture-secret';
    $general = $getGeneral->invoke($settings);
    check('settings never returns stored secret', !array_key_exists('microsoft_client_secret', $general) && strpos(json_encode($general), 'fixture-secret') === false);
    $parseGeneral = new ReflectionMethod($settings, 'parseGeneralSettings');
    $parseGeneral->setAccessible(true);
    $draft = clone $appCfg;
    $errors = $parseGeneral->invoke($settings, ['microsoft_client_secret'=>''], $draft);
    check('blank secret preserves stored value', empty($errors) && $draft->microsoft_client_secret === 'fixture-secret');
    $errors = $parseGeneral->invoke($settings, ['microsoft_tenant'=>'common'], $draft);
    check('settings rejects common tenant', isset($errors['microsoft_tenant']));
    $errors = $parseGeneral->invoke($settings, ['microsoft_allowed_domains'=>'*.university.ac.th'], $draft);
    check('settings rejects wildcard domains', isset($errors['microsoft_allowed_domains']));
    $db = Kotchasan\DB::create();
    $db->beginTransaction();
    try {
        $identity=OAuth::profile($profile,$claims,'university.ac.th');
        $identity['username']='microsoft-probe-'.bin2hex(random_bytes(5)).'@university.ac.th';
        $identity['microsoft_id']=sprintf('%s-%s-%s-%s-%s',bin2hex(random_bytes(4)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(2)),bin2hex(random_bytes(6)));
        $first=Index\Social\Model::authenticate($identity,'http://localhost/','Microsoft','203.0.113.250');
        check('new Microsoft user authenticates', !empty($first['success']) && !empty($first['token']));
        $id=$first['user']['id'];
        $user=$db->first('user',['id',$id]);
        check('identity persisted and ordinary role assigned', $user->microsoft_id === $identity['microsoft_id'] && $user->microsoft_tenant_id === $tenant && (int)$user->status===0 && empty($user->permission));
        $again=Index\Social\Model::authenticate($identity,'http://localhost/','Microsoft');
        check('repeat login uses same account', !empty($again['success']) && $again['user']['id']==$id);
        $db->update('user',['id',$id],['status'=>1,'permission'=>',super_admin,']);
        $admin=Index\Auth\Model::getUserById($id);
        check('permission grants SuperAdmin independently of ID', Gcms\Api::isSuperAdmin($admin));
        $changed=$identity; $changed['username']='renamed-'.$identity['username'];
        $again=Index\Social\Model::authenticate($changed,'http://localhost/','Microsoft');
        check('email change retains account identity', !empty($again['success']) && $again['user']['id']==$id);
        $changed=$identity; $changed['microsoft_id']=$client;
        check('same email different ID cannot sign in', empty(Index\Social\Model::authenticate($changed,'http://localhost/','Microsoft')['success']));
        $changed=$identity; $changed['microsoft_tenant_id']=$client;
        check('different tenant cannot sign in', empty(Index\Social\Model::authenticate($changed,'http://localhost/','Microsoft')['success']));
        $db->update('user',['id',$id],['active'=>0]);
        check('disabled account rejected', empty(Index\Social\Model::authenticate($identity,'http://localhost/','Microsoft')['success']));
        $db->update('user',['id',$id],['active'=>1,'social'=>'user','microsoft_id'=>null,'microsoft_tenant_id'=>null]);
        check('local email collision is not auto-linked', empty(Index\Social\Model::authenticate($identity,'http://localhost/','Microsoft')['success']));
        putenv('BOOKING_MICROSOFT_DEMO_EMAIL=fixture@gmail.com');
        putenv('BOOKING_MICROSOFT_DEMO_OID='.$identity['microsoft_id']);
        $demo=$identity; $demo['username']='demo-'.bin2hex(random_bytes(5)).'@gmail.com';
        $demo['microsoft_tenant_id']=OAuth::PERSONAL_TENANT;
        $created=Index\Social\Model::authenticate($demo,'http://localhost/','Microsoft');
        check('pinned demo account becomes admin', !empty($created['success']) && (int)$created['user']['status']===1);
        $demoId=$created['user']['id'];
        $db->update('user',['id',$demoId],['status'=>0]);
        $again=Index\Social\Model::authenticate($demo,'http://localhost/','Microsoft');
        check('demo demotion survives subsequent login', !empty($again['success']) && $again['user']['id']==$demoId && (int)$again['user']['status']===0);
        putenv('BOOKING_MICROSOFT_DEMO_OID');
        check('removing demo binding denies login', empty(Index\Social\Model::authenticate($demo,'http://localhost/','Microsoft')['success']));
    } finally {
        putenv('BOOKING_MICROSOFT_DEMO_EMAIL');
        putenv('BOOKING_MICROSOFT_DEMO_OID');
        $db->rollback();
    }
    check('database fixtures rolled back', !$db->first('user', ['id', $id]));
}
echo "All $checks Microsoft SSO checks passed.\n";
