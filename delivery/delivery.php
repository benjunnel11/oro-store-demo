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
    $query = "SELECT DISTINCT recipient_name, recipient_address
              FROM deliveries WHERE recipient_name LIKE ? AND is_deleted = 0";
    if ($store_id) $query .= " AND store_id = ?";
    $query .= " ORDER BY created_at DESC LIMIT 10";
    $stmt = $conn->prepare($query);
    $sp = "%{$search}%";
    if ($store_id) $stmt->bind_param("si", $sp, $store_id);
    else           $stmt->bind_param("s",  $sp);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close(); $conn->close(); exit;
}

// ─── AJAX: Get product info with category + delivery fee ──────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_product_delivery_info') {
    header('Content-Type: application/json');
    $product_id = intval($_GET['product_id'] ?? 0);
    if (!$product_id) { echo json_encode(['fee' => 0, 'category' => '']); $conn->close(); exit; }
    $stmt = $conn->prepare(
        "SELECT pc.category_name,
                COALESCE(dfc.charge_amount, 0) AS fee_amount
         FROM products p
         LEFT JOIN product_categories pc  ON p.category_id = pc.id AND pc.is_deleted = 0
         LEFT JOIN delivery_charge_categories dfc
               ON dfc.category_name = pc.category_name AND dfc.is_deleted = 0
         WHERE p.id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    echo json_encode([
        'fee'      => $row ? (float)($row['fee_amount'] ?? 0) : 0,
        'category' => $row ? ($row['category_name'] ?? '') : ''
    ]);
    $conn->close(); exit;
}

// ─── AJAX: Get half-pack info ─────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_halfpack_info') {
    header('Content-Type: application/json');
    $product_id = intval($_GET['product_id'] ?? 0);
    if (!$product_id) {
        echo json_encode([
            'is_individual'              => false,
            'parent_price'               => null,
            'individual_pieces_per_pack' => null,
            'delivery_fee'               => 0
        ]);
        $conn->close(); exit;
    }

    // Join to parent product for price/pieces_per_pack,
    // then look up delivery fee via the PARENT's category.
    $stmt = $conn->prepare(
        "SELECT
            p.parent_product_id,
            parent.price                        AS parent_price,
            parent.individual_pieces_per_pack,
            pc.category_name,
            COALESCE(dfc.charge_amount, 0)         AS delivery_fee
         FROM products p
         LEFT JOIN products parent
               ON parent.id = p.parent_product_id
         LEFT JOIN product_categories pc
               ON parent.category_id = pc.id AND pc.is_deleted = 0
         LEFT JOIN delivery_charge_categories dfc
               ON dfc.category_name = pc.category_name AND dfc.is_deleted = 0
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
            'delivery_fee'               => 0
        ]);
    } else {
        echo json_encode([
            'is_individual'              => true,
            'parent_price'               => (float)$row['parent_price'],
            'individual_pieces_per_pack' => (int)$row['individual_pieces_per_pack'],
            'delivery_fee'               => (float)$row['delivery_fee']
        ]);
    }
    $conn->close(); exit;
}

// ─── POST: Complete delivery ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_delivery') {
    header('Content-Type: application/json');
    $items             = json_decode($_POST['items'], true);
    $total_amount      = floatval($_POST['total_amount']);
    $total_profit      = floatval($_POST['total_profit']);
    $items_count       = intval($_POST['items_count']);
    $recipient_name    = $_POST['recipient_name'];
    $recipient_address = $_POST['recipient_address'];
    $user_id  = $currentUser['id'];
    $store_id = $userStore ? $userStore['id'] : null;
    $conn->begin_transaction();
    try {
        // Calculate delivery fee from delivery_charge_categories
        $total_fee = 0;
        foreach ($items as $item) {
            $fee_stmt = $conn->prepare(
                "SELECT COALESCE(dcc.charge_amount, 0) AS fee
                 FROM products p
                 LEFT JOIN products parent ON parent.id = p.parent_product_id
                 LEFT JOIN product_categories pc ON COALESCE(parent.category_id, p.category_id) = pc.id AND pc.is_deleted = 0
                 LEFT JOIN delivery_charge_categories dcc ON dcc.category_name = pc.category_name AND dcc.is_deleted = 0
                 WHERE p.id = ? AND p.is_deleted = 0 LIMIT 1"
            );
            $fee_stmt->bind_param("i", $item['id']);
            $fee_stmt->execute();
            $fee_row = $fee_stmt->get_result()->fetch_assoc();
            $fee_stmt->close();
            $total_fee += ($fee_row ? (float)$fee_row['fee'] : 0) * $item['quantity'];
        }

        $transaction_number = generateTransactionNumber($conn);
        $transaction_id = $db->insert('transactions', [
            'transaction_number' => $transaction_number,
            'user_id'    => $user_id,
            'store_id'   => $store_id,
            'subtotal'   => $total_amount,
            'total_amount'  => $total_amount,
            'total_profit'  => $total_profit,
            'payment_method' => 'delivery',
            'total_items'   => $items_count,
            'status'     => 'pending'
        ]);
        $delivery_id = $db->insert('deliveries', [
            'transaction_id'    => $transaction_id,
            'recipient_name'    => $recipient_name,
            'recipient_address' => $recipient_address,
            'delivery_fee'      => $total_fee,
            'status'     => 'pending',
            'created_by' => $user_id,
            'store_id'   => $store_id
        ]);
        foreach ($items as $item) {
            $profit  = ($item['price'] - $item['purchase_price']) * $item['quantity'];
            $item_id = $db->insert('transaction_items', [
                'transaction_id' => $transaction_id,
                'product_id'   => $item['id'],
                'product_name' => $item['name'],
                'quantity'     => $item['quantity'],
                'price'        => $item['price'],
                'purchase_price' => $item['purchase_price'],
                'subtotal'     => $item['subtotal'],
                'profit'       => $profit
            ]);
            $db->insert('delivery_items', [
                'delivery_id'        => $delivery_id,
                'transaction_item_id' => $item_id,
                'product_id'         => $item['id'],
                'product_name'       => $item['name'],
                'quantity_ordered'   => $item['quantity'],
                'quantity_delivered' => $item['quantity'],
                'quantity_lacking'   => 0,
                'status'             => 'pending'
            ]);
            try {
                if (isIndividualProduct($item['id'])) {
                    $stock_result = deductIndividualStock($item['id'], $item['quantity'], $store_id);
                    if ($stock_result['packs_opened'] > 0)
                        logActivity('product', "Converted {$stock_result['packs_opened']} pack(s) for delivery", $user_id, $store_id, ['product_id' => $item['id'], 'product_name' => $item['name'], 'packs_opened' => $stock_result['packs_opened'], 'delivery_id' => $delivery_id]);
                } else {
                    deductPackStock($item['id'], $item['quantity'], $store_id);
                }
            } catch (Exception $e) {
                throw new Exception("Stock deduction failed for {$item['name']}: " . $e->getMessage());
            }
            logActivity('product', "Stock updated for {$item['name']} (delivery)", $user_id, $store_id, ['product_id' => $item['id'], 'product_name' => $item['name'], 'quantity_change' => -$item['quantity'], 'delivery_id' => $delivery_id]);
        }
        logActivity('delivery', "Delivery created: $transaction_number", $user_id, $store_id, ['delivery_id' => $delivery_id, 'transaction_number' => $transaction_number, 'recipient' => $recipient_name, 'amount' => $total_amount]);
        $conn->commit();
        if (function_exists('flushStockPushes')) flushStockPushes();
        echo json_encode(['success' => true, 'transaction_id' => $transaction_id, 'delivery_id' => $delivery_id, 'transaction_number' => $transaction_number]);
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
    $original_items  = json_decode($_POST['original_items'], true);
    $new_items       = json_decode($_POST['new_items'], true);
    $total_amount    = floatval($_POST['total_amount']);
    $total_profit    = floatval($_POST['total_profit']);
    $items_count     = intval($_POST['items_count']);
    $recipient_name    = $_POST['recipient_name'];
    $recipient_address = $_POST['recipient_address'];
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
            'payment_method' => 'delivery',
            'status'     => 'pending'
        ]);
        $delivery_id = $db->insert('deliveries', [
            'transaction_id'    => $new_transaction_id,
            'recipient_name'    => $recipient_name,
            'recipient_address' => $recipient_address,
            'status'     => 'pending',
            'created_by' => $user_id,
            'store_id'   => $store_id
        ]);
        foreach ($new_items as $item) {
            $item_id = $db->insert('transaction_items', [
                'transaction_id' => $new_transaction_id,
                'product_id'   => $item['id'],
                'product_name' => $item['name'],
                'quantity'     => $item['quantity'],
                'price'        => $item['price'],
                'purchase_price' => $item['purchase_price'],
                'subtotal'     => $item['subtotal'],
                'profit'       => $item['profit']
            ]);
            $db->insert('delivery_items', [
                'delivery_id'        => $delivery_id,
                'transaction_item_id' => $item_id,
                'product_id'         => $item['id'],
                'product_name'       => $item['name'],
                'quantity_ordered'   => $item['quantity'],
                'quantity_delivered' => $item['quantity'],
                'quantity_lacking'   => 0,
                'status'             => 'pending'
            ]);
            if (isIndividualProduct($item['id'])) {
                deductIndividualStock($item['id'], $item['quantity'], $store_id);
            } else {
                deductPackStock($item['id'], $item['quantity'], $store_id);
            }
        }
        logActivity('delivery', "Delivery transaction re-edited: $transaction_number (parent ID: $original_transaction_id)", $user_id, $store_id, ['transaction_id' => $new_transaction_id, 'parent_transaction_id' => $original_transaction_id, 'delivery_id' => $delivery_id, 'recipient' => $recipient_name]);
        $conn->commit();
        if (function_exists('flushStockPushes')) flushStockPushes();
        echo json_encode(['success' => true, 'transaction_id' => $new_transaction_id, 'parent_transaction_id' => $original_transaction_id, 'transaction_number' => $transaction_number, 'delivery_id' => $delivery_id]);
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
    <title>Delivery Mode<?php echo $userStore ? ' - ' . htmlspecialchars($userStore['store_name']) : ''; ?></title>
    <link rel="stylesheet" href="/oro-store/delivery/delivery_styles.css">
    <style>
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
.left-panel, .right-panel { transition: flex .25s ease; }
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
    <link rel="stylesheet" href="/oro-store/core/responsive.css">
    <script src="/oro-store/core/custom_alert.js"></script>
    <script src="/oro-store/core/bt_print.js?v=20250627"></script>
</head>
<body>
<!-- Shortcut Bar -->
<div class="shortcut-bar">
    <a href="/oro-store/cashier/cashier.php" style="background:#2563eb;color:#fff;padding:6px 16px;font-size:13px;font-weight:700;text-decoration:none;border-radius:6px;white-space:nowrap;">← Cashier</a>
    <span class="sc-key" onclick="scEsc()"><kbd>Esc</kbd> Back</span>
    <span class="sc-key" onclick="scHome()"><kbd>Home</kbd> Switch</span>
    <span class="sc-key" onclick="scDel()"><kbd>Del</kbd> Remove</span>
    <span class="sc-key" onclick="scIns()"><kbd>Ins</kbd> Edit</span>
    <span class="sc-key sc-blue" onclick="printReceipt()"><kbd>F1</kbd> Print</span>
    <span class="sc-key sc-green" onclick="window.open('/oro-store/transactions/gcash.php','_blank','width=600,height=700')"><kbd>F2</kbd> GCash</span>
    <span class="sc-key sc-purple" onclick="location.href='/oro-store/credit/credit.php'"><kbd>F4</kbd> Credit</span>
    <span class="sc-key sc-teal" onclick="location.href='/oro-store/angkat/angkat.php'"><kbd>F5</kbd> Angkat</span>
    <span class="sc-key sc-blue" onclick="window.open('/oro-store/transactions/card_transaction.php','_blank','width=600,height=700')"><kbd>F7</kbd> ATM</span>
    <span class="sc-key" onclick="location.href='/oro-store/delivery/delivery_details.php'"><kbd>F9</kbd> Details</span>

    <span class="sc-key" onclick="window.open('/oro-store/stock/add_stock.php','_blank','width=800,height=600')"><kbd>F11</kbd> Add Stock</span>
    <span class="sc-key sc-green" onclick="openDeliveryInfoModal()"><kbd>Enter</kbd> Process</span>
</div>
<div class="cashier-container">

    <!-- ══ LEFT PANEL ══════════════════════════════════════════════════ -->
    <div class="left-panel">
        <div class="delivery-mode-indicator">🚚 &nbsp; DELIVERY MODE</div>
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
            <h1>DELIVERY RECEIPT</h1>
            <p>Delivery Transaction</p>
        </div>
        <div id="receipt-items">
            <div class="empty-cart">
                <h3>Cart is Empty</h3>
                <p>Add products for delivery</p>
            </div>
        </div>
        <div id="receipt-total" style="display:none;">
            <div class="receipt-totals-wrap">
                <div class="tot-row"><span>Total Items:</span><span id="total-items">0</span></div>
                <div class="tot-row"><span>Subtotal (products):</span><span id="subtotal">₱0.00</span></div>
                <div class="tot-row fee-row"><span>Total Delivery Fees:</span><span id="total-delivery-fee">₱0.00</span></div>
                <div class="tot-row grand" onclick="openDeliveryInfoModal()" style="cursor:pointer;background:#22c55e;color:#fff;border-radius:10px;padding:14px 16px;"><span style="font-weight:800;">GRAND TOTAL:</span><span class="val" id="grand-total" style="font-weight:800;">₱0.00</span></div>
            </div>
        </div>
    </div>

    <!-- Delivery Info Modal -->
    <div class="modal delivery-info-modal" id="delivery-info-modal">
        <div class="modal-content">
            <h2>🚚 Delivery Information</h2>
            <form id="delivery-info-form" onsubmit="return false;">
                <div class="delivery-form-group autocomplete-container">
                    <label for="recipient-name">Recipient Name *</label>
                    <input type="text" id="recipient-name" required placeholder="Enter recipient name" autocomplete="off">
                    <div class="autocomplete-dropdown" id="autocomplete-dropdown"></div>
                </div>
                <div class="delivery-form-group">
                    <label for="recipient-address">Delivery Address *</label>
                    <textarea id="recipient-address" required placeholder="Enter complete delivery address"></textarea>
                </div>
            </form>
            <div class="modal-buttons" style="margin-top:20px;">
                <button class="btn-print"   onclick="printReceiptFromModal()">Print (F1)</button>
                <button class="btn-confirm" onclick="completeDeliveryTransaction()">Complete (Enter)</button>
                <button class="btn-cancel"  onclick="closeDeliveryInfoModal()">Cancel (Esc)</button>
            </div>
        </div>
    </div>

    <!-- Quantity Modal -->
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

    <!-- Edit Quantity Modal -->
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
<script src="/oro-store/delivery/delivery_script.js"></script>

<!-- ══ Panel helpers + shortcut bar handlers ══════════════════════════════ -->
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

// Product click handler (event delegation for dynamically loaded products)
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

document.addEventListener('DOMContentLoaded', function() {
    setActivePanel('left');
});
</script>

<!-- ══ Overrides — must run AFTER delivery_script.js ══════════════════════ -->
<script>
(function () {

    // ── Cache for delivery-fee info ───────────────────────────────────────────
    const _feeCache     = {};
    // ── Cache for half-pack info ──────────────────────────────────────────────
    const _halfPackCache = {};

    function getProductInfo(productId) {
        if (_feeCache[productId] !== undefined) return Promise.resolve(_feeCache[productId]);
        return fetch('/oro-store/delivery/delivery.php?action=get_product_delivery_info&product_id=' + productId)
            .then(r => r.json())
            .then(d => {
                _feeCache[productId] = { fee: d.fee || 0, category: d.category || '' };
                return _feeCache[productId];
            })
            .catch(() => {
                _feeCache[productId] = { fee: 0, category: '' };
                return _feeCache[productId];
            });
    }

    function getHalfPackInfo(productId) {
        if (_halfPackCache[productId] !== undefined) return Promise.resolve(_halfPackCache[productId]);
        return fetch('/oro-store/delivery/delivery.php?action=get_halfpack_info&product_id=' + productId)
            .then(r => r.json())
            .then(d => { _halfPackCache[productId] = d; return d; })
            .catch(() => {
                const fallback = { is_individual: false, parent_price: null, individual_pieces_per_pack: null, delivery_fee: 0 };
                _halfPackCache[productId] = fallback;
                return fallback;
            });
    }

    /**
     * Compute pricing for one cart item.
     *
     * Bulk rate (qty >= pieces_per_pack / 2):
     *   bulkUnitPrice = parent_price / pieces_per_pack   ← proportional per-unit rate
     *   bulkFeePerUnit = delivery_fee / pieces_per_pack
     *   lineTotal = (bulkUnitPrice + bulkFeePerUnit) * qty
     *
     * Normal:
     *   lineTotal = (item.price + fee) * qty
     */
    function computePricing(item, feeInfo, halfPackInfo) {
        const qty = item.quantity;

        if (
            halfPackInfo.is_individual &&
            halfPackInfo.parent_price !== null &&
            halfPackInfo.individual_pieces_per_pack &&
            qty >= halfPackInfo.individual_pieces_per_pack / 2
        ) {
            const piecesPerPack  = halfPackInfo.individual_pieces_per_pack;
            const bulkUnitPrice  = halfPackInfo.parent_price / piecesPerPack;
            const bulkFeePerUnit = halfPackInfo.delivery_fee / piecesPerPack;
            return {
                isBulkRate:          true,
                effectiveUnitPrice:  bulkUnitPrice,
                effectiveFee:        bulkFeePerUnit,
                lineProductTotal:    bulkUnitPrice * qty,
                lineFeeTotal:        bulkFeePerUnit * qty,
                lineTotal:           (bulkUnitPrice + bulkFeePerUnit) * qty
            };
        }

        const unitPrice = parseFloat(item.price);
        const fee       = feeInfo.fee;
        return {
            isBulkRate:          false,
            effectiveUnitPrice:  unitPrice,
            effectiveFee:        fee,
            lineProductTotal:    unitPrice * qty,
            lineFeeTotal:        fee * qty,
            lineTotal:           (unitPrice + fee) * qty
        };
    }

    function fmt(n) {
        return '₱' + parseFloat(n).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function esc(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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
            wrap.innerHTML = '<div class="empty-cart"><h3>Cart is Empty</h3><p>Add products for delivery</p></div>';
            total.style.display = 'none';
            return;
        }

        wrap.innerHTML = '<div style="padding:16px;text-align:center;color:#a0aec0;font-size:12px;">Loading…</div>';

        // Fetch both fee info AND half-pack info in parallel
        const [feeInfos, halfPackInfos] = await Promise.all([
            Promise.all(cart.map(item => getProductInfo(item.id))),
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
            { label: 'Product',    w: '25%', align: 'left'   },
            { label: 'Qty',        w: '6%',  align: 'center' },
            { label: 'Category',   w: '13%', align: 'left'   },
            { label: 'Unit Price', w: '14%', align: 'right'  },
            { label: 'Del. Fee',   w: '13%', align: 'right'  },
            { label: 'Line Total', w: '14%', align: 'right'  },
            { label: '',           w: '8%',  align: 'center' },
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
        let totItems = 0, totSubtotal = 0, totFees = 0, totGrand = 0;

        cart.forEach((item, index) => {
            const feeInfo      = feeInfos[index];
            const halfPackInfo = halfPackInfos[index];
            const pricing      = computePricing(item, feeInfo, halfPackInfo);
            const category     = feeInfo.category || '—';
            const qty          = item.quantity;
            const unitPrice    = parseFloat(item.price);

            totItems    += qty;
            totSubtotal += pricing.lineProductTotal;
            totFees     += pricing.lineFeeTotal;
            totGrand    += pricing.lineTotal;

            const tr = document.createElement('tr');
            tr.className     = 'delivery-row';
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

            // Product name
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
                            ${fmt(pricing.effectiveUnitPrice)} &times; ${qty} =
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

            // ── Delivery Fee column ───────────────────────────────────────────
            if (pricing.isBulkRate) {
                tr.appendChild(td(
                    pricing.effectiveFee > 0
                        ? `<div style="text-align:right;">
                               <div style="font-style:italic;font-size:10px;color:#a0aec0;white-space:nowrap;">${fmt(pricing.effectiveFee)} &times; ${qty} =</div>
                               <div style="font-weight:700;color:#d69e2e;white-space:nowrap;">${fmt(pricing.lineFeeTotal)}</div>
                           </div>`
                        : '<div style="text-align:right;color:#cbd5e0;">—</div>',
                    'white-space:nowrap;'
                ));
            } else {
                tr.appendChild(td(
                    feeInfo.fee > 0
                        ? `<div style="text-align:right;">
                               <div style="font-style:italic;font-size:10px;color:#a0aec0;white-space:nowrap;">${fmt(feeInfo.fee)} &times; ${qty} =</div>
                               <div style="font-weight:700;color:#d69e2e;white-space:nowrap;">${fmt(pricing.lineFeeTotal)}</div>
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
                            ${fmt(pricing.lineProductTotal)} + ${fmt(pricing.lineFeeTotal)} =
                        </div>
                        <div style="font-weight:700;color:#2563eb;white-space:nowrap;">${fmt(pricing.lineTotal)}</div>
                    </div>`,
                    'white-space:nowrap;'
                ));
            } else {
                tr.appendChild(td(
                    `<div style="text-align:right;">
                        <div style="font-style:italic;font-size:10px;color:#a0aec0;white-space:nowrap;">
                            ${fmt(pricing.lineProductTotal)} + ${fmt(pricing.lineFeeTotal)} =
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
        document.getElementById('total-items').textContent        = totItems;
        document.getElementById('subtotal').textContent           = fmt(totSubtotal);
        document.getElementById('total-delivery-fee').textContent = fmt(totFees);
        document.getElementById('grand-total').textContent        = fmt(totGrand);

        if (typeof currentPanel !== 'undefined' && currentPanel === 'right') {
            updateReceiptSelection();
        }
    };

    // ── updateReceiptSelection override ──────────────────────────────────────
    window.updateReceiptSelection = function () {
        const rows = document.querySelectorAll('.delivery-row');
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

    // ── calculateTotal override — half-pack aware, returns Promise<number> ────
    window.calculateTotal = async function () {
        const [feeInfos, halfPackInfos] = await Promise.all([
            Promise.all(cart.map(item => getProductInfo(item.id))),
            Promise.all(cart.map(item => getHalfPackInfo(item.id)))
        ]);
        return cart.reduce((sum, item, index) => {
            return sum + computePricing(item, feeInfos[index], halfPackInfos[index]).lineTotal;
        }, 0);
    };

    // ── Keyboard capture for right-panel row navigation ───────────────────────
    document.addEventListener('keydown', function(e) {
        if (typeof currentPanel === 'undefined' || currentPanel !== 'right') return;
        const anyModalOpen =
            document.getElementById('delivery-info-modal')?.classList.contains('active') ||
            document.getElementById('quantity-modal')?.classList.contains('active') ||
            document.getElementById('edit-modal')?.classList.contains('active');
        if (anyModalOpen) return;

        const rows = document.querySelectorAll('.delivery-row');
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

})();
</script>
<script>
var _lastClickTime=0,_lastClickId='',_lpTimer=null;
function handleProductTap(el){var now=Date.now(),id=el.dataset.id;if(now-_lastClickTime<400&&_lastClickId===id){simulateKey('Enter');_lastClickTime=0;_lastClickId='';}else{_lastClickTime=now;_lastClickId=id;}}
function startLongPress(idx){_lpTimer=setTimeout(function(){selectedReceiptIndex=parseInt(idx);openEditModal(parseInt(idx));},500);}
function cancelLongPress(){if(_lpTimer){clearTimeout(_lpTimer);_lpTimer=null;}}
function simulateKey(key){document.dispatchEvent(new KeyboardEvent('keydown',{key:key,bubbles:true}));}
</script>
<script src="/oro-store/core/search_tags.js"></script>
</body>
</html>