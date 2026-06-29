<?php
// product_stock_helper.php - Individual stock management with pack auto-opening

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/../sync/sync_helper.php';
require_once __DIR__ . '/realtime_stock.php';

/**
 * Check if product is an individual product
 */
function isIndividualProduct($product_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT parent_product_id FROM products WHERE id = ? AND is_deleted = 0");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();
        $stmt->close();
        return $product['parent_product_id'] !== null;
    }
    $stmt->close();
    return false;
}

/**
 * Get MAX CAP for individual product (pieces per pack)
 */
function getMaxIndividualCap($pack_product_id) {
    global $conn;

    $stmt = $conn->prepare("SELECT individual_pieces_per_pack FROM products WHERE id = ? AND is_deleted = 0");
    $stmt->bind_param("i", $pack_product_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $pack = $result->fetch_assoc();
        $stmt->close();
        return $pack['individual_pieces_per_pack'] ?? 0;
    }

    $stmt->close();
    return 0;
}

/**
 * Deduct individual stock with auto-pack opening
 */
function deductIndividualStock($product_id, $quantity_sold, $store_id = null) {
    global $conn;
    $db = new SyncDB();

    // Get individual product
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND is_deleted = 0");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $individual_product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$individual_product || !$individual_product['parent_product_id']) {
        throw new Exception("Invalid individual product");
    }

    $pack_product_id = $individual_product['parent_product_id'];

    // Get pack product
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND is_deleted = 0");
    $stmt->bind_param("i", $pack_product_id);
    $stmt->execute();
    $pack_product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pack_product) {
        throw new Exception("Parent pack product not found");
    }

    $pieces_per_pack = $pack_product['individual_pieces_per_pack'];

    if (!$pieces_per_pack || $pieces_per_pack <= 0) {
        throw new Exception("Invalid pieces per pack configuration");
    }

    // Get current stocks
    if ($store_id) {
        $stmt = $conn->prepare("SELECT stock FROM store_prices WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("ii", $product_id, $store_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $individual_stock = $result->num_rows > 0 ? $result->fetch_assoc()['stock'] : 0;
        $stmt->close();

        $stmt = $conn->prepare("SELECT stock FROM store_prices WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("ii", $pack_product_id, $store_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $pack_stock = $result->num_rows > 0 ? $result->fetch_assoc()['stock'] : 0;
        $stmt->close();
    } else {
        $individual_stock = $individual_product['stock'];
        $pack_stock = $pack_product['stock'];
    }

    // Calculate total available stock
    $total_available = $individual_stock + ($pack_stock * $pieces_per_pack);

    if ($total_available < $quantity_sold) {
        throw new Exception("Insufficient stock! Requested: $quantity_sold, Available: $total_available (Individual: $individual_stock, Packs: $pack_stock × $pieces_per_pack)");
    }

    $packs_opened = 0;
    $remaining_quantity = $quantity_sold;

    // Process the sale
    while ($remaining_quantity > 0) {
        if ($individual_stock >= $remaining_quantity) {
            $individual_stock -= $remaining_quantity;
            $remaining_quantity = 0;
        } else {
            if ($pack_stock <= 0) {
                throw new Exception("Logic error: No packs available but total stock check passed");
            }
            $remaining_quantity -= $individual_stock;
            $individual_stock = 0;
            $pack_stock -= 1;
            $packs_opened += 1;
            $individual_stock = $pieces_per_pack;
        }
    }

    if ($individual_stock < 0) {
        $individual_stock = 0;
    }

    // Update stocks in database
    if ($store_id) {
        $stmt = $conn->prepare("UPDATE store_prices SET stock = ? WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("iii", $pack_stock, $pack_product_id, $store_id);
        $stmt->execute();
        $stmt->close();
        _queuePush($pack_product_id, $store_id, $pack_stock);

        $stmt = $conn->prepare("UPDATE store_prices SET stock = ? WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("iii", $individual_stock, $product_id, $store_id);
        $stmt->execute();
        $stmt->close();
        _queuePush($product_id, $store_id, $individual_stock);

        if ($packs_opened > 0) {
            $db->logChange('store_prices', "product_id = $pack_product_id AND store_id = $store_id", 'UPDATE', [
                'stock_change' => -$packs_opened,
                'reason' => 'Opened for individual sale'
            ]);
        }

        $db->logChange('store_prices', "product_id = $product_id AND store_id = $store_id", 'UPDATE', [
            'stock_change' => -$quantity_sold,
            'packs_opened' => $packs_opened,
            'final_individual_stock' => $individual_stock
        ]);
    } else {
        $stmt = $conn->prepare("UPDATE products SET stock = ? WHERE id = ?");
        $stmt->bind_param("ii", $pack_stock, $pack_product_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE products SET stock = ? WHERE id = ?");
        $stmt->bind_param("ii", $individual_stock, $product_id);
        $stmt->execute();
        $stmt->close();

        if ($packs_opened > 0) {
            $db->logChange('products', $pack_product_id, 'UPDATE', [
                'stock_change' => -$packs_opened,
                'reason' => 'Opened for individual sale'
            ]);
        }

        $db->logChange('products', $product_id, 'UPDATE', [
            'stock_change' => -$quantity_sold,
            'packs_opened' => $packs_opened,
            'final_individual_stock' => $individual_stock
        ]);
    }

    return [
        'quantity_sold' => $quantity_sold,
        'packs_opened' => $packs_opened,
        'new_individual_stock' => $individual_stock,
        'new_pack_stock' => $pack_stock,
        'total_available_before' => $total_available
    ];
}

/**
 * Deduct pack stock (selling whole packs)
 */
function deductPackStock($product_id, $quantity_sold, $store_id = null) {
    global $conn;
    $db = new SyncDB();

    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND is_deleted = 0");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $pack_product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pack_product) {
        throw new Exception("Pack product not found");
    }

    if ($store_id) {
        $stmt = $conn->prepare("SELECT stock FROM store_prices WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("ii", $product_id, $store_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $pack_stock = $result->num_rows > 0 ? $result->fetch_assoc()['stock'] : 0;
        $stmt->close();
    } else {
        $pack_stock = $pack_product['stock'];
    }

    if ($pack_stock < $quantity_sold) {
        throw new Exception("Insufficient pack stock! Requested: $quantity_sold, Available: $pack_stock");
    }

    $new_pack_stock = $pack_stock - $quantity_sold;

    if ($store_id) {
        $stmt = $conn->prepare("UPDATE store_prices SET stock = ? WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("iii", $new_pack_stock, $product_id, $store_id);
        $stmt->execute();
        $stmt->close();
        _queuePush($product_id, $store_id, $new_pack_stock);

        $db->logChange('store_prices', "product_id = $product_id AND store_id = $store_id", 'UPDATE', [
            'stock_change' => -$quantity_sold,
            'reason' => 'Pack sold'
        ]);
    } else {
        $stmt = $conn->prepare("UPDATE products SET stock = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_pack_stock, $product_id);
        $stmt->execute();
        $stmt->close();

        $db->logChange('products', $product_id, 'UPDATE', [
            'stock_change' => -$quantity_sold,
            'reason' => 'Pack sold'
        ]);
    }

    return [
        'pack_sold' => $quantity_sold,
        'new_pack_stock' => $new_pack_stock
    ];
}

/**
 * Add stock to pack product (restocking)
 */
function addPackStock($product_id, $quantity_added, $store_id = null) {
    global $conn;
    $db = new SyncDB();

    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND is_deleted = 0");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $pack_product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pack_product) {
        throw new Exception("Pack product not found");
    }

    if ($store_id) {
        $stmt = $conn->prepare("SELECT stock FROM store_prices WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("ii", $product_id, $store_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $pack_stock = $result->num_rows > 0 ? $result->fetch_assoc()['stock'] : 0;
        $stmt->close();
    } else {
        $pack_stock = $pack_product['stock'];
    }

    $new_pack_stock = $pack_stock + $quantity_added;

    if ($store_id) {
        $stmt = $conn->prepare("UPDATE store_prices SET stock = ? WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("iii", $new_pack_stock, $product_id, $store_id);
        $stmt->execute();
        $stmt->close();
        _queuePush($product_id, $store_id, $new_pack_stock);

        $db->logChange('store_prices', "product_id = $product_id AND store_id = $store_id", 'UPDATE', [
            'stock_change' => $quantity_added,
            'reason' => 'Restocked'
        ]);
    } else {
        $stmt = $conn->prepare("UPDATE products SET stock = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_pack_stock, $product_id);
        $stmt->execute();
        $stmt->close();

        $db->logChange('products', $product_id, 'UPDATE', [
            'stock_change' => $quantity_added,
            'reason' => 'Restocked'
        ]);
    }

    return [
        'pack_added' => $quantity_added,
        'new_pack_stock' => $new_pack_stock
    ];
}

/**
 * Get total available stock for individual product
 */
function getTotalAvailableIndividualStock($product_id, $store_id = null) {
    global $conn;

    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND is_deleted = 0");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $individual_product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$individual_product || !$individual_product['parent_product_id']) {
        return 0;
    }

    $pack_product_id = $individual_product['parent_product_id'];

    $stmt = $conn->prepare("SELECT individual_pieces_per_pack FROM products WHERE id = ? AND is_deleted = 0");
    $stmt->bind_param("i", $pack_product_id);
    $stmt->execute();
    $pack_product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$pack_product) {
        return 0;
    }

    $pieces_per_pack = $pack_product['individual_pieces_per_pack'] ?? 0;

    if ($store_id) {
        $stmt = $conn->prepare("SELECT stock FROM store_prices WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("ii", $product_id, $store_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $individual_stock = $result->num_rows > 0 ? $result->fetch_assoc()['stock'] : 0;
        $stmt->close();

        $stmt = $conn->prepare("SELECT stock FROM store_prices WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("ii", $pack_product_id, $store_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $pack_stock = $result->num_rows > 0 ? $result->fetch_assoc()['stock'] : 0;
        $stmt->close();
    } else {
        $individual_stock = $individual_product['stock'];

        $stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
        $stmt->bind_param("i", $pack_product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $pack_stock = $result->num_rows > 0 ? $result->fetch_assoc()['stock'] : 0;
        $stmt->close();
    }

    return $individual_stock + ($pack_stock * $pieces_per_pack);
}

/**
 * Restore individual stock (for transaction re-edit/void)
 */
function restoreIndividualStock($product_id, $quantity_to_restore, $store_id = null) {
    global $conn;
    $db = new SyncDB();

    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND is_deleted = 0");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $individual_product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$individual_product || !$individual_product['parent_product_id']) {
        throw new Exception("Invalid individual product");
    }

    if ($store_id) {
        $stmt = $conn->prepare("SELECT stock FROM store_prices WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("ii", $product_id, $store_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $individual_stock = $result->num_rows > 0 ? $result->fetch_assoc()['stock'] : 0;
        $stmt->close();
    } else {
        $individual_stock = $individual_product['stock'];
    }

    $new_individual_stock = $individual_stock + $quantity_to_restore;

    if ($store_id) {
        $stmt = $conn->prepare("UPDATE store_prices SET stock = ? WHERE product_id = ? AND store_id = ?");
        $stmt->bind_param("iii", $new_individual_stock, $product_id, $store_id);
        $stmt->execute();
        $stmt->close();
        _queuePush($product_id, $store_id, $new_individual_stock);

        $db->logChange('store_prices', "product_id = $product_id AND store_id = $store_id", 'UPDATE', [
            'stock_change' => $quantity_to_restore,
            'reason' => 'Stock restored'
        ]);
    } else {
        $stmt = $conn->prepare("UPDATE products SET stock = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_individual_stock, $product_id);
        $stmt->execute();
        $stmt->close();

        $db->logChange('products', $product_id, 'UPDATE', [
            'stock_change' => $quantity_to_restore,
            'reason' => 'Stock restored'
        ]);
    }

    return [
        'quantity_restored' => $quantity_to_restore,
        'new_individual_stock' => $new_individual_stock
    ];
}

// Queue stock pushes to run AFTER transaction commits (avoids lock issues)
$_stockPushQueue = [];

function _queuePush($product_id, $store_id, $stock) {
    global $_stockPushQueue;
    $_stockPushQueue[] = ['product_id' => $product_id, 'store_id' => $store_id, 'stock' => $stock];
}

function flushStockPushes() {
    global $_stockPushQueue;

    // Push to cloud database (source of truth)
    @include_once __DIR__ . '/../sync/cloud_stock_sync.php';
    if (function_exists('cloudStockPush')) {
        foreach ($_stockPushQueue as $p) {
            cloudStockPush($p['product_id'], $p['store_id'], $p['stock']);
        }
    }

    $_stockPushQueue = [];
}

/**
 * Pull latest stock from cloud for other stores (call periodically)
 */
function pullCloudStock() {
    global $conn;
    @include_once __DIR__ . '/../sync/cloud_stock_sync.php';
    if (!function_exists('cloudStockPull')) return 0;

    @include_once __DIR__ . '/../core/auth_check.php';
    $store_id = $_SESSION['store_id'] ?? null;
    if (!$store_id) return 0;

    return cloudStockPull($conn, $store_id);
}
?>
