<?php
session_start();
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/includes/db.php';
$booking_error = '';
$wellnessTerms = '';
$termsResult = mysqli_query($conn, "SELECT wellness_terms FROM settings WHERE id = 1 LIMIT 1");
if ($termsResult && ($termsRow = mysqli_fetch_assoc($termsResult))) {
    $wellnessTerms = trim((string)($termsRow['wellness_terms'] ?? ''));
}
if ($wellnessTerms === '') {
    $wellnessTerms = "1. Accuracy of Information\nI confirm that the wellness information I provide is accurate and complete to the best of my knowledge.\n\n2. Wellness Information\nI understand that the information will be used to help the spa understand my needs and relevant concerns before providing a service.\n\n3. Massage and Spa Services\nI understand that spa services are for relaxation and wellness and are not a substitute for professional medical care.\n\n4. Health and Safety\nI will disclose relevant allergies, medical conditions, injuries, pain, and skin sensitivities.\n\n5. Privacy\nMy information will be used for spa service and customer care and accessed only by authorized personnel.";
}



function bookingDurationMinutes($duration) {
    $duration = strtolower(trim((string)$duration));
    $hours = 0.0; $minutes = 0;
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?|hr)\b/i', $duration, $m)) $hours = (float)$m[1];
    if (preg_match('/(\d+)\s*(?:minutes?|mins?|min)\b/i', $duration, $m)) $minutes = (int)$m[1];
    if ($hours > 0 || $minutes > 0) return (int)round($hours * 60) + $minutes;
    if (is_numeric($duration)) { $n=(float)$duration; return $n > 3 ? (int)round($n) : (int)round($n*60); }
    return 60;
}
function bookingOperatingMinutes($time, $openingHour) {
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim((string)$time), $m)) return null;
    $h=(int)$m[1]; $min=(int)$m[2];
    if ($h<0 || $h>23 || $min<0 || $min>59) return null;
    if ($h<$openingHour) $h+=24;
    return $h*60+$min;
}
function bookingOpeningHour($date) { $n=(int)date('N', strtotime($date)); return $n>=6 ? 13 : 15; }

function bookingRoomHasCapacity($conn, $date, $time, $roomType, $guestDurations) {
    $capacity = $roomType === 'Couple Room' ? 1 : 4;
    $durations = array_values(array_filter(array_map('intval', $guestDurations), static fn($v) => $v > 0));
    if (!$durations) return false;

    // $date is already normalized to the actual calendar date by the booking flow.
    $candidateStart = strtotime($date . ' ' . $time);
    if ($candidateStart === false) return false;
    $prepMinutes = 15;
    $candidateMaxDuration = max($durations);
    $queryEndDate = date('Y-m-d', $candidateStart + ($candidateMaxDuration + $prepMinutes) * 60);

    $previousDate = date('Y-m-d', strtotime($date . ' -1 day'));
    $sql = "SELECT b.id,b.booking_date,b.booking_time,b.duration AS parent_duration,
                   COALESCE(NULLIF(b.pax,0),1) AS pax,b.created_by,
                   bg.id AS guest_id,bg.duration AS guest_duration
            FROM bookings b
            LEFT JOIN booking_guests bg ON bg.booking_id=b.id
            WHERE b.booking_date BETWEEN ? AND ? AND b.room_type=?
              AND LOWER(TRIM(COALESCE(b.status,''))) NOT IN ('cancelled','completed')";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'sss', $previousDate, $queryEndDate, $roomType);
    if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); return false; }
    $result = mysqli_stmt_get_result($stmt);
    $bookings = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $id = (int)$row['id'];
        if (!isset($bookings[$id])) {
            $bookings[$id] = [
                'start' => strtotime($row['booking_date'] . ' ' . $row['booking_time']),
                'parent' => bookingDurationMinutes($row['parent_duration'] ?? '1 Hour'),
                'pax' => max(1, (int)$row['pax']),
                'created_by' => strtolower(trim((string)($row['created_by'] ?? ''))),
                'durations' => []
            ];
        }
        if (!empty($row['guest_id'])) {
            $bookings[$id]['durations'][] = bookingDurationMinutes($row['guest_duration'] ?: $row['parent_duration']);
        }
    }
    mysqli_stmt_close($stmt);

    $events = [];
    foreach ($bookings as $booking) {
        if ($booking['start'] === false) continue;
        $existingDurations = array_values(array_filter($booking['durations'], static fn($v) => $v > 0));
        if (!$existingDurations) $existingDurations = array_fill(0, $booking['pax'], max(1, $booking['parent']));
        // Admin Walk-ins have no automatic preparation buffer; online bookings do.
        $existingPrep = $booking['created_by'] === 'admin' ? 0 : $prepMinutes;
        if ($roomType === 'Couple Room') $existingDurations = [max($existingDurations)];
        foreach ($existingDurations as $duration) {
            $events[] = [$booking['start'], 1];
            $events[] = [$booking['start'] + ((int)$duration + $existingPrep) * 60, -1];
        }
    }

    if ($roomType === 'Couple Room') $durations = [max($durations)];
    $candidateMaxEnd = $candidateStart;
    foreach ($durations as $duration) {
        $end = $candidateStart + ((int)$duration + $prepMinutes) * 60;
        $events[] = [$candidateStart, 1];
        $events[] = [$end, -1];
        $candidateMaxEnd = max($candidateMaxEnd, $end);
    }

    usort($events, static function ($a, $b) { return $a[0] <=> $b[0] ?: $a[1] <=> $b[1]; });
    $active = 0;
    $index = 0;
    $count = count($events);
    while ($index < $count) {
        $at = $events[$index][0];
        $delta = 0;
        while ($index < $count && $events[$index][0] === $at) {
            $delta += $events[$index][1];
            $index++;
        }
        $active += $delta;
        // Only reject overload during the candidate's actual service/prep window.
        if ($at >= $candidateStart && $at < $candidateMaxEnd && $active > $capacity) return false;
    }
    return true;
}


function bookingTherapistsAreAvailable($conn, $date, $time, $assignedDuration) {
    if (!$assignedDuration) return true;
    $candidateStart = strtotime($date . ' ' . $time);
    if ($candidateStart === false) return false;
    $maxAssignedDuration = max(array_map('intval', $assignedDuration));
    $queryEndDate = date('Y-m-d', $candidateStart + $maxAssignedDuration * 60);
    $previousDate = date('Y-m-d', strtotime($date . ' -1 day'));
    $sql = "SELECT b.booking_date,b.booking_time,b.duration AS parent_duration,b.therapist_id,
                   bt.therapist_id AS assigned_therapist_id,bg.duration AS guest_duration
            FROM bookings b
            LEFT JOIN booking_therapists bt ON bt.booking_id=b.id
            LEFT JOIN booking_guests bg ON bg.id=bt.booking_guest_id
            WHERE b.booking_date BETWEEN ? AND ?
              AND LOWER(TRIM(COALESCE(b.status,''))) NOT IN ('cancelled','completed')";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 'ss', $previousDate, $queryEndDate);
    if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); return false; }
    $result = mysqli_stmt_get_result($stmt);
    $available = true;
    while ($row = mysqli_fetch_assoc($result)) {
        $busyStart = strtotime($row['booking_date'] . ' ' . $row['booking_time']);
        if ($busyStart === false) continue;
        $assignedId = (int)($row['assigned_therapist_id'] ?? 0);
        $therapistId = $assignedId > 0 ? $assignedId : (int)($row['therapist_id'] ?? 0);
        if (!isset($assignedDuration[$therapistId])) continue;
        $busyDuration = $assignedId > 0 && trim((string)($row['guest_duration'] ?? '')) !== ''
            ? bookingDurationMinutes($row['guest_duration'])
            : bookingDurationMinutes($row['parent_duration'] ?? '1 Hour');
        $busyEnd = $busyStart + $busyDuration * 60;
        $candidateEnd = $candidateStart + ((int)$assignedDuration[$therapistId]) * 60;
        if ($candidateStart < $busyEnd && $busyStart < $candidateEnd) { $available = false; break; }
    }
    mysqli_stmt_close($stmt);
    return $available;
}


/** Return one therapist who can cover this guest for the requested interval.
 *  Excluded IDs are therapists already selected/reserved for another guest
 *  in the same multi-guest booking.
 */
function bookingFindAvailableTherapist($conn, $date, $time, $durationMinutes, $excludedIds = []) {
    $candidateStart = strtotime($date . ' ' . $time);
    $durationMinutes = (int)$durationMinutes;
    if ($candidateStart === false || $durationMinutes <= 0) return 0;
    $candidateEnd = $candidateStart + $durationMinutes * 60;
    $previousDate = date('Y-m-d', $candidateStart - 86400);
    $endDate = date('Y-m-d', $candidateEnd);

    $active = [];
    $therapistResult = mysqli_query($conn, "SELECT id FROM therapists WHERE status='Active' ORDER BY id ASC");
    if (!$therapistResult) return 0;
    while ($therapist = mysqli_fetch_assoc($therapistResult)) {
        $id = (int)$therapist['id'];
        if ($id > 0 && !in_array($id, array_map('intval', $excludedIds), true)) $active[$id] = true;
    }
    if (!$active) return 0;

    $sql = "SELECT b.booking_date,b.booking_time,b.duration AS parent_duration,b.therapist_id,
                   bt.therapist_id AS assigned_therapist_id,bg.duration AS guest_duration
            FROM bookings b
            LEFT JOIN booking_therapists bt ON bt.booking_id=b.id
            LEFT JOIN booking_guests bg ON bg.id=bt.booking_guest_id
            WHERE b.booking_date BETWEEN ? AND ?
              AND LOWER(TRIM(COALESCE(b.status,''))) NOT IN ('cancelled','completed')";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, 'ss', $previousDate, $endDate);
    if (!mysqli_stmt_execute($stmt)) { mysqli_stmt_close($stmt); return 0; }
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $busyStart = strtotime($row['booking_date'] . ' ' . $row['booking_time']);
        if ($busyStart === false) continue;
        $assignedId = (int)($row['assigned_therapist_id'] ?? 0);
        $therapistId = $assignedId > 0 ? $assignedId : (int)($row['therapist_id'] ?? 0);
        if (!isset($active[$therapistId])) continue;
        $busyDuration = $assignedId > 0 && trim((string)($row['guest_duration'] ?? '')) !== ''
            ? bookingDurationMinutes($row['guest_duration'])
            : bookingDurationMinutes($row['parent_duration'] ?? '1 Hour');
        $busyEnd = $busyStart + max(1, (int)$busyDuration) * 60;
        if ($candidateStart < $busyEnd && $busyStart < $candidateEnd) unset($active[$therapistId]);
    }
    mysqli_stmt_close($stmt);
    return $active ? (int)array_key_first($active) : 0;
}


if (isset($_POST['submit_booking'])) {
    $name=trim($_POST['customer_name'] ?? '');
    $phone=preg_replace('/\s+/', '', trim($_POST['phone'] ?? ''));
    $email=strtolower(trim($_POST['email'] ?? ''));
    $emailUpdates = 1; // Guest email status updates are automatic; no opt-in checkbox.
    $rawWellness=$_POST['wellness'] ?? [];
    $date=trim($_POST['booking_date'] ?? '');
    $time=trim($_POST['booking_time'] ?? '');
    $pax=(int)($_POST['pax'] ?? 1);
    $payment=trim($_POST['payment_method'] ?? 'Cash');
    $notes='';
    $room_type=trim($_POST['room_type'] ?? '');
    $guestNames=array_merge([$name], array_values(array_map('trim',(array)($_POST['guest_names'] ?? []))));
    $rawSelections=json_decode($_POST['guest_selections'] ?? '[]', true);
    if ($room_type==='Couple Room') $pax=2;

    if (!$name || !$phone || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || count($guestNames)!==$pax || !is_array($rawSelections) || count($rawSelections)!==$pax || !is_array($rawWellness) || count($rawWellness)!==$pax || !$date || !$time || !$room_type) {
        $booking_error='Please provide a valid email address and complete each guest\'s service selection, wellness profile, and booking information.';
    } elseif (!preg_match('/^(?=.{2,100}$)[\p{L}][\p{L}\s.\'-]*$/u', $name)) {
        $booking_error='Please enter a valid full name using letters and spaces only.';
    } elseif (count(array_filter($guestNames,fn($guestName)=>preg_match('/^(?=.{2,100}$)[\p{L}][\p{L}\s.\'-]*$/u',trim($guestName)))) !== $pax) {
        $booking_error='Please enter a valid full name for every guest.';
    } elseif (!preg_match('/^09\d{9}$/', $phone)) {
        $booking_error='Please enter a valid 11-digit Philippine mobile number starting with 09.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        $booking_error='Please enter a valid email address (maximum 150 characters).';
    } elseif (!in_array($room_type,['Single Room','Couple Room'],true) || $pax<1 || $pax>4 || ($room_type==='Couple Room' && $pax!==2)) {
        $booking_error='Please select a valid room and guest count (Single Room: 1–4; Couple Room: exactly 2).';
    } elseif (!in_array($payment,['Cash','GCash'],true)) {
        $booking_error='Please select a valid payment method.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !preg_match('/^\d{2}:\d{2}$/',$time) || !strtotime($date)) {
        $booking_error='Please select a valid appointment date and time.';
    } elseif (($_POST['wellness_terms_accepted'] ?? '') !== '1') {
        $booking_error='Please read and accept the Wellness Terms and Conditions before booking.';
    } else {
        $opening=bookingOpeningHour($date);
        $now=new DateTime('now',new DateTimeZone('Asia/Manila'));
        $selected=DateTime::createFromFormat('!Y-m-d H:i',$date.' '.$time,new DateTimeZone('Asia/Manila'));
        // Times before the day's opening hour belong to the overnight portion
        // of the selected operating day, so persist them on the next calendar day.
        if ($selected && (int)substr($time,0,2)<$opening) $selected->modify('+1 day');
        if ($selected) {
            $date=$selected->format('Y-m-d');
            $opening=bookingOpeningHour($date);
        }
        $start=bookingOperatingMinutes($time,$opening);
        $selections=[]; $totalPrice=0.0; $maxMinutes=0; $maxDuration=''; $serviceLabels=[]; $addonLabels=[]; $assigned=[]; $assignedDuration=[];
        $wellnessProfiles=[];
        for ($guestNo=1; $guestNo<=$pax; $guestNo++) {
            $profile = $rawWellness[$guestNo] ?? null;
            if (!is_array($profile)) { $booking_error='Please complete the Wellness Profile for every guest.'; break; }
            $profile = array_map(static fn($v)=>trim((string)$v), $profile);
            $emergencyNumber = preg_replace('/\\D+/', '', $profile['emergency_contact_number'] ?? '');
            if ($emergencyNumber !== '' && !preg_match('/^09[0-9]{9}$/', $emergencyNumber)) {
                $booking_error = 'Please enter a valid 11-digit Philippine emergency contact number starting with 09 for Guest '.$guestNo.'.';
                break;
            }
            $profile['emergency_contact_number'] = $emergencyNumber;
            foreach (['allergies','skin_sensitivity','medical_conditions','body_pain_injuries','preferred_pressure'] as $requiredField) {
                if (($profile[$requiredField] ?? '') === '') { $booking_error='Please complete all required Wellness Profile fields for Guest '.$guestNo.'.'; break 2; }
            }
            $wellnessProfiles[$guestNo] = [
                'allergies'=>$profile['allergies'],
                'skin_sensitivity'=>$profile['skin_sensitivity'],
                'medical_conditions'=>$profile['medical_conditions'],
                'body_pain_injuries'=>$profile['body_pain_injuries'],
                'pregnancy_status'=>'',
                'preferred_pressure'=>$profile['preferred_pressure'],
                'special_requests'=>$profile['special_requests'] ?? '',
                'emergency_contact_name'=>$profile['emergency_contact_name'] ?? '',
                'emergency_contact_number'=>$profile['emergency_contact_number'] ?? ''
            ];
        }

        if (!$selected || $selected < (clone $now)->modify('+2 hours')) $booking_error='Please book at least 2 hours in advance.';
        foreach ($rawSelections as $idx=>$item) {
            if ($booking_error) break;
            $guestNo=$idx+1; $sid=(int)($item['service_id'] ?? 0); $duration=trim((string)($item['duration'] ?? ''));
            if ($sid<=0 || $duration==='') { $booking_error='Please select a service and duration for Guest '.$guestNo.'.'; break; }
            $st=mysqli_prepare($conn,"SELECT s.service_name,s.category,sd.duration,sd.price FROM services s INNER JOIN service_durations sd ON sd.service_id=s.id WHERE s.id=? AND s.category IN ('Massage','Package','Promo') AND sd.duration=? LIMIT 1");
            mysqli_stmt_bind_param($st,'is',$sid,$duration); mysqli_stmt_execute($st); $rs=mysqli_stmt_get_result($st); $svc=mysqli_fetch_assoc($rs); mysqli_stmt_close($st);
            if (!$svc) { $booking_error='The selected service or duration for Guest '.$guestNo.' is no longer available. Please select again.'; break; }
            $mins=bookingDurationMinutes($svc['duration']); if($mins<=0){$booking_error='Invalid service duration for Guest '.$guestNo.'.';break;}
            $guestAddons=[]; $addonIds=array_values(array_unique(array_filter(array_map('intval',(array)($item['addons'] ?? [])),fn($v)=>$v>0))); $addonTotal=0.0;
            foreach($addonIds as $aid){
                $as=mysqli_prepare($conn,"SELECT id,service_name,price FROM services WHERE id=? AND category='Add-ons' LIMIT 1"); mysqli_stmt_bind_param($as,'i',$aid); mysqli_stmt_execute($as); $ar=mysqli_stmt_get_result($as); $ad=mysqli_fetch_assoc($ar); mysqli_stmt_close($as);
                if(!$ad){$booking_error='An add-on selected for Guest '.$guestNo.' is no longer available.';break;}
                $ap=(float)$ad['price']; $addonTotal+=$ap; $guestAddons[]=['id'=>(int)$ad['id'],'name'=>$ad['service_name'],'price'=>$ap]; $addonLabels[]=$ad['service_name'];
            }
            if($booking_error) break;
            $therapistId=(int)($item['therapist_id'] ?? 0);
            if($therapistId>0){
                $ts=mysqli_prepare($conn,"SELECT id FROM therapists WHERE id=? AND status='Active' LIMIT 1"); mysqli_stmt_bind_param($ts,'i',$therapistId); mysqli_stmt_execute($ts); $tr=mysqli_stmt_get_result($ts); $validTherapist=mysqli_fetch_assoc($tr); mysqli_stmt_close($ts);
                if(!$validTherapist){$booking_error='The selected therapist for Guest '.$guestNo.' is no longer active. Please select again.';break;}
                if(in_array($therapistId,$assigned,true)){$booking_error='Please choose a different therapist for each guest.';break;}
                $assigned[]=$therapistId; $assignedDuration[$therapistId]=$mins;
            }
            $servicePrice=(float)$svc['price']; $totalPrice += $servicePrice+$addonTotal; $maxMinutes=max($maxMinutes,$mins);
            if($mins===$maxMinutes)$maxDuration=$svc['duration'];
            $serviceLabels[]=$svc['service_name'];
            $selections[]=['guest_number'=>$guestNo,'service_id'=>$sid,'service'=>$svc['service_name'],'duration'=>$svc['duration'],'price'=>$servicePrice,'addons'=>$guestAddons,'therapist_id'=>$therapistId];
        }
        if(!$booking_error && ($start===null || $maxMinutes<=0 || $start+$maxMinutes>27*60)) $booking_error='The selected appointment must fit within the spa operating hours and finish by 3:00 AM.';
        if(!$booking_error){
            $guestRoomDurations=array_map(static fn($g)=>bookingDurationMinutes($g['duration']),$selections);
            if(!bookingRoomHasCapacity($conn,$date,$time,$room_type,$guestRoomDurations)) $booking_error=$room_type==='Couple Room'?'The Couple Room is already booked for this time. Please choose another time.':'Sorry, there are not enough Single Room slots available at this time. Please choose another time or reduce the guest count.';
        }
        if (!$booking_error && !bookingTherapistsAreAvailable($conn, $date, $time, $assignedDuration)) {
            $booking_error='One or more selected therapists are no longer available for the chosen schedule. Please select again.';
        }
        // No Preference is valid only when at least one distinct therapist can actually take each guest.
        if (!$booking_error) {
            $reservedTherapists = $assigned;
            foreach ($selections as $guestSelection) {
                if ((int)$guestSelection['therapist_id'] > 0) continue;
                $availableTherapistId = bookingFindAvailableTherapist(
                    $conn, $date, $time, bookingDurationMinutes($guestSelection['duration']), $reservedTherapists
                );
                if ($availableTherapistId <= 0) {
                    $booking_error = 'No therapists are available for this schedule. Please choose another time slot.';
                    break;
                }
                $reservedTherapists[] = $availableTherapistId;
            }
        }
        if(!$booking_error){
            $serviceSummary='Group Booking ('.$pax.' guests)'; $durationSummary=$maxDuration; $addonsSummary=implode(', ',array_values(array_unique($addonLabels))); $primaryTherapist=$assigned[0]??0;
            mysqli_begin_transaction($conn);
            $st=mysqli_prepare($conn,"INSERT INTO bookings (service,duration,price,customer_name,phone,email,booking_date,booking_time,pax,payment_method,notes,addons,therapist_id,room_type,status,created_by,email_updates) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'Confirmed','guest',?)");
            mysqli_stmt_bind_param($st,'ssdsssssisssisi',$serviceSummary,$durationSummary,$totalPrice,$name,$phone,$email,$date,$time,$pax,$payment,$notes,$addonsSummary,$primaryTherapist,$room_type,$emailUpdates);
            $ok=mysqli_stmt_execute($st); $bookingId=$ok?mysqli_insert_id($conn):0; if(!$ok)$booking_error='Unable to save your booking. Please try again.'; mysqli_stmt_close($st);
            if($ok){
                $guestStmt=mysqli_prepare($conn,"INSERT INTO booking_guests (booking_id,guest_number,guest_name,service_id,service,duration,price,addons,therapist_id,phone) VALUES (?,?,?,?,?,?,?,?,?,?)");
                if(!$guestStmt){$ok=false;$booking_error='Unable to save guest details. Please contact the spa.';}
                foreach($selections as $i=>$g){if(!$ok)break;$guestNo=$g['guest_number'];$gname=$guestNames[$i];$sid=$g['service_id'];$gservice=$g['service'];$gduration=$g['duration'];$gprice=$g['price'];$gaddonNames=implode(', ',array_column($g['addons'],'name'));$gtid=$g['therapist_id'];
                    mysqli_stmt_bind_param($guestStmt,'iisissdsis',$bookingId,$guestNo,$gname,$sid,$gservice,$gduration,$gprice,$gaddonNames,$gtid,$phone); if(!mysqli_stmt_execute($guestStmt)){$ok=false;$booking_error='Unable to save individual guest selections.';break;} $guestRowId=mysqli_insert_id($conn);
                    foreach($g['addons'] as $ad){$as=mysqli_prepare($conn,"INSERT INTO booking_guest_addons (booking_guest_id,addon_service_id,addon_name,quantity,price) VALUES (?,?,?,1,?)");if(!$as){$ok=false;break;}$aid=$ad['id'];$aname=$ad['name'];$aprice=$ad['price'];mysqli_stmt_bind_param($as,'iisd',$guestRowId,$aid,$aname,$aprice);if(!mysqli_stmt_execute($as))$ok=false;mysqli_stmt_close($as);if(!$ok)break;}
                    if($gtid>0){$bt=mysqli_prepare($conn,"INSERT INTO booking_therapists (booking_id,booking_guest_id,therapist_id,assigned_by,source,action_by) VALUES (?,?,?,'guest','guest','guest')");if(!$bt){$ok=false;break;}mysqli_stmt_bind_param($bt,'iii',$bookingId,$guestRowId,$gtid);if(!mysqli_stmt_execute($bt))$ok=false;mysqli_stmt_close($bt);}
                    if (!$ok) break;
                    $wp=$wellnessProfiles[$guestNo];
                    $termsAccepted=1; $termsAcceptedAt=date('Y-m-d H:i:s');
                    $wellnessStmt=mysqli_prepare($conn,"INSERT INTO booking_guest_wellness (booking_guest_id,allergies,skin_sensitivity,medical_conditions,body_pain_injuries,pregnancy_status,preferred_pressure,special_requests,emergency_contact_name,emergency_contact_number,terms_accepted,terms_accepted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                    if(!$wellnessStmt){$ok=false;$booking_error='Unable to save the Wellness Profile for Guest '.$guestNo.'.';break;}
                    mysqli_stmt_bind_param($wellnessStmt,'isssssssssis',$guestRowId,$wp['allergies'],$wp['skin_sensitivity'],$wp['medical_conditions'],$wp['body_pain_injuries'],$wp['pregnancy_status'],$wp['preferred_pressure'],$wp['special_requests'],$wp['emergency_contact_name'],$wp['emergency_contact_number'],$termsAccepted,$termsAcceptedAt);
                    if(!mysqli_stmt_execute($wellnessStmt)){$ok=false;$booking_error='Unable to save the Wellness Profile for Guest '.$guestNo.'.';}
                    mysqli_stmt_close($wellnessStmt);
                    if(!$ok)break;
                }
                if(isset($guestStmt) && $guestStmt)mysqli_stmt_close($guestStmt);
            }
            if($ok){
                mysqli_commit($conn);
                try {
                    require_once __DIR__ . '/includes/mailer.php';
                    $mailGuests=[];
                    foreach($selections as $i=>$g){
                        $mailGuests[]=['guest_number'=>$g['guest_number'],'name'=>$guestNames[$i],'service'=>$g['service'],'duration'=>$g['duration'],'price'=>$g['price'],'addons'=>$g['addons']];
                    }
                    $mailData=['id'=>$bookingId,'customer_name'=>$name,'phone'=>$phone,'email'=>$email,'booking_date'=>$date,'booking_time'=>$time,'pax'=>$pax,'payment_method'=>$payment,'price'=>$totalPrice,'guests'=>$mailGuests,'notes'=>$notes];
                    if(function_exists('mizpahSendBookingEmails')) mizpahSendBookingEmails($mailData);
                    else error_log('Mizpah mailer is missing mizpahSendBookingEmails().');
                } catch (Throwable $mailError) {
                    error_log('Mizpah booking email error: '.$mailError->getMessage());
                }
                header('Location: thankyou.php?id='.$bookingId);exit;
            }
            mysqli_rollback($conn); if(!$booking_error)$booking_error='Unable to save the group booking. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>Book an Appointment | Mizpah Wellness Spa</title>

<link
    href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=Poppins:wght@300;400;500;600;700&display=swap"
    rel="stylesheet"
>

<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    font-family:Poppins,sans-serif;
}

html{
    scroll-behavior:smooth;
}

body{

    min-height:100vh;

    background:
        linear-gradient(
            rgba(0,0,0,.84),
            rgba(0,0,0,.94)
        ),
        url('assets/images/hero.jpg')
        center/cover fixed no-repeat;

    color:#fff;

}


/* =========================================================
   HEADER
   ========================================================= */

.site-header{

    min-height:70px;

    display:flex;
    align-items:center;
    justify-content:space-between;

    padding:12px 7%;

    background:rgba(10,10,10,.96);

    border-bottom:
        1px solid
        rgba(214,194,156,.16);

    position:sticky;
    top:0;

    z-index:1000;

}

.brand{

    display:flex;
    align-items:center;

    gap:11px;

    text-decoration:none;

}

.brand img{

    width:42px;
    height:42px;

    object-fit:contain;

}

.brand span{

    font-family:
        'Playfair Display',
        serif;

    color:#D6C29C;

    font-size:18px;

    font-weight:600;

}

.header-links{

    display:flex;
    align-items:center;

    gap:8px;

}

.header-links a{

    color:#ddd;

    text-decoration:none;

    font-size:12px;

    padding:8px 11px;

    border-radius:7px;

    transition:.2s;

}

.header-links a:hover{

    color:#D6C29C;

}

.login-btn{

    border:
        1px solid
        rgba(214,194,156,.4);

    color:#D6C29C !important;

}


/* =========================================================
   PAGE
   ========================================================= */

.page{

    width:100%;

    max-width:1250px;

    margin:auto;

    padding:
        38px 5%
        70px;

}


/* =========================================================
   PAGE HEADING
   ========================================================= */

.page-heading{

    margin-bottom:28px;

}

.eyebrow{

    color:#8c7d63;

    text-transform:uppercase;

    letter-spacing:2px;

    font-size:9px;

    margin-bottom:5px;

}

.page-heading h1{

    font-family:
        'Playfair Display',
        serif;

    color:#D6C29C;

    font-size:34px;

    margin-bottom:7px;

}

.page-heading p{

    color:#888;

    font-size:11px;

    line-height:1.8;

    max-width:680px;

}


/* =========================================================
   ERROR
   ========================================================= */

.error-box{

    background:
        rgba(150,60,60,.12);

    border:
        1px solid
        rgba(200,100,100,.25);

    color:#dca2a2;

    padding:12px 15px;

    border-radius:10px;

    margin-bottom:18px;

    font-size:11px;

}


/* =========================================================
   MAIN LAYOUT
   ========================================================= */

.booking-layout{

    display:grid;

    grid-template-columns:
        minmax(0,1fr)
        330px;

    gap:22px;

    align-items:start;

}


/* =========================================================
   SECTIONS
   ========================================================= */

.section{

    background:
        rgba(18,18,18,.93);

    border:
        1px solid
        rgba(255,255,255,.07);

    border-radius:16px;

    padding:22px;

    margin-bottom:15px;

    box-shadow:
        0 12px 30px
        rgba(0,0,0,.18);

}

.section-head{

    display:flex;

    align-items:flex-start;

    gap:12px;

    margin-bottom:19px;

}

.step{

    width:29px;
    height:29px;

    min-width:29px;

    border-radius:50%;

    display:flex;

    justify-content:center;
    align-items:center;

    background:
        rgba(214,194,156,.10);

    border:
        1px solid
        rgba(214,194,156,.25);

    color:#D6C29C;

    font-size:11px;

    font-weight:600;

}

.section-title{

    color:#D6C29C;

    font-size:14px;

    font-weight:600;

    margin-bottom:3px;

}

.section-sub{

    color:#666;

    font-size:10px;

    line-height:1.6;

}

.field-title{

    color:#aaa;

    font-size:10px;

    font-weight:500;

    text-transform:uppercase;

    letter-spacing:.6px;

    margin:
        18px 0
        9px;

}

.field-title:first-child{
    margin-top:0;
}


/* =========================================================
   SELECTION CARDS
   ========================================================= */

.selection-grid{

    display:grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(130px,1fr)
        );

    gap:9px;

}

.select-card{

    min-height:49px;

    position:relative;

    display:flex;

    flex-direction:column;

    justify-content:center;
    align-items:center;

    text-align:center;

    padding:12px 10px;

    background:#101010;

    border:
        1px solid
        #292929;

    border-radius:10px;

    color:#ccc;

    cursor:pointer;

    font-size:11px;

    transition:.2s;

}

.select-card:hover{

    transform:
        translateY(-1px);

    border-color:
        rgba(214,194,156,.55);

    color:#fff;

}

.select-card.active{

    border-color:#D6C29C;

    background:
        rgba(214,194,156,.08);

    color:#D6C29C;

    box-shadow:
        0 0 0 1px
        rgba(214,194,156,.04);

}

.select-card.unavailable{

    opacity:.30;

    cursor:not-allowed;

    pointer-events:none;

}

.card-price{

    color:#D6C29C;

    font-size:10px;

    margin-top:3px;

}

.card-desc{

    color:#666;

    font-size:9px;

    line-height:1.4;

    margin-top:4px;

}

.service-description{

    margin-top:11px;

    padding:11px 13px;

    background:#0e0e0e;

    border-left:
        2px solid
        #D6C29C;

    border-radius:
        0 8px 8px 0;

    color:#888;

    font-size:10px;

    line-height:1.7;

}


/* =========================================================
   FORM
   ========================================================= */

.form-grid{

    display:grid;

    grid-template-columns:
        repeat(2,1fr);

    gap:14px;

}

.form-group{

    min-width:0;

}

.form-group.full{

    grid-column:
        1 / -1;

}

label{

    display:block;

    color:#999;

    font-size:10px;

    margin-bottom:6px;

}

.required{

    color:#D6C29C;

}

input,
select,
textarea{

    width:100%;

    padding:11px 12px;

    background:#0f0f0f;

    color:#fff;

    border:
        1px solid
        #303030;

    border-radius:9px;

    outline:none;

    font-size:11px;

    transition:.2s;

}

input:focus,
select:focus,
textarea:focus{

    border-color:#D6C29C;

    box-shadow:
        0 0 7px
        rgba(214,194,156,.12);

}

input::placeholder,
textarea::placeholder{

    color:#555;

}

select option{

    background:#111;

}

textarea{

    min-height:90px;

    resize:vertical;

    line-height:1.6;

}

input:read-only{

    opacity:.65;

}


/* =========================================================
   SCHEDULE
   ========================================================= */

.schedule-grid{

    display:grid;

    grid-template-columns:
        repeat(2,1fr);

    gap:16px;

    margin-top:18px;

}

.time-grid{

    display:grid;

    grid-template-columns:
        repeat(4,1fr);

    gap:8px;

}

.time-card{

    background:#101010;

    border:
        1px solid
        #292929;

    border-radius:9px;

    padding:10px 5px;

    text-align:center;

    cursor:pointer;

    color:#ccc;

    font-size:10px;

    transition:.2s;

}

.time-card:hover{

    border-color:#D6C29C;

}

.time-card.active{

    border-color:#D6C29C;

    background:
        rgba(214,194,156,.08);

    color:#D6C29C;

}

.time-card.dim{

    opacity:.25;

    pointer-events:none;

}

.slot-count{

    display:block;

    color:#666;

    font-size:8px;

    margin-top:3px;

}


/* =========================================================
   THERAPIST
   ========================================================= */

.therapist-choice{

    display:grid;

    grid-template-columns:
        repeat(2,1fr);

    gap:9px;

    margin-bottom:13px;

}

.therapist-list{

    display:grid;

    grid-template-columns:
        repeat(3,1fr);

    gap:9px;

    margin-top:10px;

}

.therapist-card{

    min-height:65px;

}

.available-text{

    color:#8fa98f;

}

.unavailable-text{

    color:#b78383;

}

.message{

    color:#777;

    font-size:10px;

    margin-top:10px;

    line-height:1.6;

}


/* =========================================================
   SUMMARY
   ========================================================= */

.summary-panel{

    position:sticky;

    top:88px;

    background:
        rgba(18,18,18,.97);

    border:
        1px solid
        rgba(214,194,156,.17);

    border-radius:16px;

    padding:22px;

    box-shadow:
        0 16px 40px
        rgba(0,0,0,.30);

}

.summary-panel h3{

    font-family:
        'Playfair Display',
        serif;

    color:#D6C29C;

    font-size:20px;

    margin-bottom:5px;

}

.summary-sub{

    color:#666;

    font-size:9px;

    margin-bottom:18px;

}

.summary-row{

    display:flex;

    justify-content:space-between;

    gap:12px;

    padding:10px 0;

    border-bottom:
        1px solid
        #272727;

}

.summary-label{

    color:#777;

    font-size:9px;

}

.summary-value{

    color:#ddd;

    font-size:10px;

    text-align:right;

    max-width:180px;

}

.summary-addons{

    line-height:1.5;

}

.price-area{

    padding:
        15px 0
        4px;

}

.price-line{

    display:flex;

    justify-content:space-between;

    gap:10px;

    margin-bottom:7px;

    color:#888;

    font-size:9px;

}

.total-line{

    display:flex;

    justify-content:space-between;

    align-items:center;

    margin-top:12px;

    padding-top:13px;

    border-top:
        1px solid
        rgba(214,194,156,.18);

}

.total-label{

    color:#aaa;

    font-size:11px;

}

.total-price{

    color:#D6C29C;

    font-size:21px;

    font-weight:600;

}

.book-btn{

    width:100%;

    margin-top:17px;

    padding:13px;

    border:none;

    border-radius:9px;

    background:#D6C29C;

    color:#111;

    font-size:11px;

    font-weight:700;

    cursor:pointer;

    transition:.2s;

}

.book-btn:hover{

    transform:
        translateY(-2px);

    box-shadow:
        0 8px 20px
        rgba(214,194,156,.18);

}

.booking-note{

    color:#555;

    font-size:8px;

    line-height:1.5;

    text-align:center;

    margin-top:10px;

}


/* =========================================================
   RESPONSIVE + STICKY BOOKING SUMMARY
   ========================================================= */

/* =========================================================
   STICKY BOOKING SUMMARY
   Apply sticky positioning to the GRID ITEM wrapper, not the
   inner .summary-panel. This lets the whole summary follow the
   long booking form while the PAGE remains the only scroll area.
   ========================================================= */
.booking-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 330px;
    gap: 22px;
    align-items: start;
}

/* The summary is wrapped in the second direct child of the grid. */
.booking-layout > div:last-child {
    position: -webkit-sticky;
    position: sticky;
    top: 88px;
    align-self: start;
    height: auto;
    max-height: none;
    overflow: visible;
}

/* Keep the panel itself in normal flow inside its sticky wrapper. */
.summary-panel {
    position: static;
    top: auto;
    height: auto;
    max-height: none;
    overflow: visible;
}

/* Tablet/mobile: stack the summary under the form. */
@media (max-width: 900px) {
    .booking-layout {
        grid-template-columns: minmax(0, 1fr);
        align-items: start;
    }

    .booking-layout > div:last-child {
        position: static;
        top: auto;
        max-height: none;
        overflow: visible;
    }

    .summary-panel {
        position: static;
        top: auto;
        max-height: none;
        overflow: visible;
    }
}

@media (max-width: 750px) {
    .site-header {
        padding: 12px 4%;
    }

    .brand span {
        display: none;
    }

    .header-links {
        gap: 2px;
    }

    .header-links a {
        padding: 7px;
        font-size: 10px;
    }

    .page {
        padding: 28px 4% 50px;
    }

    .schedule-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .time-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .therapist-list {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 550px) {
    .page-heading h1 {
        font-size: 27px;
    }

    .section {
        padding: 17px;
    }

    .form-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .form-group.full {
        grid-column: auto;
    }

    .selection-grid,
    .therapist-choice {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .time-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .summary-panel {
        padding: 17px;
    }

    .summary-value {
        overflow-wrap: anywhere;
    }
}


/* Compact calendar picker icon; keep the date field dimensions unchanged. */
input[type="date"] {
    color-scheme: dark;
    cursor: pointer;
}
input[type="date"]::-webkit-calendar-picker-indicator {
    width: 13px !important;
    height: 13px !important;
    max-width: 13px;
    max-height: 13px;
    padding: 0 !important;
    margin: 0 2px 0 5px !important;
    opacity: 1;
    cursor: pointer;
    filter: brightness(0) invert(1);
    background-size: 13px 13px;
}

/* MODERN MIZPAH TRANSACTION DIALOG */
.mizpah-dialog-backdrop{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.78);backdrop-filter:blur(5px)}
.mizpah-dialog-backdrop.is-visible{display:flex}
.mizpah-dialog{width:100%;max-width:390px;padding:28px 26px 24px;text-align:center;background:linear-gradient(145deg,#1b1a17,#10100f);border:1px solid rgba(214,194,156,.35);border-radius:17px;box-shadow:0 22px 70px rgba(0,0,0,.65);animation:mizpahDialogIn .18s ease-out}
.mizpah-dialog-icon{width:48px;height:48px;margin:0 auto 15px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(214,194,156,.5);border-radius:50%;color:#D6C29C;background:rgba(214,194,156,.09);font-family:Georgia,serif;font-size:25px}
.mizpah-dialog h2{margin:0 0 9px;color:#D6C29C;font-family:'Playfair Display',serif;font-size:21px;font-weight:600}
.mizpah-dialog p{margin:0;color:#c4c0b8;font-size:12px;line-height:1.75;overflow-wrap:anywhere}
.mizpah-dialog-button{min-width:112px;margin-top:22px;padding:10px 24px;border:1px solid #D6C29C;border-radius:8px;background:#D6C29C;color:#171512;font-family:Poppins,sans-serif;font-size:11px;font-weight:600;cursor:pointer;transition:.2s ease}
.mizpah-dialog-button:hover{background:#e5d4b2;transform:translateY(-1px)}
.mizpah-dialog-button:focus-visible{outline:2px solid #fff;outline-offset:3px}
@keyframes mizpahDialogIn{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
@media(max-width:480px){.mizpah-dialog{padding:25px 20px 21px}.mizpah-dialog h2{font-size:19px}}


.legacy-shared-service,.legacy-shared-therapists{display:none!important}
.guest-choice-card{border:1px solid rgba(214,194,156,.22);background:rgba(18,16,13,.72);border-radius:12px;padding:16px;margin:14px 0}
.guest-choice-card h3{font-family:'Playfair Display',serif;color:#D6C29C;font-size:17px;margin-bottom:12px}
.guest-choice-card .field-title{margin-top:12px}
.guest-choice-card select{width:100%;background:#171411;color:#fff;border:1px solid rgba(214,194,156,.35);border-radius:8px;padding:11px;font-family:inherit;font-size:11px;line-height:1.4}
.guest-choice-card .selection-grid{margin-bottom:8px}
.guest-choice-card .guest-addons{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:9px}
.guest-choice-card .guest-therapist-select{margin-top:10px}
.guest-choice-card .guest-line-total{color:#D6C29C;font-weight:600;margin-top:12px}
.guest-choice-card .select-card{min-height:42px}



/* Schedule fields share one row while space allows. */
.schedule-date-guests{
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:14px;
    margin-top:18px;
}

/* Therapist choices: guest panels side-by-side, names in tidy compact grids. */
#individualGuestTherapists{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
    align-items:start;
}
#individualGuestTherapists .guest-choice-card{
    min-width:0;
    margin:0;
    padding:13px;
}
#individualGuestTherapists .guest-choice-card h3{
    font-size:14px;
    margin-bottom:9px;
}
#individualGuestTherapists .guest-therapist-select{
    display:flex;
    flex-direction:column;
    gap:8px;
}
#individualGuestTherapists .guest-no-preference{
    display:inline-flex;
    align-self:flex-start;
    flex-direction:row;
    gap:5px;
    align-items:center;
    justify-content:center;
    width:auto;
    min-height:31px;
    padding:6px 11px;
    border-radius:18px;
    font-size:9px;
}
#individualGuestTherapists .guest-no-preference .card-desc{
    margin:0;
    font-size:8px;
}
.guest-therapist-list{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:7px;
    margin-top:2px;
}
.guest-therapist-list .select-card{
    display:flex;
    width:100%;
    min-width:0;
    min-height:35px;
    padding:7px 8px;
    margin:0;
    border-radius:8px;
    font-size:9px;
    line-height:1.25;
    white-space:normal;
    overflow-wrap:anywhere;
}
.guest-therapist-list .select-card.active{border-color:#D6C29C;}
.guest-therapist-list .select-card.unavailable{opacity:.38;}
.guest-no-preference.unavailable{opacity:.38;cursor:not-allowed;pointer-events:none;border-color:#6b6253!important;}
@media(max-width:750px){
    .schedule-date-guests{grid-template-columns:repeat(2,minmax(0,1fr));}
    #individualGuestTherapists{grid-template-columns:repeat(2,minmax(0,1fr));}
}
@media(max-width:550px){
    .schedule-date-guests{grid-template-columns:minmax(0,1fr);}
    #individualGuestTherapists{grid-template-columns:minmax(0,1fr);}
    .guest-therapist-list{grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;}
    .guest-therapist-list .select-card{min-height:32px;padding:7px;font-size:9px;}
}

</style>

</head>


<body>


<!-- =========================================================
     HEADER
     ========================================================= -->

<header class="site-header">

    <a
        href="index.php"
        class="brand"
    >

        <img
            src="assets/images/logo.png"
            alt="Mizpah Logo"
        >

        <span>
            Mizpah Wellness Spa
        </span>

    </a>


    <div class="header-links">

        <a href="index.php">
            Home
        </a>

        <a href="services.php">
            Services
        </a>

        <a href="therapist.php">
            Therapists
        </a>

        <a
            href="login.php"
            class="login-btn"
        >
            Login
        </a>

    </div>

</header>


<!-- =========================================================
     PAGE
     ========================================================= -->

<div class="page">


    <div class="page-heading">

        <div class="eyebrow">
            Guest Booking
        </div>

        <h1>
            Book an Appointment
        </h1>

        <p>
            No account is required. Choose your preferred
            room and schedule first, choose services for each
            guest, then select therapists and provide your details.
        </p>

    </div>


    <?php if (!empty($booking_error)): ?>

        <div class="error-box">
            <?= htmlspecialchars($booking_error) ?>
        </div>

    <?php endif; ?>


    <form
        method="POST"
        id="bookingForm"
    >

        <input type="hidden" name="guest_selections" id="guest_selections" value="[]">


        <div class="booking-layout">


            <!-- =================================================
                 LEFT COLUMN
                 ================================================= -->

            <div>


                <!-- =============================================
                     STEP 2
                     ============================================= -->

                <div class="section">

                    <div class="section-head">

                        <div class="step">
                            1
                        </div>

                        <div>

                            <div class="section-title">
                                Schedule & Room
                            </div>

                            <div class="section-sub">
                                Choose your room and appointment date.
                                Select your time after choosing services.
                            </div>

                        </div>

                    </div>


                    <!-- ROOM -->

                    <div class="field-title">
                        Room Type
                    </div>

                    <div class="selection-grid">

                        <div
                            class="select-card room"
                            data-room="Single Room"
                        >

                            Single Room

                            <div class="card-desc">
                                Individual booking
                            </div>

                        </div>


                        <div
                            class="select-card room"
                            data-room="Couple Room"
                        >

                            Couple Room

                            <div class="card-desc">
                                Automatically 2 pax
                            </div>

                        </div>

                    </div>


                    <input
                        type="hidden"
                        name="room_type"
                        id="room_type"
                    >


                    <div class="schedule-grid schedule-date-guests">
                        <div>
                            <label for="booking_date">
                                Appointment Date <span class="required">*</span>
                            </label>
                            <input
                                type="date"
                                id="booking_date"
                                name="booking_date"
                                required
                            >
                        </div>

                        <div>
                            <label for="pax">
                                Number of Guests <span class="required">*</span>
                            </label>
                            <input
                                type="number"
                                id="pax"
                                name="pax"
                                value="1"
                                min="1"
                                max="4"
                                required
                            >
                            <p class="message" style="margin:6px 0 0;">Select how many guests will book.</p>
                        </div>
                    </div>


                </div>


                <!-- =============================================
                     STEP 1
                     ============================================= -->

                <div class="section">

                    <div class="section-head">

                        <div class="step">
                            2
                        </div>

                        <div>

                            <div class="section-title">
                                Choose Your Service
                            </div>

                            <div class="section-sub">
                                Select a category, service,
                                duration and optional add-ons.
                            </div>

                        </div>

                    </div>


                    <div id="individualGuestServices"></div>
                    <p class="message" style="margin:8px 0 18px;">Each guest can choose a different service, duration, and add-ons.</p>
                    <div class="legacy-shared-service" aria-hidden="true">
                    <!-- CATEGORY -->

                    <div class="field-title">
                        Category
                    </div>

                    <div class="selection-grid">

                        <div
                            class="select-card category"
                            data-cat="Massage"
                        >
                            Massage
                        </div>

                        <div
                            class="select-card category"
                            data-cat="Package"
                        >
                            Package
                        </div>

                        <div
                            class="select-card category"
                            data-cat="Promo"
                        >
                            Promo
                        </div>

                    </div>


                    <!-- SERVICE -->

                    <div class="field-title">
                        Service
                    </div>

                    <div
                        class="selection-grid"
                        id="serviceBox"
                    >

                        <div
                            class="message"
                            style="grid-column:1/-1;"
                        >
                            Select a category first.
                        </div>

                    </div>


                    <div
                        id="serviceDesc"
                        class="service-description"
                    >
                        Select a service to view
                        its description.
                    </div>


                    <!-- DURATION -->

                    <div class="field-title">
                        Duration
                    </div>

                    <div
                        class="selection-grid"
                        id="durationBox"
                    >

                        <div
                            class="message"
                            style="grid-column:1/-1;"
                        >
                            Select a service first.
                        </div>

                    </div>


                    <!-- ADDONS -->

                    <div class="field-title">

                        Add-ons

                        <span
                            style="
                                color:#555;
                                text-transform:none;
                            "
                        >
                            (Optional)
                        </span>

                    </div>

                    <div
                        class="selection-grid"
                        id="addonBox"
                    >

                        <div
                            class="message"
                            style="grid-column:1/-1;"
                        >
                            Add-ons will appear after
                            selecting a service.
                        </div>

                    </div>


                    <input
                        type="hidden"
                        name="addons"
                        id="addons"
                    >
                    </div><!-- legacy shared service controls -->

                </div>


                <!-- =============================================
                     STEP 3
                     ============================================= -->

                <div class="section">

                    <div class="section-head">

                        <div class="step">
                            3
                        </div>

                        <div>

                            <div class="section-title">
                                Appointment Time & Therapist
                            </div>

                            <div class="section-sub">
                                Choose an available time for your selected date and services,
                                then select a therapist for each guest or choose No Preference.
                            </div>

                        </div>

                    </div>


                    <!-- TIME -->

                    <div class="field-title">
                        Available Time
                    </div>

                    <div
                        class="time-grid"
                        id="timeBox"
                    >

                        <div
                            class="message"
                            style="grid-column:1/-1;"
                        >
                            Select an appointment date first.
                        </div>

                    </div>

                    <p class="message">
                        Note: Please book at least 2 hours before your desired service time.
                    </p>

                    <input
                        type="hidden"
                        name="booking_time"
                        id="booking_time"
                    >


                    <div id="individualGuestTherapists"></div>
                    <div class="legacy-shared-therapists" aria-hidden="true">
                    <div class="therapist-choice">

                        <div
                            class="select-card therapist-option active"
                            data-therapist-option="0"
                        >

                            <strong>
                                No Preference
                            </strong>

                            <span class="card-desc">
                                Any available therapist
                            </span>

                        </div>


                        <div
                            class="select-card therapist-option"
                            data-therapist-option="choose"
                        >

                            <strong>
                                Choose Therapist
                            </strong>

                            <span class="card-desc">
                                Select a specific therapist
                            </span>

                        </div>

                    </div>


                    <div
                        id="therapistList"
                        class="therapist-list"
                    ></div>


                    <div
                        id="therapistMessage"
                        class="message"
                    >
                        Select a date and time first
                        to view therapist availability.
                    </div>


                    <input type="hidden" name="therapist_mode" id="therapist_mode" value="0">
                    <input type="hidden" name="therapist" id="therapist" value="0">
                    <div id="therapistInputs"></div>
                    </div><!-- legacy shared therapist controls -->

                </div>


                <!-- =============================================
                     STEP 4
                     ============================================= -->

                <div class="section">

                    <div class="section-head">

                        <div class="step">
                            4
                        </div>

                        <div>

                            <div class="section-title">
                                Guest Details
                            </div>

                            <div class="section-sub">
                                Enter your contact information
                                so the spa can identify your
                                appointment.
                            </div>

                        </div>

                    </div>


                    <div class="form-grid">


                        <div class="form-group">

                            <label>

                                Full Name

                                <span class="required">
                                    *
                                </span>

                            </label>

                            <input
                                type="text"
                                name="customer_name"
                                placeholder="Enter your full name"
                                pattern="[A-Za-zÀ-ÿ .'-]{2,100}"
                                title="Please enter your full name using letters and spaces only."
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label>

                                Phone Number

                                <span class="required">
                                    *
                                </span>

                            </label>

                            <input
                                type="text"
                                name="phone"
                                placeholder="09XXXXXXXXX"
                                inputmode="numeric"
                                maxlength="11"
                                pattern="09[0-9]{9}"
                                title="Enter an 11-digit Philippine mobile number starting with 09."
                                required
                            >

                        </div>


                        <div class="form-group">
                            <label>Email Address <span class="required">*</span></label>
                            <input type="email" name="email" placeholder="Enter your email address" maxlength="150" autocomplete="email" required>
                        </div>

                        <div class="form-group full" style="margin-top:-4px;">
                            <p class="message" style="margin:0;color:#aaa;">
                                <span style="color:#D6C29C;font-weight:600;">Email notifications:</span>
                                We will automatically send updates to this email address when your booking is confirmed, cancelled, or marked as completed.
                            </p>
                        </div>

                        <div class="form-group full" id="guestNamesGroup">
                            <label>Other Guests' Full Names <span style="color:#555; text-transform:none;">(Guest 2 onward)</span></label>
                            <div id="guestNamesFields"></div>
                            <p class="message">Your Full Name is Guest 1. Enter only the other guests below.</p>
                        </div>

                        <div class="form-group full">
                            <div class="section-title" style="margin-bottom:8px;">Wellness Profile</div>
                            <div class="section-sub" style="margin-bottom:14px;">Each guest must complete their own profile before the booking can be confirmed.</div>
                            <div class="form-group full" style="margin-bottom:16px;">
                                <details class="wellness-terms-details" style="border:1px solid rgba(214,194,156,.25);border-radius:8px;padding:12px;">
                                    <summary style="cursor:pointer;color:#D6C29C;font-weight:600;font-size:11px;line-height:1.4;">Wellness Terms and Conditions (Click to view)</summary>
                                    <div class="wellness-terms-text" style="max-height:180px;overflow:auto;margin-top:12px;padding:12px;border:1px solid rgba(214,194,156,.18);border-radius:8px;color:#aaa;white-space:pre-line;font-size:12px;line-height:1.7;"></div>
                                </details>
                                <label style="display:flex;gap:9px;align-items:flex-start;margin-top:10px;text-transform:none;">
                                    <input type="checkbox" name="wellness_terms_accepted" value="1" required style="width:auto;margin-top:3px;">
                                    I have read and agree to the Wellness Profile Terms and Conditions. <span class="required">*</span>
                                </label>
                            </div>
                            <div id="guestWellnessProfiles"></div>
                        </div>

                        <div class="form-group full">

                            <label>

                                Payment Method

                                <span class="required">
                                    *
                                </span>

                            </label>

                            <select
                                name="payment_method"
                                required
                            >

                                <option value="Cash">
                                    Cash
                                </option>

                                <option value="GCash">
                                    GCash
                                </option>

                            </select>

                        </div>


                    </div>

                </div>


            </div>


            <!-- =================================================
                 SUMMARY
                 ================================================= -->

            <div>

                <div class="summary-panel">

                    <h3>
                        Booking Summary
                    </h3>

                    <div class="summary-sub">
                        Review your appointment before booking.
                    </div>


                    <div class="summary-row">

                        <span class="summary-label">
                            Service
                        </span>

                        <span
                            class="summary-value"
                            id="sumService"
                        >
                            —
                        </span>

                    </div>


                    <div class="summary-row">

                        <span class="summary-label">
                            Duration
                        </span>

                        <span
                            class="summary-value"
                            id="sumDuration"
                        >
                            —
                        </span>

                    </div>


                    <div class="summary-row">

                        <span class="summary-label">
                            Room
                        </span>

                        <span
                            class="summary-value"
                            id="sumRoom"
                        >
                            —
                        </span>

                    </div>


                    <div class="summary-row">

                        <span class="summary-label">
                            Guests
                        </span>

                        <span
                            class="summary-value"
                            id="sumPax"
                        >
                            1
                        </span>

                    </div>


                    <div class="summary-row">

                        <span class="summary-label">
                            Date
                        </span>

                        <span
                            class="summary-value"
                            id="sumDate"
                        >
                            —
                        </span>

                    </div>


                    <div class="summary-row">

                        <span class="summary-label">
                            Time
                        </span>

                        <span
                            class="summary-value"
                            id="sumTime"
                        >
                            —
                        </span>

                    </div>


                    <div class="summary-row">

                        <span class="summary-label">
                            Therapist
                        </span>

                        <span
                            class="summary-value"
                            id="sumTherapist"
                        >
                            No Preference
                        </span>

                    </div>


                    <div class="summary-row">

                        <span class="summary-label">
                            Add-ons
                        </span>

                        <span
                            class="summary-value summary-addons"
                            id="sumAddons"
                        >
                            None
                        </span>

                    </div>


                    <div class="price-area">


                        <div class="price-line">

                            <span>
                                Service
                            </span>

                            <span id="sumBasePrice">
                                ₱0.00
                            </span>

                        </div>


                        <div class="price-line">

                            <span>
                                Add-ons
                            </span>

                            <span id="sumAddonPrice">
                                ₱0.00
                            </span>

                        </div>


                        <div class="total-line">

                            <span class="total-label">
                                Total
                            </span>

                            <span
                                class="total-price"
                                id="sumTotal"
                            >
                                ₱0.00
                            </span>

                        </div>


                    </div>


                    <button
                        type="submit"
                        name="submit_booking"
                        class="book-btn"
                    >
                        Confirm Booking
                    </button>


                    <div class="booking-note">
                        No account is required. Your booking
                        will initially be submitted as Confirmation.
                    </div>

                </div>

            </div>


        </div>


        <!-- =================================================
             HIDDEN VALUES
             ================================================= -->

        <input
            type="hidden"
            name="service_id"
            id="service_id"
        >

        <input
            type="hidden"
            name="service"
            id="service"
        >

        <input
            type="hidden"
            name="duration"
            id="duration"
        >

        <input
            type="hidden"
            name="price"
            id="price"
            value="0"
        >


    </form>

</div>



<!-- Modern dialog used by guest-booking validation messages. -->
<div class="mizpah-dialog-backdrop" id="mizpahDialogBackdrop" aria-hidden="true">
  <div class="mizpah-dialog" role="alertdialog" aria-modal="true" aria-labelledby="mizpahDialogTitle" aria-describedby="mizpahDialogMessage">
    <div class="mizpah-dialog-icon" aria-hidden="true">!</div>
    <h2 id="mizpahDialogTitle">Mizpah Wellness Spa</h2>
    <p id="mizpahDialogMessage"></p>
    <button type="button" class="mizpah-dialog-button" id="mizpahDialogOk">OK</button>
  </div>
</div>

<script>

(function () {
    const backdrop = document.getElementById('mizpahDialogBackdrop');
    const messageBox = document.getElementById('mizpahDialogMessage');
    const okButton = document.getElementById('mizpahDialogOk');
    if (!backdrop || !messageBox || !okButton) return;

    let previousFocus = null;
    function closeMizpahDialog() {
        backdrop.classList.remove('is-visible');
        backdrop.setAttribute('aria-hidden', 'true');
        if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    }

    // Existing alert(message) calls now display this custom modal.
    window.alert = function (message) {
        previousFocus = document.activeElement;
        messageBox.textContent = String(message);
        backdrop.classList.add('is-visible');
        backdrop.setAttribute('aria-hidden', 'false');
        okButton.focus();
    };

    okButton.addEventListener('click', closeMizpahDialog);
    backdrop.addEventListener('click', function (event) {
        if (event.target === backdrop) closeMizpahDialog();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && backdrop.classList.contains('is-visible')) closeMizpahDialog();
    });
})();


/* =========================================================
   DOM
   ========================================================= */

const serviceBox =
    document.getElementById('serviceBox');

const serviceDesc =
    document.getElementById('serviceDesc');

const durationBox =
    document.getElementById('durationBox');

const addonBox =
    document.getElementById('addonBox');

const timeBox =
    document.getElementById('timeBox');

const therapistList =
    document.getElementById('therapistList');

const therapistMessage =
    document.getElementById('therapistMessage');

const serviceIdInput =
    document.getElementById('service_id');

const serviceInput =
    document.getElementById('service');

const durationInput =
    document.getElementById('duration');

const priceInput =
    document.getElementById('price');

const addonsInput =
    document.getElementById('addons');

const roomInput =
    document.getElementById('room_type');

const bookingDate =
    document.getElementById('booking_date');

const bookingTime =
    document.getElementById('booking_time');

const therapistInput =
    document.getElementById('therapist');
const therapistModeInput = document.getElementById('therapist_mode');
const therapistInputs = document.getElementById('therapistInputs');
let selectedTherapistIds = [];
let selectedTherapistNames = [];

const paxInput =
    document.getElementById('pax');

// Full Name is saved as Guest 1. Render inputs only for Guest 2 onward.
function renderGuestNameFields() {
    const wrap = document.getElementById('guestNamesFields');
    if (!wrap || !paxInput) return;
    const count = roomInput && roomInput.value === 'Couple Room' ? 2 : Math.max(1, Math.min(4, Number(paxInput.value || 1)));
    const old = Array.from(wrap.querySelectorAll('input[name="guest_names[]"]')).map(x => x.value);
    wrap.innerHTML = '';
    for (let guestNumber = 2; guestNumber <= count; guestNumber++) {
        const label = document.createElement('label');
        label.textContent = 'Guest ' + guestNumber + ' Full Name';
        label.style.marginTop = '10px';
        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'guest_names[]';
        input.required = true;
        input.placeholder = 'Enter Guest ' + guestNumber + ' full name';
        input.value = old[guestNumber - 2] || '';
        wrap.appendChild(label);
        wrap.appendChild(input);
    }
}



/* =========================================================
   PRICE
   ========================================================= */

let basePrice = 0;

let addonTotal = 0;


/* =========================================================
   SUMMARY DATA
   ========================================================= */

let summary = {

    service: '—',

    duration: '—',

    room: '—',

    pax: '1',

    date: '—',

    time: '—',

    therapist: 'No Preference',

    addons: 'None'

};


function money(value){

    return '₱' +
        Number(value || 0)
        .toLocaleString(
            'en-PH',
            {
                minimumFractionDigits:2,
                maximumFractionDigits:2
            }
        );

}


function renderSummary(){

    document.getElementById(
        'sumService'
    ).textContent =
        summary.service;

    document.getElementById(
        'sumDuration'
    ).textContent =
        summary.duration;

    document.getElementById(
        'sumRoom'
    ).textContent =
        summary.room;

    document.getElementById(
        'sumPax'
    ).textContent =
        summary.pax;

    document.getElementById(
        'sumDate'
    ).textContent =
        summary.date;

    document.getElementById(
        'sumTime'
    ).textContent =
        summary.time;

    document.getElementById(
        'sumTherapist'
    ).textContent =
        summary.therapist;

    document.getElementById(
        'sumAddons'
    ).textContent =
        summary.addons;


    document.getElementById(
        'sumBasePrice'
    ).textContent =
        money(basePrice);


    document.getElementById(
        'sumAddonPrice'
    ).textContent =
        money(addonTotal);


    const total = Number(basePrice) + Number(addonTotal);


    document.getElementById(
        'sumTotal'
    ).textContent =
        money(total);


    /*
       IMPORTANT:
       Save complete total:
       service/duration + add-ons
    */

    priceInput.value =
        total.toFixed(2);

}


/* =========================================================
   RESET SERVICE
   ========================================================= */

function resetServiceSelection(){

    serviceIdInput.value = '';

    serviceInput.value = '';

    durationInput.value = '';

    addonsInput.value = '';

    basePrice = 0;

    addonTotal = 0;


    summary.service = '—';

    summary.duration = '—';

    summary.addons = 'None';


    serviceDesc.textContent =
        'Select a service to view its description.';


    durationBox.innerHTML = `
        <div
            class="message"
            style="grid-column:1/-1;"
        >
            Select a service first.
        </div>
    `;


    addonBox.innerHTML = `
        <div
            class="message"
            style="grid-column:1/-1;"
        >
            Add-ons will appear after
            selecting a service.
        </div>
    `;


    renderSummary();

}


/* =========================================================
   CATEGORY
   ========================================================= */

document.addEventListener(
    'click',
    function(e){

        const category =
            e.target.closest('.category');

        if(!category){
            return;
        }


        document
        .querySelectorAll('.category')
        .forEach(function(card){

            card.classList.remove(
                'active'
            );

        });


        category.classList.add(
            'active'
        );


        resetServiceSelection();


        serviceBox.innerHTML = `
            <div
                class="message"
                style="grid-column:1/-1;"
            >
                Loading services...
            </div>
        `;


        fetch(
            'get_services_by_category.php?cat=' +
            encodeURIComponent(
                category.dataset.cat
            )
        )

        .then(function(response){
            return response.json();
        })

        .then(function(data){

            serviceBox.innerHTML = '';


            if(
                !Array.isArray(data) ||
                data.length === 0
            ){

                serviceBox.innerHTML = `
                    <div
                        class="message"
                        style="grid-column:1/-1;"
                    >
                        No services available.
                    </div>
                `;

                return;

            }


            data.forEach(function(service){

                const card =
                    document.createElement(
                        'div'
                    );


                card.className =
                    'select-card service';


                card.dataset.id =
                    service.id;


                card.dataset.name =
                    service.service_name;


                card.dataset.desc =
                    service.description || '';


                card.textContent =
                    service.service_name;


                serviceBox.appendChild(
                    card
                );

            });

        })

        .catch(function(error){

            console.error(
                'Service error:',
                error
            );


            serviceBox.innerHTML = `
                <div
                    class="message"
                    style="grid-column:1/-1;"
                >
                    Unable to load services.
                </div>
            `;

        });

    }
);


/* =========================================================
   SERVICE
   ========================================================= */

document.addEventListener(
    'click',
    function(e){

        const selectedService =
            e.target.closest('.service');

        if(!selectedService){
            return;
        }


        document
        .querySelectorAll('.service')
        .forEach(function(card){

            card.classList.remove(
                'active'
            );

        });


        selectedService.classList.add(
            'active'
        );


        serviceIdInput.value =
            selectedService.dataset.id;


        serviceInput.value =
            selectedService.dataset.name;


        serviceDesc.textContent =
            selectedService.dataset.desc ||
            'No description available.';


        summary.service =
            selectedService.dataset.name;


        summary.duration = '—';

        summary.addons = 'None';


        durationInput.value = '';

        addonsInput.value = '';


        basePrice = 0;

        addonTotal = 0;


        renderSummary();


        /* =============================================
           LOAD DURATION
           ============================================= */

        durationBox.innerHTML = `
            <div
                class="message"
                style="grid-column:1/-1;"
            >
                Loading durations...
            </div>
        `;


        fetch(
            'get_duration.php?id=' +
            encodeURIComponent(
                selectedService.dataset.id
            )
        )

        .then(function(response){
            return response.json();
        })

        .then(function(data){

            durationBox.innerHTML = '';


            if(
                !Array.isArray(data) ||
                data.length === 0
            ){

                durationBox.innerHTML = `
                    <div
                        class="message"
                        style="grid-column:1/-1;"
                    >
                        No duration available.
                    </div>
                `;

                return;

            }


            data.forEach(function(item){

                const card =
                    document.createElement(
                        'div'
                    );


                card.className =
                    'select-card duration';


                card.dataset.duration =
                    item.duration;


                card.dataset.price =
                    item.price;


                card.innerHTML = `
                    <span>
                        ${item.duration}
                    </span>

                    <span class="card-price">
                        ${money(item.price)}
                    </span>
                `;


                durationBox.appendChild(
                    card
                );

            });

            // Packages use their fixed duration automatically when only one duration is available.
            const selectedCategory = document.querySelector('.category.active');
            if (selectedCategory && selectedCategory.dataset.cat === 'Package' && data.length === 1) {
                const onlyDuration = durationBox.querySelector('.duration');
                if (onlyDuration) {
                    onlyDuration.click();
                    onlyDuration.style.pointerEvents = 'none';
                    onlyDuration.style.cursor = 'default';
                    onlyDuration.title = 'Package duration is fixed.';
                }
            }

        })

        .catch(function(error){

            console.error(
                'Duration error:',
                error
            );

        });


        /* =============================================
           LOAD ADDONS
           ============================================= */

        addonBox.innerHTML = `
            <div
                class="message"
                style="grid-column:1/-1;"
            >
                Loading add-ons...
            </div>
        `;


        fetch('get_addons.php')

        .then(function(response){
            return response.json();
        })

        .then(function(data){

            addonBox.innerHTML = '';


            if(
                !Array.isArray(data) ||
                data.length === 0
            ){

                addonBox.innerHTML = `
                    <div
                        class="message"
                        style="grid-column:1/-1;"
                    >
                        No add-ons available.
                    </div>
                `;

                return;

            }


            data.forEach(function(addon){

                const card =
                    document.createElement(
                        'div'
                    );


                card.className =
                    'select-card addon';


                card.dataset.name =
                    addon.service_name;


                card.dataset.price =
                    addon.price;


                card.innerHTML = `
                    <span>
                        ${addon.service_name}
                    </span>

                    <span class="card-price">
                        ${money(addon.price)}
                    </span>

                    ${
                        addon.description
                        ?
                        `
                            <span class="card-desc">
                                ${addon.description}
                            </span>
                        `
                        :
                        ''
                    }
                `;


                addonBox.appendChild(
                    card
                );

            });

        })

        .catch(function(error){

            console.error(
                'Add-ons error:',
                error
            );

        });

    }
);


/* =========================================================
   DURATION
   ========================================================= */

document.addEventListener(
    'click',
    function(e){

        const selectedDuration =
            e.target.closest('.duration');

        if(!selectedDuration){
            return;
        }


        document
        .querySelectorAll('.duration')
        .forEach(function(card){

            card.classList.remove(
                'active'
            );

        });


        selectedDuration.classList.add(
            'active'
        );


        durationInput.value =
            selectedDuration.dataset.duration;


        basePrice =
            parseFloat(
                selectedDuration.dataset.price
            ) || 0;


        summary.duration =
            selectedDuration.dataset.duration;


        renderSummary();


        /*
           Duration can affect therapist availability.
        */

        if(
            bookingDate.value &&
            bookingTime.value
        ){

            const chooseOption =
                document.querySelector(
                    '.therapist-option[data-therapist-option="choose"]'
                );


            if(
                chooseOption &&
                chooseOption.classList.contains(
                    'active'
                )
            ){

                loadTherapists(
                    bookingDate.value,
                    bookingTime.value
                );

            }

        }

    }
);


/* =========================================================
   ADD-ONS
   ========================================================= */

document.addEventListener(
    'click',
    function(e){

        const selectedAddon =
            e.target.closest('.addon');

        if(!selectedAddon){
            return;
        }


        selectedAddon.classList.toggle(
            'active'
        );


        const names = [];

        addonTotal = 0;


        document
        .querySelectorAll(
            '.addon.active'
        )
        .forEach(function(card){

            names.push(
                card.dataset.name
            );


            addonTotal +=
                parseFloat(
                    card.dataset.price
                ) || 0;

        });


        addonsInput.value =
            names.join(', ');


        summary.addons =
            names.length
            ? names.join(', ')
            : 'None';


        renderSummary();

    }
);


/* =========================================================
   ROOM
   ========================================================= */

document.addEventListener(
    'click',
    function(e){

        const selectedRoom =
            e.target.closest('.room');

        if(!selectedRoom){
            return;
        }


        document
        .querySelectorAll('.room')
        .forEach(function(card){

            card.classList.remove(
                'active'
            );

        });


        selectedRoom.classList.add(
            'active'
        );


        roomInput.value =
            selectedRoom.dataset.room;


        summary.room =
            selectedRoom.dataset.room;
        selectedTherapistIds = []; selectedTherapistNames = []; therapistInputs.innerHTML = ''; therapistInput.value='0'; therapistModeInput.value='0';


        if(
            selectedRoom.dataset.room ===
            'Couple Room'
        ){

            paxInput.value = 2;

            paxInput.readOnly = true;

            summary.pax = '2';

        }else{

            paxInput.value = 1;

            paxInput.readOnly = false;

            summary.pax = '1';

        }


        renderSummary();

        // Room capacity and slot counts depend on the selected room and pax.
        // Reload the available times immediately when the room changes.
        if (bookingDate.value) {
            bookingDate.dispatchEvent(new Event('change'));
        }

    }
);


/* =========================================================
   PAX
   ========================================================= */

paxInput.addEventListener(
    'input',
    function(){

        let value =
            parseInt(
                paxInput.value
            ) || 1;


        if(value < 1){
            value = 1;
        }


        if(value > 4){
            value = 4;
        }


        paxInput.value = value;

        summary.pax =
            String(value);
        selectedTherapistIds = []; selectedTherapistNames = []; therapistInputs.innerHTML=''; therapistInput.value='0';
        document.querySelectorAll('.therapist-card.active').forEach(function(c){c.classList.remove('active');});
        if (therapistModeInput.value === 'choose' && bookingDate.value && bookingTime.value) loadTherapists(bookingDate.value, bookingTime.value);


        renderSummary();

    }
);


/* =========================================================
   MIN DATE
   ========================================================= */

const today =
    new Date();


const year =
    today.getFullYear();


const month =
    String(
        today.getMonth() + 1
    ).padStart(
        2,
        '0'
    );


const day =
    String(
        today.getDate()
    ).padStart(
        2,
        '0'
    );


bookingDate.min =
    `${year}-${month}-${day}`;


/* =========================================================
   THERAPIST RESET
   ========================================================= */

function resetTherapist(){

    document
    .querySelectorAll(
        '.therapist-option'
    )
    .forEach(function(card){

        card.classList.remove(
            'active'
        );

    });


    const noPreference =
        document.querySelector(
            '.therapist-option[data-therapist-option="0"]'
        );


    if(noPreference){

        noPreference.classList.add(
            'active'
        );

    }


    therapistInput.value = '0';
    therapistModeInput.value = '0';
    selectedTherapistIds = [];
    selectedTherapistNames = [];
    therapistInputs.innerHTML = '';
    therapistList.innerHTML = '';

    summary.therapist = 'No Preference';


    therapistMessage.textContent =
        'Select a date and time first to view therapist availability.';


    renderSummary();

}


/* =========================================================
   THERAPIST OPTION
   ========================================================= */

document.addEventListener(
    'click',
    function(e){

        const option =
            e.target.closest(
                '.therapist-option'
            );

        if(!option){
            return;
        }


        document
        .querySelectorAll(
            '.therapist-option'
        )
        .forEach(function(card){

            card.classList.remove(
                'active'
            );

        });


        option.classList.add(
            'active'
        );


        const value =
            option.dataset.therapistOption;


        if(value === '0'){

            therapistInput.value = '0';
            therapistModeInput.value = '0';
            selectedTherapistIds = []; selectedTherapistNames = []; therapistInputs.innerHTML = '';

            therapistList.innerHTML = '';


            summary.therapist =
                'No Preference';


            therapistMessage.textContent =
                'No Preference selected. Any available therapist may be assigned.';


            renderSummary();

            return;

        }


        if(value === 'choose'){

            therapistInput.value = '0';
            therapistModeInput.value = 'choose';
            selectedTherapistIds = []; selectedTherapistNames = []; therapistInputs.innerHTML = '';

            summary.therapist =
                'No Preference';


            renderSummary();


            if(
                !bookingDate.value ||
                !bookingTime.value
            ){

                therapistList.innerHTML = '';

                therapistMessage.textContent =
                    'Please select a date and time first.';

                return;

            }


            loadTherapists(
                bookingDate.value,
                bookingTime.value
            );

        }

    }
);


/* =========================================================
   LOAD THERAPISTS
   ========================================================= */

function loadTherapists(
    selectedDate,
    selectedTime
){

    therapistList.innerHTML = '';

    therapistMessage.textContent =
        'Loading therapists...';


    let selectedDuration =
        durationInput.value ||
        '1 Hour';


    fetch(
        'get_available_therapists.php?date=' +
        encodeURIComponent(
            selectedDate
        ) +
        '&time=' +
        encodeURIComponent(
            selectedTime
        ) +
        '&duration=' +
        encodeURIComponent(
            selectedDuration
        )
    )

    .then(function(response){
        return response.json();
    })

    .then(function(data){

        therapistList.innerHTML = '';


        if(
            !Array.isArray(data) ||
            data.length === 0
        ){

            therapistMessage.textContent =
                'No therapists found.';

            return;

        }


        therapistMessage.textContent =
            'Select an available therapist.';


        data.forEach(function(therapist){

            const therapistId =
                therapist.id ?? '';


            const therapistName =
                therapist.name ||
                'Unnamed Therapist';


            const isAvailable =
                therapist.available === undefined
                ? true
                : Boolean(
                    therapist.available
                );


            const card =
                document.createElement(
                    'div'
                );


            card.className =
                'select-card therapist-card';


            card.dataset.therapistId =
                therapistId;


            card.dataset.therapistName =
                therapistName;


            if(!isAvailable){

                card.classList.add(
                    'unavailable'
                );


                card.innerHTML = `

                    <strong>
                        ${therapistName}
                    </strong>

                    <span
                        class="
                            card-desc
                            unavailable-text
                        "
                    >
                        Booked / Unavailable
                    </span>

                `;

            }else{

                card.innerHTML = `

                    <strong>
                        ${therapistName}
                    </strong>

                    <span
                        class="
                            card-desc
                            available-text
                        "
                    >
                        Available
                    </span>

                `;


                card.addEventListener(
                    'click',
                    function(){

                        const id = String(therapistId);
                        const pos = selectedTherapistIds.indexOf(id);
                        const maxPick = Number(paxInput.value || 1);
                        if (pos >= 0) {
                            selectedTherapistIds.splice(pos, 1);
                            selectedTherapistNames.splice(pos, 1);
                            card.classList.remove('active');
                        } else {
                            if (selectedTherapistIds.length >= maxPick) {
                                alert('Select only ' + maxPick + ' therapist(s) for this booking.');
                                return;
                            }
                            selectedTherapistIds.push(id);
                            selectedTherapistNames.push(therapistName);
                            card.classList.add('active');
                        }
                        therapistInput.value = selectedTherapistIds[0] || '0';
                        therapistInputs.innerHTML = selectedTherapistIds.map(function(tid){
                            return '<input type="hidden" name="therapists[]" value="' + tid + '">';
                        }).join('');
                        summary.therapist = selectedTherapistIds.length
                            ? selectedTherapistNames.join(', ') + ' (' + selectedTherapistIds.length + '/' + maxPick + ')'
                            : 'Select ' + maxPick + ' therapist(s)';
                        therapistMessage.textContent = 'Selected ' + selectedTherapistIds.length + ' of ' + maxPick + ' therapist(s).';
                        renderSummary();

                    }
                );

            }


            therapistList.appendChild(
                card
            );

        });

    })

    .catch(function(error){

        console.error(
            'Therapist error:',
            error
        );


        therapistList.innerHTML = '';


        therapistMessage.textContent =
            'Unable to load therapists. Please try again.';

    });

}


function getOpeningHour(dateString) {
    const d = new Date(dateString + 'T12:00:00');
    return (d.getDay() === 0 || d.getDay() === 6) ? 13 : 15;
}
function durationHours(value) {
    const text = String(value || '1 Hour').toLowerCase();
    let m = text.match(/(\d+(?:\.\d+)?)\s*(?:hours?|hrs?)/);
    if (m) return parseFloat(m[1]);
    m = text.match(/(\d+)\s*(?:minutes?|mins?)/);
    if (m) return parseInt(m[1],10) / 60;
    m = text.match(/\d+(?:\.\d+)?/);
    if (m) { const n=parseFloat(m[0]); return n>3 ? n/60 : n; }
    return 1;
}
function selectedTimeDate(dateString, timeString) {
    const [h,m] = timeString.split(':').map(Number);
    const d = new Date(dateString + 'T00:00:00');
    d.setHours(h,m,0,0);
    if (h < getOpeningHour(dateString)) d.setDate(d.getDate()+1);
    return d;
}
function slotWithinHours(dateString, timeString, duration) {
    const [h,m] = timeString.split(':').map(Number);
    let opHour=h; if(h<getOpeningHour(dateString)) opHour+=24;
    return opHour*60+m + durationHours(duration)*60 <= 27*60;
}
function slotHasLeadTime(dateString, timeString) {
    return selectedTimeDate(dateString,timeString).getTime() >= Date.now()+2*60*60*1000;
}

/* =========================================================
   DATE CHANGE / LOAD TIME
   ========================================================= */

bookingDate.addEventListener(
    'change',
    async function(){

        timeBox.innerHTML = '';

        bookingTime.value = '';


        resetTherapist();


        const selectedDate =
            bookingDate.value;


        if(!selectedDate){

            summary.date = '—';

            summary.time = '—';

            renderSummary();

            return;

        }


        const dateObject =
            new Date(
                selectedDate +
                'T00:00:00'
            );


        summary.date =
            dateObject.toLocaleDateString(
                'en-US',
                {
                    month:'short',
                    day:'numeric',
                    year:'numeric'
                }
            );


        summary.time = '—';


        renderSummary();


        timeBox.innerHTML = `
            <div
                class="message"
                style="grid-column:1/-1;"
            >
                Checking available time slots...
            </div>
        `;


        const cards = [];


        /*
           Operating hours: weekdays 3 PM–3 AM, weekends 1 PM–3 AM
        */

        const openingHour = getOpeningHour(selectedDate);
        for(
            let operatingMinute = openingHour * 60;
            operatingMinute <= 26 * 60;
            operatingMinute += 30
        ){

            // Visually separate slots that belong to the next calendar date.
            if (operatingMinute === 24 * 60) {
                const afterMidnightDate = new Date(selectedDate + 'T00:00:00');
                afterMidnightDate.setDate(afterMidnightDate.getDate() + 1);
                const dateLabel = afterMidnightDate.toLocaleDateString('en-US', {
                    month: 'short', day: 'numeric', year: 'numeric'
                });
                const divider = document.createElement('div');
                divider.className = 'after-midnight-divider';
                divider.style.cssText = 'grid-column:1/-1; padding:12px 4px 2px; margin-top:4px; border-top:1px solid rgba(212,175,104,.22); color:#d4af68; font-size:12px; font-weight:600; letter-spacing:.06em;';
                divider.textContent = 'AFTER MIDNIGHT · ' + dateLabel;
                cards.push(divider);
            }

            const actualHour = Math.floor(operatingMinute / 60) % 24;
            const actualMinute = operatingMinute % 60;
            const timeValue = String(actualHour).padStart(2, '0') + ':' + String(actualMinute).padStart(2, '0');


            try{

                const response =
                    await fetch(
                        'check_slot.php?date=' +
                        encodeURIComponent(
                            selectedDate
                        ) +
                        '&time=' +
                        encodeURIComponent(
                            timeValue
                        ) + '&duration=' + encodeURIComponent(durationInput.value || '1 Hour') +
                        '&room_type=' + encodeURIComponent(roomInput.value || 'Single Room') +
                        '&pax=' + encodeURIComponent(paxInput.value || '1') +
                        '&guest_durations=' + encodeURIComponent(JSON.stringify(individualSelections.slice(0,currentGuestCount()).map(x=>x.duration || durationInput.value || '1 Hour')))
                    );


                const data =
                    await response.json();


                const card =
                    document.createElement(
                        'div'
                    );


                card.className =
                    'time-card';


                const clockHour = actualHour;
                const displayHour = clockHour % 12 || 12;
                const displayMinute = String(actualMinute).padStart(2, '0');
                const meridiem = clockHour >= 12 ? 'PM' : 'AM';


                const isCoupleRoom = (roomInput.value || 'Single Room') === 'Couple Room';
                const roomStatus = data.available ? 'Available' : 'Occupied';

                card.innerHTML = `
                    ${displayHour}:${displayMinute} ${meridiem}
                    <span class="slot-count">
                        ${isCoupleRoom ? roomStatus : `${Number(data.remaining ?? 0)} slot${Number(data.remaining) === 1 ? '' : 's'}`}
                    </span>
                `;


                const leadTimeBlocked = !slotHasLeadTime(selectedDate, timeValue);
                const durationBlocked = !slotWithinHours(selectedDate, timeValue, durationInput.value || '1 Hour');

                if(!data.available || leadTimeBlocked || durationBlocked){

                    card.classList.add('dim');
                    card.setAttribute('aria-disabled', 'true');
                    card.style.pointerEvents = 'none';
                    card.style.opacity = '0.45';
                    card.style.cursor = 'not-allowed';
                    if (leadTimeBlocked) {
                        card.title = 'Must be booked at least 2 hours in advance';
                    } else if (durationBlocked) {
                        card.title = 'Appointment must finish by 3:00 AM';
                    } else {
                        card.title = 'This time slot is unavailable';
                    }

                }else{

                    card.addEventListener(
                        'click',
                        function(){

                            document
                            .querySelectorAll(
                                '.time-card'
                            )
                            .forEach(function(item){

                                item.classList.remove(
                                    'active'
                                );

                            });


                            card.classList.add(
                                'active'
                            );


                            bookingTime.value =
                                timeValue;
                            bookingTime.dispatchEvent(new Event('change'));


                            summary.time =
                                `${displayHour}:00 ${meridiem}`;
                            const summaryBookingDate = new Date(selectedDate + 'T00:00:00');
                            if (actualHour < openingHour) summaryBookingDate.setDate(summaryBookingDate.getDate() + 1);
                            summary.date = summaryBookingDate.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});


                            resetTherapist();


                            /*
                               resetTherapist also renders
                               summary, so restore chosen time.
                            */

                            summary.time =
                                `${displayHour}:00 ${meridiem}`;


                            therapistMessage.textContent =
                                'Choose No Preference or click Choose Therapist to view availability.';


                            renderSummary();

                        }
                    );

                }


                cards.push(card);


            }catch(error){

                console.error(
                    'Time slot error:',
                    error
                );

            }

        }


        timeBox.innerHTML = '';


        if(cards.length === 0){

            timeBox.innerHTML = `
                <div
                    class="message"
                    style="grid-column:1/-1;"
                >
                    Unable to load available time slots.
                </div>
            `;

            return;

        }


        cards.forEach(function(card){

            timeBox.appendChild(
                card
            );

        });

    }
);


/* =========================================================
   FORM VALIDATION
   ========================================================= */

paxInput?.addEventListener('input', renderGuestNameFields);
paxInput?.addEventListener('input', renderGuestWellnessProfiles);
roomInput?.addEventListener('change', renderGuestNameFields);
roomInput?.addEventListener('change', renderGuestWellnessProfiles);
document.querySelectorAll('.room').forEach(card => card.addEventListener('click', () => setTimeout(renderGuestNameFields, 0)));
renderGuestNameFields();

document
.getElementById(
    'bookingForm'
)
.addEventListener(
    'submit',
    function(e){

        if (Number(paxInput.value) < 1 || Number(paxInput.value) > 4 || (roomInput.value === 'Couple Room' && Number(paxInput.value) !== 2)) {
            alert('Single Room allows 1–4 guests. Couple Room is exactly 2 guests.'); e.preventDefault(); return;
        }
        if (!slotHasLeadTime(bookingDate.value, bookingTime.value)) {
            alert('Please book at least 2 hours in advance.'); e.preventDefault(); return;
        }
        if (!slotWithinHours(bookingDate.value, bookingTime.value, durationInput.value)) {
            alert('The appointment must finish by 3:00 AM. Please choose an earlier time or shorter duration.'); e.preventDefault(); return;
        }
        if (therapistModeInput.value === 'choose' && selectedTherapistIds.length !== Number(paxInput.value)) {
            alert('Please select exactly ' + Number(paxInput.value) + ' available therapist(s), or choose No Preference.'); e.preventDefault(); return;
        }

        if(
            !serviceIdInput.value
        ){

            alert(
                'Please select a service.'
            );

            e.preventDefault();

            return;

        }


        if(
            !durationInput.value
        ){

            alert(
                'Please select a duration.'
            );

            e.preventDefault();

            return;

        }


        if(
            !roomInput.value
        ){

            alert(
                'Please select a room.'
            );

            e.preventDefault();

            return;

        }


        if(
            !bookingDate.value
        ){

            alert(
                'Please select an appointment date.'
            );

            e.preventDefault();

            return;

        }


        if(
            !bookingTime.value
        ){

            alert(
                'Please select an available time.'
            );

            e.preventDefault();

            return;

        }


        if(
            !priceInput.value ||
            Number(
                priceInput.value
            ) <= 0
        ){

            alert(
                'Please select a service duration so the booking price can be calculated.'
            );

            e.preventDefault();

            return;

        }

    }
);



durationInput.addEventListener('change', function(){
    if (bookingDate.value) bookingDate.dispatchEvent(new Event('change'));
});

/* =========================================================
   INITIAL SUMMARY
   ========================================================= */

renderSummary();


/* Customer details input guard: proper name and 11-digit Philippine mobile number. */
const customerNameInput = document.querySelector('input[name="customer_name"]');
const phoneNumberInput = document.querySelector('input[name="phone"]');

if (customerNameInput) {
    customerNameInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^A-Za-zÀ-ÿ .'-]/g, '');
    });
}

if (phoneNumberInput) {
    phoneNumberInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 11);
    });
}



/* =========================================================
   INDIVIDUAL GROUP BOOKING SELECTIONS
   ========================================================= */
const guestServiceWrap = document.getElementById('individualGuestServices');
const guestTherapistWrap = document.getElementById('individualGuestTherapists');
const guestSelectionsInput = document.getElementById('guest_selections');
let individualSelections = [];
const serviceCatalogCache = {};
let addonCatalogCache = null;

function currentGuestCount(){return roomInput.value==='Couple Room'?2:Math.max(1,Math.min(4,Number(paxInput.value||1)));}
function escHtml(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function guestDurationMinutes(v){return durationHours(v)*60;}
function updateGroupHiddenFields(){
  const count=currentGuestCount();
  const chosen=individualSelections.slice(0,count);
  const complete=chosen.length===count&&chosen.every(x=>x.service_id&&x.duration&&Number(x.price)>0);
  const maxGuest=chosen.reduce((a,b)=>guestDurationMinutes(b.duration)>guestDurationMinutes(a.duration)?b:a,chosen[0]||{});
  serviceIdInput.value=chosen[0]?.service_id||'';
  serviceInput.value=chosen.map((x,i)=>`Guest ${i+1}: ${x.service||''}`).join(' | ');
  durationInput.value=maxGuest?.duration||'';
  const serviceSum=chosen.reduce((n,x)=>n+Number(x.price||0),0);
  const addonSum=chosen.reduce((n,x)=>n+(x.addons||[]).reduce((a,b)=>a+Number(b.price||0),0),0);
  basePrice=serviceSum; addonTotal=addonSum;
  addonsInput.value=chosen.map((x,i)=>(x.addons||[]).map(a=>`Guest ${i+1}: ${a.name}`).join(', ')).filter(Boolean).join('; ');
  priceInput.value=(serviceSum+addonSum).toFixed(2);
  guestSelectionsInput.value=JSON.stringify(chosen.map((x,i)=>({guest_number:i+1,service_id:Number(x.service_id||0),duration:x.duration||'',addons:(x.addons||[]).map(a=>Number(a.id)),therapist_id:Number(x.therapist_id||0)})));
  summary.service=complete?chosen.map((x,i)=>`G${i+1}: ${x.service}`).join(' / '):'Select a service for each guest';
  summary.duration=chosen.length?chosen.map((x,i)=>`Guest ${i+1}: ${x.duration||'Not selected'}`).join(' | '):'—';
  summary.addons=addonSum?chosen.flatMap((x,i)=>(x.addons||[]).map(a=>`G${i+1}: ${a.name}`)).join(', '):'None';
  summary.therapist=chosen.some(x=>x.therapist_id)?chosen.map((x,i)=>x.therapist_name?`G${i+1}: ${x.therapist_name}`:`G${i+1}: No Preference`).join(', '):'No Preference';
  document.getElementById('sumBasePrice').textContent=money(serviceSum);
  document.getElementById('sumAddonPrice').textContent=money(addonSum);
  renderSummary();
}
function ensureGuestSelectionCount(){
  const count=currentGuestCount();
  individualSelections=Array.from({length:count},(_,i)=>individualSelections[i]||{service_id:0,service:'',description:'',duration:'',price:0,addons:[],therapist_id:0,therapist_name:''});
  renderGuestServiceCards();renderGuestTherapistCards();updateGroupHiddenFields();
}
async function getServiceCatalog(category){
  if(serviceCatalogCache[category])return serviceCatalogCache[category];
  const r=await fetch('get_services_by_category.php?cat='+encodeURIComponent(category),{cache:'no-store'});if(!r.ok)throw new Error('Unable to load services');
  const data=await r.json();serviceCatalogCache[category]=Array.isArray(data)?data:[];return serviceCatalogCache[category];
}
async function getAddonCatalog(){if(addonCatalogCache)return addonCatalogCache;const r=await fetch('get_addons.php',{cache:'no-store'});if(!r.ok)throw new Error('Unable to load add-ons');addonCatalogCache=await r.json();return Array.isArray(addonCatalogCache)?addonCatalogCache:[];}
function renderGuestServiceCards(){
  if(!guestServiceWrap)return;guestServiceWrap.innerHTML='';
  individualSelections.forEach((sel,index)=>{
    const card=document.createElement('div');card.className='guest-choice-card';card.dataset.guestIndex=index;
    card.innerHTML=`<h3>Guest ${index+1} Service Selection</h3>
      <div class="field-title">Category</div><div class="selection-grid guest-categories"><div class="select-card guest-category" data-category="Massage">Massage</div><div class="select-card guest-category" data-category="Package">Package</div><div class="select-card guest-category" data-category="Promo">Promo</div></div>
      <div class="field-title">Service</div><div class="selection-grid guest-services"><div class="message">Select a category first.</div></div>
      <div class="service-description guest-service-description">Select a service to view its description.</div>
      <div class="field-title">Duration</div><div class="selection-grid guest-durations"><div class="message">Select a service first.</div></div>
      <div class="field-title">Add-ons <span style="color:#777;text-transform:none">(Optional)</span></div><div class="guest-addons"><div class="message">Select a service first.</div></div>
      <div class="guest-line-total">Guest ${index+1} Total: <span class="guest-total">₱0.00</span></div>`;
    guestServiceWrap.appendChild(card);
    if(sel.category){const cat=card.querySelector(`[data-category="${CSS.escape(sel.category)}"]`);if(cat)cat.classList.add('active');}
    if(sel.service_id&&sel.service){card.querySelector('.guest-services').innerHTML=`<div class="select-card active">${escHtml(sel.service)}</div>`;card.querySelector('.guest-service-description').textContent=sel.description||'No description available.';}
    if(sel.duration){card.querySelector('.guest-durations').innerHTML=`<div class="select-card active">${escHtml(sel.duration)} <span class="card-price">${money(sel.price)}</span></div>`;}
    card.querySelector('.guest-total').textContent=money(Number(sel.price||0)+(sel.addons||[]).reduce((a,b)=>a+Number(b.price||0),0));
  });
}
async function loadGuestServices(card,index,category){
  const box=card.querySelector('.guest-services');box.innerHTML='<div class="message">Loading services...</div>';
  try{const data=await getServiceCatalog(category);box.innerHTML='';if(!data.length){box.innerHTML='<div class="message">No services available.</div>';return;}
    data.forEach(s=>{const el=document.createElement('div');el.className='select-card';el.textContent=s.service_name;el.dataset.id=s.id;el.dataset.name=s.service_name;el.dataset.description=s.description||'';if(Number(s.id)===Number(individualSelections[index].service_id))el.classList.add('active');el.addEventListener('click',()=>selectGuestService(card,index,s));box.appendChild(el);});
  }catch(e){box.innerHTML='<div class="message">Unable to load services.</div>';console.error(e);}
}
async function selectGuestService(card,index,service){
  const sel=individualSelections[index];sel.category=service.category||card.querySelector('.guest-category.active')?.dataset.category||'';sel.service_id=Number(service.id);sel.service=service.service_name;sel.description=service.description||'';card.querySelector('.guest-service-description').textContent=sel.description||'No description available.';sel.duration='';sel.price=0;sel.addons=[];sel.therapist_id=0;sel.therapist_name='';
  card.querySelectorAll('.guest-services .select-card').forEach(x=>x.classList.toggle('active',Number(x.dataset.id)===Number(service.id)));
  const durationBox=card.querySelector('.guest-durations');durationBox.innerHTML='<div class="message">Loading durations...</div>';
  try{const r=await fetch('get_duration.php?id='+encodeURIComponent(service.id),{cache:'no-store'});const data=await r.json();durationBox.innerHTML='';if(!Array.isArray(data)||!data.length){durationBox.innerHTML='<div class="message">No duration available.</div>';return;}
    data.forEach(d=>{const el=document.createElement('div');el.className='select-card';el.innerHTML=`${escHtml(d.duration)}<span class="card-price">${money(d.price)}</span>`;el.addEventListener('click',()=>{durationBox.querySelectorAll('.select-card').forEach(x=>x.classList.remove('active'));el.classList.add('active');sel.duration=d.duration;sel.price=Number(d.price)||0;sel.therapist_id=0;sel.therapist_name='';refreshGuestTherapistAvailability(index);updateGuestCardTotal(card,index);updateGroupHiddenFields();refreshSlotDuration();});durationBox.appendChild(el);});
    const fixed=card.querySelector('.guest-category.active')?.dataset.category==='Package'&&data.length===1;if(fixed){const el=durationBox.querySelector('.select-card');el.click();el.style.pointerEvents='none';el.title='Package duration is fixed.';}
  }catch(e){durationBox.innerHTML='<div class="message">Unable to load durations.</div>';console.error(e);}
  const addonsBox=card.querySelector('.guest-addons');addonsBox.innerHTML='<div class="message">Loading add-ons...</div>';
  try{const data=await getAddonCatalog();addonsBox.innerHTML='';if(!data.length){addonsBox.innerHTML='<div class="message">No add-ons available.</div>';return;}data.forEach(a=>{const el=document.createElement('div');el.className='select-card';el.innerHTML=`${escHtml(a.service_name)}<span class="card-price">${money(a.price)}</span>`;el.addEventListener('click',()=>{const pos=sel.addons.findIndex(x=>Number(x.id)===Number(a.id));if(pos>=0){sel.addons.splice(pos,1);el.classList.remove('active');}else{sel.addons.push({id:Number(a.id),name:a.service_name,price:Number(a.price)||0});el.classList.add('active');}updateGuestCardTotal(card,index);updateGroupHiddenFields();});addonsBox.appendChild(el);});}
  catch(e){addonsBox.innerHTML='<div class="message">Unable to load add-ons.</div>';console.error(e);}
  updateGuestCardTotal(card,index);updateGroupHiddenFields();refreshSlotDuration();
}
function updateGuestCardTotal(card,index){const s=individualSelections[index];const total=Number(s.price||0)+(s.addons||[]).reduce((a,b)=>a+Number(b.price||0),0);const out=card.querySelector('.guest-total');if(out)out.textContent=money(total);}
function refreshSlotDuration(){if(durationInput.value&&bookingDate.value)bookingDate.dispatchEvent(new Event('change'));}
if(guestServiceWrap){guestServiceWrap.addEventListener('click',async e=>{const cat=e.target.closest('.guest-category');if(!cat)return;const card=cat.closest('.guest-choice-card');const index=Number(card.dataset.guestIndex);card.querySelectorAll('.guest-category').forEach(x=>x.classList.toggle('active',x===cat));individualSelections[index].category=cat.dataset.category;individualSelections[index].service_id=0;individualSelections[index].service='';individualSelections[index].description='';card.querySelector('.guest-service-description').textContent='Select a service to view its description.';individualSelections[index].duration='';individualSelections[index].price=0;individualSelections[index].addons=[];individualSelections[index].therapist_id=0;individualSelections[index].therapist_name='';card.querySelector('.guest-durations').innerHTML='<div class="message">Select a service first.</div>';card.querySelector('.guest-addons').innerHTML='<div class="message">Select a service first.</div>';await loadGuestServices(card,index,cat.dataset.category);updateGroupHiddenFields();refreshSlotDuration();});}
function renderGuestTherapistCards(){
  if(!guestTherapistWrap)return;
  guestTherapistWrap.innerHTML='';
  individualSelections.forEach((sel,index)=>{
    const c=document.createElement('div');
    c.className='guest-choice-card';
    c.dataset.guestIndex=index;
    c.innerHTML=`<h3>Guest ${index+1} Therapist</h3><div class="message">${sel.duration?'Select a date and time first.':'Choose a service and duration first.'}</div><div class="guest-therapist-select"><div class="select-card active guest-no-preference">No Preference <span class="card-desc">Any available therapist</span></div><div class="guest-therapist-list"></div></div>`;
    guestTherapistWrap.appendChild(c);
    c.querySelector('.guest-no-preference').addEventListener('click',()=>{
      const option=c.querySelector('.guest-no-preference');
      if(option.classList.contains('unavailable'))return;
      sel.therapist_id=0;sel.therapist_name='';option.classList.add('active');
      c.querySelectorAll('.guest-therapist-list .select-card').forEach(x=>x.classList.remove('active'));
      updateGroupHiddenFields();
    });
  });
}
async function refreshGuestTherapistAvailability(index){
  const wrap=guestTherapistWrap?.querySelector(`[data-guest-index="${index}"]`);if(!wrap)return;
  const list=wrap.querySelector('.guest-therapist-list');const sel=individualSelections[index];
  const noPref=wrap.querySelector('.guest-no-preference');
  if(!sel.duration||!bookingDate.value||!bookingTime.value){
    list.innerHTML='<div class="message">Select service, duration, date and time first.</div>';
    noPref.classList.remove('unavailable');noPref.setAttribute('aria-disabled','false');
    return;
  }
  list.innerHTML='<div class="message">Checking available therapists...</div>';
  try{
    const url='get_available_therapists.php?date='+encodeURIComponent(bookingDate.value)+'&time='+encodeURIComponent(bookingTime.value)+'&duration='+encodeURIComponent(sel.duration);
    const r=await fetch(url,{cache:'no-store'});if(!r.ok)throw new Error('Availability request failed');
    const data=await r.json();list.innerHTML='';
    const otherAssigned=individualSelections.filter((x,i)=>i!==index&&Number(x.therapist_id)>0).map(x=>Number(x.therapist_id));
    const currentTherapist=(Array.isArray(data)?data:[]).find(t=>Number(t.id)===Number(sel.therapist_id));
    if(sel.therapist_id&&(!currentTherapist||!currentTherapist.available||otherAssigned.includes(Number(sel.therapist_id)))){
      sel.therapist_id=0;sel.therapist_name='';noPref.classList.add('active');updateGroupHiddenFields();
    }
    const availableForThisGuest=(Array.isArray(data)?data:[]).filter(t=>Boolean(t.available)&&!otherAssigned.includes(Number(t.id)));
    const noneAvailable=availableForThisGuest.length===0;
    noPref.classList.toggle('unavailable',noneAvailable);
    noPref.setAttribute('aria-disabled',noneAvailable?'true':'false');
    noPref.innerHTML=noneAvailable
      ? 'No Preference <span class="card-desc">No therapists available for this schedule</span>'
      : 'No Preference <span class="card-desc">Any available therapist</span>';
    if(noneAvailable){
      noPref.classList.remove('active');
      list.innerHTML='<div class="message">No therapists are available for this schedule. Please choose another time slot.</div>';
    } else if(Number(sel.therapist_id)===0){noPref.classList.add('active');}
    (Array.isArray(data)?data:[]).forEach(t=>{
      const el=document.createElement('div');el.className='select-card';el.textContent=t.name;el.dataset.id=t.id;
      const available=Boolean(t.available)&&!otherAssigned.includes(Number(t.id));
      if(!available){el.classList.add('unavailable');el.textContent=t.name+' — Unavailable';}
      else{el.addEventListener('click',()=>{
        individualSelections.forEach((x,i)=>{if(i!==index&&Number(x.therapist_id)===Number(t.id)){x.therapist_id=0;x.therapist_name='';const otherCard=guestTherapistWrap.querySelector(`[data-guest-index="${i}"]`);otherCard?.querySelector('.guest-no-preference')?.classList.add('active');}});
        sel.therapist_id=Number(t.id);sel.therapist_name=t.name;noPref.classList.remove('active');
        list.querySelectorAll('.select-card').forEach(x=>x.classList.toggle('active',x===el));updateGroupHiddenFields();
        individualSelections.forEach((_,i)=>{if(i!==index)refreshGuestTherapistAvailability(i);});
      });}
      if(Number(sel.therapist_id)===Number(t.id))el.classList.add('active');list.appendChild(el);
    });
    if(!Array.isArray(data)||data.length===0)list.innerHTML='<div class="message">No active therapists found. Please choose another time slot.</div>';
  }catch(e){
    noPref.classList.add('unavailable');noPref.setAttribute('aria-disabled','true');
    list.innerHTML='<div class="message">Unable to verify therapist availability. Please try again.</div>';console.error(e);
  }
}
function refreshAllGuestTherapists(){individualSelections.forEach((s,i)=>refreshGuestTherapistAvailability(i));updateGroupHiddenFields();}
bookingDate.addEventListener('change',()=>setTimeout(refreshAllGuestTherapists,250));
bookingTime.addEventListener('change',()=>setTimeout(refreshAllGuestTherapists,100));
if(guestTherapistWrap){guestTherapistWrap.addEventListener('click',e=>{const noPref=e.target.closest('.guest-no-preference');if(!noPref||noPref.classList.contains('unavailable'))return;const index=Number(noPref.closest('.guest-choice-card').dataset.guestIndex);individualSelections[index].therapist_id=0;individualSelections[index].therapist_name='';updateGroupHiddenFields();});}
// Render a separate required wellness profile for every guest.
const guestWellnessWrap = document.getElementById('guestWellnessProfiles');
const wellnessTermsText = <?= (json_encode((string)$wellnessTerms, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?: '""') ?>;
function renderGuestWellnessProfiles(){
  if(!guestWellnessWrap)return;
  const count=currentGuestCount();
  const previous={};
  guestWellnessWrap.querySelectorAll('[data-wellness-guest]').forEach(card=>{
    const n=Number(card.dataset.wellnessGuest); previous[n]={};
    card.querySelectorAll('[name^="wellness["]').forEach(el=>{const m=el.name.match(/wellness\[\d+\]\[([^\]]+)\]/);if(m)previous[n][m[1]]=el.type==='checkbox'?(el.checked?'1':''):el.value;});
  });
  guestWellnessWrap.innerHTML='';
  for(let n=1;n<=count;n++){
    const v=previous[n]||{}; const card=document.createElement('div');card.className='guest-choice-card';card.dataset.wellnessGuest=n;
    card.innerHTML=`<h3>Guest ${n} Wellness Profile</h3>
      <div class="form-grid">
        <div class="form-group"><label>Allergies <span class="required">*</span></label><input name="wellness[${n}][allergies]" required placeholder="e.g. None, Lavender, Coconut Oil"></div>
        <div class="form-group"><label>Skin Sensitivity <span class="required">*</span></label><select name="wellness[${n}][skin_sensitivity]" required><option value="">Select one</option><option>Normal Skin</option><option>Sensitive Skin</option><option>Dry Skin</option><option>Oily Skin</option><option>Not Sure</option></select></div>
        <div class="form-group"><label>Medical Conditions <span class="required">*</span></label><input name="wellness[${n}][medical_conditions]" required placeholder="e.g. None, Asthma, Diabetes"></div>
        <div class="form-group"><label>Body Pain / Injuries <span class="required">*</span></label><input name="wellness[${n}][body_pain_injuries]" required placeholder="e.g. None, Back Pain, Neck Pain"></div>
        <div class="form-group"><label>Preferred Pressure <span class="required">*</span></label><select name="wellness[${n}][preferred_pressure]" required><option value="">Select pressure</option><option>Very Light</option><option>Light</option><option>Medium</option><option>Strong</option><option>No Preference</option></select></div>
        <div class="form-group full"><label>Special Requests (Optional)</label><textarea name="wellness[${n}][special_requests]" placeholder="Any wellness-related request..."></textarea></div>
        <div class="form-group"><label>Emergency Contact Name (Optional)</label><input name="wellness[${n}][emergency_contact_name]" placeholder="Emergency contact name"></div>
        <div class="form-group"><label>Emergency Contact Number (Optional)</label><input type="tel" name="wellness[${n}][emergency_contact_number]" class="guest-emergency-number" placeholder="09XXXXXXXXX" maxlength="11" minlength="11" inputmode="numeric" pattern="09[0-9]{9}" autocomplete="tel" title="Enter an 11-digit Philippine mobile number starting with 09."></div>
      </div>`;
    guestWellnessWrap.appendChild(card);
    const sharedTermsText=document.querySelector('.wellness-terms-details .wellness-terms-text');
    if(sharedTermsText && !sharedTermsText.textContent) sharedTermsText.textContent=wellnessTermsText;
    Object.entries(v).forEach(([key,value])=>{const el=card.querySelector(`[name="wellness[${n}][${key}]"]`);if(el){if(el.type==='checkbox')el.checked=value==='1';else el.value=value;}});
  }
}

// Philippine emergency contact number: block non-digits and limit to 11 digits.
guestWellnessWrap.addEventListener('beforeinput', function(event) {
  const field = event.target;
  if (!field.matches('.guest-emergency-number') || event.data === null) return;

  if (/\D/.test(event.data)) {
    event.preventDefault();
    return;
  }

  const selectedLength = field.selectionEnd - field.selectionStart;
  const nextLength = field.value.length - selectedLength + event.data.length;
  if (nextLength > 11) event.preventDefault();
});

guestWellnessWrap.addEventListener('input', function(event) {
  if (event.target.matches('.guest-emergency-number')) {
    event.target.value = event.target.value.replace(/\D/g, '').slice(0, 11);
  }
});

const originalPaxRender=renderGuestNameFields;
paxInput.addEventListener('input',()=>{ensureGuestSelectionCount();renderGuestWellnessProfiles();if(bookingDate.value)bookingDate.dispatchEvent(new Event('change'));});
roomInput.addEventListener('change',()=>{ensureGuestSelectionCount();renderGuestWellnessProfiles();});
document.querySelectorAll('.room').forEach(el=>el.addEventListener('click',()=>setTimeout(()=>{ensureGuestSelectionCount();renderGuestWellnessProfiles();},0)));
ensureGuestSelectionCount();
renderGuestWellnessProfiles();

// Keep the legacy time-slot checker informed of the longest service duration.
// It uses the maximum guest duration for conservative room-capacity checking.
const originalSubmitValidation=document.getElementById('bookingForm');
originalSubmitValidation.addEventListener('submit',e=>{
  const count=currentGuestCount();
  if(individualSelections.length!==count||individualSelections.some(s=>!s.service_id||!s.duration||Number(s.price)<=0)){e.preventDefault();alert('Please select a service and duration for every guest.');return;}
  if(!guestNamesAreValid()){e.preventDefault();return;}
  const wellnessCards=Array.from(guestWellnessWrap?.querySelectorAll('[data-wellness-guest]')||[]);
  if(wellnessCards.length!==count||wellnessCards.some(card=>Array.from(card.querySelectorAll('[required]')).some(field=>!field.value.trim()))){e.preventDefault();alert('Please complete the required Wellness Profile fields for every guest.');return;}
  if(!document.querySelector('[name="wellness_terms_accepted"]')?.checked){e.preventDefault();alert('Please read and accept the Wellness Terms and Conditions.');return;}
  guestSelectionsInput.value=JSON.stringify(individualSelections.map((s,i)=>({guest_number:i+1,service_id:s.service_id,duration:s.duration,addons:s.addons.map(a=>a.id),therapist_id:s.therapist_id||0})));
});
function guestNamesAreValid(){const names=[document.querySelector('[name="customer_name"]')?.value||'',...Array.from(document.querySelectorAll('[name="guest_names[]"]')).map(x=>x.value)];if(names.length!==currentGuestCount()||names.some(n=>!/^([\p{L}][\p{L}\s.'-]{1,99})$/u.test(n.trim()))){alert('Please enter the full name of every guest.');return false;}return true;}

// Landing-page service prefill: apply the requested service to Guest 1.
(async function prefillIndividualGuestService(){const params=new URLSearchParams(location.search);const id=Number(params.get('service_id')||0);if(!id)return;for(const category of ['Massage','Package','Promo']){try{const rows=await getServiceCatalog(category);const match=rows.find(x=>Number(x.id)===id);if(!match)continue;const card=guestServiceWrap.querySelector('.guest-choice-card');const cat=card.querySelector(`[data-category="${CSS.escape(category)}"]`);cat.click();await new Promise(resolve=>setTimeout(resolve,250));const serviceCard=Array.from(card.querySelectorAll('.guest-services .select-card')).find(x=>Number(x.dataset.id)===id);if(serviceCard){serviceCard.click();await new Promise(resolve=>setTimeout(resolve,400));const wanted=params.get('duration');const d=Array.from(card.querySelectorAll('.guest-durations .select-card')).find(x=>wanted&&(x.textContent||'').toLowerCase().includes(wanted.toLowerCase()))||card.querySelector('.guest-durations .select-card');if(d)d.click();}break;}catch(e){console.warn(e);}}})();

</script>

</body>

</html>
