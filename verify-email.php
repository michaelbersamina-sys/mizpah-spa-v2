<?php
session_start();
require_once __DIR__ . '/includes/db.php';

$message = 'This verification link is invalid, expired, or has already been used.';
$verified = false;
$token = trim((string)($_GET['token'] ?? ''));

if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT id, user_id FROM email_verification_tokens WHERE token_hash = ? AND expires_at > NOW() AND used_at IS NULL LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $conn->begin_transaction();
            try {
                $updateUser = $conn->prepare('UPDATE users SET email_verified = 1 WHERE id = ?');
                $updateUser->bind_param('i', $row['user_id']);
                if (!$updateUser->execute()) {
                    throw new RuntimeException('Could not activate account.');
                }
                $updateUser->close();

                $markUsed = $conn->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
                $markUsed->bind_param('i', $row['id']);
                if (!$markUsed->execute() || $markUsed->affected_rows !== 1) {
                    throw new RuntimeException('Could not consume verification token.');
                }
                $markUsed->close();
                $conn->commit();
                $verified = true;
                $message = 'Your email has been verified successfully. You can now log in to your Mizpah Wellness Spa account.';
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('Mizpah email verification failed: ' . $e->getMessage());
                $message = 'We could not verify your email right now. Please try again or contact the spa administrator.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Verification | Mizpah Wellness Spa</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}body{min-height:100vh;background:#0b0b0b;color:#f5f0e8;font-family:Poppins,sans-serif;display:flex;align-items:center;justify-content:center;padding:20px}.card{width:100%;max-width:480px;background:#161616;border:1px solid rgba(214,194,156,.22);border-radius:16px;padding:38px 30px;text-align:center;box-shadow:0 25px 60px rgba(0,0,0,.55)}h1{font:600 27px 'Playfair Display',serif;color:#d6c29c;margin-bottom:14px}p{font-size:14px;line-height:1.8;color:#c9c1b5;margin-bottom:24px}.mark{font-size:38px;margin-bottom:14px;color:<?= $verified ? '#a8c58a' : '#d6c29c' ?>}a{display:inline-block;text-decoration:none;background:#d6c29c;color:#111;padding:12px 22px;border-radius:8px;font-weight:600;font-size:13px}a:hover{background:#c1a878}
</style>
</head>
<body><main class="card"><div class="mark"><?= $verified ? '&#10003;' : '&#9432;' ?></div><h1><?= $verified ? 'Email Verified' : 'Verification Notice' ?></h1><p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p><a href="login.php">Go to Login</a></main></body>
</html>
