<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/sync_helper.php';
require_once __DIR__ . '/../core/transaction_helper.php';
require_once __DIR__ . '/../core/system_logger.php';

$db = new SyncDB();
$currentUser = getCurrentUser();

$userStore = null;
if ($currentUser['store_id'] && !isAdmin()) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $currentUser['store_id']);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
}

// Ensure delivery_batches table exists
$conn->query("CREATE TABLE IF NOT EXISTS delivery_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_number VARCHAR(50) NOT NULL,
    delivery_ids JSON NOT NULL,
    total_items INT NOT NULL DEFAULT 0,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('pending','on_delivery','completed','cancelled') DEFAULT 'pending',
    created_by INT DEFAULT NULL,
    store_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL
)");

// POST: Save delivery batch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_batch') {
    header('Content-Type: application/json');
    try {
        $delivery_ids = json_decode($_POST['delivery_ids'], true);
        $total_items = intval($_POST['total_items']);
        $total_amount = floatval($_POST['total_amount']);
        $store_id = $userStore ? $userStore['id'] : null;
        if (!$delivery_ids || !count($delivery_ids)) throw new Exception('No deliveries selected');

        $today = date('Y-m-d');
        $r = $conn->query("SELECT COUNT(*) as c FROM delivery_batches WHERE DATE(created_at) = '$today'" . ($store_id ? " AND store_id = $store_id" : ""));
        $batch_num = 'BATCH-' . date('md') . '-' . str_pad(($r->fetch_assoc()['c'] + 1), 3, '0', STR_PAD_LEFT);

        $ids_json = json_encode($delivery_ids);
        $stmt = $conn->prepare("INSERT INTO delivery_batches (batch_number, delivery_ids, total_items, total_amount, created_by, store_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssidii", $batch_num, $ids_json, $total_items, $total_amount, $currentUser['id'], $store_id);
        $stmt->execute();
        $batch_id = $conn->insert_id;
        $stmt->close();

        // Tag deliveries as on_delivery
        $id_list = implode(',', array_map('intval', $delivery_ids));
        $conn->query("UPDATE deliveries SET status = 'on_delivery' WHERE id IN ($id_list) AND status IN ('pending','lacking')");

        logActivity('delivery', "Delivery batch created: $batch_num ($total_items items)", $currentUser['id'], $store_id, [
            'batch_id' => $batch_id, 'batch_number' => $batch_num, 'delivery_count' => count($delivery_ids)
        ]);

        echo json_encode(['success' => true, 'batch_id' => $batch_id, 'batch_number' => $batch_num]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// POST: Remove order from batch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_from_batch') {
    header('Content-Type: application/json');
    $batch_id = intval($_POST['batch_id']);
    $delivery_id = intval($_POST['delivery_id']);
    $batch = $conn->query("SELECT * FROM delivery_batches WHERE id = $batch_id")->fetch_assoc();
    if (!$batch) { echo json_encode(['success' => false, 'error' => 'Batch not found']); $conn->close(); exit; }
    $ids = json_decode($batch['delivery_ids'], true);
    $ids = array_values(array_filter($ids, function($id) use ($delivery_id) { return $id != $delivery_id; }));
    $conn->query("UPDATE delivery_batches SET delivery_ids = '" . $conn->real_escape_string(json_encode($ids)) . "' WHERE id = $batch_id");
    $conn->query("UPDATE deliveries SET status = 'pending' WHERE id = $delivery_id AND status = 'on_delivery'");
    if (empty($ids)) { $conn->query("UPDATE delivery_batches SET status = 'cancelled' WHERE id = $batch_id"); }
    echo json_encode(['success' => true]);
    $conn->close(); exit;
}

// POST: Complete batch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_batch') {
    header('Content-Type: application/json');
    $batch_id = intval($_POST['batch_id']);
    $batch = $conn->query("SELECT * FROM delivery_batches WHERE id = $batch_id")->fetch_assoc();
    if (!$batch) { echo json_encode(['success' => false, 'error' => 'Batch not found']); $conn->close(); exit; }
    $ids = json_decode($batch['delivery_ids'], true);
    if ($ids && count($ids)) {
        $id_list = implode(',', array_map('intval', $ids));
        $conn->query("UPDATE deliveries SET status = 'completed', completed_at = NOW() WHERE id IN ($id_list)");
        $conn->query("UPDATE delivery_items SET status = 'completed' WHERE delivery_id IN ($id_list)");
        // Also complete the linked transactions
        $conn->query("UPDATE transactions SET status = 'completed' WHERE id IN (SELECT transaction_id FROM deliveries WHERE id IN ($id_list))");
    }
    $conn->query("UPDATE delivery_batches SET status = 'completed', completed_at = NOW() WHERE id = $batch_id");
    logActivity('delivery', "Batch completed: {$batch['batch_number']}", $currentUser['id'], $userStore ? $userStore['id'] : null, ['batch_id' => $batch_id]);
    echo json_encode(['success' => true]);
    $conn->close(); exit;
}

// POST: Cancel batch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_batch') {
    header('Content-Type: application/json');
    $batch_id = intval($_POST['batch_id']);
    $batch = $conn->query("SELECT * FROM delivery_batches WHERE id = $batch_id")->fetch_assoc();
    if (!$batch) { echo json_encode(['success' => false, 'error' => 'Batch not found']); $conn->close(); exit; }
    $ids = json_decode($batch['delivery_ids'], true);
    if ($ids && count($ids)) {
        $id_list = implode(',', array_map('intval', $ids));
        $conn->query("UPDATE deliveries SET status = 'pending' WHERE id IN ($id_list) AND status = 'on_delivery'");
    }
    $conn->query("UPDATE delivery_batches SET status = 'cancelled' WHERE id = $batch_id");
    echo json_encode(['success' => true]);
    $conn->close(); exit;
}

// GET: List saved batches
if (isset($_GET['action']) && $_GET['action'] === 'get_batches') {
    header('Content-Type: application/json');
    $sc = $userStore ? " AND db.store_id = " . intval($userStore['id']) : "";
    $r = $conn->query("SELECT db.*, u.full_name as created_by_name FROM delivery_batches db LEFT JOIN users u ON db.created_by = u.id WHERE db.status IN ('pending','on_delivery') $sc ORDER BY db.created_at DESC LIMIT 20");
    echo json_encode($r ? $r->fetch_all(MYSQLI_ASSOC) : []);
    $conn->close(); exit;
}

// GET: Load batch details (returns delivery data for the batch)
if (isset($_GET['action']) && $_GET['action'] === 'load_batch') {
    header('Content-Type: application/json');
    $batch_id = intval($_GET['batch_id']);
    $stmt = $conn->prepare("SELECT * FROM delivery_batches WHERE id = ?");
    $stmt->bind_param("i", $batch_id); $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$batch) { echo json_encode(['success' => false, 'error' => 'Batch not found']); $conn->close(); exit; }

    $ids = json_decode($batch['delivery_ids'], true);
    $deliveries = [];
    if ($ids && count($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $conn->prepare("SELECT d.id as delivery_id, d.status, d.recipient_name, d.recipient_address, d.created_at,
            t.transaction_number, t.transaction_date, t.total_amount,
            COUNT(di.id) as item_count,
            GROUP_CONCAT(di.product_name, ' x', di.quantity_ordered ORDER BY di.id SEPARATOR '||') as items_summary,
            GROUP_CONCAT(di.product_name, ':::', di.quantity_ordered, ':::', COALESCE(ti.price, 0) ORDER BY di.id SEPARATOR '|||') as items_detail
            FROM deliveries d
            INNER JOIN transactions t ON d.transaction_id = t.id
            LEFT JOIN delivery_items di ON d.id = di.delivery_id AND di.is_deleted = 0
            LEFT JOIN transaction_items ti ON ti.transaction_id = t.id AND ti.product_id = di.product_id
            WHERE d.id IN ($placeholders) AND d.is_deleted = 0
            GROUP BY d.id ORDER BY d.recipient_name, d.created_at");
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $deliveries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    echo json_encode(['success' => true, 'batch' => $batch, 'deliveries' => $deliveries]);
    $conn->close(); exit;
}

// Ensure delivery_batches status supports all states
$conn->query("ALTER TABLE delivery_batches MODIFY COLUMN status ENUM('pending','on_delivery','completed','cancelled') DEFAULT 'pending'");

// Ensure delivery status supports 'on_delivery'
$conn->query("ALTER TABLE deliveries MODIFY COLUMN status ENUM('pending','completed','lacking','cancelled','on_delivery') DEFAULT 'pending'");

// Ensure delivery_charge_categories table exists
$conn->query("CREATE TABLE IF NOT EXISTS delivery_charge_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(255) NOT NULL,
    charge_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// ─── AJAX: Get delivery charge categories ─────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_delivery_charges') {
    header('Content-Type: application/json');
    $sql = "SELECT pc.id, pc.category_name,
                   COALESCE(dcc.charge_amount, 0.00) AS charge_amount,
                   COALESCE(dcc.id, 0) AS charge_id
            FROM product_categories pc
            LEFT JOIN delivery_charge_categories dcc
                ON dcc.category_name = pc.category_name AND dcc.is_deleted = 0
            WHERE pc.is_deleted = 0
            ORDER BY pc.category_name ASC";
    $res = $conn->query($sql);
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $indSql = "SELECT DISTINCT p.individual_sell_unit AS unit_name
               FROM products p
               WHERE p.parent_product_id IS NOT NULL AND p.is_deleted = 0
               AND p.individual_sell_unit IS NOT NULL AND p.individual_sell_unit != ''";
    $indRes = $conn->query($indSql);
    $units = $indRes ? $indRes->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($units as $u) {
        $uName = ucfirst($u['unit_name']);
        $already = false;
        foreach ($rows as $r) { if (strcasecmp($r['category_name'], $uName) === 0) { $already = true; break; } }
        if (!$already) {
            $chk = $conn->query("SELECT id, charge_amount FROM delivery_charge_categories WHERE category_name = '" . $conn->real_escape_string($uName) . "' AND is_deleted = 0");
            $ex = $chk ? $chk->fetch_assoc() : null;
            $rows[] = ['id' => 0, 'category_name' => $uName, 'charge_amount' => $ex ? (float)$ex['charge_amount'] : 0, 'charge_id' => $ex ? (int)$ex['id'] : 0];
        }
    }
    echo json_encode($rows);
    $conn->close(); exit;
}

// ─── POST: Save delivery charge ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_delivery_charge') {
    header('Content-Type: application/json');
    try {
        $charge_id = intval($_POST['charge_id'] ?? 0);
        $category_name = trim($_POST['category_name'] ?? '');
        $charge_amount = floatval($_POST['charge_amount'] ?? 0);
        if (!$category_name) throw new Exception('Category name is required');
        if ($charge_id > 0) {
            $stmt = $conn->prepare("UPDATE delivery_charge_categories SET charge_amount = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
            $stmt->bind_param("di", $charge_amount, $charge_id);
            $stmt->execute(); $stmt->close();
        } else {
            $stmt = $conn->prepare("SELECT id FROM delivery_charge_categories WHERE category_name = ? AND is_deleted = 0");
            $stmt->bind_param("s", $category_name); $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($existing) {
                $stmt = $conn->prepare("UPDATE delivery_charge_categories SET charge_amount = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("di", $charge_amount, $existing['id']);
                $stmt->execute(); $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO delivery_charge_categories (category_name, charge_amount, is_deleted) VALUES (?, ?, 0)");
                $stmt->bind_param("sd", $category_name, $charge_amount);
                $stmt->execute(); $stmt->close();
            }
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// POST: Delete delivery charge category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_delivery_charge') {
    header('Content-Type: application/json');
    $charge_id = intval($_POST['charge_id'] ?? 0);
    if ($charge_id > 0) {
        $conn->query("UPDATE delivery_charge_categories SET is_deleted = 1 WHERE id = $charge_id");
    }
    echo json_encode(['success' => true]);
    $conn->close(); exit;
}

// Get deliveries for a specific recipient
if (isset($_GET['action']) && $_GET['action'] === 'get_recipient_deliveries') {
    header('Content-Type: application/json');
    try {
        $recipient_name = $_GET['recipient_name'];
        $storeClause = $userStore ? " AND d.store_id = {$userStore['id']}" : "";
        $query = "SELECT d.id as delivery_id, d.status, d.recipient_address, d.created_at,
                         t.transaction_number, t.transaction_date, t.total_amount,
                         COUNT(di.id) as item_count,
                         GROUP_CONCAT(di.product_name, ' x', di.quantity_ordered ORDER BY di.id SEPARATOR '||') as items_summary,
                         GROUP_CONCAT(di.product_name, ':::', di.quantity_ordered, ':::', COALESCE(ti.price, 0) ORDER BY di.id SEPARATOR '|||') as items_detail
                  FROM deliveries d
                  INNER JOIN transactions t ON d.transaction_id = t.id
                  LEFT JOIN delivery_items di ON d.id = di.delivery_id AND di.is_deleted = 0
                  LEFT JOIN transaction_items ti ON ti.transaction_id = t.id AND ti.product_id = di.product_id
                  WHERE d.recipient_name = ? AND d.is_deleted = 0 $storeClause
                  GROUP BY d.id
                  ORDER BY d.created_at DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $recipient_name);
        $stmt->execute();
        $deliveries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        // Attach batch info to on_delivery orders
        $batches = $conn->query("SELECT id, batch_number, delivery_ids FROM delivery_batches WHERE status IN ('pending','on_delivery')");
        $batchMap = [];
        if ($batches) {
            while ($b = $batches->fetch_assoc()) {
                $bids = json_decode($b['delivery_ids'], true);
                if ($bids) { foreach ($bids as $bid) { $batchMap[$bid] = $b['batch_number']; } }
            }
        }
        foreach ($deliveries as &$del) {
            $del['batch_number'] = $batchMap[$del['delivery_id']] ?? null;
        }
        unset($del);
        echo json_encode($deliveries);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// Get delivery details
if (isset($_GET['action']) && $_GET['action'] === 'get_delivery') {
    header('Content-Type: application/json');
    try {
        $delivery_id = intval($_GET['delivery_id']);
        $query = "SELECT di.*, d.recipient_name, d.recipient_address, d.status as delivery_status,
                  t.transaction_number, t.total_amount, t.id as transaction_id
                  FROM delivery_items di
                  INNER JOIN deliveries d ON di.delivery_id = d.id
                  INNER JOIN transactions t ON d.transaction_id = t.id
                  WHERE di.delivery_id = ? AND di.is_deleted = 0";
        if ($userStore) $query .= " AND d.store_id = ?";
        $stmt = $conn->prepare($query);
        if ($userStore) $stmt->bind_param("ii", $delivery_id, $userStore['id']);
        else $stmt->bind_param("i", $delivery_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($items as &$item) {
            $item['id']                 = (int)$item['id'];
            $item['delivery_id']        = (int)$item['delivery_id'];
            $item['product_id']         = (int)$item['product_id'];
            $item['quantity_ordered']   = (int)$item['quantity_ordered'];
            $item['quantity_delivered'] = (int)$item['quantity_delivered'];
            $item['quantity_lacking']   = (int)$item['quantity_lacking'];
            $item['total_amount']       = (float)$item['total_amount'];
            $item['transaction_id']     = (int)$item['transaction_id'];
            $item['recipient_name']     = $item['recipient_name'] ?? '';
            $item['recipient_address']  = $item['recipient_address'] ?? '';
            $item['transaction_number'] = $item['transaction_number'] ?? '';
        }
        echo json_encode($items);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// Mark lacking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_lacking') {
    header('Content-Type: application/json');
    $item_id     = intval($_POST['item_id']);
    $lacking_qty = intval($_POST['lacking_qty']);
    try {
        $db->update('delivery_items', [
            'quantity_lacking'   => $lacking_qty,
            'quantity_delivered' => "quantity_ordered - $lacking_qty",
            'status'             => $lacking_qty > 0 ? 'lacking' : 'pending'
        ], "id = $item_id");
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    $conn->close(); exit;
}

// Update quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_quantity') {
    header('Content-Type: application/json');
    $item_id = intval($_POST['item_id']);
    $new_qty  = intval($_POST['new_qty']);
    try {
        $db->update('delivery_items', ['quantity_delivered' => $new_qty], "id = $item_id");
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'error' => $e->getMessage()]); }
    $conn->close(); exit;
}

// Mark complete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_complete') {
    header('Content-Type: application/json');
    $delivery_id = intval($_POST['delivery_id']);
    $conn->begin_transaction();
    try {
        // Get delivery info before updating
        $stmt = $conn->prepare("SELECT d.*, t.total_amount, t.transaction_number
            FROM deliveries d LEFT JOIN transactions t ON d.transaction_id = t.id
            WHERE d.id = ?");
        $stmt->bind_param("i", $delivery_id);
        $stmt->execute();
        $delivery = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $db->update('deliveries', ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')], "id = $delivery_id");
        $db->update('delivery_items', ['status' => 'completed'], "delivery_id = $delivery_id");

        if ($delivery) {
            $conn->prepare("UPDATE transactions SET status = 'completed' WHERE id = ?")->execute([$delivery['transaction_id']]);

            logActivity('delivery', "Delivery marked complete: {$delivery['recipient_name']}", $currentUser['id'], $delivery['store_id'], [
                'delivery_id' => $delivery_id, 'amount' => floatval($delivery['total_amount'] ?? 0)
            ]);
        }

        $conn->commit();
        if (function_exists('flushStockPushes')) flushStockPushes();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// Fetch recipients (grouped)
$storeClause = $userStore ? " AND d.store_id = " . intval($userStore['id']) : "";
$query = "SELECT
    d.recipient_name,
    d.recipient_address,
    COUNT(d.id)                                             AS total_deliveries,
    SUM(CASE WHEN d.status='pending'      THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN d.status='completed'   THEN 1 ELSE 0 END) AS completed_count,
    SUM(CASE WHEN d.status='lacking'     THEN 1 ELSE 0 END) AS lacking_count,
    SUM(CASE WHEN d.status='on_delivery' THEN 1 ELSE 0 END) AS on_delivery_count,
    COALESCE(SUM(t.total_amount), 0)                       AS total_amount,
    COALESCE(SUM(CASE WHEN d.status='completed' THEN t.total_amount ELSE 0 END), 0) AS completed_amount,
    COALESCE(SUM(CASE WHEN d.status!='completed' THEN t.total_amount ELSE 0 END), 0) AS pending_amount,
    MAX(d.created_at)                                       AS last_delivery_date
FROM deliveries d
LEFT JOIN transactions t ON d.transaction_id = t.id
WHERE d.is_deleted = 0 $storeClause
GROUP BY d.recipient_name, d.recipient_address
ORDER BY last_delivery_date DESC";
$result = $conn->query($query);
$recipients = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Delivery Details<?php echo $userStore ? ' — ' . htmlspecialchars($userStore['store_name']) : ''; ?></title>
<link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
<style>
    /* ── Page Layout ── */
    .dd-container {
        max-width: 1100px;
        margin: 0 auto;
        padding: 24px 20px;
    }
    .dd-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 12px;
    }
    .dd-header h1 {
        font-size: 20px;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    .dd-header-right {
        display: flex;
        gap: 8px;
    }
    .btn {
        padding: 8px 18px;
        border: none;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: opacity .15s;
    }
    .btn:hover { opacity: .85; }
    .btn-primary   { background: #667eea; color: #fff; }
    .btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }
    .btn-success   { background: #22c55e; color: #fff; }
    .btn-warn      { background: #f59e0b; color: #fff; }
    .btn-danger    { background: #ef4444; color: #fff; }

    /* ── Toolbar ── */
    .dd-toolbar {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }
    .search-wrap {
        position: relative;
        flex: 1;
        max-width: 340px;
    }
    .search-wrap input {
        width: 100%;
        padding: 8px 12px 8px 34px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 13px;
        outline: none;
        background: #fff;
        color: #1e293b;
        transition: border .15s;
    }
    .search-wrap input:focus { border-color: #667eea; }
    .search-wrap::before {
        content: '\2315';
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 16px;
        pointer-events: none;
    }
    .filter-group { display: flex; gap: 4px; }
    .filter-btn {
        padding: 7px 14px;
        font-size: 12px;
        font-weight: 600;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        color: #64748b;
        border-radius: 6px;
        cursor: pointer;
        transition: all .15s;
    }
    .filter-btn.active, .filter-btn:hover { background: #667eea; color: #fff; border-color: #667eea; }
    .filter-btn[data-filter="pending"].active   { background: #f59e0b; border-color: #f59e0b; }
    .filter-btn[data-filter="on_delivery"].active { background: #2563eb; border-color: #2563eb; }
    .filter-btn[data-filter="completed"].active { background: #22c55e; border-color: #22c55e; }
    .result-count { margin-left: auto; font-size: 12px; color: #94a3b8; }

    /* ── List ── */
    .dd-list {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
    }
    .dd-list-head {
        display: grid;
        grid-template-columns: 2fr 1.2fr 1.2fr 80px 1fr 1fr 1fr;
        padding: 10px 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        font-size: 11px;
        font-weight: 700;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: .6px;
    }
    .dd-row {
        display: grid;
        grid-template-columns: 2fr 1.2fr 1.2fr 80px 1fr 1fr 1fr;
        padding: 14px 20px;
        border-bottom: 1px solid #f1f5f9;
        align-items: center;
        cursor: pointer;
        transition: background .12s;
        position: relative;
    }
    .dd-row:last-child { border-bottom: none; }
    .dd-row:hover { background: #f8fafc; }
    .dd-row.selected { background: #eff6ff; }
    .dd-row.hidden { display: none; }
    .dd-row::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        border-radius: 3px 0 0 3px;
    }
    .dd-row.status-pending::before      { background: #f59e0b; }
    .dd-row.status-on_delivery::before { background: #2563eb; }
    .dd-row.status-completed::before   { background: #22c55e; }

    .row-name { font-size: 13px; font-weight: 700; color: #1e293b; }
    .row-name small { font-size: 11px; font-weight: 400; color: #94a3b8; margin-left: 6px; }
    .row-contact { font-size: 12px; color: #64748b; }
    .row-address { font-size: 12px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .row-credits { font-size: 12px; color: #94a3b8; text-align: center; }
    .row-amount  { font-size: 13px; font-weight: 700; color: #22c55e; text-align: right; }
    .row-due     { font-size: 13px; font-weight: 700; color: #ef4444; text-align: right; }
    .row-date    { font-size: 12px; color: #64748b; }

    .status-badge {
        display: inline-block;
        padding: 3px 9px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 700;
        text-align: center;
        letter-spacing: .4px;
    }
    .badge-pending   { background: #fef3c7; color: #d97706; }
    .badge-completed { background: #dcfce7; color: #16a34a; }
    .badge-on_delivery { background: #dbeafe; color: #2563eb; }
    .badge-cancelled { background: #fee2e2; color: #dc2626; }
    .badge-lacking   { background: #fee2e2; color: #dc2626; }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #94a3b8;
    }
    .empty-state h3 { margin: 10px 0 6px; font-size: 16px; }

    /* ── Modal (shared) ── */
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.45);
        backdrop-filter: blur(3px);
        z-index: 500;
        align-items: center;
        justify-content: center;
    }
    .modal.active { display: flex; }
    .modal-box {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 28px;
        width: 620px;
        max-width: 95vw;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0,0,0,.15);
    }
    .modal-title {
        font-size: 16px;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 18px;
        padding-bottom: 14px;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .modal-title .dim { font-size: 12px; color: #94a3b8; font-weight: 400; }
    .modal-info {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-bottom: 18px;
        padding: 14px;
        background: #f8fafc;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }
    .modal-info-row { display: flex; gap: 10px; font-size: 13px; }
    .modal-info-label { color: #94a3b8; min-width: 80px; flex-shrink: 0; }
    .modal-info-value { color: #1e293b; font-weight: 500; }
    .modal-btns { display: flex; gap: 10px; }
    .modal-btns button {
        flex: 1;
        padding: 10px;
        border: none;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: opacity .15s;
    }
    .modal-btns button:hover { opacity: .85; }

    /* Items table inside modal */
    .items-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        margin-bottom: 16px;
    }
    .items-table thead tr {
        background: #f8fafc;
        border-bottom: 2px solid #e2e8f0;
    }
    .items-table th {
        padding: 9px 12px;
        text-align: left;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: #94a3b8;
    }
    .items-table td {
        padding: 10px 12px;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
    }
    .items-table tbody tr { cursor: pointer; transition: background .1s; }
    .items-table tbody tr:hover { background: #f8fafc; }
    .items-table tbody tr.selected { background: #eff6ff; outline: 1px solid #bfdbfe; }

    .lacking-badge {
        display: inline-block;
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fca5a5;
        border-radius: 3px;
        padding: 1px 6px;
        font-size: 10px;
        font-weight: 700;
        margin-left: 6px;
    }

    /* Delivery info grid in detail modal */
    .delivery-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 16px;
    }
    .delivery-info-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 12px;
    }
    .delivery-info-box .label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: #94a3b8;
        font-weight: 700;
        margin-bottom: 4px;
    }
    .delivery-info-box .value {
        font-size: 15px;
        font-weight: 700;
        color: #1e293b;
    }

    .modal-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 16px;
        background: #f0fdf4;
        border-radius: 8px;
        border: 1px solid #bbf7d0;
        margin-bottom: 16px;
    }
    .modal-total-label { font-size: 13px; color: #64748b; }
    .modal-total-value { font-size: 17px; font-weight: 700; color: #16a34a; }

    /* field inputs in modals */
    .field-label {
        font-size: 11px;
        color: #94a3b8;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        margin-bottom: 6px;
    }
    .field-input {
        width: 100%;
        padding: 9px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 13px;
        outline: none;
        margin-bottom: 16px;
        color: #1e293b;
        transition: border .15s;
    }
    .field-input:focus { border-color: #667eea; }

    /* ── Drawer ── */
    .drawer-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.45);
        backdrop-filter: blur(3px);
        z-index: 200;
        opacity: 0;
        pointer-events: none;
        transition: opacity .25s;
    }
    .drawer-overlay.open { opacity: 1; pointer-events: all; }
    .drawer {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        width: 700px;
        max-width: 95vw;
        background: #fff;
        border-left: 1px solid #e2e8f0;
        z-index: 201;
        transform: translateX(100%);
        transition: transform .3s cubic-bezier(.4,0,.2,1);
        display: flex;
        flex-direction: column;
    }
    .drawer.open { transform: translateX(0); }
    .drawer-head {
        padding: 20px 24px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        flex-shrink: 0;
        background: #f8fafc;
    }
    .drawer-head-info h2 { font-size: 16px; font-weight: 700; color: #1e293b; margin-bottom: 3px; }
    .drawer-head-info p { font-size: 12px; color: #64748b; }
    .drawer-close {
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        color: #64748b;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all .15s;
    }
    .drawer-close:hover { background: #ef4444; color: #fff; border-color: #ef4444; }
    .drawer-stats {
        display: flex;
        gap: 1px;
        background: #e2e8f0;
        border-bottom: 1px solid #e2e8f0;
        flex-shrink: 0;
    }
    .drawer-stat {
        flex: 1;
        padding: 12px 16px;
        background: #fff;
        display: flex;
        flex-direction: column;
        gap: 3px;
    }
    .drawer-stat-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: #94a3b8;
        font-weight: 700;
    }
    .drawer-stat-value { font-size: 15px; font-weight: 700; }
    .drawer-body { flex: 1; overflow-y: auto; padding: 0; }
    .receipt-card {
        border-bottom: 1px solid #f1f5f9;
        padding: 16px 24px;
        cursor: pointer;
        transition: background .1s;
    }
    .receipt-card:hover { background: #f8fafc; }
    .receipt-card.receipt-selected { background: #eff6ff; border-left: 3px solid #667eea; }
    .receipt-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 6px;
    }
    .receipt-txn { font-size: 13px; color: #667eea; font-weight: 700; }
    .receipt-date { font-size: 12px; color: #94a3b8; }
    .receipt-amounts { display: flex; gap: 16px; margin-bottom: 8px; flex-wrap: wrap; }
    .receipt-amt-item { display: flex; flex-direction: column; gap: 2px; }
    .receipt-amt-label {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: #94a3b8;
        font-weight: 700;
    }
    .receipt-amt-value { font-size: 13px; font-weight: 700; color: #1e293b; }
    .receipt-items-wrap { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
    .item-chip {
        padding: 2px 8px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        font-size: 11px;
        color: #64748b;
    }
    .drawer-loading {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 200px;
        color: #94a3b8;
        font-size: 13px;
        gap: 10px;
    }
    .spinner {
        width: 18px;
        height: 18px;
        border: 2px solid #e2e8f0;
        border-top-color: #667eea;
        border-radius: 50%;
        animation: spin .7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    .drawer-foot {
        padding: 14px 24px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        gap: 10px;
        flex-shrink: 0;
        background: #f8fafc;
    }

    /* ── Main Layout ── */
    .dd-main-wrap { display: flex; gap: 20px; align-items: flex-start; }
    .dd-main-wrap .dd-list-col { flex: 1; min-width: 0; }

    /* ── Selected Orders Panel ── */
    .selected-panel {
        width: 440px; flex-shrink: 0; background: #fff;
        border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden;
        display: flex; flex-direction: column; max-height: calc(100vh - 180px);
        position: sticky; top: 80px;
    }
    .selected-panel-head {
        padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
        display: flex; align-items: center; justify-content: space-between;
        flex-shrink: 0;
    }
    .selected-panel-head h3 {
        font-size: 13px; font-weight: 700; color: #1e293b; margin: 0;
        display: flex; align-items: center; gap: 8px;
    }
    .selected-panel-head .sel-count {
        background: #667eea; color: #fff; font-size: 11px; font-weight: 700;
        padding: 2px 8px; border-radius: 10px; min-width: 20px; text-align: center;
    }
    .selected-panel-head .btn-clear-all {
        font-size: 11px; color: #ef4444; background: none; border: none;
        cursor: pointer; font-weight: 600; padding: 4px 8px; border-radius: 4px;
        transition: background .12s;
    }
    .selected-panel-head .btn-clear-all:hover { background: #fef2f2; }
    .selected-panel-body {
        flex: 1; overflow-y: auto; min-height: 120px;
    }
    .selected-panel-empty {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        padding: 40px 20px; color: #94a3b8; text-align: center; gap: 6px;
    }
    .selected-panel-empty .icon { font-size: 32px; }
    .selected-panel-empty p { font-size: 12px; margin: 0; }

    .sel-customer-group { border-bottom: 1px solid #e2e8f0; }
    .sel-customer-group:last-child { border-bottom: none; }
    .sel-customer-header {
        padding: 8px 14px; background: #f1f5f9; font-size: 11px; font-weight: 700;
        color: #475569; text-transform: uppercase; letter-spacing: .5px;
        border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 1;
    }
    .sel-order-card {
        padding: 10px 14px; border-bottom: 1px solid #f8fafc;
        position: relative;
    }
    .sel-order-card:last-child { border-bottom: none; }
    .sel-order-top {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 6px;
    }
    .sel-order-txn { font-size: 12px; color: #667eea; font-weight: 700; }
    .sel-order-date { font-size: 11px; color: #94a3b8; }
    .sel-order-remove {
        position: absolute; top: 8px; right: 10px;
        background: none; border: none; color: #cbd5e0; cursor: pointer;
        font-size: 14px; padding: 2px 6px; border-radius: 4px;
        transition: all .12s; line-height: 1;
    }
    .sel-order-remove:hover { color: #ef4444; background: #fef2f2; }
    .sel-order-items { display: flex; flex-direction: column; gap: 2px; }
    .sel-order-item {
        display: flex; justify-content: space-between;
        font-size: 12px; color: #475569; padding: 1px 0;
    }
    .sel-order-item .qty { font-weight: 700; color: #1e293b; min-width: 30px; text-align: right; }

    /* Totals footer */
    .selected-panel-totals {
        border-top: 2px solid #e2e8f0; background: #f8fafc; flex-shrink: 0;
        max-height: 200px; overflow-y: auto;
    }
    .totals-header {
        padding: 10px 14px 6px; font-size: 11px; font-weight: 700;
        color: #64748b; text-transform: uppercase; letter-spacing: .6px;
    }
    .totals-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: 4px 14px; font-size: 12px; color: #334155;
    }
    .totals-row .product-name { flex: 1; }
    .totals-row .product-qty {
        font-weight: 700; color: #1e293b; min-width: 40px; text-align: right;
    }
    .totals-grand {
        display: flex; justify-content: space-between; align-items: center;
        padding: 8px 14px; font-size: 13px; font-weight: 700; color: #1e293b;
        border-top: 1px solid #e2e8f0; background: #eef2ff;
    }
    .totals-grand .product-qty { color: #667eea; font-size: 14px; }

    /* ── Fee Button + Modal ── */
    .btn-fees {
        padding: 7px 14px; font-size: 12px; font-weight: 600;
        background: #f1f5f9; border: 1px solid #e2e8f0; color: #64748b;
        border-radius: 6px; cursor: pointer; transition: all .15s;
        display: inline-flex; align-items: center; gap: 5px;
    }
    .btn-fees:hover { background: #667eea; color: #fff; border-color: #667eea; }
    .fee-modal-box {
        background: #fff; border-radius: 12px; padding: 24px;
        width: 460px; max-width: 95vw; max-height: 80vh; overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0,0,0,.15);
    }
    .fee-modal-title {
        font-size: 16px; font-weight: 700; color: #1e293b;
        margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;
    }
    .fee-list { max-height: 400px; overflow-y: auto; }
    .fee-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: 10px 14px; border-bottom: 1px solid #f1f5f9;
        font-size: 13px; cursor: pointer; transition: background .1s;
    }
    .fee-row:last-child { border-bottom: none; }
    .fee-row:hover { background: #f8fafc; }
    .fee-cat { color: #334155; font-weight: 500; }
    .fee-amt { color: #667eea; font-weight: 700; }
    .fee-amt.zero { color: #94a3b8; font-weight: 400; }

    /* ── Drawer checkbox ── */
    .receipt-card-select {
        display: flex; align-items: flex-start; gap: 10px;
    }
    .order-checkbox {
        width: 20px; height: 20px; flex-shrink: 0; margin-top: 2px;
        accent-color: #667eea; cursor: pointer;
    }
    .receipt-card-content { flex: 1; min-width: 0; }

    @media (max-width: 900px) {
        .dd-main-wrap { flex-direction: column; }
        .selected-panel { width: 100%; max-height: 50vh; position: static; }
    }
    /* Receipt modal */
    .dd-receipt-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); backdrop-filter:blur(3px); z-index:600; align-items:center; justify-content:center; }
    .dd-receipt-modal.active { display:flex; }
    .dd-receipt-box { background:#fff; border-radius:12px; padding:24px; width:380px; max-width:95vw; max-height:85vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.15); font-family:'Courier New',monospace; font-size:12px; }
    .dd-receipt-line { display:flex; justify-content:space-between; padding:1px 0; font-size:11px; }
    .dd-receipt-line.bold { font-weight:700; }
    .dd-receipt-dash { border-top:1px dashed #cbd5e0; margin:6px 0; }
    .dd-receipt-dash.thick { border-top:2px solid #1e293b; }
    .dd-receipt-center { text-align:center; }
    .dd-receipt-disclaimer { font-size:9px; color:#94a3b8; text-align:center; margin-top:8px; line-height:1.4; }
    .dd-receipt-customer { font-weight:700; font-size:13px; color:#1e293b; margin:8px 0 4px; padding-top:6px; border-top:2px solid #1e293b; }
    .dd-receipt-txn { font-size:10px; color:#64748b; margin-bottom:2px; }
</style>
<script src="/oro-store/core/custom_alert.js"></script>
<script src="/oro-store/core/bt_print.js?v=20250627"></script>
</head>
<body>
<?php
if (isAdmin()) { include_once __DIR__ . '/../admin/admin_sidebar.php'; }
elseif ($currentUser['role'] === 'manager') { include_once __DIR__ . '/../manager/manager_sidebar.php'; }
else { ?>
<style>.cashier-topnav{display:flex;align-items:center;gap:10px;padding:10px 16px;background:#1a1a2e;position:fixed;top:0;left:0;right:0;z-index:100;}
.cashier-topnav a{color:#fff;text-decoration:none;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;background:#334155;transition:background .15s;}
.cashier-topnav a:hover{background:#475569;} .cashier-topnav a.active{background:#6366f1;}
.cashier-topnav .nav-title{color:#94a3b8;font-size:12px;margin-right:auto;font-weight:600;}
.main-content{margin-left:0 !important;padding-top:56px !important;}</style>
<div class="cashier-topnav">
    <span class="nav-title"><?php echo htmlspecialchars($currentUser['full_name']); ?> &middot; Cashier</span>
    <a href="/oro-store/cashier/cashier.php">Cashier</a>
    <a href="/oro-store/credit/credit_details.php">Credit</a>
    <a href="/oro-store/delivery/delivery_details.php" class="active">Delivery</a>
    <a href="/oro-store/angkat/angkat_details.php">Angkat</a>
</div>
<?php }
?>

<main class="main-content">
    <div class="dd-container">

        <!-- Header -->
        <div class="dd-header">
            <h1>Delivery Details<?php echo $userStore ? ' <span style="font-size:14px;font-weight:500;color:#64748b">&mdash; ' . htmlspecialchars($userStore['store_name']) . '</span>' : ''; ?></h1>
            <div class="dd-header-right">
                <button class="btn btn-secondary" onclick="location.href='/oro-store/delivery/delivery.php'">+ New Delivery</button>
                <button class="btn btn-primary" onclick="location.href='/oro-store/cashier/cashier.php'">&larr; Cashier</button>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="dd-toolbar">
            <div class="search-wrap">
                <input type="text" id="searchInput" placeholder="Search recipient, address..." autofocus>
            </div>
            <div class="filter-group">
                <button class="filter-btn active" data-filter="all"       onclick="setFilter(this,'all')">All</button>
                <button class="filter-btn"         data-filter="pending"   onclick="setFilter(this,'pending')">Pending</button>
                <button class="filter-btn"         data-filter="on_delivery" onclick="setFilter(this,'on_delivery')">On Delivery</button>
                <button class="filter-btn"         data-filter="completed" onclick="setFilter(this,'completed')">Completed</button>
            </div>
            <button class="btn-fees" onclick="openFeeModal()">&#9881; Delivery Fees</button>
            <div class="result-count" id="resultCount"></div>
        </div>

        <!-- List + Fee Table -->
        <div class="dd-main-wrap">
        <div class="dd-list-col">
        <?php if (empty($recipients)): ?>
            <div class="empty-state">
                <div style="font-size:48px;">📦</div>
                <h3>No delivery records found<?php echo $userStore ? ' for ' . htmlspecialchars($userStore['store_name']) : ''; ?></h3>
                <p>Create a new delivery to get started.</p>
            </div>
        <?php else: ?>
        <div class="dd-list">
            <div class="dd-list-head">
                <div>Recipient</div>
                <div>Address</div>
                <div>Status</div>
                <div style="text-align:center">Deliveries</div>
                <div style="text-align:right">Completed</div>
                <div style="text-align:right">Pending</div>
                <div>Last Date</div>
            </div>
            <?php foreach ($recipients as $i => $r):
                $hasOnDelivery = ($r['on_delivery_count'] ?? 0) > 0;
                $hasPending = ($r['pending_count'] + $r['lacking_count']) > 0;
                if ($hasOnDelivery) { $displayStatus = 'on_delivery'; }
                elseif ($hasPending) { $displayStatus = 'pending'; }
                else { $displayStatus = 'completed'; }
            ?>
            <div class="dd-row status-<?php echo $displayStatus; ?>"
                 data-index="<?php echo $i; ?>"
                 data-status="<?php echo $displayStatus; ?>"
                 data-name="<?php echo htmlspecialchars($r['recipient_name']); ?>"
                 data-addr="<?php echo htmlspecialchars($r['recipient_address'] ?? ''); ?>"
                 data-pending="<?php echo $r['pending_amount']; ?>"
                 onclick="openRecipientDrawer('<?php echo addslashes(htmlspecialchars($r['recipient_name'])); ?>', '<?php echo addslashes(htmlspecialchars($r['recipient_address'] ?? '')); ?>')">
                <div>
                    <div class="row-name"><?php echo htmlspecialchars($r['recipient_name']); ?></div>
                </div>
                <div class="row-address"><?php echo htmlspecialchars($r['recipient_address'] ?? '&mdash;'); ?></div>
                <div>
                    <?php if ($displayStatus === 'completed'): ?>
                        <span class="status-badge badge-completed">Completed</span>
                    <?php elseif ($displayStatus === 'on_delivery'): ?>
                        <span class="status-badge badge-on_delivery">On Delivery</span>
                    <?php else: ?>
                        <span class="status-badge badge-pending">Pending</span>
                    <?php endif; ?>
                </div>
                <div class="row-credits"><?php echo (int)$r['total_deliveries']; ?> delivery<?php echo $r['total_deliveries'] != 1 ? 's' : ''; ?></div>
                <?php if ($displayStatus === 'completed'): ?>
                    <div class="row-amount" style="color:#16a34a">Completed</div>
                    <div class="row-due" style="color:#94a3b8">&mdash;</div>
                <?php else: ?>
                    <div class="row-amount"><?php echo number_format($r['completed_amount'],2); ?></div>
                    <div class="row-due"><?php echo number_format($r['pending_amount'],2); ?></div>
                <?php endif; ?>
                <div class="row-date"><?php echo $r['last_delivery_date'] ? date('M d, Y', strtotime($r['last_delivery_date'])) : '&mdash;'; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </div><!-- /.dd-list-col -->

        <!-- Selected Orders Panel -->
        <div class="selected-panel" id="selectedPanel">
            <div class="selected-panel-head">
                <h3>Selected Orders <span class="sel-count" id="selCount">0</span></h3>
                <div style="display:flex;gap:6px;align-items:center;">
                    <button onclick="openBatchModal()" style="font-size:11px;color:#667eea;background:none;border:1px solid #667eea;cursor:pointer;font-weight:600;padding:4px 10px;border-radius:4px;transition:all .12s;">📦 Load</button>
                    <button id="btnPrintSelected" onclick="printSelectedOrders()" style="display:none;font-size:11px;color:#667eea;background:none;border:1px solid #667eea;cursor:pointer;font-weight:600;padding:4px 10px;border-radius:4px;transition:all .12s;">🖨️ Print</button>
                    <button class="btn-clear-all" id="btnClearAll" onclick="clearAllSelections()" style="display:none">Clear All</button>
                </div>
            </div>
            <div class="selected-panel-body" id="selBody">
                <div class="selected-panel-empty">
                    <div class="icon">📋</div>
                    <p>No orders selected</p>
                    <p style="font-size:11px;color:#cbd5e0;">Open a customer and check orders to add them here</p>
                    <button onclick="openBatchModal()" style="margin-top:12px;padding:8px 20px;background:#667eea;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;">Load Saved Batch</button>
                </div>
            </div>
            <div class="selected-panel-totals" id="selTotals" style="display:none"></div>
        </div>
        </div><!-- /.dd-main-wrap -->

    </div><!-- /.dd-container -->
</main>

<!-- Recipient Drawer -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="recipientDrawer">
    <div class="drawer-head">
        <div class="drawer-head-info">
            <h2 id="drawerName">&mdash;</h2>
            <p id="drawerAddr" style="margin-top:2px">&mdash;</p>
        </div>
        <button class="drawer-close" onclick="closeDrawer()">&times;</button>
    </div>
    <div class="drawer-stats">
        <div class="drawer-stat">
            <span class="drawer-stat-label">Total</span>
            <span class="drawer-stat-value" id="dStatTotal" style="color:#1e293b">&mdash;</span>
        </div>
        <div class="drawer-stat">
            <span class="drawer-stat-label">Pending</span>
            <span class="drawer-stat-value" id="dStatPending" style="color:#f59e0b">&mdash;</span>
        </div>
        <div class="drawer-stat">
            <span class="drawer-stat-label">Completed</span>
            <span class="drawer-stat-value" id="dStatCompleted" style="color:#22c55e">&mdash;</span>
        </div>
        <div class="drawer-stat">
            <span class="drawer-stat-label">Deliveries</span>
            <span class="drawer-stat-value" id="dStatCount" style="color:#667eea">&mdash;</span>
        </div>
    </div>
    <div class="drawer-body" id="drawerBody">
        <div class="drawer-loading"><div class="spinner"></div> Loading deliveries...</div>
    </div>
    <div class="drawer-foot">
        <button class="btn btn-success" id="drawerMarkAllBtn" onclick="markAllCompleteForRecipient()" style="display:none">&#10003; Mark All Pending as Complete</button>
    </div>
</div>

<!-- Details Modal -->
<div class="modal" id="detailsModal">
    <div class="modal-box">
        <div class="modal-title" id="modalTitle">Delivery Details</div>
        <div id="detailsContent"></div>
        <div class="modal-btns">
            <button id="completeBtn" style="background:#22c55e;color:#fff" onclick="showCompleteConfirmation()">&#10003; Mark Complete</button>
            <button style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0" onclick="closeDetailsModal()">Close</button>
        </div>
    </div>
</div>

<!-- Lacking Modal -->
<div class="modal" id="lackingModal">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-title">Mark as Lacking</div>
        <div class="field-label">Product</div>
        <div id="lackingProductName" style="font-size:13px;font-weight:600;margin-bottom:12px;padding:10px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0"></div>
        <div class="field-label">Ordered Qty: <span id="lackingOrderedQty" style="color:#1e293b;font-weight:600"></span></div>
        <br>
        <div class="field-label">Lacking Quantity</div>
        <input type="number" class="field-input" id="lackingInput" min="0" placeholder="Enter lacking quantity">
        <div class="modal-btns">
            <button style="background:#ef4444;color:#fff" onclick="confirmLacking()">Confirm</button>
            <button style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0" onclick="closeLackingModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Edit Quantity Modal -->
<div class="modal" id="editQtyModal">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-title">Edit Delivered Quantity</div>
        <div class="field-label">Product</div>
        <div id="editProductName" style="font-size:13px;font-weight:600;margin-bottom:12px;padding:10px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0"></div>
        <div class="field-label">Ordered: <span id="editOrderedQty" style="color:#1e293b;font-weight:600"></span></div>
        <br>
        <div class="field-label">Delivered Quantity</div>
        <input type="number" class="field-input" id="editQtyInput" min="0" placeholder="Enter delivered quantity">
        <div class="modal-btns">
            <button style="background:#667eea;color:#fff" onclick="confirmEditQty()">Confirm</button>
            <button style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0" onclick="closeEditQtyModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Complete Confirmation Modal -->
<div class="modal" id="completeModal">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-title">Mark as Complete?</div>
        <p style="font-size:13px;color:#64748b;margin-bottom:24px">This action cannot be undone. All items will be marked as delivered.</p>
        <div class="modal-btns">
            <button style="background:#22c55e;color:#fff" onclick="confirmComplete()">Yes, Complete</button>
            <button style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0" onclick="closeCompleteModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="dd-receipt-modal" id="dd-receipt-modal">
    <div class="dd-receipt-box">
        <div id="dd-receipt-body"></div>
        <div style="text-align:center;margin-top:12px;font-size:11px;color:#94a3b8;">
            Closing in <span id="dd-receipt-countdown" style="font-weight:700;color:#667eea;">10</span>s
        </div>
        <div style="text-align:center;margin-top:10px;">
            <button onclick="closeDDReceiptModal()" style="padding:8px 24px;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<!-- Batch Modal -->
<div class="modal" id="batchModal">
    <div class="modal-box" style="width:500px;">
        <div class="modal-title">Load Saved Batch</div>
        <div id="batchList" style="max-height:60vh;overflow-y:auto;">
            <div style="padding:20px;text-align:center;color:#94a3b8;font-size:13px;">Loading...</div>
        </div>
        <div class="modal-btns" style="margin-top:14px;">
            <button style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0" onclick="closeBatchModal()">Close</button>
        </div>
    </div>
</div>

<!-- Fee Modal -->
<div class="modal" id="feeModal">
    <div class="fee-modal-box">
        <div class="fee-modal-title">Delivery Fees by Category</div>
        <div class="fee-list" id="feeList">
            <div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Loading...</div>
        </div>
        <div style="margin-top:16px;text-align:right;">
            <button class="btn btn-secondary" onclick="closeFeeModal()">Close</button>
        </div>
    </div>
</div>

<script>
const DD_STORE_NAME = <?php echo json_encode($userStore ? $userStore['store_name'] : 'ORO STORE'); ?>;
let selectedRowIndex = 0;
let selectedCardIdx = 0;
let currentFilter = 'all';
let currentDeliveryId = null;
let currentDeliveryItems = [];
let currentItemId = null;
let currentRecipientName = null;
let drawerDeliveries = [];

// ── Order selection state ──
// Map: delivery_id -> { delivery_id, recipient_name, transaction_number, date, total_amount, items: [{product_name, quantity}] }
let selectedOrders = new Map();

document.addEventListener('DOMContentLoaded', () => {
    setupSearch();
    updateRowSelection();
    updateResultCount();
    loadFees();
});

// ── Helpers ──
function fmt(n) { return parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(s) { if(!s) return '—'; return new Date(s).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function ucFirst(str) { return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''; }

function parseItemsSummary(summary) {
    if (!summary) return [];
    return summary.split('||').filter(Boolean).map(s => {
        const match = s.match(/^(.+?)\s+x(\d+)$/);
        if (match) return { product_name: match[1].trim(), quantity: parseInt(match[2]), price: 0 };
        return { product_name: s.trim(), quantity: 1, price: 0 };
    });
}

function parseItemsDetail(detail) {
    if (!detail) return [];
    return detail.split('|||').filter(Boolean).map(s => {
        const parts = s.split(':::');
        return {
            product_name: (parts[0] || '').trim(),
            quantity: parseInt(parts[1] || 1),
            price: parseFloat(parts[2] || 0)
        };
    });
}

// ── Search & Filter ──
function setupSearch() {
    document.getElementById('searchInput').addEventListener('input', applyFilter);
}
function setFilter(btn, filter) {
    currentFilter = filter;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilter();
}
function applyFilter() {
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    let visible = 0;
    document.querySelectorAll('.dd-row').forEach(row => {
        const matchStatus = currentFilter === 'all' || row.dataset.status === currentFilter;
        const matchQuery  = !q ||
            row.dataset.name.toLowerCase().includes(q) ||
            row.dataset.addr.toLowerCase().includes(q);
        const show = matchStatus && matchQuery;
        row.classList.toggle('hidden', !show);
        if (show) visible++;
    });
    selectedRowIndex = 0;
    updateRowSelection();
    updateResultCount(visible);
}
function updateResultCount(count) {
    const rows  = count !== undefined ? count : document.querySelectorAll('.dd-row:not(.hidden)').length;
    const total = document.querySelectorAll('.dd-row').length;
    document.getElementById('resultCount').textContent = rows === total ? `${total} records` : `${rows} of ${total}`;
}

// ── Row Selection ──
function visibleRows() { return Array.from(document.querySelectorAll('.dd-row:not(.hidden)')); }
function updateRowSelection() {
    const rows = visibleRows();
    rows.forEach((r, i) => r.classList.toggle('selected', i === selectedRowIndex));
    if (rows[selectedRowIndex]) rows[selectedRowIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}
function openSelectedRow() {
    const row = visibleRows()[selectedRowIndex];
    if (row) openRecipientDrawer(row.dataset.name, row.dataset.addr);
}

// ── Drawer card selection ──
function cardElements() {
    return Array.from(document.querySelectorAll('#drawerBody .receipt-card'));
}
function updateCardSelection() {
    cardElements().forEach((c, i) => c.classList.toggle('receipt-selected', i === selectedCardIdx));
    const card = cardElements()[selectedCardIdx];
    if (card) card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}
function openSelectedCard() {
    const d = drawerDeliveries[selectedCardIdx];
    if (d) viewDeliveryDetails(d.delivery_id);
}

// ── Recipient Drawer ──
function openRecipientDrawer(name, addr) {
    currentRecipientName = name;
    selectedCardIdx = 0;
    document.getElementById('drawerName').textContent = name;
    document.getElementById('drawerAddr').textContent = addr ? addr : '';
    ['dStatTotal','dStatPending','dStatCompleted','dStatCount'].forEach(sid => document.getElementById(sid).textContent = '—');
    document.getElementById('drawerMarkAllBtn').style.display = 'none';
    document.getElementById('drawerBody').innerHTML = '<div class="drawer-loading"><div class="spinner"></div> Loading…</div>';
    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('recipientDrawer').classList.add('open');

    fetch(`?action=get_recipient_deliveries&recipient_name=${encodeURIComponent(name)}`)
        .then(r => r.json())
        .then(data => { drawerDeliveries = data; renderDrawer(data); setTimeout(updateCardSelection, 30); })
        .catch(() => { document.getElementById('drawerBody').innerHTML = '<div class="drawer-loading">Failed to load.</div>'; });
}

function renderDrawer(deliveries) {
    if (!deliveries.length) {
        document.getElementById('drawerBody').innerHTML = '<div class="drawer-loading">No deliveries found.</div>';
        return;
    }
    const totalAmount     = deliveries.reduce((s,d) => s + parseFloat(d.total_amount||0), 0);
    const pendingAmount   = deliveries.filter(d => d.status !== 'completed').reduce((s,d) => s + parseFloat(d.total_amount||0), 0);
    const completedAmount = deliveries.filter(d => d.status === 'completed').reduce((s,d) => s + parseFloat(d.total_amount||0), 0);
    document.getElementById('dStatTotal').textContent     = '₱' + fmt(totalAmount);
    document.getElementById('dStatPending').textContent   = '₱' + fmt(pendingAmount);
    document.getElementById('dStatCompleted').textContent = '₱' + fmt(completedAmount);
    document.getElementById('dStatCount').textContent     = deliveries.length;
    const hasPending = deliveries.some(d => d.status === 'pending' || d.status === 'lacking');
    document.getElementById('drawerMarkAllBtn').style.display = hasPending ? '' : 'none';

    document.getElementById('drawerBody').innerHTML = deliveries.map((d, idx) => {
        const items = (d.items_summary || '').split('||').filter(Boolean);
        const chips = items.slice(0,5).map(i => `<span class="item-chip">${esc(i)}</span>`).join('');
        const more  = items.length > 5 ? `<span class="item-chip">+${items.length-5} more</span>` : '';
        const isComplete = d.status === 'completed';
        const isCancelled = d.status === 'cancelled';
        const isOnDelivery = d.status === 'on_delivery';
        const isSelectable = !isComplete && !isCancelled && !isOnDelivery;
        const isSelected = selectedOrders.has(d.delivery_id);
        const batchLabel = d.batch_number ? '<div style="font-size:9px;color:#2563eb;font-weight:700;margin-top:2px;">📦 ' + esc(d.batch_number) + '</div>' : '';
        return `<div class="receipt-card" data-idx="${idx}">
            <div class="receipt-card-select">
                <input type="checkbox" class="order-checkbox" data-delivery-id="${d.delivery_id}"
                    ${isSelected ? 'checked' : ''}
                    ${!isSelectable ? 'disabled style="opacity:0.3;cursor:not-allowed;"' : ''}
                    onclick="event.stopPropagation(); toggleOrderSelection(${idx})" title="${isSelectable ? 'Select for loading list' : (isOnDelivery ? 'Already in batch ' + (d.batch_number||'') : 'Cannot select ' + d.status + ' orders')}">
                <div class="receipt-card-content" onclick="viewDeliveryDetails(${d.delivery_id})">
                    <div class="receipt-top">
                        <span class="receipt-txn">#${esc(d.transaction_number)}</span>
                        <span class="receipt-date">${fmtDate(d.transaction_date || d.created_at)}</span>
                    </div>
                    ${isComplete ? `
                    <div class="receipt-amounts">
                        <div class="receipt-amt-item"><span class="receipt-amt-label">Status</span><span class="receipt-amt-value" style="color:#16a34a;font-weight:700">Completed</span></div>
                    </div>
                    ` : `
                    <div class="receipt-amounts">
                        <div class="receipt-amt-item"><span class="receipt-amt-label">Total</span><span class="receipt-amt-value">₱${fmt(d.total_amount)}</span></div>
                        <div class="receipt-amt-item"><span class="receipt-amt-label">Items</span><span class="receipt-amt-value">${d.item_count || 0}</span></div>
                        <div class="receipt-amt-item"><span class="receipt-amt-label">Status</span><span class="status-badge badge-${d.status === 'on_delivery' ? 'on_delivery' : (d.status === 'lacking' ? 'lacking' : 'pending')}">${d.status === 'on_delivery' ? 'On Delivery' : ucFirst(d.status)}</span></div>
                    </div>
                    `}
                    ${batchLabel}
                    <div class="receipt-items-wrap">${chips}${more}</div>
                </div>
            </div>
        </div>`;
    }).join('');
}

function closeDrawer() {
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('recipientDrawer').classList.remove('open');
    updateRowSelection();
}

// ── Order Selection ──
function toggleOrderSelection(drawerIdx) {
    const d = drawerDeliveries[drawerIdx];
    if (!d) return;
    if (d.status === 'completed' || d.status === 'cancelled') return;
    const id = d.delivery_id;
    if (selectedOrders.has(id)) {
        selectedOrders.delete(id);
    } else {
        selectedOrders.set(id, {
            delivery_id: d.delivery_id,
            recipient_name: d.recipient_name || currentRecipientName,
            transaction_number: d.transaction_number,
            date: d.transaction_date || d.created_at,
            total_amount: parseFloat(d.total_amount || 0),
            status: d.status,
            items: d.items_detail ? parseItemsDetail(d.items_detail) : parseItemsSummary(d.items_summary)
        });
    }
    renderDrawer(drawerDeliveries);
    setTimeout(updateCardSelection, 30);
    renderSelectedPanel();
}

function removeSelectedOrder(deliveryId) {
    selectedOrders.delete(deliveryId);
    renderSelectedPanel();
    if (document.getElementById('recipientDrawer').classList.contains('open')) {
        renderDrawer(drawerDeliveries);
        setTimeout(updateCardSelection, 30);
    }
}

function clearAllSelections() {
    selectedOrders.clear();
    renderSelectedPanel();
    if (document.getElementById('recipientDrawer').classList.contains('open')) {
        renderDrawer(drawerDeliveries);
        setTimeout(updateCardSelection, 30);
    }
}

// ── Selected Orders Panel ──
function renderSelectedPanel() {
    const body = document.getElementById('selBody');
    const totals = document.getElementById('selTotals');
    const countEl = document.getElementById('selCount');
    const clearBtn = document.getElementById('btnClearAll');

    var printBtn = document.getElementById('btnPrintSelected');

    countEl.textContent = selectedOrders.size;
    clearBtn.style.display = selectedOrders.size > 0 && !_currentBatchId ? '' : 'none';
    if (printBtn) printBtn.style.display = selectedOrders.size > 0 ? '' : 'none';

    if (selectedOrders.size === 0) {
        _currentBatchId = null;
        _currentBatchNumber = null;
        body.innerHTML = `<div class="selected-panel-empty">
            <div class="icon">📋</div>
            <p>No orders selected</p>
            <p style="font-size:11px;color:#cbd5e0;">Open a customer and check orders to add them here</p>
            <button onclick="openBatchModal()" style="margin-top:12px;padding:8px 20px;background:#667eea;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;">Load Saved Batch</button>
        </div>`;
        totals.style.display = 'none';
        return;
    }

    // Group by recipient
    const groups = new Map();
    selectedOrders.forEach(order => {
        const name = order.recipient_name;
        if (!groups.has(name)) groups.set(name, []);
        groups.get(name).push(order);
    });

    let html = '';
    // Batch header
    if (_currentBatchId) {
        html += `<div style="padding:8px 14px;background:#eef2ff;border-bottom:1px solid #c7d2fe;display:flex;align-items:center;justify-content:space-between;">
            <span style="font-size:12px;font-weight:700;color:#667eea;">📦 ${esc(_currentBatchNumber)}</span>
            <div style="display:flex;gap:4px;">
                <button onclick="completeBatch()" style="font-size:10px;padding:4px 10px;background:#22c55e;color:#fff;border:none;border-radius:4px;font-weight:700;cursor:pointer;" title="Mark all as delivered">✓ Complete</button>
                <button onclick="cancelBatch()" style="font-size:10px;padding:4px 10px;background:#ef4444;color:#fff;border:none;border-radius:4px;font-weight:700;cursor:pointer;" title="Cancel batch">✕ Cancel</button>
            </div>
        </div>`;
    }
    let grandAmount = 0;
    groups.forEach((orders, recipientName) => {
        let customerAmount = 0;
        html += `<div class="sel-customer-group">`;
        html += `<div class="sel-customer-header">${esc(recipientName)} (${orders.length})</div>`;
        orders.forEach(order => {
            html += `<div class="sel-order-card">
                <button class="sel-order-remove" onclick="${_currentBatchId ? 'removeFromBatch(' + order.delivery_id + ')' : 'removeSelectedOrder(' + order.delivery_id + ')'}" title="Remove">&times;</button>
                <div class="sel-order-top">
                    <span class="sel-order-txn">#${esc(order.transaction_number)}</span>
                    <span class="sel-order-date">${fmtDate(order.date)}</span>
                </div>
                <div class="sel-order-items">`;
            order.items.forEach(item => {
                const price = parseFloat(item.price || 0);
                const lineTotal = price * item.quantity;
                customerAmount += lineTotal;
                html += `<div class="sel-order-item">
                    <span>${esc(item.product_name)}</span>
                    <span class="qty" style="white-space:nowrap;">${price > 0 ? '₱' + price.toFixed(2) + ' x' + item.quantity + ' = ₱' + lineTotal.toFixed(2) : 'x' + item.quantity}</span>
                </div>`;
            });
            html += `</div></div>`;
        });
        grandAmount += customerAmount;
        if (customerAmount > 0) {
            html += `<div style="padding:6px 14px;background:#f0fdf4;border-top:1px solid #bbf7d0;font-size:12px;font-weight:700;display:flex;justify-content:space-between;color:#16a34a;">
                <span>Subtotal</span><span>₱${customerAmount.toFixed(2)}</span>
            </div>`;
        }
        html += `</div>`;
    });
    body.innerHTML = html;

    // Compute product totals
    const productTotals = new Map();
    let grandTotal = 0;
    selectedOrders.forEach(order => {
        order.items.forEach(item => {
            const existing = productTotals.get(item.product_name) || 0;
            productTotals.set(item.product_name, existing + item.quantity);
            grandTotal += item.quantity;
        });
    });

    if (productTotals.size > 0) {
        let totalsHtml = '<div class="totals-header">Product Totals</div>';
        const sorted = Array.from(productTotals.entries()).sort((a, b) => a[0].localeCompare(b[0]));
        sorted.forEach(([name, qty]) => {
            totalsHtml += `<div class="totals-row">
                <span class="product-name">${esc(name)}</span>
                <span class="product-qty">${qty}</span>
            </div>`;
        });
        totalsHtml += `<div class="totals-grand">
            <span>Total Items</span>
            <span class="product-qty">${grandTotal}</span>
        </div>`;
        if (grandAmount > 0) {
            totalsHtml += `<div class="totals-grand" style="background:#f0fdf4;border-top:1px solid #bbf7d0;">
                <span style="color:#16a34a;">Total Amount</span>
                <span class="product-qty" style="color:#16a34a;font-size:15px;">₱${grandAmount.toFixed(2)}</span>
            </div>`;
        }
        totalsHtml += `<div style="padding:8px 14px;display:flex;gap:6px;">
            <button onclick="saveBatch()" style="flex:1;padding:8px;background:#667eea;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;">Save Batch</button>
        </div>`;
        totals.innerHTML = totalsHtml;
        totals.style.display = 'block';
    } else {
        totals.style.display = 'none';
    }
}

// ── Mark All Complete for Recipient ──
function markAllCompleteForRecipient() {
    const pendingIds = drawerDeliveries.filter(d => d.status !== 'completed').map(d => d.delivery_id);
    if (!pendingIds.length || !confirm(`Mark ${pendingIds.length} delivery(ies) as complete?`)) return;

    let completed = 0;
    pendingIds.forEach(id => {
        const fd = new FormData();
        fd.append('action', 'mark_complete');
        fd.append('delivery_id', id);
        fetch('/oro-store/delivery/delivery_details.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                completed++;
                if (completed === pendingIds.length) {
                    closeDrawer();
                    location.reload();
                }
            });
    });
}

// ── View Delivery Details (Modal) ──
function viewDeliveryDetails(deliveryId) {
    currentDeliveryId = deliveryId;
    document.getElementById('detailsModal').classList.add('active');
    document.getElementById('detailsContent').innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px">Loading…</div>';
    document.getElementById('modalTitle').innerHTML = 'Delivery Details';

    fetch(`/oro-store/delivery/delivery_details.php?action=get_delivery&delivery_id=${deliveryId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.length) {
                document.getElementById('detailsContent').innerHTML = '<p style="color:#94a3b8;padding:20px">No items found.</p>';
                return;
            }
            currentDeliveryItems = data;
            const first  = data[0];
            const status = first.delivery_status || 'pending';
            const statusBadge = status === 'completed' ? 'badge-completed' : (status === 'lacking' ? 'badge-lacking' : 'badge-pending');

            document.getElementById('modalTitle').innerHTML =
                `#${esc(first.transaction_number)} <span class="dim">· ${esc(first.recipient_name)}</span>
                 <span class="status-badge ${statusBadge}" style="margin-left:6px">${ucFirst(status)}</span>`;

            document.getElementById('completeBtn').style.display = status === 'completed' ? 'none' : '';

            const totalOrdered   = data.reduce((s,i) => s + i.quantity_ordered, 0);
            const totalDelivered = data.reduce((s,i) => s + i.quantity_delivered, 0);
            const totalLacking   = data.reduce((s,i) => s + i.quantity_lacking, 0);

            let html = `
                <div class="delivery-info-grid">
                    <div class="delivery-info-box"><div class="label">Total Amount</div><div class="value">₱${fmt(first.total_amount)}</div></div>
                    <div class="delivery-info-box"><div class="label">Items Ordered</div><div class="value">${totalOrdered}</div></div>
                    <div class="delivery-info-box"><div class="label">Items Delivered</div><div class="value" style="color:#22c55e">${totalDelivered}</div></div>
                    ${totalLacking > 0 ? `<div class="delivery-info-box"><div class="label">Items Lacking</div><div class="value" style="color:#ef4444">${totalLacking}</div></div>` : ''}
                </div>
                <div class="modal-info">
                    <div class="modal-info-row"><span class="modal-info-label">Recipient</span><span class="modal-info-value">${esc(first.recipient_name)}</span></div>
                    <div class="modal-info-row"><span class="modal-info-label">Address</span><span class="modal-info-value">${esc(first.recipient_address)}</span></div>
                </div>`;

            html += `<table class="items-table"><thead><tr>
                <th>Product</th>
                <th style="text-align:center">Ordered</th>
                <th style="text-align:center">Delivered</th>
                <th style="text-align:center">Lacking</th>
                <th style="text-align:center">Actions</th>
            </tr></thead><tbody>`;

            data.forEach((item, i) => {
                const lb = item.quantity_lacking > 0 ? `<span class="lacking-badge">−${item.quantity_lacking}</span>` : '';
                html += `<tr class="product-item-row" data-index="${i}" data-item-id="${item.id}">
                    <td>${esc(item.product_name)}${lb}</td>
                    <td style="text-align:center">${item.quantity_ordered}</td>
                    <td style="text-align:center;color:#16a34a;font-weight:600">${item.quantity_delivered}</td>
                    <td style="text-align:center;color:${item.quantity_lacking > 0 ? '#dc2626' : '#94a3b8'}">${item.quantity_lacking > 0 ? item.quantity_lacking : '—'}</td>
                    <td style="text-align:center">
                        ${status !== 'completed' ? `
                        <button onclick="openLackingModal(${i})" style="padding:3px 8px;font-size:11px;background:#fef3c7;color:#d97706;border:1px solid #fde68a;border-radius:4px;cursor:pointer;margin-right:4px;">Lacking</button>
                        <button onclick="openEditQtyModal(${i})" style="padding:3px 8px;font-size:11px;background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;border-radius:4px;cursor:pointer;">Edit Qty</button>
                        ` : '—'}
                    </td>
                </tr>`;
            });

            html += `</tbody></table>
            <div class="modal-total">
                <span class="modal-total-label">Total Amount</span>
                <span class="modal-total-value">₱${fmt(first.total_amount)}</span>
            </div>`;

            document.getElementById('detailsContent').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('detailsContent').innerHTML = '<p style="color:#dc2626;padding:20px">Error loading delivery details.</p>';
        });
}

function closeDetailsModal() {
    document.getElementById('detailsModal').classList.remove('active');
    currentDeliveryId = null; currentDeliveryItems = [];
    if (currentRecipientName && document.getElementById('recipientDrawer').classList.contains('open')) {
        const addr = document.getElementById('drawerAddr').textContent;
        openRecipientDrawer(currentRecipientName, addr);
    }
}

// ── Lacking ──
function openLackingModal(index) {
    const item = currentDeliveryItems[index];
    currentItemId = item.id;
    document.getElementById('lackingProductName').textContent = item.product_name;
    document.getElementById('lackingOrderedQty').textContent  = item.quantity_ordered;
    document.getElementById('lackingInput').value = item.quantity_lacking || 0;
    document.getElementById('lackingInput').max   = item.quantity_ordered;
    document.getElementById('lackingModal').classList.add('active');
    document.getElementById('lackingInput').focus();
}
function closeLackingModal() { document.getElementById('lackingModal').classList.remove('active'); currentItemId = null; }
function confirmLacking() {
    const qty = parseInt(document.getElementById('lackingInput').value);
    if (isNaN(qty) || qty < 0) { alert('Invalid quantity'); return; }
    const fd = new FormData();
    fd.append('action', 'mark_lacking'); fd.append('item_id', currentItemId); fd.append('lacking_qty', qty);
    fetch('/oro-store/delivery/delivery_details.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(d => { if (d.success) { closeLackingModal(); viewDeliveryDetails(currentDeliveryId); } else alert('Error: ' + d.error); });
}

// ── Edit Qty ──
function openEditQtyModal(index) {
    const item = currentDeliveryItems[index];
    currentItemId = item.id;
    document.getElementById('editProductName').textContent = item.product_name;
    document.getElementById('editOrderedQty').textContent  = item.quantity_ordered;
    document.getElementById('editQtyInput').value = item.quantity_delivered;
    document.getElementById('editQtyInput').max   = item.quantity_ordered;
    document.getElementById('editQtyModal').classList.add('active');
    document.getElementById('editQtyInput').focus();
}
function closeEditQtyModal() { document.getElementById('editQtyModal').classList.remove('active'); currentItemId = null; }
function confirmEditQty() {
    const qty = parseInt(document.getElementById('editQtyInput').value);
    if (isNaN(qty) || qty < 0) { alert('Invalid quantity'); return; }
    const fd = new FormData();
    fd.append('action', 'update_quantity'); fd.append('item_id', currentItemId); fd.append('new_qty', qty);
    fetch('/oro-store/delivery/delivery_details.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(d => { if (d.success) { closeEditQtyModal(); viewDeliveryDetails(currentDeliveryId); } else alert('Error: ' + d.error); });
}

// ── Complete ──
function showCompleteConfirmation() { document.getElementById('completeModal').classList.add('active'); }
function closeCompleteModal()       { document.getElementById('completeModal').classList.remove('active'); }
function confirmComplete() {
    const fd = new FormData();
    fd.append('action', 'mark_complete'); fd.append('delivery_id', currentDeliveryId);
    fetch('/oro-store/delivery/delivery_details.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(d => {
            if (d.success) { alert('Delivery marked as complete!'); closeCompleteModal(); closeDetailsModal(); }
            else alert('Error: ' + d.error);
        });
}

// ── Fee Modal ──
let feeData = [];
function openFeeModal() { document.getElementById('feeModal').classList.add('active'); }
function closeFeeModal() { document.getElementById('feeModal').classList.remove('active'); }
function loadFees() {
    fetch('?action=get_delivery_charges')
        .then(r => r.json())
        .then(data => { feeData = data; renderFees(); })
        .catch(() => { document.getElementById('feeList').innerHTML = '<div style="padding:14px;color:#dc2626;font-size:12px;">Failed to load</div>'; });
}
function renderFees() {
    const el = document.getElementById('feeList');
    if (!feeData.length) { el.innerHTML = '<div style="padding:14px;color:#94a3b8;font-size:12px;text-align:center;">No categories found</div>'; return; }
    el.innerHTML = feeData.map((f, i) => {
        const amt = parseFloat(f.charge_amount || 0);
        const cid = f.charge_id || f.id || 0;
        return `<div class="fee-row" style="display:flex;align-items:center;gap:6px;">
            <span class="fee-cat" style="flex:1;cursor:pointer;" ondblclick="editFee(${i})">${esc(f.category_name)}</span>
            <span class="fee-amt ${amt === 0 ? 'zero' : ''}" style="cursor:pointer;" ondblclick="editFee(${i})">₱${amt.toFixed(2)}</span>
            ${cid > 0 ? `<button onclick="deleteFee(${cid},'${esc(f.category_name)}')" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:14px;padding:2px 4px;" title="Delete">&times;</button>` : ''}
        </div>`;
    }).join('');
}
function editFee(idx) {
    const f = feeData[idx];
    const newAmt = prompt('Fee for "' + f.category_name + '":', parseFloat(f.charge_amount || 0).toFixed(2));
    if (newAmt === null) return;
    const amt = parseFloat(newAmt);
    if (isNaN(amt) || amt < 0) { alert('Invalid amount'); return; }
    fetch('', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=save_delivery_charge&charge_id=${f.charge_id || 0}&category_name=${encodeURIComponent(f.category_name)}&charge_amount=${amt}`
    }).then(r => r.json()).then(d => {
        if (d.success) loadFees();
        else alert('Error: ' + (d.error || 'Failed'));
    });
}
function deleteFee(id, name) {
    if (!confirm('Delete fee for "' + name + '"?')) return;
    fetch('', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=delete_delivery_charge&charge_id=' + id
    }).then(r => r.json()).then(d => { if (d.success) loadFees(); else alert('Error'); });
}

// ── Batch Modal ──
function openBatchModal() {
    document.getElementById('batchModal').classList.add('active');
    document.getElementById('batchList').innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:13px;">Loading...</div>';
    fetch('?action=get_batches')
        .then(function(r) { return r.json(); })
        .then(function(batches) {
            var el = document.getElementById('batchList');
            if (!batches.length) {
                el.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;"><div style="font-size:32px;margin-bottom:8px;">📦</div><p>No saved batches</p></div>';
                return;
            }
            el.innerHTML = batches.map(function(b) {
                var ids = JSON.parse(b.delivery_ids || '[]');
                var statusColor = b.status === 'on_delivery' ? '#f59e0b' : '#667eea';
                var statusLabel = b.status === 'on_delivery' ? 'ON DELIVERY' : 'PENDING';
                return '<div style="padding:12px 14px;border-bottom:1px solid #f1f5f9;cursor:pointer;transition:background .12s;" onmouseenter="this.style.background=\'#f8fafc\'" onmouseleave="this.style.background=\'\'" onclick="loadBatch(' + b.id + ')">' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">' +
                        '<span style="font-weight:700;color:#667eea;font-size:13px;">' + esc(b.batch_number) + '</span>' +
                        '<span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;background:' + statusColor + '20;color:' + statusColor + ';">' + statusLabel + '</span>' +
                    '</div>' +
                    '<div style="display:flex;gap:16px;font-size:11px;color:#64748b;">' +
                        '<span>' + ids.length + ' order(s)</span>' +
                        '<span>' + b.total_items + ' items</span>' +
                        '<span>₱' + fmt(b.total_amount) + '</span>' +
                    '</div>' +
                    '<div style="font-size:10px;color:#94a3b8;margin-top:2px;">' + fmtDate(b.created_at) + (b.created_by_name ? ' · ' + esc(b.created_by_name) : '') + '</div>' +
                '</div>';
            }).join('');
        })
        .catch(function() {
            document.getElementById('batchList').innerHTML = '<div style="padding:20px;text-align:center;color:#dc2626;">Failed to load batches</div>';
        });
}

function closeBatchModal() {
    document.getElementById('batchModal').classList.remove('active');
}

var _currentBatchId = null;
var _currentBatchNumber = null;

function loadBatch(batchId) {
    fetch('?action=load_batch&batch_id=' + batchId)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) { alert('Error: ' + (data.error || 'Failed')); return; }
            _currentBatchId = data.batch.id;
            _currentBatchNumber = data.batch.batch_number;
            selectedOrders.clear();
            data.deliveries.forEach(function(d) {
                selectedOrders.set(parseInt(d.delivery_id), {
                    delivery_id: parseInt(d.delivery_id),
                    recipient_name: d.recipient_name,
                    transaction_number: d.transaction_number,
                    date: d.transaction_date || d.created_at,
                    total_amount: parseFloat(d.total_amount || 0),
                    status: d.status,
                    items: d.items_detail ? parseItemsDetail(d.items_detail) : parseItemsSummary(d.items_summary)
                });
            });
            closeBatchModal();
            renderSelectedPanel();
            if (document.getElementById('recipientDrawer').classList.contains('open')) {
                renderDrawer(drawerDeliveries);
            }
        })
        .catch(function(err) { alert('Error: ' + err.message); });
}

// ── Save Batch ──
function saveBatch() {
    if (selectedOrders.size === 0) { alert('No orders selected'); return; }
    var ids = [];
    var totalItems = 0;
    var totalAmount = 0;
    selectedOrders.forEach(function(order) {
        ids.push(order.delivery_id);
        order.items.forEach(function(item) {
            totalItems += item.quantity;
            totalAmount += item.quantity * parseFloat(item.price || 0);
        });
    });

    if (!confirm('Save batch with ' + selectedOrders.size + ' order(s) and ' + totalItems + ' items?\n\nSelected deliveries will be tagged as "on delivery".')) return;

    var fd = new FormData();
    fd.append('action', 'save_batch');
    fd.append('delivery_ids', JSON.stringify(ids));
    fd.append('total_items', totalItems);
    fd.append('total_amount', totalAmount);
    fetch('/oro-store/delivery/delivery_details.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                _currentBatchId = data.batch_id;
                _currentBatchNumber = data.batch_number;
                if (typeof customAlert === 'function') {
                    customAlert('Batch saved!\n\nBatch #: ' + data.batch_number + '\n' + selectedOrders.size + ' order(s) tagged as on delivery.', 'success', function() { location.reload(); });
                } else {
                    alert('Batch saved! #' + data.batch_number);
                    location.reload();
                }
            } else {
                alert('Error: ' + (data.error || 'Failed'));
            }
        })
        .catch(function(err) { alert('Error: ' + err.message); });
}

// ── Batch Management ──
function removeFromBatch(deliveryId) {
    if (!_currentBatchId) { removeSelectedOrder(deliveryId); return; }
    if (!confirm('Remove this order from batch ' + _currentBatchNumber + '?\nThe order will return to pending status.')) return;
    var fd = new FormData();
    fd.append('action', 'remove_from_batch');
    fd.append('batch_id', _currentBatchId);
    fd.append('delivery_id', deliveryId);
    fetch('/oro-store/delivery/delivery_details.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                selectedOrders.delete(deliveryId);
                if (selectedOrders.size === 0) { _currentBatchId = null; _currentBatchNumber = null; }
                renderSelectedPanel();
                if (document.getElementById('recipientDrawer').classList.contains('open')) {
                    openRecipientDrawer(currentRecipientName, document.getElementById('drawerAddr').textContent);
                }
            } else { alert('Error: ' + (data.error || 'Failed')); }
        }).catch(function(err) { alert('Error: ' + err.message); });
}

function completeBatch() {
    if (!_currentBatchId) return;
    if (!confirm('Mark batch ' + _currentBatchNumber + ' as complete?\n\nAll ' + selectedOrders.size + ' deliveries will be marked as completed.')) return;
    var fd = new FormData();
    fd.append('action', 'complete_batch');
    fd.append('batch_id', _currentBatchId);
    fetch('/oro-store/delivery/delivery_details.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                customAlert('Batch ' + _currentBatchNumber + ' completed!\nAll deliveries marked as delivered.', 'success', function() { location.reload(); });
            } else { alert('Error: ' + (data.error || 'Failed')); }
        }).catch(function(err) { alert('Error: ' + err.message); });
}

function cancelBatch() {
    if (!_currentBatchId) return;
    if (!confirm('Cancel batch ' + _currentBatchNumber + '?\n\nAll deliveries will return to pending status.')) return;
    var fd = new FormData();
    fd.append('action', 'cancel_batch');
    fd.append('batch_id', _currentBatchId);
    fetch('/oro-store/delivery/delivery_details.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                customAlert('Batch ' + _currentBatchNumber + ' cancelled.\nDeliveries returned to pending.', 'info', function() { location.reload(); });
            } else { alert('Error: ' + (data.error || 'Failed')); }
        }).catch(function(err) { alert('Error: ' + err.message); });
}

// ── Print Selected Orders ──
var _ddReceiptTimer = null;

function printSelectedOrders() {
    if (selectedOrders.size === 0) { alert('No orders selected'); return; }

    // Group by recipient
    var groups = new Map();
    selectedOrders.forEach(function(order) {
        var name = order.recipient_name;
        if (!groups.has(name)) groups.set(name, []);
        groups.get(name).push(order);
    });

    // Compute product totals
    var productTotals = new Map();
    var grandTotal = 0;
    selectedOrders.forEach(function(order) {
        order.items.forEach(function(item) {
            var existing = productTotals.get(item.product_name) || 0;
            productTotals.set(item.product_name, existing + item.quantity);
            grandTotal += item.quantity;
        });
    });
    var sortedProducts = Array.from(productTotals.entries()).sort(function(a, b) { return a[0].localeCompare(b[0]); });

    // Print via RawBT
    if (typeof BTPrint !== 'undefined') {
        var btGroups = [];
        groups.forEach(function(orders, recipientName) {
            btGroups.push({ name: recipientName, orders: orders });
        });
        BTPrint.printLoadingList(btGroups, sortedProducts, grandTotal, selectedOrders.size, DD_STORE_NAME, _currentBatchNumber);
    }

    // Build modal HTML — per-customer receipts
    var dateStr = new Date().toLocaleString();
    var html = '';

    groups.forEach(function(orders, recipientName) {
        html += '<div style="border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:12px;">';
        html += '<div class="dd-receipt-center" style="font-weight:700;font-size:13px;margin-bottom:2px;">DELIVERY ORDER</div>';
        html += '<div class="dd-receipt-center" style="font-size:10px;color:#64748b;margin-bottom:4px;">' + dateStr + '</div>';
        html += '<div class="dd-receipt-dash"></div>';
        html += '<div class="dd-receipt-center" style="font-weight:700;font-size:13px;margin:4px 0;">' + esc(recipientName) + '</div>';
        html += '<div class="dd-receipt-dash"></div>';

        var customerTotal = 0;
        orders.forEach(function(order) {
            html += '<div class="dd-receipt-txn">#' + esc(order.transaction_number) + ' &middot; ' + fmtDate(order.date) + '</div>';
            order.items.forEach(function(item) {
                var price = parseFloat(item.price || 0);
                var sub = item.quantity * price;
                customerTotal += sub;
                html += '<div style="font-weight:600;color:#1e293b;font-size:11px;">' + esc(item.product_name) + '</div>';
                if (price > 0) {
                    html += '<div class="dd-receipt-line" style="color:#64748b;font-size:10px;"><span>&nbsp;&nbsp;' + item.quantity + ' x ₱' + price.toFixed(2) + '</span><span>₱' + sub.toFixed(2) + '</span></div>';
                } else {
                    html += '<div class="dd-receipt-line" style="color:#64748b;font-size:10px;"><span>&nbsp;&nbsp;qty</span><span>x' + item.quantity + '</span></div>';
                }
            });
            html += '<div class="dd-receipt-dash"></div>';
        });

        if (customerTotal > 0) {
            html += '<div class="dd-receipt-line bold" style="font-size:12px;"><span>TOTAL</span><span>₱' + customerTotal.toFixed(2) + '</span></div>';
        }
        html += '<div class="dd-receipt-disclaimer">NOT AN OFFICIAL RECEIPT — PROOF OF PURCHASE ONLY</div>';
        html += '</div>';
    });

    // Product totals summary
    html += '<div style="border:2px solid #667eea;border-radius:8px;padding:12px;background:#f8fafc;">';
    html += '<div class="dd-receipt-center" style="font-weight:700;font-size:13px;margin-bottom:2px;">LOADING SUMMARY</div>';
    if (_currentBatchNumber) html += '<div class="dd-receipt-center" style="font-weight:700;font-size:12px;color:#667eea;margin-bottom:4px;">' + esc(_currentBatchNumber) + '</div>';
    html += '<div class="dd-receipt-center" style="font-size:10px;color:#64748b;margin-bottom:6px;">' + selectedOrders.size + ' order(s) / ' + groups.size + ' customer(s)</div>';
    html += '<div class="dd-receipt-dash thick"></div>';
    html += '<div class="dd-receipt-center" style="font-weight:700;font-size:12px;margin:4px 0;">PRODUCT TOTALS</div>';
    sortedProducts.forEach(function(entry) {
        html += '<div class="dd-receipt-line"><span>' + esc(entry[0]) + '</span><span style="font-weight:700;">x' + entry[1] + '</span></div>';
    });
    html += '<div class="dd-receipt-dash thick"></div>';
    html += '<div class="dd-receipt-line bold" style="font-size:13px;"><span>TOTAL ITEMS</span><span>' + grandTotal + '</span></div>';
    html += '</div>';

    document.getElementById('dd-receipt-body').innerHTML = html;
    document.getElementById('dd-receipt-modal').classList.add('active');

    // Auto-close countdown
    var seconds = 10;
    var countEl = document.getElementById('dd-receipt-countdown');
    if (countEl) countEl.textContent = seconds;
    if (_ddReceiptTimer) clearInterval(_ddReceiptTimer);
    _ddReceiptTimer = setInterval(function() {
        seconds--;
        if (countEl) countEl.textContent = seconds;
        if (seconds <= 0) closeDDReceiptModal();
    }, 1000);
}

function closeDDReceiptModal() {
    if (_ddReceiptTimer) { clearInterval(_ddReceiptTimer); _ddReceiptTimer = null; }
    document.getElementById('dd-receipt-modal').classList.remove('active');
}

// ── Keyboard ──
document.addEventListener('keydown', e => {
    const alertOverlay = document.getElementById('custom-alert-overlay');
    if (alertOverlay && alertOverlay.style.display !== 'none') return;

    const feeOpen      = document.getElementById('feeModal').classList.contains('active');
    const lackingOpen  = document.getElementById('lackingModal').classList.contains('active');
    const editOpen     = document.getElementById('editQtyModal').classList.contains('active');
    const completeOpen = document.getElementById('completeModal').classList.contains('active');
    const detailsOpen  = document.getElementById('detailsModal').classList.contains('active');
    const drawerOpen   = document.getElementById('recipientDrawer').classList.contains('open');

    if (feeOpen) {
        if (e.key === 'Escape') { e.preventDefault(); closeFeeModal(); }
        return;
    }
    if (lackingOpen) {
        if (e.key === 'Enter')  { e.preventDefault(); confirmLacking(); }
        if (e.key === 'Escape') { e.preventDefault(); closeLackingModal(); }
        return;
    }
    if (editOpen) {
        if (e.key === 'Enter')  { e.preventDefault(); confirmEditQty(); }
        if (e.key === 'Escape') { e.preventDefault(); closeEditQtyModal(); }
        return;
    }
    if (completeOpen) {
        if (e.key === 'Enter')  { e.preventDefault(); confirmComplete(); }
        if (e.key === 'Escape') { e.preventDefault(); closeCompleteModal(); }
        return;
    }
    if (detailsOpen) {
        if (e.key === 'Enter')  { e.preventDefault(); showCompleteConfirmation(); }
        if (e.key === 'Escape') { e.preventDefault(); closeDetailsModal(); }
        return;
    }
    if (drawerOpen) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedCardIdx = Math.min(selectedCardIdx + 1, drawerDeliveries.length - 1);
            updateCardSelection();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedCardIdx = Math.max(selectedCardIdx - 1, 0);
            updateCardSelection();
        } else if (e.key === 'Enter' || e.key === 'v' || e.key === 'V') {
            e.preventDefault();
            openSelectedCard();
        } else if (e.key === ' ') {
            e.preventDefault();
            toggleOrderSelection(selectedCardIdx);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeDrawer();
        }
        return;
    }

    // Main list
    const rows = visibleRows();
    if (e.key === 'ArrowDown')  { e.preventDefault(); selectedRowIndex = Math.min(selectedRowIndex + 1, rows.length - 1); updateRowSelection(); }
    if (e.key === 'ArrowUp')    { e.preventDefault(); selectedRowIndex = Math.max(selectedRowIndex - 1, 0); updateRowSelection(); }
    if (e.key === 'Enter')      { e.preventDefault(); openSelectedRow(); }
    if (e.key === 'Escape') {
        const s = document.getElementById('searchInput');
        if (s.value) { s.value = ''; applyFilter(); s.focus(); } else location.reload();
    }
});
</script>
</body>
</html>