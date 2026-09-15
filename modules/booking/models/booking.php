<?php
/**
 * @filesource modules/booking/models/booking.php
 */

namespace Booking\Booking;

use Booking\Helper\Controller as Helper;
use Kotchasan\Database\Sql;
use Kotchasan\Date;

class Model extends \Kotchasan\Model
{
    /**
     * Get booking form data.
     *
     * @param object $login
     * @param int $id
     *
     * @return object|null
     */
    public static function get($login, int $id = 0, int $prefillRoomId = 0)
    {
        if ($id <= 0) {
            $closedLevel = Helper::getApprovalLevelCount();
            $record = (object) [
                'id' => 0,
                'member_id' => (int) $login->id,
                'member_name' => (string) $login->name,
                'room_id' => $prefillRoomId,
                'room_name' => '',
                'topic' => '',
                'reason' => '',
                'comment' => '',
                'attendees' => 1,
                'begin' => '',
                'end' => '',
                'begin_date' => '',
                'begin_time' => '',
                'end_date' => '',
                'end_time' => '',
                'schedule_type' => Helper::SCHEDULE_DAILY_SLOT,
                'recurrence_days' => [],
                'excluded_dates' => [],
                'version' => 0,
                'use' => '',
                'accessories' => [],
                'canEdit' => true,
                'approve' => 1,
                'closed' => $closedLevel > 0 ? $closedLevel : 1
            ];

            if ($closedLevel === 0) {
                // Immediate approval
                $record->status = Helper::STATUS_APPROVED;
            } else {
                // Approval level 1
                $record->status = Helper::STATUS_PENDING_REVIEW;
            }

            return $record;
        }
        $record = static::createQuery()
            ->select('R.*', 'U.name member_name', 'Room.name room_name')
            ->from('reservation R')
            ->join('user U', ['U.id', 'R.member_id'], 'LEFT')
            ->join('rooms Room', ['Room.id', 'R.room_id'], 'LEFT')
            ->where([
                ['R.id', $id],
                ['R.member_id', $login->id]
            ])
            ->first();

        if (!$record) {
            return null;
        }

        $meta = self::getMetaValues($id);
        $record->schedule_type = Helper::getScheduleType($record, Helper::SCHEDULE_CONTINUOUS);

        $record->begin_date = !empty($record->begin) ? date('Y-m-d', strtotime((string) $record->begin)) : '';
        $record->end_date = !empty($record->end) ? date('Y-m-d', strtotime((string) $record->end)) : '';
        $record->begin_time = !empty($record->begin) ? date('H:i', strtotime((string) $record->begin)) : '';
        $record->end_time = !empty($record->end) ? date('H:i', strtotime((string) $record->end)) : '';
        $record->use = (string) ($meta['use'] ?? '');
        $record->accessories = self::csvToArray($meta['accessories'] ?? '');
        $record->recurrence_days = Helper::normalizeRecurrenceDays(
            $meta['recurrence_days'] ?? ''
        );
        $record->excluded_dates = self::normalizeExcludedDates($meta['excluded_dates'] ?? '');
        $record->canEdit = Helper::canEditBooking($record);
        $record->status_text = Helper::getStatusText($record);

        return $record;
    }

    /**
     * Get an owned reservation record.
     *
     * @param int $memberId
     * @param int $id
     *
     * @return object|null
     */
    public static function getRecord(int $memberId, int $id)
    {
        return static::createQuery()
            ->select()
            ->from('reservation')
            ->where([
                ['id', $id],
                ['member_id', $memberId]
            ])
            ->first();
    }

    /**
     * Get any reservation record.
     *
     * @param int $id
     *
     * @return object|null
     */
    public static function getById(int $id)
    {
        return static::createQuery()
            ->select()
            ->from('reservation')
            ->where(['id', $id])
            ->first();
    }

    /**
     * Get a booking row with detail joins used by views and notifications.
     *
     * @param int $id
     *
     * @return object|null
     */
    public static function getDetailRow(int $id)
    {
        if ($id <= 0) {
            return null;
        }

        return static::createQuery()
            ->select(
                'R.id',
                'R.room_id',
                'R.member_id',
                'R.reason',
                'R.topic',
                'R.comment',
                'R.attendees',
                'R.begin',
                'R.end',
                'R.schedule_type',
                'R.status',
                'R.approve',
                'R.closed',
                'R.created_at',
                'U.name member_name',
                'U.username member_email',
                'U.line_uid member_line_uid',
                'U.telegram_id member_telegram_id',
                'Room.name room_name',
                'Room.color room_color',
                'RoomNumber.value room_number',
                'Building.value room_building',
                'Seats.value room_seats',
                'UseMeta.value use_id',
                'Accessories.value accessories',
                'RecurrenceDays.value recurrence_days'
            )
            ->from('reservation R')
            ->join('rooms Room', ['Room.id', 'R.room_id'], 'LEFT')
            ->join('user U', ['U.id', 'R.member_id'], 'LEFT')
            ->join('rooms_meta RoomNumber', [['RoomNumber.room_id', 'Room.id'], ['RoomNumber.name', 'number']], 'LEFT')
            ->join('rooms_meta Building', [['Building.room_id', 'Room.id'], ['Building.name', 'building']], 'LEFT')
            ->join('rooms_meta Seats', [['Seats.room_id', 'Room.id'], ['Seats.name', 'seats']], 'LEFT')
            ->join('reservation_data UseMeta', [['UseMeta.reservation_id', 'R.id'], ['UseMeta.name', 'use']], 'LEFT')
            ->join('reservation_data Accessories', [['Accessories.reservation_id', 'R.id'], ['Accessories.name', 'accessories']], 'LEFT')
            ->join('reservation_data RecurrenceDays', [['RecurrenceDays.reservation_id', 'R.id'], ['RecurrenceDays.name', 'recurrence_days']], 'LEFT')
            ->where(['R.id', $id])
            ->first();
    }

    /**
     * Get normalized booking detail data.
     *
     * @param int $id
     *
     * @return array|null
     */
    public static function getDetailData(int $id): ?array
    {
        $row = self::getDetailRow($id);
        if (!$row) {
            return null;
        }
        $recurrenceDays = Helper::normalizeRecurrenceDays(
            $row->recurrence_days ?? ''
        );
        $activeDates = [];
        $cancelledDates = [];
        foreach (self::getOccurrences($id) as $occurrence) {
            $text = Date::format($occurrence->begin, 'd M Y')
                .' '.Date::format($occurrence->begin, 'H:i')
                .'-'.Date::format($occurrence->end, 'H:i');
            if (in_array((int) $occurrence->status, [
                Helper::STATUS_CANCELLED_BY_REQUESTER,
                Helper::STATUS_CANCELLED_BY_OFFICER
            ], true)) {
                $cancelledDates[] = $text;
            } else {
                $activeDates[] = $text;
            }
        }
        return [
            'id' => (int) $row->id,
            'member_id' => (int) $row->member_id,
            'member_name' => (string) $row->member_name,
            'member_email' => (string) $row->member_email,
            'member_line_uid' => (string) $row->member_line_uid,
            'member_telegram_id' => (string) $row->member_telegram_id,
            'room_id' => (int) $row->room_id,
            'room_name' => (string) $row->room_name,
            'room_number' => (string) ($row->room_number ?? ''),
            'room_building' => (string) ($row->room_building ?? ''),
            'room_seats' => (string) ($row->room_seats ?? ''),
            'reason' => (string) $row->reason,
            'topic' => (string) $row->topic,
            'comment' => (string) $row->comment,
            'attendees' => (int) $row->attendees,
            'begin' => (string) $row->begin,
            'end' => (string) $row->end,
            'schedule_type' => Helper::getScheduleType($row, Helper::SCHEDULE_CONTINUOUS),
            'recurrence_days' => $recurrenceDays,
            'begin_text' => Date::format($row->begin, 'd M Y H:i'),
            'end_text' => Date::format($row->end, 'd M Y H:i'),
            'reservation_text' => Helper::formatBookingTime((object) [
                'begin' => (string) $row->begin,
                'end' => (string) $row->end,
                'schedule_type' => Helper::getScheduleType($row, Helper::SCHEDULE_CONTINUOUS),
                'recurrence_days' => $recurrenceDays
            ]),
            'use_id' => (string) ($row->use_id ?? ''),
            'use_text' => Helper::getUseLabel($row->use_id ?? ''),
            'accessories_text' => Helper::formatAccessoryNames($row->accessories ?? ''),
            'status' => Helper::getStatusValue($row),
            'status_text' => Helper::getStatusText($row),
            'approve' => (int) ($row->approve ?? 1),
            'closed' => (int) ($row->closed ?? 1),
            'created_at' => (string) ($row->created_at ?? ''),
            'room_image_url' => Helper::getRoomFirstImageUrl((int) $row->room_id),
            'active_dates_text' => implode(', ', $activeDates),
            'cancelled_dates_text' => implode(', ', $cancelledDates),
            'occurrence_count' => count($activeDates) + count($cancelledDates),
            'active_occurrence_count' => count($activeDates)
        ];
    }

    /**
     * Check if a user may view a booking detail record.
     *
     * @param object $login
     * @param array|null $detail
     *
     * @return bool
     */
    public static function canView($login, ?array $detail): bool
    {
        if (!$detail) {
            return false;
        }

        $userId = (int) ($login->id ?? 0);
        if ($userId <= 0) {
            return false;
        }

        if (Helper::canApproveRequests($login)) {
            return true;
        }

        return $userId === (int) ($detail['member_id'] ?? 0);
    }

    /**
     * Save reservation.
     *
     * @param int $id
     * @param array $save
     * @param array $meta
     *
     * @return array{id:int,conflicts:array}
     */
    public static function saveReservation(int $id, array $save, array $meta): array
    {
        $db = static::createDB();
        $schedule = Helper::buildBookingSchedule(
            $save['begin'] ?? null,
            $save['end'] ?? null,
            $save['schedule_type'] ?? null,
            $meta['recurrence_days'] ?? []
        );
        $occurrences = $schedule === null
            ? []
            : Helper::buildBookingOccurrences($schedule, $meta['excluded_dates'] ?? []);

        if (empty($occurrences)) {
            throw new \InvalidArgumentException('At least one booking date must be selected', 400);
        }

        $db->beginTransaction();
        try {
            self::lockRoom($db, (int) $save['room_id']);

            $expectedVersion = (int) ($save['version'] ?? 0);
            if ($id > 0) {
                $locked = self::lockReservation($db, $id);
                if (!$locked) {
                    throw new \RuntimeException('No data available', 404);
                }
                if ((int) $locked->version !== $expectedVersion) {
                    throw new \RuntimeException('This booking was changed by another user. Please reload and try again', 409);
                }
            }

            $conflicts = self::findApprovedConflicts((int) $save['room_id'], $occurrences, $id, $db);
            if (!empty($conflicts)) {
                $db->rollback();
                return ['id' => $id, 'conflicts' => $conflicts];
            }

            $save['version'] = $id > 0 ? $expectedVersion + 1 : 1;
            if ($id > 0) {
                $db->update('reservation', ['id', $id], $save);
            } else {
                $id = (int) $db->insert('reservation', $save);
            }

            self::saveMetaWithDb($db, $id, $meta);
            $db->delete('reservation_occurrence', ['reservation_id', $id], 0);
            $now = date('Y-m-d H:i:s');
            foreach ($occurrences as $occurrence) {
                $db->insert('reservation_occurrence', [
                    'reservation_id' => $id,
                    'room_id' => (int) $save['room_id'],
                    'begin' => $occurrence['begin'],
                    'end' => $occurrence['end'],
                    'status' => (int) $save['status'],
                    'created_at' => $now,
                    'updated_at' => $now
                ]);
            }
            $db->commit();

            return ['id' => $id, 'conflicts' => []];
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * Update reservation status and optional meta fields.
     *
     * @param int $id
     * @param int $status
     * @param array $fields
     * @param array $metaUpdates
     * @param int|null $expectedVersion
     *
     * @return void
     */
    public static function updateStatus(int $id, int $status, array $fields = [], array $metaUpdates = [], ?int $expectedVersion = null): void
    {
        if ($id <= 0) {
            return;
        }

        $save = array_merge($fields, [
            'status' => Helper::normalizeStatusId($status)
        ]);

        $db = static::createDB();
        $db->beginTransaction();
        try {
            $row = self::lockReservation($db, $id);
            if (!$row) {
                $db->rollback();
                return;
            }
            if ($expectedVersion !== null && (int) ($row->version ?? 0) !== $expectedVersion) {
                throw new \RuntimeException('This booking was changed by another user. Please reload and try again', 409);
            }
            $save['version'] = (int) ($row->version ?? 0) + 1;
            $db->update('reservation', ['id', $id], $save);
            $db->update('reservation_occurrence', ['reservation_id', $id], [
                'status' => Helper::normalizeStatusId($status),
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            if (!empty($metaUpdates)) {
                $meta = self::getMetaValues($id);
                foreach ($metaUpdates as $name => $value) {
                    $value = is_array($value) ? implode(',', $value) : trim((string) $value);
                    if ($value === '') {
                        unset($meta[$name]);
                    } else {
                        $meta[$name] = $value;
                    }
                }
                self::saveMetaWithDb($db, $id, $meta);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * Cancel a booking on behalf of its requester.
     *
     * The eligibility check is repeated against the row locked inside the
     * transaction, so a cancellation racing an officer's approval fails with
     * 409 instead of quietly cancelling a booking that was just approved.
     *
     * @param int $id
     * @param int $memberId
     * @param int|null $expectedVersion
     *
     * @return void
     */
    public static function cancelByRequester(int $id, int $memberId, ?int $expectedVersion = null): void
    {
        $db = static::createDB();
        $db->beginTransaction();
        try {
            $row = self::lockReservation($db, $id);
            if (!$row || (int) $row->member_id !== $memberId) {
                throw new \RuntimeException('No data available', 404);
            }
            $row->status = (int) $row->status;
            if ($expectedVersion !== null && (int) ($row->version ?? 0) !== $expectedVersion) {
                throw new \RuntimeException('This booking was changed by another user. Please reload and try again', 409);
            }
            if (!Helper::canCancelBookingByRequester($row)) {
                throw new \RuntimeException('This booking can no longer be cancelled', 409);
            }

            $now = date('Y-m-d H:i:s');
            $db->update('reservation', ['id', $id], [
                'status' => Helper::STATUS_CANCELLED_BY_REQUESTER,
                'version' => (int) ($row->version ?? 0) + 1
            ]);
            $db->update('reservation_occurrence', ['reservation_id', $id], [
                'status' => Helper::STATUS_CANCELLED_BY_REQUESTER,
                'updated_at' => $now
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * Delete reservations owned by a member.
     *
     * @param int $memberId
     * @param array $ids
     *
     * @return int
     */
    public static function removeOwned(int $memberId, array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($memberId <= 0 || empty($ids)) {
            return 0;
        }

        $rows = static::createQuery()
            ->select('id', 'status')
            ->from('reservation')
            ->where(['member_id', $memberId])
            ->where(['id', $ids])
            ->fetchAll();

        $allowedIds = [];
        foreach ($rows as $row) {
            if (Helper::canDeleteBookingByRequester($row)) {
                $allowedIds[] = (int) $row->id;
            }
        }

        if (empty($allowedIds)) {
            return 0;
        }

        $db = \Kotchasan\DB::create();
        $db->delete('reservation_occurrence', ['reservation_id', $allowedIds], 0);
        $db->delete('reservation_data', ['reservation_id', $allowedIds], 0);

        return (int) $db->delete('reservation', [
            ['id', $allowedIds],
            ['member_id', $memberId]
        ], 0);
    }

    /**
     * Save reservation meta.
     *
     * @param int $id
     * @param array $meta
     *
     * @return void
     */
    public static function saveMeta(int $id, array $meta): void
    {
        $db = \Kotchasan\DB::create();
        self::saveMetaWithDb($db, $id, $meta);
    }

    /**
     * Save metadata using an existing connection/transaction.
     */
    protected static function saveMetaWithDb(\Kotchasan\DB $db, int $id, array $meta): void
    {
        $db->delete('reservation_data', ['reservation_id', $id], 0);

        foreach ($meta as $name => $value) {
            if (is_array($value)) {
                $value = implode(',', array_values(array_filter(array_map('strval', $value), static function ($item) {
                    return $item !== '';
                })));
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $db->insert('reservation_data', [
                'reservation_id' => $id,
                'name' => $name,
                'value' => $value
            ]);
        }
    }

    /**
     * Get reservation meta as array.
     *
     * @param int $id
     *
     * @return array
     */
    public static function getMetaValues(int $id): array
    {
        $rows = static::createQuery()
            ->select('name', 'value')
            ->from('reservation_data')
            ->where(['reservation_id', $id])
            ->fetchAll();

        $meta = [];
        foreach ($rows as $row) {
            $meta[$row->name] = $row->value;
        }

        return $meta;
    }

    /**
     * Check for datetime overlap on the same room.
     *
     * @param array $save
     *
     * @return bool
     */
    public static function availability($save): bool
    {
        $current = Helper::buildBookingSchedule(
            $save['begin'] ?? null,
            $save['end'] ?? null,
            $save['schedule_type'] ?? null,
            $save['recurrence_days'] ?? []
        );
        if ($current === null) {
            return false;
        }

        $occurrences = Helper::buildBookingOccurrences($current, $save['excluded_dates'] ?? []);
        return !empty($occurrences) && empty(self::findApprovedConflicts(
            (int) $save['room_id'],
            $occurrences,
            (int) ($save['id'] ?? 0)
        ));
    }

    /**
     * Return concrete dates that overlap approved occurrences.
     */
    public static function findApprovedConflicts(int $roomId, array $occurrences, int $excludeReservationId = 0, ?\Kotchasan\DB $db = null): array
    {
        if ($roomId <= 0 || empty($occurrences)) {
            return [];
        }
        $begins = array_column($occurrences, 'begin');
        $ends = array_column($occurrences, 'end');
        $where = [
            ['room_id', $roomId],
            ['status', Helper::STATUS_APPROVED],
            ['begin', '<', max($ends)],
            ['end', '>', min($begins)]
        ];
        if ($excludeReservationId > 0) {
            $where[] = ['reservation_id', '!=', $excludeReservationId];
        }
        $database = $db ? $db->getDatabase() : static::createDatabase();
        $approved = $database->createQuery()
            ->select('id', 'reservation_id', 'begin', 'end')
            ->from('reservation_occurrence')
            ->where($where)
            ->orderBy('begin')
            ->fetchAll();

        $conflicts = [];
        foreach ($occurrences as $candidate) {
            foreach ($approved as $existing) {
                if ($candidate['begin'] < $existing->end && $candidate['end'] > $existing->begin) {
                    $key = $candidate['begin'].'|'.$candidate['end'];
                    $conflicts[$key] = [
                        'date' => substr($candidate['begin'], 0, 10),
                        'begin' => $candidate['begin'],
                        'end' => $candidate['end'],
                        'reservation_id' => (int) $existing->reservation_id
                    ];
                    break;
                }
            }
        }

        return array_values($conflicts);
    }

    /**
     * Final approval. Room and request rows are locked so two approvals cannot
     * claim the same room/time concurrently.
     */
    public static function approveReservation(int $id, int $expectedVersion, array $fields = []): array
    {
        $db = static::createDB();
        $initial = self::getById($id);
        if (!$initial) {
            throw new \RuntimeException('No data available', 404);
        }

        $db->beginTransaction();
        try {
            self::lockRoom($db, (int) $initial->room_id);
            $row = self::lockReservation($db, $id);
            if (!$row || (int) $row->status !== Helper::STATUS_PENDING_REVIEW) {
                throw new \RuntimeException('This booking has already been processed', 409);
            }
            if ((int) $row->version !== $expectedVersion) {
                throw new \RuntimeException('This booking was changed by another user. Please reload and try again', 409);
            }

            $occurrences = self::getOccurrences($id, null, $db);
            if (empty($occurrences)) {
                throw new \RuntimeException('At least one booking date must be selected', 409);
            }
            $conflicts = self::findApprovedConflicts((int) $row->room_id, array_map(static function ($item) {
                return ['begin' => (string) $item->begin, 'end' => (string) $item->end];
            }, $occurrences), $id, $db);
            if (!empty($conflicts)) {
                $db->rollback();
                return $conflicts;
            }

            $save = array_merge($fields, [
                'status' => Helper::STATUS_APPROVED,
                'version' => $expectedVersion + 1
            ]);
            $db->update('reservation', ['id', $id], $save);
            $db->update('reservation_occurrence', ['reservation_id', $id], [
                'status' => Helper::STATUS_APPROVED,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            $db->commit();

            return [];
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * Fetch occurrences for a parent reservation.
     */
    public static function getOccurrences(int $reservationId, ?int $status = null, ?\Kotchasan\DB $db = null): array
    {
        $where = [['reservation_id', $reservationId]];
        if ($status !== null) {
            $where[] = ['status', $status];
        }
        $database = $db ? $db->getDatabase() : static::createDatabase();
        return $database->createQuery()
            ->select()
            ->from('reservation_occurrence')
            ->where($where)
            ->orderBy('begin')
            ->fetchAll();
    }

    /**
     * Return active/total occurrence counts for compact parent-row displays.
     */
    public static function getOccurrenceSummary(int $reservationId): array
    {
        $occurrences = self::getOccurrences($reservationId);
        $activeStatuses = [
            Helper::STATUS_PENDING_REVIEW,
            Helper::STATUS_APPROVED,
            Helper::STATUS_RETURNED_FOR_EDIT
        ];
        $active = 0;
        foreach ($occurrences as $occurrence) {
            if (in_array((int) $occurrence->status, $activeStatuses, true)) {
                $active++;
            }
        }
        return ['active' => $active, 'total' => count($occurrences)];
    }

    /**
     * Active/total occurrence counts for many reservations in one query.
     * Table listings call this once per page instead of once per row.
     *
     * @param array $reservationIds
     *
     * @return array<int, array{active:int,total:int}> keyed by reservation id
     */
    public static function getOccurrenceSummaries(array $reservationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $reservationIds))));
        $summaries = [];
        foreach ($ids as $id) {
            $summaries[$id] = ['active' => 0, 'total' => 0];
        }
        if (empty($ids)) {
            return $summaries;
        }

        $activeStatuses = [
            Helper::STATUS_PENDING_REVIEW,
            Helper::STATUS_APPROVED,
            Helper::STATUS_RETURNED_FOR_EDIT
        ];
        $rows = static::createQuery()
            ->select('reservation_id', 'status', Sql::COUNT('id', 'total'))
            ->from('reservation_occurrence')
            ->where(['reservation_id', $ids])
            ->groupBy(['reservation_id', 'status'])
            ->fetchAll();

        foreach ($rows as $row) {
            $id = (int) $row->reservation_id;
            if (!isset($summaries[$id])) {
                $summaries[$id] = ['active' => 0, 'total' => 0];
            }
            $count = (int) $row->total;
            $summaries[$id]['total'] += $count;
            if (in_array((int) $row->status, $activeStatuses, true)) {
                $summaries[$id]['active'] += $count;
            }
        }

        return $summaries;
    }

    /**
     * Cancel one approved occurrence while preserving the parent request.
     */
    public static function cancelOccurrence(int $reservationId, int $occurrenceId, int $memberId, string $reason, int $expectedVersion): bool
    {
        $initial = self::getById($reservationId);
        if (!$initial) {
            return false;
        }
        $db = static::createDB();
        $db->beginTransaction();
        try {
            self::lockRoom($db, (int) $initial->room_id);
            $row = self::lockReservation($db, $reservationId);
            if (!$row || (int) $row->status !== Helper::STATUS_APPROVED || (int) $row->version !== $expectedVersion) {
                throw new \RuntimeException('This booking was changed by another user. Please reload and try again', 409);
            }
            $occurrenceTable = $db->getTableName('reservation_occurrence');
            $occurrence = $db->raw(
                "SELECT * FROM `$occurrenceTable` WHERE `id`=:id AND `reservation_id`=:reservation_id FOR UPDATE",
                [':id' => $occurrenceId, ':reservation_id' => $reservationId]
            )->fetch();
            if (!$occurrence || (int) $occurrence->status !== Helper::STATUS_APPROVED) {
                throw new \RuntimeException('This booking date has already been cancelled', 409);
            }

            $now = date('Y-m-d H:i:s');
            $db->update('reservation_occurrence', ['id', $occurrenceId], [
                'status' => Helper::STATUS_CANCELLED_BY_OFFICER,
                'cancelled_by' => $memberId,
                'cancelled_at' => $now,
                'cancellation_reason' => $reason,
                'updated_at' => $now
            ]);
            $active = $db->getDatabase()->createQuery()
                ->select('id')
                ->from('reservation_occurrence')
                ->where([
                    ['reservation_id', $reservationId],
                    ['status', Helper::STATUS_APPROVED]
                ])
                ->first();
            $parentSave = ['version' => $expectedVersion + 1];
            if (!$active) {
                $parentSave['status'] = Helper::STATUS_CANCELLED_BY_OFFICER;
                $parentSave['reason'] = $reason;
            }
            $db->update('reservation', ['id', $reservationId], $parentSave);
            $db->commit();
            return true;
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    protected static function lockRoom(\Kotchasan\DB $db, int $roomId): void
    {
        $table = $db->getTableName('rooms');
        $row = $db->raw("SELECT `id` FROM `$table` WHERE `id`=:id AND `is_active`=1 FOR UPDATE", [':id' => $roomId])->fetch();
        if (!$row) {
            throw new \InvalidArgumentException('Please select an active room', 400);
        }
    }

    protected static function lockReservation(\Kotchasan\DB $db, int $id)
    {
        $table = $db->getTableName('reservation');
        return $db->raw("SELECT * FROM `$table` WHERE `id`=:id FOR UPDATE", [':id' => $id])->fetch();
    }

    public static function normalizeExcludedDates($dates): array
    {
        if (is_string($dates)) {
            $dates = explode(',', $dates);
        }
        $result = [];
        foreach (is_array($dates) ? $dates : [] as $date) {
            $date = trim((string) $date);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && date('Y-m-d', strtotime($date)) === $date) {
                $result[$date] = $date;
            }
        }
        ksort($result);
        return array_values($result);
    }

    /**
     * Convert CSV values to array of strings.
     *
     * @param string|null $csv
     *
     * @return array
     */
    public static function csvToArray(?string $csv): array
    {
        if ($csv === null || trim($csv) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $csv)), static function ($value) {
            return $value !== '';
        }));
    }
}
