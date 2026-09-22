<?php
/** CLI only: keeps the suite off the web, like every other test here. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * What the API router is allowed to call.
 *
 * Before this guard existed, method_exists() decided, and three things got
 * through without an account:
 *
 *   1. Every controller inherits index() from ApiController, so a route with
 *      no action of its own (api/index/config) dispatched into the dispatcher,
 *      which re-read the same route and recursed until PHP hit the 128 MB
 *      limit. The reply was HTTP 200 carrying a fatal error that also printed
 *      the server path, and the worker died with it.
 *   2. method_exists() is true for protected methods, so
 *      api/index/auth/validateCsrfToken reached an internal helper.
 *   3. A public static helper answered through an instance, so
 *      api/booking/export/isAdmin ran.
 *
 * Usage: docker compose exec web php tests/api_dispatch_test.php
 */

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

$fail = 0;

function check($condition, string $message): void
{
    global $fail;
    if ($condition) {
        echo "  ok   $message\n";
    } else {
        $fail++;
        echo "  FAIL $message\n";
    }
}

/** Ask the router's guard the same question the dispatcher asks. */
function dispatchable(string $class, string $action): bool
{
    static $method = null;
    if ($method === null) {
        $method = new \ReflectionMethod(\Kotchasan\ApiController::class, 'isDispatchableAction');
        $method->setAccessible(true);
    }

    return $method->invoke(null, $class, $action);
}

// =========================================================================
echo "\n[1] A controller without its own index() is a 404, not a crash\n";
// =========================================================================
// These five were the reported bug: reachable with no account, 128 MB each.
$inherited = [
    'Index\Config\Controller',
    'Index\Auth\Controller',
    'Booking\Catalog\Controller',
    'Booking\Review\Controller',
    'Booking\Settings\Controller'
];
foreach ($inherited as $class) {
    check(class_exists($class), $class.' exists, so the old guard let it through');
    check(method_exists($class, 'index'), $class.' still inherits index(), which is why method_exists() was not enough');
    check(!dispatchable($class, 'index'), $class.'::index is refused — no recursion');
}

// =========================================================================
echo "\n[2] Nothing may dispatch back into the dispatcher\n";
// =========================================================================
// The rule, stated once: whatever ApiController declares is plumbing. If a
// later controller forgets to define index(), it must fall to a 404 by
// itself, without anyone remembering to add it to a list.
foreach (glob(__DIR__.'/../modules/*/controllers/*.php') as $file) {
    $module = basename(dirname(dirname($file)));
    $name = basename($file, '.php');
    $class = ucfirst($module).'\\'.ucfirst($name).'\\Controller';
    if (!class_exists($class) || !method_exists($class, 'index')) {
        continue;
    }
    $declaredBy = (new \ReflectionMethod($class, 'index'))->getDeclaringClass()->getName();
    if ($declaredBy === \Kotchasan\ApiController::class) {
        check(!dispatchable($class, 'index'), 'api/'.$module.'/'.$name.' cannot recurse into the dispatcher');
    } else {
        check(dispatchable($class, 'index'), 'api/'.$module.'/'.$name.' still works — its index() is its own');
    }
}

// =========================================================================
echo "\n[3] Internal helpers are not endpoints\n";
// =========================================================================
foreach (['validateCsrfToken', 'handleException', 'authenticateRequest', 'errorResponse', 'getAccessToken'] as $helper) {
    check(method_exists('Index\Auth\Controller', $helper), $helper.' exists, so method_exists() would have allowed it');
    check(!dispatchable('Index\Auth\Controller', $helper), $helper.' is protected — refused');
}
check(!dispatchable('Booking\Export\Controller', 'isAdmin'), 'isAdmin() is a static helper, not a route — refused');
check(!dispatchable('Booking\Export\Controller', 'validateMethod'), 'validateMethod() is a static helper — refused');

// =========================================================================
echo "\n[4] Real endpoints are untouched\n";
// =========================================================================
$routes = [
    ['Booking\Calendar\Controller', 'index', 'the public calendar'],
    ['Booking\Rooms\Controller', 'index', 'the room list, whose index() comes from Gcms\Table'],
    ['Index\Config\Controller', 'login', 'the login page config'],
    ['Index\Config\Controller', 'frontendSettings', 'the frontend settings'],
    ['Index\Auth\Controller', 'login', 'signing in'],
    ['Index\Auth\Controller', 'logout', 'signing out'],
    ['Booking\Export\Controller', 'csv', 'the CSV export'],
    ['Booking\Booking\Controller', 'save', 'saving a booking']
];
foreach ($routes as $route) {
    list($class, $action, $what) = $route;
    check(dispatchable($class, $action), $what.' still dispatches ('.$class.'::'.$action.')');
}

// A parent may legitimately supply an action — only this dispatcher may not.
$roomsIndex = (new \ReflectionMethod('Booking\Rooms\Controller', 'index'))->getDeclaringClass()->getName();
check(
    $roomsIndex !== 'Booking\Rooms\Controller' && dispatchable('Booking\Rooms\Controller', 'index'),
    'an inherited action from a normal parent ('.$roomsIndex.') is still allowed'
);

// =========================================================================
echo "\n[5] A route that never existed still 404s the same way\n";
// =========================================================================
check(!dispatchable('Booking\Zzz\Controller', 'index'), 'an unknown class is refused');
check(!dispatchable('Booking\Calendar\Controller', 'zzz'), 'an unknown action is refused');
check(!dispatchable('Booking\Calendar\Controller', '__construct'), 'the constructor is not an endpoint');

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILURES\n";
exit($fail === 0 ? 0 : 1);
