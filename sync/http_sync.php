<?php
/**
 * HTTP Sync Engine — syncs with all remote stores via web API
 * Supports multiple branch devices. Each store's IP comes from the stores table.
 */
require_once __DIR__ . '/config.php';

function httpSync() {
    $conn = getLocalConnection();
    $sync_key = SYNC_PASSWORD;
    $results = ['pushed' => 0, 'pulled' => 0, 'errors' => [], 'devices' => []];

    // Get all remote store IPs from database
    $remote_stores = [];
    $r = @$conn->query("SELECT store_name, device_id, device_ip FROM stores WHERE device_id IS NOT NULL AND device_id != '" . LOCAL_DEVICE_ID . "' AND device_ip IS NOT NULL AND device_ip != '' AND status = 'active'");
    if ($r) {
        while ($row = $r->fetch_assoc()) $remote_stores[] = $row;
    }

    // Fallback to config REMOTE_IP if no stores have IPs
    if (empty($remote_stores)) {
        if (REMOTE_IP && REMOTE_IP !== '0.0.0.0') {
            $remote_stores[] = ['store_name' => 'Remote', 'device_id' => 'UNKNOWN', 'device_ip' => REMOTE_IP];
        } else {
            $results['errors'][] = 'No remote stores configured with IPs. Go to Manage Stores and set ZeroTier IPs.';
            updateSyncLog($conn, 0, 0, 'FAILED', 'No remote IPs configured', '');
            $conn->close();
            return $results;
        }
    }

    // Sync with each remote store
    foreach ($remote_stores as $remote) {
        $ip = $remote['device_ip'];
        $dev_result = syncWithDevice($conn, $ip, $sync_key);
        $results['pushed'] += $dev_result['pushed'];
        $results['pulled'] += $dev_result['pulled'];
        if (!empty($dev_result['errors'])) {
            foreach ($dev_result['errors'] as $e) $results['errors'][] = "[{$remote['device_id']}] $e";
        }
        $results['devices'][$remote['device_id']] = $dev_result;

        $status = empty($dev_result['errors']) ? 'SUCCESS' : 'PARTIAL';
        updateSyncLog($conn, $dev_result['pushed'], $dev_result['pulled'], $status, implode('; ', $dev_result['errors']), $ip);
    }

    $conn->close();
    return $results;
}

function syncWithDevice($conn, $remote_ip, $sync_key) {
    $remote_url = 'http://' . $remote_ip . '/oro-store/sync/sync_api.php';
    $results = ['pushed' => 0, 'pulled' => 0, 'errors' => []];

    // 1. Check if remote is available
    $status_url = $remote_url . '?action=status&key=' . urlencode($sync_key);
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "X-Sync-Key: $sync_key\r\n"]]);
    $status_raw = @file_get_contents($status_url, false, $ctx);

    if (!$status_raw) {
        $results['errors'][] = "Unreachable at $remote_ip";
        return $results;
    }

    $remote_status = json_decode($status_raw, true);
    if (!$remote_status || empty($remote_status['success'])) {
        $err = isset($remote_status['error']) ? $remote_status['error'] : 'not valid JSON: ' . substr($status_raw, 0, 100);
        $results['errors'][] = "Bad response from $remote_ip: $err";
        return $results;
    }

    logMessage("Connected to " . ($remote_status['device_id'] ?? $remote_ip) . " | Pending: " . ($remote_status['pending_changes'] ?? 0));

    // 2. Push our changes to remote
    $local_changes = $conn->query("SELECT * FROM sync_log WHERE synced = 0 AND device_id = '" . LOCAL_DEVICE_ID . "' ORDER BY created_at ASC LIMIT 200")->fetch_all(MYSQLI_ASSOC);

    if (!empty($local_changes)) {
        $push_data = json_encode(['device_id' => LOCAL_DEVICE_ID, 'changes' => $local_changes]);
        $push_url = $remote_url . '?action=push_changes';
        $push_ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'timeout' => 30,
            'header' => "Content-Type: application/json\r\nX-Sync-Key: $sync_key\r\n",
            'content' => $push_data
        ]]);
        $push_raw = @file_get_contents($push_url, false, $push_ctx);
        $push_result = $push_raw ? json_decode($push_raw, true) : null;

        if ($push_result && isset($push_result['success']) && $push_result['success']) {
            $results['pushed'] = $push_result['applied'] ?? 0;
            foreach ($local_changes as $change) {
                $conn->query("UPDATE sync_log SET synced = 1 WHERE id = " . intval($change['id']));
            }
        } else {
            if (!$push_raw) {
                $results['errors'][] = "Push failed: no response from $remote_ip";
            } elseif (!$push_result) {
                $results['errors'][] = "Push failed: invalid JSON from $remote_ip: " . substr($push_raw, 0, 200);
            } elseif (isset($push_result['errors']) && !empty($push_result['errors'])) {
                $results['errors'][] = 'Push errors: ' . implode(', ', array_slice($push_result['errors'], 0, 3));
            } else {
                $results['errors'][] = 'Push failed: ' . substr($push_raw, 0, 200);
            }
        }
    }

    // 3. Pull remote changes
    $pull_url = $remote_url . '?action=get_changes&key=' . urlencode($sync_key) . '&limit=200';
    $pull_ctx = stream_context_create(['http' => ['timeout' => 15, 'header' => "X-Sync-Key: $sync_key\r\n"]]);
    $pull_raw = @file_get_contents($pull_url, false, $pull_ctx);
    $pull_result = $pull_raw ? json_decode($pull_raw, true) : null;

    if ($pull_result && $pull_result['success'] && !empty($pull_result['changes'])) {
        $conn->query("SET @is_syncing = 1");
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");

        foreach ($pull_result['changes'] as $change) {
            $table = $conn->real_escape_string($change['table_name']);
            $record_id = intval($change['record_id']);
            $operation = $change['operation'];
            $data = json_decode($change['data'], true);

            // Check table exists
            $tbl_check = @$conn->query("SHOW TABLES LIKE '$table'");
            if (!$tbl_check || $tbl_check->num_rows === 0) continue;

            // Auto-create missing columns
            $valid_cols = [];
            $col_r = @$conn->query("SHOW COLUMNS FROM `$table`");
            if ($col_r) { while ($c = $col_r->fetch_assoc()) $valid_cols[] = $c['Field']; }
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

            $check = @$conn->query("SELECT id FROM `$table` WHERE id = $record_id");
            $exists = $check && $check->num_rows > 0;

            $remote_dev = $conn->real_escape_string($pull_result['device_id']);
            $set = [];
            foreach ($data as $k => $v) $set[] = $v === null ? "`$k`=NULL" : "`$k`='" . $conn->real_escape_string($v) . "'";
            if (in_array('is_synced', $valid_cols)) $set[] = "is_synced=1";

            if ($operation === 'INSERT') {
                $cols = array_keys($data);
                $cols[] = 'id'; $cols[] = 'device_id'; $cols[] = 'is_synced';
                $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'", array_values($data));
                $vals[] = $record_id;
                $vals[] = "'$remote_dev'";
                $vals[] = '1';
                $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ") ON DUPLICATE KEY UPDATE " . implode(', ', $set);
                if (@$conn->query($sql)) $results['pulled']++;
            } elseif ($operation === 'UPDATE') {
                if ($exists) {
                    @$conn->query("UPDATE `$table` SET " . implode(',', $set) . " WHERE id=$record_id");
                } else {
                    $cols = array_keys($data);
                    $cols[] = 'id'; $cols[] = 'device_id'; $cols[] = 'is_synced';
                    $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'", array_values($data));
                    $vals[] = $record_id;
                    $vals[] = "'$remote_dev'";
                    $vals[] = '1';
                    @$conn->query("INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ") ON DUPLICATE KEY UPDATE " . implode(', ', $set));
                }
                $results['pulled']++;
            } elseif ($operation === 'DELETE' && $exists) {
                @$conn->query("UPDATE `$table` SET is_deleted=1, is_synced=1 WHERE id=$record_id");
                $results['pulled']++;
            }
        }

        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->query("SET @is_syncing = 0");
    }

    return $results;
}

function updateSyncLog($conn, $pushed, $pulled, $status, $error, $remote_ip = '') {
    if (!$remote_ip) $remote_ip = defined('REMOTE_IP') ? REMOTE_IP : '';
    $error_sql = $error ? "'" . $conn->real_escape_string($error) . "'" : 'NULL';
    $conn->query("INSERT INTO sync_status (last_sync_time, remote_device_ip, sync_direction, records_pushed, records_pulled, status, error_message)
        VALUES (NOW(), '" . $conn->real_escape_string($remote_ip) . "', 'BOTH', $pushed, $pulled, '$status', $error_sql)");
}

// Run if called directly
if (php_sapi_name() === 'cli' || isset($_GET['run'])) {
    ob_start();
    error_reporting(0);
    ini_set('display_errors', '0');
    $result = httpSync();
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode($result);
}
