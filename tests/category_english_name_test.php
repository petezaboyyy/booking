<?php
/** CLI only. Never reachable over HTTP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/** CLI regression check for the optional English category name feature. */

$root = dirname(__DIR__);
$schema = file_get_contents($root.'/install/database.sql');
$categoriesModel = file_get_contents($root.'/modules/booking/models/categories.php');
$categoriesController = file_get_contents($root.'/modules/booking/controllers/categories.php');
$helper = file_get_contents($root.'/modules/booking/controllers/helper.php');
$bootstrap = file_get_contents($root.'/scripts/bootstrap_migrations.php');
$i18nSrc = file_get_contents($root.'/Now/js/I18nManager.js');
$i18nDist = file_get_contents($root.'/Now/dist/now.core.min.js');
$thPhp = file_get_contents($root.'/language/th.php');

$checks = [
    [strpos($schema, '`topic_en` varchar(150) NOT NULL DEFAULT') !== false, 'schema has an optional English name column, additive only (no data touched)'],
    // v5 is the marker this column shipped with. Later migrations bump it again (room_key made it v6),
    // so ask for "v5 or newer": an older marker would never run the sync on a deployed container.
    [preg_match('/schema-v(\d+)\.done/', $bootstrap, $marker) === 1 && (int) $marker[1] >= 5, 'the self-migration marker was bumped so the new column reaches already-deployed containers'],
    [strpos($categoriesModel, 'function englishNames') !== false, 'model exposes per-category English names keyed by category_id'],
    [strpos($categoriesModel, "'field' => 'topic_en'") !== false, 'categories admin table renders an English name column for the "use" type'],
    [strpos($categoriesController, "post('topic_en'") !== false, 'category save reads the English name field'],
    [strpos($helper, 'localizeCategoryOptionText') !== false && strpos($helper, "Language::name() !== 'en'") !== false, 'booking form option helpers swap in the English name only when the site language is English'],
    [strpos($helper, "'value' => \$thText,") !== false, 'the stored topic value stays the Thai text regardless of language, so historical bookings keep a stable value'],
    [strpos($i18nSrc, "document.cookie = 'my_lang='") !== false, 'I18nManager source syncs the chosen locale to the my_lang cookie the PHP backend reads'],
    [strpos($i18nDist, 'document.cookie="my_lang="') !== false, 'built now.core.min.js bundle matches the source fix (bundle must be rebuilt after I18nManager.js changes)'],
    [strpos($thPhp, "'English name' =>") !== false, 'the new column label has a Thai translation']
];

$failed = 0;
foreach ($checks as [$passed, $message]) {
    echo ($passed ? 'ok   ' : 'FAIL ').$message.PHP_EOL;
    if (!$passed) {
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
