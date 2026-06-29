<?php
/**
 * Cloud MySQL Configuration for Stock Sync
 * Credentials are stored in sync/.cloud_env (not in code)
 */

// Load credentials from .cloud_env file
$_cloud_env_file = __DIR__ . '/.cloud_env';
$_cloud_env = [];
if (file_exists($_cloud_env_file)) {
    $_cloud_env = json_decode(file_get_contents($_cloud_env_file), true) ?: [];
}

define('CLOUD_DB_HOST', $_cloud_env['host'] ?? '');
define('CLOUD_DB_USER', $_cloud_env['user'] ?? '');
define('CLOUD_DB_PASS', $_cloud_env['pass'] ?? '');
define('CLOUD_DB_NAME', $_cloud_env['name'] ?? 'oro_cloud_stock');
define('CLOUD_DB_PORT', intval($_cloud_env['port'] ?? 4000));

/**
 * Save cloud credentials to .cloud_env file
 */
function saveCloudCredentials($host, $user, $pass, $name = 'oro_cloud_stock', $port = 4000) {
    $file = __DIR__ . '/.cloud_env';
    $data = json_encode([
        'host' => $host,
        'user' => $user,
        'pass' => $pass,
        'name' => $name,
        'port' => intval($port)
    ]);
    return file_put_contents($file, $data) !== false;
}

/**
 * Get cloud database connection (TiDB Cloud requires SSL)
 */
function getCloudConnection() {
    if (!CLOUD_DB_HOST || !CLOUD_DB_PASS) return false;

    $prev = mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $conn = mysqli_init();
        $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
        $conn->ssl_set(null, null, null, null, null);
        @$conn->real_connect(CLOUD_DB_HOST, CLOUD_DB_USER, CLOUD_DB_PASS, '', CLOUD_DB_PORT, null, MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT);
        if ($conn->connect_error) {
            mysqli_report($prev);
            return false;
        }
        $conn->set_charset("utf8mb4");
        $conn->query("CREATE DATABASE IF NOT EXISTS `" . CLOUD_DB_NAME . "`");
        $conn->select_db(CLOUD_DB_NAME);
        mysqli_report($prev);
        return $conn;
    } catch (\Throwable $e) {
        mysqli_report($prev);
        return false;
    }
}
