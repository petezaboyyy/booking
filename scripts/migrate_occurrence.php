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
if (!preg_match('/^[a-zA-Z0-9_]+$/D', (string) $cfg['prefix'])) {
    throw new RuntimeException('Invalid table prefix');
}
require_once __DIR__.'/migrations/connection.php';
$pdo = booking_migration_pdo($cfg);

require_once __DIR__.'/migrations/occurrence_migration.php';
echo 'Reservation occurrence '.booking_apply_occurrence_migration($pdo, (string) $cfg['prefix'])."\n";

require_once __DIR__.'/migrations/schema_sync.php';
$applied = booking_sync_missing_columns($pdo, (string) $cfg['prefix'], dirname(__DIR__).'/install/database.sql');
echo 'Added '.count($applied)." missing column(s).\n";
foreach ($applied as $sql) {
    echo '  '.$sql."\n";
}

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
