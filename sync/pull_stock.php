<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../core/db_config.php';
header('Content-Type: application/json');

$key = $_GET['key'] ?? '';
if ($key !== SYNC_PASSWORD) { echo json_encode(['error' => 'Unauthorized']); exit; }

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { echo json_encode(['error' => $conn->connect_error]); exit; }

$tables = ['products', 'store_prices', 'stores', 'product_categories', 'product_brands', 'gcash_accounts', 'bank_accounts'];
$data = [];

foreach ($tables as $table) {
    $check = @$conn->query("SHOW TABLES LIKE '$table'");
    if (!$check || $check->num_rows === 0) continue;

    $where = "WHERE 1=1";
    $cols = $conn->query("SHOW COLUMNS FROM `$table`");
    $col_names = [];
    while ($c = $cols->fetch_assoc()) $col_names[] = $c['Field'];
    if (in_array('is_deleted', $col_names)) $where .= " AND is_deleted = 0";

    $r = @$conn->query("SELECT * FROM `$table` $where");
    if ($r) {
        $rows = $r->fetch_all(MYSQLI_ASSOC);
        if (!empty($rows)) $data[$table] = $rows;
    }
}

$conn->close();
echo json_encode(['success' => true, 'tables' => $data]);
