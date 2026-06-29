<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/config.php';

if (!isSuperAdmin()) { header("Location: /oro-store/admin/admin_panel.php"); exit; }
$currentUser = getCurrentUser();

// Get all stores with device assignments
$stores = $conn->query("SELECT * FROM stores WHERE status = 'active' AND device_id IS NOT NULL AND device_id != 'DEVICE_A' ORDER BY device_id")->fetch_all(MYSQLI_ASSOC);

// Get local cleanup stats (use safe queries — column names vary per table)
$local_stats = [];
function safeCount($conn, $sql) { $r = @$conn->query($sql); return $r ? intval($r->fetch_assoc()['c']) : 0; }
$local_stats['transactions'] = safeCount($conn, "SELECT COUNT(*) as c FROM transactions WHERE DATE(transaction_date) < CURDATE() AND status IN ('completed','cancelled')");
$local_stats['gcash'] = safeCount($conn, "SELECT COUNT(*) as c FROM gcash_transactions WHERE DATE(transaction_date) < CURDATE()");
$local_stats['atm'] = safeCount($conn, "SELECT COUNT(*) as c FROM atm_transactions WHERE DATE(transaction_date) < CURDATE()");
$local_stats['deliveries'] = safeCount($conn, "SELECT COUNT(*) as c FROM deliveries WHERE status = 'completed' AND DATE(created_at) < CURDATE()");
$local_stats['angkat'] = safeCount($conn, "SELECT COUNT(*) as c FROM angkat_transactions WHERE status IN ('completed','returned') AND DATE(created_at) < CURDATE()");
$local_stats['credits'] = safeCount($conn, "SELECT COUNT(*) as c FROM credits WHERE status = 'paid' AND DATE(created_at) < CURDATE()");
$local_stats['expenses'] = safeCount($conn, "SELECT COUNT(*) as c FROM expenses WHERE DATE(created_at) < CURDATE()");
$local_stats['total'] = array_sum($local_stats);

// Sync history for this device
$sync_history = $conn->query("SELECT * FROM sync_status ORDER BY id DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
$last_sync = !empty($sync_history) ? $sync_history[0] : null;

// Check cleanup log
$cleanup_log_file = __DIR__ . '/../sync/sync.log';
$cleanup_entries = [];
if (file_exists($cleanup_log_file)) {
    $lines = file($cleanup_log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (array_reverse($lines) as $line) {
        if (stripos($line, 'cleanup') !== false || stripos($line, 'wiped') !== false || stripos($line, 'nightly') !== false) {
            $cleanup_entries[] = $line;
            if (count($cleanup_entries) >= 10) break;
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Branch Data Wipe - Oro Store</title>
    <link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
    <?php include_once __DIR__ . '/../core/pwa.php'; ?>
    <style>
        .wipe-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px,1fr)); gap:16px; margin-bottom:20px; }
        .wipe-card { background:#fff; border-radius:12px; padding:18px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .wipe-card h3 { font-size:14px; font-weight:700; margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid #e2e8f0; }
        .wipe-row { display:flex; justify-content:space-between; padding:5px 0; font-size:13px; border-bottom:1px solid #f1f5f9; }
        .wipe-row:last-child { border-bottom:none; }
        .wipe-row .label { color:#64748b; }
        .wipe-row .val { font-weight:600; }
        .device-card { border-radius:12px; padding:18px; margin-bottom:14px; border:2px solid #e2e8f0; background:#fff; }
        .device-card.online { border-color:#bbf7d0; }
        .device-card.offline { border-color:#fecaca; }
        .device-header { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
        .device-icon { width:44px; height:44px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:900; color:#fff; flex-shrink:0; }
        .device-name { font-size:16px; font-weight:800; color:#0f172a; }
        .device-sub { font-size:11px; color:#64748b; }
        .device-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin:10px 0; }
        .device-stat { background:#f8fafc; border-radius:6px; padding:8px; text-align:center; }
        .device-stat .num { font-size:16px; font-weight:800; color:#1e293b; }
        .device-stat .lbl { font-size:9px; color:#64748b; text-transform:uppercase; font-weight:600; }
        .wipe-btn { padding:10px 20px; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; width:100%; }
        .wipe-btn.danger { background:#dc2626; color:#fff; }
        .wipe-btn.danger:hover { background:#b91c1c; }
        .wipe-btn.check { background:#6366f1; color:#fff; }
        .wipe-btn.check:hover { background:#4f46e5; }
        .wipe-btn:disabled { opacity:0.5; cursor:not-allowed; }
        .result-box { margin-top:10px; padding:10px 14px; border-radius:8px; font-size:12px; display:none; }
        .result-box.ok { background:#dcfce7; color:#166534; display:block; }
        .result-box.fail { background:#fee2e2; color:#991b1b; display:block; }
        .result-box.info { background:#f1f5f9; color:#475569; display:block; }
        .warning-box { background:#fef3c7; border:1px solid #fde68a; border-radius:8px; padding:12px 16px; margin-bottom:20px; font-size:12px; color:#92400e; }
        .safe-box { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 16px; margin-bottom:20px; font-size:12px; color:#166534; }
    </style>
</head>
<body>
<?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>
<main class="main-content">

<div class="page-header">
    <h1>Branch Data Wipe</h1>
    <p>Manage data cleanup on branch devices to reduce leakage risk</p>
</div>

<div class="safe-box">
    <strong>Device A is protected.</strong> This page can only wipe data on branch devices (B–J). The main server database is never affected.
    A <code style="background:#dcfce7;padding:1px 6px;border-radius:3px;">.main_server</code> lock file and config check guarantee this.
</div>

<!-- How it works -->
<div class="wipe-card" style="margin-bottom:20px;border-left:4px solid #6366f1;">
    <h3>How Branch Wipe Works</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;font-size:12px;">
        <div style="background:#eef2ff;border-radius:8px;padding:12px;text-align:center;">
            <div style="font-size:24px;margin-bottom:4px;">1</div>
            <strong>Sync First</strong><br>All pending data is pushed to Device A
        </div>
        <div style="background:#fffbeb;border-radius:8px;padding:12px;text-align:center;">
            <div style="font-size:24px;margin-bottom:4px;">2</div>
            <strong>Wipe Old Data</strong><br>Completed transactions older than today are deleted
        </div>
        <div style="background:#f0fdf4;border-radius:8px;padding:12px;text-align:center;">
            <div style="font-size:24px;margin-bottom:4px;">3</div>
            <strong>Pull Fresh</strong><br>Latest stock levels pulled back from Device A
        </div>
    </div>
    <div style="margin-top:12px;font-size:11px;color:#64748b;">
        <strong>What gets wiped:</strong> Sales, GCash, ATM, completed deliveries, completed angkat, paid credits, expenses, stock receipts (older than today)<br>
        <strong>What stays:</strong> Products, stock levels, stores, users, accounts, today's transactions, active/unpaid credits, active deliveries, active angkat
    </div>
</div>

<!-- Device A Stats (this server) -->
<div class="wipe-card" style="margin-bottom:20px;border-left:4px solid #16a34a;">
    <h3 style="color:#16a34a;">Device A — Main Server (This Device)</h3>
    <div style="font-size:12px;color:#64748b;margin-bottom:10px;">All data is permanently stored here. These counts show what branch devices would have wiped:</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:8px;">
        <?php foreach ($local_stats as $key => $val): if ($key === 'total') continue; ?>
        <div style="background:#f0fdf4;border-radius:6px;padding:8px;text-align:center;">
            <div style="font-size:16px;font-weight:800;color:#166534;"><?php echo $val; ?></div>
            <div style="font-size:9px;color:#64748b;text-transform:uppercase;"><?php echo $key; ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:8px;font-size:11px;color:#16a34a;font-weight:600;">Total records safe on this server: <?php echo number_format($local_stats['total']); ?></div>
</div>

<!-- Sync & Wipe History -->
<div class="wipe-grid">
    <div class="wipe-card" style="border-left:4px solid #f59e0b;">
        <h3>Sync History (This Device)</h3>
        <?php if (empty($sync_history)): ?>
            <div style="text-align:center;padding:16px;color:#94a3b8;font-size:12px;">No syncs recorded yet</div>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:11px;">
            <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="padding:5px 6px;text-align:left;">Time</th>
                <th style="padding:5px 6px;text-align:center;">Push</th>
                <th style="padding:5px 6px;text-align:center;">Pull</th>
                <th style="padding:5px 6px;text-align:center;">Status</th>
                <th style="padding:5px 6px;text-align:left;">Error</th>
            </tr></thead>
            <tbody>
            <?php foreach ($sync_history as $sh): ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:4px 6px;white-space:nowrap;"><?php echo $sh['last_sync_time'] ? date('M j, g:i A', strtotime($sh['last_sync_time'])) : '—'; ?></td>
                <td style="padding:4px 6px;text-align:center;font-weight:600;"><?php echo $sh['records_pushed']; ?></td>
                <td style="padding:4px 6px;text-align:center;font-weight:600;"><?php echo $sh['records_pulled']; ?></td>
                <td style="padding:4px 6px;text-align:center;">
                    <span style="padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;background:<?php echo $sh['status']==='SUCCESS' ? '#dcfce7' : '#fee2e2'; ?>;color:<?php echo $sh['status']==='SUCCESS' ? '#166534' : '#991b1b'; ?>;">
                        <?php echo $sh['status']; ?>
                    </span>
                </td>
                <td style="padding:4px 6px;color:#94a3b8;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($sh['error_message'] ?? ''); ?>">
                    <?php echo htmlspecialchars($sh['error_message'] ?? '—'); ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div style="margin-top:8px;font-size:10px;color:#94a3b8;">
            Last sync: <?php echo $last_sync ? date('M j, Y g:i:s A', strtotime($last_sync['last_sync_time'])) : 'Never'; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="wipe-card" style="border-left:4px solid #dc2626;">
        <h3>Cleanup Log</h3>
        <?php if (empty($cleanup_entries)): ?>
            <div style="text-align:center;padding:16px;color:#94a3b8;font-size:12px;">No cleanup events recorded yet</div>
        <?php else: ?>
        <div style="max-height:250px;overflow:auto;">
            <?php foreach ($cleanup_entries as $entry): ?>
            <div style="padding:6px 8px;margin-bottom:4px;background:#fef2f2;border-radius:6px;font-size:11px;color:#991b1b;font-family:monospace;word-break:break-all;">
                <?php echo htmlspecialchars($entry); ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:8px;font-size:10px;color:#94a3b8;">
            Showing last <?php echo count($cleanup_entries); ?> cleanup events from sync.log
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Branch Devices -->
<h2 style="font-size:16px;font-weight:700;color:#1e293b;margin-bottom:14px;">Branch Devices</h2>

<?php if (empty($stores)): ?>
<div class="wipe-card" style="text-align:center;padding:30px;color:#94a3b8;">
    No branch devices configured. Assign devices to stores in <a href="/oro-store/admin/manage_stores.php" style="color:#6366f1;">Manage Stores</a>.
</div>
<?php else: ?>
<?php foreach ($stores as $store):
    $dev_letter = substr($store['device_id'], -1);
    $dev_colors = ['B'=>'#f59e0b','C'=>'#16a34a','D'=>'#dc2626','E'=>'#8b5cf6','F'=>'#0891b2','G'=>'#d946ef','H'=>'#ea580c','I'=>'#4f46e5','J'=>'#059669'];
    $dev_color = $dev_colors[$dev_letter] ?? '#64748b';
?>
<div class="device-card" id="device-<?php echo $dev_letter; ?>">
    <div class="device-header">
        <div class="device-icon" style="background:<?php echo $dev_color; ?>;"><?php echo $dev_letter; ?></div>
        <div>
            <div class="device-name"><?php echo htmlspecialchars($store['store_name']); ?></div>
            <div class="device-sub"><?php echo $store['device_id']; ?> &bull; <?php echo htmlspecialchars($store['store_code']); ?></div>
        </div>
        <div style="margin-left:auto;" id="status-<?php echo $dev_letter; ?>">
            <span style="font-size:11px;color:#94a3b8;">Checking...</span>
        </div>
    </div>

    <div class="device-stats" id="stats-<?php echo $dev_letter; ?>">
        <div class="device-stat"><div class="num">—</div><div class="lbl">Pending</div></div>
        <div class="device-stat"><div class="num">—</div><div class="lbl">Last Sync</div></div>
        <div class="device-stat"><div class="num">—</div><div class="lbl">Status</div></div>
    </div>

    <div style="display:flex;gap:8px;">
        <button class="wipe-btn check" onclick="checkBranch('<?php echo $dev_letter; ?>', '<?php echo REMOTE_IP; ?>')">Check Status</button>
        <button class="wipe-btn danger" onclick="wipeBranch('<?php echo $dev_letter; ?>', '<?php echo REMOTE_IP; ?>')">Wipe Data</button>
    </div>

    <div class="result-box" id="result-<?php echo $dev_letter; ?>"></div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Manual IP Wipe -->
<div class="wipe-card" style="margin-top:20px;border-left:4px solid #475569;">
    <h3>Manual Branch Wipe (by IP)</h3>
    <p style="font-size:11px;color:#64748b;margin-bottom:10px;">Enter a branch device's ZeroTier IP to check or wipe manually:</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <input type="text" id="manual-ip" placeholder="10.219.18.xxx" style="padding:9px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;font-family:monospace;width:180px;">
        <button class="wipe-btn check" style="width:auto;padding:9px 18px;" onclick="checkBranch('manual', document.getElementById('manual-ip').value)">Check</button>
        <button class="wipe-btn danger" style="width:auto;padding:9px 18px;" onclick="wipeBranch('manual', document.getElementById('manual-ip').value)">Wipe</button>
    </div>
    <div class="result-box" id="result-manual"></div>
</div>

</main>

<script>
function proxyFetch(ip, action) {
    return fetch('/oro-store/sync/proxy.php?ip=' + encodeURIComponent(ip) + '&action=' + encodeURIComponent(action))
        .then(r => r.json());
}

function showDiagnosis(el, data) {
    if (data.diagnosis) {
        const d = data.diagnosis;
        let html = '<div style="margin-top:8px;padding:10px;background:#1e293b;border-radius:6px;color:#e2e8f0;font-size:11px;">';
        html += '<div style="font-weight:700;margin-bottom:4px;color:#f59e0b;">Diagnosis:</div>';
        html += '<div>IP: <strong>' + d.ip + '</strong></div>';
        html += '<div>Port 80: ' + (d.port_80 ? '<span style="color:#22c55e;">Open</span>' : '<span style="color:#ef4444;">Closed</span>') + '</div>';
        if (d.ping !== undefined) html += '<div>Ping: ' + (d.ping ? '<span style="color:#22c55e;">OK</span>' : '<span style="color:#ef4444;">Failed</span>') + '</div>';
        if (d.api_response !== undefined) html += '<div>API Response: ' + (d.api_response ? '<span style="color:#22c55e;">Yes</span>' : '<span style="color:#ef4444;">No</span>') + '</div>';
        if (d.valid_json !== undefined) html += '<div>Valid JSON: ' + (d.valid_json ? '<span style="color:#22c55e;">Yes</span>' : '<span style="color:#ef4444;">No</span>') + '</div>';
        if (d.raw_snippet) html += '<div style="margin-top:4px;padding:6px;background:#0f172a;border-radius:4px;font-family:monospace;font-size:10px;max-height:80px;overflow:auto;word-break:break-all;">' + d.raw_snippet + '</div>';
        html += '<div style="margin-top:6px;padding:6px;background:#fffbeb;border-radius:4px;color:#92400e;font-weight:600;">' + d.suggestion + '</div>';
        html += '</div>';
        el.innerHTML += html;
    }
}

function checkBranch(id, ip) {
    if (!ip) { alert('No IP configured for this device'); return; }
    const el = document.getElementById('result-' + id);
    const statusEl = document.getElementById('status-' + id);
    const statsEl = document.getElementById('stats-' + id);
    el.className = 'result-box info'; el.textContent = 'Checking ' + ip + ' via server...';

    proxyFetch(ip, 'status').then(data => {
        if (data.success) {
            if (statusEl) statusEl.innerHTML = '<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">Online</span>';
            if (statsEl) {
                const ls = data.last_sync;
                statsEl.innerHTML =
                    '<div class="device-stat"><div class="num" style="color:#d97706;">' + data.pending_changes + '</div><div class="lbl">Pending</div></div>' +
                    '<div class="device-stat"><div class="num" style="font-size:12px;">' + (ls ? new Date(ls.last_sync_time).toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit',hour12:true}) : 'Never') + '</div><div class="lbl">Last Sync</div></div>' +
                    '<div class="device-stat"><div class="num" style="color:' + (ls && ls.status==='SUCCESS' ? '#16a34a' : '#dc2626') + ';">' + (ls ? ls.status : '—') + '</div><div class="lbl">Status</div></div>';
            }
            el.className = 'result-box ok';
            el.textContent = 'Device ' + data.device_id + ' is online. Server time: ' + data.server_time;
        } else {
            if (statusEl) statusEl.innerHTML = '<span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">Error</span>';
            el.className = 'result-box fail';
            el.innerHTML = data.message || 'Check failed';
            showDiagnosis(el, data);
        }
    }).catch(e => {
        if (statusEl) statusEl.innerHTML = '<span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;">Offline</span>';
        el.className = 'result-box fail'; el.textContent = 'Proxy error: ' + e.message;
    });
}

function wipeBranch(id, ip) {
    if (!ip) { alert('No IP configured'); return; }
    if (!confirm('WIPE data on branch device at ' + ip + '?\n\nThis will:\n1. Sync all data to Device A first\n2. Delete completed transactions older than today\n3. Pull fresh stock data\n\nContinue?')) return;

    const el = document.getElementById('result-' + id);
    el.className = 'result-box info'; el.textContent = 'Syncing then wiping ' + ip + '... This may take a moment.';

    proxyFetch(ip, 'remote_cleanup').then(data => {
        if (data.success) {
            let wiped = Object.entries(data.wiped || {}).filter(([k,v]) => v > 0).map(([k,v]) => k + ': ' + v).join(', ');
            el.className = 'result-box ok';
            el.innerHTML = '<strong>Wipe complete!</strong><br>' +
                'Device: ' + (data.device || '?') + '<br>' +
                'Synced first: pushed ' + (data.sync_pushed||0) + ', pulled ' + (data.sync_pulled||0) + '<br>' +
                'Wiped: ' + (wiped || 'nothing old to clean') + '<br>' +
                'Post-pull: ' + (data.post_pull||0) + ' fresh records';
        } else {
            el.className = 'result-box fail';
            el.innerHTML = data.message || 'Wipe failed';
            showDiagnosis(el, data);
        }
    }).catch(e => {
        el.className = 'result-box fail'; el.textContent = 'Proxy error: ' + e.message;
    });
}

// Auto-check all branches on load
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach ($stores as $store): $dl = substr($store['device_id'], -1); ?>
    checkBranch('<?php echo $dl; ?>', '<?php echo REMOTE_IP; ?>');
    <?php endforeach; ?>
});
</script>
</body>
</html>
