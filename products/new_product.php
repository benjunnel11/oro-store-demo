<?php
ob_start(); // buffer output so PHP notices don't corrupt AJAX JSON responses
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/sync_helper.php';
require_once __DIR__ . '/../core/system_logger.php';

// Only admins and managers can access
if (!isManager() && !isAdmin()) {
    header("Location: /oro-store-demo/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

// ─── Ensure product_brands table exists ──────────────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS product_brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    brand_name VARCHAR(100) NOT NULL,
    is_deleted TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_brand (brand_name)
)");

// ─── Ensure products.brand_id column exists ──────────────────────────────────
$colCheck = $conn->query("SHOW COLUMNS FROM products LIKE 'brand_id'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE products ADD COLUMN brand_id INT DEFAULT NULL");
}

// ─── AJAX: Generate barcode ───────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'generate_barcode') {
    header('Content-Type: application/json');
    try {
        $maxAttempts = 100;
        $attempt = 0;

        do {
            $barcode = '';
            for ($i = 0; $i < 12; $i++) {
                $barcode .= rand(0, 9);
            }
            // Calculate EAN-13 check digit
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += ($i % 2 === 0) ? (int)$barcode[$i] : (int)$barcode[$i] * 3;
            }
            $checkDigit = (10 - ($sum % 10)) % 10;
            $barcode .= $checkDigit;

            $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ?");
            $stmt->bind_param("s", $barcode);
            $stmt->execute();
            $result = $stmt->get_result();
            $exists = $result->num_rows > 0;
            $stmt->close();

            $attempt++;
        } while ($exists && $attempt < $maxAttempts);

        if ($attempt >= $maxAttempts) {
            echo json_encode(['error' => 'Could not generate unique barcode']);
        } else {
            echo json_encode(['barcode' => $barcode]);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    $conn->close();
    exit;
}

// ─── AJAX: Get categories ─────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_categories') {
    header('Content-Type: application/json');
    $search = trim($_GET['search'] ?? '');
    $sql = "SELECT id, category_name FROM product_categories WHERE is_deleted = 0";
    if ($search !== '') {
        $like = '%' . $conn->real_escape_string($search) . '%';
        $sql .= " AND category_name LIKE '$like'";
    }
    $sql .= " ORDER BY category_name ASC";
    $res = $conn->query($sql);
    echo json_encode($res ? $res->fetch_all(MYSQLI_ASSOC) : []);
    $conn->close();
    exit;
}

// ─── AJAX: Get brands ─────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_brands') {
    header('Content-Type: application/json');
    $search = trim($_GET['search'] ?? '');
    $sql = "SELECT id, brand_name FROM product_brands WHERE is_deleted = 0";
    if ($search !== '') {
        $like = '%' . $conn->real_escape_string($search) . '%';
        $sql .= " AND brand_name LIKE '$like'";
    }
    $sql .= " ORDER BY brand_name ASC";
    $res = $conn->query($sql);
    echo json_encode($res ? $res->fetch_all(MYSQLI_ASSOC) : []);
    $conn->close();
    exit;
}

// ─── AJAX: Add category ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_category') {
    ob_clean(); // discard any stray output so JSON stays clean
    header('Content-Type: application/json');
    try {
        $name = trim($_POST['category_name'] ?? '');
        if (!$name) {
            echo json_encode(['success' => false, 'error' => 'Category name is required']);
            $conn->close(); exit;
        }

        // Auto-create table if migration has not been run yet
        $conn->query("CREATE TABLE IF NOT EXISTS product_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_name VARCHAR(100) NOT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cat_name (category_name)
        )");

        // Check for duplicate
        $stmt = $conn->prepare("SELECT id FROM product_categories WHERE category_name = ? AND is_deleted = 0");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            echo json_encode(['success' => false, 'error' => "Category '$name' already exists"]);
            $conn->close(); exit;
        }

        // Insert with raw mysqli (avoids SyncDB dependency issues)
        $stmt = $conn->prepare("INSERT INTO product_categories (category_name, is_deleted) VALUES (?, 0)");
        $stmt->bind_param("s", $name);
        if (!$stmt->execute()) {
            throw new Exception("Insert failed: " . $stmt->error);
        }
        $newId = $conn->insert_id;
        $stmt->close();

        if (!$newId) {
            throw new Exception("No insert_id returned — check table permissions");
        }

        echo json_encode(['success' => true, 'id' => $newId, 'category_name' => $name]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── AJAX: Add brand ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_brand') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $name = trim($_POST['brand_name'] ?? '');
        if (!$name) {
            echo json_encode(['success' => false, 'error' => 'Brand name is required']);
            $conn->close(); exit;
        }

        // Check for duplicate
        $stmt = $conn->prepare("SELECT id FROM product_brands WHERE brand_name = ? AND is_deleted = 0");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($existing) {
            echo json_encode(['success' => false, 'error' => "Brand '$name' already exists"]);
            $conn->close(); exit;
        }

        $stmt = $conn->prepare("INSERT INTO product_brands (brand_name, is_deleted) VALUES (?, 0)");
        $stmt->bind_param("s", $name);
        if (!$stmt->execute()) {
            throw new Exception("Insert failed: " . $stmt->error);
        }
        $newId = $conn->insert_id;
        $stmt->close();

        if (!$newId) {
            throw new Exception("No insert_id returned — check table permissions");
        }

        echo json_encode(['success' => true, 'id' => $newId, 'brand_name' => $name]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── AJAX: Add product to store ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_store') {
    ob_clean();
    header('Content-Type: application/json');
    try {
        $product_id = intval($_POST['product_id']);
        $store_id = intval($_POST['store_id']);
        $price = floatval($_POST['price']);
        $purchase_price = floatval($_POST['purchase_price']);
        $stock = intval($_POST['stock']);

        $chk = $conn->prepare("SELECT id FROM store_prices WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
        $chk->bind_param("ii", $product_id, $store_id);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $chk->close();
            echo json_encode(['success' => false, 'error' => 'Product already exists in this store']);
            $conn->close(); exit;
        }
        $chk->close();

        $db = new SyncDB();
        $db->insert('store_prices', [
            'product_id' => $product_id,
            'store_id' => $store_id,
            'price' => $price,
            'purchase_price' => $purchase_price,
            'stock' => $stock
        ]);

        $pname = $conn->query("SELECT name FROM products WHERE id = $product_id")->fetch_assoc()['name'] ?? '';
        $scode = $conn->query("SELECT store_code FROM stores WHERE id = $store_id")->fetch_assoc()['store_code'] ?? '';
        logActivity('product', "Added product to store: $pname -> $scode", $currentUser['id'], $store_id, [
            'product_id' => $product_id, 'store_id' => $store_id, 'price' => $price, 'stock' => $stock
        ]);

        // Auto-add child product (individual unit) to store with max units stock
        $child_q = $conn->prepare("SELECT c.id, c.price, c.purchase_price, p.individual_pieces_per_pack
            FROM products c JOIN products p ON c.parent_product_id = p.id
            WHERE c.parent_product_id = ? AND c.is_deleted = 0 LIMIT 1");
        $child_q->bind_param("i", $product_id);
        $child_q->execute();
        $child_row = $child_q->get_result()->fetch_assoc();
        $child_q->close();
        if ($child_row) {
            $child_id = $child_row['id'];
            $child_exists = $conn->prepare("SELECT id FROM store_prices WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
            $child_exists->bind_param("ii", $child_id, $store_id);
            $child_exists->execute();
            if ($child_exists->get_result()->num_rows === 0) {
                $child_pieces = max(1, intval($child_row['individual_pieces_per_pack']));
                $db->insert('store_prices', [
                    'product_id' => $child_id,
                    'store_id' => $store_id,
                    'price' => $child_row['price'],
                    'purchase_price' => $child_row['purchase_price'],
                    'stock' => $child_pieces
                ]);
            }
            $child_exists->close();
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── Handle form submission ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    // Sanitize inputs
    $name = trim($_POST['name']);
    $price = floatval($_POST['price']);
    $discounted_price = !empty($_POST['discounted_price']) ? floatval($_POST['discounted_price']) : $price;
    $purchase_price = floatval($_POST['purchase_price']);
    $stock = intval($_POST['stock']);
    $description = trim($_POST['description']);
    $barcode = trim($_POST['barcode']);
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $brand_id = !empty($_POST['brand_id']) ? intval($_POST['brand_id']) : null;

    // Individual selling product fields
    $can_sell_individually = isset($_POST['can_sell_individually']) ? 1 : 0;
    $individual_sell_unit = $can_sell_individually ? trim($_POST['individual_sell_unit']) : null;
    $individual_pieces_per_pack = $can_sell_individually ? intval($_POST['individual_pieces_per_pack']) : null;
    $individual_selling_price = $can_sell_individually ? floatval($_POST['individual_selling_price']) : null;
    $individual_discounted_price = $can_sell_individually && !empty($_POST['individual_discounted_price']) ? floatval($_POST['individual_discounted_price']) : $individual_selling_price;

    $db = new SyncDB();

    try {
        // Insert main pack product
        $newProductId = $db->insert('products', [
            'name' => $name,
            'price' => $price,
            'discounted_price' => $discounted_price,
            'purchase_price' => $purchase_price,
            'stock' => $stock,
            'description' => $description,
            'barcode' => $barcode,
            'category_id' => $category_id,
            'brand_id' => $brand_id,
            'can_sell_individually' => $can_sell_individually,
            'individual_sell_unit' => $individual_sell_unit,
            'individual_pieces_per_pack' => $individual_pieces_per_pack,
            'individual_selling_price' => $individual_selling_price,
            'individual_discounted_price' => $individual_discounted_price
        ]);

        if (!$newProductId) {
            throw new Exception("Failed to insert main product");
        }

        // Log product created
        $newDetails = "Name: $name, Price: $price, Discounted Price: $discounted_price, Purchase Price: $purchase_price, Stock: $stock, Description: $description, Barcode: $barcode";
        $db->insert('product_history', [
            'product_id' => $newProductId,
            'change_type' => 'added',
            'old_value' => '',
            'new_value' => $newDetails
        ]);

        logActivity(
            'product',
            'Product Added',
            $currentUser['id'],
            $currentUser['store_id'] ?? null,
            [
                'product_id' => $newProductId,
                'product_name' => $name,
                'barcode' => $barcode,
                'price' => $price,
                'discounted_price' => $discounted_price,
                'purchase_price' => $purchase_price,
                'stock' => $stock,
                'category_id' => $category_id,
                'brand_id' => $brand_id,
                'can_sell_individually' => $can_sell_individually,
                'individual_sell_unit' => $individual_sell_unit,
                'individual_pieces_per_pack' => $individual_pieces_per_pack,
                'individual_selling_price' => $individual_selling_price,
                'individual_discounted_price' => $individual_discounted_price
            ]
        );

        // If individual selling enabled, create individual product
        if ($can_sell_individually && $individual_sell_unit && $individual_pieces_per_pack > 0) {
            $ind_name = $name;
            $ind_barcode = $barcode ? $barcode . '-IND' : null;

            // Use manual price if set, otherwise auto-calculate
            $ind_price = $individual_selling_price > 0 ? $individual_selling_price : ($price / $individual_pieces_per_pack);
            $ind_discounted_price = $individual_discounted_price > 0 ? $individual_discounted_price : ($discounted_price / $individual_pieces_per_pack);
            $ind_purchase_price = $purchase_price / $individual_pieces_per_pack;

            // Calculate initial individual stock (total pieces available)
            $ind_stock = $individual_pieces_per_pack;

            $individualProductId = $db->insert('products', [
                'name' => $ind_name,
                'price' => $ind_price,
                'discounted_price' => $ind_discounted_price,
                'purchase_price' => $ind_purchase_price,
                'stock' => $ind_stock,
                'description' => "Individual unit of " . $name . " (linked to pack ID: $newProductId)",
                'barcode' => $ind_barcode,
                'parent_product_id' => $newProductId,
                'category_id' => null  // Individual products have no category — they have their own separate delivery fee / credit charge
            ]);

            if (!$individualProductId) {
                throw new Exception("Failed to insert individual product");
            }

            // Log individual product created
            $db->insert('product_history', [
                'product_id' => $individualProductId,
                'change_type' => 'added',
                'old_value' => '',
                'new_value' => "Individual product linked to pack product ID $newProductId"
            ]);

            logActivity(
                'product',
                'Individual Product Added',
                $currentUser['id'],
                $currentUser['store_id'] ?? null,
                [
                    'product_id' => $individualProductId,
                    'product_name' => $ind_name,
                    'linked_pack_product_id' => $newProductId,
                    'price' => $ind_price,
                    'discounted_price' => $ind_discounted_price,
                    'purchase_price' => $ind_purchase_price,
                    'stock' => $ind_stock
                ]
            );
        }

        // Redirect with success message, product ID, and last-used category/brand
        $redirectParams = ['success' => 1, 'new_pid' => $newProductId, 'new_pname' => $name, 'new_pprice' => $price, 'new_pcost' => $purchase_price];
        if ($category_id) {
            $redirectParams['last_cat'] = $category_id;
            // Look up category name
            $catStmt = $conn->prepare("SELECT category_name FROM product_categories WHERE id = ?");
            $catStmt->bind_param("i", $category_id);
            $catStmt->execute();
            $catRow = $catStmt->get_result()->fetch_assoc();
            $catStmt->close();
            if ($catRow) {
                $redirectParams['last_cat_name'] = $catRow['category_name'];
            }
        }
        if ($brand_id) {
            $redirectParams['last_brand'] = $brand_id;
            // Look up brand name
            $brandStmt = $conn->prepare("SELECT brand_name FROM product_brands WHERE id = ?");
            $brandStmt->bind_param("i", $brand_id);
            $brandStmt->execute();
            $brandRow = $brandStmt->get_result()->fetch_assoc();
            $brandStmt->close();
            if ($brandRow) {
                $redirectParams['last_brand_name'] = $brandRow['brand_name'];
            }
        }
        header("Location: new_product.php?" . http_build_query($redirectParams));
        exit;

    } catch (Exception $e) {
        // Redirect with error message
        $error_msg = urlencode($e->getMessage());
        header("Location: new_product.php?error=" . $error_msg);
        exit;
    }

    $conn->close();
    exit;
}

// Load existing categories for initial dropdown
$catRes = $conn->query("SELECT id, category_name FROM product_categories WHERE is_deleted = 0 ORDER BY category_name ASC");
$initialCategories = $catRes ? $catRes->fetch_all(MYSQLI_ASSOC) : [];

// Load existing brands for initial dropdown
$brandRes = $conn->query("SELECT id, brand_name FROM product_brands WHERE is_deleted = 0 ORDER BY brand_name ASC");
$initialBrands = $brandRes ? $brandRes->fetch_all(MYSQLI_ASSOC) : [];

$storesRes = $conn->query("SELECT id, store_name, store_code FROM stores WHERE status = 'active' ORDER BY store_code");
$allStores = $storesRes ? $storesRes->fetch_all(MYSQLI_ASSOC) : [];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Add New Product</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <link rel="stylesheet" href="/oro-store-demo/style.css">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Segoe UI',sans-serif; background:linear-gradient(135deg,#e8eaf6 0%,#f5f7ff 50%,#e3f2fd 100%); font-size:13px; min-height:100vh; }
        .main-content { height:100vh; overflow-y:auto; }

        .np-container { max-width:980px; margin:0 auto; padding:14px 18px 24px; }

        /* Header */
        .np-page-header { display:flex; align-items:center; gap:12px; margin-bottom:14px; }
        .np-back-btn { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:10px; border:none; background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; text-decoration:none; font-size:16px; box-shadow:0 2px 8px rgba(102,126,234,.3); transition:transform .15s,box-shadow .15s; }
        .np-back-btn:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(102,126,234,.4); }
        .np-page-title { font-size:20px; font-weight:800; background:linear-gradient(135deg,#667eea,#764ba2); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }

        /* Alerts */
        .np-alert { padding:10px 14px; border-radius:10px; margin-bottom:12px; font-size:13px; font-weight:600; display:flex; align-items:center; gap:8px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
        .np-alert-success { background:linear-gradient(135deg,#f0fdf4,#dcfce7); color:#166534; border:1px solid #86efac; }
        .np-alert-error { background:linear-gradient(135deg,#fef2f2,#fee2e2); color:#991b1b; border:1px solid #fca5a5; }

        /* Card */
        .np-card { background:#fff; border-radius:16px; border:none; overflow:hidden; box-shadow:0 4px 24px rgba(102,126,234,.12),0 1px 3px rgba(0,0,0,.06); }

        /* 2 columns */
        .np-columns { display:flex; }
        .np-col { flex:1; min-width:0; }
        .np-col + .np-col { border-left:1px solid #eef0f6; }

        /* Sections */
        .np-section { padding:16px 20px; border-bottom:1px solid #eef0f6; }
        .np-section:last-child { border-bottom:none; }
        .np-section-header { font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.08em; margin:0 0 14px; padding:6px 12px; border-radius:8px; display:flex; align-items:center; gap:8px; }
        .np-section-header.hdr-info { background:linear-gradient(135deg,#eef2ff,#e0e7ff); color:#4338ca; }
        .np-section-header.hdr-price { background:linear-gradient(135deg,#ecfdf5,#d1fae5); color:#065f46; }
        .np-section-header.hdr-extra { background:linear-gradient(135deg,#fefce8,#fef9c3); color:#854d0e; }
        .np-section-header.hdr-ind { background:linear-gradient(135deg,#fdf2f8,#fce7f3); color:#9d174d; }

        /* Fields */
        .np-field { margin-bottom:12px; }
        .np-field:last-child { margin-bottom:0; }
        .np-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .np-grid-2 .np-field { margin-bottom:0; }
        .np-grid-2 + .np-grid-2 { margin-top:10px; }

        /* Labels */
        .np-label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:5px; }
        .np-label-hint { font-size:10px; font-weight:400; text-transform:none; letter-spacing:normal; color:#a5b4c8; margin-left:3px; }

        /* Inputs */
        .np-input, .np-select, .np-textarea {
            width:100%; padding:9px 12px; border:2px solid #e2e8f0; border-radius:10px; font-size:13px; color:#1e293b; background:#f8fafc;
            transition:border-color .2s,box-shadow .2s,background .2s;
        }
        .np-input:focus, .np-select:focus, .np-textarea:focus {
            outline:none; border-color:#667eea; background:#fff;
            box-shadow:0 0 0 3px rgba(102,126,234,.15),0 2px 8px rgba(102,126,234,.1);
        }
        .np-input::placeholder, .np-textarea::placeholder { color:#a5b4c8; }
        .np-textarea { resize:vertical; min-height:50px; }

        /* Autocomplete */
        .np-ac-wrap { display:flex; gap:6px; align-items:flex-start; }
        .np-ac-input-wrap { flex:1; position:relative; }
        .np-ac-dropdown { position:absolute; top:100%; left:0; right:0; z-index:999; background:#fff; border:2px solid #e2e8f0; border-top:none; border-radius:0 0 10px 10px; max-height:170px; overflow-y:auto; box-shadow:0 8px 24px rgba(0,0,0,.1); display:none; }
        .np-ac-dropdown.open { display:block; }
        .np-ac-opt { padding:9px 12px; cursor:pointer; font-size:12px; border-bottom:1px solid #f1f5f9; transition:background .1s; }
        .np-ac-opt:last-child { border-bottom:none; }
        .np-ac-opt:hover { background:linear-gradient(135deg,#eef2ff,#e0e7ff); color:#4338ca; }
        .np-ac-opt.empty { color:#a5b4c8; font-style:italic; cursor:default; }
        .np-ac-opt.empty:hover { background:none; color:#a5b4c8; }
        .np-ac-badge { display:inline-flex; align-items:center; gap:6px; background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; font-size:11px; font-weight:600; padding:4px 10px; border-radius:8px; margin-top:5px; box-shadow:0 2px 6px rgba(102,126,234,.25); }
        .np-ac-badge .np-ac-clear { cursor:pointer; opacity:.7; font-size:12px; }
        .np-ac-badge .np-ac-clear:hover { opacity:1; }
        .np-add-btn { padding:9px 12px; font-size:12px; font-weight:700; background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; border:none; border-radius:10px; cursor:pointer; white-space:nowrap; box-shadow:0 2px 6px rgba(102,126,234,.25); transition:transform .15s,box-shadow .15s; }
        .np-add-btn:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(102,126,234,.35); }

        /* Toggle */
        .np-toggle-wrap { display:flex; align-items:center; gap:10px; padding:10px 14px; background:linear-gradient(135deg,#fdf2f8,#fce7f3); border:2px solid #f9a8d4; border-radius:12px; cursor:pointer; user-select:none; margin-bottom:12px; transition:border-color .2s,box-shadow .2s; }
        .np-toggle-wrap:hover { border-color:#ec4899; box-shadow:0 2px 10px rgba(236,72,153,.15); }
        .np-toggle-wrap input[type="checkbox"] { width:16px; height:16px; accent-color:#ec4899; cursor:pointer; }
        .np-toggle-label { font-size:13px; font-weight:600; color:#9d174d; }
        .np-ind-options { display:none; }
        .np-ind-options.visible { display:block; }
        .np-ind-note { background:linear-gradient(135deg,#fffbeb,#fef3c7); border:1px solid #fbbf24; border-radius:10px; padding:10px 12px; font-size:11px; color:#92400e; margin-bottom:12px; line-height:1.5; }
        .np-ind-note strong { display:block; margin-bottom:2px; font-size:12px; }

        /* Inline group */
        .np-inline-group { display:flex; gap:6px; align-items:center; }
        .np-inline-group .np-select, .np-inline-group .np-input { flex:1; }

        /* Barcode */
        .np-barcode-actions { display:flex; gap:6px; align-items:center; }
        .np-barcode-actions .np-input { flex:1; }
        .np-btn-outline { padding:9px 12px; font-size:12px; font-weight:600; background:#fff; color:#667eea; border:2px solid #c7d2fe; border-radius:10px; cursor:pointer; white-space:nowrap; transition:all .15s; }
        .np-btn-outline:hover { background:#eef2ff; border-color:#667eea; box-shadow:0 2px 8px rgba(102,126,234,.12); }
        .np-barcode-preview { text-align:center; padding:14px; background:linear-gradient(135deg,#f8fafc,#eef2ff); border:2px dashed #c7d2fe; border-radius:12px; margin-top:10px; display:none; }
        .np-barcode-preview h4 { margin:0 0 8px; font-size:11px; font-weight:700; color:#667eea; text-transform:uppercase; letter-spacing:.06em; }

        /* Submit */
        .np-submit-wrap { padding:16px 20px; background:linear-gradient(135deg,#f8fafc,#eef2ff); }
        .np-submit-btn {
            width:100%; padding:14px; font-size:15px; font-weight:700; letter-spacing:.03em;
            background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); color:#fff; border:none; border-radius:12px; cursor:pointer;
            box-shadow:0 4px 16px rgba(102,126,234,.3); transition:transform .15s,box-shadow .15s;
        }
        .np-submit-btn:hover { transform:translateY(-2px); box-shadow:0 6px 24px rgba(102,126,234,.4); }
        .np-submit-btn:active { transform:translateY(0); }

        /* Modal overrides */
        .modal { display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(15,23,42,.5); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
        .modal.active { display:flex; }
        .modal-content { background:#fff; border-radius:16px; padding:24px; width:90%; max-width:480px; box-shadow:0 20px 60px rgba(0,0,0,.2); }

        @media (max-width:700px) {
            .np-columns { flex-direction:column; }
            .np-col + .np-col { border-left:none; border-top:1px solid #eef0f6; }
            .np-grid-2 { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
    <?php
    // Show the matching sidebar for whoever is actually logged in,
    // so managers don't get dropped into the admin layout.
    if (isAdmin()) {
        include_once __DIR__ . '/../admin/admin_sidebar.php';
    } else {
        include_once __DIR__ . '/../manager/manager_sidebar.php';
    }
    ?>
    <main class="main-content">
    <div class="np-container">

        <!-- Page Header -->
        <div class="np-page-header">
            <a href="/oro-store-demo/admin/admin_products.php" class="np-back-btn" title="Back to Products">&larr;</a>
            <h1 class="np-page-title">Add New Product</h1>
        </div>

        <!-- Alerts -->
        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div class="np-alert np-alert-success" id="success-message">
                Product added successfully!
            </div>
            <script>
                setTimeout(function() {
                    var msg = document.getElementById("success-message");
                    if (msg) {
                        msg.style.transition = "opacity 0.4s";
                        msg.style.opacity = "0";
                        setTimeout(function() { msg.remove(); }, 400);
                    }
                }, 3000);
            </script>
        <?php endif; ?>
        <?php if (isset($_GET['error'])): ?>
            <div class="np-alert np-alert-error" id="error-message">
                Error: <?php echo htmlspecialchars(urldecode($_GET['error'])); ?>
            </div>
            <script>
                setTimeout(function() {
                    var msg = document.getElementById("error-message");
                    if (msg) {
                        msg.style.transition = "opacity 0.4s";
                        msg.style.opacity = "0";
                        setTimeout(function() { msg.remove(); }, 400);
                    }
                }, 5000);
            </script>
        <?php endif; ?>

        <!-- Form Card -->
        <form method="POST" id="productForm" class="np-card">
        <div class="np-columns">

            <!-- LEFT COLUMN: Basic Info + Pricing -->
            <div class="np-col">
                <div class="np-section">
                    <h2 class="np-section-header hdr-info">Basic Info</h2>
                    <div class="np-field">
                        <label class="np-label" for="productName">Product Name</label>
                        <input type="text" class="np-input" id="productName" name="name" required placeholder="e.g., Nutrigro 50kg">
                    </div>
                    <div class="np-grid-2">
                        <div class="np-field">
                            <label class="np-label">Category</label>
                            <div class="np-ac-wrap">
                                <div class="np-ac-input-wrap">
                                    <input type="text" class="np-input" id="catSearchInput" placeholder="Search..." autocomplete="off" oninput="onCatType(this.value)" onfocus="openCatDropdown()" onblur="setTimeout(closeCatDropdown, 180)">
                                    <input type="hidden" name="category_id" id="categoryIdHidden">
                                    <div class="np-ac-dropdown" id="catDropdown"></div>
                                    <div id="catSelectedBadge" style="display:none"><span class="np-ac-badge"><span id="catSelectedName"></span><span class="np-ac-clear" onclick="clearCategory()">&#x2715;</span></span></div>
                                </div>
                                <button type="button" class="np-add-btn" onclick="promptAddCategory()">+</button>
                            </div>
                        </div>
                        <div class="np-field">
                            <label class="np-label">Brand</label>
                            <div class="np-ac-wrap">
                                <div class="np-ac-input-wrap">
                                    <input type="text" class="np-input" id="brandSearchInput" placeholder="Search..." autocomplete="off" oninput="onBrandType(this.value)" onfocus="openBrandDropdown()" onblur="setTimeout(closeBrandDropdown, 180)">
                                    <input type="hidden" name="brand_id" id="brandIdHidden">
                                    <div class="np-ac-dropdown" id="brandDropdown"></div>
                                    <div id="brandSelectedBadge" style="display:none"><span class="np-ac-badge"><span id="brandSelectedName"></span><span class="np-ac-clear" onclick="clearBrand()">&#x2715;</span></span></div>
                                </div>
                                <button type="button" class="np-add-btn" onclick="promptAddBrand()">+</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="np-section">
                    <h2 class="np-section-header hdr-price">Pricing</h2>
                    <div class="np-grid-2">
                        <div class="np-field">
                            <label class="np-label" for="packPrice">Selling Price</label>
                            <input type="number" step="0.01" class="np-input" name="price" id="packPrice" required placeholder="0.00">
                        </div>
                        <div class="np-field">
                            <label class="np-label" for="discountedPrice">Discounted <span class="np-label-hint">(auto)</span></label>
                            <input type="number" step="0.01" class="np-input" name="discounted_price" id="discountedPrice" placeholder="Same as selling">
                        </div>
                    </div>
                    <div class="np-grid-2">
                        <div class="np-field">
                            <label class="np-label" for="purchasePrice">Cost Price</label>
                            <input type="number" step="0.01" class="np-input" name="purchase_price" id="purchasePrice" required placeholder="0.00">
                        </div>
                        <div class="np-field"></div>
                    </div>
                    <input type="hidden" name="stock" value="0">
                </div>

                <div class="np-section">
                    <h2 class="np-section-header hdr-extra">Additional</h2>
                    <div class="np-field">
                        <label class="np-label" for="productDescription">Description</label>
                        <textarea class="np-textarea" name="description" id="productDescription" placeholder="Optional description" style="min-height:40px;"></textarea>
                    </div>
                    <div class="np-field">
                        <label class="np-label">Barcode</label>
                        <div class="np-barcode-actions">
                            <input type="text" class="np-input" name="barcode" id="barcode-input" placeholder="Scan or generate">
                            <button type="button" class="np-btn-outline" id="random-btn">Gen</button>
                            <button type="button" class="np-btn-outline" id="generate-btn">Show</button>
                        </div>
                    </div>
                    <div class="np-barcode-preview" id="barcode-display">
                        <h4>Preview</h4>
                        <svg id="barcode-svg"></svg><br>
                        <button type="button" class="np-btn-outline" id="print-btn" style="margin-top:6px;">Print</button>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: Individual Selling + Submit -->
            <div class="np-col">
                <div class="np-section">
                    <h2 class="np-section-header hdr-ind">Individual Selling</h2>
                    <label class="np-toggle-wrap">
                        <input type="checkbox" id="can_sell_individually" name="can_sell_individually">
                        <span class="np-toggle-label">Can be sold individually</span>
                    </label>
                    <div class="np-ind-options" id="individual-selling-options">
                        <div class="np-ind-note">
                            <strong>No category for individual units</strong>
                            Individual products get their own delivery fee / credit charge. Set those after adding.
                        </div>
                        <div class="np-field">
                            <label class="np-label">Selling Unit</label>
                            <div class="np-inline-group">
                                <select id="individual_sell_unit" name="individual_sell_unit" class="np-select">
                                    <option value="">Select unit</option>
                                    <option value="kilo">Kilo</option>
                                    <option value="piece">Piece</option>
                                    <option value="bottle">Bottle</option>
                                    <option value="liter">Liter</option>
                                    <option value="gram">Gram</option>
                                </select>
                                <button type="button" class="np-add-btn" id="add-unit-btn">+</button>
                            </div>
                        </div>
                        <div class="np-grid-2">
                            <div class="np-field">
                                <label class="np-label">Units per Pack</label>
                                <input type="number" class="np-input" id="individual_pieces_per_pack" name="individual_pieces_per_pack" min="1" placeholder="e.g., 50">
                            </div>
                            <div class="np-field">
                                <label class="np-label">Price/Unit <span class="np-label-hint">(auto)</span></label>
                                <input type="number" step="0.01" class="np-input" id="individual_selling_price" name="individual_selling_price" placeholder="Auto">
                            </div>
                        </div>
                        <div class="np-grid-2">
                            <div class="np-field">
                                <label class="np-label">Discounted/Unit <span class="np-label-hint">(auto)</span></label>
                                <input type="number" step="0.01" class="np-input" id="individual_discounted_price" name="individual_discounted_price" placeholder="Auto">
                            </div>
                            <div class="np-field"></div>
                        </div>
                    </div>
                </div>

                <div class="np-submit-wrap">
                    <button type="submit" class="np-submit-btn">Add Product</button>
                </div>
            </div>

        </div>
        </form>
    </div>

    <script>
        /* ── Category Autocomplete ───────────────────────────────────────────── */
        let allCategories = <?php echo json_encode($initialCategories); ?>;
        let selectedCategoryId = null;

        function renderCatOptions(list) {
            const dd = document.getElementById('catDropdown');
            if (!list.length) {
                dd.innerHTML = '<div class="np-ac-opt empty">No categories found</div>';
            } else {
                dd.innerHTML = list.map(c =>
                    `<div class="np-ac-opt" data-id="${c.id}" onmousedown="selectCategory(${c.id}, '${escJs(c.category_name)}')">${escHtml(c.category_name)}</div>`
                ).join('');
            }
        }

        function openCatDropdown() {
            const q = document.getElementById('catSearchInput').value.toLowerCase();
            const filtered = q
                ? allCategories.filter(c => c.category_name.toLowerCase().includes(q))
                : allCategories;
            renderCatOptions(filtered);
            document.getElementById('catDropdown').classList.add('open');
        }

        function closeCatDropdown() {
            document.getElementById('catDropdown').classList.remove('open');
        }

        function onCatType(val) {
            const q = val.toLowerCase();
            const filtered = q
                ? allCategories.filter(c => c.category_name.toLowerCase().includes(q))
                : allCategories;
            renderCatOptions(filtered);
            document.getElementById('catDropdown').classList.add('open');
            selectedCategoryId = null;
            document.getElementById('categoryIdHidden').value = '';
            document.getElementById('catSelectedBadge').style.display = 'none';
        }

        function selectCategory(id, name) {
            selectedCategoryId = id;
            document.getElementById('categoryIdHidden').value = id;
            document.getElementById('catSearchInput').value = '';
            document.getElementById('catSearchInput').placeholder = name;
            document.getElementById('catSelectedName').textContent = name;
            document.getElementById('catSelectedBadge').style.display = 'block';
            closeCatDropdown();
            localStorage.setItem('np_last_cat', id);
            localStorage.setItem('np_last_cat_name', name);
        }

        function clearCategory() {
            selectedCategoryId = null;
            document.getElementById('categoryIdHidden').value = '';
            document.getElementById('catSearchInput').placeholder = 'Search category...';
            document.getElementById('catSelectedBadge').style.display = 'none';
            localStorage.removeItem('np_last_cat');
            localStorage.removeItem('np_last_cat_name');
        }

        function promptAddCategory() {
            const newName = prompt('Enter new category name:');
            if (!newName || !newName.trim()) return;

            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=add_category&category_name=' + encodeURIComponent(newName.trim())
            })
            .then(r => r.text().then(text => {
                try { return JSON.parse(text); }
                catch(e) { throw new Error('Server returned non-JSON: ' + text.substring(0, 200)); }
            }))
            .then(d => {
                if (d.success) {
                    allCategories.push({ id: d.id, category_name: d.category_name });
                    allCategories.sort((a, b) => a.category_name.localeCompare(b.category_name));
                    selectCategory(d.id, d.category_name);
                } else {
                    alert('Error: ' + (d.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Failed to add category: ' + err.message));
        }

        /* ── Brand Autocomplete ──────────────────────────────────────────────── */
        let allBrands = <?php echo json_encode($initialBrands); ?>;
        let selectedBrandId = null;

        function renderBrandOptions(list) {
            const dd = document.getElementById('brandDropdown');
            if (!list.length) {
                dd.innerHTML = '<div class="np-ac-opt empty">No brands found</div>';
            } else {
                dd.innerHTML = list.map(b =>
                    `<div class="np-ac-opt" data-id="${b.id}" onmousedown="selectBrand(${b.id}, '${escJs(b.brand_name)}')">${escHtml(b.brand_name)}</div>`
                ).join('');
            }
        }

        function openBrandDropdown() {
            const q = document.getElementById('brandSearchInput').value.toLowerCase();
            const filtered = q
                ? allBrands.filter(b => b.brand_name.toLowerCase().includes(q))
                : allBrands;
            renderBrandOptions(filtered);
            document.getElementById('brandDropdown').classList.add('open');
        }

        function closeBrandDropdown() {
            document.getElementById('brandDropdown').classList.remove('open');
        }

        function onBrandType(val) {
            const q = val.toLowerCase();
            const filtered = q
                ? allBrands.filter(b => b.brand_name.toLowerCase().includes(q))
                : allBrands;
            renderBrandOptions(filtered);
            document.getElementById('brandDropdown').classList.add('open');
            selectedBrandId = null;
            document.getElementById('brandIdHidden').value = '';
            document.getElementById('brandSelectedBadge').style.display = 'none';
        }

        function selectBrand(id, name) {
            selectedBrandId = id;
            document.getElementById('brandIdHidden').value = id;
            document.getElementById('brandSearchInput').value = '';
            document.getElementById('brandSearchInput').placeholder = name;
            document.getElementById('brandSelectedName').textContent = name;
            document.getElementById('brandSelectedBadge').style.display = 'block';
            closeBrandDropdown();
            localStorage.setItem('np_last_brand', id);
            localStorage.setItem('np_last_brand_name', name);
        }

        function clearBrand() {
            selectedBrandId = null;
            document.getElementById('brandIdHidden').value = '';
            document.getElementById('brandSearchInput').placeholder = 'Search brand...';
            document.getElementById('brandSelectedBadge').style.display = 'none';
            localStorage.removeItem('np_last_brand');
            localStorage.removeItem('np_last_brand_name');
        }

        function promptAddBrand() {
            const newName = prompt('Enter new brand name:');
            if (!newName || !newName.trim()) return;

            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=add_brand&brand_name=' + encodeURIComponent(newName.trim())
            })
            .then(r => r.text().then(text => {
                try { return JSON.parse(text); }
                catch(e) { throw new Error('Server returned non-JSON: ' + text.substring(0, 200)); }
            }))
            .then(d => {
                if (d.success) {
                    allBrands.push({ id: d.id, brand_name: d.brand_name });
                    allBrands.sort((a, b) => a.brand_name.localeCompare(b.brand_name));
                    selectBrand(d.id, d.brand_name);
                } else {
                    alert('Error: ' + (d.error || 'Unknown error'));
                }
            })
            .catch(err => alert('Failed to add brand: ' + err.message));
        }

        /* ── Auto-select last used Category & Brand ──────────────────────── */
        (function() {
            // Check URL params first (from redirect after adding product)
            const params = new URLSearchParams(window.location.search);
            const lastCat = params.get('last_cat');
            const lastCatName = params.get('last_cat_name');
            const lastBrand = params.get('last_brand');
            const lastBrandName = params.get('last_brand_name');

            if (lastCat && lastCatName) {
                selectCategory(parseInt(lastCat), lastCatName);
                localStorage.setItem('np_last_cat', lastCat);
                localStorage.setItem('np_last_cat_name', lastCatName);
            } else {
                // Fall back to localStorage
                const savedCat = localStorage.getItem('np_last_cat');
                const savedCatName = localStorage.getItem('np_last_cat_name');
                if (savedCat && savedCatName) selectCategory(parseInt(savedCat), savedCatName);
            }

            if (lastBrand && lastBrandName) {
                selectBrand(parseInt(lastBrand), lastBrandName);
                localStorage.setItem('np_last_brand', lastBrand);
                localStorage.setItem('np_last_brand_name', lastBrandName);
            } else {
                const savedBrand = localStorage.getItem('np_last_brand');
                const savedBrandName = localStorage.getItem('np_last_brand_name');
                if (savedBrand && savedBrandName) selectBrand(parseInt(savedBrand), savedBrandName);
            }

            // Restore selling unit
            const savedUnit = localStorage.getItem('np_last_unit');
            if (savedUnit) {
                const unitSelect = document.getElementById('individual_sell_unit');
                // Check if option exists, add it if custom
                let found = false;
                for (let o of unitSelect.options) { if (o.value === savedUnit) { found = true; break; } }
                if (!found) {
                    const opt = document.createElement('option');
                    opt.value = savedUnit;
                    opt.textContent = savedUnit.charAt(0).toUpperCase() + savedUnit.slice(1);
                    unitSelect.appendChild(opt);
                }
                unitSelect.value = savedUnit;
            }

            // Restore units per pack
            const savedPieces = localStorage.getItem('np_last_pieces');
            if (savedPieces) {
                document.getElementById('individual_pieces_per_pack').value = savedPieces;
            }

            // Restore description
            const savedDesc = localStorage.getItem('np_last_desc');
            if (savedDesc) {
                document.getElementById('productDescription').value = savedDesc;
            }
        })();

        /* ── Pack Price -> Discounted Price ─────────────────────────────────── */
        document.getElementById('packPrice').addEventListener('input', function() {
            const price = parseFloat(this.value);
            const discountedPriceInput = document.getElementById('discountedPrice');
            if (price && price > 0 && !discountedPriceInput.dataset.userChanged) {
                discountedPriceInput.value = price.toFixed(2);
                discountedPriceInput.placeholder = price.toFixed(2);
            }
            recalcIndPrice();
        });

        document.getElementById('discountedPrice').addEventListener('input', function() {
            if (this.value) {
                this.dataset.userChanged = 'true';
            } else {
                delete this.dataset.userChanged;
                const packPrice = parseFloat(document.getElementById('packPrice').value);
                if (packPrice > 0) this.value = packPrice.toFixed(2);
            }
            recalcIndPrice();
        });

        /* ── Individual Selling Toggle ──────────────────────────────────────── */
        document.getElementById('can_sell_individually').addEventListener('change', function(e) {
            const show = e.target.checked;
            const div = document.getElementById('individual-selling-options');
            if (show) {
                div.classList.add('visible');
            } else {
                div.classList.remove('visible');
            }
            const unitSelect = document.getElementById('individual_sell_unit');
            const piecesInput = document.getElementById('individual_pieces_per_pack');
            if (show) {
                unitSelect.setAttribute('required', 'required');
                piecesInput.setAttribute('required', 'required');
            } else {
                unitSelect.removeAttribute('required');
                piecesInput.removeAttribute('required');
            }
        });

        /* ── Auto-calculate individual selling price ─────────────────────── */
        function recalcIndPrice() {
            const packPrice = parseFloat(document.getElementById('packPrice').value);
            const packDiscounted = parseFloat(document.getElementById('discountedPrice').value || packPrice);
            const pieces = parseInt(document.getElementById('individual_pieces_per_pack').value);
            const indPriceInput = document.getElementById('individual_selling_price');
            const indDiscountedPriceInput = document.getElementById('individual_discounted_price');
            if (packPrice > 0 && pieces > 0) {
                const calculated = packPrice / pieces;
                const calculatedDiscounted = packDiscounted / pieces;
                indPriceInput.placeholder = calculated.toFixed(2);
                if (!indPriceInput.dataset.userChanged && document.activeElement !== indPriceInput) {
                    indPriceInput.value = calculated.toFixed(2);
                }
                const indPrice = parseFloat(indPriceInput.value || calculated);
                indDiscountedPriceInput.placeholder = calculatedDiscounted.toFixed(2);
                if (!indDiscountedPriceInput.dataset.userChanged && document.activeElement !== indDiscountedPriceInput) {
                    indDiscountedPriceInput.value = calculatedDiscounted.toFixed(2);
                }
            }
        }

        document.getElementById('individual_pieces_per_pack').addEventListener('input', recalcIndPrice);
        document.getElementById('discountedPrice').addEventListener('input', recalcIndPrice);

        document.getElementById('individual_selling_price').addEventListener('input', function(e) {
            if (e.target.value) {
                e.target.dataset.userChanged = 'true';
                const indPrice = parseFloat(e.target.value);
                const indDiscountedPriceInput = document.getElementById('individual_discounted_price');
                if (!indDiscountedPriceInput.dataset.userChanged && document.activeElement !== indDiscountedPriceInput && indPrice > 0) {
                    indDiscountedPriceInput.value = indPrice.toFixed(2);
                }
            }
        });
        document.getElementById('individual_selling_price').addEventListener('blur', function(e) {
            if (!e.target.value) { delete e.target.dataset.userChanged; recalcIndPrice(); }
        });

        document.getElementById('individual_discounted_price').addEventListener('input', function(e) {
            if (e.target.value) { e.target.dataset.userChanged = 'true'; }
        });
        document.getElementById('individual_discounted_price').addEventListener('blur', function(e) {
            if (!e.target.value) {
                delete e.target.dataset.userChanged;
                recalcIndPrice();
            }
        });

        /* ── Remember description ──────────────────────────────────────── */
        document.getElementById('productDescription').addEventListener('input', function() {
            if (this.value) localStorage.setItem('np_last_desc', this.value);
            else localStorage.removeItem('np_last_desc');
        });

        /* ── Remember selling unit & units per pack ──────────────────────── */
        document.getElementById('individual_sell_unit').addEventListener('change', function() {
            if (this.value) localStorage.setItem('np_last_unit', this.value);
            else localStorage.removeItem('np_last_unit');
        });
        document.getElementById('individual_pieces_per_pack').addEventListener('change', function() {
            if (this.value) localStorage.setItem('np_last_pieces', this.value);
            else localStorage.removeItem('np_last_pieces');
        });

        /* ── Add custom selling unit ─────────────────────────────────────── */
        document.getElementById('add-unit-btn').addEventListener('click', function() {
            const newCat = prompt('Enter new unit type:');
            if (newCat && newCat.trim()) {
                const select = document.getElementById('individual_sell_unit');
                const option = document.createElement('option');
                option.value = newCat.trim().toLowerCase();
                option.textContent = newCat.trim().charAt(0).toUpperCase() + newCat.trim().slice(1);
                select.appendChild(option);
                select.value = option.value;
                localStorage.setItem('np_last_unit', option.value);
            }
        });

        /* ── Generate random barcode ─────────────────────────────────────── */
        document.getElementById('random-btn').addEventListener('click', function() {
            fetch('/oro-store-demo/products/new_product.php?action=generate_barcode')
                .then(response => response.json())
                .then(data => {
                    if (data.error) alert('Error: ' + data.error);
                    else document.getElementById('barcode-input').value = data.barcode;
                })
                .catch(error => alert('Error: ' + error));
        });

        /* ── Show barcode image ──────────────────────────────────────────── */
        document.getElementById('generate-btn').addEventListener('click', function() {
            const barcodeValue = document.getElementById('barcode-input').value;
            if (!barcodeValue) { alert('Please enter or generate a barcode first'); return; }
            try {
                JsBarcode("#barcode-svg", barcodeValue, {
                    format: "CODE128", width: 2, height: 50,
                    displayValue: true, fontSize: 14, margin: 5
                });
                document.getElementById('barcode-display').style.display = 'block';
            } catch (e) {
                alert('Invalid barcode: ' + e.message);
            }
        });

        /* ── Print barcode ───────────────────────────────────────────────── */
        document.getElementById('print-btn').addEventListener('click', function() {
            const barcodeValue = document.getElementById('barcode-input').value;
            const productName = document.querySelector('input[name="name"]').value;
            const productPrice = document.querySelector('input[name="price"]').value;
            const productStock = document.querySelector('input[name="stock"]').value;
            if (!barcodeValue) { alert('Please generate a barcode first'); return; }
            const params = new URLSearchParams({ barcode: barcodeValue, name: productName || 'Product', price: productPrice || '0', stock: productStock || '0' });
            window.open('/oro-store-demo/print/print_barcode_label.php?' + params.toString(), '_blank', 'width=500,height=600');
        });

        /* ── Helpers ─────────────────────────────────────────────────────── */
        function escHtml(str) {
            return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        }
        function escJs(str) {
            return String(str).replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/"/g,'\\"');
        }
    </script>

    <!-- Add to Store Modal -->
    <div class="modal" id="addToStoreModal">
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header">
                <h2 id="atsTitle">Add to Store</h2>
                <button class="btn-close-modal" onclick="closeAtsModal()">&times;</button>
            </div>
            <div id="atsBody">
                <p style="color:#64748b;font-size:13px;margin-bottom:16px;">Select which stores to add this product to and set the stock for each.</p>
                <div id="atsStoreList"></div>
            </div>
            <div style="display:flex;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid #e2e8f0;">
                <button onclick="submitAts()" class="btn btn-success" style="flex:1;">Add to Selected Stores</button>
                <button onclick="closeAtsModal()" class="btn btn-secondary">Skip</button>
            </div>
        </div>
    </div>

    <script>
    const allStoresData = <?php echo json_encode($allStores); ?>;
    let atsProductId = null, atsPrice = 0, atsCost = 0;

    function openAtsModal(pid, pname, price, cost) {
        atsProductId = pid;
        atsPrice = price;
        atsCost = cost;
        document.getElementById('atsTitle').textContent = 'Add "' + pname + '" to Store';
        const list = document.getElementById('atsStoreList');
        list.innerHTML = allStoresData.map(s => `
            <div style="display:flex;align-items:center;gap:10px;padding:10px 12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px;">
                <input type="checkbox" id="ats_store_${s.id}" data-sid="${s.id}" style="width:auto;margin:0;" checked>
                <label for="ats_store_${s.id}" style="flex:1;font-weight:600;font-size:13px;color:#1e293b;cursor:pointer;margin:0;">
                    ${s.store_code} — ${s.store_name}
                </label>
                <div style="display:flex;align-items:center;gap:6px;">
                    <label style="font-size:11px;color:#64748b;margin:0;">Stock:</label>
                    <input type="number" id="ats_stock_${s.id}" min="0" value="0" style="width:70px;padding:4px 8px;border:1px solid #e2e8f0;border-radius:4px;font-size:13px;text-align:center;">
                </div>
            </div>
        `).join('');
        document.getElementById('addToStoreModal').classList.add('active');
    }

    function closeAtsModal() {
        document.getElementById('addToStoreModal').classList.remove('active');
        atsProductId = null;
    }

    async function submitAts() {
        const checkboxes = document.querySelectorAll('#atsStoreList input[type="checkbox"]:checked');
        if (!checkboxes.length) { alert('Select at least one store'); return; }
        let added = 0, errors = [];
        for (const cb of checkboxes) {
            const sid = cb.dataset.sid;
            const stock = parseInt(document.getElementById('ats_stock_' + sid).value) || 0;
            try {
                const res = await fetch('', {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: `action=add_to_store&product_id=${atsProductId}&store_id=${sid}&price=${atsPrice}&purchase_price=${atsCost}&stock=${stock}`
                });
                const d = await res.json();
                if (d.success) added++;
                else errors.push(d.error);
            } catch(e) { errors.push(e.message); }
        }
        if (added > 0) {
            closeAtsModal();
            // Brief confirmation then let the success message show
        }
        if (errors.length) alert('Some errors:\n' + errors.join('\n'));
    }

    // Auto-open modal after adding a product
    (function() {
        const params = new URLSearchParams(window.location.search);
        const pid = params.get('new_pid');
        const pname = params.get('new_pname');
        const pprice = params.get('new_pprice');
        const pcost = params.get('new_pcost');
        if (pid && pname) {
            setTimeout(() => openAtsModal(parseInt(pid), pname, parseFloat(pprice||0), parseFloat(pcost||0)), 500);
        }
    })();

    document.getElementById('addToStoreModal').addEventListener('click', function(e) {
        if (e.target === this) closeAtsModal();
    });
    </script>
    <?php
    include_once __DIR__ . '/../core/page_info.php';
    renderPageInfo('Add New Product', [
        'Left Column' => [
            'Product name — required, main display name',
            'Category — searchable dropdown, click + to create new',
            'Brand — searchable dropdown, click + to create new',
            'Selling price — what customers pay',
            'Discounted price — auto-fills from selling price, override if needed',
            'Cost price — what you paid the supplier',
            'Description — optional product notes',
            'Barcode — scan, manually enter, or click Gen to auto-generate',
        ],
        'Right Column — Individual Selling' => [
            'Enable "Can be sold individually" for per-unit selling',
            'Select unit type (Kilo, Piece, Bottle, etc.) or add custom',
            'Units per Pack — how many individual units in one pack',
            'Price per Unit auto-calculates from pack price / units',
            'Creates a linked child product automatically',
            'Child inherits brand but gets no category (separate fee settings)',
        ],
        'Smart Defaults' => [
            'Last used category, brand, unit, and pieces auto-restore',
            'Description persists between product additions',
            'After adding, prompts to assign product to store(s)',
        ],
    ]);
    ?>
    </main>
</body>
</html>
