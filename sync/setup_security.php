<?php
/**
 * Security Setup Script - Sets MySQL root password and updates config
 *
 * WARNING: Run this ONCE. After setting the password, MySQL will require it for all connections.
 * Make sure XAMPP is running before executing this script.
 */
require_once __DIR__ . '/../core/auth_check.php';
if (!isSuperAdmin()) {
    header("Location: /oro-store/admin/admin_panel.php");
    exit;
}

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'set_mysql_password') {
        $new_password = trim($_POST['new_password'] ?? '');

        if (empty($new_password)) {
            $error = "Password cannot be empty.";
        } elseif (strlen($new_password) < 8) {
            $error = "Password must be at least 8 characters.";
        } else {
            // Connect with current credentials
            require_once __DIR__ . '/../core/db_config.php';
            $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

            if ($conn->connect_error) {
                $error = "Cannot connect to MySQL: " . $conn->connect_error;
            } else {
                // Set the new password
                $escaped = $conn->real_escape_string($new_password);

                // Try MySQL 5.7+ syntax first, fall back to older syntax
                $success = $conn->query("ALTER USER 'root'@'localhost' IDENTIFIED BY '$escaped'");
                if (!$success) {
                    $success = $conn->query("SET PASSWORD FOR 'root'@'localhost' = PASSWORD('$escaped')");
                }

                if ($success) {
                    $conn->query("FLUSH PRIVILEGES");

                    // Update db_config.php with the new password
                    $config_path = __DIR__ . '/../core/db_config.php';
                    $config_content = "<?php\n// Database configuration - Change credentials here\nif (!defined('DB_HOST')) define('DB_HOST', '127.0.0.1');\nif (!defined('DB_USER')) define('DB_USER', 'root');\nif (!defined('DB_PASS')) define('DB_PASS', '" . addslashes($new_password) . "');\nif (!defined('DB_NAME')) define('DB_NAME', 'product_db');\n";

                    if (file_put_contents($config_path, $config_content)) {
                        $result = "MySQL root password has been set and db_config.php has been updated. All connections will now use the new password.";
                    } else {
                        $error = "MySQL password was set, but could not update db_config.php. Manually set DB_PASS in core/db_config.php to: " . htmlspecialchars($new_password);
                    }
                } else {
                    $error = "Failed to set MySQL password: " . $conn->error;
                }
                $conn->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Setup - Oro Store</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; display: flex; justify-content: center; align-items: center; }
        .container { background: #1e293b; padding: 32px; border-radius: 16px; max-width: 500px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.5); }
        h1 { font-size: 22px; margin-bottom: 8px; color: #f1f5f9; }
        .subtitle { font-size: 13px; color: #94a3b8; margin-bottom: 24px; }
        .warning { background: #451a03; border: 1px solid #f59e0b; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 12px; color: #fbbf24; }
        .success { background: #052e16; border: 1px solid #22c55e; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: #4ade80; }
        .error { background: #450a0a; border: 1px solid #ef4444; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: #fca5a5; }
        .form-group { margin-bottom: 16px; }
        label { display: block; font-size: 12px; font-weight: 600; color: #94a3b8; margin-bottom: 6px; }
        input[type="password"], input[type="text"] { width: 100%; padding: 10px 14px; border: 1px solid #334155; background: #0f172a; color: #e2e8f0; border-radius: 8px; font-size: 14px; }
        input:focus { outline: none; border-color: #6366f1; }
        .btn { width: 100%; padding: 12px; background: #dc2626; color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 700; cursor: pointer; margin-top: 8px; }
        .btn:hover { background: #b91c1c; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .info { background: #0c4a6e; border: 1px solid #0284c7; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 12px; color: #7dd3fc; }
        .back-link { display: block; text-align: center; margin-top: 16px; color: #6366f1; text-decoration: none; font-size: 13px; }
        .back-link:hover { color: #818cf8; }
        .current-status { background: #1a2332; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 12px; }
        .status-row { display: flex; justify-content: space-between; padding: 4px 0; }
        .status-label { color: #64748b; }
        .status-val { color: #e2e8f0; font-weight: 600; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <h1>MySQL Security Setup</h1>
        <p class="subtitle">Set a password for the MySQL root account</p>

        <?php
        // Show current connection status
        require_once __DIR__ . '/../core/db_config.php';
        $test_conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $connected = !$test_conn->connect_error;
        $has_password = !empty(DB_PASS);
        if ($connected) $test_conn->close();
        ?>

        <div class="current-status">
            <div class="status-row">
                <span class="status-label">MySQL Connection</span>
                <span class="status-val" style="color:<?php echo $connected ? '#22c55e' : '#ef4444'; ?>;"><?php echo $connected ? 'Connected' : 'Failed'; ?></span>
            </div>
            <div class="status-row">
                <span class="status-label">Root Password</span>
                <span class="status-val" style="color:<?php echo $has_password ? '#22c55e' : '#f59e0b'; ?>;"><?php echo $has_password ? 'Set' : 'Not Set (default)'; ?></span>
            </div>
        </div>

        <?php if ($result): ?>
            <div class="success"><?php echo htmlspecialchars($result); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="warning">
            <strong>Important:</strong> After setting a MySQL password, all database connections will use the new password automatically (via db_config.php). If you forget the password, you will need to reset it through MySQL command line. Write it down somewhere safe.
        </div>

        <?php if (!$has_password): ?>
        <div class="info">
            <strong>Recommended:</strong> The MySQL root account currently has no password. Anyone on your network could potentially access your database directly. Setting a password is strongly recommended.
        </div>
        <?php endif; ?>

        <form method="POST" onsubmit="return confirm('Are you sure you want to set/change the MySQL root password? Make sure you have noted the new password.');">
            <input type="hidden" name="action" value="set_mysql_password">
            <div class="form-group">
                <label>New MySQL Root Password</label>
                <input type="password" name="new_password" required minlength="8" placeholder="Enter a strong password (min 8 chars)" autocomplete="new-password">
            </div>
            <button type="submit" class="btn">Set MySQL Password</button>
        </form>

        <a href="/oro-store/admin/connection.php" class="back-link">Back to Connection Manager</a>
    </div>
</body>
</html>
