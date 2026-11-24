<?php

session_start();
date_default_timezone_set('Asia/Manila');
include '../includes/db.php';

/** @var mysqli $conn */

error_reporting(E_ALL);
ini_set('display_errors', 1);


/* =========================
   CHECK LOGIN
========================= */

if(!isset($_SESSION['user_id'])){

    header("Location: ../login.php");
    exit;

}

$user_id = (int)$_SESSION['user_id'];

/** Return the appointment's actual start timestamp. Booking times may be stored
 *  as a range (for example, 09:00 AM - 10:00 AM), so parse only the start time.
 */
function mizpahBookingStartTimestamp($bookingDate, $bookingTime) {
    $bookingDate = trim((string)$bookingDate);
    $bookingTime = trim((string)$bookingTime);
    if ($bookingDate === '' || $bookingTime === '') return false;

    $parts = preg_split('/\s*(?:-|–|—|\bto\b)\s*/iu', $bookingTime, 2);
    $startTime = trim($parts[0] ?? $bookingTime);
    if ($startTime === '') return false;

    $timestamp = strtotime($bookingDate . ' ' . $startTime);
    return $timestamp ?: false;
}

// Customer cancellation is allowed only until 1 hour before the appointment starts.
$bookingActionMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $cancelId = (int)($_POST['booking_id'] ?? 0);
    $reason = trim((string)($_POST['cancellation_reason'] ?? ''));
    if ($cancelId > 0) {
        if ($reason === '') {
            $bookingActionMessage = 'Please provide a reason for cancelling this booking.';
        } else {
        $find = $conn->prepare("SELECT * FROM bookings WHERE id = ? AND user_id = ? LIMIT 1");
        if ($find) {
            $find->bind_param('ii', $cancelId, $user_id);
            $find->execute();
            $row = $find->get_result()->fetch_assoc();
            $find->close();
            if (!$row) {
                $bookingActionMessage = 'Booking not found.';
            } elseif (in_array(strtolower(trim((string)$row['status'])), ['completed','cancelled','canceled'], true)) {
                $bookingActionMessage = 'This booking can no longer be cancelled.';
            } else {
                $appointmentTime = mizpahBookingStartTimestamp($row['booking_date'] ?? '', $row['booking_time'] ?? '');
                if (!$appointmentTime || time() > ($appointmentTime - 3600)) {
                    $bookingActionMessage = 'Bookings can only be cancelled at least 1 hour before the appointment.';
                } else {
                    $reason = mb_substr($reason, 0, 1000);
                    $upd = $conn->prepare("UPDATE bookings SET status = 'Cancelled', cancellation_reason = ? WHERE id = ? AND user_id = ? AND status NOT IN ('Completed','Cancelled')");
                    if ($upd) {
                        $upd->bind_param('sii', $reason, $cancelId, $user_id);
                        $upd->execute();
                        $changed = $upd->affected_rows;
                        $upd->close();
                        if ($changed > 0) {
                            $title = 'Booking cancelled';
                            $message = 'Your booking #' . $cancelId . ' has been cancelled successfully.';
                            $kind = 'booking_cancelled';
                            $n = $conn->prepare("INSERT INTO customer_notifications (user_id, booking_id, title, message, notification_type) VALUES (?, ?, ?, ?, ?)");
                            if ($n) { $n->bind_param('iisss', $user_id, $cancelId, $title, $message, $kind); $n->execute(); $n->close(); }

                            // Send the same cancellation status email used by the admin workflow.
                            // Account customers receive this automatically when they cancel their own booking.
                            $customerEmail = trim((string)($row['email'] ?? ''));
                            if (filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                                try {
                                    require_once __DIR__ . '/../includes/mailer.php';
                                    if (function_exists('mizpahSendBookingStatusEmail')) {
                                        mizpahSendBookingStatusEmail($row, 'Cancelled', $reason);
                                    } else {
                                        error_log('Mizpah mailer is missing mizpahSendBookingStatusEmail().');
                                    }
                                } catch (Throwable $mailError) {
                                    error_log('Mizpah customer cancellation email error: ' . $mailError->getMessage());
                                }
                            } else {
                                error_log('Mizpah customer cancellation email skipped: invalid or missing booking email for booking #' . $cancelId);
                            }

                            $bookingActionMessage = 'Your booking has been cancelled.';
                        } else {
                            $bookingActionMessage = 'The booking could not be cancelled. Please refresh and try again.';
                        }
                    }
                }
            }
        }
        }
    }
}

// Load recent in-app notifications for this customer.
$customerNotifications = [];
$unreadNotifications = 0;
$notificationStmt = $conn->prepare("SELECT id, booking_id, title, message, is_read, created_at FROM customer_notifications WHERE user_id = ? ORDER BY id DESC LIMIT 10");
if ($notificationStmt) {
    $notificationStmt->bind_param('i', $user_id);
    $notificationStmt->execute();
    $notificationResult = $notificationStmt->get_result();
    while ($notificationRow = $notificationResult->fetch_assoc()) {
        $customerNotifications[] = $notificationRow;
        if ((int)$notificationRow['is_read'] === 0) $unreadNotifications++;
    }
    $notificationStmt->close();
}
// Mark visible notifications as read when the page is opened.
$markRead = $conn->prepare("UPDATE customer_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
if ($markRead) { $markRead->bind_param('i', $user_id); $markRead->execute(); $markRead->close(); }


/* =========================
   GET PROFILE PICTURE
========================= */

$userQ = mysqli_query($conn, "
    SELECT profile_pic
    FROM users
    WHERE id='$user_id'
    LIMIT 1
");

$userRow = mysqli_fetch_assoc($userQ);

$profilePic = $userRow['profile_pic'] ?? '';

if(empty($profilePic)){

    $profilePic =
        '../assets/images/default-profile.png';

}else{

    $profilePic =
        '../uploads/profile/' . $profilePic;

}


/* =========================
   BOOKINGS
========================= */

$bookings = mysqli_query($conn, "
    SELECT *
    FROM bookings
    WHERE user_id='$user_id'
    ORDER BY id DESC
");

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>My Bookings</title>

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

body{

    background:
        linear-gradient(
            rgba(0,0,0,.78),
            rgba(0,0,0,.90)
        ),
        url('../assets/images/hero.jpg')
        center/cover fixed no-repeat;

    color:#fff;
    min-height:100vh;
}


/* =========================
   HEADER
========================= */

header{

    display:flex;
    justify-content:space-between;
    align-items:center;

    padding:14px 8%;

    background:rgba(10,10,10,.94);

    border-bottom:
        1px solid
        rgba(214,194,156,.15);

    position:sticky;
    top:0;
    z-index:99;

}

.logo{

    display:flex;
    align-items:center;

    gap:10px;

    color:#D6C29C;

    font-weight:600;

}

.logo img{

    height:40px;

}

nav{

    display:flex;
    align-items:center;

    gap:8px;

}

nav a{

    color:#fff;

    padding:8px 11px;

    text-decoration:none;

    font-size:13px;

    opacity:.8;

    transition:.2s;

}

nav a:hover{

    color:#D6C29C;
    opacity:1;

}

nav a.active{

    color:#D6C29C;
    opacity:1;

}

.notification-wrap{position:relative;display:inline-flex;align-items:center}
.notification-toggle{position:relative;border:1px solid rgba(214,194,156,.35);background:#171512;color:#D6C29C;border-radius:20px;padding:8px 12px;font-size:12px;cursor:pointer}
.notification-count{display:inline-flex;align-items:center;justify-content:center;min-width:17px;height:17px;border-radius:50%;background:#b88b4a;color:#111;font-size:10px;font-weight:700;margin-left:5px}
.notification-panel{display:none;position:absolute;right:0;top:42px;width:min(340px,85vw);max-height:360px;overflow:auto;background:#171512;border:1px solid rgba(214,194,156,.28);border-radius:12px;padding:12px;box-shadow:0 15px 40px #000;z-index:200}
.notification-wrap:focus-within .notification-panel,.notification-wrap:hover .notification-panel{display:block}
.notification-item{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);font-size:11px;color:#c9c0b1;line-height:1.6}
.notification-item:last-child{border:0}.notification-item strong{display:block;color:#e2c99c;font-size:12px}
.action-message{margin:0 0 18px;padding:12px 15px;border:1px solid rgba(214,194,156,.3);border-radius:9px;color:#e6d3ad;background:rgba(214,194,156,.08);font-size:12px}
.cancel-booking-form{display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap}
.btn-cancel{display:inline-block;padding:9px 13px;border-radius:8px;border:1px solid #925047;background:#572a25;color:#fff;font:11px Poppins,sans-serif;cursor:pointer;transition:.2s ease}
.btn-cancel:hover{background:#71362f;transform:translateY(-1px)}
.customer-cancel-backdrop{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(0,0,0,.78);backdrop-filter:blur(5px)}
.customer-cancel-backdrop.is-visible{display:flex}
.customer-cancel-dialog{width:100%;max-width:420px;padding:27px 25px 23px;background:linear-gradient(145deg,#1b1a17,#10100f);border:1px solid rgba(214,194,156,.35);border-radius:17px;box-shadow:0 22px 70px rgba(0,0,0,.65);animation:mizpahCancelIn .18s ease-out}
.customer-cancel-icon{width:46px;height:46px;margin:0 auto 13px;display:flex;align-items:center;justify-content:center;border:1px solid rgba(191,112,96,.6);border-radius:50%;color:#e0a093;background:rgba(191,112,96,.10);font-size:22px}
.customer-cancel-dialog h2{margin:0 0 8px;text-align:center;color:#D6C29C;font-family:'Playfair Display',serif;font-size:21px;font-weight:600}
.customer-cancel-dialog .cancel-intro{margin:0 0 17px;text-align:center;color:#c4c0b8;font-size:11px;line-height:1.7}
.customer-cancel-dialog label{display:block;margin:0 0 7px;color:#e1d3b8;font-size:11px;font-weight:600}
.customer-cancel-dialog textarea{display:block;width:100%;min-height:112px;resize:vertical;padding:12px;border:1px solid rgba(214,194,156,.32);border-radius:9px;outline:none;background:#12110f;color:#f4eee4;font:11px Poppins,sans-serif;line-height:1.6;box-sizing:border-box}
.customer-cancel-dialog textarea:focus{border-color:#D6C29C;box-shadow:0 0 0 2px rgba(214,194,156,.10)}
.customer-cancel-dialog textarea::placeholder{color:#817b70}
.customer-cancel-note{margin:8px 0 0;color:#938b7e;font-size:10px;line-height:1.5}
.customer-cancel-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:20px}
.customer-cancel-actions button{padding:10px 15px;border-radius:8px;font:600 10px Poppins,sans-serif;cursor:pointer;transition:.2s ease}
.customer-cancel-back{border:1px solid rgba(214,194,156,.3);background:transparent;color:#d9c9aa}
.customer-cancel-confirm{border:1px solid #925047;background:#572a25;color:#fff}
.customer-cancel-confirm:hover{background:#71362f}
@keyframes mizpahCancelIn{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
@media(max-width:480px){.customer-cancel-dialog{padding:24px 19px 20px}.customer-cancel-dialog h2{font-size:19px}.customer-cancel-actions{flex-direction:column-reverse}.customer-cancel-actions button{width:100%}}

.profile-mini{

    width:36px;
    height:36px;

    border-radius:50%;

    object-fit:cover;

    border:2px solid #D6C29C;

    margin-left:8px;

}


/* =========================
   MAIN
========================= */

.wrap{

    width:100%;
    max-width:1200px;

    margin:auto;

    padding:38px 8% 60px;

}


/* =========================
   PAGE HEADER
========================= */

.page-header{

    display:flex;
    justify-content:space-between;
    align-items:flex-end;

    gap:20px;

    margin-bottom:26px;

}

.page-title h1{

    font-family:
        'Playfair Display',
        serif;

    color:#D6C29C;

    font-size:31px;

    margin-bottom:6px;

}

.page-title p{

    color:#888;

    font-size:12px;

    line-height:1.6;

}

.main-book-btn{

    display:inline-block;

    padding:11px 18px;

    background:#D6C29C;

    color:#111;

    text-decoration:none;

    border-radius:9px;

    font-size:12px;
    font-weight:600;

    white-space:nowrap;

    transition:.2s;

}

.main-book-btn:hover{

    transform:translateY(-2px);

    box-shadow:
        0 8px 20px
        rgba(214,194,156,.20);

}


/* =========================
   BOOKING CARD
========================= */

.booking-card{

    background:
        rgba(20,20,20,.88);

    border:
        1px solid
        rgba(255,255,255,.07);

    border-radius:16px;

    padding:23px;

    margin-bottom:17px;

    box-shadow:
        0 12px 30px
        rgba(0,0,0,.20);

    transition:.2s;

}

.booking-card:hover{

    border-color:
        rgba(214,194,156,.30);

    transform:translateY(-2px);

}


/* =========================
   BOOKING TOP
========================= */

.booking-top{

    display:flex;

    justify-content:space-between;
    align-items:flex-start;

    gap:20px;

}

.booking-number{

    color:#666;

    font-size:9px;

    text-transform:uppercase;

    letter-spacing:1px;

    margin-bottom:5px;

}

.service{

    color:#fff;

    font-size:18px;
    font-weight:600;

}

.duration{

    display:inline-block;

    color:#999;

    font-size:10px;

    margin-top:5px;

}


/* =========================
   STATUS
========================= */

.badge{

    display:inline-block;

    padding:6px 12px;

    border-radius:20px;

    font-size:10px;

    font-weight:600;

    white-space:nowrap;

}

.pending{

    background:
        rgba(214,194,156,.13);

    color:#D6C29C;

    border:
        1px solid
        rgba(214,194,156,.16);

}

.confirmed{

    background:
        rgba(130,180,130,.10);

    color:#a9d5a9;

    border:
        1px solid
        rgba(130,180,130,.18);

}

.completed{

    background:
        rgba(255,255,255,.08);

    color:#ddd;

    border:
        1px solid
        rgba(255,255,255,.10);

}

.cancelled{

    background:
        rgba(180,90,90,.10);

    color:#d49a9a;

    border:
        1px solid
        rgba(180,90,90,.18);

}


/* =========================
   BOOKING DETAILS
========================= */

.details-grid{

    display:grid;

    grid-template-columns:
        repeat(4,1fr);

    gap:12px;

    margin-top:20px;

}

.detail{

    background:#101010;

    border:
        1px solid
        #262626;

    border-radius:9px;

    padding:12px;

}

.detail-label{

    display:block;

    color:#666;

    font-size:9px;

    text-transform:uppercase;

    letter-spacing:.5px;

    margin-bottom:5px;

}

.detail-value{

    color:#ddd;

    font-size:11px;

    line-height:1.5;

}


/* =========================
   SECOND DETAILS
========================= */

.extra-grid{

    display:grid;

    grid-template-columns:
        repeat(3,1fr);

    gap:12px;

    margin-top:12px;

}


/* =========================
   ADDONS / NOTES
========================= */

.additional-info{

    display:grid;

    grid-template-columns:
        repeat(2,1fr);

    gap:12px;

    margin-top:12px;

}

.info-box{

    padding:12px 14px;

    background:
        rgba(214,194,156,.04);

    border:
        1px solid
        rgba(214,194,156,.10);

    border-radius:9px;

}

.info-box strong{

    display:block;

    color:#D6C29C;

    font-size:10px;

    margin-bottom:4px;

}

.info-box p{

    color:#aaa;

    font-size:10px;

    line-height:1.6;

}


/* =========================
   THERAPISTS
========================= */

.therapist-box{

    margin-top:14px;

    padding:14px;

    background:#101010;

    border:
        1px solid
        #262626;

    border-radius:9px;

}

.therapist-title{

    color:#D6C29C;

    font-size:10px;
    font-weight:600;

    margin-bottom:7px;

}

.therapist-name{

    color:#ccc;

    font-size:11px;

    line-height:1.8;

}

.waiting{

    color:#666;

    font-size:10px;

}


/* =========================
   BOTTOM ACTIONS
========================= */

.booking-bottom{

    display:flex;

    justify-content:space-between;
    align-items:center;

    gap:15px;

    margin-top:16px;

    padding-top:16px;

    border-top:
        1px solid
        #252525;

}

.status-note{

    color:#666;

    font-size:9px;

    line-height:1.6;

}

.actions{

    display:flex;

    align-items:center;

    gap:8px;

}

.btn{

    display:inline-block;

    padding:9px 14px;

    border-radius:8px;

    text-decoration:none;

    font-size:10px;

    font-weight:600;

    transition:.2s;

}

.btn-gold{

    background:#D6C29C;

    color:#111;

}

.btn-gold:hover{

    transform:translateY(-1px);

}

.btn-dark{

    background:#222;

    color:#ccc;

    border:
        1px solid
        #333;

}

.btn-dark:hover{

    border-color:#D6C29C;

    color:#D6C29C;

}


/* =========================
   EMPTY
========================= */

.empty{

    background:
        rgba(20,20,20,.88);

    border:
        1px solid
        rgba(214,194,156,.13);

    border-radius:16px;

    padding:55px 20px;

    text-align:center;

}

.empty h3{

    color:#D6C29C;

    font-size:18px;

    margin-bottom:8px;

}

.empty p{

    color:#777;

    font-size:11px;

    margin-bottom:20px;

}


/* =========================
   MOBILE
========================= */

@media(max-width:900px){

    .details-grid{

        grid-template-columns:
            repeat(2,1fr);

    }

    .extra-grid{

        grid-template-columns:
            repeat(2,1fr);

    }

}

@media(max-width:700px){

    header{

        padding:12px 4%;

    }

    .logo span{

        display:none;

    }

    nav{

        gap:1px;

    }

    nav a{

        padding:6px;

        font-size:10px;

    }

    .profile-mini{

        width:32px;
        height:32px;

        margin-left:4px;

    }

    .wrap{

        padding:
            28px 4%
            45px;

    }

    .page-header{

        align-items:flex-start;

        flex-direction:column;

    }

    .booking-top{

        flex-direction:column;

    }

    .additional-info{

        grid-template-columns:1fr;

    }

    .booking-bottom{

        flex-direction:column;

        align-items:flex-start;

    }

}

@media(max-width:500px){

    .details-grid,
    .extra-grid{

        grid-template-columns:1fr;

    }

}

</style>

</head>

<body>


<!-- =========================
     HEADER
========================= -->

<header>

    <div class="logo">

        <img
            src="../assets/images/logo.png"
            alt="Mizpah Logo"
        >

        <span>
            Mizpah Wellness Spa
        </span>

    </div>


    <nav>

        <a href="dashboard.php">
            Home
        </a>

        <a
            href="mybookings.php"
            class="active"
        >
            My Bookings
        </a>

        <a href="profile.php">
            Profile
        </a>

        <div class="notification-wrap">
            <button type="button" class="notification-toggle" aria-label="Notifications">Notifications<?php if ($unreadNotifications > 0): ?><span class="notification-count"><?= $unreadNotifications ?></span><?php endif; ?></button>
            <div class="notification-panel">
                <strong style="color:#D6C29C;font-size:12px;display:block;padding:5px 9px 9px;">Recent Notifications</strong>
                <?php if (!$customerNotifications): ?><div class="notification-item">No notifications yet.</div><?php else: foreach ($customerNotifications as $notice): ?>
                    <div class="notification-item"><strong><?= htmlspecialchars($notice['title']) ?></strong><?= htmlspecialchars($notice['message']) ?><div style="opacity:.55;font-size:10px;"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($notice['created_at']))) ?></div></div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <a href="logout.php">
            Logout
        </a>

        <img
            src="<?= htmlspecialchars($profilePic) ?>"
            class="profile-mini"
            alt="Profile"
        >

    </nav>

</header>


<!-- =========================
     MAIN
========================= -->

<div class="wrap">

    <?php if ($bookingActionMessage !== ''): ?><div class="action-message"><?= htmlspecialchars($bookingActionMessage) ?></div><?php endif; ?>


    <!-- PAGE HEADER -->

    <div class="page-header">

        <div class="page-title">

            <h1>
                My Bookings
            </h1>

            <p>
                View your appointments, assigned
                therapists and current booking status.
            </p>

        </div>


        <a
            href="booking.php"
            class="main-book-btn"
        >
            Book an Appointment
        </a>

    </div>


    <!-- =========================
         NO BOOKINGS
    ========================= -->

    <?php if(
        !$bookings ||
        mysqli_num_rows($bookings) == 0
    ): ?>


        <div class="empty">

            <h3>
                No Bookings Yet
            </h3>

            <p>
                You don't have any appointments yet.
                Book your first spa visit when you're ready.
            </p>

            <a
                href="booking.php"
                class="btn btn-gold"
            >
                Book an Appointment
            </a>

        </div>


    <?php endif; ?>


    <!-- =========================
         BOOKINGS
    ========================= -->

    <?php while(
        $b = mysqli_fetch_assoc($bookings)
    ): ?>


        <?php

        /* =========================
           STATUS
        ========================= */

        // The booking workflow writes and updates bookings.status.
        // Use it as the source of truth; booking_status is only a legacy fallback.
        $rawStatus = (isset($b['status']) && trim((string)$b['status']) !== '')
            ? (string)$b['status']
            : (string)($b['booking_status'] ?? 'Pending');

        $status = strtolower(trim($rawStatus));

        // Normalize common status spellings for consistent display.
        if ($status === 'approve') {
            $status = 'approved';
        }

        if ($status === 'confirm') {
            $status = 'confirmed';
        }

        $displayStatus = ucwords($status !== '' ? $status : 'pending');

        $class = 'pending';

        if ($status === 'approved' || $status === 'confirmed') {
            $class = 'confirmed';
            $displayStatus = 'Confirmed';
        } elseif ($status === 'completed') {
            $class = 'completed';
            $displayStatus = 'Completed';
        } elseif ($status === 'cancelled' || $status === 'canceled') {
            $class = 'cancelled';
            $displayStatus = 'Cancelled';
        } elseif ($status === 'pending') {
            $displayStatus = 'Pending';
        }


        /* =========================
           THERAPISTS
        ========================= */

        $booking_id =
            (int)$b['id'];

        $therapists =
            mysqli_query($conn, "

                SELECT t.name

                FROM booking_therapists bt

                LEFT JOIN therapists t
                    ON t.id =
                    bt.therapist_id

                WHERE
                    bt.booking_id =
                    '$booking_id'

            ");

        ?>


        <div class="booking-card">


            <!-- =========================
                 TOP
            ========================= -->

            <div class="booking-top">


                <div>

                    <div class="booking-number">

                        Booking
                        #<?= $booking_id ?>

                    </div>


                    <div class="service">

                        <?= htmlspecialchars(
                            $b['service'] ?? 'Service'
                        ) ?>

                    </div>


                    <div class="duration">

                        <?= htmlspecialchars(
                            $b['duration'] ?? ''
                        ) ?>

                    </div>

                </div>


                <div>

                    <span
                        class="badge <?= $class ?>"
                    >

                        <?= htmlspecialchars($displayStatus) ?>

                    </span>

                </div>


            </div>


            <!-- =========================
                 MAIN DETAILS
            ========================= -->

            <div class="details-grid">


                <!-- DATE -->

                <div class="detail">

                    <span class="detail-label">
                        Date
                    </span>

                    <div class="detail-value">

                        <?php

                        if(!empty(
                            $b['booking_date']
                        )){

                            echo date(
                                'M d, Y',
                                strtotime(
                                    $b['booking_date']
                                )
                            );

                        }else{

                            echo '—';

                        }

                        ?>

                    </div>

                </div>


                <!-- TIME -->

                <div class="detail">

                    <span class="detail-label">
                        Time
                    </span>

                    <div class="detail-value">

                        <?php

                        if(!empty(
                            $b['booking_time']
                        )){

                            $bookingStartForDisplay = mizpahBookingStartTimestamp(
                                $b['booking_date'] ?? '',
                                $b['booking_time']
                            );

                            echo $bookingStartForDisplay
                                ? date('g:i A', $bookingStartForDisplay)
                                : htmlspecialchars($b['booking_time']);

                        }else{

                            echo '—';

                        }

                        ?>

                    </div>

                </div>


                <!-- PAX -->

                <div class="detail">

                    <span class="detail-label">
                        Pax
                    </span>

                    <div class="detail-value">

                        <?= htmlspecialchars(
                            $b['pax'] ?? '1'
                        ) ?>

                    </div>

                </div>


                <!-- ROOM -->

                <div class="detail">

                    <span class="detail-label">
                        Room
                    </span>

                    <div class="detail-value">

                        <?= htmlspecialchars(
                            !empty($b['room_type'])
                                ? $b['room_type']
                                : 'N/A'
                        ) ?>

                    </div>

                </div>


            </div>


            <!-- =========================
                 PAYMENT DETAILS
            ========================= -->

            <div class="extra-grid">


                <!-- PAYMENT -->

                <div class="detail">

                    <span class="detail-label">
                        Payment Method
                    </span>

                    <div class="detail-value">

                        <?= htmlspecialchars(
                            $b['payment_method']
                            ?? 'Cash'
                        ) ?>

                    </div>

                </div>


                <!-- PRICE -->

                <div class="detail">

                    <span class="detail-label">
                        Total Price
                    </span>

                    <div class="detail-value">

                        ₱<?= number_format(
                            (float)(
                                $b['price'] ?? 0
                            ),
                            2
                        ) ?>

                    </div>

                </div>


                <!-- DURATION -->

                <div class="detail">

                    <span class="detail-label">
                        Duration
                    </span>

                    <div class="detail-value">

                        <?= htmlspecialchars(
                            $b['duration'] ?? '—'
                        ) ?>

                    </div>

                </div>


            </div>


            <!-- =========================
                 ADDONS / NOTES
            ========================= -->

            <?php if(
                !empty($b['addons']) ||
                !empty($b['notes'])
            ): ?>


                <div class="additional-info">


                    <?php if(
                        !empty($b['addons'])
                    ): ?>

                        <div class="info-box">

                            <strong>
                                Add-ons
                            </strong>

                            <p>

                                <?= htmlspecialchars(
                                    $b['addons']
                                ) ?>

                            </p>

                        </div>

                    <?php endif; ?>


                    <?php if(
                        !empty($b['notes'])
                    ): ?>

                        <div class="info-box">

                            <strong>
                                Notes
                            </strong>

                            <p>

                                <?= nl2br(
                                    htmlspecialchars(
                                        $b['notes']
                                    )
                                ) ?>

                            </p>

                        </div>

                    <?php endif; ?>


                </div>


            <?php endif; ?>


            <!-- =========================
                 THERAPISTS
            ========================= -->

            <div class="therapist-box">

                <div class="therapist-title">
                    Assigned Therapist(s)
                </div>


                <?php

                if(
                    $therapists &&
                    mysqli_num_rows(
                        $therapists
                    ) > 0
                ){

                    while(
                        $t =
                        mysqli_fetch_assoc(
                            $therapists
                        )
                    ){

                        if(
                            !empty($t['name'])
                        ){

                            echo
                            '<div class="therapist-name">
                                • ' .
                                htmlspecialchars(
                                    $t['name']
                                ) .
                            '</div>';

                        }

                    }

                }else{

                    echo '
                    <div class="waiting">
                        Waiting for therapist assignment
                    </div>
                    ';

                }

                ?>

            </div>


            <!-- =========================
                 BOTTOM
            ========================= -->

            <div class="booking-bottom">


                <div class="status-note">


                    <?php if(
                        $status == 'pending'
                    ): ?>

                        Your booking is waiting
                        for confirmation.


                    <?php elseif(
                        $status == 'confirmed' ||
                        $status == 'approved'
                    ): ?>

                        Your appointment has
                        been confirmed.


                    <?php elseif(
                        $status == 'completed'
                    ): ?>

                        This appointment has
                        been completed.


                    <?php elseif(
                        $status == 'cancelled'
                    ): ?>

                        This booking has
                        been cancelled.


                    <?php endif; ?>


                </div>


                <div class="actions">


                    <!-- RATE -->

                    <?php if(
                        $status == 'completed'
                    ): ?>

                        <a
                            href="rate_therapist.php?booking_id=<?= $booking_id ?>"
                            class="btn btn-gold"
                        >
                            Rate Therapist
                        </a>

                    <?php endif; ?>


                    <!-- CUSTOMER CANCEL: allowed until 1 hour before appointment; final statuses are locked -->
                    <?php
                    $canCancel = !in_array($status, ['completed','cancelled'], true);
                    $bookingStart = mizpahBookingStartTimestamp($b['booking_date'] ?? '', $b['booking_time'] ?? '');
                    $canCancel = $canCancel && $bookingStart && time() <= ($bookingStart - 3600);
                    ?>
                    <?php if ($canCancel): ?>
                        <div class="cancel-booking-form">
                            <button type="button" class="btn-cancel" onclick="openCustomerCancelDialog(<?= (int)$booking_id ?>)">Cancel Booking</button>
                        </div>
                    <?php endif; ?>

                    <!-- BOOK AGAIN -->

                    <a
                        href="booking.php"
                        class="btn btn-dark"
                    >
                        Book Again
                    </a>


                </div>


            </div>


        </div>


    <?php endwhile; ?>


</div>



<div class="customer-cancel-backdrop" id="customerCancelBackdrop" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="customerCancelTitle" onclick="if(event.target===this)closeCustomerCancelDialog()">
    <div class="customer-cancel-dialog">
        <div class="customer-cancel-icon" aria-hidden="true">!</div>
        <h2 id="customerCancelTitle">Cancel Booking</h2>
        <p class="cancel-intro">Are you sure you want to cancel this appointment? Please tell us why so our team can properly record your request.</p>
        <form method="POST" id="customerCancelForm">
            <input type="hidden" name="booking_id" id="customerCancelBookingId" value="">
            <input type="hidden" name="cancel_booking" value="1">
            <label for="customerCancellationReason">Reason for cancellation</label>
            <textarea name="cancellation_reason" id="customerCancellationReason" maxlength="1000" required placeholder="Please enter your reason..."></textarea>
            <p class="customer-cancel-note">Your reason will be visible to the Mizpah admin team. Cancellation is allowed until 1 hour before your appointment.</p>
            <div class="customer-cancel-actions">
                <button type="button" class="customer-cancel-back" onclick="closeCustomerCancelDialog()">Go Back</button>
                <button type="submit" class="customer-cancel-confirm">Confirm Cancellation</button>
            </div>
        </form>
    </div>
</div>
<script>
(function(){
    const backdrop = document.getElementById('customerCancelBackdrop');
    const bookingInput = document.getElementById('customerCancelBookingId');
    const reasonInput = document.getElementById('customerCancellationReason');
    let previousFocus = null;
    window.openCustomerCancelDialog = function(bookingId){
        if(!backdrop || !bookingInput || !reasonInput) return;
        previousFocus = document.activeElement;
        bookingInput.value = String(bookingId);
        reasonInput.value = '';
        backdrop.classList.add('is-visible');
        backdrop.setAttribute('aria-hidden','false');
        setTimeout(function(){reasonInput.focus();},80);
    };
    window.closeCustomerCancelDialog = function(){
        if(!backdrop) return;
        backdrop.classList.remove('is-visible');
        backdrop.setAttribute('aria-hidden','true');
        if(previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    };
    document.addEventListener('keydown',function(event){
        if(event.key === 'Escape' && backdrop && backdrop.classList.contains('is-visible')) window.closeCustomerCancelDialog();
    });
    if(reasonInput){
        document.getElementById('customerCancelForm').addEventListener('submit',function(event){
            if(!reasonInput.value.trim()){
                event.preventDefault();
                reasonInput.setCustomValidity('Please enter your reason for cancellation.');
                reasonInput.reportValidity();
            } else {
                reasonInput.setCustomValidity('');
            }
        });
        reasonInput.addEventListener('input',function(){reasonInput.setCustomValidity('');});
    }
})();
</script>

</body>
</html>