<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

if (!isAdmin()) {
    header("Location: /oro-store/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

// ── Stats queries ──────────────────────────────────────────────

$totalAngkat = $conn->query("SELECT COUNT(*) as c FROM angkat_transactions WHERE is_deleted = 0")->fetch_assoc()['c'];

$statusCounts = ['active' => 0, 'completed' => 0, 'cancelled' => 0];
$statusResult = $conn->query("SELECT status, COUNT(*) as c FROM angkat_transactions WHERE is_deleted = 0 GROUP BY status");
while ($row = $statusResult->fetch_assoc()) {
    $statusCounts[$row['status']] = $row['c'];
}

$todayCount = $conn->query("SELECT COUNT(*) as c FROM angkat_transactions WHERE is_deleted = 0 AND DATE(created_at) = CURDATE()")->fetch_assoc()['c'];

$weekCount = $conn->query("SELECT COUNT(*) as c FROM angkat_transactions WHERE is_deleted = 0 AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)")->fetch_assoc()['c'];

$totalValue = $conn->query("SELECT COALESCE(SUM(total_value), 0) as total FROM angkat_transactions WHERE is_deleted = 0")->fetch_assoc()['total'];

$totalCollected = $conn->query("SELECT COALESCE(SUM(amount_collected), 0) as total FROM angkat_transactions WHERE is_deleted = 0 AND status = 'completed'")->fetch_assoc()['total'];

$activeItems = $conn->query("SELECT COALESCE(SUM(ai.quantity_given), 0) as c FROM angkat_items ai INNER JOIN angkat_transactions a ON ai.angkat_id = a.id WHERE a.status = 'active' AND a.is_deleted = 0 AND ai.is_deleted = 0")->fetch_assoc()['c'];

$topRetailers = [];
$topResult = $conn->query("
    SELECT a.retailer_name,
           COUNT(*) as active_count,
           COALESCE(SUM(a.total_value), 0) as total_value
    FROM angkat_transactions a
    WHERE a.status = 'active' AND a.is_deleted = 0
    GROUP BY a.retailer_name
    ORDER BY active_count DESC
    LIMIT 5
");
while ($row = $topResult->fetch_assoc()) {
    $topRetailers[] = $row;
}

// ── Filter params ──────────────────────────────────────────────

$filterStatus   = $_GET['status']    ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo   = $_GET['date_to']   ?? '';
$filterSearch   = $_GET['search']    ?? '';
$filterStore    = $_GET['store']     ?? '';
$page           = max(1, intval($_GET['page'] ?? 1));
$perPage        = 20;
$offset         = ($page - 1) * $perPage;

// ── Build filtered query ───────────────────────────────────────

$where  = "WHERE a.is_deleted = 0";
$params = [];
$types  = '';

if ($filterStatus !== '') {
    $where .= " AND a.status = ?";
    $params[] = $filterStatus;
    $types   .= 's';
}
if ($filterDateFrom !== '') {
    $where .= " AND DATE(a.created_at) >= ?";
    $params[] = $filterDateFrom;
    $types   .= 's';
}
if ($filterDateTo !== '') {
    $where .= " AND DATE(a.created_at) <= ?";
    $params[] = $filterDateTo;
    $types   .= 's';
}
if ($filterSearch !== '') {
    $where .= " AND a.retailer_name LIKE ?";
    $params[] = '%' . $filterSearch . '%';
    $types   .= 's';
}
if ($filterStore !== '') {
    $where .= " AND a.store_id = ?";
    $params[] = intval($filterStore);
    $types   .= 'i';
}

$countQuery = "SELECT COUNT(*) as total FROM angkat_transactions a $where";
$stmt = $conn->prepare($countQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalFiltered = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$totalPages = max(1, ceil($totalFiltered / $perPage));

$listQuery = "
    SELECT a.*,
           s.store_name,
           s.store_code,
           u.full_name as created_by_name,
           (SELECT COUNT(*) FROM angkat_items ai WHERE ai.angkat_id = a.id AND ai.is_deleted = 0) as item_count
    FROM angkat_transactions a
    LEFT JOIN stores s ON a.store_id = s.id
    LEFT JOIN users u ON a.created_by = u.id
    $where
    ORDER BY a.created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $perPage;
$types   .= 'i';
$params[] = $offset;
$types   .= 'i';

$stmt = $conn->prepare($listQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$angkatList = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$storesList = [];
$storesResult = $conn->query("SELECT id, store_name, store_code FROM stores WHERE status = 'active' ORDER BY store_code");
while ($row = $storesResult->fetch_assoc()) {
    $storesList[] = $row;
}

$conn->close();

function buildPageUrl($p) {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Angkat Management - Admin Panel</title>
    <link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
    <style>
        .retailer-cell {
            max-width: 180px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .retailer-cell:hover {
            white-space: normal;
            overflow: visible;
        }
        .badge-secondary {
            background: #e2e8f0;
            color: #475569;
        }
        .top-retailers-grid {
            display: grid;
            gap: 10px;
        }
        .retailer-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px;
            background: #f8fafc;
            border-radius: 8px;
            border-left: 3px solid #667eea;
        }
        .retailer-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .retailer-name {
            font-weight: 600;
            font-size: 14px;
            color: #0f172a;
        }
        .retailer-amount {
            font-size: 12px;
            color: #64748b;
        }
        .retailer-count {
            background: #ede9fe;
            color: #5b21b6;
            font-weight: 700;
            font-size: 13px;
            padding: 4px 10px;
            border-radius: 6px;
            white-space: nowrap;
        }
        .week-stat {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>Angkat Management</h1>
            <p>Monitor and manage all angkat (consignment) transactions across stores</p>
        </div>

        <!-- Summary Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">&#128230;</div>
                <div class="stat-label">Total Angkat</div>
                <div class="stat-value"><?php echo number_format($totalAngkat); ?></div>
                <div class="week-stat">This week: <?php echo number_format($weekCount); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">&#9203;</div>
                <div class="stat-label">Active</div>
                <div class="stat-value"><?php echo number_format($statusCounts['active']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">&#9989;</div>
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?php echo number_format($statusCounts['completed']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red">&#10060;</div>
                <div class="stat-label">Cancelled</div>
                <div class="stat-value"><?php echo number_format($statusCounts['cancelled']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">&#128197;</div>
                <div class="stat-label">Today's Angkat</div>
                <div class="stat-value"><?php echo number_format($todayCount); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">&#128230;</div>
                <div class="stat-label">Active Items Out</div>
                <div class="stat-value"><?php echo number_format($activeItems); ?></div>
                <div class="week-stat">Collected: &#8369;<?php echo number_format($totalCollected, 2); ?></div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="content-section">
            <form method="GET" class="filter-bar">
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>">
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All</option>
                        <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Retailer</label>
                    <input type="text" name="search" placeholder="Search retailer..." value="<?php echo htmlspecialchars($filterSearch); ?>">
                </div>
                <div class="filter-group">
                    <label>Store</label>
                    <select name="store">
                        <option value="">All Stores</option>
                        <?php foreach ($storesList as $store): ?>
                            <option value="<?php echo $store['id']; ?>" <?php echo $filterStore == $store['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($store['store_code'] . ' - ' . $store['store_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="flex: 0;">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                </div>
                <div class="filter-group" style="flex: 0;">
                    <label>&nbsp;</label>
                    <a href="/oro-store/angkat/angkat_management.php" class="btn btn-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>

        <!-- Angkat Table -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Angkat Transactions</h2>
                <span class="badge badge-info"><?php echo number_format($totalFiltered); ?> result<?php echo $totalFiltered !== 1 ? 's' : ''; ?></span>
            </div>

            <?php if (empty($angkatList)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">&#128230;</div>
                    <p>No angkat transactions found matching your filters.</p>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Transaction #</th>
                                <th>Retailer</th>
                                <th>Contact</th>
                                <th>Items</th>
                                <th>Total Value</th>
                                <th>Total Cost</th>
                                <th>Collected</th>
                                <th>Status</th>
                                <th>Store</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($angkatList as $angkat): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($angkat['created_at'])); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($angkat['transaction_number'] ?? 'N/A'); ?></strong>
                                    </td>
                                    <td>
                                        <div class="retailer-cell" title="<?php echo htmlspecialchars($angkat['retailer_name']); ?>">
                                            <?php echo htmlspecialchars($angkat['retailer_name']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($angkat['retailer_contact'] ?? '—'); ?></td>
                                    <td style="text-align: center;">
                                        <span class="badge badge-info"><?php echo $angkat['item_count']; ?></span>
                                    </td>
                                    <td>&#8369;<?php echo number_format($angkat['total_value'] ?? 0, 2); ?></td>
                                    <td>&#8369;<?php echo number_format($angkat['total_cost'] ?? 0, 2); ?></td>
                                    <td>&#8369;<?php echo number_format($angkat['amount_collected'] ?? 0, 2); ?></td>
                                    <td>
                                        <?php
                                        $badgeClass = 'badge-secondary';
                                        switch ($angkat['status']) {
                                            case 'active':    $badgeClass = 'badge-warning'; break;
                                            case 'completed': $badgeClass = 'badge-success'; break;
                                            case 'cancelled': $badgeClass = 'badge-secondary'; break;
                                        }
                                        ?>
                                        <span class="badge <?php echo $badgeClass; ?>">
                                            <?php echo ucfirst($angkat['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($angkat['store_code'] ?? 'N/A'); ?></td>
                                    <td>
                                        <button onclick='viewAngkatDetail(<?php echo $angkat["id"]; ?>)' class="btn btn-primary btn-sm">
                                            View Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo buildPageUrl(1); ?>">First</a>
                            <a href="<?php echo buildPageUrl($page - 1); ?>">Prev</a>
                        <?php else: ?>
                            <span class="disabled">First</span>
                            <span class="disabled">Prev</span>
                        <?php endif; ?>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage   = min($totalPages, $page + 2);
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <?php if ($i === $page): ?>
                                <span class="current-page"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="<?php echo buildPageUrl($i); ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?php echo buildPageUrl($page + 1); ?>">Next</a>
                            <a href="<?php echo buildPageUrl($totalPages); ?>">Last</a>
                        <?php else: ?>
                            <span class="disabled">Next</span>
                            <span class="disabled">Last</span>
                        <?php endif; ?>

                        <span class="pagination-info">
                            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                        </span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Top Retailers Section -->
        <?php if (!empty($topRetailers)): ?>
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">Top Retailers with Active Angkat</h2>
                </div>
                <div class="top-retailers-grid">
                    <?php foreach ($topRetailers as $idx => $retailer): ?>
                        <div class="retailer-row">
                            <div class="retailer-info">
                                <span class="retailer-name">
                                    <?php echo ($idx + 1) . '. ' . htmlspecialchars($retailer['retailer_name']); ?>
                                </span>
                                <span class="retailer-amount">
                                    Total value: &#8369;<?php echo number_format($retailer['total_value'], 2); ?>
                                </span>
                            </div>
                            <span class="retailer-count">
                                <?php echo $retailer['active_count']; ?> active
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    <!-- Angkat Detail Modal -->
    <div class="modal" id="angkatDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:28px;width:680px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;">
                <h2 id="amDetailTitle" style="font-size:16px;font-weight:700;color:#1e293b;margin:0;">Angkat Details</h2>
                <button onclick="closeAngkatDetailModal()" style="background:#f1f5f9;border:1px solid #e2e8f0;color:#64748b;width:32px;height:32px;border-radius:6px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;">&times;</button>
            </div>
            <div id="amDetailBody" style="font-size:13px;color:#334155;">Loading...</div>
            <div id="amDetailFooter" style="display:flex;gap:10px;margin-top:16px;padding-top:14px;border-top:1px solid #f1f5f9;"></div>
        </div>
    </div>

    </main>

    <script>
    function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function fmt(n) { return parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }

    let currentAngkatId = null;

    function viewAngkatDetail(id) {
        currentAngkatId = id;
        document.getElementById('amDetailBody').innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;">Loading...</div>';
        document.getElementById('amDetailFooter').innerHTML = '';
        document.getElementById('angkatDetailModal').style.display = 'flex';

        fetch(`/oro-store/angkat/angkat_details.php?action=get_angkat_items&angkat_id=${id}`)
            .then(r => r.json())
            .then(data => {
                if (!data.length) {
                    document.getElementById('amDetailBody').innerHTML = '<p style="color:#94a3b8;text-align:center;padding:20px;">No items found.</p>';
                    return;
                }
                const first = data[0];
                const angkatStatus = first.angkat_status || 'active';
                const statusColor = angkatStatus === 'completed' ? '#22c55e' : angkatStatus === 'cancelled' ? '#ef4444' : '#f59e0b';

                document.getElementById('amDetailTitle').innerHTML =
                    `${esc(first.transaction_number || 'Angkat')} <span style="font-size:12px;color:#94a3b8;font-weight:400;margin-left:6px;">· ${esc(first.retailer_name)}</span>
                     <span style="margin-left:8px;background:${statusColor};color:#fff;padding:3px 9px;border-radius:4px;font-size:11px;font-weight:700;">${angkatStatus.toUpperCase()}</span>`;

                let totalValue = 0, totalCost = 0, soldValue = 0;
                data.forEach(item => {
                    const price = parseFloat(item.price||0);
                    const cost = parseFloat(item.cost_price||0);
                    const given = parseInt(item.quantity_given||0);
                    const ws = parseInt(item.quantity_sold||0);
                    const is_ = parseInt(item.individual_sold||0);
                    const unitsPerPack = parseInt(item.individual_pieces_per_pack||1) || 1;
                    const indPrice = parseFloat(item.individual_selling_price||0) || (price / unitsPerPack);
                    totalValue += given * price;
                    totalCost += given * cost;
                    soldValue += (ws * price) + (is_ * indPrice);
                });
                const balance = totalValue - soldValue;

                let html = `
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:16px;">
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:700;margin-bottom:4px;">Total Value</div>
                        <div style="font-size:15px;font-weight:700;">₱${fmt(totalValue)}</div>
                    </div>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:700;margin-bottom:4px;">Collected</div>
                        <div style="font-size:15px;font-weight:700;color:#22c55e;">₱${fmt(soldValue)}</div>
                    </div>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:700;margin-bottom:4px;">Balance</div>
                        <div style="font-size:15px;font-weight:700;color:${balance > 0 ? '#ef4444' : '#22c55e'};">${balance > 0 ? '₱'+fmt(balance) : 'Settled'}</div>
                    </div>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;">
                    <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Product</th>
                        <th style="padding:9px 12px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Given</th>
                        <th style="padding:9px 12px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Sold</th>
                        <th style="padding:9px 12px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Returned</th>
                        <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Price</th>
                        <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Sold Value</th>
                    </tr></thead>
                    <tbody>`;

                data.forEach(item => {
                    const price = parseFloat(item.price||0);
                    const ws = parseInt(item.quantity_sold||0);
                    const wr = parseInt(item.quantity_returned||0);
                    const is_ = parseInt(item.individual_sold||0);
                    const ir = parseInt(item.individual_returned||0);
                    const unitsPerPack = parseInt(item.individual_pieces_per_pack||1) || 1;
                    const indPrice = parseFloat(item.individual_selling_price||0) || (price / unitsPerPack);
                    const sv = (ws * price) + (is_ * indPrice);
                    const soldLabel = ws > 0 ? ws + ' pack' + (ws>1?'s':'') : '';
                    const indLabel = is_ > 0 ? is_ + ' pc' + (is_>1?'s':'') : '';
                    const soldStr = [soldLabel, indLabel].filter(Boolean).join(' + ') || '0';
                    const retLabel = wr > 0 ? wr + ' pack' + (wr>1?'s':'') : '';
                    const retIndLabel = ir > 0 ? ir + ' pc' + (ir>1?'s':'') : '';
                    const retStr = [retLabel, retIndLabel].filter(Boolean).join(' + ') || '0';

                    html += `<tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 12px;font-weight:600;">${esc(item.product_name)}</td>
                        <td style="padding:10px 12px;text-align:center;">${item.quantity_given}</td>
                        <td style="padding:10px 12px;text-align:center;color:#2563eb;font-weight:600;">${soldStr}</td>
                        <td style="padding:10px 12px;text-align:center;color:#d97706;">${retStr}</td>
                        <td style="padding:10px 12px;text-align:right;">₱${fmt(price)}</td>
                        <td style="padding:10px 12px;text-align:right;font-weight:700;color:#22c55e;">₱${fmt(sv)}</td>
                    </tr>`;
                });

                html += '</tbody></table>';
                document.getElementById('amDetailBody').innerHTML = html;

                if (angkatStatus === 'active') {
                    document.getElementById('amDetailFooter').innerHTML = `
                        <button onclick="closeAngkatDetailModal()" style="flex:1;padding:10px;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Close</button>`;
                } else {
                    document.getElementById('amDetailFooter').innerHTML = `
                        <button onclick="closeAngkatDetailModal()" style="flex:1;padding:10px;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">Close</button>`;
                }
            })
            .catch(() => {
                document.getElementById('amDetailBody').innerHTML = '<p style="color:#dc2626;padding:20px;">Failed to load angkat details.</p>';
            });
    }

    function closeAngkatDetailModal() {
        document.getElementById('angkatDetailModal').style.display = 'none';
        currentAngkatId = null;
    }

    document.getElementById('angkatDetailModal').addEventListener('click', function(e) {
        if (e.target === this) closeAngkatDetailModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeAngkatDetailModal();
    });
    </script>
</body>
</html>
