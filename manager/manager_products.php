<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';
require_once __DIR__ . '/../sync/sync_helper.php';

$currentUser = getCurrentUser();

// Only logged-in managers (users tied to a specific store) can access this page.
// NOTE: this assumes a "manager" is any user with a store_id set on their account.
// If you have a dedicated role-check helper (e.g. isManager()) in auth_check.php,
// swap it in here instead for a more explicit check.
if (!$currentUser || empty($currentUser['store_id'])) {
    header("Location: /oro-store-demo/cashier/cashier.php");
    exit;
}

$store_id = intval($currentUser['store_id']);
$db = new SyncDB();

// Handle store-scoped price/stock actions.
// Note: $store_id always comes from the logged-in manager's session, never from
// the submitted form, so a manager can never edit another store's data.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_store_price') {
        $product_id = intval($_POST['product_id']);
        $new_price = floatval($_POST['price']);

        $stmt = $conn->prepare("
            SELECT p.name, sp.price, s.store_code
            FROM store_prices sp
            JOIN products p ON sp.product_id = p.id
            JOIN stores s ON sp.store_id = s.id
            WHERE sp.product_id = ? AND sp.store_id = ?
        ");
        $stmt->bind_param("ii", $product_id, $store_id);
        $stmt->execute();
        $old_data = $stmt->get_result()->fetch_assoc();

        if ($old_data) {
            $result = $db->update('store_prices', [
                'price' => $new_price
            ], "product_id = $product_id AND store_id = $store_id");

            if ($result) {
                logActivity('product', "Updated store price for product: {$old_data['name']} at {$old_data['store_code']}",
                    $currentUser['id'], $store_id,
                    [
                        'product_id' => $product_id,
                        'product_name' => $old_data['name'],
                        'store_id' => $store_id,
                        'store_code' => $old_data['store_code'],
                        'old_price' => $old_data['price'],
                        'new_price' => $new_price
                    ]
                );

                $db->insert('product_history', [
                    'product_id' => $product_id,
                    'change_type' => 'store_price_updated',
                    'old_value' => "Store: {$old_data['store_code']}, Price: {$old_data['price']}",
                    'new_value' => "Store: {$old_data['store_code']}, Price: $new_price"
                ]);

                $success = "Price updated successfully!";
            } else {
                $error = "Error updating price.";
            }
        } else {
            $error = "Product not found in your store.";
        }

    } elseif ($_POST['action'] === 'update_store_stock') {
        $product_id = intval($_POST['product_id']);
        $stock_change = intval($_POST['stock_change']);
        $change_type = $_POST['change_type'];

        $stmt = $conn->prepare("
            SELECT p.name, sp.stock, s.store_code
            FROM store_prices sp
            JOIN products p ON sp.product_id = p.id
            JOIN stores s ON sp.store_id = s.id
            WHERE sp.product_id = ? AND sp.store_id = ?
        ");
        $stmt->bind_param("ii", $product_id, $store_id);
        $stmt->execute();
        $result_data = $stmt->get_result()->fetch_assoc();

        if ($result_data) {
            $old_stock = $result_data['stock'];
            $new_stock = $change_type === 'add' ? $old_stock + $stock_change : max(0, $old_stock - $stock_change);

            $result = $db->update('store_prices', [
                'stock' => $new_stock
            ], "product_id = $product_id AND store_id = $store_id");

            if ($result) {
                $action_text = $change_type === 'add' ? 'Added' : 'Removed';
                logActivity('product', "$action_text $stock_change units of {$result_data['name']} at {$result_data['store_code']}",
                    $currentUser['id'], $store_id,
                    [
                        'product_id' => $product_id,
                        'product_name' => $result_data['name'],
                        'store_id' => $store_id,
                        'store_code' => $result_data['store_code'],
                        'change_type' => $change_type,
                        'stock_change' => $stock_change,
                        'old_stock' => $old_stock,
                        'new_stock' => $new_stock
                    ]
                );

                $db->insert('product_history', [
                    'product_id' => $product_id,
                    'change_type' => 'stock_updated',
                    'old_value' => "Store: {$result_data['store_code']}, Stock: $old_stock",
                    'new_value' => "Store: {$result_data['store_code']}, Stock: $new_stock ($action_text $stock_change)"
                ]);

                $success = "Stock updated successfully!";
            } else {
                $error = "Error updating stock.";
            }
        } else {
            $error = "Product not found in your store.";
        }

    } elseif ($_POST['action'] === 'add_to_store') {
        $product_id = intval($_POST['product_id']);
        $price = floatval($_POST['price']);
        $init_stock = intval($_POST['stock']);

        // Check product exists and isn't already in store
        $chk = $conn->prepare("SELECT id FROM store_prices WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
        $chk->bind_param("ii", $product_id, $store_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = "Product already exists in your store.";
        } else {
            $stmt = $conn->prepare("SELECT name, purchase_price FROM products WHERE id = ? AND is_deleted = 0");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $prod = $stmt->get_result()->fetch_assoc();
            if ($prod) {
                $db->insert('store_prices', [
                    'product_id' => $product_id,
                    'store_id' => $store_id,
                    'price' => $price,
                    'purchase_price' => $prod['purchase_price'],
                    'stock' => $init_stock
                ]);
                logActivity('product', "Added product to store: {$prod['name']}", $currentUser['id'], $store_id, [
                    'product_id' => $product_id, 'product_name' => $prod['name'], 'price' => $price, 'stock' => $init_stock
                ]);
                $success = "Product added to your store!";
            } else {
                $error = "Product not found.";
            }
        }

    } elseif ($_POST['action'] === 'add_new_product') {
        $name = trim($_POST['name']);
        $price = floatval($_POST['price']);
        $discounted_price = !empty($_POST['discounted_price']) ? floatval($_POST['discounted_price']) : $price;
        $purchase_price = floatval($_POST['purchase_price']);
        $init_stock = intval($_POST['stock']);
        $description = trim($_POST['description'] ?? '');
        $barcode = trim($_POST['barcode'] ?? '');
        $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
        $can_sell_individually = isset($_POST['can_sell_individually']) ? 1 : 0;
        $individual_sell_unit = $can_sell_individually ? trim($_POST['individual_sell_unit'] ?? '') : null;
        $individual_pieces_per_pack = $can_sell_individually ? intval($_POST['individual_pieces_per_pack'] ?? 0) : null;
        $individual_selling_price = $can_sell_individually ? floatval($_POST['individual_selling_price'] ?? 0) : null;
        $individual_discounted_price = $can_sell_individually && !empty($_POST['individual_discounted_price']) ? floatval($_POST['individual_discounted_price']) : $individual_selling_price;

        try {
            $newProductId = $db->insert('products', [
                'name' => $name, 'price' => $price, 'discounted_price' => $discounted_price,
                'purchase_price' => $purchase_price, 'stock' => $init_stock,
                'description' => $description, 'barcode' => $barcode, 'category_id' => $category_id,
                'can_sell_individually' => $can_sell_individually,
                'individual_sell_unit' => $individual_sell_unit,
                'individual_pieces_per_pack' => $individual_pieces_per_pack,
                'individual_selling_price' => $individual_selling_price,
                'individual_discounted_price' => $individual_discounted_price
            ]);

            if (!$newProductId) throw new Exception("Failed to insert product");

            $db->insert('store_prices', [
                'product_id' => $newProductId, 'store_id' => $store_id,
                'price' => $price, 'purchase_price' => $purchase_price, 'stock' => $init_stock
            ]);
            $db->insert('product_history', [
                'product_id' => $newProductId, 'change_type' => 'added', 'old_value' => '',
                'new_value' => "Name: $name, Price: $price, Purchase: $purchase_price, Stock: $init_stock"
            ]);
            logActivity('product', "New product created and added to store: $name", $currentUser['id'], $store_id, [
                'product_id' => $newProductId, 'product_name' => $name, 'price' => $price, 'stock' => $init_stock
            ]);

            if ($can_sell_individually && $individual_sell_unit && $individual_pieces_per_pack > 0) {
                $ind_name = $name;
                $ind_barcode = $barcode ? $barcode . '-IND' : null;
                $ind_price = $individual_selling_price > 0 ? $individual_selling_price : ($price / $individual_pieces_per_pack);
                $ind_discounted = $individual_discounted_price > 0 ? $individual_discounted_price : ($discounted_price / $individual_pieces_per_pack);
                $ind_purchase = $purchase_price / $individual_pieces_per_pack;
                $ind_stock = $individual_pieces_per_pack;

                $indId = $db->insert('products', [
                    'name' => $ind_name, 'price' => $ind_price, 'discounted_price' => $ind_discounted,
                    'purchase_price' => $ind_purchase, 'stock' => $ind_stock,
                    'description' => "Individual unit of $name (linked to pack ID: $newProductId)",
                    'barcode' => $ind_barcode, 'parent_product_id' => $newProductId, 'category_id' => null
                ]);
                if ($indId) {
                    $db->insert('product_history', [
                        'product_id' => $indId, 'change_type' => 'added', 'old_value' => '',
                        'new_value' => "Individual product linked to pack product ID $newProductId"
                    ]);
                }
            }
            $success = "New product created and added to your store!";
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Inventory summary stats, scoped to this manager's store only
$inv = [];
$inv['total'] = $conn->query("
    SELECT COUNT(*) as c FROM products p
    JOIN store_prices sp ON p.id = sp.product_id
    WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL AND sp.store_id = {$store_id} AND sp.is_deleted = 0
")->fetch_assoc()['c'];
$inv['in_stock'] = $conn->query("
    SELECT COUNT(*) as c FROM products p
    JOIN store_prices sp ON p.id = sp.product_id
    WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL AND sp.store_id = {$store_id} AND sp.is_deleted = 0 AND sp.stock > 10
")->fetch_assoc()['c'];
$inv['low_stock'] = $conn->query("
    SELECT COUNT(*) as c FROM products p
    JOIN store_prices sp ON p.id = sp.product_id
    WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL AND sp.store_id = {$store_id} AND sp.is_deleted = 0 AND sp.stock > 0 AND sp.stock <= 10
")->fetch_assoc()['c'];
$inv['out_of_stock'] = $conn->query("
    SELECT COUNT(*) as c FROM products p
    JOIN store_prices sp ON p.id = sp.product_id
    WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL AND sp.store_id = {$store_id} AND sp.is_deleted = 0 AND sp.stock = 0
")->fetch_assoc()['c'];
$inv['total_units'] = $conn->query("
    SELECT COALESCE(SUM(sp.stock), 0) as c FROM products p
    JOIN store_prices sp ON p.id = sp.product_id
    WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL AND sp.store_id = {$store_id} AND sp.is_deleted = 0
")->fetch_assoc()['c'];

$stock_filter = $_GET['stock'] ?? 'all';

// Get products that exist in THIS store only, with this store's price & stock
$stmt = $conn->prepare("
    SELECT p.id, p.name, p.barcode, sp.price as store_price, sp.stock as store_stock
    FROM products p
    JOIN store_prices sp ON p.id = sp.product_id AND sp.store_id = ? AND sp.is_deleted = 0
    WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL
    ORDER BY p.name
");
$stmt->bind_param("i", $store_id);
$stmt->execute();
$all_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Filter once here so the "Showing X products" count and the table body
// always agree, instead of repeating the same filter logic twice.
$visible_products = array_values(array_filter($all_products, function ($p) use ($stock_filter) {
    if ($stock_filter === 'out') return $p['store_stock'] == 0;
    if ($stock_filter === 'low') return $p['store_stock'] > 0 && $p['store_stock'] <= 10;
    if ($stock_filter === 'in') return $p['store_stock'] > 10;
    return true; // 'all' or anything unrecognized
}));
$visible = count($visible_products);

// Products in admin catalog but NOT in this store
$not_in_store = $conn->query("
    SELECT p.id, p.name, p.barcode, p.price as admin_price, p.purchase_price
    FROM products p
    WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL
    AND p.id NOT IN (SELECT product_id FROM store_prices WHERE store_id = {$store_id} AND is_deleted = 0)
    ORDER BY p.name
")->fetch_all(MYSQLI_ASSOC);

// Categories for new product modal
$catRes = $conn->query("SELECT id, category_name FROM product_categories WHERE is_deleted = 0 ORDER BY category_name ASC");
$initialCategories = $catRes ? $catRes->fetch_all(MYSQLI_ASSOC) : [];

// Other stores for peek view
$other_stores_q = $conn->query("SELECT id, store_name, store_code FROM stores WHERE status = 'active' AND id != $store_id ORDER BY store_code");
$other_stores_list = $other_stores_q ? $other_stores_q->fetch_all(MYSQLI_ASSOC) : [];
$peek_store = isset($_GET['peek']) ? intval($_GET['peek']) : 0;
$peek_store_name = '';
$is_peeking = false;

if ($peek_store) {
    foreach ($other_stores_list as $os) { if ($os['id'] == $peek_store) $peek_store_name = $os['store_name']; }
    // Override visible_products with the other store's data
    $peek_q = $conn->query("
        SELECT p.id, p.name, p.barcode, sp.price as store_price, sp.stock as store_stock
        FROM store_prices sp
        JOIN products p ON sp.product_id = p.id AND p.is_deleted = 0 AND p.parent_product_id IS NULL
        WHERE sp.store_id = $peek_store AND sp.is_deleted = 0
        ORDER BY p.name
    ");
    $peek_all = $peek_q ? $peek_q->fetch_all(MYSQLI_ASSOC) : [];
    $visible_products = array_values(array_filter($peek_all, function ($p) use ($stock_filter) {
        if ($stock_filter === 'out') return $p['store_stock'] == 0;
        if ($stock_filter === 'low') return $p['store_stock'] > 0 && $p['store_stock'] <= 10;
        if ($stock_filter === 'in') return $p['store_stock'] > 10;
        return true;
    }));
    $visible = count($visible_products);
    $is_peeking = true;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Store Products - Manager Panel</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <style>
        /* Scoped styles for the products table on this page only */
        .mp-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .mp-search-wrap {
            position: relative;
        }

        .mp-search-wrap::before {
            content: "🔍";
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 13px;
            opacity: .55;
            pointer-events: none;
        }

        .mp-search-wrap input {
            padding: 10px 14px 10px 38px;
            border: 1px solid #d8dce3;
            border-radius: 10px;
            font-size: 14px;
            width: 280px;
            transition: border-color .15s, box-shadow .15s;
        }

        .mp-search-wrap input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, .14);
        }

        .mp-count {
            font-size: 13px;
            color: #64748b;
        }

        .mp-table-card {
            background: #fff;
            border: 1px solid #e8eaee;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
        }

        .mp-table-scroll {
            overflow-x: auto;
        }

        .mp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .mp-table thead th {
            position: sticky;
            top: 0;
            background: #f8fafc;
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: #64748b;
            padding: 14px 18px;
            border-bottom: 1px solid #e8eaee;
            white-space: nowrap;
        }

        .mp-table tbody tr {
            border-bottom: 1px solid #f1f3f6;
            transition: background-color .12s;
        }

        .mp-table tbody tr:last-child {
            border-bottom: none;
        }

        .mp-table tbody tr:nth-child(even) {
            background: #fbfcfd;
        }

        .mp-table tbody tr:hover {
            background: #f0f4ff;
        }

        .mp-table td {
            padding: 14px 18px;
            vertical-align: middle;
        }

        .mp-product-name {
            font-weight: 600;
            color: #1e293b;
            font-size: 14.5px;
        }

        .mp-barcode {
            font-family: 'SFMono-Regular', Consolas, monospace;
            font-size: 11.5px;
            color: #98a2b3;
            margin-top: 2px;
            letter-spacing: .02em;
        }

        .mp-price {
            font-weight: 700;
            color: #1e293b;
            font-size: 15px;
        }

        .mp-price-currency {
            font-weight: 500;
            color: #98a2b3;
            font-size: 12px;
            margin-right: 1px;
        }

        .mp-stock-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12.5px;
            font-weight: 700;
            white-space: nowrap;
        }

        .mp-stock-pill .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .mp-stock-good {
            background: #ecfdf3;
            color: #15803d;
        }

        .mp-stock-good .dot {
            background: #22c55e;
        }

        .mp-stock-low {
            background: #fffaeb;
            color: #b45309;
        }

        .mp-stock-low .dot {
            background: #f59e0b;
        }

        .mp-stock-out {
            background: #fef2f2;
            color: #b91c1c;
        }

        .mp-stock-out .dot {
            background: #ef4444;
        }

        /* Peek mode - other store's stock (blue/purple tones) */
        .mp-stock-peek-good {
            background: #ede9fe;
            color: #6d28d9;
        }
        .mp-stock-peek-good .dot {
            background: #8b5cf6;
        }
        .mp-stock-peek-low {
            background: #fef3c7;
            color: #92400e;
        }
        .mp-stock-peek-low .dot {
            background: #f59e0b;
        }
        .mp-stock-peek-out {
            background: #fce7f3;
            color: #9d174d;
        }
        .mp-stock-peek-out .dot {
            background: #ec4899;
        }

        .mp-edit-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid #c7d2fe;
            background: #eef2ff;
            color: #4338ca;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color .15s, transform .05s;
        }

        .mp-edit-btn:hover {
            background: #e0e7ff;
        }

        .mp-edit-btn:active {
            transform: scale(.97);
        }

        .mp-empty {
            padding: 56px 20px;
            text-align: center;
            color: #98a2b3;
        }

        .mp-empty-icon {
            font-size: 30px;
            margin-bottom: 8px;
        }

        @media (max-width: 640px) {
            .mp-search-wrap input {
                width: 200px;
            }

            .mp-table th,
            .mp-table td {
                padding: 10px 12px;
            }

            .mp-barcode {
                display: none;
            }

            .mp-edit-btn .mp-edit-label {
                display: none;
            }
        }

        /* Not-in-store card */
        .nis-card {
            background: #fff;
            border: 1px solid #e8eaee;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(16,24,40,.04);
            margin-top: 20px;
        }
        .nis-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 18px;
            background: #f8fafc;
            border-bottom: 1px solid #e8eaee;
        }
        .nis-header h3 {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            margin: 0;
        }
        .nis-count {
            font-size: 12px;
            color: #94a3b8;
        }
        .nis-list {
            max-height: 320px;
            overflow-y: auto;
        }
        .nis-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 18px;
            border-bottom: 1px solid #f1f3f6;
            font-size: 13px;
            transition: background .1s;
        }
        .nis-item:last-child { border-bottom: none; }
        .nis-item:hover { background: #f8fafc; }
        .nis-item-name { font-weight: 600; color: #1e293b; }
        .nis-item-meta { font-size: 11px; color: #94a3b8; margin-top: 2px; }
        .nis-add-btn {
            padding: 5px 12px;
            border-radius: 6px;
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #15803d;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
            white-space: nowrap;
        }
        .nis-add-btn:hover { background: #dcfce7; }
        .nis-empty { padding: 30px 18px; text-align: center; color: #94a3b8; font-size: 13px; }
        .nis-new-btn {
            padding: 6px 14px;
            border-radius: 6px;
            border: none;
            background: #667eea;
            color: #fff;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .15s;
        }
        .nis-new-btn:hover { opacity: .85; }
        .nis-search-wrap {
            position: relative;
            margin: 0 18px 0;
            padding: 10px 0;
        }
        .nis-search-wrap input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 12px;
            outline: none;
        }
        .nis-search-wrap input:focus { border-color: #667eea; }
    </style>
</head>
<body>
    <?php /* NOTE: adjust this filename if your manager sidebar partial has a different name */ ?>
    <?php include_once __DIR__ . '/../manager/manager_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Store Products</h1>
                <p>Stock and pricing for your store</p>
            </div>
        </div>

        <!-- Inventory Summary -->
        <div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));margin-bottom:20px;">
            <a href="?stock=all" class="stat-card" style="text-decoration:none;<?php echo $stock_filter==='all' ? 'border:2px solid #6366f1;' : ''; ?>">
                <div class="stat-icon blue">&#128230;</div>
                <div class="stat-label">Total Products</div>
                <div class="stat-value"><?php echo number_format($inv['total']); ?></div>
            </a>
            <a href="?stock=in" class="stat-card" style="text-decoration:none;<?php echo $stock_filter==='in' ? 'border:2px solid #22c55e;' : ''; ?>">
                <div class="stat-icon green">&#10004;</div>
                <div class="stat-label">In Stock</div>
                <div class="stat-value"><?php echo number_format($inv['in_stock']); ?></div>
            </a>
            <a href="?stock=low" class="stat-card" style="text-decoration:none;<?php echo $stock_filter==='low' ? 'border:2px solid #f59e0b;' : ''; ?>">
                <div class="stat-icon orange">&#9888;</div>
                <div class="stat-label">Low Stock</div>
                <div class="stat-value"><?php echo number_format($inv['low_stock']); ?></div>
            </a>
            <a href="?stock=out" class="stat-card" style="text-decoration:none;<?php echo $stock_filter==='out' ? 'border:2px solid #ef4444;' : ''; ?>">
                <div class="stat-icon red">&#10060;</div>
                <div class="stat-label">Out of Stock</div>
                <div class="stat-value"><?php echo number_format($inv['out_of_stock']); ?></div>
            </a>
            <div class="stat-card">
                <div class="stat-icon purple">&#128203;</div>
                <div class="stat-label">Total Units</div>
                <div class="stat-value"><?php echo number_format($inv['total_units']); ?></div>
            </div>
        </div>

        <!-- Other Stores Stock Viewer -->
        <?php if (!empty($other_stores_list)): ?>
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:12px;flex-wrap:wrap;">
            <span style="font-size:12px;font-weight:600;color:#64748b;">View Stock:</span>
            <a href="?stock=<?php echo $stock_filter; ?>" style="padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;<?php echo !$peek_store ? 'background:#6366f1;color:#fff;' : 'background:#f1f5f9;color:#475569;'; ?>">My Store</a>
            <?php foreach ($other_stores_list as $os): ?>
            <a href="?stock=<?php echo $stock_filter; ?>&peek=<?php echo $os['id']; ?>" style="padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;<?php echo $peek_store == $os['id'] ? 'background:#f59e0b;color:#fff;' : 'background:#f1f5f9;color:#475569;'; ?>">
                <?php echo htmlspecialchars($os['store_name']); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($is_peeking): ?>
        <div style="padding:8px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-size:13px;font-weight:700;color:#92400e;">Viewing: <?php echo htmlspecialchars($peek_store_name); ?> (Read Only)</span>
            <a href="?stock=<?php echo $stock_filter; ?>" style="font-size:12px;color:#6366f1;font-weight:600;">Back to My Store</a>
        </div>
        <?php endif; ?>

        <!-- Products Table -->
        <div class="content-section">
            <div class="mp-toolbar">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <div class="mp-search-wrap">
                        <input type="text" id="search-products" placeholder="Search by name or barcode..." onkeyup="searchAllProducts()">
                    </div>
                    <?php if ($stock_filter !== 'all'): ?>
                        <a href="manager_product.php" class="btn btn-secondary btn-sm">Clear filter</a>
                    <?php endif; ?>
                </div>
                <div class="mp-count">Showing <strong><?php echo $visible; ?></strong> product<?php echo $visible === 1 ? '' : 's'; ?></div>
            </div>

            <div class="mp-table-card">
                <div class="mp-table-scroll">
                    <table class="mp-table" id="all-products-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th style="text-align:right;">Sell Price</th>
                                <th style="text-align:center;">Stock</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($visible === 0): ?>
                                <tr>
                                    <td colspan="4">
                                        <div class="mp-empty">
                                            <div class="mp-empty-icon">📭</div>
                                            <div>No products match this view.</div>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($visible_products as $product): ?>
                                    <?php
                                    $stock = $product['store_stock'];
                                    if ($is_peeking) {
                                        $stock_class = $stock > 10 ? 'mp-stock-peek-good' : ($stock > 0 ? 'mp-stock-peek-low' : 'mp-stock-peek-out');
                                    } else {
                                        $stock_class = $stock > 10 ? 'mp-stock-good' : ($stock > 0 ? 'mp-stock-low' : 'mp-stock-out');
                                    }
                                    ?>
                                    <tr<?php echo $is_peeking ? ' style="opacity:0.85;"' : ''; ?>>
                                        <td>
                                            <div class="mp-product-name"><?php echo htmlspecialchars($product['name']); ?></div>
                                            <?php if (!empty($product['barcode'])): ?>
                                                <div class="mp-barcode"><?php echo htmlspecialchars($product['barcode']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:right;">
                                            <span class="mp-price"><span class="mp-price-currency">₱</span><?php echo number_format($product['store_price'], 2); ?></span>
                                        </td>
                                        <td style="text-align:center;">
                                            <span class="mp-stock-pill <?php echo $stock_class; ?>">
                                                <span class="dot"></span><?php echo $stock; ?> units
                                            </span>
                                        </td>
                                        <td style="text-align:right;">
                                            <?php if ($is_peeking): ?>
                                                <span style="font-size:10px;color:#94a3b8;padding:6px 10px;">View Only</span>
                                            <?php else: ?>
                                            <button onclick='openEditModal(<?php echo json_encode([
                                                "product_id" => $product['id'],
                                                "product_name" => $product['name'],
                                                "stock" => $product['store_stock'],
                                                "price" => $product['store_price']
                                            ]); ?>)' class="mp-edit-btn">
                                                ✏️ <span class="mp-edit-label">Edit</span>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Not In Store Products -->
        <div class="content-section" style="margin-top:20px;">
            <div class="nis-card">
                <div class="nis-header">
                    <h3>📋 Products Not In Your Store</h3>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <span class="nis-count"><?php echo count($not_in_store); ?> product<?php echo count($not_in_store) !== 1 ? 's' : ''; ?></span>
                        <button class="nis-new-btn" onclick="openNewProductModal()">+ Add New Product</button>
                    </div>
                </div>
                <div class="nis-search-wrap">
                    <input type="text" id="nis-search" placeholder="Search products not in store..." oninput="filterNisList()">
                </div>
                <div class="nis-list" id="nis-list">
                    <?php if (empty($not_in_store)): ?>
                        <div class="nis-empty">All admin products are already in your store.</div>
                    <?php else: ?>
                        <?php foreach ($not_in_store as $np): ?>
                        <div class="nis-item" data-name="<?php echo htmlspecialchars(strtolower($np['name'])); ?>">
                            <div>
                                <div class="nis-item-name"><?php echo htmlspecialchars($np['name']); ?></div>
                                <div class="nis-item-meta">
                                    Admin price: ₱<?php echo number_format($np['admin_price'],2); ?>
                                    <?php if ($np['barcode']): ?> · <?php echo htmlspecialchars($np['barcode']); ?><?php endif; ?>
                                </div>
                            </div>
                            <button class="nis-add-btn" onclick='openAddToStoreModal(<?php echo json_encode([
                                "product_id" => $np["id"],
                                "name" => $np["name"],
                                "admin_price" => $np["admin_price"],
                                "purchase_price" => $np["purchase_price"]
                            ]); ?>)'>+ Add to Store</button>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Edit Price & Stock Modal (scoped to manager's own store) -->
        <div class="modal" id="store-stock-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Edit Price &amp; Stock</h2>
                    <button class="btn-close-modal" onclick="closeStoreStockModal()">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="product_id" id="store-stock-product-id">

                    <div class="form-group">
                        <label>Product</label>
                        <input type="text" id="store-stock-product-name" disabled style="background: #f0f2f5;">
                    </div>

                    <hr style="margin: 20px 0;">

                    <h3 style="margin-bottom: 15px;">Update Selling Price</h3>
                    <div class="form-group">
                        <label>Selling Price (₱)</label>
                        <input type="number" name="price" id="store-stock-price" step="0.01" min="0">
                    </div>

                    <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                        <button type="submit" name="action" value="update_store_price" class="btn btn-primary">
                            💵 Update Price
                        </button>
                    </div>

                    <hr style="margin: 20px 0;">

                    <h3 style="margin-bottom: 15px;">Update Stock</h3>
                    <div class="form-group">
                        <label>Current Stock</label>
                        <input type="text" id="store-stock-current" disabled style="background: #f0f2f5; font-weight: bold;">
                    </div>

                    <div class="form-group">
                        <label>Change Type *</label>
                        <select name="change_type" id="store-stock-change-type">
                            <option value="add">➕ Add Stock</option>
                            <option value="remove">➖ Remove Stock</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Quantity *</label>
                        <input type="number" name="stock_change" id="store-stock-change" min="1">
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="submit" name="action" value="update_store_stock" class="btn btn-success">
                            📦 Update Stock
                        </button>
                        <button type="button" class="btn btn-warning" onclick="closeStoreStockModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
        <!-- Add to Store Modal -->
        <div class="modal" id="add-to-store-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Add Product to Store</h2>
                    <button class="btn-close-modal" onclick="closeAddToStoreModal()">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="add_to_store">
                    <input type="hidden" name="product_id" id="ats-product-id">
                    <div class="form-group">
                        <label>Product</label>
                        <input type="text" id="ats-product-name" disabled style="background:#f0f2f5;">
                    </div>
                    <div class="form-group">
                        <label>Admin Price</label>
                        <input type="text" id="ats-admin-price" disabled style="background:#f0f2f5;">
                    </div>
                    <div class="form-group">
                        <label>Store Selling Price (₱) *</label>
                        <input type="number" name="price" id="ats-price" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label>Initial Stock *</label>
                        <input type="number" name="stock" id="ats-stock" min="0" value="0" required>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button type="submit" class="btn btn-success">+ Add to Store</button>
                        <button type="button" class="btn btn-warning" onclick="closeAddToStoreModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add New Product Modal (full featured) -->
        <div class="modal" id="new-product-modal">
            <div class="modal-content" style="max-width:560px;max-height:90vh;overflow-y:auto;">
                <div class="modal-header">
                    <h2>Add New Product</h2>
                    <button class="btn-close-modal" onclick="closeNewProductModal()">&times;</button>
                </div>
                <form method="POST" id="npForm">
                    <input type="hidden" name="action" value="add_new_product">

                    <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" name="name" required placeholder="e.g., Nutrigro 50kg">
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <div style="display:flex;gap:8px;align-items:flex-start;">
                            <div style="flex:1;position:relative;">
                                <input type="text" id="npCatSearch" placeholder="Type to search category…" autocomplete="off"
                                       oninput="npOnCatType(this.value)" onfocus="npOpenCatDD()" onblur="setTimeout(npCloseCatDD,180)">
                                <input type="hidden" name="category_id" id="npCategoryId">
                                <div id="npCatDD" style="position:absolute;top:100%;left:0;right:0;z-index:999;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 4px 4px;max-height:160px;overflow-y:auto;box-shadow:0 4px 10px rgba(0,0,0,.12);display:none;"></div>
                                <div id="npCatBadge" style="display:none;margin-top:4px;">
                                    <span style="display:inline-block;background:#667eea;color:#fff;font-size:12px;padding:2px 8px;border-radius:3px;">
                                        <span id="npCatName"></span>
                                        <span style="margin-left:6px;cursor:pointer;opacity:.7;" onclick="npClearCat()" title="Remove">✕</span>
                                    </span>
                                </div>
                            </div>
                            <button type="button" style="padding:4px 8px;font-size:12px;background:#22c55e;color:#fff;border:none;border-radius:3px;cursor:pointer;white-space:nowrap;" onclick="npAddCategory()">+ Add</button>
                        </div>
                    </div>

                    <div style="display:flex;gap:10px;">
                        <div class="form-group" style="flex:1;">
                            <label>Selling Price (₱) *</label>
                            <input type="number" step="0.01" name="price" id="npPrice" required placeholder="₱0.00">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>Discounted Price</label>
                            <input type="number" step="0.01" name="discounted_price" id="npDiscountedPrice" placeholder="Auto-filled">
                        </div>
                    </div>

                    <div style="display:flex;gap:10px;">
                        <div class="form-group" style="flex:1;">
                            <label>Purchase Price (₱) *</label>
                            <input type="number" step="0.01" name="purchase_price" required placeholder="₱0.00">
                        </div>
                        <div class="form-group" style="flex:1;">
                            <label>Initial Stock *</label>
                            <input type="number" name="stock" min="0" value="0" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" placeholder="Optional" rows="2" style="resize:vertical;"></textarea>
                    </div>

                    <div class="form-group">
                        <label>Barcode</label>
                        <div style="display:flex;gap:8px;">
                            <input type="text" name="barcode" id="npBarcode" placeholder="Scan or generate" style="flex:1;">
                            <button type="button" style="padding:6px 12px;font-size:12px;background:#64748b;color:#fff;border:none;border-radius:4px;cursor:pointer;" onclick="npGenerateBarcode()">Generate</button>
                        </div>
                    </div>

                    <label style="display:flex;align-items:center;gap:8px;background:#f0f8ff;padding:10px;border-radius:4px;cursor:pointer;margin:12px 0;">
                        <input type="checkbox" id="npCanSellInd" name="can_sell_individually" style="width:auto;margin:0;">
                        <span style="font-weight:500;">Can be sold individually</span>
                    </label>

                    <div id="npIndOptions" style="display:none;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:14px;margin-bottom:12px;">
                        <div style="font-weight:700;font-size:13px;color:#92400e;margin-bottom:10px;">Individual Selling Options</div>
                        <div style="display:flex;gap:10px;">
                            <div class="form-group" style="flex:1;">
                                <label>Unit Type *</label>
                                <select id="npIndUnit" name="individual_sell_unit">
                                    <option value="">Select unit</option>
                                    <option value="kilo">Kilo</option>
                                    <option value="piece">Piece</option>
                                    <option value="bottle">Bottle</option>
                                    <option value="liter">Liter</option>
                                    <option value="gram">Gram</option>
                                </select>
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label>Units per Pack *</label>
                                <input type="number" id="npIndPieces" name="individual_pieces_per_pack" min="1" placeholder="e.g., 50">
                            </div>
                        </div>
                        <div style="display:flex;gap:10px;">
                            <div class="form-group" style="flex:1;">
                                <label>Price per Unit</label>
                                <input type="number" step="0.01" id="npIndPrice" name="individual_selling_price" placeholder="Auto-calculated">
                            </div>
                            <div class="form-group" style="flex:1;">
                                <label>Discounted per Unit</label>
                                <input type="number" step="0.01" id="npIndDiscounted" name="individual_discounted_price" placeholder="Auto-calculated">
                            </div>
                        </div>
                    </div>

                    <div style="display:flex;gap:10px;">
                        <button type="submit" class="btn btn-primary" style="flex:1;">Create & Add to Store</button>
                        <button type="button" class="btn btn-warning" onclick="closeNewProductModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        function openEditModal(data) {
            document.getElementById('store-stock-product-id').value = data.product_id;
            document.getElementById('store-stock-product-name').value = data.product_name;
            document.getElementById('store-stock-current').value = data.stock + ' units';
            document.getElementById('store-stock-price').value = data.price;
            document.getElementById('store-stock-change').value = '';
            document.getElementById('store-stock-modal').classList.add('active');
        }

        function closeStoreStockModal() {
            document.getElementById('store-stock-modal').classList.remove('active');
        }

        function searchAllProducts() {
            const input = document.getElementById('search-products');
            const filter = input.value.toUpperCase();
            const rows = document.querySelectorAll('#all-products-table tbody tr');

            rows.forEach(function(row) {
                const cell = row.querySelector('td');
                if (!cell) return;
                const text = (cell.textContent || '').toUpperCase();
                row.style.display = text.indexOf(filter) > -1 ? '' : 'none';
            });
        }

        // Add to Store modal
        function openAddToStoreModal(data) {
            document.getElementById('ats-product-id').value = data.product_id;
            document.getElementById('ats-product-name').value = data.name;
            document.getElementById('ats-admin-price').value = '₱' + parseFloat(data.admin_price).toFixed(2);
            document.getElementById('ats-price').value = data.admin_price;
            document.getElementById('ats-stock').value = 0;
            document.getElementById('add-to-store-modal').classList.add('active');
        }

        function closeAddToStoreModal() {
            document.getElementById('add-to-store-modal').classList.remove('active');
        }

        // New Product modal
        function openNewProductModal() {
            document.getElementById('npForm').reset();
            npClearCat();
            document.getElementById('npIndOptions').style.display = 'none';
            document.getElementById('new-product-modal').classList.add('active');
        }

        function closeNewProductModal() {
            document.getElementById('new-product-modal').classList.remove('active');
        }

        // ── Category autocomplete for new product modal ──
        let npCategories = <?php echo json_encode($initialCategories); ?>;
        let npSelectedCatId = null;

        function npRenderCatOpts(list) {
            const dd = document.getElementById('npCatDD');
            if (!list.length) {
                dd.innerHTML = '<div style="padding:9px 12px;color:#999;font-style:italic;font-size:13px;">No categories found</div>';
            } else {
                dd.innerHTML = list.map(c =>
                    `<div style="padding:9px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f0;" onmousedown="npSelectCat(${c.id},'${c.category_name.replace(/'/g,"\\'")}')"
                         onmouseover="this.style.background='#e8f4ff'" onmouseout="this.style.background=''">${c.category_name}</div>`
                ).join('');
            }
        }

        function npOpenCatDD() {
            const q = document.getElementById('npCatSearch').value.toLowerCase();
            npRenderCatOpts(q ? npCategories.filter(c => c.category_name.toLowerCase().includes(q)) : npCategories);
            document.getElementById('npCatDD').style.display = 'block';
        }

        function npCloseCatDD() { document.getElementById('npCatDD').style.display = 'none'; }

        function npOnCatType(val) {
            npRenderCatOpts(val ? npCategories.filter(c => c.category_name.toLowerCase().includes(val.toLowerCase())) : npCategories);
            document.getElementById('npCatDD').style.display = 'block';
            npSelectedCatId = null;
            document.getElementById('npCategoryId').value = '';
            document.getElementById('npCatBadge').style.display = 'none';
        }

        function npSelectCat(id, name) {
            npSelectedCatId = id;
            document.getElementById('npCategoryId').value = id;
            document.getElementById('npCatSearch').value = '';
            document.getElementById('npCatSearch').placeholder = name;
            document.getElementById('npCatName').textContent = name;
            document.getElementById('npCatBadge').style.display = 'block';
            npCloseCatDD();
        }

        function npClearCat() {
            npSelectedCatId = null;
            document.getElementById('npCategoryId').value = '';
            document.getElementById('npCatSearch').value = '';
            document.getElementById('npCatSearch').placeholder = 'Type to search category…';
            document.getElementById('npCatBadge').style.display = 'none';
        }

        function npAddCategory() {
            const name = prompt('Enter new category name:');
            if (!name || !name.trim()) return;
            fetch('/oro-store-demo/products/new_product.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=add_category&category_name=' + encodeURIComponent(name.trim())
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    npCategories.push({ id: d.id, category_name: d.category_name });
                    npCategories.sort((a, b) => a.category_name.localeCompare(b.category_name));
                    npSelectCat(d.id, d.category_name);
                } else {
                    alert('Error: ' + (d.error || 'Unknown'));
                }
            })
            .catch(err => alert('Failed: ' + err.message));
        }

        // ── Individual selling toggle ──
        document.getElementById('npCanSellInd').addEventListener('change', function() {
            const show = this.checked;
            document.getElementById('npIndOptions').style.display = show ? 'block' : 'none';
            if (show) {
                document.getElementById('npIndUnit').setAttribute('required', 'required');
                document.getElementById('npIndPieces').setAttribute('required', 'required');
            } else {
                document.getElementById('npIndUnit').removeAttribute('required');
                document.getElementById('npIndPieces').removeAttribute('required');
            }
        });

        // ── Price auto-calc ──
        document.getElementById('npPrice').addEventListener('input', function() {
            const price = parseFloat(this.value);
            const dp = document.getElementById('npDiscountedPrice');
            if (price > 0 && !dp.dataset.userChanged) dp.value = price.toFixed(2);
            npRecalcInd();
        });

        document.getElementById('npDiscountedPrice').addEventListener('input', function() {
            if (this.value) this.dataset.userChanged = 'true';
            else { delete this.dataset.userChanged; const p = parseFloat(document.getElementById('npPrice').value); if (p > 0) this.value = p.toFixed(2); }
            npRecalcInd();
        });

        document.getElementById('npIndPieces').addEventListener('input', npRecalcInd);

        document.getElementById('npIndPrice').addEventListener('input', function() {
            if (this.value) {
                this.dataset.userChanged = 'true';
                const ip = parseFloat(this.value);
                const idp = document.getElementById('npIndDiscounted');
                if (!idp.dataset.userChanged && ip > 0) idp.value = ip.toFixed(2);
            } else { delete this.dataset.userChanged; npRecalcInd(); }
        });

        document.getElementById('npIndDiscounted').addEventListener('input', function() {
            if (this.value) this.dataset.userChanged = 'true';
            else { delete this.dataset.userChanged; const ip = parseFloat(document.getElementById('npIndPrice').value); if (ip > 0) this.value = ip.toFixed(2); }
        });

        function npRecalcInd() {
            const price = parseFloat(document.getElementById('npPrice').value);
            const disc = parseFloat(document.getElementById('npDiscountedPrice').value || price);
            const pieces = parseInt(document.getElementById('npIndPieces').value);
            const ipEl = document.getElementById('npIndPrice');
            const idEl = document.getElementById('npIndDiscounted');
            if (price > 0 && pieces > 0) {
                if (!ipEl.dataset.userChanged) ipEl.value = (price / pieces).toFixed(2);
                if (!idEl.dataset.userChanged) idEl.value = (disc / pieces).toFixed(2);
            }
        }

        // ── Barcode generation ──
        function npGenerateBarcode() {
            fetch('/oro-store-demo/products/new_product.php?action=generate_barcode')
                .then(r => r.json())
                .then(d => { if (d.error) alert(d.error); else document.getElementById('npBarcode').value = d.barcode; })
                .catch(err => alert('Error: ' + err));
        }

        // ── Not-in-store search filter ──
        function filterNisList() {
            const q = document.getElementById('nis-search').value.toLowerCase().trim();
            document.querySelectorAll('.nis-item').forEach(item => {
                item.style.display = (!q || (item.dataset.name || '').includes(q)) ? '' : 'none';
            });
        }

        // ── Close modals on Escape ──
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeStoreStockModal();
                closeAddToStoreModal();
                closeNewProductModal();
            }
        });

        // ── Close modals on click outside ──
        ['store-stock-modal','add-to-store-modal','new-product-modal'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('active'); });
        });
    </script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Manager Products', array (
  'Features' => 
  array (
    0 => 'View and manage products for assigned store',
    1 => 'Edit store-specific prices and stock',
    2 => 'Cannot modify global product settings',
  ),
));
?>
</body>
</html>