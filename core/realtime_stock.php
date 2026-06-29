<?php
/**
 * Dual-write stock change to remote database.
 * Device A is main server. All devices write changes to both local AND remote (Device A).
 * If remote is offline, changes are queued and flushed when sync runs.
 * Uses a cached connection check so it only tests reachability once per request.
 */

$_remoteStatus = null;  // null = not checked, true = online, false = offline
$_remoteConn = null;    // cached connection

function _getRemoteOnce() {
    global $_remoteStatus, $_remoteConn;
    if ($_remoteStatus === false) return false;
    if ($_remoteStatus === true && $_remoteConn) return $_remoteConn;

    @include_once __DIR__ . '/../sync/config.php';
    $_remoteConn = @getRemoteConnection();
    $_remoteStatus = $_remoteConn ? true : false;
    return $_remoteConn;
}

function pushStockChange($product_id, $store_id, $new_stock, $change = null) {
    if ($change === null || $change == 0) return;

    $product_id = intval($product_id);
    $store_id = intval($store_id);
    $change = intval($change);

    $remote = _getRemoteOnce();
    if (!$remote) {
        _queueStockChange($product_id, $store_id, $change);
        return;
    }

    $stmt = $remote->prepare("UPDATE store_prices SET stock = stock + ? WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
    if ($stmt) {
        $stmt->bind_param("iii", $change, $product_id, $store_id);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Queue a stock change for when the remote comes back online
 */
function _queueStockChange($product_id, $store_id, $change) {
    $queue_file = __DIR__ . '/../sync/stock_queue.json';
    $queue = [];
    if (file_exists($queue_file)) {
        $queue = json_decode(file_get_contents($queue_file), true) ?: [];
    }
    $queue[] = [
        'product_id' => intval($product_id),
        'store_id' => intval($store_id),
        'change' => intval($change),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    file_put_contents($queue_file, json_encode($queue));
}

/**
 * Flush queued stock changes to remote (call this periodically or on reconnect)
 */
function flushStockQueue() {
    $queue_file = __DIR__ . '/../sync/stock_queue.json';
    if (!file_exists($queue_file)) return 0;

    $queue = json_decode(file_get_contents($queue_file), true) ?: [];
    if (empty($queue)) return 0;

    @include_once __DIR__ . '/../sync/config.php';
    $remote = @getRemoteConnection();
    if (!$remote) return 0;

    $flushed = 0;
    foreach ($queue as $item) {
        $stmt = $remote->prepare("UPDATE store_prices SET stock = stock + ? WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
        if ($stmt) {
            $stmt->bind_param("iii", $item['change'], $item['product_id'], $item['store_id']);
            $stmt->execute();
            $stmt->close();
            $flushed++;
        }
    }
    $remote->close();

    // Clear the queue
    file_put_contents($queue_file, '[]');
    return $flushed;
}
