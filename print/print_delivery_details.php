<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Delivery Details</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            font-size: 11px;
            padding: 10px;
            line-height: 1.2;
        }
        
        .page-break {
            page-break-after: always;
        }
        
        .receipt-section {
            margin-bottom: 15px;
            border-bottom: 1px dashed #333;
            padding-bottom: 10px;
        }
        
        .receipt-section:last-of-type {
            border-bottom: none;
        }
        
        .receipt-header {
            margin-bottom: 8px;
            padding-bottom: 5px;
            border-bottom: 1px solid #333;
        }
        
        .transaction-number {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 3px;
        }
        
        .recipient-info {
            margin-bottom: 5px;
        }
        
        .info-line {
            font-weight: bold;
            margin: 2px 0;
            font-size: 11px;
        }
        
        .info-label {
            font-weight: bold;
            display: inline-block;
            min-width: 80px;
        }
        
        .products-list {
            list-style: none;
            margin: 8px 0;
        }
        
        .product-item {
            margin-bottom: 5px;
            padding: 5px;
            background: #f9f9f9;
            border-left: 2px solid #333;
        }
        
        .product-name {
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 0;
        }
        
        .product-details {
            font-size: 12px;
            color: #333;
            margin-left: 10px;
        }
        
        .product-detail-line {
            margin: 2px 0;
        }
        
        .quantity-label {
            display: inline-block;
            min-width: 80px;
        }
        
        .lacking-highlight {
            color: #d00;
            font-weight: bold;
        }
        
        .summary-section {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 2px solid #333;
        }
        
        .summary-title {
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 8px;
            text-align: center;
            text-decoration: underline;
        }
        
        .summary-list {
            list-style: none;
        }
        
        .summary-item {
            margin-bottom: 5px;
            padding: 5px;
            background: #f0f0f0;
            border-left: 3px solid #333;
        }
        
        .summary-product-name {
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 0;
        }
        
        .summary-details {
            margin-left: 10px;
            font-size: 12px;
        }
        
        .no-print {
            margin-bottom: 20px;
        }
        
        @media print {
            .no-print {
                display: none;
            }
            
            body {
                padding: 10px;
            }
            
            .product-item {
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; font-size: 14px;">Print</button>
        <button onclick="window.close()" style="padding: 10px 20px; cursor: pointer; font-size: 14px; margin-left: 10px;">Close</button>
    </div>

    <div id="content">
        <div style="text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 8px;">
            DELIVERY DETAILS REPORT
        </div>
        <div style="text-align: center; margin-bottom: 10px; font-size: 10px;">
            Generated: <span id="print-date"></span>
        </div>

        <div id="receipts-container"></div>

        <div class="summary-section">
            <div class="summary-title">TOTAL PRODUCTS SUMMARY</div>
            <div id="summary-container"></div>
        </div>
    </div>

    <script>
        let deliveriesData = [];

        // Set print date
        document.getElementById('print-date').textContent = new Date().toLocaleString();

        // Listen for data from parent window
        window.addEventListener('message', function(event) {
            if (event.data && event.data.deliveries) {
                deliveriesData = event.data.deliveries;
                loadDeliveriesData();
            }
        });

        async function loadDeliveriesData() {
            const receiptsContainer = document.getElementById('receipts-container');
            const summaryContainer = document.getElementById('summary-container');
            const productsData = {};
            
            let receiptsHTML = '';

            for (const delivery of deliveriesData) {
                try {
                    const response = await fetch(`/oro-store/delivery/delivery_details.php?action=get_delivery&delivery_id=${delivery.id}`);
                    const items = await response.json();

                    if (items.length > 0) {
                        const transactionId = items[0].transaction_id || delivery.id;
                        const transactionNumber = items[0].transaction_number;
                        const recipient = items[0].recipient_name;
                        const address = items[0].recipient_address;
                        const status = items[0].delivery_status;

                        receiptsHTML += `
                            <div class="receipt-section">
                                <div class="receipt-header">
                                    <div class="transaction-number">ID: ${transactionId}</div>
                                    <div class="recipient-info">
                                        <div class="info-line"><span class="info-label">Recipient:</span> ${recipient}</div>
                                        <div class="info-line"><span class="info-label">Address:</span> ${address}</div>
                                        <div class="info-line"><span class="info-label">Status:</span> ${status.toUpperCase()}</div>
                                        <div class="info-line"><span class="info-label">Amount:</span> ₱${delivery.amount.toFixed(2)}</div>
                                    </div>
                                </div>
                                <div style="font-weight: bold; margin-bottom: 5px; font-size: 11px;">PRODUCTS:</div>
                                <ul class="products-list">
                        `;

                        items.forEach(item => {
                            const lackingBadge = item.quantity_lacking > 0 ? ' <span style="color: #d00; font-weight: bold;">[LACKING: ' + item.quantity_lacking + ']</span>' : '';
                            receiptsHTML += `
                                <li class="product-item">
                                    <div class="product-name">${item.product_name}${lackingBadge}</div>
                                </li>
                            `;

                            // Aggregate for totals
                            if (!productsData[item.product_name]) {
                                productsData[item.product_name] = {
                                    ordered: 0,
                                    delivered: 0,
                                    lacking: 0
                                };
                            }
                            productsData[item.product_name].ordered += item.quantity_ordered;
                            productsData[item.product_name].delivered += item.quantity_delivered;
                            productsData[item.product_name].lacking += item.quantity_lacking;
                        });

                        receiptsHTML += `
                                </ul>
                            </div>
                        `;
                    }
                } catch (error) {
                    console.error('Error loading delivery:', error);
                }
            }

            receiptsContainer.innerHTML = receiptsHTML;

            // Build summary list
            let summaryHTML = '<ul class="summary-list">';

            for (const [productName, quantities] of Object.entries(productsData)) {
                const lackingBadge = quantities.lacking > 0 ? ' - <span style="color: #d00; font-weight: bold;">[LACKING: ' + quantities.lacking + ']</span>' : '';
                summaryHTML += `
                    <li class="summary-item">
                        <div class="summary-product-name">${quantities.delivered} | ${productName}${lackingBadge}</div>
                    </li>
                `;
            }

            summaryHTML += '</ul>';

            summaryContainer.innerHTML = summaryHTML;
        }

        // Auto-trigger print after loading (optional)
        // window.addEventListener('load', function() {
        //     setTimeout(() => window.print(), 500);
        // });
    </script>
</body>
</html>