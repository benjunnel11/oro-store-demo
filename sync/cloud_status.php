<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/cloud_config.php';
require_once __DIR__ . '/cloud_stock_sync.php';
require_once __DIR__ . '/config.php';

if (!isAdmin()) { header("Location: /oro-store/cashier/cashier.php"); exit; }
$currentUser = getCurrentUser();

$device = LOCAL_DEVICE_ID;

// Get local store for this device
$store = $conn->query("SELECT id, store_name FROM stores WHERE device_id = '$device' AND status = 'active' LIMIT 1")->fetch_assoc();
if (!$store) $store = $conn->query("SELECT id, store_name FROM stores WHERE status = 'active' ORDER BY id LIMIT 1")->fetch_assoc();
$store_id = $store ? $store['id'] : 0;
$store_name = $store ? $store['store_name'] : 'Unknown';

// Action handlers
// POST: Save local credentials
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cloud_action']) && $_POST['cloud_action'] === 'save_local') {
    $local_data = json_encode([
        'db_host' => trim($_POST['l_db_host'] ?? '127.0.0.1'),
        'db_user' => trim($_POST['l_db_user'] ?? 'root'),
        'db_pass' => trim($_POST['l_db_pass'] ?? ''),
        'db_name' => trim($_POST['l_db_name'] ?? 'product_db'),
        'device_id' => trim($_POST['l_device_id'] ?? 'DEVICE_A'),
        'remote_ip' => trim($_POST['l_remote_ip'] ?? ''),
        'remote_port' => trim($_POST['l_remote_port'] ?? '80'),
        'sync_user' => trim($_POST['l_sync_user'] ?? 'sync_user'),
        'sync_pass' => trim($_POST['l_sync_pass'] ?? '')
    ]);
    $saved = file_put_contents(__DIR__ . '/.local_env', $local_data) !== false;
    header("Location: ?msg=" . ($saved ? 'local_saved' : 'save_failed'));
    exit;
}

// POST: Save cloud credentials
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cloud_action']) && $_POST['cloud_action'] === 'save_credentials') {
    $saved = saveCloudCredentials(
        trim($_POST['cloud_host'] ?? ''),
        trim($_POST['cloud_user'] ?? ''),
        trim($_POST['cloud_pass'] ?? ''),
        trim($_POST['cloud_name'] ?? 'oro_cloud_stock'),
        intval($_POST['cloud_port'] ?? 4000)
    );
    header("Location: ?msg=" . ($saved ? 'credentials_saved' : 'save_failed'));
    exit;
}

$action = $_GET['action'] ?? '';
$message = '';
if (isset($_GET['msg']) && $_GET['msg'] === 'credentials_saved') $message = 'Cloud credentials saved! Refresh to test connection.';
if (isset($_GET['msg']) && $_GET['msg'] === 'local_saved') $message = 'Local credentials saved! Restart the page for changes to take effect.';
if (isset($_GET['msg']) && $_GET['msg'] === 'save_failed') $message = 'Failed to save credentials.';

if ($action === 'push_all') {
    cloudStockInit();
    $pushed = cloudStockPushAll($conn, $store_id);
    $message = "Pushed $pushed products to cloud.";
}
if ($action === 'pull') {
    $pulled = cloudStockPull($conn, $store_id);
    $message = "Pulled $pulled stock updates from cloud.";
}
if ($action === 'flush') {
    $flushed = cloudFlushQueue();
    $message = "Flushed $flushed queued pushes.";
}

// Test cloud connection
$cloud = getCloudConnection();
$cloud_ok = !!$cloud;
$cloud_count = 0;
$cloud_rows = [];
if ($cloud) {
    $r = $cloud->query("SELECT * FROM cloud_stock ORDER BY store_id, product_id");
    if ($r) {
        $cloud_count = $r->num_rows;
        $cloud_rows = $r->fetch_all(MYSQLI_ASSOC);
    }
    $cloud->close();
}

// Get local stock
$local_rows = [];
$r = $conn->query("SELECT sp.product_id, sp.store_id, sp.stock, p.name as product_name, s.store_name
    FROM store_prices sp
    LEFT JOIN products p ON sp.product_id = p.id
    LEFT JOIN stores s ON sp.store_id = s.id
    WHERE sp.is_deleted = 0 ORDER BY sp.store_id, p.name");
if ($r) { while ($row = $r->fetch_assoc()) $local_rows[] = $row; }

// Check queue
$queue_file = __DIR__ . '/cloud_stock_queue.json';
$queue = file_exists($queue_file) ? (json_decode(file_get_contents($queue_file), true) ?: []) : [];

// Get all stores
$all_stores = [];
$r = $conn->query("SELECT id, store_name, store_code, device_id FROM stores WHERE status = 'active' ORDER BY id");
if ($r) { while ($row = $r->fetch_assoc()) $all_stores[] = $row; }

// Build per-store stock from local DB
$per_store_stock = [];
foreach ($local_rows as $lr) {
    $sid = $lr['store_id'];
    if (!isset($per_store_stock[$sid])) $per_store_stock[$sid] = [];
    $per_store_stock[$sid][] = $lr;
}

// Build per-store stock from cloud
$cloud_per_store = [];
foreach ($cloud_rows as $cr) {
    $sid = $cr['store_id'];
    if (!isset($cloud_per_store[$sid])) $cloud_per_store[$sid] = [];
    $cloud_per_store[$sid][] = $cr;
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Cloud Stock Sync Status</title>
<link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
<style>
    .cs-container{max-width:1100px;margin:0 auto;padding:24px 20px;}
    .cs-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
    .cs-header h1{font-size:20px;font-weight:700;color:#1e293b;margin:0;}
    .card{background:#fff;border-radius:10px;padding:16px;margin-bottom:16px;border:1px solid #e2e8f0;}
    .card h2{font-size:13px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;font-weight:700;}
    .status{display:inline-block;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:700;}
    .ok{background:#dcfce7;color:#16a34a;} .fail{background:#fee2e2;color:#dc2626;}
    .info-row{display:flex;justify-content:space-between;padding:6px 0;font-size:13px;border-bottom:1px solid #f1f5f9;}
    .info-row:last-child{border:none;}
    .label{color:#94a3b8;} .val{font-weight:600;color:#1e293b;}
    table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;}
    th{background:#f8fafc;color:#64748b;padding:8px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid #e2e8f0;}
    td{padding:7px 10px;border-bottom:1px solid #f1f5f9;color:#334155;}
    .match{color:#16a34a;} .mismatch{color:#dc2626;font-weight:700;}
    .btn{display:inline-block;padding:8px 16px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;margin-right:6px;margin-bottom:6px;cursor:pointer;border:none;transition:opacity .15s;}
    .btn:hover{opacity:.85;}
    .btn-blue{background:#667eea;color:#fff;} .btn-green{background:#22c55e;color:#fff;} .btn-yellow{background:#f59e0b;color:#000;}
    .msg{background:#dcfce7;color:#16a34a;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:13px;font-weight:600;border:1px solid #bbf7d0;}
    .store-section{margin-bottom:20px;}
    .store-header{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px 8px 0 0;font-weight:700;font-size:14px;color:#1e293b;}
    .store-badge{font-size:10px;padding:2px 8px;border-radius:4px;font-weight:700;}
    .store-badge.mine{background:#dbeafe;color:#2563eb;}
    .store-badge.other{background:#f1f5f9;color:#64748b;}
    .store-table{border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;overflow:hidden;}
    .pw-wrap{position:relative;}
    .pw-wrap input{width:100%;padding:8px 36px 8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;}
    .pw-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:16px;color:#94a3b8;padding:2px;}
    .pw-toggle:hover{color:#334155;}
</style>
</head>
<body>
<?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>
<main class="main-content">
<div class="cs-container">
<div class="cs-header">
    <h1>☁️ Cloud Stock Sync Status</h1>
</div>

<?php if ($message): ?>
<div class="msg"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="card">
    <h2>Device Info</h2>
    <div class="info-row"><span class="label">Device</span><span class="val"><?php echo $device; ?></span></div>
    <div class="info-row"><span class="label">Store</span><span class="val"><?php echo htmlspecialchars($store_name); ?> (ID: <?php echo $store_id; ?>)</span></div>
    <div class="info-row"><span class="label">Cloud Connection</span><span class="status <?php echo $cloud_ok ? 'ok' : 'fail'; ?>"><?php echo $cloud_ok ? 'CONNECTED' : 'FAILED'; ?></span></div>
    <div class="info-row"><span class="label">Cloud Records</span><span class="val"><?php echo $cloud_count; ?></span></div>
    <div class="info-row"><span class="label">Local Records</span><span class="val"><?php echo count($local_rows); ?></span></div>
    <div class="info-row"><span class="label">Queued Pushes</span><span class="val"><?php echo count($queue); ?></span></div>
</div>

<div class="card">
    <h2>Actions</h2>
    <a href="?action=push_all" class="btn btn-blue">Push All Local Stock to Cloud</a>
    <a href="?action=pull" class="btn btn-green">Pull Other Stores from Cloud</a>
    <a href="/oro-store/sync/setup_cloud_sync_task.bat" download class="btn" style="background:#334155;color:#fff;">Download Auto-Sync Installer (.bat)</a>
    <a href="?action=flush" class="btn btn-yellow">Flush Queued Pushes</a>
    <a href="?" class="btn" style="background:#e2e8f0;color:#334155;">Refresh</a>
</div>

<div class="card">
    <h2>Cloud Database Credentials</h2>
    <form method="POST" style="max-width:500px;">
        <input type="hidden" name="cloud_action" value="save_credentials">
        <div style="margin-bottom:10px;">
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Host</label>
            <input type="text" name="cloud_host" value="<?php echo htmlspecialchars(CLOUD_DB_HOST); ?>" placeholder="gateway01.ap-southeast-1.prod.alicloud.tidbcloud.com" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;">
        </div>
        <div style="display:flex;gap:10px;margin-bottom:10px;">
            <div style="flex:1;">
                <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Port</label>
                <input type="number" name="cloud_port" value="<?php echo CLOUD_DB_PORT; ?>" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;">
            </div>
            <div style="flex:2;">
                <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Database Name</label>
                <input type="text" name="cloud_name" value="<?php echo htmlspecialchars(CLOUD_DB_NAME); ?>" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;">
            </div>
        </div>
        <div style="margin-bottom:10px;">
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Username</label>
            <input type="text" name="cloud_user" value="<?php echo htmlspecialchars(CLOUD_DB_USER); ?>" placeholder="username.root" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;">
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Password</label>
            <div class="pw-wrap">
                <input type="password" name="cloud_pass" id="pw-cloud" value="<?php echo htmlspecialchars(CLOUD_DB_PASS); ?>" placeholder="Enter password">
                <button type="button" class="pw-toggle" onclick="togglePw('pw-cloud',this)">👁</button>
            </div>
        </div>
        <button type="submit" class="btn btn-blue">Save Cloud Credentials</button>
    </form>
</div>

<?php
$_le = [];
if (file_exists(__DIR__ . '/.local_env')) $_le = json_decode(file_get_contents(__DIR__ . '/.local_env'), true) ?: [];
?>
<div class="card">
    <h2>Local Device & Database Credentials</h2>
    <form method="POST" style="max-width:500px;">
        <input type="hidden" name="cloud_action" value="save_local">
        <div style="display:flex;gap:10px;margin-bottom:10px;">
            <div style="flex:1;">
                <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Device ID</label>
                <input type="text" name="l_device_id" value="<?php echo htmlspecialchars($_le['device_id'] ?? 'DEVICE_A'); ?>" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;">
            </div>
            <div style="flex:1;">
                <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">DB Host</label>
                <input type="text" name="l_db_host" value="<?php echo htmlspecialchars($_le['db_host'] ?? '127.0.0.1'); ?>" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;">
            </div>
        </div>
        <div style="display:flex;gap:10px;margin-bottom:10px;">
            <div style="flex:1;">
                <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">DB Username</label>
                <input type="text" name="l_db_user" value="<?php echo htmlspecialchars($_le['db_user'] ?? 'root'); ?>" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;">
            </div>
            <div style="flex:1;">
                <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">DB Password</label>
                <div class="pw-wrap">
                    <input type="password" name="l_db_pass" id="pw-db" value="<?php echo htmlspecialchars($_le['db_pass'] ?? ''); ?>">
                    <button type="button" class="pw-toggle" onclick="togglePw('pw-db',this)">👁</button>
                </div>
            </div>
        </div>
        <div style="margin-bottom:10px;">
            <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">DB Name</label>
            <input type="text" name="l_db_name" value="<?php echo htmlspecialchars($_le['db_name'] ?? 'product_db'); ?>" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;">
        </div>
        <div style="display:flex;gap:10px;margin-bottom:10px;">
            <div style="flex:2;">
                <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Remote Device IP</label>
                <input type="text" name="l_remote_ip" value="<?php echo htmlspecialchars($_le['remote_ip'] ?? ''); ?>" placeholder="10.219.18.250" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;">
            </div>
            <div style="flex:1;">
                <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Remote Port</label>
                <input type="text" name="l_remote_port" value="<?php echo htmlspecialchars($_le['remote_port'] ?? '80'); ?>" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;">
            </div>
        </div>
        <div style="display:flex;gap:10px;margin-bottom:14px;">
            <div style="flex:1;">
                <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Sync Username</label>
                <input type="text" name="l_sync_user" value="<?php echo htmlspecialchars($_le['sync_user'] ?? 'sync_user'); ?>" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;outline:none;">
            </div>
            <div style="flex:1;">
                <label style="display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;margin-bottom:4px;">Sync Password</label>
                <div class="pw-wrap">
                    <input type="password" name="l_sync_pass" id="pw-sync" value="<?php echo htmlspecialchars($_le['sync_pass'] ?? ''); ?>">
                    <button type="button" class="pw-toggle" onclick="togglePw('pw-sync',this)">👁</button>
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-blue">Save Local Credentials</button>
    </form>
</div>

<div class="card">
    <h2>Stock Comparison (Local vs Cloud)</h2>
    <table>
        <thead><tr><th>Product</th><th>Store</th><th>Local Stock</th><th>Cloud Stock</th><th>Status</th></tr></thead>
        <tbody>
        <?php
        // Build cloud lookup
        $cloud_lookup = [];
        foreach ($cloud_rows as $cr) {
            $cloud_lookup[$cr['product_id'] . '_' . $cr['store_id']] = $cr;
        }
        foreach ($local_rows as $lr) {
            $key = $lr['product_id'] . '_' . $lr['store_id'];
            $cloud_stock = isset($cloud_lookup[$key]) ? (int)$cloud_lookup[$key]['stock'] : null;
            $local_stock = (int)$lr['stock'];
            $match = $cloud_stock !== null && $cloud_stock === $local_stock;
            $status_class = $cloud_stock === null ? 'label' : ($match ? 'match' : 'mismatch');
            $status_text = $cloud_stock === null ? 'Not in cloud' : ($match ? 'In sync' : 'OUT OF SYNC');
            echo "<tr>
                <td>{$lr['product_name']}</td>
                <td>{$lr['store_name']}</td>
                <td>{$local_stock}</td>
                <td>" . ($cloud_stock !== null ? $cloud_stock : '—') . "</td>
                <td class='{$status_class}'>{$status_text}</td>
            </tr>";
            if (isset($cloud_lookup[$key])) unset($cloud_lookup[$key]);
        }
        // Cloud-only rows (not in local DB)
        foreach ($cloud_lookup as $cr) {
            echo "<tr>
                <td>Product #{$cr['product_id']}</td>
                <td>Store #{$cr['store_id']}</td>
                <td>—</td>
                <td>{$cr['stock']}</td>
                <td class='label'>Cloud only</td>
            </tr>";
        }
        ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Cloud Database (All Records)</h2>
    <table>
        <thead><tr><th>UID</th><th>Product</th><th>Store</th><th>Stock</th><th>Updated</th><th>Device</th></tr></thead>
        <tbody>
        <?php foreach ($cloud_rows as $r): ?>
        <tr>
            <td style="font-family:monospace;font-size:10px;"><?php echo $r['product_uid']; ?></td>
            <?php
                $local_name = '—';
                foreach ($local_rows as $lr) { if ($lr['product_id'] == $r['product_id']) { $local_name = $lr['product_name']; break; } }
            ?>
            <td><?php echo htmlspecialchars($local_name); ?></td>
            <td><?php echo $r['store_id']; ?></td>
            <td style="font-weight:700;"><?php echo $r['stock']; ?></td>
            <td style="font-size:10px;color:#64748b;"><?php echo $r['updated_at']; ?></td>
            <td><?php echo $r['updated_by_device']; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Per-Store Stock -->
<div class="card">
    <h2>Stock by Store</h2>
    <?php foreach ($all_stores as $st):
        $sid = $st['id'];
        $is_mine = ($sid == $store_id);
        $store_products = $per_store_stock[$sid] ?? [];
        $cloud_products = $cloud_per_store[$sid] ?? [];
        $cloud_by_pid = [];
        foreach ($cloud_products as $cp) { $cloud_by_pid[$cp['product_id']] = (int)$cp['stock']; }
    ?>
    <div class="store-section">
        <div class="store-header">
            <?php echo htmlspecialchars($st['store_name']); ?>
            <span class="store-badge <?php echo $is_mine ? 'mine' : 'other'; ?>"><?php echo $is_mine ? 'THIS DEVICE' : ($st['device_id'] ?: 'No Device'); ?></span>
            <?php if ($st['store_code']): ?><span style="font-size:11px;color:#94a3b8;font-weight:400;">(<?php echo htmlspecialchars($st['store_code']); ?>)</span><?php endif; ?>
            <span style="margin-left:auto;font-size:12px;color:#94a3b8;font-weight:400;"><?php echo count($store_products); ?> products</span>
        </div>
        <div class="store-table">
        <table>
            <thead><tr><th>Product</th><th style="text-align:right;">Local Stock</th><th style="text-align:right;">Cloud Stock</th><th style="text-align:center;">Status</th></tr></thead>
            <tbody>
            <?php if (empty($store_products)): ?>
                <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:16px;">No products for this store</td></tr>
            <?php else: ?>
                <?php foreach ($store_products as $sp):
                    $local = (int)$sp['stock'];
                    $cloud = isset($cloud_by_pid[$sp['product_id']]) ? $cloud_by_pid[$sp['product_id']] : null;
                    $synced = $cloud !== null && $cloud === $local;
                ?>
                <tr>
                    <td style="font-weight:600;"><?php echo htmlspecialchars($sp['product_name']); ?></td>
                    <td style="text-align:right;font-weight:700;"><?php echo $local; ?></td>
                    <td style="text-align:right;font-weight:700;color:<?php echo $cloud === null ? '#94a3b8' : ($synced ? '#16a34a' : '#dc2626'); ?>;"><?php echo $cloud !== null ? $cloud : '—'; ?></td>
                    <td style="text-align:center;">
                        <?php if ($cloud === null): ?>
                            <span style="color:#94a3b8;font-size:11px;">Not synced</span>
                        <?php elseif ($synced): ?>
                            <span class="match" style="font-size:11px;">✓ In sync</span>
                        <?php else: ?>
                            <span class="mismatch" style="font-size:11px;">✕ Mismatch (<?php echo $local - $cloud > 0 ? '+' : ''; ?><?php echo $local - $cloud; ?>)</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <h2>Auto Sync</h2>
    <div class="info-row"><span class="label">Status</span><span class="val" id="auto-sync-status">Starting...</span></div>
    <div class="info-row"><span class="label">Last sync</span><span class="val" id="auto-sync-time">—</span></div>
    <div class="info-row"><span class="label">Next refresh</span><span class="val" id="auto-sync-countdown">30s</span></div>
</div>

</div><!-- /.cs-container -->
</main>
<script>
var _syncInterval = 30;
var _countdown = _syncInterval;

function autoSync() {
    document.getElementById('auto-sync-status').textContent = 'Syncing...';
    fetch('/oro-store/sync/cloud_pull.php')
        .then(function(r) { return r.text(); })
        .then(function(text) {
            try {
                var data = JSON.parse(text);
                var status = 'Pushed ' + (data.pushed || 0) + ', Pulled ' + (data.pulled || 0) + ', Flushed ' + (data.flushed || 0);
                document.getElementById('auto-sync-status').textContent = data.success ? status : 'Error: ' + (data.error || 'Unknown');
            } catch(e) {
                document.getElementById('auto-sync-status').textContent = 'Response error';
            }
            document.getElementById('auto-sync-time').textContent = new Date().toLocaleTimeString();
            setTimeout(function() { location.reload(); }, 2000);
        })
        .catch(function() {
            document.getElementById('auto-sync-status').textContent = 'Network error';
            document.getElementById('auto-sync-time').textContent = new Date().toLocaleTimeString();
        });
}

function tick() {
    _countdown--;
    document.getElementById('auto-sync-countdown').textContent = _countdown + 's';
    if (_countdown <= 0) {
        _countdown = _syncInterval;
        autoSync();
    }
}

setInterval(tick, 1000);

function togglePw(id, btn) {
    var inp = document.getElementById(id);
    if (inp.type === 'password') { inp.type = 'text'; btn.textContent = '🙈'; }
    else { inp.type = 'password'; btn.textContent = '👁'; }
}
</script>
</body>
</html>
