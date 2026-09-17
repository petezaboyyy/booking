<?php
/**
 * @filesource Gcms/Api.php
 *
 * API Base class Controller
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms;

class Api extends \Kotchasan\ApiController
{
    /**
     * Authenticate request and prefer the auth model so user_meta is available.
     *
     * @param \Kotchasan\Http\Request $request
     *
     * @return object|null
     */
    protected function authenticateRequest(\Kotchasan\Http\Request $request)
    {
        $accessToken = $this->getAccessToken($request);

        if (!empty($accessToken) && class_exists('\\Index\\Auth\\Model')) {
            $user = \Index\Auth\Model::getUserByToken($accessToken);
            if ($user !== null) {
                return $user;
            }
        }

        return parent::authenticateRequest($request);
    }

    /**
     * Permission helper: check that user has a specific permission key or admin.
     * User object comes from authenticateRequest() and may contain `permission` as string or array.
     *
     * @param object|null $login User object from authenticateRequest()
     * @param string|array $permission Single permission or array of permissions (OR logic)
     * @param bool $allowAdmin If true, admin users (status=1) automatically pass
     *
     * @return bool True if user has permission
     */
    public static function hasPermission($login, $permission, $allowAdmin = true)
    {
        if (!$login) {
            return false;
        }
        if ($login->status === 1 && $allowAdmin) {
            return true;
        }
        $perms = [];
        if (isset($login->permission)) {
            if (is_array($login->permission)) {
                $perms = $login->permission;
            } elseif (is_string($login->permission)) {
                $perms = empty($login->permission) ? [] : explode(',', trim($login->permission, " \t\n\r\0\x0B,"));
            }
        }
        $perms = array_map('trim', $perms);
        $perms = array_filter($perms, fn($v) => $v !== '');
        // Handle both string and array
        if (is_array($permission)) {
            // OR logic: has ANY of the permissions
            foreach ($permission as $perm) {
                if (in_array($perm, $perms, true)) {
                    return true;
                }
            }
            return false;
        }

        return in_array($permission, $perms, true);
    }

    /**
     * Role helper: SuperAdmin is granted explicitly by permission.
     *
     * @param object $login
     *
     * @return bool
     */
    public static function isSuperAdmin($login)
    {
        return $login
            && isset($login->status)
            && (int) $login->status === 1
            && self::hasPermission($login, 'super_admin', false);
    }

    /**
     * Role helper: Admin (status=1) but not superadmin
     *
     * @param object $login
     *
     * @return bool
     */
    public static function isAdmin($login)
    {
        return $login && isset($login->status) && $login->status === 1;
    }

    /**
     * Role helper: Demo mode or Super Admin mode (social and demo_mode enabled)
     *
     * @param object $login
     *
     * @return bool
     */
    public static function isNotDemoMode($login)
    {
        return $login && !empty($login->social) && !empty(self::$cfg->demo_mode) ? false : true;
    }

    /**
     * Role helper: Check if user can modify configuration or admin features
     * SuperAdmin always allowed, others need permission and must not be in demo mode
     *
     * @param object|null $login User object from authenticateRequest()
     * @param string|array $permission Single permission or array of permissions (default: ['can_config'])
     *
     * @return bool True if allowed to modify
     */
    public static function canModify($login, $permission = ['can_config'])
    {
        return self::isSuperAdmin($login)
            || (self::hasPermission($login, $permission) && self::isNotDemoMode($login));
    }

    /**
     * Get avatar URL for a user by ID
     *
     * @param int $id User ID
     * @return string|null Avatar URL or null if not found
     */
    public static function getAvatarUrl($id, $type = 'avatar')
    {
        // Avatar image
        if (file_exists(ROOT_PATH.DATA_FOLDER.$type.'/'.$id.self::$cfg->stored_img_type)) {
            return WEB_URL.DATA_FOLDER.$type.'/'.$id.self::$cfg->stored_img_type;
        }
        return null;
    }

    /**
     * Controllers pass an exception's own message here, and for a server fault
     * that message is the database driver's: it names the schema, the table and
     * sometimes the connection, to anyone who can reach the endpoint, signed in
     * or not. Callers can do nothing with those details anyway, so a 5xx
     * answers with a fixed sentence and keeps the real one for the log, which
     * the parent still writes, alongside the request_id that ties the two
     * together.
     *
     * Client errors (4xx) keep their message: those are written for the caller.
     *
     * @param string     $message
     * @param int        $code
     * @param \Throwable $error
     *
     * @return \Kotchasan\Http\Response
     */
    protected function errorResponse($message = 'Error', $code = 400, $error = null)
    {
        if ((int) $code >= 500 && !(defined('DEBUG') && DEBUG == 2)) {
            $message = \Kotchasan\Language::get('An error occurred, please try again');
        }

        return parent::errorResponse($message, $code, $error);
    }
}
