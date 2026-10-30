<?php
namespace Gcms;

/** Server-side Microsoft OIDC helpers. Never accept identity data from the browser. */
class MicrosoftOAuth
{
    const PERSONAL_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';
    const GRAPH_SCOPE = 'https://graph.microsoft.com/User.Read';
    const CMU_SCOPE = 'api://cmu/Mis.Account.Read.Me.Basicinfo';
    const CMU_BASICINFO_URL = 'https://api.cmu.ac.th/mis/cmuaccount/prod/v3/me/basicinfo';

    /**
     * Where the profile comes from after the ID token is validated:
     * 'graph' (Microsoft Graph /me, default) or 'cmu' (CMU MIS BasicInfo,
     * for app registrations that are granted only the CMU API scope).
     */
    public static function profileApi($cfg)
    {
        return strtolower(trim((string) ($cfg->microsoft_profile_api ?? ''))) === 'cmu' ? 'cmu' : 'graph';
    }

    /** One resource per access token: Graph or CMU API, never both. */
    public static function scope($cfg)
    {
        return 'openid profile email '.(self::profileApi($cfg) === 'cmu' ? self::CMU_SCOPE : self::GRAPH_SCOPE);
    }

    public static function demoEmail()
    {
        return strtolower(trim((string) getenv('BOOKING_MICROSOFT_DEMO_EMAIL')));
    }

    /**
     * When enabled, Microsoft is the only supported sign-in method.
     * Keep this as an environment switch so a delivered deployment can
     * change the policy without editing application code.
     */
    public static function microsoftOnly()
    {
        return in_array(strtolower(trim((string) getenv('BOOKING_MICROSOFT_ONLY'))), ['1', 'true', 'yes', 'on'], true);
    }

    public static function authority($cfg)
    {
        return self::demoEmail() !== '' ? 'common' : $cfg->microsoft_tenant;
    }

    public static function isDemoIdentity(array $identity)
    {
        $oid = (string) getenv('BOOKING_MICROSOFT_DEMO_OID');
        return self::demoEmail() !== '' && self::guid($oid)
            && ($identity['microsoft_tenant_id'] ?? '') === self::PERSONAL_TENANT
            && hash_equals($oid, $identity['microsoft_id'] ?? '');
    }

    public static function guid($value)
    {
        return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value);
    }

    public static function domains($value)
    {
        $domains = preg_split('/[\s,;]+/', strtolower(trim((string) $value)), -1, PREG_SPLIT_NO_EMPTY);
        foreach ($domains as $domain) {
            if (!filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || strpos($domain, '.') === false) {
                throw new \RuntimeException('Invalid allowed domain');
            }
        }
        return array_values(array_unique($domains));
    }

    public static function validRedirect($url)
    {
        $parts = parse_url($url);
        return $parts && filter_var($url, FILTER_VALIDATE_URL)
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && isset($parts['host'], $parts['scheme'], $parts['path'])
            && ($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1'], true)))
            && substr($parts['path'], -23) === '/microsoft/callback.php';
    }

    public static function configured($cfg)
    {
        try {
            return self::guid($cfg->microsoft_client_id ?? '')
                && self::guid($cfg->microsoft_tenant ?? '')
                && !empty($cfg->microsoft_client_secret)
                && self::validRedirect($cfg->microsoft_redirect_uri ?? '')
                && count(self::domains($cfg->microsoft_allowed_domains ?? '')) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function baseUrl($redirect)
    {
        return substr($redirect, 0, -strlen('microsoft/callback.php'));
    }

    /** SPA routes only; no schemes, protocol-relative URLs, backslashes or control characters. */
    public static function returnUrl($intended, $base)
    {
        $decoded = rawurldecode((string) $intended);
        if (!is_string($intended) || preg_match('/[\x00-\x20\x7f\\\\]/', $decoded)
            || strpos($decoded, '//') === 0 || preg_match('/^[a-z][a-z0-9+.-]*:/i', ltrim($decoded, '/'))
            || preg_match('~(^|/)\.\.(/|$)~', $decoded)) {
            return $base;
        }
        return $base.ltrim($intended, '/');
    }

    public static function encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function decode($data)
    {
        if (!is_string($data) || !preg_match('/^[A-Za-z0-9_-]+$/D', $data)) {
            throw new \RuntimeException('Invalid token encoding');
        }
        $result = base64_decode(strtr($data, '-_', '+/'), true);
        if ($result === false) {
            throw new \RuntimeException('Invalid token encoding');
        }
        return $result;
    }

    /** Fixed Microsoft and CMU API endpoints only. TLS verification and bounded requests are mandatory. */
    public static function request($url, $post = null, $bearer = null)
    {
        $curl = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer '.$bearer;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $headers
        ]);
        if ($post !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post, '', '&', PHP_QUERY_RFC3986));
        }
        $raw = curl_exec($curl);
        $err = curl_error($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($status !== 200 || !is_array($data)) {
            $msg = !empty($err) ? $err : ($data['error_description'] ?? ($data['error'] ?? "HTTP status $status"));
            throw new \RuntimeException('Microsoft service request failed: '.$msg);
        }
        return $data;
    }

    /** Validate RS256 using certificates from the tenant's fixed HTTPS JWKS endpoint. */
    public static function validateIdToken($token, array $jwks, $client, $tenant, $nonce, $allowPersonal = false)
    {
        $parts = explode('.', (string) $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid ID token');
        }
        $header = json_decode(self::decode($parts[0]), true);
        $claims = json_decode(self::decode($parts[1]), true);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid']) || !empty($header['crit'])) {
            throw new \RuntimeException('Invalid ID token header');
        }
        $verified = false;
        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['kid'] ?? '') !== $header['kid'] || ($key['kty'] ?? '') !== 'RSA'
                || ($key['use'] ?? 'sig') !== 'sig' || empty($key['x5c'][0])) {
                continue;
            }
            $certificate = "-----BEGIN CERTIFICATE-----\n".chunk_split($key['x5c'][0], 64, "\n")."-----END CERTIFICATE-----\n";
            $verified = openssl_verify($parts[0].'.'.$parts[1], self::decode($parts[2]), $certificate, OPENSSL_ALGO_SHA256) === 1;
            if ($verified) {
                break;
            }
        }
        $now = time();
        $expectedTenant = $allowPersonal && ($claims['tid'] ?? '') === self::PERSONAL_TENANT
            ? self::PERSONAL_TENANT : $tenant;
        if (!$verified || ($claims['aud'] ?? '') !== $client
            || ($claims['tid'] ?? '') !== $expectedTenant
            || ($claims['iss'] ?? '') !== 'https://login.microsoftonline.com/'.$expectedTenant.'/v2.0'
            || ($claims['ver'] ?? '') !== '2.0'
            || !is_int($claims['exp'] ?? null) || $claims['exp'] <= $now
            || !is_int($claims['nbf'] ?? null) || $claims['nbf'] > $now + 60
            || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])
            || !self::guid($claims['oid'] ?? '')) {
            throw new \RuntimeException('ID token validation failed');
        }
        return $claims;
    }

    /**
     * Graph leaves userType null on some older / directory-synced member accounts,
     * so a blank type is not proof of a guest. Invited (B2B) guests always carry
     * an #EXT# user principal name, so a blank type counts as a member unless it has one.
     */
    public static function isGuest(array $user)
    {
        $type = trim((string) ($user['userType'] ?? ''));
        if ($type !== '') {
            return strcasecmp($type, 'Member') !== 0;
        }
        return stripos((string) ($user['userPrincipalName'] ?? ''), '#EXT#') !== false;
    }

    public static function profile(array $user, array $claims, $allowedDomains)
    {
        if (($user['id'] ?? '') !== ($claims['oid'] ?? null) || self::isGuest($user)) {
            $account = strtolower((string) ($user['mail'] ?? '') ?: (string) ($user['userPrincipalName'] ?? ''));
            error_log('Microsoft SSO: not a member: userType='.json_encode($user['userType'] ?? null)
                .' id_matches='.(($user['id'] ?? '') === ($claims['oid'] ?? null) ? 'yes' : 'no')
                .' domain='.(substr(strrchr($account, '@') ?: '', 1) ?: '-'));
            throw new \RuntimeException('This Microsoft account is not an eligible university member.');
        }
        $email = strtolower(trim($user['mail'] ?? ''));
        if ($email === '') {
            $email = strtolower(trim($user['userPrincipalName'] ?? ''));
        }
        $domain = substr(strrchr($email, '@') ?: '', 1);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($domain, self::domains($allowedDomains), true)) {
            throw new \RuntimeException('Your email domain is not allowed to access Booking.');
        }
        return [
            'username' => $email,
            'name' => trim($user['displayName'] ?? '') ?: $email,
            'microsoft_id' => $claims['oid'],
            'microsoft_tenant_id' => $claims['tid'],
            'social' => 'microsoft',
            'status' => 0
        ];
    }

    /**
     * CMU BasicInfo in place of Graph /me. Identity stays the validated ID token's
     * oid/tid; BasicInfo only supplies the email and name. Guests and accounts
     * without a CMU MIS record get no cmuitaccount and are rejected here.
     */
    public static function cmuProfile(array $info, array $claims, $allowedDomains)
    {
        $email = strtolower(trim((string) ($info['cmuitaccount'] ?? '')));
        $domain = substr(strrchr($email, '@') ?: '', 1);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($domain, self::domains($allowedDomains), true)) {
            error_log('Microsoft SSO: CMU BasicInfo account rejected: domain='.($domain ?: '-'));
            throw new \RuntimeException('Your email domain is not allowed to access Booking.');
        }
        $name = trim(trim((string) ($info['firstname_TH'] ?? '')).' '.trim((string) ($info['lastname_TH'] ?? '')));
        if ($name === '') {
            $name = trim(trim((string) ($info['firstname_EN'] ?? '')).' '.trim((string) ($info['lastname_EN'] ?? '')));
        }
        return [
            'username' => $email,
            'name' => $name !== '' ? $name : $email,
            'microsoft_id' => $claims['oid'],
            'microsoft_tenant_id' => $claims['tid'],
            'social' => 'microsoft',
            'status' => 0
        ];
    }

    public static function consumeState(array &$states, $state)
    {
        $transaction = is_string($state) ? ($states[$state] ?? null) : null;
        if (is_string($state)) {
            unset($states[$state]);
        }
        if (!is_array($transaction) || ($transaction['expires'] ?? 0) < time()) {
            throw new \RuntimeException('Login request expired or invalid. Please sign in again.');
        }
        return $transaction;
    }
}
