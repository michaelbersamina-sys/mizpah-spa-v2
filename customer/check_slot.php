<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Manila');

function slotDurationMinutes($duration) {
    $d = strtolower(trim((string)$duration));
    $hours = 0.0; $minutes = 0;
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?|hr)\b/i', $d, $m)) $hours = (float)$m[1];
    if (preg_match('/(\d+)\s*(?:minutes?|mins?|min)\b/i', $d, $m)) $minutes = (int)$m[1];
    if ($hours > 0 || $minutes > 0) return (int)round($hours * 60) + $minutes;
    if (is_numeric($d)) { $n=(float)$d; return $n>3?(int)round($n):(int)round($n*60); }
    return 60;
}
function slotRespond($available, $remaining, $reason='') {
    echo json_encode(['available'=>(bool)$available, 'remaining'=>(int)$remaining, 'reason'=>$reason]);
    exit;
}

$date = trim((string)($_GET['date'] ?? ''));
$time = trim((string)($_GET['time'] ?? ''));
$roomType = trim((string)($_GET['room_type'] ?? 'Single Room'));
$pax = max(1, min(4, (int)($_GET['pax'] ?? 1)));
if ($roomType === 'Couple Room') $pax = 2;
$dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
$errors = DateTimeImmutable::getLastErrors();
$validDate = $dateObject && $dateObject->format('Y-m-d') === $date && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']));
if (!$validDate || !in_array($roomType, ['Single Room','Couple Room'], true) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) || ($roomType === 'Single Room' && $pax > 4)) slotRespond(false, 0, 'invalid_request');

$openingHour = (int)$dateObject->format('N') >= 6 ? 13 : 15;
[$hour, $minute] = array_map('intval', explode(':', $time));
$operatingStart = $hour * 60 + $minute;
if ($hour < $openingHour) $operatingStart += 1440;
$raw = json_decode((string)($_GET['guest_durations'] ?? '[]'), true);
$durations = [];
if (is_array($raw)) foreach ($raw as $value) { $mins = slotDurationMinutes($value); if ($mins > 0) $durations[] = $mins; }
if (!$durations) {
    $fallback = slotDurationMinutes($_GET['duration'] ?? '1 Hour');
    $durations = array_fill(0, $pax, $fallback);
}
if ($roomType === 'Couple Room') $durations = array_slice($durations, 0, 2);
while (count($durations) < $pax) $durations[] = slotDurationMinutes($_GET['duration'] ?? '1 Hour');
if ($roomType === 'Single Room') $durations = array_slice($durations, 0, $pax);
$maxServiceDuration = max($durations);
if ($operatingStart < $openingHour * 60 || $operatingStart + $maxServiceDuration > 27 * 60) slotRespond(false, 0, 'outside_operating_hours');

// A slot before the opening hour belongs to the next calendar date of the operating day.
$actualStart = $dateObject->setTime($hour, $minute);
if ($hour < $openingHour) $actualStart = $actualStart->modify('+1 day');
$candidateStart = $actualStart->getTimestamp();
$candidateEnd = $candidateStart + ($maxServiceDuration + 15) * 60;
$actualDate = $actualStart->format('Y-m-d');
$queryEndDate = date('Y-m-d', $candidateEnd);
$previousDate = $dateObject->modify('-1 day')->format('Y-m-d');

$sql = "SELECT b.id,b.booking_date,b.booking_time,b.duration AS parent_duration,
               COALESCE(NULLIF(b.pax,0),1) AS pax,b.created_by,
               bg.id AS guest_id,bg.duration AS guest_duration
        FROM bookings b
        LEFT JOIN booking_guests bg ON bg.booking_id=b.id
        WHERE b.booking_date BETWEEN ? AND ? AND b.room_type=?
          AND LOWER(TRIM(COALESCE(b.status,''))) NOT IN ('cancelled','completed')";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) slotRespond(false, 0, 'database_error');
mysqli_stmt_bind_param($stmt, 'sss', $previousDate, $queryEndDate, $roomType);
if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); slotRespond(false, 0, 'database_error'); }
$result = mysqli_stmt_get_result($stmt);
$bookings = [];
while ($row = mysqli_fetch_assoc($result)) {
    $id = (int)$row['id'];
    if (!isset($bookings[$id])) {
        $bookings[$id] = [
            'start' => strtotime($row['booking_date'] . ' ' . $row['booking_time']),
            'parent' => slotDurationMinutes($row['parent_duration'] ?? '1 Hour'),
            'pax' => max(1, (int)$row['pax']),
            'created_by' => strtolower(trim((string)($row['created_by'] ?? ''))),
            'durations' => []
        ];
    }
    if (!empty($row['guest_id'])) $bookings[$id]['durations'][] = slotDurationMinutes($row['guest_duration'] ?: $row['parent_duration']);
}
mysqli_stmt_close($stmt);

$capacity = $roomType === 'Couple Room' ? 1 : 4;
$events = [];
$existingAtStart = 0;
foreach ($bookings as $booking) {
    if ($booking['start'] === false) continue;
    $existingDurations = array_values(array_filter($booking['durations'], static fn($v) => $v > 0));
    if (!$existingDurations) $existingDurations = array_fill(0, $booking['pax'], max(1, $booking['parent']));
    $existingPrep = $booking['created_by'] === 'admin' ? 0 : 15;
    if ($roomType === 'Couple Room') $existingDurations = [max($existingDurations)];
    foreach ($existingDurations as $duration) {
        $end = $booking['start'] + ((int)$duration + $existingPrep) * 60;
        $events[] = [$booking['start'], 1];
        $events[] = [$end, -1];
        if ($booking['start'] <= $candidateStart && $candidateStart < $end) $existingAtStart++;
    }
}
if ($roomType === 'Couple Room') $durations = [max($durations)];
$candidateMaxEnd = $candidateStart;
foreach ($durations as $duration) {
    $end = $candidateStart + ((int)$duration + 15) * 60;
    $events[] = [$candidateStart, 1];
    $events[] = [$end, -1];
    $candidateMaxEnd = max($candidateMaxEnd, $end);
}
usort($events, static function ($a, $b) { return $a[0] <=> $b[0] ?: $a[1] <=> $b[1]; });
$active = 0; $available = true; $i = 0; $count = count($events);
while ($i < $count) {
    $at = $events[$i][0]; $delta = 0;
    while ($i < $count && $events[$i][0] === $at) { $delta += $events[$i][1]; $i++; }
    $active += $delta;
    // Only assess the interval while this requested booking occupies a room.
    if ($at >= $candidateStart && $at < $candidateMaxEnd && $active > $capacity) { $available = false; break; }
}
$remaining = max(0, $capacity - $existingAtStart);
slotRespond($available, $remaining, $available ? 'available' : 'room_capacity');
?>