<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';
require_once __DIR__ . '/../sync/sync_helper.php';
require_once __DIR__ . '/../core/transaction_helper.php';
require_once __DIR__ . '/../core/product_stock_helper.php';

// Initialize SyncDB
$db = new SyncDB();

$currentUser = getCurrentUser();

if ($currentUser['role'] === 'kiosk') {
    header("Location: /oro-store/kiosk/kiosk.php");
    exit;
}

// Get user's store information
$userStore = null;
$isAdmin = in_array($currentUser['role'], ['admin', 'super_admin']);

// Auto-detect store from device_id
@include_once __DIR__ . '/../sync/config.php';
$device_id = defined('LOCAL_DEVICE_ID') ? LOCAL_DEVICE_ID : null;

// Admin with assigned store — lock to that store
if ($isAdmin && $currentUser['store_id']) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $currentUser['store_id']);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Super admin or admin without store — show picker
if ($isAdmin && !$currentUser['store_id']) {
    // Check if admin selected a store via URL param
    if (isset($_GET['store'])) {
        $admin_store_id = intval($_GET['store']);
        $_SESSION['admin_cashier_store'] = $admin_store_id;
    }
    // Auto-select store linked to this device
    if (!isset($_SESSION['admin_cashier_store']) && $device_id) {
        $dev_stmt = $conn->prepare("SELECT id FROM stores WHERE device_id = ? AND status = 'active' LIMIT 1");
        $dev_stmt->bind_param("s", $device_id);
        $dev_stmt->execute();
        $dev_store = $dev_stmt->get_result()->fetch_assoc();
        $dev_stmt->close();
        if ($dev_store) {
            $_SESSION['admin_cashier_store'] = $dev_store['id'];
        }
    }
    // Use session store for AJAX calls (no ?store in URL)
    if (isset($_SESSION['admin_cashier_store'])) {
        $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
        $stmt->bind_param("i", $_SESSION['admin_cashier_store']);
        $stmt->execute();
        $userStore = $stmt->get_result()->fetch_assoc();
    }
    // If still no store and not an AJAX request, show picker
    if (!$userStore && !isset($_GET['action']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $all_stores = $conn->query("SELECT id, store_name, store_code FROM stores WHERE status = 'active' ORDER BY store_name")->fetch_all(MYSQLI_ASSOC);
        if (count($all_stores) === 1) {
            // Only one store, auto-select
            $_SESSION['admin_cashier_store'] = $all_stores[0]['id'];
            header("Location: /oro-store/cashier/cashier.php?store=" . $all_stores[0]['id']);
            exit;
        }
        // Show store picker page
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
            <title>Select Store - Oro Store</title>
            <style>
                * { margin:0; padding:0; box-sizing:border-box; }
                body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; background:#0f172a; display:flex; align-items:center; justify-content:center; min-height:100vh; }
                .picker { background:#fff; border-radius:16px; padding:32px; width:90%; max-width:400px; box-shadow:0 25px 80px rgba(0,0,0,.4); text-align:center; }
                .picker h1 { font-size:20px; color:#1e293b; margin-bottom:4px; }
                .picker p { font-size:13px; color:#64748b; margin-bottom:24px; }
                .store-option { display:flex; align-items:center; gap:14px; padding:16px; border:2px solid #e2e8f0; border-radius:12px; margin-bottom:10px; cursor:pointer; transition:all .15s; text-decoration:none; color:inherit; }
                .store-option:hover { border-color:#6366f1; background:#eef2ff; transform:translateY(-1px); }
                .store-icon { width:48px; height:48px; border-radius:12px; background:linear-gradient(135deg,#6366f1,#8b5cf6); display:flex; align-items:center; justify-content:center; font-size:22px; color:#fff; flex-shrink:0; }
                .store-info { text-align:left; }
                .store-name { font-size:16px; font-weight:700; color:#1e293b; }
                .store-code { font-size:12px; color:#64748b; font-weight:600; }
                .back-link { display:inline-block; margin-top:16px; color:#6366f1; text-decoration:none; font-size:13px; font-weight:600; }
                .back-link:hover { color:#4f46e5; }
            </style>
        </head>
        <body>
            <div class="picker">
                <h1>Select Store</h1>
                <p>Choose which store to operate the cashier for</p>
                <?php foreach ($all_stores as $s): ?>
                <a href="/oro-store/cashier/cashier.php?store=<?php echo $s['id']; ?>" class="store-option">
                    <div class="store-icon">&#127978;</div>
                    <div class="store-info">
                        <div class="store-name"><?php echo htmlspecialchars($s['store_name']); ?></div>
                        <div class="store-code"><?php echo htmlspecialchars($s['store_code']); ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
                <a href="/oro-store/admin/admin_panel.php" class="back-link">&larr; Back to Dashboard</a>
            </div>
        </body>
        </html>
        <?php
        $conn->close();
        exit;
    }
} elseif ($currentUser['store_id']) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $currentUser['store_id']);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
}

// Handle transaction history fetch
if (isset($_GET['action']) && $_GET['action'] === 'get_transactions') {
    header('Content-Type: application/json');

    $query = "SELECT t.*,
              COUNT(ti.id) as item_count,
              t.parent_transaction_id,
              (SELECT COUNT(*) FROM transactions WHERE parent_transaction_id = t.id AND is_deleted = 0) as has_children
              FROM transactions t
              LEFT JOIN transaction_items ti ON t.id = ti.transaction_id AND ti.is_deleted = 0
              WHERE t.is_deleted = 0";

    if ($userStore) {
        $query .= " AND t.store_id = ?";
    }

    $query .= " GROUP BY t.id ORDER BY t.transaction_date DESC LIMIT 100";

    $stmt = $conn->prepare($query);
    if ($userStore) {
        $stmt->bind_param("i", $userStore['id']);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $transactions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Merge GCash transactions from this device's store
    $gcash_where = "WHERE g.is_deleted = 0 AND g.status != 'voided'";
    if ($userStore) $gcash_where .= " AND g.store_id = " . intval($userStore['id']);
    $gr = $conn->query("SELECT g.id, g.transaction_type, g.amount, g.fee, g.total_amount,
        g.reference_number, g.status, g.transaction_date, g.user_name, g.device_id, g.store_id,
        g.customer_number, g.original_transaction_id,
        ga.account_name as gcash_acct_name
        FROM gcash_transactions g LEFT JOIN gcash_accounts ga ON g.gcash_account_id = ga.id
        $gcash_where ORDER BY g.transaction_date DESC LIMIT 50");
    $gcash_txns = $gr ? $gr->fetch_all(MYSQLI_ASSOC) : [];

    foreach ($gcash_txns as &$g) {
        $g['_source'] = 'gcash';
        $g['payment_method'] = 'gcash_service';
        $g['total_items'] = 1;
        $g['item_count'] = 1;
        $g['transaction_number'] = $g['reference_number'];
        $g['has_children'] = 0;
        $g['parent_transaction_id'] = $g['original_transaction_id'];
    }
    unset($g);

    $merged = array_merge($transactions, $gcash_txns);
    usort($merged, function($a, $b) {
        return strtotime($b['transaction_date']) - strtotime($a['transaction_date']);
    });
    $merged = array_slice($merged, 0, 100);

    echo json_encode($merged);
    $conn->close();
    exit;
}

// Handle transaction details fetch
if (isset($_GET['action']) && $_GET['action'] === 'get_transaction_details') {
    header('Content-Type: application/json');
    
    $transaction_id = intval($_GET['transaction_id']);
    $store_id = $userStore ? $userStore['id'] : null;
    
    if ($store_id) {
        $stmt = $conn->prepare("SELECT ti.*, 
                               COALESCE(sp.stock, p.stock) as current_stock 
                               FROM transaction_items ti 
                               LEFT JOIN products p ON ti.product_id = p.id 
                               LEFT JOIN store_prices sp ON ti.product_id = sp.product_id AND sp.store_id = ?
                               WHERE ti.transaction_id = ? AND ti.is_deleted = 0");
        $stmt->bind_param("ii", $store_id, $transaction_id);
    } else {
        $stmt = $conn->prepare("SELECT ti.*, p.stock as current_stock 
                               FROM transaction_items ti 
                               LEFT JOIN products p ON ti.product_id = p.id 
                               WHERE ti.transaction_id = ? AND ti.is_deleted = 0");
        $stmt->bind_param("i", $transaction_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode($items);
    $conn->close();
    exit;
}
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

    // Join to parent product to get its price and pieces_per_pack,
    // then look up credit charge via the PARENT's category.
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
        // Not an individual product
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
// Handle reprint logging
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_reprint') {
    header('Content-Type: application/json');
    
    $transaction_id      = intval($_POST['transaction_id']);
    $transaction_number  = $_POST['transaction_number'];
    $items               = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];
    $total_amount        = isset($_POST['total_amount'])  ? floatval($_POST['total_amount'])  : 0;
    $amount_paid         = isset($_POST['amount_paid'])   ? floatval($_POST['amount_paid'])   : 0;
    $change_amount       = isset($_POST['change_amount']) ? floatval($_POST['change_amount']) : 0;
    $user_id             = $currentUser['id'];
    $store_id            = $userStore ? $userStore['id'] : null;
    $reprint_reason      = isset($_POST['reprint_reason']) ? $_POST['reprint_reason'] : 'Manual reprint';
    
    try {
        $device_id    = isset($_SERVER['HTTP_X_DEVICE_ID']) ? $_SERVER['HTTP_X_DEVICE_ID'] : null;
        $reprint_data = [
            'transaction_id'     => $transaction_id,
            'transaction_number' => $transaction_number,
            'reprinted_by'       => $user_id,
            'store_id'           => $store_id,
            'total_amount'       => $total_amount,
            'amount_paid'        => $amount_paid,
            'change_amount'      => $change_amount,
            'items_json'         => json_encode($items),
            'reprint_reason'     => $reprint_reason
        ];
        
        if ($device_id) { $reprint_data['device_id'] = $device_id; }
        
        $reprint_id = $db->insert('reprints', $reprint_data);
        
        logActivity('reprint', "Receipt reprinted: $transaction_number", 
            $user_id, $store_id, 
            [
                'reprint_id'       => $reprint_id,
                'transaction_id'   => $transaction_id,
                'transaction_number' => $transaction_number,
                'reprinted_by'     => $currentUser['full_name'],
                'total_amount'     => $total_amount,
                'items_count'      => count($items),
                'items_modified'   => count($items) > 0 ? 'yes' : 'no'
            ]
        );
        
        echo json_encode(['success' => true, 'reprint_id' => $reprint_id, 'message' => 'Reprint logged successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    $conn->close();
    exit;
}

// Handle re-edited transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reedit_transaction') {
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    
    $original_transaction_id = intval($_POST['original_transaction_id']);
    $original_items          = json_decode($_POST['original_items'], true);
    $new_items               = json_decode($_POST['new_items'], true);
    $total_amount            = floatval($_POST['total_amount']);
    $total_profit            = floatval($_POST['total_profit']);
    $items_count             = intval($_POST['items_count']);
    $payment_method          = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'cash';
    $amount_paid             = isset($_POST['amount_paid'])  ? floatval($_POST['amount_paid'])  : $total_amount;
    $change_amount           = isset($_POST['change_amount']) ? floatval($_POST['change_amount']) : 0;
    $user_id                 = $currentUser['id'];
    $store_id                = $userStore ? $userStore['id'] : null;
    
    $conn->begin_transaction();
    
    try {
        $debug_info = ['restored' => [], 'deducted' => []];
        
        foreach ($original_items as $item) {
            try {
                if ($store_id) {
                    $check = $conn->query("SELECT stock FROM store_prices WHERE product_id = {$item['product_id']} AND store_id = $store_id");
                    $before_stock = $check->fetch_assoc()['stock'] ?? 0;
                } else {
                    $check = $conn->query("SELECT stock FROM products WHERE id = {$item['product_id']}");
                    $before_stock = $check->fetch_assoc()['stock'] ?? 0;
                }
                
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
                
                if ($store_id) {
                    $check = $conn->query("SELECT stock FROM store_prices WHERE product_id = {$item['product_id']} AND store_id = $store_id");
                    $after_stock = $check->fetch_assoc()['stock'] ?? 0;
                } else {
                    $check = $conn->query("SELECT stock FROM products WHERE id = {$item['product_id']}");
                    $after_stock = $check->fetch_assoc()['stock'] ?? 0;
                }
                
                $debug_info['restored'][] = ['product_id' => $item['product_id'], 'name' => $item['product_name'], 'qty_restored' => $item['quantity'], 'before' => $before_stock, 'after' => $after_stock];
                logActivity('product', "Stock restored for {$item['product_name']} during re-edit", $user_id, $store_id, ['product_id' => $item['product_id'], 'quantity_restored' => $item['quantity'], 'before_stock' => $before_stock, 'after_stock' => $after_stock, 'transaction_id' => $original_transaction_id]);
            } catch (Exception $e) {
                throw new Exception("Failed to restore stock for product ID {$item['product_id']}: " . $e->getMessage());
            }
        }
        
        $db->update('transactions', ['status' => 'edited'], "id = $original_transaction_id");
        $transaction_number = generateTransactionNumber($conn);

        $new_status = in_array($payment_method, ['credit', 'delivery']) ? 'pending' : 'completed';
        $new_transaction_id = $db->insert('transactions', [
            'parent_transaction_id' => $original_transaction_id,
            'transaction_number'    => $transaction_number,
            'user_id'               => $user_id,
            'store_id'              => $store_id,
            'subtotal'              => $total_amount,
            'total_amount'          => $total_amount,
            'total_profit'          => $total_profit,
            'total_items'           => $items_count,
            'payment_method'        => $payment_method,
            'amount_paid'           => $amount_paid,
            'change_amount'         => $change_amount,
            'status'                => $new_status
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
            
            try {
                if ($store_id) {
                    $check = $conn->query("SELECT stock FROM store_prices WHERE product_id = {$item['id']} AND store_id = $store_id");
                    $before_stock = $check->fetch_assoc()['stock'] ?? 0;
                } else {
                    $check = $conn->query("SELECT stock FROM products WHERE id = {$item['id']}");
                    $before_stock = $check->fetch_assoc()['stock'] ?? 0;
                }
                
                if (isIndividualProduct($item['id'])) {
                    $stock_result = deductIndividualStock($item['id'], $item['quantity'], $store_id);
                    if ($stock_result['packs_opened'] > 0) {
                        logActivity('product', "Converted {$stock_result['packs_opened']} pack(s) during re-edit", $user_id, $store_id, ['product_id' => $item['id'], 'product_name' => $item['name'], 'packs_opened' => $stock_result['packs_opened'], 'transaction_id' => $new_transaction_id]);
                    }
                } else {
                    $stock_result = deductPackStock($item['id'], $item['quantity'], $store_id);
                }
                
                if ($store_id) {
                    $check = $conn->query("SELECT stock FROM store_prices WHERE product_id = {$item['id']} AND store_id = $store_id");
                    $after_stock = $check->fetch_assoc()['stock'] ?? 0;
                } else {
                    $check = $conn->query("SELECT stock FROM products WHERE id = {$item['id']}");
                    $after_stock = $check->fetch_assoc()['stock'] ?? 0;
                }
                
                $debug_info['deducted'][] = ['product_id' => $item['id'], 'name' => $item['name'], 'qty_deducted' => $item['quantity'], 'before' => $before_stock, 'after' => $after_stock];
                logActivity('product', "Stock deducted for {$item['name']} during re-edit", $user_id, $store_id, ['product_id' => $item['id'], 'product_name' => $item['name'], 'quantity_deducted' => $item['quantity'], 'before_stock' => $before_stock, 'after_stock' => $after_stock, 'transaction_id' => $new_transaction_id]);
            } catch (Exception $e) {
                throw new Exception("Stock deduction failed for {$item['name']}: " . $e->getMessage());
            }
        }
        
        logActivity('transaction', "Transaction re-edited: $transaction_number (parent ID: $original_transaction_id)", $user_id, $store_id, ['transaction_id' => $new_transaction_id, 'parent_transaction_id' => $original_transaction_id, 'transaction_number' => $transaction_number, 'amount' => $total_amount, 'payment_method' => $payment_method, 'debug_stock' => $debug_info]);
        
        $conn->commit();
        if (function_exists('flushStockPushes')) flushStockPushes();
        echo json_encode(['success' => true, 'transaction_id' => $new_transaction_id, 'parent_transaction_id' => $original_transaction_id, 'transaction_number' => $transaction_number, 'debug' => $debug_info]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    $conn->close();
    exit;
}

// ─── AJAX: Get pending kiosk orders ──────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_kiosk_orders') {
    header('Content-Type: application/json');
    $store_id = $userStore ? $userStore['id'] : null;
    $today = date('Y-m-d');
    $sql = "SELECT ko.*, u.full_name as created_by_name FROM kiosk_orders ko LEFT JOIN users u ON ko.created_by = u.id WHERE ko.order_date = ? AND ko.status IN ('waiting','processing')";
    if ($store_id) { $sql .= " AND ko.store_id = ?"; }
    $sql .= " ORDER BY ko.priority_number ASC";
    $stmt = $conn->prepare($sql);
    if ($store_id) { $stmt->bind_param("si", $today, $store_id); }
    else { $stmt->bind_param("s", $today); }
    $stmt->execute();
    $orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo json_encode($orders);
    $conn->close(); exit;
}

// ─── POST: Process kiosk order (load into cashier cart) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_kiosk_order') {
    header('Content-Type: application/json');
    $order_id = intval($_POST['order_id']);
    $store_id = $userStore ? $userStore['id'] : null;
    $today = date('Y-m-d');
    $sc = $store_id ? " AND store_id = " . intval($store_id) : "";

    // Check if this order exists
    $stmt = $conn->prepare("SELECT * FROM kiosk_orders WHERE id = ? AND status IN ('waiting', 'processing')");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order) { echo json_encode(['success' => false, 'error' => 'Order not found or already completed']); $conn->close(); exit; }

    // If this order is already processing, allow re-loading (no blocking)

    $conn->query("UPDATE kiosk_orders SET status = 'processing', processed_by = {$currentUser['id']}, processed_at = NOW() WHERE id = $order_id");
    echo json_encode(['success' => true, 'order' => $order]);
    $conn->close(); exit;
}

// ─── POST: Complete kiosk order (link to transaction) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_kiosk_order') {
    header('Content-Type: application/json');
    $order_id = intval($_POST['order_id']);
    $transaction_id = intval($_POST['transaction_id']);
    $conn->query("UPDATE kiosk_orders SET status = 'completed', transaction_id = $transaction_id, processed_by = {$currentUser['id']}, processed_at = NOW() WHERE id = $order_id");
    echo json_encode(['success' => true]);
    $conn->close(); exit;
}

// ─── POST: Cancel kiosk order ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_kiosk_order') {
    header('Content-Type: application/json');
    $order_id = intval($_POST['order_id']);
    $conn->query("UPDATE kiosk_orders SET status = 'cancelled', processed_by = {$currentUser['id']}, processed_at = NOW() WHERE id = $order_id");
    logActivity('kiosk', "Kiosk order #$order_id cancelled", $currentUser['id'], $userStore ? $userStore['id'] : null, ['kiosk_order_id' => $order_id]);
    echo json_encode(['success' => true]);
    $conn->close(); exit;
}

// ─── POST: Void transaction ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'void_transaction') {
    header('Content-Type: application/json');
    $transaction_id = intval($_POST['transaction_id']);
    $user_id = $currentUser['id'];
    $store_id = $userStore ? $userStore['id'] : null;

    $conn->begin_transaction();
    try {
        // Get transaction
        $stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ? AND status IN ('completed','pending')");
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $transaction = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$transaction) throw new Exception('Transaction not found or already voided');

        // Restore stock for each item
        $stmt = $conn->prepare("SELECT * FROM transaction_items WHERE transaction_id = ?");
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($items as $item) {
            if (isIndividualProduct($item['product_id'])) {
                restoreIndividualStock($item['product_id'], $item['quantity'], $transaction['store_id']);
            } else {
                if ($transaction['store_id']) {
                    $conn->query("UPDATE store_prices SET stock = stock + {$item['quantity']} WHERE product_id = {$item['product_id']} AND store_id = {$transaction['store_id']}");
                } else {
                    $conn->query("UPDATE products SET stock = stock + {$item['quantity']} WHERE id = {$item['product_id']}");
                }
            }
        }

        // Mark as voided
        $db->update('transactions', ['status' => 'voided'], "id = $transaction_id");

        // Void related records (delivery, credit, angkat)
        $conn->query("UPDATE deliveries SET status = 'cancelled' WHERE transaction_id = $transaction_id AND is_deleted = 0");
        $conn->query("UPDATE credits SET status = 'voided' WHERE transaction_id = $transaction_id AND is_deleted = 0");

        logActivity('transaction', "Transaction voided: {$transaction['transaction_number']}", $user_id, $store_id, [
            'transaction_id' => $transaction_id,
            'transaction_number' => $transaction['transaction_number'],
            'amount' => $transaction['total_amount'],
            'payment_method' => $transaction['payment_method']
        ]);

        $conn->commit();
        if (function_exists('flushStockPushes')) flushStockPushes();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

function _logTxDebug($status, $txn_number, $steps) {
    $log = date('Y-m-d H:i:s') . " [$status] TXN:$txn_number DEVICE:" . (defined('LOCAL_DEVICE_ID') ? LOCAL_DEVICE_ID : '?') . "\n";
    foreach ($steps as $s) { $log .= "  " . json_encode($s) . "\n"; }
    $log .= "\n";
    @file_put_contents(__DIR__ . '/transaction_debug.log', $log, FILE_APPEND);
}

// Handle transaction submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_transaction') {
    header('Content-Type: application/json');
    $tx_start = microtime(true);
    $tx_steps = [];

    $items          = json_decode($_POST['items'], true);
    $total_amount   = floatval($_POST['total_amount']);
    $total_profit   = floatval($_POST['total_profit']);
    $items_count    = intval($_POST['items_count']);
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method'] : 'cash';
    $amount_paid    = isset($_POST['amount_paid'])   ? floatval($_POST['amount_paid'])   : $total_amount;
    $change_amount  = isset($_POST['change_amount']) ? floatval($_POST['change_amount']) : 0;
    $discount_amount = isset($_POST['discount_amount']) ? floatval($_POST['discount_amount']) : 0;
    $subtotal       = isset($_POST['subtotal']) ? floatval($_POST['subtotal']) : $total_amount;
    $user_id        = $currentUser['id'];
    $store_id       = $userStore ? $userStore['id'] : null;
    
    $conn->begin_transaction();
    
    try {
        $tx_steps[] = ['step' => 'begin_transaction', 'time' => round((microtime(true) - $tx_start) * 1000) . 'ms'];

        $transaction_number = generateTransactionNumber($conn);
        $tx_steps[] = ['step' => 'generate_txn_number', 'time' => round((microtime(true) - $tx_start) * 1000) . 'ms'];

        $transaction_id = $db->insert('transactions', [
            'transaction_number' => $transaction_number,
            'user_id'            => $user_id,
            'store_id'           => $store_id,
            'subtotal'           => $subtotal,
            'discount_amount'    => $discount_amount,
            'total_amount'       => $total_amount,
            'total_profit'       => $total_profit,
            'payment_method'     => $payment_method,
            'amount_paid'        => $amount_paid,
            'change_amount'      => $change_amount,
            'total_items'        => $items_count,
            'status'             => 'completed'
        ]);
        
        $tx_steps[] = ['step' => 'insert_transaction', 'id' => $transaction_id, 'time' => round((microtime(true) - $tx_start) * 1000) . 'ms'];

        foreach ($items as $idx => $item) {
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
            
            $tx_steps[] = ['step' => "insert_item_$idx", 'product' => $item['name'], 'time' => round((microtime(true) - $tx_start) * 1000) . 'ms'];

            try {
                if (isIndividualProduct($item['id'])) {
                    $stock_result = deductIndividualStock($item['id'], $item['quantity'], $store_id);
                    $tx_steps[] = ['step' => "deduct_individual_$idx", 'product' => $item['name'], 'time' => round((microtime(true) - $tx_start) * 1000) . 'ms'];
                    if ($stock_result['packs_opened'] > 0) {
                        logActivity('product', "Converted {$stock_result['packs_opened']} pack(s) to individual units", $user_id, $store_id, ['product_id' => $item['id'], 'product_name' => $item['name'], 'packs_opened' => $stock_result['packs_opened'], 'transaction_id' => $transaction_id, 'new_individual_stock' => $stock_result['new_individual_stock'], 'new_pack_stock' => $stock_result['new_pack_stock']]);
                    }
                } else {
                    $stock_result = deductPackStock($item['id'], $item['quantity'], $store_id);
                    $tx_steps[] = ['step' => "deduct_pack_$idx", 'product' => $item['name'], 'time' => round((microtime(true) - $tx_start) * 1000) . 'ms'];
                }
            } catch (Exception $e) {
                $tx_steps[] = ['step' => "STOCK_ERROR_$idx", 'product' => $item['name'], 'error' => $e->getMessage(), 'time' => round((microtime(true) - $tx_start) * 1000) . 'ms'];
                throw new Exception("Stock deduction failed for {$item['name']}: " . $e->getMessage());
            }
            
            logActivity('product', "Stock updated for {$item['name']}", $user_id, $store_id, ['product_id' => $item['id'], 'product_name' => $item['name'], 'quantity_change' => -$item['quantity'], 'transaction_id' => $transaction_id]);
        }
        
        logActivity('transaction', "Transaction completed: $transaction_number", $user_id, $store_id, ['transaction_id' => $transaction_id, 'transaction_number' => $transaction_number, 'amount' => $total_amount, 'payment_method' => $payment_method, 'items_count' => $items_count]);
        
        $tx_steps[] = ['step' => 'commit', 'time' => round((microtime(true) - $tx_start) * 1000) . 'ms'];
        $conn->commit();
        if (function_exists('flushStockPushes')) flushStockPushes();
        $tx_steps[] = ['step' => 'done', 'total' => round((microtime(true) - $tx_start) * 1000) . 'ms'];
        _logTxDebug('SUCCESS', $transaction_number, $tx_steps);
        echo json_encode(['success' => true, 'transaction_id' => $transaction_id, 'transaction_number' => $transaction_number]);
    } catch (Exception $e) {
        $conn->rollback();
        $tx_steps[] = ['step' => 'ROLLBACK', 'error' => $e->getMessage(), 'mysql_error' => $conn->error, 'time' => round((microtime(true) - $tx_start) * 1000) . 'ms'];
        _logTxDebug('FAILED', '', $tx_steps);
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'debug_steps' => $tx_steps]);
    }

    $conn->close();
    exit;
}

// Fetch products
if ($userStore) {
    $stmt = $conn->prepare("SELECT p.id, p.name, p.description, p.barcode,
                           p.parent_product_id,
                           sp.price as price,
                           sp.purchase_price as purchase_price,
                           sp.stock as stock,
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
    $result = $conn->query("SELECT p.id, p.name,
                           COALESCE(sp.price, p.price) as price,
                           COALESCE(sp.purchase_price, p.purchase_price) as purchase_price,
                           COALESCE(sp.stock, p.stock) as stock,
                           p.description, p.barcode, p.parent_product_id,
                           COALESCE(pc.category_name, ppc.category_name) as category_name,
                           COALESCE(pb.brand_name, ppb.brand_name) as brand_name,
                           pp.individual_sell_unit
                           FROM products p
                           LEFT JOIN store_prices sp ON p.id = sp.product_id AND sp.is_deleted = 0
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
    <title>Cashier<?php echo $userStore ? ' - ' . htmlspecialchars($userStore['store_name']) : ''; ?></title>
    <?php include_once __DIR__ . '/../core/pwa.php'; ?>
    <link rel="stylesheet" href="/oro-store/cashier/cashier_styles.css">
    <script src="/oro-store/core/cache.js"></script>
    <script src="/oro-store/core/bt_print.js?v=20250627"></script>
    <style>
        /* Disable text selection and context menu on touch devices */
        .product-item-cashier, .receipt-item, .total-row, .btn-confirm, .product-list-cashier, .cart-section {
            -webkit-user-select: none;
            user-select: none;
            -webkit-touch-callout: none;
        }
    </style>
</head>
<body>
<!-- Shortcut Bar -->
<div class="shortcut-bar">
    <span class="sc-key" onclick="scEsc()"><kbd>Esc</kbd> Cancel</span>
    <span class="sc-key" onclick="scHome()"><kbd>Home</kbd> Switch</span>
    <span class="sc-key" onclick="scDel()"><kbd>Del</kbd> Remove</span>
    <span class="sc-key" onclick="scIns()"><kbd>Ins</kbd> Edit</span>
    <span class="sc-key sc-blue" onclick="printReceipt()"><kbd>F1</kbd> Print</span>
    <span class="sc-key sc-green" onclick="openGCash()"><kbd>F2</kbd> GCash</span>
    <span class="sc-key sc-orange" onclick="scF3()"><kbd>F3</kbd> Delivery</span>
    <span class="sc-key sc-purple" onclick="scF4()"><kbd>F4</kbd> Credit</span>
    <span class="sc-key sc-teal" onclick="scF5()"><kbd>F5</kbd> Angkat</span>
    <span class="sc-key sc-blue" onclick="openCardTransaction()"><kbd>F7</kbd> ATM</span>
    <span class="sc-key" onclick="location.href='/oro-store/delivery/delivery_details.php'"><kbd>F9</kbd> Details</span>
    <span class="sc-key" onclick="openAddStock()"><kbd>F11</kbd> Add Stock</span>
    <span class="sc-key" onclick="openHistoryModal()"><kbd>F12</kbd> History</span>
    <span class="sc-key" onclick="openKioskQueue()" id="kioskQueueBtn" style="background:#7c3aed;color:#e9d5ff;"><kbd>F8</kbd> Queue <span id="kioskQueueCount" style="background:#fbbf24;color:#1a202c;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700;margin-left:2px;display:none;">0</span></span>
</div>

<div class="cashier-container">

    <!-- ══ LEFT PANEL ══════════════════════════════════════════════════ -->
    <div class="left-panel">

        <!-- Top bar -->
        <div class="lp-topbar">
            <h1>Product Selection</h1>
            <div class="lp-meta">
                <?php if ($userStore): ?>
                    <span class="lp-store-badge">🏪 <?php echo htmlspecialchars($userStore['store_code']); ?></span>
                <?php endif; ?>
                <div class="lp-user">
                    <strong><?php echo htmlspecialchars($currentUser['full_name']); ?></strong>
                    <?php echo ucfirst($currentUser['role']); ?>
                </div>
                <?php if ($currentUser['role'] === 'admin' || $currentUser['role'] === 'super_admin'): ?>
                    <button class="btn-panel" onclick="window.location.href='/oro-store/admin/admin_panel.php'">Admin Panel</button>
                <?php elseif ($currentUser['role'] === 'manager'): ?>
                    <button class="btn-panel" onclick="window.location.href='/oro-store/manager/manager_panel.php'">Manager Panel</button>
                <?php endif; ?>
                <button class="btn-logout" onclick="logout()">Logout</button>
            </div>
        </div>

        <!-- Search with autocomplete -->
        <div class="search-container" style="position:relative;">
            <input type="text" id="cashier-search" placeholder="Search (Name, Brand, Category, Barcode)…" autofocus autocomplete="off">
            <div id="search-tags" style="display:flex;gap:4px;flex-wrap:wrap;padding:4px 8px;"></div>
            <div id="search-suggestions" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #e2e8f0;border-radius:0 0 8px 8px;box-shadow:0 4px 12px rgba(0,0,0,.15);max-height:200px;overflow-y:auto;z-index:100;"></div>
        </div>
        <!-- Suggestion data -->
        <script>
        var __suggestions = new Set();
        <?php foreach ($products as $p): ?>
        <?php if (!empty($p['brand_name'])): ?>__suggestions.add(<?php echo json_encode($p['brand_name']); ?>);<?php endif; ?>
        <?php if (!empty($p['category_name'])): ?>__suggestions.add(<?php echo json_encode($p['category_name']); ?>);<?php endif; ?>
        <?php if (!empty($p['individual_sell_unit'])): ?>__suggestions.add(<?php echo json_encode(ucfirst($p['individual_sell_unit'])); ?>);<?php endif; ?>
        <?php endforeach; ?>
        </script>

        <!-- Product list -->
        <ul class="product-list-cashier" id="product-list">
            <?php foreach ($products as $index => $product): ?>
            <?php $oos = $product['stock'] <= 0; ?>
            <li class="product-item-cashier <?php echo $oos ? 'out-of-stock' : ''; ?>"
                onclick="handleProductTap(this)"
                data-id="<?php echo $product['id']; ?>"
                data-name="<?php echo htmlspecialchars($product['name']); ?>"
                data-price="<?php echo $product['price']; ?>"
                data-purchase-price="<?php echo $product['purchase_price']; ?>"
                data-stock="<?php echo $product['stock']; ?>"
                data-barcode="<?php echo htmlspecialchars($product['barcode'] ?? ''); ?>"
                data-description="<?php echo htmlspecialchars($product['description'] ?? ''); ?>"
                data-brand="<?php echo htmlspecialchars($product['brand_name'] ?? ''); ?>"
                data-category="<?php echo htmlspecialchars($product['category_name'] ?? ''); ?>"
                data-unit="<?php echo htmlspecialchars($product['individual_sell_unit'] ?? ''); ?>"
                data-parent-product-id="<?php echo $product['parent_product_id'] ?? ''; ?>"
                data-index="<?php echo $index; ?>">
                <span class="product-name-cashier">
                    <?php echo htmlspecialchars($product['name']); ?>
                    <?php if ($oos): ?>
                        <span class="oos-label"> (OUT OF STOCK)</span>
                    <?php endif; ?>
                    <?php
                    $is_child = !empty($product['parent_product_id']);
                    if (!empty($product['category_name']) || !empty($product['brand_name']) || !empty($product['individual_sell_unit'])): ?>
                    <span style="display:inline-flex;gap:3px;margin-left:4px;vertical-align:middle;">
                        <?php if (!empty($product['individual_sell_unit'])): ?>
                            <span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#fef3c7;color:#92400e;letter-spacing:.2px;"><?php echo htmlspecialchars(ucfirst($product['individual_sell_unit'])); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($product['category_name']) && !$is_child): ?>
                            <span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#ede9fe;color:#7c3aed;letter-spacing:.2px;"><?php echo htmlspecialchars($product['category_name']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($product['brand_name'])): ?>
                            <span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#dbeafe;color:#1d4ed8;letter-spacing:.2px;"><?php echo htmlspecialchars($product['brand_name']); ?></span>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                </span>
                <span class="product-stock-cashier <?php echo $oos ? 'oos-stock' : ''; ?>">
                    Stock: <?php echo $product['stock']; ?>
                </span>
                <span class="product-price-cashier">₱<?php echo number_format($product['price'], 2); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <!-- ══ END LEFT PANEL ═════════════════════════════════════════════ -->

    <!-- ══ RIGHT PANEL ════════════════════════════════════════════════ -->
    <div class="right-panel">
        <div class="receipt-header">
            <h1>RECEIPT</h1>
            <p>Transaction in Progress</p>
            <?php if ($userStore): ?>
                <p class="receipt-store-name"><?php echo htmlspecialchars($userStore['store_name']); ?></p>
            <?php endif; ?>
        </div>

        <div class="receipt-items" id="receipt-items">
            <div class="empty-cart">
                <h3>Cart is Empty</h3>
                <p>Select products from the left to add to cart</p>
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
            <div class="total-row grand-total" onclick="if(typeof cart!=='undefined'&&cart.length>0){openPaymentModal();}else{simulateKey('Enter');}" style="cursor:pointer;padding:16px;border-radius:10px;background:#22c55e;color:#fff;">
                <span style="font-weight:800;">TOTAL:</span>
                <span id="grand-total" style="font-weight:800;">₱0.00</span>
            </div>
        </div>
    </div>
    <!-- ══ END RIGHT PANEL ════════════════════════════════════════════ -->

    <!-- ══ TRANSACTION HISTORY MODAL ══════════════════════════════════ -->
    <div class="modal" id="history-modal">
        <div class="modal-content modal-wide">
            <h2>Transaction History</h2>
            <div class="modal-search-bar">
                <input type="text" id="history-search" placeholder="Search by Transaction ID, Number, or Customer…">
            </div>
            <div class="modal-table-wrap">
                <table class="modal-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Transaction #</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th class="text-right">Total</th>
                            <th class="text-center">Items</th>
                            <th class="text-center">Status</th>
                            <th class="text-center col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="history-table-body">
                        <tr><td colspan="8" class="text-center cell-loading">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-buttons">
                <button class="btn-cancel" onclick="closeHistoryModal()">Close (Esc)</button>
            </div>
        </div>
    </div>

    <!-- ══ TRANSACTION DETAILS MODAL ══════════════════════════════════ -->
    <div class="modal" id="details-modal">
        <div class="modal-content modal-medium">
            <h2>Transaction Details</h2>
            <div id="transaction-details-content" class="modal-details-body">Loading…</div>
            <div class="modal-buttons">
                <button class="btn-confirm" onclick="loadTransactionToCart()">Load to Cart (Enter)</button>
                <button class="btn-cancel" onclick="closeDetailsModal()">Cancel (Esc)</button>
            </div>
        </div>
    </div>

    <!-- ══ QUANTITY MODAL ══════════════════════════════════════════════ -->
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

    <!-- ══ PAYMENT MODAL ═══════════════════════════════════════════════ -->
    <div class="modal" id="payment-modal">
        <div class="modal-content">
            <h2 id="payment-modal-title">Complete Transaction</h2>
            <div class="payment-summary">
                <div class="payment-row">
                    <span>Total Amount:</span>
                    <span id="payment-total" class="payment-total-val">₱0.00</span>
                </div>
                <div class="payment-row">
                    <span>Total Items:</span>
                    <span id="payment-items" class="payment-items-val">0</span>
                </div>
            </div>
            <div class="payment-field">
                <label>Amount Paid *</label>
                <input type="number" id="amount-paid-input" step="0.01" min="0" placeholder="Enter amount paid">
            </div>
            <div id="change-display" class="change-display" style="display:none;">
                <div class="change-row">
                    <span>Change:</span>
                    <span id="change-amount" class="change-val">₱0.00</span>
                </div>
            </div>
            <div id="insufficient-warning" class="insufficient-warning" style="display:none;">
                ⚠️ Insufficient payment amount
            </div>
            <div class="modal-buttons">
                <button class="btn-print" id="print-payment-btn" onclick="printReceiptFromPaymentModal()">Print Receipt (F1)</button>
                <button class="btn-confirm" id="complete-payment-btn" onclick="completeTransaction()">Complete (Enter)</button>
                <button class="btn-cancel"  onclick="closePaymentModal()">Cancel (Esc)</button>
            </div>
        </div>
    </div>

    <!-- ══ CONFIRM MODAL (re-edit mode) ═══════════════════════════════ -->
    <div class="modal" id="confirm-modal">
        <div class="modal-content">
            <h2 id="confirm-modal-title">Complete Transaction?</h2>
            <p>Total Amount: <strong id="confirm-total">₱0.00</strong></p>
            <p>Total Items: <strong id="confirm-items">0</strong></p>
            <div class="modal-buttons">
                <button class="btn-print"   onclick="printReceiptFromModal()">Print Receipt (F1)</button>
                <button class="btn-confirm" onclick="completeTransaction()">Confirm (Enter)</button>
                <button class="btn-cancel"  onclick="closeConfirmModal()">Cancel (Esc)</button>
            </div>
        </div>
    </div>

    <!-- ══ EDIT QUANTITY MODAL ═════════════════════════════════════════ -->
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

<!-- Kiosk Queue Modal -->
<div class="modal" id="kiosk-queue-modal" style="z-index:600;">
    <div class="modal-content" style="max-width:640px;max-height:85vh;overflow-y:auto;">
        <h2 style="display:flex;align-items:center;gap:10px;">
            Kiosk Queue
            <span id="kqCountBadge" style="background:#7c3aed;color:#fff;font-size:12px;padding:2px 10px;border-radius:10px;">0</span>
        </h2>
        <div id="kiosk-queue-list" style="margin:16px 0;">
            <div style="text-align:center;color:#94a3b8;padding:20px;font-size:13px;">Loading...</div>
        </div>
        <div class="modal-buttons">
            <button class="btn-cancel" onclick="closeKioskQueue()">Close (Esc)</button>
        </div>
    </div>
</div>
</div><!-- /.cashier-container -->

<style>
.kq-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; margin-bottom:10px; transition:all .15s; }
.kq-card:hover { border-color:#7c3aed; }
.kq-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
.kq-priority { font-size:28px; font-weight:900; color:#7c3aed; line-height:1; }
.kq-time { font-size:11px; color:#94a3b8; }
.kq-items { display:flex; flex-wrap:wrap; gap:4px; margin-bottom:10px; }
.kq-chip { padding:3px 8px; background:#ede9fe; border:1px solid #c4b5fd; border-radius:4px; font-size:11px; color:#5b21b6; font-weight:600; }
.kq-footer { display:flex; align-items:center; justify-content:space-between; gap:8px; }
.kq-total { font-size:14px; font-weight:700; color:#1e293b; }
.kq-actions { display:flex; gap:6px; }
.kq-btn { padding:6px 14px; border:none; border-radius:6px; font-size:12px; font-weight:700; cursor:pointer; transition:opacity .12s; }
.kq-btn:hover { opacity:.85; }
.kq-btn-process { background:#7c3aed; color:#fff; }
.kq-btn-cancel { background:#fee2e2; color:#dc2626; }
.kq-empty { text-align:center; padding:30px 20px; color:#94a3b8; }
.kq-empty .icon { font-size:36px; margin-bottom:8px; }
</style>

<script>
    const STORE_INFO = {
        storeName:    <?php echo json_encode($userStore ? $userStore['store_name'] : 'ORO STORE'); ?>,
        storeAddress: <?php echo json_encode($userStore ? $userStore['address'] : ''); ?>,
        cashierName:  <?php echo json_encode($currentUser['full_name']); ?>
    };
</script>
<script src="/oro-store/cashier/cashier_script.js"></script>

<script>
function simulateKey(key) {
    document.dispatchEvent(new KeyboardEvent('keydown', {key: key, bubbles: true}));
}

// Autocomplete tags (runs after cashier_script.js)
try { (function(){
    var allSuggestions = Array.from(__suggestions || []);
    var searchInput = document.getElementById('cashier-search');
    var sugBox = document.getElementById('search-suggestions');
    var tagsBox = document.getElementById('search-tags');
    var activeTags = [];

    searchInput.addEventListener('input', function(){
        var val = this.value.toLowerCase().trim();
        if (val.length < 1) { sugBox.style.display = 'none'; return; }
        var matches = allSuggestions.filter(function(s){ return s.toLowerCase().includes(val) && activeTags.indexOf(s) === -1; });
        if (matches.length === 0) { sugBox.style.display = 'none'; return; }
        sugBox.innerHTML = '';
        matches.slice(0, 8).forEach(function(m){
            var div = document.createElement('div');
            div.textContent = m;
            div.style.cssText = 'padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9;-webkit-user-select:none;user-select:none;';
            div.onmouseenter = function(){ this.style.background='#f0f7ff'; };
            div.onmouseleave = function(){ this.style.background=''; };
            div.onclick = function(e){ e.stopPropagation(); addTag(m); };
            div.ontouchend = function(e){ e.preventDefault(); e.stopPropagation(); addTag(m); };
            sugBox.appendChild(div);
        });
        sugBox.style.display = 'block';
    });

    window.addSearchTag = function(text) { addTag(text); };

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
            span.style.cssText = 'display:inline-flex;align-items:center;gap:4px;padding:4px 10px;background:#6366f1;color:#fff;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;-webkit-user-select:none;user-select:none;';
            span.innerHTML = tag + ' <span style="font-size:10px;opacity:0.7;margin-left:2px;">✕</span>';
            span.onclick = function(){ removeTag(tag); };
            tagsBox.appendChild(span);
        });
    }

    function filterByTags() {
        var query = searchInput.value.toLowerCase().trim();
        var items = document.querySelectorAll('.product-item-cashier');
        var vi = 0;
        items.forEach(function(item){
            var hasUnit = (item.dataset.unit||'').trim() !== '';
            var isChild = hasUnit;
            // For tag matching: child products exclude parent's category, use unit instead
            var searchable = (item.dataset.name||'')+' '+(item.dataset.barcode||'')+' '+(item.dataset.description||'')+' '+(item.dataset.brand||'')+' '+(item.dataset.unit||'');
            if (!isChild) searchable += ' '+(item.dataset.category||'');
            var all = searchable.toLowerCase();
            var tagMatch = activeTags.length === 0 || activeTags.every(function(t){ return all.includes(t.toLowerCase()); });
            var textMatch = query === '' || all.includes(query);
            item.style.setProperty('display', tagMatch && textMatch ? 'flex' : 'none', 'important');
            if (tagMatch && textMatch) { item.dataset.visibleIndex = vi; vi++; }
        });
        selectedProductIndex = 0;
        updateProductSelection();
    }

    // Run tag filter AFTER the original cashier_script.js filter
    searchInput.addEventListener('input', function(){ setTimeout(filterByTags, 50); });

    document.addEventListener('click', function(e){
        if (!e.target.closest('.search-container')) sugBox.style.display = 'none';
    });
})(); } catch(e) { console.error('Autocomplete error:', e); }
// Simple double-tap and long-press using a global click counter
var _lastClickTime = 0;
var _lastClickId = '';
var _lpTimer = null;

function handleProductTap(el) {
    var now = Date.now();
    var id = el.dataset.id;
    if (now - _lastClickTime < 400 && _lastClickId === id) {
        simulateKey('Enter');
        _lastClickTime = 0;
        _lastClickId = '';
    } else {
        _lastClickTime = now;
        _lastClickId = id;
    }
}

function startLongPress(idx) {
    _lpTimer = setTimeout(function() {
        selectedReceiptIndex = parseInt(idx);
        openEditModal(parseInt(idx));
    }, 500);
}

function cancelLongPress() {
    if (_lpTimer) { clearTimeout(_lpTimer); _lpTimer = null; }
}

// Block context menu and text selection on touch (but allow in modals for scrolling)
document.addEventListener('contextmenu', function(e) {
    if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA' && !e.target.closest('.modal')) {
        e.preventDefault();
    }
});
document.addEventListener('selectstart', function(e) {
    if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA' && !e.target.closest('.modal')) {
        e.preventDefault();
    }
});
</script>

<!-- Receipt Modal -->
<div class="modal" id="receipt-modal" style="z-index:700;">
    <div class="modal-content" style="max-width:360px;font-family:'Courier New',monospace;font-size:12px;padding:24px;">
        <div id="receipt-modal-body" style="text-align:center;"></div>
        <div style="text-align:center;margin-top:16px;font-size:11px;color:#94a3b8;">
            Closing in <span id="receipt-countdown" style="font-weight:700;color:#7c3aed;">10</span>s
        </div>
        <div class="modal-buttons" style="margin-top:12px;">
            <button class="btn-cancel" onclick="closeReceiptModal()">New Transaction</button>
        </div>
    </div>
</div>

<style>
.receipt-line { display:flex; justify-content:space-between; padding:1px 0; font-size:12px; }
.receipt-line.bold { font-weight:700; }
.receipt-dash { border-top:1px dashed #cbd5e0; margin:6px 0; }
.receipt-center { text-align:center; }
.receipt-disclaimer { font-size:9px; color:#94a3b8; text-align:center; margin-top:8px; line-height:1.4; }
</style>

<!-- GCash Reference Modal -->
<div class="modal" id="gcash-ref-modal" style="z-index:650;">
    <div class="modal-content" style="max-width:400px;">
        <h2 style="margin-bottom:4px;">Process GCash Order</h2>
        <div id="gcash-ref-info" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;"></div>
        <label style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">GCash Account *</label>
        <select id="gcash-ref-account" style="width:100%;padding:10px;border:2px solid #e2e8f0;border-radius:8px;font-size:13px;outline:none;margin:8px 0 12px;">
            <option value="">Loading accounts...</option>
        </select>
        <label style="font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;">Reference Number *</label>
        <input type="text" id="gcash-ref-input" maxlength="13" placeholder="Enter reference number" inputmode="numeric"
            style="width:100%;padding:12px;border:2px solid #e2e8f0;border-radius:8px;font-size:16px;font-weight:700;outline:none;margin:8px 0 16px;letter-spacing:1px;text-align:center;">
        <div class="modal-buttons">
            <button class="btn-confirm" onclick="confirmGcashProcess()">Process</button>
            <button class="btn-cancel" onclick="closeGcashRefModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Receipt Modal Logic -->
<script>
var _receiptTimer = null;

function showReceiptModal(data) {
    var html = '<div style="font-weight:700;font-size:14px;margin-bottom:2px;">' + escRM(data.storeName || 'ORO STORE') + '</div>';
    if (data.storeAddress) html += '<div style="font-size:10px;color:#64748b;">' + escRM(data.storeAddress) + '</div>';
    html += '<div class="receipt-dash"></div>';
    html += '<div style="font-weight:700;font-size:13px;">' + (data.isReprint ? 'REPRINT' : 'PROOF OF PURCHASE') + '</div>';
    if (data.transactionNumber) html += '<div style="font-size:11px;color:#64748b;">TXN#: ' + escRM(data.transactionNumber) + '</div>';
    html += '<div style="font-size:11px;color:#64748b;">' + escRM(data.date) + '</div>';
    if (data.cashierName) html += '<div style="font-size:11px;color:#64748b;">Cashier: ' + escRM(data.cashierName) + '</div>';
    html += '<div class="receipt-dash"></div>';

    if (data.items && data.items.length) {
        html += '<div style="text-align:left;">';
        data.items.forEach(function(item) {
            html += '<div style="font-weight:600;color:#1e293b;">' + escRM(item.name) + '</div>';
            html += '<div class="receipt-line"><span style="color:#64748b;">&nbsp;&nbsp;' + item.quantity + ' x ₱' + parseFloat(item.price).toFixed(2) + '</span><span>₱' + parseFloat(item.subtotal || item.price * item.quantity).toFixed(2) + '</span></div>';
        });
        html += '</div>';
    }

    html += '<div class="receipt-dash"></div>';
    html += '<div class="receipt-line bold"><span>TOTAL</span><span>₱' + parseFloat(data.total || 0).toFixed(2) + '</span></div>';
    if (data.amountPaid !== undefined) {
        html += '<div class="receipt-line"><span>Paid</span><span>₱' + parseFloat(data.amountPaid).toFixed(2) + '</span></div>';
        html += '<div class="receipt-line"><span>Change</span><span>₱' + parseFloat(data.change || 0).toFixed(2) + '</span></div>';
    }
    html += '<div class="receipt-dash"></div>';
    html += '<div class="receipt-center" style="margin-top:6px;">Thank you!</div>';
    html += '<div class="receipt-disclaimer">THIS IS NOT AN OFFICIAL RECEIPT.<br>THIS SERVES AS PROOF OF PURCHASE ONLY.</div>';

    document.getElementById('receipt-modal-body').innerHTML = html;
    document.getElementById('receipt-modal').classList.add('active');

    // Auto-close countdown
    var seconds = 10;
    var countEl = document.getElementById('receipt-countdown');
    if (countEl) countEl.textContent = seconds;
    if (_receiptTimer) clearInterval(_receiptTimer);
    _receiptTimer = setInterval(function() {
        seconds--;
        if (countEl) countEl.textContent = seconds;
        if (seconds <= 0) closeReceiptModal();
    }, 1000);
}

function closeReceiptModal() {
    if (_receiptTimer) { clearInterval(_receiptTimer); _receiptTimer = null; }
    document.getElementById('receipt-modal').classList.remove('active');
}

function escRM(s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
</script>

<!-- Kiosk Queue Logic -->
<script>
let _kioskQueueInterval = null;
let _activeKioskOrderId = sessionStorage.getItem('_activeKioskOrderId') ? parseInt(sessionStorage.getItem('_activeKioskOrderId')) : null;

function openKioskQueue() {
    document.getElementById('kiosk-queue-modal').classList.add('active');
    loadKioskQueue();
}

function closeKioskQueue() {
    document.getElementById('kiosk-queue-modal').classList.remove('active');
}

function loadKioskQueue() {
    if (typeof OroCache !== 'undefined') {
        var cached = OroCache.get('cashier_queue');
        if (cached) {
            renderKioskQueue(cached);
            updateKioskBadge(cached.length);
        }
    }
    fetch('/oro-store/cashier/cashier.php?action=get_kiosk_orders')
        .then(r => r.json())
        .then(orders => {
            if (typeof OroCache !== 'undefined') OroCache.set('cashier_queue', orders, 15);
            renderKioskQueue(orders);
            updateKioskBadge(orders.length);
        })
        .catch(() => {
            if (!OroCache.get('cashier_queue'))
                document.getElementById('kiosk-queue-list').innerHTML = '<div style="text-align:center;color:#dc2626;padding:20px;font-size:13px;">Failed to load queue</div>';
        });
}

function updateKioskBadge(count) {
    const badge = document.getElementById('kioskQueueCount');
    const modalBadge = document.getElementById('kqCountBadge');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline' : 'none';
    }
    if (modalBadge) modalBadge.textContent = count;
}

function renderKioskQueue(orders) {
    const el = document.getElementById('kiosk-queue-list');
    if (!orders.length) {
        el.innerHTML = '<div class="kq-empty"><div class="icon">📋</div><p>No pending kiosk orders</p></div>';
        return;
    }

    el.innerHTML = orders.map(o => {
        const items = JSON.parse(o.items || '[]');
        const isGcash = (o.payment_method || '').startsWith('gcash_');
        const time = new Date(o.created_at).toLocaleTimeString('en-US', { hour:'2-digit', minute:'2-digit' });
        const isProcessing = o.status === 'processing';

        if (isGcash) {
            const g = items[0] || {};
            const gcashType = g.gcash_type === 'cash_in' ? 'Cash In' : 'Cash Out';
            const custNum = g.gcash_customer_number || '—';
            const ref = g.gcash_reference || '—';
            return `<div class="kq-card" style="border-left:3px solid #3b82f6;${isProcessing ? 'border-color:#fbbf24;background:#fffbeb;' : ''}">
                <div class="kq-top">
                    <span class="kq-priority">#${String(o.priority_number).padStart(3, '0')}</span>
                    <span style="font-size:11px;font-weight:700;color:#3b82f6;">GCASH ${escQ(gcashType.toUpperCase())}</span>
                    <span class="kq-time">${time}${isProcessing ? ' <span style="color:#d97706;font-weight:700;">PROCESSING</span>' : ''}</span>
                </div>
                <div style="display:flex;flex-direction:column;gap:4px;margin:8px 0;padding:8px 10px;background:#eff6ff;border-radius:6px;font-size:12px;">
                    <div><span style="color:#64748b;">Amount:</span> <strong>₱${parseFloat(g.price || 0).toFixed(2)}</strong> <span style="color:#64748b;margin-left:8px;">Fee:</span> <strong style="color:#f59e0b;">₱${parseFloat(g.gcash_fee || 0).toFixed(2)}</strong></div>
                    <div><span style="color:#64748b;">Customer #:</span> <strong>${escQ(custNum)}</strong></div>
                    ${ref && ref !== '—' ? `<div><span style="color:#64748b;">Reference:</span> <strong style="font-family:monospace;">${escQ(ref)}</strong></div>` : ''}
                </div>
                <div class="kq-footer">
                    <span class="kq-total" style="color:#3b82f6;">Total: ₱${parseFloat(o.total_amount).toFixed(2)}</span>
                    <div class="kq-actions">
                        <button class="kq-btn kq-btn-process" onclick="processGcashKioskOrder(${o.id})">Process GCash</button>
                        <button class="kq-btn kq-btn-cancel" onclick="cancelKioskOrder(${o.id}, ${o.priority_number})">Cancel</button>
                    </div>
                </div>
            </div>`;
        }

        const chips = items.slice(0, 6).map(i => `<span class="kq-chip">${escQ(i.name)} x${i.quantity}</span>`).join('');
        const more = items.length > 6 ? `<span class="kq-chip" style="background:#f1f5f9;color:#64748b;">+${items.length - 6} more</span>` : '';
        return `<div class="kq-card" style="${isProcessing ? 'border-color:#fbbf24;background:#fffbeb;' : ''}">
            <div class="kq-top">
                <span class="kq-priority">#${String(o.priority_number).padStart(3, '0')}</span>
                <span class="kq-time">${time}${isProcessing ? ' <span style="color:#d97706;font-weight:700;">PROCESSING</span>' : ''}</span>
            </div>
            <div class="kq-items">${chips}${more}</div>
            <div class="kq-footer">
                <span class="kq-total">₱${parseFloat(o.total_amount).toFixed(2)} &middot; ${o.items_count} item${o.items_count > 1 ? 's' : ''}</span>
                <div class="kq-actions">
                    <button class="kq-btn kq-btn-process" onclick="processKioskOrder(${o.id})">Process</button>
                    <button class="kq-btn kq-btn-cancel" onclick="cancelKioskOrder(${o.id}, ${o.priority_number})">Cancel</button>
                </div>
            </div>
        </div>`;
    }).join('');
}

function escQ(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function processKioskOrder(orderId) {
    const fd = new FormData();
    fd.append('action', 'process_kiosk_order');
    fd.append('order_id', orderId);
    fetch('/oro-store/cashier/cashier.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert('Error: ' + (data.error || 'Failed')); return; }
            const order = data.order;
            const items = JSON.parse(order.items || '[]');
            _activeKioskOrderId = orderId;
            sessionStorage.setItem('_activeKioskOrderId', orderId);

            if (typeof cart !== 'undefined') {
                cart.length = 0;
                items.forEach(item => {
                    cart.push({
                        id: item.id,
                        name: item.name,
                        price: parseFloat(item.price),
                        purchase_price: parseFloat(item.purchase_price),
                        quantity: item.quantity,
                        subtotal: parseFloat(item.subtotal),
                        profit: parseFloat(item.profit || 0),
                        parent_product_id: item.parent_product_id || null
                    });
                });
                if (typeof updateReceipt === 'function') updateReceipt();
                sessionStorage.setItem('cashierCart', JSON.stringify(cart));
            }

            closeKioskQueue();
            if (typeof customAlert === 'function') {
                customAlert('Kiosk order #' + String(order.priority_number).padStart(3, '0') + ' loaded into cart.\nProcess the transaction normally.', 'info');
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

var _pendingGcashOrder = null;
var _gcashAccounts = [];

function _renderGcashAccounts(accounts) {
    _gcashAccounts = accounts;
    var sel = document.getElementById('gcash-ref-account');
    sel.innerHTML = '<option value="">-- Select Account --</option>' +
        accounts.map(a => '<option value="' + a.id + '">' + escQ(a.account_name) + ' (' + escQ(a.phone_number) + ')</option>').join('');
}
function loadGcashAccounts() {
    if (typeof OroCache !== 'undefined') {
        var cached = OroCache.get('cashier_gcash_accounts');
        if (cached) { _renderGcashAccounts(cached); return; }
    }
    fetch('/oro-store/transactions/gcash.php?action=get_accounts')
        .then(r => r.json())
        .then(accounts => {
            if (typeof OroCache !== 'undefined') OroCache.set('cashier_gcash_accounts', accounts, 300);
            _renderGcashAccounts(accounts);
        })
        .catch(() => {});
}
loadGcashAccounts();

function processGcashKioskOrder(orderId) {
    // First fetch the order details to show in the modal
    const fd = new FormData();
    fd.append('action', 'process_kiosk_order');
    fd.append('order_id', orderId);
    fetch('/oro-store/cashier/cashier.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert('Error: ' + (data.error || 'Failed')); return; }
            const order = data.order;
            const items = typeof order.items === 'string' ? JSON.parse(order.items) : (order.items || []);
            const g = items[0] || {};

            _pendingGcashOrder = { orderId: orderId, order: order, gcash: g };

            var typeLabel = (g.gcash_type === 'cash_in') ? 'Cash In' : 'Cash Out';
            var custNum = g.gcash_customer_number || '-';
            var amt = parseFloat(g.price || 0);
            var fee = parseFloat(g.gcash_fee || 0);
            var total = parseFloat(g.gcash_total || 0);

            document.getElementById('gcash-ref-info').innerHTML =
                '<div style="font-weight:700;color:#3b82f6;margin-bottom:6px;">Priority #' + String(order.priority_number).padStart(3, '0') + ' — GCash ' + escQ(typeLabel) + '</div>' +
                '<div>Customer #: <strong>' + escQ(custNum) + '</strong></div>' +
                '<div>Amount: <strong>₱' + amt.toFixed(2) + '</strong> &middot; Fee: <strong>₱' + fee.toFixed(2) + '</strong></div>' +
                '<div style="font-size:14px;font-weight:800;margin-top:4px;">Total: ₱' + total.toFixed(2) + '</div>';

            var refInput = document.getElementById('gcash-ref-input');
            refInput.value = g.gcash_reference || '';
            document.getElementById('gcash-ref-modal').classList.add('active');
            refInput.focus();
        })
        .catch(err => alert('Error: ' + err.message));
}

function closeGcashRefModal() {
    document.getElementById('gcash-ref-modal').classList.remove('active');
    _pendingGcashOrder = null;
}

function confirmGcashProcess() {
    if (!_pendingGcashOrder) return;
    var ref = document.getElementById('gcash-ref-input').value.trim();
    var accountId = document.getElementById('gcash-ref-account').value;
    if (!accountId) { alert('Please select a GCash account'); return; }
    if (!ref) { alert('Please enter the reference number'); document.getElementById('gcash-ref-input').focus(); return; }

    var g = _pendingGcashOrder.gcash;
    var orderId = _pendingGcashOrder.orderId;
    var order = _pendingGcashOrder.order;

    const gfd = new FormData();
    gfd.append('action', 'complete_gcash');
    gfd.append('type', g.gcash_type || 'cash_in');
    gfd.append('amount', parseFloat(g.price || 0));
    gfd.append('fee', parseFloat(g.gcash_fee || 0));
    gfd.append('total', parseFloat(g.gcash_total || 0));
    gfd.append('reference', ref);
    gfd.append('gcash_account_id', accountId);
    if (g.gcash_customer_number) gfd.append('customer_number', g.gcash_customer_number);

    fetch('/oro-store/transactions/gcash.php', { method: 'POST', body: gfd })
    .then(r => r.text())
    .then(text => {
        console.log('GCash response:', text);
        var gdata;
        try { gdata = JSON.parse(text); } catch(e) { alert('GCash response error:\n' + text.substring(0, 300)); return; }
        if (gdata.success) {
            if (typeof OroCache !== 'undefined') { OroCache.invalidate('cashier_queue'); OroCache.invalidatePrefix('gcash_history'); }
            const cfd = new FormData();
            cfd.append('action', 'complete_kiosk_order');
            cfd.append('order_id', orderId);
            cfd.append('transaction_id', gdata.transaction_id);
            fetch('/oro-store/cashier/cashier.php', { method: 'POST', body: cfd });

            closeGcashRefModal();
            closeKioskQueue();
            customAlert('GCash transaction completed!\nRef: ' + gdata.reference_number + '\nKiosk order #' + String(order.priority_number).padStart(3, '0') + ' done.', 'success', function(){ loadKioskQueue(); });
        } else {
            alert('GCash error: ' + (gdata.error || 'Failed'));
        }
    }).catch(err => alert('GCash fetch error: ' + err.message));
}

function cancelKioskOrder(orderId, priorityNum) {
    if (!confirm('Cancel kiosk order #' + String(priorityNum).padStart(3, '0') + '?')) return;
    const fd = new FormData();
    fd.append('action', 'cancel_kiosk_order');
    fd.append('order_id', orderId);
    fetch('/oro-store/cashier/cashier.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) { if (typeof OroCache !== 'undefined') OroCache.invalidate('cashier_queue'); loadKioskQueue(); }
            else alert('Error cancelling order');
        });
}

// Poll for kiosk orders every 30 seconds
function pollKioskQueue() {
    fetch('/oro-store/cashier/cashier.php?action=get_kiosk_orders')
        .then(r => r.json())
        .then(orders => {
            if (typeof OroCache !== 'undefined') OroCache.set('cashier_queue', orders, 15);
            updateKioskBadge(orders.length);
        })
        .catch(() => {});
}
setInterval(pollKioskQueue, 30000);
pollKioskQueue();

// Cloud stock sync — pull other stores' stock every 30 seconds
function cloudStockPull() {
    fetch('/oro-store/sync/cloud_pull.php').then(function(){ if (typeof refreshAllStocks === 'function') refreshAllStocks(); }).catch(function(){});
}
setInterval(cloudStockPull, 30000);
setTimeout(cloudStockPull, 5000);

// F8 keyboard shortcut
document.addEventListener('keydown', function(e) {
    if (e.key === 'F8') {
        e.preventDefault();
        const modal = document.getElementById('kiosk-queue-modal');
        if (modal.classList.contains('active')) closeKioskQueue();
        else openKioskQueue();
    }
});
</script>
</body>
</html>