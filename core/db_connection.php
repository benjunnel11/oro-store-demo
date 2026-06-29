<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/db_config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { die("Connection failed: " . $conn->connect_error); }
$conn->set_charset("utf8mb4");

// Device ID + auto-increment offset (prevents ID conflicts between devices)
@include_once __DIR__ . '/../sync/config.php';
$_device = defined('LOCAL_DEVICE_ID') ? LOCAL_DEVICE_ID : 'DEVICE_A';
$_offsets = ['DEVICE_A'=>1,'DEVICE_B'=>2,'DEVICE_C'=>3,'DEVICE_D'=>4,'DEVICE_E'=>5,'DEVICE_F'=>6,'DEVICE_G'=>7,'DEVICE_H'=>8,'DEVICE_I'=>9,'DEVICE_J'=>10];
$conn->query("SET @@SESSION.auto_increment_increment=10,@@SESSION.auto_increment_offset=" . ($_offsets[$_device] ?? 1));
?>
