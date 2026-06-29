<?php
require_once __DIR__ . '/core/network_auth.php';
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';
    if (in_array($role, ['admin', 'super_admin'])) {
        header("Location: /oro-store/admin/admin_panel.php");
    } elseif ($role === 'manager') {
        header("Location: /oro-store/manager/manager_panel.php");
    } else {
        header("Location: /oro-store/cashier/cashier.php");
    }
} else {
    header("Location: /oro-store/auth/login.php");
}
exit;
