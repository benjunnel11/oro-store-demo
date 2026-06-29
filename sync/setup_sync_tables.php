<?php
// sync/setup_sync_tables.php
require_once 'config.php';

echo "=== Setting up Sync System for Device A ===\n\n";

$conn = getLocalConnection();

// Step 1: Add sync columns to all existing tables
echo "Step 1: Adding sync columns to existing tables...\n";

foreach (SYNC_TABLES as $table) {
    echo "  Processing table: $table\n";
    
    // Check if table exists
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check->num_rows == 0) {
        echo "    ⚠ Warning: Table '$table' does not exist. Skipping.\n";
        continue;
    }
    
    // Add sync columns if they don't exist
    $columns_to_add = [
        "updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        "device_id VARCHAR(50) DEFAULT 'DEVICE_A'",
        "is_synced BOOLEAN DEFAULT 0",
        "is_deleted BOOLEAN DEFAULT 0"
    ];
    
    foreach ($columns_to_add as $column_def) {
        $column_name = explode(' ', $column_def)[0];
        
        // Check if column exists
        $check_col = $conn->query("SHOW COLUMNS FROM $table LIKE '$column_name'");
        
        if ($check_col->num_rows == 0) {
            $sql = "ALTER TABLE $table ADD COLUMN $column_def";
            if ($conn->query($sql)) {
                echo "    ✓ Added column: $column_name\n";
            } else {
                echo "    ✗ Error adding column $column_name: " . $conn->error . "\n";
            }
        } else {
            echo "    - Column $column_name already exists\n";
        }
    }
    
    // Add indexes for better performance
    $conn->query("ALTER TABLE $table ADD INDEX idx_sync (is_synced, updated_at)");
    $conn->query("ALTER TABLE $table ADD INDEX idx_device (device_id)");
    $conn->query("ALTER TABLE $table ADD INDEX idx_deleted (is_deleted)");
    
    echo "    ✓ Table $table configured for sync\n\n";
}

// Step 2: Create sync_log table
echo "Step 2: Creating sync_log table...\n";
$sql = "CREATE TABLE IF NOT EXISTS sync_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    table_name VARCHAR(100) NOT NULL,
    record_id INT NOT NULL,
    operation VARCHAR(10) NOT NULL,
    data TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    synced BOOLEAN DEFAULT 0,
    device_id VARCHAR(50),
    INDEX idx_sync (synced, created_at),
    INDEX idx_table (table_name, record_id),
    INDEX idx_device (device_id)
)";

if ($conn->query($sql)) {
    echo "  ✓ sync_log table created\n\n";
} else {
    echo "  ✗ Error: " . $conn->error . "\n\n";
}

// Step 3: Create sync_status table
echo "Step 3: Creating sync_status table...\n";
$sql = "CREATE TABLE IF NOT EXISTS sync_status (
    id INT PRIMARY KEY AUTO_INCREMENT,
    last_sync_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    remote_device_ip VARCHAR(50),
    sync_direction VARCHAR(10),
    records_pushed INT DEFAULT 0,
    records_pulled INT DEFAULT 0,
    status VARCHAR(20),
    error_message TEXT
)";

if ($conn->query($sql)) {
    echo "  ✓ sync_status table created\n\n";
} else {
    echo "  ✗ Error: " . $conn->error . "\n\n";
}

// Step 4: Create sync user for remote access
echo "Step 4: Creating sync user...\n";
echo "  Run these commands in phpMyAdmin or MySQL:\n\n";
echo "  CREATE USER 'sync_user'@'%' IDENTIFIED BY 'sync_pass';\n";
echo "  GRANT ALL PRIVILEGES ON product_db.* TO 'sync_user'@'%';\n";
echo "  FLUSH PRIVILEGES;\n\n";

// Step 5: Show current configuration
echo "Step 5: Configuration Summary\n";
echo "  Device ID: " . LOCAL_DEVICE_ID . "\n";
echo "  Database: " . DB_NAME . "\n";
echo "  Tables to sync: " . implode(', ', SYNC_TABLES) . "\n";
echo "  Remote IP: " . REMOTE_IP . " (Update this in config.php when Device B is ready)\n\n";

// Step 6: Verify setup
echo "Step 6: Verifying setup...\n";
$result = $conn->query("SELECT COUNT(*) as count FROM sync_log");
$row = $result->fetch_assoc();
echo "  ✓ sync_log table accessible (records: {$row['count']})\n";

$result = $conn->query("SELECT COUNT(*) as count FROM sync_status");
$row = $result->fetch_assoc();
echo "  ✓ sync_status table accessible (records: {$row['count']})\n\n";

mysqli_close($conn);

echo "=== Setup Complete! ===\n\n";
echo "Next steps:\n";
echo "1. Run the SQL commands above in phpMyAdmin to create the sync user\n";
echo "2. Configure MySQL to accept remote connections (my.ini)\n";
echo "3. Update REMOTE_IP in config.php when Device B is ready\n";
echo "4. Test with: php test_sync.php\n";
?>