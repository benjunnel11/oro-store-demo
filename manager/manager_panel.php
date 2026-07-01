<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';
require_once __DIR__ . '/../sync/sync_helper.php';
require_once __DIR__ . '/../core/transaction_helper.php';

$db = new SyncDB();

// Only managers and admins can access
if (!isManager() && !isAdmin()) {
    header("Location: /oro-store-demo/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

// Get manager's store
$userStore = null;
if ($currentUser['store_id']) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $currentUser['store_id']);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$userStore) {
    die("Error: You are not assigned to any store. Please contact the administrator.");
}

// Get current view
$view = isset($_GET['view']) ? $_GET['view'] : 'dashboard';

// Handle product actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_stock') {
        $product_id = intval($_POST['product_id']);
        $stock_change = intval($_POST['stock_change']);
        $change_type = $_POST['change_type'];
        
        $stmt = $conn->prepare("SELECT name FROM products WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        
        $stmt = $conn->prepare("SELECT * FROM store_prices WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("ii", $product_id, $userStore['id']);
        $stmt->execute();
        $current_data = $stmt->get_result()->fetch_assoc();
        
        if (!$current_data) {
            $stmt = $conn->prepare("SELECT price, purchase_price, stock FROM products WHERE id = ?");
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $current_data = $stmt->get_result()->fetch_assoc();
        }
        
        $old_stock = $current_data['stock'];
        $new_stock = $change_type === 'add' ? $old_stock + $stock_change : max(0, $old_stock - $stock_change);
        
        $stmt = $conn->prepare("SELECT id FROM store_prices WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("ii", $product_id, $userStore['id']);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        
        if ($exists) {
            $stmt = $conn->prepare("UPDATE store_prices SET stock = ? WHERE product_id = ? AND store_id = ?");
            $stmt->bind_param("iii", $new_stock, $product_id, $userStore['id']);
            $result = $stmt->execute();
            $stmt->close();
            
            $db->logChange('store_prices', "product_id = $product_id AND store_id = {$userStore['id']}", 'UPDATE', [
                'stock_change' => $change_type === 'add' ? $stock_change : -$stock_change
            ]);
        } else {
            $result = $db->insert('store_prices', [
                'product_id' => $product_id,
                'store_id' => $userStore['id'],
                'price' => $current_data['price'],
                'purchase_price' => $current_data['purchase_price'],
                'stock' => $new_stock
            ]);
        }
        
        if ($result) {
            $action_text = $change_type === 'add' ? 'Added' : 'Removed';
            logActivity('product', "$action_text $stock_change units of {$product['name']} at {$userStore['store_code']}", 
                $currentUser['id'], $userStore['id'], 
                [
                    'product_id' => $product_id,
                    'product_name' => $product['name'],
                    'change_type' => $change_type,
                    'stock_change' => $stock_change,
                    'old_stock' => $old_stock,
                    'new_stock' => $new_stock
                ]
            );
            
            $db->insert('product_history', [
                'product_id' => $product_id,
                'change_type' => 'stock_updated',
                'old_value' => "Stock: $old_stock",
                'new_value' => "Stock: $new_stock ($action_text $stock_change)"
            ]);
            
            $success = "Stock updated successfully!";
        } else {
            $error = "Error updating stock.";
        }
        
    } elseif ($_POST['action'] === 'add_product_to_store') {
        $product_id = intval($_POST['product_id']);
        $stock = intval($_POST['stock']);

        $stmt = $conn->prepare("SELECT name, price, purchase_price FROM products WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();

        $result = $db->insert('store_prices', [
            'product_id' => $product_id,
            'store_id' => $userStore['id'],
            'price' => $product['price'],
            'purchase_price' => $product['purchase_price'],
            'stock' => $stock
        ]);

        if ($result) {
            logActivity('product', "Added product {$product['name']} to {$userStore['store_code']}",
                $currentUser['id'], $userStore['id'],
                [
                    'product_id' => $product_id,
                    'product_name' => $product['name'],
                    'stock' => $stock
                ]
            );

            $db->insert('product_history', [
                'product_id' => $product_id,
                'change_type' => 'added_to_store',
                'old_value' => '',
                'new_value' => "Added to {$userStore['store_code']}: Stock: $stock"
            ]);

            $success = "Product added to store successfully!";
        } else {
            $error = "Error adding product to store.";
        }
    }
}

// Get store statistics
$stats = [];
$sid = $userStore['id'];

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM transactions WHERE store_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'completed' AND is_deleted = 0");
$stmt->bind_param("i", $sid);
$stmt->execute();
$stats['today_transactions'] = $stmt->get_result()->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(DISTINCT product_id) as c FROM store_prices WHERE store_id = ? AND is_deleted = 0");
$stmt->bind_param("i", $sid);
$stmt->execute();
$stats['total_products'] = $stmt->get_result()->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(DISTINCT product_id) as c FROM store_prices sp JOIN products p ON sp.product_id = p.id WHERE sp.store_id = ? AND sp.stock = 0 AND sp.is_deleted = 0 AND p.is_deleted = 0");
$stmt->bind_param("i", $sid);
$stmt->execute();
$stats['out_of_stock'] = $stmt->get_result()->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(DISTINCT product_id) as c FROM store_prices sp JOIN products p ON sp.product_id = p.id WHERE sp.store_id = ? AND sp.stock > 0 AND sp.stock <= 5 AND sp.is_deleted = 0 AND p.is_deleted = 0");
$stmt->bind_param("i", $sid);
$stmt->execute();
$stats['low_stock'] = $stmt->get_result()->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM deliveries WHERE store_id = ? AND is_deleted = 0");
$stmt->bind_param("i", $sid);
$stmt->execute();
$stats['total_deliveries'] = $stmt->get_result()->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM deliveries WHERE store_id = ? AND status = 'pending' AND is_deleted = 0");
$stmt->bind_param("i", $sid);
$stmt->execute();
$stats['pending_deliveries'] = $stmt->get_result()->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM deliveries WHERE store_id = ? AND status = 'lacking' AND is_deleted = 0");
$stmt->bind_param("i", $sid);
$stmt->execute();
$stats['lacking_deliveries'] = $stmt->get_result()->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM credits WHERE store_id = ? AND status IN ('unpaid','partial') AND is_deleted = 0");
$stmt->bind_param("i", $sid);
$stmt->execute();
$stats['unpaid_credits'] = $stmt->get_result()->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM employees WHERE store_id = ? AND status = 'active'");
$stmt->bind_param("i", $sid);
$stmt->execute();
$stats['employees'] = $stmt->get_result()->fetch_assoc()['c'];

// Today's attendance for this store
$stmt = $conn->prepare("SELECT COUNT(*) as c FROM employee_attendance ea JOIN employees e ON ea.employee_id = e.id WHERE e.store_id = ? AND ea.attendance_date = CURDATE() AND ea.attendance_type = 'whole_day'");
$stmt->bind_param("i", $sid);
$stmt->execute();
$stats['present_today'] = $stmt->get_result()->fetch_assoc()['c'];

$stmt = $conn->prepare("SELECT COUNT(*) as c FROM employee_attendance ea JOIN employees e ON ea.employee_id = e.id WHERE e.store_id = ? AND ea.attendance_date = CURDATE() AND ea.attendance_type = 'half_day'");
$stmt->bind_param("i", $sid);
$stmt->execute();
$stats['half_today'] = $stmt->get_result()->fetch_assoc()['c'];

// Low stock products list (top 8)
$stmt = $conn->prepare("SELECT p.name, sp.stock FROM store_prices sp JOIN products p ON sp.product_id = p.id WHERE sp.store_id = ? AND sp.is_deleted = 0 AND p.is_deleted = 0 AND sp.stock <= 10 ORDER BY sp.stock ASC LIMIT 8");
$stmt->bind_param("i", $sid);
$stmt->execute();
$low_stock_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Today's attendance list
$stmt = $conn->prepare("SELECT e.name, ea.attendance_type FROM employee_attendance ea JOIN employees e ON ea.employee_id = e.id WHERE e.store_id = ? AND ea.attendance_date = CURDATE() AND e.status = 'active' ORDER BY e.name");
$stmt->bind_param("i", $sid);
$stmt->execute();
$today_attendance = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get data based on view
if ($view === 'products') {
    $stmt = $conn->prepare("SELECT p.*, 
                           sp.price as store_price,
                           sp.purchase_price as store_purchase_price,
                           sp.stock as store_stock,
                           sp.id as store_price_id
                           FROM products p
                           INNER JOIN store_prices sp ON p.id = sp.product_id AND sp.store_id = ?
                           WHERE p.is_deleted = 0 AND sp.is_deleted = 0
                           ORDER BY p.name");
    $stmt->bind_param("i", $userStore['id']);
    $stmt->execute();
    $store_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $stmt = $conn->prepare("SELECT p.* FROM products p
                           WHERE p.id NOT IN (SELECT product_id FROM store_prices WHERE store_id = ? AND is_deleted = 0)
                           AND p.is_deleted = 0
                           ORDER BY p.name");
    $stmt->bind_param("i", $userStore['id']);
    $stmt->execute();
    $available_products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
} elseif ($view === 'attendance') {
    // Get employees for this store
    $stmt = $conn->prepare("SELECT e.*, r.role_name FROM employees e LEFT JOIN employee_roles r ON e.role_id = r.id WHERE e.store_id = ? AND e.status = 'active' ORDER BY e.name");
    $stmt->bind_param("i", $userStore['id']);
    $stmt->execute();
    $store_employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Get current week attendance
    $att_week_start = new DateTime();
    $att_week_start->modify('-' . ($att_week_start->format('N') - 1) . ' days');
    $att_week_end = clone $att_week_start;
    $att_week_end->modify('+6 days');

    $stmt = $conn->prepare("SELECT ea.* FROM employee_attendance ea JOIN employees e ON ea.employee_id = e.id WHERE e.store_id = ? AND ea.attendance_date BETWEEN ? AND ?");
    $stmt->bind_param("iss", $userStore['id'], $att_week_start->format('Y-m-d'), $att_week_end->format('Y-m-d'));
    $stmt->execute();
    $att_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $att_by_emp = [];
    foreach ($att_data as $a) {
        $att_by_emp[$a['employee_id']][$a['attendance_date']] = $a['attendance_type'];
    }

} else {
    $stmt = $conn->prepare("SELECT sl.*, u.full_name as user_name
                           FROM system_logs sl 
                           LEFT JOIN users u ON sl.user_id = u.id
                           WHERE sl.store_id = ? 
                           AND sl.is_deleted = 0
                           AND sl.activity_category NOT IN ('authentication', 'user', 'store')
                           ORDER BY sl.created_at DESC 
                           LIMIT 20");
    $stmt->bind_param("i", $userStore['id']);
    $stmt->execute();
    $recent_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manager Panel - <?php echo htmlspecialchars($userStore['store_name']); ?></title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
</head>
<body>
    <?php include_once __DIR__ . '/../manager/manager_sidebar.php'; ?>

    <main class="main-content">
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if ($view === 'dashboard'): ?>
            <!-- Welcome banner -->
            <div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:24px 28px;border-radius:14px;margin-bottom:20px;">
                <h1 style="font-size:22px;margin-bottom:4px;">&#127978; <?php echo htmlspecialchars($userStore['store_name']); ?></h1>
                <div style="opacity:.85;font-size:13px;display:flex;gap:18px;flex-wrap:wrap;">
                    <span>&#128205; <?php echo htmlspecialchars($userStore['address']); ?></span>
                    <span>&#128222; <?php echo htmlspecialchars($userStore['phone']); ?></span>
                    <span>&#128278; <?php echo htmlspecialchars($userStore['store_code']); ?></span>
                </div>
            </div>

            <!-- Alerts -->
            <?php if ($stats['out_of_stock'] > 0 || $stats['low_stock'] > 0 || $stats['lacking_deliveries'] > 0): ?>
            <div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
                <?php if ($stats['out_of_stock'] > 0): ?>
                <a href="?view=products" style="flex:1;min-width:180px;padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;color:#991b1b;font-weight:600;font-size:14px;text-decoration:none;display:flex;align-items:center;gap:10px;">
                    <span style="font-size:22px;">&#9888;</span>
                    <span><?php echo $stats['out_of_stock']; ?> Out of Stock<br><span style="font-size:11px;font-weight:400;">Needs restocking</span></span>
                </a>
                <?php endif; ?>
                <?php if ($stats['low_stock'] > 0): ?>
                <a href="?view=products" style="flex:1;min-width:180px;padding:12px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;color:#92400e;font-weight:600;font-size:14px;text-decoration:none;display:flex;align-items:center;gap:10px;">
                    <span style="font-size:22px;">&#128230;</span>
                    <span><?php echo $stats['low_stock']; ?> Low Stock<br><span style="font-size:11px;font-weight:400;">5 or fewer left</span></span>
                </a>
                <?php endif; ?>
                <?php if ($stats['lacking_deliveries'] > 0): ?>
                <a href="/oro-store-demo/delivery/delivery_details.php" style="flex:1;min-width:180px;padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;color:#991b1b;font-weight:600;font-size:14px;text-decoration:none;display:flex;align-items:center;gap:10px;">
                    <span style="font-size:22px;">&#128666;</span>
                    <span><?php echo $stats['lacking_deliveries']; ?> Lacking Deliveries<br><span style="font-size:11px;font-weight:400;">Items missing</span></span>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Stat cards -->
            <div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));">
                <div class="stat-card">
                    <div class="stat-icon blue">&#128230;</div>
                    <div class="stat-label">Products</div>
                    <div class="stat-value"><?php echo $stats['total_products']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon orange">&#128722;</div>
                    <div class="stat-label">Today's Sales</div>
                    <div class="stat-value"><?php echo $stats['today_transactions']; ?></div>
                    <div style="font-size:11px;color:#94a3b8;">transactions</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon purple">&#128666;</div>
                    <div class="stat-label">Pending Deliveries</div>
                    <div class="stat-value"><?php echo $stats['pending_deliveries']; ?></div>
                    <div style="font-size:11px;color:#94a3b8;"><?php echo $stats['total_deliveries']; ?> total</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon red">&#128180;</div>
                    <div class="stat-label">Unpaid Credits</div>
                    <div class="stat-value"><?php echo $stats['unpaid_credits']; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon green">&#128101;</div>
                    <div class="stat-label">Employees</div>
                    <div class="stat-value"><?php echo $stats['employees']; ?></div>
                    <div style="font-size:11px;color:#94a3b8;"><?php echo $stats['present_today']; ?> present today</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon blue">&#128197;</div>
                    <div class="stat-label">Attendance Today</div>
                    <div class="stat-value"><?php echo $stats['present_today'] + $stats['half_today']; ?>/<?php echo $stats['employees']; ?></div>
                    <div style="font-size:11px;color:#94a3b8;"><?php echo $stats['present_today']; ?> full, <?php echo $stats['half_today']; ?> half</div>
                </div>
            </div>

            <!-- Two column: Low stock + Today's attendance -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
                <!-- Low stock -->
                <div class="content-section">
                    <div class="section-header">
                        <h2 class="section-title">&#128200; Low Stock Items</h2>
                        <a href="?view=products" class="btn btn-secondary btn-sm">View All</a>
                    </div>
                    <?php if (empty($low_stock_products)): ?>
                        <div style="text-align:center;padding:20px;color:#94a3b8;">&#10004; All products well stocked</div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead><tr><th>Product</th><th style="text-align:right;">Stock</th></tr></thead>
                            <tbody>
                            <?php foreach ($low_stock_products as $lsp): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($lsp['name']); ?></td>
                                    <td style="text-align:right;">
                                        <span class="badge <?php echo $lsp['stock'] == 0 ? 'badge-danger' : 'badge-warning'; ?>">
                                            <?php echo $lsp['stock']; ?> left
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <!-- Today's attendance -->
                <div class="content-section">
                    <div class="section-header">
                        <h2 class="section-title">&#128197; Today's Attendance</h2>
                        <a href="?view=attendance" class="btn btn-secondary btn-sm">Full View</a>
                    </div>
                    <?php if (empty($today_attendance)): ?>
                        <div style="text-align:center;padding:20px;color:#94a3b8;">No attendance recorded yet today</div>
                    <?php else: ?>
                        <table class="data-table">
                            <thead><tr><th>Employee</th><th style="text-align:right;">Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($today_attendance as $ta): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ta['name']); ?></td>
                                    <td style="text-align:right;">
                                        <?php if ($ta['attendance_type'] === 'whole_day'): ?>
                                            <span class="badge badge-success">Present</span>
                                        <?php elseif ($ta['attendance_type'] === 'half_day'): ?>
                                            <span class="badge badge-warning">Half Day</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger">Absent</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">Quick Actions</h2>
                </div>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;">
                    <a href="?view=products" style="background:linear-gradient(135deg,#11998e,#38ef7d);color:#fff;padding:18px;border-radius:12px;text-align:center;text-decoration:none;font-weight:700;font-size:14px;">
                        <div style="font-size:28px;margin-bottom:6px;">&#128230;</div>Products & Stock
                    </a>
                    <a href="/oro-store-demo/products/new_product.php" style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:18px;border-radius:12px;text-align:center;text-decoration:none;font-weight:700;font-size:14px;">
                        <div style="font-size:28px;margin-bottom:6px;">&#10133;</div>Add Product
                    </a>
                    <a href="/oro-store-demo/cashier/cashier.php" style="background:linear-gradient(135deg,#4facfe,#00f2fe);color:#fff;padding:18px;border-radius:12px;text-align:center;text-decoration:none;font-weight:700;font-size:14px;">
                        <div style="font-size:28px;margin-bottom:6px;">&#128179;</div>Open Cashier
                    </a>
                    <a href="/oro-store-demo/delivery/delivery.php" style="background:linear-gradient(135deg,#f093fb,#f5576c);color:#fff;padding:18px;border-radius:12px;text-align:center;text-decoration:none;font-weight:700;font-size:14px;">
                        <div style="font-size:28px;margin-bottom:6px;">&#128666;</div>Deliveries
                    </a>
                    <a href="?view=attendance" style="background:linear-gradient(135deg,#fa709a,#fee140);color:#fff;padding:18px;border-radius:12px;text-align:center;text-decoration:none;font-weight:700;font-size:14px;">
                        <div style="font-size:28px;margin-bottom:6px;">&#128197;</div>Attendance
                    </a>
                    <a href="/oro-store-demo/credit/credit.php" style="background:linear-gradient(135deg,#a18cd1,#fbc2eb);color:#fff;padding:18px;border-radius:12px;text-align:center;text-decoration:none;font-weight:700;font-size:14px;">
                        <div style="font-size:28px;margin-bottom:6px;">&#128180;</div>Credits
                    </a>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">Recent Activity</h2>
                </div>
                <?php if (empty($recent_activities)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">&#128237;</div>
                        <p>No recent activities</p>
                    </div>
                <?php else: ?>
                    <?php foreach (array_slice($recent_activities, 0, 10) as $activity): ?>
                        <div style="display:flex;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9;">
                            <div style="width:32px;height:32px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;">
                                <?php
                                $aicons = ['product'=>'&#128230;','transaction'=>'&#128179;','system'=>'&#9881;','delivery'=>'&#128666;','credit'=>'&#128180;'];
                                echo $aicons[$activity['activity_category']] ?? '&#128221;';
                                ?>
                            </div>
                            <div style="flex:1;min-width:0;">
                                <div style="font-size:13px;color:#0f172a;">
                                    <strong><?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></strong>
                                    <?php echo htmlspecialchars($activity['description']); ?>
                                    <?php if ($activity['details']):
                                        $d = json_decode($activity['details'], true);
                                        if ($d && is_array($d)):
                                            $parts = [];
                                            if (isset($d['product_name'])) { $parts[] = htmlspecialchars($d['product_name']); }
                                            if (isset($d['old_stock'], $d['new_stock'])) { $parts[] = "Stock: {$d['old_stock']} &#8594; {$d['new_stock']}"; }
                                            if (isset($d['quantity_change'])) { $parts[] = "Qty: " . htmlspecialchars($d['quantity_change']); }
                                            if (!empty($parts)): ?>
                                                <br><small style="color:#64748b;"><?php echo implode(' &middot; ', $parts); ?></small>
                                            <?php endif; endif; endif; ?>
                                </div>
                            </div>
                            <div style="font-size:11px;color:#94a3b8;white-space:nowrap;">
                                <?php
                                $t = strtotime($activity['created_at']);
                                $df = time() - $t;
                                if ($df < 60) { echo $df . 's ago'; }
                                elseif ($df < 3600) { echo floor($df / 60) . 'm ago'; }
                                elseif ($df < 86400) { echo floor($df / 3600) . 'h ago'; }
                                else { echo date('M j, g:i A', $t); }
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        <?php elseif ($view === 'products'): ?>
            <?php if (!empty($available_products)): ?>
            <div class="content-section">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2 class="section-title" style="margin: 0;">Add Product to Store</h2>
                    <a href="/oro-store-demo/stock/add_stock.php" class="nav-btn primary">📦 Add Stocks</a>
                </div>
                <div class="search-box">
                    <input type="text" id="search-available" placeholder="🔍 Search products to add..." onkeyup="searchAvailableProducts()">
                </div>
                <table class="products-table" id="available-products-table">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>Barcode</th>
                            <th>Default Stock</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($available_products as $product): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($product['barcode']); ?></td>
                                <td><?php echo $product['stock']; ?></td>
                                <td>
                                    <button onclick='openAddProductModal(<?php echo json_encode($product); ?>)' class="btn btn-success btn-sm">
                                        ➕ Add to Store
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="content-section">
                <h2 class="section-title">Store Products (<?php echo count($store_products); ?>)</h2>
                <div class="search-box">
                    <input type="text" id="search-products" placeholder="🔍 Search products..." onkeyup="searchProducts()">
                </div>
                <table class="products-table" id="products-table">
                    <thead>
                        <tr>
                            <th>Product Name</th>
                            <th>Barcode</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($store_products as $product): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($product['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($product['barcode']); ?></td>
                                <td>
                                    <span class="stock-badge <?php
                                        if ($product['store_stock'] > 50) echo 'stock-high';
                                        elseif ($product['store_stock'] > 10) echo 'stock-medium';
                                        else echo 'stock-low';
                                    ?>">
                                        <?php echo $product['store_stock']; ?> units
                                    </span>
                                </td>
                                <td>
                                    <?php if ($product['store_stock'] <= 0): ?>
                                        <span style="color: #dc3545; font-weight: bold;">Out of Stock</span>
                                    <?php elseif ($product['store_stock'] <= 10): ?>
                                        <span style="color: #ffc107; font-weight: bold;">Low Stock</span>
                                    <?php else: ?>
                                        <span style="color: #28a745; font-weight: bold;">In Stock</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <button onclick='openStockModal(<?php echo json_encode($product); ?>)' class="btn btn-warning btn-sm">
                                            📦 Update Stock
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($view === 'attendance'): ?>
            <div class="content-section">
                <div class="section-header">
                    <h2 class="section-title">Employee Attendance - This Week</h2>
                </div>
                <p style="color: #65676b; margin-bottom: 20px;">
                    <?php echo $att_week_start->format('M j, Y'); ?> (Mon) - <?php echo $att_week_end->format('M j, Y'); ?> (Sun)
                </p>

                <?php if (empty($store_employees)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">👥</div>
                        <p>No active employees found for this store</p>
                    </div>
                <?php else: ?>
                    <?php
                    $day_labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                    $today = new DateTime();
                    $today_str = $today->format('Y-m-d');
                    ?>
                    <?php foreach ($store_employees as $emp): ?>
                        <?php
                        $full_days = 0;
                        $half_days = 0;
                        $emp_attendance = isset($att_by_emp[$emp['id']]) ? $att_by_emp[$emp['id']] : [];
                        foreach ($emp_attendance as $att_type) {
                            if ($att_type === 'present' || $att_type === 'full') {
                                $full_days++;
                            } elseif ($att_type === 'half_day' || $att_type === 'half') {
                                $half_days++;
                            }
                        }
                        ?>
                        <div style="background: #f8f9fa; border-radius: 10px; padding: 15px; margin-bottom: 15px; border: 1px solid #e4e6eb;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                                <div>
                                    <strong style="font-size: 16px;"><?php echo htmlspecialchars($emp['name']); ?></strong>
                                    <span style="color: #65676b; margin-left: 8px; font-size: 13px;"><?php echo htmlspecialchars($emp['role_name'] ?? 'Staff'); ?></span>
                                </div>
                                <div style="font-size: 13px; color: #65676b;">
                                    <?php echo $full_days; ?> full<?php if ($half_days > 0) echo " + $half_days half"; ?> day<?php echo ($full_days + $half_days) !== 1 ? 's' : ''; ?>
                                </div>
                            </div>
                            <div style="display: flex; gap: 6px;">
                                <?php
                                $day_date = clone $att_week_start;
                                for ($d = 0; $d < 7; $d++):
                                    $date_str = $day_date->format('Y-m-d');
                                    $att_type = isset($emp_attendance[$date_str]) ? $emp_attendance[$date_str] : null;

                                    if ($date_str > $today_str) {
                                        $bg = '#e4e6eb'; $color = '#65676b'; $label = '-';
                                    } elseif ($att_type === 'present' || $att_type === 'full') {
                                        $bg = '#d4edda'; $color = '#155724'; $label = 'P';
                                    } elseif ($att_type === 'half_day' || $att_type === 'half') {
                                        $bg = '#fff3cd'; $color = '#856404'; $label = 'H';
                                    } elseif ($att_type === 'absent') {
                                        $bg = '#f8d7da'; $color = '#721c24'; $label = 'A';
                                    } else {
                                        $bg = '#e4e6eb'; $color = '#65676b'; $label = '-';
                                    }
                                ?>
                                    <div style="flex: 1; text-align: center; background: <?php echo $bg; ?>; color: <?php echo $color; ?>; border-radius: 8px; padding: 8px 4px;">
                                        <div style="font-size: 11px; font-weight: 600; margin-bottom: 2px;"><?php echo $day_labels[$d]; ?></div>
                                        <div style="font-size: 10px; color: #999; margin-bottom: 4px;"><?php echo $day_date->format('M j'); ?></div>
                                        <div style="font-size: 14px; font-weight: bold;"><?php echo $label; ?></div>
                                    </div>
                                <?php
                                    $day_date->modify('+1 day');
                                endfor;
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div style="display: flex; gap: 15px; margin-top: 15px; padding: 10px; background: #f0f2f5; border-radius: 8px; font-size: 13px; color: #65676b;">
                        <span><span style="display: inline-block; width: 12px; height: 12px; background: #d4edda; border-radius: 3px; margin-right: 4px;"></span> Present</span>
                        <span><span style="display: inline-block; width: 12px; height: 12px; background: #fff3cd; border-radius: 3px; margin-right: 4px;"></span> Half Day</span>
                        <span><span style="display: inline-block; width: 12px; height: 12px; background: #f8d7da; border-radius: 3px; margin-right: 4px;"></span> Absent</span>
                        <span><span style="display: inline-block; width: 12px; height: 12px; background: #e4e6eb; border-radius: 3px; margin-right: 4px;"></span> Upcoming / No Record</span>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </main>

    <div class="modal" id="add-product-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add Product to Store</h2>
                <button class="btn-close-modal" onclick="closeAddProductModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_product_to_store">
                <input type="hidden" name="product_id" id="add-product-id">
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" id="add-product-name" disabled style="background: #f0f2f5;">
                </div>
                <div class="form-group">
                    <label>Initial Stock *</label>
                    <input type="number" name="stock" id="add-stock" min="0" required>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-success">Add Product</button>
                    <button type="button" class="btn btn-warning" onclick="closeAddProductModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal" id="stock-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Update Product Stock</h2>
                <button class="btn-close-modal" onclick="closeStockModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="update_stock">
                <input type="hidden" name="product_id" id="stock-product-id">
                <div class="form-group">
                    <label>Product Name</label>
                    <input type="text" id="stock-product-name" disabled style="background: #f0f2f5;">
                </div>
                <div class="form-group">
                    <label>Current Stock</label>
                    <input type="text" id="stock-current" disabled style="background: #f0f2f5; font-weight: bold; font-size: 16px;">
                </div>
                <div class="form-group">
                    <label>Change Type *</label>
                    <select name="change_type" id="stock-change-type" required>
                        <option value="add">➕ Add Stock</option>
                        <option value="remove">➖ Remove Stock</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity *</label>
                    <input type="number" name="stock_change" id="stock-change" min="1" required>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-success">Update Stock</button>
                    <button type="button" class="btn btn-warning" onclick="closeStockModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddProductModal(product) {
            document.getElementById('add-product-id').value = product.id;
            document.getElementById('add-product-name').value = product.name;
            document.getElementById('add-stock').value = product.stock;
            document.getElementById('add-product-modal').classList.add('active');
        }
        function closeAddProductModal() {
            document.getElementById('add-product-modal').classList.remove('active');
        }
        function openStockModal(product) {
            document.getElementById('stock-product-id').value = product.id;
            document.getElementById('stock-product-name').value = product.name;
            document.getElementById('stock-current').value = product.store_stock + ' units';
            document.getElementById('stock-change').value = '';
            document.getElementById('stock-modal').classList.add('active');
        }
        function closeStockModal() {
            document.getElementById('stock-modal').classList.remove('active');
        }
        function searchProducts() {
            const input = document.getElementById('search-products');
            const filter = input.value.toUpperCase();
            const table = document.getElementById('products-table');
            const tr = table.getElementsByTagName('tr');
            for (let i = 1; i < tr.length; i++) {
                const td = tr[i].getElementsByTagName('td');
                let found = false;
                for (let j = 0; j < td.length; j++) {
                    if (td[j]) {
                        const txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                tr[i].style.display = found ? '' : 'none';
            }
        }
        function searchAvailableProducts() {
            const input = document.getElementById('search-available');
            const filter = input.value.toUpperCase();
            const table = document.getElementById('available-products-table');
            const tr = table.getElementsByTagName('tr');
            for (let i = 1; i < tr.length; i++) {
                const td = tr[i].getElementsByTagName('td');
                let found = false;
                for (let j = 0; j < td.length; j++) {
                    if (td[j]) {
                        const txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                tr[i].style.display = found ? '' : 'none';
            }
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeStockModal();
                closeAddProductModal();
            }
        });
        document.getElementById('stock-modal').addEventListener('click', function(e) {
            if (e.target === this) closeStockModal();
        });
        document.getElementById('add-product-modal').addEventListener('click', function(e) {
            if (e.target === this) closeAddProductModal();
        });
    </script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Manager Dashboard', array (
  'Features' => 
  array (
    0 => 'Store-specific dashboard for assigned manager',
    1 => 'Sales stats, stock alerts, pending deliveries/credits',
    2 => 'Quick access to cashier, products, and reports',
    3 => 'Limited to own store data only',
  ),
));
?>
</body>
</html>