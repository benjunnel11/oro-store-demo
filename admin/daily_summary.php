<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

if (!isAdmin()) { header("Location: /oro-store-demo/cashier/cashier.php"); exit; }
$currentUser = getCurrentUser();

$date = $_GET['date'] ?? date('Y-m-d');
$prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
$stores = $conn->query("SELECT id, store_name, store_code FROM stores WHERE status='active' ORDER BY store_name")->fetch_all(MYSQLI_ASSOC);
$store_id = intval($_GET['store'] ?? ($stores[0]['id'] ?? 0));
$store = null;
foreach ($stores as $s) if ($s['id'] == $store_id) { $store = $s; break; }

$sf = $store_id ? "AND t.store_id = $store_id" : "";
$sfn = $store_id ? "AND store_id = $store_id" : "";

// === TODAY'S DATA ===
$d = [];

// Cash sales
$d['cash_revenue'] = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM transactions t WHERE DATE(transaction_date)='$date' AND payment_method='cash' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$d['cash_profit'] = floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as t FROM transactions t WHERE DATE(transaction_date)='$date' AND payment_method='cash' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$d['cash_cogs'] = $d['cash_revenue'] - $d['cash_profit'];
$d['cash_count'] = intval($conn->query("SELECT COUNT(*) as c FROM transactions t WHERE DATE(transaction_date)='$date' AND payment_method='cash' AND status IN ('completed','pending') $sf")->fetch_assoc()['c']);

// Delivery
$d['del_revenue'] = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM transactions t WHERE DATE(transaction_date)='$date' AND payment_method='delivery' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$d['del_profit'] = floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as t FROM transactions t WHERE DATE(transaction_date)='$date' AND payment_method='delivery' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$d['del_fee'] = floatval($conn->query("SELECT COALESCE(SUM(d.delivery_fee),0) as t FROM deliveries d WHERE DATE(d.created_at)='$date' AND d.is_deleted=0 $sfn")->fetch_assoc()['t']);
$d['del_count'] = intval($conn->query("SELECT COUNT(*) as c FROM transactions t WHERE DATE(transaction_date)='$date' AND payment_method='delivery' AND status IN ('completed','pending') $sf")->fetch_assoc()['c']);

// Credit
$d['cred_revenue'] = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM transactions t WHERE DATE(transaction_date)='$date' AND payment_method='credit' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$d['cred_profit'] = floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as t FROM transactions t WHERE DATE(transaction_date)='$date' AND payment_method='credit' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$d['cred_fee'] = floatval($conn->query("SELECT COALESCE(SUM(c.additional_charge),0) as t FROM credits c WHERE DATE(c.created_at)='$date' AND c.is_deleted=0 $sfn")->fetch_assoc()['t']);

// GCash fees
$d['gcash_fee'] = floatval($conn->query("SELECT COALESCE(SUM(fee),0) as t FROM gcash_transactions WHERE DATE(transaction_date)='$date' AND status='completed' AND is_deleted=0 AND (user_reference IS NULL OR user_reference != 'SETBAL')")->fetch_assoc()['t']);
$d['gcash_cashin'] = floatval($conn->query("SELECT COALESCE(SUM(amount),0) as t FROM gcash_transactions WHERE DATE(transaction_date)='$date' AND transaction_type='cash_in' AND status='completed' AND is_deleted=0 AND (user_reference IS NULL OR user_reference != 'SETBAL')")->fetch_assoc()['t']);
$d['gcash_cashout'] = floatval($conn->query("SELECT COALESCE(SUM(amount),0) as t FROM gcash_transactions WHERE DATE(transaction_date)='$date' AND transaction_type='cash_out' AND status='completed' AND is_deleted=0 AND (user_reference IS NULL OR user_reference != 'SETBAL')")->fetch_assoc()['t']);

// ATM
$d['atm_fee'] = floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as t FROM transactions t WHERE DATE(transaction_date)='$date' AND payment_method='atm_withdrawal' AND status='completed' $sf")->fetch_assoc()['t']);
$d['atm_withdrawn'] = floatval($conn->query("SELECT COALESCE(SUM(amount),0) as t FROM atm_transactions WHERE DATE(transaction_date)='$date' AND status='completed' AND is_deleted=0 $sfn")->fetch_assoc()['t']);

// Supply
$d['supply_cost'] = floatval($conn->query("SELECT COALESCE(SUM(total_cost),0) as t FROM stock_receipts WHERE DATE(created_at)='$date' AND is_deleted=0")->fetch_assoc()['t']);
$d['supply_items'] = intval($conn->query("SELECT COALESCE(SUM(total_items),0) as t FROM stock_receipts WHERE DATE(created_at)='$date' AND is_deleted=0")->fetch_assoc()['t']);
$d['supply_count'] = intval($conn->query("SELECT COUNT(*) as c FROM stock_receipts WHERE DATE(created_at)='$date' AND is_deleted=0")->fetch_assoc()['c']);

// Totals
$d['total_revenue'] = $d['cash_revenue'] + $d['del_revenue'] + $d['cred_revenue'] + $d['gcash_fee'] + $d['atm_fee'];
$d['total_cogs'] = $d['cash_cogs'] + ($d['del_revenue'] - $d['del_profit']) + ($d['cred_revenue'] - $d['cred_profit']);
$d['total_sales_profit'] = $d['cash_profit'] + $d['del_profit'] + $d['cred_profit'];
$d['total_fees'] = $d['del_fee'] + $d['cred_fee'] + $d['gcash_fee'] + $d['atm_fee'];
$d['net_profit'] = $d['total_sales_profit'] + $d['total_fees'];
$d['total_txn'] = $d['cash_count'] + $d['del_count'];

// Expenses
$d['expenses'] = floatval($conn->query("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE DATE(created_at)='$date' AND is_deleted=0")->fetch_assoc()['t']);
$d['expense_list'] = $conn->query("SELECT * FROM expenses WHERE DATE(created_at)='$date' AND is_deleted=0 ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

// Final take-home
$d['take_home'] = $d['net_profit'] - $d['expenses'];

// Cash register movement
$d['reg_sales'] = $d['cash_revenue'];
$d['reg_gcash_in'] = $d['gcash_cashin'];
$d['reg_gcash_out'] = $d['gcash_cashout'];
$d['reg_atm_out'] = $d['atm_withdrawn'];
$d['reg_adjustment'] = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM transactions t WHERE DATE(transaction_date)='$date' AND payment_method='cash_adjustment' AND status='completed' $sf")->fetch_assoc()['t']);

// Inventory snapshot
$d['inv_cost'] = floatval($conn->query("SELECT COALESCE(SUM(sp.stock * sp.purchase_price),0) as t FROM store_prices sp JOIN products p ON sp.product_id = p.id AND p.is_deleted=0 AND p.parent_product_id IS NULL WHERE sp.store_id = $store_id AND sp.is_deleted=0")->fetch_assoc()['t']);
$d['inv_units'] = intval($conn->query("SELECT COALESCE(SUM(sp.stock),0) as t FROM store_prices sp JOIN products p ON sp.product_id = p.id AND p.is_deleted=0 AND p.parent_product_id IS NULL WHERE sp.store_id = $store_id AND sp.is_deleted=0")->fetch_assoc()['t']);

// === YESTERDAY'S DATA (for comparison) ===
$y = [];
$y['total_revenue'] = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM transactions t WHERE DATE(transaction_date)='$prev_date' AND payment_method IN ('cash','delivery','credit') AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$y['total_revenue'] += floatval($conn->query("SELECT COALESCE(SUM(fee),0) as t FROM gcash_transactions WHERE DATE(transaction_date)='$prev_date' AND status='completed' AND is_deleted=0 AND (user_reference IS NULL OR user_reference != 'SETBAL')")->fetch_assoc()['t']);
$y['total_revenue'] += floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as t FROM transactions t WHERE DATE(transaction_date)='$prev_date' AND payment_method='atm_withdrawal' AND status='completed' $sf")->fetch_assoc()['t']);
$y['net_profit'] = floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as t FROM transactions t WHERE DATE(transaction_date)='$prev_date' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$y['net_profit'] += floatval($conn->query("SELECT COALESCE(SUM(fee),0) as t FROM gcash_transactions WHERE DATE(transaction_date)='$prev_date' AND status='completed' AND is_deleted=0 AND (user_reference IS NULL OR user_reference != 'SETBAL')")->fetch_assoc()['t']);
$y['supply_cost'] = floatval($conn->query("SELECT COALESCE(SUM(total_cost),0) as t FROM stock_receipts WHERE DATE(created_at)='$prev_date' AND is_deleted=0")->fetch_assoc()['t']);

// Stock movement: products sold today
$stock_sold = $conn->query("SELECT ti.product_name, SUM(ti.quantity) as qty_sold, SUM(ti.subtotal) as revenue, SUM(ti.profit) as profit
    FROM transaction_items ti JOIN transactions t ON ti.transaction_id = t.id
    WHERE DATE(t.transaction_date) = '$date' AND t.status = 'completed' $sf
    GROUP BY ti.product_id ORDER BY qty_sold DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

$conn->close();

function arrow($current, $previous) {
    if ($previous == 0) return '';
    $diff = $current - $previous;
    $pct = abs($diff / $previous * 100);
    if ($diff > 0) return '<span style="color:#16a34a;font-size:10px;">▲ ' . number_format($pct, 0) . '%</span>';
    if ($diff < 0) return '<span style="color:#dc2626;font-size:10px;">▼ ' . number_format($pct, 0) . '%</span>';
    return '<span style="color:#94a3b8;font-size:10px;">—</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Daily Summary - Oro Store</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <style>
        .ds-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:16px; }
        .ds-card { background:#fff; border-radius:12px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .ds-card h3 { font-size:13px; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:12px; border-bottom:1px solid #e2e8f0; padding-bottom:6px; }
        .ds-row { display:flex; justify-content:space-between; padding:4px 0; font-size:13px; }
        .ds-row.total { font-weight:800; font-size:14px; border-top:2px solid #1e293b; padding-top:8px; margin-top:6px; }
        .ds-row .label { color:#475569; }
        .ds-row .val { font-weight:600; }
        .ds-row .val.green { color:#16a34a; } .ds-row .val.red { color:#dc2626; } .ds-row .val.blue { color:#3b82f6; } .ds-row .val.amber { color:#f59e0b; }
        .ds-row .sub { font-size:11px; color:#94a3b8; margin-left:6px; }

        .ds-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
        .ds-header h1 { font-size:20px; margin:0; }
        .ds-nav { display:flex; gap:6px; align-items:center; }
        .ds-nav a, .ds-nav button { padding:6px 12px; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:6px; font-size:12px; font-weight:600; color:#475569; cursor:pointer; text-decoration:none; }
        .ds-nav a:hover, .ds-nav button:hover { background:#e2e8f0; }
        .ds-nav .active { background:#6366f1; color:#fff; border-color:#6366f1; }

        .ds-big { display:flex; gap:14px; margin-bottom:16px; }
        .ds-big-card { flex:1; background:#fff; border-radius:12px; padding:16px; box-shadow:0 1px 3px rgba(0,0,0,.06); text-align:center; border-top:3px solid; }
        .ds-big-card .big-label { font-size:11px; color:#64748b; text-transform:uppercase; font-weight:700; }
        .ds-big-card .big-val { font-size:24px; font-weight:800; margin:4px 0; }
        .ds-big-card .big-sub { font-size:11px; color:#94a3b8; }

        .ds-table { width:100%; border-collapse:collapse; font-size:12px; }
        .ds-table th { padding:6px 10px; text-align:left; font-size:10px; color:#64748b; text-transform:uppercase; border-bottom:2px solid #e2e8f0; background:#f8fafc; }
        .ds-table td { padding:6px 10px; border-bottom:1px solid #f1f5f9; }
        .ds-table .num { text-align:right; font-family:'Courier New',monospace; }

        @media print {
            .sidebar, .sidebar-toggle, .sidebar-overlay { display:none !important; }
            .main-content { margin-left:0 !important; }
            .ds-nav, .no-print { display:none !important; }
            @page { size:letter; margin:10mm; }
        }
        @media (max-width:768px) { .ds-grid { grid-template-columns:1fr; } .ds-big { flex-direction:column; } }
    </style>
</head>
<body>
<?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>
<main class="main-content">

<div class="ds-header">
    <div>
        <h1>Daily Summary</h1>
        <div style="font-size:13px;color:#64748b;"><?php echo date('l, F j, Y', strtotime($date)); ?><?php echo $store ? ' — ' . htmlspecialchars($store['store_name']) : ''; ?></div>
    </div>
    <div class="ds-nav">
        <a href="?date=<?php echo date('Y-m-d', strtotime($date . ' -1 day')); ?>&store=<?php echo $store_id; ?>">← Prev</a>
        <a href="?date=<?php echo date('Y-m-d'); ?>&store=<?php echo $store_id; ?>" class="<?php echo $date == date('Y-m-d') ? 'active' : ''; ?>">Today</a>
        <a href="?date=<?php echo date('Y-m-d', strtotime($date . ' +1 day')); ?>&store=<?php echo $store_id; ?>">Next →</a>
        <form method="GET" style="display:flex;gap:4px;">
            <input type="date" name="date" value="<?php echo $date; ?>" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;">
            <select name="store" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;">
                <?php foreach ($stores as $s): ?><option value="<?php echo $s['id']; ?>" <?php echo $store_id == $s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['store_code']); ?></option><?php endforeach; ?>
            </select>
            <button type="submit" style="padding:5px 10px;background:#6366f1;color:#fff;border:none;border-radius:4px;font-size:11px;cursor:pointer;">Go</button>
        </form>
        <button onclick="window.print()" class="no-print" style="background:#16a34a;color:#fff;border-color:#16a34a;">Print</button>
    </div>
</div>

<!-- Big numbers -->
<div class="ds-big">
    <div class="ds-big-card" style="border-color:#3b82f6;">
        <div class="big-label">Revenue</div>
        <div class="big-val" style="color:#3b82f6;">₱<?php echo number_format($d['total_revenue'], 2); ?></div>
        <div class="big-sub"><?php echo $d['total_txn']; ?> transactions <?php echo arrow($d['total_revenue'], $y['total_revenue']); ?> vs yesterday</div>
    </div>
    <div class="ds-big-card" style="border-color:#dc2626;">
        <div class="big-label">COGS (Cost of Goods Sold)</div>
        <div class="big-val" style="color:#dc2626;">₱<?php echo number_format($d['total_cogs'], 2); ?></div>
        <div class="big-sub">Products sold at cost</div>
    </div>
    <div class="ds-big-card" style="border-color:#16a34a;">
        <div class="big-label">Net Profit</div>
        <div class="big-val" style="color:<?php echo $d['net_profit'] >= 0 ? '#16a34a' : '#dc2626'; ?>;">₱<?php echo number_format($d['net_profit'], 2); ?></div>
        <div class="big-sub">Sales profit + fees <?php echo arrow($d['net_profit'], $y['net_profit']); ?> vs yesterday</div>
    </div>
    <div class="ds-big-card" style="border-color:#f59e0b;">
        <div class="big-label">Fees Earned</div>
        <div class="big-val" style="color:#f59e0b;">₱<?php echo number_format($d['total_fees'], 2); ?></div>
        <div class="big-sub">Delivery + Credit + GCash + ATM</div>
    </div>
</div>
<div class="ds-big" style="margin-bottom:16px;">
    <div class="ds-big-card" style="border-color:#ef4444;">
        <div class="big-label">Expenses</div>
        <div class="big-val" style="color:#ef4444;">₱<?php echo number_format($d['expenses'], 2); ?></div>
        <div class="big-sub"><?php echo count($d['expense_list']); ?> expense(s) today</div>
    </div>
    <div class="ds-big-card" style="border-color:<?php echo $d['take_home'] >= 0 ? '#059669' : '#dc2626'; ?>;">
        <div class="big-label">Take-Home (Profit - Expenses)</div>
        <div class="big-val" style="color:<?php echo $d['take_home'] >= 0 ? '#059669' : '#dc2626'; ?>;">₱<?php echo number_format($d['take_home'], 2); ?></div>
        <div class="big-sub">What you actually keep today</div>
    </div>
</div>

<div class="ds-grid">
    <!-- P&L -->
    <div class="ds-card">
        <h3>Profit & Loss</h3>
        <div class="ds-row"><span class="label">Cash Sales</span><span class="val">₱<?php echo number_format($d['cash_revenue'], 2); ?> <span class="sub"><?php echo $d['cash_count']; ?> txn</span></span></div>
        <div class="ds-row"><span class="label">Delivery Sales</span><span class="val">₱<?php echo number_format($d['del_revenue'], 2); ?> <span class="sub"><?php echo $d['del_count']; ?> txn</span></span></div>
        <div class="ds-row"><span class="label">Credit Sales</span><span class="val">₱<?php echo number_format($d['cred_revenue'], 2); ?></span></div>
        <div class="ds-row" style="border-top:1px solid #e2e8f0;padding-top:6px;margin-top:4px;"><span class="label"><strong>Total Revenue</strong></span><span class="val blue"><strong>₱<?php echo number_format($d['total_revenue'], 2); ?></strong></span></div>
        <div class="ds-row"><span class="label">Less: COGS</span><span class="val red">-₱<?php echo number_format($d['total_cogs'], 2); ?></span></div>
        <div class="ds-row"><span class="label"><strong>Gross Profit</strong></span><span class="val green"><strong>₱<?php echo number_format($d['total_sales_profit'], 2); ?></strong></span></div>
        <div class="ds-row"><span class="label">+ Delivery Fees</span><span class="val amber">₱<?php echo number_format($d['del_fee'], 2); ?></span></div>
        <div class="ds-row"><span class="label">+ Credit Fees</span><span class="val amber">₱<?php echo number_format($d['cred_fee'], 2); ?></span></div>
        <div class="ds-row"><span class="label">+ GCash Fees</span><span class="val amber">₱<?php echo number_format($d['gcash_fee'], 2); ?></span></div>
        <div class="ds-row"><span class="label">+ ATM Fees</span><span class="val amber">₱<?php echo number_format($d['atm_fee'], 2); ?></span></div>
        <div class="ds-row total"><span class="label">NET PROFIT</span><span class="val <?php echo $d['net_profit'] >= 0 ? 'green' : 'red'; ?>">₱<?php echo number_format($d['net_profit'], 2); ?></span></div>
        <div class="ds-row"><span class="label">Margin</span><span class="val"><?php echo $d['total_revenue'] > 0 ? number_format(($d['net_profit'] / $d['total_revenue']) * 100, 1) : '0'; ?>%</span></div>
        <?php if ($d['expenses'] > 0): ?>
        <div class="ds-row" style="border-top:1px solid #e2e8f0;padding-top:6px;margin-top:4px;"><span class="label">Less: Expenses</span><span class="val red">-₱<?php echo number_format($d['expenses'], 2); ?></span></div>
        <div class="ds-row total"><span class="label">TAKE-HOME</span><span class="val <?php echo $d['take_home'] >= 0 ? 'green' : 'red'; ?>">₱<?php echo number_format($d['take_home'], 2); ?></span></div>
        <?php endif; ?>
    </div>

    <!-- Cash Register -->
    <div class="ds-card">
        <h3>Cash Register Movement</h3>
        <div class="ds-row"><span class="label">+ Cash Sales</span><span class="val green">₱<?php echo number_format($d['reg_sales'], 2); ?></span></div>
        <div class="ds-row"><span class="label">+ GCash Cash-In (received)</span><span class="val green">₱<?php echo number_format($d['reg_gcash_in'], 2); ?></span></div>
        <div class="ds-row"><span class="label">- GCash Cash-Out (given)</span><span class="val red">-₱<?php echo number_format($d['reg_gcash_out'], 2); ?></span></div>
        <div class="ds-row"><span class="label">- ATM Withdrawals (given)</span><span class="val red">-₱<?php echo number_format($d['reg_atm_out'], 2); ?></span></div>
        <?php if ($d['reg_adjustment'] != 0): ?>
        <div class="ds-row"><span class="label"><?php echo $d['reg_adjustment'] >= 0 ? '+' : ''; ?> Adjustments</span><span class="val"><?php echo ($d['reg_adjustment'] >= 0 ? '' : '-') . '₱' . number_format(abs($d['reg_adjustment']), 2); ?></span></div>
        <?php endif; ?>
        <div class="ds-row total">
            <span class="label">NET CASH FLOW</span>
            <?php $net_cash = $d['reg_sales'] + $d['reg_gcash_in'] - $d['reg_gcash_out'] - $d['reg_atm_out'] + $d['reg_adjustment']; ?>
            <span class="val <?php echo $net_cash >= 0 ? 'green' : 'red'; ?>">₱<?php echo number_format($net_cash, 2); ?></span>
        </div>

        <h3 style="margin-top:16px;">Supply & Inventory</h3>
        <div class="ds-row"><span class="label">Stock Receipts</span><span class="val"><?php echo $d['supply_count']; ?> receipts, <?php echo $d['supply_items']; ?> units</span></div>
        <div class="ds-row"><span class="label">Supply Spending</span><span class="val red">₱<?php echo number_format($d['supply_cost'], 2); ?> <?php echo arrow($d['supply_cost'], $y['supply_cost']); ?></span></div>
        <div class="ds-row"><span class="label">Inventory Value (COGS)</span><span class="val">₱<?php echo number_format($d['inv_cost'], 0); ?></span></div>
        <div class="ds-row"><span class="label">Inventory Units</span><span class="val"><?php echo number_format($d['inv_units']); ?> units</span></div>
    </div>
</div>

<!-- Stock Movement -->
<div class="ds-card" style="margin-bottom:16px;">
    <h3>Expenses</h3>
    <?php if (empty($d['expense_list'])): ?>
        <p style="color:#94a3b8;font-size:13px;text-align:center;padding:12px;">No expenses today</p>
    <?php else: ?>
    <table class="ds-table" style="margin-bottom:16px;">
        <thead><tr><th>Category</th><th>Description</th><th class="num">Amount</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($d['expense_list'] as $exp): ?>
        <tr>
            <td><span style="background:#fee2e2;color:#991b1b;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;"><?php echo htmlspecialchars($exp['category']); ?></span></td>
            <td style="color:#475569;"><?php echo htmlspecialchars($exp['description'] ?: '—'); ?></td>
            <td class="num" style="font-weight:700;color:#dc2626;">₱<?php echo number_format($exp['amount'], 2); ?></td>
            <td style="font-size:11px;color:#94a3b8;"><?php echo date('g:i A', strtotime($exp['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="border-top:2px solid #1e293b;"><td colspan="2" style="font-weight:800;">Total Expenses</td><td class="num" style="font-weight:800;color:#dc2626;">₱<?php echo number_format($d['expenses'], 2); ?></td><td></td></tr>
        </tbody>
    </table>
    <?php endif; ?>

    <h3>Products Sold Today (Stock Movement)</h3>
    <?php if (empty($stock_sold)): ?>
        <p style="color:#94a3b8;font-size:13px;text-align:center;padding:12px;">No sales today</p>
    <?php else: ?>
    <table class="ds-table">
        <thead><tr><th>Product</th><th class="num">Qty Sold</th><th class="num">Revenue</th><th class="num">Profit</th></tr></thead>
        <tbody>
        <?php foreach ($stock_sold as $ss): ?>
        <tr>
            <td style="font-weight:600;"><?php echo htmlspecialchars($ss['product_name']); ?></td>
            <td class="num"><?php echo $ss['qty_sold']; ?></td>
            <td class="num">₱<?php echo number_format($ss['revenue'], 2); ?></td>
            <td class="num" style="color:<?php echo floatval($ss['profit']) >= 0 ? '#16a34a' : '#dc2626'; ?>;font-weight:700;">₱<?php echo number_format($ss['profit'], 2); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Yesterday comparison -->
<div class="ds-card">
    <h3>vs Yesterday (<?php echo date('M j', strtotime($prev_date)); ?>)</h3>
    <div class="ds-row"><span class="label">Revenue</span><span class="val">₱<?php echo number_format($y['total_revenue'], 2); ?> → ₱<?php echo number_format($d['total_revenue'], 2); ?> <?php echo arrow($d['total_revenue'], $y['total_revenue']); ?></span></div>
    <div class="ds-row"><span class="label">Profit</span><span class="val">₱<?php echo number_format($y['net_profit'], 2); ?> → ₱<?php echo number_format($d['net_profit'], 2); ?> <?php echo arrow($d['net_profit'], $y['net_profit']); ?></span></div>
    <div class="ds-row"><span class="label">Supply Spending</span><span class="val">₱<?php echo number_format($y['supply_cost'], 2); ?> → ₱<?php echo number_format($d['supply_cost'], 2); ?> <?php echo arrow($d['supply_cost'], $y['supply_cost']); ?></span></div>
</div>

</main>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Daily Summary', array (
  'Features' => 
  array (
    0 => 'End-of-day report with total sales, expenses, and profit',
    1 => 'Breakdown by payment method (cash, GCash, credit, delivery)',
    2 => 'Top selling products list',
    3 => 'Cash register reconciliation',
  ),
));
?>
</body>
</html>
