<?php
// sync/sync_engine.php
require_once 'config.php';

class SyncEngine {
    private $local_conn;
    private $remote_conn;
    
    public function __construct() {
        $this->local_conn = getLocalConnection();
    }
    
    // Main sync function
    public function sync() {
        logMessage("=== Starting Sync Process ===");
        
        if (!isRemoteAvailable()) {
            logMessage("Remote device not available. Sync postponed.");
            $this->updateSyncStatus(0, 0, 'FAILED', 'Remote device unreachable');
            return false;
        }
        
        $this->remote_conn = getRemoteConnection();
        if (!$this->remote_conn) {
            logMessage("Could not connect to remote database.");
            $this->updateSyncStatus(0, 0, 'FAILED', 'Database connection failed');
            return false;
        }
        
        logMessage("Connected to remote device at " . REMOTE_IP);
        
        try {
            // Push local changes to remote
            $pushed = $this->pushChanges();
            
            // Pull remote changes to local
            $pulled = $this->pullChanges();
            
            // Update sync status
            $this->updateSyncStatus($pushed, $pulled, 'SUCCESS', null);
            
            logMessage("=== Sync Completed: Pushed $pushed, Pulled $pulled ===");
            
            mysqli_close($this->remote_conn);
            return true;
            
        } catch (Exception $e) {
            logMessage("Sync error: " . $e->getMessage());
            $this->updateSyncStatus(0, 0, 'FAILED', $e->getMessage());
            if ($this->remote_conn) {
                mysqli_close($this->remote_conn);
            }
            return false;
        }
    }
    
    // Push local unsynced changes to remote
    private function pushChanges() {
        $sql = "SELECT * FROM sync_log WHERE synced = 0 ORDER BY created_at ASC";
        $result = mysqli_query($this->local_conn, $sql);
        
        $pushed = 0;
        while ($log = mysqli_fetch_assoc($result)) {
            $success = $this->applyChange($this->remote_conn, $log);
            
            if ($success) {
                // Mark as synced
                mysqli_query($this->local_conn, "UPDATE sync_log SET synced = 1 WHERE id = " . $log['id']);
                
                // Mark original record as synced
                mysqli_query($this->local_conn, 
                    "UPDATE {$log['table_name']} SET is_synced = 1 WHERE id = " . $log['record_id']);
                
                $pushed++;
                logMessage("Pushed {$log['operation']} for {$log['table_name']} ID {$log['record_id']}");
            }
        }
        
        return $pushed;
    }
    
    // Pull remote unsynced changes to local
    private function pullChanges() {
        $sql = "SELECT * FROM sync_log WHERE synced = 0 AND device_id != '" . LOCAL_DEVICE_ID . "' ORDER BY created_at ASC";
        $result = mysqli_query($this->remote_conn, $sql);
        
        if (!$result) {
            logMessage("Error querying remote sync_log: " . mysqli_error($this->remote_conn));
            return 0;
        }
        
        $pulled = 0;
        while ($log = mysqli_fetch_assoc($result)) {
            $success = $this->applyChange($this->local_conn, $log);
            
            if ($success) {
                // Mark as synced on remote
                mysqli_query($this->remote_conn, "UPDATE sync_log SET synced = 1 WHERE id = " . $log['id']);
                $pulled++;
                logMessage("Pulled {$log['operation']} for {$log['table_name']} ID {$log['record_id']}");
            }
        }
        
        return $pulled;
    }
    
    // Apply a change to a database
    private function applyChange($conn, $log) {
        $table = mysqli_real_escape_string($conn, $log['table_name']);
        $record_id = intval($log['record_id']);
        $operation = $log['operation'];
        $data = json_decode($log['data'], true);
        
        // Verify table exists
        $table_check = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
        if (mysqli_num_rows($table_check) == 0) {
            logMessage("Warning: Table '$table' does not exist on target database");
            return false;
        }
        
        // Check if record exists
        $check = mysqli_query($conn, "SELECT * FROM $table WHERE id = $record_id");
        $exists = mysqli_num_rows($check) > 0;
        $existing = mysqli_fetch_assoc($check);
        
        switch ($operation) {
            case 'INSERT':
                if (!$exists) {
                    $columns = array_keys($data);
                    $columns[] = 'id';
                    $columns[] = 'device_id';
                    $columns[] = 'is_synced';
                    
                    $values = array_map(function($v) use ($conn) {
                        if ($v === null) return 'NULL';
                        return "'" . mysqli_real_escape_string($conn, $v) . "'";
                    }, array_values($data));
                    $values[] = $record_id;
                    $values[] = "'" . mysqli_real_escape_string($conn, $log['device_id']) . "'";
                    $values[] = "1";
                    
                    $sql = "INSERT INTO $table (" . implode(', ', $columns) . ") 
                            VALUES (" . implode(', ', $values) . ")";
                    return mysqli_query($conn, $sql);
                }
                return true;
                
            case 'UPDATE':
                if ($exists) {
                    // Conflict resolution: check timestamp
                    if (isset($existing['updated_at']) && isset($log['created_at'])) {
                        if (strtotime($existing['updated_at']) > strtotime($log['created_at'])) {
                            logMessage("Conflict: Local version newer, skipping record $record_id");
                            return true;
                        }
                    }
                    
                    $set = [];
                    foreach ($data as $key => $value) {
                        if ($value === null) {
                            $set[] = "$key = NULL";
                        } else {
                            $set[] = "$key = '" . mysqli_real_escape_string($conn, $value) . "'";
                        }
                    }
                    $set[] = "device_id = '" . mysqli_real_escape_string($conn, $log['device_id']) . "'";
                    $set[] = "is_synced = 1";
                    
                    $sql = "UPDATE $table SET " . implode(', ', $set) . " WHERE id = $record_id";
                    return mysqli_query($conn, $sql);
                }
                return true;
                
            case 'DELETE':
                if ($exists) {
                    $sql = "UPDATE $table SET is_deleted = 1, is_synced = 1 WHERE id = $record_id";
                    return mysqli_query($conn, $sql);
                }
                return true;
        }
        
        return false;
    }
    
    private function updateSyncStatus($pushed, $pulled, $status, $error = null) {
        $error_msg = $error ? "'" . mysqli_real_escape_string($this->local_conn, $error) . "'" : 'NULL';
        $sql = "INSERT INTO sync_status 
                (last_sync_time, remote_device_ip, sync_direction, records_pushed, records_pulled, status, error_message) 
                VALUES (NOW(), '" . REMOTE_IP . "', 'BOTH', $pushed, $pulled, '$status', $error_msg)";
        mysqli_query($this->local_conn, $sql);
    }
}
?>