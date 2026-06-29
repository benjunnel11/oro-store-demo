<?php
/**
 * Cloud Stock Sync Module
 *
 * Uses a cloud MySQL as the single source of truth for stock.
 * Each device pushes its own store's stock changes to the cloud.
 * Each device pulls other stores' stock from the cloud.
 *
 * Cloud table: cloud_stock (minimal — no product names stored)
 *   - product_uid (unique: product_id + store_id)
 *   - product_id, store_id, stock
 *   - updated_at, updated_by_device
 */

require_once __DIR__ . '/cloud_config.php';
require_once __DIR__ . '/config.php';

function cloudStockInit() {
    $cloud = getCloudConnection();
    if (!$cloud) return false;

    $cloud->query("CREATE TABLE IF NOT EXISTS cloud_stock (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_uid VARCHAR(64) NOT NULL UNIQUE,
        product_id INT NOT NULL,
        store_id INT NOT NULL,
        stock INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by_device VARCHAR(50) DEFAULT NULL,
        INDEX idx_store (store_id),
        INDEX idx_product_store (product_id, store_id)
    )");

    // Drop product_name column if it exists (cleanup)
    $cloud->query("ALTER TABLE cloud_stock DROP COLUMN IF EXISTS product_name");

    $cloud->close();
    return true;
}

function _productUid($product_id, $store_id) {
    return 'p' . intval($product_id) . '_s' . intval($store_id);
}

function cloudStockPush($product_id, $store_id, $stock) {
    $cloud = getCloudConnection();
    if (!$cloud) {
        _cloudQueuePush($product_id, $store_id, $stock);
        return false;
    }

    $uid = _productUid($product_id, $store_id);
    $device = LOCAL_DEVICE_ID;
    $stock = intval($stock);
    $product_id = intval($product_id);
    $store_id = intval($store_id);

    $stmt = $cloud->prepare("INSERT INTO cloud_stock (product_uid, product_id, store_id, stock, updated_by_device)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE stock = VALUES(stock), updated_by_device = VALUES(updated_by_device), updated_at = NOW()");
    $stmt->bind_param("siiis", $uid, $product_id, $store_id, $stock, $device);
    $stmt->execute();
    $stmt->close();
    $cloud->close();
    return true;
}

function cloudStockPull($local_conn, $my_store_id) {
    $cloud = getCloudConnection();
    if (!$cloud) return 0;

    $my_store_id = intval($my_store_id);

    $stmt = $cloud->prepare("SELECT product_id, store_id, stock FROM cloud_stock WHERE store_id != ?");
    $stmt->bind_param("i", $my_store_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $updated = 0;
    while ($row = $result->fetch_assoc()) {
        $pid = intval($row['product_id']);
        $sid = intval($row['store_id']);
        $stk = intval($row['stock']);

        $check = $local_conn->query("SELECT id, stock FROM store_prices WHERE product_id = $pid AND store_id = $sid AND is_deleted = 0");
        if ($check && $existing = $check->fetch_assoc()) {
            if ((int)$existing['stock'] !== $stk) {
                $local_conn->query("SET @is_syncing = 1");
                $local_conn->query("UPDATE store_prices SET stock = $stk WHERE product_id = $pid AND store_id = $sid AND is_deleted = 0");
                $local_conn->query("SET @is_syncing = 0");
                $updated++;
            }
        }
    }
    $stmt->close();
    $cloud->close();
    return $updated;
}

function cloudStockPushAll($local_conn, $my_store_id) {
    $cloud = getCloudConnection();
    if (!$cloud) return 0;

    $my_store_id = intval($my_store_id);
    $device = LOCAL_DEVICE_ID;

    $r = $local_conn->query("SELECT sp.product_id, sp.store_id, sp.stock
        FROM store_prices sp
        WHERE sp.store_id = $my_store_id AND sp.is_deleted = 0");

    $pushed = 0;
    while ($row = $r->fetch_assoc()) {
        $uid = _productUid($row['product_id'], $row['store_id']);
        $stmt = $cloud->prepare("INSERT INTO cloud_stock (product_uid, product_id, store_id, stock, updated_by_device)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE stock = VALUES(stock), updated_by_device = VALUES(updated_by_device), updated_at = NOW()");
        $stmt->bind_param("siiis", $uid, $row['product_id'], $row['store_id'], $row['stock'], $device);
        $stmt->execute();
        $stmt->close();
        $pushed++;
    }

    $cloud->close();
    return $pushed;
}

function _cloudQueuePush($product_id, $store_id, $stock) {
    $file = __DIR__ . '/cloud_stock_queue.json';
    $queue = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    $queue[] = ['product_id' => intval($product_id), 'store_id' => intval($store_id), 'stock' => intval($stock), 'time' => date('Y-m-d H:i:s')];
    file_put_contents($file, json_encode($queue));
}

function cloudFlushQueue() {
    $file = __DIR__ . '/cloud_stock_queue.json';
    if (!file_exists($file)) return 0;
    $queue = json_decode(file_get_contents($file), true) ?: [];
    if (empty($queue)) return 0;

    $cloud = getCloudConnection();
    if (!$cloud) return 0;

    $flushed = 0;
    $device = LOCAL_DEVICE_ID;
    foreach ($queue as $item) {
        $uid = _productUid($item['product_id'], $item['store_id']);
        $stmt = $cloud->prepare("INSERT INTO cloud_stock (product_uid, product_id, store_id, stock, updated_by_device)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE stock = VALUES(stock), updated_by_device = VALUES(updated_by_device), updated_at = NOW()");
        $stmt->bind_param("siiis", $uid, $item['product_id'], $item['store_id'], $item['stock'], $device);
        $stmt->execute();
        $stmt->close();
        $flushed++;
    }

    $cloud->close();
    file_put_contents($file, '[]');
    return $flushed;
}

// ── Cloud-based table sync (GCash accounts, users, etc.) ──

function cloudTableInit() {
    $cloud = getCloudConnection();
    if (!$cloud) return false;
    $cloud->query("CREATE TABLE IF NOT EXISTS cloud_shared_data (
        id INT AUTO_INCREMENT PRIMARY KEY,
        table_name VARCHAR(50) NOT NULL,
        record_id INT NOT NULL,
        data JSON NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by_device VARCHAR(50) DEFAULT NULL,
        UNIQUE KEY unique_record (table_name, record_id)
    )");
    $cloud->close();
    return true;
}

function cloudPushTable($local_conn, $table, $where = "is_deleted = 0") {
    $cloud = getCloudConnection();
    if (!$cloud) return 0;

    $device = LOCAL_DEVICE_ID;
    $r = $local_conn->query("SELECT * FROM `$table` WHERE $where");
    if (!$r) { $cloud->close(); return 0; }

    $pushed = 0;
    while ($row = $r->fetch_assoc()) {
        $rid = intval($row['id']);
        $data = $cloud->real_escape_string(json_encode($row));
        $table_e = $cloud->real_escape_string($table);
        $cloud->query("INSERT INTO cloud_shared_data (table_name, record_id, data, updated_by_device)
            VALUES ('$table_e', $rid, '$data', '$device')
            ON DUPLICATE KEY UPDATE data = VALUES(data), updated_by_device = VALUES(updated_by_device), updated_at = NOW()");
        $pushed++;
    }
    $cloud->close();
    return $pushed;
}

function cloudPullTable($local_conn, $table) {
    $cloud = getCloudConnection();
    if (!$cloud) return 0;

    $table_e = $cloud->real_escape_string($table);
    $r = $cloud->query("SELECT record_id, data FROM cloud_shared_data WHERE table_name = '$table_e'");
    if (!$r) { $cloud->close(); return 0; }

    $updated = 0;
    $local_conn->query("SET @is_syncing = 1");
    while ($row = $r->fetch_assoc()) {
        $record = json_decode($row['data'], true);
        if (!$record || empty($record['id'])) continue;

        $cols = []; $vals = []; $updates = [];
        foreach ($record as $k => $v) {
            if ($k === 'is_synced') continue;
            $cols[] = "`$k`";
            $val = $v === null ? 'NULL' : "'" . $local_conn->real_escape_string($v) . "'";
            $vals[] = $val;
            if ($k !== 'id') $updates[] = "`$k` = $val";
        }
        if (!empty($updates)) {
            $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ") ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
            if (@$local_conn->query($sql)) $updated++;
        }
    }
    $local_conn->query("SET @is_syncing = 0");
    $cloud->close();
    return $updated;
}
