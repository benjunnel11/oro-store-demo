<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Angkat Receipt - Oro Store</title>
    <link rel="stylesheet" href="/oro-store-demo/print/print_receipt.css">
</head>
<body>
    <div class="no-print instructions">
        <strong>📋 Instructions:</strong>
        Press <kbd>Enter</kbd> to complete angkat transaction and print, or <kbd>Esc</kbd> to cancel
    </div>

    <div class="receipt-container">
        <div id="receipt-content" class="">
            Waiting for angkat transaction data...
        </div>
    </div>

    <button class="print-button no-print" id="print-btn" style="display: none;">
        Press Enter to Complete & Print
    </button>

    <script>
        let receiptData = null;
        let transactionCompleted = false;

        // Listen for data from parent window
        window.addEventListener('message', function(event) {
            console.log('Received angkat data:', event.data);
            receiptData = event.data;
            displayAngkatReceipt(receiptData);
        });

        // Display angkat receipt
        function displayAngkatReceipt(data) {
            const content = document.getElementById('receipt-content');
            const printBtn = document.getElementById('print-btn');

            const expectedProfit = data.total - data.totalCost;

            let html = `
                <div class="receipt-header">
                    <div class="store-name">${data.storeName || 'ORO STORE'}</div>
                    ${data.storeAddress ? `<div class="store-address">${data.storeAddress}</div>` : ''}
                </div>

                <div class="transaction-combined">
                    <span class="transaction-id-inline">#PENDING</span>
                    <span class="transaction-type-inline type-delivery">ANGKAT RECEIPT</span>
                </div>

                <div style="text-align: center; padding: 10px; background-color: #f8f9fa; border-radius: 4px; margin: 10px 0;">
                    <div style="font-weight: bold; color: #667eea; font-size: 14px;">📦 CONSIGNMENT TRANSACTION</div>
                    <div style="font-size: 11px; color: #666; margin-top: 5px;">Products given on credit - Pay for sold items only</div>
                </div>

                <div class="transaction-info">
                    <div>
                        <span class="info-label">Date:</span>
                        <span class="info-value">${data.date}</span>
                    </div>
                    <div>
                        <span class="info-label">Processed by:</span>
                        <span class="info-value">${data.cashierName || 'Cashier'}</span>
                    </div>
                    <div>
                        <span class="info-label">Total Items:</span>
                        <span class="info-value">${data.itemCount}</span>
                    </div>
                    <div style="border-top: 1px dashed #ddd; margin-top: 5px; padding-top: 5px;">
                        <span class="info-label">Retailer:</span>
                        <span class="info-value" style="font-weight: bold;">${data.angkatInfo.retailerName}</span>
                    </div>
                    ${data.angkatInfo.retailerContact ? `
                    <div>
                        <span class="info-label">Contact:</span>
                        <span class="info-value">${data.angkatInfo.retailerContact}</span>
                    </div>
                    ` : ''}
                </div>

                <div class="items-section">
                    <div class="items-header">ITEMS GIVEN ON CONSIGNMENT</div>
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
                    <div class="total-row">
                        <span>Total Value (if all sold):</span>
                        <span>₱${data.total.toFixed(2)}</span>
                    </div>
                    <div class="total-row" style="color: #666;">
                        <span>Total Cost:</span>
                        <span>₱${data.totalCost.toFixed(2)}</span>
                    </div>
                    <div class="total-row grand-total" style="border-top: 2px solid #667eea; color: #667eea;">
                        <span>Expected Profit:</span>
                        <span>₱${expectedProfit.toFixed(2)}</span>
                    </div>
                </div>

                <div style="background-color: #fff3cd; padding: 12px; margin: 15px 0; border-radius: 4px; border-left: 4px solid #ffc107;">
                    <div style="font-weight: bold; font-size: 12px; color: #856404; margin-bottom: 5px;">
                        ⚠️ IMPORTANT TERMS:
                    </div>
                    <div style="font-size: 11px; color: #856404; line-height: 1.6;">
                        • NO CASH EXCHANGE NOW<br>
                        • Payment due ONLY for items sold<br>
                        • Return unsold items in good condition<br>
                        • Settlement required within agreed period
                    </div>
                </div>

                <div class="receipt-footer">
                    <div class="thank-you">★ THANK YOU! ★</div>
                    <div class="footer-message" style="margin-top: 8px; font-weight: bold;">
                        Good luck with sales!
                    </div>
                    <div class="footer-message" style="font-size: 10px; color: #666; margin-top: 8px;">
                        Keep this receipt for your records
                    </div>
                </div>

                <div style="text-align: center; margin-top: 15px; padding-top: 10px; border-top: 1px dashed #ddd;">
                    <div style="font-size: 10px; color: #999;">
                        Retailer Signature: _____________________
                    </div>
                </div>
            `;

            content.innerHTML = html;
            printBtn.style.display = 'block';
        }

        // Complete angkat transaction
        function completeAndPrint() {
            if (!receiptData || transactionCompleted) {
                return;
            }

            console.log('Completing angkat transaction with data:', receiptData);
            
            // Simply print - the actual transaction completion happens in angkat.php
            transactionCompleted = true;
            
            setTimeout(() => {
                window.print();
                
                // Notify parent window
                if (window.opener) {
                    window.opener.postMessage({
                        action: 'angkat_print_completed'
                    }, '*');
                }
                
                setTimeout(() => {
                    window.close();
                }, 500);
            }, 300);
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                completeAndPrint();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                if (confirm('Cancel printing? (Transaction will still be completed)')) {
                    window.close();
                }
            }
        });

        // Print button click
        document.getElementById('print-btn').addEventListener('click', function() {
            completeAndPrint();
        });
    </script>
</body>
</html>