<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/sync_helper.php';

// Only admins can access
if (!isAdmin()) {
    header("Location: /oro-store-demo/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

$conn->query("CREATE TABLE IF NOT EXISTS gcash_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_name VARCHAR(100) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    store_id INT DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    initial_balance_set TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
$cols_ga = [];
$cr_ga = $conn->query("SHOW COLUMNS FROM gcash_accounts");
while ($c_ga = $cr_ga->fetch_assoc()) $cols_ga[] = $c_ga['Field'];
if (!in_array('initial_balance_set', $cols_ga)) $conn->query("ALTER TABLE gcash_accounts ADD COLUMN initial_balance_set TINYINT(1) DEFAULT 0");

// Get filter parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$device_filter = isset($_GET['device_id']) ? $_GET['device_id'] : 'all';
$store_filter = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$account_filter = isset($_GET['account_id']) ? intval($_GET['account_id']) : 0;

// Build query
$query = "SELECT gt.*, u.full_name, u.username, s.store_name, s.store_code,
                 ga.account_name as gcash_acct_name, ga.phone_number as gcash_acct_phone
          FROM gcash_transactions gt
          LEFT JOIN users u ON gt.user_id = u.id
          LEFT JOIN stores s ON gt.store_id = s.id
          LEFT JOIN gcash_accounts ga ON gt.gcash_account_id = ga.id
          WHERE DATE(gt.transaction_date) BETWEEN ? AND ?
          AND gt.is_deleted = 0";

$params = [$start_date, $end_date];
$types = "ss";

if ($status_filter !== 'all') {
    $query .= " AND gt.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($device_filter !== 'all') {
    $query .= " AND gt.device_id = ?";
    $params[] = $device_filter;
    $types .= "s";
}

if ($store_filter > 0) {
    $query .= " AND gt.store_id = ?";
    $params[] = $store_filter;
    $types .= "i";
}

if ($account_filter > 0) {
    $query .= " AND gt.gcash_account_id = ?";
    $params[] = $account_filter;
    $types .= "i";
}

$query .= " ORDER BY gt.transaction_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate totals (only for completed transactions)
$total_amount = 0;
$total_charge = 0;
$total_net = 0;
$transaction_count = 0;
$cash_in_total = 0;
$cash_out_total = 0;

foreach ($transactions as $transaction) {
    if ($transaction['status'] === 'completed') {
        $total_amount += $transaction['amount'];
        $total_charge += $transaction['fee'];
        $total_net += ($transaction['amount'] - $transaction['fee']);
        $transaction_count++;
        
        if ($transaction['transaction_type'] === 'cash_in') {
            $cash_in_total += $transaction['amount'];
        } else {
            $cash_out_total += $transaction['amount'];
        }
    }
}

// AJAX: manage accounts
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acct_action'])) {
    header('Content-Type: application/json');
    if ($_POST['acct_action'] === 'add') {
        $name = trim($_POST['name']); $phone = trim($_POST['phone']); $sid = intval($_POST['store_id'] ?? 0) ?: null;
        if (!$name || !$phone) { echo json_encode(['success'=>false,'error'=>'Name and number required']); exit; }
        $stmt = $conn->prepare("INSERT INTO gcash_accounts (account_name, phone_number, store_id) VALUES (?, ?, ?)");
        $stmt->bind_param("ssi", $name, $phone, $sid); $stmt->execute();
        echo json_encode(['success'=>true,'id'=>$conn->insert_id]); exit;
    }
    if ($_POST['acct_action'] === 'delete') {
        $id = intval($_POST['id']);
        $conn->query("UPDATE gcash_accounts SET is_active = 0 WHERE id = $id");
        echo json_encode(['success'=>true]); exit;
    }
}

// Get stores for filter
$stores = $conn->query("SELECT id, store_name, store_code FROM stores WHERE is_deleted = 0 ORDER BY store_name")->fetch_all(MYSQLI_ASSOC);

// Get devices for filter
$devices = $conn->query("SELECT DISTINCT device_id FROM gcash_transactions WHERE device_id IS NOT NULL ORDER BY device_id")->fetch_all(MYSQLI_ASSOC);

// Get gcash accounts with per-account wallet balances
$gcash_accounts = $conn->query("SELECT ga.*, s.store_name, s.store_code,
    COALESCE((SELECT SUM(g.amount) FROM gcash_transactions g WHERE g.gcash_account_id = ga.id AND g.transaction_type = 'cash_out' AND g.status = 'completed' AND g.is_deleted = 0), 0) as total_received,
    COALESCE((SELECT SUM(g.amount) FROM gcash_transactions g WHERE g.gcash_account_id = ga.id AND g.transaction_type = 'cash_in' AND g.status = 'completed' AND g.is_deleted = 0), 0) as total_sent_cashin,
    COALESCE((SELECT SUM(g.amount) FROM gcash_transactions g WHERE g.gcash_account_id = ga.id AND g.transaction_type IN ('send_gcash','bank_transfer') AND g.status = 'completed' AND g.is_deleted = 0), 0) as total_withdrawn,
    COALESCE((SELECT SUM(g.fee) FROM gcash_transactions g WHERE g.gcash_account_id = ga.id AND g.status = 'completed' AND g.is_deleted = 0), 0) as total_fees,
    COALESCE((SELECT COUNT(*) FROM gcash_transactions g WHERE g.gcash_account_id = ga.id AND g.status = 'completed' AND g.is_deleted = 0), 0) as tx_count
    FROM gcash_accounts ga LEFT JOIN stores s ON ga.store_id = s.id
    WHERE ga.is_active = 1 ORDER BY ga.account_name")->fetch_all(MYSQLI_ASSOC);

foreach ($gcash_accounts as &$ga) {
    $ga['wallet_balance'] = floatval($ga['total_received']) - floatval($ga['total_sent_cashin']) - floatval($ga['total_withdrawn']);
}
unset($ga);

// POST: set GCash balance (initial or super_admin with password)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acct_action']) && $_POST['acct_action'] === 'set_gcash_balance') {
    header('Content-Type: application/json');
    $acct_id = intval($_POST['account_id']);
    $amount = floatval($_POST['amount']);
    $password = $_POST['password'] ?? '';

    $acct = $conn->query("SELECT * FROM gcash_accounts WHERE id = $acct_id")->fetch_assoc();
    if (!$acct) { echo json_encode(['success'=>false,'error'=>'Account not found']); exit; }

    // If initial balance already set, require super_admin password
    if ($acct['initial_balance_set']) {
        if ($currentUser['role'] !== 'super_admin') {
            echo json_encode(['success'=>false,'error'=>'Only the super admin can adjust after initial calibration']); exit;
        }
        if (empty($password)) { echo json_encode(['success'=>false,'error'=>'Password required']); exit; }
        $pw_check = $conn->query("SELECT password FROM users WHERE id = {$currentUser['id']}")->fetch_assoc();
        if (!$pw_check || !password_verify($password, $pw_check['password'])) {
            echo json_encode(['success'=>false,'error'=>'Incorrect password']); exit;
        }
    }

    // Calculate current balance and insert difference
    $cur_bal = floatval($conn->query("SELECT
        COALESCE(SUM(CASE WHEN transaction_type='cash_out' THEN amount ELSE 0 END),0)
        - COALESCE(SUM(CASE WHEN transaction_type='cash_in' THEN amount ELSE 0 END),0)
        - COALESCE(SUM(CASE WHEN transaction_type IN ('send_gcash','bank_transfer') THEN amount ELSE 0 END),0) as bal
        FROM gcash_transactions WHERE gcash_account_id = $acct_id AND status='completed' AND is_deleted=0")->fetch_assoc()['bal']);

    $diff = $amount - $cur_bal;
    if ($diff != 0) {
        require_once __DIR__ . '/../sync/sync_helper.php';
        require_once __DIR__ . '/../core/transaction_helper.php';
        $db_gc = new SyncDB();
        require_once __DIR__ . '/../core/db_config.php';
        $conn_gc = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $sys_ref = generateGCashReferenceNumber($conn_gc);
        // balance_adjustment doesn't affect cash register
        $adj_type = $diff > 0 ? 'cash_out' : 'cash_in';
        $adj_amt = abs($diff);
        $db_gc->insert('gcash_transactions', [
            'transaction_type' => $adj_type, 'amount' => $adj_amt, 'fee' => 0, 'total_amount' => $adj_amt,
            'reference_number' => $sys_ref, 'user_reference' => 'SETBAL', 'gcash_account_id' => $acct_id,
            'notes' => 'Balance set to ₱' . number_format($amount, 2),
            'user_id' => $currentUser['id'], 'user_name' => $currentUser['full_name'],
            'store_id' => $currentUser['store_id'] ?? null, 'status' => 'completed'
        ]);
        $conn_gc->close();
    }

    // Lock initial balance
    $conn->query("UPDATE gcash_accounts SET initial_balance_set = 1 WHERE id = $acct_id");

    require_once __DIR__ . '/../core/system_logger.php';
    logActivity('transaction', "GCash balance set: {$acct['account_name']} → ₱$amount", $currentUser['id'], null, [
        'account_id' => $acct_id, 'amount' => $amount, 'diff' => $diff
    ]);
    echo json_encode(['success'=>true]); exit;
}

// POST: send/withdraw from account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acct_action']) && $_POST['acct_action'] === 'withdraw') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../sync/sync_helper.php';
    require_once __DIR__ . '/../core/transaction_helper.php';
    require_once __DIR__ . '/../core/system_logger.php';
    $db2 = new SyncDB();
    $conn2 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $acct_id = intval($_POST['account_id']);
    $amount = floatval($_POST['amount']);
    $dest = trim($_POST['destination']);
    $notes = trim($_POST['notes'] ?? '');
    $wtype = $_POST['withdraw_type']; // send_gcash or bank_transfer
    if ($amount <= 0 || empty($dest)) { echo json_encode(['success'=>false,'error'=>'Enter amount and destination']); exit; }
    $sys_ref = generateGCashReferenceNumber($conn2);
    $tid = $db2->insert('gcash_transactions', [
        'transaction_type' => $wtype, 'amount' => $amount, 'fee' => 0, 'total_amount' => $amount,
        'reference_number' => $sys_ref, 'user_reference' => '',
        'gcash_account_id' => $acct_id, 'destination' => $dest, 'notes' => $notes,
        'user_id' => $currentUser['id'], 'user_name' => $currentUser['full_name'],
        'store_id' => $currentUser['store_id'] ?? null, 'status' => 'completed'
    ]);
    $label = $wtype === 'send_gcash' ? 'GCash' : 'Bank';
    logActivity('transaction', "GCash withdraw to $label: ₱$amount → $dest", $currentUser['id'], null, [
        'transaction_id'=>$tid, 'account_id'=>$acct_id, 'type'=>$wtype, 'amount'=>$amount, 'destination'=>$dest
    ]);
    $conn2->close();
    echo json_encode(['success'=>true,'id'=>$tid,'ref'=>$sys_ref]); exit;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>GCash Transaction History - Oro Store</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <link rel="stylesheet" href="/oro-store-demo/transactions/gcash_transaction_history_style.css">
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1 class="page-title">💰 GCash Transaction History</h1>
            <p class="page-subtitle">View and analyze all GCash transactions with detailed filtering options</p>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <div class="summary-icon green">💰</div>
                <div class="summary-label">Revenue (Fees Earned)</div>
                <div class="summary-value">₱<?php echo number_format($total_charge, 2); ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-icon purple">🧾</div>
                <div class="summary-label">Transactions</div>
                <div class="summary-value"><?php echo $transaction_count; ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-icon blue">📥</div>
                <div class="summary-label">Cash In Total</div>
                <div class="summary-value">₱<?php echo number_format($cash_in_total, 2); ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-icon orange">📤</div>
                <div class="summary-label">Cash Out Total</div>
                <div class="summary-value">₱<?php echo number_format($cash_out_total, 2); ?></div>
            </div>
            <?php
                $total_wallet = 0;
                foreach ($gcash_accounts as $ga) $total_wallet += $ga['wallet_balance'];
                $twColor = $total_wallet >= 0 ? 'green' : 'red';
            ?>
            <div class="summary-card" style="border-left:3px solid <?php echo $total_wallet >= 0 ? '#16a34a' : '#dc2626'; ?>;">
                <div class="summary-icon <?php echo $twColor; ?>" style="font-weight:800;font-size:14px;color:<?php echo $total_wallet >= 0 ? '#16a34a' : '#dc2626'; ?>;">G₱</div>
                <div class="summary-label">Total Wallet</div>
                <div class="summary-value" style="color:<?php echo $total_wallet >= 0 ? '#16a34a' : '#dc2626'; ?>;font-size:18px;">₱<?php echo number_format(abs($total_wallet), 2); ?><?php echo $total_wallet < 0 ? ' <span style="font-size:11px;">(deficit)</span>' : ''; ?></div>
            </div>
        </div>

        <!-- GCash Accounts & Wallets -->
        <div class="content-section" style="margin-bottom:20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                <h3 style="font-size:15px;font-weight:700;color:#1e293b;">GCash Accounts</h3>
                <button onclick="document.getElementById('add-acct-form').style.display = document.getElementById('add-acct-form').style.display === 'none' ? 'flex' : 'none'" class="btn btn-primary btn-sm" style="font-size:12px;">+ Add Account</button>
            </div>
            <div id="add-acct-form" style="display:none;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:flex-end;">
                <div style="flex:1;min-width:150px;"><label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:2px;">Account Name</label><input type="text" id="acct-name" placeholder="e.g. Store Main" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;"></div>
                <div style="flex:1;min-width:150px;"><label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:2px;">Phone Number</label><input type="text" id="acct-phone" placeholder="09XX XXX XXXX" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;"></div>
                <div style="min-width:140px;"><label style="font-size:11px;font-weight:600;color:#64748b;display:block;margin-bottom:2px;">Store</label>
                    <select id="acct-store" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                        <option value="">All stores</option>
                        <?php foreach ($stores as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['store_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button onclick="addAcct()" class="btn btn-success btn-sm" style="padding:8px 16px;">Save</button>
            </div>

            <?php if (empty($gcash_accounts)): ?>
                <p style="color:#94a3b8;font-size:13px;text-align:center;padding:16px;">No GCash accounts yet. Click "+ Add Account" to create one.</p>
            <?php else: ?>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px;">
                    <?php foreach ($gcash_accounts as $ga):
                        $bal = $ga['wallet_balance'];
                        $balColor = $bal > 0 ? '#16a34a' : ($bal < 0 ? '#dc2626' : '#64748b');
                    ?>
                    <div id="acct-card-<?php echo $ga['id']; ?>" style="background:#fff;border:2px solid <?php echo $account_filter == $ga['id'] ? '#3b82f6' : '#e2e8f0'; ?>;border-radius:12px;overflow:hidden;<?php echo $account_filter == $ga['id'] ? 'box-shadow:0 0 0 3px rgba(59,130,246,.15);' : ''; ?>">
                        <!-- Account header (clickable to filter) -->
                        <div onclick="filterByAccount(<?php echo $ga['id']; ?>)" style="display:flex;align-items:center;gap:12px;padding:14px 16px;cursor:pointer;transition:background .15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                            <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#007bff,#0056d2);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:18px;flex-shrink:0;">G</div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:15px;font-weight:700;color:#1e293b;"><?php echo htmlspecialchars($ga['account_name']); ?></div>
                                <div style="font-size:13px;color:#64748b;font-family:monospace;"><?php echo htmlspecialchars($ga['phone_number']); ?></div>
                                <?php if ($ga['store_name']): ?>
                                    <div style="font-size:10px;color:#6366f1;font-weight:600;"><?php echo htmlspecialchars($ga['store_name']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:10px;color:#64748b;font-weight:700;text-transform:uppercase;">Wallet</div>
                                <div style="font-size:20px;font-weight:800;color:<?php echo $balColor; ?>;">&#8369;<?php echo number_format(abs($bal), 2); ?></div>
                                <?php if ($bal < 0): ?><div style="font-size:9px;color:#dc2626;">deficit</div><?php endif; ?>
                            </div>
                        </div>
                        <!-- Breakdown -->
                        <div style="display:flex;border-top:1px solid #f1f5f9;font-size:11px;text-align:center;">
                            <div style="flex:1;padding:8px 4px;border-right:1px solid #f1f5f9;">
                                <div style="color:#64748b;">Cash In (sent)</div>
                                <div style="font-weight:700;color:#ef4444;">-&#8369;<?php echo number_format($ga['total_sent_cashin'], 0); ?></div>
                            </div>
                            <div style="flex:1;padding:8px 4px;border-right:1px solid #f1f5f9;">
                                <div style="color:#64748b;">Cash Out (recv)</div>
                                <div style="font-weight:700;color:#16a34a;">+&#8369;<?php echo number_format($ga['total_received'], 0); ?></div>
                            </div>
                            <div style="flex:1;padding:8px 4px;border-right:1px solid #f1f5f9;">
                                <div style="color:#64748b;">Withdrawn</div>
                                <div style="font-weight:700;color:#8b5cf6;">-&#8369;<?php echo number_format($ga['total_withdrawn'], 0); ?></div>
                            </div>
                            <div style="flex:1;padding:8px 4px;">
                                <div style="color:#64748b;">Fees</div>
                                <div style="font-weight:700;color:#f59e0b;">&#8369;<?php echo number_format($ga['total_fees'], 0); ?></div>
                            </div>
                        </div>
                        <!-- Actions -->
                        <div style="display:flex;gap:6px;padding:8px 12px;background:#f8fafc;border-top:1px solid #e2e8f0;">
                            <button onclick='openWithdraw(<?php echo json_encode(["id"=>$ga["id"],"name"=>$ga["account_name"],"phone"=>$ga["phone_number"],"balance"=>$bal]); ?>)' class="btn btn-primary btn-sm" style="flex:1;font-size:11px;padding:6px;">Send / Withdraw</button>
                            <button onclick='openSetGcashBal(<?php echo json_encode(["id"=>$ga["id"],"name"=>$ga["account_name"],"balance"=>$bal,"locked"=>(bool)$ga["initial_balance_set"]]); ?>)' class="btn btn-secondary btn-sm" style="flex:1;font-size:11px;padding:6px;"><?php echo $ga['initial_balance_set'] ? 'Adjust' : 'Set Balance'; ?></button>
                            <button onclick="deleteAcct(<?php echo $ga['id']; ?>)" class="btn btn-danger btn-sm" style="font-size:11px;padding:6px 10px;">&times;</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Withdraw Modal -->
        <div class="modal" id="withdraw-modal">
            <div class="modal-content" style="max-width:420px;">
                <div class="modal-header">
                    <h2 id="wd-title">Send / Withdraw</h2>
                    <button class="btn-close-modal" onclick="closeWithdraw()">&times;</button>
                </div>
                <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f0f4ff;border-radius:8px;margin-bottom:14px;">
                    <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#007bff,#0056d2);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:14px;">G</div>
                    <div>
                        <div style="font-size:13px;font-weight:700;color:#1e293b;" id="wd-acct-name"></div>
                        <div style="font-size:11px;color:#64748b;font-family:monospace;" id="wd-acct-phone"></div>
                    </div>
                    <div style="margin-left:auto;text-align:right;">
                        <div style="font-size:10px;color:#64748b;">Balance</div>
                        <div style="font-size:16px;font-weight:800;" id="wd-balance"></div>
                    </div>
                </div>
                <div style="display:flex;gap:0;background:#f1f5f9;border-radius:8px;padding:3px;margin-bottom:12px;">
                    <button class="btn btn-sm" id="wd-type-gcash" onclick="setWdType('send_gcash')" style="flex:1;font-size:12px;padding:8px;border-radius:6px;">To GCash</button>
                    <button class="btn btn-sm" id="wd-type-bank" onclick="setWdType('bank_transfer')" style="flex:1;font-size:12px;padding:8px;border-radius:6px;background:transparent;color:#64748b;">To Bank</button>
                </div>
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" id="wd-amount" step="0.01" min="0" placeholder="0.00" style="font-size:18px;font-weight:700;">
                </div>
                <div class="form-group">
                    <label id="wd-dest-label">GCash Number</label>
                    <input type="text" id="wd-destination" placeholder="09XX XXX XXXX">
                </div>
                <div class="form-group">
                    <label>Notes (optional)</label>
                    <textarea id="wd-notes" rows="2" placeholder="Payment for..., transfer to..."></textarea>
                </div>
                <button onclick="submitWithdraw()" class="btn btn-primary" style="width:100%;padding:12px;font-size:14px;">Confirm Send</button>
            </div>
        </div>

        <!-- Set GCash Balance Modal -->
        <div class="modal" id="set-gcash-bal-modal">
            <div class="modal-content" style="max-width:400px;">
                <div class="modal-header">
                    <h2 id="sgb-title">Set Balance</h2>
                    <button class="btn-close-modal" onclick="document.getElementById('set-gcash-bal-modal').classList.remove('active')">&times;</button>
                </div>
                <div style="font-size:13px;font-weight:700;color:#1e293b;margin-bottom:8px;" id="sgb-name"></div>
                <div class="form-group">
                    <label>Balance Amount</label>
                    <input type="number" id="sgb-amount" step="0.01" min="0" placeholder="0.00" style="font-size:18px;font-weight:700;">
                </div>
                <div id="sgb-pw-group" style="display:none;">
                    <div style="padding:8px 10px;background:#fef3c7;border-radius:6px;margin-bottom:8px;font-size:11px;color:#92400e;font-weight:600;">Initial balance already set. Super admin password required to adjust.</div>
                    <div class="form-group">
                        <label>Super Admin Password</label>
                        <input type="password" id="sgb-password" placeholder="Enter password">
                    </div>
                </div>
                <button onclick="submitSetGcashBal()" class="btn btn-primary" style="width:100%;padding:12px;font-size:14px;">Set Balance</button>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label class="filter-label">Start Date</label>
                    <input type="date" name="start_date" class="filter-input" value="<?php echo $start_date; ?>" required>
                </div>
                <div class="filter-group">
                    <label class="filter-label">End Date</label>
                    <input type="date" name="end_date" class="filter-input" value="<?php echo $end_date; ?>" required>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Status</label>
                    <select name="status" class="filter-input">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="edited" <?php echo $status_filter === 'edited' ? 'selected' : ''; ?>>Edited</option>
                        <option value="voided" <?php echo $status_filter === 'voided' ? 'selected' : ''; ?>>Voided</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Store</label>
                    <select name="store_id" class="filter-input">
                        <option value="0">All Stores</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>" <?php echo $store_filter == $store['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($store['store_code']); ?> - <?php echo htmlspecialchars($store['store_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">GCash Account</label>
                    <select name="account_id" class="filter-input">
                        <option value="0">All Accounts</option>
                        <?php foreach ($gcash_accounts as $ga): ?>
                            <option value="<?php echo $ga['id']; ?>" <?php echo $account_filter == $ga['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ga['account_name']); ?> (<?php echo htmlspecialchars($ga['phone_number']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="filter-btn">🔍 Filter</button>
                <button type="button" class="filter-btn" onclick="window.print()" style="background: #28a745;">🖨️ Print</button>
            </form>
        </div>

        <!-- Transactions Table -->
        <div class="table-section">
            <div class="table-wrapper">
                <?php if (empty($transactions)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📭</div>
                        <p class="empty-text">No transactions found</p>
                        <p style="font-size: 14px;">Try adjusting your date range or filters</p>
                    </div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Date & Time</th>
                                <th>Type</th>
                                <th>User</th>
                                <th>Store</th>
                                <th>GCash Account</th>
                                <th>User Reference</th>
                                <th>System Reference</th>
                                <th>Amount</th>
                                <th>Fee</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Device</th>
                                <th class="no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td>#<?php echo htmlspecialchars($transaction['id']); ?></td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($transaction['transaction_date'])); ?></td>
                                    <td>
                                        <?php if ($transaction['transaction_type'] === 'cash_in'): ?>
                                            <span style="color: #28a745; font-weight: bold;">📥 Cash In</span>
                                        <?php else: ?>
                                            <span style="color: #dc3545; font-weight: bold;">📤 Cash Out</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($transaction['full_name'] ?? $transaction['user_name'] ?? 'N/A'); ?></strong><br>
                                        <span style="font-size: 12px; color: #65676b;">@<?php echo htmlspecialchars($transaction['username'] ?? 'N/A'); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($transaction['store_name']): ?>
                                            <strong><?php echo htmlspecialchars($transaction['store_code']); ?></strong><br>
                                            <span style="font-size: 12px; color: #65676b;"><?php echo htmlspecialchars($transaction['store_name']); ?></span>
                                        <?php else: ?>
                                            <span style="color: #65676b;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($transaction['gcash_acct_name'])): ?>
                                            <strong style="color:#16a34a;"><?php echo htmlspecialchars($transaction['gcash_acct_name']); ?></strong><br>
                                            <span style="font-size:11px;color:#64748b;font-family:monospace;"><?php echo htmlspecialchars($transaction['gcash_acct_phone']); ?></span>
                                        <?php else: ?>
                                            <span style="color:#94a3b8;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <!-- Show user's 6-digit reference -->
                                        <?php if (!empty($transaction['user_reference'])): ?>
                                            <code style="background: #e3f2fd; padding: 6px 12px; border-radius: 4px; font-size: 14px; font-weight: bold; color: #1976d2;">
                                                <?php echo htmlspecialchars($transaction['user_reference']); ?>
                                            </code>
                                        <?php else: ?>
                                            <span style="color: #999;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code style="background: #f8f9fa; padding: 4px 8px; border-radius: 4px; font-size: 11px; color: #666;">
                                            <?php echo htmlspecialchars($transaction['reference_number']); ?>
                                        </code>
                                    </td>
                                    <td class="amount-positive">₱<?php echo number_format($transaction['amount'], 2); ?></td>
                                    <td class="amount-charge">₱<?php echo number_format($transaction['fee'], 2); ?></td>
                                    <td class="amount-positive">
                                        <strong>₱<?php echo number_format($transaction['amount'] + $transaction['fee'], 2); ?></strong>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                            <?php echo ucfirst($transaction['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($transaction['device_id']): ?>
                                            <code style="background: #f8f9fa; padding: 4px 8px; border-radius: 4px; font-size: 12px;">
                                                <?php echo htmlspecialchars($transaction['device_id']); ?>
                                            </code>
                                        <?php else: ?>
                                            <span style="color: #65676b;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="no-print">
                                        <!-- ✅ ADDED: View Details Button -->
                                        <button 
                                            onclick="viewDetails(<?php echo $transaction['id']; ?>)" 
                                            class="btn-view-details"
                                            title="View transaction details">
                                            👁️ View
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- ✅ ADDED: Details Modal -->
    <div id="detailsModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h2>Transaction Details</h2>
                <button onclick="closeModal()" class="btn-close-modal">&times;</button>
            </div>
            <div id="detailsContent" class="modal-body">
                Loading...
            </div>
        </div>
    </div>

    <style>
    .modal-body { padding: 20px; }
    #detailsModal .modal-content { padding: 0; }
    @media print { .no-print { display: none !important; } }
    </style>

    <script>
    function viewDetails(transactionId) {
        const modal = document.getElementById('detailsModal');
        const content = document.getElementById('detailsContent');
        
        modal.style.display = 'flex';
        content.innerHTML = 'Loading...';
        
        fetch(`/oro-store-demo/transactions/gcash.php?action=get_gcash_details&id=${transactionId}`)
            .then(response => response.json())
            .then(data => {
                if (data.transaction) {
                    const t = data.transaction;
                    const typeLabel = t.transaction_type === 'cash_in' ? '📥 Cash In' : '📤 Cash Out';
                    
                    content.innerHTML = `
                        <div class="detail-row">
                            <div class="detail-label">Transaction ID:</div>
                            <div class="detail-value">#${t.id}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Date & Time:</div>
                            <div class="detail-value">${new Date(t.transaction_date).toLocaleString()}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Type:</div>
                            <div class="detail-value">${typeLabel}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">User Reference:</div>
                            <div class="detail-value"><strong style="font-size: 18px; color: #1976d2;">${t.user_reference || 'N/A'}</strong></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">System Reference:</div>
                            <div class="detail-value"><code>${t.reference_number}</code></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Amount:</div>
                            <div class="detail-value">₱${parseFloat(t.amount).toFixed(2)}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Fee:</div>
                            <div class="detail-value">₱${parseFloat(t.fee).toFixed(2)}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Total (Amount + Fee):</div>
                            <div class="detail-value"><strong>₱${parseFloat(t.amount + t.fee).toFixed(2)}</strong></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Status:</div>
                            <div class="detail-value"><span class="status-badge status-${t.status}">${t.status.toUpperCase()}</span></div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">Device:</div>
                            <div class="detail-value">${t.device_id || 'N/A'}</div>
                        </div>
                        <div class="detail-row">
                            <div class="detail-label">User:</div>
                            <div class="detail-value">${t.user_name || 'N/A'}</div>
                        </div>
                    `;
                } else {
                    content.innerHTML = '<p style="color: red;">Transaction not found</p>';
                }
            })
            .catch(error => {
                content.innerHTML = '<p style="color: red;">Error loading details</p>';
                console.error('Error:', error);
            });
    }

    function closeModal() {
        document.getElementById('detailsModal').style.display = 'none';
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('detailsModal');
        if (event.target === modal) {
            closeModal();
        }
    }

    // ESC key to close modal
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });

    // Withdraw / Send
    let wdAcct = null, wdType = 'send_gcash';
    function openWithdraw(acct) {
        wdAcct = acct;
        document.getElementById('wd-title').textContent = 'Send from ' + acct.name;
        document.getElementById('wd-acct-name').textContent = acct.name;
        document.getElementById('wd-acct-phone').textContent = acct.phone;
        const balEl = document.getElementById('wd-balance');
        balEl.textContent = '₱' + Math.abs(acct.balance).toFixed(2);
        balEl.style.color = acct.balance >= 0 ? '#16a34a' : '#dc2626';
        document.getElementById('wd-amount').value = '';
        document.getElementById('wd-destination').value = '';
        document.getElementById('wd-notes').value = '';
        setWdType('send_gcash');
        document.getElementById('withdraw-modal').classList.add('active');
        setTimeout(() => document.getElementById('wd-amount').focus(), 100);
    }
    function closeWithdraw() { document.getElementById('withdraw-modal').classList.remove('active'); wdAcct = null; }
    function setWdType(type) {
        wdType = type;
        document.getElementById('wd-type-gcash').style.background = type === 'send_gcash' ? '' : 'transparent';
        document.getElementById('wd-type-gcash').style.color = type === 'send_gcash' ? '' : '#64748b';
        document.getElementById('wd-type-bank').style.background = type === 'bank_transfer' ? '' : 'transparent';
        document.getElementById('wd-type-bank').style.color = type === 'bank_transfer' ? '' : '#64748b';
        document.getElementById('wd-dest-label').textContent = type === 'send_gcash' ? 'GCash Number' : 'Bank Account / Details';
        document.getElementById('wd-destination').placeholder = type === 'send_gcash' ? '09XX XXX XXXX' : 'Bank name, account number';
    }
    function submitWithdraw() {
        if (!wdAcct) return;
        const amount = parseFloat(document.getElementById('wd-amount').value);
        const dest = document.getElementById('wd-destination').value.trim();
        const notes = document.getElementById('wd-notes').value.trim();
        if (!amount || amount <= 0) { alert('Enter a valid amount'); return; }
        if (!dest) { alert('Enter destination'); return; }
        if (!confirm('Send ₱' + amount.toFixed(2) + ' from ' + wdAcct.name + ' to ' + dest + '?')) return;
        const fd = new FormData();
        fd.append('acct_action', 'withdraw');
        fd.append('account_id', wdAcct.id);
        fd.append('amount', amount);
        fd.append('destination', dest);
        fd.append('notes', notes);
        fd.append('withdraw_type', wdType);
        fetch('', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
            if (d.success) { alert('Sent! Ref: ' + d.ref); location.reload(); }
            else alert('Error: ' + d.error);
        });
    }

    function filterByAccount(id) {
        const params = new URLSearchParams(window.location.search);
        if (params.get('account_id') == id) {
            params.delete('account_id');
        } else {
            params.set('account_id', id);
        }
        window.location.search = params.toString();
    }

    // Set GCash balance
    let sgbAcct = null;
    function openSetGcashBal(acct) {
        sgbAcct = acct;
        document.getElementById('sgb-title').textContent = (acct.locked ? 'Adjust' : 'Set') + ' Balance — ' + acct.name;
        document.getElementById('sgb-name').textContent = acct.name;
        document.getElementById('sgb-amount').value = Math.max(0, acct.balance).toFixed(2);
        document.getElementById('sgb-pw-group').style.display = acct.locked ? '' : 'none';
        document.getElementById('sgb-password').value = '';
        document.getElementById('set-gcash-bal-modal').classList.add('active');
        setTimeout(() => document.getElementById('sgb-amount').focus(), 100);
    }
    function submitSetGcashBal() {
        if (!sgbAcct) return;
        const amount = parseFloat(document.getElementById('sgb-amount').value);
        const password = document.getElementById('sgb-password').value;
        if (isNaN(amount) || amount < 0) { alert('Enter valid amount'); return; }
        if (sgbAcct.locked && !password) { alert('Enter super admin password'); return; }
        const fd = new FormData();
        fd.append('acct_action', 'set_gcash_balance');
        fd.append('account_id', sgbAcct.id);
        fd.append('amount', amount);
        if (password) fd.append('password', password);
        fetch('', { method:'POST', body:fd }).then(r=>r.json()).then(d => {
            if (d.success) { alert('Balance set!'); location.reload(); }
            else alert('Error: ' + d.error);
        });
    }

    function addAcct() {
        const name = document.getElementById('acct-name').value.trim();
        const phone = document.getElementById('acct-phone').value.trim();
        const storeId = document.getElementById('acct-store').value;
        if (!name || !phone) { alert('Enter name and number'); return; }
        const fd = new FormData();
        fd.append('acct_action', 'add'); fd.append('name', name); fd.append('phone', phone);
        if (storeId) fd.append('store_id', storeId);
        fetch('', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
            if (d.success) location.reload();
            else alert('Error: ' + d.error);
        });
    }
    function deleteAcct(id) {
        if (!confirm('Remove this account?')) return;
        const fd = new FormData(); fd.append('acct_action', 'delete'); fd.append('id', id);
        fetch('', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
            if (d.success) { const el = document.getElementById('acct-card-' + id); if (el) el.remove(); }
        });
    }
    </script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('GCash History', array (
  'Features' => 
  array (
    0 => 'All GCash transactions: Cash In, Cash Out, Send, Bank Transfer',
    1 => 'Filter by date range and transaction type',
    2 => 'Shows amount, fee, total, reference number, account name',
    3 => 'Wallet balance calculation (net of all transactions)',
  ),
));
?>
</body>
</html>