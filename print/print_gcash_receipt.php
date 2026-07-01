<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print GCash Receipt</title>
    <link rel="stylesheet" href="/oro-store-demo/print/print_receipt.css">
</head>
<body>
    <div class="no-print instructions">
        <strong>📋 Instructions:</strong>
        Press <kbd>Enter</kbd> to complete transaction and print, or <kbd>Esc</kbd> to cancel
    </div>

    <div class="receipt-container">
        <div id="receipt-content" class="loading">
            Waiting for transaction data...
        </div>
    </div>

    <button class="print-button no-print" id="print-btn" style="display: none;">
        Press Enter to Complete & Print
    </button>

    <script>
        let receiptData = null;
        let transactionCompleted = false;
        let transactionId = null;

        // Listen for data from parent window
        window.addEventListener('message', function(event) {
            receiptData = event.data;
            displayReceipt(receiptData);
        });

        // Display receipt
        function displayReceipt(data) {
            const content = document.getElementById('receipt-content');
            const printBtn = document.getElementById('print-btn');

            const typeText = data.type === 'cash_in' ? 'CASH IN' : 'CASH OUT';
            const typeClass = data.type === 'cash_in' ? 'type-cash-in' : 'type-cash-out';
            const typeIcon = data.type === 'cash_in' ? '💰' : '💸';

            let html = `
                <div class="receipt-header">
                    <div class="payment-icon">${typeIcon}</div>
                    <div class="store-name">ORO STORE</div>
                    ${data.storeName ? `<div class="store-badge">🏪 ${data.storeName}</div>` : ''}
                    <div class="store-tagline">GCash Transaction</div>
                    <div class="store-info">
                        <div>📱 Mobile Money Service</div>
                        <div>🔒 Secure & Fast</div>
                    </div>
                </div>

                ${data.isEditMode ? `
                    <div class="reedit-indicator">
                        ⚠️ EDITED TRANSACTION<br>
                        Original ID: #${data.originalTransactionId}
                    </div>
                ` : ''}

                <div id="transaction-id-placeholder"></div>

                <div class="transaction-type ${typeClass}">
                    ${typeIcon} ${typeText}
                </div>

                <div class="transaction-info">
                    <div>
                        <span class="info-label">Date:</span>
                        <span class="info-value">${data.date}</span>
                    </div>
                    <div>
                        <span class="info-label">Time:</span>
                        <span class="info-value">${new Date().toLocaleTimeString()}</span>
                    </div>
                    <div>
                        <span class="info-label">Reference:</span>
                        <span class="info-value">${data.reference}</span>
                    </div>
                    <div>
                        <span class="info-label">Processor:</span>
                        <span class="info-value">${data.cashierName || 'Staff'}</span>
                    </div>
                </div>

                <div class="details-section">
                    <div class="detail-row">
                        <span style="font-weight: bold;">Amount:</span>
                        <span style="font-weight: bold;">₱${data.amount.toFixed(2)}</span>
                    </div>
                    <div class="detail-row">
                        <span>Service Fee (${data.feePercent}%):</span>
                        <span style="color: #dc3545;">₱${data.fee.toFixed(2)}</span>
                    </div>
                </div>

                <div class="totals-section">
                    <div class="total-row grand-total">
                        <span>${data.type === 'cash_in' ? 'TOTAL RECEIVED:' : 'TOTAL PAID:'}</span>
                        <span>₱${data.total.toFixed(2)}</span>
                    </div>
                </div>

                <div class="receipt-footer">
                    <div class="thank-you">★ TRANSACTION COMPLETE ★</div>
                    <div class="footer-message">
                        ${data.type === 'cash_in' ? 'Cash received via GCash' : 'Cash disbursed via GCash'}
                    </div>
                    <div class="barcode-placeholder" id="barcode-placeholder"></div>
                    <div style="margin-top: 12px; font-size: 9px; color: #999;">
                        Keep this receipt for your records
                    </div>
                    <div style="margin-top: 4px; font-size: 9px; color: #999;">
                        Powered by Oro Store POS System
                    </div>
                </div>
            `;

            content.innerHTML = html;
            printBtn.style.display = 'block';
        }

        // Update receipt with transaction ID
        function updateReceiptWithTransactionId(id) {
            transactionId = id;
            const placeholder = document.getElementById('transaction-id-placeholder');
            const barcodePlaceholder = document.getElementById('barcode-placeholder');
            
            if (placeholder) {
                placeholder.innerHTML = `
                    <div class="transaction-id">
                        #${id}
                    </div>
                `;
            }
            
            if (barcodePlaceholder) {
                barcodePlaceholder.textContent = id.toString().padStart(12, '0');
            }
        }

        // Complete transaction and print
        function completeAndPrint() {
            if (!receiptData || transactionCompleted) {
                return;
            }

            transactionCompleted = true;

            if (receiptData.isEditMode) {
                // Handle re-edited transaction
                const formData = new FormData();
                formData.append('action', 'reedit_gcash');
                formData.append('original_id', receiptData.originalTransactionId);
                formData.append('type', receiptData.type);
                formData.append('amount', receiptData.amount);
                formData.append('fee', receiptData.fee);
                formData.append('total', receiptData.total);
                formData.append('reference', receiptData.reference);

                fetch('/oro-store-demo/transactions/gcash.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateReceiptWithTransactionId(data.transaction_id);
                        
                        setTimeout(() => {
                            window.print();
                            
                            if (window.opener) {
                                window.opener.postMessage({
                                    action: 'gcash_completed',
                                    transaction_id: data.transaction_id,
                                    message: 'GCash transaction re-edited successfully!\nNew Transaction ID: #' + data.transaction_id
                                }, '*');
                            }
                            
                            setTimeout(() => {
                                window.close();
                            }, 500);
                        }, 300);
                    } else {
                        alert('Error completing transaction: ' + data.error);
                        transactionCompleted = false;
                    }
                })
                .catch(error => {
                    alert('Error: ' + error);
                    transactionCompleted = false;
                });
            } else {
                // Handle normal transaction
                const formData = new FormData();
                formData.append('action', 'complete_gcash');
                formData.append('type', receiptData.type);
                formData.append('amount', receiptData.amount);
                formData.append('fee', receiptData.fee);
                formData.append('total', receiptData.total);
                formData.append('reference', receiptData.reference);

                fetch('/oro-store-demo/transactions/gcash.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateReceiptWithTransactionId(data.transaction_id);
                        
                        setTimeout(() => {
                            window.print();
                            
                            if (window.opener) {
                                window.opener.postMessage({
                                    action: 'gcash_completed',
                                    transaction_id: data.transaction_id,
                                    message: 'GCash transaction completed successfully!\nTransaction ID: #' + data.transaction_id
                                }, '*');
                            }
                            
                            setTimeout(() => {
                                window.close();
                            }, 500);
                        }, 300);
                    } else {
                        alert('Error completing transaction: ' + data.error);
                        transactionCompleted = false;
                    }
                })
                .catch(error => {
                    alert('Error: ' + error);
                    transactionCompleted = false;
                });
            }
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                completeAndPrint();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                if (confirm('Cancel transaction?')) {
                    window.close();
                }
            }
        });

        // Print button click
        document.getElementById('print-btn').addEventListener('click', function() {
            completeAndPrint();
        });

        // Prevent accidental close
        window.addEventListener('beforeunload', function(e) {
            if (receiptData && !transactionCompleted) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
    </script>
</body>
</html>