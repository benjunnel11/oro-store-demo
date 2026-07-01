<?php
// sync/sync.php
// Run via browser: http://localhost/oro-store-demo/sync/sync.php
// Or via cron:     php C:\xampp\htdocs\oro-store\sync\sync.php
//
// This script:
//   1. Checks remote is reachable
//   2. Verifies token
//   3. Pushes local unsynced changes to remote
//   4. Pulls remote unsynced changes to local
//   5. Logs everything to sync_status

require_once 'config.php';

$is_browser = php_sapi_name() !== 'cli';

if ($is_browser) {
    echo "<pre style='font-family:monospace;font-size:13px;background:#0e1219;color:#d0daf0;padding:24px;'>";
}

$start = microtime(true);

log_out("=== OroStore Sync ===");
log_out("Device : " . LOCAL_DEVICE_ID);
log_out("Time   : " . date('Y-m-d H:i:s'));
log_out("Remote : " . REMOTE_IP . ":" . REMOTE_PORT);
log_out("");

// ── 1. Check remote reachable ─────────────────────────────────────────────────
log_out("[ 1 ] Checking remote availability...");
if (!isRemoteAvailable()) {
    log_out("  ✗ Remote unreachable — sync aborted");
    save_sync_status(0, 0, 'FAILED', 'Remote device unreachable');
    end_output();
    exit;
}
log_out("  ✓ Remote reachable");

// ── 1b. Cloud stock sync ─────────────────────────────────────────────────────
require_once __DIR__ . '/cloud_stock_sync.php';
$cloud_flushed = cloudFlushQueue();
if ($cloud_flushed > 0) log_out("  ✓ Flushed $cloud_flushed queued cloud stock push(es)");

// Pull other stores' stock from cloud
require_once __DIR__ . '/../core/db_connection.php';
$_sync_store = $conn->query("SELECT id FROM stores WHERE device_id = '" . LOCAL_DEVICE_ID . "' AND status = 'active' LIMIT 1");
$_sync_store_row = $_sync_store ? $_sync_store->fetch_assoc() : null;
if ($_sync_store_row) {
    $cloud_pulled = cloudStockPull($conn, $_sync_store_row['id']);
    if ($cloud_pulled > 0) log_out("  ✓ Pulled $cloud_pulled stock update(s) from cloud");
}

require_once __DIR__ . '/../core/realtime_stock.php';
$flushed = flushStockQueue();
if ($flushed > 0) log_out("  ✓ Flushed $flushed queued stock change(s) to remote");

// ── 2. Connect ────────────────────────────────────────────────────────────────
log_out("");
log_out("[ 2 ] Connecting to databases...");

$local = getLocalConnection();
if (!$local) {
    log_out("  ✗ Local DB connection failed");
    save_sync_status(0, 0, 'FAILED', 'Local DB connection failed');
    end_output();
    exit;
}
log_out("  ✓ Local DB connected");

$remote = getRemoteConnection();
if (!$remote) {
    log_out("  ✗ Remote DB connection failed");
    save_sync_status(0, 0, 'FAILED', 'Remote DB connection failed');
    end_output();
    exit;
}
log_out("  ✓ Remote DB connected");

// ── 3. Verify token ───────────────────────────────────────────────────────────
log_out("");
log_out("[ 3 ] Verifying remote token...");
if (!verifyRemoteToken($remote)) {
    log_out("  ✗ Token mismatch — sync aborted for security");
    save_sync_status(0, 0, 'FAILED', 'Token verification failed');
    $remote->close();
    end_output();
    exit;
}
log_out("  ✓ Token verified");

// ── 4. Push local changes ─────────────────────────────────────────────────────
log_out("");
log_out("[ 4 ] Pushing local changes to remote...");
$remote->query("SET FOREIGN_KEY_CHECKS = 0");
$pushed = push_changes($local, $remote);
$remote->query("SET FOREIGN_KEY_CHECKS = 1");
log_out("  → Pushed: $pushed records");

// ── 5. Pull remote changes ────────────────────────────────────────────────────
log_out("");
log_out("[ 5 ] Pulling remote changes to local...");
$local->query("SET FOREIGN_KEY_CHECKS = 0");
$pulled = pull_changes($local, $remote);
$local->query("SET FOREIGN_KEY_CHECKS = 1");
log_out("  ← Pulled: $pulled records");

// ── 6. Done ───────────────────────────────────────────────────────────────────
$elapsed = round((microtime(true) - $start) * 1000);
$remote->close();

save_sync_status($pushed, $pulled, 'SUCCESS', null, $local);

log_out("");
log_out("=== Sync Complete ===");
log_out("Pushed  : $pushed");
log_out("Pulled  : $pulled");
log_out("Elapsed : {$elapsed}ms");
log_out("Time    : " . date('Y-m-d H:i:s'));

end_output();


// ════════════════════════════════════════════════════════════════════════════
// PUSH — send local unsynced records to remote
// ════════════════════════════════════════════════════════════════════════════
function push_changes(mysqli $local, mysqli $remote): int {
    $pushed = 0;

    // Get all unsynced log entries from local that came from THIS device
    $result = $local->query("
        SELECT * FROM sync_log
        WHERE synced = 0
          AND device_id = '" . LOCAL_DEVICE_ID . "'
        ORDER BY created_at ASC
        LIMIT 500
    ");

    if (!$result) {
        log_out("  ✗ Could not read sync_log: " . $local->error);
        return 0;
    }

    while ($log = $result->fetch_assoc()) {
        try {
            $success = apply_change($remote, $log, 'push');
        } catch (Exception $e) {
            $success = false;
            log_out("  ✗ PUSH exception {$log['table_name']} [{$log['record_id']}]: " . $e->getMessage());
        }
        if ($success) {
            $local->query("UPDATE sync_log SET synced = 1 WHERE id = '{$log['id']}'");
            $pushed++;
            log_out("  ✓ PUSH {$log['operation']} {$log['table_name']} [{$log['record_id']}]");
        } else {
            // Mark as synced if record already exists on remote to prevent infinite retry
            $tbl = $log['table_name'];
            $rid = $log['record_id'];
            $chk = $remote->query("SELECT id FROM `$tbl` WHERE id = '$rid'");
            if ($chk && $chk->num_rows > 0) {
                $local->query("UPDATE sync_log SET synced = 1 WHERE id = '{$log['id']}'");
                log_out("  - SKIP {$log['operation']} $tbl [$rid] (already exists on remote)");
            } else {
                log_out("  ✗ PUSH failed {$log['operation']} $tbl [$rid]");
            }
        }
    }

    return $pushed;
}


// ════════════════════════════════════════════════════════════════════════════
// PULL — fetch remote unsynced records and apply locally
// ════════════════════════════════════════════════════════════════════════════
function pull_changes(mysqli $local, mysqli $remote): int {
    $pulled = 0;

    // Get unsynced log entries from remote that did NOT originate here
    $result = $remote->query("
        SELECT * FROM sync_log
        WHERE synced = 0
          AND device_id != '" . LOCAL_DEVICE_ID . "'
        ORDER BY created_at ASC
        LIMIT 500
    ");

    if (!$result) {
        log_out("  ✗ Could not read remote sync_log: " . $remote->error);
        return 0;
    }

    while ($log = $result->fetch_assoc()) {
        try {
            $success = apply_change($local, $log, 'pull');
        } catch (Exception $e) {
            $success = false;
            log_out("  ✗ PULL exception {$log['table_name']} [{$log['record_id']}]: " . $e->getMessage());
        }
        if ($success) {
            // Mark as synced on remote
            $remote->query("UPDATE sync_log SET synced = 1 WHERE id = '{$log['id']}'");
            // Insert into local sync_log as already synced so we don't re-push it
            $table    = $local->real_escape_string($log['table_name']);
            $rec_id   = $local->real_escape_string($log['record_id']);
            $op       = $local->real_escape_string($log['operation']);
            $data     = $local->real_escape_string($log['data']);
            $dev      = $local->real_escape_string($log['device_id']);
            $created  = $local->real_escape_string($log['created_at']);
            $local->query("
                INSERT IGNORE INTO sync_log
                    (table_name, record_id, operation, data, device_id, synced, created_at)
                VALUES
                    ('$table', '$rec_id', '$op', '$data', '$dev', 1, '$created')
            ");
            $pulled++;
            log_out("  ✓ PULL {$log['operation']} {$log['table_name']} [{$log['record_id']}]");
        } else {
            log_out("  ✗ PULL failed {$log['operation']} {$log['table_name']} [{$log['record_id']}]");
        }
    }

    return $pulled;
}


// ════════════════════════════════════════════════════════════════════════════
// APPLY CHANGE — insert/update/delete a record on a target DB
// ════════════════════════════════════════════════════════════════════════════
function apply_change(mysqli $conn, array $log, string $direction): bool {
    $table     = $conn->real_escape_string($log['table_name']);
    $record_id = $conn->real_escape_string($log['record_id']);
    $operation = $log['operation'];
    $data      = json_decode($log['data'], true) ?? [];

    // Verify table exists on target
    $tbl_check = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$tbl_check || $tbl_check->num_rows === 0) {
        log_out("    ⚠ Table '$table' not found on target ($direction)");
        return false;
    }

    switch ($operation) {

        case 'INSERT':
            // Check if record already exists (idempotent)
            $exists = $conn->query("SELECT id FROM `$table` WHERE id = '$record_id'")->num_rows > 0;
            if ($exists) return true; // already there, skip

            if (empty($data)) return false;

            // Build INSERT
            $cols = [];
            $vals = [];
            foreach ($data as $col => $val) {
                $cols[] = "`" . $conn->real_escape_string($col) . "`";
                $vals[] = $val === null ? "NULL" : "'" . $conn->real_escape_string($val) . "'";
            }
            // Make sure id is included
            if (!isset($data['id'])) {
                $cols[] = "`id`";
                $vals[] = "'" . $record_id . "'";
            }
            // Stamp device_id
            if (!isset($data['device_id'])) {
                $cols[] = "`device_id`";
                $vals[] = "'" . $conn->real_escape_string($log['device_id']) . "'";
            }
            // Mark as synced on arrival
            $cols[] = "`is_synced`";
            $vals[] = "1";

            $sql = "INSERT IGNORE INTO `$table` (" . implode(', ', $cols) . ")
                    VALUES (" . implode(', ', $vals) . ")";
            if (!$conn->query($sql)) {
                log_out("    ✗ INSERT error on $table: " . $conn->error);
                return false;
            }
            return true;

        case 'UPDATE':
            // Check if record exists
            $check = $conn->query("SELECT id, updated_at FROM `$table` WHERE id = '$record_id'");
            if (!$check || $check->num_rows === 0) {
                // Record doesn't exist on target — treat as INSERT
                $log['operation'] = 'INSERT';
                return apply_change($conn, $log, $direction);
            }

            $existing = $check->fetch_assoc();

            // Conflict resolution: if target record is newer, skip
            if (!empty($existing['updated_at']) && !empty($log['created_at'])) {
                if (strtotime($existing['updated_at']) > strtotime($log['created_at'])) {
                    log_out("    ⚠ Conflict: target newer for $table [$record_id] — skipping");
                    return true; // not an error, just skip
                }
            }

            if (empty($data)) return false;

            $set = [];
            foreach ($data as $col => $val) {
                if ($col === 'id') continue; // never update PK
                $set[] = "`" . $conn->real_escape_string($col) . "` = " .
                         ($val === null ? "NULL" : "'" . $conn->real_escape_string($val) . "'");
            }
            $set[] = "`is_synced` = 1";
            $set[] = "`device_id` = '" . $conn->real_escape_string($log['device_id']) . "'";

            $sql = "UPDATE `$table` SET " . implode(', ', $set) . " WHERE id = '$record_id'";
            if (!$conn->query($sql)) {
                log_out("    ✗ UPDATE error on $table: " . $conn->error);
                return false;
            }
            return true;

        case 'DELETE':
            // Soft delete
            $col_check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'is_deleted'");
            if ($col_check && $col_check->num_rows > 0) {
                $sql = "UPDATE `$table` SET is_deleted = 1, is_synced = 1 WHERE id = '$record_id'";
            } else {
                // Hard delete if no is_deleted column
                $sql = "DELETE FROM `$table` WHERE id = '$record_id'";
            }
            if (!$conn->query($sql)) {
                log_out("    ✗ DELETE error on $table: " . $conn->error);
                return false;
            }
            return true;
    }

    return false;
}


// ════════════════════════════════════════════════════════════════════════════
// HELPERS
// ════════════════════════════════════════════════════════════════════════════
function save_sync_status(int $pushed, int $pulled, string $status, ?string $error, ?mysqli $conn = null): void {
    try {
        $db = $conn ?? getLocalConnection();
        $err_val = $error ? "'" . $db->real_escape_string($error) . "'" : "NULL";
        $db->query("
            INSERT INTO sync_status
                (last_sync_time, remote_device_ip, sync_direction, records_pushed, records_pulled, status, error_message)
            VALUES
                (NOW(), '" . REMOTE_IP . "', 'BOTH', $pushed, $pulled, '$status', $err_val)
        ");
    } catch (Exception $e) {
        log_out("  ⚠ Could not save sync status: " . $e->getMessage());
    }
}

function log_out(string $msg): void {
    echo $msg . "\n";
    logMessage($msg);
    flush();
}

function end_output(): void {
    global $is_browser;
    if ($is_browser) echo "</pre>";
}