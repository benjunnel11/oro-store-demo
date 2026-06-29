<?php
date_default_timezone_set('Asia/Manila');
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

if (!isAdmin()) { header("Location: /oro-store/cashier/cashier.php"); exit; }
$currentUser = getCurrentUser();

$env_file = __DIR__ . '/../sync/.local_env';
$env = file_exists($env_file) ? (json_decode(file_get_contents($env_file), true) ?: []) : [];
$current_pass = $env['network_pass'] ?? 'OroStore2026!';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_network_pass') {
    $new_pass = $_POST['net_pass'] ?? '';
    $confirm = $_POST['net_confirm'] ?? '';

    if (!$new_pass) { $error = 'Password cannot be empty.'; }
    elseif ($new_pass !== $confirm) { $error = 'Passwords do not match.'; }
    else {
        $env['network_pass'] = $new_pass;
        if (file_put_contents($env_file, json_encode($env)) !== false) {
            $current_pass = $new_pass;
            $message = 'Network access password changed!';
        } else {
            $error = 'Failed to save password.';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Network Access Password</title>
<link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
<style>
    .cp-container{max-width:500px;margin:40px auto;padding:0 20px;}
    .cp-card{background:#fff;border-radius:10px;padding:28px;border:1px solid #e2e8f0;}
    .cp-card h1{font-size:18px;font-weight:700;color:#1e293b;margin:0 0 6px;}
    .cp-card .sub{font-size:12px;color:#94a3b8;margin-bottom:20px;}
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
    .current-info{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:13px;}
    .current-info span{font-weight:700;color:#1e293b;}
</style>
</head>
<body>
<?php include_once __DIR__ . '/admin_sidebar.php'; ?>
<main class="main-content">
<div class="cp-container">
    <div class="cp-card">
        <h1>🌐 Network Access Password</h1>
        <p class="sub">This is the password shown on the "Oro Store" gate when accessing from the local network (tablets, phones).</p>

        <?php if ($message): ?><div class="msg msg-ok"><?php echo $message; ?></div><?php endif; ?>
        <?php if ($error): ?><div class="msg msg-err"><?php echo $error; ?></div><?php endif; ?>

        <div class="current-info">
            Current password: <span id="cur-pass" style="filter:blur(4px);cursor:pointer;" onclick="this.style.filter=this.style.filter?'':'blur(4px)'"><?php echo htmlspecialchars($current_pass); ?></span>
            <span style="font-size:10px;color:#94a3b8;margin-left:6px;">(click to reveal)</span>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="change_network_pass">
            <div class="field">
                <label>New Password</label>
                <div class="pw-wrap">
                    <input type="password" name="net_pass" id="np1" required>
                    <button type="button" class="pw-eye" onclick="var i=document.getElementById('np1');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁':'🙈';">👁</button>
                </div>
            </div>
            <div class="field">
                <label>Confirm Password</label>
                <div class="pw-wrap">
                    <input type="password" name="net_confirm" id="np2" required>
                    <button type="button" class="pw-eye" onclick="var i=document.getElementById('np2');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'👁':'🙈';">👁</button>
                </div>
            </div>
            <button type="submit" class="btn-save">Change Password</button>
        </form>
    </div>
</div>
</main>
</body>
</html>
