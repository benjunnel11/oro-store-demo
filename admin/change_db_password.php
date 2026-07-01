<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

if (!isAdmin()) { header("Location: /oro-store-demo/cashier/cashier.php"); exit; }
$currentUser = getCurrentUser();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'change_db_pass') {
        $current = $_POST['current_pass'] ?? '';
        $new_pass = $_POST['new_pass'] ?? '';
        $confirm = $_POST['confirm_pass'] ?? '';

        if (!$new_pass) { $error = 'New password cannot be empty.'; }
        elseif ($new_pass !== $confirm) { $error = 'New passwords do not match.'; }
        else {
            // Verify current password works
            $test = @new mysqli('127.0.0.1', 'root', $current);
            if ($test->connect_error) {
                $error = 'Current password is incorrect.';
            } else {
                // Change the password
                $escaped = $test->real_escape_string($new_pass);
                $r = $test->query("ALTER USER 'root'@'localhost' IDENTIFIED BY '$escaped'");
                if (!$r) {
                    // Try MariaDB syntax
                    $r = $test->query("SET PASSWORD FOR 'root'@'localhost' = PASSWORD('$escaped')");
                }
                $test->query("FLUSH PRIVILEGES");
                $test->close();

                if ($r) {
                    // Update .local_env
                    $env_file = __DIR__ . '/../sync/.local_env';
                    $env = file_exists($env_file) ? (json_decode(file_get_contents($env_file), true) ?: []) : [];
                    $env['db_pass'] = $new_pass;
                    file_put_contents($env_file, json_encode($env));
                    $message = 'Password changed successfully! The system will use the new password.';
                } else {
                    $error = 'Failed to change password. Try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Change Database Password</title>
<link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
<style>
    .cp-container{max-width:500px;margin:40px auto;padding:0 20px;}
    .cp-card{background:#fff;border-radius:10px;padding:28px;border:1px solid #e2e8f0;}
    .cp-card h1{font-size:18px;font-weight:700;color:#1e293b;margin:0 0 20px;}
    .field{margin-bottom:14px;}
    .field label{display:block;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
    .field input{width:100%;padding:10px 12px;border:1px solid #e2e8f0;border-radius:6px;font-size:14px;outline:none;}
    .field input:focus{border-color:#667eea;}
    .btn-save{width:100%;padding:12px;background:#667eea;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;}
    .btn-save:hover{opacity:.9;}
    .msg{padding:10px 14px;border-radius:6px;margin-bottom:16px;font-size:13px;font-weight:600;}
    .msg-ok{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0;}
    .msg-err{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;}
    .pw-wrap{position:relative;}
    .pw-wrap input{padding-right:36px;}
    .pw-eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:16px;color:#94a3b8;}
</style>
</head>
<body>
<?php include_once __DIR__ . '/admin_sidebar.php'; ?>
<main class="main-content">
<div class="cp-container">
    <div class="cp-card">
        <h1>🔒 Change Database Password</h1>

        <?php if ($message): ?><div class="msg msg-ok"><?php echo $message; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="msg msg-err"><?php echo $error; ?></div><?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="change_db_pass">
            <div class="field">
                <label>Current Password</label>
                <div class="pw-wrap">
                    <input type="password" name="current_pass" id="cp1" required>
                    <button type="button" class="pw-eye" onclick="var i=document.getElementById('cp1');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁':'🙈';">👁</button>
                </div>
            </div>
            <div class="field">
                <label>New Password</label>
                <div class="pw-wrap">
                    <input type="password" name="new_pass" id="cp2" required>
                    <button type="button" class="pw-eye" onclick="var i=document.getElementById('cp2');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁':'🙈';">👁</button>
                </div>
            </div>
            <div class="field">
                <label>Confirm New Password</label>
                <div class="pw-wrap">
                    <input type="password" name="confirm_pass" id="cp3" required>
                    <button type="button" class="pw-eye" onclick="var i=document.getElementById('cp3');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁':'🙈';">👁</button>
                </div>
            </div>
            <button type="submit" class="btn-save">Change Password</button>
        </form>
    </div>
</div>
</main>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Change DB Password', array (
  'Features' => 
  array (
    0 => 'Change the MySQL database password from browser',
    1 => 'Updates both MySQL user and the .local_env config file',
    2 => 'Requires current password verification',
  ),
));
?>
</body>
</html>
