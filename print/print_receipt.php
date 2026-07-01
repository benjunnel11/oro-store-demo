<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Receipt - Oro Store</title>
    <link rel="stylesheet" href="/oro-store-demo/print/print_receipt.css">
</head>
<body>
    <div class="no-print instructions" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div><strong>📋 Instructions:</strong> Press <kbd>Enter</kbd> to complete and print, or <kbd>Esc</kbd> to cancel</div>
        <button onclick="window.location.href='/oro-store-demo/cashier/cashier.php'" style="padding:12px 24px;background:#3b82f6;color:#fff;border:none;border-radius:8px;font-size:16px;font-weight:700;cursor:pointer;">← Cashier</button>
    </div>

    <div class="receipt-container">
        <div id="receipt-content" class="">
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

        // Update displayReceipt function to show transaction ID
// Update displayReceipt function
function displayReceipt(data) {
    const content = document.getElementById('receipt-content');
    const printBtn = document.getElementById('print-btn');

    const isDelivery = data.paymentMethod === 'delivery';
    const isCredit = data.paymentMethod === 'credit';
    const isReprint = data.isReprint || false;
    const isReEdit = data.isReEdit || false;
    
    // ✅ Determine which transaction ID to show
    const displayTransactionId = data.parentTransactionId || data.transactionId || '#PENDING';
    
    let typeText = 'SALES RECEIPT';
    let typeClass = 'type-sale';
    
    if (isReprint) {
        typeText = 'REPRINT - ' + typeText;
        typeClass += ' type-reprint';
    } else if (isReEdit) {
        typeText = 'EDITED - ' + typeText;
        typeClass += ' type-reedit';
    } else if (isDelivery) {
        typeText = 'DELIVERY ORDER';
        typeClass = 'type-delivery';
    } else if (isCredit) {
        typeText = 'CREDIT RECEIPT';
        typeClass = 'type-credit';
    }

    let html = `
        <div class="receipt-header">
            <div class="store-name">${data.storeName || 'ORO STORE'}</div>
            ${data.storeAddress ? `<div class="store-address">${data.storeAddress}</div>` : ''}
        </div>

        ${isReEdit ? `
            <div class="reedit-indicator" style="text-align: center; padding: 10px; background-color: #fff3cd; border: 1px solid #ffc107; margin-bottom: 10px; border-radius: 4px;">
                ⚠️ EDITED TRANSACTION<br>
                <small style="font-size: 10px;">Original Transaction ID preserved</small>
            </div>
        ` : ''}

        <div id="transaction-id-placeholder" class="transaction-combined">
            <span class="transaction-id-inline">#${displayTransactionId}</span>
            <span class="transaction-type-inline ${typeClass}">${typeText}</span>
        </div>
        
        ${data.transactionNumber ? `
            <div style="text-align: center; font-size: 11px; color: #666; margin-top: -5px; margin-bottom: 10px;">
                Transaction #: ${data.transactionNumber}
            </div>
        ` : ''}

        <div class="transaction-info">
            <div>
                <span class="info-label">Date:</span>
                <span class="info-value">${data.date}</span>
            </div>
            <div>
                <span class="info-label">Cashier:</span>
                <span class="info-value">${data.cashierName || 'Cashier'}</span>
            </div>
            <div>
                <span class="info-label">Items:</span>
                <span class="info-value">${data.itemCount}</span>
            </div>
            <div>
                <span class="info-label">Payment:</span>
                <span class="info-value">${data.paymentMethod ? data.paymentMethod.toUpperCase() : 'CASH'}</span>
            </div>
            ${isDelivery && data.deliveryInfo ? `
                <div>
                    <span class="info-label">Recipient:</span>
                    <span class="info-value">${data.deliveryInfo.recipientName}</span>
                </div>
                <div>
                    <span class="info-label">Address:</span>
                    <span class="info-value">${data.deliveryInfo.recipientAddress}</span>
                </div>
            ` : ''}
            ${isCredit && data.creditInfo ? `
                <div>
                    <span class="info-label">Customer:</span>
                    <span class="info-value">${data.creditInfo.customerName}</span>
                </div>
                <div>
                    <span class="info-label">Contact:</span>
                    <span class="info-value">${data.creditInfo.customerContact}</span>
                </div>
                ${data.creditInfo.customerAddress ? `
                <div>
                    <span class="info-label">Address:</span>
                    <span class="info-value">${data.creditInfo.customerAddress}</span>
                </div>
                ` : ''}
            ` : ''}
        </div>

        <div class="items-section">
            <div class="items-header">ITEMS</div>
    `;

    // Add items
    data.items.forEach(item => {
        html += `
            <div class="item-row">
                <div class="item-name">${item.name}</div>
                <div class="item-details">
                    <span class="item-qty-price">${item.quantity} × ₱${item.price.toFixed(2)}</span>
                    <span style="font-weight: bold;">₱${item.subtotal.toFixed(2)}</span>
                </div>
            </div>
        `;
    });

    html += `
        </div>

        <div class="totals-section">
            ${data.discount ? `
                <div class="total-row">
                    <span>Discount:</span>
                    <span style="color: #dc3545;">-₱${data.discount.toFixed(2)}</span>
                </div>
            ` : ''}
            <div class="total-row grand-total">
                <span>TOTAL:</span>
                <span>₱${data.total.toFixed(2)}</span>
            </div>
            ${data.amountPaid && !isDelivery && !isCredit ? `
                <div class="total-row" style="margin-top: 8px;">
                    <span>Amount Paid:</span>
                    <span>₱${data.amountPaid.toFixed(2)}</span>
                </div>
                <div class="total-row">
                    <span>Change:</span>
                    <span>₱${(data.amountPaid - data.total).toFixed(2)}</span>
                </div>
            ` : ''}
            ${isCredit ? `
                <div class="total-row" style="margin-top: 8px; color: #dc3545; font-weight: bold;">
                    <span>AMOUNT DUE:</span>
                    <span>₱${data.total.toFixed(2)}</span>
                </div>
            ` : ''}
        </div>

        <div class="receipt-footer">
            <div class="thank-you">★ THANK YOU! ★</div>
            <div class="tax-disclaimer">
                THIS DOCUMENT IS NOT VALID<br>
                FOR CLAIM OF INPUT TAX
            </div>
            <div class="footer-message">This serves as your proof of purchase</div>
            ${isDelivery ? `
                <div class="footer-message" style="margin-top: 8px; font-weight: bold; color: #856404;">
                    We'll deliver your order soon!
                </div>
            ` : ''}
            ${isCredit ? `
                <div class="footer-message" style="margin-top: 8px; font-weight: bold; color: #dc3545;">
                    Please settle payment on or before due date
                </div>
            ` : ''}
            ${isReprint ? `
                <div class="footer-message" style="margin-top: 8px; font-weight: bold; color: #666;">
                    ** REPRINT - REFERENCE ONLY **
                </div>
            ` : ''}
            ${isReEdit ? `
                <div class="footer-message" style="margin-top: 8px; font-weight: bold; color: #856404;">
                    ** EDITED TRANSACTION **
                </div>
            ` : ''}
        </div>
    `;

    content.innerHTML = html;
    printBtn.style.display = 'block';
    
    if (isReprint) {
        printBtn.textContent = 'Press Enter to Print (Reprint)';
    } else if (isReEdit) {
        printBtn.textContent = 'Press Enter to Print (Edited)';
    }
}
        // Update receipt with transaction ID
        function updateReceiptWithTransactionId(id) {
            transactionId = id;
            const placeholder = document.getElementById('transaction-id-placeholder');
            
            if (placeholder) {
                const typeElement = placeholder.querySelector('.transaction-type-inline');
                const typeText = typeElement ? typeElement.textContent : '';
                const typeClass = typeElement ? typeElement.className : 'transaction-type-inline';
                
                placeholder.innerHTML = `
                    <span class="transaction-id-inline">#${id}</span>
                    <span class="${typeClass}">${typeText}</span>
                `;
            }
        }

        // Complete transaction and print
     // Update the completeAndPrint function in print_receipt.php
function completeAndPrint() {
    if (!receiptData || transactionCompleted) {
        return;
    }

    // ✅ HANDLE REPRINT MODE - Log to database with cart items
    if (receiptData.isReprint) {
        transactionCompleted = true;
        
        // Log the reprint to database with cart items
        const formData = new FormData();
        formData.append('action', 'log_reprint');
        formData.append('transaction_id', receiptData.transactionId);
        formData.append('transaction_number', receiptData.transactionNumber);
        formData.append('items', JSON.stringify(receiptData.items)); // ✅ Include cart items
        formData.append('total_amount', receiptData.total);
        formData.append('amount_paid', receiptData.amountPaid || receiptData.total);
        formData.append('change_amount', receiptData.change || 0);
        formData.append('reprint_reason', 'Manual reprint from cashier');
        
        fetch('/oro-store-demo/cashier/cashier.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Reprint logged successfully:', data.reprint_id);
            } else {
                console.error('Error logging reprint:', data.error);
            }
            
            // Print regardless of logging success
            setTimeout(() => {
                window.print();
                
                if (window.opener) {
                    window.opener.postMessage({
                        action: 'reprint_completed',
                        reprint_id: data.reprint_id,
                        message: 'Receipt reprinted successfully!'
                    }, '*');
                }
                
                setTimeout(() => {
                    window.close();
                }, 500);
            }, 300);
        })
        .catch(error => {
            console.error('Error logging reprint:', error);
            // Still print even if logging fails
            setTimeout(() => {
                window.print();
                
                if (window.opener) {
                    window.opener.postMessage({
                        action: 'reprint_completed',
                        message: 'Receipt reprinted (logging may have failed)'
                    }, '*');
                }
                
                setTimeout(() => {
                    window.close();
                }, 500);
            }, 300);
        });
        
        return;
    }

            transactionCompleted = true;

            const isDelivery = receiptData.paymentMethod === 'delivery';
            const isCredit = receiptData.paymentMethod === 'credit';

            // Check if this is a re-edited transaction
            if (receiptData.isReEdit) {
                // Handle re-edited transaction
                const formData = new FormData();
                formData.append('action', 'reedit_transaction');
                formData.append('original_transaction_id', receiptData.originalTransactionId);
                formData.append('original_items', JSON.stringify(receiptData.originalTransactionItems));
                formData.append('new_items', JSON.stringify(receiptData.items));
                formData.append('total_amount', receiptData.total);
                
                const profit = receiptData.items.reduce((sum, item) => sum + item.profit, 0);
                formData.append('total_profit', profit);
                formData.append('items_count', receiptData.itemCount);
                formData.append('payment_method', receiptData.paymentMethod || 'cash');
                
                if (receiptData.amountPaid) {
                    formData.append('amount_paid', receiptData.amountPaid);
                    formData.append('change_amount', receiptData.amountPaid - receiptData.total);
                }

                fetch('/oro-store-demo/cashier/cashier.php', {
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
                                    action: 'transaction_completed',
                                    transaction_id: data.transaction_id,
                                    transaction_number: data.transaction_number,
                                    message: 'Transaction re-edited successfully!\nNew Transaction #: ' + data.transaction_number
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
            } else if (isCredit) {
                // Handle credit transaction
                const formData = new FormData();
                formData.append('action', 'complete_credit');
                formData.append('items', JSON.stringify(receiptData.items));
                formData.append('total_amount', receiptData.total);
                
                const profit = receiptData.items.reduce((sum, item) => sum + item.profit, 0);
                formData.append('total_profit', profit);
                formData.append('items_count', receiptData.itemCount);
                formData.append('customer_name', receiptData.creditInfo.customerName);
                formData.append('customer_contact', receiptData.creditInfo.customerContact);
                formData.append('customer_address', receiptData.creditInfo.customerAddress || '');

                fetch('/oro-store-demo/credit/credit.php', {
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
                                    action: 'transaction_completed',
                                    transaction_id: data.transaction_id,
                                    credit_id: data.credit_id,
                                    transaction_number: data.transaction_number,
                                    message: 'Credit transaction created successfully!\nTransaction #: ' + data.transaction_number
                                }, '*');
                            }
                            
                            setTimeout(() => {
                                window.close();
                            }, 500);
                        }, 300);
                    } else {
                        alert('Error completing credit: ' + data.error);
                        transactionCompleted = false;
                    }
                })
                .catch(error => {
                    alert('Error: ' + error);
                    transactionCompleted = false;
                });
            } else if (isDelivery) {
                // Handle delivery transaction
                const formData = new FormData();
                formData.append('action', 'complete_delivery');
                formData.append('items', JSON.stringify(receiptData.items));
                formData.append('total_amount', receiptData.total);
                
                const profit = receiptData.items.reduce((sum, item) => sum + item.profit, 0);
                formData.append('total_profit', profit);
                formData.append('items_count', receiptData.itemCount);
                formData.append('recipient_name', receiptData.deliveryInfo.recipientName);
                formData.append('recipient_address', receiptData.deliveryInfo.recipientAddress);

                fetch('/oro-store-demo/delivery/delivery.php', {
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
                                    action: 'transaction_completed',
                                    transaction_id: data.transaction_id,
                                    delivery_id: data.delivery_id,
                                    transaction_number: data.transaction_number,
                                    message: 'Delivery created successfully!\nTransaction #: ' + data.transaction_number
                                }, '*');
                            }
                            
                            setTimeout(() => {
                                window.close();
                            }, 500);
                        }, 300);
                    } else {
                        alert('Error completing delivery: ' + data.error);
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
                formData.append('action', 'complete_transaction');
                formData.append('items', JSON.stringify(receiptData.items));
                formData.append('total_amount', receiptData.total);
                
                const profit = receiptData.items.reduce((sum, item) => sum + item.profit, 0);
                formData.append('total_profit', profit);
                formData.append('items_count', receiptData.itemCount);
                formData.append('payment_method', receiptData.paymentMethod || 'cash');
                formData.append('amount_paid', receiptData.amountPaid || receiptData.total);
                formData.append('change_amount', receiptData.amountPaid ? (receiptData.amountPaid - receiptData.total) : 0);
                formData.append('subtotal', receiptData.subtotal || receiptData.total);

                fetch('/oro-store-demo/cashier/cashier.php', {
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
                                    action: 'transaction_completed',
                                    transaction_id: data.transaction_id,
                                    transaction_number: data.transaction_number,
                                    message: 'Transaction completed successfully!\nTransaction #: ' + data.transaction_number
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
            if (receiptData && !transactionCompleted && !receiptData.isReprint) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
    </script>
</body>
</html>