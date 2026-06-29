<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

if (!isAdmin()) {
    header("Location: /oro-store/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

// ── Stats queries ──────────────────────────────────────────────

// Total deliveries (not deleted)
$totalDeliveries = $conn->query("SELECT COUNT(*) as c FROM deliveries WHERE is_deleted = 0")->fetch_assoc()['c'];

// Count by status
$statusCounts = ['pending' => 0, 'completed' => 0, 'lacking' => 0, 'cancelled' => 0];
$statusResult = $conn->query("SELECT status, COUNT(*) as c FROM deliveries WHERE is_deleted = 0 GROUP BY status");
while ($row = $statusResult->fetch_assoc()) {
    $statusCounts[$row['status']] = $row['c'];
}

// Today's deliveries
$todayCount = $conn->query("SELECT COUNT(*) as c FROM deliveries WHERE is_deleted = 0 AND DATE(created_at) = CURDATE()")->fetch_assoc()['c'];

// This week's deliveries
$weekCount = $conn->query("SELECT COUNT(*) as c FROM deliveries WHERE is_deleted = 0 AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)")->fetch_assoc()['c'];

// Total delivery fees collected
$totalFees = $conn->query("SELECT COALESCE(SUM(delivery_fee), 0) as total FROM deliveries WHERE is_deleted = 0")->fetch_assoc()['total'];

// Pending items count (delivery_items)
$pendingItems = $conn->query("SELECT COUNT(*) as c FROM delivery_items WHERE status = 'pending' AND is_deleted = 0")->fetch_assoc()['c'];

// Lacking items count (delivery_items)
$lackingItems = $conn->query("SELECT COUNT(*) as c FROM delivery_items WHERE status = 'lacking' AND is_deleted = 0")->fetch_assoc()['c'];

// Top 5 recipients with most pending deliveries
$topRecipients = [];
$topResult = $conn->query("
    SELECT d.recipient_name,
           COUNT(*) as pending_count,
           COALESCE(SUM(t.total_amount), 0) as total_amount
    FROM deliveries d
    LEFT JOIN transactions t ON d.transaction_id = t.id
    WHERE d.status = 'pending' AND d.is_deleted = 0
    GROUP BY d.recipient_name
    ORDER BY pending_count DESC
    LIMIT 5
");
while ($row = $topResult->fetch_assoc()) {
    $topRecipients[] = $row;
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

// ── Build filtered deliveries query ────────────────────────────

$where   = "WHERE d.is_deleted = 0";
$params  = [];
$types   = '';

if ($filterStatus !== '') {
    $where .= " AND d.status = ?";
    $params[] = $filterStatus;
    $types   .= 's';
}
if ($filterDateFrom !== '') {
    $where .= " AND DATE(d.created_at) >= ?";
    $params[] = $filterDateFrom;
    $types   .= 's';
}
if ($filterDateTo !== '') {
    $where .= " AND DATE(d.created_at) <= ?";
    $params[] = $filterDateTo;
    $types   .= 's';
}
if ($filterSearch !== '') {
    $where .= " AND d.recipient_name LIKE ?";
    $params[] = '%' . $filterSearch . '%';
    $types   .= 's';
}
if ($filterStore !== '') {
    $where .= " AND d.store_id = ?";
    $params[] = intval($filterStore);
    $types   .= 'i';
}

// Count total filtered rows for pagination
$countQuery = "SELECT COUNT(*) as total FROM deliveries d $where";
$stmt = $conn->prepare($countQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$totalFiltered = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();
$totalPages = max(1, ceil($totalFiltered / $perPage));

// Main deliveries list query
$listQuery = "
    SELECT d.*,
           t.transaction_number,
           t.transaction_date,
           t.total_amount,
           s.store_name,
           s.store_code,
           u.full_name as created_by_name,
           (SELECT COUNT(*) FROM delivery_items di WHERE di.delivery_id = d.id AND di.is_deleted = 0) as item_count
    FROM deliveries d
    LEFT JOIN transactions t ON d.transaction_id = t.id
    LEFT JOIN stores s ON d.store_id = s.id
    LEFT JOIN users u ON d.created_by = u.id
    $where
    ORDER BY d.created_at DESC
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
$deliveries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Stores list for filter dropdown ────────────────────────────

$storesList = [];
$storesResult = $conn->query("SELECT id, store_name, store_code FROM stores WHERE status = 'active' ORDER BY store_code");
while ($row = $storesResult->fetch_assoc()) {
    $storesList[] = $row;
}

$conn->close();

// ── Helper: build pagination URL ───────────────────────────────

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
    <title>Delivery Management - Admin Panel</title>
    <link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
    <style>
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        .status-completed {
            background: #dcfce7;
            color: #166534;
        }
        .status-lacking {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-cancelled {
            background: #e2e8f0;
            color: #475569;
        }

        .amount-danger {
            color: #dc2626;
            font-weight: 700;
        }
        .amount-success {
            color: #16a34a;
            font-weight: 700;
        }

        .debtors-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .debtor-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .debtor-item:last-child {
            border-bottom: none;
        }
        .debtor-name {
            font-weight: 600;
            color: #0f172a;
            font-size: 14px;
        }
        .debtor-meta {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }
        .debtor-amount {
            font-weight: 700;
            color: #f59e0b;
            font-size: 15px;
            text-align: right;
        }

        .transaction-link {
            font-family: monospace;
            font-size: 13px;
            color: #4f46e5;
        }

        .store-badge {
            background: #dbeafe;
            color: #1e40af;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 700;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 12px;
        }

        @media (max-width: 768px) {
            .data-table-wrapper {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1>Delivery Management</h1>
            <p>Monitor and manage all deliveries across stores</p>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">&#128666;</div>
                <div class="stat-label">Total Deliveries</div>
                <div class="stat-value"><?php echo number_format($totalDeliveries); ?></div>
                <small style="color: #64748b;">
                    <?php echo $statusCounts['pending']; ?> pending &middot;
                    <?php echo $statusCounts['completed']; ?> completed &middot;
                    <?php echo $statusCounts['lacking']; ?> lacking
                </small>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">&#9203;</div>
                <div class="stat-label">Pending Deliveries</div>
                <div class="stat-value"><?php echo number_format($statusCounts['pending']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">&#9989;</div>
                <div class="stat-label">Completed</div>
                <div class="stat-value amount-success"><?php echo number_format($statusCounts['completed']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">&#128197;</div>
                <div class="stat-label">Today's Deliveries</div>
                <div class="stat-value"><?php echo number_format($todayCount); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue">&#128200;</div>
                <div class="stat-label">This Week</div>
                <div class="stat-value"><?php echo number_format($weekCount); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">&#128230;</div>
                <div class="stat-label">Items Pending / Lacking</div>
                <div class="stat-value"><?php echo number_format($pendingItems); ?></div>
                <small style="color: #64748b;">Lacking items: <?php echo number_format($lackingItems); ?></small>
            </div>
        </div>

        <!-- Filters -->
        <div class="content-section">
            <form method="GET" class="filter-bar">
                <div class="filter-group">
                    <label>Search Recipient</label>
                    <input type="text"
                           name="search"
                           value="<?php echo htmlspecialchars($filterSearch); ?>"
                           placeholder="Recipient name...">
                </div>
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date"
                           name="date_from"
                           value="<?php echo htmlspecialchars($filterDateFrom); ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date"
                           name="date_to"
                           value="<?php echo htmlspecialchars($filterDateTo); ?>">
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="" <?php echo $filterStatus === '' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="lacking" <?php echo $filterStatus === 'lacking' ? 'selected' : ''; ?>>Lacking</option>
                        <option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
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
                <div class="filter-group" style="flex: 0 0 auto; min-width: auto;">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
                <?php if ($filterStatus !== '' || !empty($filterDateFrom) || !empty($filterDateTo) || !empty($filterSearch) || $filterStore !== ''): ?>
                    <div class="filter-group" style="flex: 0 0 auto; min-width: auto;">
                        <label>&nbsp;</label>
                        <a href="/oro-store/delivery/delivery_management.php" class="btn btn-secondary">Clear</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Deliveries Table -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Delivery Records (<?php echo number_format($totalFiltered); ?> total)</h2>
            </div>

            <?php if (empty($deliveries)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">&#128666;</div>
                    <h3>No Delivery Records Found</h3>
                    <p>Try adjusting your filters or check back later.</p>
                </div>
            <?php else: ?>
                <div class="data-table-wrapper" style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Transaction #</th>
                                <th>Recipient</th>
                                <th>Address</th>
                                <th>Items</th>
                                <th>Amount</th>
                                <th>Fee</th>
                                <th>Status</th>
                                <th>Store</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deliveries as $delivery): ?>
                                <tr>
                                    <td>
                                        <?php
                                        $date = new DateTime($delivery['created_at']);
                                        echo $date->format('M j, Y');
                                        ?>
                                        <br>
                                        <small style="color: #64748b;"><?php echo $date->format('g:i A'); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($delivery['transaction_number'])): ?>
                                            <span class="transaction-link"><?php echo htmlspecialchars($delivery['transaction_number']); ?></span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-style: italic;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($delivery['recipient_name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo !empty($delivery['recipient_address']) ? htmlspecialchars($delivery['recipient_address']) : '<span style="color: #94a3b8;">-</span>'; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <strong><?php echo $delivery['item_count']; ?></strong>
                                    </td>
                                    <td>
                                        <strong>&#8369;<?php echo number_format($delivery['total_amount'] ?? 0, 2); ?></strong>
                                    </td>
                                    <td>
                                        <span class="amount-success">&#8369;<?php echo number_format($delivery['delivery_fee'] ?? 0, 2); ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = 'status-' . $delivery['status'];
                                        $status_label = ucfirst($delivery['status']);
                                        ?>
                                        <span class="badge <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($delivery['store_code'])): ?>
                                            <span class="store-badge"><?php echo htmlspecialchars($delivery['store_code']); ?></span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-style: italic;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-primary btn-sm"
                                                onclick="viewDeliveryDetail(<?php echo $delivery['id']; ?>)">View Details</button>
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

        <!-- Top Recipients Section -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Top Pending Recipients</h2>
            </div>

            <?php if (empty($topRecipients)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">&#128077;</div>
                    <h3>No Pending Deliveries</h3>
                    <p>All deliveries have been completed.</p>
                </div>
            <?php else: ?>
                <ul class="debtors-list">
                    <?php foreach ($topRecipients as $index => $recipient): ?>
                        <li class="debtor-item">
                            <div>
                                <div class="debtor-name">
                                    <?php echo ($index + 1) . '. ' . htmlspecialchars($recipient['recipient_name']); ?>
                                </div>
                                <div class="debtor-meta">
                                    <?php echo $recipient['pending_count']; ?> pending deliver<?php echo $recipient['pending_count'] > 1 ? 'ies' : 'y'; ?>
                                </div>
                            </div>
                            <div class="debtor-amount">
                                &#8369;<?php echo number_format($recipient['total_amount'], 2); ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </main>

    <!-- Delivery Detail Modal -->
    <div class="modal" id="deliveryDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:28px;width:620px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;">
                <h2 id="dmDetailTitle" style="font-size:16px;font-weight:700;color:#1e293b;margin:0;">Delivery Details</h2>
                <button onclick="closeDeliveryDetailModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#94a3b8;line-height:1;">&times;</button>
            </div>
            <div id="dmDetailBody" style="color:#334155;font-size:13px;">Loading...</div>
            <div id="dmDetailFooter" style="display:none;margin-top:16px;display:flex;gap:10px;">
                <button id="dmMarkCompleteBtn" onclick="markDeliveryComplete()" style="flex:1;padding:10px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;background:#22c55e;color:#fff;transition:opacity .15s;">&#10003; Mark Complete</button>
                <button onclick="closeDeliveryDetailModal()" style="flex:1;padding:10px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;transition:opacity .15s;">Close</button>
            </div>
        </div>
    </div>

    <script>
    let currentDeliveryId = null;

    function viewDeliveryDetail(deliveryId) {
        currentDeliveryId = deliveryId;
        const modal = document.getElementById('deliveryDetailModal');
        const body = document.getElementById('dmDetailBody');
        const footer = document.getElementById('dmDetailFooter');
        modal.style.display = 'flex';
        footer.style.display = 'none';
        body.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;">Loading...</div>';

        fetch(`/oro-store/delivery/delivery_details.php?action=get_delivery&delivery_id=${deliveryId}`)
            .then(r => r.json())
            .then(data => {
                if (!data.length) { body.innerHTML = '<p style="color:#94a3b8;padding:20px;">No items found.</p>'; return; }
                const first = data[0];
                const status = first.delivery_status || 'pending';
                const isComplete = status === 'completed';
                const statusClass = 'status-' + status;

                footer.style.display = 'flex';
                document.getElementById('dmMarkCompleteBtn').style.display = isComplete ? 'none' : '';

                document.getElementById('dmDetailTitle').innerHTML =
                    'Receipt #' + esc(first.transaction_number || 'N/A') +
                    ' <span class="badge ' + statusClass + '" style="margin-left:8px;">' + status.toUpperCase() + '</span>';

                let html = `
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:16px;">
                    <div style="display:flex;gap:10px;margin-bottom:6px;"><span style="color:#94a3b8;min-width:80px;">Recipient</span><span style="font-weight:500;">${esc(first.recipient_name)}</span></div>
                    <div style="display:flex;gap:10px;margin-bottom:6px;"><span style="color:#94a3b8;min-width:80px;">Address</span><span style="font-weight:500;">${esc(first.recipient_address || '—')}</span></div>
                    <div style="display:flex;gap:10px;"><span style="color:#94a3b8;min-width:80px;">Date</span><span style="font-weight:500;">${first.transaction_date ? new Date(first.transaction_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—'}</span></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:16px;">
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:700;margin-bottom:4px;">Total Amount</div>
                        <div style="font-size:15px;font-weight:700;">₱${fmt(first.total_amount)}</div>
                    </div>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:700;margin-bottom:4px;">Delivery Fee</div>
                        <div style="font-size:15px;font-weight:700;color:#f59e0b;">₱${fmt(first.delivery_fee)}</div>
                    </div>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:700;margin-bottom:4px;">Status</div>
                        <div style="font-size:15px;font-weight:700;color:${isComplete ? '#22c55e' : '#f59e0b'};">${status.charAt(0).toUpperCase() + status.slice(1)}</div>
                    </div>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;">
                    <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Product</th>
                        <th style="padding:9px 12px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Ordered</th>
                        <th style="padding:9px 12px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Delivered</th>
                        <th style="padding:9px 12px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Lacking</th>
                    </tr></thead>
                    <tbody>`;

                data.forEach(item => {
                    html += `<tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 12px;font-weight:600;">${esc(item.product_name)}</td>
                        <td style="padding:10px 12px;text-align:center;">${item.quantity_ordered}</td>
                        <td style="padding:10px 12px;text-align:center;color:#16a34a;font-weight:600;">${item.quantity_delivered}</td>
                        <td style="padding:10px 12px;text-align:center;color:${item.quantity_lacking > 0 ? '#dc2626' : '#94a3b8'};font-weight:700;">${item.quantity_lacking > 0 ? item.quantity_lacking : '—'}</td>
                    </tr>`;
                });

                html += `</tbody></table>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#f0fdf4;border-radius:8px;border:1px solid #bbf7d0;">
                    <span style="font-size:13px;color:#64748b;">Total Amount</span>
                    <span style="font-size:17px;font-weight:700;color:#16a34a;">₱${fmt(first.total_amount)}</span>
                </div>`;

                body.innerHTML = html;
            })
            .catch(() => { body.innerHTML = '<p style="color:#dc2626;padding:20px;">Failed to load delivery details.</p>'; });
    }

    function closeDeliveryDetailModal() {
        document.getElementById('deliveryDetailModal').style.display = 'none';
        currentDeliveryId = null;
    }

    document.getElementById('deliveryDetailModal').addEventListener('click', function(e) {
        if (e.target === this) closeDeliveryDetailModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeDeliveryDetailModal();
    });

    function markDeliveryComplete() {
        if (!currentDeliveryId || !confirm('Mark this delivery as complete? This action cannot be undone.')) return;
        const fd = new FormData();
        fd.append('action', 'mark_complete');
        fd.append('delivery_id', currentDeliveryId);
        fetch('/oro-store/delivery/delivery_details.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.success) { closeDeliveryDetailModal(); location.reload(); }
                else alert('Error: ' + (d.error || 'Unknown'));
            });
    }

    function fmt(n) { return parseFloat(n || 0).toLocaleString('en', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function esc(s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    </script>
</body>
</html>
