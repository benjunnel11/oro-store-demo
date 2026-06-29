<?php
if (!isset($currentUser)) {
    require_once __DIR__ . '/../core/auth_check.php';
    $currentUser = getCurrentUser();
}

$_current_page = ltrim(str_replace('/oro-store/', '', $_SERVER['PHP_SELF']), '/');
$_is_super = ($currentUser['role'] ?? '') === 'super_admin';
$_is_store_admin = !$_is_super && !empty($currentUser['store_id']);
$_on_own_device = isOnOwnDevice();

// Clear admin cashier store selection when navigating away from cashier
if (isset($_SESSION['admin_cashier_store'])) {
    unset($_SESSION['admin_cashier_store']);
}

// Sidebar badge cache — reuse for 30 seconds across page navigations
$_sb_cache_ttl = 30;
$_sb_cache_valid = isset($_SESSION['_sb_cache'], $_SESSION['_sb_cache_ts']) && (time() - $_SESSION['_sb_cache_ts']) < $_sb_cache_ttl;

$_b = ['out_of_stock'=>0,'low_stock'=>0,'pending_credits'=>0,'credit_amount'=>0,'pending_deliveries'=>0,'delivery_amount'=>0,'active_angkat'=>0,'angkat_amount'=>0,'today_transactions'=>0,'today_supply'=>0,'today_transfers'=>0];

if ($_sb_cache_valid) {
    $_b = $_SESSION['_sb_cache'];
} else {
    global $conn;
    require_once __DIR__ . '/../core/db_config.php';
    $_sb_own = false;
    $_sb = null;
    try { if ($conn && $conn->ping()) $_sb = $conn; } catch (\Throwable $e) {}
    if (!$_sb) { $_sb = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME); $_sb_own = true; }
    if ($_sb && !$_sb->connect_error) {
        $r = @$_sb->query("SELECT
            (SELECT COUNT(*) FROM products p WHERE p.is_deleted=0 AND p.parent_product_id IS NULL AND COALESCE((SELECT SUM(sp.stock) FROM store_prices sp WHERE sp.product_id=p.id AND sp.is_deleted=0),p.stock)=0) as out_of_stock,
            (SELECT COUNT(*) FROM products p WHERE p.is_deleted=0 AND p.parent_product_id IS NULL AND COALESCE((SELECT SUM(sp.stock) FROM store_prices sp WHERE sp.product_id=p.id AND sp.is_deleted=0),p.stock) BETWEEN 1 AND 5) as low_stock,
            (SELECT COUNT(*) FROM credits WHERE status IN ('unpaid','partial') AND is_deleted=0) as pending_credits,
            (SELECT COALESCE(SUM(amount_due),0) FROM credits WHERE status IN ('unpaid','partial') AND is_deleted=0) as credit_amount,
            (SELECT COUNT(*) FROM deliveries WHERE status='pending' AND is_deleted=0) as pending_deliveries,
            (SELECT COUNT(*) FROM angkat_transactions WHERE status='active' AND is_deleted=0) as active_angkat,
            (SELECT COALESCE(SUM(total_value-amount_collected),0) FROM angkat_transactions WHERE status='active' AND is_deleted=0) as angkat_amount,
            (SELECT COUNT(*) FROM transactions WHERE DATE(transaction_date)=CURDATE() AND status IN ('completed','pending')) as today_transactions,
            (SELECT COUNT(*) FROM stock_receipts WHERE DATE(created_at)=CURDATE() AND is_deleted=0) as today_supply,
            (SELECT COUNT(*) FROM stock_transfers WHERE DATE(created_at)=CURDATE() AND is_deleted=0) as today_transfers
        ");
        if ($r) { $row = $r->fetch_assoc(); $_b = array_merge($_b, array_map('intval', $row)); $_b['credit_amount'] = floatval($row['credit_amount']); $_b['angkat_amount'] = floatval($row['angkat_amount']); }
    }
    $_SESSION['_sb_cache'] = $_b;
    $_SESSION['_sb_cache_ts'] = time();
    if (isset($_sb_own) && $_sb_own && $_sb) @$_sb->close();
}

$_total_alerts = $_b['out_of_stock'] + $_b['pending_credits'] + $_b['pending_deliveries'] + $_b['active_angkat'];

function sidebarActive($page) {
    global $_current_page;
    return $_current_page === $page ? ' active' : '';
}
?>

<!-- Mobile toggle -->
<button class="sidebar-toggle" onclick="toggleSidebar()">&#9776;</button>
<div class="sidebar-overlay" id="sidebarOverlay" role="presentation" onclick="toggleSidebar()"></div>

<style>
.sidebar-link .badge-count { margin-left:auto; font-size:10px; font-weight:700; padding:2px 7px; border-radius:10px; min-width:18px; text-align:center; line-height:14px; }
.badge-red { background:#ef4444; color:#fff; }
.badge-orange { background:#f59e0b; color:#fff; }
.badge-blue { background:#3b82f6; color:#fff; }
.badge-purple { background:#8b5cf6; color:#fff; }
.badge-green { background:#22c55e; color:#fff; }
.badge-amount { font-size:9px; font-weight:600; color:#94a3b8; margin-left:auto; padding-right:2px; }
.sidebar-link .badge-group { margin-left:auto; display:flex; gap:4px; align-items:center; }
.sidebar-brand .alert-dot { display:inline-block; width:8px; height:8px; border-radius:50%; background:#ef4444; margin-left:6px; animation:pulse-dot 2s infinite; vertical-align:middle; }
@keyframes pulse-dot { 0%,100%{opacity:1;} 50%{opacity:.3;} }

/* Connection signal */
#conn-signal .cb { transition:background .3s,height .3s; }
#conn-signal.sig-great .cb { background:#22c55e; }
#conn-signal.sig-good .cb:nth-child(-n+3) { background:#22c55e; }
#conn-signal.sig-ok .cb:nth-child(-n+2) { background:#f59e0b; }
#conn-signal.sig-poor .cb:nth-child(1) { background:#ef4444; }
#conn-signal.sig-dead .cb { background:#334155; }

/* Loading bar */
#nav-loader { position:fixed; top:0; left:0; width:0; height:3px; background:linear-gradient(90deg,#818cf8,#a78bfa,#c084fc); z-index:9999; transition:width .2s ease; box-shadow:0 0 8px rgba(129,140,248,.5); }
#nav-loader.loading { width:70%; transition:width 1.5s cubic-bezier(.1,.8,.2,1); }
#nav-loader.done { width:100%; transition:width .15s ease; opacity:0; transition:width .15s ease, opacity .3s .15s; }
.sidebar-link.nav-loading { background:#334155; color:#fff; opacity:.7; }
</style>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <h2>Oro Store<?php if ($_total_alerts > 0): ?><span class="alert-dot"></span><?php endif; ?></h2>
        <span>ADMIN</span>
    </div>
    <?php
    // Use $_device from db_connection.php (auto-detected), fall back to config
    @include_once __DIR__ . '/../sync/config.php';
    $_dev_id = isset($GLOBALS['_device']) ? $GLOBALS['_device'] : (defined('LOCAL_DEVICE_ID') ? LOCAL_DEVICE_ID : 'DEVICE_A');
    $_dev_ltr = substr($_dev_id, -1);
    $_dev_n = ord($_dev_ltr) - 64;
    $_dev_clrs = ['A'=>'#6366f1','B'=>'#f59e0b','C'=>'#16a34a','D'=>'#dc2626','E'=>'#8b5cf6','F'=>'#0891b2','G'=>'#d946ef','H'=>'#ea580c','I'=>'#4f46e5','J'=>'#059669'];
    $_dev_clr = $_dev_clrs[$_dev_ltr] ?? '#6366f1';
    ?>
    <div style="margin:0 12px 4px;padding:8px 10px;border-radius:8px;background:<?php echo $_dev_clr; ?>;color:#fff;text-align:center;font-size:11px;font-weight:700;letter-spacing:0.5px;">
        <?php echo $_dev_ltr; ?> — <?php echo $_dev_n === 1 ? 'MAIN SERVER' : 'BRANCH ' . ($_dev_n - 1); ?>
    </div>
    <!-- Connection signal -->
    <div id="conn-signal" style="margin:0 12px 8px;padding:5px 10px;border-radius:6px;background:#0f172a;display:flex;align-items:center;justify-content:center;gap:6px;font-size:10px;color:#64748b;font-weight:600;">
        <span id="conn-bars" style="display:inline-flex;align-items:flex-end;gap:1px;height:12px;">
            <span class="cb" style="width:3px;height:3px;background:#334155;border-radius:1px;"></span>
            <span class="cb" style="width:3px;height:5px;background:#334155;border-radius:1px;"></span>
            <span class="cb" style="width:3px;height:8px;background:#334155;border-radius:1px;"></span>
            <span class="cb" style="width:3px;height:11px;background:#334155;border-radius:1px;"></span>
        </span>
        <span id="conn-label">Checking...</span>
        <span id="conn-ms" style="color:#475569;font-size:9px;"></span>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-group-label">Main</div>
        <a href="/oro-store/admin/admin_panel.php" class="sidebar-link<?php echo sidebarActive('admin/admin_panel.php'); ?>">
            <span class="link-icon">&#128202;</span> Dashboard
            <?php if ($_total_alerts > 0): ?>
                <span class="badge-count badge-red"><?php echo $_total_alerts; ?></span>
            <?php endif; ?>
        </a>
        <?php if ($_on_own_device): ?>
        <a href="/oro-store/cashier/cashier.php" class="sidebar-link<?php echo sidebarActive('cashier/cashier.php'); ?>">
            <span class="link-icon">&#128179;</span> Open Cashier
        </a>
        <?php endif; ?>

        <div class="nav-group-label">Inventory</div>
        <a href="/oro-store/admin/admin_products.php" class="sidebar-link<?php echo sidebarActive('admin/admin_products.php'); ?>">
            <span class="link-icon">&#128230;</span> Products
            <?php if ($_b['out_of_stock'] > 0 || $_b['low_stock'] > 0): ?>
                <span class="badge-group">
                    <?php if ($_b['out_of_stock'] > 0): ?><span class="badge-count badge-red"><?php echo $_b['out_of_stock']; ?></span><?php endif; ?>
                    <?php if ($_b['low_stock'] > 0): ?><span class="badge-count badge-orange"><?php echo $_b['low_stock']; ?></span><?php endif; ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="/oro-store/products/new_product.php" class="sidebar-link<?php echo sidebarActive('products/new_product.php'); ?>">
            <span class="link-icon">&#10133;</span> Add Product
        </a>
        <?php if (!$_is_store_admin): ?>
        <a href="/oro-store/admin/manage_stores.php" class="sidebar-link<?php echo sidebarActive('admin/manage_stores.php'); ?>">
            <span class="link-icon">&#127978;</span> Stores
        </a>
        <?php endif; ?>
        <a href="/oro-store/print/inventory_sheet.php" class="sidebar-link<?php echo sidebarActive('print/inventory_sheet.php'); ?>">
            <span class="link-icon">&#128203;</span> Inventory Sheet
        </a>
        <a href="/oro-store/stock/stock_transfer.php" class="sidebar-link<?php echo sidebarActive('stock/stock_transfer.php'); ?>">
            <span class="link-icon">&#128260;</span> Stock Transfer
            <?php if ($_b['today_transfers'] > 0): ?>
                <span class="badge-count badge-blue"><?php echo $_b['today_transfers']; ?></span>
            <?php endif; ?>
        </a>

        <?php if (!$_on_own_device && !$_is_super): ?>
        <div style="margin:4px 12px 8px;padding:6px 8px;background:#451a03;border-radius:6px;font-size:9px;color:#fbbf24;text-align:center;font-weight:600;">
            ⚠ Wrong device — view only
        </div>
        <?php endif; ?>

        <div class="nav-group-label">Sales</div>
        <a href="/oro-store/transactions/transaction_history.php" class="sidebar-link<?php echo sidebarActive('transactions/transaction_history.php'); ?>">
            <span class="link-icon">&#128195;</span> Transactions
            <?php if ($_b['today_transactions'] > 0): ?>
                <span class="badge-count badge-blue"><?php echo $_b['today_transactions']; ?></span>
            <?php endif; ?>
        </a>
        <a href="/oro-store/transactions/gcash_transaction_history.php" class="sidebar-link<?php echo sidebarActive('transactions/gcash_transaction_history.php'); ?>">
            <span class="link-icon">&#128176;</span> GCash
        </a>
        <a href="/oro-store/transactions/card_transaction_history.php" class="sidebar-link<?php echo sidebarActive('transactions/card_transaction_history.php'); ?>">
            <span class="link-icon">&#128179;</span> ATM
        </a>
        <a href="/oro-store/credit/credit_management.php" class="sidebar-link<?php echo sidebarActive('credit/credit_management.php'); ?>">
            <span class="link-icon">&#128180;</span> Credit
            <?php if ($_b['pending_credits'] > 0): ?>
                <span class="badge-group">
                    <span class="badge-count badge-red"><?php echo $_b['pending_credits']; ?></span>
                    <span class="badge-amount">&#8369;<?php echo number_format($_b['credit_amount'], 0); ?></span>
                </span>
            <?php endif; ?>
        </a>
        <a href="/oro-store/credit/credit_details.php" class="sidebar-link<?php echo sidebarActive('credit/credit_details.php'); ?>">
            <span class="link-icon">&#128203;</span> Credit Details
        </a>
        <a href="/oro-store/delivery/delivery_management.php" class="sidebar-link<?php echo sidebarActive('delivery/delivery_management.php'); ?>">
            <span class="link-icon">&#128666;</span> Delivery
            <?php if ($_b['pending_deliveries'] > 0): ?>
                <span class="badge-group">
                    <span class="badge-count badge-red"><?php echo $_b['pending_deliveries']; ?></span>
                    <span class="badge-amount">&#8369;<?php echo number_format($_b['delivery_amount'], 0); ?></span>
                </span>
            <?php endif; ?>
        </a>
        <a href="/oro-store/delivery/delivery_details.php" class="sidebar-link<?php echo sidebarActive('delivery/delivery_details.php'); ?>">
            <span class="link-icon">&#128203;</span> Delivery Details
        </a>
        <a href="/oro-store/angkat/angkat_management.php" class="sidebar-link<?php echo sidebarActive('angkat/angkat_management.php'); ?>">
            <span class="link-icon">&#128230;</span> Angkat
            <?php if ($_b['active_angkat'] > 0): ?>
                <span class="badge-group">
                    <span class="badge-count badge-purple"><?php echo $_b['active_angkat']; ?></span>
                    <span class="badge-amount">&#8369;<?php echo number_format($_b['angkat_amount'], 0); ?></span>
                </span>
            <?php endif; ?>
        </a>
        <a href="/oro-store/angkat/angkat_details.php" class="sidebar-link<?php echo sidebarActive('angkat/angkat_details.php'); ?>">
            <span class="link-icon">&#128203;</span> Angkat Details
        </a>

        <div class="nav-group-label">People</div>
        <?php if (!$_is_store_admin): ?>
        <a href="/oro-store/admin/manage_users.php" class="sidebar-link<?php echo sidebarActive('admin/manage_users.php'); ?>">
            <span class="link-icon">&#128101;</span> Users
        </a>
        <?php endif; ?>
        <a href="/oro-store/payroll/payroll.php" class="sidebar-link<?php echo sidebarActive('payroll/payroll.php'); ?>">
            <span class="link-icon">&#128188;</span> Payroll
        </a>
        <a href="/oro-store/payroll/payroll_summary.php" class="sidebar-link<?php echo sidebarActive('payroll/payroll_summary.php'); ?>">
            <span class="link-icon">&#128203;</span> Payroll Summary
        </a>

        <div class="nav-group-label">Reports</div>
        <a href="/oro-store/admin/daily_summary.php" class="sidebar-link<?php echo sidebarActive('admin/daily_summary.php'); ?>">
            <span class="link-icon">&#128203;</span> Daily Summary
        </a>
        <a href="/oro-store/admin/admin_stats.php" class="sidebar-link<?php echo sidebarActive('admin/admin_stats.php'); ?>">
            <span class="link-icon">&#128200;</span> Statistics
        </a>
        <a href="/oro-store/admin/activity_log.php" class="sidebar-link<?php echo sidebarActive('admin/activity_log.php'); ?>">
            <span class="link-icon">&#128203;</span> Activity Log
        </a>

        <?php if (!$_is_store_admin): ?>
        <div class="nav-group-label">System</div>
        <a href="/oro-store/admin/connection.php" class="sidebar-link<?php echo sidebarActive('admin/connection.php'); ?>">
            <span class="link-icon">&#128279;</span> Connection
        </a>
        <a href="/oro-store/sync/cloud_status.php" class="sidebar-link<?php echo sidebarActive('sync/cloud_status.php'); ?>">
            <span class="link-icon">&#9729;</span> Cloud Sync
        </a>
        <a href="/oro-store/admin/change_db_password.php" class="sidebar-link<?php echo sidebarActive('admin/change_db_password.php'); ?>">
            <span class="link-icon">&#128274;</span> DB Password
        </a>
        <a href="/oro-store/admin/change_network_password.php" class="sidebar-link<?php echo sidebarActive('admin/change_network_password.php'); ?>">
            <span class="link-icon">&#127760;</span> Network Password
        </a>
        <a href="/oro-store/admin/reset_data.php" class="sidebar-link<?php echo sidebarActive('admin/reset_data.php'); ?>">
            <span class="link-icon">&#9888;</span> Reset Data
        </a>
        <?php if ($_is_super): ?>
        <a href="/oro-store/admin/branch_wipe.php" class="sidebar-link<?php echo sidebarActive('admin/branch_wipe.php'); ?>">
            <span class="link-icon">&#128274;</span> Branch Wipe
        </a>
        <?php endif; ?>
        <?php endif; ?>
    </nav>

    <div class="sidebar-user">
        <div class="sidebar-avatar"><?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?></div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?php echo htmlspecialchars($currentUser['full_name']); ?></div>
            <div class="sidebar-user-role"><?php echo $_is_store_admin ? 'Store Admin — ' . htmlspecialchars($currentUser['store_name']) : ($_is_super ? 'Super Admin' : 'Administrator'); ?></div>
        </div>
    </div>
    <a href="/oro-store/auth/logout.php" class="sidebar-logout" onclick="this.classList.add('logging-out')">
        <span>&#x2716;</span> Logout
    </a>
</aside>

<div id="nav-loader"></div>
<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
}

// Instant navigation feedback
(function(){
    var links = document.querySelectorAll('.sidebar-link[href]');
    var loader = document.getElementById('nav-loader');
    links.forEach(function(link) {
        link.addEventListener('click', function(e) {
            if (link.classList.contains('active')) return;
            links.forEach(function(l) { l.classList.remove('nav-loading'); });
            link.classList.add('nav-loading');
            loader.className = '';
            loader.offsetWidth;
            loader.classList.add('loading');
        });
    });
    window.addEventListener('pageshow', function() {
        loader.classList.remove('loading');
        loader.classList.add('done');
        document.querySelectorAll('.nav-loading').forEach(function(l) { l.classList.remove('nav-loading'); });
    });
})();

// Connection signal
(function(){
    var sig = document.getElementById('conn-signal');
    var label = document.getElementById('conn-label');
    var ms = document.getElementById('conn-ms');
    var bars = sig.querySelectorAll('.cb');

    function checkConnection() {
        var t0 = performance.now();
        fetch('/oro-store/core/ping.php?_=' + Date.now(), { cache: 'no-store' })
        .then(function(r) { return r.text(); })
        .then(function() {
            var latency = Math.round(performance.now() - t0);
            ms.textContent = latency + 'ms';
            sig.className = '';
            if (latency < 80) { sig.classList.add('sig-great'); label.textContent = 'Excellent'; label.style.color = '#22c55e'; }
            else if (latency < 200) { sig.classList.add('sig-good'); label.textContent = 'Good'; label.style.color = '#22c55e'; }
            else if (latency < 500) { sig.classList.add('sig-ok'); label.textContent = 'Fair'; label.style.color = '#f59e0b'; }
            else { sig.classList.add('sig-poor'); label.textContent = 'Slow'; label.style.color = '#ef4444'; }
        })
        .catch(function() {
            sig.className = 'sig-dead';
            label.textContent = 'Offline';
            label.style.color = '#ef4444';
            ms.textContent = '';
        });
    }
    checkConnection();
    setInterval(checkConnection, 15000);
})();

// Background sync on ALL admin pages — every 30 seconds
(function(){
    function bgSync(){
        fetch('/oro-store/sync/cloud_pull.php').catch(function(){});
    }
    setTimeout(bgSync, 5000);
    setInterval(bgSync, 30000);
})();
</script>
