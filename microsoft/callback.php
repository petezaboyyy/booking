<?php
require __DIR__.'/bootstrap.php';
$app = Kotchasan::createWebApplication('Gcms\Config');
$app->defaultController = 'Index\Microsoftcallback\Controller';
$app->run();
