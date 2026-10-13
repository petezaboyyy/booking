<?php
/**
 * @filesource modules/booking/controllers/helper.php
 */

namespace Booking\Helper;

use Booking\Category\Controller as BookingCategory;
use Gcms\Api as ApiController;
use Kotchasan\Date;
use Kotchasan\Language;

class Controller extends \Gcms\Controller
{
    public const SCHEDULE_CONTINUOUS = 'continuous';
    public const SCHEDULE_DAILY_SLOT = 'daily-slot';
    public const SCHEDULE_WEEKLY_SLOT = 'weekly-slot';

    public const STATUS_PENDING_REVIEW = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;
    public const STATUS_CANCELLED_BY_REQUESTER = 3;
    public const STATUS_CANCELLED_BY_OFFICER = 4;
    public const STATUS_RETURNED_FOR_EDIT = 5;

    public const APPROVAL_BEFORE_END = 0;
    public const APPROVAL_BEFORE_START = 1;
    public const APPROVAL_ALWAYS = 2;

    public const CANCELLATION_PENDING_ONLY = 0;
    public const CANCELLATION_BEFORE_DATE = 1;
    public const CANCELLATION_BEFORE_END = 2;
    public const CANCELLATION_BEFORE_START = 3;
    public const CANCELLATION_ALWAYS = 4;

    /**
     * Longest span, in days, a single reservation may cover.
     * Bounds both the day-by-day expansion loops and the number of rows a
     * single request can create.
     */
    public const MAX_BOOKING_RANGE_DAYS = 366;

    /**
     * Hard ceiling on occurrences produced for one reservation.
     */
    public const MAX_BOOKING_OCCURRENCES = 366;

    /**
     * Booking times (start and end) are chosen in steps of this many minutes.
     *
     * Free minutes let requests overlap by odd amounts (08:00-09:52 against
     * 09:47-11:00), which leaves ragged gaps in the timetable. The booking form's
     * time wheels offer only these steps, and the server refuses anything else so
     * a request sent around the form cannot break the rule. 60 must be divisible
     * by it. The wheels read the same value from BOOKING_TIME_STEP_MINUTES in
     * ui-handoff/modules/booking/admin.js; booking_time_step_test checks they agree.
     */
    public const BOOKING_TIME_STEP_MINUTES = 5;

    /**
     * Longest cancellation note that fits reservation_occurrence.cancellation_reason.
     */
    public const MAX_CANCELLATION_REASON_LENGTH = 255;

    /**
     * Cached room galleries for the current request.
     *
     * @var array
     */
    protected static $roomGalleryCache = [];

    /**
     * Get active approval steps keyed by step number.
     * `approve_level` is the source of truth for how many steps are enabled.
     *
     * @return array<int, array{status:int, department:string}>
     */
    public static function getApprovalSteps(): array
    {
        $levelCount = max(0, (int) (self::$cfg->booking_approve_level ?? 0));
        if ($levelCount === 0) {
            return [];
        }

        $statuses = (array) (self::$cfg->booking_approve_status ?? []);
        $departments = (array) (self::$cfg->booking_approve_department ?? []);
        $steps = [];
        for ($level = 1; $level <= $levelCount; $level++) {
            if (!array_key_exists($level, $statuses)) {
                break;
            }
            $steps[$level] = [
                'status' => (int) $statuses[$level],
                'department' => isset($departments[$level]) ? (string) $departments[$level] : ''
            ];
        }

        return $steps;
    }

    /**
     * Get the number of active approval steps.
     *
     * @return int
     */
    public static function getApprovalLevelCount(): int
    {
        return count(self::getApprovalSteps());
    }

    /**
     * Get configuration for a single approval step.
     *
     * @param int $step
     *
     * @return array{status:int, department:string}|null
     */
    public static function getApprovalStepConfig(int $step): ?array
    {
        $steps = self::getApprovalSteps();

        return $steps[$step] ?? null;
    }

    /**
     * Get the next configured approval step after the current one.
     *
     * @param int $currentStep
     *
     * @return int
     */
    public static function getNextApprovalStep(int $currentStep): int
    {
        $steps = array_keys(self::getApprovalSteps());
        $index = array_search($currentStep, $steps, true);

        if ($index === false || !isset($steps[$index + 1])) {
            return 0;
        }

        return (int) $steps[$index + 1];
    }

    /**
     * Determine the approver level available to this login.
     * -1 means unrestricted approval access, 0 means no approval access.
     *
     * @param object|null $login
     *
     * @return int
     */
    public static function getApproveLevel($login): int
    {
        if (!$login) {
            return 0;
        }
        if (
            ApiController::isAdmin($login)
            || ApiController::hasPermission($login, 'can_manage_booking', false)
        ) {
            return -1;
        }

        $steps = self::getApprovalSteps();
        if (empty($steps)) {
            return 0;
        }

        $loginDepartment = isset($login->metas['department'][0]) ? trim((string) $login->metas['department'][0]) : '';

        foreach ($steps as $level => $step) {
            if ((int) $step['status'] !== (int) $login->status) {
                continue;
            }
            $department = $step['department'];
            if ($department === '' || $department === $loginDepartment) {
                return (int) $level;
            }
        }

        return 0;
    }

    /**
     * Check if user can approve the current step of a request.
     *
     * @param object|null $login
     * @param object|array $request
     *
     * @return bool
     */
    public static function canApproveStep($login, $request): bool
    {
        if (!$login) {
            return false;
        }
        if (
            ApiController::isAdmin($login)
            || ApiController::hasPermission($login, 'can_manage_booking', false)
        ) {
            return true;
        }

        $steps = self::getApprovalSteps();
        if (empty($steps)) {
            return false;
        }

        $approve = is_array($request) ? ($request['approve'] ?? 1) : ($request->approve ?? 1);
        $department = is_array($request) ? ($request['department'] ?? '') : ($request->department ?? '');
        $step = $steps[$approve] ?? null;
        $loginDepartment = isset($login->metas['department'][0]) ? trim((string) $login->metas['department'][0]) : '';
        if ($step !== null && (int) $login->status === (int) $step['status']) {
            if ($step['department'] === '') {
                return (string) $department === $loginDepartment;
            }

            return $step['department'] === $loginDepartment;
        }

        return false;
    }

    /**
     * Permission helper for approval workflow. Booking managers can approve
     * without matching a configured status or department.
     *
     * @param object|null $login
     *
     * @return bool
     */
    public static function canApproveRequests($login): bool
    {
        return self::getApproveLevel($login) !== 0;
    }

    /**
     * Check whether the login should be allowed into approval pages.
     *
     * Approval-area access follows the configured step rules, unless the login
     * has the explicit booking-management permission.
     *
     * @param object|null $login
     *
     * @return bool
     */
    public static function canAccessApprovalArea($login): bool
    {
        if (!$login) {
            return false;
        }
        if (
            ApiController::isAdmin($login)
            || ApiController::hasPermission($login, 'can_manage_booking', false)
        ) {
            return true;
        }

        $steps = self::getApprovalSteps();
        if (empty($steps)) {
            return false;
        }

        return self::canApproveRequests($login);
    }

    /**
     * Get vehicle gallery images.
     *
     * @param int $roomId
     *
     * @return array
     */
    public static function getRoomGallery(int $roomId): array
    {
        if ($roomId <= 0) {
            return [];
        }

        if (!array_key_exists($roomId, self::$roomGalleryCache)) {
            $imgTypes = !empty(self::$cfg->img_typies) && is_array(self::$cfg->img_typies) ? self::$cfg->img_typies : ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $files = \Download\Index\Controller::getAttachments($roomId, 'booking', $imgTypes);
            self::$roomGalleryCache[$roomId] = array_values(array_filter($files, static function ($file) {
                return !empty($file['is_image']);
            }));
        }

        return self::$roomGalleryCache[$roomId];
    }

    /**
     * Get the first vehicle image URL.
     *
     * @param int $roomId
     *
     * @return string|null
     */
    public static function getRoomFirstImageUrl(int $roomId): ?string
    {
        $gallery = self::getRoomGallery($roomId);

        return $gallery[0]['url'] ?? null;
    }

    /**
     * Check if the vehicle exists.
     *
     * @param int $roomId
     *
     * @return bool
     */
    public static function roomExists(int $roomId): bool
    {
        if ($roomId <= 0) {
            return false;
        }

        return \Kotchasan\Model::createQuery()
            ->select('id')
            ->from('rooms')
            ->where(['id', $roomId])
            ->first() !== null;
    }

    /**
     * Metadata key containing the rooms a booking manager may manage.
     */
    public const META_BOOKING_ROOMS = 'booking_rooms';

    /**
     * Normalize room IDs from a request or user metadata.
     *
     * @param mixed $roomIds
     *
     * @return array<int>
     */
    public static function normalizeRoomIds($roomIds): array
    {
        $roomIds = is_array($roomIds) ? $roomIds : [$roomIds];
        $normalized = [];

        foreach ($roomIds as $roomId) {
            if (is_numeric($roomId) && (int) $roomId > 0) {
                $normalized[(int) $roomId] = true;
            }
        }

        $roomIds = array_keys($normalized);
        sort($roomIds, SORT_NUMERIC);

        return $roomIds;
    }

    /**
     * Get the room scope for a booking manager.
     *
     * A null scope means all rooms. This preserves existing behavior for
     * administrators, non-manager approvers, and managers without a selection.
     *
     * @param object|null $login
     *
     * @return array<int>|null
     */
    public static function getBookingRoomScope($login): ?array
    {
        if (
            !$login ||
            ApiController::isAdmin($login) ||
            !ApiController::hasPermission($login, 'can_manage_booking', false)
        ) {
            return null;
        }

        $metas = isset($login->metas) && is_array($login->metas) ? $login->metas : [];
        $roomIds = self::normalizeRoomIds($metas[self::META_BOOKING_ROOMS] ?? []);

        return empty($roomIds) ? null : $roomIds;
    }

    /**
     * Check whether a room is inside the login's booking-management scope.
     *
     * @param object|null $login
     * @param int $roomId
     *
     * @return bool
     */
    public static function isRoomInBookingScope($login, int $roomId): bool
    {
        $scope = self::getBookingRoomScope($login);

        return $scope === null || in_array($roomId, $scope, true);
    }

    /**
     * Return room options limited to the login's booking-management scope.
     *
     * @param object|null $login
     * @param bool $activeOnly
     *
     * @return array
     */
    public static function getBookingRoomOptions($login, bool $activeOnly = false): array
    {
        return self::getRoomOptions($activeOnly, null, self::getBookingRoomScope($login));
    }

    /**
     * Room options.
     *
     * @param bool $activeOnly
     * @param int|null $includeId
     * @param array<int>|null $roomIds
     * @param bool $showRoomNumber
     *
     * @return array
     */
    public static function getRoomOptions(bool $activeOnly = true, ?int $includeId = null, ?array $roomIds = null, bool $showRoomNumber = false): array
    {
        $query = \Kotchasan\Model::createQuery()
            ->select(
                'R.id', 'R.name', 'R.is_active', 'R.detail', 'R.color',
                'RoomNumber.value room_number', 'Building.value building',
                'Seats.value seats'
            )
            ->from('rooms R')
            ->join('rooms_meta RoomNumber', [['RoomNumber.room_id', 'R.id'], ['RoomNumber.name', 'number']], 'LEFT')
            ->join('rooms_meta Building', [['Building.room_id', 'R.id'], ['Building.name', 'building']], 'LEFT')
            ->join('rooms_meta Seats', [['Seats.room_id', 'R.id'], ['Seats.name', 'seats']], 'LEFT');

        $query->orderBy($showRoomNumber ? 'RoomNumber.value' : 'R.name');

        if ($roomIds !== null) {
            $query->where(['R.id', self::normalizeRoomIds($roomIds)]);
        }

        if ($activeOnly) {
            if ($includeId !== null && $includeId > 0) {
                $query->where([
                    ['R.is_active', 1],
                    ['R.id', $includeId]
                ], 'OR');
            } else {
                $query->where(['R.is_active', 1]);
            }
        }

        $items = $query->fetchAll();

        // Each option below asks for its room's first photo. Fetch every room's
        // photo list from Supabase together first, rather than one after another.
        \Download\Storage\Model::prefetch(array_map(static function ($item) {
            return 'booking/'.(int) $item->id.'/';
        }, $items));

        $options = [];
        foreach ($items as $item) {
            $roomNumber = (string) ($item->room_number ?? '');
            $label = $showRoomNumber && $roomNumber !== '' ? $roomNumber : (string) $item->name;
            if ((int) $item->is_active !== 1) {
                $label .= ' ({LNG_Inactive})';
            }
            $options[] = [
                'value' => (string) $item->id,
                'text' => $label,
                'image' => self::getRoomFirstImageUrl((int) $item->id) ?: '',
                'room_number' => $roomNumber,
                'building' => (string) ($item->building ?? ''),
                'seats' => (string) ($item->seats ?? ''),
                'detail' => (string) ($item->detail ?? '')
            ];
        }

        return $options;
    }

    /**
     * Driver options from members that have can_drive_car permission.
     *
     * @return array
     */
    public static function getUseOptions(): array
    {
        // Categories are admin-managed and rarely change; skip the 1-hour
        // query cache so a rename shows up on the booking form right away
        // instead of the next time it happens to expire.
        $options = BookingCategory::init(true, true, false)->toOptions('use');

        return self::localizeCategoryOptionText($options);
    }

    /**
     * Swaps each option's display text for its English name when the current
     * site language is English and one is set (Booking\Categories\Model::englishNames()).
     * The option `value` is left untouched - only the label shown to the user changes.
     *
     * @param array $options each item shaped like ['value' => category_id, 'text' => ..., ...]
     *
     * @return array
     */
    private static function localizeCategoryOptionText(array $options): array
    {
        if (Language::name() !== 'en') {
            return $options;
        }
        $englishNames = \Booking\Categories\Model::englishNames();
        if (empty($englishNames)) {
            return $options;
        }
        foreach ($options as &$opt) {
            $en = $englishNames[(string) ($opt['value'] ?? '')] ?? '';
            if ($en !== '') {
                $opt['text'] = $en;
                $opt['label'] = $en;
            }
        }
        unset($opt);

        return $options;
    }

    /**
     * Topic options based on the 'use' category.
     * Returns a sequential array of ['value' => $text, 'text' => $text, 'label' => $text,
     * 'category_id' => $id], always the category's current name as set on the
     * Categories admin page. The course-code requirement is included per category
     * so the booking form never depends on a fixed category ID.
     *
     * @param string|null $currentTopic
     * @return array
     */
    public static function getTopicOptions(?string $currentTopic = null): array
    {
        $categories = BookingCategory::init(true, true, false)->toOptions('use', true);
        $courseCodeRequirements = \Booking\Categories\Model::courseCodeRequirements();
        // The booking record always stores the Thai name (`value` below), so a
        // category keeps the same stored topic no matter what language the
        // booker had selected - only the label shown in the dropdown changes.
        $englishNames = Language::name() === 'en' ? \Booking\Categories\Model::englishNames() : [];
        $options = [];

        foreach ($categories as $cat) {
            $thText = $cat['text'];
            $categoryId = (string) $cat['value'];
            $displayText = $englishNames[$categoryId] ?? $thText;
            $options[] = [
                'value' => $thText,
                'text' => $displayText,
                'label' => $displayText,
                'category_id' => $categoryId,
                'requires_course_code' => !empty($courseCodeRequirements[$categoryId])
            ];
        }

        if (!empty($currentTopic)) {
            $found = false;
            foreach ($options as $opt) {
                if ($opt['value'] === $currentTopic || $opt['text'] === $currentTopic) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $options[] = ['value' => $currentTopic, 'text' => $currentTopic, 'label' => $currentTopic, 'category_id' => '', 'requires_course_code' => false];
            }
        }
        return $options;
    }

    /**
     * Status options.
     *
     * @return array
     */
    public static function getStatusOptions(): array
    {
        return \Gcms\Controller::arrayToOptions(Language::get('BOOKING_STATUS', []));
    }

    /**
     * Status options a booker may be granted permanent-delete rights for.
     * Approved bookings are left out: only officers may remove those.
     *
     * @return array
     */
    public static function getBookerDeleteStatusOptions(): array
    {
        $protected = self::getBookerProtectedStatuses();
        $options = [];
        foreach (Language::get('BOOKING_STATUS', []) as $key => $value) {
            if (!in_array((int) $key, $protected, true)) {
                $options[] = ['value' => (string) $key, 'text' => $value];
            }
        }

        return $options;
    }

    /**
     * Return normalized status key.
     *
     * @param object|null $row
     *
     * @return int
     */
    public static function getStatusValue(?object $row): int
    {
        if ($row === null) {
            return self::STATUS_PENDING_REVIEW;
        }

        $status = (int) self::readField($row, 'status');

        return self::normalizeStatusId($status);
    }

    /**
     * Human readable status text.
     *
     * @param object|null $row
     *
     * @return string
     */
    public static function getStatusText(?object $row): string
    {
        return self::getStatusLabel($row->status);
    }

    /**
     * Human readable status label.
     *
     * @param int $status
     *
     * @return string
     */
    public static function getStatusLabel(int $status): string
    {
        return Language::get('BOOKING_STATUS', '-', $status);
    }

    /**
     * Normalize a status value to a supported booking status.
     *
     * @param int $status
     * @param int|null $default
     *
     * @return int
     */
    public static function normalizeStatusId(int $status, ?int $default = null): int
    {
        $validStatuses = [
            self::STATUS_PENDING_REVIEW,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED_BY_REQUESTER,
            self::STATUS_CANCELLED_BY_OFFICER,
            self::STATUS_RETURNED_FOR_EDIT
        ];
        if (in_array($status, $validStatuses, true)) {
            return $status;
        }

        return $default ?? self::STATUS_PENDING_REVIEW;
    }

    /**
     * Booking statuses shown on the public calendar.
     * An empty result means "no filter configured" — show every status.
     *
     * @return array
     */
    public static function getCalendarStatuses(): array
    {
        $statuses = self::$cfg->booking_calendar_status ?? [];
        if (!is_array($statuses)) {
            $statuses = [$statuses];
        }

        return array_values(array_unique(array_filter(array_map(static function ($status) {
            return Controller::normalizeStatusId((int) $status, -1);
        }, $statuses), static function ($status) {
            return $status >= 0;
        })));
    }

    /**
     * Booking statuses a requester may never delete, whatever the settings say.
     * An approved request is the room's record of a granted slot, so only an
     * officer may remove it.
     *
     * @return array
     */
    public static function getBookerProtectedStatuses(): array
    {
        return [self::STATUS_APPROVED];
    }

    /**
     * Configured booking statuses that bookers may permanently delete.
     *
     * @return array
     */
    public static function getBookerDeleteStatuses(): array
    {
        $statuses = self::$cfg->booking_delete ?? [self::STATUS_CANCELLED_BY_REQUESTER];
        if (!is_array($statuses)) {
            $statuses = [$statuses];
        }

        $protected = self::getBookerProtectedStatuses();
        $statuses = array_values(array_unique(array_filter(array_map(static function ($status) {
            return Controller::normalizeStatusId((int) $status, -1);
        }, $statuses), static function ($status) use ($protected) {
            return $status >= 0 && !in_array($status, $protected, true);
        })));

        return empty($statuses) ? [self::STATUS_CANCELLED_BY_REQUESTER] : $statuses;
    }

    /**
     * Can the requester edit this booking?
     *
     * @param object|null $row
     *
     * @return bool
     */
    public static function canEditBooking(?object $row): bool
    {
        if ($row === null) {
            return false;
        }

        return in_array(self::getStatusValue($row), [self::STATUS_PENDING_REVIEW, self::STATUS_RETURNED_FOR_EDIT], true);
    }

    /**
     * Can staff still process this booking?
     *
     * @param object|null $row
     *
     * @return bool
     */
    public static function canProcessBooking(?object $row): bool
    {
        if ($row === null) {
            return false;
        }

        return self::getStatusValue($row) === self::STATUS_PENDING_REVIEW
        && self::isWithinApprovalWindow($row);
    }

    /**
     * Can the requester cancel this booking?
     *
     * @param object|null $row
     *
     * @return bool
     */
    public static function canCancelBookingByRequester(?object $row): bool
    {
        if ($row === null) {
            return false;
        }

        // Once approved, only an officer may cancel the whole request or one
        // concrete date. The requester must not alter the approved schedule.
        if (!in_array($row->status, [
            self::STATUS_PENDING_REVIEW,
            self::STATUS_RETURNED_FOR_EDIT
        ], true)) {
            return false;
        }

        switch ((int) (self::$cfg->booking_cancellation ?? self::CANCELLATION_PENDING_ONLY)) {
        case self::CANCELLATION_BEFORE_DATE:
            // ก่อนวันจอง
            return self::isBeforeBoundaryDate($row, 'begin');
        case self::CANCELLATION_BEFORE_END:
            // ก่อนสิ้นสุดเวลาจอง
            return self::isBeforeBoundary($row, 'end');
        case self::CANCELLATION_BEFORE_START:
            // ก่อนถึงเวลาจอง
            return self::isBeforeBoundary($row, 'begin');
        case self::CANCELLATION_ALWAYS:
            // ยกเลิกย้อนหลังได้
            return true;
        }

        // สถานะรอตรวจสอบ หรือ กลับไปแก้ไข
        return in_array($row->status, [self::STATUS_PENDING_REVIEW, self::STATUS_RETURNED_FOR_EDIT], true);
    }

    /**
     * Can the requester delete this booking permanently?
     *
     * @param object|null $row
     *
     * @return bool
     */
    public static function canDeleteBookingByRequester(?object $row): bool
    {
        if ($row === null) {
            return false;
        }

        $status = self::getStatusValue($row);
        if (in_array($status, self::getBookerProtectedStatuses(), true)) {
            return false;
        }

        return in_array($status, self::getBookerDeleteStatuses(), true);
    }

    /**
     * Can an officer cancel this booking?
     *
     * @param object|null $row
     *
     * @return bool
     */
    public static function canCancelBookingByOfficer(?object $row): bool
    {
        if ($row === null) {
            return false;
        }

        $status = self::getStatusValue($row);
        if (!in_array($status, [
            self::STATUS_PENDING_REVIEW,
            self::STATUS_APPROVED,
            self::STATUS_RETURNED_FOR_EDIT
        ], true)) {
            return false;
        }

        return self::isWithinApprovalWindow($row);
    }

    /**
     * Check approval/edit timing policy.
     *
     * @param object|null $row
     *
     * @return bool
     */
    public static function isWithinApprovalWindow(?object $row): bool
    {
        if ($row === null) {
            return false;
        }

        switch ((int) (self::$cfg->booking_approving ?? self::APPROVAL_BEFORE_END)) {
        case self::APPROVAL_BEFORE_START:
            return self::isBeforeBoundary($row, 'begin');
        case self::APPROVAL_ALWAYS:
            return true;
        default:
            return self::isBeforeBoundary($row, 'end');
        }
    }

    /**
     * The "use" categories as read for this request.
     *
     * @var \Booking\Category\Controller|null
     */
    private static $useCategories = null;

    /**
     * Use category label.
     *
     * Read without the 1-hour query cache, like getUseOptions(): a category an
     * admin just renamed must show its new name straight away. The list is
     * loaded once per request, so labelling many rows is still one query.
     *
     * @param string|null $useId
     *
     * @return string
     */
    public static function getUseLabel(?string $useId): string
    {
        if ($useId === null || trim($useId) === '') {
            return '';
        }
        if (self::$useCategories === null) {
            self::$useCategories = BookingCategory::init(true, true, false);
        }

        return (string) self::$useCategories->get('use', $useId);
    }

    /**
     * Current "use" category name of each reservation, looked up by the
     * category id the reservation stores (reservation_data 'use').
     *
     * The reservation's own `topic` column keeps the name it was booked under,
     * so it goes stale when a category is renamed. Reservations without a
     * stored id (or whose category no longer exists) are left out; callers fall
     * back to `topic` for those.
     *
     * @param array<int|string> $reservationIds
     *
     * @return array<int, string> reservation id => category name
     */
    public static function getUseLabelsByReservation(array $reservationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $reservationIds))));
        if (empty($ids)) {
            return [];
        }

        $rows = \Kotchasan\Model::createQuery()
            ->select('reservation_id', 'value')
            ->from('reservation_data')
            ->where([
                ['reservation_id', $ids],
                ['name', 'use']
            ])
            ->fetchAll();

        $labels = [];
        foreach ($rows as $row) {
            $label = self::getUseLabel((string) $row->value);
            if ($label !== '') {
                $labels[(int) $row->reservation_id] = $label;
            }
        }

        return $labels;
    }

    /**
     * Department name helper.
     *
     * @param string|null $departmentId
     *
     * @return string
     */
    public static function getDepartmentName(?string $departmentId): string
    {
        if ($departmentId === null || $departmentId === '') {
            return '';
        }

        return (string) \Gcms\Category::init()->get('department', $departmentId);
    }

    /**
     * Check that the current time is before a booking boundary.
     *
     * @param object|null $row
     * @param string $field
     *
     * @return bool
     */
    public static function isBeforeBoundary(?object $row, string $field): bool
    {
        if ($row === null) {
            return false;
        }

        $value = self::readField($row, $field);
        if (empty($value)) {
            return true;
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return true;
        }

        return time() < $timestamp;
    }

    /**
     * Check that the current date is before a booking boundary date.
     *
     * @param object|null $row
     * @param string $field
     *
     * @return bool
     */
    public static function isBeforeBoundaryDate(?object $row, string $field): bool
    {
        if ($row === null) {
            return false;
        }

        $value = self::readField($row, $field);
        if (empty($value)) {
            return true;
        }

        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return true;
        }

        return date('Y-m-d') < date('Y-m-d', $timestamp);
    }

    /**
     * Normalize booking schedule type.
     *
     * @param string|null $scheduleType
     * @param string|null $default
     *
     * @return string
     */
    public static function normalizeScheduleType(?string $scheduleType, ?string $default = null): string
    {
        $scheduleType = trim((string) $scheduleType);
        if (in_array($scheduleType, [self::SCHEDULE_CONTINUOUS, self::SCHEDULE_DAILY_SLOT, self::SCHEDULE_WEEKLY_SLOT], true)) {
            return $scheduleType;
        }

        return $default ?? self::SCHEDULE_DAILY_SLOT;
    }

    /**
     * Resolve booking schedule type from row-like data.
     *
     * @param object|array|string|null $row
     * @param string|null $default
     *
     * @return string
     */
    public static function getScheduleType($row, ?string $default = null): string
    {
        if (is_array($row)) {
            $scheduleType = $row['schedule_type'] ?? null;
        } elseif (is_object($row)) {
            $scheduleType = $row->schedule_type ?? null;
        } else {
            $scheduleType = $row;
        }

        return self::normalizeScheduleType(
            is_string($scheduleType) ? $scheduleType : null,
            $default ?? self::SCHEDULE_CONTINUOUS
        );
    }


    /**
     * แปลงวันในสัปดาห์ให้เป็นเลข 0–6 ที่ไม่ซ้ำกัน
     *
     * @param array|string|null $days
     *
     * @return array
     */
    public static function normalizeRecurrenceDays($days): array
    {
        if (is_string($days)) {
            $days = explode(',', $days);
        }

        if (!is_array($days)) {
            return [];
        }

        $normalized = [];

        foreach ($days as $day) {
            if ((string) $day !== '' && is_numeric($day)) {
                $day = (int) $day;

                if ($day >= 0 && $day <= 6) {
                    $normalized[$day] = $day;
                }
            }
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * ตัวเลือกวันในสัปดาห์สำหรับแบบฟอร์ม
     *
     * @return array
     */
    public static function getWeekdayOptions(): array
    {
        $dayNames = Language::get('DATE_LONG', []);
        $options = [];

        for ($day = 0; $day <= 6; $day++) {
            $options[] = [
                'value' => (string) $day,
                'text' => $dayNames[$day] ?? (string) $day
            ];
        }

        return $options;
    }


    /**
     * Build normalized booking schedule data from stored begin/end values.
     *
     * @param string|null $begin
     * @param string|null $end
     * @param string|null $scheduleType
     *
     * @return array|null
     */
    public static function buildBookingSchedule(?string $begin, ?string $end, ?string $scheduleType = null, $recurrenceDays = []): ?array
    {
        if ($begin === null || $end === null || trim($begin) === '' || trim($end) === '') {
            return null;
        }

        $beginTs = strtotime($begin);
        $endTs = strtotime($end);
        if ($beginTs === false || $endTs === false || $endTs <= $beginTs) {
            return null;
        }

        return [
            'schedule_type' => self::normalizeScheduleType($scheduleType, self::SCHEDULE_CONTINUOUS),
            'begin_ts' => $beginTs,
            'end_ts' => $endTs,
            'begin_date' => date('Y-m-d', $beginTs),
            'end_date' => date('Y-m-d', $endTs),
            'begin_time' => date('H:i:s', $beginTs),
            'end_time' => date('H:i:s', $endTs),
            'recurrence_days' => self::normalizeRecurrenceDays($recurrenceDays)
        ];
    }

    /**
     * Get the effective time interval for a schedule on a specific date.
     *
     * @param array $schedule
     * @param string $date
     *
     * @return array|null
     */
    public static function getBookingIntervalForDate(array $schedule, string $date): ?array
    {
        if ($date < $schedule['begin_date'] || $date > $schedule['end_date']) {
            return null;
        }
        if ($schedule['schedule_type'] === self::SCHEDULE_WEEKLY_SLOT) {
            $timestamp = strtotime($date);

            if ($timestamp === false) {
                return null;
            }

            $weekday = (int) date('w', $timestamp);

            if (!in_array($weekday, $schedule['recurrence_days'], true)) {
                return null;
            }

            return [
                'start' => self::timeToSeconds($schedule['begin_time']),
                'end' => self::timeToSeconds($schedule['end_time'])
            ];
        }
        if ($schedule['schedule_type'] === self::SCHEDULE_DAILY_SLOT) {
            return [
                'start' => self::timeToSeconds($schedule['begin_time']),
                'end' => self::timeToSeconds($schedule['end_time'])
            ];
        }

        if ($schedule['begin_date'] === $schedule['end_date']) {
            return [
                'start' => self::timeToSeconds($schedule['begin_time']),
                'end' => self::timeToSeconds($schedule['end_time'])
            ];
        }

        if ($date === $schedule['begin_date']) {
            return [
                'start' => self::timeToSeconds($schedule['begin_time']),
                'end' => 86400
            ];
        }
        if ($date === $schedule['end_date']) {
            return [
                'start' => 0,
                'end' => self::timeToSeconds($schedule['end_time'])
            ];
        }

        return [
            'start' => 0,
            'end' => 86400
        ];
    }

    /**
     * Determine whether two booking schedules overlap.
     *
     * @param array $left
     * @param array $right
     *
     * @return bool
     */
    public static function bookingSchedulesOverlap(array $left, array $right): bool
    {
        $cursor = strtotime(max($left['begin_date'], $right['begin_date']));
        $last = strtotime(min($left['end_date'], $right['end_date']));
        if ($cursor === false || $last === false || $cursor > $last) {
            return false;
        }
        $daysLeft = self::MAX_BOOKING_RANGE_DAYS;
        while ($cursor <= $last && $daysLeft-- > 0) {
            $date = date('Y-m-d', $cursor);
            $leftInterval = self::getBookingIntervalForDate($left, $date);
            $rightInterval = self::getBookingIntervalForDate($right, $date);

            if (
                $leftInterval !== null &&
                $rightInterval !== null &&
                $leftInterval['start'] < $rightInterval['end'] &&
                $leftInterval['end'] > $rightInterval['start']
            ) {
                return true;
            }

            $next = strtotime('+1 day', $cursor);
            if ($next === false) {
                break;
            }
            $cursor = $next;
        }

        return false;
    }

    /**
     * Check that a schedule has at least one occurrence inside its date range.
     */
    public static function bookingScheduleHasOccurrence(array $schedule): bool
    {
        $cursor = strtotime($schedule['begin_date']);
        $last = strtotime($schedule['end_date']);
        if ($cursor === false || $last === false) {
            return false;
        }

        $daysLeft = self::MAX_BOOKING_RANGE_DAYS;
        while ($cursor <= $last && $daysLeft-- > 0) {
            if (self::getBookingIntervalForDate($schedule, date('Y-m-d', $cursor)) !== null) {
                return true;
            }
            $cursor = strtotime('+1 day', $cursor);
            if ($cursor === false) {
                break;
            }
        }

        return false;
    }

    /**
     * Expand a recurring schedule into concrete, same-day occurrences.
     * Excluded dates are compared using YYYY-MM-DD.
     *
     * @param array $schedule
     * @param array|string|null $excludedDates
     *
     * @return array<int, array{begin:string,end:string,date:string}>
     */
    public static function buildBookingOccurrences(array $schedule, $excludedDates = []): array
    {
        if (is_string($excludedDates)) {
            $excludedDates = explode(',', $excludedDates);
        }
        $excluded = [];
        foreach (is_array($excludedDates) ? $excludedDates : [] as $date) {
            $date = trim((string) $date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && date('Y-m-d', strtotime($date)) === $date) {
                $excluded[$date] = true;
            }
        }

        $cursor = strtotime($schedule['begin_date']);
        $last = strtotime($schedule['end_date']);
        if ($cursor === false || $last === false || $cursor > $last) {
            return [];
        }

        // Defence in depth: validation rejects over-long ranges before we get
        // here, but never let a stored or crafted range spin this loop.
        $daysLeft = self::MAX_BOOKING_RANGE_DAYS;
        $occurrences = [];
        while ($cursor <= $last && $daysLeft-- > 0) {
            $date = date('Y-m-d', $cursor);
            $interval = self::getBookingIntervalForDate($schedule, $date);
            if ($interval !== null && !isset($excluded[$date])) {
                $occurrences[] = [
                    'date' => $date,
                    'begin' => $date.' '.$schedule['begin_time'],
                    'end' => $date.' '.$schedule['end_time']
                ];
                if (count($occurrences) >= self::MAX_BOOKING_OCCURRENCES) {
                    break;
                }
            }
            $cursor = strtotime('+1 day', $cursor);
            if ($cursor === false) {
                break;
            }
        }

        return $occurrences;
    }

    /**
     * Does this time fall on a booking time step (BOOKING_TIME_STEP_MINUTES)?
     *
     * Accepts H:i or H:i:s; seconds must be zero.
     *
     * @param string|null $time e.g. "08:30", "8:30", "08:30:00"
     *
     * @return bool
     */
    public static function isBookingTimeOnStep(?string $time): bool
    {
        if (!preg_match('/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/', trim((string) $time), $match)) {
            return false;
        }
        $hours = (int) $match[1];
        $minutes = (int) $match[2];
        $seconds = isset($match[3]) ? (int) $match[3] : 0;

        return $hours < 24 && $minutes < 60 && $seconds === 0
            && $minutes % self::BOOKING_TIME_STEP_MINUTES === 0;
    }

    /**
     * Number of calendar days a begin/end date pair spans, inclusive.
     *
     * @param string $beginDate YYYY-MM-DD
     * @param string $endDate YYYY-MM-DD
     *
     * @return int 0 when either date cannot be parsed
     */
    public static function countBookingRangeDays(string $beginDate, string $endDate): int
    {
        $begin = strtotime($beginDate.' 00:00:00');
        $end = strtotime($endDate.' 00:00:00');
        if ($begin === false || $end === false || $end < $begin) {
            return 0;
        }

        // round() keeps the count right across any DST shift in the range.
        return (int) round(($end - $begin) / 86400) + 1;
    }

    /**
     * Read object field.
     *
     * @param object|null $row
     * @param string $field
     *
     * @return mixed|null
     */
    protected static function readField(?object $row, string $field)
    {
        if ($row === null) {
            return null;
        }

        return $row->$field ?? null;
    }

    /**
     * คืนค่าเวลาจอง
     *
     * @param object $item
     * @param bool $omitDateOnSameDay
     *
     * @return string
     */
    public static function formatBookingTime(object $item, bool $omitDateOnSameDay = false): string
    {
        $schedule = self::buildBookingSchedule(
            $item->begin ?? null,
            $item->end ?? null,
            self::getScheduleType($item, self::SCHEDULE_CONTINUOUS),
            $item->recurrence_days ?? []
        );
        if ($schedule === null) {
            return '';
        }
        if ($schedule['schedule_type'] === self::SCHEDULE_WEEKLY_SLOT) {
            $dayNames = Language::get('DATE_LONG', []);

            $selectedDays = array_map(
                static function ($day) use ($dayNames) {
                    return $dayNames[$day] ?? (string) $day;
                },
                $schedule['recurrence_days']
            );

            $timeText = Date::format($item->begin, 'H:i')
                .' {LNG_To} '
                .Date::format($item->end, 'TIME_FORMAT');

            $dateText = Date::format($item->begin, 'd M Y')
                .' {LNG_To} '
                .Date::format($item->end, 'd M Y');

            return Language::trans(
                implode(', ', $selectedDays)
                .' {LNG_Time} '
                .$timeText
                .' ('
                .$dateText
                .')'
            );
        }
        if (
            $schedule['schedule_type'] === self::SCHEDULE_DAILY_SLOT &&
            $schedule['begin_date'] !== $schedule['end_date']
        ) {
            $timeText = Date::format($item->begin, 'H:i').' {LNG_To} '.Date::format($item->end, 'TIME_FORMAT');
            $dateText = Date::format($item->begin, 'd M Y').' {LNG_To} '.Date::format($item->end, 'd M Y');

            return Language::trans('{LNG_Every day} {LNG_Time} '.$timeText.' ('.$dateText.')');
        }

        if ($schedule['begin_date'] === $schedule['end_date']) {
            if ($omitDateOnSameDay) {
                $return = Date::format($item->begin, 'H:i').' {LNG_To} '.Date::format($item->end, 'TIME_FORMAT');
            } else {
                $return = Date::format($item->begin, 'DATE_FORMAT').' {LNG_To} '.Date::format($item->end, 'TIME_FORMAT');
            }
        } else {
            $return = Date::format($item->begin).' {LNG_To} '.Date::format($item->end);
        }

        return Language::trans($return);
    }

    /**
     * Convert HH:mm:ss to seconds from start of day.
     *
     * @param string $value
     *
     * @return int
     */
    protected static function timeToSeconds(string $value): int
    {
        [$hour, $minute, $second] = array_pad(array_map('intval', explode(':', $value)), 3, 0);

        return ($hour * 3600) + ($minute * 60) + $second;
    }
}
