<?php
// Access Guard — limits concurrent visitors and rate limits requests
$_ag_max_ips = 10;
$_ag_rate_limit = 60; // max requests per minute per IP
// Use sys_get_temp_dir if writable, otherwise fall back to local logs/ folder
$_ag_tmp = sys_get_temp_dir() . '/oro_demo_guard';
$_ag_local = __DIR__ . '/../logs/guard';
if (!is_dir($_ag_tmp) && !@mkdir($_ag_tmp, 0777, true)) {
    $_ag_tmp = $_ag_local;
}
$_ag_dir = (is_writable($_ag_tmp) || @mkdir($_ag_tmp, 0777, true)) ? $_ag_tmp : $_ag_local;
if (!is_dir($_ag_dir)) @mkdir($_ag_dir, 0777, true);

$_ag_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_ag_now = time();

// Clean expired sessions (older than 10 minutes)
foreach (glob($_ag_dir . '/ip_*.json') as $f) {
    $d = json_decode(file_get_contents($f), true);
    if (!$d || ($_ag_now - ($d['last_seen'] ?? 0)) > 600) @unlink($f);
}

// Count active IPs
$_ag_active = glob($_ag_dir . '/ip_*.json');
$_ag_my_file = $_ag_dir . '/ip_' . md5($_ag_ip) . '.json';
$_ag_is_existing = file_exists($_ag_my_file);

if (!$_ag_is_existing && count($_ag_active) >= $_ag_max_ips) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Demo Full</title><style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex;justify-content:center;align-items:center}
    .box{background:#1e293b;padding:40px;border-radius:16px;max-width:400px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.4)}
    h1{color:#f59e0b;margin-bottom:8px;font-size:24px}p{color:#94a3b8;font-size:14px;line-height:1.6;margin-bottom:16px}
    .count{font-size:48px;font-weight:900;color:#ef4444;margin:16px 0}
    .retry{display:inline-block;padding:10px 24px;background:#6366f1;color:#fff;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px}</style></head>
    <body><div class="box"><h1>Demo Capacity Full</h1><div class="count">' . count($_ag_active) . '/' . $_ag_max_ips . '</div>
    <p>This demo only allows ' . $_ag_max_ips . ' concurrent visitors. Please try again in a few minutes.</p>
    <a href="" class="retry">Retry</a></div></body></html>';
    exit;
}

// Rate limiting — max requests per minute
$_ag_data = $_ag_is_existing ? (json_decode(file_get_contents($_ag_my_file), true) ?: []) : [];
$_ag_minute = date('YmdHi');
if (($_ag_data['minute'] ?? '') !== $_ag_minute) {
    $_ag_data['minute'] = $_ag_minute;
    $_ag_data['count'] = 0;
}
$_ag_data['count'] = ($_ag_data['count'] ?? 0) + 1;
$_ag_data['last_seen'] = $_ag_now;
$_ag_data['ip'] = $_ag_ip;

if ($_ag_data['count'] > $_ag_rate_limit) {
    http_response_code(429);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Too Fast</title>
    <style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:sans-serif;background:#0f172a;color:#e2e8f0;min-height:100vh;display:flex;justify-content:center;align-items:center}
    .box{background:#1e293b;padding:40px;border-radius:16px;max-width:400px;width:90%;text-align:center}
    h1{color:#ef4444;margin-bottom:8px}p{color:#94a3b8;font-size:14px}</style></head>
    <body><div class="box"><h1>Slow Down</h1><p>Too many requests. Please wait a moment and try again.</p></div></body></html>';
    @file_put_contents($_ag_my_file, json_encode($_ag_data));
    exit;
}

@file_put_contents($_ag_my_file, json_encode($_ag_data));