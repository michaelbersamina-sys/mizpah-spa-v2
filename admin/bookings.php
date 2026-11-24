<?php
session_start();
date_default_timezone_set('Asia/Manila');
include __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Persistent admin notification history (one record per online booking).
$conn->query("CREATE TABLE IF NOT EXISTS admin_booking_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL UNIQUE,
    customer_name VARCHAR(255) NOT NULL DEFAULT '',
    booking_date DATE NULL,
    booking_time TIME NULL,
    source VARCHAR(30) NOT NULL DEFAULT 'Online',
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_notice_read_created (is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Dropdown history and read-state endpoints.
if (isset($_GET['ajax_notification_history']) || isset($_POST['mark_booking_notification_read'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (isset($_POST['mark_booking_notification_read'])) {
        $noticeId = (int)$_POST['mark_booking_notification_read'];
        $mark = $conn->prepare("UPDATE admin_booking_notifications SET is_read = 1 WHERE id = ?");
        if ($mark) { $mark->bind_param('i', $noticeId); $mark->execute(); $mark->close(); }
    }
    $limit = isset($_GET['all']) && $_GET['all'] === '1' ? 100 : 10;
    $items = [];
    $result = $conn->query("SELECT id, booking_id, customer_name, booking_date, booking_time, source, is_read, created_at FROM admin_booking_notifications ORDER BY created_at DESC, id DESC LIMIT " . $limit);
    if ($result) while ($row = $result->fetch_assoc()) $items[] = $row;
    $unreadRow = $conn->query("SELECT COUNT(*) AS total FROM admin_booking_notifications WHERE is_read = 0");
    $unreadCount = $unreadRow ? (int)$unreadRow->fetch_assoc()['total'] : 0;
    echo json_encode(['success'=>true, 'items'=>$items, 'unread_count'=>$unreadCount]);
    exit;
}

/* Establish the admin session's notification baseline when Bookings is first opened. */
if (!isset($_SESSION['booking_notification_since'])) {
    $initialClock = $conn->query("SELECT NOW() AS db_now");
    $initialClockRow = $initialClock ? $initialClock->fetch_assoc() : null;
    $_SESSION['booking_notification_since'] = (string)($initialClockRow['db_now'] ?? date('Y-m-d H:i:s'));
    $_SESSION['booking_notification_seen_ids'] = [];
}

/* =========================================================
   AJAX - NEW ONLINE BOOKING NOTIFICATIONS FOR ADMIN
   Polls Guest/Customer bookings created while this page is open.
   ========================================================= */
if (isset($_GET['ajax_new_booking_notifications'])) {
    header('Content-Type: application/json; charset=utf-8');
    $since = (string)($_SESSION['booking_notification_since'] ?? '');
    if ($since === '') {
        $clock = $conn->query("SELECT NOW() AS db_now");
        $clockRow = $clock ? $clock->fetch_assoc() : null;
        $since = (string)($clockRow['db_now'] ?? date('Y-m-d H:i:s'));
        $_SESSION['booking_notification_since'] = $since;
        echo json_encode(['success' => true, 'notifications' => []]);
        exit;
    }

    $clock = $conn->query("SELECT NOW() AS db_now");
    $clockRow = $clock ? $clock->fetch_assoc() : null;
    $pollUntil = (string)($clockRow['db_now'] ?? date('Y-m-d H:i:s'));

    $notifications = [];
    $stmt = $conn->prepare("SELECT id, customer_name, booking_date, booking_time, created_by, created_at
        FROM bookings
        WHERE created_at >= ? AND created_at <= ?
          AND LOWER(TRIM(COALESCE(created_by, ''))) IN ('guest', 'customer')
        ORDER BY created_at ASC, id ASC LIMIT 30");
    if ($stmt) {
        $stmt->bind_param('ss', $since, $pollUntil);
        $stmt->execute();
        $result = $stmt->get_result();
        $seen = $_SESSION['booking_notification_seen_ids'] ?? [];
        if (!is_array($seen)) $seen = [];
        while ($row = $result->fetch_assoc()) {
            $id = (int)$row['id'];
            if (in_array($id, $seen, true)) continue;
            $notifications[] = [
                'id' => $id,
                'customer' => (string)($row['customer_name'] ?? 'New customer'),
                'date' => (string)($row['booking_date'] ?? ''),
                'time' => (string)($row['booking_time'] ?? ''),
                'source' => ucfirst(strtolower((string)($row['created_by'] ?? 'online')))
            ];
            $seen[] = $id;
        }
        $stmt->close();
        $_SESSION['booking_notification_seen_ids'] = array_slice(array_values(array_unique($seen)), -200);
        if ($notifications) {
            $saveNotice = $conn->prepare("INSERT IGNORE INTO admin_booking_notifications (booking_id, customer_name, booking_date, booking_time, source) VALUES (?, ?, ?, ?, ?)");
            if ($saveNotice) {
                foreach ($notifications as $notice) {
                    $bid=(int)$notice['id']; $customer=(string)$notice['customer']; $bdate=$notice['date'] ?: null; $btime=$notice['time'] ?: null; $source=(string)$notice['source'];
                    $saveNotice->bind_param('issss', $bid, $customer, $bdate, $btime, $source); $saveNotice->execute();
                }
                $saveNotice->close();
            }
        }
    }
    $_SESSION['booking_notification_since'] = $pollUntil;
    echo json_encode(['success' => true, 'notifications' => $notifications]);
    exit;
}

/* =========================================================
   DURATION TO MINUTES
   ========================================================= */
function getMinutes($duration)
{
    $duration = strtolower(trim((string)$duration));
    if (in_array($duration, ['1.5hr', '1.5 hr', '1.5hrs', '1.5 hrs'], true)) return 90;
    if (in_array($duration, ['2hr', '2 hr', '2hrs', '2 hrs'], true)) return 120;
    return 60;
}

/* =========================================================
   THERAPIST AVAILABILITY
   Checks both the legacy single therapist field and the
   multi-therapist assignment table.
   ========================================================= */
function isTherapistAvailable($conn, $therapist_id, $date, $time, $duration, $current_booking_id = 0)
{
    $therapist_id = (int)$therapist_id;
    $current_booking_id = (int)$current_booking_id;
    if ($therapist_id <= 0) return true;

    $newStart = strtotime("$date $time");
    if (!$newStart) return true;
    $newEnd = $newStart + getMinutes($duration) * 60;

    $stmt = $conn->prepare("
        SELECT DISTINCT b.id, b.booking_date, b.booking_time, b.duration
        FROM bookings b
        LEFT JOIN booking_therapists bt ON bt.booking_id = b.id
        WHERE (b.therapist_id = ? OR bt.therapist_id = ?)
          AND b.id != ?
          AND b.status NOT IN ('Completed', 'Cancelled')
    ");
    if (!$stmt) return true;

    $stmt->bind_param("iii", $therapist_id, $therapist_id, $current_booking_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $existingStart = strtotime($row['booking_date'] . ' ' . $row['booking_time']);
        if (!$existingStart) continue;
        $existingEnd = $existingStart + getMinutes($row['duration']) * 60;
        if ($newStart < $existingEnd && $newEnd > $existingStart) {
            $stmt->close();
            return false;
        }
    }
    $stmt->close();
    return true;
}

/* =========================================================
   AJAX - ASSIGN THERAPIST PER GUEST SLOT
   ========================================================= */
if (isset($_POST['ajax_change_therapist'])) {
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $therapist_id = (int)($_POST['therapist_id'] ?? 0);
    $guest_index = max(1, (int)($_POST['guest_index'] ?? 1));

    if ($booking_id <= 0) { echo 'INVALID'; exit; }

    $bookingStmt = $conn->prepare("SELECT booking_date, booking_time, duration, pax, therapist_id, status FROM bookings WHERE id = ? LIMIT 1");
    if (!$bookingStmt) { echo 'ERROR'; exit; }
    $bookingStmt->bind_param("i", $booking_id);
    $bookingStmt->execute();
    $booking = $bookingStmt->get_result()->fetch_assoc();
    $bookingStmt->close();
    if (!$booking) { echo 'INVALID'; exit; }

    if (in_array(trim((string)$booking['status']), ['Completed', 'Cancelled'], true)) {
        echo 'LOCKED'; exit;
    }

    $pax = max(1, min(4, (int)($booking['pax'] ?? 1)));
    if ($guest_index > $pax) { echo 'INVALID'; exit; }

    // Customer-selected therapist assignments cannot be changed by admin.
    $sourceStmt = $conn->prepare("SELECT assigned_by, therapist_id FROM booking_therapists WHERE booking_id = ?");
    $hasCustomerChoice = false;
    $hasAdminAssignment = false;
    $existingSlotRows = [];
    if ($sourceStmt) {
        $sourceStmt->bind_param("i", $booking_id);
        $sourceStmt->execute();
        $sourceResult = $sourceStmt->get_result();
        while ($sourceRow = $sourceResult->fetch_assoc()) {
            $source = strtolower(trim((string)($sourceRow['assigned_by'] ?? '')));
            if (in_array($source, ['customer', 'guest', 'user', 'client'], true)) {
                $hasCustomerChoice = true;
            }
            if (strpos($source, 'admin') === 0) {
                $hasAdminAssignment = true;
            }
            $slot = 1;
            if (preg_match('/^admin_guest_(\d+)$/', $source, $matches)) {
                $slot = max(1, (int)$matches[1]);
            }
            $existingSlotRows[] = [
                'id' => (int)$sourceRow['therapist_id'],
                'source' => $source,
                'slot' => $slot
            ];
        }
        $sourceStmt->close();
    }

    $legacyTherapistId = (int)($booking['therapist_id'] ?? 0);
    if ($hasCustomerChoice || ($legacyTherapistId > 0 && !$hasAdminAssignment)) {
        echo 'CUSTOMER_SELECTED'; exit;
    }

    if ($therapist_id > 0) {
        $check = $conn->prepare("SELECT id FROM therapists WHERE id = ? AND status = 'Active' LIMIT 1");
        if (!$check) { echo 'ERROR'; exit; }
        $check->bind_param("i", $therapist_id);
        $check->execute();
        $valid = $check->get_result()->num_rows > 0;
        $check->close();
        if (!$valid) { echo 'INVALID'; exit; }

        if (!isTherapistAvailable($conn, $therapist_id, $booking['booking_date'], $booking['booking_time'], $booking['duration'], $booking_id)) {
            echo 'BUSY'; exit;
        }

        // A therapist can serve only one guest in this booking.
        foreach ($existingSlotRows as $existing) {
            if ((int)$existing['id'] === $therapist_id && (int)$existing['slot'] !== $guest_index) {
                echo 'DUPLICATE'; exit;
            }
        }
        if ($guest_index > 1 && $legacyTherapistId === $therapist_id) {
            echo 'DUPLICATE'; exit;
        }
    }

    $slotSource = 'admin_guest_' . $guest_index;
    $assignmentRowId = 0;
    foreach ($existingSlotRows as $existing) {
        $sameSlot = ((int)$existing['slot'] === $guest_index && strpos($existing['source'], 'admin') === 0);
        $legacyAdminGuestOne = ($guest_index === 1 && $existing['source'] === 'admin');
        if ($sameSlot || $legacyAdminGuestOne) {
            // Locate the exact row for this slot, preserving existing admin assignment.
            $find = $conn->prepare("SELECT id FROM booking_therapists WHERE booking_id = ? AND therapist_id = ? AND LOWER(TRIM(assigned_by)) = ? LIMIT 1");
            if ($find) {
                $sourceToFind = $existing['source'];
                $existingTherapistId = (int)$existing['id'];
                $find->bind_param("iis", $booking_id, $existingTherapistId, $sourceToFind);
                $find->execute();
                $found = $find->get_result()->fetch_assoc();
                $assignmentRowId = (int)($found['id'] ?? 0);
                $find->close();
            }
            if ($assignmentRowId > 0) break;
        }
    }

    if ($therapist_id > 0) {
        if ($assignmentRowId > 0) {
            $save = $conn->prepare("UPDATE booking_therapists SET therapist_id = ?, assigned_by = ? WHERE id = ? AND booking_id = ?");
            if (!$save) { echo 'ERROR'; exit; }
            $save->bind_param("isii", $therapist_id, $slotSource, $assignmentRowId, $booking_id);
        } else {
            $save = $conn->prepare("INSERT INTO booking_therapists (booking_id, therapist_id, assigned_by) VALUES (?, ?, ?)");
            if (!$save) { echo 'ERROR'; exit; }
            $save->bind_param("iis", $booking_id, $therapist_id, $slotSource);
        }
        $saved = $save->execute();
        $save->close();
        if (!$saved) { echo 'ERROR'; exit; }
    } elseif ($assignmentRowId > 0) {
        $delete = $conn->prepare("DELETE FROM booking_therapists WHERE id = ? AND booking_id = ?");
        if (!$delete) { echo 'ERROR'; exit; }
        $delete->bind_param("ii", $assignmentRowId, $booking_id);
        $delete->execute();
        $delete->close();
    }

    // Keep the legacy primary therapist field synchronized with Guest 1.
    if ($guest_index === 1) {
        $updatePrimary = $conn->prepare("UPDATE bookings SET therapist_id = ? WHERE id = ?");
        if (!$updatePrimary) { echo 'ERROR'; exit; }
        $updatePrimary->bind_param("ii", $therapist_id, $booking_id);
        $updatePrimary->execute();
        $updatePrimary->close();
    }

    echo 'OK';
    exit;
}

/* =========================================================
   UPDATE STATUS
   ========================================================= */
if (isset($_POST['update_status'])) {
    $booking_id = (int)($_POST['booking_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');
    $allowedStatuses = ['Completed', 'Cancelled'];

    $cancellationReason = trim((string)($_POST['cancellation_reason'] ?? ''));

    if ($booking_id > 0 && in_array($status, $allowedStatuses, true)) {
        // Completed and Cancelled are final states and cannot be changed again.
        $currentStmt = $conn->prepare("SELECT id, status, user_id, created_by, email, customer_name, booking_date, booking_time, email_updates FROM bookings WHERE id = ? LIMIT 1");
        if ($currentStmt) {
            $currentStmt->bind_param("i", $booking_id);
            $currentStmt->execute();
            $currentResult = $currentStmt->get_result();
            $currentBooking = $currentResult ? $currentResult->fetch_assoc() : null;
            $currentStmt->close();

            $currentStatus = trim((string)($currentBooking['status'] ?? ''));
            $isFinalStatus = in_array($currentStatus, ['Completed', 'Cancelled'], true);

            $statusUpdated = false;
            if ($currentBooking && !$isFinalStatus) {
                if ($status === 'Cancelled' && $cancellationReason !== '') {
                    $stmt = $conn->prepare("UPDATE bookings SET status = 'Cancelled', cancellation_reason = ? WHERE id = ? AND status NOT IN ('Completed', 'Cancelled')");
                    if ($stmt) {
                        $stmt->bind_param("si", $cancellationReason, $booking_id);
                        $stmt->execute();
                        $statusUpdated = $stmt->affected_rows > 0;
                        $stmt->close();
                    }
                } elseif ($status === 'Completed') {
                    $stmt = $conn->prepare("UPDATE bookings SET status = 'Completed' WHERE id = ? AND status NOT IN ('Completed', 'Cancelled')");
                    if ($stmt) {
                        $stmt->bind_param("i", $booking_id);
                        $stmt->execute();
                        $statusUpdated = $stmt->affected_rows > 0;
                        $stmt->close();
                    }
                }

                // Save an in-app notification for account customers on each status change.
                if ($statusUpdated && in_array($status, ['Completed', 'Cancelled'], true) && $currentStatus !== $status) {
                    $customerId = (int)($currentBooking['user_id'] ?? 0);
                    if ($customerId > 0) {
                        $title = 'Booking ' . strtolower($status);
                        $message = 'Your booking #' . $booking_id . ' has been marked as ' . $status . '.';
                        $type = $status === 'Completed' ? 'booking_completed' : 'booking_cancelled';
                        $notice = $conn->prepare("INSERT INTO customer_notifications (user_id, booking_id, title, message, notification_type) VALUES (?, ?, ?, ?, ?)");
                        if ($notice) { $notice->bind_param('iisss', $customerId, $booking_id, $title, $message, $type); $notice->execute(); $notice->close(); }
                    }

                    // Send status emails to account customers and guests.
                    // Guest email updates remain subject to their saved preference.
                    $isGuestBooking = strtolower((string)($currentBooking['created_by'] ?? '')) === 'guest';
                    $guestEmailAllowed = !$isGuestBooking || (int)($currentBooking['email_updates'] ?? 0) === 1;
                    if (in_array($status, ['Completed', 'Cancelled'], true)
                        && $guestEmailAllowed
                        && filter_var((string)($currentBooking['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
                        try {
                            require_once __DIR__ . '/../includes/mailer.php';
                            if (function_exists('mizpahSendBookingStatusEmail')) {
                                mizpahSendBookingStatusEmail($currentBooking, $status, $cancellationReason);
                            }
                        } catch (Throwable $mailError) {
                            error_log('Mizpah booking status email error: ' . $mailError->getMessage());
                        }
                    }
                }
            }
        }
    }

    $returnFilter = strtolower(trim($_POST['return_filter'] ?? 'new'));
    $allowedFilters = ['new', 'confirmed', 'completed', 'cancelled', 'all'];
    if (!in_array($returnFilter, $allowedFilters, true)) $returnFilter = 'new';
    header("Location: bookings.php?filter=" . urlencode($returnFilter));
    exit;
}

/* =========================================================
   FILTER
   ========================================================= */
$filter = strtolower(trim($_GET['filter'] ?? 'new'));
$allowedFilters = ['new', 'confirmed', 'completed', 'cancelled', 'all'];
if (!in_array($filter, $allowedFilters, true)) $filter = 'new';

/* ACTIVE THERAPISTS */
$therapists = [];
$therapistQuery = $conn->query("SELECT id, name FROM therapists WHERE status = 'Active' ORDER BY name ASC");
if ($therapistQuery) {
    while ($row = $therapistQuery->fetch_assoc()) $therapists[] = $row;
}

/* COUNTS */
$newBookings = 0;
$confirmedBookings = 0;
$completedBookings = 0;
$cancelledBookings = 0;
$totalBookings = 0;

$newQuery = $conn->query("SELECT COUNT(*) AS total FROM bookings WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status NOT IN ('Completed', 'Cancelled')");
if ($newQuery) {
    $newRow = $newQuery->fetch_assoc();
    $newBookings = (int)($newRow['total'] ?? 0);
}

$countQuery = $conn->query("
    SELECT COUNT(*) AS total,
        SUM(CASE WHEN status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed,
        SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled
    FROM bookings
");
if ($countQuery) {
    $counts = $countQuery->fetch_assoc();
    $totalBookings = (int)($counts['total'] ?? 0);
    $confirmedBookings = (int)($counts['confirmed'] ?? 0);
    $completedBookings = (int)($counts['completed'] ?? 0);
    $cancelledBookings = (int)($counts['cancelled'] ?? 0);
}

/* FILTER QUERY */
$where = "";
$orderBy = "b.booking_date DESC, b.booking_time DESC, b.id DESC";
if ($filter === 'new') {
    $where = "WHERE b.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND b.status NOT IN ('Completed', 'Cancelled')";
    $orderBy = "b.created_at DESC, b.id DESC";
} elseif ($filter === 'confirmed') {
    $where = "WHERE b.status = 'Confirmed'";
    $orderBy = "b.booking_date ASC, b.booking_time ASC, b.id DESC";
} elseif ($filter === 'completed') {
    $where = "WHERE b.status = 'Completed'";
    $orderBy = "b.booking_date DESC, b.booking_time DESC, b.id DESC";
} elseif ($filter === 'cancelled') {
    $where = "WHERE b.status = 'Cancelled'";
    $orderBy = "b.booking_date DESC, b.booking_time DESC, b.id DESC";
} elseif ($filter === 'all') {
    $orderBy = "b.created_at DESC, b.id DESC";
}

$bookings = $conn->query("
    SELECT b.*
    FROM bookings b
    $where
    ORDER BY $orderBy
");


/* Fetch all guest names saved for each booking */
$bookingGuests = [];
$guestQuery = $conn->query("SELECT id, booking_id, guest_number, guest_name, service, duration, price, addons, phone FROM booking_guests ORDER BY booking_id ASC, guest_number ASC");
if ($guestQuery) {
    while ($guest = $guestQuery->fetch_assoc()) {
        $bid = (int)$guest['booking_id'];
        if (!isset($bookingGuests[$bid])) $bookingGuests[$bid] = [];
        $bookingGuests[$bid][] = $guest;
    }
}

/* Fetch Wellness Profile answers for every guest in each booking */
$bookingGuestWellness = [];
$wellnessQuery = $conn->query("
    SELECT w.*
    FROM booking_guest_wellness w
    INNER JOIN booking_guests bg ON bg.id = w.booking_guest_id
    ORDER BY bg.booking_id ASC, bg.guest_number ASC
");
if ($wellnessQuery) {
    while ($wellness = $wellnessQuery->fetch_assoc()) {
        $guestId = (int)$wellness['booking_guest_id'];
        $bookingGuestWellness[$guestId] = $wellness;
    }
}

/* Fetch add-ons saved per guest */
$bookingGuestAddons = [];
$guestAddonQuery = $conn->query("SELECT booking_guest_id, addon_name, quantity, price FROM booking_guest_addons ORDER BY booking_guest_id ASC, id ASC");
if ($guestAddonQuery) {
    while ($addon = $guestAddonQuery->fetch_assoc()) {
        $guestId = (int)$addon['booking_guest_id'];
        if (!isset($bookingGuestAddons[$guestId])) $bookingGuestAddons[$guestId] = [];
        $bookingGuestAddons[$guestId][] = $addon;
    }
}

/* Fetch all therapist assignments, preserving the guest slot. */
$bookingTherapists = [];
$bookingTherapistIds = [];
$bookingTherapistSources = [];
if ($bookings && $bookings->num_rows > 0) {
    $assignmentQuery = $conn->query("
        SELECT bt.id, bt.booking_id, bt.therapist_id, bt.assigned_by, t.name AS therapist_name
        FROM booking_therapists bt
        INNER JOIN therapists t ON t.id = bt.therapist_id
        ORDER BY bt.booking_id ASC, bt.id ASC
    ");
    if ($assignmentQuery) {
        $nextGuestSlot = [];
        while ($assignment = $assignmentQuery->fetch_assoc()) {
            $bid = (int)$assignment['booking_id'];
            $tid = (int)$assignment['therapist_id'];
            $source = strtolower(trim((string)($assignment['assigned_by'] ?? '')));
            if (preg_match('/^admin_guest_(\d+)$/', $source, $matches)) {
                $slot = max(1, (int)$matches[1]);
            } else {
                $slot = ($nextGuestSlot[$bid] ?? 0) + 1;
                $nextGuestSlot[$bid] = $slot;
            }
            $bookingTherapists[$bid][$slot] = $assignment['therapist_name'];
            $bookingTherapistIds[$bid][$slot] = $tid;
            $bookingTherapistSources[$bid][] = $source;
        }
    }
}

$filterTitle = 'New Bookings';
$filterDescription = 'Bookings received within the last 24 hours.';
if ($filter === 'confirmed') {
    $filterTitle = 'Confirmed Bookings';
    $filterDescription = 'Upcoming confirmed appointments.';
} elseif ($filter === 'completed') {
    $filterTitle = 'Completed Bookings';
    $filterDescription = 'Appointments that have already been completed.';
} elseif ($filter === 'cancelled') {
    $filterTitle = 'Cancelled Bookings';
    $filterDescription = 'Appointments that were cancelled.';
} elseif ($filter === 'all') {
    $filterTitle = 'All Bookings';
    $filterDescription = 'Complete booking history.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bookings | Mizpah Wellness Spa</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600;700&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{margin:0;background:#0b0b0b;color:#eee;font-family:'Poppins',sans-serif}
.main-content{margin-left:250px;min-height:100vh;padding:28px 30px 55px}
.page-header{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:20px}
.page-eyebrow{color:#84745f;font-size:11px;font-weight:500;letter-spacing:1.5px;text-transform:uppercase}
.page-header h1{margin:2px 0 0;color:#d5c29d;font-family:'Playfair Display',serif;font-size:34px;font-weight:600}
.page-header p{margin:5px 0 0;color:#888;font-size:12px}
.walkin-button{display:inline-flex;align-items:center;justify-content:center;padding:11px 18px;background:#d5c29d;color:#111;border-radius:7px;text-decoration:none;font-size:12px;font-weight:600}
.walkin-button:hover{background:#ead9b6}
.header-actions{display:flex;align-items:center;gap:10px}
.notification-bell{position:relative;display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border:1px solid rgba(213,194,157,.18);border-radius:9px;background:#131313;color:#d5c29d;cursor:pointer;font-size:19px;transition:background .18s,border-color .18s}
.notification-bell:hover{background:#191919;border-color:rgba(213,194,157,.30);color:#eadcc2}
.bell-icon{width:21px;height:21px;display:block;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
.notification-count{position:absolute;top:-6px;right:-6px;min-width:18px;height:18px;padding:0 5px;border-radius:12px;background:#8f4b40;color:#fff;font:600 10px/18px Poppins,sans-serif;text-align:center;display:none}
.notification-wrap{position:relative}.notification-panel{display:none;position:absolute;right:0;top:50px;width:min(360px,calc(100vw - 32px));max-height:430px;overflow:auto;background:#111;border:1px solid rgba(255,255,255,.09);border-radius:10px;box-shadow:0 16px 40px rgba(0,0,0,.65);z-index:11000}.notification-panel.open{display:block}.notification-panel-head{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;background:#181818;border-bottom:1px solid rgba(213,194,157,.13);color:#d5c29d;font-size:12px;font-weight:600}.notification-list{max-height:330px;overflow:auto}.notification-item{display:block;width:100%;padding:12px 16px;text-align:left;background:#111;border:0;border-bottom:1px solid rgba(255,255,255,.055);color:#eee;cursor:pointer;font:inherit}.notification-item:hover{background:#171717}.notification-item.unread{background:rgba(213,194,157,.045);box-shadow:inset 3px 0 0 #d5c29d}.notification-item strong{display:block;font-size:11px;color:#d5c29d}.notification-item span{display:block;margin-top:4px;font-size:10px;color:#9b8b70;line-height:1.5}.notification-empty{padding:22px 16px;text-align:center;color:#777;font-size:11px}.notification-view-all{display:block;width:100%;padding:12px;text-align:center;color:#a18f70;background:#181818;border:0;border-top:1px solid rgba(213,194,157,.13);font:500 11px Poppins,sans-serif;cursor:pointer}.notification-view-all:hover{background:#202020;color:#d5c29d}
.booking-notification-toast{position:fixed;right:22px;top:22px;z-index:12000;width:min(360px,calc(100vw - 32px));padding:15px 16px;background:#111;border:1px solid rgba(255,255,255,.09);border-left:3px solid #d5c29d;border-radius:10px;box-shadow:0 12px 35px rgba(0,0,0,.65);color:#eee;display:none;cursor:pointer}
.booking-notification-toast.show{display:block;animation:bookingToastIn .25s ease-out}
.booking-notification-toast strong{display:block;color:#d5c29d;font-size:12px;margin-bottom:4px}
.booking-notification-toast span{display:block;color:#9b8b70;font-size:11px;line-height:1.5}
@keyframes bookingToastIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.success-message{margin-bottom:17px;padding:12px 15px;background:rgba(100,130,90,.10);border:1px solid rgba(120,150,105,.22);border-radius:7px;color:#b7c8ad;font-size:12px}
.stats{display:grid;grid-template-columns:repeat(5,1fr);gap:11px;margin-bottom:23px}
.stat-card{display:block;position:relative;padding:15px 17px;background:#131313;border:1px solid rgba(255,255,255,.07);border-radius:10px;color:inherit;text-decoration:none;transition:.2s;cursor:pointer}
.stat-card:hover{background:#191919;border-color:rgba(213,194,157,.30);transform:translateY(-2px)}
.stat-card.active{background:linear-gradient(145deg,rgba(83,63,33,.48),#15130f);border-color:rgba(213,194,157,.50)}
.stat-card.active::after{content:"";position:absolute;left:16px;right:16px;bottom:-1px;height:2px;background:#d5c29d}
.stat-label{color:#999;font-size:10px;font-weight:500;letter-spacing:.6px;text-transform:uppercase}
.stat-number{margin-top:3px;color:#d5c29d;font-family:'Playfair Display',serif;font-size:27px;line-height:1.1}
.stat-card.active .stat-label{color:#c8b78f}
.new-card .stat-number{display:flex;align-items:center;gap:7px}
.new-dot{width:7px;height:7px;background:#d5c29d;border-radius:50%}
.section-header{display:flex;align-items:flex-end;justify-content:space-between;gap:15px;margin-bottom:11px}
.section-header h2{margin:0;color:#d5c29d;font-family:'Playfair Display',serif;font-size:22px;font-weight:600}
.section-header p{margin:3px 0 0;color:#777;font-size:11px}
.filter-indicator{color:#9a8b70;font-size:11px}
.table-container{width:100%;background:#111;border:1px solid rgba(255,255,255,.07);border-radius:10px;overflow:hidden}
.table-scroll{width:100%;max-width:100%;overflow-x:hidden}
.booking-table{width:100%;min-width:0;table-layout:fixed;border-collapse:collapse}
.booking-table thead{background:#181818}
.booking-table th{padding:11px 7px;border-bottom:1px solid rgba(213,194,157,.13);color:#a18f70;font-size:10px;font-weight:600;letter-spacing:.2px;text-align:left;text-transform:uppercase;white-space:normal;overflow-wrap:anywhere}
.booking-table td{padding:11px 7px;border-bottom:1px solid rgba(255,255,255,.055);color:#aaa;font-size:11px;line-height:1.5;vertical-align:top;overflow-wrap:anywhere;word-break:normal}
.booking-table tbody tr:nth-child(even){background:#121212}
.booking-table tbody tr:hover{background:#171717}
.booking-table tbody tr:last-child td{border-bottom:none}
.booking-top{display:flex;align-items:center;gap:6px;margin-bottom:2px}
.booking-number{color:#807258;font-size:10px;font-weight:600}
.new-badge{display:inline-block;padding:2px 6px;background:rgba(213,194,157,.12);border:1px solid rgba(213,194,157,.25);border-radius:10px;color:#d5c29d;font-size:8px;font-weight:600;letter-spacing:.5px}
.customer-name{color:#eee;font-size:13px;font-weight:500}
.customer-phone{margin-top:1px;color:#888;font-size:11px}
.source-badge{display:inline-block;margin-top:4px;padding:3px 7px;background:#191919;border:1px solid #292929;border-radius:12px;color:#888;font-size:9px;text-transform:capitalize}
.created-time{margin-top:5px;color:#6f6657;font-size:9px}
.service-name{color:#e0e0e0;font-size:13px;font-weight:500}
.service-info{margin-top:2px;color:#888;font-size:11px}
.service-price{color:#d5c29d;font-size:12px;font-weight:500}
.addons{max-width:190px;margin-top:4px;color:#9b8b70;font-size:10px;line-height:1.4}
.schedule-date{color:#ddd;font-size:12px;font-weight:500}
.schedule-time{margin-top:1px;color:#888;font-size:11px}
.room-name{color:#ddd;font-size:12px}
.room-pax{margin-top:2px;color:#888;font-size:11px}
.control-label{display:block;margin-bottom:5px;color:#9b8a6c;font-size:9px;font-weight:600;letter-spacing:.5px;text-transform:uppercase}
.admin-select{display:block;width:100%;min-width:0;max-width:100%;padding:7px 5px;background:#0c0c0c;color:#ddd;border:1px solid #343434;border-radius:6px;outline:none;font-family:'Poppins',sans-serif;font-size:10px}
.admin-select:hover{border-color:#574a36}
.admin-select:focus{border-color:rgba(213,194,157,.60)}
.admin-select:disabled{opacity:.55}
.payment{color:#ddd;font-size:12px}
.notes{max-width:165px;color:#9b8b70;font-size:11px;line-height:1.45}
.empty{padding:45px 20px!important;color:#777!important;font-size:12px!important;text-align:center}
.therapist-list{display:flex;flex-direction:column;gap:6px;width:100%;min-width:0}
.therapist-manage{width:100%;min-width:0;margin-top:8px}
.addons,.notes,.customer-name,.service-name,.therapist-chip{max-width:100%;overflow-wrap:anywhere}
.guest-service-list{display:flex;flex-direction:column;gap:8px}
.guest-service-item{padding:8px 9px;background:rgba(213,194,157,.045);border:1px solid rgba(213,194,157,.13);border-radius:7px}
.guest-service-title{color:#d8c7a6;font-size:10px;font-weight:600;margin-bottom:3px}
.guest-service-name{color:#eee;font-size:12px;font-weight:500;overflow-wrap:anywhere}
.guest-service-meta{color:#9b8b70;font-size:10px;margin-top:2px}
.guest-addon-line{color:#a99570;font-size:10px;margin-top:3px;overflow-wrap:anywhere}
.wellness-view-btn{display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:7px 10px;background:rgba(213,194,157,.08);border:1px solid rgba(213,194,157,.28);border-radius:7px;color:#d5c29d;font:500 10px Poppins,sans-serif;cursor:pointer;transition:.18s}
.wellness-view-btn:hover{background:rgba(213,194,157,.15);border-color:#b49b70}
.wellness-none{margin-top:8px;color:#777;font-size:10px;font-style:italic}
.wellness-overlay{display:none;position:fixed;inset:0;z-index:10020;align-items:center;justify-content:center;padding:20px;background:rgba(5,5,5,.80);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px)}
.wellness-overlay.show{display:flex;animation:dialogFadeIn .18s ease-out}
.wellness-dialog{width:min(760px,100%);max-height:min(86vh,850px);overflow:hidden;display:flex;flex-direction:column;background:linear-gradient(145deg,#1d1b17,#111 75%);border:1px solid rgba(193,179,159,.26);border-radius:17px;box-shadow:0 24px 80px rgba(0,0,0,.7);animation:dialogRise .2s ease-out}
.wellness-dialog-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;padding:22px 25px;border-bottom:1px solid rgba(213,194,157,.13)}
.wellness-dialog-head h3{margin:0;color:#eadcc2;font:600 21px 'Playfair Display',serif}
.wellness-dialog-head p{margin:5px 0 0;color:#958b7a;font-size:11px}
.wellness-close{width:34px;height:34px;flex:0 0 34px;border:1px solid #514633;border-radius:9px;background:#25221d;color:#d8c7a6;font-size:20px;line-height:1;cursor:pointer}
.wellness-dialog-body{padding:20px 25px;overflow-y:auto}
.wellness-guest-card{padding:17px;margin-bottom:13px;background:rgba(255,255,255,.025);border:1px solid rgba(213,194,157,.14);border-radius:12px}
.wellness-guest-card:last-child{margin-bottom:0}
.wellness-guest-title{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:13px;padding-bottom:10px;border-bottom:1px solid rgba(213,194,157,.12);color:#d8c7a6;font-size:12px;font-weight:600}
.wellness-guest-title small{color:#8f846f;font-size:10px;font-weight:400}
.wellness-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}
.wellness-field{min-width:0}
.wellness-field.full{grid-column:1/-1}
.wellness-field label{display:block;margin-bottom:3px;color:#8e8069;font-size:9px;font-weight:600;letter-spacing:.5px;text-transform:uppercase}
.wellness-field div{color:#e5e0d7;font-size:11px;line-height:1.55;overflow-wrap:anywhere;white-space:pre-wrap}
.wellness-terms{margin-top:13px;color:#9e927e;font-size:10px}
@media(max-width:600px){.wellness-dialog-head{padding:18px}.wellness-dialog-body{padding:15px}.wellness-grid{grid-template-columns:1fr}.wellness-field.full{grid-column:auto}.wellness-overlay{padding:10px}}
.status-locked{opacity:.65;cursor:not-allowed}
.cancel-modal,.admin-dialog-overlay{display:none;position:fixed;inset:0;z-index:9999;background:rgba(5,5,5,.78);backdrop-filter:blur(7px);-webkit-backdrop-filter:blur(7px);align-items:center;justify-content:center;padding:20px}
.cancel-modal.show,.admin-dialog-overlay.show{display:flex;animation:dialogFadeIn .18s ease-out}
.cancel-dialog,.admin-dialog{position:relative;width:min(480px,100%);padding:27px;background:linear-gradient(145deg,#1d1b17,#121212 72%);border:1px solid rgba(193,179,159,.25);border-radius:17px;box-shadow:0 24px 80px rgba(0,0,0,.68),inset 0 1px rgba(255,255,255,.035);animation:dialogRise .2s ease-out}
.dialog-mark{display:flex;align-items:center;justify-content:center;width:43px;height:43px;margin-bottom:17px;border-radius:13px;background:rgba(193,179,159,.10);border:1px solid rgba(193,179,159,.22);color:#d5c29d;font-size:20px}
.dialog-mark.warning{background:rgba(157,111,54,.13);border-color:rgba(193,151,91,.25);color:#d6b77f}
.dialog-mark.danger{background:rgba(133,57,48,.16);border-color:rgba(174,86,72,.3);color:#d98c7f}
.cancel-dialog h3,.admin-dialog h3{margin:0 0 8px;color:#eadcc2;font-family:'Playfair Display',serif;font-size:21px;font-weight:600;letter-spacing:.1px}
.cancel-dialog p,.admin-dialog p{margin:0 0 18px;color:#a59d90;font-size:12px;line-height:1.7}
.cancel-dialog textarea{display:block;width:100%;min-height:118px;resize:vertical;padding:12px 13px;background:#0c0c0b;border:1px solid #40392d;border-radius:9px;color:#eee;font:12px Poppins,sans-serif;outline:none;transition:border-color .18s,box-shadow .18s}
.cancel-dialog textarea::placeholder{color:#716b60}
.cancel-dialog textarea:focus{border-color:#b49b70;box-shadow:0 0 0 3px rgba(180,155,112,.10)}
.cancel-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:9px;margin-top:20px}
.cancel-actions button{min-height:39px;padding:9px 16px;border-radius:8px;border:1px solid #514633;background:#28251f;color:#d8c7a6;font:11px Poppins,sans-serif;font-weight:500;cursor:pointer;transition:background .18s,border-color .18s,transform .18s}
.cancel-actions button:hover{background:#353027;border-color:#8c7958;transform:translateY(-1px)}
.cancel-actions .confirm-cancel{background:linear-gradient(135deg,#783c32,#572a25);border-color:#925047;color:#fff}
.cancel-actions .confirm-cancel:hover{background:linear-gradient(135deg,#8a493d,#68312a);border-color:#b16a5e}
.admin-dialog{width:min(430px,100%)}
.admin-dialog .dialog-message{margin-bottom:0}
@keyframes dialogFadeIn{from{opacity:0}to{opacity:1}}
@keyframes dialogRise{from{opacity:0;transform:translateY(10px) scale(.985)}to{opacity:1;transform:translateY(0) scale(1)}}
@media(max-width:520px){.cancel-dialog,.admin-dialog{padding:22px 19px;border-radius:14px}.cancel-dialog h3,.admin-dialog h3{font-size:19px}.cancel-actions{flex-direction:column-reverse}.cancel-actions button{width:100%}}
.therapist-chip{display:block;padding:6px 8px;background:rgba(213,194,157,.07);border:1px solid rgba(213,194,157,.16);border-radius:6px;color:#e1d2b4;font-size:11px;line-height:1.4}
.therapist-chip small{display:block;margin-bottom:2px;color:#9b8a6c;font-size:8px;letter-spacing:.4px;text-transform:uppercase}
.therapist-manage{margin-top:8px}
@media(max-width:1200px){.stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:1100px){.main-content{margin-left:0;padding:24px 18px 50px}}
@media(max-width:700px){.page-header{align-items:flex-start;flex-direction:column}.walkin-button{width:100%}.stats{grid-template-columns:repeat(2,1fr)}.section-header{align-items:flex-start;flex-direction:column}}
@media(max-width:900px){.table-scroll{overflow-x:auto}.booking-table{min-width:950px}}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<main class="main-content">
    <div class="page-header">
        <div>
            <div class="page-eyebrow">Appointment Management</div>
            <h1>Bookings</h1>
            <p>View new reservations, manage therapists and update appointment status.</p>
        </div>
        <div class="header-actions">
            <div class="notification-wrap">
                <button type="button" class="notification-bell" id="bookingNotificationBell" aria-label="Booking notifications" title="New booking notifications" aria-expanded="false">
                    <svg class="bell-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M18 8a6 6 0 0 0-12 0c0 7-2.5 7-2.5 9h17C20.5 15 18 15 18 8Z" />
                        <path d="M10 21a2 2 0 0 0 4 0" />
                        <path d="M12 2v1" />
                    </svg><span class="notification-count" id="bookingNotificationCount">0</span>
                </button>
                <div class="notification-panel" id="bookingNotificationPanel" aria-label="Booking notification history">
                    <div class="notification-panel-head"><span>Notifications</span><span id="notificationUnreadLabel">0 unread</span></div>
                    <div class="notification-list" id="bookingNotificationList"><div class="notification-empty">No notifications yet.</div></div>
                    <button type="button" class="notification-view-all" id="notificationViewAll">View All Notifications</button>
                </div>
            </div>
            <a href="walkin-booking.php" class="walkin-button">+ New Walk-in Booking</a>
        </div>
    </div>

    <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
        <div class="success-message">Booking successfully created and <strong>Confirmed</strong>.</div>
    <?php endif; ?>

    <div class="stats">
        <a href="bookings.php?filter=new" class="stat-card new-card <?= $filter === 'new' ? 'active' : '' ?>">
            <div class="stat-label">New Bookings</div>
            <div class="stat-number"><span class="new-dot"></span><?= $newBookings ?></div>
        </a>
        <a href="bookings.php?filter=confirmed" class="stat-card <?= $filter === 'confirmed' ? 'active' : '' ?>">
            <div class="stat-label">Confirmed</div><div class="stat-number"><?= $confirmedBookings ?></div>
        </a>
        <a href="bookings.php?filter=completed" class="stat-card <?= $filter === 'completed' ? 'active' : '' ?>">
            <div class="stat-label">Completed</div><div class="stat-number"><?= $completedBookings ?></div>
        </a>
        <a href="bookings.php?filter=cancelled" class="stat-card <?= $filter === 'cancelled' ? 'active' : '' ?>">
            <div class="stat-label">Cancelled</div><div class="stat-number"><?= $cancelledBookings ?></div>
        </a>
        <a href="bookings.php?filter=all" class="stat-card <?= $filter === 'all' ? 'active' : '' ?>">
            <div class="stat-label">All Bookings</div><div class="stat-number"><?= $totalBookings ?></div>
        </a>
    </div>

    <div class="section-header">
        <div><h2><?= htmlspecialchars($filterTitle) ?></h2><p><?= htmlspecialchars($filterDescription) ?></p></div>
        <div class="filter-indicator">
            <?php if ($filter === 'new'): ?>Latest received first
            <?php elseif ($filter === 'confirmed'): ?>Upcoming schedule first
            <?php elseif ($filter === 'all'): ?>Latest booking first
            <?php else: ?><?= ucfirst(htmlspecialchars($filter)) ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="table-container">
        <div class="table-scroll">
            <table class="booking-table">
                <thead><tr>
                    <th>Customer</th><th>Service</th><th>Schedule</th><th>Room / Pax</th><th>Therapist(s)</th><th>Payment</th><th>Notes</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php if ($bookings && $bookings->num_rows > 0): ?>
                    <?php while ($booking = $bookings->fetch_assoc()): ?>
                        <?php
                        $bookingId = (int)$booking['id'];
                        $therapistId = (int)($booking['therapist_id'] ?? 0);
                        $currentStatus = trim($booking['status'] ?? 'Confirmed');
                        $pax = max(1, (int)($booking['pax'] ?? 1));
                        $guestNamesForBooking = $bookingGuests[$bookingId] ?? [];
                        $mainCustomerName = trim((string)($booking['customer_name'] ?? ''));
                        if (!empty($guestNamesForBooking[0]['guest_name'])) {
                            $mainCustomerName = trim((string)$guestNamesForBooking[0]['guest_name']);
                        }
                        $isNew = false;
                        if (!empty($booking['created_at'])) {
                            $createdTimestamp = strtotime($booking['created_at']);
                            $isNew = $createdTimestamp >= strtotime('-24 hours');
                        }

                        // Keep therapist assignments aligned with each guest number.
                        $assignedForBooking = $bookingTherapists[$bookingId] ?? [];
                        $assignedIdsForBooking = $bookingTherapistIds[$bookingId] ?? [];
                        if ($therapistId > 0 && !isset($assignedIdsForBooking[1])) {
                            $assignedIdsForBooking[1] = $therapistId;
                            foreach ($therapists as $activeTherapist) {
                                if ((int)$activeTherapist['id'] === $therapistId) {
                                    $assignedForBooking[1] = $activeTherapist['name'];
                                    break;
                                }
                            }
                        }

                        $assignmentSourcesForBooking = $bookingTherapistSources[$bookingId] ?? [];
                        $hasCustomerSelectedTherapist = false;
                        $hasAdminAssignedTherapist = false;
                        foreach ($assignmentSourcesForBooking as $assignmentSource) {
                            if (in_array($assignmentSource, ['customer', 'guest', 'user', 'client'], true)) {
                                $hasCustomerSelectedTherapist = true;
                            }
                            if (strpos($assignmentSource, 'admin') === 0) {
                                $hasAdminAssignedTherapist = true;
                            }
                        }
                        // Legacy records with a therapist but no admin marker are locked.
                        if ($therapistId > 0 && !$hasAdminAssignedTherapist) {
                            $hasCustomerSelectedTherapist = true;
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="booking-top"><span class="booking-number">Booking #<?= $bookingId ?></span>
                                <?php if ($isNew): ?><span class="new-badge">NEW</span><?php endif; ?></div>
                                <div class="customer-name"><?= htmlspecialchars($mainCustomerName) ?></div>
                                <?php if ($pax > 1 && !empty($guestNamesForBooking)): ?>
                                    <?php foreach ($guestNamesForBooking as $guestIndex => $guestInfo): ?>
                                        <?php if ($guestIndex > 0 && !empty($guestInfo['guest_name'])): ?>
                                            <div class="customer-phone" style="font-size:10px;color:#9b8b70;">Guest <?= (int)$guestInfo['guest_number'] ?>: <?= htmlspecialchars($guestInfo['guest_name']) ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="customer-phone"><?= htmlspecialchars($booking['phone'] ?? '') ?></div>
                                <?php
                                $bookingSource = strtolower(trim($booking['created_by'] ?? ''));

                                if ($bookingSource === 'admin' || $bookingSource === 'walkin' || $bookingSource === 'walk-in') {
                                    $displaySource = 'Walk-in';
                                } elseif (!empty($booking['user_id']) && (int)$booking['user_id'] > 0) {
                                    $displaySource = 'Customer';
                                } else {
                                    $displaySource = 'Guest';
                                }
                                ?>

<div class="source-badge"><?= htmlspecialchars($displaySource) ?></div>
                                <?php if (!empty($booking['created_at'])): ?><div class="created-time">Booked: <?= date('M d, g:i A', strtotime($booking['created_at'])) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <div class="guest-service-list">
                                    <?php if (!empty($guestNamesForBooking)): ?>
                                        <?php foreach ($guestNamesForBooking as $guestIndex => $guestInfo): ?>
                                            <?php
                                            $guestService = trim((string)($guestInfo['service'] ?? ''));
                                            if ($guestService === '' || stripos($guestService, 'Individual Booking') !== false || stripos($guestService, 'Group Booking') !== false) {
                                                $guestService = trim((string)($booking['service'] ?? ''));
                                            }
                                            if ($guestService === '' || stripos($guestService, 'Individual Booking') !== false || stripos($guestService, 'Group Booking') !== false) $guestService = 'Service details unavailable';
                                            $guestDuration = trim((string)($guestInfo['duration'] ?? '')) ?: trim((string)($booking['duration'] ?? ''));
                                            $guestPrice = (float)($guestInfo['price'] ?? 0);
                                            if ($guestPrice <= 0 && count($guestNamesForBooking) === 1) $guestPrice = (float)($booking['price'] ?? 0);
                                            $guestAddons = [];
                                            if (!empty($bookingGuestAddons[(int)$guestInfo['id']])) {
                                                foreach ($bookingGuestAddons[(int)$guestInfo['id']] as $savedAddon) {
                                                    $guestAddons[] = trim($savedAddon['addon_name']) . ((int)$savedAddon['quantity'] > 1 ? ' × ' . (int)$savedAddon['quantity'] : '') . ' (₱' . number_format((float)$savedAddon['price'], 2) . ')';
                                                }
                                            } elseif (!empty($guestInfo['addons'])) {
                                                $guestAddons[] = trim((string)$guestInfo['addons']);
                                            } elseif (count($guestNamesForBooking) === 1 && !empty($booking['addons'])) {
                                                $guestAddons[] = trim((string)$booking['addons']);
                                            }
                                            ?>
                                            <div class="guest-service-item">
                                                <div class="guest-service-title">Guest <?= (int)($guestInfo['guest_number'] ?? ($guestIndex + 1)) ?><?= !empty($guestInfo['guest_name']) ? ' · ' . htmlspecialchars($guestInfo['guest_name']) : '' ?></div>
                                                <div class="guest-service-name"><?= htmlspecialchars($guestService) ?></div>
                                                <div class="guest-service-meta"><?= htmlspecialchars($guestDuration ?: 'Duration not specified') ?> · <span class="service-price">₱<?= number_format($guestPrice, 2) ?></span></div>
                                                <?php if ($guestAddons): ?><div class="guest-addon-line">Add-ons: <?= htmlspecialchars(implode(', ', $guestAddons)) ?></div><?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="guest-service-item">
                                            <div class="guest-service-name"><?= htmlspecialchars($booking['service'] ?? 'Service details unavailable') ?></div>
                                            <div class="guest-service-meta"><?= htmlspecialchars($booking['duration'] ?? '') ?> · <span class="service-price">₱<?= number_format((float)($booking['price'] ?? 0), 2) ?></span></div>
                                            <?php if (!empty($booking['addons'])): ?><div class="guest-addon-line">Add-ons: <?= htmlspecialchars($booking['addons']) ?></div><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php
                                $wellnessGuestCount = 0;
                                foreach ($guestNamesForBooking as $wellnessGuest) {
                                    if (isset($bookingGuestWellness[(int)$wellnessGuest['id']])) $wellnessGuestCount++;
                                }
                                ?>
                                <?php if ($wellnessGuestCount > 0): ?>
                                    <button type="button" class="wellness-view-btn" onclick="openWellnessProfile('wellness-template-<?= $bookingId ?>')">View Wellness Profile (<?= $wellnessGuestCount ?>/<?= count($guestNamesForBooking) ?>)</button>
                                    <div id="wellness-template-<?= $bookingId ?>" hidden>
                                        <?php foreach ($guestNamesForBooking as $wellnessGuestIndex => $wellnessGuest): ?>
                                            <?php $wellnessData = $bookingGuestWellness[(int)$wellnessGuest['id']] ?? null; ?>
                                            <section class="wellness-guest-card">
                                                <div class="wellness-guest-title">
                                                    <span>Guest <?= (int)($wellnessGuest['guest_number'] ?? ($wellnessGuestIndex + 1)) ?> · <?= htmlspecialchars($wellnessGuest['guest_name'] ?? 'Guest') ?></span>
                                                    <small><?= $wellnessData ? 'Profile submitted' : 'No profile submitted' ?></small>
                                                </div>
                                                <?php if ($wellnessData): ?>
                                                    <div class="wellness-grid">
                                                        <div class="wellness-field"><label>Allergies</label><div><?= htmlspecialchars(trim((string)($wellnessData['allergies'] ?? '')) ?: 'Not provided') ?></div></div>
                                                        <div class="wellness-field"><label>Skin Sensitivity</label><div><?= htmlspecialchars(trim((string)($wellnessData['skin_sensitivity'] ?? '')) ?: 'Not provided') ?></div></div>
                                                        <div class="wellness-field"><label>Medical Conditions</label><div><?= htmlspecialchars(trim((string)($wellnessData['medical_conditions'] ?? '')) ?: 'Not provided') ?></div></div>
                                                        <div class="wellness-field"><label>Body Pain / Injuries</label><div><?= htmlspecialchars(trim((string)($wellnessData['body_pain_injuries'] ?? '')) ?: 'Not provided') ?></div></div>
                                                        <div class="wellness-field"><label>Preferred Pressure</label><div><?= htmlspecialchars(trim((string)($wellnessData['preferred_pressure'] ?? '')) ?: 'Not provided') ?></div></div>
                                                        <div class="wellness-field full"><label>Special Requests</label><div><?= htmlspecialchars(trim((string)($wellnessData['special_requests'] ?? '')) ?: 'None') ?></div></div>
                                                        <div class="wellness-field"><label>Emergency Contact Name</label><div><?= htmlspecialchars(trim((string)($wellnessData['emergency_contact_name'] ?? '')) ?: 'Not provided') ?></div></div>
                                                        <div class="wellness-field"><label>Emergency Contact Number</label><div><?= htmlspecialchars(trim((string)($wellnessData['emergency_contact_number'] ?? '')) ?: 'Not provided') ?></div></div>
                                                    </div>
                                                    <div class="wellness-terms">Terms accepted: <?= !empty($wellnessData['terms_accepted']) ? 'Yes' : 'No' ?><?php if (!empty($wellnessData['terms_accepted_at'])): ?> · <?= date('M d, Y g:i A', strtotime($wellnessData['terms_accepted_at'])) ?><?php endif; ?></div>
                                                <?php else: ?>
                                                    <div class="wellness-field"><div>No Wellness Profile answers were saved for this guest.</div></div>
                                                <?php endif; ?>
                                            </section>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif (!empty($guestNamesForBooking)): ?>
                                    <div class="wellness-none">No Wellness Profile submitted</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="schedule-date"><?= !empty($booking['booking_date']) ? date('M d, Y', strtotime($booking['booking_date'])) : '—' ?></div>
                                <div class="schedule-time"><?= !empty($booking['booking_time']) ? date('g:i A', strtotime($booking['booking_time'])) : '—' ?></div>
                            </td>
                            <td>
                                <div class="room-name"><?= htmlspecialchars(!empty($booking['room_type']) ? $booking['room_type'] : 'N/A') ?></div>
                                <div class="room-pax"><?= $pax ?> <?= $pax === 1 ? 'guest' : 'guests' ?></div>
                            </td>
                            <td>
                                <div class="therapist-list">
                                    <?php for ($guestSlot = 1; $guestSlot <= min(4, $pax); $guestSlot++): ?>
                                        <?php $shownTherapist = $assignedForBooking[$guestSlot] ?? ''; ?>
                                        <div class="therapist-chip">
                                            <small><?= $pax > 1 ? 'Guest ' . $guestSlot : 'Assigned Therapist' ?></small>
                                            <?= htmlspecialchars($shownTherapist !== '' ? $shownTherapist : 'Not assigned') ?>
                                        </div>
                                    <?php endfor; ?>
                                </div>

                                <?php if (!$hasCustomerSelectedTherapist): ?>
                                    <div class="therapist-manage">
                                        <?php for ($guestSlot = 1; $guestSlot <= min(4, $pax); $guestSlot++): ?>
                                            <?php
                                            $guestAssignedId = (int)($assignedIdsForBooking[$guestSlot] ?? 0);
                                            $selectId = 'therapist-' . $bookingId . '-' . $guestSlot;
                                            ?>
                                            <label class="control-label" for="<?= $selectId ?>">
                                                <?= $pax > 1 ? 'Assign Therapist — Guest ' . $guestSlot : 'Assign Therapist (No Preference)' ?>
                                            </label>
                                            <select id="<?= $selectId ?>"
                                                class="admin-select <?= in_array($currentStatus, ['Completed', 'Cancelled'], true) ? 'status-locked' : '' ?>"
                                                data-old-value="<?= $guestAssignedId ?>"
                                                onchange="changeTherapist(this, <?= $bookingId ?>, <?= $guestSlot ?>)"
                                                <?= in_array($currentStatus, ['Completed', 'Cancelled'], true) ? 'disabled' : '' ?>>
                                                <option value="0" <?= $guestAssignedId === 0 ? 'selected' : '' ?>>No Preference</option>
                                                <?php foreach ($therapists as $therapist): ?>
                                                    <?php
                                                    $optionId = (int)$therapist['id'];
                                                    $optionBusy = !isTherapistAvailable(
                                                        $conn,
                                                        $optionId,
                                                        $booking['booking_date'],
                                                        $booking['booking_time'],
                                                        $booking['duration'],
                                                        $bookingId
                                                    );
                                                    $usedByAnotherGuest = false;
                                                    foreach ($assignedIdsForBooking as $existingSlot => $existingTherapistId) {
                                                        if ((int)$existingSlot !== $guestSlot && (int)$existingTherapistId === $optionId) {
                                                            $usedByAnotherGuest = true;
                                                            break;
                                                        }
                                                    }
                                                    if ($guestSlot !== 1 && $therapistId === $optionId) {
                                                        $usedByAnotherGuest = true;
                                                    }
                                                    $optionDisabled = $optionBusy || $usedByAnotherGuest;
                                                    $optionLabel = htmlspecialchars($therapist['name']);
                                                    if ($optionBusy) $optionLabel .= ' (Unavailable)';
                                                    elseif ($usedByAnotherGuest) $optionLabel .= ' (Assigned to another guest)';
                                                    ?>
                                                    <option value="<?= $optionId ?>" <?= $guestAssignedId === $optionId ? 'selected' : '' ?> <?= $optionDisabled ? 'disabled' : '' ?>>
                                                        <?= $optionLabel ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endfor; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><div class="payment"><?= htmlspecialchars($booking['payment_method'] ?? 'Cash') ?></div></td>
                            <td><div class="notes"><?php if (!empty($booking['notes'])): ?><?= nl2br(htmlspecialchars($booking['notes'])) ?><?php else: ?>—<?php endif; ?></div></td>
                            <td>
                                <span class="control-label">Booking Status</span>
                                <?php if (in_array($currentStatus, ['Completed', 'Cancelled'], true)): ?>
                                    <select class="admin-select status-locked" disabled><option selected><?= htmlspecialchars($currentStatus) ?> · Final</option></select>
                                    <?php if ($currentStatus === 'Cancelled' && !empty($booking['cancellation_reason'])): ?>
                                        <div class="guest-addon-line" style="margin-top:6px">Reason: <?= nl2br(htmlspecialchars($booking['cancellation_reason'])) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <form method="POST" class="status-form" data-booking-id="<?= $bookingId ?>" data-original-status="<?= htmlspecialchars($currentStatus) ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                                        <input type="hidden" name="return_filter" value="<?= htmlspecialchars($filter) ?>">
                                        <input type="hidden" name="cancellation_reason" value="">
                                        <select name="status" class="admin-select" onchange="handleStatusChange(this)">
                                            <?php if ($currentStatus === 'Confirmed'): ?>
                                                <option value="Confirmed" selected disabled>Confirmed</option>
                                            <?php elseif (!in_array($currentStatus, ['Completed', 'Cancelled'], true)): ?>
                                                <option value="<?= htmlspecialchars($currentStatus) ?>" selected disabled><?= htmlspecialchars($currentStatus) ?></option>
                                            <?php endif; ?>
                                            <option value="Completed">Completed</option>
                                            <option value="Cancelled">Cancelled</option>
                                        </select>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="empty"><?php if ($filter === 'new'): ?>No new bookings within the last 24 hours.<?php else: ?>No <?= $filter === 'all' ? '' : htmlspecialchars($filter) ?> bookings found.<?php endif; ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<div class="wellness-overlay" id="wellnessProfileModal" role="dialog" aria-modal="true" aria-labelledby="wellnessModalTitle" aria-hidden="true" onclick="if(event.target===this)closeWellnessProfile()">
    <div class="wellness-dialog">
        <div class="wellness-dialog-head">
            <div><h3 id="wellnessModalTitle">Guest Wellness Profile</h3><p>Submitted wellness information for each guest in this booking.</p></div>
            <button type="button" class="wellness-close" onclick="closeWellnessProfile()" aria-label="Close">&times;</button>
        </div>
        <div class="wellness-dialog-body" id="wellnessProfileContent"></div>
    </div>
</div>
<div class="cancel-modal" id="cancelModal" role="dialog" aria-modal="true" aria-labelledby="cancelModalTitle" aria-hidden="true">
    <div class="cancel-dialog">
        <div class="dialog-mark danger" aria-hidden="true">!</div>
        <h3 id="cancelModalTitle">Cancel Booking</h3>
        <p>Please provide the reason for cancelling this booking. This reason will be saved in the booking record.</p>
        <textarea id="cancellationReasonInput" maxlength="1000" placeholder="Enter cancellation reason..." required></textarea>
        <div class="cancel-actions">
            <button type="button" onclick="closeCancelModal()">Go Back</button>
            <button type="button" class="confirm-cancel" onclick="confirmCancellation()">Confirm Cancellation</button>
        </div>
    </div>
</div>
<div class="admin-dialog-overlay" id="adminDialog" role="dialog" aria-modal="true" aria-labelledby="adminDialogTitle" aria-hidden="true">
    <div class="admin-dialog">
        <div class="dialog-mark warning" id="adminDialogMark" aria-hidden="true">!</div>
        <h3 id="adminDialogTitle">Confirm Action</h3>
        <p class="dialog-message" id="adminDialogMessage"></p>
        <div class="cancel-actions">
            <button type="button" id="adminDialogCancel">Go Back</button>
            <button type="button" class="confirm-cancel" id="adminDialogConfirm">Continue</button>
        </div>
    </div>
</div>
<div class="booking-notification-toast" id="bookingNotificationToast" role="status" aria-live="polite" onclick="window.location.href='bookings.php?filter=new'">
    <strong id="bookingNotificationTitle">New booking received</strong>
    <span id="bookingNotificationMessage">A Guest or Customer submitted a new booking.</span>
</div>
<script>
let pendingCancelForm = null;
let adminDialogAction = null;

// Check for newly submitted Guest/Customer bookings every 15 seconds.
(function initBookingNotifications() {
    const bell = document.getElementById('bookingNotificationBell');
    const count = document.getElementById('bookingNotificationCount');
    const toast = document.getElementById('bookingNotificationToast');
    const toastTitle = document.getElementById('bookingNotificationTitle');
    const toastMessage = document.getElementById('bookingNotificationMessage');
    if (!bell || !count || !toast) return;

    let unread = 0;
    let toastTimer = null;
    let audioContext = null;
    let audioUnlocked = false;

    // Browsers require a user gesture before notification audio can play.
    function unlockNotificationAudio() {
        if (audioUnlocked) return;
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;
        try {
            audioContext = audioContext || new AudioContextClass();
            if (audioContext.state === 'suspended') audioContext.resume();
            audioUnlocked = true;
        } catch (error) {
            audioContext = null;
        }
    }
    function playNotificationSound() {
        if (!audioContext || audioContext.state !== 'running') return;
        try {
            const now = audioContext.currentTime;
            [0, 0.16].forEach((offset, index) => {
                const oscillator = audioContext.createOscillator();
                const gain = audioContext.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(index === 0 ? 880 : 1174.66, now + offset);
                gain.gain.setValueAtTime(0.0001, now + offset);
                gain.gain.exponentialRampToValueAtTime(0.12, now + offset + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.24);
                oscillator.connect(gain);
                gain.connect(audioContext.destination);
                oscillator.start(now + offset);
                oscillator.stop(now + offset + 0.25);
            });
        } catch (error) {
            // Visual notifications remain available if audio is blocked.
        }
    }
    document.addEventListener('pointerdown', unlockNotificationAudio, {once:true});
    document.addEventListener('keydown', unlockNotificationAudio, {once:true});

    const panel = document.getElementById('bookingNotificationPanel');
    const list = document.getElementById('bookingNotificationList');
    const unreadLabel = document.getElementById('notificationUnreadLabel');
    let showingAll = false;
    const viewAllButton = document.getElementById('notificationViewAll');
    if (viewAllButton) viewAllButton.addEventListener('click', () => {
        showingAll = !showingAll;
        viewAllButton.textContent = showingAll ? 'Show Latest 10' : 'View All Notifications';
        loadNotificationHistory(showingAll);
    });

    function formatNotificationTime(value) {
        const raw = String(value || '').trim();
        if (!raw) return 'Time not set';
        const match = raw.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
        if (!match) return raw;
        const hour = Number(match[1]);
        if (hour < 0 || hour > 23 || Number(match[2]) > 59) return raw;
        const suffix = hour >= 12 ? 'PM' : 'AM';
        return (hour % 12 || 12) + ':' + match[2] + ' ' + suffix;
    }

    function renderHistory(items, unreadCount) {
        count.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
        count.style.display = unreadCount ? 'block' : 'none';
        unreadLabel.textContent = unreadCount + ' unread';
        if (!items.length) { list.innerHTML = '<div class="notification-empty">No notifications yet.</div>'; return; }
        list.innerHTML = '';
        items.forEach(item => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'notification-item' + (Number(item.is_read) ? '' : ' unread');
            const title = document.createElement('strong');
            title.textContent = 'New ' + item.source + ' booking · #' + item.booking_id;
            const detail = document.createElement('span');
            const displayTime = formatNotificationTime(item.booking_time);
            detail.textContent = (item.customer_name || 'Guest/Customer') + ' · ' + (item.booking_date || 'Date not set') + ' · ' + displayTime;
            button.append(title, detail);
            button.addEventListener('click', async () => {
                if (!Number(item.is_read)) {
                    const body = new URLSearchParams(); body.set('mark_booking_notification_read', item.id);
                    await fetch('bookings.php', {method:'POST', body, credentials:'same-origin'}).catch(() => {});
                }
                window.location.href = 'bookings.php?filter=new&highlight=' + encodeURIComponent(item.booking_id);
            });
            list.appendChild(button);
        });
    }
    async function loadNotificationHistory(all=false) {
        try {
            const response = await fetch('bookings.php?ajax_notification_history=1' + (all ? '&all=1' : ''), {cache:'no-store', credentials:'same-origin'});
            const data = await response.json();
            if (data && data.success) renderHistory(data.items || [], Number(data.unread_count || 0));
        } catch (error) {}
    }
    bell.addEventListener('click', () => {
        const opening = !panel.classList.contains('open');
        panel.classList.toggle('open', opening);
        bell.setAttribute('aria-expanded', opening ? 'true' : 'false');
        if (opening) loadNotificationHistory(showingAll);
    });
    document.addEventListener('click', event => {
        if (!event.target.closest('.notification-wrap')) {
            panel.classList.remove('open'); bell.setAttribute('aria-expanded', 'false');
        }
    });

    function showNotification(item) {
        playNotificationSound();
        unread += 1;
        count.textContent = unread > 99 ? '99+' : String(unread);
        count.style.display = 'block';
        toastTitle.textContent = 'New ' + item.source + ' booking received';
        const dateText = item.date ? new Date(item.date + 'T00:00:00').toLocaleDateString(undefined, {month:'short', day:'numeric', year:'numeric'}) : 'Date not set';
        const timeText = formatNotificationTime(item.time);
        toastMessage.textContent = (item.customer || 'New customer') + ' · ' + dateText + ' · ' + timeText + ' · Booking #' + item.id;
        toast.classList.add('show');
        loadNotificationHistory(showingAll);
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 9000);
    }

    // Restore the toast after the automatic refresh so the new booking is visible in the list.
    try {
        const pending = sessionStorage.getItem('mizpahPendingBookingToast');
        if (pending) {
            sessionStorage.removeItem('mizpahPendingBookingToast');
            showNotification(JSON.parse(pending));
        }
    } catch (error) {}

    let refreshInProgress = false;
    async function pollNewBookings() {
        if (document.hidden || refreshInProgress) return;
        try {
            const response = await fetch('bookings.php?ajax_new_booking_notifications=1', {cache:'no-store', credentials:'same-origin'});
            if (!response.ok) return;
            const data = await response.json();
            if (data && data.success && Array.isArray(data.notifications) && data.notifications.length) {
                data.notifications.forEach(showNotification);
                // Reload the current filter so the newly submitted booking appears without manual refresh.
                // Keep the latest toast across reload; the server marks these IDs as already notified.
                try { sessionStorage.setItem('mizpahPendingBookingToast', JSON.stringify(data.notifications[data.notifications.length - 1])); } catch (error) {}
                refreshInProgress = true;
                setTimeout(() => window.location.reload(), 900);
            }
        } catch (error) {
            // Keep the bookings page usable if notification polling is temporarily unavailable.
        }
    }
    loadNotificationHistory(false);
    setTimeout(pollNewBookings, 2500);
    setInterval(pollNewBookings, 15000);
})();

function openWellnessProfile(templateId) {
    const template = document.getElementById(templateId);
    const modal = document.getElementById('wellnessProfileModal');
    const content = document.getElementById('wellnessProfileContent');
    if (!template || !modal || !content) return;
    content.innerHTML = template.innerHTML;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}
function closeWellnessProfile() {
    const modal = document.getElementById('wellnessProfileModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('wellnessProfileContent').innerHTML = '';
    document.body.style.overflow = '';
}

function showAdminDialog(title, message, options = {}) {
    const overlay = document.getElementById('adminDialog');
    const mark = document.getElementById('adminDialogMark');
    const cancelButton = document.getElementById('adminDialogCancel');
    const confirmButton = document.getElementById('adminDialogConfirm');

    document.getElementById('adminDialogTitle').textContent = title;
    document.getElementById('adminDialogMessage').textContent = message;
    mark.textContent = options.type === 'danger' ? '!' : (options.type === 'success' ? '✓' : '?');
    mark.className = 'dialog-mark ' + (options.type || 'warning');
    confirmButton.textContent = options.confirmText || 'Continue';
    cancelButton.style.display = options.showCancel === false ? 'none' : '';
    adminDialogAction = options.onConfirm || null;

    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    if (options.showCancel === false) confirmButton.focus();
    else cancelButton.focus();
}
function closeAdminDialog(runConfirm) {
    const overlay = document.getElementById('adminDialog');
    overlay.classList.remove('show');
    overlay.setAttribute('aria-hidden', 'true');
    const action = adminDialogAction;
    adminDialogAction = null;
    if (runConfirm && typeof action === 'function') action();
}
document.getElementById('adminDialogCancel').addEventListener('click', () => closeAdminDialog(false));
document.getElementById('adminDialogConfirm').addEventListener('click', () => closeAdminDialog(true));
document.getElementById('adminDialog').addEventListener('click', function(e) {
    if (e.target === this) closeAdminDialog(false);
});

function handleStatusChange(select) {
    const form = select.closest('form');
    if (!form) return;
    if (select.value === 'Cancelled') {
        pendingCancelForm = form;
        document.getElementById('cancellationReasonInput').value = '';
        const modal = document.getElementById('cancelModal');
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        setTimeout(() => document.getElementById('cancellationReasonInput').focus(), 80);
        return;
    }
    if (select.value === 'Completed') {
        showAdminDialog('Complete Booking?', 'Mark this booking as Completed? This is a final status and cannot be changed afterward.', {
            type: 'warning',
            confirmText: 'Confirm Completed',
            onConfirm: () => form.submit()
        });
        return;
    }
    form.submit();
}
function closeCancelModal() {
    const modal = document.getElementById('cancelModal');
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    if (pendingCancelForm) pendingCancelForm.querySelector('select[name="status"]').value = pendingCancelForm.dataset.originalStatus || 'Confirmed';
    pendingCancelForm = null;
}
function confirmCancellation() {
    const reasonInput = document.getElementById('cancellationReasonInput');
    const reason = reasonInput.value.trim();
    if (!reason) {
        showAdminDialog('Cancellation Reason Required', 'Please enter a reason before cancelling this booking.', {
            type: 'danger',
            showCancel: false,
            confirmText: 'Okay'
        });
        reasonInput.focus();
        return;
    }
    if (!pendingCancelForm) return;
    pendingCancelForm.querySelector('input[name="cancellation_reason"]').value = reason;
    pendingCancelForm.submit();
}
document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) closeCancelModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const wellnessModal = document.getElementById('wellnessProfileModal');
        const cancelModal = document.getElementById('cancelModal');
        if (wellnessModal.classList.contains('show')) closeWellnessProfile();
        else if (cancelModal.classList.contains('show')) closeCancelModal();
        else if (document.getElementById('adminDialog').classList.contains('show')) closeAdminDialog(false);
    }
});
function changeTherapist(select, bookingId, guestIndex = 1) {
    const oldValue = select.dataset.oldValue;
    const therapistId = select.value;
    select.disabled = true;
    const data = new URLSearchParams();
    data.append('ajax_change_therapist', '1');
    data.append('booking_id', bookingId);
    data.append('therapist_id', therapistId);
    data.append('guest_index', guestIndex);

    fetch('bookings.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: data.toString()
    })
    .then(response => response.text())
    .then(result => {
        result = result.trim();
        if (result === 'OK') {
            window.location.reload();
            return;
        }
        if (result === 'LOCKED') {
            showAdminDialog('Booking Locked', 'Completed and Cancelled bookings are final and cannot be edited.', {type:'warning', showCancel:false, confirmText:'Okay'});
            window.location.reload();
            return;
        }
        if (result === 'CUSTOMER_SELECTED') {
            showAdminDialog('Therapist Locked', 'The customer/guest selected this therapist during booking. Admin cannot change the customer’s selection.', {type:'warning', showCancel:false, confirmText:'Okay'});
            window.location.reload();
            return;
        }
        if (result === 'BUSY') {
            showAdminDialog('Therapist Unavailable', 'This therapist is unavailable for this booking time because they are already serving another booking or have an overlapping appointment.', {type:'warning', showCancel:false, confirmText:'Okay'});
        } else if (result === 'INVALID') {
            showAdminDialog('Invalid Selection', 'Invalid booking or therapist.', {type:'danger', showCancel:false, confirmText:'Okay'});
        } else {
            showAdminDialog('Update Failed', 'Unable to update therapist. Please try again.', {type:'danger', showCancel:false, confirmText:'Okay'});
        }
        select.value = oldValue;
        select.disabled = false;
    })
    .catch(() => {
        showAdminDialog('Update Failed', 'Unable to update therapist. Please try again.', {type:'danger', showCancel:false, confirmText:'Okay'});
        select.value = oldValue;
        select.disabled = false;
    });
}
</script>
</body>
</html>
