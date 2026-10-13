<?php
/**
 * @filesource modules/booking/models/competition.php
 */

namespace Booking\Competition;

use Booking\Booking\Model as BookingModel;
use Booking\Helper\Controller as Helper;
use Kotchasan\Language;

/**
 * Pending requests that want the same room at overlapping times.
 *
 * Nothing stops two requests for one slot from waiting side by side: the first
 * one approved gets the room and the others fail their final approval. This
 * finds such a group and ranks it with rules that can be explained to the people
 * who lose out. The ranking is only advice; approving stays a human decision.
 *
 * The rules look at the request itself and at one fact about the requester: the
 * role (member status) they hold. Nothing else about the person is used.
 */
class Model extends \Kotchasan\Model
{
    /** Points for the earliest submission, falling to 0 for the latest. */
    const POINTS_FIRST_COME = 30;
    /** Points for a request that fills at least half of the room's seats. */
    const POINTS_GOOD_FIT = 25;
    /** Points for filling a quarter to a half of the seats. */
    const POINTS_FAIR_FIT = 10;
    /**
     * Taken off when more people are expected than the room holds. Larger than
     * POINTS_FIRST_COME on purpose: a request the room cannot seat should not
     * win just because it was submitted first.
     */
    const POINTS_OVER_CAPACITY = -35;
    /** Points for a request that carries a course code (a teaching booking). */
    const POINTS_COURSE_CODE = 20;
    /** Points for a "use for" category the site has marked as a priority. */
    const POINTS_PRIORITY_USE = 20;
    /**
     * Points when the request was made by someone in a priority role (by default
     * staff, member status 2). The heaviest single rule: more than any two of
     * the other rules together (at most 30 + 25 = 55), so a staff request comes
     * first unless another request is ahead on all of first-come, room fit and
     * course code (75), or the room cannot seat the staff request's attendees.
     */
    const POINTS_PRIORITY_ROLE = 60;
    /** Member status of staff in the default configuration (member_status in Gcms\Config). */
    const DEFAULT_PRIORITY_STATUSES = [2];

    /**
     * The pending requests that overlap this one in the same room, with this
     * request first.
     *
     * @param int $reservationId
     *
     * @return array{seats:int,items:array} items is empty when nothing competes
     */
    public static function competitors(int $reservationId): array
    {
        $none = ['seats' => 0, 'items' => []];
        $reservation = BookingModel::getById($reservationId);
        if (!$reservation || (int) $reservation->status !== Helper::STATUS_PENDING_REVIEW) {
            return $none;
        }

        $mine = BookingModel::getOccurrences($reservationId, Helper::STATUS_PENDING_REVIEW);
        if (empty($mine)) {
            return $none;
        }
        $earliest = min(array_column($mine, 'begin'));
        $latest = max(array_column($mine, 'end'));

        $others = static::createQuery()
            ->select('O.reservation_id', 'O.begin', 'O.end')
            ->from('reservation_occurrence O')
            ->join('reservation R', ['R.id', 'O.reservation_id'])
            ->where([
                ['O.room_id', (int) $reservation->room_id],
                ['O.status', Helper::STATUS_PENDING_REVIEW],
                ['R.status', Helper::STATUS_PENDING_REVIEW],
                ['O.reservation_id', '!=', $reservationId],
                ['O.begin', '<', $latest],
                ['O.end', '>', $earliest]
            ])
            ->fetchAll();

        // A request competes only on the dates that really overlap, not just
        // because its overall date range does.
        $overlaps = [];
        foreach ($others as $other) {
            foreach ($mine as $occurrence) {
                if ($other->begin < $occurrence->end && $other->end > $occurrence->begin) {
                    $overlaps[(int) $other->reservation_id][$other->begin] = [
                        'begin' => (string) $other->begin,
                        'end' => (string) $other->end
                    ];
                    break;
                }
            }
        }
        if (empty($overlaps)) {
            return $none;
        }

        $ids = array_merge([$reservationId], array_keys($overlaps));
        $rows = static::createQuery()
            ->select(
                'R.id',
                'R.created_at',
                'R.topic',
                'R.course_code',
                'R.comment',
                'R.attendees',
                'R.begin',
                'R.end',
                'R.schedule_type',
                'U.name member_name',
                'U.status member_status',
                'UseMeta.value use_id',
                'RecurrenceDays.value recurrence_days'
            )
            ->from('reservation R')
            ->join('user U', ['U.id', 'R.member_id'], 'LEFT')
            ->join('reservation_data UseMeta', [['UseMeta.reservation_id', 'R.id'], ['UseMeta.name', 'use']], 'LEFT')
            ->join('reservation_data RecurrenceDays', [['RecurrenceDays.reservation_id', 'R.id'], ['RecurrenceDays.name', 'recurrence_days']], 'LEFT')
            ->where(['R.id', $ids])
            ->fetchAll();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
        }
        $labels = Helper::getUseLabelsByReservation($ids);

        $items = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $row = $byId[$id];
            $items[] = [
                'id' => (int) $id,
                'is_current' => (int) $id === $reservationId,
                'member_name' => (string) $row->member_name,
                'member_status' => (int) ($row->member_status ?? 0),
                'created_at' => (string) $row->created_at,
                'topic' => $labels[(int) $id] ?? (string) $row->topic,
                'use_id' => (string) ($row->use_id ?? ''),
                'course_code' => trim((string) ($row->course_code ?? '')),
                'comment' => trim((string) ($row->comment ?? '')),
                'attendees' => (int) $row->attendees,
                'begin' => (string) $row->begin,
                'end' => (string) $row->end,
                'reservation_text' => Helper::formatBookingTime($row),
                // Dates of this request that collide with the one being reviewed.
                'overlap_dates' => (int) $id === $reservationId ? count($mine) : count($overlaps[$id] ?? [])
            ];
        }

        return ['seats' => self::roomSeats((int) $reservation->room_id), 'items' => $items];
    }

    /**
     * How many other pending requests each of these requests competes with, for
     * a whole page of the approvals table at once (two queries, not one per row).
     * A request with no competitor, or one that is not pending, is left out.
     *
     * @param array<int|string> $reservationIds
     *
     * @return array<int, int> reservation id => number of competing requests
     */
    public static function countCompetitors(array $reservationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $reservationIds))));
        if (empty($ids)) {
            return [];
        }

        $pending = static function (array $where) {
            return static::createQuery()
                ->select('O.reservation_id', 'O.room_id', 'O.begin', 'O.end')
                ->from('reservation_occurrence O')
                ->join('reservation R', ['R.id', 'O.reservation_id'])
                ->where(array_merge([
                    ['O.status', Helper::STATUS_PENDING_REVIEW],
                    ['R.status', Helper::STATUS_PENDING_REVIEW]
                ], $where))
                ->fetchAll();
        };

        $mine = $pending([['O.reservation_id', $ids]]);
        if (empty($mine)) {
            return [];
        }
        $everyone = $pending([
            ['O.room_id', array_values(array_unique(array_column($mine, 'room_id')))],
            ['O.begin', '<', max(array_column($mine, 'end'))],
            ['O.end', '>', min(array_column($mine, 'begin'))]
        ]);

        $byRoom = [];
        foreach ($everyone as $row) {
            $byRoom[(int) $row->room_id][] = $row;
        }
        $rivals = [];
        foreach ($mine as $occurrence) {
            foreach ($byRoom[(int) $occurrence->room_id] ?? [] as $other) {
                if ((int) $other->reservation_id !== (int) $occurrence->reservation_id
                    && $other->begin < $occurrence->end && $other->end > $occurrence->begin) {
                    $rivals[(int) $occurrence->reservation_id][(int) $other->reservation_id] = true;
                }
            }
        }

        return array_map('count', $rivals);
    }

    /**
     * For each pending request, on how many of its dates it collides with a
     * booking that is already approved. Such a request can no longer be approved
     * (the room is taken), yet it looks like any other pending one, so the
     * approvals table flags it. One batch for a whole page.
     *
     * @param array<int|string> $reservationIds
     *
     * @return array<int, int> reservation id => number of colliding dates; requests with none are left out
     */
    public static function conflictingWithApproved(array $reservationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $reservationIds))));
        if (empty($ids)) {
            return [];
        }

        $slots = static function (int $status, array $where) {
            return static::createQuery()
                ->select('O.id', 'O.reservation_id', 'O.room_id', 'O.begin', 'O.end')
                ->from('reservation_occurrence O')
                ->join('reservation R', ['R.id', 'O.reservation_id'])
                ->where(array_merge([['O.status', $status], ['R.status', $status]], $where))
                ->fetchAll();
        };

        $mine = $slots(Helper::STATUS_PENDING_REVIEW, [['O.reservation_id', $ids]]);
        if (empty($mine)) {
            return [];
        }
        $approved = $slots(Helper::STATUS_APPROVED, [
            ['O.room_id', array_values(array_unique(array_column($mine, 'room_id')))],
            ['O.begin', '<', max(array_column($mine, 'end'))],
            ['O.end', '>', min(array_column($mine, 'begin'))]
        ]);

        $byRoom = [];
        foreach ($approved as $row) {
            $byRoom[(int) $row->room_id][] = $row;
        }
        $dates = [];
        foreach ($mine as $slot) {
            foreach ($byRoom[(int) $slot->room_id] ?? [] as $taken) {
                if ($taken->begin < $slot->end && $taken->end > $slot->begin) {
                    $dates[(int) $slot->reservation_id][(int) $slot->id] = true;
                    break;
                }
            }
        }

        return array_map('count', $dates);
    }

    /**
     * The pending requests that clash with an approved booking: the ones left
     * over once a request won the room. They stay "pending" and can never be
     * approved, so the approver is offered a way to reject them together.
     *
     * @param int $approvedId
     *
     * @return array<int, array> one entry per clashing request: id, member_name, created_at, reservation_text, overlap_dates
     */
    public static function pendingClashingWith(int $approvedId): array
    {
        $approved = BookingModel::getById($approvedId);
        if (!$approved || (int) $approved->status !== Helper::STATUS_APPROVED) {
            return [];
        }
        $taken = BookingModel::getOccurrences($approvedId, Helper::STATUS_APPROVED);
        if (empty($taken)) {
            return [];
        }

        $pending = static::createQuery()
            ->select('O.reservation_id', 'O.begin', 'O.end')
            ->from('reservation_occurrence O')
            ->join('reservation R', ['R.id', 'O.reservation_id'])
            ->where([
                ['O.room_id', (int) $approved->room_id],
                ['O.status', Helper::STATUS_PENDING_REVIEW],
                ['R.status', Helper::STATUS_PENDING_REVIEW],
                ['O.begin', '<', max(array_column($taken, 'end'))],
                ['O.end', '>', min(array_column($taken, 'begin'))]
            ])
            ->fetchAll();

        $dates = [];
        foreach ($pending as $slot) {
            foreach ($taken as $occurrence) {
                if ($slot->begin < $occurrence->end && $slot->end > $occurrence->begin) {
                    $dates[(int) $slot->reservation_id][(string) $slot->begin] = true;
                    break;
                }
            }
        }
        if (empty($dates)) {
            return [];
        }

        $rows = static::createQuery()
            ->select('R.id', 'R.created_at', 'R.begin', 'R.end', 'R.schedule_type', 'U.name member_name', 'RecurrenceDays.value recurrence_days')
            ->from('reservation R')
            ->join('user U', ['U.id', 'R.member_id'], 'LEFT')
            ->join('reservation_data RecurrenceDays', [['RecurrenceDays.reservation_id', 'R.id'], ['RecurrenceDays.name', 'recurrence_days']], 'LEFT')
            ->where(['R.id', array_keys($dates)])
            ->orderBy('R.created_at')
            ->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row->id,
                'member_name' => (string) $row->member_name,
                'created_at' => (string) $row->created_at,
                'reservation_text' => Helper::formatBookingTime($row),
                'overlap_dates' => count($dates[(int) $row->id])
            ];
        }

        return $items;
    }

    /**
     * Rank competing requests, best first, with the reasons behind each score.
     *
     * @param array $items as returned by competitors()
     * @param int   $seats seats in the room, 0 when unknown
     *
     * @return array the same items plus rank, score and reasons
     */
    public static function rank(array $items, int $seats): array
    {
        if (empty($items)) {
            return [];
        }

        // Submission order, earliest first; id breaks a tie in created_at.
        $byTime = $items;
        usort($byTime, static function ($a, $b) {
            return [$a['created_at'], $a['id']] <=> [$b['created_at'], $b['id']];
        });
        $position = [];
        foreach ($byTime as $index => $item) {
            $position[$item['id']] = $index;
        }
        $count = count($items);

        $priorityUses = array_map('strval', (array) (self::$cfg->booking_priority_uses ?? []));
        // Roles whose requests come first: member statuses, 2 (staff) unless the site says otherwise.
        $priorityStatuses = array_map('intval', (array) (self::$cfg->booking_priority_statuses ?? self::DEFAULT_PRIORITY_STATUSES));

        foreach ($items as &$item) {
            $score = 0;
            $reasons = [];

            $place = $position[$item['id']];
            if ($count > 1) {
                $score += (int) round(self::POINTS_FIRST_COME * (1 - $place / ($count - 1)));
            }
            $reasons[] = $place === 0
                ? Language::get('Submitted first')
                : Language::get('Submission order').' '.($place + 1).'/'.$count;

            if ($seats > 0 && $item['attendees'] > 0) {
                $ratio = $item['attendees'] / $seats;
                $text = Language::get('Seats used').': '.$item['attendees'].'/'.$seats;
                if ($ratio > 1) {
                    $score += self::POINTS_OVER_CAPACITY;
                    $reasons[] = Language::get('Exceeds room capacity').' ('.$item['attendees'].'/'.$seats.')';
                } elseif ($ratio >= 0.5) {
                    $score += self::POINTS_GOOD_FIT;
                    $reasons[] = $text;
                } elseif ($ratio >= 0.25) {
                    $score += self::POINTS_FAIR_FIT;
                    $reasons[] = $text;
                } else {
                    $reasons[] = $text;
                }
            }

            $status = (int) ($item['member_status'] ?? 0);
            if (in_array($status, $priorityStatuses, true)) {
                $score += self::POINTS_PRIORITY_ROLE;
                // The role's own name, so a site that renamed "staff" sees its own word.
                $reasons[] = Language::get('Requested by').' '.((string) (self::$cfg->member_status[$status] ?? $status));
            }

            if ($item['course_code'] !== '') {
                $score += self::POINTS_COURSE_CODE;
                $reasons[] = Language::get('Has a course code');
            }
            if ($item['use_id'] !== '' && in_array($item['use_id'], $priorityUses, true)) {
                $score += self::POINTS_PRIORITY_USE;
                $reasons[] = Language::get('Priority use category');
            }

            $item['score'] = $score;
            $item['reasons'] = $reasons;
            $item['submitted_order'] = $place + 1;
        }
        unset($item);

        usort($items, static function ($a, $b) use ($position) {
            return [$b['score'], $position[$a['id']]] <=> [$a['score'], $position[$b['id']]];
        });
        foreach ($items as $index => &$item) {
            $item['rank'] = $index + 1;
        }
        unset($item);

        return $items;
    }

    /**
     * Seats in a room (0 when the room has no seat count).
     *
     * @param int $roomId
     *
     * @return int
     */
    public static function roomSeats(int $roomId): int
    {
        $row = static::createQuery()
            ->select('value')
            ->from('rooms_meta')
            ->where([['room_id', $roomId], ['name', 'seats']])
            ->first();

        return $row && preg_match('/^\s*(\d+)/', (string) $row->value, $m) ? (int) $m[1] : 0;
    }
}
