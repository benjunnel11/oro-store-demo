<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/sync_helper.php';

$db = new SyncDB();
$currentUser = getCurrentUser();

$isAdmin = in_array($currentUser['role'], ['admin', 'super_admin']);

$userStore = null;
if ($currentUser['store_id'] && !$isAdmin) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $currentUser['store_id']);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ─── Ensure delivery_fee_categories table exists ──────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS delivery_fee_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(255) NOT NULL,
    fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// ─── AJAX: Get delivery items ───────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_delivery') {
    header('Content-Type: application/json');
    try {
        $delivery_id = intval($_GET['delivery_id']);
        $query = "SELECT di.*, d.recipient_name, d.recipient_address, d.status as delivery_status,
                  t.transaction_number, t.total_amount, s.store_name as store_name
                  FROM delivery_items di
                  INNER JOIN deliveries d ON di.delivery_id = d.id
                  INNER JOIN transactions t ON d.transaction_id = t.id
                  LEFT JOIN stores s ON d.store_id = s.id
                  WHERE di.delivery_id = ? AND di.is_deleted = 0";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $delivery_id);
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
            $item['recipient_name']     = $item['recipient_name'] ?? '';
            $item['recipient_address']  = $item['recipient_address'] ?? '';
            $item['transaction_number'] = $item['transaction_number'] ?? '';
            $item['store_name']         = $item['store_name'] ?? 'Unknown Store';
        }
        echo json_encode($items);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── AJAX: Get all receipts/deliveries for a customer (by recipient_name) ───
if (isset($_GET['action']) && $_GET['action'] === 'get_customer_receipts') {
    header('Content-Type: application/json');
    try {
        $recipient = trim($_GET['recipient'] ?? '');
        $query = "SELECT d.id as delivery_id, d.status, d.created_at, d.recipient_address,
                         t.transaction_number, t.total_amount, t.transaction_date,
                         COUNT(di.id) as item_count,
                         s.store_name,
                         GROUP_CONCAT(CONCAT(di.product_name, ' x', di.quantity_delivered) ORDER BY di.id SEPARATOR '||') as items_summary
                  FROM deliveries d
                  INNER JOIN transactions t ON d.transaction_id = t.id
                  LEFT JOIN delivery_items di ON d.id = di.delivery_id AND di.is_deleted = 0
                  LEFT JOIN stores s ON d.store_id = s.id
                  WHERE d.is_deleted = 0 AND d.recipient_name = ?
                  GROUP BY d.id
                  ORDER BY t.transaction_date DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $recipient);
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

// ─── AJAX: Get delivery fee categories ───────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_delivery_fees') {
    header('Content-Type: application/json');
    try {
        $search = trim($_GET['search'] ?? '');
        $sql = "SELECT
                    pc.id,
                    pc.category_name,
                    COALESCE(dfc.fee_amount, 0.00) AS fee_amount,
                    COALESCE(dfc.id, 0)            AS fee_id
                FROM product_categories pc
                LEFT JOIN delivery_fee_categories dfc
                    ON dfc.category_name = pc.category_name AND dfc.is_deleted = 0
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
            $row['id']         = (int)$row['id'];
            $row['fee_id']     = (int)$row['fee_id'];
            $row['fee_amount'] = (float)$row['fee_amount'];
        }
        echo json_encode($rows);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── AJAX: Save/update delivery fee ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_delivery_fee') {
    header('Content-Type: application/json');
    try {
        $fee_id        = intval($_POST['fee_id']      ?? 0);
        $category_name = trim($_POST['category_name'] ?? '');
        $fee_amount    = floatval($_POST['fee_amount'] ?? 0);
        if (!$category_name) throw new Exception('Category name is required');
        if ($fee_amount < 0)  throw new Exception('Fee amount cannot be negative');

        if ($fee_id > 0) {
            // Existing row — update fee_amount only
            $stmt = $conn->prepare("UPDATE delivery_fee_categories SET fee_amount = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
            $stmt->bind_param("di", $fee_amount, $fee_id);
            $stmt->execute();
            $stmt->close();
        } else {
            // No row yet for this category — check first to avoid duplicates
            $stmt = $conn->prepare("SELECT id FROM delivery_fee_categories WHERE category_name = ? AND is_deleted = 0");
            $stmt->bind_param("s", $category_name);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                // Row exists (race condition) — just update it
                $stmt = $conn->prepare("UPDATE delivery_fee_categories SET fee_amount = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("di", $fee_amount, $existing['id']);
                $stmt->execute();
                $stmt->close();
            } else {
                // Insert brand-new row
                $stmt = $conn->prepare("INSERT INTO delivery_fee_categories (category_name, fee_amount, is_deleted, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())");
                $stmt->bind_param("sd", $category_name, $fee_amount);
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

// ─── Mark lacking / update qty / mark complete ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        if ($_POST['action'] === 'mark_lacking') {
            $item_id     = intval($_POST['item_id']);
            $lacking_qty = intval($_POST['lacking_qty']);
            $db->update('delivery_items', [
                'quantity_lacking'   => $lacking_qty,
                'quantity_delivered' => "quantity_ordered - $lacking_qty",
                'status'             => $lacking_qty > 0 ? 'lacking' : 'pending'
            ], "id = $item_id");
            echo json_encode(['success' => true]);
        } elseif ($_POST['action'] === 'update_quantity') {
            $item_id = intval($_POST['item_id']);
            $db->update('delivery_items', ['quantity_delivered' => intval($_POST['new_qty'])], "id = $item_id");
            echo json_encode(['success' => true]);
        } elseif ($_POST['action'] === 'mark_complete') {
            $delivery_id = intval($_POST['delivery_id']);
            $db->update('deliveries', ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')], "id = $delivery_id");
            $db->update('delivery_items', ['status' => 'completed'], "delivery_id = $delivery_id");
            echo json_encode(['success' => true]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── Main query: aggregate per customer ──────────────────────────────────────
$havingClause = $userStore && !$isAdmin ? " AND d.store_id = {$userStore['id']}" : "";

$query = "SELECT
    d.recipient_name,
    d.recipient_address,
    COUNT(DISTINCT d.id)    AS total_deliveries,
    SUM(t.total_amount)     AS total_transaction_amount,
    0                       AS total_delivery_fee,
    MAX(t.transaction_date) AS last_transaction_date,
    (SELECT t2.total_amount FROM deliveries d2
        INNER JOIN transactions t2 ON d2.transaction_id = t2.id
        WHERE d2.recipient_name = d.recipient_name AND d2.is_deleted = 0
        ORDER BY t2.transaction_date DESC LIMIT 1) AS last_transaction_amount,
    (SELECT d3.status FROM deliveries d3
        INNER JOIN transactions t3 ON d3.transaction_id = t3.id
        WHERE d3.recipient_name = d.recipient_name AND d3.is_deleted = 0
        ORDER BY t3.transaction_date DESC LIMIT 1) AS last_status,
    s.store_name
FROM deliveries d
INNER JOIN transactions t ON d.transaction_id = t.id
LEFT JOIN stores s ON d.store_id = s.id
WHERE d.is_deleted = 0 $havingClause
GROUP BY d.recipient_name, d.recipient_address
ORDER BY last_transaction_date DESC";

$result    = $conn->query($query);
$customers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$grandTotal = array_sum(array_column($customers, 'total_transaction_amount'));
$grandFee   = array_sum(array_column($customers, 'total_delivery_fee'));
$totalRows  = count($customers);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Delivery Management</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;600&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d0f14;--surface:#14171f;--surface2:#1c2030;--border:#2a2f3d;
  --border-light:#353b4d;--text:#e8ecf4;--muted:#7a8299;--accent:#3b82f6;
  --accent2:#10b981;--warn:#f59e0b;--danger:#ef4444;
  --mono:'IBM Plex Mono',monospace;--sans:'IBM Plex Sans',sans-serif;
}
html{font-size:13px}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;overflow-x:hidden}

/* Top Bar */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:14px 28px;background:var(--surface);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:100}
.topbar-title{display:flex;align-items:center;gap:10px}
.topbar-title h1{font-size:16px;font-weight:600;letter-spacing:.5px}
.topbar-title .badge{font-family:var(--mono);font-size:10px;padding:2px 8px;background:rgba(59,130,246,.15);color:var(--accent);border:1px solid rgba(59,130,246,.3);border-radius:3px}
.topbar-actions{display:flex;gap:10px}
.btn{padding:7px 16px;border:none;border-radius:4px;cursor:pointer;font-family:var(--sans);font-size:12px;font-weight:500;transition:all .15s}
.btn-primary{background:var(--accent);color:#fff}.btn-primary:hover{background:#2563eb}
.btn-secondary{background:var(--surface2);color:var(--text);border:1px solid var(--border)}.btn-secondary:hover{background:var(--border)}
.btn-warn{background:var(--warn);color:#000;font-weight:600}.btn-warn:hover{background:#d97706}
.btn-success{background:var(--accent2);color:#fff}.btn-success:hover{background:#059669}

/* Stats Bar */
.stats-bar{display:flex;gap:1px;background:var(--border);border-bottom:1px solid var(--border)}
.stat-cell{flex:1;padding:16px 24px;background:var(--surface);display:flex;flex-direction:column;gap:4px}
.stat-label{font-size:10px;text-transform:uppercase;letter-spacing:1.2px;color:var(--muted);font-family:var(--mono)}
.stat-value{font-size:22px;font-weight:600;font-family:var(--mono);color:var(--text)}
.stat-value.green{color:var(--accent2)}.stat-value.blue{color:var(--accent)}.stat-value.amber{color:var(--warn)}

/* Toolbar */
.toolbar{display:flex;align-items:center;gap:12px;padding:12px 28px;background:var(--surface);border-bottom:1px solid var(--border)}
.search-wrap{position:relative;flex:1;max-width:340px}
.search-wrap input{width:100%;padding:8px 12px 8px 34px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-family:var(--sans);font-size:12px;outline:none;transition:border .15s}
.search-wrap input:focus{border-color:var(--accent)}
.search-wrap::before{content:'⌕';position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:15px;pointer-events:none}
.status-filter{display:flex;gap:4px}
.filter-btn{padding:6px 12px;font-size:11px;font-family:var(--mono);background:var(--surface2);border:1px solid var(--border);color:var(--muted);border-radius:3px;cursor:pointer;transition:all .15s}
.filter-btn.active,.filter-btn:hover{background:rgba(59,130,246,.12);border-color:var(--accent);color:var(--accent)}

/* Main Table */
.table-wrap{overflow-x:auto;padding:0 28px 40px}
table{width:100%;border-collapse:collapse;margin-top:16px}
thead tr{background:var(--surface2);border-bottom:2px solid var(--accent)}
thead th{padding:10px 14px;text-align:left;font-family:var(--mono);font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);white-space:nowrap;cursor:pointer;user-select:none}
thead th:hover{color:var(--text)}thead th.sorted{color:var(--accent)}
thead th .sort-arrow{margin-left:4px;opacity:.6}
tbody tr{border-bottom:1px solid var(--border);cursor:pointer;transition:background .1s}
tbody tr:hover{background:var(--surface2)}tbody tr:hover .name-cell{color:var(--accent)}
tbody td{padding:11px 14px;font-size:12px;vertical-align:middle}
.name-cell{font-weight:600;color:var(--text);transition:color .15s}
.mono{font-family:var(--mono);font-size:11px}
.addr{color:var(--muted);font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.amount-pos{color:var(--accent2);font-family:var(--mono);font-weight:600}
.amount-fee{color:var(--warn);font-family:var(--mono)}
.tag{display:inline-block;padding:2px 7px;border-radius:2px;font-family:var(--mono);font-size:10px;font-weight:600;letter-spacing:.5px}
.tag-pending{background:rgba(245,158,11,.12);color:var(--warn);border:1px solid rgba(245,158,11,.3)}
.tag-completed{background:rgba(16,185,129,.12);color:var(--accent2);border:1px solid rgba(16,185,129,.3)}
.tag-lacking{background:rgba(239,68,68,.12);color:var(--danger);border:1px solid rgba(239,68,68,.3)}
.count-pill{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:rgba(59,130,246,.12);color:var(--accent);font-family:var(--mono);font-size:11px;font-weight:600}
.row-arrow{color:var(--muted);font-size:16px;transition:transform .15s}
tbody tr:hover .row-arrow{transform:translateX(4px);color:var(--accent)}
.empty-state{text-align:center;padding:60px 20px;color:var(--muted);font-family:var(--mono);font-size:12px}
.empty-state .icon{font-size:40px;margin-bottom:12px}

/* Drawer */
.drawer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);z-index:200;opacity:0;pointer-events:none;transition:opacity .25s}
.drawer-overlay.open{opacity:1;pointer-events:all}
.drawer{position:fixed;top:0;right:0;bottom:0;width:680px;max-width:95vw;background:var(--surface);border-left:1px solid var(--border);z-index:201;transform:translateX(100%);transition:transform .3s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column}
.drawer.open{transform:translateX(0)}
.drawer-head{padding:20px 24px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;flex-shrink:0}
.drawer-head-info{display:flex;flex-direction:column;gap:4px}
.drawer-head-info h2{font-size:16px;font-weight:600}
.drawer-head-info p{font-size:11px;color:var(--muted)}
.drawer-close{background:none;border:1px solid var(--border);color:var(--muted);width:32px;height:32px;border-radius:4px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:all .15s}
.drawer-close:hover{background:var(--danger);color:#fff;border-color:var(--danger)}
.drawer-stats{display:flex;gap:1px;background:var(--border);border-bottom:1px solid var(--border);flex-shrink:0}
.drawer-stat{flex:1;padding:12px 16px;background:var(--surface2);display:flex;flex-direction:column;gap:3px}
.drawer-stat-label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);font-family:var(--mono)}
.drawer-stat-value{font-size:16px;font-weight:600;font-family:var(--mono)}
.drawer-body{flex:1;overflow-y:auto;padding:0}
.receipt-card{border-bottom:1px solid var(--border);padding:16px 24px;cursor:pointer;transition:background .1s}
.receipt-card:hover{background:var(--surface2)}
.receipt-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.receipt-txn{font-family:var(--mono);font-size:12px;color:var(--accent);font-weight:600}
.receipt-date{font-family:var(--mono);font-size:11px;color:var(--muted)}
.receipt-amount{font-family:var(--mono);font-size:15px;font-weight:600;color:var(--accent2)}
.receipt-addr{font-size:11px;color:var(--muted);margin-bottom:8px}
.receipt-items{display:flex;flex-wrap:wrap;gap:4px;margin-top:8px}
.item-chip{padding:2px 8px;background:var(--surface);border:1px solid var(--border);border-radius:2px;font-family:var(--mono);font-size:10px;color:var(--muted)}
.receipt-footer{display:flex;align-items:center;justify-content:space-between;margin-top:10px}
.receipt-store{font-size:10px;color:var(--muted);font-family:var(--mono)}
.drawer-loading{display:flex;align-items:center;justify-content:center;height:200px;color:var(--muted);font-family:var(--mono);font-size:12px;gap:10px}
.spinner{width:18px;height:18px;border:2px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

/* Detail Modal */
.detail-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:300;display:none;align-items:center;justify-content:center}
.detail-overlay.open{display:flex}
.detail-modal{background:var(--surface);border:1px solid var(--border);border-radius:6px;width:560px;max-width:95vw;max-height:80vh;display:flex;flex-direction:column}
.detail-modal-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.detail-modal-head h3{font-size:14px;font-weight:600}
.detail-modal-body{overflow-y:auto;padding:20px;flex:1}
.detail-modal-foot{padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end}
.items-table{width:100%;border-collapse:collapse;font-size:12px}
.items-table th{padding:8px 10px;text-align:left;background:var(--surface2);font-family:var(--mono);font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);border-bottom:1px solid var(--border)}
.items-table td{padding:9px 10px;border-bottom:1px solid var(--border)}
.items-table tr:last-child td{border-bottom:none}

/* Fee Modal */
.fee-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:400;display:none;align-items:center;justify-content:center}
.fee-overlay.open{display:flex}
.fee-modal{background:var(--surface);border:1px solid var(--border);border-radius:6px;width:480px;max-width:96vw;max-height:85vh;display:flex;flex-direction:column}
.fee-modal-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.fee-modal-head h3{font-size:14px;font-weight:600}
.fee-search-bar{padding:12px 20px;border-bottom:1px solid var(--border);flex-shrink:0;position:relative}
.fee-search-bar input{width:100%;padding:8px 12px 8px 32px;background:var(--surface2);border:1px solid var(--border);border-radius:4px;color:var(--text);font-family:var(--sans);font-size:12px;outline:none;transition:border .15s}
.fee-search-bar input:focus{border-color:var(--warn)}
.fee-search-bar::before{content:'⌕';position:absolute;left:30px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:15px;pointer-events:none}
.fee-list{flex:1;overflow-y:auto}
/* fee rows use 3 columns: name | amount | edit btn */
.fee-row{display:grid;grid-template-columns:1fr 140px 80px;align-items:center;gap:10px;padding:11px 20px;border-bottom:1px solid var(--border);transition:background .1s}
.fee-row:hover{background:var(--surface2)}
.fee-row.header{background:var(--surface2);border-bottom:2px solid var(--warn);font-family:var(--mono);font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);pointer-events:none}
.fee-cat-name{font-weight:600;font-size:12px}
.fee-amount{font-family:var(--mono);font-size:13px;font-weight:600;color:var(--warn)}
/* Inline edit expander */
.fee-edit-row{display:none;grid-template-columns:1fr 140px auto;gap:8px;align-items:center;padding:10px 20px;background:rgba(245,158,11,.06);border-bottom:1px solid var(--border)}
.fee-edit-row.open{display:grid}
.fee-edit-row input{padding:6px 10px;background:var(--surface);border:1px solid var(--border);border-radius:4px;color:var(--text);font-family:var(--sans);font-size:12px;outline:none;width:100%;transition:border .15s}
.fee-edit-row input:focus{border-color:var(--warn)}
.fee-empty{padding:40px;text-align:center;color:var(--muted);font-family:var(--mono);font-size:12px}

/* Scrollbar */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--surface)}
::-webkit-scrollbar-thumb{background:var(--border-light);border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:var(--muted)}
</style>
</head>
<body>

<!-- Top Bar -->
<div class="topbar">
  <div class="topbar-title">
    <h1>📦 Delivery Management</h1>
    <?php if ($isAdmin): ?>
      <span class="badge">ADMIN · ALL STORES</span>
    <?php elseif ($userStore): ?>
      <span class="badge">🏪 <?php echo htmlspecialchars($userStore['store_name']); ?></span>
    <?php endif; ?>
  </div>
  <div class="topbar-actions">
    <button class="btn btn-secondary" onclick="location.href='/oro-store/delivery/delivery.php'">+ New Delivery</button>
    <button class="btn btn-primary" onclick="location.href='/oro-store/cashier/cashier.php'">← Cashier</button>
  </div>
</div>

<!-- Stats Bar -->
<div class="stats-bar">
  <div class="stat-cell">
    <span class="stat-label">Customers</span>
    <span class="stat-value blue"><?php echo $totalRows; ?></span>
  </div>
  <div class="stat-cell">
    <span class="stat-label">Total Transactions</span>
    <span class="stat-value"><?php echo array_sum(array_column($customers,'total_deliveries')); ?></span>
  </div>
  <div class="stat-cell">
    <span class="stat-label">Total Revenue</span>
    <span class="stat-value green">₱<?php echo number_format($grandTotal,2); ?></span>
  </div>
  <div class="stat-cell">
    <span class="stat-label">Total Delivery Fees</span>
    <span class="stat-value amber">₱<?php echo number_format($grandFee,2); ?></span>
  </div>
</div>

<!-- Toolbar -->
<div class="toolbar">
  <div class="search-wrap">
    <input type="text" id="searchInput" placeholder="Search customer, address…" oninput="filterTable()">
  </div>
  <div class="status-filter">
    <button class="filter-btn active" data-filter="all" onclick="setFilter(this,'all')">All</button>
    <button class="filter-btn" data-filter="pending" onclick="setFilter(this,'pending')">Pending</button>
    <button class="filter-btn" data-filter="completed" onclick="setFilter(this,'completed')">Completed</button>
    <button class="filter-btn" data-filter="lacking" onclick="setFilter(this,'lacking')">Lacking</button>
  </div>
  <div style="margin-left:auto">
    <button class="btn btn-warn" onclick="openDeliveryFeeModal()" style="display:flex;align-items:center;gap:6px;font-size:12px">
      🚚 <span>Delivery Fees</span>
    </button>
  </div>
</div>

<!-- Main Table -->
<div class="table-wrap">
  <table id="mainTable">
    <thead>
      <tr>
        <th onclick="sortTable(0)">Customer <span class="sort-arrow">↕</span></th>
        <th onclick="sortTable(1)">Location <span class="sort-arrow">↕</span></th>
        <th onclick="sortTable(2)" style="text-align:center">Deliveries <span class="sort-arrow">↕</span></th>
        <th onclick="sortTable(3)">Total Spent <span class="sort-arrow">↕</span></th>
        <th onclick="sortTable(4)">Delivery Fees <span class="sort-arrow">↕</span></th>
        <th onclick="sortTable(5)">Last Transaction <span class="sort-arrow">↕</span></th>
        <th onclick="sortTable(6)">Last Amount <span class="sort-arrow">↕</span></th>
        <th>Last Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody id="tableBody">
      <?php if (empty($customers)): ?>
      <tr><td colspan="9">
        <div class="empty-state"><div class="icon">📭</div>No delivery records found.</div>
      </td></tr>
      <?php else: foreach ($customers as $c):
        $status = strtolower($c['last_status'] ?? 'pending');
        $tagClass = $status === 'completed' ? 'tag-completed' : ($status === 'lacking' ? 'tag-lacking' : 'tag-pending');
      ?>
      <tr onclick="openCustomerDrawer('<?php echo htmlspecialchars(addslashes($c['recipient_name'])); ?>','<?php echo htmlspecialchars(addslashes($c['recipient_address'])); ?>')"
          data-status="<?php echo $status; ?>">
        <td class="name-cell"><?php echo htmlspecialchars($c['recipient_name']); ?></td>
        <td class="addr" title="<?php echo htmlspecialchars($c['recipient_address']); ?>"><?php echo htmlspecialchars($c['recipient_address']); ?></td>
        <td style="text-align:center"><span class="count-pill"><?php echo (int)$c['total_deliveries']; ?></span></td>
        <td class="amount-pos mono">₱<?php echo number_format($c['total_transaction_amount'],2); ?></td>
        <td class="amount-fee mono">₱<?php echo number_format($c['total_delivery_fee'],2); ?></td>
        <td class="mono"><?php echo $c['last_transaction_date'] ? date('M d, Y', strtotime($c['last_transaction_date'])) : '—'; ?></td>
        <td class="amount-pos mono">₱<?php echo number_format($c['last_transaction_amount'],2); ?></td>
        <td><span class="tag <?php echo $tagClass; ?>"><?php echo strtoupper($status); ?></span></td>
        <td><span class="row-arrow">›</span></td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<!-- Customer Receipt Drawer -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="customerDrawer">
  <div class="drawer-head">
    <div class="drawer-head-info">
      <h2 id="drawerName">—</h2>
      <p id="drawerAddr">—</p>
    </div>
    <button class="drawer-close" onclick="closeDrawer()">×</button>
  </div>
  <div class="drawer-stats">
    <div class="drawer-stat">
      <span class="drawer-stat-label">Transactions</span>
      <span class="drawer-stat-value" id="dStatCount">—</span>
    </div>
    <div class="drawer-stat">
      <span class="drawer-stat-label">Total Spent</span>
      <span class="drawer-stat-value" id="dStatTotal" style="color:var(--accent2)">—</span>
    </div>
    <div class="drawer-stat">
      <span class="drawer-stat-label">Last Visit</span>
      <span class="drawer-stat-value" id="dStatLast" style="font-size:12px;margin-top:4px">—</span>
    </div>
  </div>
  <div class="drawer-body" id="drawerBody">
    <div class="drawer-loading"><div class="spinner"></div> Loading receipts…</div>
  </div>
</div>

<!-- Delivery Detail Modal -->
<div class="detail-overlay" id="detailOverlay">
  <div class="detail-modal">
    <div class="detail-modal-head">
      <h3 id="detailTitle">Delivery Details</h3>
      <button class="drawer-close" onclick="closeDetailModal()">×</button>
    </div>
    <div class="detail-modal-body" id="detailBody">Loading…</div>
    <div class="detail-modal-foot">
      <button class="btn btn-secondary" onclick="closeDetailModal()">Close</button>
      <button class="btn btn-primary" id="detailCompleteBtn" onclick="markComplete()">Mark Complete</button>
    </div>
  </div>
</div>

<!-- Delivery Fee Modal -->
<div class="fee-overlay" id="feeOverlay">
  <div class="fee-modal">
    <div class="fee-modal-head">
      <h3>🚚 Delivery Fee Categories</h3>
      <button class="drawer-close" onclick="closeFeeModal()">×</button>
    </div>
    <div class="fee-search-bar">
      <input type="text" id="feeSearch" placeholder="Search category…" oninput="loadFees(this.value)">
    </div>
    <div class="fee-list" id="feeList">
      <div class="fee-empty"><div style="font-size:32px;margin-bottom:8px">🚚</div>Loading…</div>
    </div>
  </div>
</div>

<script>
/* ── Globals ── */
let currentFilter   = 'all';
let sortCol         = 5, sortAsc = false;
let currentDeliveryId = null;
// Cache of fee objects keyed by product_categories.id
// so we never embed special characters into onclick strings
let feeCache = {};

/* ── Filter / Search ── */
function filterTable() {
  const q = document.getElementById('searchInput').value.toLowerCase();
  document.querySelectorAll('#tableBody tr').forEach(tr => {
    const ok = tr.textContent.toLowerCase().includes(q) &&
               (currentFilter === 'all' || (tr.dataset.status || '') === currentFilter);
    tr.style.display = ok ? '' : 'none';
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
    const arrow = th.querySelector('.sort-arrow');
    if (arrow) arrow.textContent = i === col ? (sortAsc ? '↑' : '↓') : '↕';
  });
  rows.sort((a, b) => {
    const av = a.cells[col]?.textContent.trim() || '';
    const bv = b.cells[col]?.textContent.trim() || '';
    const an = parseFloat(av.replace(/[₱,]/g, ''));
    const bn = parseFloat(bv.replace(/[₱,]/g, ''));
    if (!isNaN(an) && !isNaN(bn)) return sortAsc ? an - bn : bn - an;
    return sortAsc ? av.localeCompare(bv) : bv.localeCompare(av);
  });
  rows.forEach(r => tbody.appendChild(r));
}

/* ── Customer Drawer ── */
function openCustomerDrawer(name, addr) {
  document.getElementById('drawerName').textContent  = name;
  document.getElementById('drawerAddr').textContent  = addr || '—';
  document.getElementById('dStatCount').textContent  = '—';
  document.getElementById('dStatTotal').textContent  = '—';
  document.getElementById('dStatLast').textContent   = '—';
  document.getElementById('drawerBody').innerHTML    = '<div class="drawer-loading"><div class="spinner"></div> Loading receipts…</div>';
  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('customerDrawer').classList.add('open');
  fetch(`?action=get_customer_receipts&recipient=${encodeURIComponent(name)}`)
    .then(r => r.json())
    .then(receipts => renderDrawer(receipts))
    .catch(() => { document.getElementById('drawerBody').innerHTML = '<div class="drawer-loading">Failed to load.</div>'; });
}

function renderDrawer(receipts) {
  if (!receipts.length) {
    document.getElementById('drawerBody').innerHTML = '<div class="drawer-loading">No receipts found.</div>';
    return;
  }
  const total = receipts.reduce((s, r) => s + parseFloat(r.total_amount || 0), 0);
  document.getElementById('dStatCount').textContent = receipts.length;
  document.getElementById('dStatTotal').textContent = '₱' + total.toLocaleString('en', {minimumFractionDigits:2, maximumFractionDigits:2});
  document.getElementById('dStatLast').textContent  = receipts[0].transaction_date ? formatDate(receipts[0].transaction_date) : '—';

  const html = receipts.map(r => {
    const items = (r.items_summary || '').split('||').filter(Boolean);
    const chips = items.slice(0, 6).map(i => `<span class="item-chip">${escHtml(i)}</span>`).join('');
    const more  = items.length > 6 ? `<span class="item-chip">+${items.length - 6} more</span>` : '';
    const sc    = r.status === 'completed' ? 'tag-completed' : r.status === 'lacking' ? 'tag-lacking' : 'tag-pending';
    return `<div class="receipt-card" onclick="openDeliveryDetail(${r.delivery_id}, event)">
      <div class="receipt-top">
        <span class="receipt-txn">#${escHtml(r.transaction_number)}</span>
        <span class="receipt-date">${formatDate(r.transaction_date)}</span>
      </div>
      <div class="receipt-addr">📍 ${escHtml(r.recipient_address || '')}</div>
      <div class="receipt-items">${chips}${more}</div>
      <div class="receipt-footer">
        <span class="receipt-store">🏪 ${escHtml(r.store_name || '—')} · ${r.item_count} items</span>
        <div style="display:flex;align-items:center;gap:10px">
          <span class="tag ${sc}">${(r.status || 'pending').toUpperCase()}</span>
          <span class="receipt-amount">₱${parseFloat(r.total_amount || 0).toLocaleString('en',{minimumFractionDigits:2})}</span>
        </div>
      </div>
    </div>`;
  }).join('');
  document.getElementById('drawerBody').innerHTML = html;
}

function closeDrawer() {
  document.getElementById('drawerOverlay').classList.remove('open');
  document.getElementById('customerDrawer').classList.remove('open');
}

/* ── Delivery Detail Modal ── */
function openDeliveryDetail(deliveryId, e) {
  e.stopPropagation();
  currentDeliveryId = deliveryId;
  document.getElementById('detailOverlay').classList.add('open');
  document.getElementById('detailBody').innerHTML = '<div style="padding:20px;color:var(--muted);font-family:var(--mono);font-size:12px">Loading…</div>';
  fetch(`?action=get_delivery&delivery_id=${deliveryId}`)
    .then(r => r.json())
    .then(items => {
      if (!items.length) { document.getElementById('detailBody').innerHTML = '<div style="padding:20px;color:var(--muted)">No items found.</div>'; return; }
      const info = items[0];
      document.getElementById('detailTitle').textContent = `Receipt #${info.transaction_number}`;
      document.getElementById('detailCompleteBtn').style.display = info.delivery_status === 'completed' ? 'none' : '';
      const rows = items.map(item => `<tr>
        <td>${escHtml(item.product_name || '—')}</td>
        <td class="mono" style="text-align:center">${item.quantity_ordered}</td>
        <td class="mono" style="text-align:center">${item.quantity_delivered}</td>
        <td class="mono" style="text-align:center;color:var(--danger)">${item.quantity_lacking > 0 ? item.quantity_lacking : '—'}</td>
        <td class="amount-pos mono">₱${parseFloat(item.total_amount || 0).toLocaleString('en',{minimumFractionDigits:2})}</td>
      </tr>`).join('');
      document.getElementById('detailBody').innerHTML = `
        <div style="margin-bottom:14px;font-size:12px;color:var(--muted)">
          👤 ${escHtml(info.recipient_name)} · 📍 ${escHtml(info.recipient_address)}
        </div>
        <table class="items-table">
          <thead><tr>
            <th>Product</th><th style="text-align:center">Ordered</th>
            <th style="text-align:center">Delivered</th><th style="text-align:center">Lacking</th><th>Amount</th>
          </tr></thead>
          <tbody>${rows}</tbody>
        </table>`;
    })
    .catch(() => { document.getElementById('detailBody').innerHTML = '<div style="padding:20px;color:var(--danger)">Failed to load.</div>'; });
}

function closeDetailModal() {
  document.getElementById('detailOverlay').classList.remove('open');
  currentDeliveryId = null;
}

function markComplete() {
  if (!currentDeliveryId || !confirm('Mark this delivery as complete?')) return;
  fetch('', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: `action=mark_complete&delivery_id=${currentDeliveryId}`
  }).then(r => r.json()).then(d => {
    if (d.success) { closeDetailModal(); location.reload(); }
    else alert('Error: ' + (d.error || 'Unknown'));
  });
}

/* ── Delivery Fee Modal ── */
function openDeliveryFeeModal() {
  document.getElementById('feeOverlay').classList.add('open');
  document.getElementById('feeSearch').value = '';
  loadFees('');
}
function closeFeeModal() {
  document.getElementById('feeOverlay').classList.remove('open');
}

let feeDebounce = null;
function loadFees(search) {
  clearTimeout(feeDebounce);
  feeDebounce = setTimeout(() => {
    document.getElementById('feeList').innerHTML = '<div class="fee-empty"><div class="spinner" style="margin:0 auto"></div></div>';
    fetch(`?action=get_delivery_fees&search=${encodeURIComponent(search || '')}`)
      .then(r => r.json())
      .then(fees => renderFees(fees))
      .catch(() => { document.getElementById('feeList').innerHTML = '<div class="fee-empty">Failed to load.</div>'; });
  }, 200);
}

function renderFees(fees) {
  // Store all data in JS cache — no special chars in onclick attributes
  feeCache = {};
  fees.forEach(f => { feeCache[f.id] = f; });

  const list = document.getElementById('feeList');
  if (!fees.length) {
    list.innerHTML = '<div class="fee-empty">No categories found.</div>';
    return;
  }

  const header = `<div class="fee-row header">
    <div>Category</div><div>Fee Amount</div><div></div>
  </div>`;

  const rows = fees.map(f => {
    const fmtAmt = f.fee_amount.toLocaleString('en', {minimumFractionDigits: 2});
    return `
    <div>
      <div class="fee-row">
        <div class="fee-cat-name">${escHtml(f.category_name)}</div>
        <div class="fee-amount">₱${fmtAmt}</div>
        <div>
          <button class="btn btn-secondary" style="padding:4px 10px;font-size:11px"
            onclick="openEditFee(${f.id})">✏️ Edit</button>
        </div>
      </div>
      <div class="fee-edit-row" id="fee-edit-${f.id}">
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.8px">Category</span>
          <input type="text" id="fee-edit-cat-${f.id}" readonly
            style="color:var(--muted);cursor:not-allowed;background:var(--surface2)">
        </div>
        <div style="display:flex;flex-direction:column;gap:2px">
          <span style="font-size:10px;color:var(--muted);font-family:var(--mono);text-transform:uppercase;letter-spacing:.8px">Fee (₱)</span>
          <input type="number" id="fee-edit-amt-${f.id}" step="0.01" min="0" placeholder="0.00">
        </div>
        <div style="display:flex;gap:6px;align-self:flex-end">
          <button class="btn btn-success" style="padding:5px 14px;font-size:11px"
            onclick="saveFeeEdit(${f.id})">Save</button>
          <button class="btn btn-secondary" style="padding:5px 9px;font-size:11px"
            onclick="closeEditFee(${f.id})">✕</button>
        </div>
      </div>
    </div>`;
  }).join('');

  list.innerHTML = header + rows;
}

function openEditFee(id) {
  // Close all open edit rows first
  document.querySelectorAll('.fee-edit-row.open').forEach(el => el.classList.remove('open'));
  const f = feeCache[id];
  if (!f) return;
  // Populate inputs safely via JS (no inline string injection)
  document.getElementById(`fee-edit-cat-${id}`).value = f.category_name;
  document.getElementById(`fee-edit-amt-${id}`).value = f.fee_amount;
  document.getElementById(`fee-edit-${id}`).classList.add('open');
  document.getElementById(`fee-edit-amt-${id}`).focus();
}

function closeEditFee(id) {
  document.getElementById(`fee-edit-${id}`).classList.remove('open');
}

function saveFeeEdit(id) {
  const f = feeCache[id];
  if (!f) return;
  const amt = parseFloat(document.getElementById(`fee-edit-amt-${id}`).value);
  if (isNaN(amt) || amt < 0) { alert('Please enter a valid fee amount.'); return; }

  // Use URLSearchParams so all values are properly encoded
  const body = new URLSearchParams({
    action:        'save_delivery_fee',
    fee_id:        f.fee_id,          // delivery_fee_categories.id (0 = insert new)
    category_name: f.category_name,
    fee_amount:    amt
  });

  fetch('', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body})
    .then(r => r.json())
    .then(d => {
      if (d.success) loadFees(document.getElementById('feeSearch').value);
      else alert('Error: ' + (d.error || 'Unknown'));
    })
    .catch(() => alert('Network error, please try again.'));
}

/* ── Helpers ── */
function formatDate(str) {
  if (!str) return '—';
  return new Date(str).toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
}
function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/* ── Keyboard shortcuts ── */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    if (document.getElementById('feeOverlay').classList.contains('open'))    { closeFeeModal();    return; }
    if (document.getElementById('detailOverlay').classList.contains('open')) { closeDetailModal(); return; }
    closeDrawer();
  }
});

/* ── Initial render ── */
sortTable(5);
</script>
</body>
</html>