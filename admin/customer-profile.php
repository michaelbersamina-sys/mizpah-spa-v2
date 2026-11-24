```php
<?php
session_start();
include '../includes/db.php';

/* ================= SECURITY CHECK ================= */
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit;
}

$role = strtolower($_SESSION['role']);

if ($role !== 'admin') {
    header("Location: ../login.php");
    exit;
}

/* ================= CUSTOMER ID ================= */
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: users.php");
    exit;
}

$customer_id = intval($_GET['id']);

/* ================= GET CUSTOMER + WELLNESS INFO ================= */
$query = mysqli_query($conn, "
    SELECT
        u.id,
        u.name,
        u.email,
        u.role,
        u.created_at,

        w.allergies,
        w.skin_sensitivity,
        w.medical_conditions,
        w.body_pain_injuries,
        w.pregnancy_status,
        w.preferred_pressure,
        w.special_requests,
        w.emergency_contact_name,
        w.emergency_contact_number,

        (
            SELECT b.phone
            FROM bookings b
            WHERE b.user_id = u.id
            AND b.phone IS NOT NULL
            AND b.phone != ''
            ORDER BY b.id DESC
            LIMIT 1
        ) AS phone

    FROM users u

    LEFT JOIN wellness_profiles w
        ON u.id = w.user_id

    WHERE u.id = '$customer_id'
    AND u.role = 'customer'

    LIMIT 1
");

$customer = mysqli_fetch_assoc($query);

if (!$customer) {
    header("Location: users.php");
    exit;
}


/* ================= BOOKING HISTORY ================= */
$bookingHistory = [];
$historyQuery = mysqli_query($conn, "
    SELECT
        id,
        customer_name,
        phone,
        service,
        duration,
        price,
        booking_date,
        booking_time,
        pax,
        status,
        payment_method,
        room_type,
        created_at
    FROM bookings
    WHERE user_id = $customer_id
    ORDER BY booking_date DESC, booking_time DESC, id DESC
");

if ($historyQuery) {
    while ($booking = mysqli_fetch_assoc($historyQuery)) {
        $booking['guests'] = [];

        $bookingId = (int)$booking['id'];
        $guestQuery = mysqli_query($conn, "
            SELECT
                bg.id AS booking_guest_id,
                bg.guest_number,
                bg.guest_name,
                bg.service,
                bg.duration,
                bg.price,
                bg.addons
            FROM booking_guests bg
            WHERE bg.booking_id = $bookingId
            ORDER BY bg.guest_number ASC
        ");

        if ($guestQuery) {
            while ($guest = mysqli_fetch_assoc($guestQuery)) {
                $guest['addon_items'] = [];
                $bookingGuestId = (int)$guest['booking_guest_id'];

                $addonQuery = mysqli_query($conn, "
                    SELECT addon_name, quantity, price
                    FROM booking_guest_addons
                    WHERE booking_guest_id = $bookingGuestId
                    ORDER BY id ASC
                ");

                if ($addonQuery) {
                    while ($addon = mysqli_fetch_assoc($addonQuery)) {
                        $guest['addon_items'][] = $addon;
                    }
                }

                $booking['guests'][] = $guest;
            }
        }

        $bookingHistory[] = $booking;
    }
}

function displayValue($value) {
    return !empty($value)
        ? htmlspecialchars($value)
        : 'Not provided';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Customer Profile | Mizpah Wellness Spa</title>

<link rel="stylesheet" href="../assets/css/admin.css">

<style>
:root {
    --gold: #D6C29C;
    --gold-dark: #A88B58;
    --bg: #0b0b0b;
    --panel: #151515;
    --panel-inner: #101010;
    --border: #302b22;
    --text: #f5f0e7;
    --muted: #99938a;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: Poppins, Arial, sans-serif;
}

.main {
    margin-left: 250px;
    padding: 30px;
    min-height: 100vh;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
    margin-bottom: 28px;
}

.eyebrow {
    color: var(--gold-dark);
    text-transform: uppercase;
    letter-spacing: 2px;
    font-size: 11px;
    font-weight: 700;
    margin: 0 0 8px;
}

.page-header h2 {
    color: var(--gold);
    font-size: 28px;
    margin: 0 0 8px;
}

.page-description {
    color: var(--muted);
    font-size: 13px;
    margin: 0;
    line-height: 1.6;
}

.back-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 11px 17px;
    background: var(--gold);
    color: #15120c;
    text-decoration: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    transition: .2s ease;
    white-space: nowrap;
}

.back-btn:hover {
    background: #e7d8b9;
    transform: translateY(-1px);
}

.profile-banner {
    display: flex;
    align-items: center;
    gap: 18px;
    padding: 24px;
    margin-bottom: 22px;
    background: linear-gradient(135deg, #1c1913, #121212 70%);
    border: 1px solid var(--border);
    border-radius: 14px;
    position: relative;
    overflow: hidden;
}

.profile-banner::after {
    content: "";
    position: absolute;
    right: -45px;
    top: -75px;
    width: 180px;
    height: 180px;
    border: 1px solid rgba(214,194,156,.10);
    border-radius: 50%;
    box-shadow: 0 0 0 22px rgba(214,194,156,.025),
                0 0 0 44px rgba(214,194,156,.02);
    pointer-events: none;
}

.avatar {
    width: 66px;
    height: 66px;
    flex-shrink: 0;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #2a2419;
    border: 1px solid var(--gold-dark);
    color: var(--gold);
    font-size: 25px;
    font-weight: 700;
}

.profile-summary {
    min-width: 0;
    position: relative;
    z-index: 1;
}

.profile-summary h3 {
    color: var(--text);
    font-size: 20px;
    margin: 0 0 6px;
    overflow-wrap: anywhere;
}

.profile-summary p {
    color: var(--muted);
    font-size: 13px;
    margin: 0;
    overflow-wrap: anywhere;
}

.customer-badge {
    display: inline-block;
    margin-top: 10px;
    padding: 5px 10px;
    border-radius: 20px;
    background: rgba(214,194,156,.10);
    border: 1px solid rgba(214,194,156,.25);
    color: var(--gold);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .5px;
}

.section {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 23px;
    margin-bottom: 22px;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 1px solid #302d27;
}

.section-icon {
    width: 38px;
    height: 38px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    color: var(--gold);
    background: #282218;
    border: 1px solid #403522;
    font-size: 17px;
}

.section-header h3 {
    color: var(--gold);
    font-size: 16px;
    margin: 0 0 4px;
}

.section-header p {
    color: var(--muted);
    font-size: 12px;
    margin: 0;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
}

.info-box {
    min-width: 0;
    background: var(--panel-inner);
    border: 1px solid #2a2926;
    border-radius: 9px;
    padding: 16px;
    transition: border-color .2s ease;
}

.info-box:hover {
    border-color: #55462e;
}

.info-box label {
    display: block;
    color: var(--muted);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .8px;
    font-weight: 600;
    margin-bottom: 9px;
}

.info-box p {
    color: var(--text);
    font-size: 13px;
    line-height: 1.7;
    margin: 0;
    overflow-wrap: anywhere;
    white-space: normal;
}

.info-box.full-width {
    grid-column: 1 / -1;
}

.not-provided {
    color: #77716a;
    font-style: italic;
}

.profile-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 6px;
}

.footer-note {
    color: #77716a;
    font-size: 11px;
    margin: 0;
}


.history-count {
    margin-left: auto;
    color: var(--gold);
    background: rgba(214,194,156,.09);
    border: 1px solid rgba(214,194,156,.2);
    border-radius: 20px;
    padding: 7px 11px;
    font-size: 11px;
    white-space: nowrap;
}
.booking-history-list {
    display: grid;
    gap: 14px;
}
.history-card {
    background: var(--panel-inner);
    border: 1px solid #302b22;
    border-radius: 10px;
    padding: 17px;
}
.history-top, .guest-heading {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.booking-reference {
    color: var(--gold-dark);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
}
.history-top h4 {
    color: var(--text);
    font-size: 14px;
    margin: 6px 0 0;
}
.status-pill {
    display: inline-flex;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    text-transform: capitalize;
    background: rgba(214,194,156,.1);
    color: var(--gold);
}
.status-completed { background: rgba(93,160,112,.13); color: #9bd5a8; }
.status-confirmed { background: rgba(214,194,156,.13); color: var(--gold); }
.status-cancelled { background: rgba(190,91,91,.13); color: #e6a0a0; }
.status-pending { background: rgba(191,155,89,.13); color: #d8bd82; }
.history-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 17px;
    color: var(--muted);
    font-size: 11px;
    margin: 12px 0;
}
.history-guests {
    display: grid;
    gap: 9px;
}
.history-guest {
    padding: 12px 13px;
    border-radius: 8px;
    background: #171613;
    border: 1px solid rgba(214,194,156,.1);
}
.guest-heading strong {
    color: var(--text);
    font-size: 12px;
}
.guest-heading .guest-price {
    color: var(--gold);
    white-space: nowrap;
}
.history-guest p {
    color: #c4bcaf;
    font-size: 12px;
    margin: 7px 0 0;
}
.history-guest p span {
    color: var(--gold-dark);
    padding: 0 4px;
}
.guest-addons {
    color: var(--muted);
    font-size: 11px;
    margin-top: 7px;
    line-height: 1.5;
}
.history-empty {
    text-align: center;
    padding: 30px 15px;
    border: 1px dashed #403a2e;
    border-radius: 9px;
}
.history-empty strong {
    color: var(--text);
    font-size: 13px;
}
.history-empty p {
    color: var(--muted);
    font-size: 11px;
    margin: 7px 0 0;
}
@media (max-width: 1000px) {
    .main {
        padding: 24px;
    }
}

@media (max-width: 800px) {
    .main {
        margin-left: 0;
        padding: 20px 16px;
    }

    .page-header h2 {
        font-size: 24px;
    }

    .section {
        padding: 18px;
    }
}

@media (max-width: 520px) {
    .info-grid {
        grid-template-columns: 1fr;
    }

    .info-box.full-width {
        grid-column: auto;
    }

    .profile-banner {
        padding: 18px;
        gap: 13px;
    }

    .avatar {
        width: 54px;
        height: 54px;
        font-size: 21px;
    }

    .profile-summary h3 {
        font-size: 17px;
    }

    .page-header {
        align-items: flex-start;
    }

    .back-btn {
        width: 100%;
    }
}
</style>
</head>

<body>

<?php include __DIR__ . '/includes/sidebar.php'; ?>

<div class="main">

    <div class="page-header">
        <div>
            <p class="eyebrow">Mizpah Wellness Spa · Administration</p>
            <h2>Customer Profile</h2>
            <p class="page-description">
                View customer information, saved wellness profile, and booking history.
            </p>
        </div>

        <a href="users.php" class="back-btn">
            &larr; Back to Users
        </a>
    </div>

    <!-- CUSTOMER SUMMARY -->
    <div class="profile-banner">
        <div class="avatar">
            <?= strtoupper(substr(trim($customer['name']), 0, 1)) ?>
        </div>

        <div class="profile-summary">
            <h3><?= htmlspecialchars($customer['name']) ?></h3>
            <p><?= htmlspecialchars($customer['email']) ?></p>
            <span class="customer-badge">CUSTOMER ACCOUNT</span>
        </div>
    </div>

    <!-- CUSTOMER INFORMATION -->
    <div class="section">

        <div class="section-header">
            <div class="section-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="8" r="3.5"></circle>
                    <path d="M5 20c.6-3.4 3.1-5.2 7-5.2s6.4 1.8 7 5.2"></path>
                </svg>
            </div>
            <div>
                <h3>Customer Information</h3>
                <p>Basic account and contact details</p>
            </div>
        </div>

        <div class="info-grid">

            <div class="info-box">
                <label>Full Name</label>
                <p><?= displayValue($customer['name']) ?></p>
            </div>

            <div class="info-box">
                <label>Email Address</label>
                <p><?= displayValue($customer['email']) ?></p>
            </div>

            <div class="info-box">
                <label>Phone Number</label>
                <p><?= displayValue($customer['phone']) ?></p>
            </div>

            <div class="info-box">
                <label>Account Created</label>
                <p><?= displayValue($customer['created_at']) ?></p>
            </div>

        </div>
    </div>

    <!-- WELLNESS INFORMATION -->
    <div class="section">

        <div class="section-header">
            <div class="section-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z"></path>
                    <path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15z"></path>
                </svg>
            </div>
            <div>
                <h3>Wellness Information</h3>
                <p>Customer health considerations and massage preferences</p>
            </div>
        </div>

        <div class="info-grid">

            <div class="info-box">
                <label>Allergies</label>
                <p><?= displayValue($customer['allergies']) ?></p>
            </div>

            <div class="info-box">
                <label>Skin Sensitivity</label>
                <p><?= displayValue($customer['skin_sensitivity']) ?></p>
            </div>

            <div class="info-box">
                <label>Medical Conditions</label>
                <p><?= displayValue($customer['medical_conditions']) ?></p>
            </div>

            <div class="info-box">
                <label>Body Pain / Injuries</label>
                <p><?= displayValue($customer['body_pain_injuries']) ?></p>
            </div>

            <div class="info-box">
                <label>Pregnancy Status</label>
                <p><?= displayValue($customer['pregnancy_status']) ?></p>
            </div>

            <div class="info-box">
                <label>Preferred Massage Pressure</label>
                <p><?= displayValue($customer['preferred_pressure']) ?></p>
            </div>

            <div class="info-box full-width">
                <label>Special Requests</label>
                <p>
                    <?php if (!empty($customer['special_requests'])): ?>
                        <?= nl2br(htmlspecialchars($customer['special_requests'])) ?>
                    <?php else: ?>
                        <span class="not-provided">Not provided</span>
                    <?php endif; ?>
                </p>
            </div>

            <div class="info-box">
                <label>Emergency Contact Name</label>
                <p><?= displayValue($customer['emergency_contact_name']) ?></p>
            </div>

            <div class="info-box">
                <label>Emergency Contact Number</label>
                <p><?= displayValue($customer['emergency_contact_number']) ?></p>
            </div>

        </div>
    </div>


    <!-- BOOKING HISTORY -->
    <div class="section">
        <div class="section-header">
            <div class="section-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3.5" y="5" width="17" height="16" rx="2"></rect>
                    <path d="M7.5 3v4M16.5 3v4M3.5 9.5h17"></path>
                    <path d="M8 13h.01M12 13h.01M16 13h.01M8 17h.01M12 17h.01"></path>
                </svg>
            </div>
            <div>
                <h3>Booking History</h3>
                <p>All bookings linked to this customer account</p>
            </div>
            <span class="history-count">
                <?= count($bookingHistory) ?> booking<?= count($bookingHistory) === 1 ? '' : 's' ?>
            </span>
        </div>

        <?php if (!empty($bookingHistory)): ?>
            <div class="booking-history-list">
                <?php foreach ($bookingHistory as $booking): ?>
                    <?php
                    $status = strtolower(trim($booking['status'] ?? ''));
                    $statusClass = preg_replace('/[^a-z0-9_-]/', '', $status);

                    $bookingDate = !empty($booking['booking_date'])
                        ? date('M d, Y', strtotime($booking['booking_date']))
                        : 'Date not available';

                    $bookingTime = !empty($booking['booking_time'])
                        ? date('g:i A', strtotime($booking['booking_time']))
                        : 'Time not available';      
                         
                    ?>
                    <article class="history-card">
                        <div class="history-top">
                            <div>
                                <span class="booking-reference">BOOKING #<?= (int)$booking['id'] ?></span>
                                <h4><?= htmlspecialchars($bookingDate) ?> · <?= htmlspecialchars($bookingTime) ?></h4>
                            </div>
                            <span class="status-pill status-<?= htmlspecialchars($statusClass) ?>">
                                <?= htmlspecialchars($booking['status'] ?? 'Unknown') ?>
                            </span>
                        </div>

                        <div class="history-meta">
                            <span><?= (int)($booking['pax'] ?? 1) ?> guest<?= (int)($booking['pax'] ?? 1) === 1 ? '' : 's' ?></span>
                            <span><?= htmlspecialchars($booking['room_type'] ?: 'Room not specified') ?></span>
                            <span><?= htmlspecialchars($booking['payment_method'] ?: 'Payment not specified') ?></span>
                        </div>

                        <?php if (!empty($booking['guests'])): ?>
                            <div class="history-guests">
                                <?php foreach ($booking['guests'] as $guest): ?>
                                    <div class="history-guest">
                                        <div class="guest-heading">
                                            <strong>Guest <?= (int)$guest['guest_number'] ?> · <?= htmlspecialchars($guest['guest_name'] ?: 'Unnamed guest') ?></strong>
                                            <strong class="guest-price">₱<?= number_format((float)$guest['price'], 2) ?></strong>
                                        </div>
                                        <p>
                                            <?= htmlspecialchars($guest['service'] ?: ($booking['service'] ?: 'Service not specified')) ?>
                                            <span>·</span>
                                            <?= htmlspecialchars($guest['duration'] ?: ($booking['duration'] ?: 'Duration not specified')) ?>
                                        </p>

                                        <?php if (!empty($guest['addon_items'])): ?>
                                            <div class="guest-addons">
                                                Add-ons:
                                                <?php
                                                    $addonLabels = [];
                                                    foreach ($guest['addon_items'] as $addon) {
                                                        $addonLabels[] = htmlspecialchars($addon['addon_name']) .
                                                            ((int)$addon['quantity'] > 1 ? ' × ' . (int)$addon['quantity'] : '');
                                                    }
                                                    echo implode(', ', $addonLabels);
                                                ?>
                                            </div>
                                        <?php elseif (!empty($guest['addons'])): ?>
                                            <div class="guest-addons">Add-ons: <?= htmlspecialchars($guest['addons']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="history-guest">
                                <div class="guest-heading">
                                    <strong><?= htmlspecialchars($booking['service'] ?: 'Service not specified') ?></strong>
                                    <strong class="guest-price">₱<?= number_format((float)$booking['price'], 2) ?></strong>
                                </div>
                                <p><?= htmlspecialchars($booking['duration'] ?: 'Duration not specified') ?></p>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="history-empty">
                <strong>No booking history yet</strong>
                <p>Bookings made using this customer account will appear here.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="profile-footer">
        <p class="footer-note">
            Customer information is displayed for authorized administration use.
        </p>

        <a href="users.php" class="back-btn">
            &larr; Back to Users
        </a>
    </div>

</div>

</body>
</html>
```