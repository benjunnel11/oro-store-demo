<?php
ob_start();
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/sync_helper.php';
require_once __DIR__ . '/../core/system_logger.php';

$db = new SyncDB();
$currentUser = getCurrentUser();

$userStore = null;
$_store_id = $currentUser['store_id'] ?: ($_SESSION['admin_cashier_store'] ?? null);
if (!$_store_id) {
    // Admin with no store — auto-select first active store
    $first = $conn->query("SELECT id FROM stores WHERE status='active' LIMIT 1")->fetch_assoc();
    if ($first) $_store_id = $first['id'];
}
if ($_store_id) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $_store_id);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Auto-create stock_receipts tables
$conn->query("CREATE TABLE IF NOT EXISTS stock_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(255) DEFAULT '',
    invoice_number VARCHAR(100) DEFAULT '',
    receipt_date DATE,
    total_items INT DEFAULT 0,
    total_cost DECIMAL(12,2) DEFAULT 0,
    total_selling_value DECIMAL(12,2) DEFAULT 0,
    notes TEXT,
    user_id INT,
    user_name VARCHAR(255),
    store_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0
)");
$conn->query("CREATE TABLE IF NOT EXISTS stock_receipt_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_id INT,
    product_id INT,
    product_name VARCHAR(255),
    quantity INT,
    purchase_price DECIMAL(12,2),
    selling_price DECIMAL(12,2),
    old_stock INT,
    new_stock INT,
    old_selling_price DECIMAL(12,2),
    new_selling_price DECIMAL(12,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Handle get recent receipts
if (isset($_GET['action']) && $_GET['action'] === 'get_receipts') {
    ob_clean(); header('Content-Type: application/json');
    $store_cond = $userStore ? "AND sr.store_id = " . intval($userStore['id']) : "";
    $res = $conn->query("SELECT sr.*,
        (SELECT COUNT(*) FROM stock_receipt_items WHERE receipt_id = sr.id) as item_count
        FROM stock_receipts sr
        WHERE sr.is_deleted = 0 $store_cond
        ORDER BY sr.created_at DESC LIMIT 20");
    $receipts = $res->fetch_all(MYSQLI_ASSOC);
    echo json_encode($receipts);
    $conn->close();
    exit;
}

// Handle get receipt details
if (isset($_GET['action']) && $_GET['action'] === 'get_receipt_items') {
    ob_clean(); header('Content-Type: application/json');
    $rid = intval($_GET['receipt_id']);
    $res = $conn->query("SELECT * FROM stock_receipt_items WHERE receipt_id = $rid ORDER BY id");
    $items = $res->fetch_all(MYSQLI_ASSOC);
    echo json_encode($items);
    $conn->close();
    exit;
}

// Handle bulk stock update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_update_stock') {
    header('Content-Type: application/json');

    $items = json_decode($_POST['items'], true);
    $total_cost = floatval($_POST['total_cost']);
    $total_selling = floatval($_POST['total_selling']);
    $total_items = intval($_POST['total_items']);
    $supplier_name = $_POST['supplier_name'] ?? '';
    $invoice_number = $_POST['invoice_number'] ?? '';
    $notes = $_POST['notes'] ?? '';

    $conn->begin_transaction();

    try {
        // Create stock receipt record
        $stmt = $conn->prepare("INSERT INTO stock_receipts (supplier_name, invoice_number, receipt_date, total_items, total_cost, total_selling_value, notes, user_id, user_name, store_id) VALUES (?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?)");
        $store_id = $userStore ? $userStore['id'] : null;
        $stmt->bind_param("ssiddsiis", $supplier_name, $invoice_number, $total_items, $total_cost, $total_selling, $notes, $currentUser['id'], $currentUser['full_name'], $store_id);
        $stmt->execute();
        $receipt_id = $conn->insert_id;
        $stmt->close();

        $activity_details = [];

        foreach ($items as $item) {
            $product_id = intval($item['id']);
            $stock_to_add = intval($item['stock_add']);
            $new_price = floatval($item['new_price']);
            $purchase_price = floatval($item['purchase_price']);

            // Get current product data (store-aware)
            if ($userStore) {
                $stmt = $conn->prepare("SELECT p.name, sp.stock, sp.price, sp.purchase_price as current_pp FROM products p INNER JOIN store_prices sp ON p.id = sp.product_id AND sp.store_id = ? WHERE p.id = ?");
                $stmt->bind_param("ii", $userStore['id'], $product_id);
            } else {
                $stmt = $conn->prepare("SELECT name, stock, price, purchase_price as current_pp FROM products WHERE id = ?");
                $stmt->bind_param("i", $product_id);
            }
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $product_name = $product['name'];
            $old_stock = (int)$product['stock'];
            $old_price = (float)$product['price'];
            $new_stock = $old_stock + $stock_to_add;

            // Update stock and cost price as entered, selling price stays current
            if ($userStore) {
                $db->update('store_prices', [
                    'stock' => $new_stock,
                    'purchase_price' => $purchase_price
                ], "product_id = $product_id AND store_id = " . $userStore['id']);
            } else {
                $db->update('products', [
                    'stock' => $new_stock,
                    'purchase_price' => $purchase_price
                ], "id = $product_id");
            }

            // Save receipt item
            $stmt = $conn->prepare("INSERT INTO stock_receipt_items (receipt_id, product_id, product_name, quantity, purchase_price, selling_price, old_stock, new_stock, old_selling_price, new_selling_price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisiddiidd", $receipt_id, $product_id, $product_name, $stock_to_add, $purchase_price, $new_price, $old_stock, $new_stock, $old_price, $new_price);
            $stmt->execute();
            $stmt->close();

            if ($stock_to_add != 0) {
                logActivity('product', "Added $stock_to_add units to $product_name", $currentUser['id'], $store_id, [
                    'product_id' => $product_id, 'product_name' => $product_name,
                    'old_stock' => $old_stock, 'new_stock' => $new_stock, 'stock_added' => $stock_to_add,
                    'receipt_id' => $receipt_id, 'supplier' => $supplier_name
                ]);
                $db->insert('product_history', [
                    'product_id' => $product_id, 'change_type' => 'stock',
                    'old_value' => (string)$old_stock, 'new_value' => (string)$new_stock,
                    'user_id' => $currentUser['id'], 'user_name' => $currentUser['full_name']
                ]);
            }

            if ($new_price != $old_price) {
                logActivity('product', "Updated price for $product_name", $currentUser['id'], $store_id, [
                    'product_id' => $product_id, 'old_price' => $old_price, 'new_price' => $new_price
                ]);
                $db->insert('product_history', [
                    'product_id' => $product_id, 'change_type' => 'price',
                    'old_value' => (string)$old_price, 'new_value' => (string)$new_price,
                    'user_id' => $currentUser['id'], 'user_name' => $currentUser['full_name']
                ]);
            }

            $activity_details[] = [
                'product_name' => $product_name, 'stock_added' => $stock_to_add,
                'purchase_price' => $purchase_price, 'selling_price' => $new_price,
                'cost_value' => $stock_to_add * $purchase_price
            ];
        }

        logActivity('product', "Stock receipt #$receipt_id completed" . ($supplier_name ? " from $supplier_name" : ""), $currentUser['id'], $store_id, [
            'receipt_id' => $receipt_id, 'supplier' => $supplier_name, 'invoice' => $invoice_number,
            'total_products' => count($items), 'total_items_added' => $total_items,
            'total_cost' => $total_cost, 'total_selling' => $total_selling, 'products' => $activity_details
        ]);

        $conn->commit();
        echo json_encode(['success' => true, 'receipt_id' => $receipt_id]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Fetch products with tags
if ($userStore) {
    $stmt = $conn->prepare("SELECT p.id, p.name, p.description, p.barcode, p.parent_product_id,
                           sp.price, sp.purchase_price, sp.stock,
                           COALESCE(pc.category_name, ppc.category_name) as category_name,
                           COALESCE(pb.brand_name, ppb.brand_name) as brand_name,
                           pp.individual_sell_unit
                           FROM products p
                           INNER JOIN store_prices sp ON p.id = sp.product_id AND sp.store_id = ?
                           LEFT JOIN product_categories pc ON p.category_id = pc.id AND pc.is_deleted = 0
                           LEFT JOIN product_brands pb ON p.brand_id = pb.id AND pb.is_deleted = 0
                           LEFT JOIN products pp ON p.parent_product_id = pp.id
                           LEFT JOIN product_categories ppc ON pp.category_id = ppc.id AND ppc.is_deleted = 0
                           LEFT JOIN product_brands ppb ON pp.brand_id = ppb.id AND ppb.is_deleted = 0
                           WHERE p.is_deleted = 0 AND sp.is_deleted = 0
                           ORDER BY p.name");
    $stmt->bind_param("i", $userStore['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $products = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $result = $conn->query("SELECT p.id, p.name, p.description, p.barcode, p.parent_product_id,
                           p.price, p.purchase_price, p.stock,
                           COALESCE(pc.category_name, ppc.category_name) as category_name,
                           COALESCE(pb.brand_name, ppb.brand_name) as brand_name,
                           pp.individual_sell_unit
                           FROM products p
                           LEFT JOIN product_categories pc ON p.category_id = pc.id AND pc.is_deleted = 0
                           LEFT JOIN product_brands pb ON p.brand_id = pb.id AND pb.is_deleted = 0
                           LEFT JOIN products pp ON p.parent_product_id = pp.id
                           LEFT JOIN product_categories ppc ON pp.category_id = ppc.id AND ppc.is_deleted = 0
                           LEFT JOIN product_brands ppb ON pp.brand_id = ppb.id AND ppb.is_deleted = 0
                           WHERE p.is_deleted = 0
                           ORDER BY p.name");
    $products = $result->fetch_all(MYSQLI_ASSOC);
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Add Stock - Oro Store</title>
    <style>
        /* ── Reset ── */
        * { margin:0; padding:0; box-sizing:border-box; }
        html, body { height:100%; overflow:hidden; }
        body { font-family:'Segoe UI',sans-serif; background:#f4f6f8; color:#222; font-size:13px; }

        /* ── Top bar ── */
        .shortcut-bar { display:flex; gap:4px; padding:4px 10px; background:#1e293b; flex-wrap:wrap; align-items:center; }
        .sc-key { font-size:10px; color:#94a3b8; cursor:pointer; padding:2px 6px; border-radius:3px; white-space:nowrap; }
        .sc-key:hover { background:rgba(255,255,255,.08); }
        .sc-key kbd { background:#334155; color:#e2e8f0; padding:0 4px; border-radius:2px; font-size:9px; margin-right:2px; border:1px solid #475569; }
        .sc-key.sc-green { color:#86efac; }
        .sc-key.sc-red { color:#fca5a5; }
        .sc-key.sc-blue { color:#93c5fd; }

        /* ── Main 2-column layout ── */
        .stock-container { display:flex; height:calc(100vh - 30px); }

        /* ── LEFT: Products ── */
        .stock-left-panel { flex:1; display:flex; flex-direction:column; background:#fff; border-right:1px solid #ddd; min-width:0; }
        .panel-header { padding:8px 12px; border-bottom:1px solid #ddd; display:flex; justify-content:space-between; align-items:center; background:#f8f9fa; }
        .panel-header h2 { font-size:14px; font-weight:700; }
        .panel-header .sub { font-size:11px; color:#666; }
        .btn-close { padding:4px 10px; background:#e74c3c; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:600; font-size:11px; }
        .search-section { padding:6px 10px; border-bottom:1px solid #eee; position:relative; }
        #search-input { width:100%; padding:7px 10px; font-size:13px; border:1px solid #ccc; border-radius:6px; outline:none; }
        #search-input:focus { border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,.15); }
        .products-list { flex:1; overflow-y:auto; padding:4px 6px; }

        /* Product rows */
        .product-item-stock { display:flex; align-items:center; gap:8px; padding:6px 10px; border:1px solid transparent; border-radius:6px; margin-bottom:2px; cursor:pointer; }
        .product-item-stock:hover { background:#f0f4ff; }
        .product-item-stock.selected { border-color:#6366f1; background:#eef2ff; }
        .product-main-info { display:flex; align-items:center; gap:8px; width:100%; }
        .product-name-col { flex:1; min-width:0; overflow:hidden; }
        .product-name-stock { font-weight:600; font-size:13px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .product-tags { display:flex; gap:3px; flex-wrap:wrap; margin-top:1px; }
        .ptag { font-size:9px; padding:0 5px; border-radius:8px; font-weight:600; line-height:16px; }
        .ptag-unit { background:#fef9c3; color:#854d0e; }
        .ptag-cat { background:#f3e8ff; color:#7c3aed; }
        .ptag-brand { background:#dbeafe; color:#2563eb; }
        .price-col { text-align:right; white-space:nowrap; }
        .price-sell { font-weight:700; font-size:13px; }
        .price-cost { font-size:10px; color:#999; }
        .stock-badge { font-size:10px; padding:1px 6px; border-radius:8px; font-weight:600; white-space:nowrap; }
        .stock-ok { background:#dcfce7; color:#166534; }
        .stock-low { background:#fef3c7; color:#92400e; }
        .stock-out { background:#fee2e2; color:#991b1b; }

        /* ── RIGHT: Cart + History ── */
        .stock-right-panel { flex:1; display:flex; flex-direction:column; background:#fff; min-width:0; }

        /* Supplier fields */
        .supply-info { padding:6px 10px; border-bottom:1px solid #eee; background:#f8f9fa; }
        .supply-info label { font-size:10px; font-weight:600; color:#888; text-transform:uppercase; display:block; margin-bottom:2px; }
        .supply-row { display:flex; gap:6px; margin-bottom:4px; }
        .supply-row input { flex:1; padding:5px 8px; font-size:12px; border:1px solid #ccc; border-radius:4px; outline:none; }
        .supply-row input:focus { border-color:#6366f1; }
        .supply-notes textarea { width:100%; padding:4px 8px; font-size:11px; border:1px solid #ccc; border-radius:4px; outline:none; resize:none; height:28px; font-family:inherit; }
        .supply-notes textarea:focus { border-color:#6366f1; }

        /* Tabs */
        .tab-bar { display:flex; border-bottom:2px solid #eee; background:#f8f9fa; }
        .tab-btn { flex:1; padding:6px; text-align:center; font-size:12px; font-weight:600; color:#888; cursor:pointer; border:none; background:none; border-bottom:2px solid transparent; margin-bottom:-2px; }
        .tab-btn.active { color:#6366f1; border-bottom-color:#6366f1; }
        .tab-content { display:none; }
        .tab-content.active { display:flex; flex-direction:column; flex:1; overflow:hidden; }

        /* Cart */
        .cart-section { flex:1; overflow-y:auto; padding:4px 6px; }
        .cart-empty { text-align:center; padding:30px 10px; color:#aaa; }
        .cart-empty h3 { font-size:14px; margin-bottom:4px; color:#888; }
        .cart-item { padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:3px; }
        .cart-item.selected { border-color:#6366f1; background:#eef2ff; }
        .cart-item-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:2px; }
        .cart-item-name { font-weight:700; font-size:12px; }
        .cart-item-tags { display:flex; gap:2px; margin-top:1px; }
        .cart-cost-badge { font-size:11px; font-weight:700; color:#dc2626; }
        .cart-item-details { font-size:11px; color:#666; line-height:1.6; }
        .cart-detail-row { display:flex; justify-content:space-between; }
        .cart-detail-label { color:#aaa; }
        .cart-detail-val { font-weight:600; }
        .val-green { color:#16a34a; }
        .val-red { color:#dc2626; }
        .val-blue { color:#2563eb; }

        /* Cart totals */
        .cart-total { padding:8px 10px; border-top:2px solid #222; background:#f8f9fa; }
        .total-row { display:flex; justify-content:space-between; padding:2px 0; font-size:12px; color:#555; }
        .total-row.grand { font-size:14px; font-weight:800; color:#fff; border-top:1px solid #ddd; padding:10px; margin-top:4px; }
        .total-row.selling { font-size:12px; font-weight:700; color:#2563eb; }
        .total-row.margin { font-size:11px; font-weight:600; color:#16a34a; }

        /* ── Modals ── */
        .modal { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,.4); z-index:1000; align-items:center; justify-content:center; }
        .modal.active { display:flex; }
        .modal-content { background:#fff; border-radius:10px; padding:20px; width:90%; max-width:380px; box-shadow:0 10px 40px rgba(0,0,0,.2); }
        .modal-content h2 { font-size:16px; margin-bottom:12px; }
        .modal-info { font-size:12px; color:#666; margin-bottom:3px; }
        .modal-info strong { color:#222; }
        .form-group { margin:10px 0; }
        .form-group label { display:block; font-size:11px; font-weight:600; color:#555; margin-bottom:3px; text-transform:uppercase; }
        .form-group input { width:100%; padding:8px 10px; font-size:14px; border:1px solid #ccc; border-radius:6px; outline:none; }
        .form-group input:focus { border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,.15); }
        .modal-buttons { display:flex; gap:8px; margin-top:14px; }
        .btn-confirm { flex:1; padding:8px; background:#6366f1; color:#fff; border:none; border-radius:6px; font-weight:700; font-size:13px; cursor:pointer; }
        .btn-confirm:hover { background:#4f46e5; }
        .btn-cancel { flex:1; padding:8px; background:#f1f5f9; color:#555; border:none; border-radius:6px; font-weight:600; font-size:13px; cursor:pointer; }
        .btn-cancel:hover { background:#e2e8f0; }

        /* Confirm breakdown */
        .confirm-breakdown { background:#f8f9fa; border-radius:6px; padding:10px; margin:10px 0; }
        .confirm-row { display:flex; justify-content:space-between; font-size:12px; padding:2px 0; }
        .confirm-row.total { font-weight:800; font-size:14px; border-top:1px solid #ddd; padding-top:6px; margin-top:4px; }
        .confirm-row.cost { color:#dc2626; }
        .confirm-row.sell { color:#2563eb; }
        .confirm-row.margin-row { color:#16a34a; }

        /* History */
        .history-list { flex:1; overflow-y:auto; padding:6px; }
        .history-item { padding:8px 10px; border:1px solid #e2e8f0; border-radius:6px; margin-bottom:4px; cursor:pointer; }
        .history-item:hover { background:#f0f4ff; border-color:#6366f1; }
        .history-header { display:flex; justify-content:space-between; align-items:center; }
        .history-supplier { font-weight:700; font-size:12px; }
        .history-date { font-size:10px; color:#999; }
        .history-meta { display:flex; gap:10px; margin-top:2px; font-size:10px; color:#666; }
        .history-cost { color:#dc2626; font-weight:600; }
        .history-invoice { color:#6366f1; font-weight:600; }

        /* Receipt table */
        .receipt-table { width:100%; border-collapse:collapse; font-size:11px; margin-top:8px; }
        .receipt-table th { background:#f1f5f9; padding:4px 6px; text-align:left; font-size:10px; color:#666; text-transform:uppercase; }
        .receipt-table td { padding:4px 6px; border-bottom:1px solid #f1f5f9; }
        .receipt-table .num { text-align:right; }

        /* Search suggestions + tags */
        #stock-suggestions { display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #ddd; border-radius:0 0 6px 6px; box-shadow:0 4px 12px rgba(0,0,0,.1); max-height:180px; overflow-y:auto; z-index:100; }
        #stock-search-tags { display:flex; gap:3px; flex-wrap:wrap; padding:2px 0; }
    </style>
</head>
<body>

<div class="shortcut-bar">
    <a href="/oro-store/cashier/cashier.php" style="background:#2563eb;color:#fff;padding:3px 12px;font-size:11px;font-weight:700;text-decoration:none;border-radius:4px;white-space:nowrap;">Back</a>
    <span class="sc-key sc-blue"><kbd>↑↓</kbd> Nav</span>
    <span class="sc-key sc-green" onclick="handleEnter()"><kbd>Enter</kbd> Select</span>
    <span class="sc-key sc-red" onclick="handleEsc()"><kbd>Esc</kbd> Back</span>
    <span class="sc-key" onclick="switchPanel()"><kbd>Home</kbd> Switch</span>
    <span class="sc-key sc-red" onclick="handleDel()"><kbd>Del</kbd> Remove</span>
    <span class="sc-key" onclick="handleIns()"><kbd>Ins</kbd> Edit</span>
    <span class="sc-key" onclick="switchTab()"><kbd>Tab</kbd> Cart/History</span>
    <span class="sc-key sc-red" onclick="window.close()"><kbd>F12</kbd> Close</span>
</div>

<div class="stock-container">
    <!-- LEFT: Product List -->
    <div class="stock-left-panel">
        <div class="panel-header">
            <div>
                <h2>Products</h2>
                <div class="sub"><?php echo htmlspecialchars($currentUser['full_name']); ?><?php if ($userStore): ?> - <?php echo htmlspecialchars($userStore['store_name']); ?><?php endif; ?></div>
            </div>
            <button onclick="window.close()" class="btn-close">Close</button>
        </div>
        <div class="search-section">
            <input type="text" id="search-input" placeholder="Search product..." autofocus autocomplete="off">
            <div id="stock-search-tags"></div>
            <div id="stock-suggestions"></div>
        </div>
        <div class="products-list" id="products-list">
            <?php foreach ($products as $i => $p):
                $stock = (int)$p['stock'];
                $stockClass = $stock <= 0 ? 'stock-out' : ($stock <= 5 ? 'stock-low' : 'stock-ok');
                $unit = $p['individual_sell_unit'] ?? '';
                $cat = $p['category_name'] ?? '';
                $brand = $p['brand_name'] ?? '';
            ?>
            <div class="product-item-stock" onclick="handleProductTap(this)" data-id="<?php echo $p['id']; ?>" data-name="<?php echo htmlspecialchars($p['name']); ?>" data-price="<?php echo $p['price']; ?>" data-purchase-price="<?php echo $p['purchase_price']; ?>" data-stock="<?php echo $stock; ?>" data-barcode="<?php echo htmlspecialchars($p['barcode'] ?? ''); ?>" data-description="<?php echo htmlspecialchars($p['description'] ?? ''); ?>" data-category="<?php echo htmlspecialchars($cat); ?>" data-brand="<?php echo htmlspecialchars($brand); ?>" data-unit="<?php echo htmlspecialchars($unit); ?>" data-index="<?php echo $i; ?>" style="-webkit-user-select:none;user-select:none;">
                <div class="product-main-info">
                    <div class="product-name-col">
                        <div class="product-name-stock"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div class="product-tags">
                            <?php if ($unit): ?><span class="ptag ptag-unit"><?php echo htmlspecialchars($unit); ?></span><?php endif; ?>
                            <?php if ($cat && empty($p['parent_product_id'])): ?><span class="ptag ptag-cat"><?php echo htmlspecialchars($cat); ?></span><?php endif; ?>
                            <?php if ($brand): ?><span class="ptag ptag-brand"><?php echo htmlspecialchars($brand); ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="price-col">
                        <div class="price-sell">₱<?php echo number_format($p['price'], 2); ?></div>
                        <div class="price-cost">Cost ₱<?php echo number_format($p['purchase_price'], 2); ?></div>
                    </div>
                    <span class="stock-badge <?php echo $stockClass; ?>"><?php echo $stock; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- RIGHT: Cart + History -->
    <div class="stock-right-panel">
        <div class="panel-header">
            <div>
                <h2>Incoming Supply</h2>
                <div class="sub">Add stock from supplier</div>
            </div>
        </div>

        <div class="supply-info">
            <div class="supply-row">
                <div style="flex:1"><label>Supplier</label><input type="text" id="supplier-name" placeholder="Supplier name"></div>
                <div style="flex:1"><label>Invoice #</label><input type="text" id="invoice-number" placeholder="Invoice #"></div>
            </div>
            <div class="supply-notes"><label>Notes</label><textarea id="supply-notes" placeholder="Notes..."></textarea></div>
        </div>

        <div class="tab-bar">
            <button class="tab-btn active" onclick="switchToTab('cart')">Cart <span id="cart-count-badge"></span></button>
            <button class="tab-btn" onclick="switchToTab('history')">History</button>
        </div>

        <div class="tab-content active" id="tab-cart">
            <div class="cart-section" id="cart-section">
                <div class="cart-empty"><h3>Cart is Empty</h3><p>Select a product to add stock</p></div>
            </div>
            <div class="cart-total" id="cart-total" style="display:none;">
                <div class="total-row"><span>Products:</span><span id="total-products">0</span></div>
                <div class="total-row"><span>Total Units:</span><span id="total-items">0</span></div>
                <div class="total-row grand" onclick="switchPanel();switchTab();handleEnter();" style="cursor:pointer;background:#22c55e;border-radius:8px;"><span>TOTAL COST:</span><span id="grand-total-cost">₱0.00</span></div>
                <div class="total-row selling"><span>Selling Value:</span><span id="grand-total-selling">₱0.00</span></div>
                <div class="total-row margin"><span>Margin:</span><span id="grand-margin">₱0.00 (0%)</span></div>
            </div>
        </div>

        <div class="tab-content" id="tab-history">
            <div class="history-list" id="history-list">
                <div class="cart-empty"><h3>Loading...</h3></div>
            </div>
        </div>
    </div>
</div>

<!-- Add to Cart Modal -->
<div class="modal" id="quantity-modal">
    <div class="modal-content">
        <h2 id="modal-product-name">Product</h2>
        <p class="modal-info">Current Stock: <strong id="modal-stock">0</strong></p>
        <p class="modal-info">Current Selling Price: <strong id="modal-price">₱0.00</strong></p>
        <p class="modal-info">Current Cost Price: <strong id="modal-cost">₱0.00</strong></p>
        <div class="form-group">
            <label>Quantity to Add</label>
            <input type="number" id="stock-input" min="0" value="0">
        </div>
        <div class="form-group">
            <label>Cost Price (per unit)</label>
            <input type="number" id="cost-input" step="0.01" min="0">
        </div>
        <input type="hidden" id="price-input">
        <div class="modal-buttons">
            <button class="btn-confirm" onclick="confirmAddToCart()">Add to Cart (Enter)</button>
            <button class="btn-cancel" onclick="closeModal('quantity-modal')">Cancel (Esc)</button>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal" id="edit-modal">
    <div class="modal-content">
        <h2 id="edit-product-name">Edit Item</h2>
        <div class="form-group">
            <label>Quantity</label>
            <input type="number" id="edit-stock-input" min="0">
        </div>
        <div class="form-group">
            <label>Cost Price</label>
            <input type="number" id="edit-cost-input" step="0.01" min="0">
        </div>
        <input type="hidden" id="edit-price-input">
        <div class="modal-buttons">
            <button class="btn-confirm" onclick="confirmEdit()">Save (Enter)</button>
            <button class="btn-cancel" onclick="closeModal('edit-modal')">Cancel (Esc)</button>
        </div>
    </div>
</div>

<!-- Confirm Modal -->
<div class="modal" id="confirm-modal">
    <div class="modal-content">
        <h2>Confirm Stock Receipt</h2>
        <div id="confirm-supplier-info"></div>
        <div class="confirm-breakdown">
            <div class="confirm-row"><span>Products:</span><span id="confirm-products">0</span></div>
            <div class="confirm-row"><span>Total Units:</span><span id="confirm-items">0</span></div>
            <div class="confirm-row total cost"><span>TOTAL COST (COGS):</span><span id="confirm-cost">₱0.00</span></div>
            <div class="confirm-row sell"><span>Selling Value:</span><span id="confirm-selling">₱0.00</span></div>
            <div class="confirm-row margin-row"><span>Expected Margin:</span><span id="confirm-margin">₱0.00</span></div>
        </div>
        <div class="modal-buttons">
            <button class="btn-confirm" onclick="saveAllUpdates()">Confirm Receipt (Enter)</button>
            <button class="btn-cancel" onclick="closeModal('confirm-modal')">Cancel (Esc)</button>
        </div>
    </div>
</div>

<!-- Receipt Detail Modal -->
<div class="modal" id="receipt-modal">
    <div class="modal-content" style="max-width:580px;max-height:80vh;overflow-y:auto;">
        <h2 id="receipt-title">Receipt Details</h2>
        <div id="receipt-info"></div>
        <div id="receipt-items"></div>
        <div class="modal-buttons">
            <button class="btn-cancel" onclick="closeModal('receipt-modal')" style="flex:1">Close (Esc)</button>
        </div>
    </div>
</div>

<script>
let currentPanel = 'left';
let selectedProductIndex = 0;
let selectedCartIndex = 0;
let cart = [];
let currentSelectedProduct = null;
let currentEditIndex = null;
let activeTab = 'cart';

// Search
document.getElementById('search-input').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    const items = document.querySelectorAll('.product-item-stock');
    let vi = 0;
    items.forEach(item => {
        const isChild = (item.dataset.unit||'').trim() !== '';
        let searchable = (item.dataset.name||'') + ' ' + (item.dataset.barcode||'') + ' ' + (item.dataset.brand||'') + ' ' + (item.dataset.unit||'') + ' ' + (item.dataset.description||'');
        if (!isChild) searchable += ' ' + (item.dataset.category||'');
        const match = q === '' || searchable.toLowerCase().includes(q);
        item.style.display = match ? '' : 'none';
        if (match) item.dataset.visibleIndex = vi++;
    });
    selectedProductIndex = 0;
    updateProductSelection();
});

// Product click
document.getElementById('products-list').addEventListener('click', function(e) {
    const item = e.target.closest('.product-item-stock');
    if (!item) return;
    currentPanel = 'left';
    const visible = getVisibleProducts();
    selectedProductIndex = visible.indexOf(item);
    updateProductSelection();
    // Don't open modal here — handleProductTap handles double-click to open
});

function getVisibleProducts() {
    return Array.from(document.querySelectorAll('.product-item-stock')).filter(i => i.style.display !== 'none');
}

function updateProductSelection() {
    const items = getVisibleProducts();
    items.forEach((item, i) => item.classList.toggle('selected', i === selectedProductIndex));
    if (items[selectedProductIndex]) items[selectedProductIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function updateCartSelection() {
    document.querySelectorAll('.cart-item').forEach((item, i) => {
        item.classList.toggle('selected', i === selectedCartIndex);
    });
    const sel = document.querySelectorAll('.cart-item')[selectedCartIndex];
    if (sel) sel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

// Modals
function openAddModal(el) {
    currentSelectedProduct = {
        id: parseInt(el.dataset.id), name: el.dataset.name,
        price: parseFloat(el.dataset.price), purchase_price: parseFloat(el.dataset.purchasePrice),
        stock: parseInt(el.dataset.stock), category: el.dataset.category, brand: el.dataset.brand, unit: el.dataset.unit
    };
    document.getElementById('modal-product-name').textContent = currentSelectedProduct.name;
    document.getElementById('modal-stock').textContent = currentSelectedProduct.stock;
    document.getElementById('modal-price').textContent = '₱' + currentSelectedProduct.price.toFixed(2);
    document.getElementById('modal-cost').textContent = '₱' + currentSelectedProduct.purchase_price.toFixed(2);
    document.getElementById('stock-input').value = '1';
    document.getElementById('cost-input').value = currentSelectedProduct.purchase_price.toFixed(2);
    document.getElementById('price-input').value = currentSelectedProduct.price.toFixed(2);
    document.getElementById('quantity-modal').classList.add('active');
    document.getElementById('stock-input').focus();
    document.getElementById('stock-input').select();
}

function confirmAddToCart() {
    const qty = parseInt(document.getElementById('stock-input').value) || 0;
    const costPrice = parseFloat(document.getElementById('cost-input').value) || 0;
    const sellPrice = parseFloat(document.getElementById('price-input').value) || 0;
    if (qty <= 0) { alert('Enter a quantity greater than 0'); return; }
    if (costPrice <= 0) { alert('Enter a valid cost price'); return; }

    const existing = cart.findIndex(c => c.id === currentSelectedProduct.id);
    const entry = {
        id: currentSelectedProduct.id, name: currentSelectedProduct.name,
        current_stock: currentSelectedProduct.stock, stock_add: qty,
        old_price: currentSelectedProduct.price, new_price: sellPrice,
        purchase_price: costPrice, old_purchase_price: currentSelectedProduct.purchase_price,
        category: currentSelectedProduct.category, brand: currentSelectedProduct.brand, unit: currentSelectedProduct.unit
    };
    if (existing !== -1) cart[existing] = entry;
    else cart.push(entry);

    updateCart();
    closeModal('quantity-modal');
    switchToTab('cart');
    document.getElementById('search-input').value = '';
    document.getElementById('search-input').dispatchEvent(new Event('input'));
    document.getElementById('search-input').focus();
}

function updateCart() {
    const section = document.getElementById('cart-section');
    const totalDiv = document.getElementById('cart-total');
    const badge = document.getElementById('cart-count-badge');
    badge.textContent = cart.length ? `(${cart.length})` : '';

    if (!cart.length) {
        section.innerHTML = '<div class="cart-empty"><h3>Cart is Empty</h3><p>Select products to add stock</p></div>';
        totalDiv.style.display = 'none';
        return;
    }

    let totalProducts = cart.length, totalItems = 0, totalCost = 0, totalSelling = 0;
    let html = '';

    cart.forEach((item, i) => {
        totalItems += item.stock_add;
        const costVal = item.stock_add * item.purchase_price;
        const sellVal = item.stock_add * item.new_price;
        totalCost += costVal;
        totalSelling += sellVal;
        const margin = sellVal - costVal;
        const marginPct = costVal > 0 ? ((margin / costVal) * 100).toFixed(0) : 0;

        let tags = '';
        if (item.unit) tags += `<span class="ptag ptag-unit">${esc(item.unit)}</span>`;
        if (item.category) tags += `<span class="ptag ptag-cat">${esc(item.category)}</span>`;
        if (item.brand) tags += `<span class="ptag ptag-brand">${esc(item.brand)}</span>`;

        html += `<div class="cart-item" data-index="${i}" ontouchstart="startLongPress(${i})" ontouchend="cancelLongPress()" ontouchmove="cancelLongPress()" style="-webkit-user-select:none;user-select:none;">
            <div class="cart-item-header">
                <div><div class="cart-item-name">${esc(item.name)}</div>${tags ? `<div class="cart-item-tags">${tags}</div>` : ''}</div>
                <span class="cart-cost-badge">₱${costVal.toFixed(2)}</span>
            </div>
            <div class="cart-item-details">
                <div class="cart-detail-row"><span class="cart-detail-label">Qty Added:</span><span class="cart-detail-val">+${item.stock_add} (${item.current_stock} → ${item.current_stock + item.stock_add})</span></div>
                <div class="cart-detail-row"><span class="cart-detail-label">Cost Price:</span><span class="cart-detail-val val-red">₱${item.purchase_price.toFixed(2)}/unit</span></div>
                <div class="cart-detail-row"><span class="cart-detail-label">Sell Price:</span><span class="cart-detail-val val-blue">₱${item.new_price.toFixed(2)}/unit</span></div>
                <div class="cart-detail-row"><span class="cart-detail-label">Margin:</span><span class="cart-detail-val val-green">₱${margin.toFixed(2)} (${marginPct}%)</span></div>
            </div>
        </div>`;
    });

    section.innerHTML = html;
    totalDiv.style.display = 'block';
    const totalMargin = totalSelling - totalCost;
    const totalMarginPct = totalCost > 0 ? ((totalMargin / totalCost) * 100).toFixed(1) : 0;

    document.getElementById('total-products').textContent = totalProducts;
    document.getElementById('total-items').textContent = totalItems;
    document.getElementById('grand-total-cost').textContent = '₱' + totalCost.toFixed(2);
    document.getElementById('grand-total-selling').textContent = '₱' + totalSelling.toFixed(2);
    document.getElementById('grand-margin').textContent = `₱${totalMargin.toFixed(2)} (${totalMarginPct}%)`;

    // Budget display
    if (window.stockBudget) {
        const remaining = window.stockBudget - totalCost;
        let budgetEl = document.getElementById('budget-bar');
        if (!budgetEl) {
            budgetEl = document.createElement('div');
            budgetEl.id = 'budget-bar';
            budgetEl.style.cssText = 'padding:8px 16px;font-size:12px;font-weight:700;text-align:center;';
            totalDiv.prepend(budgetEl);
        }
        budgetEl.style.background = remaining >= 0 ? '#dcfce7' : '#fee2e2';
        budgetEl.style.color = remaining >= 0 ? '#166534' : '#991b1b';
        budgetEl.innerHTML = remaining >= 0 ? `Budget: ₱${window.stockBudget.toFixed(2)} | Remaining: ₱${remaining.toFixed(2)}` : `⚠ Over budget by ₱${Math.abs(remaining).toFixed(2)}`;
    }

    if (currentPanel === 'right') updateCartSelection();
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

// Cart click
document.addEventListener('click', function(e) {
    const ci = e.target.closest('.cart-item');
    if (ci) { currentPanel = 'right'; selectedCartIndex = parseInt(ci.dataset.index); updateCartSelection(); }
});

function deleteCartItem(i) {
    if (confirm('Remove ' + cart[i].name + '?')) {
        cart.splice(i, 1);
        if (selectedCartIndex >= cart.length) selectedCartIndex = Math.max(0, cart.length - 1);
        updateCart();
    }
}

function openEditModal(i) {
    currentEditIndex = i;
    const item = cart[i];
    document.getElementById('edit-product-name').textContent = 'Edit: ' + item.name;
    document.getElementById('edit-stock-input').value = item.stock_add;
    document.getElementById('edit-cost-input').value = item.purchase_price;
    document.getElementById('edit-price-input').value = item.new_price;
    document.getElementById('edit-modal').classList.add('active');
    document.getElementById('edit-stock-input').focus();
}

function confirmEdit() {
    const qty = parseInt(document.getElementById('edit-stock-input').value) || 0;
    const cost = parseFloat(document.getElementById('edit-cost-input').value) || 0;
    const sell = parseFloat(document.getElementById('edit-price-input').value) || 0;
    if (qty <= 0) { alert('Quantity must be greater than 0'); return; }
    cart[currentEditIndex].stock_add = qty;
    cart[currentEditIndex].purchase_price = cost;
    cart[currentEditIndex].new_price = sell;
    updateCart();
    closeModal('edit-modal');
}

function openConfirmModal() {
    if (!cart.length) { alert('Cart is empty'); return; }
    const totalProducts = cart.length;
    const totalItems = cart.reduce((s, c) => s + c.stock_add, 0);
    const totalCost = cart.reduce((s, c) => s + c.stock_add * c.purchase_price, 0);
    const totalSelling = cart.reduce((s, c) => s + c.stock_add * c.new_price, 0);
    const margin = totalSelling - totalCost;
    const marginPct = totalCost > 0 ? ((margin / totalCost) * 100).toFixed(1) : 0;

    document.getElementById('confirm-products').textContent = totalProducts;
    document.getElementById('confirm-items').textContent = totalItems;
    document.getElementById('confirm-cost').textContent = '₱' + totalCost.toFixed(2);
    document.getElementById('confirm-selling').textContent = '₱' + totalSelling.toFixed(2);
    document.getElementById('confirm-margin').textContent = `₱${margin.toFixed(2)} (${marginPct}%)`;

    const supplier = document.getElementById('supplier-name').value;
    const invoice = document.getElementById('invoice-number').value;
    let infoHtml = '';
    if (supplier) infoHtml += `<p class="modal-info">Supplier: <strong>${esc(supplier)}</strong></p>`;
    if (invoice) infoHtml += `<p class="modal-info">Invoice: <strong>${esc(invoice)}</strong></p>`;
    document.getElementById('confirm-supplier-info').innerHTML = infoHtml;

    document.getElementById('confirm-modal').classList.add('active');
}

function saveAllUpdates() {
    const totalItems = cart.reduce((s, c) => s + c.stock_add, 0);
    const totalCost = cart.reduce((s, c) => s + c.stock_add * c.purchase_price, 0);
    const totalSelling = cart.reduce((s, c) => s + c.stock_add * c.new_price, 0);

    if (window.stockBudget && totalCost > window.stockBudget) {
        alert(`Over budget! Budget: ₱${window.stockBudget.toFixed(2)}, Cost: ₱${totalCost.toFixed(2)}`);
        return;
    }

    const fd = new FormData();
    fd.append('action', 'bulk_update_stock');
    fd.append('items', JSON.stringify(cart));
    fd.append('total_cost', totalCost);
    fd.append('total_selling', totalSelling);
    fd.append('total_items', totalItems);
    fd.append('supplier_name', document.getElementById('supplier-name').value);
    fd.append('invoice_number', document.getElementById('invoice-number').value);
    fd.append('notes', document.getElementById('supply-notes').value);

    fetch('/oro-store/stock/add_stock.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Budget flow
            const stockBudget = sessionStorage.getItem('stockBudget');
            const stockTxId = sessionStorage.getItem('stockBudgetTransactionId');
            if (stockBudget && stockTxId) {
                const rfd = new FormData();
                rfd.append('action', 'return_remaining_cash');
                rfd.append('transaction_id', stockTxId);
                rfd.append('amount_spent', totalCost);
                fetch('/oro-store/transactions/other_transaction.php', { method:'POST', body:rfd })
                .then(r=>r.json()).then(rd => {
                    sessionStorage.removeItem('stockBudget');
                    sessionStorage.removeItem('stockBudgetTransactionId');
                    customAlert(`Stock receipt #${data.receipt_id} saved!\n\nCost: ₱${totalCost.toFixed(2)}\nBudget change returned: ₱${parseFloat(rd.register_change||0).toFixed(2)}`, 'success', function(){ window.location.href='/oro-store/cashier/cashier.php'; });
                }).catch(() => { customAlert('Stock saved, but budget adjustment failed.', 'warning', function(){ window.location.href='/oro-store/cashier/cashier.php'; }); });
            } else {
                customAlert(`Stock receipt #${data.receipt_id} saved!\n\n${cart.length} products, ${totalItems} units\nTotal Cost: ₱${totalCost.toFixed(2)}`, 'success', function(){ window.location.href='/oro-store/cashier/cashier.php'; });
            }
        } else {
            alert('Error: ' + data.error);
        }
    }).catch(e => alert('Error: ' + e));
}

function closeModal(id) { document.getElementById(id).classList.remove('active'); }

// Tabs
function switchToTab(tab) {
    activeTab = tab;
    document.querySelectorAll('.tab-btn').forEach((b, i) => b.classList.toggle('active', (i === 0 && tab === 'cart') || (i === 1 && tab === 'history')));
    document.getElementById('tab-cart').classList.toggle('active', tab === 'cart');
    document.getElementById('tab-history').classList.toggle('active', tab === 'history');
    if (tab === 'history') loadHistory();
}
function switchTab() { switchToTab(activeTab === 'cart' ? 'history' : 'cart'); }

// History
function loadHistory() {
    fetch('/oro-store/stock/add_stock.php?action=get_receipts')
    .then(r => r.json()).then(receipts => {
        const list = document.getElementById('history-list');
        if (!receipts.length) { list.innerHTML = '<div class="cart-empty"><h3>No receipts yet</h3></div>'; return; }
        list.innerHTML = receipts.map(r => {
            const d = new Date(r.created_at);
            const dateStr = d.toLocaleDateString('en-PH', {month:'short',day:'numeric',year:'numeric'});
            return `<div class="history-item" onclick="viewReceipt(${r.id})">
                <div class="history-header">
                    <span class="history-supplier">${r.supplier_name || 'No supplier'}</span>
                    <span class="history-date">${dateStr}</span>
                </div>
                <div class="history-meta">
                    <span>${r.item_count} products, ${r.total_items} units</span>
                    <span class="history-cost">Cost: ₱${parseFloat(r.total_cost).toFixed(2)}</span>
                    ${r.invoice_number ? `<span class="history-invoice">#${esc(r.invoice_number)}</span>` : ''}
                </div>
                ${r.notes ? `<div style="font-size:11px;color:#94a3b8;margin-top:3px;">${esc(r.notes)}</div>` : ''}
            </div>`;
        }).join('');
    });
}

function viewReceipt(id) {
    fetch('/oro-store/stock/add_stock.php?action=get_receipt_items&receipt_id=' + id)
    .then(r => r.json()).then(items => {
        document.getElementById('receipt-title').textContent = 'Receipt #' + id;
        let totalCost = 0, totalSell = 0;
        let tbody = items.map(it => {
            const cost = it.quantity * it.purchase_price;
            const sell = it.quantity * it.selling_price;
            totalCost += cost;
            totalSell += sell;
            return `<tr>
                <td>${esc(it.product_name)}</td>
                <td class="num">${it.quantity}</td>
                <td class="num">₱${parseFloat(it.purchase_price).toFixed(2)}</td>
                <td class="num">₱${parseFloat(it.selling_price).toFixed(2)}</td>
                <td class="num">₱${cost.toFixed(2)}</td>
                <td class="num">${it.old_stock} → ${it.new_stock}</td>
            </tr>`;
        }).join('');
        const margin = totalSell - totalCost;
        document.getElementById('receipt-items').innerHTML = `
            <table class="receipt-table">
                <thead><tr><th>Product</th><th>Qty</th><th>Cost</th><th>Sell</th><th>Total Cost</th><th>Stock</th></tr></thead>
                <tbody>${tbody}</tbody>
                <tfoot>
                    <tr style="font-weight:700;border-top:2px solid #e2e8f0;">
                        <td colspan="4" style="text-align:right;">Totals:</td>
                        <td class="num" style="color:#dc2626;">₱${totalCost.toFixed(2)}</td>
                        <td></td>
                    </tr>
                    <tr style="font-weight:600;">
                        <td colspan="4" style="text-align:right;color:#2563eb;">Selling Value:</td>
                        <td class="num" style="color:#2563eb;">₱${totalSell.toFixed(2)}</td>
                        <td></td>
                    </tr>
                    <tr style="font-weight:600;">
                        <td colspan="4" style="text-align:right;color:#16a34a;">Margin:</td>
                        <td class="num" style="color:#16a34a;">₱${margin.toFixed(2)}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>`;
        document.getElementById('receipt-modal').classList.add('active');
    });
}

// Keyboard
function handleEnter() {
    if (currentPanel === 'left') {
        const items = getVisibleProducts();
        if (items[selectedProductIndex]) openAddModal(items[selectedProductIndex]);
    } else {
        openConfirmModal();
    }
}
function handleEsc() {
    if (document.getElementById('receipt-modal').classList.contains('active')) { closeModal('receipt-modal'); return; }
    if (document.getElementById('confirm-modal').classList.contains('active')) { closeModal('confirm-modal'); return; }
    if (document.getElementById('edit-modal').classList.contains('active')) { closeModal('edit-modal'); return; }
    if (document.getElementById('quantity-modal').classList.contains('active')) { closeModal('quantity-modal'); document.getElementById('search-input').focus(); return; }
    if (currentPanel === 'right') { switchPanel(); return; }
    document.getElementById('search-input').value = '';
    document.getElementById('search-input').dispatchEvent(new Event('input'));
    document.getElementById('search-input').focus();
}
function handleDel() {
    if (currentPanel === 'right' && cart.length) deleteCartItem(selectedCartIndex);
}
function handleIns() {
    if (currentPanel === 'right' && cart.length) openEditModal(selectedCartIndex);
}
function switchPanel() {
    if (currentPanel === 'left' && cart.length) {
        currentPanel = 'right'; selectedCartIndex = 0; updateCartSelection();
    } else {
        currentPanel = 'left'; document.getElementById('search-input').focus(); updateProductSelection();
    }
}

document.addEventListener('keydown', function(e) {
    // Modal keyboard
    const anyModal = ['quantity-modal','edit-modal','confirm-modal','receipt-modal'].find(id => document.getElementById(id).classList.contains('active'));
    if (anyModal) {
        if (e.key === 'Escape') { e.preventDefault(); handleEsc(); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (anyModal === 'quantity-modal') confirmAddToCart();
            else if (anyModal === 'edit-modal') confirmEdit();
            else if (anyModal === 'confirm-modal') saveAllUpdates();
            else if (anyModal === 'receipt-modal') closeModal('receipt-modal');
        }
        return;
    }

    if (e.key === 'F12') { e.preventDefault(); window.close(); return; }
    if (e.key === 'Tab' && !e.target.closest('.supply-info')) { e.preventDefault(); switchTab(); return; }

    if (currentPanel === 'left') {
        const items = getVisibleProducts();
        if (e.key === 'ArrowDown') { e.preventDefault(); selectedProductIndex = Math.min(selectedProductIndex + 1, items.length - 1); updateProductSelection(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); selectedProductIndex = Math.max(selectedProductIndex - 1, 0); updateProductSelection(); }
        else if (e.key === 'Enter' && document.activeElement.id !== 'search-input') { e.preventDefault(); handleEnter(); }
        else if (e.key === 'Enter' && document.activeElement.id === 'search-input') { e.preventDefault(); handleEnter(); }
        else if (e.key === 'Home') { e.preventDefault(); switchPanel(); }
        else if (e.key === 'Escape') { e.preventDefault(); handleEsc(); }
    } else {
        const items = document.querySelectorAll('.cart-item');
        if (e.key === 'ArrowDown') { e.preventDefault(); selectedCartIndex = Math.min(selectedCartIndex + 1, items.length - 1); updateCartSelection(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); selectedCartIndex = Math.max(selectedCartIndex - 1, 0); updateCartSelection(); }
        else if (e.key === 'Enter') { e.preventDefault(); openConfirmModal(); }
        else if (e.key === 'Delete') { e.preventDefault(); handleDel(); }
        else if (e.key === 'Insert') { e.preventDefault(); handleIns(); }
        else if (e.key === 'Home' || e.key === 'Escape') { e.preventDefault(); switchPanel(); }
    }
});

// Init
document.addEventListener('DOMContentLoaded', function() {
    updateProductSelection();

    // Budget mode
    const stockBudget = sessionStorage.getItem('stockBudget');
    const txId = sessionStorage.getItem('stockBudgetTransactionId');
    if (stockBudget && txId) {
        window.stockBudget = parseFloat(stockBudget);
        window.stockBudgetTransactionId = txId;
        const header = document.querySelector('.stock-right-panel .panel-header .sub');
        if (header) header.innerHTML = `Budget: <strong style="color:#16a34a;">₱${window.stockBudget.toFixed(2)}</strong> from Transaction #${txId}`;
    }
});

// Autocomplete tags for search
(function(){
    var sug = new Set();
    document.querySelectorAll('.product-item-stock').forEach(function(item){
        if (item.dataset.brand) sug.add(item.dataset.brand);
        if (item.dataset.category && !item.closest('[data-parent-product-id]')) sug.add(item.dataset.category);
        if (item.dataset.unit) sug.add(item.dataset.unit);
    });
    var allSug = Array.from(sug);
    var searchInput = document.getElementById('search-input');
    var sugBox = document.getElementById('stock-suggestions');
    var tagsBox = document.getElementById('stock-search-tags');
    var activeTags = [];

    searchInput.addEventListener('input', function(){
        var val = this.value.toLowerCase().trim();
        if (val.length < 1) { sugBox.style.display = 'none'; return; }
        var matches = allSug.filter(function(s){ return s.toLowerCase().includes(val) && activeTags.indexOf(s) === -1; });
        if (matches.length === 0) { sugBox.style.display = 'none'; return; }
        sugBox.innerHTML = '';
        matches.slice(0, 8).forEach(function(m){
            var div = document.createElement('div');
            div.textContent = m;
            div.style.cssText = 'padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9;-webkit-user-select:none;';
            div.onmouseenter = function(){ this.style.background='#f0f7ff'; };
            div.onmouseleave = function(){ this.style.background=''; };
            div.onclick = function(e){ e.stopPropagation(); addTag(m); };
            div.ontouchend = function(e){ e.preventDefault(); e.stopPropagation(); addTag(m); };
            sugBox.appendChild(div);
        });
        sugBox.style.display = 'block';
    });

    function addTag(text) {
        if (activeTags.indexOf(text) !== -1) return;
        activeTags.push(text);
        renderTags();
        searchInput.value = '';
        sugBox.style.display = 'none';
        filterByTags();
        searchInput.focus();
    }
    function removeTag(text) {
        activeTags = activeTags.filter(function(t){ return t !== text; });
        renderTags();
        filterByTags();
        searchInput.focus();
    }
    function renderTags() {
        tagsBox.innerHTML = '';
        activeTags.forEach(function(tag){
            var span = document.createElement('span');
            span.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#6366f1;color:#fff;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;-webkit-user-select:none;';
            span.innerHTML = tag + ' <span style="font-size:10px;opacity:0.7;margin-left:2px;">✕</span>';
            span.onclick = function(){ removeTag(tag); };
            tagsBox.appendChild(span);
        });
    }
    function filterByTags() {
        var query = searchInput.value.toLowerCase().trim();
        var items = document.querySelectorAll('.product-item-stock');
        var vi = 0;
        items.forEach(function(item){
            var isChild = (item.dataset.unit||'').trim() !== '';
            var searchable = (item.dataset.name||'')+' '+(item.dataset.barcode||'')+' '+(item.dataset.brand||'')+' '+(item.dataset.unit||'')+' '+(item.dataset.description||'');
            if (!isChild) searchable += ' '+(item.dataset.category||'');
            var all = searchable.toLowerCase();
            var tagMatch = activeTags.length === 0 || activeTags.every(function(t){ return all.includes(t.toLowerCase()); });
            var textMatch = query === '' || all.includes(query);
            item.style.display = tagMatch && textMatch ? '' : 'none';
            if (tagMatch && textMatch) { item.dataset.visibleIndex = vi; vi++; }
        });
        selectedProductIndex = 0;
        updateProductSelection();
    }
    searchInput.addEventListener('input', function(){ setTimeout(filterByTags, 50); });
    document.addEventListener('click', function(e){ if (!e.target.closest('.search-section')) sugBox.style.display = 'none'; });
})();

// Double-tap product = open quantity modal, single tap = select/highlight
var _lastClickTime = 0, _lastClickId = '';
function handleProductTap(el) {
    var items = document.querySelectorAll('.product-item-stock');
    var visible = Array.from(items).filter(function(i){ return i.style.display !== 'none'; });
    var idx = visible.indexOf(el);
    if (idx >= 0) { selectedProductIndex = idx; updateProductSelection(); }

    var now = Date.now(), id = el.dataset.id;
    if (now - _lastClickTime < 400 && _lastClickId === id) {
        handleEnter();
        _lastClickTime = 0; _lastClickId = '';
    } else {
        _lastClickTime = now; _lastClickId = id;
    }
}

// Long-press cart item = Edit
var _lpTimer = null;
function startLongPress(idx) {
    _lpTimer = setTimeout(function() {
        selectedCartIndex = idx;
        updateCartSelection();
        handleIns();
    }, 500);
}
function cancelLongPress() {
    if (_lpTimer) { clearTimeout(_lpTimer); _lpTimer = null; }
}

// Block context menu + text selection on touch
document.addEventListener('contextmenu', function(e) {
    if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') e.preventDefault();
});
document.addEventListener('selectstart', function(e) {
    if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') e.preventDefault();
});
</script>
<script src="/oro-store/core/custom_alert.js"></script>
<link rel="stylesheet" href="/oro-store/core/responsive.css">
</body>
</html>
