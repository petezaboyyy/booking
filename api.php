<?php
/**
 * api.php
 * หน้าเพจสำหรับให้ API เรียกมา
 *
 * @author Goragod Wiriya <admin@goragod.com>
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */
// load Kotchasan
include 'load.php';
// Apply pending schema migrations. Hosts that replace the image ENTRYPOINT with
// their own start command never run the container's migration step, and those
// same hosts rarely offer a shell to run it by hand.
include ROOT_PATH.'scripts/bootstrap_migrations.php';
Gcms\BootstrapMigrations::run(ROOT_PATH, DATA_FOLDER);
// A failed migration is invisible on a host with no log access, so the reason
// still leaves the building — but only as its SQLSTATE code. That is enough to
// tell a missing table from a refused connection or a denied privilege, and it
// carries none of the schema, credentials or host names the driver's full
// message spells out. The full message goes to the error log.
if (!headers_sent() && Gcms\BootstrapMigrations::lastError() !== null) {
    $migrationError = Gcms\BootstrapMigrations::lastError();
    header('X-Migration-Error: '.(preg_match('/SQLSTATE\[[0-9A-Za-z]+\](?:\s*\[\d+\])?/', $migrationError, $sqlState) ? $sqlState[0] : 'failed'));
}
// Initial Kotchasan Framework
$app = Kotchasan::createWebApplication('Gcms\Config');
$app->run();
