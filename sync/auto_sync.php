<?php
/**
 * Combined auto-sync — device-to-device + cloud stock sync.
 * Windows Task Scheduler runs this every minute.
 * Internally syncs twice (0s and 30s) for 30-second intervals.
 */
date_default_timezone_set('Asia/Manila');
set_time_limit(55);

require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cloud_stock_sync.php';
require_once __DIR__ . '/http_sync.php';

$device = LOCAL_DEVICE_ID;
$log_file = __DIR__ . '/auto_sync.log';

function slog($msg) {
    global $log_file;
    $line = date('Y-m-d H:i:s') . " $msg\n";
    if (file_exists($log_file) && filesize($log_file) > 100000) {
        file_put_contents($log_file, $line);
    } else {
        file_put_contents($log_file, $line, FILE_APPEND);
    }
}

function getLocalConn() {
    $c = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($c->connect_error) return null;
    $c->set_charset("utf8mb4");
    return $c;
}

function runSync() {
    global $device;
    $conn = getLocalConn();
    if (!$conn) { slog("[$device] Local DB failed"); return []; }

    $store = $conn->query("SELECT id FROM stores WHERE device_id = '$device' AND status = 'active' LIMIT 1");
    $store_row = $store ? $store->fetch_assoc() : null;
    if (!$store_row) { $store = $conn->query("SELECT id FROM stores WHERE status = 'active' ORDER BY id LIMIT 1"); $store_row = $store ? $store->fetch_assoc() : null; }
    $store_id = $store_row ? $store_row['id'] : 0;
    $results = [];

    // 1. Device-to-device sync via HTTP API (same as Connection page)
    $sync_result = httpSync();
    if (!empty($sync_result['pushed'])) $results['pushed'] = $sync_result['pushed'];
    if (!empty($sync_result['pulled'])) $results['pulled'] = $sync_result['pulled'];
    if (!empty($sync_result['errors'])) $results['sync_errors'] = $sync_result['errors'];

    // 2. Cloud stock sync
    if ($store_id) {
        $cf = intval(cloudFlushQueue());
        $cp = intval(cloudStockPushAll($conn, $store_id));
        $cl = intval(cloudStockPull($conn, $store_id));
        if ($cf) $results['cf'] = $cf;
        if ($cp) $results['cp'] = $cp;
        if ($cl) $results['cl'] = $cl;
    }

    // 3. Cloud cleanup (once per hour)
    $flag = __DIR__ . '/.last_cleanup';
    if (time() - (file_exists($flag) ? intval(file_get_contents($flag)) : 0) > 3600) {
        $cloud = getCloudConnection();
        if ($cloud) {
            $cloud->query("CREATE TABLE IF NOT EXISTS cloud_sync_tracker (device_id VARCHAR(50) PRIMARY KEY, last_sync TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
            $cloud->query("INSERT INTO cloud_sync_tracker (device_id, last_sync) VALUES ('$device', NOW()) ON DUPLICATE KEY UPDATE last_sync = NOW()");
            $o = $cloud->query("SELECT MIN(last_sync) as o FROM cloud_sync_tracker");
            $os = $o ? $o->fetch_assoc()['o'] : null;
            if ($os) $cloud->query("DELETE FROM cloud_stock WHERE updated_at < NOW() - INTERVAL 2 DAY AND updated_at < '$os'");
            $cloud->close();
        }
        file_put_contents($flag, time());
    }

    $conn->close();
    return $results;
}

// Sync twice per minute for ~30 second intervals
$r1 = runSync();
slog("[$device] sync1: " . json_encode($r1));

sleep(30);

$r2 = runSync();
slog("[$device] sync2: " . json_encode($r2));
