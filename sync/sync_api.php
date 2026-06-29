<?php
/**
 * HTTP Sync API — allows stores to sync via web requests (no MySQL port needed)
 * Each store runs its own XAMPP. They sync through this API over Tailscale.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$auth_key = $_SERVER['HTTP_X_SYNC_KEY'] ?? ($_GET['key'] ?? '');
if ($auth_key !== SYNC_PASSWORD) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$conn = getLocalConnection();
$action = $_GET['action'] ?? '';

// GET: Return unsynced changes for the remote to pull
if ($action === 'get_changes') {
    $since = $_GET['since'] ?? '2000-01-01 00:00:00';
    $limit = intval($_GET['limit'] ?? 100);

    $stmt = $conn->prepare("SELECT * FROM sync_log WHERE synced = 0 AND device_id = ? AND created_at > ? ORDER BY created_at ASC LIMIT ?");
    $device = LOCAL_DEVICE_ID;
    $stmt->bind_param("ssi", $device, $since, $limit);
    $stmt->execute();
    $changes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode(['success' => true, 'device_id' => LOCAL_DEVICE_ID, 'changes' => $changes, 'count' => count($changes)]);
    $conn->close();
    exit;
}

// POST: Receive changes from remote and apply them
if ($action === 'push_changes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $changes = $input['changes'] ?? [];
    $remote_device = $input['device_id'] ?? 'UNKNOWN';

    $applied = 0;
    $errors = [];

    // Tell triggers not to re-log incoming sync writes
    $conn->query("SET @is_syncing = 1");
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    foreach ($changes as $change) {
        $table = $conn->real_escape_string($change['table_name']);
        $record_id = intval($change['record_id']);
        $operation = $change['operation'];
        $data = json_decode($change['data'], true);

        // Verify table exists
        $check = $conn->query("SHOW TABLES LIKE '$table'");
        if (!$check || $check->num_rows === 0) {
            $errors[] = "Table '$table' not found";
            continue;
        }

        // Get valid columns for this table to filter out mismatched ones
        $valid_cols = [];
        $col_r = $conn->query("SHOW COLUMNS FROM `$table`");
        if ($col_r) { while ($c = $col_r->fetch_assoc()) $valid_cols[] = $c['Field']; }

        // Auto-create missing columns and keep all data
        foreach ($data as $k => $v) {
            if (!in_array($k, $valid_cols)) {
                $col_type = 'VARCHAR(255)';
                if ($v !== null) {
                    if (is_numeric($v) && strpos($v, '.') !== false) $col_type = 'DECIMAL(12,2)';
                    elseif (is_numeric($v)) $col_type = 'INT';
                    elseif (strlen($v) > 255) $col_type = 'TEXT';
                    elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) $col_type = 'DATETIME';
                }
                @$conn->query("ALTER TABLE `$table` ADD COLUMN `$k` $col_type DEFAULT NULL");
                $valid_cols[] = $k;
            }
        }

        $exists_r = @$conn->query("SELECT id FROM `$table` WHERE id = $record_id");
        $exists = $exists_r && $exists_r->num_rows > 0;

        // Build update SET clause
        $set = [];
        foreach ($data as $k => $v) {
            $set[] = $v === null ? "`$k` = NULL" : "`$k` = '" . $conn->real_escape_string($v) . "'";
        }
        if (in_array('is_synced', $valid_cols)) $set[] = "is_synced = 1";

        if ($operation === 'INSERT') {
            $cols = array_keys($data);
            $cols[] = 'id';
            $cols[] = 'device_id';
            $cols[] = 'is_synced';
            $vals = array_map(function($v) use ($conn) {
                return $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
            }, array_values($data));
            $vals[] = $record_id;
            $vals[] = "'" . $conn->real_escape_string($remote_device) . "'";
            $vals[] = '1';
            $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ") ON DUPLICATE KEY UPDATE " . implode(', ', $set);
            if ($conn->query($sql)) $applied++;
            else $errors[] = "$table INSERT: " . $conn->error;

        } elseif ($operation === 'UPDATE') {
            $set[] = "device_id = '" . $conn->real_escape_string($remote_device) . "'";
            if ($exists) {
                $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE id = $record_id";
            } else {
                // Record doesn't exist by ID, try inserting instead
                $cols = array_keys($data);
                $cols[] = 'id';
                $cols[] = 'device_id';
                $cols[] = 'is_synced';
                $vals = array_map(function($v) use ($conn) {
                    return $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'";
                }, array_values($data));
                $vals[] = $record_id;
                $vals[] = "'" . $conn->real_escape_string($remote_device) . "'";
                $vals[] = '1';
                $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ") ON DUPLICATE KEY UPDATE " . implode(', ', $set);
            }
            if ($conn->query($sql)) $applied++;
            else $errors[] = "$table UPDATE: " . $conn->error;

        } elseif ($operation === 'DELETE' && $exists) {
            $sql = "UPDATE `$table` SET is_deleted = 1, is_synced = 1 WHERE id = $record_id";
            if ($conn->query($sql)) $applied++;
        }
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    // Mark these as synced on our side too
    foreach ($changes as $change) {
        $conn->query("UPDATE sync_log SET synced = 1 WHERE id = " . intval($change['id']));
    }

    echo json_encode(['success' => true, 'applied' => $applied, 'errors' => $errors]);
    $conn->close();
    exit;
}

// GET: Health check / status
if ($action === 'status') {
    $last_sync = $conn->query("SELECT * FROM sync_status ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $pending = $conn->query("SELECT COUNT(*) as c FROM sync_log WHERE synced = 0")->fetch_assoc()['c'];
    echo json_encode([
        'success' => true,
        'device_id' => LOCAL_DEVICE_ID,
        'pending_changes' => intval($pending),
        'last_sync' => $last_sync,
        'server_time' => date('Y-m-d H:i:s')
    ]);
    $conn->close();
    exit;
}

// POST: Remote wipe triggered from Device A
if ($action === 'remote_cleanup') {
    if (LOCAL_DEVICE_ID === 'DEVICE_A') {
        echo json_encode(['success' => false, 'message' => 'Cannot wipe Device A.']);
        $conn->close();
        exit;
    }
    $lock_file = __DIR__ . '/.main_server';
    if (file_exists($lock_file)) {
        echo json_encode(['success' => false, 'message' => 'Blocked by .main_server lock.']);
        $conn->close();
        exit;
    }
    $conn->close();
    ob_start();
    require_once __DIR__ . '/nightly_cleanup.php';
    $output = ob_get_clean();
    $json = json_decode($output, true);
    if ($json) {
        echo json_encode($json);
    } else {
        echo json_encode(['success' => false, 'message' => 'Cleanup ran but returned invalid output', 'raw' => substr($output, 0, 500)]);
    }
    exit;
}

echo json_encode(['error' => 'Unknown action']);
$conn->close();
