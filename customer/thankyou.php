
<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!function_exists('thankyouEscape')) {
    function thankyouEscape($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$data = null;

if ($id > 0 && isset($conn) && $conn) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM bookings WHERE id = ? LIMIT 1"
    );

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);
        $data = mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);
    }
}

$guestDetails = [];
if (
    $data &&
    (!isset($_SESSION['user_id']) || !isset($data['user_id']) || (int)$data['user_id'] !== (int)$_SESSION['user_id'])
) {
    $data = null;
}

if ($id > 0 && $data) {
    $guestStmt = mysqli_prepare($conn, "SELECT guest_number, guest_name, service, duration, price, addons FROM booking_guests WHERE booking_id = ? ORDER BY guest_number ASC");
    if ($guestStmt) {
        mysqli_stmt_bind_param($guestStmt, 'i', $id);
        mysqli_stmt_execute($guestStmt);
        $guestResult = mysqli_stmt_get_result($guestStmt);
        while ($guestRow = mysqli_fetch_assoc($guestResult)) $guestDetails[] = $guestRow;
        mysqli_stmt_close($guestStmt);
    }
}

// Use each guest's saved price so group bookings show the combined amount.
$totalBookingPrice = (float)($data['price'] ?? 0);
if (!empty($guestDetails)) {
    $guestPriceTotal = 0;
    foreach ($guestDetails as $guestDetail) {
        $guestPriceTotal += (float)($guestDetail['price'] ?? 0);
    }
    if ($guestPriceTotal > 0) {
        $totalBookingPrice = $guestPriceTotal;
    }
}

$bookingType = 'Individual Booking';
if ($data) {
    $guestCount = max(1, (int)($data['pax'] ?? 1));
    if (($data['room_type'] ?? '') === 'Couple Room' && $guestCount === 2) {
        $bookingType = 'Couple Booking';
    } elseif ($guestCount > 1) {
        $bookingType = 'Group Booking';
    }
}

$formattedDate = 'Not specified';
$formattedTime = 'Not specified';

if (!empty($data['booking_date'])) {
    $timestamp = strtotime($data['booking_date']);

    if ($timestamp !== false) {
        $formattedDate = date('F j, Y', $timestamp);
    }
}

if (!empty($data['booking_time'])) {
    $timestamp = strtotime($data['booking_time']);

    if ($timestamp !== false) {
        $formattedTime = date('g:i A', $timestamp);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Booking Confirmation | Mizpah Wellness Spa</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg: #000000;
            --card: #121212;
            --card-soft: #101010;
            --gold: #D6C29C;
            --bronze: #8C7D63;
            --text: #FFFFFF;
            --muted: #888888;
            --border: rgba(255, 255, 255, 0.07);
            --gold-border: rgba(214, 194, 156, 0.25);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            padding: 24px 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                linear-gradient(
                    rgba(0, 0, 0, 0.88),
                    rgba(0, 0, 0, 0.96)
                ),
                url('../assets/images/hero.jpg') center / cover fixed no-repeat;
            color: var(--text);
            font-family: 'Poppins', sans-serif;
        }

        .box {
            width: 100%;
            max-width: 470px;
            padding: 25px 26px;
            background: rgba(18, 18, 18, 0.97);
            border: 1px solid var(--border);
            border-radius: 15px;
            box-shadow: 0 16px 45px rgba(0, 0, 0, 0.4);
        }

        .confirmation {
            text-align: center;
            margin-bottom: 23px;
        }

        .check {
            width: 42px;
            height: 42px;
            margin: 0 auto 13px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(214, 194, 156, 0.45);
            border-radius: 50%;
            color: var(--gold);
            background: rgba(214, 194, 156, 0.07);
            font-size: 20px;
        }

        .eyebrow {
            margin-bottom: 5px;
            color: var(--bronze);
            font-size: 9px;
            font-weight: 600;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        h1 {
            color: var(--gold);
            font-family: 'Playfair Display', serif;
            font-size: 25px;
            font-weight: 500;
            line-height: 1.4;
        }

        .sub {
            margin-top: 8px;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.8;
        }

        .reference {
            display: inline-block;
            margin-top: 14px;
            padding: 7px 11px;
            border: 1px solid var(--gold-border);
            border-radius: 7px;
            color: var(--gold);
            background: #0D0D0D;
            font-size: 11px;
        }

        .section-title {
            margin-bottom: 12px;
            color: var(--gold);
            font-size: 12px;
            font-weight: 600;
        }

        .service {
            margin-bottom: 17px;
            padding: 14px 15px;
            border: 1px solid var(--gold-border);
            border-radius: 10px;
            background: var(--card-soft);
        }

        .service-label {
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 10px;
        }

        .service-name {
            color: var(--gold);
            font-size: 14px;
            font-weight: 500;
            overflow-wrap: anywhere;
        }

        .service-duration {
            margin-top: 5px;
            color: var(--muted);
            font-size: 11px;
        }

        .row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            font-size: 11px;
        }

        .label {
            flex-shrink: 0;
            color: var(--muted);
        }

        .value {
            color: #EEEEEE;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .price-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 17px;
            padding: 14px 15px;
            border: 1px solid var(--gold-border);
            border-radius: 10px;
            background: rgba(214, 194, 156, 0.045);
        }

        .price-label {
            color: #DDDDDD;
            font-size: 11px;
        }

        .price-note {
            max-width: 220px;
            margin-top: 4px;
            color: var(--muted);
            font-size: 9px;
            line-height: 1.6;
        }

        .price {
            color: var(--gold);
            font-size: 19px;
            font-weight: 600;
            white-space: nowrap;
        }

        .notes {
            margin-top: 15px;
            padding: 12px;
            border-left: 2px solid var(--bronze);
            background: var(--card-soft);
        }

        .notes .label {
            display: block;
            margin-bottom: 6px;
        }

        .notes .value {
            font-size: 11px;
            line-height: 1.8;
            text-align: left;
        }

        .screenshot-note {
            margin-top: 14px;
            padding: 11px 12px;
            border: 1px solid var(--gold-border);
            border-radius: 8px;
            background: rgba(214, 194, 156, 0.045);
            color: var(--muted);
            font-size: 10px;
            line-height: 1.7;
            text-align: center;
        }

        .screenshot-note strong {
            color: var(--gold);
            font-weight: 600;
        }

        .btn {
            display: block;
            width: 100%;
            margin-top: 21px;
            padding: 12px 16px;
            border: 1px solid var(--gold);
            border-radius: 8px;
            background: var(--gold);
            color: #111111;
            text-align: center;
            text-decoration: none;
            font-family: 'Poppins', sans-serif;
            font-size: 11px;
            font-weight: 600;
            transition: 0.2s ease;
        }

        .btn:hover {
            background: #C7B187;
            border-color: #C7B187;
        }

        .empty {
            padding: 17px 0 5px;
            color: var(--muted);
            text-align: center;
            font-size: 11px;
            line-height: 1.8;
        }

        @media (max-width: 420px) {
            body {
                padding: 18px 12px;
            }

            .box {
                padding: 22px 18px;
            }

            h1 {
                font-size: 23px;
            }

            .price {
                font-size: 17px;
            }
        }
    </style>
</head>

<body>

    <main class="box">

        <?php if ($data): ?>

            <header class="confirmation">
                <div class="check" aria-hidden="true">&#10003;</div>

                <p class="eyebrow">Mizpah Wellness Spa</p>
                <h1>Booking Confirmed!</h1>

                <p class="sub">
                    Thank you for choosing Mizpah Wellness Spa.
                    Your booking details are listed below.
                </p>

                <div class="reference">
                    Booking Reference:
                    <strong>#<?= thankyouEscape($data['id']) ?></strong>
                </div>
            </header>

            <h2 class="section-title">Your Booking Details</h2>

            <section class="service">
                <p class="service-label">Booking Type</p>

                <h2 class="service-name">
                    <?= thankyouEscape($bookingType) ?>
                </h2>

                <p class="service-duration">
                    Room: <?= thankyouEscape($data['room_type'] ?? 'Not specified') ?>
                </p>
            </section>

            <?php if (!empty($guestDetails)): ?>
                <h2 class="section-title" style="margin-top:18px;">Individual Guest Services</h2>
                <?php foreach ($guestDetails as $guestDetail): ?>
                    <section class="service">
                        <p class="service-label">Guest <?= thankyouEscape($guestDetail['guest_number']) ?> — <?= thankyouEscape($guestDetail['guest_name']) ?></p>
                        <h2 class="service-name"><?= thankyouEscape($guestDetail['service']) ?></h2>
                        <p class="service-duration">Duration: <?= thankyouEscape($guestDetail['duration']) ?></p>
                        <p class="service-duration">Price: ₱<?= number_format((float)($guestDetail['price'] ?? 0), 2) ?></p>
                        <?php if (!empty($guestDetail['addons'])): ?><p class="service-duration">Add-ons: <?= thankyouEscape($guestDetail['addons']) ?></p><?php endif; ?>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="row">
                <span class="label">Name</span>
                <span class="value">
                    <?= thankyouEscape($data['customer_name']) ?>
                </span>
            </div>

            <div class="row">
                <span class="label">Date</span>
                <span class="value">
                    <?= thankyouEscape($formattedDate) ?>
                </span>
            </div>

            <div class="row">
                <span class="label">Time</span>
                <span class="value">
                    <?= thankyouEscape($formattedTime) ?>
                </span>
            </div>

            <div class="row">
                <span class="label">Phone</span>
                <span class="value">
                    <?= thankyouEscape($data['phone']) ?>
                </span>
            </div>

            <?php if (!empty($data['email'])): ?>
                <div class="row">
                    <span class="label">Email</span>
                    <span class="value"><?= thankyouEscape($data['email']) ?></span>
                </div>
            <?php endif; ?>

            <div class="row">
                <span class="label">Guests</span>
                <span class="value">
                    <?= thankyouEscape($data['pax']) ?>
                </span>
            </div>

            <div class="row">
                <span class="label">Payment Method</span>
                <span class="value">
                    <?= thankyouEscape($data['payment_method'] ?? 'Cash') ?>
                </span>
            </div>

            <div class="price-row">
                <div>
                    <p class="price-label">Total Booking Price</p>
                    <p class="price-note">
                        Booking confirmation only, not a payment receipt.
                    </p>
                </div>

                <div class="price">
                    ₱<?= number_format($totalBookingPrice, 2) ?>
                </div>
            </div>

            <div class="screenshot-note">
                <strong>Important:</strong> Please take a screenshot of this booking confirmation
                and keep it for your reference. You may present it to our staff when you arrive.
            </div>

            <?php if (!empty($data['notes'])): ?>
                <div class="notes">
                    <span class="label">Additional Notes</span>
                    <p class="value">
                        <?= nl2br(thankyouEscape($data['notes'])) ?>
                    </p>
                </div>
            <?php endif; ?>

            <a href="dashboard.php" class="btn">Back to Home</a>

        <?php else: ?>

            <header class="confirmation">
                <div class="check" aria-hidden="true">!</div>

                <p class="eyebrow">Mizpah Wellness Spa</p>
                <h1>Booking Not Found</h1>

                <p class="sub">
                    We couldn't find a booking associated with this reference.
                    Please return to the homepage and check your booking details.
                </p>
            </header>

            <a href="dashboard.php" class="btn">Back to Home</a>

        <?php endif; ?>

    </main>

</body>
</html>
