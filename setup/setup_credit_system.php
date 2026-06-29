<?php
/**
 * Credit System Setup Script
 * Run this file once to set up the credit system
 * Access via: your-domain.com/setup_credit_system.php
 */

require_once __DIR__ . '/../core/db_connection.php';

echo "<h1>Credit System Setup</h1>";
echo "<pre>";

// Step 1: Create/Update customers table
echo "Step 1: Creating/Updating customers table...\n";
$sql = "CREATE TABLE IF NOT EXISTS `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `contact_number` varchar(50) DEFAULT NULL,
  `address` text,
  `store_id` int(11) DEFAULT NULL,
  `device_id` varchar(50) DEFAULT NULL,
  `sync_status` varchar(20) DEFAULT 'synced',
  `last_sync` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_store_id` (`store_id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_sync_status` (`sync_status`),
  KEY `idx_is_deleted` (`is_deleted`),
  KEY `idx_customer_store` (`store_id`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql) === TRUE) {
    echo "✓ Customers table created/verified\n";
} else {
    echo "✗ Error: " . $conn->error . "\n";
}

// Add missing columns if table exists
$columns_to_add = [
    "ALTER TABLE `customers` ADD COLUMN IF NOT EXISTS `device_id` varchar(50) DEFAULT NULL AFTER `store_id`",
    "ALTER TABLE `customers` ADD COLUMN IF NOT EXISTS `sync_status` varchar(20) DEFAULT 'synced' AFTER `device_id`",
    "ALTER TABLE `customers` ADD COLUMN IF NOT EXISTS `last_sync` timestamp NULL DEFAULT NULL AFTER `sync_status`"
];

foreach ($columns_to_add as $sql) {
    $conn->query($sql);
}
echo "✓ Customer columns verified\n";

// Step 2: Create/Update credits table
echo "\nStep 2: Creating/Updating credits table...\n";
$sql = "CREATE TABLE IF NOT EXISTS `credits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `customer_contact` varchar(50) DEFAULT NULL,
  `customer_address` text,
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(10,2) NOT NULL DEFAULT '0.00',
  `amount_due` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `created_by` int(11) DEFAULT NULL,
  `store_id` int(11) DEFAULT NULL,
  `device_id` varchar(50) DEFAULT NULL,
  `sync_status` varchar(20) DEFAULT 'synced',
  `last_sync` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_transaction_id` (`transaction_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_store_id` (`store_id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_sync_status` (`sync_status`),
  KEY `idx_status` (`status`),
  KEY `idx_is_deleted` (`is_deleted`),
  KEY `idx_credit_customer_status` (`customer_id`, `status`, `is_deleted`),
  KEY `idx_credit_store_status` (`store_id`, `status`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql) === TRUE) {
    echo "✓ Credits table created/verified\n";
} else {
    echo "✗ Error: " . $conn->error . "\n";
}

// Add missing columns if table exists
$columns_to_add = [
    "ALTER TABLE `credits` ADD COLUMN IF NOT EXISTS `device_id` varchar(50) DEFAULT NULL AFTER `store_id`",
    "ALTER TABLE `credits` ADD COLUMN IF NOT EXISTS `sync_status` varchar(20) DEFAULT 'synced' AFTER `device_id`",
    "ALTER TABLE `credits` ADD COLUMN IF NOT EXISTS `last_sync` timestamp NULL DEFAULT NULL AFTER `sync_status`"
];

foreach ($columns_to_add as $sql) {
    $conn->query($sql);
}
echo "✓ Credit columns verified\n";

// Step 3: Check sync configuration
echo "\nStep 3: Checking sync configuration...\n";
$sync_helper_path = __DIR__ . '/sync/sync_helper.php';
if (file_exists($sync_helper_path)) {
    $content = file_get_contents($sync_helper_path);
    
    if (strpos($content, "'customers'") !== false && strpos($content, "'credits'") !== false) {
        echo "✓ Sync tables already configured\n";
    } else {
        echo "⚠ WARNING: You need to add 'customers' and 'credits' to SYNC_TABLES in sync/sync_helper.php\n";
        echo "\nAdd these lines to the SYNC_TABLES array:\n";
        echo "    'customers',\n";
        echo "    'credits',\n";
    }
} else {
    echo "⚠ sync_helper.php not found - sync may not be configured\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "SETUP COMPLETE!\n";
echo str_repeat("=", 60) . "\n";
echo "\nNext steps:\n";
echo "1. Update sync/sync_helper.php to include 'customers' and 'credits' in SYNC_TABLES\n";
echo "2. Update cashier.php keyboard shortcuts to add F5 and F6\n";
echo "3. Test the credit system!\n";
echo "\nKeyboard shortcuts:\n";
echo "- F5: Open Credit Mode\n";
echo "- F6: View Credit Details\n";

echo "</pre>";

$conn->close();
?>