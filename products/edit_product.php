<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

// Only admins can access
if (!isAdmin()) {
    header("Location: /oro-store-demo/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

// Handle AJAX request for random barcode generation
if (isset($_GET['action']) && $_GET['action'] === 'generate_barcode') {
    header('Content-Type: application/json');
    
    // Generate unique barcode
    $maxAttempts = 100;
    $attempt = 0;
    
    do {
        $barcode = '';
        for ($i = 0; $i < 12; $i++) {
            $barcode .= rand(0, 9);
        }
        // Calculate check digit for EAN-13
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += ($i % 2 == 0) ? (int)$barcode[$i] : (int)$barcode[$i] * 3;
        }
        $checkDigit = (10 - ($sum % 10)) % 10;
        $barcode .= $checkDigit;
        
        // Check if barcode exists
        $stmt = $conn->prepare("SELECT id FROM products WHERE barcode = ?");
        $stmt->bind_param("s", $barcode);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();
        
        $attempt++;
    } while ($exists && $attempt < $maxAttempts);
    
    if ($attempt >= $maxAttempts) {
        echo json_encode(['error' => 'Could not generate unique barcode']);
    } else {
        echo json_encode(['barcode' => $barcode]);
    }
    $conn->close();
    exit;
}

$productId = $_GET['id'] ?? null;
if (!$productId) {
    die("Product ID required.");
}

// Fetch product
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->bind_param("i", $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    die("Product not found.");
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $price = $_POST['price'];
    $purchase_price = $_POST['purchase_price'];
    $stock = $_POST['stock'];
    $description = $_POST['description'];
    $barcode = $_POST['barcode'];

    // Log changes
    $changes = [];
    if ($name !== $product['name']) $changes[] = ['type' => 'name', 'old' => $product['name'], 'new' => $name];
    if ($price != $product['price']) $changes[] = ['type' => 'price', 'old' => $product['price'], 'new' => $price];
    if ($purchase_price != $product['purchase_price']) $changes[] = ['type' => 'purchase_price', 'old' => $product['purchase_price'], 'new' => $purchase_price];
    if ($stock != $product['stock']) $changes[] = ['type' => 'stock', 'old' => $product['stock'], 'new' => $stock];
    if ($description !== $product['description']) $changes[] = ['type' => 'description', 'old' => $product['description'], 'new' => $description];
    if ($barcode !== $product['barcode']) $changes[] = ['type' => 'barcode', 'old' => $product['barcode'], 'new' => $barcode];

    foreach ($changes as $change) {
        $stmt = $conn->prepare("INSERT INTO product_history (product_id, change_type, old_value, new_value) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $productId, $change['type'], $change['old'], $change['new']);
        $stmt->execute();
        $stmt->close();
    }

    // Update product
    $stmt = $conn->prepare("UPDATE products SET name = ?, price = ?, purchase_price = ?, stock = ?, description = ?, barcode = ? WHERE id = ?");
    $stmt->bind_param("sddissi", $name, $price, $purchase_price, $stock, $description, $barcode, $productId);
    $stmt->execute();
    $stmt->close();

    echo "<p>Product updated successfully!</p>";
    // Refresh product data
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Edit Product</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <link rel="stylesheet" href="/oro-store-demo/style.css">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>
    <main class="main-content">
    <h1>Edit Product: <?php echo htmlspecialchars($product['name']); ?></h1>
    <form method="POST">
        <label>Name: <input type="text" name="name" value="<?php echo htmlspecialchars($product['name']); ?>" required></label><br>
        <label>Selling Price: <input type="number" step="0.01" name="price" value="<?php echo $product['price']; ?>" required></label><br>
        <label>Purchase Price (Cost): <input type="number" step="0.01" name="purchase_price" value="<?php echo $product['purchase_price'] ?? 0; ?>" required></label><br>
        <label>Stock: <input type="number" name="stock" value="<?php echo $product['stock']; ?>" required></label><br>
        <label>Description: <textarea name="description"><?php echo htmlspecialchars($product['description']); ?></textarea></label><br>
        <label>Barcode: <input type="text" name="barcode" id="barcode-input" value="<?php echo htmlspecialchars($product['barcode'] ?? ''); ?>" placeholder="Scan or generate barcode"></label>
        <button type="button" id="random-btn">Generate Random Barcode</button>
        <button type="button" id="generate-btn">Generate Barcode Image</button>
        <br>
        
        <div id="barcode-display" style="text-align: center; margin: 20px 0; display: none;">
            <h3>Generated Barcode:</h3>
            <svg id="barcode-svg"></svg>
            <br>
            <button type="button" id="print-btn">Print Barcode</button>
        </div>
        
        <button type="submit">Save Changes</button>
    </form>

    <h2>View Histories</h2>
    <button onclick="openHistory('price')">Selling Price History</button>
    <button onclick="openHistory('purchase_price')">Purchase Price History</button>
    <button onclick="openHistory('stock')">Stock Change History</button>
    <button onclick="openHistory('name')">Name Change History</button>
    <button onclick="openHistory('description')">Description Change History</button>
    <button onclick="openHistory('barcode')">Barcode Change History</button>
    <button onclick="openHistory('all')">All Changes</button>

    <script>
        // Auto-generate barcode image if exists
        window.addEventListener('load', function() {
            const barcodeValue = document.getElementById('barcode-input').value;
            if (barcodeValue) {
                try {
                    JsBarcode("#barcode-svg", barcodeValue, {
                        format: "CODE128",
                        width: 2,
                        height: 100,
                        displayValue: true
                    });
                    document.getElementById('barcode-display').style.display = 'block';
                } catch (e) {
                    console.log('Could not generate barcode on load');
                }
            }
        });

        // Generate random unique barcode
        document.getElementById('random-btn').addEventListener('click', function() {
            fetch('/oro-store-demo/products/edit_product.php?action=generate_barcode&id=<?php echo $productId; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Error: ' + data.error);
                    } else {
                        document.getElementById('barcode-input').value = data.barcode;
                        alert('Random barcode generated: ' + data.barcode);
                    }
                })
                .catch(error => {
                    alert('Error generating barcode: ' + error);
                });
        });

        // Generate barcode image
        document.getElementById('generate-btn').addEventListener('click', function() {
            const barcodeValue = document.getElementById('barcode-input').value;
            if (!barcodeValue) {
                alert('Please enter or generate a barcode first');
                return;
            }
            
            try {
                JsBarcode("#barcode-svg", barcodeValue, {
                    format: "CODE128",
                    width: 3,              // Increased from 2 to 3
                    height: 80,            // Increased from 100 to 80 for better proportion
                    displayValue: true,
                    fontSize: 16,          // Larger font
                    margin: 10,            // Add margin around barcode
                    background: "#ffffff", // White background
                    lineColor: "#000000"   // Black bars
                });
                document.getElementById('barcode-display').style.display = 'block';
            } catch (e) {
                alert('Invalid barcode format: ' + e.message);
            }
        });
        // Print barcode - Updated version
        document.getElementById('print-btn').addEventListener('click', function() {
            const barcodeValue = document.getElementById('barcode-input').value;
            const productName = document.querySelector('input[name="name"]').value;
            const productPrice = document.querySelector('input[name="price"]').value;
            const productStock = document.querySelector('input[name="stock"]').value;

            if (!barcodeValue) {
                alert('Please generate a barcode first');
                return;
            }

            // Open dedicated barcode label page
            const params = new URLSearchParams({
                barcode: barcodeValue,
                name: productName || 'Product',
                price: productPrice || '0',
                stock: productStock || '0'
            });

            window.open('/oro-store-demo/print/print_barcode_label.php?' + params.toString(), '_blank', 'width=500,height=600');
        });

        // Open history in new tab
        function openHistory(type) {
            const url = `product_history.php?id=<?php echo $productId; ?>&type=${type}`;
            const newTab = window.open(url, '_blank');
            if (newTab) {
                newTab.focus();
            }
        }
    </script>
    </main>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Edit Product', array (
  'Features' => 
  array (
    0 => 'Update product details: name, price, cost, description',
    1 => 'Change category and brand assignments',
    2 => 'Modify barcode and individual selling settings',
  ),
));
?>
</body>
</html>