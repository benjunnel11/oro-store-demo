<?php
ob_start();

require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';
require_once __DIR__ . '/../sync/sync_helper.php';
require_once __DIR__ . '/../core/transaction_helper.php';
require_once __DIR__ . '/../core/product_stock_helper.php';

$db = new SyncDB();
$currentUser = getCurrentUser();

// Ensure 'pending' is in the transactions status enum
$conn->query("ALTER TABLE transactions MODIFY COLUMN status ENUM('completed','edited','voided','pending') DEFAULT 'completed'");

$userStore = null;
$_store_id = $currentUser['store_id'] ?: ($_SESSION['admin_cashier_store'] ?? null);
if ($_store_id) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $_store_id);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ─── AJAX: Search customers ───────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search_customers') {
    header('Content-Type: application/json');
    $search   = isset($_GET['search']) ? trim($_GET['search']) : '';
    $store_id = $userStore ? $userStore['id'] : null;
    if (strlen($search) < 2) { echo json_encode([]); $conn->close(); exit; }
    $sp = "%{$search}%";
    if ($store_id) {
        $stmt = $conn->prepare("SELECT DISTINCT name, contact_number, address FROM customers WHERE store_id = ? AND is_deleted = 0 AND name LIKE ? ORDER BY name ASC LIMIT 10");
        $stmt->bind_param("is", $store_id, $sp);
    } else {
        $stmt = $conn->prepare("SELECT DISTINCT name, contact_number, address FROM customers WHERE is_deleted = 0 AND name LIKE ? ORDER BY name ASC LIMIT 10");
        $stmt->bind_param("s", $sp);
    }
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close(); $conn->close(); exit;
}

// ─── AJAX: Get product credit charge info ─────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_product_credit_info') {
    header('Content-Type: application/json');
    $product_id = intval($_GET['product_id'] ?? 0);
    if (!$product_id) { echo json_encode(['charge' => 0, 'category' => '']); $conn->close(); exit; }
    $stmt = $conn->prepare(
        "SELECT pc.category_name,
                COALESCE(ccc.charge_amount, 0) AS charge_amount
         FROM products p
         LEFT JOIN product_categories pc  ON p.category_id = pc.id AND pc.is_deleted = 0
         LEFT JOIN credit_charge_categories ccc
               ON ccc.category_name = pc.category_name AND ccc.is_deleted = 0
         WHERE p.id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode([
        'charge'   => $row ? (float)($row['charge_amount'] ?? 0) : 0,
        'category' => $row ? ($row['category_name'] ?? '') : ''
    ]);
    $conn->close(); exit;
}

// ─── AJAX: Get half-pack info (same logic as cashier.php) ─────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_halfpack_info') {
    header('Content-Type: application/json');
    $product_id = intval($_GET['product_id'] ?? 0);
    if (!$product_id) {
        echo json_encode([
            'is_individual'              => false,
            'parent_price'               => null,
            'individual_pieces_per_pack' => null,
            'credit_charge'              => 0
        ]);
        $conn->close(); exit;
    }

    $stmt = $conn->prepare(
        "SELECT
            p.parent_product_id,
            parent.price                        AS parent_price,
            parent.individual_pieces_per_pack,
            pc.category_name,
            COALESCE(ccc.charge_amount, 0)      AS credit_charge
         FROM products p
         LEFT JOIN products parent
               ON parent.id = p.parent_product_id
         LEFT JOIN product_categories pc
               ON parent.category_id = pc.id AND pc.is_deleted = 0
         LEFT JOIN credit_charge_categories ccc
               ON ccc.category_name = pc.category_name AND ccc.is_deleted = 0
         WHERE p.id = ? AND p.is_deleted = 0
         LIMIT 1"
    );
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !$row['parent_product_id']) {
        echo json_encode([
            'is_individual'              => false,
            'parent_price'               => null,
            'individual_pieces_per_pack' => null,
            'credit_charge'              => 0
        ]);
    } else {
        echo json_encode([
            'is_individual'              => true,
            'parent_price'               => (float)$row['parent_price'],
            'individual_pieces_per_pack' => (int)$row['individual_pieces_per_pack'],
            'credit_charge'              => (float)$row['credit_charge']
        ]);
    }
    $conn->close(); exit;
}

// ─── POST: Complete credit ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_credit') {
    header('Content-Type: application/json');
    $items             = json_decode($_POST['items'], true);
    $total_amount      = floatval($_POST['total_amount']);
    $total_profit      = floatval($_POST['total_profit']);
    $items_count       = intval($_POST['items_count']);
    $customer_name     = $_POST['customer_name'];
    $customer_contact  = $_POST['customer_contact'];
    $customer_address  = $_POST['customer_address'];
    $user_id  = $currentUser['id'];
    $store_id = $userStore ? $userStore['id'] : null;
    $conn->begin_transaction();
    try {
        $transaction_number = generateTransactionNumber($conn);
        $transaction_id = $db->insert('transactions', [
            'transaction_number' => $transaction_number,
            'user_id'    => $user_id,
            'store_id'   => $store_id,
            'subtotal'   => $total_amount,
            'total_amount'  => $total_amount,
            'total_profit'  => $total_profit,
            'payment_method' => 'credit',
            'total_items'   => $items_count,
            'status'     => 'pending'
        ]);
        $device_id = isset($_SERVER['HTTP_X_DEVICE_ID']) ? $_SERVER['HTTP_X_DEVICE_ID'] : null;
        $stmt = $conn->prepare("SELECT id FROM customers WHERE name = ? AND store_id = ? AND is_deleted = 0");
        $stmt->bind_param("si", $customer_name, $store_id);
        $stmt->execute();
        $existing_customer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing_customer) {
            $customer_id = $existing_customer['id'];
            $db->update('customers', ['contact_number' => $customer_contact, 'address' => $customer_address], "id = $customer_id");
        } else {
            $ins = ['name' => $customer_name, 'contact_number' => $customer_contact, 'address' => $customer_address, 'store_id' => $store_id];
            if ($device_id) $ins['device_id'] = $device_id;
            $customer_id = $db->insert('customers', $ins);
        }
        // Calculate credit fee for display only — the cashier already includes it in total_amount
        $total_fee = 0;
        foreach ($items as $item) {
            $fee_stmt = $conn->prepare(
                "SELECT COALESCE(ccc.charge_amount, 0) AS fee
                 FROM products p
                 LEFT JOIN products parent ON parent.id = p.parent_product_id
                 LEFT JOIN product_categories pc
                       ON COALESCE(parent.category_id, p.category_id) = pc.id AND pc.is_deleted = 0
                 LEFT JOIN credit_charge_categories ccc
                       ON ccc.category_name = pc.category_name AND ccc.is_deleted = 0
                 WHERE p.id = ? AND p.is_deleted = 0 LIMIT 1"
            );
            $fee_stmt->bind_param("i", $item['id']);
            $fee_stmt->execute();
            $fee_row = $fee_stmt->get_result()->fetch_assoc();
            $fee_stmt->close();
            $item_fee = $fee_row ? (float)$fee_row['fee'] : 0;
            $total_fee += $item_fee * $item['quantity'];
        }

        $credit_data = [
            'transaction_id'   => $transaction_id,
            'customer_id'      => $customer_id,
            'customer_name'    => $customer_name,
            'customer_contact' => $customer_contact,
            'customer_address' => $customer_address,
            'total_amount'     => $total_amount,
            'additional_charge' => $total_fee,
            'amount_paid'      => 0,
            'amount_due'       => $total_amount,
            'status'           => 'unpaid',
            'created_by'       => $user_id,
            'store_id'         => $store_id
        ];
        if ($device_id) $credit_data['device_id'] = $device_id;
        $credit_id = $db->insert('credits', $credit_data);
        foreach ($items as $item) {
            $profit = ($item['price'] - $item['purchase_price']) * $item['quantity'];
            $db->insert('transaction_items', [
                'transaction_id' => $transaction_id,
                'product_id'     => $item['id'],
                'product_name'   => $item['name'],
                'quantity'       => $item['quantity'],
                'price'          => $item['price'],
                'purchase_price' => $item['purchase_price'],
                'subtotal'       => $item['subtotal'],
                'profit'         => $profit
            ]);
            try {
                if (isIndividualProduct($item['id'])) {
                    $stock_result = deductIndividualStock($item['id'], $item['quantity'], $store_id);
                    if ($stock_result['packs_opened'] > 0)
                        logActivity('product', "Converted {$stock_result['packs_opened']} pack(s) for credit sale", $user_id, $store_id, ['product_id' => $item['id'], 'product_name' => $item['name'], 'packs_opened' => $stock_result['packs_opened'], 'credit_id' => $credit_id]);
                } else {
                    deductPackStock($item['id'], $item['quantity'], $store_id);
                }
            } catch (Exception $e) {
                throw new Exception("Stock deduction failed for {$item['name']}: " . $e->getMessage());
            }
            logActivity('product', "Stock updated for {$item['name']} (credit sale)", $user_id, $store_id, ['product_id' => $item['id'], 'product_name' => $item['name'], 'quantity_change' => -$item['quantity'], 'credit_id' => $credit_id]);
        }
        logActivity('credit', "Credit transaction created: $transaction_number", $user_id, $store_id, ['credit_id' => $credit_id, 'transaction_number' => $transaction_number, 'customer' => $customer_name, 'amount' => $total_amount]);
        $conn->commit();
        if (function_exists('flushStockPushes')) flushStockPushes();
        echo json_encode(['success' => true, 'transaction_id' => $transaction_id, 'credit_id' => $credit_id, 'transaction_number' => $transaction_number]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── POST: Re-edit transaction ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reedit_transaction') {
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    $original_transaction_id = intval($_POST['original_transaction_id']);
    $original_items    = json_decode($_POST['original_items'], true);
    $new_items         = json_decode($_POST['new_items'], true);
    $total_amount      = floatval($_POST['total_amount']);
    $total_profit      = floatval($_POST['total_profit']);
    $items_count       = intval($_POST['items_count']);
    $customer_name     = $_POST['customer_name'];
    $customer_contact  = $_POST['customer_contact'];
    $customer_address  = $_POST['customer_address'];
    $user_id  = $currentUser['id'];
    $store_id = $userStore ? $userStore['id'] : null;
    $conn->begin_transaction();
    try {
        foreach ($original_items as $item) {
            if (isIndividualProduct($item['product_id'])) {
                restoreIndividualStock($item['product_id'], $item['quantity'], $store_id);
            } else {
                if ($store_id) {
                    $stmt = $conn->prepare("UPDATE store_prices SET stock = stock + ? WHERE product_id = ? AND store_id = ?");
                    $stmt->bind_param("iii", $item['quantity'], $item['product_id'], $store_id);
                } else {
                    $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                    $stmt->bind_param("ii", $item['quantity'], $item['product_id']);
                }
                $stmt->execute(); $stmt->close();
            }
        }
        $db->update('transactions', ['status' => 'edited'], "id = $original_transaction_id");
        $transaction_number = generateTransactionNumber($conn);
        $new_transaction_id = $db->insert('transactions', [
            'parent_transaction_id' => $original_transaction_id,
            'transaction_number'    => $transaction_number,
            'user_id'    => $user_id,
            'store_id'   => $store_id,
            'subtotal'   => $total_amount,
            'total_amount'  => $total_amount,
            'total_profit'  => $total_profit,
            'total_items'   => $items_count,
            'payment_method' => 'credit',
            'status'     => 'pending'
        ]);
        $stmt = $conn->prepare("SELECT id FROM customers WHERE name = ? AND store_id = ? AND is_deleted = 0");
        $stmt->bind_param("si", $customer_name, $store_id);
        $stmt->execute();
        $existing_customer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing_customer) {
            $customer_id = $existing_customer['id'];
            $db->update('customers', ['contact_number' => $customer_contact, 'address' => $customer_address], "id = $customer_id");
        } else {
            $customer_id = $db->insert('customers', ['name' => $customer_name, 'contact_number' => $customer_contact, 'address' => $customer_address, 'store_id' => $store_id]);
        }
        // Calculate credit fee for re-edited items
        $total_fee = 0;
        foreach ($new_items as $item) {
            $fee_stmt = $conn->prepare(
                "SELECT COALESCE(ccc.charge_amount, 0) AS fee
                 FROM products p
                 LEFT JOIN products parent ON parent.id = p.parent_product_id
                 LEFT JOIN product_categories pc
                       ON COALESCE(parent.category_id, p.category_id) = pc.id AND pc.is_deleted = 0
                 LEFT JOIN credit_charge_categories ccc
                       ON ccc.category_name = pc.category_name AND ccc.is_deleted = 0
                 WHERE p.id = ? AND p.is_deleted = 0 LIMIT 1"
            );
            $fee_stmt->bind_param("i", $item['id']);
            $fee_stmt->execute();
            $fee_row = $fee_stmt->get_result()->fetch_assoc();
            $fee_stmt->close();
            $item_fee = $fee_row ? (float)$fee_row['fee'] : 0;
            $total_fee += $item_fee * $item['quantity'];
        }
        $credit_id = $db->insert('credits', [
            'transaction_id'   => $new_transaction_id,
            'customer_id'      => $customer_id,
            'customer_name'    => $customer_name,
            'customer_contact' => $customer_contact,
            'customer_address' => $customer_address,
            'total_amount'     => $total_amount,
            'additional_charge' => $total_fee,
            'amount_paid'      => 0,
            'amount_due'       => $total_amount,
            'status'           => 'unpaid',
            'created_by'       => $user_id,
            'store_id'         => $store_id
        ]);
        foreach ($new_items as $item) {
            $db->insert('transaction_items', [
                'transaction_id' => $new_transaction_id,
                'product_id'     => $item['id'],
                'product_name'   => $item['name'],
                'quantity'       => $item['quantity'],
                'price'          => $item['price'],
                'purchase_price' => $item['purchase_price'],
                'subtotal'       => $item['subtotal'],
                'profit'         => $item['profit']
            ]);
            if (isIndividualProduct($item['id'])) {
                deductIndividualStock($item['id'], $item['quantity'], $store_id);
            } else {
                deductPackStock($item['id'], $item['quantity'], $store_id);
            }
        }
        logActivity('credit', "Credit transaction re-edited: $transaction_number (parent ID: $original_transaction_id)", $user_id, $store_id, ['transaction_id' => $new_transaction_id, 'parent_transaction_id' => $original_transaction_id, 'credit_id' => $credit_id, 'customer' => $customer_name]);
        $conn->commit();
        if (function_exists('flushStockPushes')) flushStockPushes();
        echo json_encode(['success' => true, 'transaction_id' => $new_transaction_id, 'parent_transaction_id' => $original_transaction_id, 'transaction_number' => $transaction_number, 'credit_id' => $credit_id]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Credit Mode<?php echo $userStore ? ' - ' . htmlspecialchars($userStore['store_name']) : ''; ?></title>
    <link rel="stylesheet" href="/oro-store-demo/delivery/delivery_styles.css">
    <style>
        .credit-mode-indicator {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
            padding: 11px 20px;
            text-align: center;
            font-weight: 700;
            font-size: 15px;
            letter-spacing: .5px;
            flex-shrink: 0;
            border-bottom: 1px solid #1e40af;
        }
        .btn-back-credit {
            padding: 8px 16px;
            background: #2563eb;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
            white-space: nowrap;
        }
        .btn-back-credit:hover { background: #1d4ed8; }
        .receipt-header .credit-label {
            color: #2563eb;
            font-size: 11px;
            font-weight: 700;
            margin: 0;
        }
        .col-charge { color: #d69e2e; font-weight: 600; white-space: nowrap; }
        .credit-info-modal .modal-content { min-width: 460px; text-align: left; }
        .tot-row.charge-row { color: #2563eb; font-weight: 600; }
        .credit-charge-badge {
            display: inline-block;
            padding: 2px 8px;
            background: rgba(37,99,235,.10);
            color: #2563eb;
            border: 1px solid rgba(37,99,235,.25);
            border-radius: 3px;
            font-size: 11px;
            font-weight: 700;
        }
        .shortcut-bar { display:flex; flex-wrap:wrap; gap:4px; padding:6px 10px; background:#1a202c; border-bottom:2px solid #2d3748; z-index:999; justify-content:center; }
        .sc-key { display:inline-flex; align-items:center; gap:4px; font-size:10px; color:#a0aec0; background:#2d3748; padding:3px 8px; border-radius:4px; white-space:nowrap; cursor:pointer; transition:background .12s; }
        .sc-key:hover { background:#4a5568; }
        .sc-key kbd { display:inline-block; background:#4a5568; color:#e2e8f0; font-size:10px; font-weight:700; font-family:inherit; padding:1px 5px; border-radius:3px; border:1px solid #718096; min-width:16px; text-align:center; }
        .sc-key.sc-blue kbd { background:#2b6cb0; border-color:#3182ce; }
        .sc-key.sc-green kbd { background:#276749; border-color:#38a169; }
        .sc-key.sc-orange kbd { background:#9c4221; border-color:#dd6b20; }
        .sc-key.sc-purple kbd { background:#553c9a; border-color:#805ad5; }
        .sc-key.sc-blue { color:#90cdf4; }
        .sc-key.sc-green { color:#9ae6b4; }
        .sc-key.sc-orange { color:#fbd38d; }
        .sc-key.sc-purple { color:#d6bcfa; }
        .left-panel.panel-active { flex:1.6; }
        .right-panel.panel-active { flex:1.6; }
        .left-panel.panel-active .lp-topbar { background:linear-gradient(135deg,#1e3a5f,#1e40af); border-bottom-color:#3b82f6; }
        .left-panel.panel-active .lp-topbar h1 { color:#fff; }
        .left-panel.panel-active .lp-topbar .lp-user { color:#bfdbfe; }
        .left-panel.panel-active .lp-topbar .lp-user strong { color:#fff; }
        .left-panel.panel-active .lp-topbar .lp-store-badge { background:rgba(255,255,255,.15); color:#bfdbfe; }
        .right-panel.panel-active .receipt-header { background:linear-gradient(135deg,#1e3a5f,#1e40af); border-bottom-color:#3b82f6; }
        .right-panel.panel-active .receipt-header h1 { color:#fff; }
        .right-panel.panel-active .receipt-header p { color:#bfdbfe; }
        .right-panel.panel-active .receipt-header .receipt-store-name { color:#fff !important; }
    </style>
    <link rel="stylesheet" href="/oro-store-demo/core/responsive.css">
    <script src="/oro-store-demo/core/custom_alert.js"></script>
    <script src="/oro-store-demo/core/bt_print.js?v=20250627"></script>
</head>
<body>
<!-- Shortcut Bar -->
<div class="shortcut-bar">
    <a href="/oro-store-demo/cashier/cashier.php" style="background:#2563eb;color:#fff;padding:6px 16px;font-size:13px;font-weight:700;text-decoration:none;border-radius:6px;white-space:nowrap;">← Cashier</a>
    <span class="sc-key" onclick="scEsc()"><kbd>Esc</kbd> Back</span>
    <span class="sc-key" onclick="scHome()"><kbd>Home</kbd> Switch</span>
    <span class="sc-key" onclick="scDel()"><kbd>Del</kbd> Remove</span>
    <span class="sc-key" onclick="scIns()"><kbd>Ins</kbd> Edit</span>
    <span class="sc-key sc-blue" onclick="printReceipt()"><kbd>F1</kbd> Print</span>
    <span class="sc-key sc-green" onclick="window.open('/oro-store-demo/transactions/gcash.php','_blank','width=600,height=700')"><kbd>F2</kbd> GCash</span>
    <span class="sc-key sc-orange" onclick="location.href='/oro-store-demo/delivery/delivery.php'"><kbd>F3</kbd> Delivery</span>
    <span class="sc-key sc-teal" onclick="location.href='/oro-store-demo/angkat/angkat.php'"><kbd>F5</kbd> Angkat</span>
    <span class="sc-key sc-blue" onclick="window.open('/oro-store-demo/transactions/card_transaction.php','_blank','width=600,height=700')"><kbd>F7</kbd> ATM</span>
    <span class="sc-key" onclick="location.href='/oro-store-demo/delivery/delivery_details.php'"><kbd>F9</kbd> Details</span>

    <span class="sc-key" onclick="window.open('/oro-store-demo/stock/add_stock.php','_blank','width=800,height=600')"><kbd>F11</kbd> Add Stock</span>
    <span class="sc-key sc-green" onclick="processCredit()"><kbd>Enter</kbd> Process</span>
</div>
<div class="cashier-container">

    <!-- ══ LEFT PANEL ══════════════════════════════════════════════════ -->
    <div class="left-panel">

        <div class="credit-mode-indicator">💳 &nbsp; CREDIT MODE</div>

        <div class="lp-topbar">
            <h1>Product Selection</h1>
            <div class="lp-meta">
                <?php if ($userStore): ?>
                    <span class="lp-store-badge">🏪 <?php echo htmlspecialchars($userStore['store_code']); ?></span>
                <?php endif; ?>
                <div class="lp-user">
                    <strong><?php echo htmlspecialchars($currentUser['full_name']); ?></strong><br>
                    <?php echo ucfirst($currentUser['role']); ?>
                </div>
            </div>
        </div>

        <div class="search-container" style="position:relative;">
            <input type="text" id="cashier-search" placeholder="Search (Name, Brand, Category, Unit)…" autofocus autocomplete="off">
            <div id="search-tags" style="display:flex;gap:4px;flex-wrap:wrap;padding:4px 8px;"></div>
            <div id="search-suggestions" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #e2e8f0;border-radius:0 0 8px 8px;box-shadow:0 4px 12px rgba(0,0,0,.15);max-height:200px;overflow-y:auto;z-index:100;"></div>
        </div>

        <ul class="product-list-cashier" id="product-list"></ul>
    </div>

    <!-- ══ RIGHT PANEL ════════════════════════════════════════════════ -->
    <div class="right-panel">
        <div class="receipt-header">
            <h1>CREDIT RECEIPT</h1>
            <p class="credit-label">Credit Transaction</p>
            <?php if ($userStore): ?>
                <p style="font-size:11px;color:#718096;margin:2px 0 0;"><?php echo htmlspecialchars($userStore['store_name']); ?></p>
            <?php endif; ?>
        </div>

        <div id="receipt-items">
            <div class="empty-cart">
                <h3>Cart is Empty</h3>
                <p>Add products for credit sale</p>
            </div>
        </div>

        <div id="receipt-total" style="display:none;">
            <div class="receipt-totals-wrap">
                <div class="tot-row">
                    <span>Total Items:</span>
                    <span id="total-items">0</span>
                </div>
                <div class="tot-row">
                    <span>Subtotal (products):</span>
                    <span id="subtotal">₱0.00</span>
                </div>
                <div class="tot-row charge-row">
                    <span>Total Credit Charges:</span>
                    <span id="total-credit-charge">₱0.00</span>
                </div>
                <div class="tot-row grand" onclick="processCredit()" style="cursor:pointer;background:#22c55e;color:#fff;border-radius:10px;padding:14px 16px;">
                    <span style="font-weight:800;">GRAND TOTAL:</span>
                    <span class="val" id="grand-total" style="font-weight:800;">₱0.00</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ Customer Info Modal ════════════════════════════════════════ -->
    <div class="modal credit-info-modal" id="credit-info-modal">
        <div class="modal-content">
            <h2>💳 Customer Information</h2>
            <div class="delivery-form-group autocomplete-container">
                <label for="customer-name">Customer Name *</label>
                <input type="text" id="customer-name" required placeholder="Enter customer name" autocomplete="off">
                <div class="autocomplete-dropdown" id="autocomplete-dropdown"></div>
            </div>
            <div class="delivery-form-group">
                <label for="customer-contact">Contact Number *</label>
                <input type="text" id="customer-contact" required placeholder="Enter contact number">
            </div>
            <div class="delivery-form-group">
                <label for="customer-address">Address</label>
                <textarea id="customer-address" placeholder="Enter customer address (optional)"></textarea>
            </div>
            <div class="modal-buttons" style="margin-top:20px;">
                <button class="btn-print"   onclick="printReceiptFromModal()">Print (F1)</button>
                <button class="btn-confirm" onclick="completeCreditTransaction()">Complete (Enter)</button>
                <button class="btn-cancel"  onclick="closeCreditInfoModal()">Cancel (Esc)</button>
            </div>
        </div>
    </div>

    <!-- ══ Quantity Modal ════════════════════════════════════════════ -->
    <div class="modal" id="quantity-modal">
        <div class="modal-content">
            <h2 id="modal-product-name">Product Name</h2>
            <p>Available Stock: <span id="modal-stock">0</span></p>
            <input type="number" id="quantity-input" min="1" placeholder="Enter quantity">
            <div class="modal-buttons">
                <button class="btn-confirm" onclick="confirmQuantity()">Confirm (Enter)</button>
                <button class="btn-cancel"  onclick="closeQuantityModal()">Cancel (Esc)</button>
            </div>
        </div>
    </div>

    <!-- ══ Edit Quantity Modal ════════════════════════════════════════ -->
    <div class="modal" id="edit-modal">
        <div class="modal-content">
            <h2 id="edit-product-name">Edit Quantity</h2>
            <p>Current Quantity: <span id="edit-current-qty">0</span></p>
            <input type="number" id="edit-quantity-input" min="1" placeholder="Enter new quantity">
            <div class="modal-buttons">
                <button class="btn-confirm" onclick="confirmEdit()">Confirm (Enter)</button>
                <button class="btn-cancel"  onclick="closeEditModal()">Cancel (Esc)</button>
            </div>
        </div>
    </div>

</div><!-- /.cashier-container -->


<script>
    const STORE_INFO = {
        storeName:    <?php echo json_encode($userStore ? $userStore['store_name'] : 'ORO STORE'); ?>,
        storeAddress: <?php echo json_encode($userStore ? ($userStore['address'] ?? '') : ''); ?>,
        cashierName:  <?php echo json_encode($currentUser['full_name']); ?>
    };
</script>
<script src="/oro-store-demo/credit/credit_script.js"></script>

<!-- ══ Overrides — runs AFTER credit_script.js ════════════════════════════ -->
<script>
function setActivePanel(panel) {
    currentPanel = panel;
    const lp = document.querySelector('.left-panel');
    const rp = document.querySelector('.right-panel');
    if (lp && rp) {
        lp.classList.toggle('panel-active', panel === 'left');
        rp.classList.toggle('panel-active', panel === 'right');
    }
}
function scEsc() { location.href = 'cashier.php'; }
function scHome() {
    if (currentPanel === 'left' && cart.length > 0) { setActivePanel('right'); }
    else { setActivePanel('left'); document.getElementById('cashier-search').focus(); }
}
function scDel() {
    if (currentPanel === 'right') {
        const items = document.querySelectorAll('.receipt-item');
        if (items[selectedReceiptIndex]) deleteCartItem(parseInt(items[selectedReceiptIndex].dataset.index));
    }
}
function scIns() {
    if (currentPanel === 'right') {
        const items = document.querySelectorAll('.receipt-item');
        if (items[selectedReceiptIndex]) openEditModal(parseInt(items[selectedReceiptIndex].dataset.index));
    }
}
function processCredit() { openCreditInfoModal(); }

setActivePanel('left');

(function () {

    // ── Cache for credit-charge info ──────────────────────────────────────────
    const _chargeCache   = {};
    // ── Cache for half-pack info ──────────────────────────────────────────────
    const _halfPackCache = {};

    function getProductCreditInfo(productId) {
        if (_chargeCache[productId] !== undefined) return Promise.resolve(_chargeCache[productId]);
        return fetch('/oro-store-demo/credit/credit.php?action=get_product_credit_info&product_id=' + productId)
            .then(r => r.json())
            .then(d => {
                _chargeCache[productId] = { charge: d.charge || 0, category: d.category || '' };
                return _chargeCache[productId];
            })
            .catch(() => {
                _chargeCache[productId] = { charge: 0, category: '' };
                return _chargeCache[productId];
            });
    }

    function getHalfPackInfo(productId) {
        if (_halfPackCache[productId] !== undefined) return Promise.resolve(_halfPackCache[productId]);
        return fetch('/oro-store-demo/credit/credit.php?action=get_halfpack_info&product_id=' + productId)
            .then(r => r.json())
            .then(d => { _halfPackCache[productId] = d; return d; })
            .catch(() => {
                const fallback = { is_individual: false, parent_price: null, individual_pieces_per_pack: null, credit_charge: 0 };
                _halfPackCache[productId] = fallback;
                return fallback;
            });
    }

    /**
     * Given a cart item, its credit-charge info, and its half-pack info,
     * compute effective unit price and effective per-unit credit charge.
     *
     * Bulk rate rule (proportional pricing):
     *   When quantity >= pieces_per_pack / 2, use the bulk unit price:
     *     bulkUnitPrice       = parent_price / pieces_per_pack
     *     effectivePrice      = bulkUnitPrice              (per unit)
     *     effectiveCreditCharge = chargeInfo.charge         (per unit, unchanged)
     *   Line totals are always effectivePrice * quantity.
     *
     * Normal items:
     *   effectivePrice        = item.price                 (per unit)
     *   effectiveCreditCharge = chargeInfo.charge           (per unit)
     *   lineTotal             = (effectivePrice + chargePerUnit) * quantity
     */
    function computePricing(item, chargeInfo, halfPackInfo) {
        const qty = item.quantity;

        if (
            halfPackInfo.is_individual &&
            halfPackInfo.parent_price !== null &&
            halfPackInfo.individual_pieces_per_pack &&
            qty >= halfPackInfo.individual_pieces_per_pack / 2
        ) {
            // Bulk rate: proportional per-unit price derived from the parent pack
            const piecesPerPack   = halfPackInfo.individual_pieces_per_pack;
            const bulkUnitPrice   = halfPackInfo.parent_price / piecesPerPack;
            const chargePerUnit   = chargeInfo.charge;
            return {
                isBulkRate:           true,
                effectivePrice:       bulkUnitPrice,
                effectiveCreditCharge: chargePerUnit,
                lineProductTotal:     bulkUnitPrice * qty,
                lineChargeTotal:      chargePerUnit * qty,
                lineTotal:            (bulkUnitPrice + chargePerUnit) * qty
            };
        }

        // Normal pricing
        const unitPrice      = parseFloat(item.price);
        const chargePerUnit  = chargeInfo.charge;
        return {
            isBulkRate:           false,
            effectivePrice:       unitPrice,
            effectiveCreditCharge: chargePerUnit,
            lineProductTotal:     unitPrice * qty,
            lineChargeTotal:      chargePerUnit * qty,
            lineTotal:            (unitPrice + chargePerUnit) * qty
        };
    }

    function fmt(n) {
        return '₱' + parseFloat(n).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function esc(str) {
        return String(str || '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function td(content, css) {
        const el = document.createElement('td');
        el.style.cssText = 'display:table-cell!important;padding:9px 8px;vertical-align:middle;border-bottom:1px solid #e2e8f0;font-size:12px;' + (css || '');
        el.innerHTML = content;
        return el;
    }

    // ── updateReceipt override ────────────────────────────────────────────────
    window.updateReceipt = async function () {
        const wrap  = document.getElementById('receipt-items');
        const total = document.getElementById('receipt-total');
        if (!wrap || !total) return;

        if (!cart || cart.length === 0) {
            wrap.innerHTML = '<div class="empty-cart"><h3>Cart is Empty</h3><p>Add products for credit sale</p></div>';
            total.style.display = 'none';
            return;
        }

        wrap.innerHTML = '<div style="padding:16px;text-align:center;color:#a0aec0;font-size:12px;">Loading…</div>';

        // Fetch both credit-charge info AND half-pack info for every cart item in parallel
        const [chargeInfos, halfPackInfos] = await Promise.all([
            Promise.all(cart.map(item => getProductCreditInfo(item.id))),
            Promise.all(cart.map(item => getHalfPackInfo(item.id)))
        ]);

        const frag = document.createDocumentFragment();

        // Re-edit banner
        if (typeof isReEditMode !== 'undefined' && isReEditMode) {
            const banner = document.createElement('div');
            banner.style.cssText = 'background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;padding:9px 14px;font-size:11px;font-weight:700;letter-spacing:.6px;';
            banner.textContent = '✏️  RE-EDIT MODE — Transaction #' + originalTransactionId;
            frag.appendChild(banner);
        }

        const table = document.createElement('table');
        table.style.cssText = 'width:100%;border-collapse:collapse;table-layout:fixed;';

        // thead
        const thead = document.createElement('thead');
        const hRow  = document.createElement('tr');
        hRow.style.cssText = 'background:#2d3748;';
        [
            { label: 'Product',       w: '25%', align: 'left'   },
            { label: 'Qty',           w: '6%',  align: 'center' },
            { label: 'Category',      w: '13%', align: 'left'   },
            { label: 'Unit Price',    w: '14%', align: 'right'  },
            { label: 'Credit Charge', w: '14%', align: 'right'  },
            { label: 'Line Total',    w: '14%', align: 'right'  },
            { label: '',              w: '7%',  align: 'center' },
        ].forEach(h => {
            const th = document.createElement('th');
            th.style.cssText = 'display:table-cell!important;padding:8px 8px;text-align:' + h.align
                + ';color:#a0aec0;font-size:10px;text-transform:uppercase;letter-spacing:.7px;'
                + 'font-weight:600;border-bottom:2px solid #4a5568;white-space:nowrap;width:' + h.w + ';';
            th.textContent = h.label;
            hRow.appendChild(th);
        });
        thead.appendChild(hRow);
        table.appendChild(thead);

        // tbody
        const tbody = document.createElement('tbody');
        let totItems = 0, totSubtotal = 0, totCharges = 0, totGrand = 0;

        cart.forEach((item, index) => {
            const chargeInfo   = chargeInfos[index];
            const halfPackInfo = halfPackInfos[index];
            const pricing      = computePricing(item, chargeInfo, halfPackInfo);
            const category     = chargeInfo.category || '—';
            const qty          = item.quantity;
            const unitPrice    = parseFloat(item.price);

            totItems    += qty;
            totSubtotal += pricing.lineProductTotal;
            totCharges  += pricing.lineChargeTotal;
            totGrand    += pricing.lineTotal;

            const tr = document.createElement('tr');
            tr.className     = 'credit-row';
            tr.dataset.index = index;
            tr.style.cssText = 'cursor:pointer;border-bottom:1px solid #e2e8f0;transition:background .1s;';

            tr.addEventListener('mouseenter', () => { if (!tr.classList.contains('selected')) tr.style.background = '#f0f7ff'; });
            tr.addEventListener('mouseleave', () => { if (!tr.classList.contains('selected')) tr.style.background = ''; });
            tr.addEventListener('click', (e) => {
                if (e.target.closest('button')) return;
                setActivePanel('right');
                selectedReceiptIndex = index;
                updateReceiptSelection();
            });
            tr.ontouchstart = function(){ startLongPress(index); };
            tr.ontouchend = function(){ cancelLongPress(); };
            tr.ontouchmove = function(){ cancelLongPress(); };
            tr.style.cssText += '-webkit-user-select:none;user-select:none;';

            // Product name cell
            tr.appendChild(td(
                `<div style="font-weight:600;color:#2d3748;word-break:break-word;white-space:normal;line-height:1.3;">${esc(item.name)}</div>
                 <div style="font-weight:700;font-size:10px;color:#718096;margin-top:2px;">${fmt(unitPrice)}</div>`,
                'word-break:break-word;'
            ));

            // Qty
            tr.appendChild(td(qty, 'text-align:center;font-weight:700;color:#4a5568;'));

            // Category
            tr.appendChild(td(esc(category), 'color:#553c9a;font-size:11px;'));

            // ── Unit Price column ─────────────────────────────────────────────
            if (pricing.isBulkRate) {
                tr.appendChild(td(
                    `<div style="text-align:right;">
                        <div style="font-style:italic;font-size:10px;color:#a0aec0;white-space:nowrap;">
                            ${fmt(pricing.effectivePrice)} &times; ${qty} =
                        </div>
                        <div style="font-weight:700;color:#7c3aed;white-space:nowrap;">
                            ${fmt(pricing.lineProductTotal)}
                            <span style="font-size:9px;background:#ede9fe;color:#7c3aed;border-radius:3px;padding:1px 4px;">BULK</span>
                        </div>
                    </div>`,
                    'white-space:nowrap;'
                ));
            } else {
                tr.appendChild(td(
                    `<div style="text-align:right;">
                        <div style="font-style:italic;font-size:10px;color:#a0aec0;white-space:nowrap;">${fmt(unitPrice)} &times; ${qty} =</div>
                        <div style="font-weight:700;color:#2b6cb0;white-space:nowrap;">${fmt(pricing.lineProductTotal)}</div>
                    </div>`,
                    'white-space:nowrap;'
                ));
            }

            // ── Credit Charge column ──────────────────────────────────────────
            if (pricing.isBulkRate) {
                tr.appendChild(td(
                    pricing.effectiveCreditCharge > 0
                        ? `<div style="text-align:right;">
                               <div style="font-style:italic;font-size:10px;color:#a0aec0;white-space:nowrap;">${fmt(pricing.effectiveCreditCharge)} &times; ${qty} =</div>
                               <div style="font-weight:700;color:#2563eb;white-space:nowrap;">${fmt(pricing.lineChargeTotal)}</div>
                           </div>`
                        : '<div style="text-align:right;color:#cbd5e0;">—</div>',
                    'white-space:nowrap;'
                ));
            } else {
                tr.appendChild(td(
                    chargeInfo.charge > 0
                        ? `<div style="text-align:right;">
                               <div style="font-style:italic;font-size:10px;color:#a0aec0;white-space:nowrap;">${fmt(chargeInfo.charge)} &times; ${qty} =</div>
                               <div style="font-weight:700;color:#2563eb;white-space:nowrap;">${fmt(pricing.lineChargeTotal)}</div>
                           </div>`
                        : '<div style="text-align:right;color:#cbd5e0;">—</div>',
                    'white-space:nowrap;'
                ));
            }

            // ── Line Total column ─────────────────────────────────────────────
            if (pricing.isBulkRate) {
                tr.appendChild(td(
                    `<div style="text-align:right;">
                        <div style="font-style:italic;font-size:10px;color:#a0aec0;white-space:nowrap;">
                            ${fmt(pricing.lineProductTotal)} + ${fmt(pricing.lineChargeTotal)} =
                        </div>
                        <div style="font-weight:700;color:#2563eb;white-space:nowrap;">${fmt(pricing.lineTotal)}</div>
                    </div>`,
                    'white-space:nowrap;'
                ));
            } else {
                tr.appendChild(td(
                    `<div style="text-align:right;">
                        <div style="font-style:italic;font-size:10px;color:#a0aec0;white-space:nowrap;">
                            ${fmt(pricing.lineProductTotal)} + ${fmt(pricing.lineChargeTotal)} =
                        </div>
                        <div style="font-weight:700;color:#276749;white-space:nowrap;">${fmt(pricing.lineTotal)}</div>
                    </div>`,
                    'white-space:nowrap;'
                ));
            }

            // Delete button
            const delTd  = document.createElement('td');
            delTd.style.cssText = 'display:table-cell!important;padding:9px 8px;vertical-align:middle;text-align:center;border-bottom:1px solid #e2e8f0;';
            const delBtn = document.createElement('button');
            delBtn.textContent = '✕';
            delBtn.style.cssText = 'background:#fed7d7;color:#c53030;border:none;border-radius:3px;padding:3px 9px;cursor:pointer;font-size:11px;font-weight:700;transition:background .12s;';
            delBtn.addEventListener('mouseenter', () => delBtn.style.background = '#feb2b2');
            delBtn.addEventListener('mouseleave', () => delBtn.style.background = '#fed7d7');
            delBtn.addEventListener('click', (e) => { e.stopPropagation(); deleteCartItem(index); });
            delTd.appendChild(delBtn);
            tr.appendChild(delTd);

            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        frag.appendChild(table);

        wrap.innerHTML = '';
        wrap.appendChild(frag);

        // Totals
        total.style.display = 'block';
        document.getElementById('total-items').textContent         = totItems;
        document.getElementById('subtotal').textContent            = fmt(totSubtotal);
        document.getElementById('total-credit-charge').textContent = fmt(totCharges);
        document.getElementById('grand-total').textContent         = fmt(totGrand);

        if (typeof currentPanel !== 'undefined' && currentPanel === 'right') {
            updateReceiptSelection();
        }
    };

    // ── updateReceiptSelection override ──────────────────────────────────────
    window.updateReceiptSelection = function () {
        const rows = document.querySelectorAll('.credit-row');
        rows.forEach((row, i) => {
            const cells = row.querySelectorAll('td');
            if (i === selectedReceiptIndex) {
                row.classList.add('selected');
                row.style.background = '#dbeafe';
                cells.forEach(c => c.style.background = '#dbeafe');
                row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } else {
                row.classList.remove('selected');
                row.style.background = '';
                cells.forEach(c => c.style.background = '');
            }
        });
    };

    // ── calculateTotal override — half-pack aware ─────────────────────────────
    // Returns a Promise<number> so completeCreditTransaction must await it.
    window.calculateTotal = async function () {
        const [chargeInfos, halfPackInfos] = await Promise.all([
            Promise.all(cart.map(item => getProductCreditInfo(item.id))),
            Promise.all(cart.map(item => getHalfPackInfo(item.id)))
        ]);
        return cart.reduce((sum, item, index) => {
            return sum + computePricing(item, chargeInfos[index], halfPackInfos[index]).lineTotal;
        }, 0);
    };

    // ── Keyboard capture for right-panel row navigation ───────────────────────
    document.addEventListener('keydown', function(e) {
        if (typeof currentPanel === 'undefined' || currentPanel !== 'right') return;
        const anyModalOpen =
            document.getElementById('credit-info-modal')?.classList.contains('active') ||
            document.getElementById('quantity-modal')?.classList.contains('active') ||
            document.getElementById('edit-modal')?.classList.contains('active');
        if (anyModalOpen) return;

        const rows = document.querySelectorAll('.credit-row');
        if (!rows.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault(); e.stopImmediatePropagation();
            selectedReceiptIndex = Math.min(selectedReceiptIndex + 1, rows.length - 1);
            updateReceiptSelection();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault(); e.stopImmediatePropagation();
            selectedReceiptIndex = Math.max(selectedReceiptIndex - 1, 0);
            updateReceiptSelection();
        } else if (e.key === 'Delete') {
            e.preventDefault(); e.stopImmediatePropagation();
            if (rows[selectedReceiptIndex]) deleteCartItem(parseInt(rows[selectedReceiptIndex].dataset.index));
        } else if (e.key === 'Insert') {
            e.preventDefault(); e.stopImmediatePropagation();
            if (rows[selectedReceiptIndex]) openEditModal(parseInt(rows[selectedReceiptIndex].dataset.index));
        }
    }, true /* capture phase */);

    // ── Product click handler (event delegation for dynamically loaded products)
    document.getElementById('product-list').addEventListener('click', function(e) {
        const item = e.target.closest('.product-item-cashier');
        if (!item) return;
        const visibleItems = Array.from(document.querySelectorAll('.product-item-cashier')).filter(i => i.style.display !== 'none');
        const idx = visibleItems.indexOf(item);
        if (idx >= 0) {
            selectedProductIndex = idx;
            setActivePanel('left');
            updateProductSelection();
        }
    });

})();
</script>
<script>
var _lastClickTime=0,_lastClickId='',_lpTimer=null;
function handleProductTap(el){var now=Date.now(),id=el.dataset.id;if(now-_lastClickTime<400&&_lastClickId===id){simulateKey('Enter');_lastClickTime=0;_lastClickId='';}else{_lastClickTime=now;_lastClickId=id;}}
function startLongPress(idx){_lpTimer=setTimeout(function(){selectedReceiptIndex=parseInt(idx);openEditModal(parseInt(idx));},500);}
function cancelLongPress(){if(_lpTimer){clearTimeout(_lpTimer);_lpTimer=null;}}
function simulateKey(key){document.dispatchEvent(new KeyboardEvent('keydown',{key:key,bubbles:true}));}
</script>
<script src="/oro-store-demo/core/search_tags.js"></script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Credit Transaction', array (
  'How It Works' => 
  array (
    0 => 'Create a credit sale — customer pays later',
    1 => 'Select products and quantities like normal cashier',
    2 => 'Enter customer name and contact number',
    3 => 'Credit charge per category auto-applied',
    4 => 'Transaction saved as pending credit',
    5 => 'Receipt printable via RawBT thermal printer',
  ),
));
?>
</body>
</html>