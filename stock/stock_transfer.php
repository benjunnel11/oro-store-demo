<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/sync_helper.php';
require_once __DIR__ . '/../core/system_logger.php';

if (!isAdmin()) { header("Location: /oro-store-demo/cashier/cashier.php"); exit; }

$currentUser = getCurrentUser();
$db = new SyncDB();

$conn->query("CREATE TABLE IF NOT EXISTS stock_transfers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_store_id INT NOT NULL,
    to_store_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    notes TEXT,
    transfer_batch_id VARCHAR(20),
    transferred_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0
)");
// Add batch column if not exists
$conn->query("ALTER TABLE stock_transfers ADD COLUMN IF NOT EXISTS transfer_batch_id VARCHAR(20) DEFAULT NULL");

// AJAX: Search products
if (isset($_GET['action']) && $_GET['action'] === 'search_products') {
    header('Content-Type: application/json');
    $store_id = intval($_GET['store_id'] ?? 0);
    $q = trim($_GET['q'] ?? '');
    if ($store_id <= 0) { echo json_encode([]); exit; }

    $like = '%' . $conn->real_escape_string($q) . '%';
    $stmt = $conn->prepare("
        SELECT p.id, p.name, sp.stock, sp.price, sp.purchase_price, p.barcode,
               COALESCE(pc.category_name, ppc.category_name) as category_name,
               COALESCE(pb.brand_name, ppb.brand_name) as brand_name,
               pp.individual_sell_unit
        FROM store_prices sp
        JOIN products p ON p.id = sp.product_id
        LEFT JOIN product_categories pc ON p.category_id = pc.id AND pc.is_deleted = 0
        LEFT JOIN product_brands pb ON p.brand_id = pb.id AND pb.is_deleted = 0
        LEFT JOIN products pp ON p.parent_product_id = pp.id
        LEFT JOIN product_categories ppc ON pp.category_id = ppc.id AND ppc.is_deleted = 0
        LEFT JOIN product_brands ppb ON pp.brand_id = ppb.id AND ppb.is_deleted = 0
        WHERE sp.store_id = ? AND sp.is_deleted = 0 AND p.is_deleted = 0 AND sp.stock > 0
          AND (p.name LIKE ? OR p.barcode LIKE ? OR COALESCE(pc.category_name, ppc.category_name, '') LIKE ? OR COALESCE(pb.brand_name, ppb.brand_name, '') LIKE ?)
        ORDER BY p.name LIMIT 30
    ");
    $stmt->bind_param("issss", $store_id, $like, $like, $like, $like);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    $stmt->close(); exit;
}

// POST: Bulk transfer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_transfer') {
    header('Content-Type: application/json');
    @include_once __DIR__ . '/../sync/config.php';
    $from_store_id = intval($_POST['from_store_id'] ?? 0);
    $to_store_id = intval($_POST['to_store_id'] ?? 0);
    $items = json_decode($_POST['items'] ?? '[]', true);
    $notes = trim($_POST['notes'] ?? '');

    if ($from_store_id <= 0 || $to_store_id <= 0) { echo json_encode(['success'=>false,'error'=>'Select both stores.']); exit; }
    if ($from_store_id === $to_store_id) { echo json_encode(['success'=>false,'error'=>'Stores must be different.']); exit; }
    if (empty($items)) { echo json_encode(['success'=>false,'error'=>'Cart is empty.']); exit; }

    $stmt = $conn->prepare("SELECT id, store_name FROM stores WHERE id IN (?, ?)");
    $stmt->bind_param("ii", $from_store_id, $to_store_id);
    $stmt->execute();
    $store_names = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $sr) $store_names[$sr['id']] = $sr['store_name'];
    $stmt->close();
    $from_name = $store_names[$from_store_id] ?? 'Unknown';
    $to_name = $store_names[$to_store_id] ?? 'Unknown';

    $batch_id = 'BT-' . date('ymd') . '-' . str_pad(rand(0,9999), 4, '0', STR_PAD_LEFT);
    $conn->begin_transaction();

    try {
        $transferred = [];
        $total_units = 0;

        foreach ($items as $item) {
            $product_id = intval($item['id']);
            $quantity = intval($item['quantity']);
            if ($quantity <= 0) continue;

            $stmt = $conn->prepare("SELECT stock, price, purchase_price FROM store_prices WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
            $stmt->bind_param("ii", $product_id, $from_store_id);
            $stmt->execute();
            $source = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$source) throw new Exception("Product ID $product_id not found in source store.");
            if ($quantity > $source['stock']) {
                $pstmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
                $pstmt->bind_param("i", $product_id); $pstmt->execute();
                $pname = $pstmt->get_result()->fetch_assoc()['name']; $pstmt->close();
                throw new Exception("Insufficient stock for $pname. Available: {$source['stock']}, requested: $quantity");
            }

            // 1. Reduce source stock LOCALLY
            $new_from = $source['stock'] - $quantity;
            $stmt = $conn->prepare("UPDATE store_prices SET stock = ? WHERE product_id = ? AND store_id = ?");
            $stmt->bind_param("iii", $new_from, $product_id, $from_store_id); $stmt->execute(); $stmt->close();

            // 2. Increase destination stock LOCALLY
            $stmt = $conn->prepare("SELECT id, stock FROM store_prices WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
            $stmt->bind_param("ii", $product_id, $to_store_id); $stmt->execute();
            $dest = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($dest) {
                $new_to = $dest['stock'] + $quantity;
                $stmt = $conn->prepare("UPDATE store_prices SET stock = ? WHERE product_id = ? AND store_id = ?");
                $stmt->bind_param("iii", $new_to, $product_id, $to_store_id); $stmt->execute(); $stmt->close();
            } else {
                $new_to = $quantity;
                $stmt = $conn->prepare("INSERT INTO store_prices (product_id, store_id, stock, price, purchase_price, is_deleted) VALUES (?, ?, ?, ?, ?, 0)");
                $stmt->bind_param("iiids", $product_id, $to_store_id, $quantity, $source['price'], $source['purchase_price']);
                $stmt->execute(); $stmt->close();
            }

            // 3. Push BOTH changes to ALL other devices
            $other_devs = $conn->query("SELECT device_ip FROM stores WHERE device_id IS NOT NULL AND device_id != '$local_dev' AND device_ip IS NOT NULL AND device_ip != '' AND status = 'active'");
            if ($other_devs) {
                while ($od = $other_devs->fetch_assoc()) {
                    $api_url = "http://{$od['device_ip']}/oro-store-demo/sync/stock_transfer_api.php?key=" . urlencode(SYNC_PASSWORD);
                    // Reduce source on remote
                    $ctx = stream_context_create(['http' => ['method'=>'POST','timeout'=>5,'header'=>"Content-Type: application/json\r\n",'content'=>json_encode(['action'=>'set_stock','product_id'=>$product_id,'store_id'=>$from_store_id,'stock'=>$new_from])]]);
                    @file_get_contents($api_url, false, $ctx);
                    // Increase destination on remote
                    $ctx = stream_context_create(['http' => ['method'=>'POST','timeout'=>5,'header'=>"Content-Type: application/json\r\n",'content'=>json_encode(['action'=>'set_stock','product_id'=>$product_id,'store_id'=>$to_store_id,'stock'=>$new_to])]]);
                    @file_get_contents($api_url, false, $ctx);
                }
            }

            $stmt = $conn->prepare("INSERT INTO stock_transfers (from_store_id, to_store_id, product_id, quantity, notes, transfer_batch_id, transferred_by) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param("iiiissi", $from_store_id, $to_store_id, $product_id, $quantity, $notes, $batch_id, $currentUser['id']);
            $stmt->execute();
            $transfer_id = $conn->insert_id;
            $stmt->close();

            // Send transfer record to all other devices
            $transfer_data = [
                'action' => 'record_transfer',
                'id' => $transfer_id,
                'from_store_id' => $from_store_id, 'to_store_id' => $to_store_id,
                'product_id' => $product_id, 'quantity' => $quantity,
                'notes' => $notes, 'transfer_batch_id' => $batch_id,
                'transferred_by' => $currentUser['id'], 'transferred_by_name' => $currentUser['full_name']
            ];
            $other_devs = $conn->query("SELECT device_ip FROM stores WHERE device_id IS NOT NULL AND device_id != '$local_dev' AND device_ip IS NOT NULL AND device_ip != '' AND status = 'active'");
            if ($other_devs) {
                while ($od = $other_devs->fetch_assoc()) {
                    $api_url = "http://{$od['device_ip']}/oro-store-demo/sync/stock_transfer_api.php?key=" . urlencode(SYNC_PASSWORD);
                    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($transfer_data)]]);
                    @file_get_contents($api_url, false, $ctx);
                }
            }

            $pstmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
            $pstmt->bind_param("i", $product_id); $pstmt->execute();
            $pname = $pstmt->get_result()->fetch_assoc()['name']; $pstmt->close();

            $transferred[] = "$quantity x $pname";
            $total_units += $quantity;
        }

        logActivity('store', "Batch transfer $batch_id: " . count($transferred) . " products, $total_units units from $from_name to $to_name",
            $currentUser['id'], $from_store_id, [
                'batch_id' => $batch_id, 'from_store' => $from_name, 'to_store' => $to_name,
                'total_products' => count($transferred), 'total_units' => $total_units,
                'items' => $transferred, 'notes' => $notes
            ]);

        $conn->commit();
        echo json_encode(['success'=>true, 'message'=>"Transferred " . count($transferred) . " products ($total_units units) from $from_name to $to_name", 'batch_id'=>$batch_id]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
    }
    exit;
}

// Page data
$stores = $conn->query("SELECT id, store_name, store_code FROM stores WHERE status = 'active' ORDER BY store_name")->fetch_all(MYSQLI_ASSOC);

$stats = ['total'=>0,'this_month'=>0,'today'=>0,'units_month'=>0];
$r = $conn->query("SELECT COUNT(*) as c FROM stock_transfers WHERE is_deleted = 0"); if ($r) $stats['total'] = $r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) as c FROM stock_transfers WHERE is_deleted = 0 AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())"); if ($r) $stats['this_month'] = $r->fetch_assoc()['c'];
$r = $conn->query("SELECT COUNT(*) as c FROM stock_transfers WHERE is_deleted = 0 AND DATE(created_at)=CURDATE()"); if ($r) $stats['today'] = $r->fetch_assoc()['c'];
$r = $conn->query("SELECT COALESCE(SUM(quantity),0) as s FROM stock_transfers WHERE is_deleted = 0 AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())"); if ($r) $stats['units_month'] = $r->fetch_assoc()['s'];

$transfers = $conn->query("
    SELECT st.*, fs.store_name AS from_store_name, ts.store_name AS to_store_name,
           p.name AS product_name, u.full_name AS transferred_by_name
    FROM stock_transfers st
    LEFT JOIN stores fs ON fs.id = st.from_store_id
    LEFT JOIN stores ts ON ts.id = st.to_store_id
    LEFT JOIN products p ON p.id = st.product_id
    LEFT JOIN users u ON u.id = st.transferred_by
    WHERE st.is_deleted = 0 ORDER BY st.created_at DESC LIMIT 50
")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Stock Transfer - Oro Store</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <style>
        .transfer-panel { display:flex; gap:0; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; height:52vh; margin-bottom:20px; background:#fff; }
        .tp-left { flex:3; display:flex; flex-direction:column; border-right:1px solid #e2e8f0; }
        .tp-right { flex:2; display:flex; flex-direction:column; min-width:320px; }
        .tp-header { padding:12px 16px; background:#f8fafc; border-bottom:1px solid #e2e8f0; }
        .tp-header h3 { font-size:14px; font-weight:700; color:#1e293b; margin:0; }
        .tp-header .sub { font-size:11px; color:#64748b; }
        .tp-search { padding:8px 12px; border-bottom:1px solid #e2e8f0; }
        .tp-search input { width:100%; padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:13px; outline:none; }
        .tp-search input:focus { border-color:#6366f1; }
        .tp-products { flex:1; overflow-y:auto; padding:6px; }
        .tp-product { padding:8px 12px; border:2px solid transparent; border-radius:8px; cursor:pointer; margin-bottom:3px; transition:all .15s; display:flex; justify-content:space-between; align-items:center; }
        .tp-product:hover { background:#f8fafc; border-color:#e2e8f0; }
        .tp-product.selected { border-color:#6366f1; background:#eef2ff; }
        .tp-pname { font-weight:600; font-size:13px; color:#1e293b; }
        .tp-tags { display:flex; gap:3px; margin-top:2px; flex-wrap:wrap; }
        .ptag { font-size:9px; padding:1px 5px; border-radius:8px; font-weight:600; }
        .ptag-unit { background:#fef9c3; color:#854d0e; }
        .ptag-cat { background:#f3e8ff; color:#7c3aed; }
        .ptag-brand { background:#dbeafe; color:#2563eb; }
        .tp-stock { font-size:11px; padding:2px 8px; border-radius:8px; font-weight:600; white-space:nowrap; }
        .tp-stock-ok { background:#dcfce7; color:#166534; }
        .tp-stock-low { background:#fef3c7; color:#92400e; }

        .tp-cart { flex:1; overflow-y:auto; padding:6px; }
        .tp-cart-empty { text-align:center; padding:30px 16px; color:#94a3b8; }
        .tp-cart-item { padding:8px 12px; border:1.5px solid #e2e8f0; border-radius:8px; margin-bottom:4px; display:flex; justify-content:space-between; align-items:center; transition:all .15s; }
        .tp-cart-item.selected { border-color:#6366f1; background:#eef2ff; }
        .tp-cart-name { font-weight:600; font-size:13px; color:#1e293b; }
        .tp-cart-qty { display:flex; align-items:center; gap:6px; }
        .tp-cart-qty input { width:60px; padding:4px 6px; border:1.5px solid #e2e8f0; border-radius:6px; text-align:center; font-size:13px; font-weight:700; }
        .tp-cart-qty input:focus { border-color:#6366f1; outline:none; }
        .tp-cart-max { font-size:10px; color:#94a3b8; }
        .tp-cart-remove { background:none; border:none; color:#ef4444; cursor:pointer; font-size:16px; padding:2px 6px; border-radius:4px; }
        .tp-cart-remove:hover { background:#fee2e2; }

        .tp-footer { padding:12px 16px; border-top:2px solid #1e293b; background:#f8fafc; display:flex; justify-content:space-between; align-items:center; }
        .tp-total { font-size:14px; font-weight:700; color:#1e293b; }
        .tp-total span { color:#6366f1; }
        .btn-transfer { padding:8px 20px; background:#6366f1; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; }
        .btn-transfer:hover { background:#4f46e5; }
        .btn-transfer:disabled { opacity:.5; cursor:default; }

        .store-selectors { display:flex; gap:12px; margin-bottom:12px; align-items:flex-end; flex-wrap:wrap; }
        .store-selectors .form-group { flex:1; min-width:200px; margin-bottom:0; }
        .store-selectors label { font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:.5px; display:block; margin-bottom:3px; }
        .store-selectors select { width:100%; padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:13px; }
        .store-selectors .notes-group { flex:2; }
        .store-selectors .notes-group input { width:100%; padding:9px 12px; border:1.5px solid #e2e8f0; border-radius:8px; font-size:13px; }

        .transfer-alert { padding:10px 16px; border-radius:8px; margin-bottom:12px; font-size:13px; font-weight:600; display:none; }
        .transfer-alert.success { background:#dcfce7; color:#166534; display:block; }
        .transfer-alert.error { background:#fee2e2; color:#991b1b; display:block; }

        .table-scroll { max-height:40vh; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px; }
        .table-scroll table { margin-bottom:0; }
        .table-scroll thead th { position:sticky; top:0; z-index:2; background:#f8fafc; }
        .batch-badge { font-size:10px; padding:1px 6px; border-radius:4px; background:#eef2ff; color:#4338ca; font-weight:600; }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>
    <main class="main-content">
        <div class="page-header"><h1>Stock Transfer</h1><p>Transfer products between stores</p></div>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-icon blue">&#128260;</div><div class="stat-label">Total Transfers</div><div class="stat-value"><?php echo number_format($stats['total']); ?></div></div>
            <div class="stat-card"><div class="stat-icon purple">&#128197;</div><div class="stat-label">This Month</div><div class="stat-value"><?php echo number_format($stats['this_month']); ?></div></div>
            <div class="stat-card"><div class="stat-icon green">&#128198;</div><div class="stat-label">Today</div><div class="stat-value"><?php echo number_format($stats['today']); ?></div></div>
            <div class="stat-card"><div class="stat-icon orange">&#128230;</div><div class="stat-label">Units This Month</div><div class="stat-value"><?php echo number_format($stats['units_month']); ?></div></div>
        </div>

        <div class="content-section">
            <div class="section-header"><div class="section-title">New Transfer</div></div>
            <div id="transfer-alert" class="transfer-alert"></div>

            <div class="store-selectors">
                <div class="form-group">
                    <label>From Store</label>
                    <?php
                    @include_once __DIR__ . '/../sync/config.php';
                    $_my_dev = defined('LOCAL_DEVICE_ID') ? LOCAL_DEVICE_ID : '';
                    $_my_store_id = 0;
                    if ($currentUser['store_id']) {
                        $_my_store_id = $currentUser['store_id'];
                    } else {
                        foreach ($stores as $s) { if (isset($s['device_id']) && $s['device_id'] === $_my_dev) { $_my_store_id = $s['id']; break; } }
                    }
                    ?>
                    <select id="from_store" onchange="onStoreChange()">
                        <option value="">-- Source --</option>
                        <?php foreach ($stores as $s): ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo $s['id'] == $_my_store_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['store_name']); ?> (<?php echo $s['store_code']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>To Store</label>
                    <select id="to_store">
                        <option value="">-- Destination --</option>
                        <?php foreach ($stores as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['store_name']); ?> (<?php echo $s['store_code']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group notes-group">
                    <label>Notes (optional)</label>
                    <input type="text" id="transfer_notes" placeholder="Reason for transfer...">
                </div>
            </div>

            <!-- Mini Cashier Panel -->
            <div class="transfer-panel">
                <div class="tp-left">
                    <div class="tp-header"><h3>Product Selection</h3><div class="sub">Search products from the source store</div></div>
                    <div class="tp-search"><input type="text" id="product_search" placeholder="Search by name, barcode, brand, category..." autocomplete="off"></div>
                    <div class="tp-products" id="product_list">
                        <div class="tp-cart-empty">Select a source store, then search for products</div>
                    </div>
                </div>
                <div class="tp-right">
                    <div class="tp-header"><h3>Transfer Cart</h3><div class="sub" id="cart-subtitle">Add products to transfer</div></div>
                    <div class="tp-cart" id="cart_section">
                        <div class="tp-cart-empty">Click products on the left to add them</div>
                    </div>
                    <div class="tp-footer">
                        <div class="tp-total"><span id="cart-count">0</span> products, <span id="cart-units">0</span> units</div>
                        <button class="btn-transfer" id="btn_transfer" onclick="submitTransfer()" disabled>Transfer All</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- History -->
        <div class="content-section">
            <div class="section-header"><div class="section-title">Transfer History</div><span style="color:#64748b;font-size:13px;">Last 50</span></div>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Date</th><th>Batch</th><th>From</th><th>To</th><th>Product</th><th>Qty</th><th>By</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php if (empty($transfers)): ?>
                            <tr><td colspan="8" class="empty-state">No transfers yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $t): ?>
                            <tr>
                                <td><?php echo date('M d, g:i A', strtotime($t['created_at'])); ?></td>
                                <td><?php if ($t['transfer_batch_id']): ?><span class="batch-badge"><?php echo htmlspecialchars($t['transfer_batch_id']); ?></span><?php endif; ?></td>
                                <td><?php echo htmlspecialchars($t['from_store_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($t['to_store_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($t['product_name'] ?? ''); ?></td>
                                <td><strong><?php echo $t['quantity']; ?></strong></td>
                                <td><?php echo htmlspecialchars($t['transferred_by_name'] ?? ''); ?></td>
                                <td style="font-size:12px;color:#64748b;"><?php echo htmlspecialchars($t['notes'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

<script>
let cart = [];
let searchTimeout = null;
let productResults = [];
let selectedProductIdx = 0;
let selectedCartIdx = 0;
let currentPanel = 'left';

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function onStoreChange() {
    cart = [];
    renderCart();
    document.getElementById('product_search').value = '';
    document.getElementById('product_list').innerHTML = '<div class="tp-cart-empty">Type to search products</div>';
}

// Search
document.getElementById('product_search').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const q = this.value.trim();
    const storeId = document.getElementById('from_store').value;
    if (!storeId) { showAlert('Select a source store first.', 'error'); return; }
    if (q.length < 1) { document.getElementById('product_list').innerHTML = '<div class="tp-cart-empty">Type to search products</div>'; return; }

    searchTimeout = setTimeout(() => {
        fetch(`/oro-store-demo/stock/stock_transfer.php?action=search_products&store_id=${storeId}&q=${encodeURIComponent(q)}`)
        .then(r => r.json()).then(products => {
            productResults = products;
            selectedProductIdx = 0;
            renderProducts();
        });
    }, 200);
});

function renderProducts() {
    const list = document.getElementById('product_list');
    if (!productResults.length) { list.innerHTML = '<div class="tp-cart-empty">No products found</div>'; return; }

    list.innerHTML = productResults.map((p, i) => {
        const inCart = cart.find(c => c.id == p.id);
        const stock = parseInt(p.stock);
        const stockCls = stock <= 5 ? 'tp-stock-low' : 'tp-stock-ok';
        let tags = '';
        if (p.individual_sell_unit) tags += `<span class="ptag ptag-unit">${esc(p.individual_sell_unit)}</span>`;
        if (p.category_name) tags += `<span class="ptag ptag-cat">${esc(p.category_name)}</span>`;
        if (p.brand_name) tags += `<span class="ptag ptag-brand">${esc(p.brand_name)}</span>`;

        return `<div class="tp-product ${i === selectedProductIdx && currentPanel === 'left' ? 'selected' : ''} ${inCart ? 'in-cart' : ''}"
                    data-idx="${i}" onclick="addToCart(${i})" style="${inCart ? 'opacity:.5;' : ''}">
            <div>
                <div class="tp-pname">${esc(p.name)} ${inCart ? '<span style="color:#6366f1;font-size:11px;">(in cart)</span>' : ''}</div>
                ${tags ? `<div class="tp-tags">${tags}</div>` : ''}
            </div>
            <span class="tp-stock ${stockCls}">Stock: ${stock}</span>
        </div>`;
    }).join('');
}

function addToCart(idx) {
    const p = productResults[idx];
    if (!p) return;
    if (cart.find(c => c.id == p.id)) return; // already in cart

    cart.push({
        id: parseInt(p.id),
        name: p.name,
        stock: parseInt(p.stock),
        quantity: 1,
        category: p.category_name || '',
        brand: p.brand_name || '',
        unit: p.individual_sell_unit || ''
    });
    renderCart();
    renderProducts(); // refresh to show "in cart"
}

function renderCart() {
    const section = document.getElementById('cart_section');
    const btn = document.getElementById('btn_transfer');

    if (!cart.length) {
        section.innerHTML = '<div class="tp-cart-empty">Click products on the left to add them</div>';
        document.getElementById('cart-count').textContent = '0';
        document.getElementById('cart-units').textContent = '0';
        btn.disabled = true;
        return;
    }

    let totalUnits = 0;
    section.innerHTML = cart.map((item, i) => {
        totalUnits += item.quantity;
        let tags = '';
        if (item.unit) tags += `<span class="ptag ptag-unit">${esc(item.unit)}</span>`;
        if (item.category) tags += `<span class="ptag ptag-cat">${esc(item.category)}</span>`;
        if (item.brand) tags += `<span class="ptag ptag-brand">${esc(item.brand)}</span>`;

        return `<div class="tp-cart-item ${i === selectedCartIdx && currentPanel === 'right' ? 'selected' : ''}" data-idx="${i}">
            <div style="flex:1;min-width:0;">
                <div class="tp-cart-name">${esc(item.name)}</div>
                ${tags ? `<div class="tp-tags" style="margin-top:2px;">${tags}</div>` : ''}
            </div>
            <div class="tp-cart-qty">
                <div>
                    <input type="number" value="${item.quantity}" min="1" max="${item.stock}"
                        onchange="updateQty(${i}, this.value)" onfocus="this.select()">
                    <div class="tp-cart-max">max: ${item.stock}</div>
                </div>
                <button class="tp-cart-remove" onclick="removeFromCart(${i})" title="Remove">&times;</button>
            </div>
        </div>`;
    }).join('');

    document.getElementById('cart-count').textContent = cart.length;
    document.getElementById('cart-units').textContent = totalUnits;
    btn.disabled = false;
}

function updateQty(idx, val) {
    const qty = parseInt(val) || 1;
    cart[idx].quantity = Math.max(1, Math.min(qty, cart[idx].stock));
    renderCart();
}

function removeFromCart(idx) {
    cart.splice(idx, 1);
    if (selectedCartIdx >= cart.length) selectedCartIdx = Math.max(0, cart.length - 1);
    renderCart();
    renderProducts();
}

function submitTransfer() {
    const fromStore = document.getElementById('from_store').value;
    const toStore = document.getElementById('to_store').value;
    if (!fromStore) { showAlert('Select a source store.', 'error'); return; }
    if (!toStore) { showAlert('Select a destination store.', 'error'); return; }
    if (fromStore === toStore) { showAlert('Stores must be different.', 'error'); return; }
    if (!cart.length) { showAlert('Cart is empty.', 'error'); return; }

    // Validate quantities
    for (const item of cart) {
        if (item.quantity > item.stock) {
            showAlert(`${item.name}: quantity (${item.quantity}) exceeds stock (${item.stock}).`, 'error');
            return;
        }
    }

    const totalProducts = cart.length;
    const totalUnits = cart.reduce((s, c) => s + c.quantity, 0);
    if (!confirm(`Transfer ${totalProducts} products (${totalUnits} units)?`)) return;

    const btn = document.getElementById('btn_transfer');
    btn.disabled = true; btn.textContent = 'Transferring...';

    const fd = new FormData();
    fd.append('action', 'bulk_transfer');
    fd.append('from_store_id', fromStore);
    fd.append('to_store_id', toStore);
    fd.append('items', JSON.stringify(cart.map(c => ({ id: c.id, quantity: c.quantity }))));
    fd.append('notes', document.getElementById('transfer_notes').value);

    fetch('/oro-store-demo/stock/stock_transfer.php', { method: 'POST', body: fd })
    .then(r => r.json()).then(data => {
        if (data.success) {
            showAlert(data.message + ' (Batch: ' + data.batch_id + ')', 'success');
            cart = [];
            renderCart();
            document.getElementById('product_search').value = '';
            document.getElementById('product_list').innerHTML = '<div class="tp-cart-empty">Type to search products</div>';
            document.getElementById('transfer_notes').value = '';
            setTimeout(() => location.reload(), 1500);
        } else {
            showAlert(data.error, 'error');
        }
    }).catch(e => showAlert('Error: ' + e, 'error'))
    .finally(() => { btn.disabled = false; btn.textContent = 'Transfer All'; });
}

// Keyboard navigation
document.addEventListener('keydown', function(e) {
    // Allow Enter on search input to add selected product
    if (e.target.id === 'product_search' && e.key === 'Enter') {
        e.preventDefault();
        addToCart(selectedProductIdx);
        return;
    }
    // Allow arrow keys on search input for product navigation
    if (e.target.id === 'product_search' && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
        e.preventDefault();
        if (e.key === 'ArrowDown') selectedProductIdx = Math.min(selectedProductIdx + 1, productResults.length - 1);
        else selectedProductIdx = Math.max(selectedProductIdx - 1, 0);
        renderProducts();
        return;
    }
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') {
        if (e.key === 'Escape') { e.target.blur(); e.preventDefault(); }
        return;
    }

    if (currentPanel === 'left') {
        if (e.key === 'ArrowDown') { e.preventDefault(); selectedProductIdx = Math.min(selectedProductIdx + 1, productResults.length - 1); renderProducts(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); selectedProductIdx = Math.max(selectedProductIdx - 1, 0); renderProducts(); }
        else if (e.key === 'Enter') { e.preventDefault(); addToCart(selectedProductIdx); }
        else if (e.key === 'Home') { e.preventDefault(); if (cart.length) { currentPanel = 'right'; selectedCartIdx = 0; renderCart(); } }
    } else {
        if (e.key === 'ArrowDown') { e.preventDefault(); selectedCartIdx = Math.min(selectedCartIdx + 1, cart.length - 1); renderCart(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); selectedCartIdx = Math.max(selectedCartIdx - 1, 0); renderCart(); }
        else if (e.key === 'Delete') { e.preventDefault(); if (cart.length) removeFromCart(selectedCartIdx); }
        else if (e.key === 'Enter') { e.preventDefault(); submitTransfer(); }
        else if (e.key === 'Home' || e.key === 'Escape') { e.preventDefault(); currentPanel = 'left'; renderProducts(); renderCart(); document.getElementById('product_search').focus(); }
    }
});

function showAlert(msg, type) {
    const el = document.getElementById('transfer-alert');
    el.textContent = msg;
    el.className = 'transfer-alert ' + type;
    if (type === 'success') setTimeout(() => { el.className = 'transfer-alert'; }, 5000);
}
// Auto-load products if source store is pre-selected
if (document.getElementById('from_store').value) onStoreChange();
</script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Stock Transfer', array (
  'Features' => 
  array (
    0 => 'Transfer stock between stores',
    1 => 'Select source and destination store',
    2 => 'Pick products and quantities to transfer',
    3 => 'Stock deducted from source, added to destination',
    4 => 'Transfer receipt saved for audit trail',
  ),
));
?>
</body>
</html>
