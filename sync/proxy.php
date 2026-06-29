<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$target_ip = trim($_GET['ip'] ?? REMOTE_IP);
$action = trim($_GET['action'] ?? '');
$sync_key = SYNC_PASSWORD;

if (!$target_ip || !$action) {
    echo json_encode(['success' => false, 'message' => 'Missing ip or action parameter']);
    exit;
}

if (!preg_match('/^\d+\.\d+\.\d+\.\d+$/', $target_ip)) {
    echo json_encode(['success' => false, 'message' => 'Invalid IP format']);
    exit;
}

$url = "http://$target_ip/oro-store/sync/sync_api.php?action=$action&key=" . urlencode($sync_key);
$timeout = ($action === 'remote_cleanup') ? 60 : 10;

$ctx = stream_context_create(['http' => [
    'timeout' => $timeout,
    'header' => "X-Sync-Key: $sync_key\r\n",
    'ignore_errors' => true
]]);

// Step 1: Check if host is reachable
$sock = @fsockopen($target_ip, 80, $errno, $errstr, 3);
if (!$sock) {
    // Try ping
    $ping = @shell_exec("ping -n 1 -w 2000 $target_ip 2>&1");
    $can_ping = $ping && strpos($ping, 'TTL=') !== false;

    echo json_encode([
        'success' => false,
        'message' => "Cannot connect to $target_ip on port 80",
        'diagnosis' => [
            'ip' => $target_ip,
            'port_80' => false,
            'ping' => $can_ping,
            'suggestion' => $can_ping
                ? "Device is online (ping OK) but Apache is not running or port 80 is blocked by firewall. Start Apache in XAMPP and run 'Setup Device' on the remote."
                : "Device is unreachable. Check: ZeroTier connected? Device turned on? Same network?"
        ]
    ]);
    exit;
}
fclose($sock);

// Step 2: Make the actual request
$raw = @file_get_contents($url, false, $ctx);

if ($raw === false) {
    echo json_encode([
        'success' => false,
        'message' => "Connected to $target_ip but got no response from sync API",
        'diagnosis' => [
            'ip' => $target_ip,
            'port_80' => true,
            'api_response' => false,
            'suggestion' => "Apache is running but the sync API didn't respond. Check if oro-store/sync/sync_api.php exists on the remote device."
        ]
    ]);
    exit;
}

// Step 3: Parse response
$data = @json_decode($raw, true);
if ($data === null) {
    // Not JSON — probably a PHP error
    $snippet = substr(strip_tags($raw), 0, 300);
    echo json_encode([
        'success' => false,
        'message' => "Remote returned invalid response (PHP error on remote device)",
        'diagnosis' => [
            'ip' => $target_ip,
            'port_80' => true,
            'api_response' => true,
            'valid_json' => false,
            'raw_snippet' => $snippet,
            'suggestion' => "The remote device has a PHP error. Check the remote's sync/config.php and database connection. The error starts with: " . substr($snippet, 0, 100)
        ]
    ]);
    exit;
}

echo json_encode($data);
