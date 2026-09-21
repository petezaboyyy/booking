<?php
/**
 * @filesource modules/index/controllers/profile.php
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Index\Profile;

use Booking\Helper\Controller as BookingHelper;
use Gcms\Api as ApiController;
use Index\UserRepository\Model as UserRepository;
use Kotchasan\Http\Request;
use Kotchasan\Http\Response;
use Kotchasan\Validator;

/**
 * API Profile Controller
 *
 * Handles user profile endpoints
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class Controller extends ApiController
{
    /**
     * GET /api/index/profile/get
     * Get user details by ID
     *
     * @param Request $request
     *
     * @return Response
     */
    public function get(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            // Authentication check (required)
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }

            $isSuperAdmin = ApiController::isSuperAdmin($login);
            if (!$isSuperAdmin) {
                $id = $login->id;
            } else {
                $id = $request->get('id')->toInt();
            }
            $user = \Index\Profile\Model::get($id);
            if (!$user) {
                return $this->redirectResponse('/404', 'No data available', 404);
            }

            $user->provinceID = empty($user->provinceID) ? null : $user->provinceID;
            $user->isSuperAdmin = $isSuperAdmin;

            // Return user details with options
            return $this->successResponse([
                'data' => self::sanitizeUserData($user),
                'options' => [
                    'sex' => \Gcms\Controller::getGenderOptions(),
                    'province' => \Gcms\Controller::getProvinceOptions(),
                    'status' => \Gcms\Controller::getUserStatusOptions(),
                    'active' => \Gcms\Controller::getUserActiveOptions(),
                    'permission' => \Gcms\Controller::getPermissionOptions(),
                    'booking_rooms' => BookingHelper::getRoomOptions(false, null, null, true),
                    'department' => \Gcms\Category::init()->toOptions('department')
                ]
            ], 'User details retrieved');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * POST /api/index/profile/save
     * Save user details (create or update)
     *
     * @param Request $request
     *
     * @return Response
     */
    public function save(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'POST');
            $this->validateCsrfToken($request);

            // Authentication check (required)
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->redirectResponse('/login', 'Unauthorized', 401);
            }

            // Super admin (Can edit everything)
            $isSuperAdmin = ApiController::isSuperAdmin($login);

            // Parse input data
            $save = $this->parseInput($request);

            // Check if user exists first
            $user = \Index\Profile\Model::get($request->post('id')->toInt());
            if (!$user) {
                return $this->errorResponse('No data available', 404);
            }

            if ($user->id === 0) {
                // New user
                $save['social'] = 'user';
                $save['created_at'] = date('Y-m-d H:i:s');
            }

            // Admin-only fields
            if ($isSuperAdmin) {
                $permission = $request->post('permission', [])->filter('a-z0-9_');
                $save['permission'] = empty($permission) ? '' : ','.implode(',', $permission).',';
                $roomIds = BookingHelper::normalizeRoomIds($request->post('booking_rooms', [])->toInt());
                $validRoomIds = array_map('intval', array_column(BookingHelper::getRoomOptions(false), 'value'));
                $save['metas'][BookingHelper::META_BOOKING_ROOMS] = array_values(array_intersect($roomIds, $validRoomIds));
            } elseif ($login->id != $user->id) {
                // Not an admin, can only edit myself.
                return $this->errorResponse('You can only edit your own profile', 403);
            } else {
                // Not an admin cannot update these fields
                $save['username'] = $user->username;
                $save['permission'] = $user->permission;
                $save['status'] = $user->status;
                $save['active'] = $user->active;
                $save['metas'] = $user->metas;
            }

            $db = \Kotchasan\DB::create();

            // Validate
            $errors = $this->validateFields($request, $save, $user, $db);

            if ($user->id == 0 && empty($save['password'])) {
                //Register must have a password.
                $errors['password'] = 'Password is required';
            }

            if (empty($errors)) {
                if ($user->id > 0) {
                    // Update
                    $save['id'] = $user->id;
                } else {
                    // New
                    $save['id'] = $db->nextId('user');
                }

            }

            if (empty($errors)) {
                // Save user
                \Index\Profile\Model::save($db, $user->id, $save);

                // Log
                \Index\Log\Model::add($save['id'], 'index', 'Save', 'Edit user: '.$save['id'], $login->id);

                // Redirect to previous page
                return $this->redirectResponse('reload', 'Saved successfully', 200, 1000);
            }

            // Error response
            return $this->formErrorResponse($errors, 400);
        } catch (\Exception $e) {
            // Error response
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Parse user input from request
     *
     * @param Request $request
     * @return array
     */
    protected function parseInput(Request $request): array
    {
        $department = $request->post('department')->topic();

        $save = [
            'username' => $request->post('username')->username(),
            'status' => $request->post('status')->toInt(),
            'active' => $request->post('active')->toInt(),
            'name' => $request->post('name')->topic(),
            'sex' => $request->post('sex')->filter('fm'),
            'birthday' => $request->post('birthday')->date(),
            'phone' => $request->post('phone')->phone(),
            'backup_email' => $request->post('backup_email')->oneLine(255),
            'address' => $request->post('address')->textarea(),
            'address2' => $request->post('address2')->textarea(),
            'provinceID' => $request->post('provinceID')->toInt(),
            'zipcode' => $request->post('zipcode')->number(),
            'metas' => [
                'department' => $department === '' ? [] : [$department]
            ]
        ];

        // Optional fields
        if ($request->post('line_uid')->exists()) {
            $save['line_uid'] = $request->post('line_uid')->filter('Ua-z0-9');
        }
        if ($request->post('telegram_id')->exists()) {
            $save['telegram_id'] = $request->post('telegram_id')->number();
        }

        return $save;
    }

    /**
     * Validate user fields for duplicates and required fields
     *
     * @param array &$save Save data (modified by reference)
     * @param object $user Existing user
     * @param object $db Database connection
     */
    protected function validateFields($request, &$save, $user, $db)
    {
        $errors = [];
        // Check login information
        $checking = [];
        foreach (self::$cfg->login_fields as $field) {
            $k = $field == 'email' || $field === 'username' ? 'username' : $field;
            if (empty($save[$k])) {
                if (isset($user->{$k})) {
                    $save[$k] = $user->{$k};
                }
            } else {
                if ($field == 'email' && !in_array('username', self::$cfg->login_fields)) {
                    if (!Validator::email($save[$k])) {
                        $errors[$k] = 'Invalid email';
                    }
                }
                if (!isset($errors[$k])) {
                    $checking[$k] = $save[$k];
                    $search = $db->first('user', [[$k, $save[$k]]]);
                    if ($search && $search->id != $user->id) {
                        $errors[$k] = 'Already exist';
                    }
                }
            }
        }

        if (empty($checking) && $user->active === 1) {
            $k = reset(self::$cfg->login_fields);
            $errors[$k] = 'Please fill in';
        }

        // Passwords are set only when creating an account; SSO users cannot change them here.
        if ($user->id === 0 && $request->post('password')->exists()) {
            $password = $request->post('password')->password();
            $repassword = $request->post('repassword')->password();
            if ($password !== '' || $repassword !== '') {
                if (mb_strlen($password) < 8) {
                    $errors['password'] = 'Password must be at least 8 characters';
                } elseif ($password !== $repassword) {
                    $errors['repassword'] = 'Password does not match';
                } else {
                    // Hash password before saving (same method as auth.php)
                    $passwordData = \Index\Auth\Model::hashPassword($password);
                    $save['password'] = $passwordData['hash'];
                    $save['salt'] = $passwordData['salt'];
                }
            }
        }

        // Validate name (required)
        if (empty($save['name'])) {
            $errors['name'] = 'Please fill in';
        }

        // Validate phone uniqueness (outside login_fields)
        if (empty($save['phone'])) {
            $save['phone'] = null;
        } else {
            if (!UserRepository::isFieldUnique('phone', $save['phone'], $user->id)) {
                $errors['phone'] = 'Already exist';
            }
        }

        if (empty($save['backup_email'])) {
            $save['backup_email'] = null;
        } elseif (!Validator::email($save['backup_email'])) {
            $errors['backup_email'] = 'Invalid email';
        }

        return $errors;
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
}
