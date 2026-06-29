<?php
// Load credentials from env file
$_local_env_file = __DIR__ . '/../sync/.local_env';
$_local_env = [];
if (file_exists($_local_env_file)) {
    $_local_env = json_decode(file_get_contents($_local_env_file), true) ?: [];
}

if (!defined('DB_HOST')) define('DB_HOST', $_local_env['db_host'] ?? '127.0.0.1');
if (!defined('DB_USER')) define('DB_USER', $_local_env['db_user'] ?? 'root');
if (!defined('DB_PASS')) define('DB_PASS', $_local_env['db_pass'] ?? '');
if (!defined('DB_NAME')) define('DB_NAME', $_local_env['db_name'] ?? 'product_db');
