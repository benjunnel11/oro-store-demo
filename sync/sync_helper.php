<?php
// sync/sync_helper.php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/config.php';

// No queries on include — everything runs lazily inside SyncDB constructor

class SyncDB {
    private $conn;
    private $device_id;

public function __construct() {
    global $conn;
    
    // Use global connection if available, otherwise get local
    if (isset($conn) && $conn instanceof mysqli) {
        $this->conn = $conn;
    } else {
        $this->conn = getLocalConnection();
    }
    
    if (!$this->conn) {
        throw new Exception("Database connection not available");
    }
    
    $this->device_id = defined('LOCAL_DEVICE_ID') ? LOCAL_DEVICE_ID : 'DEVICE_A';
}

    /* =========================
       INSERT
    ========================= */
    public function insert($table, $data) {
        if (!in_array($table, SYNC_TABLES)) {
            logMessage("Warning: Table '$table' is not in SYNC_TABLES list");
        }

        $columns = array_keys($data);
        $values  = array_values($data);

        $escaped = array_map(function ($val) {
            if ($val === null) return 'NULL';
            return "'" . $this->conn->real_escape_string($val) . "'";
        }, $values);

        // Sync metadata
        $columns[] = 'device_id';
        $columns[] = 'is_synced';
        $escaped[] = "'" . $this->device_id . "'";
        $escaped[] = "0";

        // Suppress trigger (SyncDB logs its own changes)
        $this->conn->query("SET @is_syncing = 1");

        $sql = "INSERT INTO $table (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', $escaped) . ")";

        if (mysqli_query($this->conn, $sql)) {
            $record_id = mysqli_insert_id($this->conn);
            $this->conn->query("SET @is_syncing = 0");
            $this->logChange($table, $record_id, 'INSERT', $data);
            return $record_id;
        }

        $this->conn->query("SET @is_syncing = 0");
        logMessage("Insert failed in $table: " . mysqli_error($this->conn));
        return false;
    }

    /* =========================
       UPDATE
    ========================= */
    public function update($table, $data, $where) {
        if (!in_array($table, SYNC_TABLES)) {
            logMessage("Warning: Table '$table' is not in SYNC_TABLES list");
        }

        $set = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                $set[] = "$key = NULL";
            } else {
                $set[] = "$key = '" . $this->conn->real_escape_string($value) . "'";
            }
        }

        $set[] = "is_synced = 0";
        $set[] = "device_id = '{$this->device_id}'";

        // Suppress trigger (SyncDB logs its own changes)
        $this->conn->query("SET @is_syncing = 1");

        $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE $where";

        if (!mysqli_query($this->conn, $sql)) {
            $this->conn->query("SET @is_syncing = 0");
            logMessage("Update failed in $table: " . mysqli_error($this->conn));
            return false;
        }

        $this->conn->query("SET @is_syncing = 0");

        $res = mysqli_query($this->conn, "SELECT id FROM $table WHERE $where");
        while ($row = mysqli_fetch_assoc($res)) {
            $this->logChange($table, $row['id'], 'UPDATE', $data);
        }

        return true;
    }

    /* =========================
       DELETE (SOFT)
    ========================= */
    public function delete($table, $where) {
        if (!in_array($table, SYNC_TABLES)) {
            logMessage("Warning: Table '$table' is not in SYNC_TABLES list");
        }

        $this->conn->query("SET @is_syncing = 1");

        $sql = "
            UPDATE $table
            SET is_deleted = 1, is_synced = 0, device_id = '{$this->device_id}'
            WHERE $where
        ";

        if (!mysqli_query($this->conn, $sql)) {
            $this->conn->query("SET @is_syncing = 0");
            logMessage("Delete failed in $table: " . mysqli_error($this->conn));
            return false;
        }

        $this->conn->query("SET @is_syncing = 0");

        $res = mysqli_query($this->conn, "SELECT id FROM $table WHERE $where");
        while ($row = mysqli_fetch_assoc($res)) {
            $this->logChange($table, $row['id'], 'DELETE', []);
        }

        return true;
    }

    /* =========================
       🔥 MANUAL LOG METHOD (NEW)
       Used by cashier.php
    ========================= */
    public function logChange($table, $record_id, $operation, $data = []) {
        try {
            $data_json = json_encode($data);

            // If WHERE clause is passed, look up the actual ID
            $id_value = 0;
            if (is_numeric($record_id)) {
                $id_value = (int)$record_id;
            } else {
                $r = @$this->conn->query("SELECT id FROM `$table` WHERE $record_id LIMIT 1");
                if ($r && $row = $r->fetch_assoc()) $id_value = (int)$row['id'];
            }

            $stmt = $this->conn->prepare("
                INSERT INTO sync_log
                (table_name, record_id, operation, data, device_id, synced, created_at)
                VALUES (?, ?, ?, ?, ?, 0, NOW())
            ");

            $stmt->bind_param(
                'sisss',
                $table,
                $id_value,
                $operation,
                $data_json,
                $this->device_id
            );

            $result = $stmt->execute();
            $stmt->close();

            return $result;
        } catch (Exception $e) {
            error_log("Sync log error: " . $e->getMessage());
            return false;
        }
    }

    /* =========================
       SELECT
    ========================= */
    public function select($table, $where = '1=1', $columns = '*') {
        $sql = "SELECT $columns FROM $table WHERE $where AND is_deleted = 0";
        $result = mysqli_query($this->conn, $sql);

        if (!$result) {
            logMessage("Select failed in $table: " . mysqli_error($this->conn));
            return [];
        }

        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }

    /* =========================
       STATS
    ========================= */
    public function getSyncStats() {
        $stats = [];

        foreach (SYNC_TABLES as $table) {
            $res = mysqli_query($this->conn,
                "SELECT COUNT(*) AS count FROM sync_log
                 WHERE table_name = '$table' AND synced = 0");
            $row = mysqli_fetch_assoc($res);
            $stats['unsynced'][$table] = $row['count'];
        }

        $res = mysqli_query($this->conn,
            "SELECT MAX(last_sync_time) AS last_sync
             FROM sync_status WHERE status = 'SUCCESS'");
        $row = mysqli_fetch_assoc($res);

        $stats['last_sync'] = $row['last_sync'] ?? 'Never';

        $res = mysqli_query($this->conn,
            "SELECT COUNT(*) AS count FROM sync_log WHERE synced = 0");
        $row = mysqli_fetch_assoc($res);
        $stats['total_unsynced'] = $row['count'];

        return $stats;
    }

    public function getConnection() {
        return $this->conn;
    }
}
