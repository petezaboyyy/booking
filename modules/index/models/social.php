<?php
/**
 * @filesource modules/index/models/social.php
 *
 * Social Login Model
 *
 * Handles social authentication user management:
 * - Find existing social users
 * - Create new users from social data
 * - Link social accounts to existing users
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Index\Social;

use Kotchasan\Language;

/**
 * Social Authentication Model
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Model extends \Kotchasan\Model
{
    /**
     * Supported social providers stored in the user.social enum column.
     */
    const PROVIDERS = ['facebook', 'google', 'line', 'telegram', 'microsoft'];

    /**
     * Find or create user from social login
     *
     * @param string $provider Provider name (google, facebook, line, telegram)
     * @param array $socialUser User data from provider
     *
     * @return array Result with user data
     */
    public static function findOrCreateSocialUser($provider, array $socialUser)
    {
        $provider = self::normalizeProvider($provider);
        if ($provider === 'microsoft') {
            return ['success' => false, 'message' => 'Use the Microsoft OAuth callback'];
        }
        $socialId = trim((string) ($socialUser['id'] ?? ''));

        if ($socialId === '' || !self::isSupportedProvider($provider)) {
            return [
                'success' => false,
                'message' => 'Invalid social user data'
            ];
        }

        // First, try to find by social ID and provider
        $existingUser = self::findBySocialId($provider, $socialId);

        if ($existingUser) {
            // Update user info from social provider
            self::updateSocialUserInfo($existingUser->id, $socialUser);

            return [
                'success' => true,
                'message' => Language::get('Login successful'),
                'user' => self::sanitizeUserData($existingUser),
                'isNew' => false
            ];
        }

        // Try to find by email if available
        if (!empty($socialUser['email'])) {
            $emailUser = self::findByEmail($socialUser['email']);

            if ($emailUser) {
                // Link social account to existing user
                self::linkSocialAccount($emailUser->id, $provider, $socialId, $socialUser);

                return [
                    'success' => true,
                    'message' => Language::get('Account linked successfully'),
                    'user' => self::sanitizeUserData($emailUser),
                    'isNew' => false,
                    'linked' => true
                ];
            }
        }

        // Create new user
        $newUser = self::createFromSocial($provider, $socialUser);

        if (!$newUser) {
            return [
                'success' => false,
                'message' => Language::get('Failed to create account')
            ];
        }

        return [
            'success' => true,
            'message' => Language::get('Account created successfully'),
            'user' => $newUser,
            'isNew' => true
        ];
    }

    /**
     * Authenticate a social-login payload using the shared register/login rules.
     *
     * @param array       $data
     * @param string|null $baseUrl
     * @param string      $providerLabel
     * @param string|null $clientIp
     *
     * @return array
     */
    public static function authenticate(array $data, $baseUrl, $providerLabel, $clientIp = null)
    {
        $provider = self::normalizeProvider($data['social'] ?? '');
        if (!self::isSupportedProvider($provider)) {
            return [
                'success' => false,
                'message' => 'Invalid social user data',
                'code' => 400
            ];
        }

        $data['social'] = $provider;
        if ($provider === 'microsoft') {
            self::ensureSchema();
            if (!\Gcms\MicrosoftOAuth::guid($data['microsoft_id'] ?? '')
                || (($data['microsoft_tenant_id'] ?? '') !== (self::$cfg->microsoft_tenant ?? '')
                    && !\Gcms\MicrosoftOAuth::isDemoIdentity($data))) {
                return ['success' => false, 'message' => 'Invalid Microsoft identity', 'code' => 401];
            }
        }

        $existingUser = self::findAuthUser($provider, $data);
        if ($existingUser !== null) {
            if (self::canLoginWithProvider($existingUser, $provider, $data)) {
                return self::loginUser($existingUser, $providerLabel, $clientIp);
            }

            if ($provider === 'line' && !empty($data['line_uid']) && self::canAttachLineUid($existingUser)) {
                self::attachLineUid($existingUser->id, $data['line_uid'], $data['picture'] ?? '');
                $linkedUser = \Kotchasan\DB::create()->first('user', [['id', $existingUser->id]]);

                return self::loginUser($linkedUser ?: $existingUser, $providerLabel, $clientIp);
            }

            return [
                'success' => false,
                'message' => 'An account with this email already exists. Please use email login.',
                'code' => 401
            ];
        }

        $result = \Index\Register\Model::registerFromSocial($data, $baseUrl);
        // Bootstrap only a newly created, explicitly pinned personal identity.
        // Existing accounts retain their roles, including later demotions.
        if ($provider === 'microsoft' && \Gcms\MicrosoftOAuth::isDemoIdentity($data)
            && !empty($result['success']) && !empty($result['user']['id'])) {
            \Kotchasan\DB::create()->update('user', ['id', $result['user']['id']], ['status' => 1]);
            $result['user']['status'] = 1;
        }
        return $result;
    }

    /**
     * Find user by social ID
     *
     * @param string $provider
     * @param string $socialId
     *
     * @return object|null
     */
    public static function findBySocialId($provider, $socialId)
    {
        $provider = self::normalizeProvider($provider);
        $socialId = trim((string) $socialId);
        if ($socialId === '' || !self::isSupportedProvider($provider)) {
            return null;
        }

        if ($provider === 'microsoft') {
            self::ensureSchema();
            return static::createQuery()->select('*')->from('user')->where([
                ['microsoft_id', $socialId],
                ['microsoft_tenant_id', self::$cfg->microsoft_tenant]
            ])->first();
        }

        if ($provider === 'line') {
            return static::createQuery()
                ->select('*')
                ->from('user')
                ->where([['line_uid', $socialId]])
                ->first();
        }

        if ($provider === 'telegram') {
            return static::createQuery()
                ->select('*')
                ->from('user')
                ->where([['telegram_id', $socialId]])
                ->first();
        }

        $user = static::createQuery()
            ->select('*')
            ->from('user')
            ->where([
                ['username', $socialId],
                ['social', $provider]
            ])
            ->first();

        if ($user) {
            return $user;
        }

        return static::createQuery()
            ->select('*')
            ->from('user')
            ->where([
                ['username', $provider.'_'.$socialId],
                ['social', $provider]
            ])
            ->first();
    }

    /**
     * Find user by email
     *
     * @param string $email
     *
     * @return object|null
     */
    public static function findByEmail($email)
    {
        return static::createQuery()
            ->select('*')
            ->from('user')
            ->where([['username', $email]])
            ->first();
    }

    /**
     * Create new user from social data
     *
     * @param string $provider
     * @param array $socialUser
     *
     * @return array|null
     */
    public static function createFromSocial($provider, array $socialUser)
    {
        $provider = self::normalizeProvider($provider);
        $socialId = trim((string) ($socialUser['id'] ?? ''));
        if ($socialId === '' || !self::isSupportedProvider($provider)) {
            return null;
        }

        $username = self::buildUsername($provider, $socialUser);

        // Ensure username uniqueness; if collision, append random suffix
        if (!\Index\UserRepository\Model::isFieldUnique('username', $username)) {
            $username = $provider.'_'.$socialId.'_'.substr(uniqid(), -6);
        }

        // Prepare user data
        $userData = [
            'username' => $username,
            'password' => '', // No password for social users
            'salt' => '',
            'name' => $socialUser['name'] ?? 'User',
            'phone' => '',
            'phone1' => '',
            'status' => 0,
            'active' => 1, // Social users are auto-activated
            'social' => $provider,
            'activatecode' => '',
            'token' => null,
            'token_expires' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'avatar' => $socialUser['avatar'] ?? null,
            'permission' => '',
            'sex' => '',
            'id_card' => '',
            'birthday' => null,
            'website' => '',
            'company' => '',
            'icon' => '',
            'visited' => 0,
            'address' => '',
            'address2' => '',
            'provinceID' => 0,
            'province' => '',
            'zipcode' => '',
            'country' => 'TH',
            'tax_id' => '',
            'line_uid' => ($provider === 'line') ? $socialId : null,
            'telegram_id' => ($provider === 'telegram') ? $socialId : null
        ];

        if ($provider === 'microsoft') {
            $userData['microsoft_id'] = $socialId;
            $userData['microsoft_tenant_id'] = self::$cfg->microsoft_tenant;
        }

        // Insert user
        $db = \Kotchasan\DB::create();
        $userId = $db->insert('user', $userData);

        if (!$userId) {
            return null;
        }

        $userData['id'] = $userId;

        // Log registration
        self::logSocialRegistration($userId, $provider);

        return self::sanitizeUserData((object) $userData);
    }

    /**
     * Link social account to existing user
     *
     * @param int $userId
     * @param string $provider
     * @param string $socialId
     * @param array $socialUser
     */
    public static function linkSocialAccount($userId, $provider, $socialId, array $socialUser)
    {
        $provider = self::normalizeProvider($provider);
        $socialId = trim((string) $socialId);
        if ($socialId === '' || !self::isSupportedProvider($provider)) {
            return;
        }

        $updateData = [
            'social' => $provider
        ];

        if ($provider === 'line') {
            $updateData['line_uid'] = $socialId;
        } elseif ($provider === 'telegram') {
            $updateData['telegram_id'] = $socialId;
        } elseif ($provider === 'microsoft') {
            // Linking is only called with a server-verified identity by trusted code.
            $updateData['microsoft_id'] = $socialId;
            $updateData['microsoft_tenant_id'] = self::$cfg->microsoft_tenant;
        }

        // Update avatar if not set
        if (!empty($socialUser['avatar'])) {
            $currentUser = static::createQuery()
                ->select('avatar')
                ->from('user')
                ->where([['id', $userId]])
                ->first();

            if ($currentUser && empty($currentUser->avatar)) {
                $updateData['avatar'] = $socialUser['avatar'];
            }
        }

        \Kotchasan\DB::create()->update('user', [['id', $userId]], $updateData);

        // Log linking
        self::logSocialLink($userId, $provider);
    }

    /**
     * Update social user info
     *
     * @param int $userId
     * @param array $socialUser
     */
    public static function updateSocialUserInfo($userId, array $socialUser)
    {
        $updateData = [];

        // Update avatar if changed
        if (!empty($socialUser['avatar'])) {
            $updateData['avatar'] = $socialUser['avatar'];
        }

        // Update name if changed and not manually set
        // (Optionally can be disabled via config)
        if (!empty($socialUser['name']) && empty(self::$cfg->preserve_user_name_on_social_login)) {
            // Only update if current name is generic
            $currentUser = static::createQuery()
                ->select('name')
                ->from('user')
                ->where([['id', $userId]])
                ->first();

            if ($currentUser && (empty($currentUser->name) || $currentUser->name === 'User')) {
                $updateData['name'] = $socialUser['name'];
            }
        }

        if (!empty($updateData)) {
            \Kotchasan\DB::create()->update('user', [['id', $userId]], $updateData);
        }
    }

    /**
     * Unlink social account
     *
     * @param int $userId
     * @param string $provider
     *
     * @return array
     */
    public static function unlinkSocialAccount($userId, $provider)
    {
        // Check if user has password set (can only unlink if has password)
        $provider = self::normalizeProvider($provider);
        $user = static::createQuery()
            ->select('*')
            ->from('user')
            ->where([['id', $userId]])
            ->first();

        if (!$user) {
            return [
                'success' => false,
                'message' => Language::get('No data available')
            ];
        }

        $isLinked = $user->social === $provider
            || ($provider === 'line' && !empty($user->line_uid))
            || ($provider === 'telegram' && !empty($user->telegram_id))
            || ($provider === 'microsoft' && !empty($user->microsoft_id));

        if (!$isLinked) {
            return [
                'success' => false,
                'message' => Language::get('Account is not linked to this provider')
            ];
        }

        if (empty($user->password)) {
            return [
                'success' => false,
                'message' => Language::get('Please set a password before unlinking social account')
            ];
        }

        // Update user
        $updateData = [];
        if ($user->social === $provider) {
            $updateData['social'] = 'user';
        }
        if ($provider === 'line') {
            $updateData['line_uid'] = null;
        } elseif ($provider === 'telegram') {
            $updateData['telegram_id'] = null;
        } elseif ($provider === 'microsoft') {
            $updateData['microsoft_id'] = null;
            $updateData['microsoft_tenant_id'] = null;
        }

        \Kotchasan\DB::create()->update('user', [['id', $userId]], $updateData);

        return [
            'success' => true,
            'message' => Language::get('Social account unlinked successfully')
        ];
    }

    /**
     * Get linked social accounts for user
     *
     * @param int $userId
     *
     * @return array
     */
    public static function getLinkedAccounts($userId)
    {
        $user = static::createQuery()
            ->select('*')
            ->from('user')
            ->where([['id', $userId]])
            ->first();

        if (!$user) {
            return [];
        }

        $linked = [];
        if (self::isSupportedProvider($user->social)) {
            $linked[] = $user->social;
        }
        if (!empty($user->line_uid) && !in_array('line', $linked, true)) {
            $linked[] = 'line';
        }
        if (!empty($user->telegram_id) && !in_array('telegram', $linked, true)) {
            $linked[] = 'telegram';
        }

        if (!empty($user->microsoft_id) && !in_array('microsoft', $linked, true)) {
            $linked[] = 'microsoft';
        }
        return $linked;
    }

    /**
     * @param string $provider
     * @param array  $data
     *
     * @return object|null
     */
    private static function findAuthUser($provider, array $data)
    {
        if ($provider === 'microsoft' && !empty($data['microsoft_id'])) {
            self::ensureSchema();
            $user = static::createQuery()->select('*')->from('user')->where([
                ['microsoft_id', $data['microsoft_id']],
                ['microsoft_tenant_id', $data['microsoft_tenant_id'] ?? '']
            ])->first();
            if ($user !== null) {
                return $user;
            }
        }
        if ($provider === 'line' && !empty($data['line_uid'])) {
            $user = self::findBySocialId('line', $data['line_uid']);
            if ($user !== null) {
                return $user;
            }
        }

        if ($provider === 'telegram' && !empty($data['telegram_id'])) {
            $user = self::findBySocialId('telegram', $data['telegram_id']);
            if ($user !== null) {
                return $user;
            }
        }

        $username = trim((string) ($data['username'] ?? ''));

        return $username === '' ? null : \Index\UserRepository\Model::findByUsername($username);
    }

    /**
     * @param object $user
     * @param string $provider
     * @param array  $data
     *
     * @return bool
     */
    private static function canLoginWithProvider($user, $provider, array $data)
    {
        if ($provider === 'microsoft') {
            return !empty($user->microsoft_id)
                && (string) $user->microsoft_id === (string) ($data['microsoft_id'] ?? '')
                && (string) ($user->microsoft_tenant_id ?? '') === (string) ($data['microsoft_tenant_id'] ?? '');
        }
        if (($user->social ?? 'user') === $provider) {
            return true;
        }

        if ($provider === 'line' && !empty($data['line_uid']) && !empty($user->line_uid)) {
            return (string) $user->line_uid === (string) $data['line_uid'];
        }

        if ($provider === 'telegram' && !empty($data['telegram_id']) && !empty($user->telegram_id)) {
            return (string) $user->telegram_id === (string) $data['telegram_id'];
        }

        return false;
    }

    /**
     * @param object $user
     *
     * @return bool
     */
    private static function canAttachLineUid($user)
    {
        $social = (string) ($user->social ?? 'user');

        return $social !== 'line' && empty($user->line_uid);
    }

    /**
     * @param int    $userId
     * @param string $lineUid
     * @param string $avatar
     *
     * @return void
     */
    private static function attachLineUid($userId, $lineUid, $avatar = '')
    {
        $updateData = [
            'line_uid' => $lineUid
        ];

        $currentUser = static::createQuery()
            ->select('avatar')
            ->from('user')
            ->where([['id', $userId]])
            ->first();

        if (!empty($avatar) && $currentUser && empty($currentUser->avatar)) {
            $updateData['avatar'] = $avatar;
        }

        \Kotchasan\DB::create()->update('user', [['id', $userId]], $updateData);
    }

    /**
     * @param object      $existingUser
     * @param string      $providerLabel
     * @param string|null $clientIp
     *
     * @return array
     */
    private static function loginUser($existingUser, $providerLabel, $clientIp = null)
    {
        if ((int) ($existingUser->active ?? 0) !== 1 || !empty($existingUser->activatecode)) {
            return ['success' => false, 'message' => 'Account is not active', 'code' => 401];
        }
        // A session of its own for this device; the user's other devices stay signed in.
        $loginResult = \Index\Auth\Model::startSession($existingUser->id, [
            'ip' => (string) ($clientIp ?? ($_SERVER['REMOTE_ADDR'] ?? ''))
        ]);

        \Index\Auth\Model::logLoginActivity(
            $existingUser->id,
            $providerLabel.' Sign-in: '.$existingUser->username,
            $clientIp
        );

        return [
            'success' => true,
            'message' => 'Login successful',
            'user' => \Index\Auth\Model::sanitizeUserData($existingUser),
            'token' => $loginResult['access_token'],
            'refresh_token' => $loginResult['refresh_token'],
            'expires_in' => \Index\Auth\Model::TOKEN_EXPIRY,
            'token_type' => 'Bearer'
        ];
    }

    /**
     * @param string $provider
     *
     * @return bool
     */
    private static function isSupportedProvider($provider)
    {
        return in_array(self::normalizeProvider($provider), self::PROVIDERS, true);
    }

    /**
     * @param string $provider
     *
     * @return string
     */
    private static function normalizeProvider($provider)
    {
        return strtolower(trim((string) $provider));
    }

    /**
     * @param string $provider
     * @param array  $socialUser
     *
     * @return string
     */
    private static function buildUsername($provider, array $socialUser)
    {
        $email = trim((string) ($socialUser['email'] ?? ''));
        if ($email !== '') {
            return $email;
        }

        $socialId = trim((string) ($socialUser['id'] ?? ''));
        if ($provider === 'facebook') {
            return $socialId;
        }
        if ($provider === 'telegram') {
            $username = trim((string) ($socialUser['username'] ?? ''));

            return $username !== '' ? 'telegram_'.$socialId.'_'.$username : 'telegram_'.$socialId;
        }

        return $provider.'_'.$socialId;
    }

    /**
     * Sanitize user data for response
     *
     * @param object $user
     *
     * @return array
     */
    private static function sanitizeUserData($user)
    {
        $data = is_array($user) ? $user : (array) $user;

        return [
            'id' => $data['id'] ?? null,
            'username' => $data['username'] ?? null,
            'name' => $data['name'] ?? null,
            'email' => $data['username'] ?? null, // username is email
            'avatar' => $data['avatar'] ?? null,
            'status' => $data['status'] ?? 0,
            'active' => $data['active'] ?? 1
        ];
    }

    /**
     * Log social registration
     *
     * @param int $userId
     * @param string $provider
     */
    private static function logSocialRegistration($userId, $provider)
    {
        try {
            if (class_exists('\\Index\\Log\\Model')) {
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                \Index\Log\Model::add($userId, 'index', 'Auth', ucfirst($provider)." Registration provider={$provider} IP={$clientIp}", $userId);
            }
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Log social account linking
     *
     * @param int $userId
     * @param string $provider
     */
    private static function logSocialLink($userId, $provider)
    {
        try {
            if (class_exists('\\Index\\Log\\Model')) {
                $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                \Index\Log\Model::add($userId, 'index', 'Auth', ucfirst($provider)." Linking provider={$provider} IP={$clientIp}", $userId);
            }
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Ensure Microsoft SSO columns and index exist in the user table.
     */
    public static function ensureSchema()
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            $dbConfigFile = (defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3).'/').'settings/database.php';
            if (!file_exists($dbConfigFile)) {
                return;
            }
            $dbConfig = (require $dbConfigFile)['mysql'] ?? null;
            if (!$dbConfig || empty($dbConfig['hostname']) || empty($dbConfig['dbname'])) {
                return;
            }
            $table = ($dbConfig['prefix'] ?? 'app').'_user';
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
                return;
            }
            $dsn = 'mysql:host='.$dbConfig['hostname'].';port='.($dbConfig['port'] ?? 3306).';dbname='.$dbConfig['dbname'].';charset=utf8mb4';
            $pdo = new \PDO($dsn, $dbConfig['username'] ?? '', $dbConfig['password'] ?? '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(\PDO::FETCH_COLUMN);
            if (!in_array('microsoft_id', $columns, true)) {
                $pdo->exec("ALTER TABLE `$table` MODIFY `social` ENUM('user','facebook','google','line','telegram','microsoft') NULL DEFAULT 'user'");
                $pdo->exec("ALTER TABLE `$table` ADD `microsoft_id` VARCHAR(64) NULL DEFAULT NULL");
                $pdo->exec("ALTER TABLE `$table` ADD `microsoft_tenant_id` VARCHAR(36) NULL DEFAULT NULL");
                $indices = $pdo->query("SHOW INDEX FROM `$table`")->fetchAll(\PDO::FETCH_ASSOC);
                if (!in_array('microsoft_identity', array_column($indices, 'Key_name'), true)) {
                    $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `microsoft_identity` (`microsoft_tenant_id`, `microsoft_id`)");
                }
            }
        } catch (\Throwable $e) {
            error_log('ensureSchema error: '.$e->getMessage());
        }
    }
}
