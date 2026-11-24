<?php
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Manila');

function slotRespond(bool $available, int $remaining, string $reason = ''): void {
    echo json_encode(['available'=>$available, 'remaining'=>$remaining, 'reason'=>$reason]);
    exit;
}
function slotDurationMinutes($value): int {
    $value = strtolower(trim((string)$value));
    $hours = 0.0; $minutes = 0;
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?)/i', $value, $matches)) {
        foreach ($matches[1] as $n) $hours += (float)$n;
    }
    if (preg_match_all('/(\d+)\s*(?:minutes?|mins?)/i', $value, $matches)) {
        foreach ($matches[1] as $n) $minutes += (int)$n;
    }
    if ($hours > 0 || $minutes > 0) return (int)round($hours * 60) + $minutes;
    if (is_numeric($value)) { $n=(float)$value; return $n > 3 ? (int)round($n) : (int)round($n*60); }
    return 60;
}
function slotOperatingMinutes($time, int $openingHour): ?int {
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim((string)$time), $m)) return null;
    $hour=(int)$m[1]; $minute=(int)$m[2];
    if ($hour>23 || $minute>59) return null;
    if ($hour<$openingHour) $hour+=24;
    return $hour*60+$minute;
}

$date=trim((string)($_GET['date'] ?? ''));
$time=trim((string)($_GET['time'] ?? ''));
$duration=trim((string)($_GET['duration'] ?? '1 Hour'));
$roomType=trim((string)($_GET['room_type'] ?? ''));
$pax=max(1,(int)($_GET['pax'] ?? 1));
$dateObject=DateTime::createFromFormat('!Y-m-d',$date);
$dateErrors=DateTime::getLastErrors();
$validDate=$dateObject && $dateObject->format('Y-m-d')===$date && ($dateErrors===false || (!$dateErrors['warning_count'] && !$dateErrors['error_count']));
if (!$validDate || !in_array($roomType,['Single Room','Couple Room'],true) || ($roomType==='Single Room' && $pax>4) || ($roomType==='Couple Room' && $pax!==2)) slotRespond(false,0,'invalid_request');
$openingHour=((int)$dateObject->format('N')>=6)?13:15;
$requestedClock=slotOperatingMinutes($time,$openingHour);
$requestedDuration=slotDurationMinutes($duration);
$requestedStart=strtotime($date.' '.$time);
if ($requestedClock===null || $requestedDuration<=0 || $requestedClock<$openingHour*60 || $requestedClock+$requestedDuration>27*60 || $requestedStart===false) slotRespond(false,0,'invalid_time');
$requestedEnd=$requestedStart+$requestedDuration*60;
$queryEndDate=date('Y-m-d',$requestedEnd);
$previousDate=(clone $dateObject)->modify('-1 day')->format('Y-m-d');

$sql="SELECT b.id,b.booking_date,b.booking_time,b.duration AS parent_duration,COALESCE(NULLIF(b.pax,0),1) AS pax,b.created_by,bg.duration AS guest_duration
      FROM bookings b LEFT JOIN booking_guests bg ON bg.booking_id=b.id
      WHERE b.booking_date BETWEEN ? AND ? AND b.room_type=?
        AND LOWER(TRIM(COALESCE(b.status,''))) NOT IN ('cancelled','completed')
      ORDER BY b.id";
$stmt=$conn->prepare($sql);
if (!$stmt) { http_response_code(500); slotRespond(false,0,'database_error'); }
$stmt->bind_param('sss',$previousDate,$queryEndDate,$roomType);
if (!$stmt->execute()) { $stmt->close(); http_response_code(500); slotRespond(false,0,'database_error'); }
$result=$stmt->get_result();
$bookings=[];
while ($row=$result->fetch_assoc()) {
    $id=(int)$row['id'];
    if (!isset($bookings[$id])) $bookings[$id]=['date'=>$row['booking_date'],'time'=>$row['booking_time'],'parent'=>$row['parent_duration'],'pax'=>max(1,(int)$row['pax']),'created_by'=>strtolower(trim((string)($row['created_by'] ?? ''))),'durations'=>[]];
    if (trim((string)($row['guest_duration'] ?? ''))!=='') {
        $minutes=slotDurationMinutes($row['guest_duration']);
        if ($minutes>0) $bookings[$id]['durations'][]=$minutes;
    }
}
$stmt->close();

$events=[];
foreach ($bookings as $booking) {
    // Booking date/time are stored as actual calendar timestamps, including after-midnight slots.
    $start=strtotime($booking['date'].' '.$booking['time']);
    if ($start===false) continue;
    $fallback=slotDurationMinutes($booking['parent'] ?: '1 Hour');
    $durations=array_values(array_filter($booking['durations'],static fn($v)=>$v>0));
    while (count($durations)<$booking['pax']) $durations[]=$fallback;
    if (!$durations) $durations=[$fallback];
    if ($roomType==='Couple Room') $durations=[max($durations)];
    // Existing online bookings keep their 15-minute room preparation buffer.
    // Walk-in bookings have no automatic preparation buffer.
    $prepMinutes=$booking['created_by']==='admin'?0:15;
    foreach ($durations as $minutes) {
        $events[]=['time'=>$start,'delta'=>1];
        $events[]=['time'=>$start+($minutes+$prepMinutes)*60,'delta'=>-1];
    }
}
usort($events,static function($a,$b){ return $a['time']===$b['time'] ? $a['delta']<=>$b['delta'] : $a['time']<=>$b['time']; });
$capacity=$roomType==='Couple Room'?1:4;
$requestedRooms=$roomType==='Couple Room'?1:$pax;
$occupied=0; $remaining=$capacity;
foreach ($events as $event) {
    // Apply existing occupancy changes before evaluating the requested interval at that instant.
    $occupied += $event['delta'];
    if ($event['time'] >= $requestedStart && $event['time'] < $requestedEnd) {
        $remaining=min($remaining,max(0,$capacity-$occupied));
        if ($occupied+$requestedRooms>$capacity) slotRespond(false,max(0,$capacity-$occupied),'room_capacity');
    }
}
// Also evaluate at requested start when no existing event occurs exactly there.
$occupiedAtStart=0;
foreach ($events as $event) if ($event['time'] <= $requestedStart) $occupiedAtStart += $event['delta'];
if ($occupiedAtStart+$requestedRooms>$capacity) slotRespond(false,max(0,$capacity-$occupiedAtStart),'room_capacity');
// Check every event in the requested interval, including boundaries where occupancy changes.
$occupied=0;
foreach ($events as $event) {
    $occupied += $event['delta'];
    if ($event['time'] > $requestedStart && $event['time'] < $requestedEnd && $occupied+$requestedRooms>$capacity) slotRespond(false,max(0,$capacity-$occupied),'room_capacity');
}
slotRespond(true,max(0,$capacity-$occupiedAtStart),'available');
