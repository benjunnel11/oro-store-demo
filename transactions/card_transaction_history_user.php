<?php
// Start output buffering to prevent any stray output before JSON
ob_start();

require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';

// Note: We're using direct database queries instead of SyncDB
// to avoid sync configuration issues for ATM card transactions

$currentUser = getCurrentUser();

// Get user's store information
$userStore = null;
if ($currentUser['store_id']) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $currentUser['store_id']);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
}

// Handle fetching ATM transactions with filters
if (isset($_GET['action']) && $_GET['action'] === 'get_atm_transactions') {
    ob_clean(); // Clear output buffer
    header('Content-Type: application/json');
    
    $store_id = $userStore ? $userStore['id'] : null;
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $show_settled = isset($_GET['show_settled']) && $_GET['show_settled'] === 'true';
    
    $query = "SELECT at.*, 
              u.full_name as cashier_name,
              at.parent_transaction_id,
              (SELECT COUNT(*) FROM atm_transactions WHERE parent_transaction_id = at.id AND is_deleted = 0) as has_children,
              (SELECT settlement_date FROM atm_settlements WHERE id = at.settlement_id) as settlement_date
              FROM atm_transactions at
              LEFT JOIN users u ON at.user_id = u.id
              WHERE at.is_deleted = 0";
    
    $params = [];
    $types = '';
    
    if ($store_id) {
        $query .= " AND at.store_id = ?";
        $params[] = $store_id;
        $types .= 'i';
    }
    
    // Filter by settlement status
    if (!$show_settled) {
        $query .= " AND (at.settlement_id IS NULL OR at.settlement_id = 0)";
    }
    
    // Date range filter
    if ($start_date && $end_date) {
        $query .= " AND DATE(at.transaction_date) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= 'ss';
    }
    
    $query .= " ORDER BY at.transaction_date DESC";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $transactions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode($transactions);
    $conn->close();
    exit;
}

// Handle re-edit ATM transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reedit_atm_transaction') {
    ob_clean(); // Clear output buffer
    header('Content-Type: application/json');
    
    $original_transaction_id = intval($_POST['original_transaction_id']);
    $reference_number = trim($_POST['reference_number']);
    $customer_name = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : null;
    $amount = floatval($_POST['amount']);
    $user_id = $currentUser['id'];
    $store_id = $userStore ? $userStore['id'] : null;
    
    // Validation
    if (empty($reference_number) || strlen($reference_number) !== 6 || !ctype_digit($reference_number)) {
        echo json_encode(['success' => false, 'error' => 'Reference number must be exactly 6 digits']);
        $conn->close();
        exit;
    }
    
    if ($amount <= 0) {
        echo json_encode(['success' => false, 'error' => 'Amount must be greater than 0']);
        $conn->close();
        exit;
    }
    
    $conn->begin_transaction();
    
    try {
        // Mark original transaction as edited
        $stmt = $conn->prepare("UPDATE atm_transactions SET status = 'edited', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $original_transaction_id);
        $stmt->execute();
        $stmt->close();
        
        // Create new transaction with parent_transaction_id
        $stmt = $conn->prepare("INSERT INTO atm_transactions 
            (parent_transaction_id, reference_number, customer_name, amount, user_id, store_id, transaction_date, status, is_deleted, is_synced, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 'completed', 0, 0, NOW(), NOW())");
        $stmt->bind_param("issdii", $original_transaction_id, $reference_number, $customer_name, $amount, $user_id, $store_id);
        $stmt->execute();
        $new_transaction_id = $conn->insert_id;
        $stmt->close();
        
        // Log activity
        logActivity('transaction', "ATM transaction re-edited: " . number_format($amount, 2), 
            $user_id, $store_id, 
            [
                'new_transaction_id' => $new_transaction_id,
                'original_transaction_id' => $original_transaction_id,
                'reference_number' => $reference_number,
                'customer_name' => $customer_name,
                'amount' => $amount
            ]
        );
        
        $conn->commit();
        echo json_encode([
            'success' => true, 
            'transaction_id' => intval($new_transaction_id),
            'parent_transaction_id' => $original_transaction_id,
            'reference_number' => $reference_number,
            'amount' => floatval($amount)
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    $conn->close();
    exit;
}

// Handle settlement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'settle_transactions') {
    ob_clean(); // Clear output buffer
    header('Content-Type: application/json');
    
    try {
        $user_id = $currentUser['id'];
        $store_id = $userStore ? $userStore['id'] : null;
        $settlement_notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        
        $conn->begin_transaction();
        
        // Get all unsettled transactions for this store
        $query = "SELECT id, amount FROM atm_transactions 
                  WHERE is_deleted = 0 
                  AND (settlement_id IS NULL OR settlement_id = 0)";
        
        if ($store_id) {
            $query .= " AND store_id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $store_id);
        } else {
            $stmt = $conn->prepare($query);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $unsettled_transactions = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        if (empty($unsettled_transactions)) {
            throw new Exception('No unsettled transactions found');
        }
        
        // Calculate total amount
        $total_amount = array_sum(array_column($unsettled_transactions, 'amount'));
        $transaction_count = count($unsettled_transactions);
        
        // Create settlement record
        $stmt = $conn->prepare("INSERT INTO atm_settlements 
            (store_id, user_id, total_amount, transaction_count, settlement_date, notes, is_deleted, created_at, updated_at) 
            VALUES (?, ?, ?, ?, NOW(), ?, 0, NOW(), NOW())");
        $stmt->bind_param("iidis", $store_id, $user_id, $total_amount, $transaction_count, $settlement_notes);
        $stmt->execute();
        $settlement_id = $conn->insert_id;
        $stmt->close();
        
        // Update all unsettled transactions with settlement_id
        $transaction_ids = array_column($unsettled_transactions, 'id');
        $ids_string = implode(',', $transaction_ids);
        
        $update_query = "UPDATE atm_transactions 
                        SET settlement_id = ? 
                        WHERE id IN ($ids_string) AND is_deleted = 0";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("i", $settlement_id);
        $stmt->execute();
        $stmt->close();
        
        // Log activity
        logActivity('settlement', "ATM transactions settled: " . number_format($total_amount, 2), 
            $user_id, $store_id, 
            [
                'settlement_id' => $settlement_id,
                'total_amount' => $total_amount,
                'transaction_count' => $transaction_count,
                'notes' => $settlement_notes
            ]
        );
        
        $conn->commit();
        echo json_encode([
            'success' => true, 
            'settlement_id' => $settlement_id,
            'total_amount' => $total_amount,
            'transaction_count' => $transaction_count
        ]);
    } catch (Exception $e) {
        if ($conn) {
            $conn->rollback();
        }
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    if ($conn) {
        $conn->close();
    }
    exit;
}

$conn->close();

// Clear output buffer and start fresh for HTML
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ATM Card Transaction History<?php echo $userStore ? ' - ' . htmlspecialchars($userStore['store_name']) : ''; ?></title>
    <link rel="stylesheet" href="/oro-store-demo/style.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #667eea;
        }
        
        .header h1 {
            color: #333;
            margin: 0;
            font-size: 32px;
        }
        
        .store-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .summary-card h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            opacity: 0.9;
        }
        
        .summary-card .value {
            font-size: 32px;
            font-weight: bold;
            margin: 0;
        }
        
        .filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        .filter-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            font-size: 14px;
            color: #333;
        }
        
        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .transaction-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }
        
        .transaction-table thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .transaction-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
        }
        
        .transaction-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 14px;
        }
        
        .transaction-table tbody tr:hover {
            background: #f8f9fa;
        }
        
        .transaction-table tbody tr.edited {
            background: #fff3cd;
        }
        
        .transaction-table tbody tr.child-transaction {
            background: #e7f3ff;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-completed {
            background: #d4edda;
            color: #155724;
        }
        
        .status-edited {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-settled {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .action-btn {
            padding: 6px 12px;
            margin: 0 3px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
        }
        
        .btn-edit {
            background: #ffc107;
            color: #333;
        }
        
        .btn-edit:disabled {
            background: #ccc;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 20px;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideDown 0.3s;
        }
        
        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-content h2 {
            margin-top: 0;
            color: #333;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
            box-sizing: border-box;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }
        
        .modal-buttons button {
            flex: 1;
        }
        
        .keyboard-hint {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 12px;
            z-index: 999;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #666;
        }
        
        .empty-state h3 {
            font-size: 24px;
            margin: 0 0 10px 0;
        }
        
        .settlement-warning {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .settlement-warning h3 {
            margin: 0 0 10px 0;
            color: #856404;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>💳 ATM Card Transaction History</h1>
                <?php if ($userStore): ?>
                    <div class="store-info">
                        🏪 <?php echo htmlspecialchars($userStore['store_name']); ?> (<?php echo htmlspecialchars($userStore['store_code']); ?>)
                    </div>
                <?php endif; ?>
            </div>
            <div style="text-align: right;">
                <div style="margin-bottom: 10px; color: #666;">
                    <strong><?php echo htmlspecialchars($currentUser['full_name']); ?></strong><br>
                    <span style="font-size: 12px;"><?php echo ucfirst($currentUser['role']); ?></span>
                </div>
                <button class="btn btn-secondary" onclick="window.close()">Close Window</button>
            </div>
        </div>
        
        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <h3>Total Transactions</h3>
                <p class="value" id="total-count">0</p>
            </div>
            <div class="summary-card">
                <h3>Total Amount</h3>
                <p class="value" id="total-amount">₱0.00</p>
            </div>
            <div class="summary-card">
                <h3>Unsettled</h3>
                <p class="value" id="unsettled-count">0</p>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters">
            <div class="filter-group">
                <label>Start Date</label>
                <input type="date" id="start-date" />
            </div>
            <div class="filter-group">
                <label>End Date</label>
                <input type="date" id="end-date" />
            </div>
            <div class="filter-group">
                <button class="btn btn-primary" onclick="applyFilters()">Apply Filter</button>
            </div>
            <div class="filter-group">
                <button class="btn btn-secondary" onclick="clearFilters()">Clear Filter</button>
            </div>
            <div class="filter-group">
                <button class="btn btn-success" onclick="openSettlementModal()">Settlement (F2)</button>
            </div>
        </div>
        
        <div class="checkbox-group">
            <input type="checkbox" id="show-settled" onchange="loadTransactions()">
            <label for="show-settled" style="margin: 0; font-weight: normal;">Show settled transactions</label>
        </div>
        
        <!-- Transaction Table -->
        <div style="overflow-x: auto;">
            <table class="transaction-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Reference #</th>
                        <th>Customer Name</th>
                        <th>Date & Time</th>
                        <th>Cashier</th>
                        <th style="text-align: right;">Amount</th>
                        <th style="text-align: center;">Status</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody id="transaction-table-body">
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px;">
                            <div class="empty-state">
                                <h3>Loading transactions...</h3>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Edit Transaction Modal -->
    <div class="modal" id="edit-modal">
        <div class="modal-content">
            <h2>Edit ATM Transaction</h2>
            <form id="edit-form">
                <input type="hidden" id="edit-transaction-id">
                
                <div class="form-group">
                    <label>Reference Number <span style="color: #dc3545;">*</span></label>
                    <input type="text" id="edit-reference-number" maxlength="6" pattern="[0-9]{6}" required>
                    <div style="font-size: 12px; color: #999; margin-top: 5px;">Last 6 digits from receipt</div>
                </div>
                
                <div class="form-group">
                    <label>Customer Name</label>
                    <input type="text" id="edit-customer-name" placeholder="Optional">
                </div>
                
                <div class="form-group">
                    <label>Amount <span style="color: #dc3545;">*</span></label>
                    <input type="number" id="edit-amount" step="0.01" min="0.01" required>
                </div>
                
                <div class="modal-buttons">
                    <button type="submit" class="btn btn-primary">Save Changes (Enter)</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel (Esc)</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Settlement Modal -->
    <div class="modal" id="settlement-modal">
        <div class="modal-content">
            <h2>⚠️ Settle Transactions</h2>
            <div class="settlement-warning">
                <h3>Settlement Confirmation</h3>
                <p>You are about to settle all unsettled ATM card transactions.</p>
                <p><strong>Total Amount:</strong> <span id="settlement-total">₱0.00</span></p>
                <p><strong>Total Transactions:</strong> <span id="settlement-count">0</span></p>
            </div>
            
            <div class="form-group">
                <label>Settlement Notes (Optional)</label>
                <textarea id="settlement-notes" rows="3" placeholder="Enter any notes about this settlement..."></textarea>
            </div>
            
            <div class="modal-buttons">
                <button class="btn btn-success" onclick="confirmSettlement()">Confirm Settlement (Enter)</button>
                <button class="btn btn-secondary" onclick="closeSettlementModal()">Cancel (Esc)</button>
            </div>
        </div>
    </div>
    
    <div class="keyboard-hint">
        <strong>Shortcuts:</strong> F2 = Settlement | Esc = Close
    </div>
    
    <script>
        let transactions = [];
        let currentFilters = {
            startDate: null,
            endDate: null,
            showSettled: false
        };
        
        // Load transactions on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadTransactions();
        });
        
        // Load transactions from server
        function loadTransactions() {
            const showSettled = document.getElementById('show-settled').checked;
            currentFilters.showSettled = showSettled;
            
            let url = 'card_transaction_history_user.php?action=get_atm_transactions';
            
            if (currentFilters.startDate && currentFilters.endDate) {
                url += `&start_date=${currentFilters.startDate}&end_date=${currentFilters.endDate}`;
            }
            
            if (showSettled) {
                url += '&show_settled=true';
            }
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    transactions = data;
                    renderTransactions();
                    updateSummary();
                })
                .catch(error => {
                    console.error('Error loading transactions:', error);
                    alert('Failed to load transactions');
                });
        }
        
        // Render transactions table
        function renderTransactions() {
            const tbody = document.getElementById('transaction-table-body');
            
            if (transactions.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px;">
                            <div class="empty-state">
                                <h3>No transactions found</h3>
                                <p>No ATM card transactions match your current filters</p>
                            </div>
                        </td>
                    </tr>
                `;
                return;
            }
            
            tbody.innerHTML = transactions.map(t => {
                const isEdited = t.status === 'edited';
                const isChild = t.parent_transaction_id && t.parent_transaction_id > 0;
                const hasChildren = parseInt(t.has_children) > 0;
                const isSettled = t.settlement_id && t.settlement_id > 0;
                const canEdit = !isEdited && !hasChildren && !isSettled;
                
                let rowClass = '';
                if (isEdited) rowClass = 'edited';
                if (isChild) rowClass = 'child-transaction';
                
                let statusBadge = '';
                if (isSettled) {
                    statusBadge = '<span class="status-badge status-settled">Settled</span>';
                } else if (isEdited) {
                    statusBadge = '<span class="status-badge status-edited">Edited</span>';
                } else {
                    statusBadge = '<span class="status-badge status-completed">Completed</span>';
                }
                
                const prefix = isChild ? '↳ ' : '';
                
                return `
                    <tr class="${rowClass}">
                        <td>${prefix}${t.id}</td>
                        <td><strong>${t.reference_number}</strong></td>
                        <td>${t.customer_name || '<em>N/A</em>'}</td>
                        <td>${formatDateTime(t.transaction_date)}</td>
                        <td>${t.cashier_name || 'Unknown'}</td>
                        <td style="text-align: right; font-weight: bold;">₱${parseFloat(t.amount).toFixed(2)}</td>
                        <td style="text-align: center;">${statusBadge}</td>
                        <td style="text-align: center;">
                            <button class="action-btn btn-edit" 
                                    onclick="openEditModal(${t.id})"
                                    ${!canEdit ? 'disabled' : ''}>
                                ${canEdit ? 'Edit' : 'Locked'}
                            </button>
                        </td>
                    </tr>
                `;
            }).join('');
        }
        
        // Update summary cards
        function updateSummary() {
            const unsettledTransactions = transactions.filter(t => !t.settlement_id || t.settlement_id === 0);
            const totalAmount = unsettledTransactions.reduce((sum, t) => sum + parseFloat(t.amount), 0);
            
            document.getElementById('total-count').textContent = transactions.length;
            document.getElementById('total-amount').textContent = '₱' + totalAmount.toFixed(2);
            document.getElementById('unsettled-count').textContent = unsettledTransactions.length;
        }
        
        // Format date and time
        function formatDateTime(dateString) {
            const date = new Date(dateString);
            return date.toLocaleString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        // Apply filters
        function applyFilters() {
            const startDate = document.getElementById('start-date').value;
            const endDate = document.getElementById('end-date').value;
            
            if (startDate && endDate) {
                if (new Date(startDate) > new Date(endDate)) {
                    alert('Start date cannot be after end date');
                    return;
                }
                currentFilters.startDate = startDate;
                currentFilters.endDate = endDate;
            } else if (startDate || endDate) {
                alert('Please select both start and end dates');
                return;
            }
            
            loadTransactions();
        }
        
        // Clear filters
        function clearFilters() {
            document.getElementById('start-date').value = '';
            document.getElementById('end-date').value = '';
            currentFilters.startDate = null;
            currentFilters.endDate = null;
            loadTransactions();
        }
        
        // Open edit modal
        function openEditModal(transactionId) {
            const transaction = transactions.find(t => t.id === transactionId);
            if (!transaction) return;
            
            document.getElementById('edit-transaction-id').value = transaction.id;
            document.getElementById('edit-reference-number').value = transaction.reference_number;
            document.getElementById('edit-customer-name').value = transaction.customer_name || '';
            document.getElementById('edit-amount').value = parseFloat(transaction.amount).toFixed(2);
            
            document.getElementById('edit-modal').style.display = 'block';
            document.getElementById('edit-reference-number').focus();
        }
        
        // Close edit modal
        function closeEditModal() {
            document.getElementById('edit-modal').style.display = 'none';
            document.getElementById('edit-form').reset();
        }
        
        // Handle edit form submission
        document.getElementById('edit-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const transactionId = document.getElementById('edit-transaction-id').value;
            const referenceNumber = document.getElementById('edit-reference-number').value.trim();
            const customerName = document.getElementById('edit-customer-name').value.trim();
            const amount = parseFloat(document.getElementById('edit-amount').value);
            
            // Validation
            if (referenceNumber.length !== 6 || !/^\d{6}$/.test(referenceNumber)) {
                alert('Reference number must be exactly 6 digits');
                return;
            }
            
            if (amount <= 0) {
                alert('Amount must be greater than 0');
                return;
            }
            
            // Confirm edit
            if (!confirm('This will create a new transaction and mark the original as edited. Continue?')) {
                return;
            }
            
            // Submit edit
            const formData = new FormData();
            formData.append('action', 'reedit_atm_transaction');
            formData.append('original_transaction_id', transactionId);
            formData.append('reference_number', referenceNumber);
            formData.append('customer_name', customerName);
            formData.append('amount', amount);
            
            fetch('/oro-store-demo/transactions/card_transaction_history_user.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Transaction edited successfully!');
                    closeEditModal();
                    loadTransactions();
                } else {
                    alert('Error: ' + (data.error || 'Failed to edit transaction'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to edit transaction');
            });
        });
        
        // Open settlement modal
        function openSettlementModal() {
            const unsettledTransactions = transactions.filter(t => !t.settlement_id || t.settlement_id === 0);
            
            if (unsettledTransactions.length === 0) {
                alert('No unsettled transactions to settle');
                return;
            }
            
            const totalAmount = unsettledTransactions.reduce((sum, t) => sum + parseFloat(t.amount), 0);
            
            document.getElementById('settlement-total').textContent = '₱' + totalAmount.toFixed(2);
            document.getElementById('settlement-count').textContent = unsettledTransactions.length;
            
            document.getElementById('settlement-modal').style.display = 'block';
        }
        
        // Close settlement modal
        function closeSettlementModal() {
            document.getElementById('settlement-modal').style.display = 'none';
            document.getElementById('settlement-notes').value = '';
        }
        
        // Confirm settlement
        function confirmSettlement() {
            const notes = document.getElementById('settlement-notes').value.trim();
            
            if (!confirm('Are you sure you want to settle all unsettled transactions? This action cannot be undone.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'settle_transactions');
            formData.append('notes', notes);
            
            fetch('/oro-store-demo/transactions/card_transaction_history_user.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    return response.text().then(text => {
                        console.error('Non-JSON response:', text);
                        throw new Error('Server returned non-JSON response. Check console for details.');
                    });
                }
                return response.text();
            })
            .then(text => {
                // Try to parse JSON
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('JSON Parse Error:', e);
                    console.error('Response text:', text);
                    throw new Error('Invalid JSON response from server. Check console for details.');
                }
                
                if (data.success) {
                    alert(`Settlement completed successfully!\n\nTotal Amount: ₱${data.total_amount.toFixed(2)}\nTransactions Settled: ${data.transaction_count}`);
                    closeSettlementModal();
                    loadTransactions();
                } else {
                    alert('Error: ' + (data.error || 'Failed to settle transactions'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to settle transactions: ' + error.message);
            });
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Esc - Close modals or window
            if (e.key === 'Escape') {
                e.preventDefault();
                if (document.getElementById('edit-modal').style.display === 'block') {
                    closeEditModal();
                } else if (document.getElementById('settlement-modal').style.display === 'block') {
                    closeSettlementModal();
                } else {
                    window.close();
                }
            }
            
            // F2 - Settlement
            if (e.key === 'F2') {
                e.preventDefault();
                openSettlementModal();
            }
            
            // Enter - Confirm in modals
            if (e.key === 'Enter') {
                if (document.getElementById('settlement-modal').style.display === 'block') {
                    e.preventDefault();
                    confirmSettlement();
                }
            }
        });
        
        // Allow only numbers in reference number
        document.getElementById('edit-reference-number').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        
        // Format amount on blur
        document.getElementById('edit-amount').addEventListener('blur', function() {
            if (this.value) {
                this.value = parseFloat(this.value).toFixed(2);
            }
        });
    </script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('ATM History (User)', array (
  'Features' => 
  array (
    0 => 'User-specific ATM transaction history',
    1 => 'Filtered by current logged-in user',
  ),
));
?>
</body>
</html>