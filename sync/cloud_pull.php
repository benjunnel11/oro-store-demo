<?php
/**
 * AJAX endpoint — pulls other stores' stock from cloud and updates local.
 * Called periodically by the cashier/admin page in the background.
 */
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/cloud_stock_sync.php';

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

$currentUser = getCurrentUser();
$store_id = $currentUser['store_id'] ?? null;

// If no store assigned (admin/super_admin), detect from device
if (!$store_id) {
    $device = LOCAL_DEVICE_ID;
    $r = $conn->query("SELECT id FROM stores WHERE device_id = '$device' AND status = 'active' LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    $store_id = $row ? $row['id'] : null;
}

if (!$store_id) {
    // Fallback: use first active store
    $r = $conn->query("SELECT id FROM stores WHERE status = 'active' ORDER BY id LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    $store_id = $row ? $row['id'] : null;
}

if (!$store_id) {
    echo json_encode(['success' => false, 'error' => 'No store found']);
    exit;
}

// 0. Ensure .stignore is up to date on this device
$_stignore_file = __DIR__ . '/../.stignore';
$_stignore_content = "// Syncthing ignore file — auto-managed
sync/config.php
sync/.local_env
sync/.cloud_env
core/db_config.php
.htpasswd
sync/*.log
cashier/*.log
transactions/*.log
*.log
sync/.main_server
sync/.last_cleanup
sync/*_queue.json
sync/*.vbs
*.sync-conflict-*
*.sync-tmp
.sync-conflict-*
old/
backups/
";
$_current = file_exists($_stignore_file) ? file_get_contents($_stignore_file) : '';
if ($_current !== $_stignore_content) {
    file_put_contents($_stignore_file, $_stignore_content);
}

// 1. Device-to-device sync via HTTP API
require_once __DIR__ . '/http_sync.php';
$sync = httpSync();
$d2d_pushed = intval($sync['pushed'] ?? 0);
$d2d_pulled = intval($sync['pulled'] ?? 0);
$d2d_errors = $sync['errors'] ?? [];

// Log sync results for debugging
$_sync_log = __DIR__ . '/cloud_pull_debug.log';
if ($d2d_pushed > 0 || $d2d_pulled > 0 || !empty($d2d_errors)) {
    $line = date('Y-m-d H:i:s') . " pushed:$d2d_pushed pulled:$d2d_pulled" . (!empty($d2d_errors) ? " errors:" . implode('|', $d2d_errors) : "") . "\n";
    if (file_exists($_sync_log) && filesize($_sync_log) > 50000) file_put_contents($_sync_log, $line);
    else file_put_contents($_sync_log, $line, FILE_APPEND);
}

// 2. Cloud stock sync
$flushed = intval(cloudFlushQueue());
$cloud_pushed = intval(cloudStockPushAll($conn, $store_id));
$cloud_pulled = intval(cloudStockPull($conn, $store_id));

// 3. Cloud-based shared data sync (GCash accounts, users)
cloudTableInit();
$shared_tables = ['gcash_accounts', 'users', 'bank_accounts'];
$shared_pushed = 0;
$shared_pulled = 0;
foreach ($shared_tables as $st) {
    $shared_pushed += intval(cloudPushTable($conn, $st));
    $shared_pulled += intval(cloudPullTable($conn, $st));
}

$conn->close();
echo json_encode([
    'success' => true,
    'pulled' => $cloud_pulled,
    'pushed' => $cloud_pushed,
    'flushed' => $flushed,
    'd2d_pushed' => $d2d_pushed,
    'd2d_pulled' => $d2d_pulled,
    'shared_pushed' => $shared_pushed,
    'shared_pulled' => $shared_pulled
]);
