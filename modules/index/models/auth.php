<?php
/**
 * @filesource modules/index/models/auth.php
 *
 * Authentication Model - Production-grade security
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Auth;

use Kotchasan\Database\Sql;

/**
 * Authentication Model
 *
 * Handles secure authentication with database
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * A device that has made no request for this long (seconds, 1 hour) is signed out
     */
    const SESSION_IDLE_TIMEOUT = 3600;

    /**
     * A session ends this long after sign-in (seconds, 12 hours), however busy it is
     */
    const SESSION_ABSOLUTE_TIMEOUT = 43200;

    /**
     * last_seen is written at most this often (seconds), not on every request
     */
    const SESSION_TOUCH_INTERVAL = 60;

    /**
     * Token expiry time in seconds: tokens never outlive their session
     */
    const TOKEN_EXPIRY = self::SESSION_ABSOLUTE_TIMEOUT;

    /**
     * Refresh token expiry time in seconds: bound by the same session
     */
    const REFRESH_TOKEN_EXPIRY = self::SESSION_ABSOLUTE_TIMEOUT;

    /**
     * Authenticate user with username/email and password
     *
     * @param string $username Username or email
     * @param string $password Plain text password
     * @param string $clientIp Client IP for logging
     *
     * @return array Authentication result
     */
    public static function authenticate($username, $password, $clientIp = null, array $deviceContext = [])
    {
        // Sanitize input
        $username = trim($username);

        // Check for empty credentials
        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'message' => 'Username and password are required.',
                'code' => 'INVALID_INPUT'
            ];
        }

        // Check rate limiting
        $rateLimitResult = self::checkRateLimit($username, $clientIp);
        if (!$rateLimitResult['allowed']) {
            return [
                'success' => false,
                'message' => $rateLimitResult['message'],
                'code' => 'RATE_LIMITED',
                'retry_after' => $rateLimitResult['retry_after']
            ];
        }

        // Query user from database
        $user = self::findUserByCredentials($username);

        if (!$user) {
            // Record failed attempt
            self::recordLoginAttempt($username, $clientIp, false, (string) ($deviceContext['user_agent'] ?? ''));

            // Generic error message to prevent user enumeration
            return [
                'success' => false,
                'message' => 'Invalid username or password',
                'code' => 'AUTH_FAILED'
            ];
        }

        // Verify password
        if (!self::verifyPassword($password, $user->password, $user->salt)) {
            // Record failed attempt
            self::recordLoginAttempt($username, $clientIp, false, (string) ($deviceContext['user_agent'] ?? ''));

            return [
                'success' => false,
                'message' => 'Invalid username or password',
                'code' => 'AUTH_FAILED'
            ];
        }

        // Check if account is active
        if ($user->active != 1) {
            return [
                'success' => false,
                'message' => 'Your account is not active',
                'code' => 'ACCOUNT_INACTIVE'
            ];
        }

        // Check if email verification is pending
        if (!empty($user->activatecode)) {
            return [
                'success' => false,
                'message' => 'Please verify your email address first',
                'code' => 'EMAIL_NOT_VERIFIED'
            ];
        }

        // Record successful login
        self::recordLoginAttempt($username, $clientIp, true);

        // A session of its own for this device
        $tokens = self::startSession($user->id, $deviceContext);

        // Log successful login
        self::logLoginActivity($user->id, 'Login successful', $clientIp);

        // Prepare user data (exclude sensitive fields)
        $userData = self::sanitizeUserData($user);

        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => $userData,
            'token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => self::TOKEN_EXPIRY,
            'token_type' => 'Bearer'
        ];
    }

    /**
     * Find user by username or email
     *
     * @param string $username
     *
     * @return object|null
     */
    public static function findUserByCredentials($username)
    {
        // Build WHERE conditions based on login fields
        $where = [];
        $loginFields = self::$cfg->login_fields ?? ['username'];

        foreach ($loginFields as $field) {
            $fieldName = ($field === 'email' || $field === 'username') ? 'username' : $field;
            $where[$fieldName] = ['U.'.$fieldName, $username];
        }

        $user = static::createQuery()
            ->select('U.*', Sql::GROUP_CONCAT(['D.name', '|', 'D.value'], 'metas', ','))
            ->from('user U')
            ->join('user_meta D', ['D.member_id', 'U.id'], 'LEFT')
            ->where(['U.username', '!=', ''])
            ->where(array_values($where), 'OR')
            ->groupBy('U.id')
            ->first();

        if ($user) {
            $user->permission = self::parsePermission($user->permission);
            $user->metas = self::parseMeta($user->metas);
        }

        return $user;
    }

    /**
     * Verify password against stored hash
     *
     * @param string $password Plain text password
     * @param string $hash Stored password hash
     * @param string $salt User's salt
     *
     * @return bool
     */
    public static function verifyPassword($password, $hash, $salt)
    {
        $passwordKey = self::$cfg->password_key ?? '';
        $computedHash = sha1($passwordKey.$password.$salt);

        return hash_equals($hash, $computedHash);
    }

    /**
     * Hash password for storage
     *
     * @param string $password Plain text password
     * @param string|null $salt Optional salt (generates new one if not provided)
     *
     * @return array ['hash' => string, 'salt' => string]
     */
    public static function hashPassword($password, $salt = null)
    {
        $salt = $salt ?? \Kotchasan\Password::uniqid();
        $passwordKey = self::$cfg->password_key ?? '';
        $hash = sha1($passwordKey.$password.$salt);

        return [
            'hash' => $hash,
            'salt' => $salt
        ];
    }

    /**
     * Set authentication cookie
     *
     * @param string $name Cookie name
     * @param string $value Cookie value
     */
    public static function setCookie($name, $value, $sameSite = null)
    {
        // Follow framework HTTPS detection, including the configured reverse proxy.
        // OAuth callbacks use Lax so the first redirected request carries the cookie.
        $secure = (defined('HTTPS') && HTTPS) || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
        $sameSite = in_array($sameSite, ['Strict', 'Lax'], true) ? $sameSite : ($secure ? 'Strict' : 'Lax');
        $options = [
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite,
            'expires' => time() + self::TOKEN_EXPIRY
        ];

        setcookie($name, $value, $options);
    }

    /**
     * Generate access and refresh tokens
     *
     * @param int $userId
     * @param array $deviceContext
     * @param string|null $sessionId Existing session to issue for (refresh)
     * @param int|null $expiresAt Unix time the tokens expire; defaults to a full session from now
     *
     * @return array
     */
    public static function generateTokens($userId, array $deviceContext = [], $sessionId = null, $expiresAt = null)
    {
        $now = time();
        $expiresAt = $expiresAt ?: $now + self::SESSION_ABSOLUTE_TIMEOUT;
        $sessionId = $sessionId ?: bin2hex(random_bytes(12));
        $fingerprint = $deviceContext['fingerprint'] ?? null;

        $extraPayload = [
            'sid' => $sessionId
        ];
        if (!empty($fingerprint)) {
            $extraPayload['dfp'] = $fingerprint;
        }

        // Generate cryptographically secure tokens
        $accessToken = self::generateSecureToken($userId, $now, $expiresAt - $now, 'access', $extraPayload);
        $refreshToken = self::generateSecureToken($userId, $now, $expiresAt - $now, 'refresh', $extraPayload);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt,
            'refresh_expires_at' => $expiresAt,
            'session_id' => $sessionId,
            'device_fingerprint' => $fingerprint
        ];
    }

    /**
     * Generate a secure token
     *
     * @param int $userId
     * @param int $timestamp
     * @param int $expiry
     * @param string $type
     * @param array $extraPayload Session metadata
     *
     * @return string
     */
    private static function generateSecureToken($userId, $timestamp, $expiry, $type = 'access', $extraPayload = [])
    {
        // Create payload
        $payload = [
            'sub' => $userId,
            'iat' => $timestamp,
            'exp' => $timestamp + $expiry,
            'type' => $type,
            'jti' => bin2hex(random_bytes(16))
        ];

        // Merge session metadata.
        if (!empty($extraPayload)) {
            $payload = array_merge($payload, $extraPayload);
        }

        // Issue a standard 3-part JWT (header.payload.signature, HS256). This
        // replaces the previous non-standard 2-part token so the value is a real
        // JWT that the API/JS layers can parse and validate consistently.
        return \Kotchasan\Jwt::encode($payload, self::getTokenSecret(), 'HS256');
    }

    /**
     * Verify and decode a token whose session is still live, and count the
     * request as activity on that session.
     *
     * @param string $token
     *
     * @return array|null Returns decoded payload or null if invalid
     */
    public static function verifyToken($token)
    {
        $payload = self::decodeToken($token);
        if (!$payload || !self::checkSession($payload, true)) {
            return null;
        }

        return $payload;
    }

    /**
     * Check a token's signature and expiry only, not its session.
     *
     * @param string $token
     *
     * @return array|null
     */
    private static function decodeToken($token)
    {
        if (empty($token)) {
            return null;
        }

        // Verify the standard JWT: checks the HS256 signature, the algorithm
        // allow-list (rejects alg:none), and the exp/nbf claims.
        $payload = \Kotchasan\Jwt::decode($token, self::getTokenSecret(), ['HS256']);

        if (!$payload || !isset($payload['exp']) || !isset($payload['sub'])) {
            return null;
        }

        return $payload;
    }

    /**
     * Get token secret from config
     *
     * @return string
     */
    private static function getTokenSecret()
    {
        // Try to get from config
        $secret = self::$cfg->jwt_secret ?? self::$cfg->password_key ?? null;

        if (empty($secret)) {
            // Generate and store if not exists
            $secret = bin2hex(random_bytes(32));
            // In production, this should be stored in config
        }

        return $secret;
    }

    /**
     * Sign a user in on one device: a new session row and the tokens for it.
     * Sessions on the user's other devices are left alone.
     *
     * @param int $userId
     * @param array $deviceContext ip, user_agent, fingerprint (taken from the request when missing)
     *
     * @return array Tokens, as generateTokens()
     */
    public static function startSession($userId, array $deviceContext = [])
    {
        $deviceContext += [
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        ];
        $tokens = self::generateTokens($userId, $deviceContext);
        $now = date('Y-m-d H:i:s');

        self::pruneSessions();
        $db = \Kotchasan\DB::create();
        $db->insert('user_session', [
            'id' => $tokens['session_id'],
            'member_id' => (int) $userId,
            'created_at' => $now,
            'last_seen' => $now,
            'expires_at' => date('Y-m-d H:i:s', $tokens['expires_at']),
            'ip' => substr((string) $deviceContext['ip'], 0, 45),
            'user_agent' => mb_substr((string) $deviceContext['user_agent'], 0, 500)
        ]);
        $db->update('user', ['id', $userId], [
            'visited' => Sql::create('`visited` + 1')
        ]);

        return $tokens;
    }

    /**
     * Get user by token
     *
     * @param string $token
     *
     * @return object|null
     */
    public static function getUserByToken($token)
    {
        // First verify the token structure
        $payload = self::verifyToken($token);
        if (!$payload) {
            return null;
        }

        // The session is live; the account must still be allowed in too, so an
        // admin deactivating it takes effect on the next request.
        $user = static::createQuery()
            ->select('U.*', Sql::GROUP_CONCAT(['D.name', '|', 'D.value'], 'metas'))
            ->from('user U')
            ->join('user_meta D', ['D.member_id', 'U.id'], 'LEFT')
            ->where([
                ['U.id', $payload['sub']],
                ['U.active', 1]
            ])
            ->groupBy('U.id')
            ->first();

        if ($user) {
            $user->permission = self::parsePermission($user->permission);
            $user->metas = self::parseMeta($user->metas);
        }

        return $user;
    }

    /**
     * Get user by ID
     *
     * @param int $userId
     *
     * @return object|null
     */
    public static function getUserById($userId)
    {
        $user = static::createQuery()
            ->select('U.*', Sql::GROUP_CONCAT(['D.name', '|', 'D.value'], 'metas', ','))
            ->from('user U')
            ->join('user_meta D', ['D.member_id', 'U.id'], 'LEFT')
            ->where(['U.id', $userId])
            ->groupBy('U.id')
            ->first();

        if ($user) {
            $user->permission = self::parsePermission($user->permission);
            $user->metas = self::parseMeta($user->metas);
        }

        return $user;
    }

    /**
     * Refresh access token
     *
     * @param string $refreshToken
     *
     * @return array
     */
    public static function refreshAccessToken($refreshToken, array $deviceContext = [])
    {
        // Verify refresh token. A refresh runs on a timer, so it is not activity:
        // it must not keep an idle session alive.
        $payload = self::decodeToken($refreshToken);
        if ($payload && !self::checkSession($payload, false)) {
            $payload = null;
        }

        if (!$payload || ($payload['type'] ?? '') !== 'refresh') {
            return [
                'success' => false,
                'message' => 'Invalid refresh token',
                'code' => 'INVALID_TOKEN'
            ];
        }

        // Device/session binding check
        $payloadFingerprint = $payload['dfp'] ?? null;
        $currentFingerprint = $deviceContext['fingerprint'] ?? null;
        if (!empty($payloadFingerprint) && !empty($currentFingerprint) && !hash_equals($payloadFingerprint, $currentFingerprint)) {
            return [
                'success' => false,
                'message' => 'Device mismatch for refresh token',
                'code' => 'INVALID_DEVICE'
            ];
        }

        $userId = $payload['sub'];

        // Get user
        $user = self::getUserById($userId);
        if (!$user || $user->active != 1) {
            return [
                'success' => false,
                'message' => 'No data available or inactive',
                'code' => 'USER_INVALID'
            ];
        }

        // New tokens for the same session, ending when the session ends: a
        // refresh never extends the 12 hours.
        $tokens = self::generateTokens($userId, $deviceContext, $payload['sid'], self::sessionExpiresAt($payload['sid']));

        return [
            'success' => true,
            'token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => self::TOKEN_EXPIRY,
            'token_type' => 'Bearer',
            'user' => self::sanitizeUserData($user)
        ];
    }

    /**
     * Logout user
     *
     * @param string $token
     *
     * @return bool
     */
    public static function logout($token)
    {
        // Only this device's session ends; the user's other devices stay signed in.
        $payload = self::decodeToken($token);
        if ($payload && !empty($payload['sid'])) {
            self::removeSession($payload['sid']);
            self::logLoginActivity($payload['sub'], 'Logout', null);
        }

        return true;
    }

    /**
     * Whether a token's session is live: it exists, belongs to the token's user,
     * is younger than SESSION_ABSOLUTE_TIMEOUT and has been used within
     * SESSION_IDLE_TIMEOUT. A session found dead is deleted.
     *
     * @param array $payload Decoded token
     * @param bool $touch Count this request as activity
     *
     * @return bool
     */
    private static function checkSession(array $payload, $touch)
    {
        if (empty($payload['sid'])) {
            return false;
        }
        $session = static::createQuery()
            ->select('member_id', 'last_seen', 'expires_at')
            ->from('user_session')
            ->where([['id', $payload['sid']]])
            ->first();
        if (!$session || (int) $session->member_id !== (int) $payload['sub']) {
            return false;
        }

        // Compared in PHP, as they were written, so a database clock or time
        // zone that differs from PHP's cannot shorten or stretch a session.
        $now = time();
        $lastSeen = strtotime($session->last_seen);
        if (strtotime($session->expires_at) <= $now || $lastSeen + self::SESSION_IDLE_TIMEOUT <= $now) {
            self::removeSession($payload['sid']);

            return false;
        }
        if ($touch && $now - $lastSeen >= self::SESSION_TOUCH_INTERVAL) {
            \Kotchasan\DB::create()->update('user_session', [['id', $payload['sid']]], [
                'last_seen' => date('Y-m-d H:i:s', $now)
            ]);
        }

        return true;
    }

    /**
     * Unix time a session ends (its absolute limit).
     *
     * @param string $sessionId
     *
     * @return int|null
     */
    private static function sessionExpiresAt($sessionId)
    {
        $session = static::createQuery()
            ->select('expires_at')
            ->from('user_session')
            ->where([['id', $sessionId]])
            ->first();

        return $session ? strtotime($session->expires_at) : null;
    }

    /**
     * End one session (one device).
     *
     * @param string $sessionId
     */
    private static function removeSession($sessionId)
    {
        \Kotchasan\DB::create()->delete('user_session', [['id', $sessionId]]);
    }

    /**
     * Delete every session past either limit. Run on sign-in, so the table only
     * holds sessions that could still be used.
     */
    private static function pruneSessions()
    {
        $now = time();
        \Kotchasan\DB::create()->delete('user_session', [
            ['expires_at', '<=', date('Y-m-d H:i:s', $now)],
            ['last_seen', '<=', date('Y-m-d H:i:s', $now - self::SESSION_IDLE_TIMEOUT)]
        ], 0, 'OR');
    }

    /**
     * Check rate limiting for login attempts
     *
     * @param string $username
     * @param string|null $clientIp
     *
     * @return array
     */
    public static function checkRateLimit($username, $clientIp = null)
    {
        $ip = (string) ($clientIp ?? '');

        if (!class_exists('\\Gcms\\LoginAttempt') || !\Gcms\LoginAttempt::isLocked($username, $ip)) {
            return ['allowed' => true];
        }

        $retryAfter = \Gcms\LoginAttempt::getRemainingLockTime($username, $ip);
        if ($retryAfter >= 60) {
            $message = \Kotchasan\Language::replace('Too many login attempts. Please try again in :minutes minutes.', [':minutes' => (int) ceil($retryAfter / 60)]);
        } elseif ($retryAfter > 0) {
            $message = \Kotchasan\Language::replace('Too many login attempts. Please try again in :seconds seconds.', [':seconds' => (int) $retryAfter]);
        } else {
            $message = 'Too many login attempts. Please try again later.';
        }

        return [
            'allowed' => false,
            'message' => $message,
            'retry_after' => max(0, (int) $retryAfter)
        ];
    }

    /**
     * Record login attempt in the shared DB-backed brute-force store.
     *
     * @param string $username
     * @param string|null $clientIp
     * @param bool $success
     * @param string $userAgent
     */
    public static function recordLoginAttempt($username, $clientIp, $success, $userAgent = '')
    {
        if (!class_exists('\\Gcms\\LoginAttempt')) {
            return;
        }

        $ip = (string) ($clientIp ?? '');

        if ($success) {
            \Gcms\LoginAttempt::clear($username, $ip);
            return;
        }

        \Gcms\LoginAttempt::record($username, $ip, $userAgent);
    }

    /**
     * Log login activity
     *
     * @param int $userId
     * @param string $action
     * @param string|null $clientIp
     */
    public static function logLoginActivity($userId, $action, $clientIp)
    {
        try {
            \Index\Log\Model::add($userId, 'index', 'Auth', $action.' IP: '.($clientIp ?? 'unknown'), $userId);
        } catch (\Exception $e) {
            // Silently fail - logging should not break authentication
        }
    }

    /**
     * Sanitize user data for response (remove sensitive fields)
     *
     * @param object $user
     *
     * @return array
     */
    public static function sanitizeUserData($user)
    {
        $data = (array) $user;

        // Remove sensitive fields
        unset(
            $data['password'],
            $data['salt'],
            $data['token'],
            $data['token_expires'],
            $data['activatecode']
        );

        return $data;
    }

    /**
     * Parse and Normalize permission
     * Ensure API always returns an array of non-empty permission keys
     *
     * @param mixed $permission
     *
     * @return array
     */
    public static function parsePermission($permission)
    {
        if (is_array($permission)) {
            $perms = $permission;
        } elseif (is_string($permission)) {
            $perms = empty($permission)
                ? []
                : explode(',', trim($permission, " \t\n\r\0\x0B,"));
        } else {
            $perms = [];
        }

        $perms = array_map('trim', $perms);
        $perms = array_filter($perms, function ($v) {
            return $v !== '';
        });
        return array_values($perms);
    }

    /**
     * Parse and normalize meta data from GROUP_CONCAT format
     * Converts 'key1|value1,key2|value2' into ['key1' => ['value1'], 'key2' => ['value2']]
     *
     * @param string|null $metaData The concatenated meta data string
     *
     * @return array Associative array of meta keys to value arrays
     */
    public static function parseMeta($metaData)
    {
        if (empty($metaData)) {
            return [];
        }

        $metas = [];
        foreach (explode(',', $metaData) as $meta) {
            if (strpos($meta, '|') === false) {
                continue; // Skip malformed entries
            }

            [$key, $value] = explode('|', $meta, 2);
            $metas[$key][] = $value;
        }

        return $metas;
    }

    /**
     * Update user profile
     *
     * @param int $userId
     * @param array $data
     * @param string|null $newPassword
     *
     * @return array
     */
    public static function updateProfile($userId, array $data, $newPassword = null)
    {
        // Validate user exists
        $user = self::getUserById($userId);
        if (!$user) {
            return [
                'success' => false,
                'message' => 'No data available',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        // Prepare update data
        $updateData = [];

        // Allowed fields for update
        $allowedFields = ['name', 'phone', 'sex', 'provinceID', 'address', 'address2', 'zipcode', 'birthday', 'website', 'company'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        // Handle password change
        if (!empty($newPassword)) {
            if (strlen($newPassword) < 8) {
                return [
                    'success' => false,
                    'message' => 'Password must be at least 8 characters',
                    'code' => 'INVALID_PASSWORD'
                ];
            }

            $passwordData = self::hashPassword($newPassword);
            $updateData['password'] = $passwordData['hash'];
            $updateData['salt'] = $passwordData['salt'];
        }

        // Update database
        if (!empty($updateData)) {
            \Kotchasan\DB::create()->update('user', [['id', $userId]], $updateData);
        }

        return [
            'success' => true,
            'message' => 'Profile updated successfully'
        ];
    }

}
