<?php
/**
 * CLI only: this writes to the database. Never reachable over HTTP.
 *
 * Helper::getUseLabel() used to read the "use" categories through the 1-hour
 * query cache, so a category an admin had just renamed kept showing its old
 * name on booking details. It must see the rename at once.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Booking\Model;
use Booking\Category\Controller as BookingCategory;
use Booking\Helper\Controller as Helper;

$db = Model::createDB();
$category = $db->getDatabase()->createQuery()
    ->select('category_id', 'topic', 'language')
    ->from('category')
    ->where([['type', 'use'], ['language', '']])
    ->orderBy('category_id')
    ->first();
if (!$category) {
    fwrite(STDERR, "FAIL test needs one \"use\" category\n");
    exit(1);
}

$id = (string) $category->category_id;
$renamed = '__renamed_category__';
$failed = false;

try {
    // Fill the query cache with the current name, the way an earlier page view would.
    $before = (string) BookingCategory::init()->get('use', $id);

    $db->update('category', [['type', 'use'], ['category_id', $id], ['language', '']], ['topic' => $renamed]);

    $stale = (string) BookingCategory::init()->get('use', $id);
    $label = Helper::getUseLabel($id);

    if ($before !== (string) $category->topic) {
        echo "FAIL could not read the category before renaming it\n";
        $failed = true;
    } elseif ($label !== $renamed) {
        echo "FAIL getUseLabel returned \"$label\" after the category was renamed to \"$renamed\"\n";
        $failed = true;
    } else {
        echo "  ok   getUseLabel shows a renamed category at once".($stale !== $renamed ? ' (a cached read still shows the old name)' : '')."\n";
    }
} finally {
    $db->update('category', [['type', 'use'], ['category_id', $id], ['language', '']], ['topic' => (string) $category->topic]);
}

echo $failed ? "FAILED\n" : "ALL PASS\n";
exit($failed ? 1 : 0);
