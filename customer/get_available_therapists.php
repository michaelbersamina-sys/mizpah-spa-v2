<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Manila');
function therapistDurationMinutes($value) {
    $d = strtolower(trim((string)$value)); $hours = 0.0; $minutes = 0;
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?|hr)\b/i', $d, $m)) $hours = (float)$m[1];
    if (preg_match('/(\d+)\s*(?:minutes?|mins?|min)\b/i', $d, $m)) $minutes = (int)$m[1];
    if ($hours > 0 || $minutes > 0) return (int)round($hours * 60) + $minutes;
    if (is_numeric($d)) { $n=(float)$d; return $n > 3 ? (int)round($n) : (int)round($n * 60); }
    return 60;
}
$date = trim((string)($_GET['date'] ?? ''));
$time = trim((string)($_GET['time'] ?? ''));
$duration = therapistDurationMinutes($_GET['duration'] ?? '1 Hour');
$dateObject = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Manila'));
$errors = DateTimeImmutable::getLastErrors();
$validDate = $dateObject && $dateObject->format('Y-m-d') === $date && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']));
if (!$validDate || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) || $duration <= 0) { echo json_encode([]); exit; }
$openingHour = (int)$dateObject->format('N') >= 6 ? 13 : 15;
[$hour, $minute] = array_map('intval', explode(':', $time));
$operatingStart = $hour * 60 + $minute + ($hour < $openingHour ? 1440 : 0);
if ($operatingStart < $openingHour * 60 || $operatingStart + $duration > 27 * 60) { echo json_encode([]); exit; }
$candidate = $dateObject->setTime($hour, $minute);
if ($hour < $openingHour) $candidate = $candidate->modify('+1 day');
$candidateStart = $candidate->getTimestamp();
$candidateEnd = $candidateStart + $duration * 60;
$actualDate = $candidate->format('Y-m-d');
$queryEndDate = date('Y-m-d', $candidateEnd);
$previousDate = $dateObject->modify('-1 day')->format('Y-m-d');

$therapists = [];
$result = mysqli_query($conn, "SELECT id,name FROM therapists WHERE status='Active' ORDER BY name ASC");
if (!$result) { echo json_encode([]); exit; }
while ($row = mysqli_fetch_assoc($result)) $therapists[(string)$row['id']] = ['id'=>(int)$row['id'], 'name'=>$row['name'], 'available'=>true];
if (!$therapists) { echo json_encode([]); exit; }

$sql = "SELECT b.booking_date,b.booking_time,b.duration AS parent_duration,b.therapist_id,
               bt.therapist_id AS assigned_therapist_id,bg.duration AS guest_duration
        FROM bookings b
        LEFT JOIN booking_therapists bt ON bt.booking_id=b.id
        LEFT JOIN booking_guests bg ON bg.id=bt.booking_guest_id
        WHERE b.booking_date BETWEEN ? AND ?
          AND LOWER(TRIM(COALESCE(b.status,''))) NOT IN ('cancelled','completed')";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) { echo json_encode(array_values($therapists)); exit; }
mysqli_stmt_bind_param($stmt, 'ss', $previousDate, $queryEndDate);
if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); echo json_encode(array_values($therapists)); exit; }
$res = mysqli_stmt_get_result($stmt);
while ($booking = mysqli_fetch_assoc($res)) {
    $busyStart = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
    if ($busyStart === false) continue;
    $assignedId = (int)($booking['assigned_therapist_id'] ?? 0);
    $therapistId = $assignedId > 0 ? $assignedId : (int)($booking['therapist_id'] ?? 0);
    if ($therapistId <= 0 || !isset($therapists[(string)$therapistId])) continue;
    $busyDuration = $assignedId > 0 && trim((string)($booking['guest_duration'] ?? '')) !== ''
        ? therapistDurationMinutes($booking['guest_duration'])
        : therapistDurationMinutes($booking['parent_duration'] ?? '1 Hour');
    $busyEnd = $busyStart + $busyDuration * 60;
    if ($candidateStart < $busyEnd && $busyStart < $candidateEnd) $therapists[(string)$therapistId]['available'] = false;
}
mysqli_stmt_close($stmt);
echo json_encode(array_values($therapists), JSON_UNESCAPED_UNICODE);
?>