<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

// Only admins can access
if (!isAdmin()) {
    header("Location: /oro-store/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();


$productId = $_GET['id'] ?? null;
$type = $_GET['type'] ?? 'all';

// Get product details if ID provided
$product = null;
if ($productId) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $productId);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Fetch history
$history = [];
if ($productId) {
    $query = "SELECT ph.*, p.name as product_name FROM product_history ph 
              LEFT JOIN products p ON ph.product_id = p.id 
              WHERE ph.product_id = ?";
    $params = [$productId];
    $types = "i";

    if ($type !== 'all') {
        $query .= " AND ph.change_type = ?";
        $params[] = $type;
        $types .= "s";
    }

    $query .= " ORDER BY ph.changed_at DESC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    // Show all recent history if no product ID
    $query = "SELECT ph.*, p.name as product_name 
              FROM product_history ph 
              LEFT JOIN products p ON ph.product_id = p.id";
    
    if ($type !== 'all') {
        $query .= " WHERE ph.change_type = ?";
    }
    
    $query .= " ORDER BY ph.changed_at DESC LIMIT 500";
    
    if ($type !== 'all') {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $type);
        $stmt->execute();
        $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $history = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product History - Oro Store</title>
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

        /* Main Content */
        .main-content {
            max-width: 1400px;
            margin: 20px auto;
            padding: 0 20px;
        }

        /* Page Header */
        .page-header {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .page-title {
            font-size: 28px;
            font-weight: bold;
            color: #050505;
            margin-bottom: 10px;
        }

        .page-subtitle {
            color: #65676b;
            font-size: 14px;
        }

        .product-info {
            display: flex;
            gap: 15px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #e4e6eb;
        }

        .product-detail {
            background: #f0f2f5;
            padding: 10px 15px;
            border-radius: 8px;
        }

        .product-detail-label {
            font-size: 12px;
            color: #65676b;
            margin-bottom: 4px;
        }

        .product-detail-value {
            font-weight: 600;
            color: #050505;
        }

        /* Filters */
        .filters {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .filter-title {
            font-weight: 600;
            margin-bottom: 12px;
            color: #050505;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 8px 16px;
            background: #e4e6eb;
            border: 2px solid transparent;
            border-radius: 20px;
            cursor: pointer;
            font-weight: 600;
            color: #050505;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }

        .filter-btn:hover {
            background: #d8dadf;
        }

        .filter-btn.active {
            background: #1877f2;
            color: white;
            border-color: #1877f2;
        }

        /* History Table */
        .history-section {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            overflow-x: auto;
        }

        .history-count {
            color: #65676b;
            margin-bottom: 15px;
            font-size: 14px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
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

        .change-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .change-badge.stock {
            background: #e7f3ff;
            color: #1877f2;
        }

        .change-badge.price {
            background: #fff3cd;
            color: #856404;
        }

        .value-change {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .old-value {
            color: #dc3545;
            text-decoration: line-through;
        }

        .arrow {
            color: #65676b;
        }

        .new-value {
            color: #28a745;
            font-weight: 600;
        }

        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: #e4e6eb;
            border-radius: 12px;
            font-size: 13px;
        }

        .user-icon {
            width: 20px;
            height: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 10px;
            font-weight: bold;
        }

        .timestamp {
            color: #65676b;
            font-size: 13px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #65676b;
        }

        .empty-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .empty-text {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .empty-subtext {
            font-size: 14px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .filter-buttons {
                flex-direction: column;
            }

            .product-info {
                flex-direction: column;
            }

            table {
                font-size: 13px;
            }

            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">
                <?php if ($productId && $product): ?>
                    📦 <?php echo htmlspecialchars($product['name']); ?> - History
                <?php else: ?>
                    📜 All Product History
                <?php endif; ?>
            </h1>
            <p class="page-subtitle">
                <?php if ($productId && $product): ?>
                    View all changes made to this product
                <?php else: ?>
                    Complete history of all product changes across your inventory
                <?php endif; ?>
            </p>

            <?php if ($productId && $product): ?>
            <div class="product-info">
                <div class="product-detail">
                    <div class="product-detail-label">Product ID</div>
                    <div class="product-detail-value">#<?php echo htmlspecialchars($product['id']); ?></div>
                </div>
                <div class="product-detail">
                    <div class="product-detail-label">Current Stock</div>
                    <div class="product-detail-value"><?php echo htmlspecialchars($product['stock']); ?> units</div>
                </div>
                <div class="product-detail">
                    <div class="product-detail-label">Current Price</div>
                    <div class="product-detail-value">₱<?php echo number_format($product['price'], 2); ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Filters -->
        <div class="filters">
            <div class="filter-title">Filter by Change Type</div>
            <div class="filter-buttons">
                <a href="?<?php echo $productId ? 'id=' . $productId . '&' : ''; ?>type=all" 
                   class="filter-btn <?php echo $type === 'all' ? 'active' : ''; ?>">
                    🔍 All Changes
                </a>
                <a href="?<?php echo $productId ? 'id=' . $productId . '&' : ''; ?>type=stock" 
                   class="filter-btn <?php echo $type === 'stock' ? 'active' : ''; ?>">
                    📦 Stock Changes
                </a>
                <a href="?<?php echo $productId ? 'id=' . $productId . '&' : ''; ?>type=price" 
                   class="filter-btn <?php echo $type === 'price' ? 'active' : ''; ?>">
                    💵 Price Changes
                </a>
            </div>
        </div>

        <!-- History Table -->
        <div class="history-section">
            <?php if (empty($history)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📭</div>
                    <div class="empty-text">No History Found</div>
                    <div class="empty-subtext">
                        <?php if ($type !== 'all'): ?>
                            No <?php echo htmlspecialchars($type); ?> changes recorded yet
                        <?php else: ?>
                            No changes have been recorded yet
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="history-count">
                    Showing <?php echo count($history); ?> record<?php echo count($history) !== 1 ? 's' : ''; ?>
                </div>
                <table>
                    <thead>
                        <tr>
                            <?php if (!$productId): ?>
                            <th>Product</th>
                            <?php endif; ?>
                            <th>Change Type</th>
                            <th>Value Change</th>
                            <th>Changed By</th>
                            <th>Date & Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                        <tr>
                            <?php if (!$productId): ?>
                            <td>
                                <strong><?php echo htmlspecialchars($entry['product_name'] ?? 'Unknown Product'); ?></strong>
                                <div style="font-size: 12px; color: #65676b;">ID: <?php echo htmlspecialchars($entry['product_id']); ?></div>
                            </td>
                            <?php endif; ?>
                            <td>
                                <span class="change-badge <?php echo htmlspecialchars($entry['change_type']); ?>">
                                    <?php echo $entry['change_type'] === 'stock' ? '📦' : '💵'; ?>
                                    <?php echo ucfirst(htmlspecialchars($entry['change_type'])); ?>
                                </span>
                            </td>
                            <td>
                                <div class="value-change">
                                    <span class="old-value">
                                        <?php 
                                        if ($entry['change_type'] === 'price') {
                                            echo '₱' . number_format($entry['old_value'], 2);
                                        } else {
                                            echo htmlspecialchars($entry['old_value']);
                                        }
                                        ?>
                                    </span>
                                    <span class="arrow">→</span>
                                    <span class="new-value">
                                        <?php 
                                        if ($entry['change_type'] === 'price') {
                                            echo '₱' . number_format($entry['new_value'], 2);
                                        } else {
                                            echo htmlspecialchars($entry['new_value']);
                                        }
                                        ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <div class="user-badge">
                                    <div class="user-icon">
                                        <?php echo strtoupper(substr($entry['user_name'] ?? 'S', 0, 1)); ?>
                                    </div>
                                    <?php echo htmlspecialchars($entry['user_name'] ?? 'System'); ?>
                                </div>
                            </td>
                            <td>
                                <div class="timestamp">
                                    <?php 
                                    $time = strtotime($entry['changed_at']);
                                    echo date('M j, Y', $time);
                                    ?>
                                </div>
                                <div class="timestamp" style="font-size: 12px;">
                                    <?php echo date('g:i A', $time); ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>