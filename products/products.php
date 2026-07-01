<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

// Only admins can access
if (!isAdmin()) {
    header("Location: /oro-store-demo/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

// Fetch products (including barcode)
$result = $conn->query("SELECT id, name, price, stock, description, barcode FROM products");
$products = $result->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products</title>
    <link rel="stylesheet" href="/oro-store-demo/style.css">
</head>
<body>
    <h1>Product List</h1>
    <button id="add-product-btn" class="add-btn">Add Product</button>
    <button id="transaction-history-btn" class="add-btn" style="background: linear-gradient(45deg, #17a2b8, #138496); margin-left: 10px;">Transaction History</button>
    <input type="text" id="search-bar" placeholder="Search products by name, price, stock, description, or barcode..." class="search-input">
    <ul id="product-list">
        <?php foreach ($products as $product): ?>
            <li class="product-item" 
                data-id="<?php echo $product['id']; ?>" 
                data-name="<?php echo htmlspecialchars($product['name']); ?>" 
                data-price="<?php echo $product['price']; ?>" 
                data-stock="<?php echo $product['stock']; ?>" 
                data-description="<?php echo htmlspecialchars($product['description'] ?? ''); ?>"
                data-barcode="<?php echo htmlspecialchars($product['barcode'] ?? ''); ?>">
                <span class="product-name"><?php echo htmlspecialchars($product['name']); ?></span>
                <span class="product-details">
                    Price: $<?php echo number_format($product['price'], 2); ?> |
                    Stock: <?php echo $product['stock']; ?> |
                    <?php if (!empty($product['barcode'])): ?>
                        Barcode: <?php echo htmlspecialchars($product['barcode']); ?> |
                    <?php endif; ?>
                    <button class="edit-btn">Edit</button>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>

    <script>
        // Search functionality
        const searchBar = document.getElementById('search-bar');
        const productItems = document.querySelectorAll('.product-item');

        searchBar.addEventListener('input', function() {
            const query = this.value.toLowerCase();
            productItems.forEach(item => {
                const name = item.dataset.name.toLowerCase();
                const price = item.dataset.price.toString();
                const stock = item.dataset.stock.toString();
                const description = item.dataset.description.toLowerCase();
                const barcode = item.dataset.barcode.toLowerCase();
                const matches = name.includes(query) || 
                               price.includes(query) || 
                               stock.includes(query) || 
                               description.includes(query) || 
                               barcode.includes(query);
                item.style.display = matches ? 'block' : 'none';
            });
        });

        // Open add product page in new tab
        document.getElementById('add-product-btn').addEventListener('click', function() {
            const newTab = window.open('/oro-store-demo/products/new_product.php', '_blank');
            if (newTab) {
                newTab.focus(); // Highlight the new tab
            }
        });

        // Open edit page in new tab on Edit button click
        document.querySelectorAll('.edit-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const productId = this.closest('.product-item').dataset.id;
                const newTab = window.open(`/oro-store-demo/products/edit_product.php?id=${productId}`, '_blank');
                if (newTab) {
                    newTab.focus(); // Highlight the new tab
                }
            });
        });

        // Open transaction history in new tab
document.getElementById('transaction-history-btn').addEventListener('click', function() {
    const newTab = window.open('/oro-store-demo/transactions/transaction_history.php', '_blank');
    if (newTab) {
        newTab.focus();
    }
});
    </script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Products List', array (
  'Features' => 
  array (
    0 => 'Simple product listing page',
    1 => 'Shows name, price, stock, category, brand',
  ),
));
?>
</body>
</html>