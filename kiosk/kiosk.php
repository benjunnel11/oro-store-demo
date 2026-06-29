<?php
ob_start();
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';
require_once __DIR__ . '/../sync/sync_helper.php';

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

// Ensure users role column accepts 'kiosk'
$conn->query("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'cashier'");

// Ensure kiosk_orders table exists
$conn->query("CREATE TABLE IF NOT EXISTS kiosk_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    priority_number INT NOT NULL,
    store_id INT DEFAULT NULL,
    items JSON NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_profit DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    items_count INT NOT NULL DEFAULT 0,
    payment_method VARCHAR(20) NOT NULL DEFAULT 'cash',
    status ENUM('waiting','processing','completed','cancelled') NOT NULL DEFAULT 'waiting',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_by INT DEFAULT NULL,
    processed_at TIMESTAMP NULL,
    transaction_id INT DEFAULT NULL,
    order_date DATE NOT NULL,
    INDEX idx_store_date_status (store_id, order_date, status),
    INDEX idx_status (status)
)");

// ─── GET: Queue count ────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'queue_count') {
    header('Content-Type: application/json');
    $store_id = $userStore ? $userStore['id'] : null;
    $today = date('Y-m-d');
    $sc = $store_id ? " AND store_id = " . intval($store_id) : "";

    // Product queue
    $r = $conn->query("SELECT COUNT(*) as c FROM kiosk_orders WHERE order_date = '$today' AND status IN ('waiting','processing') AND payment_method = 'cash' $sc");
    $product_waiting = (int)$r->fetch_assoc()['c'];

    // GCash queue
    $r = $conn->query("SELECT COUNT(*) as c FROM kiosk_orders WHERE order_date = '$today' AND status IN ('waiting','processing') AND payment_method LIKE 'gcash_%' $sc");
    $gcash_waiting = (int)$r->fetch_assoc()['c'];

    // Now serving - product: processing first, fallback to last completed
    $r = $conn->query("SELECT priority_number FROM kiosk_orders WHERE order_date = '$today' AND status = 'processing' AND payment_method = 'cash' $sc ORDER BY priority_number ASC LIMIT 1");
    $row = $r->fetch_assoc();
    $product_now = $row ? (int)$row['priority_number'] : null;
    $product_serving = !!$row;
    if (!$product_now) {
        $r = $conn->query("SELECT priority_number FROM kiosk_orders WHERE order_date = '$today' AND status = 'completed' AND payment_method = 'cash' $sc ORDER BY processed_at DESC LIMIT 1");
        $row = $r->fetch_assoc();
        $product_now = $row ? (int)$row['priority_number'] : null;
    }

    // GCash: show the first waiting/processing order number (next in line)
    $r = $conn->query("SELECT priority_number, status FROM kiosk_orders WHERE order_date = '$today' AND status IN ('waiting','processing') AND payment_method LIKE 'gcash_%' $sc ORDER BY priority_number ASC LIMIT 1");
    $row = $r->fetch_assoc();
    $gcash_now = $row ? (int)$row['priority_number'] : null;
    $gcash_serving = $row && $row['status'] === 'processing';

    echo json_encode([
        'product_waiting' => $product_waiting,
        'gcash_waiting' => $gcash_waiting,
        'product_now' => $product_now,
        'product_serving' => $product_serving,
        'gcash_now' => $gcash_now,
        'gcash_serving' => $gcash_serving,
        'count' => $product_waiting + $gcash_waiting
    ]);
    $conn->close(); exit;
}

// ─── POST: Verify kiosk password for logout ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_kiosk_password') {
    header('Content-Type: application/json');
    $password = $_POST['password'] ?? '';
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $currentUser['id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $valid = $row && password_verify($password, $row['password']);
    echo json_encode(['success' => $valid]);
    $conn->close(); exit;
}

// ─── POST: Submit kiosk order ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_kiosk_order') {
    header('Content-Type: application/json');
    $items        = $_POST['items'];
    $total_amount = floatval($_POST['total_amount']);
    $total_profit = floatval($_POST['total_profit']);
    $items_count  = intval($_POST['items_count']);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $user_id  = $currentUser['id'];
    $store_id = $userStore ? $userStore['id'] : null;
    $today    = date('Y-m-d');

    $conn->begin_transaction();
    try {
        // Get next priority number for this store today
        $pStmt = $conn->prepare("SELECT COALESCE(MAX(priority_number), 0) + 1 AS next_num FROM kiosk_orders WHERE store_id <=> ? AND order_date = ?");
        $pStmt->bind_param("is", $store_id, $today);
        $pStmt->execute();
        $priority = $pStmt->get_result()->fetch_assoc()['next_num'];
        $pStmt->close();

        $stmt = $conn->prepare("INSERT INTO kiosk_orders (priority_number, store_id, items, total_amount, total_profit, items_count, payment_method, status, created_by, order_date) VALUES (?, ?, ?, ?, ?, ?, ?, 'waiting', ?, ?)");
        $stmt->bind_param("iisddisis", $priority, $store_id, $items, $total_amount, $total_profit, $items_count, $payment_method, $user_id, $today);
        $stmt->execute();
        $order_id = $stmt->insert_id;
        $stmt->close();

        logActivity('kiosk', "Kiosk order #$priority submitted", $user_id, $store_id, [
            'kiosk_order_id' => $order_id, 'priority_number' => $priority, 'amount' => $total_amount
        ]);

        $conn->commit();
        echo json_encode(['success' => true, 'order_id' => $order_id, 'priority_number' => $priority]);
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
    <title>Kiosk<?php echo $userStore ? ' - ' . htmlspecialchars($userStore['store_name']) : ''; ?></title>
    <link rel="stylesheet" href="/oro-store/cashier/cashier_styles.css">
    <style>
        .kiosk-mode-bar {
            background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
            color: #fff; padding: 12px 20px; text-align: center;
            font-weight: 700; font-size: 16px; letter-spacing: .5px;
            flex-shrink: 0; border-bottom: 2px solid #4c1d95;
        }
        .shortcut-bar { display:flex; flex-wrap:wrap; gap:4px; padding:6px 10px; background:#1a202c; border-bottom:2px solid #2d3748; z-index:999; justify-content:center; }
        .sc-key { display:inline-flex; align-items:center; gap:4px; font-size:10px; color:#a0aec0; background:#2d3748; padding:3px 8px; border-radius:4px; white-space:nowrap; cursor:pointer; transition:background .12s; }
        .sc-key:hover { background:#4a5568; }
        .sc-key kbd { display:inline-block; background:#4a5568; color:#e2e8f0; font-size:10px; font-weight:700; font-family:inherit; padding:1px 5px; border-radius:3px; border:1px solid #718096; min-width:16px; text-align:center; }
        .sc-key.sc-green kbd { background:#276749; border-color:#38a169; }
        .sc-key.sc-green { color:#9ae6b4; }

        .receipt-header { background: linear-gradient(135deg, #5b21b6, #7c3aed) !important; }
        .receipt-header h1 { color: #fff !important; }
        .receipt-header p { color: #c4b5fd !important; }

        /* Kiosk lockdown */
        * { -webkit-user-select:none; user-select:none; -webkit-touch-callout:none; }
        input, textarea { -webkit-user-select:text; user-select:text; }
        body { touch-action:manipulation; }

        /* Logout modal */
        .logout-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); backdrop-filter:blur(6px); z-index:10000; align-items:center; justify-content:center; }
        .logout-modal.active { display:flex; }
        .logout-box { background:#fff; border-radius:16px; padding:32px; text-align:center; max-width:360px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,.3); }
        .logout-box h3 { font-size:16px; color:#1e293b; margin-bottom:6px; }
        .logout-box p { font-size:12px; color:#94a3b8; margin-bottom:16px; }
        .logout-box input { width:100%; padding:12px; border:2px solid #e2e8f0; border-radius:8px; font-size:14px; outline:none; text-align:center; margin-bottom:12px; }
        .logout-box input:focus { border-color:#7c3aed; }
        .logout-box .btn-row { display:flex; gap:8px; }
        .logout-box button { flex:1; padding:10px; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; }
        .logout-box .btn-confirm-logout { background:#dc2626; color:#fff; }
        .logout-box .btn-cancel-logout { background:#f1f5f9; color:#334155; }
        .logout-error { color:#dc2626; font-size:12px; margin-bottom:8px; display:none; }

        /* Priority number modal */
        .priority-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.7); backdrop-filter:blur(6px); z-index:9999; align-items:center; justify-content:center; }
        .priority-modal.active { display:flex; }
        .priority-box {
            background:#fff; border-radius:20px; padding:40px 50px; text-align:center;
            box-shadow:0 20px 60px rgba(0,0,0,.3); max-width:420px; width:90%;
        }
        .priority-label { font-size:14px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px; }
        .priority-number { font-size:96px; font-weight:900; color:#7c3aed; line-height:1; margin:10px 0 20px; }
        .priority-store { font-size:13px; color:#94a3b8; margin-bottom:6px; }
        .priority-info { font-size:12px; color:#94a3b8; margin-bottom:24px; }
        .priority-actions { display:flex; gap:10px; justify-content:center; }
        .priority-actions button {
            padding:12px 28px; border:none; border-radius:10px; font-size:14px;
            font-weight:700; cursor:pointer; transition:opacity .15s;
        }
        .priority-actions button:hover { opacity:.85; }
        .btn-print-priority { background:#7c3aed; color:#fff; }
        .btn-close-priority { background:#f1f5f9; color:#334155; border:1px solid #e2e8f0 !important; }
    </style>
    <link rel="stylesheet" href="/oro-store/core/responsive.css">
    <script src="/oro-store/core/custom_alert.js"></script>
    <script src="/oro-store/core/bt_print.js?v=20250627"></script>
</head>
<body>
<div class="shortcut-bar">
    <span class="sc-key sc-green" onclick="openGCash()"><kbd>F2</kbd> GCash</span>
    <span class="sc-key" style="pointer-events:none;cursor:default;">
        <span id="printer-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#22c55e;margin-right:4px;box-shadow:0 0 6px #22c55e;"></span>
        Printer <span id="printer-label" style="font-size:9px;color:#22c55e;">ready</span>
    </span>
    <span class="sc-key" style="pointer-events:none;cursor:default;background:#2563eb;color:#bfdbfe;">
        Order: <span id="kq-product-count" style="font-weight:700;color:#fff;">0</span>
        <span id="kq-product-now" style="font-size:9px;color:#93c5fd;margin-left:2px;"></span>
    </span>
    <span class="sc-key" style="pointer-events:none;cursor:default;background:#7c3aed;color:#e9d5ff;">
        GCash: <span id="kq-gcash-count" style="font-weight:700;color:#fff;">0</span>
        <span id="kq-gcash-now" style="font-size:9px;color:#c4b5fd;margin-left:2px;"></span>
    </span>
</div>

<div class="cashier-container">
    <!-- LEFT PANEL -->
    <div class="left-panel">
        <div class="kiosk-mode-bar">🖥️ KIOSK MODE</div>
        <div class="lp-topbar">
            <h1>Product Selection</h1>
            <div class="lp-meta">
                <?php if ($userStore): ?>
                    <span class="lp-store-badge">🏪 <?php echo htmlspecialchars($userStore['store_code']); ?></span>
                <?php endif; ?>
                <div class="lp-user">
                    <strong><?php echo htmlspecialchars($currentUser['full_name']); ?></strong><br>
                    Kiosk
                </div>
                <button class="btn-logout" onclick="openLogoutModal()" style="padding:6px 14px;background:#dc2626;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;">Logout</button>
            </div>
        </div>
        <div class="search-container" style="position:relative;">
            <input type="text" id="cashier-search" placeholder="Search (Name, Brand, Category)…" autofocus autocomplete="off">
            <div id="search-tags" style="display:flex;gap:4px;flex-wrap:wrap;padding:4px 8px;"></div>
            <div id="search-suggestions" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #e2e8f0;border-radius:0 0 8px 8px;box-shadow:0 4px 12px rgba(0,0,0,.15);max-height:200px;overflow-y:auto;z-index:100;"></div>
        </div>
        <ul class="product-list-cashier" id="product-list"></ul>
    </div>

    <!-- RIGHT PANEL -->
    <div class="right-panel">
        <div class="receipt-header">
            <h1>KIOSK ORDER</h1>
            <p>Select products and submit</p>
            <?php if ($userStore): ?>
                <p style="font-size:11px;color:#c4b5fd;margin:2px 0 0;"><?php echo htmlspecialchars($userStore['store_name']); ?></p>
            <?php endif; ?>
        </div>
        <div class="receipt-items" id="receipt-items">
            <div class="empty-cart">
                <h3>Cart is Empty</h3>
                <p>Select products to add to your order</p>
            </div>
        </div>
        <div class="receipt-total" id="receipt-total" style="display:none;">
            <div class="total-row">
                <span>Items:</span>
                <span id="total-items">0</span>
            </div>
            <div class="total-row">
                <span>Subtotal:</span>
                <span id="subtotal">₱0.00</span>
            </div>
            <div class="total-row grand-total" onclick="submitKioskOrder()" style="cursor:pointer;padding:16px;border-radius:10px;background:#7c3aed;color:#fff;">
                <span style="font-weight:800;">SUBMIT ORDER</span>
                <span id="grand-total" style="font-weight:800;">₱0.00</span>
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
                <button class="btn-cancel" onclick="closeQuantityModal()">Cancel (Esc)</button>
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
                <button class="btn-cancel" onclick="closeEditModal()">Cancel (Esc)</button>
            </div>
        </div>
    </div>

    <!-- Priority Number Modal -->
    <div class="priority-modal" id="priority-modal">
        <div class="priority-box">
            <div class="priority-label">Your Priority Number</div>
            <div class="priority-number" id="priority-number">—</div>
            <div id="priority-gcash-details" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 16px;margin:0 auto 16px;max-width:280px;text-align:center;"></div>
            <div class="priority-store" id="priority-store"><?php echo htmlspecialchars($userStore ? $userStore['store_name'] : 'ORO STORE'); ?></div>
            <div class="priority-info">Please wait for your number to be called</div>
            <div style="font-size:12px;color:#94a3b8;margin-bottom:16px;">Returning in <span id="priority-countdown" style="font-weight:700;color:#7c3aed;">10</span>s</div>
            <div class="priority-actions">
                <button class="btn-close-priority" onclick="closePriorityModal()">New Order</button>
            </div>
        </div>
    </div>
</div>

<script>
const STORE_INFO = {
    storeName: <?php echo json_encode($userStore ? $userStore['store_name'] : 'ORO STORE'); ?>,
    storeAddress: <?php echo json_encode($userStore ? ($userStore['address'] ?? '') : ''); ?>
};
</script>
<script src="/oro-store/core/cache.js"></script>
<script src="/oro-store/kiosk/kiosk_script.js?v=20250627"></script>
<script src="/oro-store/core/search_tags.js"></script>

<!-- Logout Modal -->
<div class="logout-modal" id="logout-modal">
    <div class="logout-box">
        <h3>Logout</h3>
        <p>Enter kiosk account password to log out</p>
        <div class="logout-error" id="logout-error">Incorrect password</div>
        <input type="password" id="logout-password" placeholder="Password" autocomplete="off">
        <div class="btn-row">
            <button class="btn-confirm-logout" onclick="confirmLogout()">Logout</button>
            <button class="btn-cancel-logout" onclick="closeLogoutModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
// Kiosk lockdown: disable right-click, zoom, key shortcuts
document.addEventListener('contextmenu', function(e) { e.preventDefault(); });
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === '+' || e.key === '-' || e.key === '=' || e.key === '0')) e.preventDefault();
    if ((e.ctrlKey || e.metaKey) && e.key === 'u') e.preventDefault();
    if (e.key === 'F5') e.preventDefault();
    if (e.key === 'F11' && !e.shiftKey) e.preventDefault();
    if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'I' || e.key === 'i' || e.key === 'J' || e.key === 'j' || e.key === 'C' || e.key === 'c')) e.preventDefault();
});
document.addEventListener('wheel', function(e) { if (e.ctrlKey) e.preventDefault(); }, { passive: false });

// Password-protected logout
function openLogoutModal() {
    document.getElementById('logout-password').value = '';
    document.getElementById('logout-error').style.display = 'none';
    document.getElementById('logout-modal').classList.add('active');
    document.getElementById('logout-password').focus();
}
function closeLogoutModal() {
    document.getElementById('logout-modal').classList.remove('active');
}
function confirmLogout() {
    var pw = document.getElementById('logout-password').value;
    if (!pw) return;
    var fd = new FormData();
    fd.append('action', 'verify_kiosk_password');
    fd.append('password', pw);
    fetch('/oro-store/kiosk/kiosk.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { location.href = '/oro-store/auth/logout.php'; }
            else { document.getElementById('logout-error').style.display = 'block'; document.getElementById('logout-password').value = ''; document.getElementById('logout-password').focus(); }
        });
}
document.addEventListener('keydown', function(e) {
    if (document.getElementById('logout-modal').classList.contains('active')) {
        if (e.key === 'Enter') { e.preventDefault(); e.stopImmediatePropagation(); confirmLogout(); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopImmediatePropagation(); closeLogoutModal(); }
    }
}, true);

// ── Queue count polling ──
function pad3(n) { return String(n).padStart(3, '0'); }
function updateQueueCount() {
    fetch('/oro-store/kiosk/kiosk.php?action=queue_count')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var pe = document.getElementById('kq-product-count');
            var ge = document.getElementById('kq-gcash-count');
            var pn = document.getElementById('kq-product-now');
            var gn = document.getElementById('kq-gcash-now');
            if (pe) pe.textContent = data.product_waiting;
            if (ge) ge.textContent = data.gcash_waiting;
            if (pn) {
                if (data.product_now) {
                    pn.textContent = (data.product_serving ? 'Now: #' : 'Served: #') + pad3(data.product_now);
                    pn.style.color = data.product_serving ? '#fbbf24' : '#93c5fd';
                } else { pn.textContent = ''; }
            }
            if (gn) {
                if (data.gcash_now) {
                    gn.textContent = (data.gcash_serving ? 'Now: #' : 'Next: #') + pad3(data.gcash_now);
                    gn.style.color = data.gcash_serving ? '#fbbf24' : '#c4b5fd';
                } else { gn.textContent = ''; }
            }
        }).catch(function() {});
}
updateQueueCount();
setInterval(updateQueueCount, 5000);
</script>
</body>
</html>
