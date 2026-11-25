<?php
$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');
$db = getenv('DB_NAME');
$port = (int) getenv('DB_PORT');

$conn = mysqli_init();

mysqli_ssl_set(
    $conn,
    null,
    null,
    __DIR__ . '/certs/ca.pem',
    null,
    null
);

mysqli_real_connect(
    $conn,
    $host,
    $user,
    $pass,
    $db,
    $port,
    null,
    MYSQLI_CLIENT_SSL
);

$conn->query("SET time_zone = '+08:00'");

date_default_timezone_set('Asia/Manila');
?>
