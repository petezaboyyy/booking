<?php
/** Isolated HTTP/session tests with in-memory config. No real Microsoft calls or settings writes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('BOOKING_MICROSOFT_DEMO_EMAIL');
putenv('BOOKING_MICROSOFT_DEMO_OID');
$root = dirname(__DIR__);
$router = tempnam(sys_get_temp_dir(), 'booking-sso-router-');
$log = tempnam(sys_get_temp_dir(), 'booking-sso-http-');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://'.$address;
$source = '<?php if (isset($_GET["simulate_https"])) { $_SERVER["HTTPS"] = "on"; } require '.var_export($root.'/microsoft/bootstrap.php',true).';
$app = Kotchasan::createWebApplication("Gcms\\\\Config");
$property = new ReflectionProperty("Kotchasan\\\\KBase", "cfg");
$property->setAccessible(true);
$cfg = $property->getValue();
$cfg->microsoft_client_id = "22222222-2222-4222-8222-222222222222";
$cfg->microsoft_tenant = "11111111-1111-4111-8111-111111111111";
$cfg->microsoft_client_secret = "fixture-secret-never-expose";
$cfg->microsoft_allowed_domains = "university.ac.th";
$cfg->microsoft_redirect_uri = '.var_export($base.'/microsoft/callback.php',true).';
$app->defaultController = str_contains($_SERVER["REQUEST_URI"], "callback.php") ? "Index\\\\Microsoftcallback\\\\Controller" : "Index\\\\Microsoftlogin\\\\Controller";
if (str_contains($_SERVER["REQUEST_URI"], "/cookie")) {
    \Index\Auth\Model::setCookie("auth_token", "fixture-cookie", "Lax");
    echo "ok";
    return;
}
$app->run();';
file_put_contents($router,$source);
$process = proc_open([PHP_BINARY,'-S',$address,$router],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root);
function requestPage($url, $cookie = '') {
    $curl=curl_init($url);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIE=>$cookie,CURLOPT_TIMEOUT=>5]);
    $raw=curl_exec($curl);
    $status=curl_getinfo($curl,CURLINFO_HTTP_CODE);
    curl_close($curl);
    return [$status,$raw ?: ''];
}
function ensure($label,$condition) {
    if (!$condition) throw new RuntimeException('FAIL: '.$label);
    echo "PASS: $label\n";
}
$failed = false;
try {
    for ($i=0;$i<50;$i++) {
        $socket=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);
        if ($socket) { fclose($socket); break; }
        usleep(20000);
    }
    [$status,$raw]=requestPage($base.'/microsoft/login.php?intended_url=%2Fmy-bookings');
    ensure('configured start redirects', $status===302);
    ensure('OAuth responses disable cache/referrer', stripos($raw,'Cache-Control: no-store')!==false && stripos($raw,'Referrer-Policy: no-referrer')!==false);
    preg_match('/^Location: (.+)$/mi',$raw,$match);
    $authorize=trim($match[1] ?? '');
    parse_str(parse_url($authorize,PHP_URL_QUERY) ?: '',$params);
    ensure('authorize uses fixed tenant', str_starts_with($authorize,'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/oauth2/v2.0/authorize?'));
    ensure('PKCE nonce and state supplied', ($params['code_challenge_method'] ?? '')==='S256' && strlen($params['code_challenge'] ?? '')===43 && strlen($params['state'] ?? '')===43 && strlen($params['nonce'] ?? '')===43);
    ensure('callback matches configured URI exactly', ($params['redirect_uri'] ?? '')===$base.'/microsoft/callback.php');
    ensure('sign-in always offers account selection', ($params['prompt'] ?? '') === 'select_account');
    ensure('secret absent from redirect', !str_contains($raw,'fixture-secret'));
    preg_match('/Set-Cookie: (booking_microsoft_oauth=[^;]+);([^\r\n]+)/i',$raw,$cookie);
    ensure('session is HttpOnly and SameSite Lax', isset($cookie[1]) && stripos($cookie[2],'HttpOnly')!==false && stripos($cookie[2],'SameSite=Lax')!==false);
    $callback=$base.'/microsoft/callback.php?error=access_denied&state='.urlencode($params['state']);
    [$status,$raw]=requestPage($callback);
    ensure('callback without original browser session rejected', $status===400 && !str_contains($raw,'cancelled or denied'));
    [$status,$raw]=requestPage($callback,$cookie[1]);
    ensure('valid callback state reaches cancellation handling', $status===400 && str_contains($raw,'cancelled or denied'));
    [$status,$raw]=requestPage($callback,$cookie[1]);
    ensure('state cannot be replayed over HTTP', $status===400 && !str_contains($raw,'cancelled or denied'));
    [$status,$raw]=requestPage($base.'/cookie?simulate_https=1');
    ensure('HTTPS auth cookie is Secure HttpOnly and Lax', $status===200 && preg_match('/Set-Cookie: auth_token=[^\r\n]*secure; HttpOnly; SameSite=Lax/i',$raw));
    echo "All 11 Microsoft HTTP checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage()."\n".file_get_contents($log));
    $failed = true;
} finally {
    proc_terminate($process);
    proc_close($process);
    unlink($router);
    unlink($log);
}

exit($failed ? 1 : 0);
