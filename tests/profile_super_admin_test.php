<?php
/**
 * Super-admin permission normalization and role requirements.
 *
 * Usage: php tests/profile_super_admin_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../load.php';
Kotchasan::createWebApplication('Gcms\Config');

$fail = 0;
function check($condition, string $message): void
{
    global $fail;
    echo ($condition ? '  ok   ' : '  FAIL ').$message."\n";
    if (!$condition) {
        $fail++;
    }
}

$method = new ReflectionMethod(\Index\Profile\Controller::class, 'normalizePermissions');
$method->setAccessible(true);

$permissions = $method->invoke(null, ['super_admin', 'can_config', 'rray']);
check($permissions === ['super_admin', 'can_config'], 'keeps valid permissions and drops malformed tags');

$permissions = $method->invoke(null, '["Super Admin","can_manage_booking"]');
check($permissions === ['super_admin', 'can_manage_booking'], 'accepts the JSON tags payload and maps the display label');

$superAdmin = (object) ['status' => 1, 'permission' => $permissions];
check(\Gcms\Api::isSuperAdmin($superAdmin), 'saved role satisfies the super-admin authorization check');

$member = (object) ['status' => 0, 'permission' => ['super_admin']];
check(!\Gcms\Api::isSuperAdmin($member), 'permission without administrator status is rejected');

echo $fail === 0 ? "\nALL PASS\n" : "\n{$fail} FAILURES\n";
exit($fail === 0 ? 0 : 1);
