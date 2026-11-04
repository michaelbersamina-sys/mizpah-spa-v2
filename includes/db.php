<?php

$host = getenv("DB_HOST") ?: "127.0.0.1";
$user = getenv("DB_USER") ?: "root";
$pass = getenv("DB_PASS") ?: "";
$db   = getenv("DB_NAME") ?: "mizpah_spa";
$port = getenv("DB_PORT") ?: "3307";

$conn = mysqli_init();

if (getenv("DB_HOST")) {
    mysqli_ssl_set($conn, NULL, NULL, __DIR__ . "/certs/ca.pem", NULL, NULL);
}

$flags = getenv("DB_HOST") ? MYSQLI_CLIENT_SSL : 0;

if (!mysqli_real_connect(
    $conn,
    $host,
    $user,
    $pass,
    $db,
    (int)$port,
    NULL,
    $flags
)) {
    die("MySQL connection failed: " . mysqli_connect_error());
}
