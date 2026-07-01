<?php
require_once __DIR__ . '/../core/db_connection.php';

// Update all employee/payroll related activities to use 'payroll' category
$queries = [
    "UPDATE system_logs SET activity_type = 'payroll' WHERE description LIKE '%attendance%'",
    "UPDATE system_logs SET activity_type = 'payroll' WHERE description LIKE '%employee%'",
    "UPDATE system_logs SET activity_type = 'payroll' WHERE description LIKE '%cash advance%'",
    "UPDATE system_logs SET activity_type = 'payroll' WHERE description LIKE '%bonus%' AND description LIKE '%deduction%'",
];

$total = 0;
foreach ($queries as $query) {
    $conn->query($query);
    $total += $conn->affected_rows;
}

$conn->close();

echo "✓ Fixed $total activity logs - they now have the 💼 PAYROLL badge!<br><br>";
echo "<a href='/oro-store-demo/admin/admin_panel.php'>← Go back to Admin Panel</a>";
?>