<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Manila');

function therapistDurationMinutes($value): int {
    $value=strtolower(trim((string)$value)); $hours=0.0; $minutes=0;
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?)/i',$value,$m)) foreach($m[1] as $n) $hours+=(float)$n;
    if (preg_match_all('/(\d+)\s*(?:minutes?|mins?)/i',$value,$m)) foreach($m[1] as $n) $minutes+=(int)$n;
    if ($hours>0 || $minutes>0) return (int)round($hours*60)+$minutes;
    if (is_numeric($value)) { $n=(float)$value; return $n>3?(int)round($n):(int)round($n*60); }
    return 60;
}
function therapistOperatingMinutes($time,int $openingHour): ?int {
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/',trim((string)$time),$m)) return null;
    $h=(int)$m[1]; $min=(int)$m[2]; if($h>23||$min>59)return null; if($h<$openingHour)$h+=24; return $h*60+$min;
}
function therapistJsonError(string $message,int $status=400): void { http_response_code($status); echo json_encode(['error'=>$message]); exit; }

$date=trim((string)($_GET['date']??''));
$time=trim((string)($_GET['time']??''));
$durationText=trim((string)($_GET['duration']??'1 Hour'));
$dateObject=DateTime::createFromFormat('!Y-m-d',$date); $dateErrors=DateTime::getLastErrors();
$validDate=$dateObject && $dateObject->format('Y-m-d')===$date && ($dateErrors===false||(!$dateErrors['warning_count']&&!$dateErrors['error_count']));
if(!$validDate||$time==='')therapistJsonError('A valid date and time are required.');
$openingHour=((int)$dateObject->format('N')>=6)?13:15;
$requestedClock=therapistOperatingMinutes($time,$openingHour); $requestedMinutes=therapistDurationMinutes($durationText);
$requestedStart=strtotime($date.' '.$time);
if($requestedClock===null||$requestedMinutes<=0||$requestedClock<$openingHour*60||$requestedClock+$requestedMinutes>27*60||$requestedStart===false)therapistJsonError('Invalid appointment time or duration.');
$requestedEnd=$requestedStart+$requestedMinutes*60;
$queryEndDate=date('Y-m-d',$requestedEnd);
$previousDate=(clone $dateObject)->modify('-1 day')->format('Y-m-d');

$therapistQuery=$conn->query("SELECT id,name,status FROM therapists WHERE LOWER(TRIM(COALESCE(status,'')))='active' ORDER BY id ASC");
if(!$therapistQuery)therapistJsonError('Unable to load therapists.',500);
$therapists=[]; while($row=$therapistQuery->fetch_assoc())$therapists[(int)$row['id']]=['id'=>(int)$row['id'],'name'=>$row['name'],'status'=>$row['status'],'available'=>true];
$therapistQuery->free();
if(!$therapists){echo json_encode([]);exit;}

// Guest-level assignment records carry each therapist's actual service duration.
$sql="SELECT bt.therapist_id,b.booking_date,b.booking_time,b.duration AS parent_duration,bg.duration AS guest_duration
      FROM booking_therapists bt
      INNER JOIN bookings b ON b.id=bt.booking_id
      LEFT JOIN booking_guests bg ON bg.id=bt.booking_guest_id
      WHERE b.booking_date BETWEEN ? AND ?
        AND LOWER(TRIM(COALESCE(b.status,''))) NOT IN ('cancelled','completed')";
$stmt=$conn->prepare($sql);
if(!$stmt)therapistJsonError('Unable to check therapist schedules.',500);
$stmt->bind_param('ss',$previousDate,$queryEndDate); if(!$stmt->execute()){ $stmt->close(); therapistJsonError('Unable to check therapist schedules.',500); }
$result=$stmt->get_result(); $mappedBookingIds=[];
while($row=$result->fetch_assoc()){
    $id=(int)$row['therapist_id']; if(!isset($therapists[$id]))continue;
    $start=strtotime($row['booking_date'].' '.$row['booking_time']); $minutes=therapistDurationMinutes($row['guest_duration']?:$row['parent_duration']);
    if($start!==false&&$minutes>0&&$requestedStart<$start+$minutes*60&&$start<$requestedEnd)$therapists[$id]['available']=false;
}
$stmt->close();

// Backward compatibility for older bookings saved only in bookings.therapist_id.
$sql="SELECT b.id,b.booking_date,b.therapist_id,b.booking_time,b.duration
      FROM bookings b
      WHERE b.booking_date BETWEEN ? AND ? AND b.therapist_id>0
        AND LOWER(TRIM(COALESCE(b.status,''))) NOT IN ('cancelled','completed')
        AND NOT EXISTS (SELECT 1 FROM booking_therapists bt WHERE bt.booking_id=b.id)";
$stmt=$conn->prepare($sql);
if(!$stmt)therapistJsonError('Unable to check older therapist assignments.',500);
$stmt->bind_param('ss',$previousDate,$queryEndDate); if(!$stmt->execute()){ $stmt->close(); therapistJsonError('Unable to check older therapist assignments.',500); }
$result=$stmt->get_result();
while($row=$result->fetch_assoc()){
    $id=(int)$row['therapist_id']; if(!isset($therapists[$id]))continue;
    $start=strtotime($row['booking_date'].' '.$row['booking_time']); $minutes=therapistDurationMinutes($row['duration']);
    if($start!==false&&$minutes>0&&$requestedStart<$start+$minutes*60&&$start<$requestedEnd)$therapists[$id]['available']=false;
}
$stmt->close();

echo json_encode(array_values($therapists),JSON_UNESCAPED_UNICODE);
