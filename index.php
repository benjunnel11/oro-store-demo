<?php
require_once __DIR__ . '/core/network_auth.php';
require_once __DIR__ . '/core/auth_check.php';

$role = $_SESSION['role'] ?? 'super_admin';
if (in_array($role, ['admin', 'super_admin'])) {
    header("Location: /oro-store-demo/admin/admin_panel.php");
} elseif ($role === 'manager') {
    header("Location: /oro-store-demo/manager/manager_panel.php");
} else {
    header("Location: /oro-store-demo/cashier/cashier.php");
}
exit;