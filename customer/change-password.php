
<?php
session_start();
include '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$id = (int) $_SESSION['user_id'];
$message = "";
$messageType = "";

/* GET CURRENT USER */
$stmt = mysqli_prepare($conn, "SELECT password FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$user) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

/* CHANGE PASSWORD */
if (isset($_POST['change_password'])) {

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (
        $currentPassword === '' ||
        $newPassword === '' ||
        $confirmPassword === ''
    ) {

        $message = "Please complete all password fields.";
        $messageType = "error";

    } elseif (!password_verify($currentPassword, $user['password'])) {

        $message = "Your current password is incorrect.";
        $messageType = "error";

    } elseif (strlen($newPassword) < 8) {

        $message = "New password must be at least 8 characters.";
        $messageType = "error";

    } elseif ($newPassword !== $confirmPassword) {

        $message = "New password and confirmation do not match.";
        $messageType = "error";

    } elseif ($currentPassword === $newPassword) {

        $message = "Your new password must be different from your current password.";
        $messageType = "error";

    } else {

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

        $update = mysqli_prepare(
            $conn,
            "UPDATE users SET password = ? WHERE id = ?"
        );

        mysqli_stmt_bind_param($update, "si", $hashedPassword, $id);

        if (mysqli_stmt_execute($update)) {
            $message = "Password changed successfully!";
            $messageType = "success";
        } else {
            $message = "Unable to change password. Please try again.";
            $messageType = "error";
        }

        mysqli_stmt_close($update);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Change Password</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: Poppins, sans-serif;
}

body {
    background:
    linear-gradient(rgba(0,0,0,.75),rgba(0,0,0,.85)),
    url('../assets/images/hero.jpg') center/cover fixed no-repeat;
    color: #fff;
    min-height: 100vh;
}

.header {
    text-align: center;
    padding: 25px;
    font-size: 22px;
    font-weight: 600;
    color: #D6C29C;
    background: rgba(0,0,0,.6);
    backdrop-filter: blur(8px);
    border-bottom: 1px solid rgba(255,255,255,.08);
}

.wrapper {
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 40px 15px;
}

.card {
    width: 100%;
    max-width: 450px;
    background: rgba(20,20,20,.75);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 18px;
    padding: 28px;
    box-shadow: 0 10px 30px rgba(0,0,0,.4);
}

.card h2 {
    text-align: center;
    color: #D6C29C;
    margin-bottom: 22px;
    font-size: 22px;
}

.group {
    margin-bottom: 16px;
}

label {
    display: block;
    font-size: 12px;
    color: #aaa;
    margin-bottom: 6px;
}

input {
    width: 100%;
    padding: 12px;
    border-radius: 10px;
    border: 1px solid #333;
    background: #111;
    color: #fff;
    outline: none;
    transition: .2s;
}

input:focus {
    border-color: #D6C29C;
    box-shadow: 0 0 8px rgba(214,194,156,.3);
}

button {
    width: 100%;
    padding: 13px;
    margin-top: 10px;
    background: #D6C29C;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    cursor: pointer;
    color: #111;
    transition: .2s;
}

button:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(214,194,156,.2);
}

.message {
    padding: 12px;
    border-radius: 8px;
    font-size: 12px;
    text-align: center;
    margin-bottom: 18px;
}

.message.success {
    background: rgba(46,125,50,.18);
    border: 1px solid #4caf50;
    color: #a5d6a7;
}

.message.error {
    background: rgba(198,40,40,.18);
    border: 1px solid #e57373;
    color: #ef9a9a;
}

.back {
    display: block;
    text-align: center;
    margin-top: 15px;
    font-size: 13px;
    color: #aaa;
    text-decoration: none;
}

.back:hover {
    color: #D6C29C;
}
</style>
</head>

<body>

<div class="header">Change Password</div>

<div class="wrapper">
<div class="card">

<h2>Change Password</h2>

<?php if ($message !== ''): ?>
<div class="message <?= htmlspecialchars($messageType) ?>">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<form method="POST">

    <div class="group">
        <label>Current Password</label>
        <input type="password" name="current_password"
               placeholder="Enter current password"
               autocomplete="current-password" required>
    </div>

    <div class="group">
        <label>New Password</label>
        <input type="password" name="new_password"
               placeholder="Enter new password"
               minlength="8"
               autocomplete="new-password" required>
    </div>

    <div class="group">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password"
               placeholder="Confirm new password"
               minlength="8"
               autocomplete="new-password" required>
    </div>

    <button type="submit" name="change_password">
        Change Password
    </button>

</form>

<a href="profile.php" class="back">← Back to Account Settings</a>
<a href="dashboard.php" class="back">← Back to Dashboard</a>

</div>
</div>

</body>
</html>
