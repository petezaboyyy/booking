<?php
/**
 * CLI only: this writes to the database. Never reachable over HTTP.
 *
 * One room per building + room number:
 *  - the key ignores case and extra spaces, and does not exist without a number;
 *  - the save check finds an existing room, but never a room's own key;
 *  - the database itself rejects a second room with the same pair;
 *  - the migration keys old rooms and does not fail on legacy duplicates.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Room\Model;

$failed = 0;
$check = static function (bool $passed, string $message) use (&$failed): void {
    echo ($passed ? '  ok   ' : '  FAIL ').$message."\n";
    if (!$passed) {
        $failed++;
    }
};

$db = \Kotchasan\DB::create();
$building = '__test_building__';
$created = [];

$newRoom = static function (string $name) use ($db, &$created): array {
    return ['name' => $name, 'detail' => '', 'color' => '#000000', 'is_active' => 1];
};

try {
    // 1. The key itself
    $key = Model::roomKey($building, 'T-100');
    $check($key !== null && strlen($key) === 40, 'a room with a number has a 40-character key');
    $check(Model::roomKey('  '.strtoupper($building).' ', "t-100") === $key, 'the key ignores case and surrounding spaces');
    $check(Model::roomKey($building, "T-\t100") === Model::roomKey($building, 'T- 100'), 'runs of whitespace count as one space');
    $check(Model::roomKey($building, 'T-100') !== Model::roomKey($building.'2', 'T-100'), 'the same number in another building is a different key');
    $check(Model::roomKey($building, '') === null && Model::roomKey($building, '   ') === null, 'no room number, no key (the number is optional)');

    // 2. Saving
    $id = Model::save(0, $newRoom('__test_room_a__'), ['building' => $building, 'number' => 'T-100', 'seats' => '5']);
    $created[] = $id;
    $check($id > 0, 'a room with a free building + number is created');
    $check($db->first('rooms', ['id', $id])->room_key === $key, 'its key is stored on the room');

    $dup = Model::findDuplicate(0, $building, ' t-100 ');
    $check($dup !== null && (int) $dup->id === $id, 'a new room with the same pair (any case) is reported as a duplicate of the first');
    $check(Model::findDuplicate($id, $building, 'T-100') === null, 'a room is never a duplicate of itself, so editing it still saves');
    $check(Model::findDuplicate(0, $building.'2', 'T-100') === null, 'the same number in another building is free');
    $check(Model::findDuplicate(0, $building, '') === null, 'no number is never a duplicate');

    // 3. The database refuses it even if the check is skipped
    $rejected = false;
    $isDuplicate = false;
    try {
        $other = Model::save(0, $newRoom('__test_room_b__'), ['building' => $building, 'number' => 'T-100', 'seats' => '']);
        $created[] = $other;
    } catch (\Throwable $e) {
        $rejected = true;
        $isDuplicate = Model::isDuplicateKeyError($e);
    }
    $check($rejected, 'saving a second room with the same pair is rejected by the database');
    $check($isDuplicate, 'and the error is recognised as a duplicate key, so it can be shown as a form error');
    $check(empty($db->first('rooms', ['name', '__test_room_b__'])), 'no half-created room is left behind');

    $a = Model::save(0, $newRoom('__test_room_c__'), ['building' => $building, 'number' => '', 'seats' => '']);
    $b = Model::save(0, $newRoom('__test_room_d__'), ['building' => $building, 'number' => '', 'seats' => '']);
    array_push($created, $a, $b);
    $check($a > 0 && $b > 0, 'any number of rooms without a room number can exist');

    // 4. Renaming a room onto another room's pair is refused; moving it to a free one is fine
    $moved = Model::save($b, $newRoom('__test_room_d__'), ['building' => $building, 'number' => 'T-200', 'seats' => '']);
    $check($moved === $b && $db->first('rooms', ['id', $b])->room_key === Model::roomKey($building, 'T-200'), 'giving a room a free number updates its key');

    // 5. The migration on legacy data
    $cfg = (require ROOT_PATH.'settings/database.php')['mysql'];
    require_once ROOT_PATH.'scripts/migrations/connection.php';
    $pdo = booking_migration_pdo($cfg);
    $prefix = (string) $cfg['prefix'];

    // Two rooms that share a pair, added behind the app's back (no key).
    $legacy1 = (int) $db->insert('rooms', $newRoom('__test_legacy_1__'));
    $legacy2 = (int) $db->insert('rooms', $newRoom('__test_legacy_2__'));
    array_push($created, $legacy1, $legacy2);
    foreach ([$legacy1, $legacy2] as $room) {
        $db->insert('rooms_meta', ['room_id' => $room, 'name' => 'building', 'value' => $building]);
        $db->insert('rooms_meta', ['room_id' => $room, 'name' => 'number', 'value' => 'T-300']);
    }

    $summary = booking_apply_room_key_migration($pdo, $prefix);
    $keyOf = static function (int $room) use ($db) {
        return $db->first('rooms', ['id', $room])->room_key;
    };
    $check($keyOf($legacy1) === Model::roomKey($building, 'T-300'), 'the migration keys the first of two legacy rooms with the same pair');
    $check($keyOf($legacy2) === null, 'and leaves the other unkeyed instead of failing');
    $check(strpos($summary, $legacy1.'='.$legacy2) !== false, 'and names the pair in its summary');
    $check(strpos(booking_apply_room_key_migration($pdo, $prefix), '0 updated') !== false, 'running it again changes nothing');

    // The unkeyed duplicate does not let a third one in
    $check(Model::findDuplicate(0, $building, 'T-300') !== null, 'a new room with the legacy pair is still reported as a duplicate');
} finally {
    if (!empty($created)) {
        $db->delete('rooms_meta', ['room_id', $created], 0);
        $db->delete('rooms', ['id', $created], 0);
    }
    // A failed insert can still have burned an id; nothing else to undo.
}

echo $failed === 0 ? "ALL PASS\n" : "$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
