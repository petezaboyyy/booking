<?php
/**
 * reservation_occurrence migration, shared by the CLI script, the container
 * entrypoint and the web bootstrap so all three apply exactly the same schema.
 *
 * Throws PDOException on failure; callers decide whether that is fatal.
 */
if (!function_exists('booking_apply_occurrence_migration')) {
    /**
     * @param PDO    $pdo
     * @param string $prefix table prefix, already validated by the caller
     *
     * @return string human readable summary of what was applied
     */
    function booking_apply_occurrence_migration(PDO $pdo, string $prefix): string
    {
        $occurrence = $prefix.'_reservation_occurrence';
        $reservation = $prefix.'_reservation';
        $reservationData = $prefix.'_reservation_data';

        $pdo->exec("CREATE TABLE IF NOT EXISTS `$occurrence` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `reservation_id` INT(11) NOT NULL,
            `room_id` INT(11) NOT NULL,
            `begin` DATETIME NOT NULL,
            `end` DATETIME NOT NULL,
            `status` TINYINT(1) NOT NULL DEFAULT 0,
            `cancelled_by` INT(11) NULL DEFAULT NULL,
            `cancelled_at` DATETIME NULL DEFAULT NULL,
            `cancellation_reason` VARCHAR(255) NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_reservation_occurrence` (`reservation_id`, `begin`, `end`),
            KEY `idx_occurrence_availability` (`room_id`, `status`, `begin`, `end`),
            KEY `idx_occurrence_reservation` (`reservation_id`, `status`, `begin`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // A fresh install has no reservations yet; the tables the backfill reads
        // from arrive later with install/database.sql, so leaving early beats a
        // fatal error that would look like a broken deploy.
        $exists = static function (string $name) use ($pdo) {
            $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$name]);
            return (bool) $stmt->fetchColumn();
        };
        if (!$exists($reservation) || !$exists($reservationData)) {
            return "table ready, nothing to backfill (`$reservation` not installed yet)";
        }

        $legacy = $pdo->query("SELECT R.`id`, R.`room_id`, R.`begin`, R.`end`, R.`schedule_type`, R.`status`,
              (SELECT D.`value` FROM `$reservationData` D
               WHERE D.`reservation_id` = R.`id` AND D.`name` = 'recurrence_days' LIMIT 1) recurrence_days
            FROM `$reservation` R
            WHERE NOT EXISTS (SELECT 1 FROM `$occurrence` O WHERE O.`reservation_id` = R.`id`)")->fetchAll(PDO::FETCH_ASSOC);

        $insert = $pdo->prepare("INSERT IGNORE INTO `$occurrence`
            (`reservation_id`, `room_id`, `begin`, `end`, `status`, `created_at`, `updated_at`)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $now = date('Y-m-d H:i:s');
        $rows = 0;
        foreach ($legacy as $row) {
            $beginTs = strtotime((string) $row['begin']);
            $endTs = strtotime((string) $row['end']);
            if ($beginTs === false || $endTs === false || $endTs <= $beginTs) {
                continue;
            }
            $scheduleType = (string) ($row['schedule_type'] ?: 'continuous');
            $days = array_map('intval', array_filter(explode(',', (string) ($row['recurrence_days'] ?? '')), 'strlen'));
            if ($scheduleType === 'continuous') {
                $insert->execute([(int) $row['id'], (int) $row['room_id'], date('Y-m-d H:i:s', $beginTs), date('Y-m-d H:i:s', $endTs), (int) $row['status'], $now, $now]);
                $rows += $insert->rowCount();
                continue;
            }
            $cursor = strtotime(date('Y-m-d', $beginTs));
            $last = strtotime(date('Y-m-d', $endTs));
            while ($cursor !== false && $cursor <= $last) {
                if ($scheduleType !== 'weekly-slot' || in_array((int) date('w', $cursor), $days, true)) {
                    $date = date('Y-m-d', $cursor);
                    $insert->execute([(int) $row['id'], (int) $row['room_id'], $date.' '.date('H:i:s', $beginTs), $date.' '.date('H:i:s', $endTs), (int) $row['status'], $now, $now]);
                    $rows += $insert->rowCount();
                }
                $cursor = strtotime('+1 day', $cursor);
            }
        }

        return 'table ready, backfilled '.$rows.' occurrence(s) for '.count($legacy).' reservation(s)';
    }
}
