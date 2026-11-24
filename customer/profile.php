
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

/* GET USER INFORMATION */
$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$user) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

/* UPDATE PROFILE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update'])) {

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($name === '' || $phone === '') {
        $message = "Please complete all required fields.";
        $messageType = "error";

    } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
        $message = "Phone number must be exactly 11 digits and start with 09.";
        $messageType = "error";

    } else {

        $newPhoto = $user['profile_pic'] ?? null;
        $uploadValid = true;

        /* PROFILE PICTURE UPLOAD */
        if (
            isset($_FILES['profile_pic']) &&
            $_FILES['profile_pic']['error'] !== UPLOAD_ERR_NO_FILE
        ) {

            if ($_FILES['profile_pic']['error'] !== UPLOAD_ERR_OK) {

                $message = "Profile picture upload failed.";
                $messageType = "error";
                $uploadValid = false;

            } elseif ($_FILES['profile_pic']['size'] > 5 * 1024 * 1024) {

                $message = "Profile picture must not exceed 5MB.";
                $messageType = "error";
                $uploadValid = false;

            } else {

                $tmp = $_FILES['profile_pic']['tmp_name'];
                $imageInfo = @getimagesize($tmp);

                $allowedTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];

                if (
                    $imageInfo === false ||
                    !isset($allowedTypes[$imageInfo['mime']])
                ) {

                    $message = "Only JPG, PNG, and WEBP images are allowed.";
                    $messageType = "error";
                    $uploadValid = false;

                } else {

                    $folder = __DIR__ . "/../uploads/profile/";

                    if (!is_dir($folder)) {
                        mkdir($folder, 0777, true);
                    }

                    $filename = uniqid('profile_', true) . '.' .
                        $allowedTypes[$imageInfo['mime']];

                    if (move_uploaded_file($tmp, $folder . $filename)) {
                        $newPhoto = $filename;
                    } else {
                        $message = "Unable to upload profile picture.";
                        $messageType = "error";
                        $uploadValid = false;
                    }
                }
            }
        }

        /* SAVE PROFILE INFORMATION */
        if ($uploadValid) {

            if (!empty($newPhoto)) {

                $update = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET name = ?, phone = ?, profile_pic = ?
                     WHERE id = ?"
                );

                mysqli_stmt_bind_param(
                    $update,
                    "sssi",
                    $name,
                    $phone,
                    $newPhoto,
                    $id
                );

            } else {

                $update = mysqli_prepare(
                    $conn,
                    "UPDATE users
                     SET name = ?, phone = ?
                     WHERE id = ?"
                );

                mysqli_stmt_bind_param(
                    $update,
                    "ssi",
                    $name,
                    $phone,
                    $id
                );
            }

            if (mysqli_stmt_execute($update)) {

                $_SESSION['name'] = $name;

                $message = "Profile updated successfully!";
                $messageType = "success";

            } else {

                $message = "Unable to update profile. Please try again.";
                $messageType = "error";
            }

            mysqli_stmt_close($update);
        }
    }

    /* REFRESH USER DATA */
    $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
}

/* PROFILE IMAGE */
$img = "../assets/images/default-profile.png";

if (!empty($user['profile_pic'])) {
    $img = "../uploads/profile/" . rawurlencode($user['profile_pic']);
}
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile</title>

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
    margin-bottom: 18px;
    font-size: 22px;
}

.photo-wrap {
    text-align: center;
    margin-bottom: 18px;
}

.photo {
    width: 110px;
    height: 110px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #D6C29C;
    box-shadow: 0 5px 20px rgba(0,0,0,.5);
}

.group {
    margin-bottom: 14px;
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

input[readonly] {
    pointer-events: none;
    user-select: none;
    -webkit-user-select: none;
    cursor: default;
    opacity: .65;
    caret-color: transparent;
}

.file {
    padding: 10px;
    background: #0f0f0f;
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

<div class="header">My Profile</div>

<div class="wrapper">
<div class="card">

<h2>Account Settings</h2>

<?php if ($message !== ''): ?>
<div class="message <?= htmlspecialchars($messageType) ?>">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<div class="photo-wrap">
    <img
        src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>"
        class="photo"
        alt="Profile Picture"
    >
</div>

<form method="POST" enctype="multipart/form-data">

    <div class="group">
        <label>Change Profile Picture</label>
        <input
            type="file"
            name="profile_pic"
            class="file"
            accept="image/jpeg,image/png,image/webp"
        >
    </div>

    <div class="group">
        <label>Name</label>
        <input
            type="text"
            name="name"
            value="<?= htmlspecialchars($user['name'] ?? '') ?>"
            required
        >
    </div>

    <div class="group">
        <label>Verified Email Address</label>
        <input
            type="text"
            value="<?= htmlspecialchars($user['email'] ?? '') ?>"
            readonly
            tabindex="-1"
            onfocus="this.blur()"
            autocomplete="off"
        >
    </div>

    <div class="group">
        <label>Phone Number</label>
        <input
            type="tel"
            name="phone"
            value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
            placeholder="09XXXXXXXXX"
            pattern="09[0-9]{9}"
            maxlength="11"
            minlength="11"
            inputmode="numeric"
            oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)"
            required
        >
    </div>

    <button type="submit" name="update">Save Changes</button>

</form>

<a href="change-password.php" class="back">Change Password →</a>
<a href="dashboard.php" class="back">← Back to Dashboard</a>

</div>
</div>

</body>
</html>
