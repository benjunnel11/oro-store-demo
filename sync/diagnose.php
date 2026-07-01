<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

$results = [];
$remote = '0.0.0.0';

$config_file = __DIR__ . '/config.php';
if (file_exists($config_file)) {
    @include_once $config_file;
    if (defined('REMOTE_IP')) $remote = REMOTE_IP;
}

ob_end_clean();
header('Content-Type: application/json');

// Step 1: ZeroTier
$step1 = ['step' => 'ZeroTier Running', 'desc' => 'Is ZeroTier active on this device?', 'status' => 'fail', 'detail' => 'Not detected'];
$ipconfig = @shell_exec('ipconfig 2>nul');
if ($ipconfig && stripos($ipconfig, 'ZeroTier') !== false) {
    preg_match('/ZeroTier[\s\S]*?IPv4[^:]*:\s*(\d+\.\d+\.\d+\.\d+)/', $ipconfig, $ztm);
    if (!empty($ztm[1])) {
        $step1['status'] = 'pass';
        $step1['detail'] = 'ZeroTier IP: ' . $ztm[1];
    } else {
        $step1['detail'] = 'ZeroTier adapter found but no IP. Check ZeroTier app.';
    }
} else {
    $step1['detail'] = 'ZeroTier not detected in ipconfig. Is it installed and connected?';
}
$results[] = $step1;

// Step 2: Ping remote
$step2 = ['step' => 'Ping Remote', 'desc' => "Can this device reach $remote?", 'status' => 'fail', 'detail' => ''];
$ping = @shell_exec("ping -n 1 -w 3000 $remote 2>&1");
if ($ping && strpos($ping, 'TTL=') !== false) {
    preg_match('/time[=<](\d+)ms/', $ping, $m);
    $step2['status'] = 'pass';
    $step2['detail'] = 'Remote is reachable. Ping: ' . (isset($m[1]) ? $m[1] : '?') . 'ms';
} else {
    $step2['detail'] = "Cannot ping $remote. Run 'Setup Device' on BOTH stores to allow ping through firewall.";
}
$results[] = $step2;

// Step 3: Remote Apache (port 80)
$step3 = ['step' => 'Remote Apache', 'desc' => "Is web server running on $remote:80?", 'status' => 'fail', 'detail' => ''];
$sock = @fsockopen($remote, 80, $errno, $errstr, 3);
if ($sock) {
    @fclose($sock);
    $step3['status'] = 'pass';
    $step3['detail'] = 'Port 80 is open on remote.';
} else {
    $step3['detail'] = "Port 80 closed. Start Apache on the remote + run 'Setup Device' there.";
}
$results[] = $step3;

// Step 4: Sync API
$step4 = ['step' => 'Sync API', 'desc' => 'Can we reach the sync endpoint?', 'status' => 'fail', 'detail' => ''];
$sync_pass = defined('SYNC_PASSWORD') ? SYNC_PASSWORD : '';
$url = "http://$remote/oro-store-demo/sync/sync_api.php?action=status&key=" . urlencode($sync_pass);
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
$raw = @file_get_contents($url, false, $ctx);
if ($raw) {
    $data = @json_decode($raw, true);
    if ($data && !empty($data['success'])) {
        $step4['status'] = 'pass';
        $step4['detail'] = 'Sync API online! Device: ' . (isset($data['device_id']) ? $data['device_id'] : '?') . ', Pending: ' . (isset($data['pending_changes']) ? $data['pending_changes'] : 0);
    } elseif ($data && isset($data['error'])) {
        $step4['detail'] = 'API error: ' . $data['error'] . '. Check sync password matches on both stores.';
    } else {
        $step4['detail'] = 'Response is not valid JSON. Check oro-store setup on remote.';
    }
} else {
    $step4['detail'] = "No response. Make sure oro-store folder and sync/config.php exist on remote.";
}
$results[] = $step4;

// Step 5: Local database
$step5 = ['step' => 'Local Database', 'desc' => 'Is sync_log table ready?', 'status' => 'fail', 'detail' => ''];
$db_name = defined('DB_NAME') ? DB_NAME : 'product_db';
require_once __DIR__ . '/../core/db_config.php';
$conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, $db_name);
if ($conn && !$conn->connect_error) {
    $has_log = $conn->query("SHOW TABLES LIKE 'sync_log'");
    $has_status = $conn->query("SHOW TABLES LIKE 'sync_status'");
    if ($has_log && $has_log->num_rows > 0 && $has_status && $has_status->num_rows > 0) {
        $r = $conn->query("SELECT COUNT(*) as c FROM sync_log WHERE synced = 0");
        $p = $r ? $r->fetch_assoc()['c'] : 0;
        $step5['status'] = 'pass';
        $step5['detail'] = "Tables ready. Pending changes: $p";
    } else {
        $step5['detail'] = "Missing sync tables. Click 'Setup Device' to create them.";
    }
    $conn->close();
} else {
    $step5['detail'] = 'Cannot connect to local MySQL. Is it running?';
}
$results[] = $step5;

echo json_encode($results);
