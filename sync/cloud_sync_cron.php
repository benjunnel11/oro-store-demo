<?php
/**
 * Background cloud stock sync — runs via Windows Task Scheduler.
 * Pushes local stock to cloud, pulls other stores' stock from cloud.
 */
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cloud_stock_sync.php';

$device = LOCAL_DEVICE_ID;
$log_file = __DIR__ . '/cloud_sync.log';

function clog($msg) {
    global $log_file;
    $line = date('Y-m-d H:i:s') . " $msg\n";
    // Keep log small — max 50KB
    if (file_exists($log_file) && filesize($log_file) > 50000) {
        file_put_contents($log_file, $line);
    } else {
        file_put_contents($log_file, $line, FILE_APPEND);
    }
}

// Get this device's store
$store = $conn->query("SELECT id, store_name FROM stores WHERE device_id = '$device' AND status = 'active' LIMIT 1")->fetch_assoc();
if (!$store) {
    $store = $conn->query("SELECT id, store_name FROM stores WHERE status = 'active' ORDER BY id LIMIT 1")->fetch_assoc();
}
if (!$store) { clog("[$device] No store found. Exiting."); exit; }
$store_id = $store['id'];

// Flush queued pushes
$flushed = cloudFlushQueue();

// Push all current stock for my store
$pushed = cloudStockPushAll($conn, $store_id);

// Pull other stores' stock from cloud
$pulled = cloudStockPull($conn, $store_id);

// Track this device's last sync time in cloud
$cleaned = 0;
$cloud = getCloudConnection();
if ($cloud) {
    // Create tracker table if needed
    $cloud->query("CREATE TABLE IF NOT EXISTS cloud_sync_tracker (
        device_id VARCHAR(50) PRIMARY KEY,
        last_sync TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Update this device's last sync time
    $cloud->query("INSERT INTO cloud_sync_tracker (device_id, last_sync) VALUES ('$device', NOW())
        ON DUPLICATE KEY UPDATE last_sync = NOW()");

    // Find the oldest device sync time — only cleanup if ALL devices synced within 2 days
    $oldest = $cloud->query("SELECT MIN(last_sync) as oldest FROM cloud_sync_tracker");
    $oldest_sync = $oldest ? $oldest->fetch_assoc()['oldest'] : null;

    if ($oldest_sync) {
        // Only delete records older than 2 days AND older than the oldest device's last sync
        // This ensures no device misses data it hasn't pulled yet
        $cloud->query("DELETE FROM cloud_stock
            WHERE updated_at < NOW() - INTERVAL 2 DAY
            AND updated_at < '$oldest_sync'");
        $cleaned = $cloud->affected_rows;
    }

    $cloud->close();
}

if ($flushed > 0 || $pushed > 0 || $pulled > 0 || $cleaned > 0) {
    clog("[$device] Pushed:$pushed Pulled:$pulled Flushed:$flushed Cleaned:$cleaned");
}

$conn->close();
