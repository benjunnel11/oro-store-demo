<?php
// Start output buffering to prevent any stray output before JSON
ob_start();

require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';

$currentUser = getCurrentUser();

// Get user's store information
$userStore = null;
if ($currentUser['store_id']) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $currentUser['store_id']);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
}

// Handle transaction submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    ob_clean();
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'submit_transaction') {
        $transaction_type = $_POST['transaction_type']; // withdraw or deposit
        $amount = floatval($_POST['amount']);
        $reason = trim($_POST['reason']);
        $user_id = $currentUser['id'];
        $store_id = $userStore ? $userStore['id'] : null;
        
        // Validation
        if ($amount <= 0) {
            echo json_encode(['success' => false, 'error' => 'Amount must be greater than 0']);
            $conn->close();
            exit;
        }
        
        if (empty($reason)) {
            echo json_encode(['success' => false, 'error' => 'Reason is required']);
            $conn->close();
            exit;
        }
        
        $conn->begin_transaction();
        
        try {
            // Insert cash transaction
            $stmt = $conn->prepare("INSERT INTO cash_transactions 
                (transaction_type, amount, reason, user_id, store_id, transaction_date, status, is_deleted, is_synced) 
                VALUES (?, ?, ?, ?, ?, NOW(), 'completed', 0, 0)");
            $stmt->bind_param("sdsii", $transaction_type, $amount, $reason, $user_id, $store_id);
            $stmt->execute();
            $transaction_id = $conn->insert_id;
            $stmt->close();
            
            // Create corresponding transaction record to affect revenue
            // For deposit: add to revenue (positive transaction)
            // For withdraw: subtract from revenue (negative adjustment)
            $transaction_status = 'completed';
            $payment_method = $transaction_type === 'deposit' ? 'cash_deposit' : 'cash_withdrawal';
            
            // Generate unique transaction number
            $transaction_number = 'CT-' . date('Ymd') . '-' . str_pad($transaction_id, 6, '0', STR_PAD_LEFT);
            
            // Insert into transactions table to affect revenue
            $stmt = $conn->prepare("INSERT INTO transactions 
                (transaction_number, user_id, store_id, total_amount, payment_method, status, transaction_date, is_deleted, is_synced) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)");
            
            // For withdrawals, use negative amount to subtract from revenue
            $revenue_amount = $transaction_type === 'withdraw' ? -$amount : $amount;
            
            $stmt->bind_param("siidss", $transaction_number, $user_id, $store_id, $revenue_amount, $payment_method, $transaction_status);
            $stmt->execute();
            $revenue_transaction_id = $conn->insert_id;
            $stmt->close();
            
            // Log activity
            $activity_description = $transaction_type === 'withdraw' ? 
                "Cash withdrawal: " . number_format($amount, 2) . " (affects revenue)" : 
                "Cash deposit: " . number_format($amount, 2) . " (affects revenue)";
                
            logActivity('transaction', $activity_description, 
                $user_id, $store_id, 
                [
                    'transaction_id' => $transaction_id,
                    'revenue_transaction_id' => $revenue_transaction_id,
                    'transaction_type' => $transaction_type,
                    'amount' => $amount,
                    'revenue_impact' => $revenue_amount,
                    'reason' => $reason
                ]
            );
            
            $conn->commit();
            echo json_encode([
                'success' => true, 
                'transaction_id' => $transaction_id,
                'type' => $transaction_type,
                'amount' => $amount
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        
        $conn->close();
        exit;
    }
    
    if ($_POST['action'] === 'get_transactions') {
        $user_id = $currentUser['id'];
        $store_id = $userStore ? $userStore['id'] : null;
        $is_admin = isAdmin();
        
        $query = "SELECT ct.*, u.full_name as user_name, s.store_name, s.store_code
                  FROM cash_transactions ct
                  LEFT JOIN users u ON ct.user_id = u.id
                  LEFT JOIN stores s ON ct.store_id = s.id
                  WHERE ct.is_deleted = 0";
        
        // Non-admin users only see their own store's transactions
        if (!$is_admin && $store_id) {
            $query .= " AND ct.store_id = ?";
        } elseif (!$is_admin && !$store_id) {
            $query .= " AND ct.user_id = ?";
        }
        
        $query .= " ORDER BY ct.transaction_date DESC LIMIT 100";
        
        $stmt = $conn->prepare($query);
        if (!$is_admin) {
            if ($store_id) {
                $stmt->bind_param("i", $store_id);
            } else {
                $stmt->bind_param("i", $user_id);
            }
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $transactions = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        echo json_encode($transactions);
        $conn->close();
        exit;
    }
    
    if ($_POST['action'] === 'convert_to_stock') {
        $transaction_id = intval($_POST['transaction_id']);
        
        // Get transaction details
        $stmt = $conn->prepare("SELECT * FROM cash_transactions WHERE id = ? AND is_deleted = 0 AND transaction_type = 'withdraw'");
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $transaction = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$transaction) {
            echo json_encode(['success' => false, 'error' => 'Transaction not found or not a withdrawal']);
            $conn->close();
            exit;
        }
        
        if ($transaction['converted_to_stock'] == 1) {
            echo json_encode(['success' => false, 'error' => 'This transaction has already been converted to stock']);
            $conn->close();
            exit;
        }
        
        // Just mark as pending conversion
        // NO financial changes happen here - only after stock is saved
        $stmt = $conn->prepare("UPDATE cash_transactions SET converted_to_stock = 1, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $stmt->close();
        
        // Log activity
        logActivity('transaction', "Transaction #$transaction_id marked for stock conversion (awaiting stock confirmation)", 
            $currentUser['id'], $transaction['store_id'], 
            [
                'transaction_id' => $transaction_id,
                'amount' => $transaction['amount'],
                'status' => 'pending_stock_save'
            ]
        );
        
        echo json_encode([
            'success' => true,
            'amount' => $transaction['amount'],
            'transaction_id' => $transaction_id
        ]);
        
        $conn->close();
        exit;
    }

    if ($_POST['action'] === 'return_remaining_cash') {
        $transaction_id = intval($_POST['transaction_id']);
        $amount_spent = floatval($_POST['amount_spent']);
        
        // Get original transaction
        $stmt = $conn->prepare("SELECT * FROM cash_transactions WHERE id = ? AND is_deleted = 0 AND converted_to_stock = 1");
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $transaction = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$transaction) {
            echo json_encode(['success' => false, 'error' => 'Transaction not found or not converted']);
            $conn->close();
            exit;
        }
        
        $withdrawn_amount = $transaction['amount'];
        $remaining_cash = $withdrawn_amount - $amount_spent;
        
        if ($remaining_cash < 0) {
            echo json_encode(['success' => false, 'error' => 'Stock cost exceeds withdrawn amount']);
            $conn->close();
            exit;
        }
        
        $conn->begin_transaction();
        
        try {
            $user_id = $currentUser['id'];
            $store_id = $transaction['store_id'];
            
            // STEP 1: Restore the FULL withdrawn amount back to revenue
            // This reverses the original withdrawal's impact on revenue
            $transaction_number_revenue = 'CS-REV-RESTORE-' . date('Ymd') . '-' . str_pad($transaction_id, 6, '0', STR_PAD_LEFT);
            $payment_method_revenue = 'stock_conversion_revenue_restore';
            $transaction_status = 'completed';
            
            $stmt = $conn->prepare("INSERT INTO transactions 
                (transaction_number, user_id, store_id, total_amount, payment_method, status, transaction_date, is_deleted, is_synced) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)");
            
            $stmt->bind_param("siidss", $transaction_number_revenue, $user_id, $store_id, $withdrawn_amount, $payment_method_revenue, $transaction_status);
            $stmt->execute();
            $revenue_restore_id = $conn->insert_id;
            $stmt->close();
            
            // STEP 2: Add only the REMAINING CASH (change) back to cash register
            // This is the unused portion that goes back to the register
            if ($remaining_cash > 0) {
                $transaction_number_register = 'CS-REGISTER-RETURN-' . date('Ymd') . '-' . str_pad($transaction_id, 6, '0', STR_PAD_LEFT);
                $payment_method_register = 'cash_return_to_register';
                
                $stmt = $conn->prepare("INSERT INTO transactions 
                    (transaction_number, user_id, store_id, total_amount, payment_method, status, transaction_date, is_deleted, is_synced) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), 0, 0)");
                
                $stmt->bind_param("siidss", $transaction_number_register, $user_id, $store_id, $remaining_cash, $payment_method_register, $transaction_status);
                $stmt->execute();
                $register_return_id = $conn->insert_id;
                $stmt->close();
            }
            
            // Log the completion
            logActivity('transaction', sprintf("Stock purchase completed - Revenue restored: ₱%.2f, Stock spent: ₱%.2f, Change to register: ₱%.2f", 
                $withdrawn_amount, $amount_spent, $remaining_cash), 
                $currentUser['id'], $store_id, 
                [
                    'transaction_id' => $transaction_id,
                    'withdrawn_amount' => $withdrawn_amount,
                    'amount_spent' => $amount_spent,
                    'remaining_cash' => $remaining_cash,
                    'revenue_restored' => $withdrawn_amount,
                    'register_change' => $remaining_cash,
                    'revenue_restore_transaction_id' => $revenue_restore_id,
                    'register_return_transaction_id' => isset($register_return_id) ? $register_return_id : null
                ]
            );
            
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'withdrawn_amount' => $withdrawn_amount,
                'amount_spent' => $amount_spent,
                'remaining_cash' => $remaining_cash,
                'revenue_restored' => $withdrawn_amount,
                'register_change' => $remaining_cash
            ]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        
        $conn->close();
        exit;
    }
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
    <title>Cash Register Management<?php echo $userStore ? ' - ' . htmlspecialchars($userStore['store_name']) : ''; ?></title>
    <link rel="stylesheet" href="/oro-store-demo/style.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
            margin: 0;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }
        
        .header h1 {
            margin: 0 0 10px 0;
            font-size: 36px;
        }
        
        .header p {
            margin: 0;
            font-size: 16px;
            opacity: 0.9;
        }
        
        .store-info {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            display: inline-block;
            margin-top: 15px;
            font-weight: bold;
        }
        
        .cards-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            margin-bottom: 40px;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 40px 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            position: relative;
        }
        
        .card:hover,
        .card.selected {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
        }
        
        .card.selected {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .card-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .card-title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .card-description {
            font-size: 14px;
            opacity: 0.8;
        }
        
        .card.selected .card-description {
            opacity: 1;
        }
        
        .content-section {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            display: none;
        }
        
        .content-section.active {
            display: block;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            box-sizing: border-box;
            transition: all 0.3s;
        }
        
        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        
        .btn {
            flex: 1;
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            font-size: 16px;
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
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .transactions-list {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .transaction-item {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .transaction-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .transaction-item.withdraw {
            border-left-color: #dc3545;
        }
        
        .transaction-item.deposit {
            border-left-color: #28a745;
        }
        
        .transaction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .transaction-type {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .transaction-type.withdraw {
            background: #ffe6e6;
            color: #dc3545;
        }
        
        .transaction-type.deposit {
            background: #e6f7e6;
            color: #28a745;
        }
        
        .transaction-amount {
            font-size: 20px;
            font-weight: bold;
        }
        
        .transaction-amount.withdraw {
            color: #dc3545;
        }
        
        .transaction-amount.deposit {
            color: #28a745;
        }
        
        .transaction-details {
            font-size: 14px;
            color: #666;
            margin-top: 8px;
        }
        
        .transaction-actions {
            margin-top: 10px;
            display: flex;
            gap: 10px;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-convert {
            background: #ffc107;
            color: #333;
        }
        
        .btn-convert:hover {
            background: #e0a800;
        }
        
        .btn-convert:disabled {
            background: #ccc;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .keyboard-hint {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            z-index: 1000;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .revenue-note {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 12px 15px;
            margin: 15px 0;
            border-radius: 8px;
            font-size: 14px;
            color: #856404;
        }
        
        .revenue-note strong {
            color: #533f03;
        }
        
        @media (max-width: 768px) {
            .cards-container {
                grid-template-columns: 1fr;
            }
            
            .btn-group {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💰 Cash Register Management</h1>
            <p>Manage withdrawals, deposits, and cash transactions</p>
            <?php if ($userStore): ?>
                <div class="store-info">
                    🏪 <?php echo htmlspecialchars($userStore['store_name']); ?> (<?php echo htmlspecialchars($userStore['store_code']); ?>)
                </div>
            <?php endif; ?>
            <div class="store-info" style="margin-left: 10px;">
                👤 <?php echo htmlspecialchars($currentUser['full_name']); ?> (<?php echo ucfirst($currentUser['role']); ?>)
            </div>
        </div>
        
        <!-- Action Cards -->
        <div class="cards-container">
            <div class="card" id="card-withdraw" data-action="withdraw">
                <div class="card-icon">💸</div>
                <div class="card-title">Withdraw</div>
                <div class="card-description">Remove cash from register</div>
            </div>
            
            <div class="card" id="card-deposit" data-action="deposit">
                <div class="card-icon">💵</div>
                <div class="card-title">Deposit</div>
                <div class="card-description">Add cash to register</div>
            </div>
            
            <div class="card" id="card-history" data-action="history">
                <div class="card-icon">📋</div>
                <div class="card-title">Transaction History</div>
                <div class="card-description">View all transactions</div>
            </div>
        </div>
        
        <!-- Withdraw Section -->
        <div class="content-section" id="section-withdraw">
            <h2>💸 Withdraw Cash</h2>
            <p style="color: #666; margin-bottom: 20px;">Remove cash from the register and record the reason</p>
            
            <div class="revenue-note">
                <strong>⚠️ Revenue Impact:</strong> This withdrawal will be subtracted from total and today's revenue.
            </div>
            
            <form id="withdraw-form">
                <div class="form-group">
                    <label>Amount <span style="color: #dc3545;">*</span></label>
                    <input type="number" id="withdraw-amount" step="0.01" min="0.01" placeholder="0.00" required>
                </div>
                
                <div class="form-group">
                    <label>Reason <span style="color: #dc3545;">*</span></label>
                    <textarea id="withdraw-reason" placeholder="Enter reason for withdrawal..." required></textarea>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Submit Withdrawal</button>
                    <button type="button" class="btn btn-secondary" onclick="goBack()">Cancel</button>
                </div>
            </form>
        </div>
        
        <!-- Deposit Section -->
        <div class="content-section" id="section-deposit">
            <h2>💵 Deposit Cash</h2>
            <p style="color: #666; margin-bottom: 20px;">Add cash to the register and record the reason</p>
            
            <div class="revenue-note">
                <strong>✅ Revenue Impact:</strong> This deposit will be added to total and today's revenue.
            </div>
            
            <form id="deposit-form">
                <div class="form-group">
                    <label>Amount <span style="color: #dc3545;">*</span></label>
                    <input type="number" id="deposit-amount" step="0.01" min="0.01" placeholder="0.00" required>
                </div>
                
                <div class="form-group">
                    <label>Reason <span style="color: #dc3545;">*</span></label>
                    <textarea id="deposit-reason" placeholder="Enter reason for deposit..." required></textarea>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">Submit Deposit</button>
                    <button type="button" class="btn btn-secondary" onclick="goBack()">Cancel</button>
                </div>
            </form>
        </div>
        
        <!-- History Section -->
        <div class="content-section" id="section-history">
            <h2>📋 Transaction History</h2>
            <p style="color: #666; margin-bottom: 30px;">View and manage cash register transactions</p>
            
            <div class="transactions-list" id="transactions-list">
                <div class="empty-state">
                    <div class="empty-state-icon">📭</div>
                    <h3>Loading transactions...</h3>
                </div>
            </div>
            
            <div class="btn-group" style="margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="goBack()">Back</button>
            </div>
        </div>
    </div>
    
    <div class="keyboard-hint">
        <strong>Navigation:</strong> ← → Select | Enter Confirm | Esc Back | Auto-closes after transaction
    </div>
    
    <script>
        let selectedCard = null;
        const cards = document.querySelectorAll('.card');
        const sections = document.querySelectorAll('.content-section');
        
        // Initialize - show cards
        function init() {
            selectedCard = 0;
            updateCardSelection();
        }
        
        // Update card selection
        function updateCardSelection() {
            cards.forEach((card, index) => {
                card.classList.toggle('selected', index === selectedCard);
            });
        }
        
        // Select action
        function selectAction(action) {
            // Hide all sections and cards
            sections.forEach(s => s.classList.remove('active'));
            document.querySelector('.cards-container').style.display = 'none';
            
            // Show selected section
            document.getElementById('section-' + action).classList.add('active');
            
            // Focus on first input
            setTimeout(() => {
                if (action === 'withdraw') {
                    document.getElementById('withdraw-amount').focus();
                } else if (action === 'deposit') {
                    document.getElementById('deposit-amount').focus();
                } else if (action === 'history') {
                    loadTransactions();
                }
            }, 100);
        }
        
        // Go back to card selection
        function goBack() {
            sections.forEach(s => s.classList.remove('active'));
            document.querySelector('.cards-container').style.display = 'grid';
            selectedCard = 0;
            updateCardSelection();
        }
        
        // Handle withdraw form
        document.getElementById('withdraw-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const amount = parseFloat(document.getElementById('withdraw-amount').value);
            const reason = document.getElementById('withdraw-reason').value.trim();
            
            if (!amount || amount <= 0) {
                alert('Please enter a valid amount');
                return;
            }
            
            if (!reason) {
                alert('Please enter a reason');
                return;
            }
            
            if (!confirm(`Confirm withdrawal of ₱${amount.toFixed(2)}?\n\nThis will be subtracted from revenue.\n\nReason: ${reason}`)) {
                return;
            }
            
            submitTransaction('withdraw', amount, reason);
        });
        
        // Handle deposit form
        document.getElementById('deposit-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const amount = parseFloat(document.getElementById('deposit-amount').value);
            const reason = document.getElementById('deposit-reason').value.trim();
            
            if (!amount || amount <= 0) {
                alert('Please enter a valid amount');
                return;
            }
            
            if (!reason) {
                alert('Please enter a reason');
                return;
            }
            
            if (!confirm(`Confirm deposit of ₱${amount.toFixed(2)}?\n\nThis will be added to revenue.\n\nReason: ${reason}`)) {
                return;
            }
            
            submitTransaction('deposit', amount, reason);
        });
        
        // Submit transaction
        function submitTransaction(type, amount, reason) {
            const formData = new FormData();
            formData.append('action', 'submit_transaction');
            formData.append('transaction_type', type);
            formData.append('amount', amount);
            formData.append('reason', reason);
            
            fetch('/oro-store-demo/transactions/other_transaction.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const typeText = type === 'withdraw' ? 'Withdrawal' : 'Deposit';
                    const impact = type === 'withdraw' ? 'subtracted from' : 'added to';
                    alert(`✓ ${typeText} Completed!\n\nAmount: ₱${parseFloat(data.amount).toFixed(2)}\nTransaction ID: ${data.transaction_id}\n\nRevenue ${impact} total and today's revenue.`);
                    
                    // Close window after 2 seconds
                    setTimeout(() => {
                        window.close();
                    }, 2000);
                } else {
                    alert('Error: ' + (data.error || 'Failed to process transaction'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to process transaction: ' + error.message);
            });
        }
        
        // Load transactions
        function loadTransactions() {
            const formData = new FormData();
            formData.append('action', 'get_transactions');
            
            fetch('/oro-store-demo/transactions/other_transaction.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                displayTransactions(data);
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('transactions-list').innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">❌</div>
                        <h3>Error Loading Transactions</h3>
                        <p>${error.message}</p>
                    </div>
                `;
            });
        }
        
        // Display transactions
        function displayTransactions(transactions) {
            const container = document.getElementById('transactions-list');
            
            if (transactions.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <h3>No Transactions Found</h3>
                        <p>No cash register transactions have been recorded yet</p>
                    </div>
                `;
                return;
            }
            
            let html = '';
            transactions.forEach(t => {
                const isWithdraw = t.transaction_type === 'withdraw';
                const typeClass = isWithdraw ? 'withdraw' : 'deposit';
                const icon = isWithdraw ? '💸' : '💵';
                const sign = isWithdraw ? '-' : '+';
                const isConverted = parseInt(t.converted_to_stock) === 1;
                
                html += `
                    <div class="transaction-item ${typeClass}">
                        <div class="transaction-header">
                            <div>
                                <span class="transaction-type ${typeClass}">${icon} ${t.transaction_type.toUpperCase()}</span>
                                ${isConverted ? '<span class="transaction-type deposit" style="background: #e7f3ff; color: #004085; margin-left: 5px;">📦 Converted to Stock</span>' : ''}
                            </div>
                            <div class="transaction-amount ${typeClass}">${sign}₱${parseFloat(t.amount).toFixed(2)}</div>
                        </div>
                        <div class="transaction-details">
                            <strong>Reason:</strong> ${t.reason}<br>
                            <strong>Date:</strong> ${new Date(t.transaction_date).toLocaleString()}<br>
                            <strong>By:</strong> ${t.user_name || 'Unknown'}
                            ${t.store_name ? `<br><strong>Store:</strong> ${t.store_code} - ${t.store_name}` : ''}
                            <br><strong>Revenue Impact:</strong> <span style="color: ${isWithdraw ? '#dc3545' : '#28a745'}; font-weight: bold;">${sign}₱${parseFloat(t.amount).toFixed(2)}</span>
                        </div>
                        ${!isConverted && isWithdraw ? `
                            <div class="transaction-actions">
                                <button class="btn-small btn-convert" onclick="convertToStock(${t.id}, ${t.amount})">
                                    📦 Convert to Stock Purchase
                                </button>
                            </div>
                        ` : ''}
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }
        
        // Convert transaction to stock
        function convertToStock(transactionId, amount) {
            if (!confirm(`Convert this transaction of ₱${amount.toFixed(2)} to stock purchase?\n\nYou will be redirected to Add Stock page.`)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'convert_to_stock');
            formData.append('transaction_id', transactionId);
            
            fetch('/oro-store-demo/transactions/other_transaction.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✓ Transaction marked for stock conversion!\n\nRedirecting to Add Stock page...');
                    
                    // Store the withdrawal info for later use
                    sessionStorage.setItem('stockBudget', data.amount);
                    sessionStorage.setItem('stockBudgetTransactionId', data.transaction_id);
                    
                    // Redirect to add stock page
                    window.location.href = '/oro-store-demo/stock/add_stock.php';
                } else {
                    alert('Error: ' + (data.error || 'Failed to convert transaction'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to convert transaction: ' + error.message);
            });
        }
        
        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            const cardsVisible = document.querySelector('.cards-container').style.display !== 'none';
            
            if (cardsVisible) {
                if (e.key === 'ArrowLeft') {
                    e.preventDefault();
                    selectedCard = Math.max(0, selectedCard - 1);
                    updateCardSelection();
                } else if (e.key === 'ArrowRight') {
                    e.preventDefault();
                    selectedCard = Math.min(cards.length - 1, selectedCard + 1);
                    updateCardSelection();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    const action = cards[selectedCard].dataset.action;
                    selectAction(action);
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    window.close();
                }
            } else {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    goBack();
                }
            }
        });
        
        // Click handlers
        cards.forEach((card, index) => {
            card.addEventListener('click', function() {
                selectedCard = index;
                const action = this.dataset.action;
                selectAction(action);
            });
        });
        
        // Initialize
        init();
    </script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Other Transactions', array (
  'Features' => 
  array (
    0 => 'Handles stock budget cash out and return',
    1 => 'Expense recording for operational costs',
  ),
));
?>
</body>
</html>