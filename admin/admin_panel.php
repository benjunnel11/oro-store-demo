<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';

if (!isAdmin()) {
    header("Location: /oro-store-demo/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

// Wrong device — show blocked page, no sidebar, no data
if (!isOnOwnDevice()) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>Access Restricted - Oro Store</title>
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body { font-family:'Segoe UI',sans-serif; background:#0f172a; color:#e2e8f0; min-height:100vh; display:flex; justify-content:center; align-items:center; }
            .blocked { background:#1e293b; padding:40px; border-radius:16px; max-width:420px; width:90%; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,.5); }
            .blocked-icon { font-size:64px; margin-bottom:16px; }
            .blocked h1 { font-size:22px; color:#fbbf24; margin-bottom:8px; }
            .blocked p { font-size:13px; color:#94a3b8; line-height:1.6; margin-bottom:20px; }
            .blocked .user-info { background:#0f172a; border-radius:8px; padding:12px; margin-bottom:20px; font-size:12px; }
            .blocked .user-info div { display:flex; justify-content:space-between; padding:4px 0; }
            .blocked .label { color:#64748b; }
            .blocked .val { color:#e2e8f0; font-weight:600; }
            .blocked .btn { display:inline-block; padding:12px 28px; background:#dc2626; color:#fff; border-radius:8px; text-decoration:none; font-weight:700; font-size:14px; }
            .blocked .btn:hover { background:#b91c1c; }
        </style>
    </head>
    <body>
        <div class="blocked">
            <div class="blocked-icon">🚫</div>
            <h1>Wrong Device</h1>
            <p>Your account is assigned to a different store device. You cannot access this device's system to prevent data discrepancies.</p>
            <div class="user-info">
                <?php
                $__dev_id = defined('LOCAL_DEVICE_ID') ? LOCAL_DEVICE_ID : 'Unknown';
                $__this_store = '';
                $__sc = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
                if (!$__sc->connect_error) {
                    $__sr = $__sc->query("SELECT store_name FROM stores WHERE device_id = '" . $__sc->real_escape_string($__dev_id) . "' LIMIT 1");
                    if ($__sr && $__row = $__sr->fetch_assoc()) $__this_store = $__row['store_name'];
                    $__sc->close();
                }
                ?>
                <div><span class="label">This store</span><span class="val"><?php echo htmlspecialchars($__this_store ?: 'Unknown'); ?></span></div>
                <div><span class="label">Logged in as</span><span class="val"><?php echo htmlspecialchars($currentUser['full_name']); ?></span></div>
                <div><span class="label">Assigned store</span><span class="val"><?php echo htmlspecialchars($currentUser['store_name']); ?></span></div>
                <div><span class="label">This device</span><span class="val"><?php echo $__dev_id; ?></span></div>
            </div>
            <a href="/oro-store-demo/auth/logout.php" class="btn">Logout</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Get statistics
$stats = [];

// Auto-create expenses table
$conn->query("CREATE TABLE IF NOT EXISTS expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(100) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description TEXT,
    user_id INT,
    user_name VARCHAR(100),
    store_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0
)");

// POST: Add expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    require_once __DIR__ . '/../core/system_logger.php';
    require_once __DIR__ . '/../sync/sync_helper.php';
    $exp_db = new SyncDB();
    $exp_amount = floatval($_POST['amount']);
    $exp_category = trim($_POST['category']);
    $exp_desc = trim($_POST['description'] ?? '');
    $uid = $currentUser['id'];
    $sid = $currentUser['store_id'] ?? null;

    if ($exp_amount > 0 && $exp_category) {
        // Save expense record
        $stmt = $conn->prepare("INSERT INTO expenses (category, amount, description, user_id, user_name, store_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sdsiis", $exp_category, $exp_amount, $exp_desc, $uid, $currentUser['full_name'], $sid);
        $stmt->execute(); $stmt->close();

        // Deduct from cash register
        $tx_num = 'EXP-' . date('ymd') . '-' . str_pad(rand(0,9999), 4, '0', STR_PAD_LEFT);
        $neg = -$exp_amount;
        $stmt = $conn->prepare("INSERT INTO transactions (transaction_number, user_id, store_id, total_amount, payment_method, status, transaction_date) VALUES (?, ?, ?, ?, 'expense', 'completed', NOW())");
        $stmt->bind_param("siid", $tx_num, $uid, $sid, $neg);
        $stmt->execute(); $stmt->close();

        logActivity('transaction', "Expense: ₱$exp_amount — $exp_category" . ($exp_desc ? " ($exp_desc)" : ""), $uid, $sid, [
            'category' => $exp_category, 'amount' => $exp_amount, 'description' => $exp_desc
        ]);
    }
    header("Location: /oro-store-demo/admin/admin_panel.php");
    exit;
}

// POST: Adjust cash on register
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adjust_register') {
    require_once __DIR__ . '/../core/system_logger.php';
    require_once __DIR__ . '/../sync/sync_helper.php';
    $adj_db = new SyncDB();
    $adj_amount = floatval($_POST['amount']);
    $adj_type = $_POST['type']; // set, add, subtract
    $adj_reason = trim($_POST['reason'] ?? '');

    if ($adj_type === 'set') {
        $current = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM transactions WHERE DATE(transaction_date)=CURDATE() AND status='completed' AND payment_method NOT IN ('stock_conversion_revenue_restore')")->fetch_assoc()['t']);
        $gcash_adj = floatval($conn->query("SELECT COALESCE(SUM(CASE WHEN transaction_type='cash_in' THEN total_amount ELSE 0 END),0) - COALESCE(SUM(CASE WHEN transaction_type='cash_out' THEN amount ELSE 0 END),0) as n FROM gcash_transactions WHERE DATE(transaction_date)=CURDATE() AND status='completed' AND is_deleted=0")->fetch_assoc()['n']);
        $current += $gcash_adj;
        $diff = $adj_amount - $current;
    } elseif ($adj_type === 'add') {
        $diff = $adj_amount;
    } else {
        $diff = -$adj_amount;
    }

    if ($diff != 0) {
        $tx_num = 'ADJ-' . date('ymd') . '-' . str_pad(rand(0,9999), 4, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("INSERT INTO transactions (transaction_number, user_id, store_id, total_amount, payment_method, status, transaction_date) VALUES (?, ?, ?, ?, 'cash_adjustment', 'completed', NOW())");
        $uid = $currentUser['id'];
        $sid = $currentUser['store_id'] ?? null;
        $stmt->bind_param("siid", $tx_num, $uid, $sid, $diff);
        $stmt->execute(); $stmt->close();

        logActivity('transaction', "Register adjusted: " . ($diff >= 0 ? '+' : '') . "₱" . number_format($diff, 2) . ($adj_reason ? " — $adj_reason" : ""), $currentUser['id'], $sid, [
            'type' => $adj_type, 'amount' => $adj_amount, 'diff' => $diff, 'reason' => $adj_reason
        ]);
    }
    header("Location: /oro-store-demo/admin/admin_panel.php");
    exit;
}

$stats['cash_on_register'] = $conn->query("
    SELECT SUM(total_amount) as total
    FROM transactions
    WHERE DATE(transaction_date) = CURDATE()
    AND status = 'completed'
    AND payment_method NOT IN ('stock_conversion_revenue_restore')
")->fetch_assoc()['total'] ?? 0;

// GCash impact on cash register: only the base amount moves as physical cash, fee is handled in GCash
$gcash_register = $conn->query("
    SELECT COALESCE(SUM(CASE WHEN transaction_type='cash_in' THEN amount ELSE 0 END), 0)
         - COALESCE(SUM(CASE WHEN transaction_type='cash_out' THEN amount ELSE 0 END), 0) as net
    FROM gcash_transactions
    WHERE DATE(transaction_date) = CURDATE() AND status = 'completed' AND is_deleted = 0
    AND (user_reference IS NULL OR user_reference != 'SETBAL')
")->fetch_assoc()['net'];
$stats['cash_on_register'] += floatval($gcash_register);

$stats['today_revenue'] = $conn->query("
    SELECT SUM(total_amount) as total
    FROM transactions
    WHERE DATE(transaction_date) = CURDATE()
    AND status IN ('completed', 'pending')
    AND payment_method NOT IN ('cash_return_to_register', 'atm_cash_withdrawal', 'atm_withdrawal', 'cash_adjustment', 'expense')
")->fetch_assoc()['total'] ?? 0;

// GCash revenue: only the fees earned
$gcash_fees_today = $conn->query("
    SELECT COALESCE(SUM(fee), 0) as total
    FROM gcash_transactions
    WHERE DATE(transaction_date) = CURDATE() AND status = 'completed' AND is_deleted = 0
")->fetch_assoc()['total'];
$stats['today_revenue'] += floatval($gcash_fees_today);

// ATM charge revenue (from new single-row format)
$atm_fees_today = $conn->query("SELECT COALESCE(SUM(total_profit),0) as t FROM transactions WHERE DATE(transaction_date)=CURDATE() AND payment_method='atm_withdrawal' AND status='completed'")->fetch_assoc()['t'];
$stats['today_revenue'] += floatval($atm_fees_today);

$stats['total_products'] = $conn->query("SELECT COUNT(*) as count FROM products WHERE is_deleted = 0")->fetch_assoc()['count'];
$stats['total_users'] = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$stats['total_transactions'] = $conn->query("SELECT COUNT(*) as count FROM transactions WHERE status IN ('completed', 'pending')")->fetch_assoc()['count'];

$stats['total_revenue'] = $conn->query("
    SELECT SUM(total_amount) as total
    FROM transactions
    WHERE status IN ('completed', 'pending')
    AND payment_method NOT IN ('cash_return_to_register', 'atm_cash_withdrawal', 'atm_withdrawal', 'cash_adjustment', 'expense')
")->fetch_assoc()['total'] ?? 0;

// Add GCash fees + ATM fees to total revenue
$gcash_fees_all = $conn->query("SELECT COALESCE(SUM(fee), 0) as total FROM gcash_transactions WHERE status = 'completed' AND is_deleted = 0")->fetch_assoc()['total'];
$stats['total_revenue'] += floatval($gcash_fees_all);
$atm_fees_all = $conn->query("SELECT COALESCE(SUM(total_profit),0) as t FROM transactions WHERE payment_method='atm_withdrawal' AND status='completed'")->fetch_assoc()['t'];
$stats['total_revenue'] += floatval($atm_fees_all);

$stats['gcash_transactions'] = $conn->query("SELECT COUNT(*) as count FROM gcash_transactions WHERE status = 'completed'")->fetch_assoc()['count'];
$stats['total_stores'] = $conn->query("SELECT COUNT(*) as count FROM stores WHERE status = 'active'")->fetch_assoc()['count'];
$stats['atm_transactions'] = $conn->query("SELECT COUNT(*) as count FROM atm_transactions WHERE is_deleted = 0")->fetch_assoc()['count'];
$stats['atm_total_amount'] = $conn->query("SELECT SUM(amount) as total FROM atm_transactions WHERE is_deleted = 0")->fetch_assoc()['total'] ?? 0;

// Inventory & Supply stats — filter by store for non-super admins
$_dash_store_filter = '';
if ($currentUser['role'] !== 'super_admin' && !empty($currentUser['store_id'])) {
    $_dash_store_filter = "AND sp.store_id = " . intval($currentUser['store_id']);
}
if ($_dash_store_filter) {
    $inv = $conn->query("SELECT
        COALESCE(SUM(sp.stock * sp.purchase_price), 0) as inventory_cost,
        COALESCE(SUM(sp.stock * sp.price), 0) as inventory_retail
        FROM store_prices sp JOIN products p ON sp.product_id = p.id AND p.is_deleted = 0 AND p.parent_product_id IS NULL
        WHERE sp.is_deleted = 0 $_dash_store_filter")->fetch_assoc();
} else {
    $inv = $conn->query("SELECT
        SUM(COALESCE((SELECT SUM(sp.stock * sp.purchase_price) FROM store_prices sp WHERE sp.product_id = p.id AND sp.is_deleted = 0), p.stock * p.purchase_price)) as inventory_cost,
        SUM(COALESCE((SELECT SUM(sp.stock * sp.price) FROM store_prices sp WHERE sp.product_id = p.id AND sp.is_deleted = 0), p.stock * p.price)) as inventory_retail
        FROM products p WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL")->fetch_assoc();
}
$stats['inventory_cost'] = $inv['inventory_cost'] ?? 0;
$stats['inventory_retail'] = $inv['inventory_retail'] ?? 0;

$supply_today = $conn->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total_cost),0) as cost, COALESCE(SUM(total_items),0) as items FROM stock_receipts WHERE DATE(created_at) = CURDATE() AND is_deleted = 0")->fetch_assoc();
$stats['supply_today_count'] = $supply_today['cnt'];
$stats['supply_today_cost'] = $supply_today['cost'];
$stats['supply_today_items'] = $supply_today['items'];

$supply_month = $conn->query("SELECT COALESCE(SUM(total_cost),0) as cost, COALESCE(SUM(total_items),0) as items, COUNT(*) as cnt FROM stock_receipts WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE()) AND is_deleted = 0")->fetch_assoc();
$stats['supply_month_cost'] = $supply_month['cost'];
$stats['supply_month_items'] = $supply_month['items'];
$stats['supply_month_count'] = $supply_month['cnt'];

$recent_receipts = $conn->query("SELECT sr.*, (SELECT COUNT(*) FROM stock_receipt_items WHERE receipt_id = sr.id) as item_count FROM stock_receipts sr WHERE sr.is_deleted = 0 ORDER BY sr.created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// Expense stats
$stats['today_expenses'] = floatval($conn->query("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE DATE(created_at)=CURDATE() AND is_deleted=0")->fetch_assoc()['t']);
$stats['month_expenses'] = floatval($conn->query("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE()) AND is_deleted=0")->fetch_assoc()['t']);
$recent_expenses = $conn->query("SELECT * FROM expenses WHERE is_deleted=0 ORDER BY created_at DESC LIMIT 5")->fetch_all(MYSQLI_ASSOC);

// Alerts
$low_stock_count = $conn->query("SELECT COUNT(*) as count FROM products p WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL AND COALESCE((SELECT SUM(sp.stock) FROM store_prices sp WHERE sp.product_id = p.id AND sp.is_deleted = 0), p.stock) BETWEEN 1 AND 5")->fetch_assoc()['count'];
$out_of_stock_count = $conn->query("SELECT COUNT(*) as count FROM products p WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL AND COALESCE((SELECT SUM(sp.stock) FROM store_prices sp WHERE sp.product_id = p.id AND sp.is_deleted = 0), p.stock) = 0")->fetch_assoc()['count'];
$pending_credits = $conn->query("SELECT COUNT(*) as count FROM credits WHERE status IN ('unpaid','partial') AND is_deleted = 0")->fetch_assoc()['count'];
$pending_credits_amount = $conn->query("SELECT SUM(amount_due) as total FROM credits WHERE status IN ('unpaid','partial') AND is_deleted = 0")->fetch_assoc()['total'] ?? 0;
$pending_deliveries = $conn->query("SELECT COUNT(*) as count FROM deliveries WHERE status = 'pending' AND is_deleted = 0")->fetch_assoc()['count'];

// Recent activity log (last 10 only for dashboard)
$recent_logs = $conn->query("
    SELECT sl.*, u.username, u.full_name, s.store_name, s.store_code
    FROM system_logs sl
    LEFT JOIN users u ON sl.user_id = u.id
    LEFT JOIN stores s ON sl.store_id = s.id
    WHERE DATE(sl.created_at) = CURDATE()
    ORDER BY sl.created_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

$today_activity_count = $conn->query("SELECT COUNT(*) as count FROM system_logs WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['count'];

$conn->close();

$hour = date('H');
if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 17) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Panel - Oro Store</title>
    <?php include_once __DIR__ . '/../core/pwa.php'; ?>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <style>

        /* ── Welcome banner ── */
        .welcome-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            padding: 28px 32px;
            border-radius: 16px;
            margin-bottom: 24px;
        }
        .welcome-banner h1 { font-size: 24px; font-weight: 700; margin-bottom: 4px; }
        .welcome-banner p { opacity: 0.85; font-size: 14px; }

        /* ── Alert cards ── */
        .alerts-row {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .alert-card {
            flex: 1;
            min-width: 200px;
            padding: 14px 18px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .alert-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }

        .alert-card.danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .alert-card.warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
        .alert-card.info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .alert-card .alert-icon { font-size: 24px; flex-shrink: 0; }
        .alert-card .alert-text span { display: block; font-size: 12px; font-weight: 400; margin-top: 2px; }

        .no-alerts {
            padding: 14px 18px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 10px;
            color: #166534;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 24px;
        }

        /* ── Section header ── */
        .section-label {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 12px;
        }

        /* ── Stat cards ── */
        .stats-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card-big {
            background: #fff;
            padding: 24px;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            position: relative;
            overflow: hidden;
        }

        .stat-card-big::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 4px;
            height: 100%;
        }

        .stat-card-big.cash::before { background: #22c55e; }
        .stat-card-big.revenue::before { background: #3b82f6; }

        .stat-card-big .stat-label { color: #64748b; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        .stat-card-big .stat-value { font-size: 30px; font-weight: 800; color: #0f172a; }
        .stat-card-big .stat-note { font-size: 11px; color: #94a3b8; margin-top: 6px; }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 14px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #fff;
            padding: 18px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            transition: transform 0.15s;
        }
        .stat-card:hover { transform: translateY(-2px); }

        .stat-card .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 12px;
        }
        .stat-icon.blue { background: #dbeafe; }
        .stat-icon.green { background: #dcfce7; }
        .stat-icon.purple { background: #f3e8ff; }
        .stat-icon.orange { background: #ffedd5; }
        .stat-icon.red { background: #fee2e2; }

        .stat-card .stat-label { color: #64748b; font-size: 12px; font-weight: 600; margin-bottom: 4px; }
        .stat-card .stat-value { font-size: 22px; font-weight: 800; color: #0f172a; }
        .stat-card .stat-sub { font-size: 11px; color: #94a3b8; margin-top: 4px; }

        /* ── Activity log ── */
        .activity-section {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            overflow: hidden;
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 22px;
            border-bottom: 1px solid #e2e8f0;
        }

        .activity-header h3 { font-size: 16px; font-weight: 700; color: #0f172a; }
        .activity-header .activity-count {
            font-size: 12px;
            color: #64748b;
            background: #f1f5f9;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 600;
        }

        .activity-list { padding: 8px 0; }

        .log-item {
            display: flex;
            gap: 12px;
            padding: 12px 22px;
            transition: background 0.15s;
        }
        .log-item:hover { background: #f8fafc; }

        .log-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .log-icon.auth { background: #dbeafe; }
        .log-icon.product { background: #fef3c7; }
        .log-icon.transaction { background: #dcfce7; }
        .log-icon.user { background: #f3e8ff; }
        .log-icon.store { background: #dbeafe; }
        .log-icon.system { background: #fee2e2; }
        .log-icon.reprint { background: #dbeafe; }
        .log-icon.payroll { background: #e8eaf6; }
        .log-icon.delivery { background: #ffedd5; }
        .log-icon.credit { background: #ccfbf1; }

        .log-content { flex: 1; min-width: 0; }

        .log-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2px;
        }

        .log-user { font-weight: 600; color: #0f172a; font-size: 14px; }
        .log-time { font-size: 12px; color: #94a3b8; white-space: nowrap; }
        .log-desc { font-size: 13px; color: #475569; }

        .category-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-right: 6px;
            vertical-align: middle;
        }
        .category-badge.auth { background: #dbeafe; color: #1e40af; }
        .category-badge.product { background: #fef3c7; color: #92400e; }
        .category-badge.transaction { background: #dcfce7; color: #166534; }
        .category-badge.user { background: #f3e8ff; color: #6b21a8; }
        .category-badge.store { background: #dbeafe; color: #1e40af; }
        .category-badge.system { background: #fee2e2; color: #991b1b; }
        .category-badge.reprint { background: #dbeafe; color: #1e40af; }
        .category-badge.payroll { background: #e8eaf6; color: #5e35b1; }
        .category-badge.delivery { background: #ffedd5; color: #c2410c; }
        .category-badge.credit { background: #ccfbf1; color: #115e59; }

        .activity-footer {
            border-top: 1px solid #e2e8f0;
            padding: 14px 22px;
            text-align: center;
        }

        .view-all-link {
            color: #6366f1;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: color 0.15s;
        }
        .view-all-link:hover { color: #4f46e5; }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }
        .empty-state-icon { font-size: 40px; margin-bottom: 8px; }

        /* ── Responsive ── */
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); }
            .sidebar-toggle { display: block; }
            .sidebar-overlay.open { display: block; }
            .main-content { margin-left: 0; padding: 16px; padding-top: 60px; }
            .stats-row { grid-template-columns: 1fr; }
        }

        @media (max-width: 600px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .alerts-row { flex-direction: column; }
        }

        @media print {
            .sidebar, .sidebar-toggle { display: none; }
            .main-content { margin-left: 0; }
        }
    </style>
</head>
<body>

<?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

<!-- Main Content -->
<main class="main-content">

    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <h1><?php echo $greeting; ?>, <?php echo htmlspecialchars(explode(' ', $currentUser['full_name'])[0]); ?>!</h1>
        <p>Here's your store overview for <?php echo date('l, F j, Y'); ?></p>
    </div>

    <!-- Alerts -->
    <?php if ($out_of_stock_count > 0 || $low_stock_count > 0 || $pending_credits > 0 || $pending_deliveries > 0): ?>
        <div class="alerts-row">
            <?php if ($out_of_stock_count > 0): ?>
                <a href="/oro-store-demo/admin/admin_products.php" class="alert-card danger">
                    <span class="alert-icon">&#9888;</span>
                    <div class="alert-text">
                        <?php echo $out_of_stock_count; ?> Out of Stock
                        <span>Products need restocking</span>
                    </div>
                </a>
            <?php endif; ?>
            <?php if ($low_stock_count > 0): ?>
                <a href="/oro-store-demo/admin/admin_products.php" class="alert-card warning">
                    <span class="alert-icon">&#128230;</span>
                    <div class="alert-text">
                        <?php echo $low_stock_count; ?> Low Stock
                        <span>5 or fewer items remaining</span>
                    </div>
                </a>
            <?php endif; ?>
            <?php if ($pending_credits > 0): ?>
                <a href="/oro-store-demo/credit/credit.php" class="alert-card info">
                    <span class="alert-icon">&#128180;</span>
                    <div class="alert-text">
                        <?php echo $pending_credits; ?> Unpaid Credits
                        <span>&#8369;<?php echo number_format($pending_credits_amount, 2); ?> outstanding</span>
                    </div>
                </a>
            <?php endif; ?>
            <?php if ($pending_deliveries > 0): ?>
                <a href="/oro-store-demo/delivery/delivery.php" class="alert-card warning">
                    <span class="alert-icon">&#128666;</span>
                    <div class="alert-text">
                        <?php echo $pending_deliveries; ?> Pending Deliveries
                        <span>Awaiting completion</span>
                    </div>
                </a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="no-alerts">&#10004; All clear — no alerts right now.</div>
    <?php endif; ?>

    <!-- Today's Summary -->
    <div class="section-label">Today's Summary</div>
    <div class="stats-row">
        <div class="stat-card-big cash" onclick="document.getElementById('register-modal').classList.add('active')" style="cursor:pointer;" title="Click to adjust">
            <div class="stat-label">Cash on Register <span style="font-size:10px;color:#94a3b8;">&#9998; click to adjust</span></div>
            <div class="stat-value">&#8369;<?php echo number_format($stats['cash_on_register'], 2); ?></div>
            <div class="stat-note">Cash + GCash &minus; ATM withdrawals</div>
        </div>
        <div class="stat-card-big revenue">
            <div class="stat-label">Today's Revenue</div>
            <div class="stat-value">&#8369;<?php echo number_format($stats['today_revenue'], 2); ?></div>
            <div class="stat-note">Sales + Credit + Delivery + GCash fees</div>
        </div>
    </div>

    <!-- Overview Stats -->
    <div class="section-label">Overview</div>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue">&#128230;</div>
            <div class="stat-label">Products</div>
            <div class="stat-value"><?php echo number_format($stats['total_products']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">&#128181;</div>
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value">&#8369;<?php echo number_format($stats['total_revenue'], 0); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple">&#128722;</div>
            <div class="stat-label">Transactions</div>
            <div class="stat-value"><?php echo number_format($stats['total_transactions']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">&#128176;</div>
            <div class="stat-label">GCash</div>
            <div class="stat-value"><?php echo number_format($stats['gcash_transactions']); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red">&#128179;</div>
            <div class="stat-label">ATM</div>
            <div class="stat-value"><?php echo number_format($stats['atm_transactions']); ?></div>
            <div class="stat-sub">&#8369;<?php echo number_format($stats['atm_total_amount'], 0); ?> total</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">&#128101;</div>
            <div class="stat-label">Users</div>
            <div class="stat-value"><?php echo $stats['total_users']; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple">&#127978;</div>
            <div class="stat-label">Stores</div>
            <div class="stat-value"><?php echo $stats['total_stores']; ?></div>
        </div>
    </div>

    <!-- Inventory & Supply -->
    <!-- Expenses -->
    <div class="section-label">Expenses</div>
    <div class="stats-row" style="margin-bottom:24px;">
        <div class="stat-card-big" style="cursor:pointer;" onclick="document.getElementById('expense-modal').classList.add('active')">
            <div class="stat-label">Today's Expenses <span style="font-size:10px;color:#94a3b8;">&#9998; click to add</span></div>
            <div class="stat-value" style="color:#dc2626;">&#8369;<?php echo number_format($stats['today_expenses'], 2); ?></div>
            <div class="stat-note">This month: &#8369;<?php echo number_format($stats['month_expenses'], 0); ?></div>
        </div>
        <div class="stat-card-big">
            <div class="stat-label">Net After Expenses</div>
            <?php $net_after = floatval($stats['today_revenue']) - $stats['today_expenses']; ?>
            <div class="stat-value" style="color:<?php echo $net_after >= 0 ? '#16a34a' : '#dc2626'; ?>;">&#8369;<?php echo number_format($net_after, 2); ?></div>
            <div class="stat-note">Revenue minus expenses today</div>
        </div>
    </div>

    <?php if (!empty($recent_expenses)): ?>
    <div class="activity-section" style="margin-bottom:24px;">
        <div class="activity-header">
            <h3>Recent Expenses</h3>
        </div>
        <div class="activity-list">
            <?php foreach ($recent_expenses as $exp): ?>
            <div class="log-item">
                <div class="log-icon" style="background:#fee2e2;">&#128176;</div>
                <div class="log-content">
                    <div class="log-top">
                        <span class="log-user">
                            <span class="category-badge" style="background:#fee2e2;color:#991b1b;"><?php echo strtoupper(htmlspecialchars($exp['category'])); ?></span>
                            <?php echo htmlspecialchars($exp['user_name'] ?? 'Admin'); ?>
                        </span>
                        <span class="log-time"><?php echo date('M j, g:i A', strtotime($exp['created_at'])); ?></span>
                    </div>
                    <div class="log-desc">
                        <strong style="color:#dc2626;">-&#8369;<?php echo number_format($exp['amount'], 2); ?></strong>
                        <?php if ($exp['description']): ?> — <?php echo htmlspecialchars($exp['description']); ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="section-label">Inventory &amp; Supply</div>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon red">&#128176;</div>
            <div class="stat-label">Inventory Cost</div>
            <div class="stat-value" style="font-size:18px;">&#8369;<?php echo number_format($stats['inventory_cost'], 0); ?></div>
            <div class="stat-sub">Current COGS on hand</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green">&#128181;</div>
            <div class="stat-label">Retail Value</div>
            <div class="stat-value" style="font-size:18px;">&#8369;<?php echo number_format($stats['inventory_retail'], 0); ?></div>
            <div class="stat-sub">Margin: &#8369;<?php echo number_format($stats['inventory_retail'] - $stats['inventory_cost'], 0); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon orange">&#128666;</div>
            <div class="stat-label">Today's Supply</div>
            <div class="stat-value" style="font-size:18px;">&#8369;<?php echo number_format($stats['supply_today_cost'], 0); ?></div>
            <div class="stat-sub"><?php echo $stats['supply_today_count']; ?> receipts, <?php echo $stats['supply_today_items']; ?> units</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple">&#128197;</div>
            <div class="stat-label">This Month Supply</div>
            <div class="stat-value" style="font-size:18px;">&#8369;<?php echo number_format($stats['supply_month_cost'], 0); ?></div>
            <div class="stat-sub"><?php echo $stats['supply_month_count']; ?> receipts, <?php echo $stats['supply_month_items']; ?> units</div>
        </div>
    </div>

    <?php if (!empty($recent_receipts)): ?>
    <div class="activity-section" style="margin-bottom:24px;">
        <div class="activity-header">
            <h3>Recent Stock Receipts</h3>
            <a href="/oro-store-demo/stock/add_stock.php" class="view-all-link" target="_blank">+ Add Stock</a>
        </div>
        <div class="activity-list">
            <?php foreach ($recent_receipts as $r): ?>
            <div class="log-item">
                <div class="log-icon product">&#128230;</div>
                <div class="log-content">
                    <div class="log-top">
                        <span class="log-user">
                            <span class="category-badge product">SUPPLY</span>
                            <?php echo htmlspecialchars($r['supplier_name'] ?: 'No supplier'); ?>
                        </span>
                        <span class="log-time"><?php echo date('M j, g:i A', strtotime($r['created_at'])); ?></span>
                    </div>
                    <div class="log-desc">
                        Receipt #<?php echo $r['id']; ?> &mdash;
                        <?php echo $r['item_count']; ?> products, <?php echo $r['total_items']; ?> units &mdash;
                        <strong style="color:#dc2626;">Cost: &#8369;<?php echo number_format($r['total_cost'], 2); ?></strong>
                        <strong style="color:#2563eb;margin-left:8px;">Sell: &#8369;<?php echo number_format($r['total_selling_value'], 2); ?></strong>
                        <?php if ($r['invoice_number']): ?>
                            <span style="color:#6366f1;margin-left:8px;">#<?php echo htmlspecialchars($r['invoice_number']); ?></span>
                        <?php endif; ?>
                        <?php if ($r['notes']): ?>
                            <br><span style="color:#94a3b8;font-size:12px;"><?php echo htmlspecialchars($r['notes']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Activity -->
    <div class="activity-section">
        <div class="activity-header">
            <h3>Recent Activity</h3>
            <span class="activity-count"><?php echo number_format($today_activity_count); ?> today</span>
        </div>

        <div class="activity-list">
            <?php if (empty($recent_logs)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">&#128237;</div>
                    <p>No activity recorded today</p>
                </div>
            <?php else: ?>
                <?php
                $icons = [
                    'auth' => '&#128274;', 'product' => '&#128230;', 'transaction' => '&#128179;',
                    'delivery' => '&#128666;', 'credit' => '&#128180;', 'user' => '&#128101;',
                    'store' => '&#127978;', 'reprint' => '&#128424;', 'payroll' => '&#128188;',
                    'system' => '&#9881;'
                ];
                foreach ($recent_logs as $log): ?>
                    <div class="log-item">
                        <div class="log-icon <?php echo htmlspecialchars($log['activity_category']); ?>">
                            <?php echo $icons[$log['activity_category']] ?? '&#128221;'; ?>
                        </div>
                        <div class="log-content">
                            <div class="log-top">
                                <span class="log-user">
                                    <span class="category-badge <?php echo htmlspecialchars($log['activity_category']); ?>">
                                        <?php echo strtoupper($log['activity_category']); ?>
                                    </span>
                                    <?php echo htmlspecialchars($log['full_name'] ?: 'System'); ?>
                                </span>
                                <span class="log-time"><?php echo date('g:i A', strtotime($log['created_at'])); ?></span>
                            </div>
                            <div class="log-desc"><?php echo htmlspecialchars($log['description']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="activity-footer">
            <a href="/oro-store-demo/admin/activity_log.php" class="view-all-link">View All Activity &#8594;</a>
        </div>
    </div>

</main>

<!-- Expense Modal -->
<div class="modal" id="expense-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:24px;width:90%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h2 style="font-size:16px;color:#1e293b;margin:0;">Add Expense</h2>
            <button onclick="document.getElementById('expense-modal').classList.remove('active')" style="background:none;border:none;font-size:20px;color:#64748b;cursor:pointer;">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_expense">
            <div style="margin-bottom:12px;">
                <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Category</label>
                <select name="category" required style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
                    <option value="Personal">Personal</option>
                    <option value="Food">Food</option>
                    <option value="Utilities">Utilities (Electric, Water)</option>
                    <option value="Transport">Transport</option>
                    <option value="Supplies">Supplies (Non-inventory)</option>
                    <option value="Rent">Rent</option>
                    <option value="Salary">Salary / Wages</option>
                    <option value="Maintenance">Maintenance / Repairs</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Amount</label>
                <input type="number" name="amount" step="0.01" min="0.01" required placeholder="0.00" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:16px;font-weight:700;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Description (optional)</label>
                <input type="text" name="description" placeholder="What was it for?" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
            </div>
            <div style="padding:8px 10px;background:#fee2e2;border-radius:6px;margin-bottom:14px;font-size:11px;color:#991b1b;">
                This will deduct from the cash register. Does NOT affect revenue or profit.
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" style="flex:1;padding:12px;background:#dc2626;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;">Record Expense</button>
                <button type="button" onclick="document.getElementById('expense-modal').classList.remove('active')" style="padding:12px 16px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Register Adjustment Modal -->
<div class="modal" id="register-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;padding:24px;width:90%;max-width:400px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h2 style="font-size:16px;color:#1e293b;margin:0;">Adjust Cash on Register</h2>
            <button onclick="document.getElementById('register-modal').classList.remove('active')" style="background:none;border:none;font-size:20px;color:#64748b;cursor:pointer;">&times;</button>
        </div>
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;margin-bottom:14px;text-align:center;">
            <div style="font-size:11px;color:#166534;font-weight:600;">Current Register</div>
            <div style="font-size:24px;font-weight:800;color:#16a34a;">&#8369;<?php echo number_format($stats['cash_on_register'], 2); ?></div>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="adjust_register">
            <div style="margin-bottom:12px;">
                <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Adjustment Type</label>
                <select name="type" id="adj-type" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;" onchange="adjTypeChanged()">
                    <option value="set">Set to exact amount</option>
                    <option value="add">Add money</option>
                    <option value="subtract">Subtract money</option>
                </select>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;" id="adj-amount-label">Set Register To</label>
                <input type="number" name="amount" step="0.01" min="0" required placeholder="0.00" style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:16px;font-weight:700;" value="<?php echo number_format($stats['cash_on_register'], 2, '.', ''); ?>">
            </div>
            <div style="margin-bottom:14px;">
                <label style="font-size:12px;font-weight:600;color:#475569;display:block;margin-bottom:4px;">Reason</label>
                <input type="text" name="reason" placeholder="e.g. Starting cash, count correction..." style="width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;">
            </div>
            <div style="display:flex;gap:8px;">
                <button type="submit" style="flex:1;padding:12px;background:#6366f1;color:#fff;border:none;border-radius:8px;font-weight:700;font-size:14px;cursor:pointer;">Save Adjustment</button>
                <button type="button" onclick="document.getElementById('register-modal').classList.remove('active')" style="padding:12px 16px;background:#f1f5f9;color:#475569;border:none;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;">Cancel</button>
            </div>
        </form>
    </div>
</div>
<style>.modal.active{display:flex !important;}</style>
<script>
function adjTypeChanged() {
    const type = document.getElementById('adj-type').value;
    const label = document.getElementById('adj-amount-label');
    if (type === 'set') label.textContent = 'Set Register To';
    else if (type === 'add') label.textContent = 'Amount to Add';
    else label.textContent = 'Amount to Subtract';
}
</script>



<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Admin Dashboard', [
    'Overview' => [
        'Today\'s sales, revenue, profit, and transaction count',
        'GCash wallet balance and fee earnings',
        'Out of stock and low stock product alerts',
        'Pending credits, deliveries, and angkat balances',
    ],
    'Sidebar Navigation' => [
        'Products — manage all products, prices, stock, brands, categories',
        'Add Product — create new products with individual selling options',
        'Stores — manage multi-store setup and device assignments',
        'Transactions — full sales history with void/re-edit',
        'GCash / ATM — payment service transaction history',
        'Credit / Delivery / Angkat — track outstanding balances',
        'Users — manage accounts and roles',
        'Payroll — employee attendance and salary management',
        'Daily Summary — end-of-day sales report',
        'Statistics — charts and analytics',
        'Activity Log — all system actions with timestamps',
    ],
    'System' => [
        'Connection signal shows server latency in real-time',
        'Cloud Sync pushes/pulls stock and shared data every 30 seconds',
        'Badge counts (red/orange) cached for 30 seconds between pages',
        'DB Password and Network Password can be changed from sidebar',
    ],
]);
?>
</body>
</html>
