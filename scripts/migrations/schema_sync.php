<?php
/**
 * Adds columns that install/database.sql defines but a deployed database is
 * missing, so a site installed from an older schema stops failing one query at
 * a time ("1054 Unknown column 'version' in 'field list'").
 *
 * Deliberately additive: it never drops, renames or retypes a column, so it
 * cannot destroy data that the running site still reads. Type changes and
 * renames remain the web installer's job (install/upgrade2.php).
 */
if (!function_exists('booking_sync_missing_columns')) {
    /**
     * Parses the CREATE TABLE blocks of install/database.sql.
     *
     * @param string $sqlPath
     *
     * @return array<string, array<string, string>> table suffix => [column => definition]
     */
    function booking_parse_schema(string $sqlPath): array
    {
        $tables = [];
        $current = null;
        foreach (preg_split('/\R/', (string) file_get_contents($sqlPath)) as $line) {
            if (preg_match('/^CREATE TABLE `\{prefix\}_([a-z0-9_]+)`/i', $line, $m)) {
                $current = $m[1];
                $tables[$current] = [];
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^\)\s*ENGINE=/i', $line)) {
                $current = null;
                continue;
            }
            // Column lines only: keys and constraints are applied separately by
            // database.sql and are not safe to replay blindly.
            if (preg_match('/^\s*`([a-z0-9_]+)`\s+(.+?),?\s*$/i', $line, $m)) {
                $tables[$current][$m[1]] = rtrim($m[2], ',');
            }
        }

        return $tables;
    }

    /**
     * @param PDO    $pdo
     * @param string $prefix  table prefix, already validated by the caller
     * @param string $sqlPath path to install/database.sql
     *
     * @return string[] the ALTER statements that were applied
     */
    function booking_sync_missing_columns(PDO $pdo, string $prefix, string $sqlPath): array
    {
        $applied = [];
        $existingTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (booking_parse_schema($sqlPath) as $suffix => $columns) {
            $table = $prefix.'_'.$suffix;
            if (!in_array($table, $existingTables, true)) {
                // Absent tables need their keys and AUTO_INCREMENT too, which
                // this function does not reproduce; they are reported instead.
                continue;
            }
            $present = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            $previous = null;
            foreach ($columns as $name => $definition) {
                if (in_array($name, $present, true)) {
                    $previous = $name;
                    continue;
                }
                // Keep the column where install/database.sql puts it when the
                // column it follows is already there.
                $after = ($previous !== null && in_array($previous, $present, true)) ? " AFTER `$previous`" : '';
                $sql = "ALTER TABLE `$table` ADD COLUMN `$name` $definition".$after;
                $pdo->exec($sql);
                $applied[] = $sql;
                $present[] = $name;
                $previous = $name;
            }
        }

        return $applied;
    }
}
