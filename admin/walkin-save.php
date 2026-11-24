<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

function bookingError(string $message): void {
    http_response_code(400);
    exit('BOOKING ERROR: ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
        '<br><a href="walkin-booking.php">Go back to walk-in booking</a>');
}
function walkinDurationMinutes($duration): int {
    $duration = strtolower(trim((string)$duration));
    $hours = 0.0;
    $minutes = 0;
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?)/i', $duration, $hm)) {
        foreach ($hm[1] as $value) $hours += (float)$value;
    }
    if (preg_match_all('/(\d+)\s*(?:minutes?|mins?)/i', $duration, $mm)) {
        foreach ($mm[1] as $value) $minutes += (int)$value;
    }
    if ($hours > 0 || $minutes > 0) return (int)round($hours * 60) + $minutes;
    if (is_numeric($duration)) { $n=(float)$duration; return $n>3?(int)round($n):(int)round($n*60); }
    return 60;
}
function walkinOperatingMinutes($time, int $openingHour): ?int {
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim((string)$time), $m)) return null;
    $h=(int)$m[1]; $min=(int)$m[2];
    if ($h>23 || $min>59) return null;
    if ($h<$openingHour) $h+=24;
    return $h*60+$min;
}

$roomType = trim((string)($_POST['room_type'] ?? ''));
$date = trim((string)($_POST['booking_date'] ?? ''));
$time = trim((string)($_POST['booking_time'] ?? ''));
$pax = (int)($_POST['pax'] ?? 1);
$payment = trim((string)($_POST['payment_method'] ?? 'Cash'));
$notes = trim((string)($_POST['notes'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$rawSelections = json_decode((string)($_POST['guest_selections'] ?? '[]'), true);
$guestNames = $_POST['guest_name'] ?? [];
$guestPhones = $_POST['guest_phone'] ?? [];

if ($roomType === 'Couple Room') $pax = 2;
if (!in_array($roomType, ['Single Room','Couple Room'], true)) bookingError('Please select a valid room type.');
if ($pax < 1 || $pax > 4 || ($roomType === 'Couple Room' && $pax !== 2)) bookingError('Guest count must be between 1 and 4.');
if (!in_array($payment, ['Cash','GCash'], true)) bookingError('Please select a valid payment method.');
if (!is_array($rawSelections) || count($rawSelections) !== $pax) bookingError('Please select a service and duration for every guest.');
if (!is_array($guestNames) || count($guestNames) !== $pax || !is_array($guestPhones)) bookingError('Please provide every guest name and Guest 1 contact number.');
if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)) bookingError('Please enter a valid email address or leave the email field blank.');

$guests = [];
for ($i=0; $i<$pax; $i++) {
    $name = trim((string)($guestNames[$i] ?? ''));
    if (!preg_match('/^(?=.{2,100}$)[\p{L}][\p{L}\s.\'-]*$/u', $name)) bookingError('Please enter a valid full name for Guest '.($i+1).'.');
    $phone = $i === 0 ? preg_replace('/\s+/', '', trim((string)($guestPhones[0] ?? ''))) : '';
    if ($i === 0 && !preg_match('/^09\d{9}$/', $phone)) bookingError('Please enter Guest 1\'s valid 11-digit Philippine mobile number starting with 09.');
    $guests[] = ['name'=>$name,'phone'=>$phone];
}

$dateObject = DateTime::createFromFormat('!Y-m-d', $date);
$errors = DateTime::getLastErrors();
if (!$dateObject || $dateObject->format('Y-m-d') !== $date || ($errors && ($errors['warning_count'] || $errors['error_count']))) bookingError('Please select a valid appointment date.');
if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) bookingError('Please select a valid appointment time.');
if ($date < date('Y-m-d')) bookingError('That appointment date has already passed.');

$openingHour = ((int)$dateObject->format('N') >= 6) ? 13 : 15;
$startMinute = walkinOperatingMinutes($time, $openingHour);
$requestedTimestamp = strtotime($date . ' ' . $time);
$previousBookingDate = (clone $dateObject)->modify('-1 day')->format('Y-m-d');
$appointmentDate = $date;
if ((int)substr($time,0,2) < $openingHour) {
    $next = clone $dateObject; $next->modify('+1 day'); $appointmentDate = $next->format('Y-m-d');
}
if ($startMinute === null || $requestedTimestamp === false || $requestedTimestamp <= time()) bookingError('That appointment time has already passed.');

$selections=[]; $totalPrice=0.0; $maxMinutes=0; $maxDuration=''; $serviceLabels=[]; $addonLabels=[]; $assigned=[]; $assignedDuration=[];
foreach ($rawSelections as $index=>$item) {
    $guestNo=$index+1;
    if (!is_array($item)) bookingError('Invalid service selection for Guest '.$guestNo.'.');
    $serviceId=(int)($item['service_id'] ?? 0);
    $duration=trim((string)($item['duration'] ?? ''));
    if ($serviceId<=0 || $duration==='') bookingError('Please select a service and duration for Guest '.$guestNo.'.');

    $serviceQuery=$conn->prepare("SELECT s.service_name,s.category,sd.duration,sd.price FROM services s INNER JOIN service_durations sd ON sd.service_id=s.id WHERE s.id=? AND s.category IN ('Massage','Package','Promo') AND sd.duration=? LIMIT 1");
    if (!$serviceQuery) bookingError('Could not verify service selection.');
    $serviceQuery->bind_param('is',$serviceId,$duration); $serviceQuery->execute();
    $serviceRow=$serviceQuery->get_result()->fetch_assoc(); $serviceQuery->close();
    if (!$serviceRow) bookingError('The selected service or duration for Guest '.$guestNo.' is no longer available.');
    $minutes=walkinDurationMinutes($serviceRow['duration']);
    if ($minutes<=0) bookingError('Invalid duration for Guest '.$guestNo.'.');

    $guestAddons=[]; $addonTotal=0.0;
    $addonIds=array_values(array_unique(array_filter(array_map('intval',(array)($item['addons'] ?? [])),static fn($v)=>$v>0)));
    foreach ($addonIds as $addonId) {
        $addonQuery=$conn->prepare("SELECT id,service_name,price FROM services WHERE id=? AND category='Add-ons' LIMIT 1");
        if (!$addonQuery) bookingError('Could not verify add-on selection.');
        $addonQuery->bind_param('i',$addonId); $addonQuery->execute();
        $addonRow=$addonQuery->get_result()->fetch_assoc(); $addonQuery->close();
        if (!$addonRow) bookingError('An add-on selected for Guest '.$guestNo.' is no longer available.');
        $addonPrice=(float)$addonRow['price']; $addonTotal+=$addonPrice;
        $guestAddons[]=['id'=>(int)$addonRow['id'],'name'=>$addonRow['service_name'],'price'=>$addonPrice];
        $addonLabels[]=$addonRow['service_name'];
    }

    $therapistId=(int)($item['therapist_id'] ?? 0);
    if ($therapistId>0) {
        $therapistQuery=$conn->prepare("SELECT id FROM therapists WHERE id=? AND status='Active' LIMIT 1");
        if (!$therapistQuery) bookingError('Could not verify therapist selection.');
        $therapistQuery->bind_param('i',$therapistId); $therapistQuery->execute();
        $therapistRow=$therapistQuery->get_result()->fetch_assoc(); $therapistQuery->close();
        if (!$therapistRow) bookingError('The selected therapist for Guest '.$guestNo.' is no longer active.');
        if (in_array($therapistId,$assigned,true)) bookingError('Please choose a different therapist for each guest.');
        $assigned[]=$therapistId; $assignedDuration[$therapistId]=$minutes;
    }

    $servicePrice=(float)$serviceRow['price'];
    $totalPrice += $servicePrice + $addonTotal;
    if ($minutes>$maxMinutes) { $maxMinutes=$minutes; $maxDuration=$serviceRow['duration']; }
    $serviceLabels[]=$serviceRow['service_name'];
    $selections[]=['guest_number'=>$guestNo,'service_id'=>$serviceId,'service'=>$serviceRow['service_name'],'duration'=>$serviceRow['duration'],'minutes'=>$minutes,'price'=>$servicePrice,'addons'=>$guestAddons,'therapist_id'=>$therapistId];
}

if ($maxMinutes<=0 || $startMinute+$maxMinutes>27*60) bookingError('The appointment must finish by 3:00 AM.');

// Recheck room occupancy using each guest's actual service duration.
// Couple Room is one physical room occupied until the longest guest service ends.
// Single Room uses one room per guest, with each room released at that guest's own end time.
$roomQueryEndDate = date('Y-m-d', $requestedTimestamp + $maxMinutes * 60);
$roomQuery=$conn->prepare(
    "SELECT b.id,b.booking_date,b.booking_time,b.duration AS parent_duration,
            COALESCE(NULLIF(b.pax,0),1) AS pax,b.created_by,bg.duration AS guest_duration
     FROM bookings b
     LEFT JOIN booking_guests bg ON bg.booking_id=b.id
     WHERE b.booking_date BETWEEN ? AND ? AND b.room_type=?
       AND LOWER(TRIM(COALESCE(b.status,''))) NOT IN ('cancelled','completed')
     ORDER BY b.id"
);
if (!$roomQuery) bookingError('Could not verify room availability.');
$roomQuery->bind_param('sss',$previousBookingDate,$roomQueryEndDate,$roomType);
$roomQuery->execute();
$roomResult=$roomQuery->get_result();

$existingBookings=[];
while ($row=$roomResult->fetch_assoc()) {
    $id=(int)$row['id'];
    if (!isset($existingBookings[$id])) {
        $existingBookings[$id]=[
            'date'=>$row['booking_date'],
            'time'=>$row['booking_time'],
            'parent_duration'=>$row['parent_duration'],
            'pax'=>max(1,(int)$row['pax']),
            'created_by'=>strtolower(trim((string)($row['created_by'] ?? ''))),
            'guest_durations'=>[]
        ];
    }
    if (trim((string)($row['guest_duration'] ?? ''))!=='') {
        $existingBookings[$id]['guest_durations'][]=walkinDurationMinutes($row['guest_duration']);
    }
}
$roomQuery->close();

// Convert all existing room occupancy into intervals measured on the operating-day clock.
$events=[];
foreach ($existingBookings as $booking) {
    $existingStart=strtotime($booking['date'].' '.$booking['time']);
    if ($existingStart===false) continue;

    $durations=array_values(array_filter($booking['guest_durations'],static fn($v)=>$v>0));
    $fallback=walkinDurationMinutes($booking['parent_duration'] ?: '1 Hour');
    // If a legacy/partial booking has fewer guest rows than pax, account for the missing guests too.
    while (count($durations)<$booking['pax']) $durations[]=$fallback;
    if (!$durations) $durations=[$fallback];

    if ($roomType==='Couple Room') {
        // Two guests share one Couple Room; it remains occupied until both services finish.
        $existingRoomMinutes=max($durations);
        $existingPrep=$booking['created_by']==='admin'?0:15;
        $events[]=['time'=>$existingStart,'delta'=>1,'new'=>false];
        $events[]=['time'=>$existingStart+($existingRoomMinutes+$existingPrep)*60,'delta'=>-1,'new'=>false];
    } else {
        // Each Single Room guest occupies one room for that guest's own duration.
        $existingPrep=$booking['created_by']==='admin'?0:15;
        foreach ($durations as $guestMinutes) {
            $events[]=['time'=>$existingStart,'delta'=>1,'new'=>false];
            $events[]=['time'=>$existingStart+($guestMinutes+$existingPrep)*60,'delta'=>-1,'new'=>false];
        }
    }
}

// Add the requested walk-in intervals. No automatic preparation buffer applies to walk-ins.
if ($roomType==='Couple Room') {
    $events[]=['time'=>$requestedTimestamp,'delta'=>1,'new'=>true];
    $events[]=['time'=>$requestedTimestamp+$maxMinutes*60,'delta'=>-1,'new'=>true];
} else {
    foreach ($selections as $selection) {
        $guestMinutes=(int)$selection['minutes'];
        $events[]=['time'=>$requestedTimestamp,'delta'=>1,'new'=>true];
        $events[]=['time'=>$requestedTimestamp+$guestMinutes*60,'delta'=>-1,'new'=>true];
    }
}

// End events (-1) are processed before start events (+1) at the same minute,
// allowing a room to be reused immediately when the previous service ends.
usort($events,static function($a,$b) {
    if ($a['time']!==$b['time']) return $a['time']<=>$b['time'];
    return $a['delta']<=>$b['delta'];
});

$occupiedRooms=0;
$newBookingActive=0;
$roomConflict=false;
$capacity=$roomType==='Couple Room'?1:4;
foreach ($events as $event) {
    $occupiedRooms += $event['delta'];
    if ($event['new']) $newBookingActive += $event['delta'];
    if ($occupiedRooms>$capacity && $newBookingActive>0) {
        $roomConflict=true;
        break;
    }
}
if ($roomConflict) {
    bookingError('That time overlaps another appointment or exceeds the available room capacity. Please choose another time.');
}

// Validate therapist conflicts against both current guest-level assignments and legacy parent assignments.
if ($assigned) {
    $conflict=false;
    $q=$conn->prepare("SELECT bt.therapist_id,b.booking_date,b.booking_time,b.duration AS parent_duration,bg.duration AS guest_duration FROM booking_therapists bt INNER JOIN bookings b ON b.id=bt.booking_id LEFT JOIN booking_guests bg ON bg.id=bt.booking_guest_id WHERE b.booking_date BETWEEN ? AND ? AND LOWER(TRIM(COALESCE(b.status,''))) NOT IN ('cancelled','completed')");
    if ($q) {
        $therapistQueryEndDate = date('Y-m-d', $requestedTimestamp + $maxMinutes * 60);
        $q->bind_param('ss',$previousBookingDate,$therapistQueryEndDate); $q->execute(); $result=$q->get_result();
        while ($row=$result->fetch_assoc()) {
            $tid=(int)$row['therapist_id']; if (!isset($assignedDuration[$tid])) continue;
            $busyStart=strtotime($row['booking_date'].' '.$row['booking_time']); $busyDuration=walkinDurationMinutes($row['guest_duration'] ?: $row['parent_duration']);
            if ($busyStart!==false && $requestedTimestamp < $busyStart+$busyDuration*60 && $busyStart < $requestedTimestamp+$assignedDuration[$tid]*60) { $conflict=true; break; }
        }
        $q->close();
    }
    if (!$conflict) {
        $q=$conn->prepare("SELECT therapist_id,booking_date,booking_time,duration FROM bookings WHERE booking_date BETWEEN ? AND ? AND LOWER(TRIM(COALESCE(status,''))) NOT IN ('cancelled','completed') AND therapist_id>0 AND NOT EXISTS (SELECT 1 FROM booking_therapists bt_old WHERE bt_old.booking_id=bookings.id)");
        if ($q) {
            $therapistQueryEndDate = date('Y-m-d', $requestedTimestamp + $maxMinutes * 60);
        $q->bind_param('ss',$previousBookingDate,$therapistQueryEndDate); $q->execute(); $result=$q->get_result();
            while ($row=$result->fetch_assoc()) {
                $tid=(int)$row['therapist_id']; if (!isset($assignedDuration[$tid])) continue;
                $busyStart=strtotime($row['booking_date'].' '.$row['booking_time']); $busyDuration=walkinDurationMinutes($row['duration']);
                if ($busyStart!==false && $requestedTimestamp < $busyStart+$busyDuration*60 && $busyStart < $requestedTimestamp+$assignedDuration[$tid]*60) { $conflict=true; break; }
            }
            $q->close();
        }
    }
    if ($conflict) bookingError('One or more selected therapists are no longer available for this schedule.');
}

$customerName=$guests[0]['name']; $phone=$guests[0]['phone'];
$serviceSummary=($pax===1?'Individual Booking':($roomType==='Couple Room'?'Couple Booking':'Group Booking ('.$pax.' guests)'));
$durationSummary=$maxDuration;
$addonsSummary=implode(', ',array_values(array_unique($addonLabels)));
$primaryTherapist=$assigned[0] ?? 0;
$status='Confirmed'; $createdBy='admin';

$conn->begin_transaction();
try {
    $insert=$conn->prepare("INSERT INTO bookings (service,duration,price,customer_name,phone,email,booking_date,booking_time,pax,payment_method,notes,addons,therapist_id,room_type,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    if (!$insert) throw new Exception($conn->error);
    $insert->bind_param('ssdsssssisssisss',$serviceSummary,$durationSummary,$totalPrice,$customerName,$phone,$email,$date,$time,$pax,$payment,$notes,$addonsSummary,$primaryTherapist,$roomType,$status,$createdBy);
    if (!$insert->execute()) throw new Exception($insert->error);
    $bookingId=$insert->insert_id; $insert->close();

    $guestInsert=$conn->prepare('INSERT INTO booking_guests (booking_id,guest_number,guest_name,service_id,service,duration,price,addons,therapist_id) VALUES (?,?,?,?,?,?,?,?,?)');
    if (!$guestInsert) throw new Exception($conn->error);
    foreach ($selections as $i=>$selection) {
        $guestNumber=$selection['guest_number']; $guestName=$guests[$i]['name']; $sid=$selection['service_id'];
        $guestService=$selection['service']; $guestDuration=$selection['duration']; $guestPrice=$selection['price'];
        $guestAddonNames=implode(', ',array_column($selection['addons'],'name')); $guestTherapist=$selection['therapist_id']; $guestPhone=$phone;
        $guestInsert->bind_param('iisissdsi',$bookingId,$guestNumber,$guestName,$sid,$guestService,$guestDuration,$guestPrice,$guestAddonNames,$guestTherapist);
        if (!$guestInsert->execute()) throw new Exception($guestInsert->error);
        $guestRowId=$guestInsert->insert_id;

        foreach ($selection['addons'] as $addon) {
            $addonInsert=$conn->prepare('INSERT INTO booking_guest_addons (booking_guest_id,addon_service_id,addon_name,quantity,price) VALUES (?,?,?,1,?)');
            if (!$addonInsert) throw new Exception($conn->error);
            $addonId=$addon['id']; $addonName=$addon['name']; $addonPrice=$addon['price'];
            $addonInsert->bind_param('iisd',$guestRowId,$addonId,$addonName,$addonPrice);
            if (!$addonInsert->execute()) throw new Exception($addonInsert->error);
            $addonInsert->close();
        }

        if ($guestTherapist>0) {
            $map=$conn->prepare("INSERT INTO booking_therapists (booking_id,booking_guest_id,therapist_id,assigned_by,source,action_by) VALUES (?,?,?,'admin','admin','admin')");
            if (!$map) throw new Exception($conn->error);
            $map->bind_param('iii',$bookingId,$guestRowId,$guestTherapist);
            if (!$map->execute()) throw new Exception($map->error);
            $map->close();
        }
    }
    $guestInsert->close();
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    error_log('Admin walk-in save error: '.$error->getMessage());
    bookingError('The booking could not be saved. Please check the details and try again.');
}

// Email is optional for walk-ins. A mail failure must never undo a confirmed booking.
if ($email !== '') {
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        if (function_exists('mizpahSendBookingEmails')) {
            $mailGuests=[];
            foreach ($selections as $i=>$selection) {
                $mailGuests[]=['guest_number'=>$selection['guest_number'],'name'=>$guests[$i]['name'],'service'=>$selection['service'],'duration'=>$selection['duration'],'price'=>$selection['price'],'addons'=>$selection['addons']];
            }
            mizpahSendBookingEmails(['id'=>$bookingId,'customer_name'=>$customerName,'phone'=>$phone,'email'=>$email,'booking_date'=>$date,'booking_time'=>$time,'pax'=>$pax,'payment_method'=>$payment,'price'=>$totalPrice,'guests'=>$mailGuests,'notes'=>$notes]);
        } else {
            error_log('Mizpah mailer is missing mizpahSendBookingEmails().');
        }
    } catch (Throwable $mailError) {
        error_log('Mizpah walk-in confirmation email error: '.$mailError->getMessage());
    }
}

header('Location: bookings.php?success=1&id='.$bookingId.($email!==''?'&email=1':''));
exit;
?>
