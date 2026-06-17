<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

$conn = new mysqli(
    'srv1740.hstgr.io',
    'u966043993_mithran',
    'Mithranebike@29',
    'u966043993_mithran'
);

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
?>