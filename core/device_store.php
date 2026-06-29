<?php
@include_once __DIR__ . '/../sync/config.php';

function getDeviceStoreId($conn) {
    $device_id = defined('LOCAL_DEVICE_ID') ? LOCAL_DEVICE_ID : null;
    if (!$device_id) return null;

    $stmt = $conn->prepare("SELECT id FROM stores WHERE device_id = ? AND status = 'active' LIMIT 1");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? intval($row['id']) : null;
}
