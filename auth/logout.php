<?php
session_start();
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/system_logger.php';
require_once __DIR__ . '/../sync/sync_helper.php';  // ✅ ADD THIS

// ✅ ADD THIS: Initialize SyncDB
$db = new SyncDB();

// Check if a session exists before logging the logout
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    $user_id = $_SESSION['user_id'];
    $username = $_SESSION['username'];
    $full_name = $_SESSION['full_name'] ?? $username;
    $store_id = $_SESSION['store_id'] ?? null;
    $session_id = session_id();
    
    // ✅ CHANGE: Log logout with more details
    logActivity('auth', "User logged out: $full_name ($username)", 
        $user_id, $store_id, 
        [
            'username' => $username,
            'full_name' => $full_name,
            'store_id' => $store_id,
            'session_id' => $session_id,
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT']
        ]
    );
    
    // ✅ ADD: Update session record to mark as logged out
    $db->update('user_sessions', [
        'logout_time' => date('Y-m-d H:i:s'),
        'status' => 'logged_out'
    ], "session_id = '$session_id' AND user_id = $user_id");
}

// Clear all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session on the server
session_destroy();

// Close the database connection
if ($conn) {
    $conn->close();
}

// Redirect to login page
header("Location: /oro-store/auth/login.php");
exit;
?>