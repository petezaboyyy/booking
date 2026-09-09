<?php

$baseUrl = 'http://localhost/api/booking/calendar';

function fetchCalendar(string $query): array {
    global $baseUrl;
    $url = $baseUrl . '?' . $query;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) {
        fwrite(STDERR, "HTTP request failed for $url with code $httpCode\n");
        exit(1);
    }
    $json = json_decode($resp, true);
    if (isset($json['data']['data']) && is_array($json['data']['data'])) {
        return $json['data']['data'];
    }
    if (isset($json['data']) && is_array($json['data'])) {
        return $json['data'];
    }
    fwrite(STDERR, "Missing data in response for $url\n");
    exit(1);
}

echo "Testing calendar API with no filter...\n";
$allEvents = fetchCalendar('start=2026-09-01&end=2026-09-30');
echo "Total events found without filter: " . count($allEvents) . "\n";
if (empty($allEvents)) {
    fwrite(STDERR, "Expected at least 1 event in calendar test data\n");
    exit(1);
}

// Verify fields exist on event items
$first = $allEvents[0];
if (!array_key_exists('room_number', $first) || !array_key_exists('room_name', $first) || !array_key_exists('member_name', $first)) {
    fwrite(STDERR, "Event objects missing required room_number, room_name, or member_name fields\n");
    exit(1);
}
echo "Event schema verification passed (room_number, room_name, member_name present).\n";

echo "Testing calendar API with room=R-0002...\n";
$r2Events = fetchCalendar('start=2026-09-01&end=2026-09-30&room=R-0002');
echo "Events found for R-0002: " . count($r2Events) . "\n";
if (empty($r2Events)) {
    fwrite(STDERR, "Expected events for R-0002\n");
    exit(1);
}
foreach ($r2Events as $ev) {
    if (stripos($ev['room_number'], 'R-0002') === false && stripos($ev['room_name'], 'R-0002') === false && stripos($ev['description'], 'R-0002') === false) {
        fwrite(STDERR, "Unexpected event in R-0002 filter: " . json_encode($ev) . "\n");
        exit(1);
    }
}
echo "Filter room=R-0002 passed.\n";

echo "Testing calendar API with room=R-0001...\n";
$r1Events = fetchCalendar('start=2026-09-01&end=2026-09-30&room=R-0001');
echo "Events found for R-0001: " . count($r1Events) . "\n";
if (empty($r1Events)) {
    fwrite(STDERR, "Expected events for R-0001\n");
    exit(1);
}
foreach ($r1Events as $ev) {
    if (stripos($ev['room_number'], 'R-0001') === false && stripos($ev['room_name'], 'R-0001') === false && stripos($ev['description'], 'R-0001') === false) {
        fwrite(STDERR, "Unexpected event in R-0001 filter: " . json_encode($ev) . "\n");
        exit(1);
    }
}
echo "Filter room=R-0001 passed.\n";

echo "Testing calendar API with nonexistent room query...\n";
$noneEvents = fetchCalendar('start=2026-09-01&end=2026-09-30&room=NONEXISTENT_ROOM_XYZ_9999');
if (count($noneEvents) !== 0) {
    fwrite(STDERR, "Expected 0 events for nonexistent room query\n");
    exit(1);
}
echo "Nonexistent room filter passed.\n";

echo "Testing alternative query parameter q=R-0002...\n";
$qEvents = fetchCalendar('start=2026-09-01&end=2026-09-30&q=R-0002');
if (count($qEvents) !== count($r2Events)) {
    fwrite(STDERR, "Mismatch between room and q query params\n");
    exit(1);
}
echo "Alternative parameter q=R-0002 passed.\n";

echo "Testing single-digit room=1 filter (ensures topic with digit 1 does not leak Room 2 events)...\n";
$digit1Events = fetchCalendar('start=2026-09-01&end=2026-09-30&room=1');
if (count($digit1Events) !== 6) {
    fwrite(STDERR, "Expected exactly 6 events for room=1, got " . count($digit1Events) . "\n");
    exit(1);
}
foreach ($digit1Events as $ev) {
    if (stripos($ev['room_number'], '1') === false && stripos($ev['room_name'], '1') === false) {
        fwrite(STDERR, "Event from wrong room leaked into room=1: " . json_encode($ev) . "\n");
        exit(1);
    }
}
echo "Single-digit room=1 filter passed without topic contamination.\n";

echo "Testing single-digit room=2 filter (ensures topic with digit 2 does not leak Room 1 events)...\n";
$digit2Events = fetchCalendar('start=2026-09-01&end=2026-09-30&room=2');
if (count($digit2Events) !== 2) {
    fwrite(STDERR, "Expected exactly 2 events for room=2, got " . count($digit2Events) . "\n");
    exit(1);
}
foreach ($digit2Events as $ev) {
    if (stripos($ev['room_number'], '2') === false && stripos($ev['room_name'], '2') === false) {
        fwrite(STDERR, "Event from wrong room leaked into room=2: " . json_encode($ev) . "\n");
        exit(1);
    }
}
echo "Single-digit room=2 filter passed without topic contamination.\n";

echo "Testing multi-word room query room='ห้อง 2'...\n";
$thaiWordEvents = fetchCalendar('start=2026-09-01&end=2026-09-30&room=' . urlencode('ห้อง 2'));
if (count($thaiWordEvents) !== 2) {
    fwrite(STDERR, "Expected exactly 2 events for room='ห้อง 2', got " . count($thaiWordEvents) . "\n");
    exit(1);
}
echo "Multi-word room='ห้อง 2' filter passed.\n";

echo "\nALL CALENDAR API FILTER TESTS PASSED SUCCESSFULLY!\n";
