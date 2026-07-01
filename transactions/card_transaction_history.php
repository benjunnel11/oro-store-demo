<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

// Only admins can access
if (!isAdmin()) {
    header("Location: /oro-store-demo/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

// Bank accounts table
$conn->query("CREATE TABLE IF NOT EXISTS bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS bank_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_account_id INT NOT NULL,
    transaction_type ENUM('deposit','withdraw') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    fee DECIMAL(12,2) DEFAULT 0,
    notes TEXT,
    user_id INT,
    user_name VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0
)");

// Add initial_balance_set column if missing
$ba_cols = [];
$ba_cr = $conn->query("SHOW COLUMNS FROM bank_accounts");
while ($ba_c = $ba_cr->fetch_assoc()) $ba_cols[] = $ba_c['Field'];
if (!in_array('initial_balance_set', $ba_cols)) $conn->query("ALTER TABLE bank_accounts ADD COLUMN initial_balance_set TINYINT(1) DEFAULT 0");

// AJAX: bank account management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bank_action'])) {
    header('Content-Type: application/json');
    if ($_POST['bank_action'] === 'add_bank') {
        $name = trim($_POST['bank_name']);
        if (!$name) { echo json_encode(['success'=>false,'error'=>'Enter bank name']); exit; }
        $stmt = $conn->prepare("INSERT INTO bank_accounts (bank_name) VALUES (?)");
        $stmt->bind_param("s", $name); $stmt->execute();
        echo json_encode(['success'=>true,'id'=>$conn->insert_id]); exit;
    }
    if ($_POST['bank_action'] === 'delete_bank') {
        $conn->query("UPDATE bank_accounts SET is_active = 0 WHERE id = " . intval($_POST['id']));
        echo json_encode(['success'=>true]); exit;
    }
    if ($_POST['bank_action'] === 'bank_txn') {
        require_once __DIR__ . '/../core/system_logger.php';
        $bid = intval($_POST['bank_id']);
        $type = $_POST['type']; // deposit, withdraw, or set_balance
        $amount = floatval($_POST['amount']);
        $fee = floatval($_POST['fee'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        if ($amount < 0) { echo json_encode(['success'=>false,'error'=>'Invalid amount']); exit; }

        if ($type === 'set_balance') {
            $bank_acct = $conn->query("SELECT * FROM bank_accounts WHERE id = $bid")->fetch_assoc();
            // If initial already set, require super_admin password
            if ($bank_acct && $bank_acct['initial_balance_set']) {
                if ($currentUser['role'] !== 'super_admin') {
                    echo json_encode(['success'=>false,'error'=>'Only the super admin can adjust after initial calibration']); exit;
                }
                $password = $_POST['password'] ?? '';
                if (empty($password)) { echo json_encode(['success'=>false,'error'=>'Password required']); exit; }
                $pw_check = $conn->query("SELECT password FROM users WHERE id = {$currentUser['id']}")->fetch_assoc();
                if (!$pw_check || !password_verify($password, $pw_check['password'])) {
                    echo json_encode(['success'=>false,'error'=>'Incorrect password']); exit;
                }
            }

            $cur = $conn->query("SELECT
                COALESCE(SUM(CASE WHEN transaction_type='deposit' THEN amount ELSE 0 END),0)
                - COALESCE(SUM(CASE WHEN transaction_type='withdraw' THEN amount ELSE 0 END),0)
                - COALESCE(SUM(fee),0) as bal
                FROM bank_transactions WHERE bank_account_id = $bid AND is_deleted = 0")->fetch_assoc()['bal'];
            $diff = $amount - floatval($cur);
            if ($diff == 0 && !$bank_acct['initial_balance_set']) {
                $conn->query("UPDATE bank_accounts SET initial_balance_set = 1 WHERE id = $bid");
                echo json_encode(['success'=>true,'id'=>0]); exit;
            }
            if ($diff != 0) {
                $adj_type = $diff > 0 ? 'deposit' : 'withdraw';
                $adj_amount = abs($diff);
                $adj_notes = "Balance set to ₱" . number_format($amount, 2) . ($notes ? " — $notes" : "");
                $stmt = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_type, amount, fee, notes, user_id, user_name) VALUES (?, ?, ?, 0, ?, ?, ?)");
                $stmt->bind_param("isdsss", $bid, $adj_type, $adj_amount, $adj_notes, $currentUser['id'], $currentUser['full_name']);
                $stmt->execute(); $tid = $conn->insert_id; $stmt->close();
            }
            $conn->query("UPDATE bank_accounts SET initial_balance_set = 1 WHERE id = $bid");
            $bname = $conn->query("SELECT bank_name FROM bank_accounts WHERE id = $bid")->fetch_assoc()['bank_name'] ?? '';
            logActivity('transaction', "Bank balance set: $bname → ₱$amount", $currentUser['id'], null, ['bank'=>$bname,'amount'=>$amount,'diff'=>$diff ?? 0]);
            echo json_encode(['success'=>true,'id'=>$tid ?? 0]); exit;
        }

        if ($amount <= 0) { echo json_encode(['success'=>false,'error'=>'Enter amount']); exit; }
        $stmt = $conn->prepare("INSERT INTO bank_transactions (bank_account_id, transaction_type, amount, fee, notes, user_id, user_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issdsss", $bid, $type, $amount, $fee, $notes, $currentUser['id'], $currentUser['full_name']);
        $stmt->execute(); $tid = $conn->insert_id; $stmt->close();

        // Impact on cash register (not for set_balance)
        if ($type === 'deposit') {
            $reg = -$amount;
        } else {
            $reg = $amount;
        }
        $tx_num = 'BANK-' . strtoupper(substr($type,0,1)) . '-' . date('ymd') . '-' . str_pad($tid, 4, '0', STR_PAD_LEFT);
        require_once __DIR__ . '/../sync/sync_helper.php';
        $db2 = new SyncDB();
        $stmt2 = $conn->prepare("INSERT INTO transactions (transaction_number, user_id, store_id, total_amount, total_profit, payment_method, status, transaction_date) VALUES (?, ?, ?, ?, 0, 'cash_adjustment', 'completed', NOW())");
        $sid = $currentUser['store_id'] ?? null;
        $stmt2->bind_param("siid", $tx_num, $currentUser['id'], $sid, $reg);
        $stmt2->execute(); $stmt2->close();

        $bname = $conn->query("SELECT bank_name FROM bank_accounts WHERE id = $bid")->fetch_assoc()['bank_name'] ?? '';
        logActivity('transaction', "Bank $type: ₱$amount " . ($type === 'deposit' ? 'to' : 'from') . " $bname" . ($fee > 0 ? " (fee: ₱$fee)" : ""), $currentUser['id'], $sid, [
            'bank_txn_id'=>$tid, 'bank'=>$bname, 'type'=>$type, 'amount'=>$amount, 'fee'=>$fee
        ]);
        echo json_encode(['success'=>true,'id'=>$tid]); exit;
    }
}

// Get bank accounts with balances
$bank_accounts = $conn->query("SELECT ba.*,
    COALESCE((SELECT SUM(CASE WHEN bt.transaction_type='deposit' THEN bt.amount ELSE 0 END) FROM bank_transactions bt WHERE bt.bank_account_id = ba.id AND bt.is_deleted = 0), 0)
    + COALESCE((SELECT SUM(at2.amount) FROM atm_transactions at2 WHERE at2.store_id IS NOT NULL AND at2.is_deleted = 0), 0) / GREATEST((SELECT COUNT(*) FROM bank_accounts WHERE is_active = 1), 1)
    as total_deposits,
    COALESCE((SELECT SUM(CASE WHEN bt.transaction_type='withdraw' THEN bt.amount ELSE 0 END) FROM bank_transactions bt WHERE bt.bank_account_id = ba.id AND bt.is_deleted = 0), 0) as total_withdrawals,
    COALESCE((SELECT SUM(bt.fee) FROM bank_transactions bt WHERE bt.bank_account_id = ba.id AND bt.is_deleted = 0), 0) as total_fees,
    COALESCE((SELECT COUNT(*) FROM bank_transactions bt WHERE bt.bank_account_id = ba.id AND bt.is_deleted = 0), 0) as txn_count
    FROM bank_accounts ba WHERE ba.is_active = 1 ORDER BY ba.bank_name")->fetch_all(MYSQLI_ASSOC);

// Simpler balance: deposits - withdrawals - fees (ATM deposits tracked separately per account)
foreach ($bank_accounts as &$ba) {
    // ATM revenue that goes to this bank
    $atm_revenue = floatval($conn->query("SELECT COALESCE(SUM(amount + COALESCE(service_charge,0)),0) as t FROM atm_transactions WHERE is_deleted = 0 AND status = 'completed'")->fetch_assoc()['t']);
    $bank_count = max(1, count($bank_accounts));
    $ba['atm_share'] = $atm_revenue / $bank_count; // split evenly for now
    $ba['balance'] = floatval($ba['total_deposits']) - floatval($ba['total_withdrawals']) - floatval($ba['total_fees']) + $ba['atm_share'];
}
unset($ba);

// Get filter parameters
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$filter_store = isset($_GET['store']) ? intval($_GET['store']) : 0;
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_cashier = isset($_GET['cashier']) ? intval($_GET['cashier']) : 0;
$show_settled = isset($_GET['show_settled']) && $_GET['show_settled'] === '1';

// Date range for inventory report
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Get statistics
$stats = [];

// Total transactions
$stats['total_transactions'] = $conn->query("SELECT COUNT(*) as count FROM atm_transactions WHERE is_deleted = 0")->fetch_assoc()['count'];

// Total amount
$stats['total_amount'] = $conn->query("SELECT SUM(amount) as total FROM atm_transactions WHERE is_deleted = 0")->fetch_assoc()['total'] ?? 0;

// Unsettled amount
$stats['unsettled_amount'] = $conn->query("SELECT SUM(amount) as total FROM atm_transactions WHERE is_deleted = 0 AND (settlement_id IS NULL OR settlement_id = 0)")->fetch_assoc()['total'] ?? 0;

// Unsettled count
$stats['unsettled_count'] = $conn->query("SELECT COUNT(*) as count FROM atm_transactions WHERE is_deleted = 0 AND (settlement_id IS NULL OR settlement_id = 0)")->fetch_assoc()['count'];

// Today's transactions
$stats['today_transactions'] = $conn->query("SELECT COUNT(*) as count FROM atm_transactions WHERE DATE(transaction_date) = CURDATE() AND is_deleted = 0")->fetch_assoc()['count'];

// Today's amount
$stats['today_amount'] = $conn->query("SELECT SUM(amount) as total FROM atm_transactions WHERE DATE(transaction_date) = CURDATE() AND is_deleted = 0")->fetch_assoc()['total'] ?? 0;

// This week's amount
$stats['week_amount'] = $conn->query("SELECT SUM(amount) as total FROM atm_transactions WHERE YEARWEEK(transaction_date) = YEARWEEK(CURDATE()) AND is_deleted = 0")->fetch_assoc()['total'] ?? 0;

// This month's amount
$stats['month_amount'] = $conn->query("SELECT SUM(amount) as total FROM atm_transactions WHERE YEAR(transaction_date) = YEAR(CURDATE()) AND MONTH(transaction_date) = MONTH(CURDATE()) AND is_deleted = 0")->fetch_assoc()['total'] ?? 0;

// Edited transactions count
$stats['edited_count'] = $conn->query("SELECT COUNT(*) as count FROM atm_transactions WHERE status = 'edited' AND is_deleted = 0")->fetch_assoc()['count'];

// Get all stores for filter
$stores = $conn->query("SELECT id, store_name, store_code FROM stores WHERE status = 'active' ORDER BY store_name")->fetch_all(MYSQLI_ASSOC);

// Get all cashiers for filter
$cashiers = $conn->query("SELECT DISTINCT u.id, u.full_name, u.username FROM users u INNER JOIN atm_transactions at ON u.id = at.user_id WHERE at.is_deleted = 0 ORDER BY u.full_name")->fetch_all(MYSQLI_ASSOC);

// Get store-wise breakdown
$store_breakdown = [];
if (!empty($stores)) {
    foreach ($stores as $store) {
        $store_id = $store['id'];
        $store_query = "SELECT 
            COUNT(*) as transaction_count,
            SUM(amount) as total_amount,
            SUM(CASE WHEN settlement_id IS NULL OR settlement_id = 0 THEN amount ELSE 0 END) as unsettled_amount,
            SUM(CASE WHEN settlement_id IS NULL OR settlement_id = 0 THEN 1 ELSE 0 END) as unsettled_count
            FROM atm_transactions 
            WHERE store_id = $store_id 
            AND is_deleted = 0
            AND DATE(transaction_date) BETWEEN '$start_date' AND '$end_date'";
        
        $store_stats = $conn->query($store_query)->fetch_assoc();
        $store_breakdown[] = array_merge($store, $store_stats);
    }
}

// Get cashier-wise breakdown
$cashier_breakdown = [];
$cashier_query = "SELECT 
    u.id, u.full_name, u.username,
    COUNT(at.id) as transaction_count,
    SUM(at.amount) as total_amount,
    SUM(CASE WHEN at.settlement_id IS NULL OR at.settlement_id = 0 THEN at.amount ELSE 0 END) as unsettled_amount
    FROM users u
    INNER JOIN atm_transactions at ON u.id = at.user_id
    WHERE at.is_deleted = 0
    AND DATE(at.transaction_date) BETWEEN '$start_date' AND '$end_date'
    GROUP BY u.id
    ORDER BY total_amount DESC";
$cashier_breakdown = $conn->query($cashier_query)->fetch_all(MYSQLI_ASSOC);

// Get settlement history
$settlement_query = "SELECT 
    s.*,
    u.full_name as settled_by_name,
    st.store_name, st.store_code
    FROM atm_settlements s
    LEFT JOIN users u ON s.user_id = u.id
    LEFT JOIN stores st ON s.store_id = st.id
    WHERE s.is_deleted = 0
    ORDER BY s.settlement_date DESC
    LIMIT 20";
$settlements = $conn->query($settlement_query)->fetch_all(MYSQLI_ASSOC);

// Build query for transactions
$query = "SELECT at.*, 
          u.full_name as cashier_name, u.username, 
          s.store_name, s.store_code,
          at.parent_transaction_id,
          (SELECT COUNT(*) FROM atm_transactions WHERE parent_transaction_id = at.id AND is_deleted = 0) as has_children,
          settle.settlement_date
          FROM atm_transactions at
          LEFT JOIN users u ON at.user_id = u.id
          LEFT JOIN stores s ON at.store_id = s.id
          LEFT JOIN atm_settlements settle ON at.settlement_id = settle.id
          WHERE at.is_deleted = 0";

// Apply date filter
if (!empty($filter_date)) {
    $query .= " AND DATE(at.transaction_date) = '$filter_date'";
}

// Apply store filter
if ($filter_store > 0) {
    $query .= " AND at.store_id = $filter_store";
}

// Apply cashier filter
if ($filter_cashier > 0) {
    $query .= " AND at.user_id = $filter_cashier";
}

// Apply status filter
if ($filter_status !== 'all') {
    if ($filter_status === 'edited') {
        $query .= " AND at.status = 'edited'";
    } elseif ($filter_status === 'completed') {
        $query .= " AND at.status = 'completed'";
    } elseif ($filter_status === 'parent') {
        $query .= " AND at.parent_transaction_id IS NULL";
    } elseif ($filter_status === 'child') {
        $query .= " AND at.parent_transaction_id IS NOT NULL";
    }
}

// Apply settlement filter
if (!$show_settled) {
    $query .= " AND (at.settlement_id IS NULL OR at.settlement_id = 0)";
}

// Apply search filter
if (!empty($search_query)) {
    $search_query_escaped = $conn->real_escape_string($search_query);
    $query .= " AND (
        at.reference_number LIKE '%$search_query_escaped%' OR
        at.customer_name LIKE '%$search_query_escaped%' OR
        at.amount LIKE '%$search_query_escaped%' OR
        u.full_name LIKE '%$search_query_escaped%'
    )";
}

$query .= " ORDER BY at.transaction_date DESC LIMIT 500";

$result = $conn->query($query);
$transactions = $result->fetch_all(MYSQLI_ASSOC);

// Calculate filtered totals
$filtered_total = array_sum(array_column($transactions, 'amount'));
$filtered_count = count($transactions);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ATM Transaction History - Admin Panel</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <style>
        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: flex-end;
            padding: 15px;
            background: #f0f2f5;
            border-radius: 8px;
        }
        
        .filter-bar input,
        .filter-bar select {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .filter-bar input:focus,
        .filter-bar select:focus {
            outline: none;
            border-color: #1877f2;
        }
        
        .filter-bar button {
            padding: 10px 20px;
            background: #1877f2;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.2s;
        }
        
        .filter-bar button:hover {
            background: #166fe5;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 13px;
            color: #050505;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
        }

        .checkbox-filter {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 0;
        }

        .checkbox-filter input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .checkbox-filter label {
            margin: 0;
            cursor: pointer;
            font-weight: normal;
        }
        
        .transactions-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .transactions-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .transactions-table thead {
            background: #f8f9fa;
            border-bottom: 2px solid #dee2e6;
        }
        
        .transactions-table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            font-size: 14px;
        }
        
        .transactions-table td {
            padding: 12px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
        }
        
        .transactions-table tbody tr:hover {
            background: #f8f9fa;
        }

        .transactions-table tbody tr.edited-row {
            background: #fff3cd;
        }

        .transactions-table tbody tr.child-row {
            background: #e7f3ff;
        }
        
        .reference-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: bold;
            font-size: 13px;
            display: inline-block;
        }
        
        .amount-display {
            font-weight: bold;
            color: #28a745;
            font-size: 16px;
        }
        
        .store-badge {
            background: #e7f3ff;
            color: #004085;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-edited {
            background: #fff3cd;
            color: #856404;
        }

        .status-settled {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .inventory-section {
            margin-top: 30px;
        }

        .breakdown-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .breakdown-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .breakdown-card h3 {
            margin: 0 0 15px 0;
            font-size: 16px;
            color: #333;
            border-bottom: 2px solid #1877f2;
            padding-bottom: 10px;
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .breakdown-item:last-child {
            border-bottom: none;
        }

        .breakdown-label {
            font-weight: 500;
            color: #666;
        }

        .breakdown-value {
            font-weight: bold;
            color: #333;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .btn-export {
            padding: 10px 20px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }

        .btn-export:hover {
            background: #218838;
        }

        .settlement-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-top: 20px;
        }

        .tabs {
            display: flex;
            background: white;
            border-radius: 8px 8px 0 0;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .tab {
            flex: 1;
            padding: 15px 20px;
            text-align: center;
            background: #f8f9fa;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #666;
            transition: all 0.3s;
        }

        .tab.active {
            background: #1877f2;
            color: white;
        }

        .tab:hover:not(.active) {
            background: #e9ecef;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        @media print {
            .sidebar, .filter-bar, .export-buttons, .tabs {
                display: none !important;
            }
        }
        
        @media (max-width: 768px) {
            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .transactions-table {
                overflow-x: auto;
            }

            .breakdown-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">💳</div>
                <div class="stat-label">Total Transactions</div>
                <div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">💵</div>
                <div class="stat-label">Total Amount</div>
                <div class="stat-value">₱<?php echo number_format($stats['total_amount'], 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">⚠️</div>
                <div class="stat-label">Unsettled Amount</div>
                <div class="stat-value">₱<?php echo number_format($stats['unsettled_amount'], 2); ?></div>
                <small style="color: #666;"><?php echo number_format($stats['unsettled_count']); ?> transactions</small>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">💰</div>
                <div class="stat-label">Today's Amount</div>
                <div class="stat-value">₱<?php echo number_format($stats['today_amount'], 2); ?></div>
                <small style="color: #666;"><?php echo number_format($stats['today_transactions']); ?> transactions</small>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue">📊</div>
                <div class="stat-label">This Week</div>
                <div class="stat-value">₱<?php echo number_format($stats['week_amount'], 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">📈</div>
                <div class="stat-label">This Month</div>
                <div class="stat-value">₱<?php echo number_format($stats['month_amount'], 2); ?></div>
            </div>
        </div>

        <!-- Bank Accounts -->
        <div class="content-section" style="margin-bottom:20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                <h3 style="font-size:15px;font-weight:700;color:#1e293b;">Bank Accounts</h3>
                <button onclick="document.getElementById('add-bank-form').style.display = document.getElementById('add-bank-form').style.display === 'none' ? 'flex' : 'none'" class="btn btn-primary btn-sm" style="font-size:12px;">+ Add Bank</button>
            </div>
            <div id="add-bank-form" style="display:none;gap:8px;margin-bottom:14px;align-items:flex-end;">
                <div style="flex:1;"><label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:2px;">Bank Name</label><input type="text" id="bank-name" placeholder="e.g. BDO, BPI, UnionBank" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;"></div>
                <button onclick="addBank()" class="btn btn-success btn-sm" style="padding:8px 16px;">Save</button>
            </div>
            <?php if (empty($bank_accounts)): ?>
                <p style="color:#94a3b8;font-size:13px;text-align:center;padding:12px;">No bank accounts yet.</p>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">
                    <?php foreach ($bank_accounts as $ba):
                        $bal = $ba['balance'];
                        $balColor = $bal >= 0 ? '#16a34a' : '#dc2626';
                    ?>
                    <div id="bank-card-<?php echo $ba['id']; ?>" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                        <div style="display:flex;align-items:center;gap:12px;padding:14px 16px;">
                            <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:14px;flex-shrink:0;">&#127974;</div>
                            <div style="flex:1;">
                                <div style="font-size:15px;font-weight:700;color:#1e293b;"><?php echo htmlspecialchars($ba['bank_name']); ?></div>
                                <div style="font-size:11px;color:#64748b;"><?php echo $ba['txn_count']; ?> transactions</div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:10px;color:#64748b;font-weight:700;text-transform:uppercase;">Balance</div>
                                <div style="font-size:20px;font-weight:800;color:<?php echo $balColor; ?>;">&#8369;<?php echo number_format(abs($bal), 2); ?></div>
                            </div>
                        </div>
                        <div style="display:flex;border-top:1px solid #f1f5f9;font-size:11px;text-align:center;">
                            <div style="flex:1;padding:6px 4px;border-right:1px solid #f1f5f9;">
                                <div style="color:#64748b;">Deposits</div>
                                <div style="font-weight:700;color:#16a34a;">+&#8369;<?php echo number_format($ba['total_deposits'], 0); ?></div>
                            </div>
                            <div style="flex:1;padding:6px 4px;border-right:1px solid #f1f5f9;">
                                <div style="color:#64748b;">Withdrawals</div>
                                <div style="font-weight:700;color:#ef4444;">-&#8369;<?php echo number_format($ba['total_withdrawals'], 0); ?></div>
                            </div>
                            <div style="flex:1;padding:6px 4px;">
                                <div style="color:#64748b;">Fees</div>
                                <div style="font-weight:700;color:#f59e0b;">&#8369;<?php echo number_format($ba['total_fees'], 0); ?></div>
                            </div>
                        </div>
                        <div style="display:flex;gap:6px;padding:8px 12px;background:#f8fafc;border-top:1px solid #e2e8f0;">
                            <button onclick='openBankTxn(<?php echo json_encode(["id"=>$ba["id"],"name"=>$ba["bank_name"],"balance"=>$bal]); ?>, "deposit")' class="btn btn-success btn-sm" style="flex:1;font-size:11px;padding:6px;">Deposit</button>
                            <button onclick='openBankTxn(<?php echo json_encode(["id"=>$ba["id"],"name"=>$ba["bank_name"],"balance"=>$bal]); ?>, "withdraw")' class="btn btn-warning btn-sm" style="flex:1;font-size:11px;padding:6px;">Withdraw</button>
                            <button onclick='openBankTxn(<?php echo json_encode(["id"=>$ba["id"],"name"=>$ba["bank_name"],"balance"=>$bal,"locked"=>(bool)($ba["initial_balance_set"] ?? 0)]); ?>, "set_balance")' class="btn btn-secondary btn-sm" style="flex:1;font-size:11px;padding:6px;"><?php echo ($ba['initial_balance_set'] ?? 0) ? 'Adjust' : 'Set Balance'; ?></button>
                            <button onclick="deleteBank(<?php echo $ba['id']; ?>)" class="btn btn-danger btn-sm" style="font-size:11px;padding:6px 10px;">&times;</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Bank Transaction Modal -->
        <div class="modal" id="bank-txn-modal">
            <div class="modal-content" style="max-width:400px;">
                <div class="modal-header">
                    <h2 id="btm-title">Bank Transaction</h2>
                    <button class="btn-close-modal" onclick="closeBankTxn()">&times;</button>
                </div>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f0f4ff;border-radius:8px;margin-bottom:14px;">
                    <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;">&#127974;</div>
                    <div>
                        <div style="font-size:13px;font-weight:700;color:#1e293b;" id="btm-bank-name"></div>
                    </div>
                    <div style="margin-left:auto;text-align:right;">
                        <div style="font-size:10px;color:#64748b;">Balance</div>
                        <div style="font-size:16px;font-weight:800;" id="btm-balance"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" id="btm-amount" step="0.01" min="0" placeholder="0.00" style="font-size:18px;font-weight:700;">
                </div>
                <div class="form-group" id="btm-fee-group">
                    <label>Fee (optional)</label>
                    <input type="number" id="btm-fee" step="0.01" min="0" value="0" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Notes (optional)</label>
                    <input type="text" id="btm-notes" placeholder="Reason...">
                </div>
                <div id="btm-pw-group" style="display:none;">
                    <div style="padding:8px 10px;background:#fef3c7;border-radius:6px;margin-bottom:8px;font-size:11px;color:#92400e;font-weight:600;">Initial balance already set. Super admin password required.</div>
                    <div class="form-group">
                        <label>Super Admin Password</label>
                        <input type="password" id="btm-password" placeholder="Enter password">
                    </div>
                </div>
                <div style="padding:8px 10px;background:#e7f3ff;border-radius:6px;margin-bottom:12px;font-size:11px;color:#1e40af;" id="btm-impact"></div>
                <button onclick="submitBankTxn()" class="btn btn-primary" style="width:100%;padding:12px;font-size:14px;" id="btm-submit">Confirm</button>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="switchTab('transactions')">📋 Transactions</button>
            <button class="tab" onclick="switchTab('inventory')">📊 Inventory Report</button>
            <button class="tab" onclick="switchTab('settlements')">💰 Settlement History</button>
        </div>

        <!-- Transactions Tab -->
        <div id="transactions-tab" class="tab-content active">
            <!-- Filters -->
            <div class="content-section">
                <form method="GET" class="filter-bar">
                    <input type="hidden" name="tab" value="transactions">
                    <div class="filter-group">
                        <label>Search</label>
                        <input type="text" 
                               name="search" 
                               value="<?php echo htmlspecialchars($search_query); ?>" 
                               placeholder="Reference #, Name, Amount...">
                    </div>
                    <div class="filter-group">
                        <label>Date</label>
                        <input type="date" 
                               name="date" 
                               value="<?php echo $filter_date; ?>">
                    </div>
                    <div class="filter-group">
                        <label>Store</label>
                        <select name="store">
                            <option value="0">All Stores</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>" <?php echo $filter_store == $store['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['store_code']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Cashier</label>
                        <select name="cashier">
                            <option value="0">All Cashiers</option>
                            <?php foreach ($cashiers as $cashier): ?>
                                <option value="<?php echo $cashier['id']; ?>" <?php echo $filter_cashier == $cashier['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cashier['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="edited" <?php echo $filter_status === 'edited' ? 'selected' : ''; ?>>Edited</option>
                            <option value="parent" <?php echo $filter_status === 'parent' ? 'selected' : ''; ?>>Original Only</option>
                            <option value="child" <?php echo $filter_status === 'child' ? 'selected' : ''; ?>>Re-edited Only</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <div class="checkbox-filter">
                            <input type="checkbox" 
                                   name="show_settled" 
                                   id="show_settled"
                                   value="1" 
                                   <?php echo $show_settled ? 'checked' : ''; ?>>
                            <label for="show_settled">Show Settled</label>
                        </div>
                    </div>
                    <div class="filter-group">
                        <button type="submit">🔍 Filter</button>
                    </div>
                    <?php if (!empty($search_query) || $filter_date !== date('Y-m-d') || $filter_store > 0 || $filter_status !== 'all' || $filter_cashier > 0 || $show_settled): ?>
                        <div class="filter-group">
                            <a href="admin_card_transaction_history.php" class="nav-btn" style="display: inline-block; padding: 10px 20px; text-decoration: none;">
                                Clear
                            </a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Export Buttons -->
            <div class="content-section">
                <div class="export-buttons">
                    <button onclick="window.print()" class="btn-export">🖨️ Print Report</button>
                    <button onclick="exportToCSV()" class="btn-export">📄 Export CSV</button>
                </div>
            </div>

            <!-- Transactions Table -->
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">
                        Transaction History (<?php echo $filtered_count; ?> results)
                        <?php if ($filtered_count > 0): ?>
                            - Total: ₱<?php echo number_format($filtered_total, 2); ?>
                        <?php endif; ?>
                    </h2>
                </div>
                
                <div class="transactions-table">
                    <?php if (empty($transactions)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">💳</div>
                            <h3>No ATM Transactions Found</h3>
                            <p>Try adjusting your filters or create a new transaction</p>
                        </div>
                    <?php else: ?>
                        <table id="transactions-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Reference #</th>
                                    <th>Customer Name</th>
                                    <th>Amount</th>
                                    <th>Date & Time</th>
                                    <th>Cashier</th>
                                    <th>Store</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($transactions as $transaction): ?>
                                    <?php 
                                    $isEdited = $transaction['status'] === 'edited';
                                    $isChild = $transaction['parent_transaction_id'] && $transaction['parent_transaction_id'] > 0;
                                    $isSettled = $transaction['settlement_date'] !== null;
                                    $rowClass = '';
                                    if ($isEdited) $rowClass = 'edited-row';
                                    if ($isChild) $rowClass = 'child-row';
                                    ?>
                                    <tr class="<?php echo $rowClass; ?>">
                                        <td>
                                            <?php echo $isChild ? '↳ ' : ''; ?>#<?php echo $transaction['id']; ?>
                                            <?php if ($transaction['parent_transaction_id']): ?>
                                                <br><small style="color: #666;">Parent: #<?php echo $transaction['parent_transaction_id']; ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="reference-badge">
                                                <?php echo htmlspecialchars($transaction['reference_number']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            echo $transaction['customer_name'] 
                                                ? htmlspecialchars($transaction['customer_name']) 
                                                : '<span style="color: #999; font-style: italic;">Not provided</span>';
                                            ?>
                                        </td>
                                        <td>
                                            <span class="amount-display">
                                                ₱<?php echo number_format($transaction['amount'], 2); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $date = new DateTime($transaction['transaction_date']);
                                            echo $date->format('M j, Y');
                                            ?>
                                            <br>
                                            <small style="color: #666;">
                                                <?php echo $date->format('g:i:s A'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($transaction['cashier_name']); ?></strong>
                                            <br>
                                            <small style="color: #666;">
                                                @<?php echo htmlspecialchars($transaction['username']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($transaction['store_name']): ?>
                                                <span class="store-badge">
                                                    <?php echo htmlspecialchars($transaction['store_code']); ?>
                                                </span>
                                                <br>
                                                <small style="color: #666;">
                                                    <?php echo htmlspecialchars($transaction['store_name']); ?>
                                                </small>
                                            <?php else: ?>
                                                <span style="color: #999; font-style: italic;">No store</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isSettled): ?>
                                                <span class="status-badge status-settled">Settled</span>
                                                <br><small style="color: #666;">
                                                    <?php 
                                                    $settlementDate = new DateTime($transaction['settlement_date']);
                                                    echo $settlementDate->format('M j, Y');
                                                    ?>
                                                </small>
                                            <?php elseif ($isEdited): ?>
                                                <span class="status-badge status-edited">Edited</span>
                                            <?php else: ?>
                                                <span class="status-badge status-completed">Active</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Inventory Report Tab -->
        <div id="inventory-tab" class="tab-content">
            <div class="content-section">
                <form method="GET" class="filter-bar">
                    <input type="hidden" name="tab" value="inventory">
                    <div class="filter-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="filter-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="filter-group">
                        <button type="submit">🔍 Generate Report</button>
                    </div>
                </form>
            </div>

            <div class="content-section">
                <h2 class="section-title">Inventory Report: <?php echo date('M j, Y', strtotime($start_date)); ?> - <?php echo date('M j, Y', strtotime($end_date)); ?></h2>
                
                <div class="breakdown-grid">
                    <!-- Store Breakdown -->
                    <div class="breakdown-card">
                        <h3>📍 Store-wise Breakdown</h3>
                        <?php if (empty($store_breakdown)): ?>
                            <p style="text-align: center; color: #999; padding: 20px;">No data available</p>
                        <?php else: ?>
                            <?php foreach ($store_breakdown as $store): ?>
                                <div class="breakdown-item">
                                    <div class="breakdown-label">
                                        <strong><?php echo htmlspecialchars($store['store_code']); ?></strong>
                                        <br>
                                        <small style="color: #999;"><?php echo $store['transaction_count']; ?> trans</small>
                                    </div>
                                    <div class="breakdown-value">
                                        <div style="color: #28a745;">₱<?php echo number_format($store['total_amount'] ?? 0, 2); ?></div>
                                        <?php if ($store['unsettled_count'] > 0): ?>
                                            <small style="color: #dc3545;">Unsettled: ₱<?php echo number_format($store['unsettled_amount'], 2); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Cashier Breakdown -->
                    <div class="breakdown-card">
                        <h3>👤 Cashier-wise Breakdown</h3>
                        <?php if (empty($cashier_breakdown)): ?>
                            <p style="text-align: center; color: #999; padding: 20px;">No data available</p>
                        <?php else: ?>
                            <?php foreach ($cashier_breakdown as $cashier): ?>
                                <div class="breakdown-item">
                                    <div class="breakdown-label">
                                        <strong><?php echo htmlspecialchars($cashier['full_name']); ?></strong>
                                        <br>
                                        <small style="color: #999;"><?php echo $cashier['transaction_count']; ?> transactions</small>
                                    </div>
                                    <div class="breakdown-value">
                                        <div style="color: #28a745;">₱<?php echo number_format($cashier['total_amount'], 2); ?></div>
                                        <?php if ($cashier['unsettled_amount'] > 0): ?>
                                            <small style="color: #dc3545;">Unsettled: ₱<?php echo number_format($cashier['unsettled_amount'], 2); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settlements Tab -->
        <div id="settlements-tab" class="tab-content">
            <div class="content-section">
                <h2 class="section-title">Recent Settlement History</h2>
                
                <div class="settlement-table">
                    <?php if (empty($settlements)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">💰</div>
                            <h3>No Settlements Found</h3>
                            <p>No settlements have been processed yet</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Settlement ID</th>
                                    <th>Date & Time</th>
                                    <th>Store</th>
                                    <th>Settled By</th>
                                    <th>Transactions</th>
                                    <th>Total Amount</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($settlements as $settlement): ?>
                                    <tr>
                                        <td><strong>#<?php echo $settlement['id']; ?></strong></td>
                                        <td>
                                            <?php 
                                            $settleDate = new DateTime($settlement['settlement_date']);
                                            echo $settleDate->format('M j, Y');
                                            ?>
                                            <br>
                                            <small style="color: #666;">
                                                <?php echo $settleDate->format('g:i:s A'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($settlement['store_name']): ?>
                                                <span class="store-badge">
                                                    <?php echo htmlspecialchars($settlement['store_code']); ?>
                                                </span>
                                                <br>
                                                <small style="color: #666;">
                                                    <?php echo htmlspecialchars($settlement['store_name']); ?>
                                                </small>
                                            <?php else: ?>
                                                <span style="color: #999; font-style: italic;">All stores</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($settlement['settled_by_name']); ?></strong>
                                        </td>
                                        <td>
                                            <strong><?php echo number_format($settlement['transaction_count']); ?></strong> transactions
                                        </td>
                                        <td>
                                            <span class="amount-display">
                                                ₱<?php echo number_format($settlement['total_amount'], 2); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            echo $settlement['notes'] 
                                                ? htmlspecialchars($settlement['notes']) 
                                                : '<span style="color: #999; font-style: italic;">No notes</span>';
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        // Tab switching
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            event.target.classList.add('active');
        }

        // Export to CSV
        function exportToCSV() {
            const table = document.getElementById('transactions-table');
            let csv = [];
            
            // Headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                headers.push(th.textContent.trim());
            });
            csv.push(headers.join(','));
            
            // Rows
            table.querySelectorAll('tbody tr').forEach(tr => {
                const row = [];
                tr.querySelectorAll('td').forEach(td => {
                    // Clean up the text
                    let text = td.textContent.trim().replace(/\n/g, ' ').replace(/\s+/g, ' ');
                    // Escape quotes
                    text = text.replace(/"/g, '""');
                    // Add quotes if contains comma
                    if (text.includes(',')) {
                        text = '"' + text + '"';
                    }
                    row.push(text);
                });
                csv.push(row.join(','));
            });
            
            // Download
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'atm_transactions_' + new Date().toISOString().split('T')[0] + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }

        // Check if there's a tab parameter in URL
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');
        if (tabParam === 'inventory') {
            switchTab('inventory');
        } else if (tabParam === 'settlements') {
            switchTab('settlements');
        }

    // Bank account management
    function addBank() {
        const name = document.getElementById('bank-name').value.trim();
        if (!name) { alert('Enter bank name'); return; }
        const fd = new FormData(); fd.append('bank_action', 'add_bank'); fd.append('bank_name', name);
        fetch('', { method:'POST', body:fd }).then(r=>r.json()).then(d => { if (d.success) location.reload(); else alert(d.error); });
    }
    function deleteBank(id) {
        if (!confirm('Remove this bank?')) return;
        const fd = new FormData(); fd.append('bank_action', 'delete_bank'); fd.append('id', id);
        fetch('', { method:'POST', body:fd }).then(r=>r.json()).then(d => { if (d.success) { const el = document.getElementById('bank-card-'+id); if (el) el.remove(); } });
    }

    let btmBank = null, btmType = '';
    function openBankTxn(bank, type) {
        btmBank = bank; btmType = type;
        const titles = { deposit: 'Deposit to ', withdraw: 'Withdraw from ', set_balance: (bank.locked ? 'Adjust — ' : 'Set Balance — ') };
        document.getElementById('btm-title').textContent = (titles[type] || '') + bank.name;
        document.getElementById('btm-bank-name').textContent = bank.name;
        const balEl = document.getElementById('btm-balance');
        balEl.textContent = '₱' + Math.abs(bank.balance).toFixed(2);
        balEl.style.color = bank.balance >= 0 ? '#16a34a' : '#dc2626';
        document.getElementById('btm-amount').value = type === 'set_balance' ? Math.max(0, bank.balance).toFixed(2) : '';
        document.getElementById('btm-fee').value = '0';
        document.getElementById('btm-notes').value = '';
        document.getElementById('btm-fee-group').style.display = type === 'set_balance' ? 'none' : '';
        document.getElementById('btm-pw-group').style.display = (type === 'set_balance' && bank.locked) ? '' : 'none';
        document.getElementById('btm-password').value = '';
        const labels = { deposit: 'Confirm Deposit', withdraw: 'Confirm Withdrawal', set_balance: bank.locked ? 'Adjust Balance' : 'Set Balance' };
        document.getElementById('btm-submit').textContent = labels[type] || 'Confirm';
        updateBtmImpact();
        document.getElementById('bank-txn-modal').classList.add('active');
        setTimeout(() => document.getElementById('btm-amount').focus(), 100);
    }
    function closeBankTxn() { document.getElementById('bank-txn-modal').classList.remove('active'); btmBank = null; }
    function updateBtmImpact() {
        const amt = parseFloat(document.getElementById('btm-amount').value) || 0;
        const fee = parseFloat(document.getElementById('btm-fee').value) || 0;
        const el = document.getElementById('btm-impact');
        if (btmType === 'set_balance') {
            el.innerHTML = `Bank balance will be set to <strong>₱${amt.toFixed(2)}</strong>. No effect on cash register.`;
        } else if (btmType === 'deposit') {
            el.innerHTML = `Register: <strong>-₱${amt.toFixed(2)}</strong> (cash out) | Bank: <strong>+₱${(amt - fee).toFixed(2)}</strong>${fee > 0 ? ' | Fee: ₱'+fee.toFixed(2) : ''}`;
        } else {
            el.innerHTML = `Bank: <strong>-₱${amt.toFixed(2)}</strong> | Register: <strong>+₱${amt.toFixed(2)}</strong> (cash in)${fee > 0 ? ' | Fee: ₱'+fee.toFixed(2) : ''}`;
        }
    }
    document.getElementById('btm-amount').addEventListener('input', updateBtmImpact);
    document.getElementById('btm-fee').addEventListener('input', updateBtmImpact);

    function submitBankTxn() {
        if (!btmBank) return;
        const amt = parseFloat(document.getElementById('btm-amount').value);
        const fee = parseFloat(document.getElementById('btm-fee').value) || 0;
        const notes = document.getElementById('btm-notes').value.trim();
        if (!amt || amt <= 0) { alert('Enter amount'); return; }
        const label = btmType === 'deposit' ? 'Deposit ₱'+amt.toFixed(2)+' to '+btmBank.name : 'Withdraw ₱'+amt.toFixed(2)+' from '+btmBank.name;
        if (!confirm(label + '?')) return;
        const fd = new FormData();
        fd.append('bank_action', 'bank_txn'); fd.append('bank_id', btmBank.id);
        fd.append('type', btmType); fd.append('amount', amt); fd.append('fee', fee); fd.append('notes', notes);
        if (btmType === 'set_balance' && btmBank.locked) fd.append('password', document.getElementById('btm-password').value);
        fetch('', { method:'POST', body:fd }).then(r=>r.json()).then(d => {
            if (d.success) { alert('Done!'); location.reload(); } else alert('Error: '+d.error);
        });
    }
    </script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('ATM/Card History', array (
  'Features' => 
  array (
    0 => 'View all ATM and card transactions',
    1 => 'Filter by date and status',
    2 => 'Shows amount, fee, and settlement status',
  ),
));
?>
</body>
</html>