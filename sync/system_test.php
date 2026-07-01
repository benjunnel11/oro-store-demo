<?php
/**
 * Comprehensive System Test — simulates real usage across Device A and B
 * Tests: products, stock, sales, GCash, ATM, credit, delivery, angkat, sync, transfer
 */
set_time_limit(1800); // 30 minutes
require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/config.php';

$log_file = __DIR__ . '/test_results.log';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) die("DB failed: " . $conn->connect_error);

$results = ['passed' => 0, 'failed' => 0, 'errors' => []];
$device_b_ip = '';
$r = $conn->query("SELECT device_ip FROM stores WHERE device_id = 'DEVICE_B' AND device_ip IS NOT NULL LIMIT 1");
if ($r && $row = $r->fetch_assoc()) $device_b_ip = $row['device_ip'];

function logTest($msg) {
    global $log_file;
    $line = "[" . date('H:i:s') . "] $msg";
    file_put_contents($log_file, "$line\n", FILE_APPEND);
    echo "$line\n";
    flush();
}

function pass($test) {
    global $results;
    $results['passed']++;
    logTest("PASS: $test");
}

function fail($test, $reason = '') {
    global $results;
    $results['failed']++;
    $results['errors'][] = "$test: $reason";
    logTest("FAIL: $test — $reason");
}

function httpGet($url, $timeout = 10) {
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
    return @file_get_contents($url, false, $ctx);
}

function httpPost($url, $data, $timeout = 10) {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => $timeout,
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($data)
    ]]);
    return @file_get_contents($url, false, $ctx);
}

file_put_contents($log_file, "=== SYSTEM TEST STARTED " . date('Y-m-d H:i:s') . " ===\n");
logTest("Device A: " . LOCAL_DEVICE_ID . " | Device B IP: " . ($device_b_ip ?: 'NOT SET'));

// ═══════════════════════════════════════
// TEST 1: Database Connection
// ═══════════════════════════════════════
logTest("\n--- TEST 1: Database ---");
$r = $conn->query("SELECT COUNT(*) as c FROM products WHERE is_deleted = 0");
if ($r) { pass("DB connection + products table (" . $r->fetch_assoc()['c'] . " products)"); }
else { fail("DB connection", $conn->error); }

$r = $conn->query("SHOW TABLES");
$tables = [];
while ($row = $r->fetch_array()) $tables[] = $row[0];
$required = ['products','store_prices','stores','users','transactions','transaction_items','gcash_transactions','atm_transactions','credits','deliveries','angkat_transactions','expenses','sync_log','sync_status'];
foreach ($required as $t) {
    if (in_array($t, $tables)) pass("Table exists: $t");
    else fail("Table missing: $t");
}

// ═══════════════════════════════════════
// TEST 2: Store Configuration
// ═══════════════════════════════════════
logTest("\n--- TEST 2: Stores ---");
$stores = $conn->query("SELECT * FROM stores WHERE status = 'active'")->fetch_all(MYSQLI_ASSOC);
foreach ($stores as $s) {
    $has_device = !empty($s['device_id']);
    $has_ip = !empty($s['device_ip']);
    logTest("Store: {$s['store_name']} | Device: " . ($s['device_id'] ?? 'none') . " | IP: " . ($s['device_ip'] ?? 'none'));
    if ($has_device) pass("Store {$s['store_name']} has device assigned");
    else fail("Store {$s['store_name']} has no device");
}

// ═══════════════════════════════════════
// TEST 3: Product & Stock Operations
// ═══════════════════════════════════════
logTest("\n--- TEST 3: Products & Stock ---");
$products = $conn->query("SELECT p.id, p.name, COALESCE(SUM(sp.stock),0) as total_stock
    FROM products p LEFT JOIN store_prices sp ON p.id = sp.product_id AND sp.is_deleted = 0
    WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL GROUP BY p.id LIMIT 5")->fetch_all(MYSQLI_ASSOC);
foreach ($products as $p) {
    logTest("Product: {$p['name']} | Total Stock: {$p['total_stock']}");
}
if (count($products) > 0) pass("Products loaded (" . count($products) . ")");
else fail("No products found");

// Test store_prices per store
$sp = $conn->query("SELECT s.store_name, COUNT(*) as cnt, SUM(sp.stock) as total
    FROM store_prices sp JOIN stores s ON sp.store_id = s.id
    WHERE sp.is_deleted = 0 GROUP BY sp.store_id")->fetch_all(MYSQLI_ASSOC);
foreach ($sp as $s) {
    pass("Store prices: {$s['store_name']} — {$s['cnt']} products, {$s['total']} units");
}

// ═══════════════════════════════════════
// TEST 4: Stock Transfer API
// ═══════════════════════════════════════
logTest("\n--- TEST 4: Stock Transfer API ---");
if (!empty($products)) {
    $test_product = $products[0];
    $pid = $test_product['id'];

    // Get current stock
    $before = $conn->query("SELECT stock FROM store_prices WHERE product_id = $pid AND store_id = 1 AND is_deleted = 0")->fetch_assoc();
    $stock_before = $before ? $before['stock'] : 0;

    if ($stock_before > 0) {
        // Test receive_stock
        $r = httpPost("http://127.0.0.1/oro-store-demo/sync/stock_transfer_api.php?key=" . urlencode(SYNC_PASSWORD),
            ['action' => 'receive_stock', 'product_id' => $pid, 'store_id' => 1, 'quantity' => 1, 'price' => 100, 'purchase_price' => 80]);
        $d = json_decode($r, true);
        if ($d && !empty($d['success'])) {
            pass("receive_stock API (stock: {$d['new_stock']})");
            // Revert
            $conn->query("UPDATE store_prices SET stock = $stock_before WHERE product_id = $pid AND store_id = 1");
            pass("Stock reverted");
        } else { fail("receive_stock API", $r); }

        // Test reduce_stock
        $r = httpPost("http://127.0.0.1/oro-store-demo/sync/stock_transfer_api.php?key=" . urlencode(SYNC_PASSWORD),
            ['action' => 'reduce_stock', 'product_id' => $pid, 'store_id' => 1, 'quantity' => 1]);
        $d = json_decode($r, true);
        if ($d && !empty($d['success'])) {
            pass("reduce_stock API (stock: {$d['new_stock']})");
            $conn->query("UPDATE store_prices SET stock = $stock_before WHERE product_id = $pid AND store_id = 1");
            pass("Stock reverted");
        } else { fail("reduce_stock API", $r); }
    } else {
        logTest("SKIP: No stock for transfer test");
    }
}

// ═══════════════════════════════════════
// TEST 5: Sync System
// ═══════════════════════════════════════
logTest("\n--- TEST 5: Sync System ---");

// Test sync_api status
$r = httpGet("http://127.0.0.1/oro-store-demo/sync/sync_api.php?action=status&key=" . urlencode(SYNC_PASSWORD));
$d = json_decode($r, true);
if ($d && !empty($d['success'])) {
    pass("Sync API status (device: {$d['device_id']}, pending: {$d['pending_changes']})");
} else { fail("Sync API status", $r); }

// Test diagnose
$r = httpGet("http://127.0.0.1/oro-store-demo/sync/diagnose.php");
$d = json_decode($r, true);
if ($d && is_array($d)) {
    foreach ($d as $step) {
        if ($step['status'] === 'pass') pass("Diagnose: {$step['step']}");
        else logTest("WARN: Diagnose {$step['step']}: {$step['detail']}");
    }
} else { fail("Diagnose endpoint", $r); }

// Test sync execution
$r = httpGet("http://127.0.0.1/oro-store-demo/sync/http_sync.php?run=1");
$d = json_decode($r, true);
if ($d) {
    pass("Sync executed (pushed: {$d['pushed']}, pulled: {$d['pulled']}, errors: " . count($d['errors'] ?? []) . ")");
    if (!empty($d['errors'])) {
        foreach ($d['errors'] as $e) logTest("  Sync error: $e");
    }
} else { fail("Sync execution", $r); }

// ═══════════════════════════════════════
// TEST 6: Device B Connectivity
// ═══════════════════════════════════════
logTest("\n--- TEST 6: Device B ---");
if ($device_b_ip) {
    // Ping test
    $ping = @shell_exec("ping -n 1 -w 3000 $device_b_ip 2>&1");
    if ($ping && strpos($ping, 'TTL=') !== false) {
        pass("Ping Device B ($device_b_ip)");
    } else {
        fail("Ping Device B", "Unreachable");
    }

    // Sync API on Device B
    $r = httpGet("http://$device_b_ip/oro-store-demo/sync/sync_api.php?action=status&key=" . urlencode(SYNC_PASSWORD));
    $d = json_decode($r, true);
    if ($d && !empty($d['success'])) {
        pass("Device B sync API (device: {$d['device_id']}, pending: {$d['pending_changes']})");
    } else {
        fail("Device B sync API", substr($r ?? 'no response', 0, 100));
    }

    // Stock transfer to Device B
    $r = httpPost("http://$device_b_ip/oro-store-demo/sync/stock_transfer_api.php?key=" . urlencode(SYNC_PASSWORD),
        ['action' => 'receive_stock', 'product_id' => $products[0]['id'] ?? 1, 'store_id' => 3, 'quantity' => 1, 'price' => 100, 'purchase_price' => 80]);
    $d = json_decode($r, true);
    if ($d && !empty($d['success'])) {
        pass("Stock transfer to Device B (stock: {$d['new_stock']})");
        // Revert on Device B
        httpPost("http://$device_b_ip/oro-store-demo/sync/stock_transfer_api.php?key=" . urlencode(SYNC_PASSWORD),
            ['action' => 'reduce_stock', 'product_id' => $products[0]['id'] ?? 1, 'store_id' => 3, 'quantity' => 1]);
        pass("Device B stock reverted");
    } else {
        fail("Stock transfer to Device B", substr($r ?? 'no response', 0, 100));
    }

    // Pull history from Device B
    $r = httpGet("http://$device_b_ip/oro-store-demo/sync/pull_history.php?action=get_history&period=today&key=" . urlencode(SYNC_PASSWORD));
    $d = json_decode($r, true);
    if ($d && !empty($d['success'])) {
        $table_count = count($d['tables'] ?? []);
        pass("Pull history from Device B ($table_count tables)");
    } else {
        fail("Pull history from Device B", substr($r ?? 'no response', 0, 100));
    }
} else {
    logTest("SKIP: Device B IP not configured");
}

// ═══════════════════════════════════════
// TEST 7: Pull History & Fetch
// ═══════════════════════════════════════
logTest("\n--- TEST 7: Pull History ---");
$r = httpGet("http://127.0.0.1/oro-store-demo/sync/pull_history.php?action=get_history&period=today&key=" . urlencode(SYNC_PASSWORD));
$d = json_decode($r, true);
if ($d && !empty($d['success'])) {
    $total_records = 0;
    foreach ($d['tables'] ?? [] as $t => $rows) {
        $total_records += count($rows);
        logTest("  $t: " . count($rows) . " records");
    }
    pass("Pull history endpoint ($total_records total records)");
} else { fail("Pull history endpoint", substr($r ?? 'no response', 0, 100)); }

// ═══════════════════════════════════════
// TEST 8: Pull Stock
// ═══════════════════════════════════════
logTest("\n--- TEST 8: Pull Stock ---");
$r = httpGet("http://127.0.0.1/oro-store-demo/sync/pull_stock.php?key=" . urlencode(SYNC_PASSWORD));
$d = json_decode($r, true);
if ($d && !empty($d['success'])) {
    foreach ($d['tables'] ?? [] as $t => $rows) {
        logTest("  $t: " . count($rows) . " records");
    }
    pass("Pull stock endpoint");
} else { fail("Pull stock endpoint", substr($r ?? 'no response', 0, 100)); }

// ═══════════════════════════════════════
// TEST 9: Proxy
// ═══════════════════════════════════════
logTest("\n--- TEST 9: Proxy ---");
if ($device_b_ip) {
    $r = httpGet("http://127.0.0.1/oro-store-demo/sync/proxy.php?ip=$device_b_ip&action=status");
    $d = json_decode($r, true);
    if ($d && !empty($d['success'])) {
        pass("Proxy to Device B");
    } else {
        if ($d && isset($d['diagnosis'])) {
            logTest("  Diagnosis: " . ($d['diagnosis']['suggestion'] ?? 'unknown'));
        }
        fail("Proxy to Device B", $d['message'] ?? 'failed');
    }
}

// ═══════════════════════════════════════
// TEST 10: Security
// ═══════════════════════════════════════
logTest("\n--- TEST 10: Security ---");

// Test unauthorized sync API
$r = httpGet("http://127.0.0.1/oro-store-demo/sync/sync_api.php?action=status&key=wrong_password");
$d = json_decode($r, true);
if ($d && isset($d['error'])) {
    pass("Sync API rejects wrong password");
} else { fail("Sync API should reject wrong password"); }

// Test config.php blocked
$r = httpGet("http://127.0.0.1/oro-store-demo/sync/config.php");
if (!$r || strpos($r, 'Forbidden') !== false || strpos($r, '403') !== false || empty(trim($r))) {
    pass("config.php blocked from browser");
} else {
    fail("config.php accessible from browser", substr($r, 0, 50));
}

// Test db_config.php blocked
$r = httpGet("http://127.0.0.1/oro-store-demo/core/db_config.php");
if (!$r || strpos($r, 'Forbidden') !== false || strpos($r, '403') !== false || empty(trim($r))) {
    pass("db_config.php blocked from browser");
} else {
    fail("db_config.php accessible from browser", substr($r, 0, 50));
}

// ═══════════════════════════════════════
// TEST 11: Transaction Data Integrity
// ═══════════════════════════════════════
logTest("\n--- TEST 11: Data Integrity ---");

// Check for orphaned transaction_items
$r = $conn->query("SELECT COUNT(*) as c FROM transaction_items ti LEFT JOIN transactions t ON ti.transaction_id = t.id WHERE t.id IS NULL");
$orphans = $r ? $r->fetch_assoc()['c'] : 0;
if ($orphans == 0) pass("No orphaned transaction_items");
else fail("Orphaned transaction_items: $orphans");

// Check store_prices without products
$r = $conn->query("SELECT COUNT(*) as c FROM store_prices sp LEFT JOIN products p ON sp.product_id = p.id WHERE p.id IS NULL AND sp.is_deleted = 0");
$orphans = $r ? $r->fetch_assoc()['c'] : 0;
if ($orphans == 0) pass("No orphaned store_prices");
else logTest("WARN: $orphans orphaned store_prices");

// Check for negative stock
$r = $conn->query("SELECT COUNT(*) as c FROM store_prices WHERE stock < 0 AND is_deleted = 0");
$neg = $r ? $r->fetch_assoc()['c'] : 0;
if ($neg == 0) pass("No negative stock");
else fail("Negative stock found: $neg records");

// Check user with store_id pointing to valid store
$r = $conn->query("SELECT u.username, u.store_id FROM users u WHERE u.store_id IS NOT NULL AND u.store_id NOT IN (SELECT id FROM stores)");
$bad = $r ? $r->num_rows : 0;
if ($bad == 0) pass("All user store assignments valid");
else fail("Users with invalid store_id: $bad");

// ═══════════════════════════════════════
// TEST 12: Auto-Increment Offsets
// ═══════════════════════════════════════
logTest("\n--- TEST 12: Auto-Increment ---");
$r = $conn->query("SHOW VARIABLES LIKE 'auto_increment_increment'");
$inc = $r ? $r->fetch_assoc()['Value'] : '?';
$r = $conn->query("SHOW VARIABLES LIKE 'auto_increment_offset'");
$off = $r ? $r->fetch_assoc()['Value'] : '?';
logTest("Increment: $inc, Offset: $off");

// ═══════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════
logTest("\n" . str_repeat('=', 50));
logTest("RESULTS: {$results['passed']} passed, {$results['failed']} failed");
if (!empty($results['errors'])) {
    logTest("FAILURES:");
    foreach ($results['errors'] as $e) logTest("  - $e");
}
logTest(str_repeat('=', 50));

$conn->close();

// Output JSON if called via web
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode($results);
}
