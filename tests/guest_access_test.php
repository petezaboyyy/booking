<?php
/** CLI only, like the rest of the suite. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * What a visitor who has not signed in may see.
 *
 * The room catalog was opened to guests so someone can look at the rooms
 * before deciding whether an account is worth it. That is only safe while
 * the catalog keeps carrying room information and nothing else, so the
 * payload is pinned here: a join added later that drags in a booking or a
 * member breaks this test rather than the privacy of whoever booked.
 *
 * Usage: docker compose exec web php tests/guest_access_test.php
 */

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Catalog\Model as CatalogModel;

$fail = 0;
$deactivated = null;

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

/** Does this controller action ask who is calling before it answers? */
function requiresLogin(string $class, string $action): bool
{
    $method = new \ReflectionMethod($class, $action);
    $source = implode('', array_slice(
        file($method->getFileName()),
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1
    ));

    return strpos($source, 'authenticateRequest') !== false;
}

/** Flip a room's availability, restoring it in the finally block below. */
function setRoomActive($db, int $id, int $active): void
{
    $db->update('rooms', ['id', $id], ['is_active' => $active]);
}

$db = CatalogModel::createDB();

try {
    // =====================================================================
    echo "\n[1] The catalog answers without an account\n";
    // =====================================================================
    check(!requiresLogin('Booking\Catalog\Controller', 'lists'), 'catalog/lists does not demand a login');
    check(!requiresLogin('Booking\Catalog\Controller', 'detail'), 'catalog/detail does not demand a login');

    // =====================================================================
    echo "\n[2] It carries room information and nothing else\n";
    // =====================================================================
    $items = CatalogModel::getItems(true);
    check(!empty($items), 'there is at least one active room to inspect');
    $fields = array_keys(get_object_vars($items[0]));
    sort($fields);
    $expected = ['booking_url', 'building', 'color', 'detail', 'first_image_url', 'gallery', 'gallery_count', 'id', 'is_active', 'name', 'room_number', 'seats'];
    sort($expected);
    check(
        $fields === $expected,
        'the payload is exactly the public room fields (got: '.implode(', ', $fields).')'
    );

    // Named one by one so a future join cannot slip a person into the page.
    foreach (['member_id', 'member_name', 'member_email', 'email', 'username', 'password', 'phone', 'line_uid', 'telegram_id', 'permission', 'topic', 'comment', 'reason', 'reservation_id'] as $private) {
        check(!in_array($private, $fields, true), 'no '.$private.' reaches an anonymous visitor');
    }

    // =====================================================================
    echo "\n[3] A room taken out of service stays hidden\n";
    // =====================================================================
    $room = $db->getDatabase()->createQuery()->select('id')->from('rooms')->where(['is_active', 1])->orderBy('id', 'DESC')->first();
    check($room !== false, 'found an active room to deactivate');
    $deactivated = (int) $room->id;
    $before = count(CatalogModel::getItems(true));

    setRoomActive($db, $deactivated, 0);

    check(CatalogModel::get($deactivated, true) === null, 'its id typed into the URL gives nothing back');
    check(count(CatalogModel::getItems(true)) === $before - 1, 'it drops out of the list');

    setRoomActive($db, $deactivated, 1);
    $deactivated = null;
    check(CatalogModel::get($room->id, true) !== null, 'it comes back once it is active again');

    // =====================================================================
    echo "\n[4] Everything else still needs an account\n";
    // =====================================================================
    // Opening one endpoint must not have loosened its neighbours.
    $guarded = [
        ['Booking\Booking\Controller', 'save', 'making a booking'],
        ['Booking\Rooms\Controller', 'index', 'the room admin table'],
        ['Booking\Export\Controller', 'csv', 'the CSV export'],
        ['Booking\View\Controller', 'index', 'a booking detail']
    ];
    foreach ($guarded as $route) {
        list($class, $action, $what) = $route;
        if (!class_exists($class) || !method_exists($class, $action)) {
            check(false, $what.' — '.$class.'::'.$action.' is missing');
            continue;
        }
        check(requiresLogin($class, $action), $what.' still asks who is calling');
    }
} finally {
    if ($deactivated !== null) {
        setRoomActive($db, $deactivated, 1);
        echo "\nre-activated room $deactivated\n";
    }
}

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILURES\n";
exit($fail === 0 ? 0 : 1);
