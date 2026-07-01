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

// ─── AJAX: Search retailers ───────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'search_retailers') {
    header('Content-Type: application/json');
    $search   = isset($_GET['search']) ? trim($_GET['search']) : '';
    $store_id = $userStore ? $userStore['id'] : null;
    if (strlen($search) < 2) { echo json_encode([]); $conn->close(); exit; }
    $query = "SELECT DISTINCT retailer_name, retailer_contact
              FROM angkat_transactions WHERE retailer_name LIKE ? AND is_deleted = 0";
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

// ─── POST: Complete angkat ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_angkat') {
    header('Content-Type: application/json');
    $items            = json_decode($_POST['items'], true);
    $total_amount     = floatval($_POST['total_amount']);
    $total_cost       = floatval($_POST['total_cost']);
    $items_count      = intval($_POST['items_count']);
    $retailer_name    = $_POST['retailer_name'];
    $retailer_contact = $_POST['retailer_contact'];
    $user_id  = $currentUser['id'];
    $store_id = $userStore ? $userStore['id'] : null;

    $conn->begin_transaction();
    try {
// Generate unique transaction number with retry
$transaction_number = generateTransactionNumber($conn);
$attempt = 0;
while ($attempt < 10) {
    // Check BOTH transactions and angkat_transactions tables
    $chk = $conn->prepare("SELECT id FROM angkat_transactions WHERE transaction_number = ? AND is_deleted = 0");
    $chk->bind_param("s", $transaction_number);
    $chk->execute();
    $exists = $chk->get_result()->num_rows > 0;
    $chk->close();
    if (!$exists) break;
    // Append random suffix to guarantee uniqueness
    $transaction_number = generateTransactionNumber($conn) . '-A' . rand(10, 99);
    $attempt++;
}

$angkat_id = $db->insert('angkat_transactions', [
            'transaction_number' => $transaction_number,
            'retailer_name'      => $retailer_name,
            'retailer_contact'   => $retailer_contact,
            'total_items'        => $items_count,
            'total_value'        => $total_amount,
            'total_cost'         => $total_cost,
            'status'             => 'active',
            'created_by'         => $user_id,
            'store_id'           => $store_id,
        ]);

        foreach ($items as $item) {
            $db->insert('angkat_items', [
                'angkat_id'         => $angkat_id,
                'product_id'        => $item['id'],
                'product_name'      => $item['name'],
                'quantity_given'    => $item['quantity'],
                'quantity_sold'     => 0,
                'quantity_returned' => 0,
                'price'             => $item['price'],
                'cost_price'        => $item['purchase_price'],
                'status'            => 'active'
            ]);

            try {
                if (isIndividualProduct($item['id'])) {
                    $stock_result = deductIndividualStock($item['id'], $item['quantity'], $store_id);
                    if ($stock_result['packs_opened'] > 0)
                        logActivity('product', "Converted {$stock_result['packs_opened']} pack(s) for angkat", $user_id, $store_id, ['product_id' => $item['id'], 'product_name' => $item['name'], 'packs_opened' => $stock_result['packs_opened'], 'angkat_id' => $angkat_id]);
                } else {
                    deductPackStock($item['id'], $item['quantity'], $store_id);
                }
            } catch (Exception $e) {
                throw new Exception("Stock deduction failed for {$item['name']}: " . $e->getMessage());
            }

            logActivity('product', "Stock updated for {$item['name']} (angkat)", $user_id, $store_id, ['product_id' => $item['id'], 'product_name' => $item['name'], 'quantity_change' => -$item['quantity'], 'angkat_id' => $angkat_id]);
        }

        logActivity('angkat', "Angkat transaction created: $transaction_number", $user_id, $store_id, ['angkat_id' => $angkat_id, 'transaction_number' => $transaction_number, 'retailer' => $retailer_name, 'total_value' => $total_amount, 'items_count' => $items_count]);

        $conn->commit();
        if (function_exists('flushStockPushes')) flushStockPushes();
        echo json_encode(['success' => true, 'angkat_id' => $angkat_id, 'transaction_number' => $transaction_number]);
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
    <title>Angkat Mode<?php echo $userStore ? ' - ' . htmlspecialchars($userStore['store_name']) : ''; ?></title>
    <link rel="stylesheet" href="/oro-store-demo/delivery/delivery_styles.css">
    <style>
        /* ── Angkat-specific overrides ── */
        .angkat-mode-indicator {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 11px 20px;
            text-align: center;
            font-weight: 700;
            font-size: 15px;
            letter-spacing: .5px;
            flex-shrink: 0;
            border-bottom: 1px solid #5a67d8;
        }

        .btn-back-angkat {
            padding: 8px 16px;
            background: #667eea;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
            white-space: nowrap;
        }
        .btn-back-angkat:hover { background: #5a67d8; }

        /* Profit row in totals */
        .tot-row.profit-row { color: #16a34a; font-weight: 700; }
        .tot-row.cost-row   { color: #dc2626; font-weight: 600; }

        /* Angkat info modal */
        .angkat-info-modal .modal-content { min-width: 460px; text-align: left; }

        /* Consignment note */
        .consignment-note {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-left: 4px solid #f59e0b;
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 12px;
            color: #92400e;
            margin: 12px 0;
        }
        .consignment-note strong { display: block; margin-bottom: 2px; }

        /* Price badge on product name col */
        .angkat-unit-price {
            font-weight: 700;
            font-size: 10px;
            color: #718096;
            margin-top: 2px;
        }

        /* Angkat row selection */
        .angkat-row.selected td { background: #dbeafe !important; }

        .shortcut-bar { display:flex; flex-wrap:wrap; gap:4px; padding:6px 10px; background:#1a202c; border-bottom:2px solid #2d3748; z-index:999; justify-content:center; }
        .sc-key { display:inline-flex; align-items:center; gap:4px; font-size:10px; color:#a0aec0; background:#2d3748; padding:3px 8px; border-radius:4px; white-space:nowrap; cursor:pointer; transition:background .12s; }
        .sc-key:hover { background:#4a5568; }
        .sc-key kbd { display:inline-block; background:#4a5568; color:#e2e8f0; font-size:10px; font-weight:700; font-family:inherit; padding:1px 5px; border-radius:3px; border:1px solid #718096; min-width:16px; text-align:center; }
        .sc-key.sc-blue kbd { background:#2b6cb0; border-color:#3182ce; }
        .sc-key.sc-green kbd { background:#276749; border-color:#38a169; }
        .sc-key.sc-orange kbd { background:#9c4221; border-color:#dd6b20; }
        .sc-key.sc-teal kbd { background:#285e61; border-color:#38b2ac; }
        .sc-key.sc-blue { color:#90cdf4; }
        .sc-key.sc-green { color:#9ae6b4; }
        .sc-key.sc-orange { color:#fbd38d; }
        .sc-key.sc-teal { color:#81e6d9; }
        .cashier-container { height: calc(100vh - 34px); }
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
    <span class="sc-key sc-purple" onclick="location.href='/oro-store-demo/credit/credit.php'"><kbd>F4</kbd> Credit</span>
    <span class="sc-key sc-blue" onclick="window.open('/oro-store-demo/transactions/card_transaction.php','_blank','width=600,height=700')"><kbd>F7</kbd> ATM</span>
    <span class="sc-key" onclick="location.href='/oro-store-demo/delivery/delivery_details.php'"><kbd>F9</kbd> Details</span>

    <span class="sc-key" onclick="window.open('/oro-store-demo/stock/add_stock.php','_blank','width=800,height=600')"><kbd>F11</kbd> Add Stock</span>
    <span class="sc-key sc-green" onclick="processAngkat()"><kbd>Enter</kbd> Process</span>
</div>

<div class="cashier-container">

    <!-- ══ LEFT PANEL ══════════════════════════════════════════════════ -->
    <div class="left-panel">

        <div class="angkat-mode-indicator">📦 &nbsp; ANGKAT MODE (Consignment)</div>

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
    <!-- ══ END LEFT PANEL ═════════════════════════════════════════════ -->

    <!-- ══ RIGHT PANEL ════════════════════════════════════════════════ -->
    <div class="right-panel">
        <div class="receipt-header">
            <h1>ANGKAT RECEIPT</h1>
            <p style="color:#764ba2;font-weight:700;">Consignment Transaction</p>
            <?php if ($userStore): ?>
                <p style="font-size:11px;color:#718096;margin:2px 0 0;"><?php echo htmlspecialchars($userStore['store_name']); ?></p>
            <?php endif; ?>
        </div>

        <div id="receipt-items">
            <div class="empty-cart">
                <h3>Cart is Empty</h3>
                <p>Add products for angkat / consignment</p>
            </div>
        </div>

        <div id="receipt-total" style="display:none;">
            <div class="receipt-totals-wrap">
                <div class="tot-row">
                    <span>Total Items:</span>
                    <span id="total-items">0</span>
                </div>
                <div class="tot-row">
                    <span>Total Value (Selling):</span>
                    <span id="subtotal">₱0.00</span>
                </div>
                <div class="tot-row cost-row">
                    <span>Total Cost:</span>
                    <span id="total-cost">₱0.00</span>
                </div>
                <div class="tot-row grand" onclick="processAngkat()" style="cursor:pointer;background:#22c55e;color:#fff;border-radius:10px;padding:14px 16px;">
                    <span style="font-weight:800;">EXPECTED PROFIT:</span>
                    <span class="val" id="expected-profit" style="font-weight:800;">₱0.00</span>
                </div>
            </div>
        </div>
    </div>
    <!-- ══ END RIGHT PANEL ════════════════════════════════════════════ -->

    <!-- ══ Angkat Info Modal ══════════════════════════════════════════ -->
    <div class="modal angkat-info-modal" id="angkat-info-modal">
        <div class="modal-content">
            <h2>📦 Retailer Information</h2>
            <div class="delivery-form-group autocomplete-container">
                <label for="retailer-name">Retailer Name *</label>
                <input type="text" id="retailer-name" required placeholder="Enter retailer name" autocomplete="off">
                <div class="autocomplete-dropdown" id="autocomplete-dropdown"></div>
            </div>
            <div class="delivery-form-group">
                <label for="retailer-contact">Contact Number</label>
                <input type="text" id="retailer-contact" placeholder="Enter contact number (optional)">
            </div>
            <div class="consignment-note">
                <strong>⚠️ Consignment Note</strong>
                Products given on consignment — no cash collected now. Retailer pays only for sold items and returns unsold ones.
            </div>
            <div class="modal-buttons" style="margin-top:20px;">
                <button class="btn-print"   onclick="printAngkatReceipt()">Print (F1)</button>
                <button class="btn-confirm" onclick="completeAngkatTransaction()">Complete (Enter)</button>
                <button class="btn-cancel"  onclick="closeAngkatInfoModal()">Cancel (Esc)</button>
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
<script src="/oro-store-demo/angkat/angkat_script.js"></script>
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
renderPageInfo('Angkat Transaction', array (
  'How It Works' => 
  array (
    0 => 'Angkat = consignment — give products to retailer on credit',
    1 => 'Select products and quantities to consign',
    2 => 'Enter retailer name and contact',
    3 => 'Angkat charge per category auto-applied',
    4 => 'Track what retailer sells and collects payment',
  ),
));
?>
</body>
</html>