<?php
/**
 * {prefix}_user_session: one row per signed-in device.
 *
 * A token is accepted only while its session row exists, so signing in on a
 * second device no longer signs the first one out, and signing out ends only
 * the device that asked. Index\Auth\Model owns the rows and the time limits.
 *
 * Plain functions with no framework dependency: the CLI script, the container
 * entrypoint and the request-time bootstrap all use them.
 */
if (!function_exists('booking_apply_user_session_migration')) {
    /**
     * @param PDO    $pdo
     * @param string $prefix table prefix, already validated by the caller
     *
     * @return string human readable summary of what was applied
     */
    function booking_apply_user_session_migration(PDO $pdo, string $prefix): string
    {
        $table = $prefix.'_user_session';
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$table` (
            `id` VARCHAR(64) NOT NULL,
            `member_id` INT(11) NOT NULL,
            `created_at` DATETIME NOT NULL,
            `last_seen` DATETIME NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `ip` VARCHAR(45) NOT NULL DEFAULT '',
            `user_agent` VARCHAR(500) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `idx_member` (`member_id`),
            KEY `idx_expires` (`expires_at`),
            KEY `idx_last_seen` (`last_seen`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        return "user session table ready (`$table`)";
    }
}
