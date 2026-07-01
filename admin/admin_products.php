<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';
require_once __DIR__ . '/../sync/sync_helper.php';

// Only admins can access
if (!isAdmin()) {
    header("Location: /oro-store-demo/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();
$db = new SyncDB();
$_is_super = ($currentUser['role'] ?? '') === 'super_admin';
$_admin_store_id = $currentUser['store_id'] ?? null;

// AJAX: Get stock receipt history for a product
if (isset($_GET['action']) && $_GET['action'] === 'get_stock_history') {
    header('Content-Type: application/json');
    $pid = intval($_GET['product_id']);
    $r = $conn->query("SELECT sri.receipt_id, sri.quantity, sri.purchase_price, sri.selling_price, sri.old_stock, sri.new_stock,
        sr.supplier_name, sr.invoice_number, sr.created_at
        FROM stock_receipt_items sri
        JOIN stock_receipts sr ON sri.receipt_id = sr.id
        WHERE sri.product_id = $pid AND sr.is_deleted = 0
        ORDER BY sr.created_at DESC LIMIT 20");
    echo json_encode($r ? $r->fetch_all(MYSQLI_ASSOC) : []);
    exit;
}

// Block all product modifications on wrong device
$_on_own_device = isOnOwnDevice();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_on_own_device && !$_is_super) {
    $error = "You cannot modify products from this device. Use your assigned device.";
}

// Handle product actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && ($_on_own_device || $_is_super)) {
    if ($_POST['action'] === 'delete_product') {
        $product_id = intval($_POST['product_id']);
        
        $stmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $product_result = $stmt->get_result()->fetch_assoc();
        $product_name = $product_result ? $product_result['name'] : 'Unknown';
        
        $result = $db->update('products', [
            'is_deleted' => 1
        ], "id = $product_id");
        
        $db->update('store_prices', [
            'is_deleted' => 1
        ], "product_id = $product_id");
        
        if ($result) {
            $db->insert('product_history', [
                'product_id' => $product_id,
                'change_type' => 'deleted',
                'old_value' => $product_name,
                'new_value' => ''
            ]);
            
            logActivity('product', "Deleted product: $product_name (ID: $product_id)", 
                $currentUser['id'], null, 
                ['product_id' => $product_id, 'product_name' => $product_name]
            );
            
            $success = "Product '$product_name' deleted successfully!";
        } else {
            $error = "Error deleting product.";
        }
    } elseif ($_POST['action'] === 'update_global_price') {
        $product_id = intval($_POST['product_id']);
        
        // Get old values for logging
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $old_data = $stmt->get_result()->fetch_assoc();
        
        // Prepare update data
        $updateData = [
            'name' => trim($_POST['name']),
            'price' => floatval($_POST['price']),
            'discounted_price' => !empty($_POST['discounted_price']) ? floatval($_POST['discounted_price']) : floatval($_POST['price']),
            'purchase_price' => floatval($_POST['purchase_price']),
            'stock' => intval($_POST['stock']),
            'description' => trim($_POST['description']),
            'barcode' => trim($_POST['barcode']),
            'category_id' => !empty($_POST['category_id']) ? intval($_POST['category_id']) : null,
            'brand_id' => !empty($_POST['brand_id']) ? intval($_POST['brand_id']) : null
        ];
        
        // Handle individual selling fields
        $can_sell_individually = isset($_POST['can_sell_individually']) ? 1 : 0;
        $updateData['can_sell_individually'] = $can_sell_individually;
        
        if ($can_sell_individually) {
            $updateData['individual_sell_unit'] = trim($_POST['individual_sell_unit']);
            $updateData['individual_pieces_per_pack'] = intval($_POST['individual_pieces_per_pack']);
            $updateData['individual_selling_price'] = floatval($_POST['individual_selling_price']);
            $updateData['individual_discounted_price'] = !empty($_POST['individual_discounted_price']) ? floatval($_POST['individual_discounted_price']) : floatval($_POST['individual_selling_price']);
        } else {
            $updateData['individual_sell_unit'] = null;
            $updateData['individual_pieces_per_pack'] = null;
            $updateData['individual_selling_price'] = null;
            $updateData['individual_discounted_price'] = null;
        }
        
        $result = $db->update('products', $updateData, "id = $product_id");
        
        if ($result) {
            // Log the change
            logActivity('product', "Updated product: {$updateData['name']}", 
                $currentUser['id'], null, 
                [
                    'product_id' => $product_id,
                    'product_name' => $updateData['name'],
                    'changes' => $updateData
                ]
            );
            
            $db->insert('product_history', [
                'product_id' => $product_id,
                'change_type' => 'updated',
                'old_value' => json_encode($old_data),
                'new_value' => json_encode($updateData)
            ]);
            
            // Handle individual product update/creation
            if ($can_sell_individually && $updateData['individual_sell_unit'] && $updateData['individual_pieces_per_pack'] > 0) {
                // Check if individual product already exists
                $stmt = $conn->prepare("SELECT id FROM products WHERE parent_product_id = ?");
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $ind_result = $stmt->get_result();
                
                $ind_name = $updateData['name'];
                $ind_barcode = $updateData['barcode'] ? $updateData['barcode'] . '-IND' : null;
                $ind_price = $updateData['individual_selling_price'] > 0 ? $updateData['individual_selling_price'] : ($updateData['price'] / $updateData['individual_pieces_per_pack']);
                $ind_discounted_price = $updateData['individual_discounted_price'] > 0 ? $updateData['individual_discounted_price'] : ($updateData['discounted_price'] / $updateData['individual_pieces_per_pack']);
                $ind_purchase_price = $updateData['purchase_price'] / $updateData['individual_pieces_per_pack'];
                $ind_stock = $updateData['individual_pieces_per_pack'];
                
                if ($ind_result->num_rows > 0) {
                    // Update existing individual product
                    $ind_product = $ind_result->fetch_assoc();
                    $db->update('products', [
                        'name' => $ind_name,
                        'price' => $ind_price,
                        'discounted_price' => $ind_discounted_price,
                        'purchase_price' => $ind_purchase_price,
                        'stock' => $ind_stock,
                        'barcode' => $ind_barcode,
                        'description' => "Individual unit of " . $updateData['name'] . " (linked to pack ID: $product_id)"
                    ], "id = {$ind_product['id']}");
                } else {
                    // Create new individual product
                    $db->insert('products', [
                        'name' => $ind_name,
                        'price' => $ind_price,
                        'discounted_price' => $ind_discounted_price,
                        'purchase_price' => $ind_purchase_price,
                        'stock' => $ind_stock,
                        'description' => "Individual unit of " . $updateData['name'] . " (linked to pack ID: $product_id)",
                        'barcode' => $ind_barcode,
                        'parent_product_id' => $product_id
                    ]);
                }
            } else {
                // Delete individual product if exists and individual selling is disabled
                $stmt = $conn->prepare("SELECT id FROM products WHERE parent_product_id = ?");
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $ind_result = $stmt->get_result();
                if ($ind_result->num_rows > 0) {
                    $ind_product = $ind_result->fetch_assoc();
                    $db->update('products', ['is_deleted' => 1], "id = {$ind_product['id']}");
                }
            }
            
            // Update child product stock if provided
            if (!empty($_POST['child_product_id']) && isset($_POST['child_stock'])) {
                $child_id = intval($_POST['child_product_id']);
                $child_new_stock = intval($_POST['child_stock']);
                $child_old = $conn->query("SELECT stock FROM products WHERE id = $child_id")->fetch_assoc();
                if ($child_old && $child_old['stock'] != $child_new_stock) {
                    $db->update('products', ['stock' => $child_new_stock], "id = $child_id");
                    $db->insert('product_history', [
                        'product_id' => $child_id, 'change_type' => 'stock',
                        'old_value' => (string)$child_old['stock'], 'new_value' => (string)$child_new_stock,
                        'user_id' => $currentUser['id'], 'user_name' => $currentUser['full_name']
                    ]);
                }
            }

            $success = "Product updated successfully!";
        } else {
            $error = "Error updating product.";
        }

    } elseif ($_POST['action'] === 'update_store_price') {
        $product_id = intval($_POST['product_id']);
        $store_id = intval($_POST['store_id']);
        $new_price = floatval($_POST['price']);
        $new_purchase_price = floatval($_POST['purchase_price']);
        
        $stmt = $conn->prepare("
            SELECT p.name, sp.price, sp.purchase_price, s.store_code
            FROM store_prices sp
            JOIN products p ON sp.product_id = p.id
            JOIN stores s ON sp.store_id = s.id
            WHERE sp.product_id = ? AND sp.store_id = ?
        ");
        $stmt->bind_param("ii", $product_id, $store_id);
        $stmt->execute();
        $old_data = $stmt->get_result()->fetch_assoc();
        
        $result = $db->update('store_prices', [
            'price' => $new_price,
            'purchase_price' => $new_purchase_price
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
                    'new_price' => $new_price,
                    'old_purchase_price' => $old_data['purchase_price'],
                    'new_purchase_price' => $new_purchase_price
                ]
            );
            
            $db->insert('product_history', [
                'product_id' => $product_id,
                'change_type' => 'store_price_updated',
                'old_value' => "Store: {$old_data['store_code']}, Price: {$old_data['price']}, Purchase: {$old_data['purchase_price']}",
                'new_value' => "Store: {$old_data['store_code']}, Price: $new_price, Purchase: $new_purchase_price"
            ]);
            
            $success = "Store price updated successfully!";
        } else {
            $error = "Error updating store price.";
        }
        
    } elseif ($_POST['action'] === 'update_store_stock') {
        $product_id = intval($_POST['product_id']);
        $store_id = intval($_POST['store_id']);
        $stock_change = intval($_POST['stock_change']);
        $change_type = $_POST['change_type'];

        // Subtract/remove requires super admin password
        if ($change_type === 'remove') {
            $sa_password = $_POST['sa_password'] ?? '';
            if (empty($sa_password)) { $error = "Super admin password required to subtract stock."; }
            else {
                $sa_user = $conn->query("SELECT password FROM users WHERE role = 'super_admin' LIMIT 1")->fetch_assoc();
                if (!$sa_user || !password_verify($sa_password, $sa_user['password'])) { $error = "Incorrect super admin password."; }
            }
            if (!empty($error)) goto skip_stock_update;
        }

        $stmt = $conn->prepare("
            SELECT p.name, sp.stock, sp.purchase_price, s.store_code
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

            if ($change_type === 'add') {
                $new_stock = $old_stock + $stock_change;
                // Create a stock receipt so it's tracked in supply history
                $receipt_id = $db->insert('stock_receipts', [
                    'supplier_name' => 'Manual (Product Page)',
                    'invoice_number' => '',
                    'receipt_date' => date('Y-m-d'),
                    'total_items' => $stock_change,
                    'total_cost' => $stock_change * floatval($result_data['purchase_price']),
                    'total_selling_value' => 0,
                    'notes' => 'Added from product management page',
                    'user_id' => $currentUser['id'],
                    'user_name' => $currentUser['full_name'],
                    'store_id' => $store_id
                ]);
                $db->insert('stock_receipt_items', [
                    'receipt_id' => $receipt_id,
                    'product_id' => $product_id,
                    'product_name' => $result_data['name'],
                    'quantity' => $stock_change,
                    'purchase_price' => $result_data['purchase_price'],
                    'selling_price' => 0,
                    'old_stock' => $old_stock,
                    'new_stock' => $new_stock,
                    'old_selling_price' => 0,
                    'new_selling_price' => 0
                ]);
            } else {
                $new_stock = max(0, $old_stock - $stock_change);
            }

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
        }
        skip_stock_update:

    } elseif ($_POST['action'] === 'add_product_to_store') {
        header('Content-Type: application/json');
        $product_id     = intval($_POST['product_id']);
        $store_id       = intval($_POST['store_id']);
        $price          = floatval($_POST['price']);
        $purchase_price = floatval($_POST['purchase_price']);
        $stock          = intval($_POST['stock']);
        $ind_price      = isset($_POST['ind_price']) ? floatval($_POST['ind_price']) : 0;

        $conn->begin_transaction();
        try {
            // Check if parent already in store
            $chk = $conn->prepare("SELECT id FROM store_prices WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
            $chk->bind_param("ii", $product_id, $store_id);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) throw new Exception("Product already in this store");
            $chk->close();

            // Add parent
            $db->insert('store_prices', [
                'product_id' => $product_id, 'store_id' => $store_id,
                'price' => $price, 'purchase_price' => $purchase_price, 'stock' => $stock
            ]);

            // Auto-add child product if exists
            $ch = $conn->prepare("SELECT c.id, c.price, c.purchase_price, p.individual_pieces_per_pack FROM products c JOIN products p ON c.parent_product_id = p.id WHERE c.parent_product_id = ? AND c.is_deleted = 0 LIMIT 1");
            $ch->bind_param("i", $product_id);
            $ch->execute();
            $child = $ch->get_result()->fetch_assoc();
            $ch->close();

            if ($child) {
                $chk2 = $conn->prepare("SELECT id FROM store_prices WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
                $chk2->bind_param("ii", $child['id'], $store_id);
                $chk2->execute();
                if ($chk2->get_result()->num_rows === 0) {
                    $child_sell = $ind_price > 0 ? $ind_price : $child['price'];
                    $child_pieces = max(1, (int)$child['individual_pieces_per_pack']);
                    $child_cost = $purchase_price / $child_pieces;
                    $db->insert('store_prices', [
                        'product_id' => $child['id'], 'store_id' => $store_id,
                        'price' => $child_sell, 'purchase_price' => $child_cost, 'stock' => $child_pieces
                    ]);
                }
                $chk2->close();
            }

            $pname = $conn->query("SELECT name FROM products WHERE id = $product_id")->fetch_assoc()['name'] ?? '';
            $scode = $conn->query("SELECT store_code FROM stores WHERE id = $store_id")->fetch_assoc()['store_code'] ?? '';
            logActivity('product', "Added product to store: $pname -> $scode", $currentUser['id'], $store_id, [
                'product_id' => $product_id, 'store_id' => $store_id, 'price' => $price, 'stock' => $stock
            ]);

            $conn->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}

// Inventory summary stats — filter by admin's store (super admin sees all)
$inv = [];
$_inv_store_filter = '';
if (!$_is_super && $_admin_store_id) {
    $_inv_store_filter = "AND sp.store_id = " . intval($_admin_store_id);
}

$inv['total'] = $conn->query("SELECT COUNT(*) as c FROM products WHERE is_deleted = 0 AND parent_product_id IS NULL")->fetch_assoc()['c'];
$inv['in_stock'] = $conn->query("SELECT COUNT(*) as c FROM products p WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL AND COALESCE((SELECT SUM(sp.stock) FROM store_prices sp WHERE sp.product_id = p.id AND sp.is_deleted = 0 $_inv_store_filter), 0) > 10")->fetch_assoc()['c'];
$inv['low_stock'] = $conn->query("SELECT COUNT(*) as c FROM products p WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL AND COALESCE((SELECT SUM(sp.stock) FROM store_prices sp WHERE sp.product_id = p.id AND sp.is_deleted = 0 $_inv_store_filter), 0) BETWEEN 1 AND 10")->fetch_assoc()['c'];
$inv['out_of_stock'] = $conn->query("SELECT COUNT(*) as c FROM products p WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL AND COALESCE((SELECT SUM(sp.stock) FROM store_prices sp WHERE sp.product_id = p.id AND sp.is_deleted = 0 $_inv_store_filter), 0) = 0")->fetch_assoc()['c'];
$inv['total_units'] = $conn->query("SELECT COALESCE(SUM(sp.stock), 0) as c FROM store_prices sp JOIN products p ON sp.product_id = p.id AND p.is_deleted = 0 AND p.parent_product_id IS NULL WHERE sp.is_deleted = 0 $_inv_store_filter")->fetch_assoc()['c'];
$inv['total_value'] = $conn->query("SELECT COALESCE(SUM(sp.stock * sp.purchase_price), 0) as c FROM store_prices sp JOIN products p ON sp.product_id = p.id AND p.is_deleted = 0 AND p.parent_product_id IS NULL WHERE sp.is_deleted = 0 $_inv_store_filter")->fetch_assoc()['c'];
$inv['total_retail'] = $conn->query("SELECT COALESCE(SUM(sp.stock * sp.price), 0) as c FROM store_prices sp JOIN products p ON sp.product_id = p.id AND p.is_deleted = 0 AND p.parent_product_id IS NULL WHERE sp.is_deleted = 0 $_inv_store_filter")->fetch_assoc()['c'];

$inv['month_supply_cost'] = floatval($conn->query("SELECT COALESCE(SUM(total_cost),0) as c FROM stock_receipts WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) AND is_deleted=0")->fetch_assoc()['c']);
$inv['month_supply_count'] = $conn->query("SELECT COUNT(*) as c FROM stock_receipts WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) AND is_deleted=0")->fetch_assoc()['c'];

$stock_filter = $_GET['stock'] ?? 'all';

// Get all products with their store-specific data
$stmt = $conn->prepare("
    SELECT p.*, pc.category_name, pb.brand_name,
           ind.id AS ind_id, ind.name AS ind_name, ind.price AS ind_price,
           ind.stock AS ind_stock, ind.is_deleted AS ind_deleted,
           GROUP_CONCAT(
               CONCAT(s.store_code, ':', sp.stock, ':', sp.price, ':', sp.purchase_price, ':', s.id)
               ORDER BY s.store_code
               SEPARATOR '|'
           ) as store_data,
           (SELECT MAX(sr.created_at) FROM stock_receipt_items sri
            JOIN stock_receipts sr ON sri.receipt_id = sr.id
            WHERE sri.product_id = p.id AND sr.is_deleted = 0) as last_restock_date,
           (SELECT sr.supplier_name FROM stock_receipt_items sri
            JOIN stock_receipts sr ON sri.receipt_id = sr.id
            WHERE sri.product_id = p.id AND sr.is_deleted = 0
            ORDER BY sr.created_at DESC LIMIT 1) as last_supplier
    FROM products p
    LEFT JOIN product_categories pc ON p.category_id = pc.id AND pc.is_deleted = 0
    LEFT JOIN product_brands pb ON p.brand_id = pb.id AND pb.is_deleted = 0
    LEFT JOIN products ind ON ind.parent_product_id = p.id AND ind.is_deleted = 0
    LEFT JOIN store_prices sp ON p.id = sp.product_id AND sp.is_deleted = 0
    LEFT JOIN stores s ON sp.store_id = s.id AND s.status = 'active'
    WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL
    GROUP BY p.id
    ORDER BY p.name
");
$stmt->execute();
$all_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get all active stores for reference
$stmt = $conn->prepare("SELECT * FROM stores WHERE status = 'active' ORDER BY store_code");
$stmt->execute();
$all_stores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$catRes = $conn->query("SELECT id, category_name FROM product_categories WHERE is_deleted = 0 ORDER BY category_name ASC");
$allCategories = $catRes ? $catRes->fetch_all(MYSQLI_ASSOC) : [];

$brandRes = $conn->query("SELECT id, brand_name FROM product_brands WHERE is_deleted = 0 ORDER BY brand_name ASC");
$allBrands = $brandRes ? $brandRes->fetch_all(MYSQLI_ASSOC) : [];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Product Management - Admin Panel</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        .store-info-box {
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 8px;
            min-width: 140px;
        }
        
        .store-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
            font-size: 12px;
        }
        
        .store-info-label {
            color: #666;
            font-weight: 500;
        }
        
        .store-info-value {
            font-weight: bold;
            color: #333;
        }
        
        .price-selling {
            color: #28a745;
        }
        
        .price-purchase {
            color: #dc3545;
        }
        
        .store-info-divider {
            border-top: 1px solid #eee;
            margin: 6px 0;
        }
        
        /* Enhanced Modal Styles */
        .modal-content.large {
            max-width: 650px;
        }
        
        .inline-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .inline-group input,
        .inline-group select {
            flex: 1;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f0f8ff;
            padding: 10px;
            border-radius: 4px;
            cursor: pointer;
            margin: 15px 0;
        }
        
        .checkbox-label input[type="checkbox"] {
            width: auto;
            margin: 0;
        }
        
        #edit-individual-selling-options {
            background: #fffbf0;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 15px;
            margin: 10px 0;
            display: none;
        }
        
        #edit-individual-selling-options h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #856404;
        }
        
        .small-btn {
            padding: 4px 8px;
            font-size: 12px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        .small-btn:hover {
            background: #218838;
        }
        
        small.field-hint {
            display: block;
            color: #666;
            font-size: 12px;
            margin-top: 3px;
        }
        
        #edit-barcode-display {
            text-align: center;
            margin: 15px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
            display: none;
        }
        
        .btn-secondary {
            background: #6c757d;
            padding: 8px 12px;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        /* Override fixed layout for admin products table */
        .products-table {
            display: table;
        }
        .products-table thead,
        .products-table tbody {
            display: table-header-group;
            table-layout: auto;
        }
        .products-table tbody {
            display: table-row-group;
        }
        .products-table th,
        .products-table td {
            padding: 14px 20px;
            text-align: center;
            vertical-align: middle;
        }
        .products-table th:first-child,
        .products-table td:first-child {
            text-align: left;
            min-width: 180px;
        }
        .products-table th {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .03em;
            text-transform: uppercase;
            color: #64748b;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 3;
            background: #f0f2f5;
        }
        .products-table thead tr:nth-child(2) th {
            top: 43px;
        }
        .products-table tbody tr:nth-child(even) td {
            background: #fbfcfd;
        }
        .products-table tbody tr:hover td {
            background: #f0f4ff !important;
        }
        .ind-sub {
            display: block;
            font-size: 11px;
            font-weight: 400;
            color: #64748b;
            margin-top: 3px;
            padding-left: 12px;
            border-left: 2px solid #e2e8f0;
        }
        .ind-sub .ind-price {
            color: #22c55e;
            font-weight: 600;
        }

        /* Sticky actions column pinned to right */
        .act-col-head,
        .act-col {
            position: sticky;
            right: 0;
            z-index: 4;
            background: #f0f2f5;
            border-left: 2px solid #e2e8f0;
            width: 42px;
            padding: 6px 8px !important;
            text-align: center;
            vertical-align: middle;
        }
        .act-col {
            z-index: 2;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 4px;
            align-items: center;
        }
        tr:nth-child(even) .act-col { background: #fbfcfd; }
        tr:hover .act-col { background: #f0f4ff !important; }
        .act-btn {
            width: 30px;
            height: 30px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform .1s, opacity .15s;
            padding: 0;
            line-height: 1;
        }
        .act-btn:hover { transform: scale(1.15); }
        .act-edit { background: #eef2ff; }
        .act-view { background: #f1f5f9; }
        .act-del  { background: #fef2f2; }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="page-header" style="display:flex;justify-content:space-between;align-items:center;">
            <div>
                <h1>Product Management</h1>
                <p>Multi-store inventory overview</p>
            </div>
            <div style="display:flex;gap:8px;">
                <a href="/oro-store-demo/stock/add_stock.php" class="btn btn-success btn-sm">&#128230; Add Stock</a>
                <a href="/oro-store-demo/products/new_product.php" class="btn btn-primary btn-sm">&#10133; New Product</a>
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
            <div class="stat-card">
                <div class="stat-icon blue">&#128181;</div>
                <div class="stat-label">Cost Value</div>
                <div class="stat-value" style="font-size:16px;">&#8369;<?php echo number_format($inv['total_value'], 0); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">&#128176;</div>
                <div class="stat-label">Retail Value</div>
                <div class="stat-value" style="font-size:16px;">&#8369;<?php echo number_format($inv['total_retail'], 0); ?></div>
                <div style="font-size:11px;color:#16a34a;">Margin: &#8369;<?php echo number_format($inv['total_retail'] - $inv['total_value'], 0); ?></div>
            </div>
            <a href="/oro-store-demo/stock/add_stock.php" target="_blank" class="stat-card" style="text-decoration:none;">
                <div class="stat-icon orange">&#128666;</div>
                <div class="stat-label">This Month Supply</div>
                <div class="stat-value" style="font-size:16px;">&#8369;<?php echo number_format($inv['month_supply_cost'], 0); ?></div>
                <div style="font-size:11px;color:#64748b;"><?php echo $inv['month_supply_count']; ?> receipts</div>
            </a>
        </div>

        <!-- Products Table -->
        <div class="content-section">
            <?php
            $user_store_id = $currentUser['store_id'] ?? null;
            $viewing_store = isset($_GET['view_store']) ? intval($_GET['view_store']) : 0;
            ?>
            <?php if (count($all_stores) > 1): ?>
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:12px;flex-wrap:wrap;">
                <span style="font-size:12px;font-weight:600;color:#64748b;">View Stock:</span>
                <a href="?stock=<?php echo $stock_filter; ?>" style="padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;<?php echo !$viewing_store ? 'background:#6366f1;color:#fff;' : 'background:#f1f5f9;color:#475569;'; ?>">All Stores</a>
                <?php foreach ($all_stores as $vs): ?>
                <a href="?stock=<?php echo $stock_filter; ?>&view_store=<?php echo $vs['id']; ?>" style="padding:5px 12px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;<?php echo $viewing_store == $vs['id'] ? 'background:#6366f1;color:#fff;' : 'background:#f1f5f9;color:#475569;'; ?>">
                    <?php echo htmlspecialchars($vs['store_name']); ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="text" id="search-products" placeholder="Search products..." onkeyup="searchAllProducts()" style="padding:9px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;width:280px;">
                    <?php if ($stock_filter !== 'all'): ?>
                        <a href="/oro-store-demo/admin/admin_products.php" class="btn btn-secondary btn-sm">Clear filter</a>
                    <?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <button onclick="viewAllReceipts()" class="btn btn-secondary btn-sm" style="font-size:11px;">&#128203; Receipt History</button>
                <div style="font-size:13px;color:#64748b;">
                    Showing <?php
                    $visible = 0;
                    foreach ($all_products as $p) {
                        $pstock = 0;
                        if ($p['store_data']) { foreach (explode('|', $p['store_data']) as $si) { $sp = explode(':', $si); if (count($sp) === 5) $pstock += intval($sp[1]); } }
                        else { $pstock = (int)$p['stock']; }
                        if ($stock_filter === 'all') { $visible++; continue; }
                        if ($stock_filter === 'out' && $pstock == 0) { $visible++; }
                        elseif ($stock_filter === 'low' && $pstock > 0 && $pstock <= 10) { $visible++; }
                        elseif ($stock_filter === 'in' && $pstock > 10) { $visible++; }
                    }
                    echo $visible;
                    ?> products
                </div>
                </div>
            </div>
            
            <div style="overflow:auto;max-height:65vh;border:1px solid #e2e8f0;border-radius:10px;">
            <table class="products-table" id="all-products-table">
                <thead>
                    <tr>
                        <th rowspan="2" style="vertical-align:middle;">Product Name</th>
                        <th rowspan="2" style="vertical-align:middle;">Sell Price</th>
                        <th rowspan="2" style="vertical-align:middle;">Cost</th>
                        <th rowspan="2" style="vertical-align:middle;">Margin</th>
                        <th rowspan="2" style="vertical-align:middle;min-width:110px;">Last Restock</th>
                        <?php
                        $display_stores = $viewing_store ? array_filter($all_stores, fn($s) => $s['id'] == $viewing_store) : $all_stores;
                        $display_stores = array_values($display_stores);
                        ?>
                        <th colspan="<?php echo count($display_stores); ?>" style="text-align:center;background:#f0f2f5;border-bottom:2px solid #ddd;">Store Stock &amp; Prices</th>
                        <th rowspan="2" class="act-col-head"></th>
                    </tr>
                    <tr>
                        <?php foreach ($display_stores as $store): ?>
                            <th style="background:#f8f9fa;font-size:12px;min-width:160px;">
                                🏪 <?php echo htmlspecialchars($store['store_code']); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_products as $product): ?>
                        <?php
                        $store_stocks = [];
                        $store_prices = [];
                        $store_purchase_prices = [];
                        $store_ids = [];
                        $total_stock = 0;

                        if ($product['store_data']) {
                            $stores_info = explode('|', $product['store_data']);
                            foreach ($stores_info as $info) {
                                $parts = explode(':', $info);
                                if (count($parts) === 5) {
                                    $store_code = $parts[0];
                                    $store_stocks[$store_code] = intval($parts[1]);
                                    $store_prices[$store_code] = floatval($parts[2]);
                                    $store_purchase_prices[$store_code] = floatval($parts[3]);
                                    $store_ids[$store_code] = intval($parts[4]);
                                    $total_stock += intval($parts[1]);
                                }
                            }
                        }

                        // Use store stock total if available, otherwise products.stock
                        $effective_stock = $total_stock > 0 ? $total_stock : (int)$product['stock'];

                        // Apply stock filter
                        $show_row = true;
                        if ($stock_filter === 'out' && $effective_stock != 0) { $show_row = false; }
                        elseif ($stock_filter === 'low' && ($effective_stock <= 0 || $effective_stock > 10)) { $show_row = false; }
                        elseif ($stock_filter === 'in' && $effective_stock <= 10) { $show_row = false; }
                        if (!$show_row) { continue; }

                        $margin_pct = $product['purchase_price'] > 0 ? round(($product['price'] - $product['purchase_price']) / $product['purchase_price'] * 100) : 0;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                <?php if (!empty($product['category_name']) || !empty($product['brand_name'])): ?>
                                <div style="margin-top:3px;display:flex;gap:4px;flex-wrap:wrap;">
                                    <?php if (!empty($product['category_name'])): ?>
                                        <span style="display:inline-block;font-size:10px;font-weight:600;padding:1px 7px;border-radius:3px;background:#ede9fe;color:#7c3aed;letter-spacing:.3px;"><?php echo htmlspecialchars($product['category_name']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($product['brand_name'])): ?>
                                        <span style="display:inline-block;font-size:10px;font-weight:600;padding:1px 7px;border-radius:3px;background:#dbeafe;color:#1d4ed8;letter-spacing:.3px;"><?php echo htmlspecialchars($product['brand_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($product['ind_id']) && empty($product['ind_deleted'])): ?>
                                    <span class="ind-sub">↳ <?php echo htmlspecialchars($product['ind_name']); ?> — <span class="ind-price">₱<?php echo number_format($product['ind_price'], 2); ?></span> · <?php echo $product['ind_stock']; ?> units</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong>₱<?php echo number_format($product['price'], 2); ?></strong>
                                <?php if ($product['discounted_price'] && $product['discounted_price'] < $product['price']): ?>
                                    <br><span style="font-size:11px;color:#16a34a;">₱<?php echo number_format($product['discounted_price'], 2); ?> disc.</span>
                                <?php endif; ?>
                            </td>
                            <td>₱<?php echo number_format($product['purchase_price'], 2); ?></td>
                            <td>
                                <span style="font-weight:700;color:<?php echo $margin_pct >= 20 ? '#16a34a' : ($margin_pct >= 10 ? '#d97706' : '#ef4444'); ?>;">
                                    <?php echo $margin_pct; ?>%
                                </span>
                            </td>
                            <td style="font-size:11px;">
                                <?php if ($product['last_restock_date']): ?>
                                    <div style="font-weight:600;color:#1e293b;"><?php echo date('M j', strtotime($product['last_restock_date'])); ?></div>
                                    <div style="color:#94a3b8;"><?php echo date('g:i A', strtotime($product['last_restock_date'])); ?></div>
                                    <?php if ($product['last_supplier']): ?>
                                        <div style="color:#6366f1;font-weight:600;"><?php echo htmlspecialchars($product['last_supplier']); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">Never</span>
                                <?php endif; ?>
                            </td>

                            <?php foreach ($display_stores as $store): ?>
                                <td style="text-align:center;background:#f8f9fa;">
                                    <?php if (isset($store_stocks[$store['store_code']])): ?>
                                        <div class="store-info-box">
                                            <div class="store-info-row">
                                                <span class="store-info-label">📦 Stock:</span>
                                                <span class="stock-badge <?php
                                                    $stock = $store_stocks[$store['store_code']];
                                                    if ($stock > 50) echo 'stock-high';
                                                    elseif ($stock > 10) echo 'stock-medium';
                                                    else echo 'stock-low';
                                                ?>" style="font-size:11px;padding:2px 6px;">
                                                    <?php echo $stock; ?>
                                                </span>
                                            </div>
                                            <div class="store-info-divider"></div>
                                            <div class="store-info-row">
                                                <span class="store-info-label">💰 Sell:</span>
                                                <span class="store-info-value price-selling">₱<?php echo number_format($store_prices[$store['store_code']], 2); ?></span>
                                            </div>
                                            <div style="text-align:right;font-size:9px;color:#94a3b8;margin-top:-2px;margin-bottom:2px;">₱<?php echo number_format($stock * $store_prices[$store['store_code']], 2); ?></div>
                                            <div class="store-info-row">
                                                <span class="store-info-label">🛒 Buy:</span>
                                                <span class="store-info-value price-purchase">₱<?php echo number_format($store_purchase_prices[$store['store_code']], 2); ?></span>
                                            </div>
                                            <div style="text-align:right;font-size:9px;color:#94a3b8;margin-top:-2px;">₱<?php echo number_format($stock * $store_purchase_prices[$store['store_code']], 2); ?></div>
                                            <div class="store-info-divider"></div>
                                            <?php $can_edit_store = $_is_super || !$_admin_store_id || $_admin_store_id == $store_ids[$store['store_code']]; ?>
                                            <div style="text-align:center;margin-top:6px;">
                                                <?php if ($can_edit_store): ?>
                                                <button onclick='openStoreStockModal(<?php echo json_encode([
                                                    "product_id" => $product['id'],
                                                    "product_name" => $product['name'],
                                                    "store_id" => $store_ids[$store['store_code']],
                                                    "store_code" => $store['store_code'],
                                                    "stock" => $stock,
                                                    "price" => $store_prices[$store['store_code']],
                                                    "purchase_price" => $store_purchase_prices[$store['store_code']]
                                                ]); ?>)' class="btn btn-warning btn-sm" style="font-size:10px;padding:4px 8px;width:100%;">
                                                    ✏️ Edit
                                                </button>
                                                <?php else: ?>
                                                <span style="font-size:9px;color:#94a3b8;">View Only</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <?php $can_add_store = $_is_super || !$_admin_store_id || $_admin_store_id == $store['id']; ?>
                                        <?php if ($can_add_store): ?>
                                        <span onclick='openAddToStoreModal(<?php echo json_encode([
                                            "product_id" => $product['id'],
                                            "product_name" => $product['name'],
                                            "store_id" => $store['id'],
                                            "store_code" => $store['store_code'],
                                            "price" => $product['price'],
                                            "purchase_price" => $product['purchase_price'],
                                            "ind_id" => $product['ind_id'] ? (int)$product['ind_id'] : null,
                                            "ind_name" => $product['ind_name'] ?? null,
                                            "ind_price" => $product['ind_price'] ? (float)$product['ind_price'] : null
                                        ]); ?>)' style="color:#667eea;font-size:11px;cursor:pointer;text-decoration:underline;" title="Click to add to this store">+ Add to store</span>
                                        <?php else: ?>
                                        <span style="color:#cbd5e1;font-size:11px;">—</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>

                            <td class="act-col">
                                <?php if ($_on_own_device || $_is_super): ?>
                                <button onclick='openGlobalPriceModal(<?php echo json_encode($product); ?>)' class="act-btn act-edit" title="Edit">✏️</button>
                                <?php endif; ?>
                                <button onclick='viewStockHistory(<?php echo $product["id"]; ?>, <?php echo json_encode($product["name"]); ?>)' class="act-btn act-view" title="Stock Receipt History">📦</button>
                                <?php if (!empty($product['barcode'])): ?>
                                <button onclick='viewBarcode(<?php echo json_encode(["barcode" => $product["barcode"], "name" => $product["name"], "price" => $product["price"]]); ?>)' class="act-btn act-view" title="View Barcode">👁️</button>
                                <?php endif; ?>
                                <?php if ($_on_own_device || $_is_super): ?>
                                <button onclick='confirmDeleteProduct(<?php echo json_encode(["id" => $product["id"], "name" => $product["name"]]); ?>)' class="act-btn act-del" title="Delete">🗑️</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Edit Product Modal -->
        <div class="modal" id="global-price-modal">
            <div class="modal-content" style="max-width:520px;max-height:85vh;overflow-y:auto;padding:0;">
                <div style="padding:16px 20px;border-bottom:2px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:5;">
                    <h2 style="font-size:16px;margin:0;color:#1e293b;">Edit Product</h2>
                    <button class="btn-close-modal" onclick="closeGlobalPriceModal()">&times;</button>
                </div>
                <form method="POST" id="editProductForm" style="padding:16px 20px;">
                    <input type="hidden" name="action" value="update_global_price">
                    <input type="hidden" name="product_id" id="global-product-id">

                    <!-- Basic Info -->
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Basic Info</div>
                    <div style="display:grid;grid-template-columns:1fr;gap:8px;margin-bottom:16px;">
                        <div>
                            <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Name</label>
                            <input type="text" name="name" id="global-product-name" required style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;">
                        </div>
                        <div style="position:relative;">
                            <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Category</label>
                            <input type="text" id="editCatSearch" placeholder="Search category..." autocomplete="off" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;" oninput="editOnCatType(this.value)" onfocus="editOpenCatDD()" onblur="setTimeout(editCloseCatDD,180)">
                            <input type="hidden" name="category_id" id="editCategoryId">
                            <div id="editCatDD" style="position:absolute;top:100%;left:0;right:0;z-index:999;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 6px 6px;max-height:140px;overflow-y:auto;box-shadow:0 4px 10px rgba(0,0,0,.12);display:none;"></div>
                            <div id="editCatBadge" style="display:none;margin-top:3px;">
                                <span style="display:inline-block;background:#7c3aed;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">
                                    <span id="editCatName"></span>
                                    <span style="margin-left:4px;cursor:pointer;opacity:.7;" onclick="editClearCat()">✕</span>
                                </span>
                            </div>
                        </div>
                        <div style="position:relative;">
                            <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Brand</label>
                            <input type="text" id="editBrandSearch" placeholder="Search brand..." autocomplete="off" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;" oninput="editOnBrandType(this.value)" onfocus="editOpenBrandDD()" onblur="setTimeout(editCloseBrandDD,180)">
                            <input type="hidden" name="brand_id" id="editBrandId">
                            <div id="editBrandDD" style="position:absolute;top:100%;left:0;right:0;z-index:999;background:#fff;border:1px solid #ddd;border-top:none;border-radius:0 0 6px 6px;max-height:140px;overflow-y:auto;box-shadow:0 4px 10px rgba(0,0,0,.12);display:none;"></div>
                            <div id="editBrandBadge" style="display:none;margin-top:3px;">
                                <span style="display:inline-block;background:#2563eb;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;font-weight:600;">
                                    <span id="editBrandName"></span>
                                    <span style="margin-left:4px;cursor:pointer;opacity:.7;" onclick="editClearBrand()">&#x2715;</span>
                                </span>
                            </div>
                        </div>
                        <div>
                            <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Barcode</label>
                            <input type="text" name="barcode" id="global-barcode" placeholder="Optional" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Description</label>
                            <input type="text" name="description" id="global-description" placeholder="Optional" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;">
                        </div>
                    </div>

                    <!-- Pricing -->
                    <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Pricing</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;">
                        <div>
                            <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Sell Price</label>
                            <input type="number" step="0.01" name="price" id="global-price-new" required style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Cost Price</label>
                            <input type="number" step="0.01" name="purchase_price" id="global-purchase-price-new" required style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Discounted Price</label>
                            <input type="number" step="0.01" name="discounted_price" id="global-discounted-price" style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;">
                        </div>
                        <div>
                            <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Stock <span style="font-size:9px;color:#94a3b8;">(use Add Stock or Store Edit)</span></label>
                            <input type="number" name="stock" id="global-stock" required readonly style="width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;background:#f8fafc;color:#64748b;">
                        </div>
                    </div>

                    <!-- Individual Selling -->
                    <div style="margin-bottom:16px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;font-weight:600;color:#1e293b;">
                            <input type="checkbox" id="edit_can_sell_individually" name="can_sell_individually" style="width:16px;height:16px;">
                            Can be sold individually
                        </label>
                    </div>
                    <div id="edit-individual-selling-options" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:16px;">
                        <div style="font-size:11px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Individual Unit Settings</div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div>
                                <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Sell Unit</label>
                                <div style="display:flex;gap:4px;">
                                    <select id="edit_individual_sell_unit" name="individual_sell_unit" style="flex:1;padding:7px 8px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px;">
                                        <option value="">Select</option>
                                        <option value="kilo">Kilo</option>
                                        <option value="piece">Piece</option>
                                        <option value="bottle">Bottle</option>
                                        <option value="liter">Liter</option>
                                        <option value="gram">Gram</option>
                                    </select>
                                    <button type="button" style="padding:4px 8px;border:1.5px solid #e2e8f0;border-radius:6px;background:#fff;cursor:pointer;font-size:12px;" id="edit-add-category-btn">+</button>
                                </div>
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Units per Pack</label>
                                <input type="number" id="edit_individual_pieces_per_pack" name="individual_pieces_per_pack" min="1" style="width:100%;padding:7px 8px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px;">
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Unit Price</label>
                                <input type="number" step="0.01" id="edit_individual_selling_price" name="individual_selling_price" placeholder="Auto" style="width:100%;padding:7px 8px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px;">
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Unit Disc. Price</label>
                                <input type="number" step="0.01" id="edit_individual_discounted_price" name="individual_discounted_price" placeholder="Auto" style="width:100%;padding:7px 8px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px;">
                            </div>
                        </div>
                    </div>

                    <!-- Child Product Stock -->
                    <div id="edit-child-section" style="display:none;background:#eef2ff;border:1px solid #c7d2fe;border-radius:8px;padding:12px;margin-bottom:16px;">
                        <div style="font-size:11px;font-weight:700;color:#4338ca;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Child Product (Individual Unit)</div>
                        <div style="font-size:13px;font-weight:600;color:#1e293b;margin-bottom:6px;" id="edit-child-name"></div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                            <div>
                                <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Current Stock</label>
                                <input type="number" id="edit-child-stock" name="child_stock" style="width:100%;padding:7px 8px;border:1.5px solid #c7d2fe;border-radius:6px;font-size:13px;font-weight:700;background:#fff;">
                                <input type="hidden" id="edit-child-id" name="child_product_id">
                            </div>
                            <div>
                                <label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">Max Units</label>
                                <div id="edit-child-max" style="padding:7px 8px;font-size:13px;font-weight:700;color:#6366f1;"></div>
                            </div>
                        </div>
                    </div>

                    <div style="padding:8px 10px;background:#f0fdf4;border-radius:6px;margin-bottom:14px;font-size:11px;color:#166534;">
                        Updates default product info. Store-specific prices stay unchanged.
                    </div>

                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn btn-success" style="flex:1;padding:10px;font-size:13px;">Save Changes</button>
                        <button type="button" class="btn btn-warning" onclick="closeGlobalPriceModal()" style="padding:10px;font-size:13px;">Cancel</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Store Stock Edit Modal -->
        <div class="modal" id="store-stock-modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Edit Store Stock & Price</h2>
                    <button class="btn-close-modal" onclick="closeStoreStockModal()">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="product_id" id="store-stock-product-id">
                    <input type="hidden" name="store_id" id="store-stock-store-id">
                    
                    <div class="form-group">
                        <label>Product</label>
                        <input type="text" id="store-stock-product-name" disabled style="background: #f0f2f5;">
                    </div>
                    
                    <div class="form-group">
                        <label>Store</label>
                        <input type="text" id="store-stock-store-code" disabled style="background: #f0f2f5;">
                    </div>
                    
                    <hr style="margin: 20px 0;">
                    
                    <h3 style="margin-bottom: 15px;">Update Price</h3>
                    <div class="form-group">
                        <label>Selling Price (₱)</label>
                        <input type="number" name="price" id="store-stock-price" step="0.01" min="0">
                    </div>
                    
                    <div class="form-group">
                        <label>Purchase Price (₱)</label>
                        <input type="number" name="purchase_price" id="store-stock-purchase-price" step="0.01" min="0">
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

                    <div class="form-group" id="sa-password-group" style="display:none;">
                        <label style="color:#dc2626;">Super Admin Password *</label>
                        <input type="password" name="sa_password" id="sa-password-input" placeholder="Required for stock removal">
                        <small style="color:#94a3b8;">Removing stock requires super admin authorization</small>
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

        <!-- Barcode Modal -->
        <div class="modal" id="barcode-modal">
            <div class="modal-content" style="max-width:400px;text-align:center;">
                <div class="modal-header">
                    <h2>Barcode</h2>
                    <button class="btn-close-modal" onclick="closeBarcodeModal()">&times;</button>
                </div>
                <div style="font-weight:700;font-size:15px;margin-bottom:4px;" id="barcode-modal-name"></div>
                <div style="color:#64748b;font-size:13px;margin-bottom:16px;" id="barcode-modal-price"></div>
                <div style="background:#f8f9fa;border-radius:8px;padding:16px;margin-bottom:16px;">
                    <svg id="barcode-modal-svg"></svg>
                </div>
                <button class="btn btn-primary" onclick="printBarcodeFromModal()">🖨️ Print Barcode</button>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div class="modal" id="delete-product-modal">
            <div class="modal-content" style="max-width: 500px;">
                <div class="modal-header">
                    <h2>⚠️ Confirm Delete Product</h2>
                    <button class="btn-close-modal" onclick="closeDeleteModal()">&times;</button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="delete_product">
                    <input type="hidden" name="product_id" id="delete-product-id">
                    
                    <div style="padding: 20px; background: #fff3cd; border-radius: 8px; margin-bottom: 20px;">
                        <p style="margin: 0; color: #856404; font-size: 14px;">
                            <strong>⚠️ Warning:</strong> You are about to delete the following product:
                        </p>
                    </div>
                    
                    <div class="form-group">
                        <label>Product Name</label>
                        <input type="text" id="delete-product-name" disabled style="background: #f0f2f5; font-weight: bold; font-size: 16px;">
                    </div>
                    
                    <div style="padding: 15px; background: #f8d7da; border-radius: 8px; margin: 15px 0; border-left: 4px solid #dc3545;">
                        <p style="margin: 0; color: #721c24; font-size: 13px;">
                            <strong>This action will:</strong><br>
                            • Delete the product from all stores<br>
                            • Remove all stock and pricing information<br>
                            • This action cannot be undone
                        </p>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="submit" class="btn btn-danger" style="flex: 1;">
                            🗑️ Yes, Delete Product
                        </button>
                        <button type="button" class="btn btn-warning" onclick="closeDeleteModal()" style="flex: 1;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <!-- Stock Receipt History Modal -->
    <div class="modal" id="stock-history-modal">
        <div class="modal-content" style="max-width:600px;max-height:80vh;overflow-y:auto;padding:0;">
            <div style="padding:14px 18px;border-bottom:2px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;z-index:5;">
                <h2 style="font-size:15px;margin:0;" id="sh-title">Stock Receipt History</h2>
                <button class="btn-close-modal" onclick="document.getElementById('stock-history-modal').classList.remove('active')">&times;</button>
            </div>
            <div id="sh-body" style="padding:14px 18px;">Loading...</div>
        </div>
    </div>

    <!-- Add to Store Modal -->
    <div class="modal" id="ats-modal">
        <div class="modal-content" style="max-width:420px;">
            <div class="modal-header">
                <h2 id="ats-title">Add to Store</h2>
                <button class="btn-close-modal" onclick="closeAtsModal()">&times;</button>
            </div>
            <div class="form-group">
                <label>Stock Quantity *</label>
                <input type="number" id="ats-stock" min="0" placeholder="Enter stock to add" style="font-size:16px;padding:10px;">
            </div>
            <div class="form-group">
                <label>Selling Price (₱)</label>
                <input type="number" step="0.01" id="ats-price" min="0">
            </div>
            <div class="form-group">
                <label>Purchase Price (₱)</label>
                <input type="number" step="0.01" id="ats-cost" min="0">
            </div>
            <div id="ats-ind-section" style="display:none;margin-top:12px;padding:12px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;">
                <div style="font-size:12px;font-weight:700;color:#92400e;margin-bottom:8px;">Individual Product (auto-added)</div>
                <div style="font-size:13px;color:#1e293b;margin-bottom:8px;" id="ats-ind-name"></div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Individual Selling Price (₱)</label>
                    <input type="number" step="0.01" id="ats-ind-price" min="0">
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:16px;">
                <button onclick="submitAts()" class="btn btn-success" style="flex:1;">Add to Store</button>
                <button onclick="closeAtsModal()" class="btn btn-warning">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        function esc2(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

        function viewAllReceipts() {
            document.getElementById('sh-title').textContent = 'Stock Receipt History';
            document.getElementById('sh-body').innerHTML = 'Loading...';
            document.getElementById('stock-history-modal').classList.add('active');

            fetch('/oro-store-demo/stock/add_stock.php?action=get_receipts')
            .then(r => r.json()).then(receipts => {
                if (!receipts.length) {
                    document.getElementById('sh-body').innerHTML = '<p style="text-align:center;color:#94a3b8;padding:20px;">No receipts yet</p>';
                    return;
                }
                document.getElementById('sh-body').innerHTML = '<div id="receipt-list-container"></div>';
                const list = document.getElementById('receipt-list-container');
                list.innerHTML = receipts.map(r => {
                    const d = new Date(r.created_at);
                    const dateStr = d.toLocaleDateString('en-PH', {month:'short',day:'numeric',year:'numeric'});
                    return `<div onclick="viewReceiptDetail(${r.id})" style="padding:10px 12px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:6px;cursor:pointer;transition:all .15s;" onmouseover="this.style.background='#f8fafc';this.style.borderColor='#6366f1'" onmouseout="this.style.background='';this.style.borderColor='#e2e8f0'">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span><span style="font-size:11px;color:#6366f1;font-weight:700;margin-right:6px;">#${r.id}</span><span style="font-weight:700;font-size:13px;color:#1e293b;">${r.supplier_name || 'No supplier'}</span></span>
                            <span style="font-size:11px;color:#94a3b8;">${dateStr}</span>
                        </div>
                        <div style="display:flex;gap:12px;margin-top:4px;font-size:11px;color:#64748b;">
                            <span>${r.item_count} products, ${r.total_items} units</span>
                            <span style="color:#dc2626;font-weight:600;">Cost: ₱${parseFloat(r.total_cost).toFixed(2)}</span>
                            ${r.invoice_number ? `<span style="color:#6366f1;font-weight:600;">#${esc2(r.invoice_number)}</span>` : ''}
                        </div>
                        ${r.notes ? `<div style="font-size:11px;color:#94a3b8;margin-top:3px;">${esc2(r.notes)}</div>` : ''}
                    </div>`;
                }).join('');
            });
        }

        function viewReceiptDetail(id) {
            document.getElementById('sh-title').textContent = 'Receipt #' + id;
            document.getElementById('sh-body').innerHTML = 'Loading...';

            fetch('/oro-store-demo/stock/add_stock.php?action=get_receipt_items&receipt_id=' + id)
            .then(r => r.json()).then(items => {
                let totalCost = 0, totalSell = 0;
                let rows = items.map(it => {
                    const cost = it.quantity * it.purchase_price;
                    const sell = it.quantity * it.selling_price;
                    totalCost += cost; totalSell += sell;
                    return `<tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:6px 8px;font-weight:600;font-size:12px;">${esc2(it.product_name)}</td>
                        <td style="padding:6px 8px;text-align:center;">${it.quantity}</td>
                        <td style="padding:6px 8px;text-align:right;">₱${parseFloat(it.purchase_price).toFixed(2)}</td>
                        <td style="padding:6px 8px;text-align:right;">₱${parseFloat(it.selling_price).toFixed(2)}</td>
                        <td style="padding:6px 8px;text-align:right;font-weight:600;color:#dc2626;">₱${cost.toFixed(2)}</td>
                        <td style="padding:6px 8px;text-align:center;font-size:11px;color:#64748b;">${it.old_stock} → ${it.new_stock}</td>
                    </tr>`;
                }).join('');
                const margin = totalSell - totalCost;
                document.getElementById('sh-body').innerHTML = `
                    <button onclick="viewAllReceipts()" style="margin-bottom:10px;padding:5px 12px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;font-size:11px;cursor:pointer;font-weight:600;">← Back</button>
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <thead><tr style="background:#f1f5f9;">
                            <th style="padding:6px 8px;text-align:left;">Product</th>
                            <th style="padding:6px 8px;text-align:center;">Qty</th>
                            <th style="padding:6px 8px;text-align:right;">Cost</th>
                            <th style="padding:6px 8px;text-align:right;">Sell</th>
                            <th style="padding:6px 8px;text-align:right;">Total Cost</th>
                            <th style="padding:6px 8px;text-align:center;">Stock</th>
                        </tr></thead>
                        <tbody>${rows}</tbody>
                        <tfoot>
                            <tr style="font-weight:700;border-top:2px solid #e2e8f0;">
                                <td colspan="4" style="padding:6px 8px;text-align:right;">Totals:</td>
                                <td style="padding:6px 8px;text-align:right;color:#dc2626;">₱${totalCost.toFixed(2)}</td>
                                <td></td>
                            </tr>
                            <tr style="font-weight:600;">
                                <td colspan="4" style="padding:6px 8px;text-align:right;color:#2563eb;">Selling Value:</td>
                                <td style="padding:6px 8px;text-align:right;color:#2563eb;">₱${totalSell.toFixed(2)}</td>
                                <td></td>
                            </tr>
                            <tr style="font-weight:600;">
                                <td colspan="4" style="padding:6px 8px;text-align:right;color:#16a34a;">Margin:</td>
                                <td style="padding:6px 8px;text-align:right;color:#16a34a;">₱${margin.toFixed(2)}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>`;
            });
        }

        function viewStockHistory(productId, productName) {
            document.getElementById('sh-title').textContent = 'Stock History — ' + productName;
            document.getElementById('sh-body').innerHTML = 'Loading...';
            document.getElementById('stock-history-modal').classList.add('active');

            fetch('?action=get_stock_history&product_id=' + productId)
            .then(r => r.json()).then(data => {
                if (!data.length) {
                    document.getElementById('sh-body').innerHTML = '<p style="text-align:center;color:#94a3b8;padding:20px;">No stock receipts found for this product.</p>';
                    return;
                }
                let totalQty = 0, totalCost = 0;
                let rows = data.map(r => {
                    const cost = r.quantity * r.purchase_price;
                    totalQty += parseInt(r.quantity);
                    totalCost += cost;
                    const date = new Date(r.created_at);
                    return `<tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:6px 8px;font-size:11px;color:#6366f1;font-weight:600;">#${r.receipt_id}</td>
                        <td style="padding:6px 8px;font-size:12px;color:#64748b;">${date.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})}<br><span style="font-size:10px;">${date.toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit',hour12:true})}</span></td>
                        <td style="padding:6px 8px;font-size:12px;font-weight:600;">${r.supplier_name || '—'}</td>
                        <td style="padding:6px 8px;text-align:center;font-weight:700;color:#3b82f6;">+${r.quantity}</td>
                        <td style="padding:6px 8px;text-align:right;font-size:12px;">₱${parseFloat(r.purchase_price).toFixed(2)}</td>
                        <td style="padding:6px 8px;text-align:right;font-weight:600;color:#dc2626;">₱${cost.toFixed(2)}</td>
                        <td style="padding:6px 8px;text-align:center;font-size:11px;color:#64748b;">${r.old_stock} → ${r.new_stock}</td>
                    </tr>`;
                }).join('');

                document.getElementById('sh-body').innerHTML = `
                    <table style="width:100%;border-collapse:collapse;">
                        <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                            <th style="padding:6px 8px;text-align:left;font-size:10px;color:#64748b;">Receipt</th>
                            <th style="padding:6px 8px;text-align:left;font-size:10px;color:#64748b;">Date</th>
                            <th style="padding:6px 8px;text-align:left;font-size:10px;color:#64748b;">Supplier</th>
                            <th style="padding:6px 8px;text-align:center;font-size:10px;color:#64748b;">Qty</th>
                            <th style="padding:6px 8px;text-align:right;font-size:10px;color:#64748b;">Cost/Unit</th>
                            <th style="padding:6px 8px;text-align:right;font-size:10px;color:#64748b;">Total Cost</th>
                            <th style="padding:6px 8px;text-align:center;font-size:10px;color:#64748b;">Stock</th>
                        </tr></thead>
                        <tbody>${rows}</tbody>
                        <tfoot><tr style="border-top:2px solid #1e293b;background:#f8fafc;">
                            <td colspan="3" style="padding:8px;font-weight:800;font-size:13px;">Total</td>
                            <td style="padding:8px;text-align:center;font-weight:800;color:#3b82f6;">${totalQty}</td>
                            <td></td>
                            <td style="padding:8px;text-align:right;font-weight:800;color:#dc2626;">₱${totalCost.toFixed(2)}</td>
                            <td></td>
                        </tr></tfoot>
                    </table>`;
            }).catch(() => {
                document.getElementById('sh-body').innerHTML = '<p style="color:#dc2626;text-align:center;padding:20px;">Error loading history</p>';
            });
        }

        function openGlobalPriceModal(product) {
            document.getElementById('global-product-id').value = product.id;
            document.getElementById('global-product-name').value = product.name;
            document.getElementById('global-price-new').value = product.price;
            document.getElementById('global-discounted-price').value = product.discounted_price || product.price;
            document.getElementById('global-purchase-price-new').value = product.purchase_price;
            document.getElementById('global-stock').value = product.stock;
            document.getElementById('global-description').value = product.description || '';
            document.getElementById('global-barcode').value = product.barcode || '';

            if (product.category_id && product.category_name) {
                editSelectCat(product.category_id, product.category_name);
            } else {
                editClearCat();
            }

            if (product.brand_id && product.brand_name) {
                editSelectBrand(product.brand_id, product.brand_name);
            } else {
                editClearBrand();
            }

            const canSellIndividually = product.can_sell_individually == 1;
            document.getElementById('edit_can_sell_individually').checked = canSellIndividually;
            document.getElementById('edit-individual-selling-options').style.display = canSellIndividually ? 'block' : 'none';

            if (canSellIndividually) {
                document.getElementById('edit_individual_sell_unit').value = product.individual_sell_unit || '';
                document.getElementById('edit_individual_pieces_per_pack').value = product.individual_pieces_per_pack || '';
                document.getElementById('edit_individual_selling_price').value = product.individual_selling_price || '';
                document.getElementById('edit_individual_discounted_price').value = product.individual_discounted_price || '';
            }

            // Populate child product section
            const childSection = document.getElementById('edit-child-section');
            if (product.ind_id && !product.ind_deleted) {
                childSection.style.display = 'block';
                document.getElementById('edit-child-name').textContent = product.ind_name;
                document.getElementById('edit-child-id').value = product.ind_id;
                document.getElementById('edit-child-stock').value = product.ind_stock;
                document.getElementById('edit-child-max').textContent = (product.individual_pieces_per_pack || '—') + ' units/pack';
            } else {
                childSection.style.display = 'none';
                document.getElementById('edit-child-id').value = '';
            }

            document.getElementById('global-price-modal').classList.add('active');
        }

        function closeGlobalPriceModal() {
            document.getElementById('global-price-modal').classList.remove('active');
        }

        // Auto-fill discounted price from selling price in edit modal
        document.getElementById('global-price-new').addEventListener('input', function() {
            const price = parseFloat(this.value);
            const discountedPriceInput = document.getElementById('global-discounted-price');
            
            if (price && price > 0 && !discountedPriceInput.dataset.userChanged) {
                discountedPriceInput.value = price.toFixed(2);
                discountedPriceInput.placeholder = '₱' + price.toFixed(2);
            }
            
            recalcEditIndPrice();
        });

        // Allow manual override of discounted price in edit modal
        document.getElementById('global-discounted-price').addEventListener('input', function() {
            if (this.value) {
                this.dataset.userChanged = 'true';
            } else {
                delete this.dataset.userChanged;
                const packPrice = parseFloat(document.getElementById('global-price-new').value);
                if (packPrice > 0) {
                    this.value = packPrice.toFixed(2);
                }
            }
        });

        // Toggle individual selling options in edit modal
        document.getElementById('edit_can_sell_individually').addEventListener('change', function(e) {
            const show = e.target.checked;
            const div = document.getElementById('edit-individual-selling-options');
            div.style.display = show ? 'block' : 'none';
            
            const unitSelect = document.getElementById('edit_individual_sell_unit');
            const piecesInput = document.getElementById('edit_individual_pieces_per_pack');
            
            if (show) {
                unitSelect.setAttribute('required', 'required');
                piecesInput.setAttribute('required', 'required');
            } else {
                unitSelect.removeAttribute('required');
                piecesInput.removeAttribute('required');
            }
        });

        // Auto-calculate individual selling price in edit modal
        function recalcEditIndPrice() {
            const packPrice = parseFloat(document.getElementById('global-price-new').value);
            const packDiscountedPrice = parseFloat(document.getElementById('global-discounted-price').value || packPrice);
            const pieces = parseInt(document.getElementById('edit_individual_pieces_per_pack').value);
            const indPriceInput = document.getElementById('edit_individual_selling_price');
            const indDiscountedPriceInput = document.getElementById('edit_individual_discounted_price');
            
            if (packPrice > 0 && pieces > 0) {
                const calculated = packPrice / pieces;
                const calculatedDiscounted = packDiscountedPrice / pieces;
                
                if (!indPriceInput.dataset.userChanged) {
                    indPriceInput.value = calculated.toFixed(2);
                    indPriceInput.placeholder = '₱' + calculated.toFixed(2);
                }
                
                // Auto-calculate individual discounted price
                const indPrice = parseFloat(indPriceInput.value || calculated);
                if (!indDiscountedPriceInput.dataset.userChanged && indPrice > 0) {
                    indDiscountedPriceInput.value = calculatedDiscounted.toFixed(2);
                    indDiscountedPriceInput.placeholder = '₱' + calculatedDiscounted.toFixed(2);
                }
            }
        }
        
        document.getElementById('edit_individual_pieces_per_pack').addEventListener('input', recalcEditIndPrice);
        document.getElementById('global-discounted-price').addEventListener('input', recalcEditIndPrice);

        // Allow manual override of individual selling price
        document.getElementById('edit_individual_selling_price').addEventListener('input', function(e) {
            if (e.target.value) {
                e.target.dataset.userChanged = 'true';
                
                // Update individual discounted price when individual price changes
                const indPrice = parseFloat(e.target.value);
                const indDiscountedPriceInput = document.getElementById('edit_individual_discounted_price');
                if (!indDiscountedPriceInput.dataset.userChanged && indPrice > 0) {
                    indDiscountedPriceInput.value = indPrice.toFixed(2);
                }
            } else {
                delete e.target.dataset.userChanged;
                recalcEditIndPrice();
            }
        });

        // Allow manual override of individual discounted price
        document.getElementById('edit_individual_discounted_price').addEventListener('input', function(e) {
            if (e.target.value) {
                e.target.dataset.userChanged = 'true';
            } else {
                delete e.target.dataset.userChanged;
                const indPrice = parseFloat(document.getElementById('edit_individual_selling_price').value);
                if (indPrice > 0) {
                    this.value = indPrice.toFixed(2);
                }
            }
        });

        // Add custom category in edit modal
        document.getElementById('edit-add-category-btn').addEventListener('click', function() {
            const newCat = prompt('Enter new unit type:');
            if (newCat && newCat.trim()) {
                const select = document.getElementById('edit_individual_sell_unit');
                const option = document.createElement('option');
                option.value = newCat.trim().toLowerCase();
                option.textContent = newCat.trim().charAt(0).toUpperCase() + newCat.trim().slice(1);
                select.appendChild(option);
                select.value = option.value;
            }
        });

        // Barcode helpers (elements may not exist in simplified modal)
        const editGenBtn = document.getElementById('edit-generate-btn');
        const editPrintBtn = document.getElementById('edit-print-btn');
        if (editGenBtn) {
            editGenBtn.addEventListener('click', function() {
                const barcodeValue = document.getElementById('global-barcode').value;
                if (!barcodeValue) { alert('Please enter a barcode first'); return; }
                try {
                    JsBarcode("#edit-barcode-svg", barcodeValue, { format: "CODE128", width: 2, height: 50, displayValue: true, fontSize: 14, margin: 5 });
                    document.getElementById('edit-barcode-display').style.display = 'block';
                } catch (e) { alert('Invalid barcode: ' + e.message); }
            });
        }
        if (editPrintBtn) {
            editPrintBtn.addEventListener('click', function() {
                const barcodeValue = document.getElementById('global-barcode').value;
                if (!barcodeValue) { alert('Please generate a barcode first'); return; }
                const params = new URLSearchParams({ barcode: barcodeValue, name: document.getElementById('global-product-name').value || 'Product', price: document.getElementById('global-price-new').value || '0', stock: document.getElementById('global-stock').value || '0' });
                window.open('/oro-store-demo/print/print_barcode_label.php?' + params.toString(), '_blank', 'width=500,height=600');
            });
        }

        function openStoreStockModal(data) {
            document.getElementById('store-stock-product-id').value = data.product_id;
            document.getElementById('store-stock-store-id').value = data.store_id;
            document.getElementById('store-stock-product-name').value = data.product_name;
            document.getElementById('store-stock-store-code').value = data.store_code;
            document.getElementById('store-stock-current').value = data.stock + ' units';
            document.getElementById('store-stock-price').value = data.price;
            document.getElementById('store-stock-purchase-price').value = data.purchase_price;
            document.getElementById('store-stock-change').value = '';
            document.getElementById('store-stock-change-type').value = 'add';
            document.getElementById('sa-password-group').style.display = 'none';
            document.getElementById('sa-password-input').value = '';
            document.getElementById('store-stock-modal').classList.add('active');
        }

        function closeStoreStockModal() {
            document.getElementById('store-stock-modal').classList.remove('active');
        }

        // Show/hide password field based on change type
        document.getElementById('store-stock-change-type').addEventListener('change', function() {
            document.getElementById('sa-password-group').style.display = this.value === 'remove' ? '' : 'none';
            document.getElementById('sa-password-input').value = '';
        });

        function confirmDeleteProduct(product) {
            document.getElementById('delete-product-id').value = product.id;
            document.getElementById('delete-product-name').value = product.name;
            document.getElementById('delete-product-modal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('delete-product-modal').classList.remove('active');
        }

        // ── Category autocomplete for edit modal ──
        let editCategories = <?php echo json_encode($allCategories); ?>;
        let editSelectedCatId = null;

        function editRenderCatOpts(list) {
            const dd = document.getElementById('editCatDD');
            if (!list.length) {
                dd.innerHTML = '<div style="padding:9px 12px;color:#999;font-style:italic;font-size:13px;">No categories found</div>';
            } else {
                dd.innerHTML = list.map(c =>
                    `<div style="padding:9px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f0;" onmousedown="editSelectCat(${c.id},'${c.category_name.replace(/'/g,"\\'")}')"
                         onmouseover="this.style.background='#e8f4ff'" onmouseout="this.style.background=''">${c.category_name}</div>`
                ).join('');
            }
        }
        function editOpenCatDD() {
            const q = document.getElementById('editCatSearch').value.toLowerCase();
            editRenderCatOpts(q ? editCategories.filter(c => c.category_name.toLowerCase().includes(q)) : editCategories);
            document.getElementById('editCatDD').style.display = 'block';
        }
        function editCloseCatDD() { document.getElementById('editCatDD').style.display = 'none'; }
        function editOnCatType(val) {
            editRenderCatOpts(val ? editCategories.filter(c => c.category_name.toLowerCase().includes(val.toLowerCase())) : editCategories);
            document.getElementById('editCatDD').style.display = 'block';
            editSelectedCatId = null;
            document.getElementById('editCategoryId').value = '';
            document.getElementById('editCatBadge').style.display = 'none';
        }
        function editSelectCat(id, name) {
            editSelectedCatId = id;
            document.getElementById('editCategoryId').value = id;
            document.getElementById('editCatSearch').value = '';
            document.getElementById('editCatSearch').placeholder = name;
            document.getElementById('editCatName').textContent = name;
            document.getElementById('editCatBadge').style.display = 'block';
            editCloseCatDD();
        }
        function editClearCat() {
            editSelectedCatId = null;
            document.getElementById('editCategoryId').value = '';
            document.getElementById('editCatSearch').value = '';
            document.getElementById('editCatSearch').placeholder = 'Type to search category…';
            document.getElementById('editCatBadge').style.display = 'none';
        }

        // ── Brand autocomplete for edit modal ──
        let editBrands = <?php echo json_encode($allBrands); ?>;
        let editSelectedBrandId = null;

        function editRenderBrandOpts(list) {
            const dd = document.getElementById('editBrandDD');
            if (!list.length) {
                dd.innerHTML = '<div style="padding:9px 12px;color:#999;font-style:italic;font-size:13px;">No brands found</div>';
            } else {
                dd.innerHTML = list.map(b =>
                    `<div style="padding:9px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f0f0f0;" onmousedown="editSelectBrand(${b.id},'${b.brand_name.replace(/'/g,"\\'")}')"
                         onmouseover="this.style.background='#e8f4ff'" onmouseout="this.style.background=''">${b.brand_name}</div>`
                ).join('');
            }
        }
        function editOpenBrandDD() {
            const q = document.getElementById('editBrandSearch').value.toLowerCase();
            editRenderBrandOpts(q ? editBrands.filter(b => b.brand_name.toLowerCase().includes(q)) : editBrands);
            document.getElementById('editBrandDD').style.display = 'block';
        }
        function editCloseBrandDD() { document.getElementById('editBrandDD').style.display = 'none'; }
        function editOnBrandType(val) {
            editRenderBrandOpts(val ? editBrands.filter(b => b.brand_name.toLowerCase().includes(val.toLowerCase())) : editBrands);
            document.getElementById('editBrandDD').style.display = 'block';
            editSelectedBrandId = null;
            document.getElementById('editBrandId').value = '';
            document.getElementById('editBrandBadge').style.display = 'none';
        }
        function editSelectBrand(id, name) {
            editSelectedBrandId = id;
            document.getElementById('editBrandId').value = id;
            document.getElementById('editBrandSearch').value = '';
            document.getElementById('editBrandSearch').placeholder = name;
            document.getElementById('editBrandName').textContent = name;
            document.getElementById('editBrandBadge').style.display = 'block';
            editCloseBrandDD();
        }
        function editClearBrand() {
            editSelectedBrandId = null;
            document.getElementById('editBrandId').value = '';
            document.getElementById('editBrandSearch').value = '';
            document.getElementById('editBrandSearch').placeholder = 'Search brand...';
            document.getElementById('editBrandBadge').style.display = 'none';
        }

        function searchAllProducts() {
            const input = document.getElementById('search-products');
            const filter = input.value.toUpperCase();
            const table = document.getElementById('all-products-table');
            const tr = table.getElementsByTagName('tr');

            for (let i = 2; i < tr.length; i++) {
                const td = tr[i].getElementsByTagName('td');
                let found = false;

                if (td.length > 0) {
                    const txtValue = td[0].textContent || td[0].innerText;
                    if (txtValue.toUpperCase().indexOf(filter) > -1) {
                        found = true;
                    }
                }

                tr[i].style.display = found ? '' : 'none';
            }
        }

        // View Barcode
        function viewBarcode(data) {
            document.getElementById('barcode-modal-name').textContent = data.name;
            document.getElementById('barcode-modal-price').textContent = '₱' + parseFloat(data.price).toFixed(2);
            const svg = document.getElementById('barcode-modal-svg');
            try {
                JsBarcode(svg, data.barcode, { format: "CODE128", width: 2, height: 60, displayValue: true, fontSize: 14, margin: 10 });
                svg.style.display = '';
            } catch (e) {
                svg.style.display = 'none';
            }
            document.getElementById('barcode-modal').classList.add('active');
            document.getElementById('barcode-modal').dataset.barcode = data.barcode;
            document.getElementById('barcode-modal').dataset.name = data.name;
            document.getElementById('barcode-modal').dataset.price = data.price;
        }
        function closeBarcodeModal() { document.getElementById('barcode-modal').classList.remove('active'); }
        function printBarcodeFromModal() {
            const el = document.getElementById('barcode-modal');
            const params = new URLSearchParams({ barcode: el.dataset.barcode, name: el.dataset.name, price: el.dataset.price, stock: '0' });
            window.open('/oro-store-demo/print/print_barcode_label.php?' + params.toString(), '_blank', 'width=500,height=600');
        }

        // Close modals on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeGlobalPriceModal();
                closeStoreStockModal();
                closeDeleteModal();
                closeBarcodeModal();
            }
        });

        // Close modals when clicking outside
        const globalModal = document.getElementById('global-price-modal');
        const storeModal = document.getElementById('store-stock-modal');
        const deleteModal = document.getElementById('delete-product-modal');
        const barcodeModal = document.getElementById('barcode-modal');

        if (barcodeModal) {
            barcodeModal.addEventListener('click', function(e) {
                if (e.target === this) closeBarcodeModal();
            });
        }
        
        if (globalModal) {
            globalModal.addEventListener('click', function(e) {
                if (e.target === this) closeGlobalPriceModal();
            });
        }

        if (storeModal) {
            storeModal.addEventListener('click', function(e) {
                if (e.target === this) closeStoreStockModal();
            });
        }

        if (deleteModal) {
            deleteModal.addEventListener('click', function(e) {
                if (e.target === this) closeDeleteModal();
            });
        }

        // ── Add to Store Modal ──
        let atsData = null;

        function openAddToStoreModal(data) {
            atsData = data;
            document.getElementById('ats-title').textContent = 'Add "' + data.product_name + '" to ' + data.store_code;
            document.getElementById('ats-price').value = parseFloat(data.price).toFixed(2);
            document.getElementById('ats-cost').value = parseFloat(data.purchase_price).toFixed(2);
            document.getElementById('ats-stock').value = '';

            const indSection = document.getElementById('ats-ind-section');
            if (data.ind_id) {
                indSection.style.display = '';
                document.getElementById('ats-ind-name').textContent = data.ind_name;
                document.getElementById('ats-ind-price').value = parseFloat(data.ind_price || 0).toFixed(2);
            } else {
                indSection.style.display = 'none';
            }

            document.getElementById('ats-modal').classList.add('active');
            setTimeout(() => document.getElementById('ats-stock').focus(), 100);
        }

        function closeAtsModal() {
            document.getElementById('ats-modal').classList.remove('active');
            atsData = null;
        }

        function submitAts() {
            if (!atsData) return;
            const stock = parseInt(document.getElementById('ats-stock').value);
            const price = parseFloat(document.getElementById('ats-price').value);
            const cost = parseFloat(document.getElementById('ats-cost').value);
            const indPrice = atsData.ind_id ? parseFloat(document.getElementById('ats-ind-price').value) : 0;

            if (isNaN(stock) || stock < 0) { alert('Enter a valid stock quantity'); return; }
            if (isNaN(price) || price <= 0) { alert('Enter a valid selling price'); return; }

            fetch('', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: `action=add_product_to_store&product_id=${atsData.product_id}&store_id=${atsData.store_id}&price=${price}&purchase_price=${cost}&stock=${stock}&ind_price=${indPrice}`
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) { closeAtsModal(); location.reload(); }
                else alert('Error: ' + (d.error || 'Failed'));
            })
            .catch(e => alert('Error: ' + e.message));
        }

        document.getElementById('ats-modal').addEventListener('click', function(e) {
            if (e.target === this) closeAtsModal();
        });
    </script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Product Management', [
    'Overview' => [
        'Stats bar shows total products, in stock, out of stock, cost/retail value, and margin',
        'Products table shows name, category, brand, barcode, individual unit status',
        'Store-specific stock and pricing shown per store column',
        'Search filters products by name',
    ],
    'Actions' => [
        'Edit (pencil) — update name, category, brand, barcode, pricing, individual settings',
        'Add to Store — assign product to stores with initial stock',
        'Store Edit — change price/stock for a specific store',
        'Delete — soft-delete product (can be restored)',
        'Receipt History — view past stock receipts',
    ],
    'Edit Modal' => [
        'Category and Brand have searchable autocomplete dropdowns',
        'Individual selling: set unit type, units per pack, per-unit price',
        'Child product stock shown if individual selling is enabled',
        'Changes update default product info; store-specific prices stay unchanged',
    ],
]);
?>
</body>
</html>