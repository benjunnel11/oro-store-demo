<?php
// Runs in background via auto_sync.php — detects ZeroTier IP and matches to store
require_once __DIR__ . '/../core/db_config.php';
@include_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { echo json_encode(['ok' => false]); exit; }

$device = defined('LOCAL_DEVICE_ID') ? LOCAL_DEVICE_ID : 'DEVICE_A';
$my_ip = '';

$zt = @shell_exec('ipconfig 2>nul');
if ($zt && stripos($zt, 'ZeroTier') !== false) {
    preg_match('/ZeroTier[\s\S]*?IPv4[^:]*:\s*(\d+\.\d+\.\d+\.\d+)/', $zt, $m);
    if (!empty($m[1])) $my_ip = $m[1];
}

$detected = $device;
if (!empty($my_ip)) {
    $r = $conn->query("SELECT device_id FROM stores WHERE device_ip = '" . $conn->real_escape_string($my_ip) . "' AND device_id IS NOT NULL AND status = 'active' LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        $detected = $row['device_id'];
    } else {
        $conn->query("UPDATE stores SET device_ip = '" . $conn->real_escape_string($my_ip) . "' WHERE device_id = '" . $conn->real_escape_string($device) . "' AND (device_ip IS NULL OR device_ip != '" . $conn->real_escape_string($my_ip) . "')");
    }
}

// Update cache
$cache = sys_get_temp_dir() . '/oro_device_cache.json';
@file_put_contents($cache, json_encode(['device' => $detected, 'ip' => $my_ip]));

// Update config if device changed
if ($detected !== $device) {
    $config_path = __DIR__ . '/config.php';
    if (file_exists($config_path)) {
        $config = file_get_contents($config_path);
        $config = preg_replace("/define\('LOCAL_DEVICE_ID',\s*'[^']*'\)/", "define('LOCAL_DEVICE_ID', '$detected')", $config);
        @file_put_contents($config_path, $config);
    }
}

$conn->close();
echo json_encode(['ok' => true, 'device' => $detected, 'ip' => $my_ip]);
