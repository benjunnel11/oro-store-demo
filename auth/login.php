<?php
require_once __DIR__ . '/../core/network_auth.php';
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/system_logger.php';
require_once __DIR__ . '/../sync/sync_helper.php';  // ✅ ADD THIS

// ✅ ADD THIS: Initialize SyncDB
$db = new SyncDB();

// --- Rate Limiting: 5 attempts, 15 minute lockout ---
$rate_limit_dir = sys_get_temp_dir() . '/oro_login_attempts';
if (!is_dir($rate_limit_dir)) @mkdir($rate_limit_dir, 0777, true);

$client_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rate_file = $rate_limit_dir . '/' . md5($client_ip) . '.json';
$max_attempts = 5;
$lockout_seconds = 900; // 15 minutes
$rate_limited = false;
$lockout_remaining = 0;

if (file_exists($rate_file)) {
    $rate_data = json_decode(file_get_contents($rate_file), true);
    if ($rate_data && isset($rate_data['locked_until']) && time() < $rate_data['locked_until']) {
        $rate_limited = true;
        $lockout_remaining = ceil(($rate_data['locked_until'] - time()) / 60);
    } elseif ($rate_data && isset($rate_data['locked_until']) && time() >= $rate_data['locked_until']) {
        // Lockout expired, reset
        @unlink($rate_file);
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($rate_limited) {
        $error = "Too many failed attempts. Try again in $lockout_remaining minute" . ($lockout_remaining !== 1 ? 's' : '') . ".";
    } else {
    $username = $_POST['username'];
    $password = $_POST['password'];
    
    // Join with stores table to get extra info
    $stmt = $conn->prepare("
        SELECT u.*, s.store_name, s.store_code 
        FROM users u 
        LEFT JOIN stores s ON u.store_id = s.id 
        WHERE u.username = ? AND u.is_deleted = 0
    ");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Check if account is active
        if (isset($user['status']) && $user['status'] !== 'active') {
            // ✅ CHANGE: Use new logActivity function
            logActivity('auth', "Failed login attempt for deactivated account: $username", 
                $user['id'], null, 
                [
                    'username' => $username,
                    'reason' => 'account_deactivated',
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ]
            );
            
            $error = "Your account has been deactivated. Please contact administrator.";
            
        } elseif (password_verify($password, $user['password'])) {
            // Login successful - set all session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['store_id'] = $user['store_id'];
            $_SESSION['store_name'] = $user['store_name'] ?? 'No Store';
            $_SESSION['store_code'] = $user['store_code'] ?? 'N/A';
            
            // ✅ CHANGE: Update last login using SyncDB
            $db->update('users', [
                'last_login' => date('Y-m-d H:i:s')
            ], "id = {$user['id']}");
            
            // ✅ CHANGE: Log successful login with more details
            logActivity('auth', "User logged in: {$user['full_name']} ({$username})", 
                $user['id'], $user['store_id'], 
                [
                    'username' => $username,
                    'full_name' => $user['full_name'],
                    'role' => $user['role'],
                    'store_id' => $user['store_id'],
                    'store_name' => $user['store_name'] ?? 'No Store',
                    'ip_address' => $_SERVER['REMOTE_ADDR'],
                    'user_agent' => $_SERVER['HTTP_USER_AGENT']
                ]
            );
            
            // ✅ ADD: Create login session record (for tracking active sessions)
            $session_id = session_id();
            $db->insert('user_sessions', [
                'user_id' => $user['id'],
                'session_id' => $session_id,
                'login_time' => date('Y-m-d H:i:s'),
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                'status' => 'active'
            ]);
            
            // Rate limit: clear on successful login
            if (file_exists($rate_file)) @unlink($rate_file);

            // Redirect based on role
            if ($user['role'] === 'admin' || $user['role'] === 'super_admin') {
                header("Location: /oro-store-demo/admin/admin_panel.php");
            } elseif ($user['role'] === 'manager') {
                header("Location: /oro-store-demo/manager/manager_panel.php");
            } elseif ($user['role'] === 'kiosk') {
                header("Location: /oro-store-demo/kiosk/kiosk.php");
            } else {
                header("Location: /oro-store-demo/cashier/cashier.php");
            }
            exit;

        } else {
            // ✅ CHANGE: Log failed login attempt with more details
            logActivity('auth', "Failed login attempt for username: $username", 
                null, null, 
                [
                    'username' => $username,
                    'reason' => 'invalid_password',
                    'ip_address' => $_SERVER['REMOTE_ADDR'],
                    'user_agent' => $_SERVER['HTTP_USER_AGENT']
                ]
            );
            
            $error = "Invalid username or password";
        }
    } else {
        // ✅ CHANGE: Log failed login attempt (user not found)
        logActivity('auth', "Failed login attempt for non-existent username: $username", 
            null, null, 
            [
                'username' => $username,
                'reason' => 'user_not_found',
                'ip_address' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]
        );
        
        $error = "Invalid username or password";
    }
    $stmt->close();

    // Rate limit: track failed attempts
    if (isset($error)) {
        $rate_data = ['attempts' => 1, 'first_attempt' => time()];
        if (file_exists($rate_file)) {
            $existing = json_decode(file_get_contents($rate_file), true);
            if ($existing) {
                $rate_data['attempts'] = ($existing['attempts'] ?? 0) + 1;
                $rate_data['first_attempt'] = $existing['first_attempt'] ?? time();
            }
        }
        if ($rate_data['attempts'] >= $max_attempts) {
            $rate_data['locked_until'] = time() + $lockout_seconds;
        }
        @file_put_contents($rate_file, json_encode($rate_data));
    }
    } // end rate-limit else
}
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Login - Oro Store</title>
    <?php include __DIR__ . '/../core/pwa.php'; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex; justify-content: center; align-items: center;
        }
        .login-container {
            background: white; padding: 40px; border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3); width: 100%; max-width: 400px;
        }
        .login-header { text-align: center; margin-bottom: 30px; }
        .login-header h1 { font-size: 32px; color: #333; margin-bottom: 10px; }
        .login-header p { color: #666; font-size: 14px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #333; font-weight: 600; }
        .form-group input {
            width: 100%; padding: 12px 15px; border: 2px solid #e0e0e0;
            border-radius: 10px; font-size: 16px; transition: border-color 0.3s;
        }
        .form-group input:focus { outline: none; border-color: #667eea; }
        .error-message {
            background-color: #fee; color: #c33; padding: 12px;
            border-radius: 8px; margin-bottom: 20px; font-size: 14px;
        }
        .btn-login {
            width: 100%; padding: 15px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; border: none; border-radius: 10px; font-size: 16px;
            font-weight: bold; cursor: pointer; transition: transform 0.2s;
        }
        .btn-login:hover { transform: translateY(-2px); }
        .login-footer { text-align: center; margin-top: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>🏪 Oro Store</h1>
            <p>Point of Sale System</p>
        </div>

        <?php if (isset($_GET['timeout']) && $_GET['timeout'] == '1'): ?>
            <div class="error-message">Your session has expired due to inactivity. Please log in again.</div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($rate_limited && !isset($error)): ?>
            <div class="error-message">Too many failed attempts. Try again in <?php echo $lockout_remaining; ?> minute<?php echo $lockout_remaining !== 1 ? 's' : ''; ?>.</div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required autofocus>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>

            <button type="submit" class="btn-login">Login</button>
        </form>

        <div style="margin-top:20px;background:#f0f4ff;border:1px solid #c7d2fe;border-radius:12px;padding:16px;text-align:left;">
            <div style="font-size:11px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;text-align:center;">Demo Credentials</div>
            <div style="font-size:12px;color:#475569;line-height:2;">
                <div style="display:flex;justify-content:space-between;padding:4px 8px;background:#fff;border-radius:6px;margin-bottom:4px;cursor:pointer;transition:background .15s;" onclick="fillLogin('demo_admin')">
                    <span><strong style="color:#7c3aed;">Super Admin</strong></span>
                    <span style="font-family:monospace;color:#334155;">demo_admin</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:4px 8px;background:#fff;border-radius:6px;margin-bottom:4px;cursor:pointer;transition:background .15s;" onclick="fillLogin('demo_manager')">
                    <span><strong style="color:#2563eb;">Manager</strong></span>
                    <span style="font-family:monospace;color:#334155;">demo_manager</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:4px 8px;background:#fff;border-radius:6px;margin-bottom:4px;cursor:pointer;transition:background .15s;" onclick="fillLogin('demo_cashier')">
                    <span><strong style="color:#16a34a;">Cashier</strong></span>
                    <span style="font-family:monospace;color:#334155;">demo_cashier</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:4px 8px;background:#fff;border-radius:6px;cursor:pointer;transition:background .15s;" onclick="fillLogin('demo_kiosk')">
                    <span><strong style="color:#ea580c;">Kiosk</strong></span>
                    <span style="font-family:monospace;color:#334155;">demo_kiosk</span>
                </div>
            </div>
            <div style="text-align:center;margin-top:8px;font-size:11px;color:#94a3b8;">Password for all: <strong style="color:#334155;">demo123</strong> &mdash; Click a role to auto-fill</div>
        </div>

        <div class="login-footer">
            <p>&copy; 2026 Oro Store. All rights reserved.</p>
        </div>
    </div>
    <script>
    function fillLogin(username) {
        document.querySelector('input[name="username"]').value = username;
        document.querySelector('input[name="password"]').value = 'demo123';
    }
    </script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Login', array (
  'Features' => 
  array (
    0 => 'Username + password authentication',
    1 => 'Rate limiting: 5 failed attempts = 15 minute lockout',
    2 => 'Role-based redirect after login (admin/manager/cashier/kiosk)',
    3 => 'Session timeout: 30 min for admin, 3 hours for cashier',
  ),
));
?>
</body>
</html>