<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/config.php';

if (!isAdmin()) { header("Location: /oro-store/cashier/cashier.php"); exit; }
$currentUser = getCurrentUser();

// POST: Fix firewall
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fix_firewall') {
    $output = shell_exec('netsh advfirewall firewall add rule name="Oro Store Web Server" dir=in action=allow protocol=TCP localport=80 profile=any 2>&1');
    $_SESSION['conn_msg'] = 'Firewall rule added: ' . trim($output);
    header("Location: /oro-store/admin/connection.php");
    exit;
}

// POST: One-click setup for sync
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'setup_sync') {
    $setup_results = [];

    // 1. Allow Apache (port 80) through firewall
    shell_exec('netsh advfirewall firewall delete rule name="Oro Store Web Server" 2>nul');
    $r = shell_exec('netsh advfirewall firewall add rule name="Oro Store Web Server" dir=in action=allow protocol=TCP localport=80 profile=any 2>&1');
    $setup_results[] = 'Port 80 (Apache): ' . trim($r);

    // 2. Allow ICMP ping through firewall
    shell_exec('netsh advfirewall firewall delete rule name="Oro Store Ping" 2>nul');
    $r = shell_exec('netsh advfirewall firewall add rule name="Oro Store Ping" dir=in action=allow protocol=ICMPv4 profile=any 2>&1');
    $setup_results[] = 'ICMP Ping: ' . trim($r);

    // 3. BLOCK MySQL (port 3306) from network — only localhost should access the database
    shell_exec('netsh advfirewall firewall delete rule name="Oro Store MySQL" 2>nul');
    $r = shell_exec('netsh advfirewall firewall add rule name="Oro Store MySQL" dir=in action=block protocol=TCP localport=3306 profile=any 2>&1');
    $setup_results[] = 'Port 3306 (MySQL): BLOCKED from network';

    // 4. Create sync tables if missing
    require_once __DIR__ . '/../core/db_config.php';
    $setupConn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$setupConn->connect_error) {
        $setupConn->query("CREATE TABLE IF NOT EXISTS sync_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            table_name VARCHAR(100),
            record_id INT,
            operation VARCHAR(10),
            data LONGTEXT,
            device_id VARCHAR(50),
            synced TINYINT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $setupConn->query("CREATE TABLE IF NOT EXISTS sync_status (
            id INT AUTO_INCREMENT PRIMARY KEY,
            last_sync_time DATETIME,
            remote_device_ip VARCHAR(50),
            sync_direction VARCHAR(10),
            records_pushed INT DEFAULT 0,
            records_pulled INT DEFAULT 0,
            status VARCHAR(20),
            error_message TEXT
        )");
        $setup_results[] = 'Sync tables: ready';
        $setupConn->close();
    } else {
        $setup_results[] = 'Sync tables: MySQL not running';
    }

    $_SESSION['conn_msg'] = 'Setup complete! ' . implode(' | ', $setup_results);
    header("Location: /oro-store/admin/connection.php");
    exit;
}

// POST: Run network test
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'network_diag') {
    header('Content-Type: application/json');
    $results = [];
    $results['tailscale_ip'] = trim(shell_exec('tailscale ip -4 2>nul') ?? '');
    $results['apache_listening'] = strpos(shell_exec('netstat -an 2>nul') ?? '', '0.0.0.0:80') !== false;
    $target = trim($_POST['target_ip'] ?? '');
    if ($target && preg_match('/^\d+\.\d+\.\d+\.\d+$/', $target)) {
        $ping = shell_exec("ping -n 1 -w 2000 $target 2>&1");
        $results['ping_success'] = strpos($ping, 'TTL=') !== false;
        $results['ping_output'] = trim($ping);
    }
    echo json_encode($results);
    exit;
}

// POST: Save store IPs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_store_ips') {
    $ips = $_POST['store_ip'] ?? [];
    $ip_conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$ip_conn->connect_error) {
        foreach ($ips as $store_id => $ip) {
            $sid = intval($store_id);
            $ip_val = trim($ip);
            $ip_conn->query("UPDATE stores SET device_ip = '" . $ip_conn->real_escape_string($ip_val) . "' WHERE id = $sid");
        }
        $ip_conn->close();
    }
    $_SESSION['conn_msg'] = 'Store IPs saved!';
    header("Location: /oro-store/admin/connection.php");
    exit;
}

// POST: Save sync config
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_sync_config') {
    $new_device_id = trim($_POST['device_id'] ?? '');
    $new_remote_ip = trim($_POST['remote_ip'] ?? '');
    $new_sync_pass = trim($_POST['sync_password'] ?? '');

    $assign_store = intval($_POST['assign_store'] ?? 0);

    if ($new_device_id && $new_sync_pass) {
        // Auto-detect correct REMOTE_IP: branch devices sync with Device A, Device A uses what's set
        if ($new_device_id !== 'DEVICE_A' && empty($new_remote_ip)) {
            // Look up Device A's IP from stores table
            require_once __DIR__ . '/../core/db_config.php';
            $ip_conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if (!$ip_conn->connect_error) {
                $r = $ip_conn->query("SELECT device_ip FROM stores WHERE device_id = 'DEVICE_A' AND device_ip IS NOT NULL LIMIT 1");
                if ($r && $row = $r->fetch_assoc()) $new_remote_ip = $row['device_ip'];
                $ip_conn->close();
            }
        }

        // Update config.php
        $config_path = __DIR__ . '/../sync/config.php';
        $config = file_get_contents($config_path);
        $config = preg_replace("/define\('LOCAL_DEVICE_ID',\s*'[^']*'\)/", "define('LOCAL_DEVICE_ID', '$new_device_id')", $config);
        if ($new_remote_ip) {
            $config = preg_replace("/define\('REMOTE_IP',\s*'[^']*'\)/", "define('REMOTE_IP', '$new_remote_ip')", $config);
        }
        $config = preg_replace("/define\('SYNC_PASSWORD',\s*'[^']*'\)/", "define('SYNC_PASSWORD', '$new_sync_pass')", $config);
        file_put_contents($config_path, $config);

        // Reassign store to this device
        if ($assign_store > 0) {
            require_once __DIR__ . '/../core/db_config.php';
            $cfg_conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if (!$cfg_conn->connect_error) {
                // Clear old assignment for this device ID (keep IP)
                $cfg_conn->query("UPDATE stores SET device_id = NULL WHERE device_id = '" . $cfg_conn->real_escape_string($new_device_id) . "'");
                // Assign new store (keep existing IP)
                $cfg_conn->query("UPDATE stores SET device_id = '" . $cfg_conn->real_escape_string($new_device_id) . "' WHERE id = $assign_store");
                $cfg_conn->close();
            }
        }

        // Handle lock file
        $lock_file = __DIR__ . '/../sync/.main_server';
        if ($new_device_id === 'DEVICE_A') {
            if (!file_exists($lock_file)) file_put_contents($lock_file, 'Main server lock');
        } else {
            if (file_exists($lock_file)) unlink($lock_file);
        }

        // Clear ZeroTier IP cache
        $zt_cache = sys_get_temp_dir() . '/oro_zt_ip.txt';
        if (file_exists($zt_cache)) unlink($zt_cache);

        $_SESSION['conn_msg'] = 'Settings saved! This device is now ' . $new_device_id;
    }
    header("Location: /oro-store/admin/connection.php");
    exit;
}

// Get server info
$server_ip = $_SERVER['SERVER_ADDR'] ?? 'Unknown';
$local_ips = [];
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $output = shell_exec('ipconfig');
    preg_match_all('/IPv4 Address[.\s]*:\s*(\d+\.\d+\.\d+\.\d+)/', $output, $matches);
    $local_ips = $matches[1] ?? [];
} else {
    $output = shell_exec("hostname -I");
    $local_ips = $output ? explode(' ', trim($output)) : [];
}

// Detect Tailscale IP
$tailscale_ip = null;
$tailscale_status = 'Not installed';
$ts_output = shell_exec('tailscale ip -4 2>nul');
if ($ts_output && trim($ts_output)) {
    $tailscale_ip = trim($ts_output);
    $tailscale_status = 'Connected';
} else {
    $ts_output = shell_exec('tailscale status 2>nul');
    if ($ts_output) $tailscale_status = 'Installed but not connected';
}

// Detect ZeroTier IP
$zerotier_ip = null;
$zerotier_status = 'Not installed';
$zt_output = shell_exec('powershell -Command "Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.InterfaceAlias -like \'ZeroTier*\' } | Select-Object -ExpandProperty IPAddress" 2>nul');
if ($zt_output && trim($zt_output)) {
    $zerotier_ip = trim($zt_output);
    $zerotier_status = 'Connected';
} else {
    if (is_dir('C:\Program Files (x86)\ZeroTier') || is_dir('C:\Program Files\ZeroTier')) $zerotier_status = 'Installed but not connected';
}

$server_port = $_SERVER['SERVER_PORT'] ?? '80';
$php_version = phpversion();
$mysql_version = $conn->server_info;
$uptime = shell_exec(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'net stats srv 2>nul | findstr "since"' : 'uptime -s');

// Connected clients (active sessions)
$active_sessions = $conn->query("SELECT us.*, u.username, u.full_name, u.role, s.store_name
    FROM user_sessions us
    LEFT JOIN users u ON us.user_id = u.id
    LEFT JOIN stores s ON u.store_id = s.id
    WHERE us.status = 'active' AND us.login_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY us.login_time DESC")->fetch_all(MYSQLI_ASSOC);

// Pre-fetch unassigned stores for the dropdown
$_unassigned_stores = [];
$__ua_r = $conn->query("SELECT id, store_name FROM stores WHERE (device_id IS NULL OR device_id = '') AND status = 'active'");
if ($__ua_r) $_unassigned_stores = $__ua_r->fetch_all(MYSQLI_ASSOC);

// Pre-fetch Device A's IP for the banner
$_device_a_ip = '';
$_da_r = $conn->query("SELECT device_ip FROM stores WHERE device_id = 'DEVICE_A' AND device_ip IS NOT NULL LIMIT 1");
if ($_da_r && $_da_row = $_da_r->fetch_assoc()) $_device_a_ip = $_da_row['device_ip'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Connection Manager - Oro Store</title>
    <link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
    <?php include_once __DIR__ . '/../core/pwa.php'; ?>
    <style>
        .conn-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px; }
        .conn-card { background:#fff; border-radius:12px; padding:18px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .conn-card h3 { font-size:14px; font-weight:700; color:#1e293b; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid #e2e8f0; }
        .conn-row { display:flex; justify-content:space-between; padding:6px 0; font-size:13px; border-bottom:1px solid #f1f5f9; }
        .conn-row:last-child { border-bottom:none; }
        .conn-row .label { color:#64748b; }
        .conn-row .val { font-weight:600; color:#1e293b; font-family:'Courier New',monospace; }
        .conn-url { display:block; background:#0f172a; color:#22c55e; padding:12px 16px; border-radius:8px; font-family:'Courier New',monospace; font-size:14px; margin:8px 0; word-break:break-all; }
        .conn-url .copy-btn { float:right; background:#334155; color:#fff; border:none; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:11px; }
        .conn-url .copy-btn:hover { background:#475569; }
        .status-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:6px; }
        .status-dot.online { background:#22c55e; }
        .status-dot.offline { background:#94a3b8; }
        .step-list { list-style:none; padding:0; counter-reset:steps; }
        .step-list li { padding:10px 12px; margin-bottom:8px; background:#f8fafc; border-radius:8px; font-size:13px; counter-increment:steps; display:flex; gap:10px; align-items:flex-start; }
        .step-list li::before { content:counter(steps); background:#6366f1; color:#fff; width:24px; height:24px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:700; flex-shrink:0; }
        .security-item { padding:10px 12px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; margin-bottom:8px; font-size:13px; }
        .security-item.warn { background:#fef3c7; border-color:#fde68a; }
        .security-item.danger { background:#fee2e2; border-color:#fecaca; }
        .test-btn { padding:8px 16px; background:#6366f1; color:#fff; border:none; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; }
        .test-btn:hover { background:#4f46e5; }
        .test-result { margin-top:8px; padding:8px 12px; border-radius:6px; font-size:12px; display:none; }
        .test-result.ok { background:#dcfce7; color:#166534; display:block; }
        .test-result.fail { background:#fee2e2; color:#991b1b; display:block; }
        @media (max-width:768px) { .conn-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>
<main class="main-content">

<?php
$_dev_letter = substr(LOCAL_DEVICE_ID, -1);
$_dev_num = ord($_dev_letter) - 64;
$_dev_styles = [
    'A'=>['color'=>'#6366f1','bg'=>'#eef2ff','border'=>'#c7d2fe'],
    'B'=>['color'=>'#f59e0b','bg'=>'#fffbeb','border'=>'#fde68a'],
    'C'=>['color'=>'#16a34a','bg'=>'#f0fdf4','border'=>'#bbf7d0'],
    'D'=>['color'=>'#dc2626','bg'=>'#fef2f2','border'=>'#fecaca'],
    'E'=>['color'=>'#8b5cf6','bg'=>'#f5f3ff','border'=>'#ddd6fe'],
    'F'=>['color'=>'#0891b2','bg'=>'#ecfeff','border'=>'#a5f3fc'],
    'G'=>['color'=>'#d946ef','bg'=>'#fdf4ff','border'=>'#f0abfc'],
    'H'=>['color'=>'#ea580c','bg'=>'#fff7ed','border'=>'#fed7aa'],
    'I'=>['color'=>'#4f46e5','bg'=>'#eef2ff','border'=>'#c7d2fe'],
    'J'=>['color'=>'#059669','bg'=>'#ecfdf5','border'=>'#a7f3d0'],
];
$_s = $_dev_styles[$_dev_letter] ?? $_dev_styles['A'];
$device_color = $_s['color'];
$device_bg = $_s['bg'];
$device_border = $_s['border'];
$device_label = "Store $_dev_letter" . ($_dev_num === 1 ? ' (Main)' : ' (Branch ' . ($_dev_num - 1) . ')');
$device_icon = $_dev_letter;
?>
<div class="page-header">
    <h1>Connection Manager</h1>
    <p>Manage device connections, network access, and security</p>
</div>

<div style="display:flex;align-items:center;gap:14px;padding:16px 20px;background:<?php echo $device_bg; ?>;border:2px solid <?php echo $device_border; ?>;border-radius:12px;margin-bottom:20px;">
    <div style="width:52px;height:52px;border-radius:12px;background:<?php echo $device_color; ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:900;flex-shrink:0;"><?php echo $device_icon; ?></div>
    <div style="flex:1;">
        <div style="font-size:18px;font-weight:800;color:#0f172a;"><?php echo $device_label; ?></div>
        <div style="font-size:12px;color:#64748b;margin-top:2px;">
            Device ID: <strong style="color:<?php echo $device_color; ?>;"><?php echo LOCAL_DEVICE_ID; ?></strong>
            <?php if (LOCAL_DEVICE_ID !== 'DEVICE_A'): ?>
                &nbsp;&bull;&nbsp; Syncs with Device A: <strong style="font-family:monospace;"><?php echo $_device_a_ip ?: 'Not set'; ?></strong>
            <?php endif; ?>
            <?php if ($zerotier_ip): ?>
                &nbsp;&bull;&nbsp; ZeroTier: <strong style="font-family:monospace;"><?php echo $zerotier_ip; ?></strong>
            <?php endif; ?>
        </div>
    </div>
    <div style="text-align:right;">
        <div style="display:inline-block;padding:6px 14px;border-radius:20px;font-size:11px;font-weight:700;background:<?php echo $device_color; ?>;color:#fff;">
            <?php echo $_dev_num === 1 ? 'PRIMARY SERVER' : 'BRANCH SERVER ' . ($_dev_num - 1); ?>
        </div>
    </div>
</div>

<!-- Server Info -->
<div class="conn-grid">
    <div class="conn-card">
        <h3>Server Information</h3>
        <div class="conn-row"><span class="label">Status</span><span class="val"><span class="status-dot online"></span>Running</span></div>
        <div class="conn-row"><span class="label">PHP Version</span><span class="val"><?php echo $php_version; ?></span></div>
        <div class="conn-row"><span class="label">MySQL Version</span><span class="val"><?php echo $mysql_version; ?></span></div>
        <div class="conn-row"><span class="label">Port</span><span class="val"><?php echo $server_port; ?></span></div>
        <div class="conn-row"><span class="label">OS</span><span class="val"><?php echo PHP_OS; ?></span></div>
        <?php foreach ($local_ips as $ip): ?>
        <div class="conn-row"><span class="label">IP Address</span><span class="val"><?php echo $ip; ?></span></div>
        <?php endforeach; ?>
    </div>

    <div class="conn-card">
        <h3>Access URLs</h3>
        <p style="font-size:12px;color:#64748b;margin-bottom:8px;">Share these with devices on your network:</p>
        <?php foreach ($local_ips as $ip): ?>
        <div class="conn-url">
            <button class="copy-btn" onclick="copyUrl('http://<?php echo $ip; ?>/oro-store/')">Copy</button>
            http://<?php echo $ip; ?>/oro-store/
        </div>
        <?php endforeach; ?>
        <div class="conn-url" style="background:#1e293b;">
            <button class="copy-btn" onclick="copyUrl('http://localhost/oro-store/')">Copy</button>
            http://localhost/oro-store/ <span style="color:#94a3b8;font-size:11px;">(this PC only)</span>
        </div>

        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
            <button class="test-btn" onclick="testConnection()">Test Connection</button>
            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="fix_firewall"><button type="submit" class="test-btn" style="background:#f59e0b;">Fix Firewall (Allow Port 80)</button></form>
            <div style="display:flex;gap:4px;align-items:center;">
                <input type="text" id="ping-target" placeholder="IP to ping..." style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;width:130px;font-family:monospace;">
                <button class="test-btn" style="background:#475569;" onclick="pingDevice()">Ping</button>
            </div>
        </div>
        <?php if (isset($_SESSION['conn_msg'])): ?>
        <div class="test-result ok" style="display:block;margin-top:8px;"><?php echo htmlspecialchars($_SESSION['conn_msg']); unset($_SESSION['conn_msg']); ?></div>
        <?php endif; ?>
        <div id="test-result" class="test-result"></div>
    </div>
</div>

<!-- Tailscale -->
<div class="conn-card" style="margin-bottom:20px;border-left:4px solid <?php echo $tailscale_ip ? '#22c55e' : '#94a3b8'; ?>;">
    <h3>Tailscale (Remote Access)</h3>
    <div class="conn-row">
        <span class="label">Status</span>
        <span class="val"><span class="status-dot <?php echo $tailscale_ip ? 'online' : 'offline'; ?>"></span><?php echo $tailscale_status; ?></span>
    </div>
    <?php if ($tailscale_ip): ?>
    <div class="conn-row"><span class="label">Tailscale IP</span><span class="val"><?php echo $tailscale_ip; ?></span></div>
    <div class="conn-url" style="background:#0f2a1a;color:#22c55e;">
        <button class="copy-btn" onclick="copyUrl('http://<?php echo $tailscale_ip; ?>/oro-store/')">Copy</button>
        http://<?php echo $tailscale_ip; ?>/oro-store/ <span style="color:#4ade80;font-size:11px;">(encrypted remote access)</span>
    </div>
    <div style="font-size:11px;color:#16a34a;margin-top:4px;">
        ✓ Any device with Tailscale on your account can access this URL from anywhere in the world. Traffic is encrypted end-to-end.
    </div>
    <?php else: ?>
    <div style="padding:12px;background:#f8fafc;border-radius:8px;margin-top:8px;font-size:12px;color:#64748b;">
        Tailscale is not connected. Install it from <a href="https://tailscale.com/download" target="_blank" style="color:#6366f1;">tailscale.com/download</a> and login to enable remote access.
    </div>
    <?php endif; ?>
</div>

<!-- ZeroTier -->
<div class="conn-card" style="margin-bottom:20px;border-left:4px solid <?php echo $zerotier_ip ? '#f59e0b' : '#94a3b8'; ?>;">
    <h3>ZeroTier (Remote Access)</h3>
    <div class="conn-row">
        <span class="label">Status</span>
        <span class="val"><span class="status-dot <?php echo $zerotier_ip ? 'online' : 'offline'; ?>"></span><?php echo $zerotier_status; ?></span>
    </div>
    <?php if ($zerotier_ip): ?>
    <div class="conn-row"><span class="label">ZeroTier IP</span><span class="val"><?php echo $zerotier_ip; ?></span></div>
    <div class="conn-url" style="background:#1a1a0f;color:#f59e0b;">
        <button class="copy-btn" onclick="copyUrl('http://<?php echo $zerotier_ip; ?>/oro-store/')">Copy</button>
        http://<?php echo $zerotier_ip; ?>/oro-store/ <span style="color:#fbbf24;font-size:11px;">(encrypted remote access)</span>
    </div>
    <div style="font-size:11px;color:#f59e0b;margin-top:4px;">
        ✓ Any device joined to your ZeroTier network can access this URL. 25 devices free. Traffic is encrypted.
    </div>
    <?php else: ?>
    <div style="padding:12px;background:#f8fafc;border-radius:8px;margin-top:8px;font-size:12px;color:#64748b;">
        ZeroTier is not connected. Install from <a href="https://www.zerotier.com/download/" target="_blank" style="color:#6366f1;">zerotier.com/download</a>, create a network, and join it.
    </div>
    <?php endif; ?>
</div>

<!-- How to Connect -->
<div class="conn-grid">
    <div class="conn-card">
        <h3>Connect Devices (Same WiFi)</h3>
        <ol class="step-list">
            <li>Make sure both devices are on the <strong>same WiFi network</strong></li>
            <li>On the other device, open a browser (Chrome recommended)</li>
            <li>Type the Access URL above into the address bar</li>
            <li>Login with your username and password</li>
            <li>For tablet/phone: tap "Add to Home Screen" for app-like experience</li>
        </ol>
    </div>

    <div class="conn-card">
        <h3>Connect Remotely (Different Location)</h3>
        <ol class="step-list">
            <li>Install <strong>Tailscale</strong> (free) on this PC: <a href="https://tailscale.com/download" target="_blank" style="color:#6366f1;">tailscale.com/download</a></li>
            <li>Install Tailscale on your phone/laptop too</li>
            <li>Login with the same Google/Microsoft account on both</li>
            <li>Tailscale gives this PC an IP like <code style="background:#f1f5f9;padding:2px 6px;border-radius:3px;">100.x.x.x</code></li>
            <li>On the remote device, open: <code style="background:#f1f5f9;padding:2px 6px;border-radius:3px;">http://100.x.x.x/oro-store/</code></li>
        </ol>
        <div style="margin-top:8px;padding:8px;background:#eff6ff;border-radius:6px;font-size:11px;color:#1e40af;">
            Tailscale creates an encrypted tunnel — your data never touches the public internet. It's the safest way to connect remotely without exposing your server.
        </div>
    </div>
</div>

<!-- Sync Controls -->
<?php
$sync_status = $conn2 = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$last_sync = $conn2->query("SELECT * FROM sync_status ORDER BY id DESC LIMIT 1")->fetch_assoc();
$pending_sync = $conn2->query("SELECT COUNT(*) as c FROM sync_log WHERE synced = 0")->fetch_assoc()['c'];
$_taken_devices = [];
$_td_r = $conn2->query("SELECT device_id FROM stores WHERE device_id IS NOT NULL AND status = 'active'");
if ($_td_r) { while ($row = $_td_r->fetch_assoc()) $_taken_devices[] = $row['device_id']; }
$_all_store_devs = [];
$_sd_r = @$conn2->query("SELECT id as store_id, store_name, device_id, device_ip FROM stores WHERE device_id IS NOT NULL AND status = 'active' ORDER BY device_id");
if (!$_sd_r) $_sd_r = @$conn2->query("SELECT id as store_id, store_name, device_id, NULL as device_ip FROM stores WHERE device_id IS NOT NULL AND status = 'active' ORDER BY device_id");
if ($_sd_r) { while ($row = $_sd_r->fetch_assoc()) $_all_store_devs[] = $row; }
$conn2->close();
?>
<div class="conn-card" style="margin-bottom:20px;border-left:4px solid #f59e0b;">
    <h3>Store-to-Store Sync</h3>
    <div class="conn-row"><span class="label">This Device</span><span class="val"><?php echo LOCAL_DEVICE_ID; ?></span></div>
    <?php if (!empty($_all_store_devs)): ?>
    <?php foreach ($_all_store_devs as $_sd):
        $_is_self = $_sd['device_id'] === LOCAL_DEVICE_ID;
        $_sd_l = substr($_sd['device_id'], -1);
        $_sd_c = ['A'=>'#6366f1','B'=>'#f59e0b','C'=>'#16a34a','D'=>'#dc2626','E'=>'#8b5cf6'][$_sd_l] ?? '#64748b';
    ?>
    <div class="conn-row">
        <span class="label">
            <span style="display:inline-block;width:18px;height:18px;border-radius:4px;background:<?php echo $_sd_c; ?>;color:#fff;text-align:center;font-size:10px;font-weight:700;line-height:18px;margin-right:4px;"><?php echo $_sd_l; ?></span>
            <?php echo htmlspecialchars($_sd['store_name']); ?>
            <?php echo $_is_self ? ' (this)' : ''; ?>
        </span>
        <span class="val"><?php echo $_sd['device_ip'] ? htmlspecialchars($_sd['device_ip']) : '<span style="color:#94a3b8;font-weight:400;">No IP set</span>'; ?></span>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <div class="conn-row"><span class="label">Remote Store IP</span><span class="val"><?php echo REMOTE_IP; ?></span></div>
    <?php endif; ?>
    <div class="conn-row"><span class="label">Pending Changes</span><span class="val" style="color:<?php echo $pending_sync > 0 ? '#f59e0b' : '#16a34a'; ?>;"><?php echo $pending_sync; ?></span></div>
    <?php if ($last_sync): ?>
    <div class="conn-row"><span class="label">Last Sync</span><span class="val"><?php echo date('M j, g:i A', strtotime($last_sync['last_sync_time'])); ?></span></div>
    <div class="conn-row"><span class="label">Last Status</span><span class="val" style="color:<?php echo $last_sync['status'] === 'SUCCESS' ? '#16a34a' : '#dc2626'; ?>;"><?php echo $last_sync['status']; ?></span></div>
    <div class="conn-row"><span class="label">Last Result</span><span class="val">Pushed: <?php echo $last_sync['records_pushed']; ?> | Pulled: <?php echo $last_sync['records_pulled']; ?></span></div>
    <?php if ($last_sync['error_message']): ?>
    <div style="margin-top:6px;padding:6px 10px;background:#fee2e2;border-radius:6px;font-size:11px;color:#991b1b;"><?php echo htmlspecialchars($last_sync['error_message']); ?></div>
    <?php endif; ?>
    <?php else: ?>
    <div style="margin-top:6px;padding:6px 10px;background:#f8fafc;border-radius:6px;font-size:11px;color:#64748b;">No sync has been performed yet</div>
    <?php endif; ?>

    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;align-items:center;">
        <button class="test-btn" onclick="runSync()" id="sync-btn">Sync Now</button>
        <button class="test-btn" style="background:#f59e0b;" onclick="checkRemote()">Check Remote</button>
        <button class="test-btn" style="background:#dc2626;" onclick="runDiagnose()" id="diag-btn">Diagnose</button>
        <label style="font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;">
            <input type="checkbox" id="auto-sync-toggle" onchange="toggleAutoSync()" style="width:16px;height:16px;">
            Auto-sync every 5 min
        </label>
        <span id="auto-sync-status" style="font-size:11px;color:#94a3b8;"></span>
    </div>
    <div id="sync-result" class="test-result"></div>
    <div id="diag-result" style="display:none;margin-top:10px;"></div>

    <!-- Sync Settings -->
    <div style="margin-top:14px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
        <h4 style="font-size:12px;color:#1e293b;margin-bottom:8px;">Device Config</h4>
        <form method="POST">
            <input type="hidden" name="action" value="save_sync_config">
            <input type="hidden" name="remote_ip" value="<?php echo REMOTE_IP; ?>">
            <div style="display:flex;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
                <div style="flex:1;min-width:120px;">
                    <label style="font-size:10px;font-weight:600;color:#64748b;display:block;margin-bottom:2px;">Device ID</label>
                    <select name="device_id" style="width:100%;padding:7px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;">
                        <?php for ($i = 0; $i < 10; $i++): $letter = chr(65 + $i); $dev = "DEVICE_$letter";
                            $is_current = LOCAL_DEVICE_ID === $dev;
                            $is_taken = in_array($dev, $_taken_devices);
                            $label = $dev . ' (Store ' . ($i + 1) . ')';
                            if ($is_current) $label .= ' — this device';
                            elseif ($is_taken) $label .= ' — taken';
                        ?>
                        <option value="<?php echo $dev; ?>" <?php echo $is_current ? 'selected' : ''; ?> <?php echo ($is_taken && !$is_current && !isSuperAdmin()) ? 'disabled style="color:#94a3b8;"' : ''; ?>><?php echo $label; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div style="flex:2;min-width:180px;">
                    <label style="font-size:10px;font-weight:600;color:#64748b;display:block;margin-bottom:2px;">This Device's ZeroTier IP</label>
                    <input type="text" value="<?php echo $zerotier_ip ?? 'Not detected'; ?>" readonly style="width:100%;padding:7px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;font-family:monospace;background:#f1f5f9;color:#64748b;">
                </div>
                <div style="flex:2;min-width:180px;">
                    <label style="font-size:10px;font-weight:600;color:#64748b;display:block;margin-bottom:2px;">Sync Password (same on all devices)</label>
                    <input type="text" name="sync_password" value="<?php echo SYNC_PASSWORD; ?>" style="width:100%;padding:7px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;">
                </div>
                <div style="flex:2;min-width:180px;">
                    <label style="font-size:10px;font-weight:600;color:#64748b;display:block;margin-bottom:2px;">Assign Store to This Device</label>
                    <select name="assign_store" style="width:100%;padding:7px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;">
                        <option value="0">— Don't change —</option>
                        <?php foreach ($_all_store_devs as $_as):
                            $_as_current = ($_as['device_id'] === LOCAL_DEVICE_ID);
                        ?>
                        <option value="<?php echo $_as['store_id']; ?>" <?php echo $_as_current ? 'selected' : ''; ?>><?php echo htmlspecialchars($_as['store_name']); ?> (<?php echo $_as['device_id']; ?>)</option>
                        <?php endforeach; ?>
                        <?php foreach ($_unassigned_stores as $__r): ?>
                        <option value="<?php echo $__r['id']; ?>"><?php echo htmlspecialchars($__r['store_name']); ?> (unassigned)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="test-btn" style="font-size:11px;padding:6px 14px;">Save Device Config</button>
        </form>
    </div>

    <!-- Store IPs -->
    <?php if (!empty($_all_store_devs)): ?>
    <div style="margin-top:10px;padding:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
        <h4 style="font-size:12px;color:#166534;margin-bottom:8px;">Store Device IPs (for sync)</h4>
        <form method="POST">
            <input type="hidden" name="action" value="save_store_ips">
            <table style="width:100%;font-size:12px;border-collapse:collapse;">
                <?php
                $shown_devices = [];
                foreach ($_all_store_devs as $_sd):
                    // Skip duplicates
                    if (isset($shown_devices[$_sd['device_id']])) continue;
                    $shown_devices[$_sd['device_id']] = true;

                    $_is_self = $_sd['device_id'] === LOCAL_DEVICE_ID;
                    $_sd_l = substr($_sd['device_id'], -1);
                    $_sd_c = ['A'=>'#6366f1','B'=>'#f59e0b','C'=>'#16a34a','D'=>'#dc2626','E'=>'#8b5cf6'][$_sd_l] ?? '#64748b';

                    // For other stores, default IP from REMOTE_IP config if empty
                    $_sd_ip = $_sd['device_ip'] ?? '';
                    if (!$_is_self && empty($_sd_ip) && defined('REMOTE_IP')) {
                        $_sd_ip = REMOTE_IP;
                    }
                ?>
                <tr style="border-bottom:1px solid #dcfce7;">
                    <td style="padding:6px 4px;width:30px;">
                        <span style="display:inline-block;width:22px;height:22px;border-radius:5px;background:<?php echo $_sd_c; ?>;color:#fff;text-align:center;font-size:11px;font-weight:700;line-height:22px;"><?php echo $_sd_l; ?></span>
                    </td>
                    <td style="padding:6px 4px;font-weight:600;"><?php echo htmlspecialchars($_sd['store_name']); ?><?php echo $_is_self ? ' <span style="color:#16a34a;font-size:10px;">(this device)</span>' : ''; ?></td>
                    <td style="padding:6px 4px;">
                        <?php if ($_is_self): ?>
                            <input type="text" value="<?php echo htmlspecialchars($_sd_ip); ?>" readonly style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-family:monospace;font-size:12px;background:#f1f5f9;color:#64748b;">
                            <input type="hidden" name="store_ip[<?php echo $_sd['store_id']; ?>]" value="<?php echo htmlspecialchars($_sd_ip); ?>">
                        <?php else: ?>
                            <input type="text" name="store_ip[<?php echo $_sd['store_id']; ?>]" value="<?php echo htmlspecialchars($_sd_ip); ?>" placeholder="Enter ZeroTier IP" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:5px;font-family:monospace;font-size:12px;">
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <button type="submit" class="test-btn" style="font-size:11px;padding:6px 14px;margin-top:8px;background:#16a34a;">Save IPs</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- One-Click Setup -->
    <div style="margin-top:14px;padding:14px;background:#eff6ff;border:2px solid #bfdbfe;border-radius:8px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;">
                <h4 style="font-size:13px;color:#1e40af;margin-bottom:4px;">Setup This Device for Sync</h4>
                <p style="font-size:11px;color:#3b82f6;margin:0;">Opens firewall (port 80 + ping + MySQL), creates sync tables. Run this once on each new device.</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <form method="POST">
                    <input type="hidden" name="action" value="setup_sync">
                    <button type="submit" class="test-btn" style="background:#2563eb;font-size:13px;padding:10px 24px;white-space:nowrap;" onclick="this.textContent='Setting up...';this.disabled=true;this.form.submit();">Setup Device</button>
                </form>
                <a href="/oro-store/sync/setup_firewall.bat" download class="test-btn" style="background:#dc2626;font-size:13px;padding:10px 24px;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;">Download Firewall Fix</a>
                <button class="test-btn" style="background:#7c3aed;font-size:13px;padding:10px 24px;white-space:nowrap;" onclick="installTriggers(this)" id="trigger-btn">Install Sync Triggers</button>
            </div>
        </div>
        <div id="trigger-result" style="margin-top:8px;font-size:12px;display:none;"></div>
    </div>

    <!-- Nightly Cleanup (branch devices only) -->
    <?php if (LOCAL_DEVICE_ID !== 'DEVICE_A'): ?>
    <div style="margin-top:14px;padding:14px;background:#fef2f2;border:2px solid #fecaca;border-radius:8px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
            <div style="flex:1;">
                <h4 style="font-size:13px;color:#991b1b;margin-bottom:4px;">Nightly Data Cleanup</h4>
                <p style="font-size:11px;color:#dc2626;margin:0;">At midnight, syncs all data to Device A then wipes completed transactions older than today. Keeps current stock and today's data. Reduces data leakage risk on branch devices.</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <button class="test-btn" style="background:#dc2626;font-size:13px;padding:10px 24px;white-space:nowrap;" onclick="runCleanup(this)" id="cleanup-btn">Run Cleanup Now</button>
                <a href="/oro-store/sync/setup_nightly_cleanup.bat" download class="test-btn" style="background:#7c3aed;font-size:13px;padding:10px 24px;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;">Schedule at Midnight</a>
            </div>
        </div>
        <div id="cleanup-result" style="margin-top:8px;font-size:12px;display:none;"></div>
    </div>
    <?php endif; ?>

    <!-- Remote Wipe (Device A super admin only) -->
    <?php if (LOCAL_DEVICE_ID === 'DEVICE_A' && isSuperAdmin()): ?>
    <div style="margin-top:14px;padding:14px;background:#1e293b;border:2px solid #334155;border-radius:8px;">
        <h4 style="font-size:13px;color:#f1f5f9;margin-bottom:4px;">Remote Branch Wipe</h4>
        <p style="font-size:11px;color:#94a3b8;margin:0 0 10px;">Remotely wipe old transaction data from branch devices. This syncs their data first, then clears completed transactions older than today.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;" id="remote-wipe-row">
            <input type="text" id="wipe-target-ip" value="<?php echo REMOTE_IP; ?>" placeholder="Branch device IP" style="padding:8px 12px;border:1px solid #475569;background:#0f172a;color:#e2e8f0;border-radius:6px;font-size:12px;font-family:monospace;width:160px;">
            <button class="test-btn" style="background:#dc2626;font-size:12px;padding:8px 18px;" onclick="remoteWipe()">Wipe Branch</button>
        </div>
        <div id="remote-wipe-result" style="margin-top:8px;font-size:12px;display:none;"></div>
    </div>
    <?php endif; ?>
</div>

<!-- Sync Tools (Super Admin) -->
<?php if (isSuperAdmin()): ?>
<?php
$_st_conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$_sync_log_count = 0; $_sync_log_unsynced = 0; $_sync_tables_count = 0; $_last_5_syncs = [];
if (!$_st_conn->connect_error) {
    $r = $_st_conn->query("SELECT COUNT(*) as c FROM sync_log"); if ($r) $_sync_log_count = $r->fetch_assoc()['c'];
    $r = $_st_conn->query("SELECT COUNT(*) as c FROM sync_log WHERE synced = 0"); if ($r) $_sync_log_unsynced = $r->fetch_assoc()['c'];
    $r = $_st_conn->query("SELECT COUNT(DISTINCT table_name) as c FROM sync_log WHERE synced = 0"); if ($r) $_sync_tables_count = $r->fetch_assoc()['c'];
    $r = $_st_conn->query("SELECT last_sync_time, status, records_pushed, records_pulled, error_message FROM sync_status ORDER BY id DESC LIMIT 5");
    if ($r) $_last_5_syncs = $r->fetch_all(MYSQLI_ASSOC);
    $_st_conn->close();
}
?>
<div class="conn-card" style="margin-bottom:20px;border-left:4px solid #6366f1;">
    <h3>Sync Tools</h3>

    <!-- Sync Log Stats -->
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;">
        <div style="background:#f8fafc;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:20px;font-weight:800;color:#1e293b;"><?php echo number_format($_sync_log_count); ?></div>
            <div style="font-size:10px;color:#64748b;">Total Sync Entries</div>
        </div>
        <div style="background:<?php echo $_sync_log_unsynced > 0 ? '#fffbeb' : '#f0fdf4'; ?>;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:20px;font-weight:800;color:<?php echo $_sync_log_unsynced > 0 ? '#d97706' : '#16a34a'; ?>;"><?php echo $_sync_log_unsynced; ?></div>
            <div style="font-size:10px;color:#64748b;">Pending Changes</div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:20px;font-weight:800;color:#1e293b;"><?php echo $_sync_tables_count; ?></div>
            <div style="font-size:10px;color:#64748b;">Tables Affected</div>
        </div>
    </div>

    <!-- Recent Sync History -->
    <?php if (!empty($_last_5_syncs)): ?>
    <div style="margin-bottom:14px;">
        <div style="font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;">Recent Syncs</div>
        <table style="width:100%;border-collapse:collapse;font-size:11px;">
            <thead><tr style="background:#f8fafc;">
                <th style="padding:4px 8px;text-align:left;">Time</th>
                <th style="padding:4px 8px;text-align:center;">Pushed</th>
                <th style="padding:4px 8px;text-align:center;">Pulled</th>
                <th style="padding:4px 8px;text-align:center;">Status</th>
                <th style="padding:4px 8px;text-align:left;">Error</th>
            </tr></thead>
            <tbody>
            <?php foreach ($_last_5_syncs as $s): ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:4px 8px;"><?php echo $s['last_sync_time'] ? date('M j, g:i A', strtotime($s['last_sync_time'])) : '—'; ?></td>
                <td style="padding:4px 8px;text-align:center;"><?php echo $s['records_pushed']; ?></td>
                <td style="padding:4px 8px;text-align:center;"><?php echo $s['records_pulled']; ?></td>
                <td style="padding:4px 8px;text-align:center;"><span style="color:<?php echo $s['status']==='SUCCESS' ? '#16a34a' : '#dc2626'; ?>;font-weight:700;"><?php echo $s['status']; ?></span></td>
                <td style="padding:4px 8px;color:#94a3b8;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($s['error_message'] ?? '—'); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="test-btn" style="background:#6366f1;font-size:11px;padding:7px 14px;" onclick="syncToolAction('force_sync')">Force Sync</button>
        <button class="test-btn" style="background:#f59e0b;font-size:11px;padding:7px 14px;" onclick="syncToolAction('clear_synced')">Clear Synced Log</button>
        <button class="test-btn" style="background:#0891b2;font-size:11px;padding:7px 14px;" onclick="syncToolAction('remote_status')">Check Remote Status</button>
        <button class="test-btn" style="background:#16a34a;font-size:11px;padding:7px 14px;" onclick="syncToolAction('backup_db')">Backup Database</button>
        <button class="test-btn" style="background:#8b5cf6;font-size:11px;padding:7px 14px;" onclick="syncToolAction('view_pending')">View Pending</button>
    </div>
    <div id="sync-tool-result" style="margin-top:10px;display:none;"></div>
</div>
<?php endif; ?>

<!-- Security -->
<div class="conn-card" style="margin-bottom:20px;">
    <h3>Security Status</h3>
    <div class="security-item">
        <strong>✓ Local Network Only</strong> — Your server is not exposed to the internet by default. Only devices on your network can access it.
    </div>
    <div class="security-item">
        <strong>✓ Password Protected</strong> — All pages require login. Unauthorized users can't see any data.
    </div>
    <div class="security-item">
        <strong>✓ Super Admin Lock</strong> — Balance adjustments and stock removal require super admin password verification.
    </div>
    <div class="security-item <?php echo empty($_SERVER['HTTPS']) ? 'warn' : ''; ?>">
        <strong><?php echo empty($_SERVER['HTTPS']) ? '⚠' : '✓'; ?> HTTPS</strong> —
        <?php if (empty($_SERVER['HTTPS'])): ?>
            Not enabled. For local use this is fine. If using Tailscale, traffic is encrypted anyway.
        <?php else: ?>
            Enabled. All data is encrypted in transit.
        <?php endif; ?>
    </div>
    <div class="security-item">
        <strong>✓ Role-Based Access</strong> — Cashiers can only use the cashier. Managers see their store only. Admins see everything.
    </div>

    <h3 style="margin-top:16px;">Security Tips</h3>
    <div class="security-item warn">
        <strong>Change default password</strong> — If you're still using "admin123", change it immediately from User Management.
    </div>
    <div class="security-item">
        <strong>Don't use port forwarding</strong> — Never expose port 80/443 on your router. Use Tailscale instead for remote access.
    </div>
    <div class="security-item">
        <strong>Regular backups</strong> — Export your database regularly. Go to phpMyAdmin → Export → product_db.
    </div>
    <div class="security-item">
        <strong>Keep XAMPP updated</strong> — Update Apache and PHP when new versions are available.
    </div>
</div>

<!-- Active Sessions -->
<div class="conn-card" style="margin-bottom:20px;">
    <h3>Active Sessions (Last 24 Hours)</h3>
    <?php if (empty($active_sessions)): ?>
        <p style="color:#94a3b8;font-size:13px;text-align:center;padding:16px;">No active sessions</p>
    <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
            <th style="padding:8px 10px;text-align:left;">User</th>
            <th style="padding:8px 10px;text-align:left;">Role</th>
            <th style="padding:8px 10px;text-align:left;">Store</th>
            <th style="padding:8px 10px;text-align:left;">IP Address</th>
            <th style="padding:8px 10px;text-align:left;">Login Time</th>
        </tr></thead>
        <tbody>
        <?php foreach ($active_sessions as $sess): ?>
        <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="padding:6px 10px;font-weight:600;"><?php echo htmlspecialchars($sess['full_name'] ?? $sess['username'] ?? '—'); ?></td>
            <td style="padding:6px 10px;"><span style="background:#ede9fe;color:#7c3aed;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;"><?php echo strtoupper($sess['role'] ?? '—'); ?></span></td>
            <td style="padding:6px 10px;color:#64748b;"><?php echo htmlspecialchars($sess['store_name'] ?? 'All'); ?></td>
            <td style="padding:6px 10px;font-family:monospace;font-size:11px;"><?php echo htmlspecialchars($sess['ip_address'] ?? '—'); ?></td>
            <td style="padding:6px 10px;font-size:11px;color:#64748b;"><?php echo $sess['login_time'] ? date('M j, g:i A', strtotime($sess['login_time'])) : '—'; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Troubleshooting -->
<div class="conn-card">
    <h3>Troubleshooting</h3>
    <details style="margin-bottom:8px;">
        <summary style="cursor:pointer;font-weight:600;font-size:13px;padding:6px 0;">Can't connect from another device</summary>
        <div style="padding:8px 12px;font-size:12px;color:#475569;">
            1. Make sure both devices are on the same WiFi<br>
            2. Check Windows Firewall — allow Apache through: Control Panel → Firewall → Allow an app → Apache HTTP Server<br>
            3. Try disabling firewall temporarily to test<br>
            4. Make sure XAMPP Apache is running
        </div>
    </details>
    <details style="margin-bottom:8px;">
        <summary style="cursor:pointer;font-weight:600;font-size:13px;padding:6px 0;">Page loads but shows "Access Denied"</summary>
        <div style="padding:8px 12px;font-size:12px;color:#475569;">
            Check XAMPP's httpd-xampp.conf — make sure it allows access from your network:<br>
            <code style="background:#f1f5f9;padding:4px 8px;border-radius:4px;display:block;margin:4px 0;">Require all granted</code>
            Or specifically your subnet: <code style="background:#f1f5f9;padding:4px 8px;border-radius:4px;">Require ip 192.168.1</code>
        </div>
    </details>
    <details style="margin-bottom:8px;">
        <summary style="cursor:pointer;font-weight:600;font-size:13px;padding:6px 0;">Very slow from remote device</summary>
        <div style="padding:8px 12px;font-size:12px;color:#475569;">
            1. Check WiFi signal strength on both devices<br>
            2. Use ethernet cable for the server PC if possible<br>
            3. Close other heavy applications on the server PC<br>
            4. Check if the server PC is sleeping/hibernating
        </div>
    </details>
    <details style="margin-bottom:8px;">
        <summary style="cursor:pointer;font-weight:600;font-size:13px;padding:6px 0;">Tailscale not connecting</summary>
        <div style="padding:8px 12px;font-size:12px;color:#475569;">
            1. Make sure Tailscale is running on BOTH devices (check system tray)<br>
            2. Both must be logged in with the same account<br>
            3. Try disconnecting and reconnecting Tailscale<br>
            4. Check Tailscale admin console at <a href="https://login.tailscale.com/admin" target="_blank" style="color:#6366f1;">login.tailscale.com/admin</a>
        </div>
    </details>
    <details>
        <summary style="cursor:pointer;font-weight:600;font-size:13px;padding:6px 0;">Data not syncing between stores</summary>
        <div style="padding:8px 12px;font-size:12px;color:#475569;">
            With the current setup, both stores connect to the SAME server PC. There's no separate sync needed — both devices read/write the same database. Just make sure the server PC is always on and accessible.
        </div>
    </details>
</div>

</main>

<script>
function copyUrl(url) {
    navigator.clipboard.writeText(url).then(() => {
        alert('Copied: ' + url);
    });
}
function testConnection() {
    const el = document.getElementById('test-result');
    el.className = 'test-result';
    el.textContent = 'Testing...';
    el.style.display = 'block';
    el.style.background = '#f1f5f9';
    el.style.color = '#475569';

    fetch('/oro-store/auth/login.php', { method: 'HEAD' })
    .then(r => {
        if (r.ok) {
            el.className = 'test-result ok';
            el.textContent = '✓ Server is responding. Connection works!';
        } else {
            el.className = 'test-result fail';
            el.textContent = '✕ Server responded with error: ' + r.status;
        }
    })
    .catch(e => {
        el.className = 'test-result fail';
        el.textContent = '✕ Cannot reach server: ' + e.message;
    });
}
function pingDevice() {
    const ip = document.getElementById('ping-target').value.trim();
    if (!ip) { alert('Enter an IP address'); return; }
    const el = document.getElementById('test-result');
    el.style.display = 'block'; el.style.background = '#f1f5f9'; el.style.color = '#475569';
    el.textContent = 'Pinging ' + ip + '...';

    const fd = new FormData();
    fd.append('action', 'network_diag');
    fd.append('target_ip', ip);
    fetch('/oro-store/admin/connection.php', { method: 'POST', body: fd })
    .then(r => r.json()).then(data => {
        if (data.ping_success) {
            el.className = 'test-result ok';
            el.textContent = '✓ ' + ip + ' is reachable!';
        } else {
            el.className = 'test-result fail';
            el.textContent = '✕ ' + ip + ' is NOT reachable. ' + (data.ping_output || '');
        }
    }).catch(e => {
        el.className = 'test-result fail';
        el.textContent = '✕ Error: ' + e.message;
    });
}
// Auto-sync
let autoSyncInterval = null;
function toggleAutoSync() {
    const on = document.getElementById('auto-sync-toggle').checked;
    const status = document.getElementById('auto-sync-status');
    if (on) {
        localStorage.setItem('oro_auto_sync', '1');
        autoSyncInterval = setInterval(() => {
            status.textContent = 'Syncing...';
            fetch('/oro-store/sync/http_sync.php?run=1').then(r => r.json()).then(data => {
                const now = new Date().toLocaleTimeString('en-PH', {hour:'numeric',minute:'2-digit',hour12:true});
                if (data.errors && data.errors.length > 0) {
                    status.textContent = 'Last: ' + now + ' (failed)';
                    status.style.color = '#dc2626';
                } else {
                    status.textContent = 'Last: ' + now + ' (pushed ' + data.pushed + ', pulled ' + data.pulled + ')';
                    status.style.color = '#16a34a';
                }
            }).catch(() => { status.textContent = 'Sync error'; status.style.color = '#dc2626'; });
        }, 5 * 60 * 1000); // 5 minutes
        status.textContent = 'Auto-sync enabled — next in 5 min';
        status.style.color = '#16a34a';
        // Run once immediately
        runSync();
    } else {
        localStorage.removeItem('oro_auto_sync');
        if (autoSyncInterval) { clearInterval(autoSyncInterval); autoSyncInterval = null; }
        status.textContent = 'Disabled';
        status.style.color = '#94a3b8';
    }
}
function runSync() {
    const btn = document.getElementById('sync-btn');
    const el = document.getElementById('sync-result');
    btn.disabled = true; btn.textContent = 'Syncing...';
    el.style.display = 'block'; el.style.background = '#f1f5f9'; el.style.color = '#475569';
    el.textContent = 'Connecting to remote store...';

    fetch('/oro-store/sync/http_sync.php?run=1')
    .then(r => r.json()).then(data => {
        if (data.errors && data.errors.length > 0) {
            el.className = 'test-result fail';
            el.textContent = '✕ ' + data.errors.join(', ') + (data.pushed || data.pulled ? ' | Pushed: ' + data.pushed + ' | Pulled: ' + data.pulled : '');
        } else {
            el.className = 'test-result ok';
            el.textContent = '✓ Sync complete! Pushed: ' + data.pushed + ' | Pulled: ' + data.pulled;
        }
        btn.disabled = false; btn.textContent = 'Sync Now';
    }).catch(e => {
        el.className = 'test-result fail';
        el.textContent = '✕ Sync error: ' + e.message;
        btn.disabled = false; btn.textContent = 'Sync Now';
    });
}
function checkRemote() {
    const el = document.getElementById('sync-result');
    el.style.display = 'block'; el.style.background = '#f1f5f9'; el.style.color = '#475569';
    el.textContent = 'Checking remote store...';

    fetch('/oro-store/sync/sync_api.php?action=status&key=<?php echo urlencode(SYNC_PASSWORD); ?>')
    .then(r => r.json()).then(data => {
        if (data.success) {
            el.className = 'test-result ok';
            el.textContent = '✓ Remote store is online! Device: ' + data.device_id + ' | Pending: ' + data.pending_changes + ' | Time: ' + data.server_time;
        } else {
            el.className = 'test-result fail';
            el.textContent = '✕ Remote responded but with error';
        }
    }).catch(e => {
        el.className = 'test-result fail';
        el.textContent = '✕ Cannot reach remote store: ' + e.message;
    });
}
function runDiagnose() {
    const btn = document.getElementById('diag-btn');
    const el = document.getElementById('diag-result');
    btn.disabled = true; btn.textContent = 'Checking...';
    el.style.display = 'block';
    el.innerHTML = '<div style="padding:12px;background:#f8fafc;border-radius:8px;font-size:12px;color:#64748b;">Running diagnostics...</div>';

    fetch('/oro-store/sync/diagnose.php')
    .then(r => r.json()).then(steps => {
        let html = '<div style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">';
        html += '<div style="padding:10px 14px;background:#1e293b;color:#fff;font-size:13px;font-weight:700;">Connection Diagnostics</div>';
        let failed = false;
        steps.forEach((s, i) => {
            const pass = s.status === 'pass';
            if (!pass) failed = true;
            const icon = pass ? '<span style="color:#16a34a;font-size:16px;">&#10003;</span>' : '<span style="color:#dc2626;font-size:16px;">&#10007;</span>';
            const bg = pass ? '#f0fdf4' : '#fef2f2';
            const border = pass ? '#bbf7d0' : '#fecaca';
            html += '<div style="padding:10px 14px;background:' + bg + ';border-bottom:1px solid ' + border + ';display:flex;gap:10px;align-items:flex-start;">';
            html += '<div style="width:28px;height:28px;border-radius:50%;background:' + (pass ? '#dcfce7' : '#fee2e2') + ';display:flex;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;font-size:12px;">' + (i+1) + '</div>';
            html += '<div style="flex:1;">';
            html += '<div style="font-weight:700;font-size:13px;color:#0f172a;">' + icon + ' ' + s.step + '</div>';
            html += '<div style="font-size:11px;color:#64748b;margin:2px 0;">' + s.desc + '</div>';
            html += '<div style="font-size:12px;color:' + (pass ? '#166534' : '#991b1b') + ';margin-top:4px;padding:6px 10px;background:' + (pass ? '#dcfce7' : '#fee2e2') + ';border-radius:6px;">' + s.detail + '</div>';
            html += '</div></div>';
            if (!pass) {
                html += '<div style="padding:8px 14px 10px 52px;background:#fffbeb;border-bottom:1px solid #fde68a;font-size:11px;color:#92400e;">';
                html += '<strong>Fix:</strong> ' + getFix(s.step) + '</div>';
            }
        });
        if (!failed) {
            html += '<div style="padding:12px 14px;background:#f0fdf4;font-size:13px;color:#166534;font-weight:600;text-align:center;">All checks passed! Sync should work.</div>';
        }
        html += '</div>';
        el.innerHTML = html;
        btn.disabled = false; btn.textContent = 'Diagnose';
    }).catch(e => {
        el.innerHTML = '<div style="padding:12px;background:#fee2e2;border-radius:8px;font-size:12px;color:#991b1b;">Diagnose failed: ' + e.message + '</div>';
        btn.disabled = false; btn.textContent = 'Diagnose';
    });
}
function syncToolAction(action) {
    const el = document.getElementById('sync-tool-result');
    el.style.display = 'block'; el.style.padding = '10px 14px'; el.style.borderRadius = '8px';
    el.style.background = '#f1f5f9'; el.style.color = '#475569'; el.style.fontSize = '12px';
    el.textContent = 'Running ' + action + '...';

    fetch('/oro-store/sync/sync_tools.php?action=' + action)
    .then(r => r.json()).then(data => {
        if (action === 'force_sync') {
            if (data.errors && data.errors.length > 0) {
                el.style.background = '#fee2e2'; el.style.color = '#991b1b';
                el.textContent = 'Sync errors: ' + data.errors.join(', ');
            } else {
                el.style.background = '#dcfce7'; el.style.color = '#166534';
                el.textContent = 'Sync complete! Pushed: ' + data.pushed + ' | Pulled: ' + data.pulled;
                setTimeout(() => location.reload(), 2000);
            }
        } else if (action === 'clear_synced') {
            el.style.background = '#dcfce7'; el.style.color = '#166534';
            el.textContent = 'Cleared ' + data.cleared + ' synced entries from log.';
            setTimeout(() => location.reload(), 1500);
        } else if (action === 'remote_status') {
            if (data.success) {
                const r = data.remote;
                el.style.background = '#dcfce7'; el.style.color = '#166534';
                el.innerHTML = '<strong>Remote Online</strong><br>Device: ' + r.device_id + ' | Pending: ' + r.pending_changes + ' | Time: ' + r.server_time;
            } else {
                el.style.background = '#fee2e2'; el.style.color = '#991b1b';
                el.textContent = data.message;
            }
        } else if (action === 'backup_db') {
            if (data.success) {
                el.style.background = '#dcfce7'; el.style.color = '#166534';
                el.innerHTML = '<strong>Backup saved!</strong> ' + data.file + ' (' + data.size + ')<br>Location: oro-store/' + data.path;
            } else {
                el.style.background = '#fee2e2'; el.style.color = '#991b1b';
                el.textContent = data.message;
            }
        } else if (action === 'view_pending') {
            if (data.total === 0) {
                el.style.background = '#dcfce7'; el.style.color = '#166534';
                el.textContent = 'No pending changes — everything is synced!';
            } else {
                el.style.background = '#fffbeb'; el.style.color = '#92400e';
                let html = '<strong>' + data.total + ' pending changes:</strong><br>';
                html += '<table style="width:100%;font-size:11px;margin-top:6px;border-collapse:collapse;">';
                html += '<tr style="border-bottom:1px solid #fde68a;"><th style="text-align:left;padding:2px 6px;">Table</th><th style="text-align:left;padding:2px 6px;">Op</th><th style="text-align:right;padding:2px 6px;">Count</th></tr>';
                data.breakdown.forEach(function(r) {
                    html += '<tr style="border-bottom:1px solid #fef3c7;"><td style="padding:2px 6px;">' + r.table_name + '</td><td style="padding:2px 6px;">' + r.operation + '</td><td style="text-align:right;padding:2px 6px;font-weight:700;">' + r.cnt + '</td></tr>';
                });
                html += '</table>';
                el.innerHTML = html;
            }
        }
    }).catch(e => {
        el.style.background = '#fee2e2'; el.style.color = '#991b1b';
        el.textContent = 'Error: ' + e.message;
    });
}
function getFix(step) {
    const fixes = {
        'ZeroTier Running': 'Open ZeroTier app, make sure it shows "Connected". If not, right-click tray icon > Join Network > paste your network ID.',
        'Ping Remote': 'Both devices must be on the same ZeroTier network AND authorized in <a href="https://my.zerotier.com" target="_blank" style="color:#6366f1;">ZeroTier Central</a>. Check the Auth checkbox next to each device.',
        'Remote Apache': 'On the remote PC: open XAMPP Control Panel > click Start next to Apache. Then on that PC, go to Connection page > click "Fix Firewall (Allow Port 80)".',
        'Sync API': 'Make sure the oro-store folder exists on the remote at C:\\xampp\\htdocs\\oro-store\\. Also check that the Sync Password is the same on both stores.',
        'Local Database': 'Run this SQL in phpMyAdmin: CREATE TABLE IF NOT EXISTS sync_log (id INT AUTO_INCREMENT PRIMARY KEY, table_name VARCHAR(100), record_id INT, operation VARCHAR(10), data LONGTEXT, device_id VARCHAR(50), synced TINYINT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP); CREATE TABLE IF NOT EXISTS sync_status (id INT AUTO_INCREMENT PRIMARY KEY, last_sync_time DATETIME, remote_device_ip VARCHAR(50), sync_direction VARCHAR(10), records_pushed INT DEFAULT 0, records_pulled INT DEFAULT 0, status VARCHAR(20), error_message TEXT);'
    };
    return fixes[step] || 'Check the detail above for more info.';
}
function installTriggers(btn) {
    const el = document.getElementById('trigger-result');
    btn.disabled = true; btn.textContent = 'Installing...';
    el.style.display = 'block'; el.style.padding = '8px 12px'; el.style.borderRadius = '6px';
    el.style.background = '#f1f5f9'; el.style.color = '#475569';
    el.textContent = 'Creating sync triggers on all tables...';

    fetch('/oro-store/sync/install_triggers.php')
    .then(r => r.json()).then(data => {
        if (data.errors && data.errors.length > 0) {
            el.style.background = '#fef3c7'; el.style.color = '#92400e';
            el.innerHTML = 'Installed: ' + data.installed + ' tables | Skipped: ' + data.skipped + '<br>Errors: ' + data.errors.join('<br>');
        } else {
            el.style.background = '#dcfce7'; el.style.color = '#166534';
            el.textContent = 'Triggers installed on ' + data.installed + ' tables. All database changes will now auto-sync.';
        }
        btn.disabled = false; btn.textContent = 'Install Sync Triggers';
    }).catch(e => {
        el.style.background = '#fee2e2'; el.style.color = '#991b1b';
        el.textContent = 'Failed: ' + e.message;
        btn.disabled = false; btn.textContent = 'Install Sync Triggers';
    });
}
function runCleanup(btn) {
    if (!confirm('This will WIPE all completed transactions older than today from this device. Data is safe on Device A. Continue?')) return;
    const el = document.getElementById('cleanup-result');
    btn.disabled = true; btn.textContent = 'Cleaning...';
    el.style.display = 'block'; el.style.padding = '10px 14px'; el.style.borderRadius = '6px';
    el.style.background = '#f1f5f9'; el.style.color = '#475569';
    el.textContent = 'Syncing to Device A then wiping old data...';

    fetch('/oro-store/sync/nightly_cleanup.php?run=1')
    .then(r => r.json()).then(data => {
        if (data.success) {
            let wiped = Object.entries(data.wiped || {}).filter(([k,v]) => v > 0).map(([k,v]) => k + ': ' + v).join(', ');
            el.style.background = '#dcfce7'; el.style.color = '#166534';
            el.innerHTML = '<strong>Cleanup complete!</strong><br>Synced: pushed ' + (data.sync_pushed||0) + ', pulled ' + (data.sync_pulled||0) + '<br>Wiped: ' + (wiped || 'nothing to clean') + '<br>Post-pull: ' + (data.post_pull||0) + ' records';
        } else {
            el.style.background = '#fee2e2'; el.style.color = '#991b1b';
            el.textContent = data.message || 'Cleanup failed';
        }
        btn.disabled = false; btn.textContent = 'Run Cleanup Now';
    }).catch(e => {
        el.style.background = '#fee2e2'; el.style.color = '#991b1b';
        el.textContent = 'Error: ' + e.message;
        btn.disabled = false; btn.textContent = 'Run Cleanup Now';
    });
}
function remoteWipe() {
    const ip = document.getElementById('wipe-target-ip').value.trim();
    if (!ip) { alert('Enter branch device IP'); return; }
    if (!confirm('This will WIPE old transactions on the branch device at ' + ip + '. Data syncs first. Continue?')) return;
    const el = document.getElementById('remote-wipe-result');
    el.style.display = 'block'; el.style.padding = '10px 14px'; el.style.borderRadius = '6px';
    el.style.background = '#1e293b'; el.style.color = '#94a3b8'; el.style.border = '1px solid #334155';
    el.textContent = 'Connecting to ' + ip + ' via server...';

    fetch('/oro-store/sync/proxy.php?ip=' + encodeURIComponent(ip) + '&action=remote_cleanup')
    .then(r => r.json()).then(data => {
        if (data.success) {
            let wiped = Object.entries(data.wiped || {}).filter(([k,v]) => v > 0).map(([k,v]) => k + ': ' + v).join(', ');
            el.style.background = '#052e16'; el.style.color = '#22c55e'; el.style.border = '1px solid #166534';
            el.innerHTML = '<strong>Branch wiped!</strong> Device: ' + (data.device||'?') + '<br>Synced: pushed ' + (data.sync_pushed||0) + ', pulled ' + (data.sync_pulled||0) + '<br>Wiped: ' + (wiped || 'nothing to clean');
        } else {
            el.style.background = '#450a0a'; el.style.color = '#fca5a5'; el.style.border = '1px solid #991b1b';
            el.innerHTML = (data.message || 'Wipe failed');
            if (data.diagnosis) {
                el.innerHTML += '<div style="margin-top:6px;padding:6px;background:#1e293b;border-radius:4px;font-size:11px;">' + data.diagnosis.suggestion + '</div>';
            }
        }
    }).catch(e => {
        el.style.background = '#450a0a'; el.style.color = '#fca5a5'; el.style.border = '1px solid #991b1b';
        el.textContent = 'Error: ' + e.message;
    });
}
// Restore auto-sync state (after all functions defined)
if (localStorage.getItem('oro_auto_sync') === '1') {
    document.getElementById('auto-sync-toggle').checked = true;
    toggleAutoSync();
}
</script>
</body>
</html>

