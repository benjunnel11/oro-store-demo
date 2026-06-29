<?php
// Secret exit page - kills Edge and restarts Explorer
if (isset($_POST['exit_kiosk'])) {
    // Kill Edge
    exec('taskkill /F /IM msedge.exe');
    
    // Start Explorer (normal Windows)
    exec('start explorer.exe');
    
    echo "Kiosk mode ended. Close this window.";
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Exit</title>
</head>
<body>
    <h1>Exit Kiosk Mode</h1>
    <form method="POST">
        <input type="password" name="admin_password" placeholder="Admin Password" required>
        <button type="submit" name="exit_kiosk">Exit Kiosk</button>
    </form>
</body>
</html>