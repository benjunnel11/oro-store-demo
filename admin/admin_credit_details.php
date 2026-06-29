<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/sync_helper.php';

$db = new SyncDB();
$currentUser = getCurrentUser();

$userStore = null;
if ($currentUser['store_id']) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $currentUser['store_id']);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
}

// ─── Ensure credit_charge_categories table exists ─────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS credit_charge_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(255) NOT NULL,
    charge_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// ─── AJAX: Get customer credits ───────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_customer_credits') {
    header('Content-Type: application/json');
    try {
        $customer_id = intval($_GET['customer_id']);
        $query = "SELECT c.*, t.transaction_number, t.transaction_date
                  FROM credits c
                  INNER JOIN transactions t ON c.transaction_id = t.id
                  WHERE c.customer_id = ? AND c.is_deleted = 0
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
        $query = "SELECT c.id as credit_id, c.status, c.total_amount, c.amount_paid,
                         c.amount_due, c.additional_charge, c.created_at,
                         t.transaction_number, t.transaction_date, t.total_items,
                         GROUP_CONCAT(ti.product_name, ' x', ti.quantity ORDER BY ti.id SEPARATOR '||') as items_summary
                  FROM credits c
                  INNER JOIN transactions t ON c.transaction_id = t.id
                  LEFT JOIN transaction_items ti ON t.id = ti.transaction_id AND ti.is_deleted = 0
                  WHERE c.customer_id = ? AND c.is_deleted = 0
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
        $stmt = $conn->prepare("SELECT c.*, t.transaction_number, t.transaction_date, t.total_items
                                FROM credits c
                                INNER JOIN transactions t ON c.transaction_id = t.id
                                WHERE c.id = ? AND c.is_deleted = 0");
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

// ─── AJAX: Get credit charge categories ──────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_credit_charges') {
    header('Content-Type: application/json');
    try {
        $search = trim($_GET['search'] ?? '');
        $sql = "SELECT
                    pc.id,
                    pc.category_name,
                    COALESCE(ccc.charge_amount, 0.00) AS charge_amount,
                    COALESCE(ccc.id, 0)               AS charge_id
                FROM product_categories pc
                LEFT JOIN credit_charge_categories ccc
                    ON ccc.category_name = pc.category_name AND ccc.is_deleted = 0
                WHERE pc.is_deleted = 0
                AND EXISTS (SELECT 1 FROM products p WHERE p.category_id = pc.id)";
        if ($search !== '') {
            $like = '%' . $conn->real_escape_string($search) . '%';
            $sql .= " AND pc.category_name LIKE '$like'";
        }
        $sql .= " ORDER BY pc.category_name ASC";
        $res  = $conn->query($sql);
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($rows as &$row) {
            $row['id']            = (int)$row['id'];
            $row['charge_id']     = (int)$row['charge_id'];
            $row['charge_amount'] = (float)$row['charge_amount'];
        }
        echo json_encode($rows);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── POST: Save/update credit charge ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_credit_charge') {
    header('Content-Type: application/json');
    try {
        $charge_id     = intval($_POST['charge_id']      ?? 0);
        $category_name = trim($_POST['category_name']    ?? '');
        $charge_amount = floatval($_POST['charge_amount'] ?? 0);
        if (!$category_name)   throw new Exception('Category name is required');
        if ($charge_amount < 0) throw new Exception('Charge amount cannot be negative');

        if ($charge_id > 0) {
            $stmt = $conn->prepare("UPDATE credit_charge_categories SET charge_amount = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
            $stmt->bind_param("di", $charge_amount, $charge_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare("SELECT id FROM credit_charge_categories WHERE category_name = ? AND is_deleted = 0");
            $stmt->bind_param("s", $category_name);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                $stmt = $conn->prepare("UPDATE credit_charge_categories SET charge_amount = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("di", $charge_amount, $existing['id']);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO credit_charge_categories (category_name, charge_amount, is_deleted, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())");
                $stmt->bind_param("sd", $category_name, $charge_amount);
                $stmt->execute();
                $stmt->close();
            }
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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
            $stmt = $conn->prepare("SELECT transaction_id, total_amount FROM credits WHERE id = ? AND is_deleted = 0");
            $stmt->bind_param("i", $credit_id); $stmt->execute();
            $cr = $stmt->get_result()->fetch_assoc(); $stmt->close();

            $db->update('credits', [
                'amount_paid' => $cr['total_amount'],
                'amount_due'  => 0,
                'status'      => 'paid',
                'paid_at'     => date('Y-m-d H:i:s')
            ], "id = $credit_id");

            if ($cr) $conn->prepare("UPDATE transactions SET status = 'completed' WHERE id = ?")->execute([$cr['transaction_id']]);
        }
        $conn->commit();
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
        $stmt = $conn->prepare("SELECT * FROM credits WHERE id = ? AND is_deleted = 0");
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

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── Main query ───────────────────────────────────────────────────────────────
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

$grandDue    = array_sum(array_column($customers, 'total_due'));
$grandPaid   = array_sum(array_column($customers, 'total_paid'));
$grandCharge = array_sum(array_column($customers, 'total_additional_charge'));
$totalRows   = count($customers);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Credit Management</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d0f14;--surface:#14171f;--surface2:#1c2030;
  --border:#2a2f3d;--border-light:#353b4d;--text:#e8ecf4;--muted:#7a8299;
  --accent:#3b82f6;--accent2:#10b981;--warn:#f59e0b;--danger:#ef4444;--purple:#a78bfa;
  --mono:'IBM Plex Mono',monospace;--sans:'IBM Plex Sans',sans-serif;
}
html{font-size:13px}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;overflow-x:hidden}

/* ── Top Bar ── */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:14px 28px;background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.topbar-title{display:flex;align-items:center;gap:10px}
.topbar-title h1{font-size:16px;font-weight:600;letter-spacing:.5px}
.badge{font-family:var(--mono);font-size:10px;padding:2px 8px;background:rgba(167,139,250,.15);color:var(--purple);border:1px solid rgba(167,139,250,.3);border-radius:3px}
.topbar-actions{display:flex;gap:10px}
.btn{padding:7px 16px;border:none;border-radius:4px;cursor:pointer;font-family:var(--sans);font-size:12px;font-weight:500;transition:all .15s}
.btn-primary{background:var(--accent);color:#fff}.btn-primary:hover{background:#2563eb}
.btn-secondary{background:var(--surface2);color:var(--text);border:1px solid var(--border)}.btn-secondary:hover{background:var(--border)}
.btn-success{background:var(--accent2);color:#fff}.btn-success:hover{background:#059669}
.btn-warn{background:var(--warn);color:#000;font-weight:600}.btn-warn:hover{background:#d97706}
.btn-danger{background:var(--danger);color:#fff}.btn-danger:hover{background:#dc2626}

/* ── Stats Bar ── */
.stats-bar{display:flex;gap:1px;background:var(--border);border-bottom:1px solid var(--border)}
.stat-cell{flex:1;padding:16px 24px;background:var(--surface);display:flex;flex-direction:column;gap:4px}
.stat-label{font-size:10px;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted);font-family:var(--mono)}
.stat-value{font-size:22px;font-weight:600;font-family:var(--mono)}
.stat-value.green{color:var(--accent2)}.stat-value.blue{color:var(--accent)}.stat-value.red{color:var(--danger)}.stat-value.amber{color:var(--warn)}.stat-value.purple{color:var(--purple)}

/* ── Toolbar ── */
.toolbar{display:flex;align-items:center;gap:12px;padding:12px 28px;background:var(--surface);border-bottom:1px solid var(--border)}
.search-wrap{position:relative;flex:1;max-width:380px}
.search-wrap input{width:100%;padding:8px 12px 8px 34px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-family:var(--sans);font-size:12px;outline:none;transition:border .15s}
.search-wrap input:focus{border-color:var(--accent)}
.search-wrap::before{content:'⌕';position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:15px;pointer-events:none}
.filter-btn{padding:6px 12px;font-size:11px;font-family:var(--mono);background:var(--surface2);border:1px solid var(--border);color:var(--muted);border-radius:3px;cursor:pointer;transition:all .15s}
.filter-btn.active,.filter-btn:hover{background:rgba(167,139,250,.12);border-color:var(--purple);color:var(--purple)}

/* ── Table ── */
.table-wrap{overflow-x:auto;padding:0 28px 40px}
table{width:100%;border-collapse:collapse;margin-top:16px}
thead tr{background:var(--surface2);border-bottom:2px solid var(--purple)}
thead th{padding:10px 14px;text-align:left;font-family:var(--mono);font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);white-space:nowrap;cursor:pointer;user-select:none}
thead th:hover{color:var(--text)}
thead th.sorted{color:var(--purple)}
thead th .sa{margin-left:4px;opacity:.6}
tbody tr{border-bottom:1px solid var(--border);cursor:pointer;transition:background .1s}
tbody tr:hover{background:var(--surface2)}
tbody tr:hover .name-cell{color:var(--purple)}
tbody td{padding:11px 14px;font-size:12px;vertical-align:middle}
.name-cell{font-weight:600;transition:color .15s}
.mono{font-family:var(--mono);font-size:11px}
.addr{color:var(--muted);font-size:11px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.contact{color:var(--muted);font-size:11px}
.amount-due{color:var(--danger);font-family:var(--mono);font-weight:600}
.amount-paid{color:var(--accent2);font-family:var(--mono)}
.amount-charge{color:var(--warn);font-family:var(--mono)}
.tag{display:inline-block;padding:2px 7px;border-radius:2px;font-family:var(--mono);font-size:10px;font-weight:600;letter-spacing:.5px}
.tag-unpaid{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
.tag-partial{background:rgba(245,158,11,.12);color:var(--warn);border:1px solid rgba(245,158,11,.3)}
.tag-paid{background:rgba(16,185,129,.12);color:var(--accent2);border:1px solid rgba(16,185,129,.3)}
.count-pill{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:rgba(167,139,250,.12);color:var(--purple);font-family:var(--mono);font-size:11px;font-weight:600}
.row-arrow{color:var(--muted);font-size:16px;transition:transform .15s}
tbody tr:hover .row-arrow{transform:translateX(4px);color:var(--purple)}
.empty-state{text-align:center;padding:60px 20px;color:var(--muted);font-family:var(--mono);font-size:12px}

/* ── Customer Drawer ── */
.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);z-index:200;opacity:0;pointer-events:none;transition:opacity .25s}
.drawer-overlay.open{opacity:1;pointer-events:all}
.drawer{position:fixed;top:0;right:0;bottom:0;width:700px;max-width:95vw;background:var(--surface);border-left:1px solid var(--border);z-index:201;transform:translateX(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column}
.drawer.open{transform:translateX(0)}
.drawer-head{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;flex-shrink:0}
.drawer-head-info h2{font-size:16px;font-weight:600;margin-bottom:3px}
.drawer-head-info p{font-size:11px;color:var(--muted)}
.drawer-close{background:none;border:1px solid var(--border);color:var(--muted);width:32px;height:32px;border-radius:4px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.drawer-close:hover{background:var(--danger);color:#fff;border-color:var(--danger)}
.drawer-stats{display:flex;gap:1px;background:var(--border);border-bottom:1px solid var(--border);flex-shrink:0}
.drawer-stat{flex:1;padding:12px 16px;background:var(--surface2);display:flex;flex-direction:column;gap:3px}
.drawer-stat-label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);font-family:var(--mono)}
.drawer-stat-value{font-size:15px;font-weight:600;font-family:var(--mono)}
.drawer-body{flex:1;overflow-y:auto;padding:0}
.receipt-card{border-bottom:1px solid var(--border);padding:16px 24px;cursor:pointer;transition:background .1s}
.receipt-card:hover{background:var(--surface2)}
.receipt-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
.receipt-txn{font-family:var(--mono);font-size:12px;color:var(--purple);font-weight:600}
.receipt-date{font-family:var(--mono);font-size:11px;color:var(--muted)}
.receipt-amounts{display:flex;gap:16px;margin-bottom:8px;flex-wrap:wrap}
.receipt-amt-item{display:flex;flex-direction:column;gap:2px}
.receipt-amt-label{font-size:10px;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);font-family:var(--mono)}
.receipt-amt-value{font-family:var(--mono);font-size:13px;font-weight:600}
.receipt-items-wrap{display:flex;flex-wrap:wrap;gap:4px;margin-top:8px}
.item-chip{padding:2px 8px;background:var(--surface);border:1px solid var(--border);border-radius:2px;font-family:var(--mono);font-size:10px;color:var(--muted)}
.drawer-loading{display:flex;align-items:center;justify-content:center;height:200px;color:var(--muted);font-family:var(--mono);font-size:12px;gap:10px}
.spinner{width:18px;height:18px;border:2px solid var(--border);border-top-color:var(--purple);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.drawer-foot{padding:14px 24px;border-top:1px solid var(--border);display:flex;gap:10px;flex-shrink:0}

/* ── Detail Modal ── */
.detail-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:300;display:none;align-items:center;justify-content:center}
.detail-overlay.open{display:flex}
.detail-modal{background:var(--surface);border:1px solid var(--border);border-radius:6px;width:580px;max-width:95vw;max-height:82vh;display:flex;flex-direction:column}
.detail-modal-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.detail-modal-head h3{font-size:14px;font-weight:600}
.detail-modal-body{overflow-y:auto;padding:20px;flex:1}
.detail-modal-foot{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end}
.items-table{width:100%;border-collapse:collapse;font-size:12px}
.items-table th{padding:8px 10px;text-align:left;background:var(--surface2);font-family:var(--mono);font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);border-bottom:1px solid var(--border)}
.items-table td{padding:9px 10px;border-bottom:1px solid var(--border)}
.items-table tr:last-child td{border-bottom:none}
.credit-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.credit-info-box{background:var(--surface2);border:1px solid var(--border);border-radius:4px;padding:12px}
.credit-info-box .label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);font-family:var(--mono);margin-bottom:4px}
.credit-info-box .value{font-size:15px;font-weight:600;font-family:var(--mono)}

/* ── Partial Payment Modal ── */
.payment-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:400;display:none;align-items:center;justify-content:center}
.payment-overlay.open{display:flex}
.payment-modal{background:var(--surface);border:1px solid var(--border);border-radius:6px;width:420px;max-width:95vw;padding:24px}
.payment-modal h3{font-size:14px;font-weight:600;margin-bottom:16px}
.payment-modal input{width:100%;padding:10px 12px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-family:var(--mono);font-size:14px;outline:none;margin-bottom:16px;transition:border .15s}
.payment-modal input:focus{border-color:var(--purple)}
.payment-modal-foot{display:flex;gap:10px;justify-content:flex-end}

/* ── Credit Charge Modal (mirrors Delivery Fee modal exactly) ── */
.charge-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:400;display:none;align-items:center;justify-content:center}
.charge-overlay.open{display:flex}
.charge-modal{background:var(--surface);border:1px solid var(--border);border-radius:6px;width:480px;max-width:96vw;max-height:85vh;display:flex;flex-direction:column}
.charge-modal-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.charge-modal-head h3{font-size:14px;font-weight:600}
.charge-search-bar{padding:12px 20px;border-bottom:1px solid var(--border);flex-shrink:0;position:relative}
.charge-search-bar input{width:100%;padding:8px 12px 8px 32px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-family:var(--sans);font-size:12px;outline:none;transition:border .15s}
.charge-search-bar input:focus{border-color:var(--purple)}
.charge-search-bar::before{content:'⌕';position:absolute;left:30px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:15px;pointer-events:none}
.charge-list{flex:1;overflow-y:auto}
.charge-row{display:grid;grid-template-columns:1fr 140px 80px;align-items:center;gap:10px;padding:11px 20px;border-bottom:1px solid var(--border);transition:background .1s}
.charge-row:hover{background:var(--surface2)}
.charge-row.header{background:var(--surface2);border-bottom:2px solid var(--purple);font-family:var(--mono);font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);pointer-events:none}
.charge-cat-name{font-weight:600;font-size:12px}
.charge-amount{font-family:var(--mono);font-size:13px;font-weight:600;color:var(--purple)}
.charge-edit-row{display:none;grid-template-columns:1fr 140px auto;gap:8px;align-items:center;padding:10px 20px;background:rgba(167,139,250,.06);border-bottom:1px solid var(--border)}
.charge-edit-row.open{display:grid}
.charge-edit-row input{padding:6px 10px;background:var(--surface);border:1px solid var(--border);border-radius:4px;color:var(--text);font-family:var(--sans);font-size:12px;outline:none;width:100%;transition:border .15s}
.charge-edit-row input:focus{border-color:var(--purple)}
.charge-empty{padding:40px;text-align:center;color:var(--muted);font-family:var(--mono);font-size:12px}

::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--surface)}
::-webkit-scrollbar-thumb{background:var(--border-light);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--muted)}
</style>
</head>
<body>

<!-- ── Top Bar ── -->
<div class="topbar">
  <div class="topbar-title">
    <h1>💳 Credit Management</h1>
    <?php if ($userStore): ?>
      <span class="badge">🏪 <?php echo htmlspecialchars($userStore['store_name']); ?></span>
    <?php endif; ?>
  </div>
  <div class="topbar-actions">
    <button class="btn btn-warn" onclick="openCreditChargeModal()" style="display:flex;align-items:center;gap:6px">
      💳 <span>Credit Charges</span>
    </button>
    <button class="btn btn-secondary" onclick="location.href='/oro-store/credit/credit.php'">+ New Credit</button>
    <button class="btn btn-primary" onclick="location.href='/oro-store/cashier/cashier.php'">← Cashier</button>
  </div>
</div>

<!-- ── Stats Bar ── -->
<div class="stats-bar">
  <div class="stat-cell">
    <span class="stat-label">Customers</span>
    <span class="stat-value purple"><?php echo $totalRows; ?></span>
  </div>
  <div class="stat-cell">
    <span class="stat-label">Total Credits</span>
    <span class="stat-value"><?php echo array_sum(array_column($customers,'total_credits')); ?></span>
  </div>
  <div class="stat-cell">
    <span class="stat-label">Total Due</span>
    <span class="stat-value red">₱<?php echo number_format($grandDue,2); ?></span>
  </div>
  <div class="stat-cell">
    <span class="stat-label">Total Paid</span>
    <span class="stat-value green">₱<?php echo number_format($grandPaid,2); ?></span>
  </div>
  <div class="stat-cell">
    <span class="stat-label">Additional Charges</span>
    <span class="stat-value amber">₱<?php echo number_format($grandCharge,2); ?></span>
  </div>
</div>

<!-- ── Toolbar ── -->
<div class="toolbar">
  <div class="search-wrap">
    <input type="text" id="searchInput" placeholder="Search customer, contact, address…" oninput="filterTable()" autofocus>
  </div>
  <div style="display:flex;gap:4px">
    <button class="filter-btn active" onclick="setFilter(this,'all')">All</button>
    <button class="filter-btn" onclick="setFilter(this,'unpaid')">Unpaid</button>
    <button class="filter-btn" onclick="setFilter(this,'partial')">Partial</button>
    <button class="filter-btn" onclick="setFilter(this,'paid')">Paid</button>
  </div>
</div>

<!-- ── Table ── -->
<div class="table-wrap">
  <table id="mainTable">
    <thead>
      <tr>
        <th onclick="sortTable(0)">Customer <span class="sa">↕</span></th>
        <th onclick="sortTable(1)">Contact <span class="sa">↕</span></th>
        <th onclick="sortTable(2)">Location <span class="sa">↕</span></th>
        <th onclick="sortTable(3)" style="text-align:center">Credits <span class="sa">↕</span></th>
        <th onclick="sortTable(4)">Total Transacted <span class="sa">↕</span></th>
        <th onclick="sortTable(5)">Total Paid <span class="sa">↕</span></th>
        <th onclick="sortTable(6)">Add'l Charges <span class="sa">↕</span></th>
        <th onclick="sortTable(7)">Amount Due <span class="sa">↕</span></th>
        <th onclick="sortTable(8)">Last Credit Date <span class="sa">↕</span></th>
        <th onclick="sortTable(9)">Last Amount <span class="sa">↕</span></th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody id="tableBody">
      <?php if (empty($customers)): ?>
      <tr><td colspan="12">
        <div class="empty-state"><div style="font-size:40px;margin-bottom:12px">💳</div>No credit records found.</div>
      </td></tr>
      <?php else: foreach ($customers as $c):
        $hasUnpaid  = $c['unpaid_count'] > 0;
        $hasPartial = $c['partial_count'] > 0;
        $displayStatus = $hasUnpaid ? 'unpaid' : ($hasPartial ? 'partial' : 'paid');
        $tagClass = $displayStatus === 'paid' ? 'tag-paid' : ($displayStatus === 'partial' ? 'tag-partial' : 'tag-unpaid');
      ?>
      <tr onclick="openCustomerDrawer(<?php echo $c['customer_id']; ?>,'<?php echo htmlspecialchars(addslashes($c['customer_name'])); ?>','<?php echo htmlspecialchars(addslashes($c['customer_contact'])); ?>','<?php echo htmlspecialchars(addslashes($c['customer_address']??'')); ?>')"
          data-status="<?php echo $displayStatus; ?>">
        <td class="name-cell"><?php echo htmlspecialchars($c['customer_name']); ?></td>
        <td class="contact"><?php echo htmlspecialchars($c['customer_contact']); ?></td>
        <td class="addr" title="<?php echo htmlspecialchars($c['customer_address']??''); ?>"><?php echo htmlspecialchars($c['customer_address']??'—'); ?></td>
        <td style="text-align:center"><span class="count-pill"><?php echo (int)$c['total_credits']; ?></span></td>
        <td class="mono">₱<?php echo number_format($c['total_transaction_amount'],2); ?></td>
        <td class="amount-paid">₱<?php echo number_format($c['total_paid'],2); ?></td>
        <td class="amount-charge">₱<?php echo number_format($c['total_additional_charge'],2); ?></td>
        <td class="amount-due">₱<?php echo number_format($c['total_due'],2); ?></td>
        <td class="mono"><?php echo $c['last_credit_date'] ? date('M d, Y', strtotime($c['last_credit_date'])) : '—'; ?></td>
        <td class="mono">₱<?php echo number_format($c['last_transaction_amount'],2); ?></td>
        <td><span class="tag <?php echo $tagClass; ?>"><?php echo strtoupper($displayStatus); ?></span></td>
        <td><span class="row-arrow">›</span></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<!-- ── Customer Drawer ── -->
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
      <span class="drawer-stat-value" id="dStatTotal" style="color:var(--text)">—</span>
    </div>
    <div class="drawer-stat">
      <span class="drawer-stat-label">Total Due</span>
      <span class="drawer-stat-value" id="dStatDue" style="color:var(--danger)">—</span>
    </div>
    <div class="drawer-stat">
      <span class="drawer-stat-label">Total Paid</span>
      <span class="drawer-stat-value" id="dStatPaid" style="color:var(--accent2)">—</span>
    </div>
    <div class="drawer-stat">
      <span class="drawer-stat-label">Transactions</span>
      <span class="drawer-stat-value" id="dStatCount" style="color:var(--purple)">—</span>
    </div>
  </div>
  <div class="drawer-body" id="drawerBody">
    <div class="drawer-loading"><div class="spinner"></div> Loading receipts…</div>
  </div>
  <div class="drawer-foot">
    <button class="btn btn-success" id="drawerMarkPaidBtn" onclick="markAllPaidForCustomer()" style="display:none">✓ Mark All Unpaid as Paid</button>
  </div>
</div>

<!-- ── Credit Detail Modal ── -->
<div class="detail-overlay" id="detailOverlay">
  <div class="detail-modal">
    <div class="detail-modal-head">
      <h3 id="detailTitle">Credit Details</h3>
      <button class="drawer-close" onclick="closeDetailModal()">×</button>
    </div>
    <div class="detail-modal-body" id="detailBody">Loading…</div>
    <div class="detail-modal-foot">
      <button class="btn btn-warn" id="detailPartialBtn" onclick="openPartialPayment()">Partial Payment</button>
      <button class="btn btn-success" id="detailMarkPaidBtn" onclick="markSinglePaid()">Mark as Paid</button>
      <button class="btn btn-secondary" onclick="closeDetailModal()">Close</button>
    </div>
  </div>
</div>

<!-- ── Partial Payment Modal ── -->
<div class="payment-overlay" id="paymentOverlay">
  <div class="payment-modal">
    <h3>💰 Partial Payment</h3>
    <p id="paymentInfo" style="font-size:12px;color:var(--muted);margin-bottom:4px"></p>
    <p style="margin-bottom:12px;font-size:12px">Remaining: <span id="paymentBalance" style="color:var(--danger);font-family:var(--mono);font-weight:600"></span></p>
    <input type="number" id="paymentInput" step="0.01" min="0" placeholder="Enter payment amount">
    <div class="payment-modal-foot">
      <button class="btn btn-secondary" onclick="closePaymentModal()">Cancel</button>
      <button class="btn btn-success" onclick="confirmPayment()">Confirm Payment</button>
    </div>
  </div>
</div>

<!-- ── Credit Charge Modal ── -->
<div class="charge-overlay" id="chargeOverlay">
  <div class="charge-modal">
    <div class="charge-modal-head">
      <h3>💳 Credit Charge Categories</h3>
      <button class="drawer-close" onclick="closeCreditChargeModal()">×</button>
    </div>
    <div class="charge-search-bar">
      <input type="text" id="chargeSearch" placeholder="Search category…" oninput="loadCharges(this.value)">
    </div>
    <div class="charge-list" id="chargeList">
      <div class="charge-empty"><div style="font-size:32px;margin-bottom:8px">💳</div>Loading…</div>
    </div>
  </div>
</div>

<script>
/* ── State ── */
let currentFilter    = 'all';
let sortCol          = 8, sortAsc = false;
let currentCreditId  = null;
let currentCustomerId = null;
let drawerReceipts   = [];
let chargeCache      = {}; // mirrors feeCache in delivery_details

/* ── Filter / Search ── */
function filterTable() {
  const q = document.getElementById('searchInput').value.toLowerCase();
  document.querySelectorAll('#tableBody tr').forEach(tr => {
    const text   = tr.textContent.toLowerCase();
    const status = tr.dataset.status || '';
    tr.style.display = (text.includes(q) && (currentFilter === 'all' || status === currentFilter)) ? '' : 'none';
  });
}
function setFilter(btn, filter) {
  currentFilter = filter;
  document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  filterTable();
}

/* ── Sort ── */
function sortTable(col) {
  const tbody = document.getElementById('tableBody');
  const rows  = Array.from(tbody.querySelectorAll('tr'));
  if (sortCol === col) sortAsc = !sortAsc; else { sortCol = col; sortAsc = true; }
  document.querySelectorAll('thead th').forEach((th, i) => {
    th.classList.toggle('sorted', i === col);
    const sa = th.querySelector('.sa');
    if (sa) sa.textContent = i === col ? (sortAsc ? '↑' : '↓') : '↕';
  });
  rows.sort((a, b) => {
    const aV = a.cells[col]?.textContent.trim() || '';
    const bV = b.cells[col]?.textContent.trim() || '';
    const aN = parseFloat(aV.replace(/[₱,]/g,''));
    const bN = parseFloat(bV.replace(/[₱,]/g,''));
    if (!isNaN(aN) && !isNaN(bN)) return sortAsc ? aN - bN : bN - aN;
    return sortAsc ? aV.localeCompare(bV) : bV.localeCompare(aV);
  });
  rows.forEach(r => tbody.appendChild(r));
}

/* ── Customer Drawer ── */
function openCustomerDrawer(id, name, contact, addr) {
  currentCustomerId = id;
  document.getElementById('drawerName').textContent    = name;
  document.getElementById('drawerContact').textContent = '📞 ' + (contact || '—');
  document.getElementById('drawerAddr').textContent    = addr ? '📍 ' + addr : '';
  ['dStatTotal','dStatDue','dStatPaid','dStatCount'].forEach(id => document.getElementById(id).textContent = '—');
  document.getElementById('drawerMarkPaidBtn').style.display = 'none';
  document.getElementById('drawerBody').innerHTML = '<div class="drawer-loading"><div class="spinner"></div> Loading…</div>';
  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('customerDrawer').classList.add('open');

  fetch(`?action=get_customer_receipts&customer_id=${id}`)
    .then(r => r.json())
    .then(data => { drawerReceipts = data; renderDrawer(data); })
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

  const html = receipts.map(r => {
    const items  = (r.items_summary || '').split('||').filter(Boolean);
    const chips  = items.slice(0,5).map(i => `<span class="item-chip">${esc(i)}</span>`).join('');
    const more   = items.length > 5 ? `<span class="item-chip">+${items.length-5} more</span>` : '';
    const tc     = r.status === 'paid' ? 'tag-paid' : r.status === 'partial' ? 'tag-partial' : 'tag-unpaid';
    const charge = parseFloat(r.additional_charge||0);
    return `
    <div class="receipt-card" onclick="openCreditDetail(${r.credit_id}, event)">
      <div class="receipt-top">
        <span class="receipt-txn">#${esc(r.transaction_number)}</span>
        <div style="display:flex;align-items:center;gap:8px">
          <span class="tag ${tc}">${(r.status||'unpaid').toUpperCase()}</span>
          <span class="receipt-date">${fmtDate(r.transaction_date)}</span>
        </div>
      </div>
      <div class="receipt-amounts">
        <div class="receipt-amt-item">
          <span class="receipt-amt-label">Total</span>
          <span class="receipt-amt-value mono">₱${fmt(r.total_amount)}</span>
        </div>
        <div class="receipt-amt-item">
          <span class="receipt-amt-label">Paid</span>
          <span class="receipt-amt-value" style="color:var(--accent2)">₱${fmt(r.amount_paid)}</span>
        </div>
        <div class="receipt-amt-item">
          <span class="receipt-amt-label">Due</span>
          <span class="receipt-amt-value" style="color:var(--danger)">₱${fmt(r.amount_due)}</span>
        </div>
      </div>
      <div class="receipt-items-wrap">${chips}${more}</div>
    </div>`;
  }).join('');
  document.getElementById('drawerBody').innerHTML = html;
}

function closeDrawer() {
  document.getElementById('drawerOverlay').classList.remove('open');
  document.getElementById('customerDrawer').classList.remove('open');
}

/* ── Mark All Paid ── */
function markAllPaidForCustomer() {
  const unpaidIds = drawerReceipts.filter(r => r.status !== 'paid').map(r => r.credit_id);
  if (!unpaidIds.length || !confirm(`Mark ${unpaidIds.length} credit(s) as fully paid?`)) return;
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=mark_paid&credit_ids=${encodeURIComponent(JSON.stringify(unpaidIds))}`
  }).then(r => r.json()).then(d => {
    if (d.success) { closeDrawer(); location.reload(); }
    else alert('Error: ' + (d.error||'Unknown'));
  });
}

/* ── Credit Detail Modal ── */
function openCreditDetail(creditId, e) {
  e.stopPropagation();
  currentCreditId = creditId;
  document.getElementById('detailOverlay').classList.add('open');
  document.getElementById('detailBody').innerHTML = '<div style="padding:20px;color:var(--muted);font-family:var(--mono)">Loading…</div>';

  fetch(`?action=get_credit_details&credit_id=${creditId}`)
    .then(r => r.json())
    .then(data => {
      const c      = data.credit;
      const isPaid = c.status === 'paid';
      document.getElementById('detailTitle').textContent         = `Receipt #${c.transaction_number}`;
      document.getElementById('detailMarkPaidBtn').style.display = isPaid ? 'none' : '';
      document.getElementById('detailPartialBtn').style.display  = isPaid ? 'none' : '';
      const rows   = (data.items||[]).map(i => `
        <tr>
          <td>${esc(i.product_name||'—')}</td>
          <td class="mono" style="text-align:center">${i.quantity}</td>
          <td class="mono">₱${fmt(i.price)}</td>
          <td class="mono amount-paid">₱${fmt(i.subtotal||i.total_price||0)}</td>
        </tr>`).join('');
      document.getElementById('detailBody').innerHTML = `
        <div class="credit-info-grid">
          <div class="credit-info-box">
            <div class="label">Total Amount</div>
            <div class="value mono">₱${fmt(c.total_amount)}</div>
          </div>
          <div class="credit-info-box">
            <div class="label">Amount Due</div>
            <div class="value" style="color:var(--danger)">₱${fmt(c.amount_due)}</div>
          </div>
          <div class="credit-info-box">
            <div class="label">Amount Paid</div>
            <div class="value" style="color:var(--accent2)">₱${fmt(c.amount_paid)}</div>
          </div>
        </div>
        <table class="items-table">
          <thead><tr>
            <th>Product</th><th style="text-align:center">Qty</th><th>Price</th><th>Subtotal</th>
          </tr></thead>
          <tbody>${rows}</tbody>
        </table>
        <div style="display:flex;justify-content:space-between;font-size:14px;font-weight:700;padding:12px 4px 0;margin-top:12px;border-top:1px solid #e2e8f0">
          <span>Amount Due</span><span style="color:var(--danger)">₱${fmt(c.amount_due)}</span>
        </div>`;
    })
    .catch(() => { document.getElementById('detailBody').innerHTML = '<div style="padding:20px;color:var(--danger)">Failed to load.</div>'; });
}

function closeDetailModal() {
  document.getElementById('detailOverlay').classList.remove('open');
  currentCreditId = null;
}

function markSinglePaid() {
  if (!currentCreditId || !confirm('Mark this credit as fully paid?')) return;
  fetch('', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=mark_paid&credit_ids=${encodeURIComponent(JSON.stringify([currentCreditId]))}`
  }).then(r => r.json()).then(d => {
    if (d.success) { closeDetailModal(); closeDrawer(); location.reload(); }
    else alert('Error: ' + (d.error||'Unknown'));
  });
}

/* ── Partial Payment ── */
function openPartialPayment() {
  if (!currentCreditId) return;
  const receipt = drawerReceipts.find(r => r.credit_id == currentCreditId);
  document.getElementById('paymentInfo').textContent    = receipt ? `Transaction #${receipt.transaction_number}` : '';
  document.getElementById('paymentBalance').textContent = receipt ? '₱' + fmt(receipt.amount_due) : '—';
  document.getElementById('paymentInput').value = '';
  document.getElementById('paymentOverlay').classList.add('open');
  setTimeout(() => document.getElementById('paymentInput').focus(), 100);
}
function closePaymentModal() { document.getElementById('paymentOverlay').classList.remove('open'); }
function confirmPayment() {
  const amt = parseFloat(document.getElementById('paymentInput').value);
  if (!amt || amt <= 0) { alert('Please enter a valid amount.'); return; }
  fetch('', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=partial_payment&credit_id=${currentCreditId}&payment_amount=${amt}`
  }).then(r => r.json()).then(d => {
    if (d.success) { closePaymentModal(); closeDetailModal(); closeDrawer(); location.reload(); }
    else alert('Error: ' + (d.error||'Unknown'));
  });
}

/* ══════════════════════════════════════════════════════════════════════
   CREDIT CHARGE MODAL — mirrors Delivery Fee modal exactly
   ══════════════════════════════════════════════════════════════════════ */
function openCreditChargeModal() {
  document.getElementById('chargeOverlay').classList.add('open');
  document.getElementById('chargeSearch').value = '';
  loadCharges('');
}
function closeCreditChargeModal() {
  document.getElementById('chargeOverlay').classList.remove('open');
}

let chargeDebounce = null;
function loadCharges(search) {
  clearTimeout(chargeDebounce);
  chargeDebounce = setTimeout(() => {
    document.getElementById('chargeList').innerHTML = '<div class="charge-empty"><div class="spinner" style="margin:0 auto"></div></div>';
    fetch(`?action=get_credit_charges&search=${encodeURIComponent(search || '')}`)
      .then(r => r.json())
      .then(charges => renderCharges(charges))
      .catch(() => { document.getElementById('chargeList').innerHTML = '<div class="charge-empty">Failed to load.</div>'; });
  }, 200);
}

function renderCharges(charges) {
  // Store all data in JS cache — no special chars in onclick attributes
  chargeCache = {};
  charges.forEach(c => { chargeCache[c.id] = c; });

  const list = document.getElementById('chargeList');
  if (!charges.length) {
    list.innerHTML = '<div class="charge-empty">No categories found.</div>';
    return;
  }

  const header = `<div class="charge-row header">
    <div>Category</div><div>Charge Amount</div><div></div>
  </div>`;

  const rows = charges.map(c => {
    const fmtAmt = c.charge_amount.toLocaleString('en', {minimumFractionDigits: 2});
    return `
    <div>
      <div class="charge-row">
        <div class="charge-cat-name">${esc(c.category_name)}</div>
        <div class="charge-amount">₱${fmtAmt}</div>
        <div>
          <button class="btn btn-secondary" style="padding:4px 10px;font-size:11px"
            onclick="openEditCharge(${c.id})">✏️ Edit</button>
        </div>
      </div>
      <div class="charge-edit-row" id="charge-edit-${c.id}">
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.8px">Category</span>
          <input type="text" id="charge-edit-cat-${c.id}" readonly
            style="color:var(--muted);cursor:not-allowed;background:var(--surface2)">
        </div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.8px">Charge (₱)</span>
          <input type="number" id="charge-edit-amt-${c.id}" step="0.01" min="0" placeholder="0.00">
        </div>
        <div style="display:flex;gap:6px;align-self:flex-end">
          <button class="btn btn-success" style="padding:5px 14px;font-size:11px"
            onclick="saveChargeEdit(${c.id})">Save</button>
          <button class="btn btn-secondary" style="padding:5px 9px;font-size:11px"
            onclick="closeEditCharge(${c.id})">✕</button>
        </div>
      </div>
    </div>`;
  }).join('');

  list.innerHTML = header + rows;
}

function openEditCharge(id) {
  // Close all open edit rows first
  document.querySelectorAll('.charge-edit-row.open').forEach(el => el.classList.remove('open'));
  const c = chargeCache[id];
  if (!c) return;
  document.getElementById(`charge-edit-cat-${id}`).value = c.category_name;
  document.getElementById(`charge-edit-amt-${id}`).value = c.charge_amount;
  document.getElementById(`charge-edit-${id}`).classList.add('open');
  document.getElementById(`charge-edit-amt-${id}`).focus();
}

function closeEditCharge(id) {
  document.getElementById(`charge-edit-${id}`).classList.remove('open');
}

function saveChargeEdit(id) {
  const c = chargeCache[id];
  if (!c) return;
  const amt = parseFloat(document.getElementById(`charge-edit-amt-${id}`).value);
  if (isNaN(amt) || amt < 0) { alert('Please enter a valid charge amount.'); return; }

  const body = new URLSearchParams({
    action:        'save_credit_charge',
    charge_id:     c.charge_id,       // credit_charge_categories.id (0 = insert new)
    category_name: c.category_name,
    charge_amount: amt
  });

  fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body})
    .then(r => r.json())
    .then(d => {
      if (d.success) loadCharges(document.getElementById('chargeSearch').value);
      else alert('Error: ' + (d.error||'Unknown'));
    })
    .catch(() => alert('Network error, please try again.'));
}

/* ── Helpers ── */
function fmt(n) { return parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(s) { if (!s) return '—'; return new Date(s).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ── Keyboard ── */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    if (document.getElementById('chargeOverlay').classList.contains('open'))   { closeCreditChargeModal(); return; }
    if (document.getElementById('paymentOverlay').classList.contains('open'))  { closePaymentModal();       return; }
    if (document.getElementById('detailOverlay').classList.contains('open'))   { closeDetailModal();        return; }
    if (document.getElementById('customerDrawer').classList.contains('open'))  { closeDrawer();             return; }
  }
  if (e.key === 'Enter' && document.getElementById('paymentOverlay').classList.contains('open')) confirmPayment();
});

/* ── Initial sort ── */
sortTable(7); // sort by amount due desc
</script>
</body>
</html>