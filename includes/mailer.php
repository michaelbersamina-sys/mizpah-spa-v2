<?php
/** Mizpah Wellness Spa Gmail SMTP helper. */

$mailConfig = [];
$configPath = __DIR__ . '/mail-config.php';

if (is_file($configPath)) {
    $loadedConfig = require $configPath;

    if (is_array($loadedConfig)) {
        $mailConfig = $loadedConfig;
    }
}

if (!function_exists('mizpahSendEmail')) {
    function mizpahSendEmail(string $to, string $subject, string $htmlBody, string $altBody = ''): bool
    {
        global $mailConfig;
        try {
            // Accept both the short keys and the smtp_* aliases used by common config templates.
            $mailConfig = array_merge([
                'host' => $mailConfig['smtp_host'] ?? '',
                'port' => $mailConfig['smtp_port'] ?? 587,
                'encryption' => $mailConfig['smtp_encryption'] ?? 'tls',
                'username' => $mailConfig['smtp_username'] ?? '',
                'password' => $mailConfig['smtp_password'] ?? '',
                'from_email' => $mailConfig['sender_email'] ?? '',
                'from_name' => $mailConfig['sender_name'] ?? 'Mizpah Wellness Spa',
                'admin_email' => $mailConfig['notification_email'] ?? ($mailConfig['sender_email'] ?? '')
            ], $mailConfig);
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (!is_file($autoload)) {
                throw new RuntimeException('Composer autoload file is missing. Run composer install.');
            }
            require_once $autoload;
            if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
                throw new RuntimeException('PHPMailer is not available.');
            }
            $required = ['host', 'port', 'username', 'password', 'from_email', 'from_name'];
            foreach ($required as $key) {
                if (!isset($mailConfig[$key]) || (string)$mailConfig[$key] === '') {
                    throw new RuntimeException('Missing SMTP configuration: ' . $key);
                }
            }
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string)$mailConfig['host'];
            $mail->SMTPAuth = true;
            $mail->Username = (string)$mailConfig['username'];
            $mail->Password = (string)$mailConfig['password'];
            $mail->Port = (int)$mailConfig['port'];
            $encryption = strtolower((string)($mailConfig['encryption'] ?? 'tls'));
            $mail->SMTPSecure = $encryption === 'ssl'
                ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom((string)$mailConfig['from_email'], (string)$mailConfig['from_name']);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $altBody !== '' ? $altBody : trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody)));
            return $mail->send();
        } catch (Throwable $e) {
            error_log('Mizpah SMTP send failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('mizpahSendBookingEmails')) {
    function mizpahSendBookingEmails(array $booking): array
    {
        global $mailConfig;
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $date = !empty($booking['booking_date']) ? date('F j, Y', strtotime($booking['booking_date'])) : '';
        $time = !empty($booking['booking_time']) ? date('g:i A', strtotime($booking['booking_time'])) : '';
        $rows = '';
        $plainRows = '';
        foreach (($booking['guests'] ?? []) as $guest) {
            $addons = array_map(static fn($a) => (string)($a['name'] ?? ''), $guest['addons'] ?? []);
            $addonText = $addons ? '<br><small>Add-ons: ' . $e(implode(', ', $addons)) . '</small>' : '';
            $rows .= '<tr><td style="padding:9px;border-bottom:1px solid #ddd">Guest ' . $e($guest['guest_number'] ?? '') . ' — ' . $e($guest['name'] ?? '') . '</td><td style="padding:9px;border-bottom:1px solid #ddd">' . $e($guest['service'] ?? '') . $addonText . '</td><td style="padding:9px;border-bottom:1px solid #ddd">' . $e($guest['duration'] ?? '') . '</td><td style="padding:9px;border-bottom:1px solid #ddd">₱' . number_format((float)($guest['price'] ?? 0), 2) . '</td></tr>';
            $plainRows .= 'Guest ' . ($guest['guest_number'] ?? '') . ': ' . ($guest['name'] ?? '') . ' | ' . ($guest['service'] ?? '') . ' | ' . ($guest['duration'] ?? '') . "\n";
        }
        $details = '<table style="width:100%;border-collapse:collapse;text-align:left"><thead><tr><th style="padding:9px">Guest</th><th style="padding:9px">Service</th><th style="padding:9px">Duration</th><th style="padding:9px">Price</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $common = '<div style="font-family:Arial,sans-serif;color:#292929;max-width:680px;margin:auto"><h2 style="color:#80643a">Mizpah Wellness Spa</h2><h3>Booking Confirmed</h3><p>Booking Reference: <strong>#' . $e($booking['id'] ?? '') . '</strong></p><p>Date: ' . $e($date) . '<br>Time: ' . $e($time) . '<br>Guests: ' . $e($booking['pax'] ?? '') . '<br>Contact: ' . $e($booking['phone'] ?? '') . '</p>' . $details . '<p><strong>Total: ₱' . number_format((float)($booking['price'] ?? 0), 2) . '</strong></p><p>Payment Method: ' . $e($booking['payment_method'] ?? '') . '</p>' . (!empty($booking['notes']) ? '<p>Notes: ' . nl2br($e($booking['notes'])) . '</p>' : '') . '<p>Thank you for choosing Mizpah Wellness Spa.</p></div>';
        $plain = "Mizpah Wellness Spa - Booking Confirmed\nReference: #" . ($booking['id'] ?? '') . "\nDate: $date\nTime: $time\nGuests: " . ($booking['pax'] ?? '') . "\n" . $plainRows . "Total: PHP " . number_format((float)($booking['price'] ?? 0), 2);
        $guestSent = mizpahSendEmail((string)$booking['email'], 'Mizpah Wellness Spa - Booking Confirmed (#' . $booking['id'] . ')', $common, $plain);
        $admin = (string)($mailConfig['admin_email'] ?? $mailConfig['from_email'] ?? '');
        $adminSent = $admin !== '' ? mizpahSendEmail($admin, 'New Guest Booking - Mizpah (#' . $booking['id'] . ')', '<h2>New confirmed guest booking</h2>' . $common, "New confirmed guest booking\n" . $plain) : false;
        if (!$guestSent || !$adminSent) {
            error_log('Mizpah booking email status: guest=' . ($guestSent ? 'sent' : 'failed') . ', admin=' . ($adminSent ? 'sent' : 'failed') . ', booking_id=' . ($booking['id'] ?? 'unknown'));
        }
        return ['guest' => $guestSent, 'admin' => $adminSent];
    }
}


if (!function_exists('mizpahSendBookingStatusEmail')) {
    function mizpahSendBookingStatusEmail(array $booking, string $status, string $reason = ''): bool
    {
        $status = trim($status);
        if (!in_array($status, ['Cancelled', 'Completed'], true)) return false;
        $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $id = (int)($booking['id'] ?? 0);
        $date = !empty($booking['booking_date']) ? date('F j, Y', strtotime($booking['booking_date'])) : 'Not specified';
        $time = !empty($booking['booking_time']) ? date('g:i A', strtotime($booking['booking_time'])) : 'Not specified';
        $heading = $status === 'Cancelled' ? 'Your Booking Has Been Cancelled' : 'Your Service Has Been Completed';
        $extra = ($status === 'Cancelled' && trim($reason) !== '') ? '<p>Reason: ' . nl2br($e($reason)) . '</p>' : '';
        $body = '<div style="font-family:Arial,sans-serif;color:#292929;max-width:620px;margin:auto"><h2 style="color:#80643a">Mizpah Wellness Spa</h2><h3>' . $heading . '</h3><p>Hello ' . $e($booking['customer_name'] ?? 'Guest') . ',</p><p>Your booking <strong>#' . $id . '</strong> for ' . $e($date) . ' at ' . $e($time) . ' is now <strong>' . $e($status) . '</strong>.</p>' . $extra . '<p>Thank you for choosing Mizpah Wellness Spa.</p></div>';
        $plain = "Mizpah Wellness Spa\n" . $heading . "\nBooking #$id\nDate: $date\nTime: $time\nStatus: $status" . ($reason !== '' && $status === 'Cancelled' ? "\nReason: $reason" : '') . "\nThank you for choosing Mizpah Wellness Spa.";
        return mizpahSendEmail((string)($booking['email'] ?? ''), 'Mizpah Wellness Spa - Booking ' . $status . ' (#' . $id . ')', $body, $plain);
    }
}
