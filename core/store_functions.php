<?php
// Store-related helper functions

function getUserStore($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT s.* FROM stores s 
                           INNER JOIN users u ON s.id = u.store_id 
                           WHERE u.id = ? AND s.status = 'active'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

function getStorePrice($product_id, $store_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM store_prices WHERE product_id = ? AND store_id = ?");
    $stmt->bind_param("ii", $product_id, $store_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $store_price = $result->fetch_assoc();
    
    // If no store-specific price, return default product price
    if (!$store_price) {
        $stmt = $conn->prepare("SELECT price, purchase_price, stock FROM products WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    return $store_price;
}

function updateStorePrice($product_id, $store_id, $price, $purchase_price, $stock) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO store_prices (product_id, store_id, price, purchase_price, stock) 
                           VALUES (?, ?, ?, ?, ?) 
                           ON DUPLICATE KEY UPDATE 
                           price = VALUES(price), 
                           purchase_price = VALUES(purchase_price), 
                           stock = VALUES(stock)");
    $stmt->bind_param("iiddi", $product_id, $store_id, $price, $purchase_price, $stock);
    return $stmt->execute();
}

function logProductHistory($product_id, $store_id, $user_id, $action_type, $field_changed, $old_value, $new_value, $description = null) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO product_history 
                           (product_id, store_id, user_id, action_type, field_changed, old_value, new_value, description) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iiisssss", $product_id, $store_id, $user_id, $action_type, $field_changed, $old_value, $new_value, $description);
    return $stmt->execute();
}

function getStoreProducts($store_id) {
    global $conn;
    $query = "SELECT p.*, 
              COALESCE(sp.price, p.price) as store_price,
              COALESCE(sp.purchase_price, p.purchase_price) as store_purchase_price,
              COALESCE(sp.stock, p.stock) as store_stock,
              sp.id as store_price_id
              FROM products p
              LEFT JOIN store_prices sp ON p.id = sp.product_id AND sp.store_id = ?
              ORDER BY p.name";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $store_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getStoreStats($store_id) {
    global $conn;
    $stats = [];
    
    // Today's revenue
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total 
                           FROM transactions 
                           WHERE store_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'completed'");
    $stmt->bind_param("i", $store_id);
    $stmt->execute();
    $stats['today_revenue'] = $stmt->get_result()->fetch_assoc()['total'];
    
    // Today's transactions
    $stmt = $conn->prepare("SELECT COUNT(*) as count 
                           FROM transactions 
                           WHERE store_id = ? AND DATE(transaction_date) = CURDATE() AND status = 'completed'");
    $stmt->bind_param("i", $store_id);
    $stmt->execute();
    $stats['today_transactions'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // Total revenue
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total 
                           FROM transactions 
                           WHERE store_id = ? AND status = 'completed'");
    $stmt->bind_param("i", $store_id);
    $stmt->execute();
    $stats['total_revenue'] = $stmt->get_result()->fetch_assoc()['total'];
    
    // Total products
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT product_id) as count FROM store_prices WHERE store_id = ?");
    $stmt->bind_param("i", $store_id);
    $stmt->execute();
    $stats['total_products'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // GCash transactions
    $stmt = $conn->prepare("SELECT COUNT(*) as count 
                           FROM gcash_transactions 
                           WHERE store_id = ? AND status = 'completed'");
    $stmt->bind_param("i", $store_id);
    $stmt->execute();
    $stats['gcash_transactions'] = $stmt->get_result()->fetch_assoc()['count'];
    
    return $stats;
}
?>