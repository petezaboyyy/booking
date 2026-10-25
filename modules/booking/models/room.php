<?php
/**
 * @filesource modules/booking/models/room.php
 */

namespace Booking\Room;

class Model extends \Kotchasan\Model
{
    /**
     * Get a room for editing.
     *
     * @param int $id
     *
     * @return object|null
     */
    public static function get(int $id)
    {
        $record = (object) [
            'id' => 0,
            'name' => '',
            'color' => '#304FFE',
            'detail' => '',
            'is_active' => 1,
            'building' => '',
            'number' => '',
            'seats' => '',
            'booking' => []
        ];

        if ($id > 0) {
            $room = static::createQuery()
                ->select('id', 'name', 'color', 'detail', 'is_active')
                ->from('rooms')
                ->where(['id', $id])
                ->first();

            if (!$room) {
                return null;
            }

            $meta = self::getMetaValues($id);
            $record = (object) array_merge((array) $record, (array) $room, array_intersect_key($meta, (array) $record));
            if (isset($record->seats)) {
                $rawSeats = (string) $record->seats;
                $record->seats = preg_match('/^\s*(\d+)/', $rawSeats, $m) ? $m[1] : trim(preg_replace('/(\s*(ที่นั่ง|คน|seats?))+/iu', '', $rawSeats));
            }
            $record->booking = \Download\Index\Controller::getAttachments($id, 'booking', self::$cfg->img_typies);
        }

        return $record;
    }

    /**
     * Save room data.
     *
     * @param int $id
     * @param array $save
     * @param array $meta
     *
     * @return int
     */
    public static function save(int $id, array $save, array $meta): int
    {
        $db = \Kotchasan\DB::create();

        // Building + room number, kept as one value on the room so the database
        // itself refuses a second room with the same pair (UNIQUE room_key).
        $save['room_key'] = self::roomKey((string) ($meta['building'] ?? ''), (string) ($meta['number'] ?? ''));

        if ($id === 0) {
            $id = (int) $db->insert('rooms', $save);
        } else {
            $db->update('rooms', ['id', $id], $save);
        }

        self::saveMeta($id, $meta);

        return $id;
    }

    /**
     * The unique key of a room: its building and room number, normalized.
     * Null when the room has no room number.
     *
     * @param string $building
     * @param string $number
     *
     * @return string|null
     */
    public static function roomKey(string $building, string $number): ?string
    {
        require_once ROOT_PATH.'scripts/migrations/room_key.php';

        return booking_room_key($building, $number);
    }

    /**
     * Another room that already uses this building and room number.
     *
     * @param int    $id       the room being saved (0 for a new one), never its own duplicate
     * @param string $building
     * @param string $number
     *
     * @return object|null the existing room (id, name), or null when the pair is free
     */
    public static function findDuplicate(int $id, string $building, string $number)
    {
        $key = self::roomKey($building, $number);
        if ($key === null) {
            return null;
        }

        return static::createQuery()
            ->select('id', 'name')
            ->from('rooms')
            ->where([
                ['room_key', $key],
                ['id', '!=', $id]
            ])
            ->first();
    }

    /**
     * Is this exception the database refusing a duplicate room_key (two people
     * saving the same building + number at the same moment)?
     *
     * @param \Throwable $e
     *
     * @return bool
     */
    public static function isDuplicateKeyError(\Throwable $e): bool
    {
        return (string) $e->getCode() === '23000'
            || stripos($e->getMessage(), 'Duplicate entry') !== false
            || stripos($e->getMessage(), 'uq_rooms_room_key') !== false;
    }

    /**
     * Delete room records and uploaded images.
     *
     * @param array $ids
     *
     * @return int
     */
    public static function remove(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return 0;
        }

        $db = \Kotchasan\DB::create();
        $db->delete('rooms_meta', ['room_id', $ids], 0);
        $removed = $db->delete('rooms', ['id', $ids], 0);

        foreach ($ids as $id) {
            \Kotchasan\File::removeDirectory(ROOT_PATH.DATA_FOLDER.'booking/'.$id.'/');
        }

        return (int) $removed;
    }

    /**
     * Toggle active state.
     *
     * @param int $id
     *
     * @return object|null
     */
    public static function toggleActive(int $id)
    {
        $db = \Kotchasan\DB::create();
        $room = $db->first('rooms', ['id', $id]);
        if (!$room) {
            return null;
        }

        $active = (int) $room->is_active === 1 ? 0 : 1;
        $db->update('rooms', ['id', $id], ['is_active' => $active]);
        $room->is_active = $active;

        return $room;
    }

    /**
     * Get raw record by ID.
     *
     * @param int $id
     *
     * @return object|null
     */
    public static function getRecord(int $id)
    {
        return static::createQuery()
            ->select('id', 'name', 'is_active')
            ->from('rooms')
            ->where(['id', $id])
            ->first();
    }

    /**
     * Load room meta into a flat array.
     *
     * @param int $id
     *
     * @return array
     */
    public static function getMetaValues(int $id): array
    {
        $rows = static::createQuery()
            ->select('name', 'value')
            ->from('rooms_meta')
            ->where(['room_id', $id])
            ->fetchAll();

        $meta = [];
        foreach ($rows as $row) {
            $meta[$row->name] = $row->value;
        }

        return $meta;
    }

    /**
     * Save room meta fields.
     *
     * @param int $id
     * @param array $meta
     *
     * @return void
     */
    public static function saveMeta(int $id, array $meta): void
    {
        $db = \Kotchasan\DB::create();
        $db->delete('rooms_meta', ['room_id', $id], 0);

        foreach ($meta as $name => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $db->insert('rooms_meta', [
                'room_id' => $id,
                'name' => $name,
                'value' => $value
            ]);
        }
    }
}
