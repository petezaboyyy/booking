<?php
/**
 * Applies pending schema migrations on the first API request after a deploy.
 *
 * The container entrypoint already does this at boot, but a host may replace
 * the image's ENTRYPOINT with its own start command (Render's "Docker Command"
 * does), and such a host usually offers no shell to run the migration by hand
 * either. Running from the application itself is the only path that survives
 * both, so the site repairs its own schema instead of serving 500s until
 * someone with dashboard access notices.
 *
 * A marker file keeps this to one file_exists() per request once applied.
 * Nothing here is allowed to break a request: a failure is logged and the
 * reason kept for Gcms\BootstrapMigrations::lastError().
 */

namespace Gcms;

class BootstrapMigrations
{
    /** @var string|null reason the last run failed, for diagnostics */
    private static $lastError;

    public static function lastError()
    {
        return self::$lastError;
    }

    public static function run(string $rootPath, string $dataFolder)
    {
        $marker = $rootPath.$dataFolder.'migrations/occurrence.done';
        if (is_file($marker)) {
            return;
        }
        try {
            $cfg = (require $rootPath.'settings/database.php')['mysql'];
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', (string) $cfg['prefix'])) {
                throw new \RuntimeException('Invalid table prefix');
            }
            $pdo = new \PDO(
                'mysql:host='.$cfg['hostname'].';port='.$cfg['port'].';dbname='.$cfg['dbname'].';charset=utf8mb4',
                $cfg['username'],
                $cfg['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            require_once $rootPath.'scripts/migrations/occurrence_migration.php';
            $summary = booking_apply_occurrence_migration($pdo, (string) $cfg['prefix']);

            // Best effort: an unwritable datas/ only costs a repeated migration,
            // which is idempotent, so it must not turn into a failure.
            if (!is_dir(dirname($marker))) {
                @mkdir(dirname($marker), 0775, true);
            }
            @file_put_contents($marker, date('c').' '.$summary."\n");
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            @error_log('[booking] occurrence migration failed: '.$e->getMessage());
        }
    }
}
