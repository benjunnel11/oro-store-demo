<?php

/**
 * Transaction Helper Functions
 * Handles device-specific transaction numbers and GCash references
 */

require_once __DIR__ . '/../sync/config.php';
require_once __DIR__ . '/system_logger.php';

/* =========================================================
   NORMAL TRANSACTION NUMBER
   Format: DEVICE_A-000001
========================================================= */

/**
 * Generate next transaction number for this device
 *
 * @param mysqli $conn
 * @return string
 */
function generateTransactionNumber($conn) {
    $device_prefix = LOCAL_DEVICE_ID;

    $stmt = $conn->prepare("
        SELECT transaction_number
        FROM transactions
        WHERE transaction_number LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");

    $like = $device_prefix . '-%';
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $last_number = intval(substr(
            $row['transaction_number'],
            strlen($device_prefix) + 1
        ));
        $next_number = $last_number + 1;
    } else {
        $next_number = 1;
    }

    $stmt->close();

    return $device_prefix . '-' . str_pad($next_number, 6, '0', STR_PAD_LEFT);
}

/* =========================================================
   GCASH TRANSACTION REFERENCE
   Format: GC-DEVICE_A-000001
========================================================= */

/**
 * Generate next GCash transaction reference number
 * Compatible with gcash_transactions table schema
 *
 * @param mysqli $conn
 * @return string
 */
function generateGCashReferenceNumber($conn) {
    $device_prefix = LOCAL_DEVICE_ID;

    $stmt = $conn->prepare("
        SELECT reference_number
        FROM gcash_transactions
        WHERE reference_number LIKE ?
        AND is_deleted = 0
        ORDER BY id DESC
        LIMIT 1
    ");

    $like = 'GC-' . $device_prefix . '-%';
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        // GC-DEVICE_A-000123
        $parts = explode('-', $row['reference_number']);
        $last_number = intval($parts[2] ?? 0);
        $next_number = $last_number + 1;
    } else {
        $next_number = 1;
    }

    $stmt->close();

    return 'GC-' . $device_prefix . '-' . str_pad($next_number, 6, '0', STR_PAD_LEFT);
}

/* =========================================================
   GCASH UTILITIES
========================================================= */

/**
 * Check if GCash reference number exists
 */
function gcashReferenceExists($conn, $reference_number) {
    $stmt = $conn->prepare(
        "SELECT id FROM gcash_transactions WHERE reference_number = ? AND is_deleted = 0"
    );
    $stmt->bind_param("s", $reference_number);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

/**
 * Get GCash statistics for this device
 */
function getDeviceGCashStats($conn) {
    $device_prefix = LOCAL_DEVICE_ID;

    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) AS total,
            SUM(CASE WHEN transaction_type = 'cash_in' THEN total_amount ELSE 0 END) AS total_cash_in,
            SUM(CASE WHEN transaction_type = 'cash_out' THEN total_amount ELSE 0 END) AS total_cash_out,
            SUM(fee) AS total_fees
        FROM gcash_transactions
        WHERE device_id = ?
        AND status = 'completed'
        AND is_deleted = 0
    ");

    $stmt->bind_param("s", $device_prefix);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'device_id' => $device_prefix,
        'total_transactions' => $stats['total'] ?? 0,
        'total_cash_in' => $stats['total_cash_in'] ?? 0,
        'total_cash_out' => $stats['total_cash_out'] ?? 0,
        'total_fees' => $stats['total_fees'] ?? 0,
        'net_amount' => ($stats['total_cash_in'] ?? 0) - ($stats['total_cash_out'] ?? 0)
    ];
}

/* =========================================================
   PARSING & VALIDATION
========================================================= */

/**
 * Parse transaction number
 *
 * @param string $transaction_number
 * @return array
 */
function parseTransactionNumber($transaction_number) {
    $parts = explode('-', $transaction_number);

    return [
        'device_id' => $parts[0] ?? null,
        'sequence'  => intval($parts[1] ?? 0),
        'full_number' => $transaction_number
    ];
}

/**
 * Validate transaction number format
 *
 * @param string $transaction_number
 * @return bool
 */
function isValidTransactionNumber($transaction_number) {
    return preg_match('/^[A-Z_]+\-\d{6}$/', $transaction_number);
}

/* =========================================================
   DEVICE STATISTICS
========================================================= */

/**
 * Get transaction statistics for this device
 *
 * @param mysqli $conn
 * @return array
 */
function getDeviceTransactionStats($conn) {
    $device_prefix = LOCAL_DEVICE_ID;

    // Overall stats
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total,
               SUM(total_amount) AS total_sales,
               SUM(total_profit) AS total_profit
        FROM transactions
        WHERE transaction_number LIKE ?
        AND status = 'completed'
        AND is_deleted = 0
    ");

    $like = $device_prefix . '-%';
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Today stats
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS today_count,
               SUM(total_amount) AS today_sales
        FROM transactions
        WHERE transaction_number LIKE ?
        AND DATE(transaction_date) = CURDATE()
        AND status = 'completed'
        AND is_deleted = 0
    ");

    $stmt->bind_param("s", $like);
    $stmt->execute();
    $today = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'device_id' => $device_prefix,
        'total_transactions' => $stats['total'] ?? 0,
        'total_sales' => $stats['total_sales'] ?? 0,
        'total_profit' => $stats['total_profit'] ?? 0,
        'today_transactions' => $today['today_count'] ?? 0,
        'today_sales' => $today['today_sales'] ?? 0
    ];
}

/* =========================================================
   UTILITIES
========================================================= */

/**
 * Check if transaction number exists
 */
function transactionNumberExists($conn, $transaction_number) {
    $stmt = $conn->prepare(
        "SELECT id FROM transactions WHERE transaction_number = ? AND is_deleted = 0"
    );
    $stmt->bind_param("s", $transaction_number);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists;
}

/**
 * Get last transaction number for device
 */
function getLastTransactionNumber($conn) {
    $device_prefix = LOCAL_DEVICE_ID;

    $stmt = $conn->prepare("
        SELECT transaction_number
        FROM transactions
        WHERE transaction_number LIKE ?
        AND is_deleted = 0
        ORDER BY id DESC
        LIMIT 1
    ");

    $like = $device_prefix . '-%';
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $last = $result->fetch_assoc()['transaction_number'] ?? null;
    $stmt->close();

    return $last;
}

/**
 * Format transaction number for UI
 */
function formatTransactionNumber($transaction_number) {
    $parts = parseTransactionNumber($transaction_number);

    if ($parts['device_id'] && $parts['sequence']) {
        return "{$parts['device_id']} #{$parts['sequence']}";
    }

    return $transaction_number;
}

/**
 * Get device from transaction number
 */
function getDeviceFromTransactionNumber($transaction_number) {
    $parts = parseTransactionNumber($transaction_number);
    return $parts['device_id'] ?? 'Unknown';
}