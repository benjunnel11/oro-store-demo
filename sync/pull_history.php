<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../core/db_config.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$key = $_GET['key'] ?? ($_SERVER['HTTP_X_SYNC_KEY'] ?? '');

// Action: get transaction count for a period (lightweight check)
if ($action === 'get_count') {
    if ($key !== SYNC_PASSWORD) { echo json_encode(['error' => 'Unauthorized']); exit; }
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) { echo json_encode(['error' => $conn->connect_error]); exit; }

    $period = $_GET['period'] ?? 'today';
    $for_device = trim($_GET['device'] ?? '');
    $dev_filter = '';
    if ($for_device) $dev_filter = "AND device_id = '" . $conn->real_escape_string($for_device) . "'";

    $date_sql = "DATE(transaction_date) = CURDATE()";
    if ($period === 'week') $date_sql = "transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    elseif ($period === 'month') $date_sql = "transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    elseif ($period === 'year') $date_sql = "transaction_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
    elseif ($period === 'all') $date_sql = "1=1";

    $r = $conn->query("SELECT COUNT(*) as c FROM transactions WHERE $date_sql $dev_filter AND status IN ('completed','cancelled')");
    $count = $r ? intval($r->fetch_assoc()['c']) : 0;
    $conn->close();
    echo json_encode(['success' => true, 'count' => $count, 'period' => $period]);
    exit;
}

// Action: serve history (runs on Device A)
if ($action === 'get_history') {
    if ($key !== SYNC_PASSWORD) { echo json_encode(['error' => 'Unauthorized']); exit; }

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) { echo json_encode(['error' => $conn->connect_error]); exit; }

    $tables = ['transactions','transaction_items','gcash_transactions','atm_transactions',
        'atm_settlements','bank_transactions','cash_transactions','deliveries','delivery_items',
        'credits','angkat_transactions','angkat_items','angkat_payments','angkat_returns','expenses',
        'stock_receipts','stock_receipt_items'];

    $period = $_GET['period'] ?? 'month';
    $date_filter = '';
    if ($period === 'today') $date_filter = "AND DATE(created_at) = CURDATE()";
    elseif ($period === 'week') $date_filter = "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    elseif ($period === 'month') $date_filter = "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    elseif ($period === 'year') $date_filter = "AND created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
    // 'all' = no filter

    // Optional: filter by requesting device
    $for_device = trim($_GET['device'] ?? '');

    $data = [];
    foreach ($tables as $table) {
        $check = @$conn->query("SHOW TABLES LIKE '$table'");
        if (!$check || $check->num_rows === 0) continue;

        // Find the date column
        $cols = $conn->query("SHOW COLUMNS FROM `$table`");
        $col_names = [];
        while ($c = $cols->fetch_assoc()) $col_names[] = $c['Field'];

        $date_col = null;
        foreach (['transaction_date','settlement_date','created_at','paid_at','completed_at'] as $dc) {
            if (in_array($dc, $col_names)) { $date_col = $dc; break; }
        }

        $where = "WHERE 1=1";
        if (in_array('is_deleted', $col_names)) $where .= " AND is_deleted = 0";
        if ($for_device && in_array('device_id', $col_names)) {
            $where .= " AND device_id = '" . $conn->real_escape_string($for_device) . "'";
        }
        if ($date_filter && $date_col) {
            $df = str_replace('created_at', $date_col, $date_filter);
            $where .= " $df";
        }

        $order = $date_col ? "ORDER BY `$date_col` DESC" : "ORDER BY id DESC";
        $r = @$conn->query("SELECT * FROM `$table` $where $order LIMIT 5000");
        if ($r) {
            $rows = $r->fetch_all(MYSQLI_ASSOC);
            if (!empty($rows)) $data[$table] = $rows;
        }
    }

    $conn->close();
    echo json_encode(['success' => true, 'tables' => $data, 'period' => $period]);
    exit;
}

// Action: pull history from Device A (runs on branch device)
if ($action === 'pull' || isset($_GET['run'])) {
    if (LOCAL_DEVICE_ID === 'DEVICE_A') {
        echo json_encode(['success' => false, 'message' => 'Device A already has all data']); exit;
    }

    $period = $_GET['period'] ?? 'month';
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) { echo json_encode(['error' => $conn->connect_error]); exit; }

    // Get Device A's IP
    $device_a_ip = REMOTE_IP;
    $r = $conn->query("SELECT device_ip FROM stores WHERE device_id = 'DEVICE_A' AND device_ip IS NOT NULL LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) $device_a_ip = $row['device_ip'];

    if (!$device_a_ip) {
        echo json_encode(['success' => false, 'message' => 'Device A IP not found']); exit;
    }

    // Fetch history from Device A
    $for_dev = $_GET['device'] ?? LOCAL_DEVICE_ID;
    $url = "http://$device_a_ip/oro-store/sync/pull_history.php?action=get_history&period=$period&device=$for_dev&key=" . urlencode(SYNC_PASSWORD);
    $ctx = stream_context_create(['http' => ['timeout' => 30]]);
    $raw = @file_get_contents($url, false, $ctx);

    if (!$raw) {
        echo json_encode(['success' => false, 'message' => "Cannot reach Device A at $device_a_ip"]); exit;
    }

    $result = json_decode($raw, true);
    if (!$result || !$result['success']) {
        echo json_encode(['success' => false, 'message' => 'Bad response from Device A']); exit;
    }

    // Save locally with is_synced=1 (won't be pushed back)
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    $conn->query("SET @is_syncing = 1");

    $inserted = 0;
    foreach ($result['tables'] as $table => $rows) {
        // Get valid columns
        $valid = [];
        $col_r = @$conn->query("SHOW COLUMNS FROM `$table`");
        if (!$col_r) continue;
        while ($c = $col_r->fetch_assoc()) $valid[] = $c['Field'];

        foreach ($rows as $row) {
            $id = intval($row['id'] ?? 0);
            if (!$id) continue;

            // Skip if already exists locally
            $exists = @$conn->query("SELECT id FROM `$table` WHERE id = $id");
            if ($exists && $exists->num_rows > 0) continue;

            // Remove is_synced from source data — we set it ourselves
            unset($row['is_synced']);

            // Filter to valid columns + auto-create missing ones
            $cols = []; $vals = [];
            foreach ($row as $k => $v) {
                if (!in_array($k, $valid)) {
                    $type = 'VARCHAR(255)';
                    if ($v !== null) {
                        if (is_numeric($v) && strpos($v, '.') !== false) $type = 'DECIMAL(12,2)';
                        elseif (is_numeric($v)) $type = 'INT';
                        elseif (strlen($v) > 255) $type = 'TEXT';
                    }
                    @$conn->query("ALTER TABLE `$table` ADD COLUMN `$k` $type DEFAULT NULL");
                    $valid[] = $k;
                }
                $cols[] = "`$k`";
                $vals[] = $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
            }

            // Mark as synced so it won't be pushed back
            $is_synced_idx = array_search('`is_synced`', $cols);
            if ($is_synced_idx !== false) {
                $vals[$is_synced_idx] = '1';
            } elseif (in_array('is_synced', $valid)) {
                $cols[] = '`is_synced`'; $vals[] = '1';
            }

            $sql = "INSERT IGNORE INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
            if (@$conn->query($sql)) $inserted++;
        }
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET @is_syncing = 0");
    $conn->close();

    echo json_encode(['success' => true, 'inserted' => $inserted, 'period' => $period, 'tables' => count($result['tables'])]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
