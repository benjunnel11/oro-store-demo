<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/sync_helper.php';
require_once __DIR__ . '/../core/transaction_helper.php';
require_once __DIR__ . '/../core/system_logger.php';

$db = new SyncDB();
$currentUser = getCurrentUser();

// Ensure credit_charge_categories table exists
$conn->query("CREATE TABLE IF NOT EXISTS credit_charge_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(255) NOT NULL,
    charge_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

$userStore = null;
if ($currentUser['store_id'] && !isAdmin()) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $currentUser['store_id']);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
}

// ─── AJAX: Get credit charge categories ──────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_credit_charges') {
    header('Content-Type: application/json');
    $sql = "SELECT pc.id, pc.category_name,
                   COALESCE(ccc.charge_amount, 0.00) AS charge_amount,
                   COALESCE(ccc.id, 0) AS charge_id
            FROM product_categories pc
            LEFT JOIN credit_charge_categories ccc
                ON ccc.category_name = pc.category_name AND ccc.is_deleted = 0
            WHERE pc.is_deleted = 0
            ORDER BY pc.category_name ASC";
    $res = $conn->query($sql);
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    // Also include individual units (products without category that have parent_product_id)
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
            $chk = $conn->query("SELECT id, charge_amount FROM credit_charge_categories WHERE category_name = '" . $conn->real_escape_string($uName) . "' AND is_deleted = 0");
            $ex = $chk ? $chk->fetch_assoc() : null;
            $rows[] = ['id' => 0, 'category_name' => $uName, 'charge_amount' => $ex ? (float)$ex['charge_amount'] : 0, 'charge_id' => $ex ? (int)$ex['id'] : 0];
        }
    }
    echo json_encode($rows);
    $conn->close(); exit;
}

// ─── POST: Save credit charge ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_credit_charge') {
    header('Content-Type: application/json');
    try {
        $charge_id = intval($_POST['charge_id'] ?? 0);
        $category_name = trim($_POST['category_name'] ?? '');
        $charge_amount = floatval($_POST['charge_amount'] ?? 0);
        if (!$category_name) throw new Exception('Category name is required');

        if ($charge_id > 0) {
            $stmt = $conn->prepare("UPDATE credit_charge_categories SET charge_amount = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
            $stmt->bind_param("di", $charge_amount, $charge_id);
            $stmt->execute(); $stmt->close();
        } else {
            $stmt = $conn->prepare("SELECT id FROM credit_charge_categories WHERE category_name = ? AND is_deleted = 0");
            $stmt->bind_param("s", $category_name); $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($existing) {
                $stmt = $conn->prepare("UPDATE credit_charge_categories SET charge_amount = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("di", $charge_amount, $existing['id']);
                $stmt->execute(); $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO credit_charge_categories (category_name, charge_amount, is_deleted) VALUES (?, ?, 0)");
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

// POST: Delete credit charge category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_credit_charge') {
    header('Content-Type: application/json');
    $charge_id = intval($_POST['charge_id'] ?? 0);
    if ($charge_id > 0) $conn->query("UPDATE credit_charge_categories SET is_deleted = 1 WHERE id = $charge_id");
    echo json_encode(['success' => true]);
    $conn->close(); exit;
}

// ─── AJAX: Get customer credits ───────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_customer_credits') {
    header('Content-Type: application/json');
    try {
        $customer_id = intval($_GET['customer_id']);
        $storeClause = $userStore ? " AND c.store_id = {$userStore['id']}" : "";
        $query = "SELECT c.*, t.transaction_number, t.transaction_date
                  FROM credits c
                  INNER JOIN transactions t ON c.transaction_id = t.id
                  WHERE c.customer_id = ? AND c.is_deleted = 0 $storeClause
                  ORDER BY c.created_at DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $credits = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($credits as &$credit) {
            $credit['id'] = (int)$credit['id'];
            $credit['total_amount'] = (float)$credit['total_amount'];
            $credit['amount_paid'] = (float)$credit['amount_paid'];
            $credit['amount_due'] = (float)$credit['amount_due'];
            $credit['additional_charge'] = (float)($credit['additional_charge'] ?? 0);
        }
        echo json_encode($credits);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── AJAX: Get all receipts for a customer ───────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_customer_receipts') {
    header('Content-Type: application/json');
    try {
        $customer_id = intval($_GET['customer_id']);
        $storeClause = $userStore ? " AND c.store_id = {$userStore['id']}" : "";
        $query = "SELECT c.id as credit_id, c.status, c.total_amount, c.amount_paid,
                         c.amount_due, c.additional_charge, c.created_at,
                         t.transaction_number, t.transaction_date, t.total_items,
                         GROUP_CONCAT(ti.product_name, ' x', ti.quantity ORDER BY ti.id SEPARATOR '||') as items_summary
                  FROM credits c
                  INNER JOIN transactions t ON c.transaction_id = t.id
                  LEFT JOIN transaction_items ti ON t.id = ti.transaction_id AND ti.is_deleted = 0
                  WHERE c.customer_id = ? AND c.is_deleted = 0 $storeClause
                  GROUP BY c.id
                  ORDER BY t.transaction_date DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $customer_id);
        $stmt->execute();
        $receipts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode($receipts);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── AJAX: Get credit receipt details ────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_credit_details') {
    header('Content-Type: application/json');
    try {
        $credit_id = intval($_GET['credit_id']);
        $storeClause = $userStore ? " AND c.store_id = {$userStore['id']}" : "";
        $stmt = $conn->prepare("SELECT c.*, t.transaction_number, t.transaction_date, t.total_items
                                FROM credits c
                                INNER JOIN transactions t ON c.transaction_id = t.id
                                WHERE c.id = ? AND c.is_deleted = 0 $storeClause");
        $stmt->bind_param("i", $credit_id);
        $stmt->execute();
        $credit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$credit) throw new Exception("Credit not found");

        $stmt = $conn->prepare("SELECT ti.* FROM transaction_items ti
                                WHERE ti.transaction_id = ? AND ti.is_deleted = 0 ORDER BY ti.id");
        $stmt->bind_param("i", $credit['transaction_id']);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['credit' => $credit, 'items' => $items]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── POST: Mark paid ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_paid') {
    header('Content-Type: application/json');
    $credit_ids = json_decode($_POST['credit_ids'], true);
    $conn->begin_transaction();
    try {
        foreach ($credit_ids as $credit_id) {
            $credit_id = intval($credit_id);
            $storeClause = $userStore ? " AND store_id = {$userStore['id']}" : "";
            $stmt = $conn->prepare("SELECT * FROM credits WHERE id = ? AND is_deleted = 0 AND status != 'paid' $storeClause");
            $stmt->bind_param("i", $credit_id);
            $stmt->execute();
            $credit = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$credit) continue;

            $payment_amount = $credit['total_amount'] - $credit['amount_paid'];
            if ($payment_amount <= 0) $payment_amount = $credit['total_amount'];

            $db->update('credits', [
                'amount_paid' => $credit['total_amount'],
                'amount_due'  => 0,
                'status'      => 'paid',
                'paid_at'     => date('Y-m-d H:i:s')
            ], "id = $credit_id");

            $conn->prepare("UPDATE transactions SET status = 'completed' WHERE id = ?")->execute([$credit['transaction_id']]);

            logActivity('credit', "Credit marked as paid: {$credit['customer_name']}", $currentUser['id'], $credit['store_id'], [
                'credit_id' => $credit_id, 'amount' => $payment_amount
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

// ─── POST: Partial payment ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'partial_payment') {
    header('Content-Type: application/json');
    $credit_id      = intval($_POST['credit_id']);
    $payment_amount = floatval($_POST['payment_amount']);
    try {
        $storeClause = $userStore ? " AND store_id = {$userStore['id']}" : "";
        $stmt = $conn->prepare("SELECT * FROM credits WHERE id = ? AND is_deleted = 0 $storeClause");
        $stmt->bind_param("i", $credit_id);
        $stmt->execute();
        $credit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$credit) throw new Exception("Credit not found");
        $new_amount_paid = $credit['amount_paid'] + $payment_amount;
        $new_amount_due  = $credit['total_amount'] - $new_amount_paid;
        if ($new_amount_due < 0) throw new Exception("Payment amount exceeds remaining balance");
        $new_status  = $new_amount_due <= 0 ? 'paid' : 'partial';
        $update_data = ['amount_paid' => $new_amount_paid, 'amount_due' => $new_amount_due, 'status' => $new_status];
        if ($new_amount_due <= 0) $update_data['paid_at'] = date('Y-m-d H:i:s');
        $db->update('credits', $update_data, "id = $credit_id");

        if ($new_amount_due <= 0) {
            $conn->prepare("UPDATE transactions SET status = 'completed' WHERE id = ?")->execute([$credit['transaction_id']]);
        }

        logActivity('credit', "Partial payment received: {$credit['customer_name']} ₱" . number_format($payment_amount, 2), $currentUser['id'], $credit['store_id'], [
            'credit_id' => $credit_id, 'amount' => $payment_amount
        ]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── Main query — always filtered to user's store ─────────────────────────────
$whereClause = $userStore ? " AND c.store_id = {$userStore['id']}" : "";

$query = "SELECT
    c.customer_id,
    c.customer_name,
    c.customer_contact,
    c.customer_address,
    COUNT(c.id)                                             AS total_credits,
    SUM(CASE WHEN c.status='unpaid'  THEN 1 ELSE 0 END)   AS unpaid_count,
    SUM(CASE WHEN c.status='partial' THEN 1 ELSE 0 END)   AS partial_count,
    SUM(CASE WHEN c.status='paid'    THEN 1 ELSE 0 END)   AS paid_count,
    SUM(c.total_amount)                                     AS total_transaction_amount,
    SUM(c.amount_due)                                       AS total_due,
    SUM(c.amount_paid)                                      AS total_paid,
    SUM(IFNULL(c.additional_charge,0))                     AS total_additional_charge,
    MAX(c.created_at)                                       AS last_credit_date,
    (SELECT c2.total_amount FROM credits c2
        WHERE c2.customer_id = c.customer_id AND c2.is_deleted = 0
        ORDER BY c2.created_at DESC LIMIT 1)               AS last_transaction_amount,
    (SELECT c2.status FROM credits c2
        WHERE c2.customer_id = c.customer_id AND c2.is_deleted = 0
        ORDER BY c2.created_at DESC LIMIT 1)               AS last_status
FROM credits c
WHERE c.is_deleted = 0 $whereClause
GROUP BY c.customer_id, c.customer_name, c.customer_contact, c.customer_address
ORDER BY total_due DESC, last_credit_date DESC";

$result    = $conn->query($query);
$customers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Credit Details<?php echo $userStore ? ' — ' . htmlspecialchars($userStore['store_name']) : ''; ?></title>
<link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
<style>
    /* ── Page Layout ── */
    .cd-container {
        max-width: 1100px;
        margin: 0 auto;
        padding: 24px 20px;
    }
    .cd-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 12px;
    }
    .cd-header h1 {
        font-size: 20px;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    .cd-header-right {
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
    .cd-toolbar {
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
        content: '⌕';
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
    .filter-btn[data-filter="unpaid"].active  { background: #ef4444; border-color: #ef4444; }
    .filter-btn[data-filter="partial"].active { background: #f59e0b; border-color: #f59e0b; }
    .filter-btn[data-filter="paid"].active    { background: #22c55e; border-color: #22c55e; }
    .result-count { margin-left: auto; font-size: 12px; color: #94a3b8; }

    /* ── List ── */
    .cd-list {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
    }
    .cd-list-head {
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
    .cd-row {
        display: grid;
        grid-template-columns: 2fr 1.2fr 1.2fr 80px 1fr 1fr 1fr;
        padding: 14px 20px;
        border-bottom: 1px solid #f1f5f9;
        align-items: center;
        cursor: pointer;
        transition: background .12s;
        position: relative;
    }
    .cd-row:last-child { border-bottom: none; }
    .cd-row:hover { background: #f8fafc; }
    .cd-row.selected { background: #eff6ff; }
    .cd-row.hidden { display: none; }
    .cd-row::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        border-radius: 3px 0 0 3px;
    }
    .cd-row.status-unpaid::before  { background: #ef4444; }
    .cd-row.status-partial::before { background: #f59e0b; }
    .cd-row.status-paid::before    { background: #22c55e; }

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
    .badge-unpaid  { background: #fee2e2; color: #dc2626; }
    .badge-partial { background: #fef3c7; color: #d97706; }
    .badge-paid    { background: #dcfce7; color: #16a34a; }

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

    /* Credit info grid in detail modal */
    .credit-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 16px;
    }
    .credit-info-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 12px;
    }
    .credit-info-box .label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: #94a3b8;
        font-weight: 700;
        margin-bottom: 4px;
    }
    .credit-info-box .value {
        font-size: 15px;
        font-weight: 700;
        color: #1e293b;
    }

    .modal-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 16px;
        background: #fef2f2;
        border-radius: 8px;
        border: 1px solid #fecaca;
        margin-bottom: 16px;
    }
    .modal-total-label { font-size: 13px; color: #64748b; }
    .modal-total-value { font-size: 17px; font-weight: 700; color: #dc2626; }

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

    /* ── Fee Table ── */
    .cd-main-wrap { display: flex; gap: 20px; align-items: flex-start; }
    .cd-main-wrap .cd-list-col { flex: 1; min-width: 0; }
    .fee-card {
        width: 240px;
        flex-shrink: 0;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
    }
    .fee-card-head {
        padding: 10px 14px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        font-size: 13px;
        font-weight: 700;
        color: #1e293b;
    }
    .fee-list {
        max-height: 400px;
        overflow-y: auto;
    }
    .fee-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 14px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 12px;
        cursor: pointer;
        transition: background .1s;
    }
    .fee-row:last-child { border-bottom: none; }
    .fee-row:hover { background: #f8fafc; }
    .fee-cat { color: #334155; font-weight: 500; }
    .fee-amt { color: #667eea; font-weight: 700; }
    .fee-amt.zero { color: #94a3b8; font-weight: 400; }
    @media (max-width: 900px) {
        .cd-main-wrap { flex-direction: column; }
        .fee-card { width: 100%; }
    }

    .drawer-foot {
        padding: 14px 24px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        gap: 10px;
        flex-shrink: 0;
        background: #f8fafc;
    }
</style>
</head>
<body>
<?php
if (isAdmin()) include_once __DIR__ . '/../admin/admin_sidebar.php';
elseif ($currentUser['role'] === 'manager') include_once __DIR__ . '/../manager/manager_sidebar.php';
else { ?>
<style>.cashier-topnav{display:flex;align-items:center;gap:10px;padding:10px 16px;background:#1a1a2e;position:fixed;top:0;left:0;right:0;z-index:100;}
.cashier-topnav a{color:#fff;text-decoration:none;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;background:#334155;transition:background .15s;}
.cashier-topnav a:hover{background:#475569;} .cashier-topnav a.active{background:#6366f1;}
.cashier-topnav .nav-title{color:#94a3b8;font-size:12px;margin-right:auto;font-weight:600;}
.main-content{margin-left:0 !important;padding-top:56px !important;}</style>
<div class="cashier-topnav">
    <span class="nav-title"><?php echo htmlspecialchars($currentUser['full_name']); ?> &middot; Cashier</span>
    <a href="/oro-store/cashier/cashier.php">Cashier</a>
    <a href="/oro-store/credit/credit_details.php" class="active">Credit</a>
    <a href="/oro-store/delivery/delivery_details.php">Delivery</a>
    <a href="/oro-store/angkat/angkat_details.php">Angkat</a>
</div>
<?php } ?>

<main class="main-content">
    <div class="cd-container">

        <!-- Header -->
        <div class="cd-header">
            <h1>💳 Credit Details<?php echo $userStore ? ' <span style="font-size:14px;font-weight:500;color:#64748b">— ' . htmlspecialchars($userStore['store_name']) . '</span>' : ''; ?></h1>
            <div class="cd-header-right">
                <button class="btn btn-secondary" onclick="location.href='/oro-store/credit/credit.php'">+ New Credit</button>
                <button class="btn btn-primary" onclick="location.href='/oro-store/cashier/cashier.php'">← Cashier</button>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="cd-toolbar">
            <div class="search-wrap">
                <input type="text" id="searchInput" placeholder="Search customer, contact, address…" autofocus>
            </div>
            <div class="filter-group">
                <button class="filter-btn active" data-filter="all"     onclick="setFilter(this,'all')">All</button>
                <button class="filter-btn"         data-filter="unpaid"  onclick="setFilter(this,'unpaid')">Unpaid</button>
                <button class="filter-btn"         data-filter="partial" onclick="setFilter(this,'partial')">Partial</button>
                <button class="filter-btn"         data-filter="paid"    onclick="setFilter(this,'paid')">Paid</button>
            </div>
            <div class="result-count" id="resultCount"></div>
        </div>

        <!-- List + Fee Table -->
        <div class="cd-main-wrap">
        <div class="cd-list-col">
        <?php if (empty($customers)): ?>
            <div class="empty-state">
                <div style="font-size:48px;">💳</div>
                <h3>No credit records found<?php echo $userStore ? ' for ' . htmlspecialchars($userStore['store_name']) : ''; ?></h3>
                <p>Create a new credit to get started.</p>
            </div>
        <?php else: ?>
        <div class="cd-list">
            <div class="cd-list-head">
                <div>Customer</div>
                <div>Contact</div>
                <div>Location</div>
                <div style="text-align:center">Credits</div>
                <div style="text-align:right">Total Paid</div>
                <div style="text-align:right">Amount Due</div>
                <div>Last Date</div>
            </div>
            <?php foreach ($customers as $i => $c):
                $hasUnpaid  = $c['unpaid_count'] > 0;
                $hasPartial = $c['partial_count'] > 0;
                $displayStatus = $hasUnpaid ? 'unpaid' : ($hasPartial ? 'partial' : 'paid');
            ?>
            <div class="cd-row status-<?php echo $displayStatus; ?>"
                 data-index="<?php echo $i; ?>"
                 data-status="<?php echo $displayStatus; ?>"
                 data-cid="<?php echo (int)$c['customer_id']; ?>"
                 data-name="<?php echo htmlspecialchars($c['customer_name']); ?>"
                 data-contact="<?php echo htmlspecialchars($c['customer_contact']); ?>"
                 data-addr="<?php echo htmlspecialchars($c['customer_address']??''); ?>"
                 data-due="<?php echo $c['total_due']; ?>"
                 onclick="openCustomerDrawer(<?php echo (int)$c['customer_id']; ?>, '<?php echo addslashes(htmlspecialchars($c['customer_name'])); ?>', '<?php echo addslashes(htmlspecialchars($c['customer_contact'])); ?>', '<?php echo addslashes(htmlspecialchars($c['customer_address']??'')); ?>')">
                <div>
                    <div class="row-name">👤 <?php echo htmlspecialchars($c['customer_name']); ?></div>
                    <div class="row-address">📍 <?php echo htmlspecialchars($c['customer_address']??'—'); ?></div>
                </div>
                <div class="row-contact">📞 <?php echo htmlspecialchars($c['customer_contact']); ?></div>
                <div class="row-address"><?php echo htmlspecialchars($c['customer_address']??'—'); ?></div>
                <div class="row-credits"><?php echo (int)$c['total_credits']; ?> credit<?php echo $c['total_credits'] != 1 ? 's' : ''; ?></div>
                <?php if ($displayStatus === 'paid'): ?>
                    <div class="row-amount" style="color:#16a34a">Paid</div>
                    <div class="row-due" style="color:#94a3b8">—</div>
                <?php else: ?>
                    <div class="row-amount">₱<?php echo number_format($c['total_paid'],2); ?></div>
                    <div class="row-due">₱<?php echo number_format($c['total_due'],2); ?></div>
                <?php endif; ?>
                <div class="row-date"><?php echo $c['last_credit_date'] ? date('M d, Y', strtotime($c['last_credit_date'])) : '—'; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </div><!-- /.cd-list-col -->

        <!-- Fee Table -->
        <div class="fee-card">
            <div class="fee-card-head">Credit Fees</div>
            <div class="fee-list" id="feeList">
                <div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Loading...</div>
            </div>
        </div>
        </div><!-- /.cd-main-wrap -->

    </div><!-- /.cd-container -->
</main>

<!-- Customer Drawer -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="customerDrawer">
    <div class="drawer-head">
        <div class="drawer-head-info">
            <h2 id="drawerName">—</h2>
            <p id="drawerContact">—</p>
            <p id="drawerAddr" style="margin-top:2px">—</p>
        </div>
        <button class="drawer-close" onclick="closeDrawer()">×</button>
    </div>
    <div class="drawer-stats">
        <div class="drawer-stat">
            <span class="drawer-stat-label">Total</span>
            <span class="drawer-stat-value" id="dStatTotal" style="color:#1e293b">—</span>
        </div>
        <div class="drawer-stat">
            <span class="drawer-stat-label">Total Due</span>
            <span class="drawer-stat-value" id="dStatDue" style="color:#ef4444">—</span>
        </div>
        <div class="drawer-stat">
            <span class="drawer-stat-label">Total Paid</span>
            <span class="drawer-stat-value" id="dStatPaid" style="color:#22c55e">—</span>
        </div>
        <div class="drawer-stat">
            <span class="drawer-stat-label">Transactions</span>
            <span class="drawer-stat-value" id="dStatCount" style="color:#667eea">—</span>
        </div>
    </div>
    <div class="drawer-body" id="drawerBody">
        <div class="drawer-loading"><div class="spinner"></div> Loading receipts…</div>
    </div>
    <div class="drawer-foot">
        <button class="btn btn-success" id="drawerMarkPaidBtn" onclick="markAllPaidForCustomer()" style="display:none">✓ Mark All Unpaid as Paid</button>
    </div>
</div>

<!-- Credit Detail Modal -->
<div class="modal" id="detailOverlay">
    <div class="modal-box">
        <div class="modal-title" id="detailTitle">Credit Details</div>
        <div id="detailBody">Loading…</div>
        <div class="modal-btns">
            <button id="detailPartialBtn" style="background:#f59e0b;color:#fff" onclick="openPartialPayment()">Partial Payment</button>
            <button id="detailMarkPaidBtn" style="background:#22c55e;color:#fff" onclick="markSinglePaid()">✓ Mark as Paid</button>
            <button style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0" onclick="closeDetailModal()">Close</button>
        </div>
    </div>
</div>

<!-- Partial Payment Modal -->
<div class="modal" id="paymentOverlay">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-title">💰 Partial Payment</div>
        <div class="field-label">Transaction</div>
        <div id="paymentInfo" style="font-size:13px;font-weight:600;margin-bottom:12px;padding:10px;background:#f8fafc;border-radius:6px;border:1px solid #e2e8f0"></div>
        <div class="field-label">Remaining Balance: <span id="paymentBalance" style="color:#ef4444;font-weight:700"></span></div>
        <br>
        <div class="field-label">Payment Amount</div>
        <input type="number" class="field-input" id="paymentInput" step="0.01" min="0" placeholder="Enter payment amount">
        <div class="modal-btns">
            <button style="background:#22c55e;color:#fff" onclick="confirmPayment()">Confirm Payment</button>
            <button style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0" onclick="closePaymentModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
let selectedRowIndex = 0;
let selectedReceiptIdx = 0;
let currentFilter = 'all';
let currentCreditId = null;
let currentCustomerId = null;
let drawerReceipts = [];

document.addEventListener('DOMContentLoaded', () => {
    setupSearch();
    updateRowSelection();
    updateResultCount();
});

// ── Helpers ──
function fmt(n) { return parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(s) { if(!s) return '—'; return new Date(s).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function ucFirst(str) { return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''; }

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
    document.querySelectorAll('.cd-row').forEach(row => {
        const matchStatus = currentFilter === 'all' || row.dataset.status === currentFilter;
        const matchQuery  = !q ||
            row.dataset.name.toLowerCase().includes(q) ||
            row.dataset.contact.toLowerCase().includes(q) ||
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
    const rows  = count !== undefined ? count : document.querySelectorAll('.cd-row:not(.hidden)').length;
    const total = document.querySelectorAll('.cd-row').length;
    document.getElementById('resultCount').textContent = rows === total ? `${total} records` : `${rows} of ${total}`;
}

// ── Row Selection ──
function visibleRows() { return Array.from(document.querySelectorAll('.cd-row:not(.hidden)')); }
function updateRowSelection() {
    const rows = visibleRows();
    rows.forEach((r, i) => r.classList.toggle('selected', i === selectedRowIndex));
    if (rows[selectedRowIndex]) rows[selectedRowIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}
function openSelectedRow() {
    const row = visibleRows()[selectedRowIndex];
    if (row) openCustomerDrawer(parseInt(row.dataset.cid), row.dataset.name, row.dataset.contact, row.dataset.addr);
}

// ── Drawer receipt selection ──
function receiptCards() {
    return Array.from(document.querySelectorAll('#drawerBody .receipt-card'));
}
function updateReceiptSelection() {
    receiptCards().forEach((c, i) => c.classList.toggle('receipt-selected', i === selectedReceiptIdx));
    const card = receiptCards()[selectedReceiptIdx];
    if (card) card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}
function openSelectedReceipt() {
    const r = drawerReceipts[selectedReceiptIdx];
    if (r) openCreditDetail(r.credit_id, { stopPropagation: () => {} });
}

// ── Customer Drawer ──
function openCustomerDrawer(id, name, contact, addr) {
    currentCustomerId  = id;
    selectedReceiptIdx = 0;
    document.getElementById('drawerName').textContent    = name;
    document.getElementById('drawerContact').textContent = '📞 ' + (contact || '—');
    document.getElementById('drawerAddr').textContent    = addr ? '📍 ' + addr : '';
    ['dStatTotal','dStatDue','dStatPaid','dStatCount'].forEach(sid => document.getElementById(sid).textContent = '—');
    document.getElementById('drawerMarkPaidBtn').style.display = 'none';
    document.getElementById('drawerBody').innerHTML = '<div class="drawer-loading"><div class="spinner"></div> Loading…</div>';
    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('customerDrawer').classList.add('open');

    fetch(`?action=get_customer_receipts&customer_id=${id}`)
        .then(r => r.json())
        .then(data => { drawerReceipts = data; renderDrawer(data); setTimeout(updateReceiptSelection, 30); })
        .catch(() => { document.getElementById('drawerBody').innerHTML = '<div class="drawer-loading">Failed to load.</div>'; });
}

function renderDrawer(receipts) {
    if (!receipts.length) {
        document.getElementById('drawerBody').innerHTML = '<div class="drawer-loading">No credits found.</div>';
        return;
    }
    const totalDue    = receipts.reduce((s,r) => s + parseFloat(r.amount_due||0), 0);
    const totalPaid   = receipts.reduce((s,r) => s + parseFloat(r.amount_paid||0), 0);
    const totalAmount = receipts.reduce((s,r) => s + parseFloat(r.total_amount||0), 0);
    document.getElementById('dStatTotal').textContent = '₱' + fmt(totalAmount);
    document.getElementById('dStatDue').textContent   = '₱' + fmt(totalDue);
    document.getElementById('dStatPaid').textContent  = '₱' + fmt(totalPaid);
    document.getElementById('dStatCount').textContent = receipts.length;
    const hasUnpaid = receipts.some(r => r.status === 'unpaid' || r.status === 'partial');
    document.getElementById('drawerMarkPaidBtn').style.display = hasUnpaid ? '' : 'none';

    document.getElementById('drawerBody').innerHTML = receipts.map(r => {
        const items  = (r.items_summary || '').split('||').filter(Boolean);
        const chips  = items.slice(0,5).map(i => `<span class="item-chip">${esc(i)}</span>`).join('');
        const more   = items.length > 5 ? `<span class="item-chip">+${items.length-5} more</span>` : '';
        const isPaid = r.status === 'paid';
        return `<div class="receipt-card" onclick="openCreditDetail(${r.credit_id}, event)">
            <div class="receipt-top">
                <span class="receipt-txn">#${esc(r.transaction_number)}</span>
                <span class="receipt-date">${fmtDate(r.transaction_date)}</span>
            </div>
            ${isPaid ? `
            <div class="receipt-amounts">
                <div class="receipt-amt-item"><span class="receipt-amt-label">Status</span><span class="receipt-amt-value" style="color:#16a34a;font-weight:700">Paid</span></div>
            </div>
            ` : `
            <div class="receipt-amounts">
                <div class="receipt-amt-item"><span class="receipt-amt-label">Total</span><span class="receipt-amt-value">₱${fmt(r.total_amount)}</span></div>
                <div class="receipt-amt-item"><span class="receipt-amt-label">Paid</span><span class="receipt-amt-value" style="color:#22c55e">₱${fmt(r.amount_paid)}</span></div>
                <div class="receipt-amt-item"><span class="receipt-amt-label">Due</span><span class="receipt-amt-value" style="color:#ef4444">₱${fmt(r.amount_due)}</span></div>
            </div>
            `}
            <div class="receipt-items-wrap">${chips}${more}</div>
        </div>`;
    }).join('');
}

function closeDrawer() {
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('customerDrawer').classList.remove('open');
    updateRowSelection();
}

// ── Mark All Paid ──
function markAllPaidForCustomer() {
    const unpaidIds = drawerReceipts.filter(r => r.status !== 'paid').map(r => r.credit_id);
    if (!unpaidIds.length || !confirm(`Mark ${unpaidIds.length} credit(s) as fully paid?`)) return;
    fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`action=mark_paid&credit_ids=${encodeURIComponent(JSON.stringify(unpaidIds))}`
    }).then(r => r.json()).then(d => {
        if (d.success) { closeDrawer(); location.reload(); }
        else alert('Error: ' + (d.error||'Unknown'));
    });
}

// ── Credit Detail Modal ──
function openCreditDetail(creditId, e) {
    e.stopPropagation();
    currentCreditId = creditId;
    document.getElementById('detailOverlay').classList.add('active');
    document.getElementById('detailBody').innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px">Loading…</div>';

    fetch(`?action=get_credit_details&credit_id=${creditId}`)
        .then(r => r.json())
        .then(data => {
            const c = data.credit, isPaid = c.status === 'paid';
            const statusBadge = isPaid ? 'badge-paid' : (c.status === 'partial' ? 'badge-partial' : 'badge-unpaid');
            document.getElementById('detailTitle').innerHTML =
                `#${esc(c.transaction_number)} <span class="dim">· ${esc(c.customer_name)}</span>
                 <span class="status-badge ${statusBadge}" style="margin-left:6px">${ucFirst(c.status)}</span>`;
            document.getElementById('detailMarkPaidBtn').style.display = isPaid ? 'none' : '';
            document.getElementById('detailPartialBtn').style.display  = isPaid ? 'none' : '';
            const items = data.items||[];
            const itemsSubtotal = items.reduce((s,i) => s + parseFloat(i.subtotal||i.total_price||0), 0);
            const charge = parseFloat(c.additional_charge||0);
            const rows = items.map(i => `<tr>
                <td>${esc(i.product_name||'—')}</td>
                <td style="text-align:center">${i.quantity}</td>
                <td>₱${fmt(i.price)}</td>
                <td style="text-align:right;font-weight:700;color:#22c55e">₱${fmt(i.subtotal||i.total_price||0)}</td>
            </tr>`).join('');
            document.getElementById('detailBody').innerHTML = `
                <div class="credit-info-grid">
                    <div class="credit-info-box"><div class="label">Total Amount</div><div class="value">₱${fmt(c.total_amount)}</div></div>
                    <div class="credit-info-box"><div class="label">Amount Due</div><div class="value" style="color:#ef4444">₱${fmt(c.amount_due)}</div></div>
                    <div class="credit-info-box"><div class="label">Amount Paid</div><div class="value" style="color:#22c55e">₱${fmt(c.amount_paid)}</div></div>
                </div>
                <table class="items-table"><thead><tr>
                    <th>Product</th><th style="text-align:center">Qty</th><th>Price</th><th style="text-align:right">Subtotal</th>
                </tr></thead><tbody>${rows}</tbody></table>
                <div style="display:flex;flex-direction:column;gap:4px;margin-bottom:16px;padding:0 4px">
                    <div style="display:flex;justify-content:space-between;font-size:13px;color:#64748b">
                        <span>Subtotal</span><span style="font-weight:600;color:#1e293b">₱${fmt(itemsSubtotal)}</span>
                    </div>
                    ${charge > 0 ? `<div style="display:flex;justify-content:space-between;font-size:13px;color:#64748b">
                        <span>Credit Fee</span><span style="font-weight:600;color:#f59e0b">₱${fmt(charge)}</span>
                    </div>` : ''}
                </div>
                <div class="modal-total">
                    <span class="modal-total-label">Amount Due</span>
                    <span class="modal-total-value">₱${fmt(c.amount_due)}</span>
                </div>`;
        })
        .catch(() => { document.getElementById('detailBody').innerHTML = '<p style="color:#dc2626;padding:20px">Error loading credit details.</p>'; });
}

function closeDetailModal() {
    document.getElementById('detailOverlay').classList.remove('active');
    currentCreditId = null;
}

function markSinglePaid() {
    if (!currentCreditId || !confirm('Mark this credit as fully paid?')) return;
    fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`action=mark_paid&credit_ids=${encodeURIComponent(JSON.stringify([currentCreditId]))}`
    }).then(r => r.json()).then(d => {
        if (d.success) { closeDetailModal(); closeDrawer(); location.reload(); }
        else alert('Error: ' + (d.error||'Unknown'));
    });
}

// ── Partial Payment ──
function openPartialPayment() {
    if (!currentCreditId) return;
    const receipt = drawerReceipts.find(r => r.credit_id == currentCreditId);
    document.getElementById('paymentInfo').textContent    = receipt ? `#${receipt.transaction_number}` : '';
    document.getElementById('paymentBalance').textContent = receipt ? '₱' + fmt(receipt.amount_due) : '—';
    document.getElementById('paymentInput').value = '';
    document.getElementById('paymentOverlay').classList.add('active');
    setTimeout(() => document.getElementById('paymentInput').focus(), 100);
}
function closePaymentModal() {
    document.getElementById('paymentOverlay').classList.remove('active');
}
function confirmPayment() {
    const amt = parseFloat(document.getElementById('paymentInput').value);
    if (!amt || amt <= 0) { alert('Please enter a valid amount.'); return; }
    fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`action=partial_payment&credit_id=${currentCreditId}&payment_amount=${amt}`
    }).then(r => r.json()).then(d => {
        if (d.success) { closePaymentModal(); closeDetailModal(); closeDrawer(); location.reload(); }
        else alert('Error: ' + (d.error||'Unknown'));
    });
}

// ── Keyboard ──
document.addEventListener('keydown', e => {
    const paymentOpen  = document.getElementById('paymentOverlay').classList.contains('active');
    const detailOpen   = document.getElementById('detailOverlay').classList.contains('active');
    const drawerOpen   = document.getElementById('customerDrawer').classList.contains('open');

    if (paymentOpen) {
        if (e.key === 'Enter')  { e.preventDefault(); confirmPayment(); }
        if (e.key === 'Escape') { e.preventDefault(); closePaymentModal(); }
        return;
    }
    if (detailOpen) {
        if (e.key === 'Escape') { e.preventDefault(); closeDetailModal(); }
        else if (e.key === 'Enter') { e.preventDefault(); markSinglePaid(); }
        else if (e.key === 'p' || e.key === 'P') { e.preventDefault(); openPartialPayment(); }
        return;
    }
    if (drawerOpen) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedReceiptIdx = Math.min(selectedReceiptIdx + 1, drawerReceipts.length - 1);
            updateReceiptSelection();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedReceiptIdx = Math.max(selectedReceiptIdx - 1, 0);
            updateReceiptSelection();
        } else if (e.key === 'Enter' || e.key === 'v' || e.key === 'V') {
            e.preventDefault();
            openSelectedReceipt();
        } else if (e.key === 'p' || e.key === 'P') {
            e.preventDefault();
            const r = drawerReceipts[selectedReceiptIdx];
            if (r && r.status !== 'paid') { currentCreditId = r.credit_id; openPartialPayment(); }
        } else if (e.key === ' ') {
            e.preventDefault();
            markAllPaidForCustomer();
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

// ── Fee Table ──
let feeData = [];
function loadFees() {
    fetch('?action=get_credit_charges')
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
        body: `action=save_credit_charge&charge_id=${f.charge_id || 0}&category_name=${encodeURIComponent(f.category_name)}&charge_amount=${amt}`
    }).then(r => r.json()).then(d => {
        if (d.success) loadFees();
        else alert('Error: ' + (d.error || 'Failed'));
    });
}
function deleteFee(id, name) {
    if (!confirm('Delete fee for "' + name + '"?')) return;
    fetch('', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=delete_credit_charge&charge_id=' + id
    }).then(r => r.json()).then(d => { if (d.success) loadFees(); else alert('Error'); });
}
loadFees();
</script>
</body>
</html>