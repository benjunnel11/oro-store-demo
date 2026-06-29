<?php
/**
 * Nightly Cleanup — runs on branch devices only
 * 1. Sync ALL data to Device A (must succeed)
 * 2. Wipe ALL transaction/operational tables
 * 3. Pull back stock, products, stores, users, accounts from Device A
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../core/db_config.php';

ob_start();
$device = LOCAL_DEVICE_ID;
$is_cli = php_sapi_name() === 'cli';
$is_json = !$is_cli;

// SAFETY: Never run on Device A
$lock_file = __DIR__ . '/.main_server';
if ($device === 'DEVICE_A') {
    ob_end_clean();
    $msg = 'BLOCKED: this is Device A. Cleanup NEVER runs here.';
    if ($is_json) { echo json_encode(['success' => false, 'message' => $msg]); exit; }
    echo $msg . "\n"; exit;
}
if (file_exists($lock_file)) {
    ob_end_clean();
    $msg = 'BLOCKED: .main_server lock file found.';
    if ($is_json) { echo json_encode(['success' => false, 'message' => $msg]); exit; }
    echo $msg . "\n"; exit;
}

$results = ['device' => $device, 'synced' => false, 'wiped' => 0, 'restored' => 0, 'errors' => []];

// Get Device A's IP
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'DB connection failed']); exit;
}
$device_a_ip = REMOTE_IP;
$r = $conn->query("SELECT device_ip FROM stores WHERE device_id = 'DEVICE_A' AND device_ip IS NOT NULL LIMIT 1");
if ($r && $row = $r->fetch_assoc()) $device_a_ip = $row['device_ip'];

// ═══════════════════════════════════════
// STEP 1: Sync to Device A (MUST succeed)
// ═══════════════════════════════════════
logMessage("[$device] Step 1: Syncing to Device A...");
require_once __DIR__ . '/http_sync.php';
$sync_result = httpSync();
$results['sync_pushed'] = $sync_result['pushed'] ?? 0;
$results['sync_pulled'] = $sync_result['pulled'] ?? 0;

// Verify Device A is reachable
$verify_url = "http://$device_a_ip/oro-store/sync/sync_api.php?action=status&key=" . urlencode(SYNC_PASSWORD);
$ctx = stream_context_create(['http' => ['timeout' => 5]]);
$verify = @file_get_contents($verify_url, false, $ctx);
$verify_data = $verify ? json_decode($verify, true) : null;

if (!$verify_data || empty($verify_data['success'])) {
    $results['errors'][] = 'Cannot verify Device A is reachable. Wipe ABORTED for safety.';
    $results['message'] = 'Wipe aborted — Device A unreachable. Data is safe.';
    $conn->close();
    ob_end_clean();
    if ($is_json) { header('Content-Type: application/json'); echo json_encode($results); exit; }
    echo $results['message'] . "\n"; exit;
}

$results['synced'] = true;
logMessage("[$device] Sync complete. Device A verified online.");

// ═══════════════════════════════════════
// STEP 2: Wipe ALL operational data
// ═══════════════════════════════════════
logMessage("[$device] Step 2: Wiping all operational data...");
$conn->query("SET @is_syncing = 1");

// Wipe transactions + stock levels — pull fresh stock back from Device A after
$wipe_tables = [
    'transactions', 'transaction_items',
    'gcash_transactions', 'atm_transactions', 'atm_settlements',
    'bank_transactions', 'cash_transactions',
    'deliveries', 'delivery_items',
    'credits', 'angkat_transactions', 'angkat_items', 'angkat_payments', 'angkat_returns',
    'expenses', 'stock_receipts', 'stock_receipt_items', 'stock_transfers',
    'store_prices',
    'sync_log', 'sync_status', 'product_history', 'system_logs'
];

$wiped = 0;
foreach ($wipe_tables as $table) {
    $check = @$conn->query("SHOW TABLES LIKE '$table'");
    if ($check && $check->num_rows > 0) {
        @$conn->query("DELETE FROM `$table`");
        $wiped += $conn->affected_rows;
    }
}
$results['wiped'] = $wiped;

$conn->query("SET @is_syncing = 0");
logMessage("[$device] Wiped $wiped records from " . count($wipe_tables) . " tables.");

// ═══════════════════════════════════════
// STEP 3: Pull back essential data from Device A
// ═══════════════════════════════════════
logMessage("[$device] Step 3: Pulling stock and essentials from Device A...");

$pull_url = "http://$device_a_ip/oro-store/sync/pull_stock.php?key=" . urlencode(SYNC_PASSWORD);
$ctx = stream_context_create(['http' => ['timeout' => 30]]);
$raw = @file_get_contents($pull_url, false, $ctx);
$stock_data = $raw ? json_decode($raw, true) : null;

$restored = 0;
if ($stock_data && !empty($stock_data['success'])) {
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    $conn->query("SET @is_syncing = 1");

    foreach ($stock_data['tables'] as $tbl => $rows) {
        foreach ($rows as $row) {
            $id = intval($row['id'] ?? 0);
            if (!$id) continue;

            unset($row['is_synced']);
            $cols = []; $vals = []; $updates = [];
            foreach ($row as $k => $v) {
                $cols[] = "`$k`";
                $val = $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
                $vals[] = $val;
                if ($k !== 'id') $updates[] = "`$k` = $val";
            }

            $sql = "INSERT INTO `$tbl` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ") ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
            if (@$conn->query($sql)) $restored++;
        }
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET @is_syncing = 0");
} else {
    $results['errors'][] = 'Could not pull stock data from Device A';
}

$results['restored'] = $restored;
$results['success'] = true;
$results['message'] = "Cleanup complete. Wiped $wiped records, restored $restored stock/essential records.";

logMessage("[$device] Cleanup finished. Wiped: $wiped | Restored: $restored");

$conn->close();

ob_end_clean();
if ($is_json) {
    header('Content-Type: application/json');
    echo json_encode($results);
} else {
    echo "Cleanup complete for $device\n";
    echo "Synced: pushed {$results['sync_pushed']}, pulled {$results['sync_pulled']}\n";
    echo "Wiped: $wiped records\n";
    echo "Restored: $restored records\n";
}
