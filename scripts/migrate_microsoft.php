<?php
/** Idempotent migration; run: docker compose exec web php scripts/migrate_microsoft.php */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$cfg = (require dirname(__DIR__).'/settings/database.php')['mysql'];
$table = $cfg['prefix'].'_user';
if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
    throw new RuntimeException('Invalid table prefix');
}
$pdo = new PDO('mysql:host='.$cfg['hostname'].';port='.$cfg['port'].';dbname='.$cfg['dbname'].';charset=utf8mb4', $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("ALTER TABLE `$table` MODIFY `social` ENUM('user','facebook','google','line','telegram','microsoft') NULL DEFAULT 'user'");
$columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
foreach (['microsoft_id' => 'VARCHAR(64)', 'microsoft_tenant_id' => 'VARCHAR(36)'] as $name => $type) {
    if (!in_array($name, $columns, true)) {
        $pdo->exec("ALTER TABLE `$table` ADD `$name` $type NULL DEFAULT NULL");
    }
}
$indices = $pdo->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
if (!in_array('microsoft_identity', array_column($indices, 'Key_name'), true)) {
    $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `microsoft_identity` (`microsoft_tenant_id`, `microsoft_id`)");
}
echo "Microsoft SSO schema ready.\n";
