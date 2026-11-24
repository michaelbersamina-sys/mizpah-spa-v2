<?php

// Allow PHP to retain session data for the 30-day "Stay logged in" option.
ini_set('session.gc_maxlifetime', (string)(30 * 24 * 60 * 60));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
date_default_timezone_set('Asia/Manila');

require_once 'includes/db.php';

// If the user already has an authenticated session, do not show the login form
// again when they open login.php in another tab or revisit the page.
if (isset($_SESSION['user_id']) && !isset($_GET['token'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header("Location: admin/dashboard.php");
        exit();
    }

    $activeUserId = (int)$_SESSION['user_id'];
    $activeProfile = $conn->prepare(
        "SELECT id FROM wellness_profiles WHERE user_id = ? LIMIT 1"
    );

    if ($activeProfile) {
        $activeProfile->bind_param("i", $activeUserId);
        $activeProfile->execute();
        $activeProfileResult = $activeProfile->get_result();
        $hasActiveProfile = $activeProfileResult && $activeProfileResult->num_rows > 0;
        $activeProfile->close();

        header($hasActiveProfile
            ? "Location: customer/dashboard.php"
            : "Location: customer/wellness-profile.php");
        exit();
    }
}

$error = "";
$success = "";
$showRegisterForm = isset($_POST['register']);

/* =========================
   FORGOT / RESET PASSWORD
========================= */
$showResetForm = isset($_GET['token']);
$resetToken = $showResetForm ? trim((string)$_GET['token']) : '';
$resetValid = false;

if ($showResetForm && preg_match('/^[a-f0-9]{64}$/', $resetToken)) {
    $tokenHash = hash('sha256', $resetToken);
    $checkToken = $conn->prepare("SELECT id FROM password_resets WHERE token_hash = ? AND expires_at > NOW() AND used_at IS NULL LIMIT 1");
    if ($checkToken) {
        $checkToken->bind_param('s', $tokenHash);
        $checkToken->execute();
        $resetValid = $checkToken->get_result()->num_rows === 1;
        $checkToken->close();
    }
}

if (isset($_POST['request_reset'])) {
    $resetEmail = trim($_POST['reset_email'] ?? '');
    $success = "If an account exists for that email, a password reset link will be sent shortly.";

    if (filter_var($resetEmail, FILTER_VALIDATE_EMAIL)) {
        $findUser = $conn->prepare("SELECT id, name, email FROM users WHERE email = ? LIMIT 1");
        if ($findUser) {
            $findUser->bind_param('s', $resetEmail);
            $findUser->execute();
            $userResult = $findUser->get_result();
            $resetUser = $userResult->fetch_assoc();
            $findUser->close();

            if ($resetUser) {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                $deleteOld = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
                if ($deleteOld) {
                    $deleteOld->bind_param('i', $resetUser['id']);
                    $deleteOld->execute();
                    $deleteOld->close();
                }

                $saveToken = $conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
                if ($saveToken) {
                    $saveToken->bind_param('iss', $resetUser['id'], $tokenHash, $expiresAt);
                    if ($saveToken->execute()) {
                        require_once __DIR__ . '/includes/mailer.php';
                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                        $resetUrl = $baseUrl . '/login.php?token=' . urlencode($rawToken);
                        $safeName = htmlspecialchars($resetUser['name'], ENT_QUOTES, 'UTF-8');
                        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
                        $html = '<div style="font-family:Arial,sans-serif;color:#292929;max-width:600px;margin:auto"><h2 style="color:#80643a">Mizpah Wellness Spa</h2><p>Hello ' . $safeName . ',</p><p>We received a request to reset your account password.</p><p><a href="' . $safeUrl . '" style="display:inline-block;background:#D6C29C;color:#111;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Reset Password</a></p><p>This link expires in 1 hour and can only be used once.</p><p>If you did not request this, you can safely ignore this email.</p></div>';
                        $plain = "Hello " . $resetUser['name'] . ",\n\nReset your Mizpah Wellness Spa password using this link (expires in 1 hour):\n" . $resetUrl . "\n\nIf you did not request this, ignore this email.";
                        if (!mizpahSendEmail($resetUser['email'], 'Mizpah Wellness Spa - Password Reset', $html, $plain)) {
                            error_log('Mizpah password reset email failed for user_id=' . $resetUser['id']);
                        }
                    }
                    $saveToken->close();
                }
            }
        }
    } else {
        $error = 'Please enter a valid email address.';
        $success = '';
    }
}

if (isset($_POST['reset_password'])) {
    $postedToken = trim((string)($_POST['token'] ?? ''));
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!preg_match('/^[a-f0-9]{64}$/', $postedToken)) {
        $error = 'Invalid or expired reset link. Please request a new one.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
        $showResetForm = true;
        $resetToken = $postedToken;
    } elseif (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword) || !preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
        $error = 'Password must be at least 8 characters and include uppercase, lowercase, number, and special character.';
        $showResetForm = true;
        $resetToken = $postedToken;
    } else {
        $tokenHash = hash('sha256', $postedToken);
        $lookup = $conn->prepare("SELECT id, user_id FROM password_resets WHERE token_hash = ? AND expires_at > NOW() AND used_at IS NULL LIMIT 1");
        if ($lookup) {
            $lookup->bind_param('s', $tokenHash);
            $lookup->execute();
            $tokenRow = $lookup->get_result()->fetch_assoc();
            $lookup->close();

            if ($tokenRow) {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateUser = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                if ($updateUser) {
                    $updateUser->bind_param('si', $newHash, $tokenRow['user_id']);
                    $updated = $updateUser->execute();
                    $updateUser->close();
                    if ($updated) {
                        $markUsed = $conn->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
                        if ($markUsed) {
                            $markUsed->bind_param('i', $tokenRow['id']);
                            $markUsed->execute();
                            $markUsed->close();
                        }
                        $success = 'Your password has been reset successfully. You can now log in.';
                        $showResetForm = false;
                    } else {
                        $error = 'Unable to update your password. Please try again.';
                        $showResetForm = true;
                        $resetToken = $postedToken;
                    }
                }
            } else {
                $error = 'This reset link is invalid, expired, or already used. Please request a new one.';
            }
        }
    }
}

/* =========================
   REGISTER CUSTOMER + EMAIL VERIFICATION
========================= */

if (isset($_POST['register'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $raw_password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter your full name and a valid email address.';
    } elseif ($raw_password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (
        strlen($raw_password) < 8 ||
        !preg_match('/[A-Z]/', $raw_password) ||
        !preg_match('/[a-z]/', $raw_password) ||
        !preg_match('/[0-9]/', $raw_password) ||
        !preg_match('/[^a-zA-Z0-9]/', $raw_password)
    ) {
        $error = 'Password must be at least 8 characters and include uppercase, lowercase, number, and special character.';
    } else {
        $check = $conn->prepare(
            'SELECT id, name, email, email_verified FROM users WHERE email = ? LIMIT 1'
        );

        if (!$check) {
            $error = 'Unable to process your registration right now. Please try again.';
        } else {
            $check->bind_param('s', $email);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $check->close();

            $userId = 0;
            $userName = $name;
            $userEmail = $email;
            $canSendVerification = false;

            if ($existing) {
                if ((int)$existing['email_verified'] === 1) {
                    $error = 'This email is already registered and verified. Please log in.';
                } else {
                    // Existing account, but email is not verified.
                    // Resend a fresh verification link.
                    $userId = (int)$existing['id'];
                    $userName = $existing['name'];
                    $userEmail = $existing['email'];
                    $canSendVerification = true;
                }
            } else {
                // Create a new customer account.
                $passwordHash = password_hash($raw_password, PASSWORD_DEFAULT);

                $insert = $conn->prepare(
                    "INSERT INTO users (name, email, password, role, email_verified)
                     VALUES (?, ?, ?, 'customer', 0)"
                );

                if (!$insert) {
                    $error = 'Unable to create your account right now. Please try again.';
                } else {
                    $insert->bind_param('sss', $name, $email, $passwordHash);

                    if ($insert->execute()) {
                        $userId = (int)$insert->insert_id;
                        $canSendVerification = true;
                    } else {
                        $error = 'Unable to create your account. Please try again.';
                    }

                    $insert->close();
                }
            }

            if ($canSendVerification && $userId > 0) {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                // Remove previous verification tokens for this account.
                $deleteOld = $conn->prepare(
                    'DELETE FROM email_verification_tokens WHERE user_id = ?'
                );

                $oldTokensDeleted = false;

                if ($deleteOld) {
                    $deleteOld->bind_param('i', $userId);
                    $oldTokensDeleted = $deleteOld->execute();
                    $deleteOld->close();
                }

                if (!$oldTokensDeleted) {
                    error_log('Mizpah old verification token cleanup failed for user_id=' . $userId);
                    $error = 'Unable to prepare a new verification link. Please try again.';
                } else {
                    $saveToken = $conn->prepare(
                        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
                         VALUES (?, ?, ?)'
                    );

                    $tokenSaved = false;

                    if ($saveToken) {
                        $saveToken->bind_param('iss', $userId, $tokenHash, $expiresAt);
                        $tokenSaved = $saveToken->execute();
                        $saveToken->close();
                    }

                    if ($tokenSaved) {
                        require_once __DIR__ . '/includes/mailer.php';

                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                            ? 'https'
                            : 'http';

                        $basePath = rtrim(
                            str_replace('\\\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')),
                            '/'
                        );

                        $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $basePath;
                        $verifyUrl = $baseUrl . '/verify-email.php?token=' . urlencode($rawToken);

                        $safeName = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
                        $safeUrl = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');

                        $html = '<div style="font-family:Arial,sans-serif;color:#292929;max-width:600px;margin:auto">
                            <h2 style="color:#80643a">Mizpah Wellness Spa</h2>
                            <p>Hello ' . $safeName . ',</p>
                            <p>Please verify your email address to activate your Mizpah Wellness Spa account.</p>
                            <p><a href="' . $safeUrl . '" style="display:inline-block;background:#D6C29C;color:#111;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Verify Email Address</a></p>
                            <p>This verification link expires in 1 hour and can only be used once.</p>
                            <p>If you did not create this account, you may ignore this email.</p>
                        </div>';

                        $plain = "Hello $userName,\n\nPlease verify your Mizpah Wellness Spa email address using this link (expires in 1 hour):\n$verifyUrl\n\nIf you did not create this account, ignore this email.";

                        if (mizpahSendEmail(
                            $userEmail,
                            'Mizpah Wellness Spa - Verify Your Email',
                            $html,
                            $plain
                        )) {
                            $success = 'A new verification link has been sent to your email address. Please check your inbox and spam folder. The link expires in 1 hour.';
                        } else {
                            error_log('Mizpah verification email failed for user_id=' . $userId);
                            $error = 'Your account exists but we could not send the verification email. Please contact the spa administrator for assistance.';
                        }
                    } else {
                        error_log('Mizpah verification token insert failed for user_id=' . $userId . ': ' . $conn->error);
                        $error = 'Unable to prepare your verification link. Please try again.';
                    }
                }
            }
        }
    }
}

/* =========================
   LOGIN
========================= */

if(isset($_POST['login'])){

    $email = mysqli_real_escape_string($conn,$_POST['email']);
    $password = $_POST['password'];

    $query = mysqli_query($conn,"
        SELECT * FROM users
        WHERE email='$email'
        LIMIT 1
    ");

    if(mysqli_num_rows($query) == 1){

        $user = mysqli_fetch_assoc($query);

        $login_success = false;

        // bcrypt check (new accounts)
        if(password_verify($password, $user['password'])){

            $login_success = true;

        }

        // MD5 check (old admin account)
        else if(md5($password) === $user['password']){

            $login_success = true;

        }

        if($login_success){

            // New customer accounts must verify their email before login.
            // Existing accounts are marked verified by the migration.
            if (isset($user['email_verified']) && (int)$user['email_verified'] !== 1) {
                $error = 'Please verify your email address first. Check your inbox for the verification link.';
            } else {

            // "Stay logged in" keeps this authenticated session for 30 days.
            // The password itself is never stored in a cookie.
            $sessionCookie = session_get_cookie_params();

            if (isset($_POST['remember_me'])) {
                setcookie(session_name(), session_id(), [
                    'expires' => time() + (30 * 24 * 60 * 60),
                    'path' => $sessionCookie['path'] ?: '/',
                    'domain' => $sessionCookie['domain'],
                    'secure' => $sessionCookie['secure'],
                    'httponly' => true,
                    'samesite' => $sessionCookie['samesite'] ?? 'Lax'
                ]);

                setcookie('mizpah_remember_email', $user['email'], [
                    'expires' => time() + (30 * 24 * 60 * 60),
                    'path' => '/',
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            } else {
                setcookie(session_name(), session_id(), [
                    'expires' => 0,
                    'path' => $sessionCookie['path'] ?: '/',
                    'domain' => $sessionCookie['domain'],
                    'secure' => $sessionCookie['secure'],
                    'httponly' => true,
                    'samesite' => $sessionCookie['samesite'] ?? 'Lax'
                ]);

                setcookie('mizpah_remember_email', '', [
                    'expires' => time() - 3600,
                    'path' => '/',
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }

            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['name']    = $user['name'];
            $_SESSION['role']    = $user['role'];

            if ($user['role'] === 'admin') {
                header("Location: admin/dashboard.php");
                exit();
            }

            // First login: require Wellness Profile. Later logins: Dashboard.
            $wellnessCheck = $conn->prepare(
                "SELECT id FROM wellness_profiles WHERE user_id = ? LIMIT 1"
            );

            if (!$wellnessCheck) {
                error_log("Mizpah wellness profile check prepare failed: " . $conn->error);
                $error = "Unable to complete login right now. Please try again.";
            } else {
                $customerId = (int)$user['id'];
                $wellnessCheck->bind_param("i", $customerId);
                $wellnessCheck->execute();
                $wellnessResult = $wellnessCheck->get_result();
                $hasWellnessProfile = $wellnessResult && $wellnessResult->num_rows > 0;
                $wellnessCheck->close();

                header($hasWellnessProfile
                    ? "Location: customer/dashboard.php"
                    : "Location: customer/wellness-profile.php");
                exit();
            }

            }

        }else{

            $error = "Invalid email or password.";

        }

    }else{

        $error = "Invalid email or password.";

    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Mizpah Login</title>

<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family:Poppins,sans-serif;
    background:#0b0b0b;
}

.login-wrapper{
    position:fixed;
    inset:0;
    display:flex;
    justify-content:center;
    align-items:center;
    background:
    linear-gradient(135deg, rgba(0,0,0,.78), rgba(20,20,20,.92)),
    url('assets/images/spa-bg.jpg') center/cover no-repeat;
    padding:20px;
}

.login-card{
    width:100%;
    max-width:390px;
    background:#161616;
    padding:40px 30px;
    border-radius:16px;
    border:1px solid rgba(214,194,156,.18);
    text-align:center;
    box-shadow:0 25px 60px rgba(0,0,0,.65);
}

.login-logo{
    width:72px;
    margin-bottom:15px;
}

h2{
    font-family:'Playfair Display',serif;
    color:#D6C29C;
    font-size:28px;
}

.sub{
    color:#aaa;
    font-size:13px;
    margin:10px 0 20px;
}

.error{
    background:rgba(255,0,0,.10);
    color:#ff7c7c;
    padding:11px;
    border-radius:8px;
    margin-bottom:12px;
    font-size:13px;
}

.success{
    background:rgba(0,255,0,.10);
    color:#7CFF7C;
    padding:11px;
    border-radius:8px;
    margin-bottom:12px;
    font-size:13px;
}

input{
    width:100%;
    padding:12px;
    margin-bottom:10px;
    border-radius:10px;
    border:1px solid rgba(214,194,156,.18);
    background:#0b0b0b;
    color:#fff;
    outline:none;
}

input:focus{
    border-color:#D6C29C;
}

.password-field{
    position:relative;
    width:100%;
    margin-bottom:10px;
}

.password-field input{
    width:100%;
    margin-bottom:0;
    padding-right:48px;
}

.password-toggle{
    position:absolute;
    top:50%;
    right:10px;
    transform:translateY(-50%);
    width:auto;
    padding:5px 7px;
    margin:0;
    border:0;
    background:transparent;
    color:#D6C29C;
    font-size:12px;
    font-weight:500;
    cursor:pointer;
}

.password-toggle:hover{
    transform:translateY(-50%);
    color:#fff;
}

.remember-row{
    display:flex;
    align-items:center;
    gap:8px;
    margin:2px 0 14px;
    color:#bbb;
    font-size:12px;
    text-align:left;
}

.remember-row input{
    width:15px;
    height:15px;
    margin:0;
    accent-color:#D6C29C;
}

button{
    width:100%;
    padding:12px;
    border:none;
    border-radius:10px;
    background:#D6C29C;
    color:#111;
    font-weight:700;
    cursor:pointer;
    transition:.2s;
}

button:hover{
    transform:translateY(-2px);
}

.toggle{
    margin-top:14px;
    font-size:12px;
    color:#bbb;
    cursor:pointer;
}

.toggle:hover{
    color:#D6C29C;
}

.footer-text{
    margin-top:16px;
    font-size:11px;
    color:#666;
}

</style>

</head>

<body>

<div class="login-wrapper">

<div class="login-card">

    <img src="assets/images/logo.png" class="login-logo">

    <h2>Mizpah Wellness Spa</h2>

    <div class="sub">Luxury Healing • Calm Experience</div>

    <?php if($error!=""){ ?>

        <div class="error"><?= htmlspecialchars($error) ?></div>

    <?php } ?>

    <?php if($success!=""){ ?>

        <div class="success"><?= htmlspecialchars($success) ?></div>

    <?php } ?>

    <!-- LOGIN FORM -->

    <form method="POST" id="loginForm" autocomplete="on" style="<?= $showRegisterForm ? 'display:none;' : '' ?>">

        <input
            type="email"
            name="email"
            placeholder="Email Address"
            value="<?= htmlspecialchars($_COOKIE['mizpah_remember_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            autocomplete="username"
            required
        >

        <div class="password-field">
            <input
                type="password"
                name="password"
                placeholder="Password"
                autocomplete="current-password"
                required
            >
        </div>

        <label class="remember-row">
            <input type="checkbox" name="remember_me" value="1" <?= !empty($_COOKIE['mizpah_remember_email']) ? 'checked' : '' ?>>
            Remember me
        </label>

        <button type="submit" name="login">
            Login
        </button>

    </form>

    <div class="toggle" id="forgotLink" onclick="showForgotForm()" style="<?= $showRegisterForm ? 'display:none;' : '' ?>">Forgot Password?</div>

    <!-- FORGOT PASSWORD FORM -->
    <form method="POST" id="forgotForm" style="display:none;">
        <p class="sub">Enter your registered email and we’ll send you a password reset link.</p>
        <input type="email" name="reset_email" placeholder="Email Address" required>
        <button type="submit" name="request_reset">Send Reset Link</button>
        <div class="toggle" onclick="showLoginForm()">Back to Login</div>
    </form>

    <!-- RESET PASSWORD FORM -->
    <?php if($showResetForm){ ?>
    <form method="POST" id="resetForm">
        <?php if(!$resetValid && !isset($_POST['reset_password'])){ ?>
            <div class="error">This reset link is invalid or expired. Please request a new one.</div>
        <?php } else { ?>
            <p class="sub">Create a new password for your account.</p>
            <input type="hidden" name="token" value="<?= htmlspecialchars($resetToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="password" name="new_password" placeholder="New Password" autocomplete="new-password" minlength="8" required>
            <input type="password" name="confirm_password" placeholder="Confirm New Password" autocomplete="new-password" minlength="8" required>
            <button type="submit" name="reset_password">Update Password</button>
        <?php } ?>
    </form>
    <?php } ?>

    <!-- REGISTER FORM -->

    <form method="POST" id="registerForm" style="<?= $showRegisterForm ? 'display:block;' : 'display:none;' ?>" autocomplete="on">

        <input 
            type="text" 
            name="name"
            placeholder="Full Name"
            autocomplete="name"
            value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            required
        >

        <input 
            type="email" 
            name="email"
            placeholder="Email Address"
            autocomplete="email"
            value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
            required
        >

        <input 
            type="password" 
            name="password"
            placeholder="Password"
            autocomplete="new-password"
            minlength="8"
            pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[^a-zA-Z0-9]).{8,}"
            title="Password must be at least 8 characters and include uppercase, lowercase, number, and special character."
            required
        >

        <input 
            type="password" 
            name="confirm_password"
            placeholder="Confirm Password"
            autocomplete="new-password"
            minlength="8"
            required
        >

        <button type="submit" name="register">
            Create Account
        </button>

    </form>

    <div class="toggle" onclick="toggleForm()" id="toggleText">
        <?= $showRegisterForm ? 'Already have account?' : 'Create account' ?>
    </div>

    <div class="footer-text">
        © 2026 Mizpah Wellness Spa
    </div>

</div>

</div>

<script>

// Add Show/Hide controls to all password fields (login, register, and reset).
document.querySelectorAll('input[type="password"]').forEach(function(input) {
    const wrapper = document.createElement('div');
    wrapper.className = 'password-field';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'password-toggle';
    toggle.textContent = 'Show';
    toggle.setAttribute('aria-label', 'Show password');

    toggle.addEventListener('click', function() {
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        toggle.textContent = isHidden ? 'Hide' : 'Show';
        toggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });

    wrapper.appendChild(toggle);
});

function toggleForm(){
    const login = document.getElementById("loginForm");
    const register = document.getElementById("registerForm");
    const forgot = document.getElementById("forgotForm");
    const forgotLink = document.getElementById("forgotLink");
    const text = document.getElementById("toggleText");
    login.style.display = "none";
    forgot.style.display = "none";
    forgotLink.style.display = "none";
    if(register.style.display === "none"){
        register.style.display = "block";
        text.innerHTML = "Already have account?";
    } else {
        register.style.display = "none";
        login.style.display = "block";
        forgotLink.style.display = "block";
        text.innerHTML = "Create account";
    }
}

function showForgotForm(){
    document.getElementById("loginForm").style.display = "none";
    document.getElementById("registerForm").style.display = "none";
    document.getElementById("forgotForm").style.display = "block";
    document.getElementById("forgotLink").style.display = "none";
    document.getElementById("toggleText").style.display = "none";
}

function showLoginForm(){
    document.getElementById("forgotForm").style.display = "none";
    document.getElementById("loginForm").style.display = "block";
    document.getElementById("forgotLink").style.display = "block";
    document.getElementById("toggleText").style.display = "block";
}

<?php if($showResetForm){ ?>
document.getElementById("loginForm").style.display = "none";
document.getElementById("registerForm").style.display = "none";
document.getElementById("forgotLink").style.display = "none";
document.getElementById("toggleText").style.display = "none";
<?php } ?>
</script>

</body>

</html>