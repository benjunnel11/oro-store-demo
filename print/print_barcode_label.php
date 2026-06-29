<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Barcode Label</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .label-container {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 400px;
        }

        .product-name {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #333;
        }

        .barcode-wrapper {
            background: white;
            padding: 15px;
            margin: 15px 0;
            border: 2px solid #ddd;
            border-radius: 5px;
        }

        #barcode {
            max-width: 100%;
            height: auto;
        }

        .product-info {
            margin-top: 10px;
            font-size: 14px;
            color: #666;
        }

        .price {
            font-size: 20px;
            font-weight: bold;
            color: #28a745;
            margin: 10px 0;
        }

        .controls {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        button {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }

        .btn-print {
            background-color: #28a745;
            color: white;
        }

        .btn-print:hover {
            background-color: #218838;
        }

        .btn-close {
            background-color: #dc3545;
            color: white;
        }

        .btn-close:hover {
            background-color: #c82333;
        }

        /* Print styles */
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }

            .label-container {
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
                padding: 10px;
            }

            .controls {
                display: none;
            }

            .barcode-wrapper {
                border: 1px solid #000;
                page-break-inside: avoid;
            }

            /* Optimize for 58mm printer */
            @page {
                size: 56mm auto;
            }
        }

        .instructions {
            background-color: #fff3cd;
            border: 1px solid #ffc107;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            font-size: 12px;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="label-container">

        <div class="product-name" id="product-name">Product Name</div>
        
        <div class="barcode-wrapper">
            <svg id="barcode"></svg>
        </div>

        <div class="product-info">
            <div class="price" id="product-price">₱0.00</div>
        </div>

        <div class="controls no-print">
            <button class="btn-print" onclick="window.print()">Print Label</button>
            <button class="btn-close" onclick="window.close()">Close</button>
        </div>
    </div>

    <script>
        // Get URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const barcode = urlParams.get('barcode');
        const name = urlParams.get('name');
        const price = urlParams.get('price');
        const stock = urlParams.get('stock');

        // Display product info
        if (name) document.getElementById('product-name').textContent = name;
        if (price) document.getElementById('product-price').textContent = '₱' + parseFloat(price).toFixed(2);

        // Generate barcode
        if (barcode) {
            try {
                JsBarcode("#barcode", barcode, {
                    format: "CODE128",
                    width: 4,              // Wider bars for better scanning
                    height: 100,           // Taller for better scanning
                    displayValue: true,
                    fontSize: 18,          // Larger font
                    margin: 15,            // More margin
                    background: "#ffffff",
                    lineColor: "#000000",
                    textMargin: 5,
                    marginTop: 10,
                    marginBottom: 10
                });
            } catch (e) {
                alert('Error generating barcode: ' + e.message);
                window.close();
            }
        } else {
            alert('No barcode provided');
            window.close();
        }

        // Auto-print option (uncomment if you want auto-print)
        // window.onload = function() {
        //     setTimeout(() => window.print(), 500);
        // };

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                window.print();
            } else if (e.key === 'Escape') {
                window.close();
            }
        });
    </script>
</body>
</html>