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
if (!headers_sent() && Gcms\BootstrapMigrations::lastError() !== null) {
    header('X-Migration-Error: '.substr(preg_replace('/[\r\n]+/', ' ', Gcms\BootstrapMigrations::lastError()), 0, 200));
}
// Initial Kotchasan Framework
$app = Kotchasan::createWebApplication('Gcms\Config');
$app->run();
