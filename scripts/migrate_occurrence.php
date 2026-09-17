<?php
/**
 * Idempotent migration; run: docker compose exec web php scripts/migrate_occurrence.php
 *
 * Creates {prefix}_reservation_occurrence and backfills one row per booked day
 * for reservations made before occurrences existed. Mirrors install/upgrade2.php
 * so a deployed site can be brought forward without the web installer.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$cfg = (require dirname(__DIR__).'/settings/database.php')['mysql'];
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $cfg['prefix'])) {
    throw new RuntimeException('Invalid table prefix');
}
$occurrence = $cfg['prefix'].'_reservation_occurrence';
$reservation = $cfg['prefix'].'_reservation';
$reservationData = $cfg['prefix'].'_reservation_data';
$pdo = new PDO(
    'mysql:host='.$cfg['hostname'].';port='.$cfg['port'].';dbname='.$cfg['dbname'].';charset=utf8mb4',
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

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
echo "Reservation occurrence schema ready. Backfilled $rows occurrence(s) for ".count($legacy)." reservation(s).\n";

// A missing occurrence table usually means the whole schema is behind, so name
// anything else install/database.sql expects but the database does not have.
$expected = [];
preg_match_all('/CREATE TABLE `\{prefix\}_([a-z0-9_]+)`/i', (string) file_get_contents(dirname(__DIR__).'/install/database.sql'), $matches);
foreach ($matches[1] as $name) {
    $expected[] = $cfg['prefix'].'_'.$name;
}
$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$missing = array_diff($expected, $existing);
if ($missing) {
    echo "Still missing from `".$cfg['dbname']."`: ".implode(', ', $missing)."\n";
    echo "Run the web installer upgrade (install/) to bring the rest of the schema forward.\n";
}
