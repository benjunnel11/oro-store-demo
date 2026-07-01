<?php
ob_start();
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';
require_once __DIR__ . '/../sync/sync_helper.php';

$db = new SyncDB();
$currentUser = getCurrentUser();
$store_id = $currentUser['store_id'] ?? ($_SESSION['admin_cashier_store'] ?? null);

// Auto-add charge column if missing
$cols = [];
$cr = $conn->query("SHOW COLUMNS FROM atm_transactions");
while ($c = $cr->fetch_assoc()) $cols[] = $c['Field'];
if (!in_array('service_charge', $cols)) $conn->query("ALTER TABLE atm_transactions ADD COLUMN service_charge DECIMAL(10,2) DEFAULT 12.00");

// POST: Submit ATM transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_atm_transaction') {
    ob_clean(); header('Content-Type: application/json');

    $reference = trim($_POST['reference_number']);
    $customer = trim($_POST['customer_name'] ?? '');
    $amount = floatval($_POST['amount']);
    $charge = floatval($_POST['service_charge'] ?? 12);
    $user_id = $currentUser['id'];

    if (strlen($reference) !== 6 || !ctype_digit($reference)) { echo json_encode(['success'=>false,'error'=>'Reference must be 6 digits']); exit; }
    if ($amount <= 0) { echo json_encode(['success'=>false,'error'=>'Amount must be > 0']); exit; }

    $conn->begin_transaction();
    try {
        // 1. Record ATM transaction
        $bank_acct_id = !empty($_POST['bank_account_id']) ? intval($_POST['bank_account_id']) : null;
        $tid = $db->insert('atm_transactions', [
            'reference_number' => $reference, 'customer_name' => $customer,
            'amount' => $amount, 'service_charge' => $charge,
            'bank_account_id' => $bank_acct_id,
            'user_id' => $user_id, 'store_id' => $store_id
        ]);

        // Register loses the full withdrawal amount (charge goes to bank, not register)
        $tx_num = 'ATM-' . date('ymd') . '-' . str_pad($tid, 5, '0', STR_PAD_LEFT);
        $register_impact = -$amount;
        $stmt = $conn->prepare("INSERT INTO transactions (transaction_number, user_id, store_id, total_amount, total_profit, payment_method, status, transaction_date) VALUES (?, ?, ?, ?, ?, 'atm_withdrawal', 'completed', NOW())");
        $stmt->bind_param("siidd", $tx_num, $user_id, $store_id, $register_impact, $charge);
        $stmt->execute(); $stmt->close();

        logActivity('transaction', "ATM withdrawal: ₱$amount (Ref: $reference, Charge: ₱$charge)", $user_id, $store_id, [
            'atm_id' => $tid, 'amount' => $amount, 'charge' => $charge, 'customer' => $customer
        ]);

        $conn->commit();
        echo json_encode(['success'=>true, 'id'=>$tid, 'reference'=>$reference, 'amount'=>$amount, 'charge'=>$charge]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success'=>false, 'error'=>$e->getMessage()]);
    }
    $conn->close(); exit;
}

// Auto-add bank_account_id column
$cols2 = [];
$cr2 = $conn->query("SHOW COLUMNS FROM atm_transactions");
while ($c2 = $cr2->fetch_assoc()) $cols2[] = $c2['Field'];
if (!in_array('bank_account_id', $cols2)) $conn->query("ALTER TABLE atm_transactions ADD COLUMN bank_account_id INT DEFAULT NULL");

// Fetch bank accounts
$conn->query("CREATE TABLE IF NOT EXISTS bank_accounts (id INT AUTO_INCREMENT PRIMARY KEY, bank_name VARCHAR(100) NOT NULL, is_active TINYINT(1) DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
$bank_accounts = $conn->query("SELECT * FROM bank_accounts WHERE is_active = 1 ORDER BY bank_name")->fetch_all(MYSQLI_ASSOC);

// Today stats
$sc = $store_id ? "AND store_id = $store_id" : "";
$today = $conn->query("SELECT COUNT(*) as c, COALESCE(SUM(amount),0) as a, COALESCE(SUM(service_charge),0) as f FROM atm_transactions WHERE DATE(transaction_date)=CURDATE() AND status='completed' AND is_deleted=0 $sc")->fetch_assoc();

ob_end_clean();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>ATM Withdrawal - Oro Store</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f172a;color:#e2e8f0;height:100vh;display:flex;flex-direction:column;}

        .shortcut-bar{display:flex;gap:6px;padding:5px 12px;background:#1e293b;flex-wrap:wrap;align-items:center;border-bottom:1px solid #334155;}
        .sc-key{font-size:11px;color:#94a3b8;cursor:pointer;padding:3px 8px;border-radius:4px;transition:background .15s;white-space:nowrap;}
        .sc-key:hover{background:rgba(255,255,255,.08);}
        .sc-key kbd{background:#334155;color:#e2e8f0;padding:1px 5px;border-radius:3px;font-family:inherit;font-size:10px;margin-right:3px;border:1px solid #475569;}
        .sc-key.sc-green{color:#86efac;} .sc-key.sc-red{color:#fca5a5;}

        .atm-layout{flex:1;display:flex;flex-direction:column;padding:12px 18px;overflow:hidden;max-width:460px;margin:0 auto;width:100%;}
        .atm-brand{display:flex;align-items:center;gap:8px;margin-bottom:12px;}
        .atm-logo{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;font-size:16px;color:#fff;}
        .atm-brand h1{font-size:17px;font-weight:800;color:#fff;}
        .atm-brand .sub{font-size:10px;color:#64748b;}

        .amount-label{font-size:10px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
        .input-row{display:flex;gap:8px;margin-bottom:8px;}
        .input-row .field{flex:1;}
        .atm-input{width:100%;padding:10px 12px;background:#1e293b;border:2px solid #334155;border-radius:10px;color:#fff;font-size:14px;outline:none;transition:border .2s;}
        .atm-input:focus{border-color:#667eea;}
        .atm-input::placeholder{color:#334155;}
        .atm-input.big{font-size:22px;font-weight:800;padding:12px 12px 12px 32px;}
        .amount-wrap{position:relative;}
        .amount-wrap .peso{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:18px;font-weight:700;color:#475569;}

        .quick-amounts{display:grid;grid-template-columns:repeat(5,1fr);gap:4px;margin-bottom:8px;}
        .quick-btn{padding:7px 4px;background:#1e293b;border:1.5px solid #334155;border-radius:6px;color:#cbd5e1;font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;text-align:center;}
        .quick-btn:hover{background:#334155;border-color:#475569;color:#fff;}

        .calc-bar{display:flex;justify-content:space-between;align-items:center;background:#1e293b;border-radius:10px;padding:8px 14px;margin-bottom:8px;border:1px solid #334155;}
        .calc-bar .cb-left{font-size:11px;color:#94a3b8;}
        .calc-bar .cb-left .cb-charge{color:#fbbf24;font-weight:600;}
        .calc-bar .cb-right{text-align:right;}
        .calc-bar .cb-cash{font-size:11px;color:#ef4444;font-weight:600;}
        .calc-bar .cb-bank{font-size:11px;color:#22c55e;font-weight:600;}

        .stats-inline{display:flex;gap:0;background:#1e293b;border-radius:10px;margin-bottom:8px;border:1px solid #334155;}
        .stat-box{flex:1;padding:6px 8px;text-align:center;border-right:1px solid #334155;}
        .stat-box:last-child{border-right:none;}
        .stat-box .stat-label{font-size:8px;color:#64748b;text-transform:uppercase;font-weight:700;}
        .stat-box .stat-val{font-size:13px;font-weight:800;margin-top:1px;}
        .stat-txn .stat-val{color:#818cf8;} .stat-amt .stat-val{color:#ef4444;} .stat-fee .stat-val{color:#fbbf24;}

        .btn-row{display:flex;gap:6px;}
        .submit-btn{flex:1;padding:14px;border:none;border-radius:10px;font-size:15px;font-weight:800;cursor:pointer;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;transition:all .2s;}
        .submit-btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(102,126,234,.4);}
        .submit-btn:disabled{opacity:.5;cursor:default;transform:none;}
        .aux-btn{padding:14px 14px;background:#1e293b;border:1.5px solid #334155;border-radius:10px;color:#94a3b8;font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap;}
        .aux-btn:hover{background:#334155;color:#fff;}

        .toast{position:fixed;top:20px;right:20px;padding:14px 20px;border-radius:12px;font-size:13px;font-weight:700;z-index:9999;transform:translateX(120%);transition:transform .3s ease;max-width:360px;}
        .toast.show{transform:translateX(0);}
        .toast.success{background:#16a34a;color:#fff;}
        .toast.error{background:#dc2626;color:#fff;}
    </style>
    <link rel="stylesheet" href="/oro-store-demo/core/responsive.css">
    <script src="/oro-store-demo/core/custom_alert.js"></script>
</head>
<body>

<div class="shortcut-bar">
    <a href="/oro-store-demo/cashier/cashier.php" style="background:#2563eb;color:#fff;padding:6px 16px;font-size:13px;font-weight:700;text-decoration:none;border-radius:6px;white-space:nowrap;">← Cashier</a>
    <span class="sc-key sc-green" onclick="submitATM()"><kbd>Enter</kbd> Submit</span>
    <span class="sc-key sc-red" onclick="window.close()"><kbd>Esc</kbd> Close</span>
    <span class="sc-key" onclick="resetForm()"><kbd>Del</kbd> Clear</span>
</div>

<div class="atm-layout">
    <div class="atm-brand">
        <div class="atm-logo">&#128179;</div>
        <div><h1>ATM Withdrawal</h1><div class="sub"><?php echo htmlspecialchars($currentUser['full_name']); ?></div></div>
    </div>

    <!-- Bank Account -->
    <div style="margin-bottom:8px;">
        <div class="amount-label">Bank Account</div>
        <select id="bank-account" class="atm-input" style="font-size:13px;">
            <option value="">-- Select Bank --</option>
            <?php foreach ($bank_accounts as $ba): ?>
            <option value="<?php echo $ba['id']; ?>"><?php echo htmlspecialchars($ba['bank_name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <!-- Reference + Customer -->
    <div class="input-row">
        <div class="field">
            <div class="amount-label">Reference (6 digits)</div>
            <input type="text" id="reference" class="atm-input" maxlength="6" placeholder="000000" inputmode="numeric" autofocus>
        </div>
        <div class="field">
            <div class="amount-label">Customer (optional)</div>
            <input type="text" id="customer" class="atm-input" placeholder="Name">
        </div>
    </div>

    <!-- Amount -->
    <div style="margin-bottom:8px;">
        <div class="amount-label">Withdrawal Amount</div>
        <div class="amount-wrap">
            <span class="peso">&#8369;</span>
            <input type="number" id="amount" class="atm-input big" step="0.01" min="0" placeholder="0.00">
        </div>
    </div>

    <!-- Quick Amounts -->
    <div class="quick-amounts">
        <button class="quick-btn" onclick="setAmt(500)">500</button>
        <button class="quick-btn" onclick="setAmt(1000)">1K</button>
        <button class="quick-btn" onclick="setAmt(2000)">2K</button>
        <button class="quick-btn" onclick="setAmt(3000)">3K</button>
        <button class="quick-btn" onclick="setAmt(5000)">5K</button>
    </div>

    <!-- Service Charge -->
    <div class="input-row">
        <div class="field">
            <div class="amount-label">Service Charge (&#8369;)</div>
            <input type="number" id="charge" class="atm-input" step="0.01" min="0" value="12.00" style="font-weight:700;color:#fbbf24;">
        </div>
    </div>

    <!-- Calculation -->
    <div class="calc-bar">
        <div class="cb-left">
            Customer gets: <span style="color:#fff;font-weight:700;" id="d-gets">&#8369;0.00</span><br>
            Card debited: <span style="color:#fff;font-weight:700;" id="d-debited">&#8369;0.00</span>
        </div>
        <div class="cb-right">
            <div class="cb-cash" id="d-cash">Register: -&#8369;0.00</div>
            <div class="cb-bank" id="d-bank">Bank: +&#8369;0.00</div>
            <div class="cb-charge" style="color:#fbbf24;font-weight:600;font-size:11px;" id="d-revenue">Revenue: +&#8369;12.00</div>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-inline">
        <div class="stat-box stat-txn"><div class="stat-label">Today</div><div class="stat-val"><?php echo $today['c']; ?> txn</div></div>
        <div class="stat-box stat-amt"><div class="stat-label">Withdrawn</div><div class="stat-val">&#8369;<?php echo number_format($today['a'], 0); ?></div></div>
        <div class="stat-box stat-fee"><div class="stat-label">Fees</div><div class="stat-val">&#8369;<?php echo number_format($today['f'], 0); ?></div></div>
    </div>

    <!-- Buttons -->
    <div class="btn-row">
        <button class="submit-btn" id="submit-btn" onclick="submitATM()">Process Withdrawal</button>
        <button class="aux-btn" onclick="window.close()">Close</button>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
// Restore last used bank account
(function() {
    const sel = document.getElementById('bank-account');
    const saved = localStorage.getItem('atm_last_bank');
    if (saved && sel.querySelector(`option[value="${saved}"]`)) sel.value = saved;
    sel.addEventListener('change', function() { localStorage.setItem('atm_last_bank', this.value); });
})();

function calc() {
    const amt = parseFloat(document.getElementById('amount').value) || 0;
    const charge = parseFloat(document.getElementById('charge').value) || 0;
    const bankFee = 18; // ATM provider/bank fee
    const totalDebited = amt + charge + bankFee;
    document.getElementById('d-gets').textContent = '₱' + amt.toFixed(2);
    document.getElementById('d-debited').textContent = '₱' + totalDebited.toFixed(2) + ' (₱' + amt.toFixed(0) + ' + ₱' + charge.toFixed(0) + ' store + ₱' + bankFee + ' bank)';
    document.getElementById('d-cash').textContent = 'Register: -₱' + amt.toFixed(2);
    document.getElementById('d-bank').textContent = 'Bank: +₱' + (amt + charge).toFixed(2);
    document.getElementById('d-revenue').textContent = 'Revenue: +₱' + charge.toFixed(2);
}
document.getElementById('amount').addEventListener('input', calc);
document.getElementById('charge').addEventListener('input', calc);

function setAmt(v) { document.getElementById('amount').value = v; calc(); }

document.getElementById('reference').addEventListener('input', function() { this.value = this.value.replace(/\D/g, '').slice(0, 6); });

function showToast(msg, type) { const t = document.getElementById('toast'); t.textContent = msg; t.className = 'toast ' + type + ' show'; setTimeout(() => t.classList.remove('show'), 4000); }

function resetForm() {
    document.getElementById('reference').value = '';
    document.getElementById('customer').value = '';
    document.getElementById('amount').value = '';
    document.getElementById('charge').value = '12.00';
    calc();
    document.getElementById('reference').focus();
}

function submitATM() {
    const ref = document.getElementById('reference').value;
    const customer = document.getElementById('customer').value.trim();
    const amount = parseFloat(document.getElementById('amount').value);
    const charge = parseFloat(document.getElementById('charge').value);

    if (!/^\d{6}$/.test(ref)) { showToast('Enter 6-digit reference', 'error'); return; }
    if (!amount || amount <= 0) { showToast('Enter withdrawal amount', 'error'); return; }

    if (!confirm(`ATM Withdrawal\n\nRef: ${ref}\nCustomer gets: ₱${amount.toFixed(2)}\nStore charge: ₱${charge.toFixed(2)}\n\nRegister: -₱${amount.toFixed(2)}\nBank: +₱${(amount + charge).toFixed(2)}\nRevenue: +₱${charge.toFixed(2)}`)) return;

    const btn = document.getElementById('submit-btn');
    btn.disabled = true; btn.textContent = 'Processing...';

    const fd = new FormData();
    fd.append('action', 'submit_atm_transaction');
    fd.append('reference_number', ref);
    fd.append('customer_name', customer);
    fd.append('amount', amount);
    fd.append('service_charge', charge);
    fd.append('bank_account_id', document.getElementById('bank-account').value);

    fetch('/oro-store-demo/transactions/card_transaction.php', { method: 'POST', body: fd })
    .then(r => r.json()).then(data => {
        if (data.success) {
            showToast(`Done! Ref: ${data.reference} | ₱${data.amount} | Charge: ₱${data.charge}`, 'success');
            resetForm();
        } else {
            showToast('Error: ' + data.error, 'error');
        }
        btn.disabled = false; btn.textContent = 'Process Withdrawal';
    }).catch(e => {
        showToast('Error: ' + e.message, 'error');
        btn.disabled = false; btn.textContent = 'Process Withdrawal';
    });
}

// Keyboard
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { e.preventDefault(); window.close(); }
    if (e.key === 'Delete') { e.preventDefault(); resetForm(); }
    if (e.key === 'Enter' && document.activeElement.id !== 'reference') { e.preventDefault(); submitATM(); }
});
document.getElementById('reference').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); document.getElementById('amount').focus(); }
});
document.getElementById('amount').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); submitATM(); }
});

calc();
</script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('ATM / Card Transaction', array (
  'How It Works' => 
  array (
    0 => 'Process ATM withdrawal or card payment transactions',
    1 => 'Enter amount and card details',
    2 => 'Fee auto-calculated based on settings',
    3 => 'Transaction saved and receipt printable',
  ),
));
?>
</body>
</html>
