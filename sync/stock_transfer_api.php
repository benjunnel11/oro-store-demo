<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../core/db_config.php';
header('Content-Type: application/json');

$key = $_GET['key'] ?? ($_SERVER['HTTP_X_SYNC_KEY'] ?? '');
if ($key !== SYNC_PASSWORD) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit; }

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) { echo json_encode(['error' => 'DB connection failed']); exit; }

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? ($_GET['action'] ?? '');

// Receive stock from another device
if ($action === 'receive_stock') {
    $product_id = intval($input['product_id'] ?? 0);
    $store_id = intval($input['store_id'] ?? 0);
    $quantity = intval($input['quantity'] ?? 0);
    $price = floatval($input['price'] ?? 0);
    $purchase_price = floatval($input['purchase_price'] ?? 0);

    if (!$product_id || !$store_id || !$quantity) {
        echo json_encode(['success' => false, 'message' => 'Missing product_id, store_id, or quantity']); exit;
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    // Check if store_prices exists for this product+store
    $stmt = $conn->prepare("SELECT id, stock FROM store_prices WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
    $stmt->bind_param("ii", $product_id, $store_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $new_stock = $existing['stock'] + $quantity;
        $conn->query("UPDATE store_prices SET stock = $new_stock WHERE id = " . $existing['id']);
        echo json_encode(['success' => true, 'new_stock' => $new_stock, 'action' => 'updated']);
    } else {
        $stmt = $conn->prepare("INSERT INTO store_prices (product_id, store_id, stock, price, purchase_price, is_deleted) VALUES (?, ?, ?, ?, ?, 0)");
        $stmt->bind_param("iiids", $product_id, $store_id, $quantity, $price, $purchase_price);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => true, 'new_stock' => $quantity, 'action' => 'created']);
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->close();
    exit;
}

// Reduce stock on source device (called remotely) — atomic
if ($action === 'reduce_stock') {
    $product_id = intval($input['product_id'] ?? 0);
    $store_id = intval($input['store_id'] ?? 0);
    $quantity = intval($input['quantity'] ?? 0);

    if (!$product_id || !$store_id || !$quantity) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']); exit;
    }

    $stmt = $conn->prepare("SELECT stock FROM store_prices WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
    $stmt->bind_param("ii", $product_id, $store_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) { echo json_encode(['success' => false, 'message' => 'Product not found']); exit; }
    if ($row['stock'] < $quantity) { echo json_encode(['success' => false, 'message' => 'Insufficient stock']); exit; }

    $neg = -$quantity;
    $stmt = $conn->prepare("UPDATE store_prices SET stock = stock + ? WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
    $stmt->bind_param("iii", $neg, $product_id, $store_id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("SELECT stock FROM store_prices WHERE product_id = ? AND store_id = ?");
    $stmt->bind_param("ii", $product_id, $store_id);
    $stmt->execute();
    $new_stock = $stmt->get_result()->fetch_assoc()['stock'];
    $stmt->close();

    echo json_encode(['success' => true, 'new_stock' => $new_stock]);
    $conn->close();
    exit;
}

// Adjust stock by delta (atomic, safe for multi-device)
if ($action === 'adjust_stock') {
    $product_id = intval($input['product_id'] ?? 0);
    $store_id = intval($input['store_id'] ?? 0);
    $change = intval($input['change'] ?? 0);
    if ($product_id && $store_id && $change != 0) {
        $stmt = $conn->prepare("UPDATE store_prices SET stock = stock + ? WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
        $stmt->bind_param("iii", $change, $product_id, $store_id);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['success' => true]);
    $conn->close();
    exit;
}

// Set stock to exact value (fallback, used when delta is unknown)
if ($action === 'set_stock') {
    $product_id = intval($input['product_id'] ?? 0);
    $store_id = intval($input['store_id'] ?? 0);
    $stock = intval($input['stock'] ?? 0);
    if ($product_id && $store_id) {
        $stmt = $conn->prepare("UPDATE store_prices SET stock = ? WHERE product_id = ? AND store_id = ? AND is_deleted = 0");
        $stmt->bind_param("iii", $stock, $product_id, $store_id);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['success' => true]);
    $conn->close();
    exit;
}

// Record a transfer from another device
if ($action === 'record_transfer') {
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    $id = intval($input['id'] ?? 0);
    $from = intval($input['from_store_id'] ?? 0);
    $to = intval($input['to_store_id'] ?? 0);
    $pid = intval($input['product_id'] ?? 0);
    $qty = intval($input['quantity'] ?? 0);
    $notes = $input['notes'] ?? '';
    $batch = $input['transfer_batch_id'] ?? '';
    $by = intval($input['transferred_by'] ?? 0);
    $by_name = $input['transferred_by_name'] ?? '';

    // Use the sender's user ID, or find/create a matching user
    if ($by > 0) {
        $u = $conn->query("SELECT id FROM users WHERE id = $by");
        if (!$u || $u->num_rows === 0) $by = 1; // fallback to admin
    }

    // Insert transfer record (skip if exists)
    $exists = $conn->query("SELECT id FROM stock_transfers WHERE id = $id");
    if (!$exists || $exists->num_rows === 0) {
        $stmt = $conn->prepare("INSERT INTO stock_transfers (from_store_id, to_store_id, product_id, quantity, notes, transfer_batch_id, transferred_by) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("iiiissi", $from, $to, $pid, $qty, $notes, $batch, $by);
        $stmt->execute();
        $stmt->close();
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    echo json_encode(['success' => true]);
    $conn->close();
    exit;
}

echo json_encode(['error' => 'Unknown action']);
$conn->close();
