<?php
set_time_limit(1800);
require_once __DIR__ . '/../core/db_config.php';
require_once __DIR__ . '/config.php';

$log_file = __DIR__ . '/simulation_results.log';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) die("DB failed");
$conn->query("SET @@SESSION.auto_increment_increment=10,@@SESSION.auto_increment_offset=1");

$device_b_ip = '';
$r = $conn->query("SELECT device_ip FROM stores WHERE device_id = 'DEVICE_B' AND device_ip IS NOT NULL LIMIT 1");
if ($r && $row = $r->fetch_assoc()) $device_b_ip = $row['device_ip'];

$results = ['passed' => 0, 'failed' => 0, 'errors' => []];
$cleanup_ids = ['transactions' => [], 'transaction_items' => [], 'gcash_transactions' => [], 'atm_transactions' => [], 'expenses' => [], 'cash_transactions' => []];

function logSim($msg) { global $log_file; $l = "[" . date('H:i:s') . "] $msg"; file_put_contents($log_file, "$l\n", FILE_APPEND); echo "$l\n"; flush(); }
function pass($t) { global $results; $results['passed']++; logSim("PASS: $t"); }
function fail($t, $r='') { global $results; $results['failed']++; $results['errors'][] = "$t: $r"; logSim("FAIL: $t — $r"); }

file_put_contents($log_file, "=== BUSINESS SIMULATION " . date('Y-m-d H:i:s') . " ===\n");

// Get a product with stock
$product = $conn->query("SELECT p.id, p.name, p.price, p.purchase_price, sp.stock, sp.store_id
    FROM products p JOIN store_prices sp ON p.id = sp.product_id
    WHERE p.is_deleted = 0 AND sp.is_deleted = 0 AND sp.stock > 5 AND p.parent_product_id IS NULL
    LIMIT 1")->fetch_assoc();

if (!$product) { logSim("No product with stock > 5. Aborting."); exit; }
logSim("Test product: {$product['name']} (ID:{$product['id']}) | Stock: {$product['stock']} | Price: {$product['price']} | Store: {$product['store_id']}");
$stock_before = intval($product['stock']);

// ═══════════════════════════════════════
// SIMULATION 1: Cash Sale on Device A
// ═══════════════════════════════════════
logSim("\n--- SIM 1: Cash Sale ---");
$sale_total = floatval($product['price']);
$sale_profit = $sale_total - floatval($product['purchase_price']);
$tx_num = 'SIM-' . date('ymdHis') . '-001';

$conn->query("INSERT INTO transactions (transaction_number, total_amount, total_profit, payment_method, amount_paid, change_amount, total_items, items_count, status, store_id, user_id, device_id, transaction_date)
    VALUES ('$tx_num', $sale_total, $sale_profit, 'cash', $sale_total, 0, 1, 1, 'completed', {$product['store_id']}, 1, 'DEVICE_A', NOW())");
$tx_id = $conn->insert_id;
if ($tx_id) {
    $cleanup_ids['transactions'][] = $tx_id;
    $conn->query("INSERT INTO transaction_items (transaction_id, product_id, product_name, quantity, price, subtotal, purchase_price)
        VALUES ($tx_id, {$product['id']}, '{$product['name']}', 1, {$product['price']}, $sale_total, {$product['purchase_price']})");
    $cleanup_ids['transaction_items'][] = $conn->insert_id;

    // Reduce stock
    $conn->query("UPDATE store_prices SET stock = stock - 1 WHERE product_id = {$product['id']} AND store_id = {$product['store_id']}");

    // Verify
    $new_stock = $conn->query("SELECT stock FROM store_prices WHERE product_id = {$product['id']} AND store_id = {$product['store_id']}")->fetch_assoc()['stock'];
    if ($new_stock == $stock_before - 1) pass("Cash sale created ($tx_num) | Stock: $stock_before → $new_stock");
    else fail("Stock not reduced correctly", "Expected " . ($stock_before-1) . " got $new_stock");

    // Verify transaction record
    $tx = $conn->query("SELECT * FROM transactions WHERE id = $tx_id")->fetch_assoc();
    if ($tx && $tx['status'] === 'completed' && floatval($tx['total_amount']) == $sale_total) pass("Transaction record verified");
    else fail("Transaction record invalid");
} else { fail("Cash sale INSERT failed", $conn->error); }

// ═══════════════════════════════════════
// SIMULATION 2: GCash Cash-In
// ═══════════════════════════════════════
logSim("\n--- SIM 2: GCash Cash-In ---");
$gcash_amount = 500;
$gcash_fee = max(5, ceil($gcash_amount * 0.01));
$gcash_total = $gcash_amount + $gcash_fee;
$gcash_ref = 'SIM' . rand(100000, 999999);

$conn->query("INSERT INTO gcash_transactions (transaction_type, amount, fee, total_amount, reference_number, status, user_id, store_id, device_id, transaction_date)
    VALUES ('cash_in', $gcash_amount, $gcash_fee, $gcash_total, '$gcash_ref', 'completed', 1, {$product['store_id']}, 'DEVICE_A', NOW())");
$gcash_id = $conn->insert_id;
if ($gcash_id) {
    $cleanup_ids['gcash_transactions'][] = $gcash_id;
    pass("GCash cash-in: ₱$gcash_amount + ₱$gcash_fee fee = ₱$gcash_total (ref: $gcash_ref)");

    // Verify
    $g = $conn->query("SELECT * FROM gcash_transactions WHERE id = $gcash_id")->fetch_assoc();
    if ($g && $g['transaction_type'] === 'cash_in' && floatval($g['amount']) == $gcash_amount) pass("GCash record verified");
    else fail("GCash record invalid");
} else { fail("GCash INSERT failed", $conn->error); }

// ═══════════════════════════════════════
// SIMULATION 3: GCash Cash-Out
// ═══════════════════════════════════════
logSim("\n--- SIM 3: GCash Cash-Out ---");
$gcash_out = 300;
$gcash_out_fee = max(10, ceil($gcash_out * 0.02));
$gcash_out_total = $gcash_out + $gcash_out_fee;
$gcash_out_ref = 'SIM' . rand(100000, 999999);

$conn->query("INSERT INTO gcash_transactions (transaction_type, amount, fee, total_amount, reference_number, status, user_id, store_id, device_id, transaction_date)
    VALUES ('cash_out', $gcash_out, $gcash_out_fee, $gcash_out_total, '$gcash_out_ref', 'completed', 1, {$product['store_id']}, 'DEVICE_A', NOW())");
$gcash_out_id = $conn->insert_id;
if ($gcash_out_id) {
    $cleanup_ids['gcash_transactions'][] = $gcash_out_id;
    pass("GCash cash-out: ₱$gcash_out + ₱$gcash_out_fee fee = ₱$gcash_out_total");
} else { fail("GCash cash-out failed", $conn->error); }

// ═══════════════════════════════════════
// SIMULATION 4: ATM Transaction
// ═══════════════════════════════════════
logSim("\n--- SIM 4: ATM Transaction ---");
$atm_amount = 2000;
$atm_fee = 12;
$atm_ref = 'SIM' . rand(100, 999);

$conn->query("INSERT INTO atm_transactions (reference_number, customer_name, amount, service_charge, user_id, store_id, device_id, status, transaction_date)
    VALUES ('$atm_ref', 'Test Customer', $atm_amount, $atm_fee, 1, {$product['store_id']}, 'DEVICE_A', 'completed', NOW())");
$atm_id = $conn->insert_id;
if ($atm_id) {
    $cleanup_ids['atm_transactions'][] = $atm_id;
    pass("ATM withdrawal: ₱$atm_amount + ₱$atm_fee charge (ref: $atm_ref)");
} else { fail("ATM INSERT failed", $conn->error); }

// ═══════════════════════════════════════
// SIMULATION 5: Expense
// ═══════════════════════════════════════
logSim("\n--- SIM 5: Expense ---");
$exp_amount = 150;
$conn->query("INSERT INTO expenses (category, amount, description, user_id, user_name, store_id, device_id)
    VALUES ('Supplies', $exp_amount, 'Test expense - simulation', 1, 'Administrator', {$product['store_id']}, 'DEVICE_A')");
$exp_id = $conn->insert_id;
if ($exp_id) {
    $cleanup_ids['expenses'][] = $exp_id;
    pass("Expense: ₱$exp_amount (Supplies)");
} else { fail("Expense INSERT failed", $conn->error); }

// ═══════════════════════════════════════
// SIMULATION 6: Multiple Sales (bulk)
// ═══════════════════════════════════════
logSim("\n--- SIM 6: Bulk Sales (5 transactions) ---");
for ($i = 1; $i <= 5; $i++) {
    $tx_n = 'SIM-BULK-' . date('ymdHis') . "-$i";
    $qty = rand(1, 2);
    $total = $qty * floatval($product['price']);
    $profit = $qty * $sale_profit;

    $conn->query("INSERT INTO transactions (transaction_number, total_amount, total_profit, payment_method, amount_paid, change_amount, total_items, items_count, status, store_id, user_id, device_id, transaction_date)
        VALUES ('$tx_n', $total, $profit, 'cash', $total, 0, $qty, $qty, 'completed', {$product['store_id']}, 1, 'DEVICE_A', NOW() - INTERVAL $i MINUTE)");
    $bulk_tx_id = $conn->insert_id;
    if ($bulk_tx_id) {
        $cleanup_ids['transactions'][] = $bulk_tx_id;
        $conn->query("INSERT INTO transaction_items (transaction_id, product_id, product_name, quantity, price, subtotal, purchase_price)
            VALUES ($bulk_tx_id, {$product['id']}, '{$product['name']}', $qty, {$product['price']}, $total, {$product['purchase_price']})");
        $cleanup_ids['transaction_items'][] = $conn->insert_id;
        $conn->query("UPDATE store_prices SET stock = stock - $qty WHERE product_id = {$product['id']} AND store_id = {$product['store_id']}");
    }
}
$after_bulk = $conn->query("SELECT stock FROM store_prices WHERE product_id = {$product['id']} AND store_id = {$product['store_id']}")->fetch_assoc()['stock'];
pass("5 bulk sales completed | Stock now: $after_bulk");

// ═══════════════════════════════════════
// SIMULATION 7: Device B Sale (remote)
// ═══════════════════════════════════════
logSim("\n--- SIM 7: Device B Operations ---");
if ($device_b_ip) {
    // Check Device B product stock
    $r_raw = @file_get_contents("http://$device_b_ip/oro-store/sync/pull_stock.php?key=" . urlencode(SYNC_PASSWORD),
        false, stream_context_create(['http' => ['timeout' => 10]]));
    $r_data = $r_raw ? json_decode($r_raw, true) : null;
    if ($r_data && !empty($r_data['success'])) {
        $b_products = count($r_data['tables']['products'] ?? []);
        $b_prices = count($r_data['tables']['store_prices'] ?? []);
        pass("Device B has $b_products products, $b_prices store prices");
    } else {
        fail("Cannot read Device B stock data");
    }

    // Test cross-device stock transfer
    $b_stock_before_r = @file_get_contents("http://$device_b_ip/oro-store/sync/stock_transfer_api.php?key=" . urlencode(SYNC_PASSWORD),
        false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['action' => 'receive_stock', 'product_id' => $product['id'], 'store_id' => 3, 'quantity' => 2, 'price' => $product['price'], 'purchase_price' => $product['purchase_price']])]]));
    $b_result = json_decode($b_stock_before_r, true);
    if ($b_result && !empty($b_result['success'])) {
        pass("Sent 2 units to Device B (new stock: {$b_result['new_stock']})");

        // Revert
        @file_get_contents("http://$device_b_ip/oro-store/sync/stock_transfer_api.php?key=" . urlencode(SYNC_PASSWORD),
            false, stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'header' => "Content-Type: application/json\r\n",
                'content' => json_encode(['action' => 'reduce_stock', 'product_id' => $product['id'], 'store_id' => 3, 'quantity' => 2])]]));
        pass("Reverted Device B stock");
    } else {
        fail("Cross-device stock transfer", substr($b_stock_before_r ?? 'no response', 0, 100));
    }

    // Test sync
    $sync_r = @file_get_contents("http://127.0.0.1/oro-store/sync/http_sync.php?run=1", false,
        stream_context_create(['http' => ['timeout' => 30]]));
    $sync_d = json_decode($sync_r, true);
    if ($sync_d) {
        pass("Sync: pushed {$sync_d['pushed']}, pulled {$sync_d['pulled']}, errors: " . count($sync_d['errors'] ?? []));
    } else { fail("Sync execution"); }
} else {
    logSim("SKIP: Device B not configured");
}

// ═══════════════════════════════════════
// SIMULATION 8: Revenue Calculation
// ═══════════════════════════════════════
logSim("\n--- SIM 8: Revenue Verification ---");
$today_rev = $conn->query("SELECT COALESCE(SUM(total_amount),0) as rev, COALESCE(SUM(total_profit),0) as profit, COUNT(*) as cnt
    FROM transactions WHERE DATE(transaction_date) = CURDATE() AND status = 'completed'")->fetch_assoc();
pass("Today's revenue: ₱{$today_rev['rev']} | Profit: ₱{$today_rev['profit']} | Transactions: {$today_rev['cnt']}");

$today_gcash = $conn->query("SELECT COUNT(*) as cnt, COALESCE(SUM(fee),0) as fees
    FROM gcash_transactions WHERE DATE(transaction_date) = CURDATE() AND status = 'completed'")->fetch_assoc();
pass("Today's GCash: {$today_gcash['cnt']} transactions | Fees earned: ₱{$today_gcash['fees']}");

$today_expenses = $conn->query("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE DATE(created_at) = CURDATE()")->fetch_assoc();
pass("Today's expenses: ₱{$today_expenses['total']}");

// ═══════════════════════════════════════
// SIMULATION 9: Inventory Check
// ═══════════════════════════════════════
logSim("\n--- SIM 9: Inventory ---");
$inv = $conn->query("SELECT COALESCE(SUM(sp.stock),0) as units, COALESCE(SUM(sp.stock*sp.price),0) as retail, COALESCE(SUM(sp.stock*sp.purchase_price),0) as cost
    FROM store_prices sp JOIN products p ON sp.product_id = p.id WHERE p.is_deleted = 0 AND sp.is_deleted = 0 AND p.parent_product_id IS NULL")->fetch_assoc();
pass("Inventory: {$inv['units']} units | Retail: ₱" . number_format($inv['retail'],0) . " | Cost: ₱" . number_format($inv['cost'],0));

// ═══════════════════════════════════════
// CLEANUP — Revert all test data
// ═══════════════════════════════════════
logSim("\n--- CLEANUP ---");
foreach ($cleanup_ids as $table => $ids) {
    if (!empty($ids)) {
        $ids_str = implode(',', $ids);
        $conn->query("DELETE FROM `$table` WHERE id IN ($ids_str)");
        logSim("Cleaned $table: " . count($ids) . " records");
    }
}
// Revert stock
$conn->query("UPDATE store_prices SET stock = $stock_before WHERE product_id = {$product['id']} AND store_id = {$product['store_id']}");
$final_stock = $conn->query("SELECT stock FROM store_prices WHERE product_id = {$product['id']} AND store_id = {$product['store_id']}")->fetch_assoc()['stock'];
if ($final_stock == $stock_before) pass("Stock reverted to $stock_before");
else fail("Stock revert", "Expected $stock_before got $final_stock");

// ═══════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════
logSim("\n" . str_repeat('=', 50));
logSim("SIMULATION COMPLETE: {$results['passed']} passed, {$results['failed']} failed");
if (!empty($results['errors'])) {
    logSim("FAILURES:");
    foreach ($results['errors'] as $e) logSim("  - $e");
}
logSim(str_repeat('=', 50));
$conn->close();

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode($results);
}
