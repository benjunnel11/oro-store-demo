<?php
if (!isset($currentUser)) {
    require_once __DIR__ . '/../core/auth_check.php';
    $currentUser = getCurrentUser();
}

$_current_page = ltrim(str_replace('/oro-store/', '', $_SERVER['PHP_SELF']), '/');
$_current_view = $_GET['view'] ?? 'dashboard';

if (isset($_SESSION['admin_cashier_store'])) {
    unset($_SESSION['admin_cashier_store']);
}

$_mgr_store_id = $currentUser['store_id'] ?? null;
$_mb = ['out_of_stock' => 0, 'low_stock' => 0, 'pending_deliveries' => 0, 'delivery_amount' => 0, 'pending_credits' => 0, 'credit_amount' => 0, 'active_angkat' => 0, 'angkat_amount' => 0, 'employees' => 0];
$_msb_cache_valid = isset($_SESSION['_msb_cache'], $_SESSION['_msb_cache_ts']) && (time() - $_SESSION['_msb_cache_ts']) < 30;

if ($_msb_cache_valid) {
    $_mb = $_SESSION['_msb_cache'];
} elseif ($_mgr_store_id) {
    require_once __DIR__ . '/../core/db_config.php';
    $_mc = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$_mc->connect_error) {
        $_mb['out_of_stock'] = $_mc->query("SELECT COUNT(*) as c FROM store_prices sp JOIN products p ON sp.product_id = p.id WHERE sp.store_id = {$_mgr_store_id} AND sp.stock = 0 AND sp.is_deleted = 0 AND p.is_deleted = 0 AND p.parent_product_id IS NULL")->fetch_assoc()['c'];
        $_mb['low_stock'] = $_mc->query("SELECT COUNT(*) as c FROM store_prices sp JOIN products p ON sp.product_id = p.id WHERE sp.store_id = {$_mgr_store_id} AND sp.stock > 0 AND sp.stock <= 5 AND sp.is_deleted = 0 AND p.is_deleted = 0 AND p.parent_product_id IS NULL")->fetch_assoc()['c'];
        $_mb['pending_deliveries'] = $_mc->query("SELECT COUNT(*) as c FROM deliveries WHERE store_id = {$_mgr_store_id} AND status = 'pending' AND is_deleted = 0")->fetch_assoc()['c'];
        $_mb['delivery_amount'] = floatval($_mc->query("SELECT COALESCE(SUM(t.total_amount),0) as t FROM deliveries d LEFT JOIN transactions t ON d.transaction_id = t.id WHERE d.store_id = {$_mgr_store_id} AND d.status = 'pending' AND d.is_deleted = 0")->fetch_assoc()['t']);
        $_mb['pending_credits'] = $_mc->query("SELECT COUNT(*) as c FROM credits WHERE store_id = {$_mgr_store_id} AND status IN ('unpaid','partial') AND is_deleted = 0")->fetch_assoc()['c'];
        $_mb['credit_amount'] = floatval($_mc->query("SELECT COALESCE(SUM(amount_due),0) as t FROM credits WHERE store_id = {$_mgr_store_id} AND status IN ('unpaid','partial') AND is_deleted = 0")->fetch_assoc()['t']);
        $r = $_mc->query("SELECT COUNT(*) as c FROM angkat_transactions WHERE store_id = {$_mgr_store_id} AND status = 'active' AND is_deleted = 0"); if ($r) $_mb['active_angkat'] = $r->fetch_assoc()['c'];
        $r = $_mc->query("SELECT COALESCE(SUM(total_value - amount_collected),0) as t FROM angkat_transactions WHERE store_id = {$_mgr_store_id} AND status = 'active' AND is_deleted = 0"); if ($r) $_mb['angkat_amount'] = floatval($r->fetch_assoc()['t']);
        $r = $_mc->query("SELECT COUNT(*) as c FROM employees WHERE store_id = {$_mgr_store_id} AND status = 'active'"); if ($r) $_mb['employees'] = $r->fetch_assoc()['c'];
        $_mc->close();
    }
    $_SESSION['_msb_cache'] = $_mb;
    $_SESSION['_msb_cache_ts'] = time();
}

$_mgr_alerts = $_mb['out_of_stock'] + $_mb['pending_credits'] + $_mb['pending_deliveries'] + $_mb['active_angkat'];
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
#nav-loader { position:fixed; top:0; left:0; width:0; height:3px; background:linear-gradient(90deg,#818cf8,#a78bfa,#c084fc); z-index:9999; box-shadow:0 0 8px rgba(129,140,248,.5); }
#nav-loader.loading { width:70%; transition:width 1.5s cubic-bezier(.1,.8,.2,1); }
#nav-loader.done { width:100%; opacity:0; transition:width .15s ease, opacity .3s .15s; }
.sidebar-link.nav-loading { background:#334155; color:#fff; opacity:.7; }
@keyframes pulse-dot { 0%,100%{opacity:1;} 50%{opacity:.3;} }
#conn-signal .cb { transition:background .3s; }
#conn-signal.sig-great .cb { background:#22c55e; }
#conn-signal.sig-good .cb:nth-child(-n+3) { background:#22c55e; }
#conn-signal.sig-ok .cb:nth-child(-n+2) { background:#f59e0b; }
#conn-signal.sig-poor .cb:nth-child(1) { background:#ef4444; }
#conn-signal.sig-dead .cb { background:#334155; }
</style>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <h2>Oro Store<?php if ($_mgr_alerts > 0): ?><span class="alert-dot"></span><?php endif; ?></h2>
        <span>MANAGER</span>
    </div>
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
        <a href="/oro-store/manager/manager_panel.php" class="sidebar-link<?php echo ($_current_page === 'manager/manager_panel.php' && $_current_view === 'dashboard') ? ' active' : ''; ?>">
            <span class="link-icon">&#128202;</span> Dashboard
            <?php if ($_mgr_alerts > 0): ?>
                <span class="badge-count badge-red"><?php echo $_mgr_alerts; ?></span>
            <?php endif; ?>
        </a>
        <a href="/oro-store/cashier/cashier.php" class="sidebar-link<?php echo ($_current_page === 'cashier/cashier.php') ? ' active' : ''; ?>">
            <span class="link-icon">&#128179;</span> Open Cashier
        </a>

        <div class="nav-group-label">Inventory</div>
        <a href="/oro-store/manager/manager_products.php" class="sidebar-link<?php echo ($_current_page === 'manager/manager_products.php') ? ' active' : ''; ?>">
            <span class="link-icon">&#128230;</span> Products & Stock
            <?php if ($_mb['out_of_stock'] > 0 || $_mb['low_stock'] > 0): ?>
                <span class="badge-group">
                    <?php if ($_mb['out_of_stock'] > 0): ?><span class="badge-count badge-red"><?php echo $_mb['out_of_stock']; ?></span><?php endif; ?>
                    <?php if ($_mb['low_stock'] > 0): ?><span class="badge-count badge-orange"><?php echo $_mb['low_stock']; ?></span><?php endif; ?>
                </span>
            <?php endif; ?>
        </a>
        <a href="/oro-store/products/new_product.php" class="sidebar-link<?php echo ($_current_page === 'products/new_product.php') ? ' active' : ''; ?>">
            <span class="link-icon">&#10133;</span> Add Product
        </a>

        <div class="nav-group-label">Operations</div>
        <a href="/oro-store/delivery/delivery_details.php" class="sidebar-link<?php echo ($_current_page === 'delivery/delivery_details.php') ? ' active' : ''; ?>">
            <span class="link-icon">&#128666;</span> Delivery Details
            <?php if ($_mb['pending_deliveries'] > 0): ?>
                <span class="badge-group">
                    <span class="badge-count badge-red"><?php echo $_mb['pending_deliveries']; ?></span>
                    <span class="badge-amount">&#8369;<?php echo number_format($_mb['delivery_amount'], 0); ?></span>
                </span>
            <?php endif; ?>
        </a>
        <a href="/oro-store/credit/credit_details.php" class="sidebar-link<?php echo ($_current_page === 'credit/credit_details.php') ? ' active' : ''; ?>">
            <span class="link-icon">&#128180;</span> Credit Details
            <?php if ($_mb['pending_credits'] > 0): ?>
                <span class="badge-group">
                    <span class="badge-count badge-red"><?php echo $_mb['pending_credits']; ?></span>
                    <span class="badge-amount">&#8369;<?php echo number_format($_mb['credit_amount'], 0); ?></span>
                </span>
            <?php endif; ?>
        </a>
        <a href="/oro-store/angkat/angkat_details.php" class="sidebar-link<?php echo ($_current_page === 'angkat/angkat_details.php') ? ' active' : ''; ?>">
            <span class="link-icon">&#128230;</span> Angkat Details
            <?php if ($_mb['active_angkat'] > 0): ?>
                <span class="badge-group">
                    <span class="badge-count badge-purple"><?php echo $_mb['active_angkat']; ?></span>
                    <span class="badge-amount">&#8369;<?php echo number_format($_mb['angkat_amount'], 0); ?></span>
                </span>
            <?php endif; ?>
        </a>

        <div class="nav-group-label">People</div>
        <a href="/oro-store/manager/manager_attendance.php" class="sidebar-link<?php echo ($_current_view === 'attendance') ? ' active' : ''; ?>">
            <span class="link-icon">&#128197;</span> Attendance
            <?php if ($_mb['employees'] > 0): ?>
                <span class="badge-count badge-blue"><?php echo $_mb['employees']; ?></span>
            <?php endif; ?>
        </a>
    </nav>

    <div class="sidebar-user">
        <div class="sidebar-avatar"><?php echo strtoupper(substr($currentUser['full_name'], 0, 1)); ?></div>
        <div class="sidebar-user-info">
            <div class="sidebar-user-name"><?php echo htmlspecialchars($currentUser['full_name']); ?></div>
            <div class="sidebar-user-role">Manager<?php echo isset($userStore) && $userStore ? ' &middot; ' . htmlspecialchars($userStore['store_code']) : ''; ?></div>
        </div>
    </div>
    <a href="/oro-store/auth/logout.php" class="sidebar-logout">
        <span>&#x2716;</span> Logout
    </a>
</aside>

<div id="nav-loader"></div>
<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
}
(function(){
    var links = document.querySelectorAll('.sidebar-link[href]');
    var loader = document.getElementById('nav-loader');
    links.forEach(function(link) {
        link.addEventListener('click', function() {
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
(function(){
    var sig = document.getElementById('conn-signal');
    var label = document.getElementById('conn-label');
    var ms = document.getElementById('conn-ms');
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
</script>
