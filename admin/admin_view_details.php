<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

// Only admins can access
if (!isAdmin()) {
    header("Location: /oro-store/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();
$productId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($productId <= 0) {
    header("Location: /oro-store/admin/admin_stats.php");
    exit;
}

// Get product details
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->bind_param("i", $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    header("Location: /oro-store/admin/admin_stats.php");
    exit;
}

// Get product history (price and stock changes)
$history_query = "
    SELECT 
        ph.id,
        ph.change_type,
        ph.old_value,
        ph.new_value,
        ph.user_name,
        ph.changed_at
    FROM product_history ph
    WHERE ph.product_id = ?
    ORDER BY ph.changed_at DESC
    LIMIT 100
";
$stmt = $conn->prepare($history_query);
$stmt->bind_param("i", $productId);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get purchase/transaction history with cashier details
$purchase_query = "
    SELECT 
        t.id as transaction_id,
        t.transaction_date,
        t.total_amount,
        t.payment_method,
        ti.quantity,
        ti.price as unit_price,
        ti.purchase_price,
        (ti.quantity * ti.price) as subtotal,
        (ti.quantity * (ti.price - ti.purchase_price)) as profit,
        u.full_name as cashier_name,
        u.username as cashier_username,
        s.store_name
    FROM transaction_items ti
    JOIN transactions t ON ti.transaction_id = t.id
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN stores s ON u.store_id = s.id
    WHERE ti.product_id = ?
    ORDER BY t.transaction_date DESC
    LIMIT 200
";
$stmt = $conn->prepare($purchase_query);
$stmt->bind_param("i", $productId);
$stmt->execute();
$purchases = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get restocking history from system_logs
$restock_query = "
    SELECT 
        sl.id,
        sl.activity_type,
        sl.details,
        u.username,
        sl.created_at
    FROM system_logs sl
    LEFT JOIN users u ON sl.user_id = u.id
    WHERE sl.activity_type LIKE '%stock%'
    AND sl.details LIKE CONCAT('%product_id:', ?, '%')
    ORDER BY sl.created_at DESC
    LIMIT 100
";
$stmt = $conn->prepare($restock_query);
$stmt->bind_param("i", $productId);
$stmt->execute();
$restocks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get current stock across all stores
$store_stock_query = "
    SELECT 
        s.store_name,
        sp.stock,
        sp.price,
        s.address,
        s.contact_number
    FROM store_prices sp
    JOIN stores s ON sp.store_id = s.id
    WHERE sp.product_id = ?
    ORDER BY sp.stock DESC
";
$stmt = $conn->prepare($store_stock_query);
$stmt->bind_param("i", $productId);
$stmt->execute();
$store_stocks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$total_sold = 0;
$total_revenue = 0;
$total_profit = 0;
$cashier_stats = [];

foreach ($purchases as $purchase) {
    $total_sold += $purchase['quantity'];
    $total_revenue += $purchase['subtotal'];
    $total_profit += $purchase['profit'];
    
    $cashier = $purchase['cashier_name'] ?? 'Unknown';
    if (!isset($cashier_stats[$cashier])) {
        $cashier_stats[$cashier] = [
            'name' => $cashier,
            'quantity' => 0,
            'revenue' => 0,
            'transactions' => 0
        ];
    }
    $cashier_stats[$cashier]['quantity'] += $purchase['quantity'];
    $cashier_stats[$cashier]['revenue'] += $purchase['subtotal'];
    $cashier_stats[$cashier]['transactions']++;
}

// Sort cashiers by quantity sold
usort($cashier_stats, function($a, $b) {
    return $b['quantity'] - $a['quantity'];
});

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product['name']); ?> - Product Details</title>
    <link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background-color: #f0f2f5;
        }

        /* Product Header */
        .product-header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .product-title {
            font-size: 32px;
            font-weight: bold;
            color: #050505;
            margin-bottom: 10px;
        }

        .product-subtitle {
            color: #65676b;
            font-size: 16px;
            margin-bottom: 20px;
        }

        .product-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .info-card {
            background: #f0f2f5;
            padding: 15px;
            border-radius: 8px;
        }

        .info-label {
            font-size: 12px;
            color: #65676b;
            margin-bottom: 5px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .info-value {
            font-size: 24px;
            font-weight: bold;
            color: #050505;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            border-left: 4px solid #1877f2;
        }

        .stat-card.success {
            border-left-color: #28a745;
        }

        .stat-card.warning {
            border-left-color: #ffc107;
        }

        .stat-card.info {
            border-left-color: #17a2b8;
        }

        .stat-card h3 {
            font-size: 14px;
            color: #65676b;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #050505;
        }

        /* Section */
        .section {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .section-title {
            font-size: 20px;
            font-weight: bold;
            color: #050505;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        thead {
            background: #f0f2f5;
        }

        th {
            text-align: left;
            padding: 12px;
            font-weight: 600;
            color: #050505;
            font-size: 14px;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #e4e6eb;
            color: #050505;
        }

        tr:hover {
            background: #f7f8fa;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-stock {
            background: #e7f3ff;
            color: #1877f2;
        }

        .badge-price {
            background: #fff3cd;
            color: #856404;
        }

        .badge-restock {
            background: #d4edda;
            color: #155724;
        }

        .badge-sale {
            background: #cce5ff;
            color: #004085;
        }

        .value-change {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .old-value {
            color: #dc3545;
            text-decoration: line-through;
        }

        .new-value {
            color: #28a745;
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #65676b;
        }

        .tabs {
            display: flex;
            gap: 10px;
            border-bottom: 2px solid #e4e6eb;
            margin-bottom: 20px;
        }

        .tab {
            padding: 12px 20px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: 600;
            color: #65676b;
            transition: all 0.2s;
        }

        .tab.active {
            color: #1877f2;
            border-bottom-color: #1877f2;
        }

        .tab:hover {
            color: #1877f2;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        @media (max-width: 768px) {
            .product-info-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Product Header -->
        <div class="product-header">
            <h1 class="product-title">📦 <?php echo htmlspecialchars($product['name']); ?></h1>
            <p class="product-subtitle">Complete product analytics and transaction history</p>

            <div class="product-info-grid">
                <div class="info-card">
                    <div class="info-label">Product ID</div>
                    <div class="info-value">#<?php echo $product['id']; ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Barcode</div>
                    <div class="info-value"><?php echo htmlspecialchars($product['barcode']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Current Stock</div>
                    <div class="info-value"><?php echo number_format($product['stock']); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Selling Price</div>
                    <div class="info-value">₱<?php echo number_format($product['price'], 2); ?></div>
                </div>
                <div class="info-card">
                    <div class="info-label">Purchase Price</div>
                    <div class="info-value">₱<?php echo number_format($product['purchase_price'], 2); ?></div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card info">
                <h3>📦 Total Units Sold</h3>
                <div class="stat-value"><?php echo number_format($total_sold); ?></div>
            </div>
            <div class="stat-card success">
                <h3>💰 Total Revenue</h3>
                <div class="stat-value">₱<?php echo number_format($total_revenue, 2); ?></div>
            </div>
            <div class="stat-card <?php echo $total_profit >= 0 ? 'success' : 'warning'; ?>">
                <h3>📈 Total Profit</h3>
                <div class="stat-value" style="color: <?php echo $total_profit >= 0 ? '#28a745' : '#dc3545'; ?>">
                    ₱<?php echo number_format($total_profit, 2); ?>
                </div>
            </div>
            <div class="stat-card">
                <h3>🔢 Total Transactions</h3>
                <div class="stat-value"><?php echo number_format(count($purchases)); ?></div>
            </div>
        </div>

        <!-- Store Stock Distribution -->
        <?php if (!empty($store_stocks)): ?>
        <div class="section">
            <h2 class="section-title">🏪 Stock Distribution Across Stores</h2>
            <table>
                <thead>
                    <tr>
                        <th>Store Name</th>
                        <th>Address</th>
                        <th>Contact</th>
                        <th>Stock</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($store_stocks as $store): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($store['store_name']); ?></strong></td>
                        <td><?php echo htmlspecialchars($store['address'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($store['contact_number'] ?? 'N/A'); ?></td>
                        <td><strong><?php echo number_format($store['stock']); ?> units</strong></td>
                        <td>₱<?php echo number_format($store['price'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Cashier Performance -->
        <?php if (!empty($cashier_stats)): ?>
        <div class="section">
            <h2 class="section-title">👤 Cashier Performance</h2>
            <p style="color: #65676b; margin-bottom: 15px;">Sales performance by cashier for this product</p>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Cashier Name</th>
                        <th>Units Sold</th>
                        <th>Revenue Generated</th>
                        <th>Transactions</th>
                        <th>Avg per Transaction</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    foreach ($cashier_stats as $cashier): 
                        $avg_per_trans = $cashier['transactions'] > 0 ? $cashier['quantity'] / $cashier['transactions'] : 0;
                    ?>
                    <tr>
                        <td>
                            <span style="font-weight: bold; color: <?php 
                                echo $rank == 1 ? '#ffc107' : ($rank == 2 ? '#c0c0c0' : ($rank == 3 ? '#cd7f32' : '#666')); 
                            ?>">
                                #<?php echo $rank; ?>
                            </span>
                        </td>
                        <td><strong><?php echo htmlspecialchars($cashier['name']); ?></strong></td>
                        <td><strong><?php echo number_format($cashier['quantity']); ?> units</strong></td>
                        <td><strong>₱<?php echo number_format($cashier['revenue'], 2); ?></strong></td>
                        <td><?php echo number_format($cashier['transactions']); ?></td>
                        <td><?php echo number_format($avg_per_trans, 1); ?> units</td>
                    </tr>
                    <?php 
                    $rank++;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Tabbed History Section -->
        <div class="section">
            <h2 class="section-title">📜 Product History</h2>
            
            <div class="tabs">
                <button class="tab active" onclick="switchTab('purchases')">💳 Purchase History</button>
                <button class="tab" onclick="switchTab('changes')">⚙️ Price & Stock Changes</button>
                <button class="tab" onclick="switchTab('restocks')">📦 Restocking History</button>
            </div>

            <!-- Purchase History Tab -->
            <div id="purchases" class="tab-content active">
                <?php if (!empty($purchases)): ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Transaction ID</th>
                                <th>Date & Time</th>
                                <th>Quantity</th>
                                <th>Unit Price</th>
                                <th>Purchase Price</th>
                                <th>Subtotal</th>
                                <th>Profit</th>
                                <th>Payment Method</th>
                                <th>Cashier</th>
                                <th>Store</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchases as $purchase): ?>
                            <tr>
                                <td><strong>#<?php echo $purchase['transaction_id']; ?></strong></td>
                                <td>
                                    <?php 
                                    $date = new DateTime($purchase['transaction_date']);
                                    echo $date->format('M j, Y');
                                    ?>
                                    <br>
                                    <small style="color: #65676b;"><?php echo $date->format('g:i A'); ?></small>
                                </td>
                                <td><strong><?php echo number_format($purchase['quantity']); ?></strong></td>
                                <td>₱<?php echo number_format($purchase['unit_price'], 2); ?></td>
                                <td>₱<?php echo number_format($purchase['purchase_price'], 2); ?></td>
                                <td><strong>₱<?php echo number_format($purchase['subtotal'], 2); ?></strong></td>
                                <td style="color: <?php echo $purchase['profit'] >= 0 ? '#28a745' : '#dc3545'; ?>">
                                    <strong>₱<?php echo number_format($purchase['profit'], 2); ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge-sale">
                                        <?php echo strtoupper($purchase['payment_method']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($purchase['cashier_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($purchase['store_name'] ?? 'N/A'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <p style="font-size: 48px;">📭</p>
                    <p style="font-weight: 600; margin-bottom: 5px;">No Purchase History</p>
                    <p>This product has not been sold yet.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Price & Stock Changes Tab -->
            <div id="changes" class="tab-content">
                <?php if (!empty($history)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Change Type</th>
                            <th>Value Change</th>
                            <th>Changed By</th>
                            <th>Date & Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                        <tr>
                            <td>
                                <span class="badge badge-<?php echo $entry['change_type']; ?>">
                                    <?php echo $entry['change_type'] === 'price' ? '💵' : '📦'; ?>
                                    <?php echo ucfirst($entry['change_type']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="value-change">
                                    <span class="old-value">
                                        <?php 
                                        echo $entry['change_type'] === 'price' 
                                            ? '₱' . number_format($entry['old_value'], 2) 
                                            : number_format($entry['old_value']);
                                        ?>
                                    </span>
                                    <span>→</span>
                                    <span class="new-value">
                                        <?php 
                                        echo $entry['change_type'] === 'price' 
                                            ? '₱' . number_format($entry['new_value'], 2) 
                                            : number_format($entry['new_value']);
                                        ?>
                                    </span>
                                </div>
                            </td>
                            <td><strong><?php echo htmlspecialchars($entry['user_name']); ?></strong></td>
                            <td>
                                <?php 
                                $date = new DateTime($entry['changed_at']);
                                echo $date->format('M j, Y g:i A');
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <p style="font-size: 48px;">📋</p>
                    <p style="font-weight: 600; margin-bottom: 5px;">No Changes Recorded</p>
                    <p>No price or stock changes have been made to this product.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Restocking History Tab -->
            <div id="restocks" class="tab-content">
                <?php if (!empty($restocks)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Details</th>
                            <th>User</th>
                            <th>Date & Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($restocks as $restock): ?>
                        <tr>
                            <td>
                                <span class="badge badge-restock">
                                    📦 <?php echo htmlspecialchars($restock['action']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($restock['details']); ?></td>
                            <td><strong><?php echo htmlspecialchars($restock['user_name']); ?></strong></td>
                            <td>
                                <?php 
                                $date = new DateTime($restock['created_at']);
                                echo $date->format('M j, Y g:i A');
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <p style="font-size: 48px;">📦</p>
                    <p style="font-weight: 600; margin-bottom: 5px;">No Restocking History</p>
                    <p>No restocking activities recorded in system logs.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        function switchTab(tabName) {
            // Hide all tab contents
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => content.classList.remove('active'));
            
            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
    </script>
</body>
</html>