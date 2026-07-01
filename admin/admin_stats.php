<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';
require_once __DIR__ . '/../core/product_stock_helper.php';

// Only admins can access
if (!isAdmin()) {
    header("Location: /oro-store-demo/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();


// Get filter parameters
$times_bought_period = isset($_GET['times_bought']) ? $_GET['times_bought'] : 'daily';
$stock_purchased_period = isset($_GET['stock_purchased']) ? $_GET['stock_purchased'] : 'daily';
$profit_period = isset($_GET['profit']) ? $_GET['profit'] : 'daily';

// Function to get date condition based on period
function getDateCondition($period, $column = 'transaction_date') {
    $today = date('Y-m-d');
    switch($period) {
        case 'daily':
            return "DATE($column) = '$today'";
        case 'weekly':
            $week_start = date('Y-m-d', strtotime('monday this week'));
            return "DATE($column) >= '$week_start'";
        case 'monthly':
            $month_start = date('Y-m-01');
            return "DATE($column) >= '$month_start'";
        default:
            return "DATE($column) = '$today'";
    }
}

// Get product statistics with all metrics
$stats_query = "
    SELECT 
        p.id,
        p.name,
        p.barcode,
        p.price as default_price,
        p.purchase_price as default_purchase_price,
        p.parent_product_id,  -- ✅ ADD: Track if individual product
        p.can_sell_individually,  -- ✅ ADD: Track if can sell individually
        p.individual_pieces_per_pack,  -- ✅ ADD: For calculations
        
        -- Times Bought (based on selected period)
        COALESCE(SUM(CASE 
            WHEN " . getDateCondition($times_bought_period, 't.transaction_date') . " 
            AND t.status = 'completed' AND t.is_deleted = 0
            THEN ti.quantity ELSE 0 
        END), 0) as times_bought,
        
        -- Stock Sold (based on selected period)
        COALESCE(SUM(CASE 
            WHEN " . getDateCondition($stock_purchased_period, 't.transaction_date') . " 
            AND t.status = 'completed' AND t.is_deleted = 0
            THEN ti.quantity ELSE 0 
        END), 0) as stock_sold,
        
        -- Profit Calculation (based on selected period)
        COALESCE(SUM(CASE 
            WHEN " . getDateCondition($profit_period, 't.transaction_date') . " 
            AND t.status = 'completed' AND t.is_deleted = 0
            THEN ti.quantity * (ti.price - ti.purchase_price) ELSE 0 
        END), 0) as profit,
        
        -- Current Total Stock across all stores
        COALESCE(SUM(sp.stock), 0) as current_stock,
        
        -- Average selling price across stores
        COALESCE(AVG(sp.price), p.price) as avg_selling_price,
        
        -- Total revenue (for the profit period)
        COALESCE(SUM(CASE 
            WHEN " . getDateCondition($profit_period, 't.transaction_date') . " 
            AND t.status = 'completed' AND t.is_deleted = 0
            THEN ti.quantity * ti.price ELSE 0 
        END), 0) as revenue
        
    FROM products p
    LEFT JOIN transaction_items ti ON p.id = ti.product_id AND ti.is_deleted = 0
    LEFT JOIN transactions t ON ti.transaction_id = t.id
    LEFT JOIN store_prices sp ON p.id = sp.product_id AND sp.is_deleted = 0
    WHERE p.is_deleted = 0
    GROUP BY p.id, p.name, p.barcode, p.price, p.purchase_price, p.parent_product_id, p.can_sell_individually, p.individual_pieces_per_pack
    ORDER BY times_bought DESC, profit DESC
";
$stats_result = $conn->query($stats_query);
$product_stats = [];
if ($stats_result) {
    while ($row = $stats_result->fetch_assoc()) {
        // ✅ CHANGE: Calculate supply and demand ratio
        $demand = floatval($row['times_bought']);
        $supply = floatval($row['current_stock']);
        
        // ✅ ADD: For individual products, calculate total available stock (individual + packs)
        if ($row['parent_product_id']) {
            // This is an individual product - get total available including packs
            try {
                $total_available = getTotalAvailableIndividualStock($row['id'], null);
                $supply = $total_available;
            } catch (Exception $e) {
                // If error, use current stock
                $supply = floatval($row['current_stock']);
            }
        } elseif ($row['can_sell_individually'] && $row['individual_pieces_per_pack'] > 0) {
            // This is a pack product that can be sold individually
            // Supply is just the pack stock (individual stock is tracked separately)
            $supply = floatval($row['current_stock']);
        }
        
        // Supply/Demand Ratio calculation
        if ($demand == 0 && $supply == 0) {
            $supply_demand_ratio = 50;
            $demand_status = 'No Activity';
        } elseif ($demand == 0) {
            $supply_demand_ratio = 100;
            $demand_status = 'Oversupplied';
        } elseif ($supply == 0) {
            $supply_demand_ratio = 0;
            $demand_status = 'Out of Stock';
        } else {
            $supply_demand_ratio = ($supply / ($supply + $demand)) * 100;
            
            if ($supply_demand_ratio >= 80) {
                $demand_status = 'Oversupplied';
            } elseif ($supply_demand_ratio >= 60) {
                $demand_status = 'Well Stocked';
            } elseif ($supply_demand_ratio >= 40) {
                $demand_status = 'Balanced';
            } elseif ($supply_demand_ratio >= 20) {
                $demand_status = 'High Demand';
            } else {
                $demand_status = 'Critical Stock';
            }
        }
        
        $row['supply_demand_ratio'] = round($supply_demand_ratio, 1);
        $row['demand_status'] = $demand_status;
        $row['total_available_stock'] = $supply;  // ✅ ADD: Store calculated supply
        $product_stats[] = $row;
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Product Statistics - Admin Panel</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <style>
        .stats-filters {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .filter-group {
            display: inline-block;
            margin-right: 30px;
            margin-bottom: 15px;
        }
        
        .filter-group label {
            display: block;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            min-width: 150px;
            background: white;
        }
        
        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #1877f2;
        }
        
        .summary-card.success {
            border-left-color: #28a745;
        }
        
        .summary-card.warning {
            border-left-color: #ffc107;
        }
        
        .summary-card.danger {
            border-left-color: #dc3545;
        }
        
        .summary-card h3 {
            margin: 0 0 10px 0;
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }
        
        .summary-card .value {
            font-size: 32px;
            font-weight: bold;
            color: #333;
        }
        
        .demand-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .demand-oversupplied {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .demand-well-stocked {
            background: #e8f5e9;
            color: #388e3c;
        }
        
        .demand-balanced {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .demand-high-demand {
            background: #fff3cd;
            color: #856404;
        }
        
        .demand-critical-stock {
            background: #f8d7da;
            color: #721c24;
        }
        
        .demand-no-activity {
            background: #f0f2f5;
            color: #666;
        }
        
        .demand-out-of-stock {
            background: #000;
            color: #fff;
        }
        
        .ratio-bar {
            width: 100%;
            height: 20px;
            background: #f0f2f5;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        
        .ratio-fill {
            height: 100%;
            background: linear-gradient(90deg, #dc3545 0%, #ffc107 50%, #28a745 100%);
            transition: width 0.3s ease;
        }
        
        .ratio-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 11px;
            font-weight: 600;
            color: #333;
        }
        
        .product-rank {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            font-weight: bold;
            font-size: 14px;
        }
        
        .rank-1 {
            background: #ffd700;
            color: #856404;
        }
        
        .rank-2 {
            background: #c0c0c0;
            color: #666;
        }
        
        .rank-3 {
            background: #cd7f32;
            color: #fff;
        }
        
        .rank-other {
            background: #f0f2f5;
            color: #666;
        }
        
        .highlight-row {
            background: #f8f9fa !important;
        }
        
        .profit-positive {
            color: #28a745;
            font-weight: bold;
        }
        
        .profit-negative {
            color: #dc3545;
            font-weight: bold;
        }
        
        .profit-zero {
            color: #666;
        }
        
        /* View Details Button */
        .view-details-btn {
            background: #1877f2;
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        
        .view-details-btn:hover {
            background: #166fe5;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(24, 119, 242, 0.3);
        }
        
        .view-details-btn svg {
            width: 16px;
            height: 16px;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Filters Section -->
        <div class="stats-filters">
            <h2 style="margin: 0 0 20px 0;">📊 Statistics Filters</h2>
            <form method="GET" action="/oro-store-demo/admin/admin_stats.php">
                <div class="filter-group">
                    <label>🛒 Times Bought Period</label>
                    <select name="times_bought" onchange="this.form.submit()">
                        <option value="daily" <?php echo $times_bought_period == 'daily' ? 'selected' : ''; ?>>Today</option>
                        <option value="weekly" <?php echo $times_bought_period == 'weekly' ? 'selected' : ''; ?>>This Week</option>
                        <option value="monthly" <?php echo $times_bought_period == 'monthly' ? 'selected' : ''; ?>>This Month</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>📦 Stock Sold Period</label>
                    <select name="stock_purchased" onchange="this.form.submit()">
                        <option value="daily" <?php echo $stock_purchased_period == 'daily' ? 'selected' : ''; ?>>Today</option>
                        <option value="weekly" <?php echo $stock_purchased_period == 'weekly' ? 'selected' : ''; ?>>This Week</option>
                        <option value="monthly" <?php echo $stock_purchased_period == 'monthly' ? 'selected' : ''; ?>>This Month</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>💰 Profit Period</label>
                    <select name="profit" onchange="this.form.submit()">
                        <option value="daily" <?php echo $profit_period == 'daily' ? 'selected' : ''; ?>>Today</option>
                        <option value="weekly" <?php echo $profit_period == 'weekly' ? 'selected' : ''; ?>>This Week</option>
                        <option value="monthly" <?php echo $profit_period == 'monthly' ? 'selected' : ''; ?>>This Month</option>
                    </select>
                </div>
            </form>
        </div>

        <!-- Summary Cards -->
        <div class="stats-summary">
            <?php
            $total_products = count($product_stats);
            $total_profit = array_sum(array_column($product_stats, 'profit'));
            $total_revenue = array_sum(array_column($product_stats, 'revenue'));
            $total_stock = array_sum(array_column($product_stats, 'current_stock'));
            ?>
            <div class="summary-card">
                <h3>📦 Total Products</h3>
                <div class="value"><?php echo number_format($total_products); ?></div>
            </div>
            
            <div class="summary-card success">
                <h3>💰 Total Profit (<?php echo ucfirst($profit_period); ?>)</h3>
                <div class="value" style="color: <?php echo $total_profit >= 0 ? '#28a745' : '#dc3545'; ?>">
                    ₱<?php echo number_format($total_profit, 2); ?>
                </div>
            </div>
            
            <div class="summary-card warning">
                <h3>💵 Total Revenue (<?php echo ucfirst($profit_period); ?>)</h3>
                <div class="value" style="color: #f57c00;">₱<?php echo number_format($total_revenue, 2); ?></div>
            </div>
            
            <div class="summary-card">
                <h3>📊 Total Stock Available</h3>
                <div class="value"><?php echo number_format($total_stock); ?></div>
            </div>
        </div>

        <!-- Products Statistics Table -->
        <div class="content-section">
            <div class="section-header">
                <h2 class="section-title">📈 Product Performance Analytics</h2>
            </div>
            
            <div class="search-box">
                <input type="text" id="search-stats" placeholder="🔍 Search products..." onkeyup="searchStats()">
            </div>
            
            <div style="overflow-x: auto;">
                <table class="products-table" id="stats-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Product Name</th>
                            <th>Barcode</th>
                            <th>🛒 Times Bought<br><small>(<?php echo ucfirst($times_bought_period); ?>)</small></th>
                            <th>📦 Stock Sold<br><small>(<?php echo ucfirst($stock_purchased_period); ?>)</small></th>
                            <th>💰 Profit<br><small>(<?php echo ucfirst($profit_period); ?>)</small></th>
                            <th>💵 Revenue<br><small>(<?php echo ucfirst($profit_period); ?>)</small></th>
                            <th>📊 Current Stock</th>
                            <th>💲 Avg Price</th>
                            <th>📈 Supply/Demand</th>
                            <th>Status</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($product_stats as $stat): 
                            $rank_class = $rank == 1 ? 'rank-1' : ($rank == 2 ? 'rank-2' : ($rank == 3 ? 'rank-3' : 'rank-other'));
                            $highlight = $rank <= 3 ? 'highlight-row' : '';
                            $badge_class = 'demand-' . strtolower(str_replace(' ', '-', $stat['demand_status']));
                        ?>
                        <tr class="<?php echo $highlight; ?>">
                            <td style="text-align: center;">
                                <div class="product-rank <?php echo $rank_class; ?>">
                                    <?php echo $rank; ?>
                                </div>
                            </td>
                           <td>
    <strong><?php echo htmlspecialchars($stat['name']); ?></strong>
    <?php if ($stat['parent_product_id']): ?>
        <br><small style="color: #1877f2; font-weight: 600;">📦 Individual Product</small>
    <?php elseif ($stat['can_sell_individually']): ?>
        <br><small style="color: #28a745; font-weight: 600;">📦 Pack (Can Sell Individually)</small>
    <?php endif; ?>
</td>
                            <td><?php echo htmlspecialchars($stat['barcode']); ?></td>
                            <td style="text-align: center;">
                                <strong style="font-size: 16px;"><?php echo number_format($stat['times_bought']); ?></strong>
                            </td>
                            <td style="text-align: center;">
                                <strong style="font-size: 16px;"><?php echo number_format($stat['stock_sold']); ?></strong>
                            </td>
                            <td style="text-align: right;">
                                <span class="<?php 
                                    echo $stat['profit'] > 0 ? 'profit-positive' : 
                                         ($stat['profit'] < 0 ? 'profit-negative' : 'profit-zero'); 
                                ?>">
                                    ₱<?php echo number_format($stat['profit'], 2); ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <strong>₱<?php echo number_format($stat['revenue'], 2); ?></strong>
                            </td>
                            <td style="text-align: center;">
                                <span class="stock-badge <?php 
                                    $stock = $stat['current_stock'];
                                    if ($stock > 50) echo 'stock-high';
                                    elseif ($stock > 10) echo 'stock-medium';
                                    else echo 'stock-low';
                                ?>">
                                    <?php echo number_format($stock); ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                ₱<?php echo number_format($stat['avg_selling_price'], 2); ?>
                            </td>
                            <td>
                                <div class="ratio-bar">
                                    <div class="ratio-fill" style="width: <?php echo $stat['supply_demand_ratio']; ?>%"></div>
                                    <div class="ratio-text"><?php echo $stat['supply_demand_ratio']; ?>%</div>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <span class="demand-badge <?php echo $badge_class; ?>">
                                    <?php echo $stat['demand_status']; ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <a href="/oro-store-demo/admin/admin_view_details.php?id=<?php echo $stat['id']; ?>" class="view-details-btn">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                    View Details
                                </a>
                            </td>
                        </tr>
                        <?php 
                        $rank++;
                        endforeach; 
                        ?>
                        <?php if (empty($product_stats)): ?>
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 40px; color: #999;">
                                No product statistics available.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Legend -->
        <div class="content-section" style="margin-top: 30px;">
            <h3>📖 Supply/Demand Legend</h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                <div style="padding: 10px; background: #f8f9fa; border-radius: 6px;">
                    <span class="demand-badge demand-oversupplied">Oversupplied</span>
                    <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">Supply significantly exceeds demand (80%+)</p>
                </div>
                <div style="padding: 10px; background: #f8f9fa; border-radius: 6px;">
                    <span class="demand-badge demand-well-stocked">Well Stocked</span>
                    <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">Good supply relative to demand (60-80%)</p>
                </div>
                <div style="padding: 10px; background: #f8f9fa; border-radius: 6px;">
                    <span class="demand-badge demand-balanced">Balanced</span>
                    <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">Supply and demand are balanced (40-60%)</p>
                </div>
                <div style="padding: 10px; background: #f8f9fa; border-radius: 6px;">
                    <span class="demand-badge demand-high-demand">High Demand</span>
                    <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">Demand exceeds supply (20-40%)</p>
                </div>
                <div style="padding: 10px; background: #f8f9fa; border-radius: 6px;">
                    <span class="demand-badge demand-critical-stock">Critical Stock</span>
                    <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">Very low stock vs high demand (0-20%)</p>
                </div>
                <div style="padding: 10px; background: #f8f9fa; border-radius: 6px;">
                    <span class="demand-badge demand-out-of-stock">Out of Stock</span>
                    <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">No stock available but demand exists</p>
                </div>
            </div>
        </div>
    </main>

    <script>
        function searchStats() {
            const input = document.getElementById('search-stats');
            const filter = input.value.toUpperCase();
            const table = document.getElementById('stats-table');
            const tr = table.getElementsByTagName('tr');

            for (let i = 1; i < tr.length; i++) {
                const td = tr[i].getElementsByTagName('td');
                let found = false;
                
                if (td.length > 1) {
                    const productName = td[1].textContent || td[1].innerText;
                    const barcode = td[2].textContent || td[2].innerText;
                    
                    if (productName.toUpperCase().indexOf(filter) > -1 || 
                        barcode.toUpperCase().indexOf(filter) > -1) {
                        found = true;
                    }
                }
                
                tr[i].style.display = found ? '' : 'none';
            }
        }
    </script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Statistics', array (
  'Features' => 
  array (
    0 => 'Sales trends and analytics charts',
    1 => 'Revenue and profit over time',
    2 => 'Product performance rankings',
    3 => 'Store comparison (multi-device)',
  ),
));
?>
</body>
</html>