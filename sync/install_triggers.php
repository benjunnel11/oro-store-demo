<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$conn = getLocalConnection();
$results = ['installed' => 0, 'skipped' => 0, 'errors' => []];

// Ensure sync tables exist
$conn->query("CREATE TABLE IF NOT EXISTS sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100),
    record_id INT,
    operation VARCHAR(10),
    data LONGTEXT,
    device_id VARCHAR(50),
    synced TINYINT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS sync_status (
    id INT AUTO_INCREMENT PRIMARY KEY,
    last_sync_time DATETIME,
    remote_device_ip VARCHAR(50),
    sync_direction VARCHAR(10),
    records_pushed INT DEFAULT 0,
    records_pulled INT DEFAULT 0,
    status VARCHAR(20),
    error_message TEXT
)");

$skip_tables = ['sync_log', 'sync_status', 'system_logs', 'user_sessions', 'product_history', 'product_history_backup'];

foreach (SYNC_TABLES as $table) {
    if (in_array($table, $skip_tables)) {
        $results['skipped']++;
        continue;
    }

    // Check table exists
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$check || $check->num_rows === 0) {
        $results['skipped']++;
        continue;
    }

    // Get columns for this table (for INSERT trigger data capture)
    $cols_result = $conn->query("SHOW COLUMNS FROM `$table`");
    $columns = [];
    $has_id = false;
    while ($col = $cols_result->fetch_assoc()) {
        if ($col['Field'] === 'id') { $has_id = true; continue; }
        if (in_array($col['Field'], ['device_id', 'is_synced', 'is_deleted'])) continue;
        $columns[] = $col['Field'];
    }

    if (!$has_id) {
        $results['skipped']++;
        continue;
    }

    // Ensure table has device_id, is_synced, is_deleted columns
    $existing = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'is_synced'");
    if ($existing->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN is_synced TINYINT DEFAULT 0");
    }
    $existing = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'is_deleted'");
    if ($existing->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN is_deleted TINYINT DEFAULT 0");
    }
    $existing = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'device_id'");
    if ($existing->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN device_id VARCHAR(50) DEFAULT NULL");
    }

    // Build JSON concat for data capture
    $json_parts = [];
    foreach ($columns as $col) {
        $json_parts[] = "'\"$col\":\"', IFNULL(REPLACE(REPLACE(NEW.`$col`, '\\\\', '\\\\\\\\'), '\"', '\\\\\"'), ''), '\"'";
    }
    $json_expr = "CONCAT('{', " . implode(", ',', ", $json_parts) . ", '}')";

    $json_parts_old = [];
    foreach ($columns as $col) {
        $json_parts_old[] = "'\"$col\":\"', IFNULL(REPLACE(REPLACE(OLD.`$col`, '\\\\', '\\\\\\\\'), '\"', '\\\\\"'), ''), '\"'";
    }
    $json_expr_old = "CONCAT('{', " . implode(", ',', ", $json_parts_old) . ", '}')";

    // Drop existing triggers
    $conn->query("DROP TRIGGER IF EXISTS `sync_after_insert_$table`");
    $conn->query("DROP TRIGGER IF EXISTS `sync_after_update_$table`");
    $conn->query("DROP TRIGGER IF EXISTS `sync_after_delete_$table`");

    // AFTER INSERT trigger
    $sql = "CREATE TRIGGER `sync_after_insert_$table` AFTER INSERT ON `$table`
    FOR EACH ROW
    BEGIN
        IF @is_syncing IS NULL OR @is_syncing = 0 THEN
            INSERT INTO sync_log (table_name, record_id, operation, data, device_id, synced, created_at)
            VALUES ('$table', NEW.id, 'INSERT', $json_expr, IFNULL(@sync_device_id, 'DEVICE_A'), 0, NOW());
        END IF;
    END";
    if (!$conn->query($sql)) {
        $results['errors'][] = "INSERT trigger $table: " . $conn->error;
    }

    // AFTER UPDATE trigger
    $sql = "CREATE TRIGGER `sync_after_update_$table` AFTER UPDATE ON `$table`
    FOR EACH ROW
    BEGIN
        IF @is_syncing IS NULL OR @is_syncing = 0 THEN
            INSERT INTO sync_log (table_name, record_id, operation, data, device_id, synced, created_at)
            VALUES ('$table', NEW.id, 'UPDATE', $json_expr, IFNULL(@sync_device_id, 'DEVICE_A'), 0, NOW());
        END IF;
    END";
    if (!$conn->query($sql)) {
        $results['errors'][] = "UPDATE trigger $table: " . $conn->error;
    }

    // AFTER DELETE trigger
    $sql = "CREATE TRIGGER `sync_after_delete_$table` AFTER DELETE ON `$table`
    FOR EACH ROW
    BEGIN
        IF @is_syncing IS NULL OR @is_syncing = 0 THEN
            INSERT INTO sync_log (table_name, record_id, operation, data, device_id, synced, created_at)
            VALUES ('$table', OLD.id, 'DELETE', $json_expr_old, IFNULL(@sync_device_id, 'DEVICE_A'), 0, NOW());
        END IF;
    END";
    if (!$conn->query($sql)) {
        $results['errors'][] = "DELETE trigger $table: " . $conn->error;
    }

    $results['installed']++;
}

$conn->close();
echo json_encode($results);
