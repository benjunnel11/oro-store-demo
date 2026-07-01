<?php
// sync/config.php - Device Configuration (credentials from .local_env)

$_local_env_file = __DIR__ . '/.local_env';
$_local_env = [];
if (file_exists($_local_env_file)) {
    $_local_env = json_decode(file_get_contents($_local_env_file), true) ?: [];
}

// Device identification
if (!defined('LOCAL_DEVICE_ID')) define('LOCAL_DEVICE_ID', $_local_env['device_id'] ?? 'DEVICE_A');

// Remote device settings
if (!defined('REMOTE_IP')) define('REMOTE_IP', $_local_env['remote_ip'] ?? '');
if (!defined('REMOTE_PORT')) define('REMOTE_PORT', $_local_env['remote_port'] ?? '80');

// Sync credentials
if (!defined('SYNC_USER')) define('SYNC_USER', $_local_env['sync_user'] ?? 'sync_user');
if (!defined('SYNC_PASSWORD')) define('SYNC_PASSWORD', $_local_env['sync_pass'] ?? '');

// Database name
if (!defined('DB_NAME')) define('DB_NAME', $_local_env['db_name'] ?? 'product_db');

// Tables to sync
define('SYNC_TABLES', [
    'gcash_transactions',
    'product_history',
    'product_history_backup',
    'products',
    'store_prices',
    'store_products',
    'stores',
    'system_logs',
    'transaction_items',
    'transactions',
    'users',
    'user_sessions',
    'deliveries',
    'delivery_items',
    'delivery_fee_categories',
    'customers',
    'credits',
    'atm_transactions',
    'atm_settlements',
    'cash_transactions',
    'employee_roles',
    'employees',
    'employee_attendance',
    'employee_cash_advances',
    'system_settings',
    'angkat_items',
    'angkat_transactions',
    'angkat_payments',
    'angkat_returns',
    'angkat_charge_categories',
    'credit_charge_categories',
    'delivery_charge_categories',
    'stock_receipts',
    'stock_receipt_items',
    'stock_transfers',
    'gcash_accounts',
    'bank_accounts',
    'bank_transactions',
    'expenses',
    'product_categories',
    'product_brands',
]);

// Local database connection
function getLocalConnection() {
    require_once __DIR__ . '/../core/db_config.php';
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$conn) {
        die('Local connection failed: ' . mysqli_connect_error());
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

// Remote database connection (5 second timeout, no pre-check)
function getRemoteConnection() {
    if (!REMOTE_IP) return false;
    $prev = mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $conn = mysqli_init();
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        @$conn->real_connect(REMOTE_IP, SYNC_USER, SYNC_PASSWORD, DB_NAME, REMOTE_PORT);
        mysqli_report($prev);
        if ($conn->connect_error) return false;
        $conn->set_charset("utf8mb4");
        return $conn;
    } catch (\Throwable $e) {
        mysqli_report($prev);
        return false;
    }
}

// Check if remote device is available (uses HTTP like the actual sync)
function isRemoteAvailable() {
    if (!REMOTE_IP) return false;
    $url = "http://" . REMOTE_IP . ":" . REMOTE_PORT . "/oro-store-demo/sync/sync_api.php?action=status&key=" . urlencode(SYNC_PASSWORD);
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $r = @file_get_contents($url, false, $ctx);
    return ($r !== false);
}

// Log function
function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $message\n";
    $log_file = __DIR__ . '/sync.log';
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

function getCurrentTimestamp() {
    return date('Y-m-d H:i:s');
}
?>
