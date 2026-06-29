<?php
// sync/sync_status.php
// View at: http://localhost/oro-store/sync/sync_status.php
require_once 'config.php';

$local = getLocalConnection();

// Last 10 sync runs
$history = $local->query("
    SELECT * FROM sync_status
    ORDER BY last_sync_time DESC
    LIMIT 10
");

// Pending unsynced counts per table
$pending = [];
foreach (SYNC_TABLES as $table) {
    $check = $local->query("SHOW TABLES LIKE '$table'");
    if (!$check || $check->num_rows === 0) continue;
    $res = $local->query("SELECT COUNT(*) as c FROM sync_log WHERE table_name = '$table' AND synced = 0 AND device_id = '" . LOCAL_DEVICE_ID . "'");
    $row = $res->fetch_assoc();
    if ($row['c'] > 0) $pending[$table] = $row['c'];
}

$total_pending = array_sum($pending);

// Last successful sync
$last_ok = $local->query("SELECT last_sync_time FROM sync_status WHERE status = 'SUCCESS' ORDER BY last_sync_time DESC LIMIT 1")->fetch_assoc();
$last_ok_time = $last_ok ? $last_ok['last_sync_time'] : 'Never';

// Remote available
$remote_up = isRemoteAvailable();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="refresh" content="15">
<title>Sync Status — <?php echo LOCAL_DEVICE_ID; ?></title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#080b10;--surface:#0e1219;--surface2:#141a24;
  --border:#1e2736;--border2:#283040;
  --text:#d0daf0;--muted:#5a6a88;
  --green:#22c55e;--red:#ef4444;--amber:#f59e0b;--blue:#3b82f6;
  --green-dim:rgba(34,197,94,.1);--red-dim:rgba(239,68,68,.1);
  --amber-dim:rgba(245,158,11,.1);--blue-dim:rgba(59,130,246,.1);
  --mono:'IBM Plex Mono',monospace;--sans:'IBM Plex Sans',sans-serif;
}
html{font-size:13px}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;padding:32px 24px;
  background-image:radial-gradient(ellipse 80% 40% at 50% -5%,rgba(34,197,94,.06) 0%,transparent 60%);}
.page{max-width:760px;margin:0 auto;}
.header{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;}
.header-left{display:flex;align-items:center;gap:12px;}
.header-icon{width:42px;height:42px;border-radius:10px;background:var(--green-dim);border:1px solid rgba(34,197,94,.2);display:flex;align-items:center;justify-content:center;font-size:20px;}
.header-title{font-size:16px;font-weight:600;}
.header-sub{font-size:11px;color:var(--muted);margin-top:2px;font-family:var(--mono);}
.grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px;}
.stat{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:16px 18px;}
.stat-label{font-size:11px;color:var(--muted);font-family:var(--mono);margin-bottom:6px;}
.stat-value{font-size:22px;font-weight:600;font-family:var(--mono);}
.stat-value.ok{color:var(--green);}
.stat-value.fail{color:var(--red);}
.stat-value.warn{color:var(--amber);}
.stat-value.info{color:var(--blue);}
.block{background:var(--surface);border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:12px;}
.block-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border);font-weight:600;font-size:13px;}
.block-body{padding:14px 18px;}
.row{display:flex;align-items:center;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:12px;}
.row:last-child{border-bottom:none;}
.row-label{color:var(--muted);font-family:var(--mono);}
.row-val{font-family:var(--mono);font-weight:500;}
.badge{padding:3px 10px;border-radius:3px;font-family:var(--mono);font-size:11px;font-weight:700;letter-spacing:.5px;}
.badge-ok{background:var(--green-dim);color:var(--green);border:1px solid rgba(34,197,94,.2);}
.badge-fail{background:var(--red-dim);color:var(--red);border:1px solid rgba(239,68,68,.2);}
.badge-warn{background:var(--amber-dim);color:var(--amber);border:1px solid rgba(245,158,11,.2);}
.sync-btn{display:inline-block;padding:10px 24px;background:var(--green-dim);border:1px solid rgba(34,197,94,.3);
  color:var(--green);border-radius:6px;font-family:var(--mono);font-size:12px;font-weight:600;
  text-decoration:none;cursor:pointer;letter-spacing:.5px;transition:all .2s;}
.sync-btn:hover{background:rgba(34,197,94,.2);border-color:rgba(34,197,94,.5);}
.pulse{width:6px;height:6px;border-radius:50%;background:var(--green);animation:pulse 2s infinite;display:inline-block;margin-right:6px;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}
.empty{color:var(--muted);font-family:var(--mono);font-size:12px;padding:8px 0;}
</style>
</head>
<body>
<div class="page">

<div class="header">
  <div class="header-left">
    <div class="header-icon">🔄</div>
    <div>
      <div class="header-title">Sync Status — <?php echo LOCAL_DEVICE_ID; ?></div>
      <div class="header-sub"><?php echo LOCAL_IP; ?> ↔ <?php echo REMOTE_IP; ?> · <?php echo date('H:i:s'); ?></div>
    </div>
  </div>
  <a href="sync.php" class="sync-btn">▶ Run Sync Now</a>
</div>

<!-- STATS -->
<div class="grid">
  <div class="stat">
    <div class="stat-label">remote status</div>
    <div class="stat-value <?php echo $remote_up ? 'ok' : 'fail'; ?>">
      <?php echo $remote_up ? '● ONLINE' : '● OFFLINE'; ?>
    </div>
  </div>
  <div class="stat">
    <div class="stat-label">pending to push</div>
    <div class="stat-value <?php echo $total_pending > 0 ? 'warn' : 'ok'; ?>">
      <?php echo $total_pending; ?> records
    </div>
  </div>
  <div class="stat">
    <div class="stat-label">last successful sync</div>
    <div class="stat-value info" style="font-size:13px;margin-top:4px;">
      <?php echo $last_ok_time === 'Never' ? 'Never' : date('M d H:i', strtotime($last_ok_time)); ?>
    </div>
  </div>
</div>

<!-- PENDING BY TABLE -->
<?php if (!empty($pending)): ?>
<div class="block">
  <div class="block-header">
    <span>⏳ Pending Changes by Table</span>
    <span class="badge badge-warn"><?php echo $total_pending; ?> total</span>
  </div>
  <div class="block-body">
    <?php foreach ($pending as $table => $count): ?>
    <div class="row">
      <span class="row-label"><?php echo $table; ?></span>
      <span class="row-val warn" style="color:var(--amber)"><?php echo $count; ?> unsynced</span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- SYNC HISTORY -->
<div class="block">
  <div class="block-header">
    <span>📋 Sync History</span>
    <span style="font-size:11px;color:var(--muted);font-family:var(--mono)"><span class="pulse"></span>auto-refresh 15s</span>
  </div>
  <div class="block-body">
    <?php if ($history && $history->num_rows > 0): ?>
      <?php while ($row = $history->fetch_assoc()): ?>
      <div class="row">
        <span class="row-label"><?php echo date('M d H:i:s', strtotime($row['last_sync_time'])); ?></span>
        <span style="color:var(--muted);font-family:var(--mono);font-size:11px;">
          ↑<?php echo $row['records_pushed']; ?> ↓<?php echo $row['records_pulled']; ?>
        </span>
        <span class="badge <?php echo $row['status'] === 'SUCCESS' ? 'badge-ok' : 'badge-fail'; ?>">
          <?php echo $row['status']; ?>
        </span>
      </div>
      <?php if ($row['error_message']): ?>
        <div style="font-family:var(--mono);font-size:11px;color:#fca5a5;padding:4px 0 8px 0;">
          <?php echo htmlspecialchars($row['error_message']); ?>
        </div>
      <?php endif; ?>
      <?php endwhile; ?>
    <?php else: ?>
      <div class="empty">No sync history yet — click "Run Sync Now" to start</div>
    <?php endif; ?>
  </div>
</div>

<!-- CONFIG -->
<div class="block">
  <div class="block-header"><span>⚙️ Configuration</span></div>
  <div class="block-body">
    <div class="row"><span class="row-label">local device</span><span class="row-val info"><?php echo LOCAL_DEVICE_ID; ?> · <?php echo LOCAL_IP; ?></span></div>
    <div class="row"><span class="row-label">remote device</span><span class="row-val info"><?php echo REMOTE_IP; ?>:<?php echo REMOTE_PORT; ?></span></div>
    <div class="row"><span class="row-label">database</span><span class="row-val info"><?php echo DB_NAME; ?></span></div>
    <div class="row"><span class="row-label">tables synced</span><span class="row-val info"><?php echo count(SYNC_TABLES); ?> tables</span></div>
    <div class="row"><span class="row-label">sync user</span><span class="row-val info"><?php echo SYNC_USER; ?></span></div>
  </div>
</div>

</div>
</body>
</html>