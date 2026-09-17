<?php
/**
 * Builds the migration's PDO connection the same way Kotchasan\Connection\MySQLDriver
 * builds the application's.
 *
 * Managed databases such as TiDB Cloud Serverless reject unencrypted
 * connections outright ("1105 Connections using insecure transport are
 * prohibited"), so a migration that opens its own plain PDO fails on exactly
 * the deployments that need it most.
 */
if (!function_exists('booking_migration_pdo')) {
    /**
     * @param array $cfg the 'mysql' section of settings/database.php
     *
     * @return PDO
     */
    function booking_migration_pdo(array $cfg): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
        if (!empty($cfg['ssl'])) {
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
            if (!empty($cfg['ssl_ca']) && file_exists($cfg['ssl_ca'])) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $cfg['ssl_ca'];
            } elseif (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
            }
        }

        return new PDO(
            'mysql:host='.$cfg['hostname'].';port='.$cfg['port'].';dbname='.$cfg['dbname'].';charset=utf8mb4',
            $cfg['username'] ?? '',
            $cfg['password'] ?? '',
            $options
        );
    }
}
