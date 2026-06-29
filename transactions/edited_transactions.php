<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

$currentUser = getCurrentUser();

// Check if user is admin
$isAdmin = in_array($currentUser['role'], ['admin', 'super_admin']);

$userStore = null;
if ($currentUser['store_id'] && !$isAdmin) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $currentUser['store_id']);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
}

// Handle AJAX requests for filtering
if (isset($_GET['action']) && $_GET['action'] === 'filter_edited_transactions') {
    header('Content-Type: application/json');
    
    $store_id = $_GET['store_id'] ?? 'all';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $search = $_GET['search'] ?? '';
    
    // Query to get edited transactions and their new versions
    $query = "SELECT 
              t.id,
              t.transaction_number,
              t.transaction_date,
              t.total_amount,
              t.total_profit,
              t.total_items,
              t.payment_method,
              t.store_id,
              s.store_name,
              s.store_code,
              (SELECT GROUP_CONCAT(ti.product_name SEPARATOR ', ') 
               FROM transaction_items ti 
               WHERE ti.transaction_id = t.id) as products,
              (SELECT COUNT(*) FROM transactions WHERE parent_transaction_id = t.id) as edit_count,
              d.recipient_name,
              d.recipient_address,
              c.customer_name
              FROM transactions t 
              LEFT JOIN stores s ON t.store_id = s.id
              LEFT JOIN deliveries d ON t.id = d.transaction_id
              LEFT JOIN credits c ON t.id = c.transaction_id
              WHERE t.status = 'edited'";
    
    $params = [];
    $types = '';
    
    // Filter by store if user has a store assigned AND is not an admin
    if ($userStore && !$isAdmin) {
        $query .= " AND t.store_id = ?";
        $params[] = $userStore['id'];
        $types .= 'i';
    } elseif ($store_id !== 'all' && $isAdmin) {
        $query .= " AND t.store_id = ?";
        $params[] = intval($store_id);
        $types .= 'i';
    }
    
    if ($date_from) {
        $query .= " AND DATE(t.transaction_date) >= ?";
        $params[] = $date_from;
        $types .= 's';
    }
    
    if ($date_to) {
        $query .= " AND DATE(t.transaction_date) <= ?";
        $params[] = $date_to;
        $types .= 's';
    }
    
    $query .= " ORDER BY t.transaction_date DESC";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $transactions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Get the new versions for each edited transaction
    foreach ($transactions as &$transaction) {
        $stmt = $conn->prepare("SELECT id, transaction_number, transaction_date, total_amount, total_profit, status 
                               FROM transactions 
                               WHERE parent_transaction_id = ? 
                               ORDER BY transaction_date DESC");
        $stmt->bind_param("i", $transaction['id']);
        $stmt->execute();
        $transaction['new_versions'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
    
    // Filter by search term
    if ($search) {
        $transactions = array_filter($transactions, function($t) use ($search) {
            return stripos($t['id'], $search) !== false || 
                   stripos($t['transaction_number'], $search) !== false ||
                   stripos($t['products'], $search) !== false ||
                   stripos($t['recipient_name'], $search) !== false ||
                   stripos($t['customer_name'], $search) !== false ||
                   stripos($t['store_name'], $search) !== false;
        });
    }
    
    echo json_encode(array_values($transactions));
    $conn->close();
    exit;
}

// Get all stores for filter dropdown (admin only)
$stores = [];
if ($isAdmin) {
    $result = $conn->query("SELECT id, store_name, store_code FROM stores WHERE status = 'active' ORDER BY store_name");
    $stores = $result->fetch_all(MYSQLI_ASSOC);
}

// Get summary statistics
$storeFilter = ($userStore && !$isAdmin) ? "AND t.store_id = " . $userStore['id'] : "";

$stats = [];
$stats['total_edited'] = $conn->query("SELECT COUNT(*) as count FROM transactions t WHERE status = 'edited' $storeFilter")->fetch_assoc()['count'];
$stats['total_re_edits'] = $conn->query("SELECT COUNT(*) as count FROM transactions t WHERE parent_transaction_id IS NOT NULL $storeFilter")->fetch_assoc()['count'];
$stats['edited_today'] = $conn->query("SELECT COUNT(*) as count FROM transactions t WHERE status = 'edited' AND DATE(transaction_date) = CURDATE() $storeFilter")->fetch_assoc()['count'];

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edited Transactions</title>
    <link rel="stylesheet" href="/oro-store/style.css">
    <style>
        .stat-card.edited {
            background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
            color: #333;
        }
        .edit-chain {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px;
            margin: 5px 0;
            border-radius: 4px;
        }
        .edit-version {
            margin: 5px 0;
            padding: 8px;
            background-color: white;
            border-radius: 3px;
            border-left: 3px solid #28a745;
        }
        .original-transaction {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="transaction-history-container">
        <header class="history-header">
            <h1>⚠️ Edited Transactions Report</h1>
            <?php if ($isAdmin): ?>
                <div style="font-size: 14px; color: #28a745; margin-top: 5px; font-weight: bold;">
                    👑 Admin - Viewing All Stores
                </div>
            <?php elseif ($userStore): ?>
                <div style="font-size: 14px; color: #666; margin-top: 5px;">
                    🏪 Store: <?php echo htmlspecialchars($userStore['store_name']); ?>
                </div>
            <?php endif; ?>
            <button onclick="window.location.href='/oro-store/transactions/transaction_history.php'" class="btn-close">Back to History</button>
        </header>

        <!-- Statistics Dashboard -->
        <div class="stats-dashboard">
            <div class="stat-card edited">
                <h3>Total Edited Transactions</h3>
                <p class="stat-value"><?php echo $stats['total_edited']; ?></p>
                <span class="stat-label">Original transactions that were modified</span>
            </div>
            <div class="stat-card">
                <h3>Total Re-edits</h3>
                <p class="stat-value"><?php echo $stats['total_re_edits']; ?></p>
                <span class="stat-label">New transactions created from edits</span>
            </div>
            <div class="stat-card warning">
                <h3>Edited Today</h3>
                <p class="stat-value"><?php echo $stats['edited_today']; ?></p>
                <span class="stat-label">Recent modifications</span>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-section">
            <?php if ($isAdmin && count($stores) > 0): ?>
            <div class="filter-group">
                <label>Store:</label>
                <select id="filter-store">
                    <option value="all">All Stores</option>
                    <?php foreach ($stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>">
                            <?php echo htmlspecialchars($store['store_code'] . ' - ' . $store['store_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="filter-group">
                <label>From Date:</label>
                <input type="date" id="filter-date-from">
            </div>
            <div class="filter-group">
                <label>To Date:</label>
                <input type="date" id="filter-date-to">
            </div>
            <div class="filter-group">
                <label>Search:</label>
                <input type="text" id="filter-search" placeholder="Transaction ID, Product, Store...">
            </div>
            <button onclick="applyFilters()" class="btn-filter">Apply Filters</button>
            <button onclick="resetFilters()" class="btn-reset">Reset</button>
            <button onclick="exportToCSV()" class="btn-export">Export CSV</button>
        </div>

        <!-- Transactions Table -->
        <div class="table-container">
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Original Transaction</th>
                        <th>Store</th>
                        <th>Date Edited</th>
                        <th>Type</th>
                        <th>Details</th>
                        <th>Original Amount</th>
                        <th>Edit History</th>
                    </tr>
                </thead>
                <tbody id="transactions-tbody">
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 20px;">Loading edited transactions...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        let currentTransactions = [];

        // Load transactions on page load
        document.addEventListener('DOMContentLoaded', function() {
            applyFilters();
        });

        // Apply filters
        function applyFilters() {
            const storeId = document.getElementById('filter-store') ? document.getElementById('filter-store').value : 'all';
            const dateFrom = document.getElementById('filter-date-from').value;
            const dateTo = document.getElementById('filter-date-to').value;
            const search = document.getElementById('filter-search').value;

            const params = new URLSearchParams({
                action: 'filter_edited_transactions',
                store_id: storeId,
                date_from: dateFrom,
                date_to: dateTo,
                search: search
            });

            fetch(`/oro-store/transactions/edited_transactions.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    currentTransactions = data;
                    displayTransactions(data);
                })
                .catch(error => {
                    console.error('Error loading transactions:', error);
                    alert('Error loading transactions');
                });
        }

        // Display transactions in table
        function displayTransactions(transactions) {
            const tbody = document.getElementById('transactions-tbody');
            
            if (transactions.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 20px;">No edited transactions found</td></tr>';
                return;
            }

            let html = '';
            transactions.forEach(t => {
                const date = new Date(t.transaction_date).toLocaleString();
                
                // Determine transaction type
                let typeBadge = '';
                if (t.payment_method === 'delivery') {
                    typeBadge = '🚚 DELIVERY';
                } else if (t.payment_method === 'credit') {
                    typeBadge = '💳 CREDIT';
                } else if (t.payment_method === 'gcash') {
                    typeBadge = '📱 GCASH';
                } else {
                    typeBadge = '💰 SALE';
                }
                
                // Determine details
                let details = '';
                if (t.payment_method === 'delivery' && t.recipient_name) {
                    details = `🚚 ${t.recipient_name}`;
                } else if (t.payment_method === 'credit' && t.customer_name) {
                    details = `💳 ${t.customer_name}`;
                } else {
                    const products = t.products ? (t.products.length > 40 ? t.products.substring(0, 40) + '...' : t.products) : 'N/A';
                    details = products;
                }
                
                // Build edit history
                let editHistory = '';
                if (t.new_versions && t.new_versions.length > 0) {
                    editHistory = `<div class="edit-chain">`;
                    editHistory += `<strong>Modified ${t.new_versions.length} time(s):</strong>`;
                    t.new_versions.forEach((v, index) => {
                        const vDate = new Date(v.transaction_date).toLocaleString();
                        const profitChange = (parseFloat(v.total_profit) - parseFloat(t.total_profit)).toFixed(2);
                        const profitColor = profitChange >= 0 ? '#28a745' : '#dc3545';
                        editHistory += `
                            <div class="edit-version">
                                <strong>Version ${index + 1}:</strong> #${v.transaction_number || v.id}<br>
                                <small>Date: ${vDate}</small><br>
                                <small>Amount: ₱${parseFloat(v.total_amount).toFixed(2)}</small><br>
                                <small>Profit Change: <span style="color: ${profitColor};">₱${profitChange}</span></small>
                            </div>
                        `;
                    });
                    editHistory += `</div>`;
                } else {
                    editHistory = '<span style="color: #999;">No edit history found</span>';
                }
                
                html += `
                    <tr>
                        <td>
                            <div class="original-transaction">
                                <strong>#${t.transaction_number || t.id}</strong><br>
                                <small>${t.total_items || 0} items</small>
                            </div>
                        </td>
                        <td>${t.store_name ? `🏪 ${t.store_code}` : 'N/A'}</td>
                        <td><small>${date}</small></td>
                        <td>${typeBadge}</td>
                        <td>${details}</td>
                        <td>
                            <strong>₱${parseFloat(t.total_amount).toFixed(2)}</strong><br>
                            <small style="color: ${parseFloat(t.total_profit) < 0 ? '#dc3545' : '#28a745'};">
                                Profit: ₱${parseFloat(t.total_profit).toFixed(2)}
                            </small>
                        </td>
                        <td>${editHistory}</td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        // Reset filters
        function resetFilters() {
            if (document.getElementById('filter-store')) {
                document.getElementById('filter-store').value = 'all';
            }
            document.getElementById('filter-date-from').value = '';
            document.getElementById('filter-date-to').value = '';
            document.getElementById('filter-search').value = '';
            applyFilters();
        }

        // Export to CSV
        function exportToCSV() {
            if (currentTransactions.length === 0) {
                alert('No transactions to export');
                return;
            }

            let csv = 'Original Transaction ID,Store,Date Edited,Type,Original Amount,Original Profit,Edit Count,Latest Version ID,Latest Amount,Latest Profit\n';
            
            currentTransactions.forEach(t => {
                const date = new Date(t.transaction_date).toLocaleString();
                const latestVersion = t.new_versions && t.new_versions.length > 0 ? t.new_versions[0] : null;
                
                csv += `#${t.transaction_number || t.id},`;
                csv += `${t.store_name || 'N/A'},`;
                csv += `"${date}",`;
                csv += `${t.payment_method || 'cash'},`;
                csv += `${t.total_amount},`;
                csv += `${t.total_profit},`;
                csv += `${t.edit_count || 0},`;
                csv += `${latestVersion ? '#' + (latestVersion.transaction_number || latestVersion.id) : 'N/A'},`;
                csv += `${latestVersion ? latestVersion.total_amount : 'N/A'},`;
                csv += `${latestVersion ? latestVersion.total_profit : 'N/A'}\n`;
            });

            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `edited_transactions_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>