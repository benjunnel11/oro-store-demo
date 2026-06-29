<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

if (!isAdmin()) {
    header("Location: /oro-store/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
    $export_to = $_GET['date_to'] ?? date('Y-m-d');
    $export_cat = $_GET['category'] ?? 'all';

    $eq = "SELECT sl.created_at, sl.activity_category, sl.activity_type, sl.description,
                  u.full_name, s.store_code, sl.ip_address, sl.device_id
           FROM system_logs sl
           LEFT JOIN users u ON sl.user_id = u.id
           LEFT JOIN stores s ON sl.store_id = s.id
           WHERE DATE(sl.created_at) BETWEEN ? AND ? AND sl.is_deleted = 0";
    $ep = [$export_from, $export_to];
    $et = "ss";
    if ($export_cat !== 'all') {
        $eq .= " AND sl.activity_category = ?";
        $ep[] = $export_cat;
        $et .= "s";
    }
    $eq .= " ORDER BY sl.created_at DESC";
    $es = $conn->prepare($eq);
    $es->bind_param($et, ...$ep);
    $es->execute();
    $rows = $es->get_result()->fetch_all(MYSQLI_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=activity_log_' . $export_from . '_to_' . $export_to . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date/Time', 'Category', 'Type', 'Description', 'User', 'Store', 'IP', 'Device']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['created_at'], $r['activity_category'], $r['activity_type'],
            $r['description'], $r['full_name'] ?? 'System', $r['store_code'] ?? '',
            $r['ip_address'] ?? '', $r['device_id'] ?? ''
        ]);
    }
    fclose($out);
    $conn->close();
    exit;
}

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filters
$filter_category = $_GET['category'] ?? 'all';
$filter_user = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$filter_store = isset($_GET['store_id']) ? intval($_GET['store_id']) : 0;
$filter_device = $_GET['device_id'] ?? 'all';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// Summary stats
$summary = [];
$summary['total'] = $conn->query("SELECT COUNT(*) as c FROM system_logs WHERE is_deleted = 0")->fetch_assoc()['c'];
$summary['today'] = $conn->query("SELECT COUNT(*) as c FROM system_logs WHERE DATE(created_at) = CURDATE() AND is_deleted = 0")->fetch_assoc()['c'];
$summary['this_week'] = $conn->query("SELECT COUNT(*) as c FROM system_logs WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) AND is_deleted = 0")->fetch_assoc()['c'];

$cat_counts_raw = $conn->query("
    SELECT activity_category, COUNT(*) as c
    FROM system_logs
    WHERE DATE(created_at) BETWEEN '{$conn->real_escape_string($date_from)}' AND '{$conn->real_escape_string($date_to)}'
    AND is_deleted = 0
    GROUP BY activity_category
    ORDER BY c DESC
")->fetch_all(MYSQLI_ASSOC);
$cat_counts = [];
foreach ($cat_counts_raw as $cc) {
    $cat_counts[$cc['activity_category']] = $cc['c'];
}

// Build filtered query
$where_conditions = ["DATE(sl.created_at) BETWEEN ? AND ?", "sl.is_deleted = 0"];
$params = [$date_from, $date_to];
$types = "ss";

if ($filter_category !== 'all') {
    $where_conditions[] = "sl.activity_category = ?";
    $params[] = $filter_category;
    $types .= "s";
}
if ($filter_user > 0) {
    $where_conditions[] = "sl.user_id = ?";
    $params[] = $filter_user;
    $types .= "i";
}
if ($filter_store > 0) {
    $where_conditions[] = "sl.store_id = ?";
    $params[] = $filter_store;
    $types .= "i";
}
if ($filter_device !== 'all') {
    $where_conditions[] = "sl.device_id = ?";
    $params[] = $filter_device;
    $types .= "s";
}
if (!empty($search)) {
    $where_conditions[] = "(sl.description LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)";
    $sp = "%{$search}%";
    $params[] = $sp;
    $params[] = $sp;
    $params[] = $sp;
    $types .= "sss";
}

$where_clause = implode(" AND ", $where_conditions);

// Count
$cs = $conn->prepare("SELECT COUNT(*) as total FROM system_logs sl LEFT JOIN users u ON sl.user_id = u.id WHERE {$where_clause}");
$cs->bind_param($types, ...$params);
$cs->execute();
$total_logs = $cs->get_result()->fetch_assoc()['total'];
$total_pages = max(1, ceil($total_logs / $per_page));

// Logs
$lq = "SELECT sl.*, u.username, u.full_name, s.store_name, s.store_code
       FROM system_logs sl
       LEFT JOIN users u ON sl.user_id = u.id
       LEFT JOIN stores s ON sl.store_id = s.id
       WHERE {$where_clause}
       ORDER BY sl.created_at DESC
       LIMIT ? OFFSET ?";
$log_params = array_merge($params, [$per_page, $offset]);
$log_types = $types . "ii";
$ls = $conn->prepare($lq);
$ls->bind_param($log_types, ...$log_params);
$ls->execute();
$system_logs = $ls->get_result()->fetch_all(MYSQLI_ASSOC);

// Filter dropdowns
$users = $conn->query("SELECT id, full_name FROM users WHERE is_deleted = 0 ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$stores = $conn->query("SELECT id, store_name, store_code FROM stores WHERE is_deleted = 0 ORDER BY store_name")->fetch_all(MYSQLI_ASSOC);
$devices = $conn->query("SELECT DISTINCT device_id FROM system_logs WHERE device_id IS NOT NULL AND device_id != '' ORDER BY device_id")->fetch_all(MYSQLI_ASSOC);

$conn->close();

// Build query string helper for pagination
function buildQS($p) {
    global $filter_category, $filter_user, $filter_store, $filter_device, $date_from, $date_to, $search;
    return http_build_query(array_filter([
        'page' => $p, 'category' => $filter_category, 'user_id' => $filter_user,
        'store_id' => $filter_store, 'device_id' => $filter_device,
        'date_from' => $date_from, 'date_to' => $date_to, 'search' => $search
    ], fn($v) => $v !== '' && $v !== 0 && $v !== 'all'));
}

$icons = [
    'auth' => '&#128274;', 'product' => '&#128230;', 'transaction' => '&#128179;',
    'user' => '&#128101;', 'store' => '&#127978;', 'system' => '&#9881;',
    'reprint' => '&#128424;', 'payroll' => '&#128188;', 'delivery' => '&#128666;',
    'credit' => '&#128180;'
];
$cat_labels = [
    'auth' => 'Authentication', 'product' => 'Products', 'transaction' => 'Transactions',
    'user' => 'Users', 'store' => 'Stores', 'system' => 'System',
    'reprint' => 'Reprints', 'payroll' => 'Payroll', 'delivery' => 'Delivery',
    'credit' => 'Credit'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Activity Log - Oro Store</title>
    <link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
    <style>
        .log-stats { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
        .log-stat {
            background: #fff; padding: 16px 20px; border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06); flex: 1; min-width: 140px;
        }
        .log-stat .ls-label { font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px; }
        .log-stat .ls-value { font-size: 24px; font-weight: 800; color: #0f172a; }
        .log-stat .ls-sub { font-size: 11px; color: #94a3b8; margin-top: 2px; }

        .cat-chips { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
        .cat-chip {
            padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;
            text-decoration: none; border: 1px solid #e2e8f0; color: #475569;
            background: #fff; transition: all 0.15s; display: inline-flex; align-items: center; gap: 6px;
        }
        .cat-chip:hover { border-color: #818cf8; color: #4f46e5; }
        .cat-chip.active { background: #6366f1; color: #fff; border-color: #6366f1; }
        .cat-chip .chip-count {
            background: rgba(0,0,0,0.1); padding: 1px 7px; border-radius: 10px; font-size: 11px;
        }
        .cat-chip.active .chip-count { background: rgba(255,255,255,0.25); }

        .log-item {
            display: flex; gap: 14px; padding: 14px 18px; border-radius: 10px;
            margin-bottom: 6px; transition: background 0.15s; border-left: 3px solid transparent;
        }
        .log-item:hover { background: #f8fafc; }
        .log-item.cat-auth { border-left-color: #3b82f6; }
        .log-item.cat-product { border-left-color: #f59e0b; }
        .log-item.cat-transaction { border-left-color: #22c55e; }
        .log-item.cat-user { border-left-color: #8b5cf6; }
        .log-item.cat-store { border-left-color: #06b6d4; }
        .log-item.cat-system { border-left-color: #ef4444; }
        .log-item.cat-reprint { border-left-color: #6366f1; }
        .log-item.cat-payroll { border-left-color: #a855f7; }
        .log-item.cat-delivery { border-left-color: #f97316; }
        .log-item.cat-credit { border-left-color: #14b8a6; }

        .log-icon {
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .log-icon.auth { background: #dbeafe; }
        .log-icon.product { background: #fef3c7; }
        .log-icon.transaction { background: #dcfce7; }
        .log-icon.user { background: #f3e8ff; }
        .log-icon.store { background: #cffafe; }
        .log-icon.system { background: #fee2e2; }
        .log-icon.reprint { background: #e0e7ff; }
        .log-icon.payroll { background: #f3e8ff; }
        .log-icon.delivery { background: #ffedd5; }
        .log-icon.credit { background: #ccfbf1; }

        .log-body { flex: 1; min-width: 0; }
        .log-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px; }
        .log-user { font-weight: 600; color: #0f172a; font-size: 14px; }
        .log-time { font-size: 12px; color: #94a3b8; white-space: nowrap; }
        .log-desc { font-size: 13px; color: #475569; margin-bottom: 6px; }

        .log-tags { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .log-tag {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 11px; color: #64748b; background: #f1f5f9;
            padding: 2px 8px; border-radius: 4px;
        }

        .cat-badge {
            display: inline-block; padding: 2px 8px; border-radius: 4px;
            font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
        }
        .cat-badge.auth { background: #dbeafe; color: #1e40af; }
        .cat-badge.product { background: #fef3c7; color: #92400e; }
        .cat-badge.transaction { background: #dcfce7; color: #166534; }
        .cat-badge.user { background: #f3e8ff; color: #6b21a8; }
        .cat-badge.store { background: #cffafe; color: #155e75; }
        .cat-badge.system { background: #fee2e2; color: #991b1b; }
        .cat-badge.reprint { background: #e0e7ff; color: #3730a3; }
        .cat-badge.payroll { background: #f3e8ff; color: #6b21a8; }
        .cat-badge.delivery { background: #ffedd5; color: #c2410c; }
        .cat-badge.credit { background: #ccfbf1; color: #115e59; }

        .log-details-toggle {
            font-size: 12px; color: #6366f1; cursor: pointer; font-weight: 600;
            background: none; border: none; padding: 0; margin-top: 4px;
        }
        .log-details-toggle:hover { color: #4f46e5; }
        .log-details-box {
            display: none; margin-top: 8px; padding: 10px 14px;
            background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;
            font-size: 12px; color: #475569;
        }
        .log-details-box.open { display: block; }
        .detail-kv { display: flex; gap: 8px; padding: 3px 0; border-bottom: 1px solid #f1f5f9; }
        .detail-kv:last-child { border-bottom: none; }
        .detail-key { font-weight: 600; color: #64748b; min-width: 120px; }
        .detail-val { color: #0f172a; word-break: break-all; }

        .actions-row {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
        }
        .actions-row .result-info { font-size: 13px; color: #64748b; }

        .quick-dates { display: flex; gap: 6px; margin-top: 4px; }
        .quick-dates button {
            padding: 4px 10px; font-size: 11px; background: #e2e8f0; border: none;
            border-radius: 6px; cursor: pointer; color: #475569; font-weight: 600;
        }
        .quick-dates button:hover { background: #cbd5e1; }

        @media (max-width: 600px) {
            .log-stats { flex-direction: column; }
            .cat-chips { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 4px; }
        }
    </style>
</head>
<body>
<?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

<main class="main-content">
    <div class="page-header">
        <h1>Activity Log</h1>
        <p>System-wide activity tracking and audit trail</p>
    </div>

    <!-- Stats -->
    <div class="log-stats">
        <div class="log-stat">
            <div class="ls-label">Today</div>
            <div class="ls-value"><?php echo number_format($summary['today']); ?></div>
            <div class="ls-sub">activities</div>
        </div>
        <div class="log-stat">
            <div class="ls-label">This Week</div>
            <div class="ls-value"><?php echo number_format($summary['this_week']); ?></div>
            <div class="ls-sub">activities</div>
        </div>
        <div class="log-stat">
            <div class="ls-label">All Time</div>
            <div class="ls-value"><?php echo number_format($summary['total']); ?></div>
            <div class="ls-sub">total records</div>
        </div>
        <div class="log-stat">
            <div class="ls-label">Filtered Results</div>
            <div class="ls-value"><?php echo number_format($total_logs); ?></div>
            <div class="ls-sub"><?php echo $date_from === $date_to ? date('M j', strtotime($date_from)) : date('M j', strtotime($date_from)) . ' - ' . date('M j', strtotime($date_to)); ?></div>
        </div>
    </div>

    <!-- Category chips -->
    <div class="cat-chips">
        <a href="?<?php echo buildQS(1) . '&category=all'; ?>" class="cat-chip<?php echo $filter_category === 'all' ? ' active' : ''; ?>">
            All <span class="chip-count"><?php echo array_sum($cat_counts); ?></span>
        </a>
        <?php foreach ($cat_counts as $cat => $cnt): if (empty($cat)) continue; ?>
            <a href="?<?php echo buildQS(1) . '&category=' . urlencode($cat); ?>"
               class="cat-chip<?php echo $filter_category === $cat ? ' active' : ''; ?>">
                <?php echo $icons[$cat] ?? ''; ?> <?php echo $cat_labels[$cat] ?? ucfirst($cat); ?>
                <span class="chip-count"><?php echo $cnt; ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="content-section">
        <form method="GET" class="filter-bar" id="filterForm">
            <div class="filter-group">
                <label>Date From</label>
                <input type="date" name="date_from" id="dateFrom" value="<?php echo $date_from; ?>">
                <div class="quick-dates">
                    <button type="button" onclick="setRange('today')">Today</button>
                    <button type="button" onclick="setRange('week')">Week</button>
                    <button type="button" onclick="setRange('month')">Month</button>
                    <button type="button" onclick="setRange('all')">All</button>
                </div>
            </div>
            <div class="filter-group">
                <label>Date To</label>
                <input type="date" name="date_to" id="dateTo" value="<?php echo $date_to; ?>">
            </div>
            <div class="filter-group">
                <label>User</label>
                <select name="user_id">
                    <option value="0">All Users</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo $u['id']; ?>" <?php echo $filter_user == $u['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Store</label>
                <select name="store_id">
                    <option value="0">All Stores</option>
                    <?php foreach ($stores as $st): ?>
                        <option value="<?php echo $st['id']; ?>" <?php echo $filter_store == $st['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($st['store_code'] . ' - ' . $st['store_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Device</label>
                <select name="device_id">
                    <option value="all">All Devices</option>
                    <?php foreach ($devices as $d): ?>
                        <option value="<?php echo htmlspecialchars($d['device_id']); ?>"
                                <?php echo $filter_device === $d['device_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($d['device_id']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Search</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Description, username...">
            </div>
            <input type="hidden" name="category" value="<?php echo htmlspecialchars($filter_category); ?>">
            <div class="filter-group" style="min-width: auto;">
                <label>&nbsp;</label>
                <div style="display:flex;gap:6px;">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="/oro-store/admin/activity_log.php" class="btn btn-secondary btn-sm">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Actions row -->
    <div class="actions-row">
        <span class="result-info">
            Showing <?php echo min($offset + 1, $total_logs); ?>-<?php echo min($offset + $per_page, $total_logs); ?>
            of <?php echo number_format($total_logs); ?> results
            (Page <?php echo $page; ?>/<?php echo $total_pages; ?>)
        </span>
        <div style="display:flex;gap:8px;">
            <a href="?export=csv&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&category=<?php echo $filter_category; ?>"
               class="btn btn-success btn-sm">&#8681; Export CSV</a>
            <button onclick="window.print()" class="btn btn-secondary btn-sm">&#128424; Print</button>
        </div>
    </div>

    <!-- Log list -->
    <div class="content-section" style="padding: 10px 0;">
        <?php if (empty($system_logs)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">&#128237;</div>
                <p>No activities found for the selected filters</p>
            </div>
        <?php else: ?>
            <?php foreach ($system_logs as $idx => $log): ?>
                <div class="log-item cat-<?php echo htmlspecialchars($log['activity_category']); ?>">
                    <div class="log-icon <?php echo htmlspecialchars($log['activity_category']); ?>">
                        <?php echo $icons[$log['activity_category']] ?? '&#128221;'; ?>
                    </div>
                    <div class="log-body">
                        <div class="log-top">
                            <span class="log-user">
                                <span class="cat-badge <?php echo htmlspecialchars($log['activity_category']); ?>">
                                    <?php echo strtoupper($log['activity_category']); ?>
                                </span>
                                <?php echo htmlspecialchars($log['full_name'] ?: 'System'); ?>
                            </span>
                            <span class="log-time"><?php echo date('M j, g:i A', strtotime($log['created_at'])); ?></span>
                        </div>
                        <div class="log-desc"><?php echo htmlspecialchars($log['description']); ?></div>
                        <div class="log-tags">
                            <?php if ($log['store_code']): ?>
                                <span class="log-tag">&#127978; <?php echo htmlspecialchars($log['store_code']); ?></span>
                            <?php endif; ?>
                            <?php if ($log['device_id']): ?>
                                <span class="log-tag">&#128187; <?php echo htmlspecialchars($log['device_id']); ?></span>
                            <?php endif; ?>
                            <?php if ($log['ip_address']): ?>
                                <span class="log-tag">&#127760; <?php echo htmlspecialchars($log['ip_address']); ?></span>
                            <?php endif; ?>
                            <span class="log-tag">&#128347; <?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></span>
                            <span class="log-tag">#<?php echo $log['id']; ?></span>
                        </div>
                        <?php if ($log['details']): ?>
                            <?php $details = json_decode($log['details'], true); ?>
                            <?php if ($details && is_array($details)): ?>
                                <button class="log-details-toggle" onclick="toggleDetails(<?php echo $idx; ?>)">
                                    &#9654; Show details
                                </button>
                                <div class="log-details-box" id="details-<?php echo $idx; ?>">
                                    <?php foreach ($details as $k => $v): ?>
                                        <div class="detail-kv">
                                            <span class="detail-key"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $k))); ?></span>
                                            <span class="detail-val"><?php
                                                if (is_array($v)) {
                                                    echo htmlspecialchars(json_encode($v));
                                                } elseif (is_bool($v)) {
                                                    echo $v ? 'Yes' : 'No';
                                                } elseif (is_null($v)) {
                                                    echo '<em style="color:#94a3b8;">N/A</em>';
                                                } else {
                                                    echo htmlspecialchars((string)$v);
                                                }
                                            ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?php echo buildQS(1); ?>">&#171; First</a>
                <a href="?<?php echo buildQS($page - 1); ?>">&#8249; Prev</a>
            <?php else: ?>
                <span class="disabled">&#171; First</span>
                <span class="disabled">&#8249; Prev</span>
            <?php endif; ?>

            <?php
            $start_p = max(1, $page - 2);
            $end_p = min($total_pages, $page + 2);
            if ($start_p > 1) echo '<span class="pagination-info">...</span>';
            for ($i = $start_p; $i <= $end_p; $i++):
            ?>
                <?php if ($i == $page): ?>
                    <span class="current-page"><?php echo $i; ?></span>
                <?php else: ?>
                    <a href="?<?php echo buildQS($i); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ($end_p < $total_pages) echo '<span class="pagination-info">...</span>'; ?>

            <?php if ($page < $total_pages): ?>
                <a href="?<?php echo buildQS($page + 1); ?>">Next &#8250;</a>
                <a href="?<?php echo buildQS($total_pages); ?>">Last &#187;</a>
            <?php else: ?>
                <span class="disabled">Next &#8250;</span>
                <span class="disabled">Last &#187;</span>
            <?php endif; ?>
            <span class="pagination-info">| <?php echo number_format($total_logs); ?> records</span>
        </div>
    <?php endif; ?>
</main>

<script>
function toggleDetails(idx) {
    const box = document.getElementById('details-' + idx);
    const btn = box.previousElementSibling;
    if (box.classList.contains('open')) {
        box.classList.remove('open');
        btn.innerHTML = '&#9654; Show details';
    } else {
        box.classList.add('open');
        btn.innerHTML = '&#9660; Hide details';
    }
}

function setRange(range) {
    const from = document.getElementById('dateFrom');
    const to = document.getElementById('dateTo');
    const today = new Date();
    const fmt = d => d.toISOString().split('T')[0];

    if (range === 'today') {
        from.value = to.value = fmt(today);
    } else if (range === 'week') {
        const ws = new Date(today);
        ws.setDate(today.getDate() - today.getDay());
        from.value = fmt(ws);
        to.value = fmt(today);
    } else if (range === 'month') {
        from.value = fmt(new Date(today.getFullYear(), today.getMonth(), 1));
        to.value = fmt(today);
    } else if (range === 'all') {
        from.value = '2020-01-01';
        to.value = fmt(today);
    }
}
</script>
</body>
</html>
