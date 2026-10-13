<?php
/**
 * One room per building + room number.
 *
 * The room number and the building live in {prefix}_rooms_meta, one row each,
 * and a database cannot make a pair of rows in a key/value table unique. So the
 * pair is also stored as a single value on the room itself, {prefix}_rooms.room_key,
 * and that column carries the UNIQUE index.
 *
 * The key is NULL for a room with no room number (the number is optional), and
 * a UNIQUE index lets any number of NULLs through. It is a SHA-1 of the
 * normalized building and number, so a long building name cannot exceed an index
 * length limit on an older server.
 *
 * Plain functions with no framework dependency: the application, the container
 * entrypoint and the request-time bootstrap all use them.
 */
if (!function_exists('booking_room_key')) {
    /**
     * Trim, fold runs of whitespace and ignore case, so "R-0001", " r-0001 " and
     * "R-0001" typed with two spaces inside are the same room number.
     *
     * @param string|null $value
     *
     * @return string
     */
    function booking_room_key_part(?string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /**
     * @param string|null $building
     * @param string|null $number
     *
     * @return string|null null when there is no room number, so nothing to keep unique
     */
    function booking_room_key(?string $building, ?string $number): ?string
    {
        $number = booking_room_key_part($number);
        if ($number === '') {
            return null;
        }

        return sha1(booking_room_key_part($building)."\x1F".$number);
    }

    /**
     * Idempotent. Adds the column and the UNIQUE index if they are missing and
     * fills in the key of every room that does not have the right one.
     *
     * Rooms that already share a building and number (created before this rule
     * existed) must not stop a deploy: the first of them keeps the key, the rest
     * are left with NULL and reported, and become editable once someone gives
     * them a different number.
     *
     * @param PDO    $pdo
     * @param string $prefix table prefix, already validated by the caller
     *
     * @return string one-line summary
     */
    function booking_apply_room_key_migration(PDO $pdo, string $prefix): string
    {
        $rooms = $prefix.'_rooms';
        $meta = $prefix.'_rooms_meta';

        $columns = $pdo->query("SHOW COLUMNS FROM `$rooms`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('room_key', $columns, true)) {
            $pdo->exec("ALTER TABLE `$rooms` ADD COLUMN `room_key` char(40) DEFAULT NULL");
        }

        // MAX() because a room could have two meta rows with the same name.
        $rows = $pdo->query(
            "SELECT R.`id`, R.`room_key`, MAX(B.`value`) AS building, MAX(N.`value`) AS number
               FROM `$rooms` R
               LEFT JOIN `$meta` B ON B.`room_id` = R.`id` AND B.`name` = 'building'
               LEFT JOIN `$meta` N ON N.`room_id` = R.`id` AND N.`name` = 'number'
              GROUP BY R.`id`, R.`room_key`
              ORDER BY R.`id`"
        )->fetchAll(PDO::FETCH_ASSOC);

        $owner = [];
        $changes = [];
        $duplicates = [];
        foreach ($rows as $row) {
            $key = booking_room_key($row['building'], $row['number']);
            if ($key !== null) {
                if (isset($owner[$key])) {
                    $duplicates[] = $owner[$key].'='.$row['id'];
                    $key = null;
                } else {
                    $owner[$key] = $row['id'];
                }
            }
            if ($key !== $row['room_key']) {
                $changes[] = [$row['id'], $key];
            }
        }

        // Clear before setting, so moving a key from one room to another cannot
        // trip the UNIQUE index half way through.
        $clear = $pdo->prepare("UPDATE `$rooms` SET `room_key` = NULL WHERE `id` = ?");
        $set = $pdo->prepare("UPDATE `$rooms` SET `room_key` = ? WHERE `id` = ?");
        foreach ($changes as [$id, $key]) {
            $clear->execute([$id]);
        }
        foreach ($changes as [$id, $key]) {
            if ($key !== null) {
                $set->execute([$key, $id]);
            }
        }

        $indexed = $pdo->query("SHOW INDEX FROM `$rooms` WHERE `Key_name` = 'uq_rooms_room_key'")->fetch();
        if (!$indexed) {
            $pdo->exec("ALTER TABLE `$rooms` ADD UNIQUE KEY `uq_rooms_room_key` (`room_key`)");
        }

        return 'room keys: '.count($changes).' updated'
            .($duplicates ? '; duplicate building+number left unkeyed (first=other room id): '.implode(', ', $duplicates) : '')
            .($indexed ? '' : '; unique index added');
    }
}
