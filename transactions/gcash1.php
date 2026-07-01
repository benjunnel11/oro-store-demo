<?php
// Database connection
require_once __DIR__ . '/../core/db_config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle transaction submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_gcash') {
    header('Content-Type: application/json');
    
    $type = $_POST['type'];
    $amount = floatval($_POST['amount']);
    $fee = floatval($_POST['fee']);
    $total = floatval($_POST['total']);
    $reference = $_POST['reference'];
    
    try {
        $stmt = $conn->prepare("INSERT INTO gcash_transactions (transaction_type, amount, fee, total_amount, reference_number) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sddds", $type, $amount, $fee, $total, $reference);
        $stmt->execute();
        $transaction_id = $stmt->insert_id;
        $stmt->close();
        
        echo json_encode(['success' => true, 'transaction_id' => $transaction_id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    $conn->close();
    exit;
}

// Handle transaction history fetch
if (isset($_GET['action']) && $_GET['action'] === 'get_gcash_transactions') {
    header('Content-Type: application/json');
    
    $stmt = $conn->prepare("SELECT * FROM gcash_transactions ORDER BY transaction_date DESC LIMIT 100");
    $stmt->execute();
    $result = $stmt->get_result();
    $transactions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode($transactions);
    $conn->close();
    exit;
}

// Handle transaction details fetch
if (isset($_GET['action']) && $_GET['action'] === 'get_gcash_details') {
    header('Content-Type: application/json');
    
    $transaction_id = intval($_GET['id']);
    
    $stmt = $conn->prepare("SELECT * FROM gcash_transactions WHERE id = ?");
    $stmt->bind_param("i", $transaction_id);
    $stmt->execute();
    $transaction = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Get related transactions
    $related = [];
    if ($transaction['original_transaction_id']) {
        $stmt = $conn->prepare("SELECT * FROM gcash_transactions WHERE id = ?");
        $stmt->bind_param("i", $transaction['original_transaction_id']);
        $stmt->execute();
        $related['original'] = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    
    $stmt = $conn->prepare("SELECT * FROM gcash_transactions WHERE original_transaction_id = ? ORDER BY transaction_date DESC");
    $stmt->bind_param("i", $transaction_id);
    $stmt->execute();
    $related['edits'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo json_encode([
        'transaction' => $transaction,
        'related' => $related
    ]);
    $conn->close();
    exit;
}

// Handle re-edited transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reedit_gcash') {
    header('Content-Type: application/json');
    
    $original_id = intval($_POST['original_id']);
    $type = $_POST['type'];
    $amount = floatval($_POST['amount']);
    $fee = floatval($_POST['fee']);
    $total = floatval($_POST['total']);
    $reference = $_POST['reference'];
    
    $conn->begin_transaction();
    
    try {
        // Mark original as edited
        $stmt = $conn->prepare("UPDATE gcash_transactions SET status = 'edited' WHERE id = ?");
        $stmt->bind_param("i", $original_id);
        $stmt->execute();
        $stmt->close();
        
        // Create new transaction
        $stmt = $conn->prepare("INSERT INTO gcash_transactions (transaction_type, amount, fee, total_amount, reference_number, status, original_transaction_id, edited_date) VALUES (?, ?, ?, ?, ?, 'completed', ?, NOW())");
        $stmt->bind_param("sdddsi", $type, $amount, $fee, $total, $reference, $original_id);
        $stmt->execute();
        $new_id = $stmt->insert_id;
        $stmt->close();
        
        $conn->commit();
        echo json_encode(['success' => true, 'transaction_id' => $new_id]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
    $conn->close();
    exit;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GCash Transactions</title>
    <link rel="stylesheet" href="/oro-store-demo/style.css">
</head>
<body>
    <div class="gcash-container">
        <header class="gcash-header">
            <h1>💰 GCash Transactions</h1>
            <button onclick="window.close()" class="btn-close">Close</button>
        </header>

        <div class="gcash-content">
            <div class="transaction-type-selector">
                <button class="type-btn active" data-type="cash_in" onclick="selectType('cash_in')">
                    📥 Cash In (+1% fee)
                </button>
                <button class="type-btn" data-type="cash_out" onclick="selectType('cash_out')">
                    📤 Cash Out (+2% fee)
                </button>
            </div>

            <div class="transaction-form">
                <div class="form-group">
                    <label>Amount:</label>
                    <input type="number" id="amount" step="0.01" min="0" placeholder="Enter amount" autofocus>
                </div>

                <div class="form-group">
                    <label>Last 6 Digits of Reference Number:</label>
                    <input type="text" id="reference" maxlength="6" pattern="[0-9]{6}" placeholder="000000">
                </div>

                <div class="calculation-display">
                    <div class="calc-row">
                        <span>Amount:</span>
                        <span id="display-amount">₱0.00</span>
                    </div>
                    <div class="calc-row">
                        <span>Fee (<span id="fee-percent">1</span>%):</span>
                        <span id="display-fee">₱0.00</span>
                    </div>
                    <div class="calc-row total">
                        <span>Total:</span>
                        <span id="display-total">₱0.00</span>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn-submit" onclick="submitTransaction()">Complete Transaction (Enter)</button>
                    <button class="btn-print" onclick="printGCashReceipt()">Print Receipt (F1)</button>
                </div>
            </div>
        </div>

        <div class="gcash-footer">
            <button class="btn-history" onclick="openGCashHistory()">View History (F12)</button>
        </div>
    </div>

    <!-- History Modal -->
    <div class="modal" id="history-modal">
        <div class="modal-content large-modal">
            <div class="modal-header">
                <h2>GCash Transaction History</h2>
                <button onclick="closeHistoryModal()" class="btn-close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <table class="gcash-history-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Fee</th>
                            <th>Total</th>
                            <th>Reference</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="history-tbody">
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 20px;">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal" id="details-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Transaction Details</h2>
                <button onclick="closeDetailsModal()" class="btn-close-modal">&times;</button>
            </div>
            <div id="details-content" class="modal-body">
                Loading...
            </div>
            <div class="modal-buttons">
                <button class="btn-confirm" onclick="loadTransactionForEdit()">Edit Transaction (Enter)</button>
                <button class="btn-cancel" onclick="closeDetailsModal()">Close (Esc)</button>
            </div>
        </div>
    </div>

    <div class="keyboard-hint">
        <strong>Shortcuts:</strong> F1 Print | F12 History | Enter Submit | Esc Close
    </div>

    <script>
        let currentType = 'cash_in';
        let currentFeePercent = 1;
        let selectedTransaction = null;
        let isEditMode = false;
        let originalTransactionId = null;

        // Select transaction type
        function selectType(type) {
            currentType = type;
            currentFeePercent = type === 'cash_in' ? 1 : 2;
            
            document.querySelectorAll('.type-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.type === type);
            });
            
            document.getElementById('fee-percent').textContent = currentFeePercent;
            calculateTotal();
        }

        // Calculate total
        function calculateTotal() {
            const amount = parseFloat(document.getElementById('amount').value) || 0;
            const fee = amount * (currentFeePercent / 100);
            const total = amount + fee;

            document.getElementById('display-amount').textContent = '₱' + amount.toFixed(2);
            document.getElementById('display-fee').textContent = '₱' + fee.toFixed(2);
            document.getElementById('display-total').textContent = '₱' + total.toFixed(2);
        }

        // Auto-calculate on input
        document.getElementById('amount').addEventListener('input', calculateTotal);

        // Submit transaction
        function submitTransaction() {
            const amount = parseFloat(document.getElementById('amount').value);
            const reference = document.getElementById('reference').value;

            if (!amount || amount <= 0) {
                alert('Please enter a valid amount');
                return;
            }

            if (!reference || reference.length !== 6 || !/^\d{6}$/.test(reference)) {
                alert('Please enter a valid 6-digit reference number');
                return;
            }

            const fee = amount * (currentFeePercent / 100);
            const total = amount + fee;

            if (isEditMode) {
                // Re-edit transaction
                const formData = new FormData();
                formData.append('action', 'reedit_gcash');
                formData.append('original_id', originalTransactionId);
                formData.append('type', currentType);
                formData.append('amount', amount);
                formData.append('fee', fee);
                formData.append('total', total);
                formData.append('reference', reference);

                fetch('/oro-store-demo/transactions/gcash.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Transaction re-edited successfully! New ID: #' + data.transaction_id);
                        resetForm();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => alert('Error: ' + error));
            } else {
                // New transaction
                const formData = new FormData();
                formData.append('action', 'complete_gcash');
                formData.append('type', currentType);
                formData.append('amount', amount);
                formData.append('fee', fee);
                formData.append('total', total);
                formData.append('reference', reference);

                fetch('/oro-store-demo/transactions/gcash.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Transaction completed! ID: #' + data.transaction_id);
                        resetForm();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => alert('Error: ' + error));
            }
        }

        // Print GCash receipt
        function printGCashReceipt() {
            const amount = parseFloat(document.getElementById('amount').value);
            const reference = document.getElementById('reference').value;

            if (!amount || amount <= 0) {
                alert('Please enter a valid amount');
                return;
            }

            if (!reference || reference.length !== 6 || !/^\d{6}$/.test(reference)) {
                alert('Please enter a valid 6-digit reference number');
                return;
            }

            const fee = amount * (currentFeePercent / 100);
            const total = amount + fee;

            const receiptData = {
                type: currentType,
                amount: amount,
                fee: fee,
                total: total,
                reference: reference,
                feePercent: currentFeePercent,
                date: new Date().toLocaleString(),
                isEditMode: isEditMode,
                originalTransactionId: originalTransactionId
            };

            const receiptWindow = window.open('/oro-store-demo/print/print_gcash_receipt.php', '_blank', 'width=400,height=600');
            
            if (receiptWindow) {
                receiptWindow.addEventListener('load', function() {
                    receiptWindow.postMessage(receiptData, '*');
                });
            }
        }

        // Reset form
        function resetForm() {
            document.getElementById('amount').value = '';
            document.getElementById('reference').value = '';
            isEditMode = false;
            originalTransactionId = null;
            calculateTotal();
            document.getElementById('amount').focus();
        }

        // Open GCash history
        function openGCashHistory() {
            document.getElementById('history-modal').classList.add('active');
            loadGCashHistory();
        }

        // Close history modal
        function closeHistoryModal() {
            document.getElementById('history-modal').classList.remove('active');
        }

        // Load GCash history
        function loadGCashHistory() {
            fetch('/oro-store-demo/transactions/gcash.php?action=get_gcash_transactions')
                .then(response => response.json())
                .then(data => {
                    const tbody = document.getElementById('history-tbody');
                    
                    if (data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="9" style="text-align: center; padding: 20px;">No transactions found</td></tr>';
                        return;
                    }

                    let html = '';
                    data.forEach(t => {
                        const date = new Date(t.transaction_date).toLocaleString();
                        const typeClass = t.transaction_type === 'cash_in' ? 'type-cash-in' : 'type-cash-out';
                        const typeText = t.transaction_type === 'cash_in' ? '📥 Cash In' : '📤 Cash Out';
                        const statusClass = t.status === 'completed' ? 'status-completed' : 
                                          t.status === 'edited' ? 'status-edited' : 'status-voided';
                        const isEditable = t.status === 'completed';

                        html += `
                            <tr>
                                <td>#${t.id}</td>
                                <td>${date}</td>
                                <td><span class="${typeClass}">${typeText}</span></td>
                                <td>₱${parseFloat(t.amount).toFixed(2)}</td>
                                <td>₱${parseFloat(t.fee).toFixed(2)}</td>
                                <td>₱${parseFloat(t.total_amount).toFixed(2)}</td>
                                <td>${t.reference_number}</td>
                                <td><span class="status-badge ${statusClass}">${t.status.toUpperCase()}</span></td>
                                <td>
                                    ${isEditable ? `
                                        <button onclick="viewGCashDetails(${t.id})" class="btn-view">View</button>
                                    ` : `
                                        <span style="color: #999; font-size: 12px;">N/A</span>
                                    `}
                                </td>
                            </tr>
                        `;
                    });
                    tbody.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error loading history:', error);
                    alert('Error loading transaction history');
                });
        }

        // View GCash details
        function viewGCashDetails(id) {
            fetch(`/oro-store-demo/transactions/gcash.php?action=get_gcash_details&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    selectedTransaction = data.transaction;
                    displayGCashDetails(data);
                    document.getElementById('details-modal').classList.add('active');
                    closeHistoryModal();
                })
                .catch(error => {
                    console.error('Error loading details:', error);
                    alert('Error loading transaction details');
                });
        }

        // Display GCash details
        function displayGCashDetails(data) {
            const t = data.transaction;
            const related = data.related;
            const typeText = t.transaction_type === 'cash_in' ? '📥 Cash In' : '📤 Cash Out';
            const typeClass = t.transaction_type === 'cash_in' ? 'type-cash-in' : 'type-cash-out';

            let html = `
                <div class="detail-section">
                    <h3>Transaction Information</h3>
                    <div class="detail-grid">
                        <div><strong>Transaction ID:</strong> #${t.id}</div>
                        <div><strong>Date:</strong> ${new Date(t.transaction_date).toLocaleString()}</div>
                        <div><strong>Type:</strong> <span class="${typeClass}">${typeText}</span></div>
                        <div><strong>Status:</strong> <span class="status-badge status-${t.status}">${t.status.toUpperCase()}</span></div>
                        <div><strong>Amount:</strong> ₱${parseFloat(t.amount).toFixed(2)}</div>
                        <div><strong>Fee:</strong> ₱${parseFloat(t.fee).toFixed(2)}</div>
                        <div><strong>Total:</strong> ₱${parseFloat(t.total_amount).toFixed(2)}</div>
                        <div><strong>Reference:</strong> ${t.reference_number}</div>
                    </div>
                </div>

                ${t.original_transaction_id ? `
                    <div class="detail-section warning-section">
                        <h3>⚠️ This is an Edited Transaction</h3>
                        <p>Original Transaction ID: <a href="#" onclick="viewGCashDetails(${t.original_transaction_id}); return false;">#${t.original_transaction_id}</a></p>
                        <p>Edited Date: ${new Date(t.edited_date).toLocaleString()}</p>
                    </div>
                ` : ''}

                ${related.edits && related.edits.length > 0 ? `
                    <div class="detail-section warning-section">
                        <h3>⚠️ This Transaction Has Been Edited</h3>
                        <p>This transaction was later modified. New versions:</p>
                        <ul>
                            ${related.edits.map(edit => `
                                <li>
                                    <a href="#" onclick="viewGCashDetails(${edit.id}); return false;">Transaction #${edit.id}</a> 
                                    - ${new Date(edit.transaction_date).toLocaleString()} 
                                    (₱${parseFloat(edit.total_amount).toFixed(2)})
                                </li>
                            `).join('')}
                        </ul>
                    </div>
                ` : ''}
            `;

            document.getElementById('details-content').innerHTML = html;
        }

        // Close details modal
        function closeDetailsModal() {
            document.getElementById('details-modal').classList.remove('active');
            selectedTransaction = null;
        }

        // Load transaction for editing
        function loadTransactionForEdit() {
            if (!selectedTransaction) return;

            if (confirm('Load this transaction for editing?')) {
                isEditMode = true;
                originalTransactionId = selectedTransaction.id;

                // Set form values
                selectType(selectedTransaction.transaction_type);
                document.getElementById('amount').value = selectedTransaction.amount;
                document.getElementById('reference').value = selectedTransaction.reference_number;
                calculateTotal();

                closeDetailsModal();
                alert('Transaction loaded for editing. Modify the values and submit.');
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            const historyModalOpen = document.getElementById('history-modal').classList.contains('active');
            const detailsModalOpen = document.getElementById('details-modal').classList.contains('active');

            if (e.key === 'F1') {
                e.preventDefault();
                printGCashReceipt();
            } else if (e.key === 'F12') {
                e.preventDefault();
                if (!historyModalOpen && !detailsModalOpen) {
                    openGCashHistory();
                }
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (detailsModalOpen) {
                    loadTransactionForEdit();
                } else if (!historyModalOpen) {
                    submitTransaction();
                }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                if (historyModalOpen) {
                    closeHistoryModal();
                } else if (detailsModalOpen) {
                    closeDetailsModal();
                } else {
                    window.close();
                }
            }
        });

        // Initialize
        calculateTotal();
    </script>
</body>
</html>