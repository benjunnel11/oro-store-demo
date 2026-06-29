<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Demo mode: auto-login as super admin if no session
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['username'] = 'demo_admin';
    $_SESSION['full_name'] = 'Demo Admin';
    $_SESSION['role'] = 'super_admin';
    $_SESSION['store_id'] = 1;
    $_SESSION['store_name'] = 'Demo Store';
    $_SESSION['store_code'] = 'DEMO';
}
$_SESSION['last_activity'] = time();

function getCurrentUser() {
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? 'demo_admin',
        'full_name' => $_SESSION['full_name'] ?? 'Demo Admin',
        'role' => $_SESSION['role'] ?? 'super_admin',
        'store_id' => $_SESSION['store_id'] ?? 1,
        'store_name' => $_SESSION['store_name'] ?? 'Demo Store'
    ];
}

function isAdmin() { return true; }
function isSuperAdmin() { return true; }
function isManager() { return false; }
function isKiosk() { return false; }
function isOnOwnDevice() { return true; }

function getUserIP() {
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function logProductHistory($conn, $product_id, $change_type, $old_value, $new_value, $user_id, $user_name, $store_id, $details = '') {
    $ip = getUserIP();
    $stmt = $conn->prepare("INSERT INTO product_history (product_id, store_id, change_type, old_value, new_value, user_id, user_name, change_details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) { $stmt->bind_param("iisssssss", $product_id, $store_id, $change_type, $old_value, $new_value, $user_id, $user_name, $details, $ip); $stmt->execute(); $stmt->close(); }
}
?>