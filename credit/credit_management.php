<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

if (!isAdmin()) {
    header("Location: /oro-store-demo/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

// ── Statistics ──────────────────────────────────────────────────────────────

// Total credits (not deleted)
$stats = [];
$stats['total_credits'] = $conn->query("SELECT COUNT(*) as c FROM credits WHERE is_deleted = 0")->fetch_assoc()['c'];

// Outstanding amount (unpaid + partial)
$stats['outstanding_amount'] = $conn->query("SELECT COALESCE(SUM(amount_due), 0) as total FROM credits WHERE status IN ('unpaid','partial') AND is_deleted = 0")->fetch_assoc()['total'];

// Collected amount (total amount_paid from paid credits)
$stats['collected_amount'] = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) as total FROM credits WHERE status = 'paid' AND is_deleted = 0")->fetch_assoc()['total'];

// Count by status
$stats['unpaid_count'] = $conn->query("SELECT COUNT(*) as c FROM credits WHERE status = 'unpaid' AND is_deleted = 0")->fetch_assoc()['c'];
$stats['partial_count'] = $conn->query("SELECT COUNT(*) as c FROM credits WHERE status = 'partial' AND is_deleted = 0")->fetch_assoc()['c'];
$stats['paid_count'] = $conn->query("SELECT COUNT(*) as c FROM credits WHERE status = 'paid' AND is_deleted = 0")->fetch_assoc()['c'];

// Today's new credits
$stats['today_credits'] = $conn->query("SELECT COUNT(*) as c FROM credits WHERE DATE(created_at) = CURDATE() AND is_deleted = 0")->fetch_assoc()['c'];

// This week's new credits
$stats['week_credits'] = $conn->query("SELECT COUNT(*) as c FROM credits WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1) AND is_deleted = 0")->fetch_assoc()['c'];

// This month's collections (paid_at this month)
$stats['month_collections'] = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) as total FROM credits WHERE YEAR(paid_at) = YEAR(CURDATE()) AND MONTH(paid_at) = MONTH(CURDATE()) AND is_deleted = 0")->fetch_assoc()['total'];

// Top 5 customers with most unpaid credits
$top_debtors = $conn->query("
    SELECT customer_name,
           SUM(amount_due) as total_due,
           COUNT(*) as credit_count
    FROM credits
    WHERE status IN ('unpaid','partial')
      AND is_deleted = 0
    GROUP BY customer_id, customer_name
    ORDER BY total_due DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ── Filters ─────────────────────────────────────────────────────────────────

$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$filter_search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_store = isset($_GET['store']) ? intval($_GET['store']) : 0;

// Pagination
$per_page = 20;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where = "c.is_deleted = 0";

if ($filter_status !== 'all' && in_array($filter_status, ['unpaid', 'partial', 'paid'])) {
    $where .= " AND c.status = '" . $conn->real_escape_string($filter_status) . "'";
}

if (!empty($filter_date_from)) {
    $where .= " AND DATE(c.created_at) >= '" . $conn->real_escape_string($filter_date_from) . "'";
}

if (!empty($filter_date_to)) {
    $where .= " AND DATE(c.created_at) <= '" . $conn->real_escape_string($filter_date_to) . "'";
}

if (!empty($filter_search)) {
    $search_escaped = $conn->real_escape_string($filter_search);
    $where .= " AND (c.customer_name LIKE '%$search_escaped%' OR c.customer_contact LIKE '%$search_escaped%')";
}

if ($filter_store > 0) {
    $where .= " AND c.store_id = $filter_store";
}

// Count total for pagination
$count_result = $conn->query("SELECT COUNT(*) as total FROM credits c WHERE $where");
$total_records = $count_result->fetch_assoc()['total'];
$total_pages = max(1, ceil($total_records / $per_page));

// Query credits list with JOIN
$credits_query = "
    SELECT c.*,
           t.transaction_number, t.transaction_date,
           s.store_name, s.store_code
    FROM credits c
    LEFT JOIN transactions t ON c.transaction_id = t.id
    LEFT JOIN stores s ON c.store_id = s.id
    WHERE $where
    ORDER BY c.created_at DESC
    LIMIT $per_page OFFSET $offset
";
$credits_result = $conn->query($credits_query);
$credits = $credits_result->fetch_all(MYSQLI_ASSOC);

// Get stores list for filter dropdown
$stores = $conn->query("SELECT id, store_name, store_code FROM stores WHERE status = 'active' ORDER BY store_name")->fetch_all(MYSQLI_ASSOC);

$conn->close();

// Helper to preserve filter params in pagination links
function buildQueryString($page_num) {
    $params = $_GET;
    $params['page'] = $page_num;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Credit Management - Admin Panel</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <style>
        .status-unpaid {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-partial {
            background: #fef3c7;
            color: #92400e;
        }
        .status-paid {
            background: #dcfce7;
            color: #166534;
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
            color: #dc2626;
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
            <h1>Credit Management</h1>
            <p>Monitor and manage customer credit accounts</p>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">&#128180;</div>
                <div class="stat-label">Total Credits</div>
                <div class="stat-value"><?php echo number_format($stats['total_credits']); ?></div>
                <small style="color: #64748b;">
                    <?php echo $stats['unpaid_count']; ?> unpaid &middot;
                    <?php echo $stats['partial_count']; ?> partial &middot;
                    <?php echo $stats['paid_count']; ?> paid
                </small>
            </div>
            <div class="stat-card">
                <div class="stat-icon red">&#9888;</div>
                <div class="stat-label">Outstanding Amount</div>
                <div class="stat-value amount-danger">&#8369;<?php echo number_format($stats['outstanding_amount'], 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">&#9989;</div>
                <div class="stat-label">Collected Amount</div>
                <div class="stat-value amount-success">&#8369;<?php echo number_format($stats['collected_amount'], 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple">&#128197;</div>
                <div class="stat-label">Today's New Credits</div>
                <div class="stat-value"><?php echo number_format($stats['today_credits']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon blue">&#128200;</div>
                <div class="stat-label">This Week</div>
                <div class="stat-value"><?php echo number_format($stats['week_credits']); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">&#128176;</div>
                <div class="stat-label">This Month Collections</div>
                <div class="stat-value amount-success">&#8369;<?php echo number_format($stats['month_collections'], 2); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="content-section">
            <form method="GET" class="filter-bar">
                <div class="filter-group">
                    <label>Search Customer</label>
                    <input type="text"
                           name="search"
                           value="<?php echo htmlspecialchars($filter_search); ?>"
                           placeholder="Customer name or contact...">
                </div>
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date"
                           name="date_from"
                           value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date"
                           name="date_to"
                           value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="unpaid" <?php echo $filter_status === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                        <option value="partial" <?php echo $filter_status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                        <option value="paid" <?php echo $filter_status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Store</label>
                    <select name="store">
                        <option value="0">All Stores</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>" <?php echo $filter_store == $store['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($store['store_code'] . ' - ' . $store['store_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="flex: 0 0 auto; min-width: auto;">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
                <?php if ($filter_status !== 'all' || !empty($filter_date_from) || !empty($filter_date_to) || !empty($filter_search) || $filter_store > 0): ?>
                    <div class="filter-group" style="flex: 0 0 auto; min-width: auto;">
                        <label>&nbsp;</label>
                        <a href="/oro-store-demo/credit/credit_management.php" class="btn btn-secondary">Clear</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Credits Table -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Credit Records (<?php echo number_format($total_records); ?> total)</h2>
            </div>

            <?php if (empty($credits)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">&#128180;</div>
                    <h3>No Credit Records Found</h3>
                    <p>Try adjusting your filters or check back later.</p>
                </div>
            <?php else: ?>
                <div class="data-table-wrapper" style="overflow-x: auto;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Transaction #</th>
                                <th>Customer</th>
                                <th>Contact</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Balance Due</th>
                                <th>Status</th>
                                <th>Store</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($credits as $credit): ?>
                                <tr>
                                    <td>
                                        <?php
                                        $date = new DateTime($credit['created_at']);
                                        echo $date->format('M j, Y');
                                        ?>
                                        <br>
                                        <small style="color: #64748b;"><?php echo $date->format('g:i A'); ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($credit['transaction_number'])): ?>
                                            <span class="transaction-link"><?php echo htmlspecialchars($credit['transaction_number']); ?></span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-style: italic;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($credit['customer_name']); ?></strong>
                                    </td>
                                    <td>
                                        <?php echo $credit['customer_contact'] ? htmlspecialchars($credit['customer_contact']) : '<span style="color: #94a3b8;">-</span>'; ?>
                                    </td>
                                    <td>
                                        <strong>&#8369;<?php echo number_format($credit['total_amount'], 2); ?></strong>
                                    </td>
                                    <td>
                                        <span class="amount-success">&#8369;<?php echo number_format($credit['amount_paid'], 2); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($credit['amount_due'] > 0): ?>
                                            <span class="amount-danger">&#8369;<?php echo number_format($credit['amount_due'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="amount-success">&#8369;0.00</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = 'status-' . $credit['status'];
                                        $status_label = ucfirst($credit['status']);
                                        ?>
                                        <span class="badge <?php echo $status_class; ?>"><?php echo $status_label; ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($credit['store_code'])): ?>
                                            <span class="store-badge"><?php echo htmlspecialchars($credit['store_code']); ?></span>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-style: italic;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-primary btn-sm"
                                                onclick="viewCreditDetail(<?php echo $credit['id']; ?>)">View Details</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="<?php echo buildQueryString(1); ?>">First</a>
                            <a href="<?php echo buildQueryString($page - 1); ?>">Prev</a>
                        <?php else: ?>
                            <span class="disabled">First</span>
                            <span class="disabled">Prev</span>
                        <?php endif; ?>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <?php if ($i === $page): ?>
                                <span class="current-page"><?php echo $i; ?></span>
                            <?php else: ?>
                                <a href="<?php echo buildQueryString($i); ?>"><?php echo $i; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <a href="<?php echo buildQueryString($page + 1); ?>">Next</a>
                            <a href="<?php echo buildQueryString($total_pages); ?>">Last</a>
                        <?php else: ?>
                            <span class="disabled">Next</span>
                            <span class="disabled">Last</span>
                        <?php endif; ?>

                        <span class="pagination-info">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        </span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Top Debtors Section -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">Top Debtors</h2>
            </div>

            <?php if (empty($top_debtors)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">&#128077;</div>
                    <h3>No Outstanding Debts</h3>
                    <p>All credits have been fully paid.</p>
                </div>
            <?php else: ?>
                <ul class="debtors-list">
                    <?php foreach ($top_debtors as $index => $debtor): ?>
                        <li class="debtor-item">
                            <div>
                                <div class="debtor-name">
                                    <?php echo ($index + 1) . '. ' . htmlspecialchars($debtor['customer_name']); ?>
                                </div>
                                <div class="debtor-meta">
                                    <?php echo $debtor['credit_count']; ?> unpaid credit<?php echo $debtor['credit_count'] > 1 ? 's' : ''; ?>
                                </div>
                            </div>
                            <div class="debtor-amount">
                                &#8369;<?php echo number_format($debtor['total_due'], 2); ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </main>

    <!-- Credit Detail Modal -->
    <div class="modal" id="creditDetailModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);backdrop-filter:blur(3px);z-index:9999;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:12px;padding:28px;width:620px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;">
                <h2 id="cmDetailTitle" style="font-size:16px;font-weight:700;color:#1e293b;margin:0;">Credit Details</h2>
                <button onclick="closeCreditDetailModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#94a3b8;line-height:1;">&times;</button>
            </div>
            <div id="cmDetailBody" style="color:#334155;font-size:13px;">Loading...</div>
            <div id="cmDetailFooter" style="display:none;margin-top:16px;display:flex;gap:10px;">
                <button id="cmMarkPaidBtn" onclick="markCreditPaid()" style="flex:1;padding:10px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;background:#22c55e;color:#fff;transition:opacity .15s;">✓ Mark as Paid</button>
                <button onclick="closeCreditDetailModal()" style="flex:1;padding:10px;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;transition:opacity .15s;">Close</button>
            </div>
        </div>
    </div>

    <script>
    let currentCreditId = null;

    function viewCreditDetail(creditId) {
        currentCreditId = creditId;
        const modal = document.getElementById('creditDetailModal');
        const body = document.getElementById('cmDetailBody');
        const footer = document.getElementById('cmDetailFooter');
        modal.style.display = 'flex';
        footer.style.display = 'none';
        body.innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;">Loading...</div>';

        fetch(`/oro-store-demo/credit/credit_details.php?action=get_credit_details&credit_id=${creditId}`)
            .then(r => r.json())
            .then(data => {
                if (data.error) { body.innerHTML = '<p style="color:#dc2626;">Error: ' + data.error + '</p>'; return; }
                const c = data.credit;
                const items = data.items || [];
                const isPaid = c.status === 'paid';
                const statusClass = isPaid ? 'status-paid' : (c.status === 'partial' ? 'status-partial' : 'status-unpaid');

                footer.style.display = 'flex';
                document.getElementById('cmMarkPaidBtn').style.display = isPaid ? 'none' : '';

                document.getElementById('cmDetailTitle').innerHTML =
                    'Receipt #' + esc(c.transaction_number || 'N/A') +
                    ' <span class="badge ' + statusClass + '" style="margin-left:8px;">' + c.status.toUpperCase() + '</span>';

                let html = `
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;margin-bottom:16px;">
                    <div style="display:flex;gap:10px;margin-bottom:6px;"><span style="color:#94a3b8;min-width:80px;">Customer</span><span style="font-weight:500;">${esc(c.customer_name)}</span></div>
                    <div style="display:flex;gap:10px;margin-bottom:6px;"><span style="color:#94a3b8;min-width:80px;">Contact</span><span style="font-weight:500;">${esc(c.customer_contact || '—')}</span></div>
                    <div style="display:flex;gap:10px;"><span style="color:#94a3b8;min-width:80px;">Date</span><span style="font-weight:500;">${c.transaction_date ? new Date(c.transaction_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}) : '—'}</span></div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:16px;">
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:700;margin-bottom:4px;">Total Amount</div>
                        <div style="font-size:15px;font-weight:700;">₱${fmt(c.total_amount)}</div>
                    </div>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:700;margin-bottom:4px;">Amount Due</div>
                        <div style="font-size:15px;font-weight:700;color:#ef4444;">₱${fmt(c.amount_due)}</div>
                    </div>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;">
                        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;font-weight:700;margin-bottom:4px;">Amount Paid</div>
                        <div style="font-size:15px;font-weight:700;color:#22c55e;">₱${fmt(c.amount_paid)}</div>
                    </div>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px;">
                    <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                        <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Product</th>
                        <th style="padding:9px 12px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Qty</th>
                        <th style="padding:9px 12px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Price</th>
                        <th style="padding:9px 12px;text-align:right;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;">Subtotal</th>
                    </tr></thead>
                    <tbody>`;

                let itemsSubtotal = 0;
                items.forEach(item => {
                    const sub = parseFloat(item.subtotal || item.total_price || 0);
                    itemsSubtotal += sub;
                    html += `<tr style="border-bottom:1px solid #f1f5f9;">
                        <td style="padding:10px 12px;">${esc(item.product_name || '—')}</td>
                        <td style="padding:10px 12px;text-align:center;">${item.quantity}</td>
                        <td style="padding:10px 12px;">₱${fmt(item.price)}</td>
                        <td style="padding:10px 12px;text-align:right;font-weight:700;color:#22c55e;">₱${fmt(sub)}</td>
                    </tr>`;
                });

                html += '</tbody></table>';
                const charge = parseFloat(c.additional_charge || 0);
                html += `<div style="display:flex;flex-direction:column;gap:4px;padding:0 4px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;font-size:13px;color:#64748b;">
                        <span>Subtotal</span><span style="font-weight:600;color:#1e293b;">₱${fmt(itemsSubtotal)}</span>
                    </div>
                    ${charge > 0 ? `<div style="display:flex;justify-content:space-between;font-size:13px;color:#64748b;">
                        <span>Credit Fee</span><span style="font-weight:600;color:#f59e0b;">₱${fmt(charge)}</span>
                    </div>` : ''}
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca;">
                    <span style="font-size:13px;color:#64748b;">Amount Due</span>
                    <span style="font-size:17px;font-weight:700;color:#dc2626;">₱${fmt(c.amount_due)}</span>
                </div>`;
                body.innerHTML = html;
            })
            .catch(() => { body.innerHTML = '<p style="color:#dc2626;padding:20px;">Failed to load credit details.</p>'; });
    }

    function closeCreditDetailModal() {
        document.getElementById('creditDetailModal').style.display = 'none';
    }

    document.getElementById('creditDetailModal').addEventListener('click', function(e) {
        if (e.target === this) closeCreditDetailModal();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeCreditDetailModal();
    });

    function markCreditPaid() {
        if (!currentCreditId || !confirm('Mark this credit as fully paid?')) return;
        fetch('/oro-store-demo/credit/credit_details.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_paid&credit_ids=' + encodeURIComponent(JSON.stringify([currentCreditId]))
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) { closeCreditDetailModal(); location.reload(); }
            else alert('Error: ' + (d.error || 'Unknown'));
        });
    }

    function fmt(n) { return parseFloat(n || 0).toLocaleString('en', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function esc(s) { return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    </script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Credit Management', array (
  'Features' => 
  array (
    0 => 'View all credit accounts with outstanding balances',
    1 => 'Filter: Unpaid, Partial, Paid, All',
    2 => 'Record payments against credit balances',
    3 => 'Track payment history per customer',
    4 => 'Total outstanding amount shown at top',
  ),
));
?>
</body>
</html>
