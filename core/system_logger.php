<?php
/**
 * system_logger.php - Centralized logging system
 * Updated to support both old and new logging methods
 */

/**
 * NEW: Simple logging function (used by new code)
 * 
 * @param string $category Activity category (auth, product, transaction, user, store, system)
 * @param string $description Activity description
 * @param int|null $user_id User ID who performed the action
 * @param int|null $store_id Store ID where action occurred
 * @param array $details Additional details (will be stored as JSON)
 * @return bool Success status
 */
function logActivity($category, $description, $user_id = null, $store_id = null, $details = []) {
    global $conn;
    
    try {
        // Get IP address
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        
        // Get user agent
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        
        // Convert details to JSON
        $details_json = !empty($details) ? json_encode($details) : null;
        
        // Map category to activity_type for backward compatibility
        $activity_type_map = [
            'auth' => 'login',
            'product' => 'product_change',
            'transaction' => 'transaction',
            'user' => 'user_edit',
            'store' => 'store_change',
            'system' => 'system'
        ];
        
        $activity_type = $activity_type_map[$category] ?? 'other';
        
        // Prepare SQL
        $stmt = $conn->prepare("
            INSERT INTO system_logs 
            (user_id, store_id, activity_type, activity_category, description, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        if (!$stmt) {
            error_log("Failed to prepare statement: " . $conn->error);
            return false;
        }
        
        // Bind parameters
        $stmt->bind_param(
            'iissssss',
            $user_id,
            $store_id,
            $activity_type,
            $category,
            $description,
            $details_json,
            $ip_address,
            $user_agent
        );
        
        // Execute
        $result = $stmt->execute();
        
        if (!$result) {
            error_log("Failed to log activity: " . $stmt->error);
        }
        
        $stmt->close();
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Exception in logActivity: " . $e->getMessage());
        return false;
    }
}

/**
 * OLD: Original logging function (kept for backward compatibility)
 */
function logSystemActivity($user_id, $store_id, $activity_type, $activity_category, $description, $details = null) {
    global $conn;
    
    // Get IP address
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    
    // Get user agent
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    // Convert details to JSON if it's an array
    $details_json = $details ? json_encode($details) : null;
    
    $stmt = $conn->prepare("INSERT INTO system_logs 
                           (user_id, store_id, activity_type, activity_category, description, details, ip_address, user_agent) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("iissssss", $user_id, $store_id, $activity_type, $activity_category, $description, $details_json, $ip_address, $user_agent);
    return $stmt->execute();
}

// ============================================================================
// SPECIFIC LOGGING FUNCTIONS (Original - kept for backward compatibility)
// ============================================================================

function logLogin($user_id, $username, $success = true) {
    global $conn;
    $description = $success ? "User '{$username}' logged in successfully" : "Failed login attempt for '{$username}'";
    $details = ['username' => $username, 'success' => $success];
    logSystemActivity($user_id, null, 'login', 'auth', $description, $details);
}

function logLogout($user_id, $username) {
    global $conn;
    $description = "User '{$username}' logged out";
    $details = ['username' => $username];
    logSystemActivity($user_id, null, 'logout', 'auth', $description, $details);
}

function logProductChange($user_id, $store_id, $product_id, $product_name, $change_type, $old_value, $new_value) {
    global $conn;
    $description = "Product '{$product_name}' {$change_type} changed from {$old_value} to {$new_value}";
    $details = [
        'product_id' => $product_id,
        'product_name' => $product_name,
        'change_type' => $change_type,
        'old_value' => $old_value,
        'new_value' => $new_value
    ];
    
    $activity_type = $change_type === 'price' ? 'price_change' : 'stock_change';
    logSystemActivity($user_id, $store_id, $activity_type, 'product', $description, $details);
}

function logTransaction($user_id, $store_id, $transaction_id, $amount, $payment_method) {
    global $conn;
    $description = "Transaction #{$transaction_id} completed - ₱" . number_format($amount, 2) . " via {$payment_method}";
    $details = [
        'transaction_id' => $transaction_id,
        'amount' => $amount,
        'payment_method' => $payment_method
    ];
    logSystemActivity($user_id, $store_id, 'transaction', 'transaction', $description, $details);
}

function logUserAction($admin_id, $action, $target_user_id, $target_username, $details = []) {
    global $conn;
    $actions = [
        'add' => 'added',
        'edit' => 'updated',
        'delete' => 'deleted',
        'activate' => 'activated',
        'deactivate' => 'deactivated',
        'reset_password' => 'reset password for'
    ];
    
    $action_text = $actions[$action] ?? $action;
    $description = "User '{$target_username}' was {$action_text}";
    $details['target_user_id'] = $target_user_id;
    $details['target_username'] = $target_username;
    $details['action'] = $action;
    
    $activity_type = 'user_' . ($action === 'add' ? 'add' : ($action === 'delete' ? 'delete' : 'edit'));
    logSystemActivity($admin_id, null, $activity_type, 'user', $description, $details);
}

// ============================================================================
// NEW: SIMPLIFIED HELPER FUNCTIONS
// ============================================================================

/**
 * Log authentication activity
 */
function logAuth($description, $user_id = null, $details = []) {
    return logActivity('auth', $description, $user_id, null, $details);
}

/**
 * Log product activity
 */
function logProduct($description, $user_id = null, $store_id = null, $details = []) {
    return logActivity('product', $description, $user_id, $store_id, $details);
}

/**
 * Log transaction activity (new style)
 */
function logTransactionActivity($description, $user_id = null, $store_id = null, $details = []) {
    return logActivity('transaction', $description, $user_id, $store_id, $details);
}

/**
 * Log user management activity
 */
function logUser($description, $user_id = null, $details = []) {
    return logActivity('user', $description, $user_id, null, $details);
}

/**
 * Log store management activity
 */
function logStore($description, $user_id = null, $store_id = null, $details = []) {
    return logActivity('store', $description, $user_id, $store_id, $details);
}

/**
 * Log system activity
 */
function logSystem($description, $details = []) {
    return logActivity('system', $description, null, null, $details);
}

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Get recent activity logs
 */
function getRecentActivity($limit = 50, $category = null, $date = null) {
    global $conn;
    
    $sql = "SELECT sl.*, u.username, u.full_name, s.store_name, s.store_code
            FROM system_logs sl
            LEFT JOIN users u ON sl.user_id = u.id
            LEFT JOIN stores s ON sl.store_id = s.id
            WHERE 1=1";
    
    $params = [];
    $types = '';
    
    if ($category) {
        $sql .= " AND sl.activity_category = ?";
        $params[] = $category;
        $types .= 's';
    }
    
    if ($date) {
        $sql .= " AND DATE(sl.created_at) = ?";
        $params[] = $date;
        $types .= 's';
    }
    
    $sql .= " ORDER BY sl.created_at DESC LIMIT ?";
    $params[] = $limit;
    $types .= 'i';
    
    $stmt = $conn->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $logs = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $logs;
}

/**
 * Clean old logs (optional - for maintenance)
 */
function cleanOldLogs($days = 90) {
    global $conn;
    
    $stmt = $conn->prepare("
        DELETE FROM system_logs 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ");
    
    $stmt->bind_param('i', $days);
    $result = $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    
    if ($result) {
        logSystem("Cleaned $affected old log entries (older than $days days)");
    }
    
    return $affected;
}

/**
 * Get activity statistics
 */
function getActivityStats($date = null) {
    global $conn;
    
    $date = $date ?? date('Y-m-d');
    
    $stmt = $conn->prepare("
        SELECT 
            activity_category,
            COUNT(*) as count
        FROM system_logs
        WHERE DATE(created_at) = ?
        GROUP BY activity_category
    ");
    
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = [];
    
    while ($row = $result->fetch_assoc()) {
        $stats[$row['activity_category']] = $row['count'];
    }
    
    $stmt->close();
    
    return $stats;
}
?>