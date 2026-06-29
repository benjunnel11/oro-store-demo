<?php
// sync/test_sync.php - Test the sync system
require_once 'sync_helper.php';

echo "=== Testing Sync System on Device A ===\n\n";

$db = new SyncDB();

// Test 1: Check configuration
echo "Test 1: Configuration Check\n";
echo "  Device ID: " . LOCAL_DEVICE_ID . "\n";
echo "  Database: " . DB_NAME . "\n";
echo "  Tables to sync: " . implode(', ', SYNC_TABLES) . "\n";
echo "  Remote IP: " . REMOTE_IP . "\n\n";

// Test 2: Check sync tables
echo "Test 2: Checking sync tables...\n";
$conn = $db->getConnection();
$tables = ['sync_log', 'sync_status'];
foreach ($tables as $table) {
    $result = mysqli_query($conn, "SELECT COUNT(*) as count FROM $table");
    if ($result) {
        $row = mysqli_fetch_assoc($result);
        echo "  ✓ $table exists (records: {$row['count']})\n";
    } else {
        echo "  ✗ $table not found\n";
    }
}
echo "\n";

// Test 3: Insert a test product
echo "Test 3: Inserting a test product...\n";
$product_id = $db->insert('products', [
    'name' => 'Test Product from Device A',
    'price' => 99.99,
    'description' => 'Created at ' . date('Y-m-d H:i:s')
]);

if ($product_id) {
    echo "  ✓ Inserted product ID: $product_id\n\n";
} else {
    echo "  ✗ Insert failed\n\n";
}

// Test 4: View all products
echo "Test 4: Viewing products...\n";
$products = $db->select('products', '1=1', 'id, name, price, device_id');
echo "  Found " . count($products) . " products:\n";
foreach (array_slice($products, 0, 5) as $product) {
    echo "    - ID: {$product['id']}, Name: {$product['name']}, Device: {$product['device_id']}\n";
}
if (count($products) > 5) {
    echo "    ... and " . (count($products) - 5) . " more\n";
}
echo "\n";

// Test 5: Update the test product
if ($product_id) {
    echo "Test 5: Updating product ID $product_id...\n";
    $db->update('products', [
        'price' => 79.99,
        'description' => 'Updated at ' . date('Y-m-d H:i:s')
    ], "id = $product_id");
    echo "  ✓ Product updated\n\n";
}

// Test 6: Check sync log
echo "Test 6: Checking sync log...\n";
$result = mysqli_query($conn, "SELECT COUNT(*) as count FROM sync_log WHERE synced = 0");
$row = mysqli_fetch_assoc($result);
echo "  Unsynced changes: {$row['count']}\n\n";

// Test 7: Get sync statistics
echo "Test 7: Sync Statistics\n";
$stats = $db->getSyncStats();
echo "  Last sync: {$stats['last_sync']}\n";
echo "  Total unsynced: {$stats['total_unsynced']}\n";
foreach ($stats['unsynced'] as $table => $count) {
    if ($count > 0) {
        echo "    - $table: $count unsynced\n";
    }
}
echo "\n";

// Test 8: Test remote connection
echo "Test 8: Testing remote connection...\n";
if (isRemoteAvailable()) {
    echo "  ✓ Remote device is reachable at " . REMOTE_IP . "\n";
    $remote = getRemoteConnection();
    if ($remote) {
        echo "  ✓ Remote database connection successful\n";
        mysqli_close($remote);
    } else {
        echo "  ✗ Remote database connection failed\n";
        echo "    Make sure sync_user is created on remote device\n";
    }
} else {
    echo "  ⚠ Remote device not reachable (this is normal if Device B is not set up yet)\n";
}
echo "\n";

echo "=== Test Complete ===\n";
echo "\nNext steps:\n";
echo "1. Create sync_user in phpMyAdmin (see setup_sync_tables.php output)\n";
echo "2. Configure MySQL for remote access (my.ini)\n";
echo "3. Set up Device B with the same system\n";
echo "4. Update REMOTE_IP in config.php once Device B is ready\n";
echo "5. Run: php auto_sync.php\n";
?>