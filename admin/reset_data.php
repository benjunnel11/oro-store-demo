<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';

if (!isAdmin()) { header("Location: /oro-store-demo/cashier/cashier.php"); exit; }
$currentUser = getCurrentUser();

// Define data categories and their tables
$categories = [
    'transactions' => [
        'label' => 'Transactions',
        'icon' => '&#128179;',
        'color' => '#16a34a',
        'desc' => 'All sales transactions, items, reprints, and edited history',
        'tables' => ['transaction_items', 'reprints', 'transactions'],
        'count_query' => "SELECT COUNT(*) as c FROM transactions",
    ],
    'credits' => [
        'label' => 'Credits',
        'icon' => '&#128180;',
        'color' => '#2563eb',
        'desc' => 'All credit records, charges, and customer credit history',
        'tables' => ['credits', 'credit_charge_categories'],
        'count_query' => "SELECT COUNT(*) as c FROM credits",
    ],
    'deliveries' => [
        'label' => 'Deliveries',
        'icon' => '&#128666;',
        'color' => '#ea580c',
        'desc' => 'All delivery records, items, batches, charges, and fees',
        'tables' => ['delivery_items', 'delivery_batches', 'deliveries', 'delivery_charge_categories', 'delivery_fee_categories'],
        'count_query' => "SELECT COUNT(*) as c FROM deliveries",
    ],
    'angkat' => [
        'label' => 'Angkat',
        'icon' => '&#128230;',
        'color' => '#7c3aed',
        'desc' => 'All angkat transactions, items, payments, returns, and charges',
        'tables' => ['angkat_items', 'angkat_payments', 'angkat_returns', 'angkat_transactions', 'angkat_charge_categories'],
        'count_query' => "SELECT COUNT(*) as c FROM angkat_transactions",
    ],
    'gcash' => [
        'label' => 'GCash',
        'icon' => '&#128176;',
        'color' => '#0284c7',
        'desc' => 'All GCash transactions and accounts',
        'tables' => ['gcash_transactions', 'gcash_accounts'],
        'count_query' => "SELECT COUNT(*) as c FROM gcash_transactions",
    ],
    'atm' => [
        'label' => 'ATM / Card',
        'icon' => '&#128179;',
        'color' => '#475569',
        'desc' => 'All ATM/card transactions and settlements',
        'tables' => ['atm_settlements', 'atm_transactions'],
        'count_query' => "SELECT COUNT(*) as c FROM atm_transactions",
    ],
    'products' => [
        'label' => 'Products',
        'icon' => '&#128230;',
        'color' => '#dc2626',
        'desc' => 'All products, store prices, categories, brands, and product history',
        'tables' => ['stock_receipt_items', 'stock_receipts', 'stock_transfers', 'product_history', 'store_prices', 'products', 'product_brands', 'product_categories'],
        'count_query' => "SELECT COUNT(*) as c FROM products",
    ],
    'stock_supply' => [
        'label' => 'Stock & Supply',
        'icon' => '&#128666;',
        'color' => '#4338ca',
        'desc' => 'Stock receipts, receipt items, and inter-store transfers',
        'tables' => ['stock_receipt_items', 'stock_receipts', 'stock_transfers'],
        'count_query' => "SELECT COUNT(*) as c FROM stock_receipts",
    ],
    'customers' => [
        'label' => 'Customers',
        'icon' => '&#128101;',
        'color' => '#0891b2',
        'desc' => 'Customer records used in credits',
        'tables' => ['customers'],
        'count_query' => "SELECT COUNT(*) as c FROM customers",
    ],
    'employees' => [
        'label' => 'Employees & Payroll',
        'icon' => '&#128188;',
        'color' => '#9333ea',
        'desc' => 'Employee records, attendance, cash advances, and roles',
        'tables' => ['employee_attendance', 'employee_cash_advances', 'employees', 'employee_roles'],
        'count_query' => "SELECT COUNT(*) as c FROM employees",
    ],
    'stores' => [
        'label' => 'Stores',
        'icon' => '&#127978;',
        'color' => '#0d9488',
        'desc' => 'Store records (will also clear store prices)',
        'tables' => ['store_prices', 'store_products', 'stores'],
        'count_query' => "SELECT COUNT(*) as c FROM stores",
    ],
    'users' => [
        'label' => 'Users & Sessions',
        'icon' => '&#128100;',
        'color' => '#be123c',
        'desc' => 'User accounts and login sessions (you will be logged out)',
        'tables' => ['user_sessions', 'users'],
        'count_query' => "SELECT COUNT(*) as c FROM users",
    ],
    'logs' => [
        'label' => 'Activity Logs',
        'icon' => '&#128203;',
        'color' => '#64748b',
        'desc' => 'System logs, activity logs, and sync logs',
        'tables' => ['system_logs', 'activity_logs', 'sync_log', 'sync_status'],
        'count_query' => "SELECT COUNT(*) as c FROM system_logs",
    ],
    'cash' => [
        'label' => 'Cash Transactions',
        'icon' => '&#128181;',
        'color' => '#15803d',
        'desc' => 'Other cash transactions (withdrawals, deposits)',
        'tables' => ['cash_transactions'],
        'count_query' => "SELECT COUNT(*) as c FROM cash_transactions",
    ],
    'bank' => [
        'label' => 'Bank',
        'icon' => '&#127974;',
        'color' => '#1e40af',
        'desc' => 'Bank accounts and transactions',
        'tables' => ['bank_transactions', 'bank_accounts'],
        'count_query' => "SELECT COUNT(*) as c FROM bank_transactions",
    ],
    'expenses' => [
        'label' => 'Expenses',
        'icon' => '&#128184;',
        'color' => '#dc2626',
        'desc' => 'All expense records',
        'tables' => ['expenses'],
        'count_query' => "SELECT COUNT(*) as c FROM expenses",
    ],
    'kiosk' => [
        'label' => 'Kiosk Orders',
        'icon' => '&#128187;',
        'color' => '#7c3aed',
        'desc' => 'Kiosk queue orders and priority numbers',
        'tables' => ['kiosk_orders'],
        'count_query' => "SELECT COUNT(*) as c FROM kiosk_orders",
    ],
    'sync' => [
        'label' => 'Sync Data',
        'icon' => '&#9729;',
        'color' => '#64748b',
        'desc' => 'Sync logs, status, product history backups, and cloud stock queue',
        'tables' => ['sync_log', 'sync_status', 'product_history_backup'],
        'count_query' => "SELECT COUNT(*) as c FROM sync_log",
    ],
];

// Get counts (suppress errors for tables that may not exist)
foreach ($categories as $key => &$cat) {
    $prev = mysqli_report(MYSQLI_REPORT_OFF);
    $r = @$conn->query($cat['count_query']);
    mysqli_report($prev);
    $cat['count'] = ($r && $row = $r->fetch_assoc()) ? $row['c'] : 0;
}
unset($cat);

// Handle reset POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_category') {
    header('Content-Type: application/json');
    $category = $_POST['category'] ?? '';
    $confirm_text = $_POST['confirm_text'] ?? '';

    if (!isset($categories[$category])) {
        echo json_encode(['success' => false, 'error' => 'Invalid category.']);
        exit;
    }

    $cat = $categories[$category];
    $expected_confirm = 'DELETE ' . strtoupper($cat['label']);

    if ($confirm_text !== $expected_confirm) {
        echo json_encode(['success' => false, 'error' => "Type exactly: $expected_confirm"]);
        exit;
    }

    $conn->begin_transaction();
    try {
        $deleted_counts = [];
        foreach ($cat['tables'] as $table) {
            $check = $conn->query("SHOW TABLES LIKE '$table'");
            if ($check && $check->num_rows > 0) {
                $count = $conn->query("SELECT COUNT(*) as c FROM `$table`")->fetch_assoc()['c'];
                $conn->query("DELETE FROM `$table`");
                $conn->query("ALTER TABLE `$table` AUTO_INCREMENT = 1");
                $deleted_counts[$table] = $count;
            }
        }

        logActivity('system', "DATA RESET: {$cat['label']} - All data deleted", $currentUser['id'], null, [
            'category' => $category,
            'tables_cleared' => $deleted_counts,
            'performed_by' => $currentUser['full_name']
        ]);

        $conn->commit();

        $total_deleted = array_sum($deleted_counts);
        echo json_encode([
            'success' => true,
            'message' => "{$cat['label']} data cleared. $total_deleted total rows deleted from " . count($deleted_counts) . " tables.",
            'details' => $deleted_counts
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle SAFE WIPE — deletes all operational data but preserves system config
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'safe_wipe') {
    header('Content-Type: application/json');
    $confirm_text = $_POST['confirm_text'] ?? '';

    if ($confirm_text !== 'SAFE WIPE') {
        echo json_encode(['success' => false, 'error' => 'Type exactly: SAFE WIPE']);
        exit;
    }

    // Tables to KEEP (not wiped)
    $preserve_tables = [
        'users',              // keep super_admin accounts
        'stores',             // keep store config
        'store_products',     // keep store-product mapping
        'system_settings',    // keep settings
        'gcash_accounts',     // keep GCash account config
        'bank_accounts',      // keep bank account config
        'product_categories', // keep categories
        'product_brands',     // keep brands
        'employee_roles',     // keep role definitions
    ];

    // Tables to wipe (all operational data)
    $wipe_tables = [
        'transaction_items', 'reprints', 'transactions',
        'credits', 'credit_charge_categories',
        'delivery_items', 'delivery_batches', 'deliveries', 'delivery_charge_categories', 'delivery_fee_categories',
        'angkat_items', 'angkat_payments', 'angkat_returns', 'angkat_transactions', 'angkat_charge_categories',
        'gcash_transactions',
        'atm_settlements', 'atm_transactions',
        'cash_transactions', 'bank_transactions',
        'expenses',
        'stock_receipt_items', 'stock_receipts', 'stock_transfers',
        'product_history', 'product_history_backup',
        'kiosk_orders',
        'sync_log', 'sync_status',
        'system_logs',
        'user_sessions',
        'employee_attendance', 'employee_cash_advances',
    ];

    $conn->begin_transaction();
    try {
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        $conn->query("SET @is_syncing = 1");
        $deleted_counts = [];
        foreach ($wipe_tables as $table) {
            $check = @$conn->query("SHOW TABLES LIKE '$table'");
            if ($check && $check->num_rows > 0) {
                $count = $conn->query("SELECT COUNT(*) as c FROM `$table`")->fetch_assoc()['c'];
                $conn->query("TRUNCATE TABLE `$table`");
                $deleted_counts[$table] = $count;
            }
        }

        // Delete non-super_admin users only
        $conn->query("DELETE FROM users WHERE role != 'super_admin'");
        $deleted_counts['users (non-super_admin)'] = $conn->affected_rows;

        // Delete non-admin employees
        $check = @$conn->query("SHOW TABLES LIKE 'employees'");
        if ($check && $check->num_rows > 0) {
            $conn->query("DELETE FROM employees");
            $deleted_counts['employees'] = $conn->affected_rows;
        }

        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->query("SET @is_syncing = 0");
        $conn->commit();

        $total = array_sum($deleted_counts);
        echo json_encode([
            'success' => true,
            'message' => "Safe wipe complete. $total rows deleted from " . count($deleted_counts) . " tables. Super admin accounts, stores, settings, and connection config preserved.",
            'details' => $deleted_counts,
            'preserved' => $preserve_tables
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle reset ALL (DANGEROUS — wipes everything)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_all') {
    header('Content-Type: application/json');
    $confirm_text = $_POST['confirm_text'] ?? '';

    if ($confirm_text !== 'DELETE EVERYTHING') {
        echo json_encode(['success' => false, 'error' => 'Type exactly: DELETE EVERYTHING']);
        exit;
    }

    // Save ALL super_admin users before wiping
    $super_admins = [];
    $r = $conn->query("SELECT * FROM users WHERE role = 'super_admin' AND status = 'active'");
    if ($r) { while ($row = $r->fetch_assoc()) $super_admins[] = $row; }

    $conn->begin_transaction();
    try {
        $all_tables = [];
        $result = $conn->query("SHOW TABLES");
        while ($row = $result->fetch_row()) $all_tables[] = $row[0];

        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        $deleted_counts = [];
        foreach ($all_tables as $table) {
            $count = $conn->query("SELECT COUNT(*) as c FROM `$table`")->fetch_assoc()['c'];
            $conn->query("TRUNCATE TABLE `$table`");
            $deleted_counts[$table] = $count;
        }
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");

        // Re-insert super_admin users so they can still log in
        foreach ($super_admins as $admin) {
            $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role, store_id, status) VALUES (?, ?, ?, 'super_admin', ?, 'active')");
            $stmt->bind_param("sssi", $admin['username'], $admin['password'], $admin['full_name'], $admin['store_id']);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();

        $total = array_sum($deleted_counts);
        echo json_encode([
            'success' => true,
            'message' => "ALL DATA DELETED. $total rows from " . count($deleted_counts) . " tables.",
            'details' => $deleted_counts
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Reset Data - Oro Store</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <style>
        .warning-banner { background:linear-gradient(135deg,#dc2626,#991b1b); color:#fff; padding:20px 24px; border-radius:12px; margin-bottom:24px; }
        .warning-banner h2 { font-size:20px; margin-bottom:6px; }
        .warning-banner p { opacity:.85; font-size:13px; }

        .reset-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; margin-bottom:24px; }

        .reset-card { background:#fff; border-radius:12px; border:2px solid #e2e8f0; padding:20px; transition:all .2s; position:relative; overflow:hidden; }
        .reset-card:hover { border-color:#cbd5e1; box-shadow:0 4px 12px rgba(0,0,0,.06); }
        .reset-card-header { display:flex; align-items:center; gap:12px; margin-bottom:10px; }
        .reset-card-icon { width:42px; height:42px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
        .reset-card-title { font-size:16px; font-weight:700; color:#1e293b; }
        .reset-card-count { font-size:12px; color:#64748b; font-weight:600; }
        .reset-card-desc { font-size:12px; color:#64748b; margin-bottom:12px; line-height:1.5; }
        .reset-card-tables { font-size:11px; color:#94a3b8; margin-bottom:14px; }
        .reset-card-tables code { background:#f1f5f9; padding:1px 5px; border-radius:3px; font-size:10px; }
        .reset-card-btn { width:100%; padding:9px; border:2px solid #fee2e2; background:#fff; color:#dc2626; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; transition:all .15s; }
        .reset-card-btn:hover { background:#dc2626; color:#fff; border-color:#dc2626; }
        .reset-card-btn:disabled { opacity:.4; cursor:default; }
        .reset-card-btn.empty { border-color:#e2e8f0; color:#94a3b8; cursor:default; }

        .reset-all-section { background:#fff; border:3px solid #dc2626; border-radius:12px; padding:24px; text-align:center; }
        .reset-all-section h3 { color:#dc2626; font-size:18px; margin-bottom:8px; }
        .reset-all-section p { color:#64748b; font-size:13px; margin-bottom:16px; }
        .reset-all-btn { padding:12px 32px; background:#dc2626; color:#fff; border:none; border-radius:8px; font-weight:800; font-size:15px; cursor:pointer; transition:all .15s; }
        .reset-all-btn:hover { background:#991b1b; transform:scale(1.02); }

        /* Modal */
        .modal-overlay { display:none; position:fixed; top:0;left:0;right:0;bottom:0; background:rgba(0,0,0,.6); z-index:1000; align-items:center; justify-content:center; }
        .modal-overlay.active { display:flex; }
        .modal-box { background:#fff; border-radius:14px; padding:28px; width:90%; max-width:440px; box-shadow:0 20px 60px rgba(0,0,0,.3); }
        .modal-box h2 { font-size:18px; color:#dc2626; margin-bottom:4px; }
        .modal-box .modal-desc { font-size:13px; color:#64748b; margin-bottom:16px; }
        .modal-box .confirm-label { font-size:12px; font-weight:700; color:#475569; margin-bottom:6px; text-transform:uppercase; letter-spacing:.5px; }
        .modal-box .confirm-hint { font-size:13px; color:#dc2626; font-weight:700; background:#fee2e2; padding:6px 12px; border-radius:6px; margin-bottom:10px; font-family:monospace; letter-spacing:1px; }
        .modal-box input { width:100%; padding:10px 14px; border:2px solid #e2e8f0; border-radius:8px; font-size:14px; outline:none; margin-bottom:16px; }
        .modal-box input:focus { border-color:#dc2626; }
        .modal-btns { display:flex; gap:10px; }
        .modal-btns .btn-danger { flex:1; padding:10px; background:#dc2626; color:#fff; border:none; border-radius:8px; font-weight:700; font-size:14px; cursor:pointer; }
        .modal-btns .btn-danger:hover { background:#991b1b; }
        .modal-btns .btn-danger:disabled { opacity:.4; cursor:default; }
        .modal-btns .btn-cancel { flex:1; padding:10px; background:#f1f5f9; color:#475569; border:none; border-radius:8px; font-weight:600; font-size:14px; cursor:pointer; }
        .modal-btns .btn-cancel:hover { background:#e2e8f0; }

        .result-box { margin-top:12px; padding:12px; border-radius:8px; font-size:13px; font-weight:600; display:none; }
        .result-box.success { background:#dcfce7; color:#166534; display:block; }
        .result-box.error { background:#fee2e2; color:#991b1b; display:block; }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="warning-banner">
            <h2>&#9888; Data Reset</h2>
            <p>Permanently delete data by category. This action cannot be undone. Make sure you have a database backup before proceeding.</p>
        </div>

        <div id="global-result" class="result-box"></div>

        <div class="section-label" style="margin-bottom:12px;">Reset by Category</div>
        <div class="reset-grid">
            <?php foreach ($categories as $key => $cat): ?>
            <div class="reset-card">
                <div class="reset-card-header">
                    <div class="reset-card-icon" style="background:<?php echo $cat['color']; ?>20; color:<?php echo $cat['color']; ?>;"><?php echo $cat['icon']; ?></div>
                    <div>
                        <div class="reset-card-title"><?php echo $cat['label']; ?></div>
                        <div class="reset-card-count"><?php echo number_format($cat['count']); ?> records</div>
                    </div>
                </div>
                <div class="reset-card-desc"><?php echo $cat['desc']; ?></div>
                <div class="reset-card-tables">Tables: <?php echo implode(' ', array_map(fn($t) => "<code>$t</code>", $cat['tables'])); ?></div>
                <?php if ($cat['count'] > 0): ?>
                    <button class="reset-card-btn" onclick="openResetModal('<?php echo $key; ?>', '<?php echo addslashes($cat['label']); ?>', <?php echo $cat['count']; ?>)">
                        Delete All <?php echo $cat['label']; ?> Data
                    </button>
                <?php else: ?>
                    <button class="reset-card-btn empty" disabled>No data</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="reset-all-section" style="border-color:#f59e0b;margin-bottom:16px;">
            <h3 style="color:#f59e0b;">&#128737; Safe Wipe: Clear All Operational Data</h3>
            <p>Deletes all transactions, deliveries, credits, angkat, GCash, expenses, kiosk orders, stock receipts, and logs.<br>
            <strong style="color:#16a34a;">Preserves:</strong> Super admin accounts, stores, product categories, brands, GCash accounts, bank accounts, settings, connection config, DB password, network password.</p>
            <button class="reset-all-btn" style="background:#f59e0b;" onclick="openSafeWipeModal()">Safe Wipe</button>
        </div>

        <div class="reset-all-section">
            <h3>&#9888; Nuclear Option: Delete Everything</h3>
            <p>This will wipe ALL data from ALL tables. Every product, transaction, user, and setting will be permanently erased. Only super admin accounts are preserved.</p>
            <button class="reset-all-btn" onclick="openResetAllModal()">Delete All Data</button>
        </div>
    </main>

    <!-- Confirm Modal -->
    <div class="modal-overlay" id="reset-modal">
        <div class="modal-box">
            <h2 id="modal-title">Confirm Delete</h2>
            <div class="modal-desc" id="modal-desc"></div>
            <div class="confirm-label">Type the following to confirm:</div>
            <div class="confirm-hint" id="modal-hint"></div>
            <input type="text" id="modal-input" placeholder="Type confirmation here..." autocomplete="off">
            <div class="modal-btns">
                <button class="btn-danger" id="modal-confirm-btn" onclick="executeReset()" disabled>Delete</button>
                <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            </div>
            <div id="modal-result" class="result-box"></div>
        </div>
    </div>

<script>
let currentCategory = null;
let currentConfirmText = '';
let isResetAll = false;
let isSafeWipe = false;

function openSafeWipeModal() {
    currentCategory = null;
    isResetAll = false;
    isSafeWipe = true;
    currentConfirmText = 'SAFE WIPE';
    document.getElementById('modal-title').textContent = 'Safe Wipe — Clear Operational Data';
    document.getElementById('modal-desc').innerHTML = 'Deletes all transactions, deliveries, credits, angkat, GCash transactions, expenses, kiosk orders, stock receipts, and logs.<br><br><strong style="color:#16a34a;">Preserved:</strong> Super admin accounts, stores, product categories, brands, GCash accounts, bank accounts, settings, connection config.';
    document.getElementById('modal-hint').textContent = currentConfirmText;
    document.getElementById('modal-input').value = '';
    document.getElementById('modal-confirm-btn').disabled = true;
    document.getElementById('modal-confirm-btn').style.background = '#f59e0b';
    document.getElementById('modal-result').className = 'result-box';
    document.getElementById('modal-result').textContent = '';
    document.getElementById('reset-modal').classList.add('active');
    document.getElementById('modal-input').focus();
}

function openResetModal(category, label, count) {
    currentCategory = category;
    isResetAll = false;
    isSafeWipe = false;
    currentConfirmText = 'DELETE ' + label.toUpperCase();
    document.getElementById('modal-title').textContent = 'Delete ' + label + ' Data';
    document.getElementById('modal-desc').textContent = 'This will permanently delete all ' + count.toLocaleString() + ' ' + label.toLowerCase() + ' records and related data. This cannot be undone.';
    document.getElementById('modal-hint').textContent = currentConfirmText;
    document.getElementById('modal-input').value = '';
    document.getElementById('modal-confirm-btn').disabled = true;
    document.getElementById('modal-result').className = 'result-box';
    document.getElementById('modal-result').textContent = '';
    document.getElementById('reset-modal').classList.add('active');
    document.getElementById('modal-input').focus();
}

function openResetAllModal() {
    currentCategory = null;
    isResetAll = true;
    isSafeWipe = false;
    document.getElementById('modal-confirm-btn').style.background = '#dc2626';
    currentConfirmText = 'DELETE EVERYTHING';
    document.getElementById('modal-title').textContent = 'Delete ALL Data';
    document.getElementById('modal-desc').textContent = 'This will permanently delete EVERY record from EVERY table in the database. All products, transactions, users, stores, logs — everything. You will be logged out.';
    document.getElementById('modal-hint').textContent = currentConfirmText;
    document.getElementById('modal-input').value = '';
    document.getElementById('modal-confirm-btn').disabled = true;
    document.getElementById('modal-result').className = 'result-box';
    document.getElementById('modal-result').textContent = '';
    document.getElementById('reset-modal').classList.add('active');
    document.getElementById('modal-input').focus();
}

document.getElementById('modal-input').addEventListener('input', function() {
    document.getElementById('modal-confirm-btn').disabled = (this.value !== currentConfirmText);
});

document.getElementById('modal-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && this.value === currentConfirmText) executeReset();
    if (e.key === 'Escape') closeModal();
});

function closeModal() {
    document.getElementById('reset-modal').classList.remove('active');
}

function executeReset() {
    const btn = document.getElementById('modal-confirm-btn');
    const result = document.getElementById('modal-result');
    btn.disabled = true;
    btn.textContent = 'Deleting...';

    const fd = new FormData();
    fd.append('action', isSafeWipe ? 'safe_wipe' : (isResetAll ? 'reset_all' : 'reset_category'));
    fd.append('confirm_text', document.getElementById('modal-input').value);
    if (!isResetAll && !isSafeWipe) fd.append('category', currentCategory);

    fetch('/oro-store-demo/admin/reset_data.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            result.className = 'result-box success';
            result.textContent = data.message;

            if (data.details) {
                let detail = Object.entries(data.details).map(([t, c]) => t + ': ' + c).join(', ');
                result.textContent += ' (' + detail + ')';
            }

            const globalResult = document.getElementById('global-result');
            globalResult.className = 'result-box success';
            globalResult.textContent = data.message;

            if (isResetAll) {
                setTimeout(() => { window.location.href = '/oro-store-demo/auth/login.php'; }, 2000);
            } else if (isSafeWipe) {
                setTimeout(() => location.reload(), 2000);
            } else {
                setTimeout(() => location.reload(), 1500);
            }
        } else {
            result.className = 'result-box error';
            result.textContent = data.error;
            btn.disabled = false;
            btn.textContent = 'Delete';
        }
    })
    .catch(e => {
        result.className = 'result-box error';
        result.textContent = 'Error: ' + e;
        btn.disabled = false;
        btn.textContent = 'Delete';
    });
}
</script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Reset Data', array (
  'Features' => 
  array (
    0 => 'Safe Wipe: clear transactions, keep products and settings',
    1 => 'Full Reset: delete everything except super admin accounts',
    2 => 'Preserves store configuration and device settings',
    3 => 'Requires confirmation before executing',
  ),
));
?>
</body>
</html>
