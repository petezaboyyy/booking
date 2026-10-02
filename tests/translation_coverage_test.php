<?php
/** CLI only, like the rest of the suite. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
/**
 * Switching between Thai and English changes everything a user reads.
 *
 * The browser translates a string only when it is a key in
 * language/th.json (Thai) or language/en.json (English), matched exactly,
 * so a typo in case, a sentence built with a number spliced in, or a Thai
 * placeholder written straight into a template all stayed in one language.
 * This pins the rules the audit found broken.
 *
 * Usage: docker compose exec web php tests/translation_coverage_test.php
 */

require_once __DIR__.'/../load.php';

Kotchasan::createWebApplication('Gcms\Config');

use Booking\Helper\Controller as Helper;
use Kotchasan\Language;

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

$root = __DIR__.'/../';
$th = json_decode(file_get_contents($root.'language/th.json'), true);
$en = json_decode(file_get_contents($root.'language/en.json'), true);
$isThai = static function ($s) {
    return preg_match('/[\x{0E00}-\x{0E7F}]/u', $s) === 1;
};
$norm = static function ($s) {
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode($s, ENT_QUOTES, 'UTF-8')));
};

// Pages a member, a guest or an approver can open. Admin settings pages are
// covered by the same rules but are not listed one by one here.
$memberPages = [
    'templates/index.html',
    'templates/profile-user.html',
    'templates/403.html',
    'templates/404.html',
    'templates/activate.html',
    'templates/booking/booking-recurring.html',
    'templates/booking/booking-recurring-admin.html',
    'templates/booking/my-bookings.html',
    'templates/booking/catalog.html',
    'templates/booking/calendar.html',
    'templates/booking/review.html',
    'ui-handoff/templates/login.html'
];

try {
    // =====================================================================
    echo "\n[1] Every translatable text on the member pages has both languages\n";
    // =====================================================================
    foreach ($memberPages as $page) {
        $html = file_get_contents($root.$page);
        $missing = [];
        // Simple data-i18n elements: <tag ... data-i18n>Text</tag>, no child tags.
        preg_match_all('/data-i18n(?:="([^"]*)")?[^>]*>([^<]*)</u', $html, $m, PREG_SET_ORDER);
        $keys = [];
        foreach ($m as $hit) {
            $key = $norm($hit[1] !== '' ? $hit[1] : $hit[2]);
            if ($key !== '' && preg_match('/\pL/u', $key) && strpos($key, '{') === false) {
                $keys[] = $key;
            }
        }
        // {LNG_...} anywhere: text, placeholder, title, aria-label.
        preg_match_all('/\{LNG_([^}]+)\}/u', $html, $m);
        $keys = array_unique(array_merge($keys, array_map($norm, $m[1])));
        foreach ($keys as $key) {
            if ($isThai($key) ? !isset($en[$key]) : !isset($th[$key])) {
                $missing[] = $key;
            }
        }
        check($missing === [], $page.' has a translation for every key'.($missing ? ' - missing: '.implode(' | ', array_slice($missing, 0, 6)) : ''));

        // Visible attributes are translated only when they carry {LNG_...}.
        // Real attributes only: data-placeholder / data-i18n-placeholder are not shown.
        preg_match_all('/(?<![\w-])(placeholder|title|aria-label)=([\'"])([^\'"]*\pL[^\'"]*)\2/u', $html, $m, PREG_SET_ORDER);
        $raw = [];
        foreach ($m as $hit) {
            $value = $hit[3];
            if (strpos($value, '{LNG_') === false && !preg_match('/^(HH:MM|your@email\.com|TH|EN)$/', $value)) {
                $raw[] = $hit[1].'="'.$value.'"';
            }
        }
        check($raw === [], $page.' has no untranslated placeholder/title/aria-label'.($raw ? ' - '.implode(' | ', array_slice($raw, 0, 4)) : ''));
    }

    // =====================================================================
    echo "\n[2] The strings that used to leak\n";
    // =====================================================================
    foreach (['Cancelling', 'Approving', 'Rejecting', 'Returning', 'Review booking', 'Remember me', 'Required',
              'Permission required', 'Unauthorized', 'Forbidden', 'This booking can no longer be edited',
              'You are not allowed to approve this step', 'Edit Profile', 'Access Denied', 'Page Not Found',
              'Switch language', 'Toggle theme', 'Search room or room number...'] as $key) {
        check(isset($th[$key]) && $isThai($th[$key]), '"'.$key.'" shows in Thai ('.($th[$key] ?? 'missing').')');
    }
    check(($en['มหาวิทยาลัยเชียงใหม่'] ?? '') === 'Chiang Mai University', 'the header subtitle has an English version');
    foreach (['สมาชิก' => 'Member', 'ผู้ดูแลระบบ' => 'Administrator', 'เจ้าหน้าที่' => 'Staff'] as $status => $english) {
        check(($en[$status] ?? '') === $english, 'the default member status '.$status.' reads '.$english.' in English');
    }

    // =====================================================================
    echo "\n[3] Messages with a number in them are translated on the server\n";
    // =====================================================================
    Language::setName('th');
    $thai = Language::replace('Deleted :count user(s) successfully', [':count' => 3]);
    check($thai === 'ลบผู้ใช้ 3 คนเรียบร้อยแล้ว', 'Thai: '.$thai);
    $thai = Language::replace('Too many login attempts. Please try again in :minutes minutes.', [':minutes' => 5]);
    check($isThai($thai) && strpos($thai, '5') !== false, 'the login lockout reads in Thai with its minutes: '.$thai);
    Language::setName('en');
    $english = Language::replace('Deleted :count user(s) successfully', [':count' => 3]);
    check($english === 'Deleted 3 user(s) successfully', 'English: '.$english);

    $settings = file_get_contents($root.'modules/index/controllers/settings.php');
    check(strpos($settings, "'{LNG_Not configured}' : '{LNG_Configured}'") !== false,
        'the Microsoft secret status is sent as {LNG_} so data-text translates it');

} finally {
    Language::setName('th');
}

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILURES\n";
exit($fail === 0 ? 0 : 1);
