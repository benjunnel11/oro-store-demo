<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/gcash_errors.log');
if (ob_get_level()) ob_end_clean();

require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/sync_helper.php';
require_once __DIR__ . '/../core/transaction_helper.php';

ob_start();
$db = new SyncDB();
$currentUser = getCurrentUser();
$store_id = $currentUser['store_id'] ?? ($_SESSION['admin_cashier_store'] ?? null);

// Schema migrations
$conn->query("ALTER TABLE gcash_transactions MODIFY COLUMN transaction_type ENUM('cash_in','cash_out','send_gcash','bank_transfer') NOT NULL");
$cols = [];
$cr = $conn->query("SHOW COLUMNS FROM gcash_transactions");
while ($c = $cr->fetch_assoc()) $cols[] = $c['Field'];
if (!in_array('destination', $cols)) $conn->query("ALTER TABLE gcash_transactions ADD COLUMN destination VARCHAR(255) DEFAULT NULL");
if (!in_array('notes', $cols)) $conn->query("ALTER TABLE gcash_transactions ADD COLUMN notes TEXT DEFAULT NULL");
if (!in_array('gcash_account_id', $cols)) $conn->query("ALTER TABLE gcash_transactions ADD COLUMN gcash_account_id INT DEFAULT NULL");
$conn->query("ALTER TABLE gcash_transactions MODIFY COLUMN user_reference VARCHAR(20) DEFAULT NULL");

$conn->query("CREATE TABLE IF NOT EXISTS gcash_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_name VARCHAR(100) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    store_id INT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    show_on_kiosk TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$__gc_cols = [];
$__gc_r = $conn->query("SHOW COLUMNS FROM gcash_accounts");
while ($__gc_c = $__gc_r->fetch_assoc()) $__gc_cols[] = $__gc_c['Field'];
if (!in_array('show_on_kiosk', $__gc_cols)) $conn->query("ALTER TABLE gcash_accounts ADD COLUMN show_on_kiosk TINYINT(1) DEFAULT 0");
// Ensure gcash_transactions has customer_number column
$__gt_cols = [];
$__gt_r = $conn->query("SHOW COLUMNS FROM gcash_transactions");
while ($__gt_c = $__gt_r->fetch_assoc()) $__gt_cols[] = $__gt_c['Field'];
if (!in_array('customer_number', $__gt_cols)) $conn->query("ALTER TABLE gcash_transactions ADD COLUMN customer_number VARCHAR(20) DEFAULT NULL");

// GET: accounts list
if (isset($_GET['action']) && $_GET['action'] === 'get_accounts') {
    ob_clean(); header('Content-Type: application/json');
    $r = $conn->query("SELECT * FROM gcash_accounts WHERE is_active = 1 ORDER BY account_name");
    echo json_encode($r->fetch_all(MYSQLI_ASSOC));
    $conn->close(); exit;
}
// POST: add account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_account') {
    ob_clean(); header('Content-Type: application/json');
    $name = trim($_POST['account_name']); $phone = trim($_POST['phone_number']);
    $sid = intval($_POST['store_id'] ?? 0) ?: null;
    if (!$name || !$phone) { echo json_encode(['success'=>false,'error'=>'Name and number required']); $conn->close(); exit; }
    $id = $db->insert('gcash_accounts', ['account_name' => $name, 'phone_number' => $phone, 'store_id' => $sid]);
    echo json_encode(['success'=>true,'id'=>$id]);
    $conn->close(); exit;
}
// POST: delete account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_account') {
    ob_clean(); header('Content-Type: application/json');
    $id = intval($_POST['account_id']);
    $db->update('gcash_accounts', ['is_active' => 0], "id = $id");
    echo json_encode(['success'=>true]);
    $conn->close(); exit;
}
// POST: toggle kiosk visibility per store
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_kiosk_account') {
    ob_clean(); header('Content-Type: application/json');
    $id = intval($_POST['account_id']);
    $toggle_store = intval($_POST['store_id'] ?? $store_id);
    $cur = $conn->query("SELECT kiosk_store_ids FROM gcash_accounts WHERE id = $id")->fetch_assoc();
    $ids = array_filter(explode(',', $cur['kiosk_store_ids'] ?? ''));
    if (in_array((string)$toggle_store, $ids)) {
        $ids = array_diff($ids, [(string)$toggle_store]);
    } else {
        $ids[] = (string)$toggle_store;
    }
    $new_ids = implode(',', array_unique($ids));
    $new_show = empty($new_ids) ? 0 : 1;
    $db->update('gcash_accounts', ['kiosk_store_ids' => $new_ids, 'show_on_kiosk' => $new_show], "id = $id");
    echo json_encode(['success' => true, 'kiosk_store_ids' => $new_ids]);
    $conn->close(); exit;
}

// Ensure kiosk_store_ids column exists
$_gc_check = $conn->query("SHOW COLUMNS FROM gcash_accounts LIKE 'kiosk_store_ids'");
if (!$_gc_check || $_gc_check->num_rows === 0) {
    $conn->query("ALTER TABLE gcash_accounts ADD COLUMN kiosk_store_ids VARCHAR(255) DEFAULT ''");
    // Migrate: if show_on_kiosk=1, set kiosk_store_ids to all store IDs
    $all_sids = [];
    $sr = $conn->query("SELECT id FROM stores WHERE status = 'active'");
    while ($srow = $sr->fetch_assoc()) { $all_sids[] = $srow['id']; }
    if (!empty($all_sids)) {
        $ids_str = implode(',', $all_sids);
        $conn->query("UPDATE gcash_accounts SET kiosk_store_ids = '$ids_str' WHERE show_on_kiosk = 1");
    }
}

// Fetch accounts for page render
$_isKioskUser = ($currentUser['role'] === 'kiosk');
$_kiosk_store_id = $store_id ?: 0;
if ($_isKioskUser) {
    // Only show accounts assigned to this store's kiosk
    $gcash_accounts = $conn->query("SELECT * FROM gcash_accounts WHERE is_active = 1 AND FIND_IN_SET($_kiosk_store_id, kiosk_store_ids) ORDER BY account_name")->fetch_all(MYSQLI_ASSOC);
} else {
    $gcash_accounts = $conn->query("SELECT * FROM gcash_accounts WHERE is_active = 1 ORDER BY account_name")->fetch_all(MYSQLI_ASSOC);
}
$stores_list = $conn->query("SELECT id, store_name, store_code FROM stores WHERE status = 'active' ORDER BY store_name")->fetch_all(MYSQLI_ASSOC);

// POST: Complete GCash transaction (cash_in / cash_out)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_gcash') {
    ob_clean(); header('Content-Type: application/json');
    try {
        $type = $_POST['type'];
        $amount = floatval($_POST['amount']);
        $fee = floatval($_POST['fee']);
        $total = floatval($_POST['total']);
        $user_reference = $_POST['reference'] ?? '';
        if ($amount <= 0) throw new Exception('Invalid amount');
        $isKioskCashIn = (isset($_SESSION['role']) && $_SESSION['role'] === 'kiosk' && $type === 'cash_in');
        if (!$isKioskCashIn && !preg_match('/^\d+$/', $user_reference)) throw new Exception('Reference number is required');
        $system_reference = generateGCashReferenceNumber($conn);
        if (!$system_reference) throw new Exception('Failed to generate reference');
        $account_id = !empty($_POST['gcash_account_id']) ? intval($_POST['gcash_account_id']) : null;
        $customer_number = !empty($_POST['customer_number']) ? trim($_POST['customer_number']) : null;
        $insert_data = [
            'transaction_type' => $type, 'amount' => $amount, 'fee' => $fee, 'total_amount' => $total,
            'reference_number' => $system_reference, 'user_reference' => $user_reference,
            'gcash_account_id' => $account_id,
            'user_id' => $currentUser['id'], 'user_name' => $currentUser['full_name'],
            'store_id' => $store_id, 'status' => 'completed'
        ];
        if ($customer_number) $insert_data['customer_number'] = $customer_number;
        $tid = $db->insert('gcash_transactions', $insert_data);
        if (!$tid) {
            $cols = 'transaction_type, amount, fee, total_amount, reference_number, user_reference, user_id, user_name, store_id, status';
            $vals = "'" . $conn->real_escape_string($type) . "', $amount, $fee, $total, '" . $conn->real_escape_string($system_reference) . "', '" . $conn->real_escape_string($user_reference) . "', " . intval($currentUser['id']) . ", '" . $conn->real_escape_string($currentUser['full_name']) . "', " . ($store_id ? intval($store_id) : 'NULL') . ", 'completed'";
            if ($account_id) { $cols .= ', gcash_account_id'; $vals .= ', ' . intval($account_id); }
            if ($customer_number) { $cols .= ', customer_number'; $vals .= ", '" . $conn->real_escape_string($customer_number) . "'"; }
            $sql = "INSERT INTO gcash_transactions ($cols) VALUES ($vals)";
            if (!$conn->query($sql)) throw new Exception('Insert failed: ' . $conn->error);
            $tid = $conn->insert_id;
        }
        logActivity('transaction', "GCash $type: $system_reference", $currentUser['id'], $store_id, [
            'transaction_id'=>$tid, 'type'=>$type, 'amount'=>$amount, 'fee'=>$fee, 'total'=>$total, 'gcash_account_id'=>$account_id
        ]);
        echo json_encode(['success'=>true, 'transaction_id'=>$tid, 'reference_number'=>$system_reference]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
    }
    $conn->close(); exit;
}

// POST: Send GCash (transfer out)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_gcash') {
    ob_clean(); header('Content-Type: application/json');
    try {
        $type = $_POST['transfer_type']; // send_gcash or bank_transfer
        $amount = floatval($_POST['amount']);
        $destination = trim($_POST['destination']);
        $notes = trim($_POST['notes'] ?? '');
        if ($amount <= 0) throw new Exception('Invalid amount');
        if (empty($destination)) throw new Exception('Enter destination');
        if (!in_array($type, ['send_gcash', 'bank_transfer'])) throw new Exception('Invalid transfer type');
        $system_reference = generateGCashReferenceNumber($conn);
        $tid = $db->insert('gcash_transactions', [
            'transaction_type' => $type, 'amount' => $amount, 'fee' => 0, 'total_amount' => $amount,
            'reference_number' => $system_reference, 'user_reference' => '',
            'destination' => $destination, 'notes' => $notes,
            'user_id' => $currentUser['id'], 'user_name' => $currentUser['full_name'],
            'store_id' => $store_id, 'status' => 'completed'
        ]);
        $label = $type === 'send_gcash' ? 'GCash-to-GCash' : 'GCash-to-Bank';
        logActivity('transaction', "$label transfer: ₱$amount to $destination", $currentUser['id'], $store_id, [
            'transaction_id'=>$tid, 'type'=>$type, 'amount'=>$amount, 'destination'=>$destination, 'notes'=>$notes
        ]);
        echo json_encode(['success'=>true, 'transaction_id'=>$tid, 'reference_number'=>$system_reference]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
    }
    $conn->close(); exit;
}

// POST: Re-edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reedit_gcash') {
    ob_clean(); header('Content-Type: application/json');
    try {
        $original_id = intval($_POST['original_id']);
        $type = $_POST['type']; $amount = floatval($_POST['amount']);
        $fee = floatval($_POST['fee']); $total = floatval($_POST['total']);
        $user_reference = $_POST['reference'];
        if (!preg_match('/^\d+$/', $user_reference)) throw new Exception('Reference number is required');
        $conn->begin_transaction();
        $db->update('gcash_transactions', ['status'=>'edited'], "id = $original_id");
        $system_reference = generateGCashReferenceNumber($conn);
        $new_id = $db->insert('gcash_transactions', [
            'transaction_type'=>$type, 'amount'=>$amount, 'fee'=>$fee, 'total_amount'=>$total,
            'reference_number'=>$system_reference, 'user_reference'=>$user_reference,
            'status'=>'completed', 'original_transaction_id'=>$original_id, 'edited_date'=>date('Y-m-d H:i:s'),
            'user_id'=>$currentUser['id'], 'user_name'=>$currentUser['full_name'], 'store_id'=>$store_id
        ]);
        $conn->commit();
        echo json_encode(['success'=>true, 'transaction_id'=>$new_id, 'reference_number'=>$system_reference]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
    }
    $conn->close(); exit;
}

// GET: History
if (isset($_GET['action']) && $_GET['action'] === 'get_gcash_transactions') {
    ob_clean(); header('Content-Type: application/json');
    $acct_filter = isset($_GET['account_id']) ? intval($_GET['account_id']) : 0;
    $where = "WHERE g.is_deleted = 0";
    if ($store_id) $where .= " AND g.store_id = " . intval($store_id);
    if ($acct_filter > 0) $where .= " AND g.gcash_account_id = $acct_filter";
    $r = $conn->query("SELECT g.*, ga.account_name as gcash_acct_name, ga.phone_number as gcash_acct_phone
        FROM gcash_transactions g LEFT JOIN gcash_accounts ga ON g.gcash_account_id = ga.id
        $where ORDER BY g.transaction_date DESC LIMIT 50");
    echo json_encode($r ? $r->fetch_all(MYSQLI_ASSOC) : []);
    $conn->close(); exit;
}

// GET: Details
if (isset($_GET['action']) && $_GET['action'] === 'get_gcash_details') {
    ob_clean(); header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM gcash_transactions WHERE id = ? AND is_deleted = 0");
    $stmt->bind_param("i", $id); $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $related = ['edits'=>[]];
    if ($t && $t['original_transaction_id']) {
        $stmt = $conn->prepare("SELECT * FROM gcash_transactions WHERE id = ? AND is_deleted = 0");
        $stmt->bind_param("i", $t['original_transaction_id']); $stmt->execute();
        $related['original'] = $stmt->get_result()->fetch_assoc(); $stmt->close();
    }
    if ($t) {
        $stmt = $conn->prepare("SELECT * FROM gcash_transactions WHERE original_transaction_id = ? AND is_deleted = 0 ORDER BY transaction_date DESC");
        $stmt->bind_param("i", $id); $stmt->execute();
        $related['edits'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    }
    echo json_encode(['transaction'=>$t, 'related'=>$related]);
    $conn->close(); exit;
}

// Today stats
$sc = $store_id ? "AND store_id = $store_id" : "";
$today_in = $conn->query("SELECT COUNT(*) as c, COALESCE(SUM(amount),0) as a, COALESCE(SUM(fee),0) as f FROM gcash_transactions WHERE transaction_type='cash_in' AND DATE(transaction_date)=CURDATE() AND status='completed' AND is_deleted=0 $sc")->fetch_assoc();
$today_out = $conn->query("SELECT COUNT(*) as c, COALESCE(SUM(amount),0) as a, COALESCE(SUM(fee),0) as f FROM gcash_transactions WHERE transaction_type='cash_out' AND DATE(transaction_date)=CURDATE() AND status='completed' AND is_deleted=0 $sc")->fetch_assoc();
$total_fees = floatval($today_in['f']) + floatval($today_out['f']);

// GCash wallet balance: cash_out received - cash_in sent - transfers sent
$wallet = $conn->query("SELECT
    COALESCE(SUM(CASE WHEN transaction_type='cash_out' THEN amount ELSE 0 END),0)
  - COALESCE(SUM(CASE WHEN transaction_type='cash_in' THEN amount ELSE 0 END),0)
  - COALESCE(SUM(CASE WHEN transaction_type IN ('send_gcash','bank_transfer') THEN amount ELSE 0 END),0) as balance
    FROM gcash_transactions WHERE status='completed' AND is_deleted=0 $sc")->fetch_assoc()['balance'];
$wallet_balance = floatval($wallet);

$today_transfers = $conn->query("SELECT COUNT(*) as c, COALESCE(SUM(amount),0) as a FROM gcash_transactions WHERE transaction_type IN ('send_gcash','bank_transfer') AND DATE(transaction_date)=CURDATE() AND status='completed' AND is_deleted=0 $sc")->fetch_assoc();

ob_end_clean();
$isKiosk = ($currentUser['role'] === 'kiosk');
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>GCash - Oro Store</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f172a;color:#e2e8f0;height:100vh;display:flex;flex-direction:column;}

        .shortcut-bar{display:flex;gap:6px;padding:5px 12px;background:#1e293b;flex-wrap:wrap;align-items:center;border-bottom:1px solid #334155;}
        .sc-key{font-size:11px;color:#94a3b8;cursor:pointer;padding:3px 8px;border-radius:4px;transition:background .15s;white-space:nowrap;}
        .sc-key:hover{background:rgba(255,255,255,.08);}
        .sc-key kbd{background:#334155;color:#e2e8f0;padding:1px 5px;border-radius:3px;font-family:inherit;font-size:10px;margin-right:3px;border:1px solid #475569;}
        .sc-key.sc-green{color:#86efac;} .sc-key.sc-blue{color:#93c5fd;} .sc-key.sc-red{color:#fca5a5;}

        .gcash-layout{flex:1;display:flex;flex-direction:column;overflow:hidden;}
        .gcash-form-panel{flex:1;display:flex;flex-direction:column;padding:12px 18px;overflow:hidden;}
        .gcash-brand{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
        .gcash-logo{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#007bff,#0056d2);display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;}
        .gcash-brand h1{font-size:17px;font-weight:800;color:#fff;}
        .gcash-brand .sub{font-size:10px;color:#64748b;}
        .gcash-brand .wallet{margin-left:auto;text-align:right;}
        .gcash-brand .wallet-label{font-size:9px;color:#64748b;text-transform:uppercase;font-weight:700;}
        .gcash-brand .wallet-val{font-size:16px;font-weight:800;}
        .wallet-pos{color:#22c55e;} .wallet-neg{color:#ef4444;} .wallet-zero{color:#64748b;}

        .type-toggle{display:flex;gap:0;background:#1e293b;border-radius:10px;padding:3px;margin-bottom:10px;}
        .type-btn{flex:1;padding:8px;text-align:center;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;background:transparent;color:#64748b;}
        .type-btn.active-in{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;box-shadow:0 4px 15px rgba(34,197,94,.3);}
        .type-btn.active-out{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;box-shadow:0 4px 15px rgba(239,68,68,.3);}
        .type-btn:hover:not(.active-in):not(.active-out){background:#334155;color:#cbd5e1;}

        .input-row{display:flex;gap:10px;margin-bottom:8px;}
        .input-row .field{flex:1;}
        .amount-label{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
        .amount-input-wrap{position:relative;}
        .amount-input-wrap .peso{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:18px;font-weight:700;color:#475569;}
        #amount{width:100%;padding:12px 12px 12px 32px;font-size:22px;font-weight:800;background:#1e293b;border:2px solid #334155;border-radius:10px;color:#fff;outline:none;transition:border .2s;}
        #amount:focus{border-color:#3b82f6;}
        #amount::placeholder{color:#334155;}
        #reference{width:100%;padding:12px 10px;font-size:16px;font-weight:700;letter-spacing:4px;text-align:center;background:#1e293b;border:2px solid #334155;border-radius:10px;color:#fff;outline:none;transition:border .2s;}
        #reference:focus{border-color:#3b82f6;}
        #reference::placeholder{letter-spacing:2px;color:#334155;}

        .quick-amounts{display:grid;grid-template-columns:repeat(6,1fr);gap:4px;margin-bottom:8px;}
        .quick-btn{padding:7px 4px;background:#1e293b;border:1.5px solid #334155;border-radius:6px;color:#cbd5e1;font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;text-align:center;}
        .quick-btn:hover{background:#334155;border-color:#475569;color:#fff;}

        /* Live total bar */
        .total-bar{display:flex;justify-content:space-between;align-items:center;background:#1e293b;border-radius:10px;padding:8px 14px;margin-bottom:8px;border:1px solid #334155;}
        .total-bar .tb-label{font-size:11px;color:#64748b;}
        .total-bar .tb-amount{font-size:11px;color:#94a3b8;}
        .total-bar .tb-total{font-size:20px;font-weight:800;color:#fff;}
        .total-bar .tb-fee{font-size:11px;color:#fbbf24;font-weight:600;}

        .stats-inline{display:flex;gap:0;background:#1e293b;border-radius:10px;margin-bottom:8px;border:1px solid #334155;}
        .stat-box{flex:1;padding:6px 8px;text-align:center;border-right:1px solid #334155;}
        .stat-box:last-child{border-right:none;}
        .stat-box .stat-label{font-size:8px;color:#64748b;text-transform:uppercase;font-weight:700;letter-spacing:.5px;}
        .stat-box .stat-val{font-size:13px;font-weight:800;margin-top:1px;}
        .stat-box .stat-sub{font-size:8px;color:#475569;}
        .stat-in .stat-val{color:#22c55e;} .stat-out .stat-val{color:#ef4444;} .stat-fee .stat-val{color:#fbbf24;} .stat-send .stat-val{color:#818cf8;}

        .edit-badge{display:inline-block;background:#fbbf24;color:#0f172a;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;margin-bottom:8px;}

        .btn-row{display:flex;gap:6px;}
        .submit-btn{flex:1;padding:12px;border:none;border-radius:10px;font-size:14px;font-weight:800;cursor:pointer;transition:all .2s;}
        .submit-btn.cash-in{background:linear-gradient(135deg,#22c55e,#16a34a);color:#fff;}
        .submit-btn.cash-out{background:linear-gradient(135deg,#ef4444,#dc2626);color:#fff;}
        .submit-btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(0,0,0,.3);}
        .aux-btn{padding:12px 14px;background:#1e293b;border:1.5px solid #334155;border-radius:10px;color:#94a3b8;font-size:11px;font-weight:600;cursor:pointer;transition:all .15s;white-space:nowrap;}
        .aux-btn:hover{background:#334155;color:#fff;}
        .aux-btn.send{border-color:#6366f1;color:#a5b4fc;}
        .aux-btn.send:hover{background:#6366f1;color:#fff;}

        .toast{position:fixed;top:20px;right:20px;padding:14px 20px;border-radius:12px;font-size:13px;font-weight:700;z-index:9999;transform:translateX(120%);transition:transform .3s ease;max-width:360px;}
        .toast.show{transform:translateX(0);}
        .toast.success{background:#16a34a;color:#fff;}
        .toast.error{background:#dc2626;color:#fff;}

        /* History slide-up */
        .history-panel{position:fixed;bottom:0;left:0;right:0;top:0;background:rgba(0,0,0,.6);z-index:900;display:none;align-items:flex-end;justify-content:center;}
        .history-panel.active{display:flex;}
        .history-inner{background:#1e293b;border-radius:16px 16px 0 0;width:100%;max-height:80vh;display:flex;flex-direction:column;border-top:2px solid #334155;}
        .history-header{padding:12px 16px;border-bottom:1px solid #334155;display:flex;justify-content:space-between;align-items:center;}
        .history-header h3{font-size:14px;font-weight:700;color:#e2e8f0;}
        .history-close{background:none;border:none;color:#64748b;font-size:18px;cursor:pointer;padding:4px 8px;border-radius:6px;}
        .history-close:hover{background:#334155;color:#fff;}
        .history-list{flex:1;overflow-y:auto;padding:6px;max-height:60vh;}
        .h-item{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;cursor:pointer;transition:background .15s;border:1px solid transparent;}
        .h-item:hover{background:#334155;border-color:#475569;}
        .h-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
        .h-icon.in{background:rgba(34,197,94,.15);color:#22c55e;} .h-icon.out{background:rgba(239,68,68,.15);color:#ef4444;}
        .h-icon.send{background:rgba(99,102,241,.15);color:#818cf8;}
        .h-info{flex:1;min-width:0;}
        .h-type{font-size:11px;font-weight:700;color:#e2e8f0;}
        .h-ref{font-size:9px;color:#475569;font-family:monospace;}
        .h-dest{font-size:9px;color:#818cf8;}
        .h-amount{text-align:right;}
        .h-total{font-size:13px;font-weight:800;color:#fff;}
        .h-fee{font-size:9px;color:#fbbf24;}
        .h-time{font-size:9px;color:#475569;text-align:right;white-space:nowrap;}
        .h-status{font-size:8px;padding:1px 4px;border-radius:3px;font-weight:700;text-transform:uppercase;}
        .h-status.completed{background:rgba(34,197,94,.15);color:#22c55e;}
        .h-status.edited{background:rgba(251,191,36,.15);color:#fbbf24;}

        /* Send modal */
        .modal-overlay{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.7);z-index:1000;align-items:center;justify-content:center;}
        .modal-overlay.active{display:flex;}
        .modal-box{background:#1e293b;border-radius:16px;padding:20px;width:90%;max-width:400px;max-height:80vh;overflow-y:auto;border:1px solid #334155;}
        .modal-box h2{font-size:15px;color:#fff;margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;}
        .modal-close{background:none;border:none;color:#64748b;font-size:18px;cursor:pointer;padding:4px 8px;border-radius:6px;}
        .modal-close:hover{background:#334155;color:#fff;}
        .m-field{margin-bottom:10px;}
        .m-field label{display:block;font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
        .m-field input,.m-field select,.m-field textarea{width:100%;padding:10px 12px;background:#0f172a;border:1.5px solid #334155;border-radius:8px;color:#fff;font-size:13px;outline:none;font-family:inherit;}
        .m-field input:focus,.m-field select:focus,.m-field textarea:focus{border-color:#6366f1;}
        .m-field textarea{resize:none;height:50px;}
        .m-toggle{display:flex;gap:0;background:#0f172a;border-radius:8px;padding:3px;margin-bottom:10px;}
        .m-toggle button{flex:1;padding:8px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;background:transparent;color:#64748b;transition:all .15s;}
        .m-toggle button.active{background:#6366f1;color:#fff;}
        .m-submit{width:100%;padding:12px;background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border:none;border-radius:8px;font-weight:800;font-size:14px;cursor:pointer;margin-top:6px;}
        .m-submit:hover{opacity:.9;}

        .detail-row{display:flex;justify-content:space-between;padding:5px 0;font-size:12px;border-bottom:1px solid #0f172a;}
        .detail-row .label{color:#64748b;} .detail-row .value{color:#e2e8f0;font-weight:600;}
        .detail-row.total .value{font-size:16px;color:#fff;font-weight:800;}
        .modal-actions{display:flex;gap:8px;margin-top:14px;}
        .modal-actions button{flex:1;padding:9px;border:none;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer;}
        .btn-edit{background:#3b82f6;color:#fff;} .btn-close-modal{background:#334155;color:#94a3b8;}

        /* Back to cashier button */
        .btn-back-cashier{font-weight:700;color:#60a5fa;font-size:14px;padding:8px 16px !important;}

        /* Tablet responsive */
        @media (pointer: coarse) {
            .btn-back-cashier{font-size:17px !important;padding:12px 20px !important;background:#2563eb;color:#fff;border-radius:8px;}
            .gcash-brand h1{font-size:22px;}
            .gcash-brand .wallet-val{font-size:20px;}
            .type-btn{padding:14px;font-size:16px;}
            #amount{font-size:28px;padding:16px 16px 16px 40px;}
            .amount-input-wrap .peso{font-size:22px;}
            #reference{font-size:20px;padding:14px 12px;}
            .amount-label{font-size:13px;}
            .quick-amounts{grid-template-columns:repeat(4,1fr);gap:6px;}
            .quick-btn{padding:14px 8px;font-size:14px;}
            .total-bar{padding:14px 18px;}
            .total-bar .tb-total{font-size:26px;}
            .total-bar .tb-label{font-size:14px;}
            .total-bar .tb-amount{font-size:14px;}
            .submit-btn{font-size:20px !important;padding:18px !important;}
            .gcash-form-panel{padding:16px 20px;}
            .shortcut-bar{padding:8px 14px;}
            .sc-key{font-size:14px;padding:10px 14px;}
            .input-row{gap:14px;}
            .field label, .amount-label{font-size:14px;}
            .account-selector select{font-size:16px;padding:12px;}
            .min-fee-row{font-size:14px;}
            .min-fee-row input{font-size:16px;width:70px;padding:8px;}
        }

        @media (max-width: 600px) {
            .quick-amounts{grid-template-columns:repeat(3,1fr);}
            .input-row{flex-direction:column;}
        }
    </style>
    <link rel="stylesheet" href="/oro-store-demo/core/responsive.css">
    <script src="/oro-store-demo/core/custom_alert.js"></script>
</head>
<body>

<div class="shortcut-bar">
    <?php if (!$isKiosk): ?><a href="/oro-store-demo/cashier/cashier.php" style="background:#2563eb;color:#fff;padding:6px 16px;font-size:13px;font-weight:700;text-decoration:none;border-radius:6px;white-space:nowrap;">← Cashier</a><?php endif; ?>
    <?php if (!$isKiosk): ?>
    <span class="sc-key sc-green" onclick="submitTransaction()"><kbd>Enter</kbd> Submit</span>
    <span class="sc-key sc-blue" onclick="printGCashReceipt()"><kbd>F1</kbd> Print</span>
    <?php endif; ?>
    <span class="sc-key sc-red" onclick="<?php echo $isKiosk ? "location.href='/oro-store-demo/kiosk/kiosk.php'" : 'window.close()'; ?>"><kbd>Esc</kbd> <?php echo $isKiosk ? 'Back' : 'Close'; ?></span>
    <?php if (!$isKiosk): ?>
    <span class="sc-key" onclick="resetForm()"><kbd>Del</kbd> Clear</span>
    <span class="sc-key" onclick="toggleHistory()"><kbd>F12</kbd> History</span>
    <?php endif; ?>
</div>

<div class="gcash-layout">
    <div class="gcash-form-panel">
        <!-- Brand + Wallet -->
        <div class="gcash-brand">
            <div class="gcash-logo">G</div>
            <div><h1>GCash</h1><div class="sub"><?php echo htmlspecialchars($currentUser['full_name']); ?></div></div>
            <?php if (!$isKiosk): ?>
            <div class="wallet">
                <div class="wallet-label">GCash Wallet</div>
                <div class="wallet-val <?php echo $wallet_balance > 0 ? 'wallet-pos' : ($wallet_balance < 0 ? 'wallet-neg' : 'wallet-zero'); ?>">&#8369;<?php echo number_format(abs($wallet_balance), 2); ?><?php echo $wallet_balance < 0 ? ' (deficit)' : ''; ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Type Toggle -->
        <div class="type-toggle">
            <button class="type-btn active-in" data-type="cash_in" onclick="selectType('cash_in')">Cash In 1%</button>
            <button class="type-btn" data-type="cash_out" onclick="selectType('cash_out')">Cash Out 2%</button>
        </div>
        <!-- Min Fee Settings -->
        <div style="display:flex;gap:6px;margin-bottom:8px;<?php echo $isKiosk ? 'display:none;' : ''; ?>">
            <div style="flex:1;display:flex;align-items:center;gap:4px;background:#1e293b;padding:4px 8px;border-radius:6px;border:1px solid #334155;">
                <span style="font-size:9px;color:#22c55e;font-weight:700;white-space:nowrap;">CI Min:</span>
                <input type="number" id="min-fee-in" value="5" min="0" step="1" style="width:65px;background:transparent;border:1px solid #334155;border-radius:4px;color:#22c55e;font-size:16px;font-weight:700;outline:none;text-align:center;padding:4px;" <?php echo $isKiosk ? 'disabled' : ''; ?>>
            </div>
            <div style="flex:1;display:flex;align-items:center;gap:4px;background:#1e293b;padding:4px 8px;border-radius:6px;border:1px solid #334155;">
                <span style="font-size:9px;color:#ef4444;font-weight:700;white-space:nowrap;">CO Min:</span>
                <input type="number" id="min-fee-out" value="10" min="0" step="1" style="width:65px;background:transparent;border:1px solid #334155;border-radius:4px;color:#ef4444;font-size:16px;font-weight:700;outline:none;text-align:center;padding:4px;" <?php echo $isKiosk ? 'disabled' : ''; ?>>
            </div>
        </div>

        <!-- GCash Account Selector -->
        <?php
        // Build store lookup for kiosk labels
        $_store_lookup = [];
        foreach ($stores_list as $_s) { $_store_lookup[$_s['id']] = $_s['store_code']; }
        $_my_store_id = $store_id ?: 0;
        ?>
        <div style="margin-bottom:8px;<?php echo $isKiosk ? 'display:none;' : ''; ?>">
            <div class="amount-label">GCash Account <span style="font-size:8px;color:#475569;font-weight:400;text-transform:none;letter-spacing:0;">(🖥️ = visible on this store's kiosk)</span></div>
            <div style="display:flex;gap:6px;align-items:center;">
                <select id="gcash-account" style="flex:1;padding:8px 10px;background:#1e293b;border:1.5px solid #334155;border-radius:8px;color:#fff;font-size:12px;outline:none;">
                    <option value="">-- Select Account --</option>
                    <?php foreach ($gcash_accounts as $ga):
                        $assigned_ids = array_filter(explode(',', $ga['kiosk_store_ids'] ?? ''));
                        $is_this_store = in_array((string)$_my_store_id, $assigned_ids);
                        $store_tags = [];
                        foreach ($assigned_ids as $sid) {
                            if (isset($_store_lookup[$sid])) { $store_tags[] = $_store_lookup[$sid]; }
                        }
                        $tag_str = !empty($store_tags) ? ' [' . implode(', ', $store_tags) . ']' : '';
                    ?>
                    <option value="<?php echo $ga['id']; ?>" <?php echo ($isKiosk && count($gcash_accounts) === 1) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ga['account_name']); ?> (<?php echo htmlspecialchars($ga['phone_number']); ?>)<?php if (!$isKiosk && $is_this_store): ?> 🖥️<?php endif; ?><?php if (!$isKiosk && !empty($tag_str)): ?><?php echo htmlspecialchars($tag_str); ?><?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$isKiosk): ?>
                <button onclick="toggleKioskAccount()" title="Toggle kiosk visibility for selected account" style="padding:6px 10px;background:#1e293b;border:1.5px solid #334155;border-radius:8px;color:#a78bfa;font-size:14px;cursor:pointer;white-space:nowrap;">🖥️</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isKiosk): ?>
        <!-- Store GCash number display for cash out (kiosk only) -->
        <?php
        $kiosk_acct = !empty($gcash_accounts) ? $gcash_accounts[0] : null;
        ?>
        <div id="kiosk-send-to" style="display:none;margin-bottom:8px;background:linear-gradient(135deg,#1e3a5f,#1e40af);border:2px solid #3b82f6;border-radius:10px;padding:14px;text-align:center;">
            <div style="font-size:10px;color:#93c5fd;text-transform:uppercase;font-weight:700;letter-spacing:1px;margin-bottom:4px;">Send money to this GCash number</div>
            <div style="font-size:28px;font-weight:900;color:#fff;letter-spacing:3px;"><?php echo $kiosk_acct ? htmlspecialchars($kiosk_acct['phone_number']) : '—'; ?></div>
            <div style="font-size:12px;color:#93c5fd;margin-top:2px;"><?php echo $kiosk_acct ? htmlspecialchars($kiosk_acct['account_name']) : ''; ?></div>
            <div style="font-size:10px;color:#60a5fa;margin-top:6px;">After sending, enter the reference number below</div>
        </div>

        <!-- Customer Number (kiosk only) -->
        <div style="margin-bottom:8px;">
            <div class="amount-label" id="customer-number-label">Customer GCash Number</div>
            <input type="text" id="customer-number" maxlength="11" placeholder="09XX XXX XXXX" inputmode="tel"
                style="width:100%;padding:10px 12px;font-size:15px;font-weight:700;background:#1e293b;border:2px solid #334155;border-radius:10px;color:#fff;outline:none;letter-spacing:1px;">
        </div>
        <?php endif; ?>

        <!-- Amount + Reference -->
        <div class="input-row">
            <div class="field" style="flex:2;">
                <div class="amount-label">Amount</div>
                <div class="amount-input-wrap">
                    <span class="peso">&#8369;</span>
                    <input type="number" id="amount" step="0.01" min="0" placeholder="0.00" autofocus>
                </div>
            </div>
            <div class="field" id="reference-field">
                <div class="amount-label"><?php echo $isKiosk ? 'GCash Reference Number' : 'Reference'; ?></div>
                <input type="text" id="reference" maxlength="13" placeholder="<?php echo $isKiosk ? 'Enter ref # from GCash' : 'Reference #'; ?>" inputmode="numeric">
            </div>
        </div>

        <!-- Quick Amounts -->
        <div class="quick-amounts">
            <button class="quick-btn" onclick="setAmount(100)">100</button>
            <button class="quick-btn" onclick="setAmount(200)">200</button>
            <button class="quick-btn" onclick="setAmount(500)">500</button>
            <button class="quick-btn" onclick="setAmount(1000)">1K</button>
            <button class="quick-btn" onclick="setAmount(2000)">2K</button>
            <button class="quick-btn" onclick="setAmount(5000)">5K</button>
        </div>

        <!-- Live Total -->
        <div class="total-bar">
            <div><div class="tb-label">Amount: <span class="tb-amount" id="d-amount">&#8369;0.00</span></div><div class="tb-fee" id="d-fee">Fee (1%): &#8369;0.00</div></div>
            <div class="tb-total" id="d-total">&#8369;0.00</div>
        </div>

        <?php if (!$isKiosk): ?><div id="edit-indicator" style="display:none;" class="edit-badge">Editing #<span id="edit-id"></span></div><?php else: ?><div id="edit-indicator" style="display:none;"></div><?php endif; ?>

        <?php if (!$isKiosk): ?>
        <!-- Stats -->
        <div class="stats-inline">
            <div class="stat-box stat-in"><div class="stat-label">In</div><div class="stat-val"><?php echo $today_in['c']; ?></div><div class="stat-sub">&#8369;<?php echo number_format($today_in['a'], 0); ?></div></div>
            <div class="stat-box stat-out"><div class="stat-label">Out</div><div class="stat-val"><?php echo $today_out['c']; ?></div><div class="stat-sub">&#8369;<?php echo number_format($today_out['a'], 0); ?></div></div>
            <div class="stat-box stat-fee"><div class="stat-label">Fees</div><div class="stat-val">&#8369;<?php echo number_format($total_fees, 0); ?></div></div>
            <div class="stat-box stat-send"><div class="stat-label">Sent</div><div class="stat-val"><?php echo $today_transfers['c']; ?></div><div class="stat-sub">&#8369;<?php echo number_format($today_transfers['a'], 0); ?></div></div>
        </div>
        <?php endif; ?>

        <!-- Buttons -->
        <div class="btn-row">
            <button class="submit-btn cash-in" id="submit-btn" onclick="submitTransaction()">Cash In</button>
            <button class="aux-btn" onclick="printGCashReceipt()">Print</button>
            <?php if (!$isKiosk): ?><button class="aux-btn send" onclick="openSendModal()">Send</button><?php endif; ?>
            <?php if (!$isKiosk): ?><button class="aux-btn" onclick="toggleHistory()">History</button><?php endif; ?>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- Send GCash Modal -->
<div class="modal-overlay" id="send-modal">
    <div class="modal-box">
        <h2><span>Send GCash</span><button class="modal-close" onclick="closeSendModal()">&times;</button></h2>
        <div class="m-toggle">
            <button class="active" id="send-type-gcash" onclick="setSendType('send_gcash')">GCash to GCash</button>
            <button id="send-type-bank" onclick="setSendType('bank_transfer')">GCash to Bank</button>
        </div>
        <div class="m-field"><label>Amount</label><input type="number" id="send-amount" step="0.01" min="0" placeholder="0.00"></div>
        <div class="m-field"><label id="send-dest-label">GCash Number</label><input type="text" id="send-destination" placeholder="09XX XXX XXXX"></div>
        <div class="m-field"><label>Notes (optional)</label><textarea id="send-notes" placeholder="Payment for..., Transfer to..."></textarea></div>
        <button class="m-submit" onclick="submitSend()">Send GCash</button>
    </div>
</div>

<!-- Detail Modal -->
<div class="modal-overlay" id="detail-modal">
    <div class="modal-box">
        <h2><span id="dm-title">Details</span><button class="modal-close" onclick="closeDetail()">&times;</button></h2>
        <div id="dm-body"></div>
        <div class="modal-actions">
            <button class="btn-edit" id="dm-edit-btn" onclick="editFromDetail()">Edit</button>
            <button class="btn-close-modal" onclick="closeDetail()">Close</button>
        </div>
    </div>
</div>

<?php if (!$isKiosk): ?>
<!-- History Panel -->
<div class="history-panel" id="history-panel" onclick="if(event.target===this)toggleHistory()">
    <div class="history-inner">
        <div class="history-header"><h3>Transaction History</h3><button class="history-close" onclick="toggleHistory()">&times;</button></div>
        <div class="history-list" id="history-list"></div>
    </div>
</div>
<?php else: ?>
<div id="history-panel"></div>
<?php endif; ?>

<script>
let currentType = 'cash_in', feePct = 1, isEditMode = false, originalTxId = null, selectedTx = null;
let sendType = 'send_gcash';
const _isKiosk = <?php echo $isKiosk ? 'true' : 'false'; ?>;
let minFeeCashIn = parseFloat(localStorage.getItem('gcash_min_fee_cash_in') || 5);
let minFeeCashOut = parseFloat(localStorage.getItem('gcash_min_fee_cash_out') || 10);

function calcFee(amt) {
    const minFee = currentType === 'cash_in' ? minFeeCashIn : minFeeCashOut;
    const pctFee = amt * (feePct / 100);
    return Math.max(minFee, Math.ceil(pctFee));
}

// Init min fee inputs from localStorage
document.getElementById('min-fee-in').value = minFeeCashIn;
document.getElementById('min-fee-out').value = minFeeCashOut;
if (!_isKiosk) {
    document.getElementById('min-fee-in').addEventListener('change', function() {
        minFeeCashIn = parseFloat(this.value) || 0;
        localStorage.setItem('gcash_min_fee_cash_in', minFeeCashIn);
        calc();
    });
    document.getElementById('min-fee-out').addEventListener('change', function() {
        minFeeCashOut = parseFloat(this.value) || 0;
        localStorage.setItem('gcash_min_fee_cash_out', minFeeCashOut);
        calc();
    });
}

// Restore last used account per type from localStorage
function loadAccountForType(type) {
    const sel = document.getElementById('gcash-account');
    const saved = localStorage.getItem('gcash_acct_' + type);
    if (saved && sel.querySelector(`option[value="${saved}"]`)) sel.value = saved;
    else sel.value = '';
}
function saveAccountForType() {
    const val = document.getElementById('gcash-account').value;
    localStorage.setItem('gcash_acct_' + currentType, val);
}
document.getElementById('gcash-account').addEventListener('change', saveAccountForType);
loadAccountForType('cash_in');

function selectType(type) {
    saveAccountForType(); // save current before switching
    currentType = type; feePct = type === 'cash_in' ? 1 : 2;
    loadAccountForType(type); // load saved for new type
    document.querySelectorAll('.type-btn').forEach(b => { b.classList.remove('active-in','active-out'); if (b.dataset.type === type) b.classList.add(type === 'cash_in' ? 'active-in' : 'active-out'); });
    const btn = document.getElementById('submit-btn');
    btn.className = 'submit-btn ' + (type === 'cash_in' ? 'cash-in' : 'cash-out');
    btn.textContent = isEditMode ? 'Save Edit' : (type === 'cash_in' ? 'Cash In' : 'Cash Out');
    // Kiosk: toggle reference field and store number display based on type
    if (_isKiosk) {
        var refField = document.getElementById('reference-field');
        var sendTo = document.getElementById('kiosk-send-to');
        if (refField) refField.style.display = type === 'cash_in' ? 'none' : '';
        if (type === 'cash_in') document.getElementById('reference').value = '';
        // Show store GCash number for cash_out so customer knows where to send
        if (sendTo) {
            if (type === 'cash_out') {
                var sel = document.getElementById('gcash-account');
                var opt = sel.options[sel.selectedIndex];
                if (opt && opt.value) {
                    var text = opt.textContent.trim();
                    var match = text.match(/\(([\d\s]+)\)/);
                    document.getElementById('kiosk-store-number').textContent = match ? match[1].trim() : '—';
                    document.getElementById('kiosk-store-name').textContent = text.replace(/\(.*\)/, '').trim();
                }
                sendTo.style.display = 'block';
            } else {
                sendTo.style.display = 'none';
            }
        }
    }
    calc();
}

function setAmount(val) { document.getElementById('amount').value = val; calc(); document.getElementById('reference').focus(); }

function calc() {
    const amt = parseFloat(document.getElementById('amount').value) || 0;
    const fee = calcFee(amt);
    const total = amt + fee;
    const minFee = currentType === 'cash_in' ? minFeeCashIn : minFeeCashOut;
    document.getElementById('d-amount').textContent = '₱' + amt.toFixed(2);
    document.getElementById('d-fee').textContent = `Fee (${feePct}%, min ₱${minFee}): ₱${fee.toFixed(2)}`;
    document.getElementById('d-total').textContent = '₱' + total.toFixed(2);
}
// Kiosk: auto-select first available account + hide reference for cash_in
if (_isKiosk) {
    var _sel = document.getElementById('gcash-account');
    if (_sel.options.length > 1) _sel.selectedIndex = 1;
    var _refField = document.getElementById('reference-field');
    if (_refField) _refField.style.display = 'none';
}
document.getElementById('amount').addEventListener('input', calc);
document.getElementById('reference').addEventListener('input', function() { this.value = this.value.replace(/\D/g, '').slice(0, 13); });

function showToast(msg, type) { const t = document.getElementById('toast'); t.textContent = msg; t.className = 'toast ' + type + ' show'; setTimeout(() => t.classList.remove('show'), 4000); }

function submitTransaction() {
    const amount = parseFloat(document.getElementById('amount').value);
    const ref = document.getElementById('reference').value;
    const customerNumEl = document.getElementById('customer-number');
    const customerNum = customerNumEl ? customerNumEl.value.trim() : '';
    if (!amount || amount <= 0) { showToast('Enter a valid amount', 'error'); return; }
    var refRequired = !(_isKiosk && currentType === 'cash_in');
    if (refRequired && !/^\d+$/.test(ref)) { showToast('Enter the reference number', 'error'); return; }
    if (_isKiosk && !customerNum) { showToast('Enter customer GCash number', 'error'); if (customerNumEl) customerNumEl.focus(); return; }
    const fee = calcFee(amount); const total = amount + fee;

    // Kiosk: submit to queue instead of completing directly
    if (_isKiosk) {
        const gcashData = [{
            id: 0, name: 'GCash ' + (currentType === 'cash_in' ? 'Cash In' : 'Cash Out'),
            price: amount, purchase_price: 0, quantity: 1, subtotal: amount, profit: fee,
            gcash_type: currentType, gcash_fee: fee, gcash_total: total,
            gcash_reference: ref, gcash_customer_number: customerNum,
            gcash_account_id: document.getElementById('gcash-account').value
        }];
        const fd = new FormData();
        fd.append('action', 'submit_kiosk_order');
        fd.append('items', JSON.stringify(gcashData));
        fd.append('total_amount', total);
        fd.append('total_profit', fee);
        fd.append('items_count', 1);
        fd.append('payment_method', 'gcash_' + currentType);
        fetch('/oro-store-demo/kiosk/kiosk.php', { method: 'POST', body: fd })
        .then(r => r.json()).then(data => {
            if (data.success) {
                showKioskPriority(data.priority_number);
            } else showToast('Error: ' + (data.error || 'Failed'), 'error');
        }).catch(e => showToast('Error: ' + e.message, 'error'));
        return;
    }

    const fd = new FormData();
    if (isEditMode) { fd.append('action', 'reedit_gcash'); fd.append('original_id', originalTxId); }
    else { fd.append('action', 'complete_gcash'); }
    fd.append('type', currentType); fd.append('amount', amount); fd.append('fee', fee); fd.append('total', total); fd.append('reference', ref);
    fd.append('gcash_account_id', document.getElementById('gcash-account').value);
    if (customerNum) fd.append('customer_number', customerNum);
    fetch('/oro-store-demo/transactions/gcash.php', { method: 'POST', body: fd })
    .then(r => r.text()).then(text => {
        const data = JSON.parse(text);
        if (data.success) { if (typeof OroCache !== 'undefined') OroCache.invalidatePrefix('gcash_history'); customAlert(`${isEditMode?'Transaction Edited':'Transaction Complete'}!\nID: #${data.transaction_id}`, 'success', function(){ window.close(); }); }
        else showToast('Error: ' + data.error, 'error');
    }).catch(e => showToast('Error: ' + e.message, 'error'));
}

// Toggle kiosk visibility for selected account (admin only)
function toggleKioskAccount() {
    const sel = document.getElementById('gcash-account');
    if (!sel.value) { showToast('Select an account first', 'error'); return; }
    const fd = new FormData();
    fd.append('action', 'toggle_kiosk_account');
    fd.append('account_id', sel.value);
    fd.append('store_id', '<?php echo intval($_my_store_id); ?>');
    fetch('/oro-store-demo/transactions/gcash.php', { method: 'POST', body: fd })
    .then(r => r.json()).then(data => {
        if (data.success) { showToast('Kiosk visibility toggled for this store', 'success'); setTimeout(() => location.reload(), 800); }
        else showToast('Error', 'error');
    });
}

function resetForm() {
    document.getElementById('amount').value = ''; document.getElementById('reference').value = '';
    var cn = document.getElementById('customer-number'); if (cn) cn.value = '';
    isEditMode = false; originalTxId = null;
    document.getElementById('edit-indicator').style.display = 'none';
    document.getElementById('submit-btn').textContent = currentType === 'cash_in' ? 'Cash In' : 'Cash Out';
    calc(); document.getElementById('amount').focus();
}

function printGCashReceipt() {
    const amount = parseFloat(document.getElementById('amount').value); const ref = document.getElementById('reference').value;
    if (!amount || amount <= 0) { showToast('Enter amount', 'error'); return; }
    if (!/^\d+$/.test(ref)) { showToast('Enter reference', 'error'); return; }
    const fee = calcFee(amount);
    const w = window.open('/oro-store-demo/print/print_gcash_receipt.php', '_blank', 'width=400,height=600');
    if (w) w.addEventListener('load', () => w.postMessage({ type: currentType, amount, fee, total: amount + fee, reference: ref, feePercent: feePct, date: new Date().toLocaleString() }, '*'));
}

// Send GCash
function openSendModal() { document.getElementById('send-modal').classList.add('active'); document.getElementById('send-amount').focus(); }
function closeSendModal() { document.getElementById('send-modal').classList.remove('active'); }
function setSendType(type) {
    sendType = type;
    document.getElementById('send-type-gcash').classList.toggle('active', type === 'send_gcash');
    document.getElementById('send-type-bank').classList.toggle('active', type === 'bank_transfer');
    document.getElementById('send-dest-label').textContent = type === 'send_gcash' ? 'GCash Number' : 'Bank Account / Details';
    document.getElementById('send-destination').placeholder = type === 'send_gcash' ? '09XX XXX XXXX' : 'Bank name, account number';
}
function submitSend() {
    const amount = parseFloat(document.getElementById('send-amount').value);
    const dest = document.getElementById('send-destination').value.trim();
    const notes = document.getElementById('send-notes').value.trim();
    if (!amount || amount <= 0) { showToast('Enter amount', 'error'); return; }
    if (!dest) { showToast('Enter destination', 'error'); return; }
    const label = sendType === 'send_gcash' ? 'GCash' : 'Bank';
    if (!confirm(`Send ₱${amount.toFixed(2)} to ${label}: ${dest}?`)) return;
    const fd = new FormData();
    fd.append('action', 'send_gcash'); fd.append('transfer_type', sendType);
    fd.append('amount', amount); fd.append('destination', dest); fd.append('notes', notes);
    fetch('/oro-store-demo/transactions/gcash.php', { method: 'POST', body: fd })
    .then(r => r.json()).then(data => {
        if (data.success) {
            if (typeof OroCache !== 'undefined') OroCache.invalidatePrefix('gcash_history');
            showToast(`Sent ₱${amount.toFixed(2)} to ${dest}`, 'success');
            closeSendModal();
            document.getElementById('send-amount').value = '';
            document.getElementById('send-destination').value = '';
            document.getElementById('send-notes').value = '';
            loadHistory();
        } else showToast('Error: ' + data.error, 'error');
    }).catch(e => showToast('Error: ' + e.message, 'error'));
}

// History
function toggleHistory() { const p = document.getElementById('history-panel'); if (p.classList.contains('active')) p.classList.remove('active'); else { p.classList.add('active'); loadHistory(); } }
function _renderHistoryList(data) {
    const list = document.getElementById('history-list');
    if (!data || !data.length) { list.innerHTML = '<div style="text-align:center;padding:30px;color:#475569;">No transactions</div>'; return; }
    list.innerHTML = data.map(t => {
        const isIn = t.transaction_type === 'cash_in';
        const isOut = t.transaction_type === 'cash_out';
        const isSend = t.transaction_type === 'send_gcash' || t.transaction_type === 'bank_transfer';
        const icon = isSend ? '↗' : (isIn ? '↓' : '↑');
        const iconCls = isSend ? 'send' : (isIn ? 'in' : 'out');
        const typeLabel = isSend ? (t.transaction_type === 'send_gcash' ? 'Send GCash' : 'Bank Transfer') : (isIn ? 'Cash In' : 'Cash Out');
        const time = new Date(t.transaction_date);
        return `<div class="h-item" onclick="viewDetail(${t.id})">
            <div class="h-icon ${iconCls}">${icon}</div>
            <div class="h-info">
                <div class="h-type">${typeLabel} <span class="h-status ${t.status}">${t.status}</span></div>
                <div class="h-ref">${t.reference_number}${t.gcash_acct_name ? ` · ${t.gcash_acct_name}` : ''}</div>
                ${t.destination ? `<div class="h-dest">→ ${esc(t.destination)}</div>` : ''}
            </div>
            <div class="h-amount">
                <div class="h-total">${isSend?'−':''}₱${parseFloat(t.total_amount).toFixed(2)}</div>
                ${!isSend && parseFloat(t.fee) > 0 ? `<div class="h-fee">fee ₱${parseFloat(t.fee).toFixed(2)}</div>` : ''}
            </div>
            <div class="h-time">${time.toLocaleDateString('en-PH',{month:'short',day:'numeric'})}<br>${time.toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit',hour12:true})}</div>
        </div>`;
    }).join('');
}
function loadHistory() {
    const acctId = document.getElementById('gcash-account').value;
    const cacheKey = 'gcash_history_' + (acctId || 'all');
    const url = '/oro-store-demo/transactions/gcash.php?action=get_gcash_transactions' + (acctId ? '&account_id=' + acctId : '');

    if (typeof OroCache !== 'undefined') {
        var cached = OroCache.get(cacheKey);
        if (cached) {
            _renderHistoryList(cached);
            fetch(url).then(r => r.json()).then(data => {
                OroCache.set(cacheKey, data, 30);
                if (data.length !== cached.length || (data[0] && cached[0] && data[0].id !== cached[0].id)) _renderHistoryList(data);
            }).catch(function(){});
            return;
        }
    }
    fetch(url).then(r => r.text()).then(text => {
        var data;
        try { data = JSON.parse(text); } catch(e) { console.error('History parse error:', text); document.getElementById('history-list').innerHTML = '<div style="text-align:center;padding:30px;color:#ef4444;">Error loading history</div>'; return; }
        if (typeof OroCache !== 'undefined') OroCache.set(cacheKey, data, 30);
        _renderHistoryList(data);
    }).catch(e => { console.error('History fetch error:', e); document.getElementById('history-list').innerHTML = '<div style="text-align:center;padding:30px;color:#ef4444;">Error loading history</div>'; });
}
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function viewDetail(id) {
    fetch(`/oro-store-demo/transactions/gcash.php?action=get_gcash_details&id=${id}`).then(r => r.json()).then(data => {
        selectedTx = data.transaction; const t = data.transaction;
        const isSend = t.transaction_type === 'send_gcash' || t.transaction_type === 'bank_transfer';
        const typeLabel = isSend ? (t.transaction_type === 'send_gcash' ? 'Send GCash' : 'Bank Transfer') : (t.transaction_type === 'cash_in' ? 'Cash In' : 'Cash Out');
        let html = `
            <div class="detail-row"><span class="label">ID</span><span class="value">#${t.id}</span></div>
            <div class="detail-row"><span class="label">Date</span><span class="value">${new Date(t.transaction_date).toLocaleString()}</span></div>
            <div class="detail-row"><span class="label">Type</span><span class="value">${typeLabel}</span></div>
            <div class="detail-row"><span class="label">Amount</span><span class="value">₱${parseFloat(t.amount).toFixed(2)}</span></div>`;
        if (!isSend) html += `<div class="detail-row"><span class="label">Fee</span><span class="value" style="color:#fbbf24">₱${parseFloat(t.fee).toFixed(2)}</span></div>`;
        html += `<div class="detail-row total"><span class="label">Total</span><span class="value">₱${parseFloat(t.total_amount).toFixed(2)}</span></div>
            <div class="detail-row"><span class="label">Reference</span><span class="value" style="font-family:monospace">${t.reference_number}</span></div>`;
        if (t.user_reference) html += `<div class="detail-row"><span class="label">User Ref</span><span class="value" style="font-family:monospace">${t.user_reference}</span></div>`;
        if (t.gcash_acct_name) html += `<div class="detail-row"><span class="label">Account</span><span class="value" style="color:#22c55e">${esc(t.gcash_acct_name)} (${esc(t.gcash_acct_phone)})</span></div>`;
        if (t.destination) html += `<div class="detail-row"><span class="label">Destination</span><span class="value" style="color:#818cf8">${esc(t.destination)}</span></div>`;
        if (t.notes) html += `<div class="detail-row"><span class="label">Notes</span><span class="value">${esc(t.notes)}</span></div>`;
        html += `<div class="detail-row"><span class="label">Cashier</span><span class="value">${t.user_name||'—'}</span></div>`;
        document.getElementById('dm-body').innerHTML = html;
        document.getElementById('dm-edit-btn').style.display = (!isSend && t.status === 'completed') ? '' : 'none';
        document.getElementById('detail-modal').classList.add('active');
    });
}
function closeDetail() { document.getElementById('detail-modal').classList.remove('active'); selectedTx = null; }
function editFromDetail() {
    if (!selectedTx) return;
    isEditMode = true; originalTxId = selectedTx.id;
    selectType(selectedTx.transaction_type);
    document.getElementById('amount').value = selectedTx.amount;
    document.getElementById('reference').value = selectedTx.user_reference || '';
    calc();
    document.getElementById('edit-indicator').style.display = '';
    document.getElementById('edit-id').textContent = selectedTx.id;
    document.getElementById('submit-btn').textContent = 'Save Edit';
    closeDetail(); document.getElementById('amount').focus();
}

// Keyboard
document.addEventListener('keydown', function(e) {
    const modalOpen = document.getElementById('detail-modal').classList.contains('active');
    const sendOpen = document.getElementById('send-modal').classList.contains('active');
    const historyOpen = document.getElementById('history-panel').classList.contains('active');
    if (modalOpen) { if (e.key === 'Escape') { e.preventDefault(); closeDetail(); } else if (e.key === 'Enter') { e.preventDefault(); editFromDetail(); } return; }
    if (sendOpen) { if (e.key === 'Escape') { e.preventDefault(); closeSendModal(); } return; }
    if (historyOpen) { if (e.key === 'Escape' || e.key === 'F12') { e.preventDefault(); toggleHistory(); } return; }
    if (e.key === 'F1') { e.preventDefault(); printGCashReceipt(); }
    else if (e.key === 'F12' && !_isKiosk) { e.preventDefault(); toggleHistory(); }
    else if (e.key === 'Delete') { e.preventDefault(); resetForm(); }
    else if (e.key === 'Escape') { e.preventDefault(); <?php echo $isKiosk ? "location.href='/oro-store-demo/kiosk/kiosk.php';" : 'window.close();'; ?> }
    else if (e.key === 'Enter' && (document.activeElement.id === 'reference' || document.activeElement.tagName === 'BODY')) { e.preventDefault(); submitTransaction(); }
});
document.getElementById('amount').addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); document.getElementById('reference').focus(); document.getElementById('reference').select(); } });

calc();

// Kiosk priority number display with auto-print and auto-redirect
var _gcashKioskTimer = null;
var _gcashKioskInfo = null;

function showKioskPriority(num) {
    var padded = String(num).padStart(3, '0');
    var modal = document.getElementById('kiosk-priority-modal');
    if (!modal) return;
    document.getElementById('kiosk-priority-num').textContent = padded;

    // Show GCash details
    var detailsEl = document.getElementById('kiosk-gcash-details');
    var customerNumEl = document.getElementById('customer-number');
    var custNum = customerNumEl ? customerNumEl.value.trim() : '';
    var amt = parseFloat(document.getElementById('amount').value) || 0;
    var fee = calcFee(amt);
    var total = amt + fee;
    var typeLabel = currentType === 'cash_in' ? 'Cash In' : 'Cash Out';

    _gcashKioskInfo = { type: currentType, customerNumber: custNum, amount: amt, fee: fee, total: total };

    if (detailsEl) {
        detailsEl.innerHTML =
            '<div style="font-size:13px;font-weight:700;color:#3b82f6;margin-bottom:6px;">GCash ' + typeLabel + '</div>' +
            '<div style="font-size:12px;color:#475569;">GCash #: <strong>' + (custNum || '-') + '</strong></div>' +
            '<div style="font-size:12px;color:#475569;">Amount: <strong>₱' + amt.toFixed(2) + '</strong></div>' +
            '<div style="font-size:12px;color:#475569;">Fee: <strong>₱' + fee.toFixed(2) + '</strong></div>' +
            '<div style="font-size:14px;font-weight:800;color:#1e293b;margin-top:4px;">Total: ₱' + total.toFixed(2) + '</div>';
    }

    modal.style.display = 'flex';

    // Auto-print via RawBT
    var store = <?php echo json_encode($currentUser['store_name'] ?? 'ORO STORE'); ?>;
    try {
        if (typeof BTPrint !== 'undefined') {
            BTPrint.printPriorityGCash(parseInt(padded), store, currentType, custNum, amt, fee, total);
        }
    } catch (e) { /* silent */ }

    // Auto-redirect countdown
    var seconds = 10;
    var countEl = document.getElementById('kiosk-priority-countdown');
    if (countEl) countEl.textContent = seconds;
    if (_gcashKioskTimer) clearInterval(_gcashKioskTimer);
    _gcashKioskTimer = setInterval(function () {
        seconds--;
        if (countEl) countEl.textContent = seconds;
        if (seconds <= 0) {
            clearInterval(_gcashKioskTimer);
            _gcashKioskTimer = null;
            location.href = '/oro-store-demo/kiosk/kiosk.php';
        }
    }, 1000);
}

function closeKioskPriority() {
    if (_gcashKioskTimer) { clearInterval(_gcashKioskTimer); _gcashKioskTimer = null; }
    location.href = '/oro-store-demo/kiosk/kiosk.php';
}
</script>

<?php if ($isKiosk): ?>
<script src="/oro-store-demo/core/cache.js"></script>
<script src="/oro-store-demo/core/bt_print.js"></script>
<!-- Kiosk Priority Modal -->
<div id="kiosk-priority-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(6px);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:20px;padding:40px 50px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);max-width:420px;width:90%;">
        <div style="font-size:14px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;">Your Priority Number</div>
        <div id="kiosk-priority-num" style="font-size:96px;font-weight:900;color:#7c3aed;line-height:1;margin:10px 0 16px;">—</div>
        <div id="kiosk-gcash-details" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 16px;margin:0 auto 16px;max-width:280px;text-align:center;"></div>
        <div style="font-size:12px;color:#94a3b8;margin-bottom:6px;">Please wait for your number to be called</div>
        <div style="font-size:12px;color:#94a3b8;margin-bottom:16px;">Returning in <span id="kiosk-priority-countdown" style="font-weight:700;color:#7c3aed;">10</span>s</div>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button onclick="closeKioskPriority()" style="padding:12px 28px;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;background:#f1f5f9;color:#334155;">New Order</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isKiosk): ?>
<!-- Inactivity timeout overlay -->
<div id="inactivity-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);z-index:8000;align-items:center;justify-content:center;">
    <div style="background:#1e293b;border-radius:16px;padding:32px 40px;text-align:center;max-width:360px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.5);">
        <div style="font-size:36px;margin-bottom:12px;">⏳</div>
        <div style="font-size:16px;font-weight:700;color:#fff;margin-bottom:8px;">Still there?</div>
        <div style="font-size:13px;color:#94a3b8;margin-bottom:16px;">Returning to kiosk in <span id="inactivity-seconds" style="font-weight:700;color:#fbbf24;">10</span>s</div>
        <button onclick="resetInactivity()" style="padding:12px 32px;background:#7c3aed;color:#fff;border:none;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;">I'm still here</button>
    </div>
</div>
<script>
(function(){
    var IDLE_TIMEOUT = 10000;
    var COUNTDOWN_FROM = 10;
    var _idleTimer = null;
    var _countdownTimer = null;
    var _counting = false;

    function resetInactivity() {
        if (_countdownTimer) { clearInterval(_countdownTimer); _countdownTimer = null; }
        _counting = false;
        document.getElementById('inactivity-overlay').style.display = 'none';
        startIdleTimer();
    }
    window.resetInactivity = resetInactivity;

    function startIdleTimer() {
        if (_idleTimer) clearTimeout(_idleTimer);
        // Don't start idle timer if priority modal is showing
        var pm = document.getElementById('kiosk-priority-modal');
        if (pm && pm.style.display !== 'none') return;
        _idleTimer = setTimeout(showCountdown, IDLE_TIMEOUT);
    }

    function showCountdown() {
        if (_counting) return;
        _counting = true;
        var seconds = COUNTDOWN_FROM;
        var el = document.getElementById('inactivity-seconds');
        var overlay = document.getElementById('inactivity-overlay');
        if (el) el.textContent = seconds;
        overlay.style.display = 'flex';
        _countdownTimer = setInterval(function(){
            seconds--;
            if (el) el.textContent = seconds;
            if (seconds <= 0) {
                clearInterval(_countdownTimer);
                location.href = '/oro-store-demo/kiosk/kiosk.php';
            }
        }, 1000);
    }

    ['click','touchstart','keydown','input','scroll'].forEach(function(evt){
        document.addEventListener(evt, function(){
            if (_counting) return;
            startIdleTimer();
        }, true);
    });

    startIdleTimer();
})();
</script>
<?php endif; ?>

<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('GCash Transactions', [
    'Transaction Types' => [
        'Cash In — customer deposits cash, gets GCash credit',
        'Cash Out — customer withdraws GCash to cash',
        'Send GCash — transfer to another GCash number',
        'Bank Transfer — send to a bank account',
    ],
    'How It Works' => [
        'Select a GCash account from the dropdown',
        'Enter amount — fee auto-calculates based on min fee settings',
        'Enter reference number (required for cash out)',
        'Total = Amount + Fee shown before confirming',
        'Transaction saves to database and prints receipt',
        'Wallet balance tracks net cash in/out over time',
    ],
    'Kiosk Mode' => [
        'Fee controls and history are hidden',
        'Store GCash number displayed for Cash Out',
        'Customer number field is required',
        'Submitted as queue order — cashier processes it',
        'Inactivity timer: 10s idle then 10s countdown to redirect',
    ],
    'Account Management' => [
        'Toggle kiosk visibility per store with the monitor icon',
        'Each store can show a different account on its kiosk',
        'History filtered by current device/store',
    ],
]);
?>
</body>
</html>
