<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

$currentUser = getCurrentUser();
$isAdmin     = in_array($currentUser['role'], ['admin', 'super_admin']);
$_on_own_device = isOnOwnDevice();

$userStore = null;
if ($currentUser['store_id']) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $currentUser['store_id']);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
}

// Force store filter: non-super admins always see only their store
$_force_store_filter = ($userStore && $currentUser['role'] !== 'super_admin');

// Get all stores for device filter (super admin only)
$_all_stores = [];
if (in_array($currentUser['role'], ['admin', 'super_admin'])) {
    $r = $conn->query("SELECT id, store_name, store_code, device_id FROM stores WHERE status = 'active' ORDER BY id");
    if ($r) { while ($row = $r->fetch_assoc()) $_all_stores[] = $row; }
}

// "View As" device override for super admin — filters by device_id, not store_id
$_view_as_device = $_GET['view_as'] ?? '';
$_view_as_store = null;
$_view_as_device_filter = '';
if ($_view_as_device && isSuperAdmin()) {
    foreach ($_all_stores as $st) {
        if ($st['device_id'] === $_view_as_device) {
            $_view_as_store = $st;
            $userStore = $st;
            $_force_store_filter = true;
            $_view_as_device_filter = $_view_as_device;
            break;
        }
    }
}

// Auto-fetch from Device A on branch devices if local data is empty (post-wipe)
@include_once __DIR__ . '/../sync/config.php';
if (defined('LOCAL_DEVICE_ID') && LOCAL_DEVICE_ID !== 'DEVICE_A') {
    $__period = $_GET['period'] ?? 'today';
    $__date_check = "DATE(transaction_date) = CURDATE()";
    if ($__period === 'week') $__date_check = "transaction_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
    elseif ($__period === 'month') $__date_check = "transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    elseif ($__period === 'year') $__date_check = "transaction_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
    elseif ($__period === 'all') $__date_check = "1=1";

    $__dev_filter = ($currentUser['role'] !== 'super_admin') ? '&device=' . LOCAL_DEVICE_ID : '';
    $__local_count = intval($conn->query("SELECT COUNT(*) as c FROM transactions WHERE $__date_check")->fetch_assoc()['c']);

    // Ask Device A how many transactions it has for this period
    $__device_a_ip = '';
    $__da = $conn->query("SELECT device_ip FROM stores WHERE device_id = 'DEVICE_A' AND device_ip IS NOT NULL LIMIT 1");
    if ($__da && $__row = $__da->fetch_assoc()) $__device_a_ip = $__row['device_ip'];

    if ($__device_a_ip) {
        $__count_url = "http://$__device_a_ip/oro-store/sync/pull_history.php?action=get_count&period=$__period$__dev_filter&key=" . urlencode(SYNC_PASSWORD);
        $__remote_raw = @file_get_contents($__count_url, false, stream_context_create(['http' => ['timeout' => 3]]));
        $__remote = $__remote_raw ? json_decode($__remote_raw, true) : null;
        $__remote_count = ($__remote && !empty($__remote['success'])) ? intval($__remote['count']) : -1;

        if ($__remote_count > $__local_count) {
            // Device A has more data — fetch the missing ones
            @file_get_contents("http://127.0.0.1/oro-store/sync/pull_history.php?run=1&period=$__period$__dev_filter",
                false, stream_context_create(['http' => ['timeout' => 15]]));
        }
    }
}

/* ══════════════════════════════════════════════════════════════
   AJAX — filter_transactions
   ══════════════════════════════════════════════════════════════ */
if (isset($_GET['action']) && $_GET['action'] === 'filter_transactions') {
    header('Content-Type: application/json');
    $status         = $_GET['status']         ?? 'all';
    $date_from      = $_GET['date_from']      ?? '';
    $date_to        = $_GET['date_to']        ?? '';
    $search         = $_GET['search']         ?? '';
    $payment_method = $_GET['payment_method'] ?? 'all';
    $device_filter  = $_GET['device']         ?? 'all';
    $_view_as_device_filter = '';

    // Apply "View As" device override
    $va = $_GET['view_as'] ?? '';
    if ($va && isSuperAdmin()) {
        $_view_as_device_filter = $va;
        $va_r = $conn->query("SELECT id FROM stores WHERE device_id = '" . $conn->real_escape_string($va) . "' AND status = 'active' LIMIT 1");
        $va_row = $va_r ? $va_r->fetch_assoc() : null;
        if ($va_row) { $_force_store_filter = true; $userStore = ['id' => $va_row['id']]; }
    }

    // ── regular transactions ──────────────────────────────────
    $query = "SELECT t.*,
              (SELECT GROUP_CONCAT(ti.product_name SEPARATOR ', ')
               FROM transaction_items ti WHERE ti.transaction_id = t.id) as products,
              d.recipient_name, d.recipient_address, d.status as delivery_status,
              COALESCE(d.delivery_fee, 0) as delivery_fee,
              COALESCE(c.additional_charge, 0) as credit_fee,
              NULL as angkat_retailer, NULL as angkat_transaction_number
              FROM transactions t
              LEFT JOIN deliveries d ON t.id = d.transaction_id
              LEFT JOIN credits c ON t.id = c.transaction_id
              WHERE 1=1";
    $params = []; $types = '';

    if ($_view_as_device_filter) { $query .= " AND t.device_id = ?"; $params[] = $_view_as_device_filter; $types .= 's'; }
    elseif ($_force_store_filter) { $query .= " AND t.store_id = ?"; $params[] = $userStore['id']; $types .= 'i'; }
    if ($device_filter !== 'all' && in_array($currentUser['role'], ['admin', 'super_admin'])) {
        $query .= " AND t.device_id = ?"; $params[] = $device_filter; $types .= 's';
    }
    if ($status !== 'all')       { $query .= " AND t.status = ?";             $params[] = $status;          $types .= 's'; }
    if ($payment_method === 'angkat') {
        $query .= " AND 1=0";
    } elseif ($payment_method === 'atm') {
        $query .= " AND t.payment_method IN ('atm_cash_withdrawal','atm_service_charge','atm_withdrawal')";
    } elseif ($payment_method === 'atm_service' || $payment_method === 'gcash_service' || $payment_method === 'stock_in') {
        $query .= " AND 1=0"; // these come from their own tables
    } elseif ($payment_method !== 'all') {
        $query .= " AND t.payment_method = ?"; $params[] = $payment_method; $types .= 's';
    }
    // Exclude ATM internal rows when showing all (they show via atm_service instead)
    if ($payment_method === 'all') {
        $query .= " AND t.payment_method NOT IN ('atm_cash_withdrawal','atm_service_charge','atm_withdrawal','expense')";
    }
    if ($date_from) { $query .= " AND DATE(t.transaction_date) >= ?"; $params[] = $date_from; $types .= 's'; }
    if ($date_to)   { $query .= " AND DATE(t.transaction_date) <= ?"; $params[] = $date_to;   $types .= 's'; }
    $query .= " ORDER BY t.transaction_date DESC LIMIT 100";

    $stmt = $conn->prepare($query);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // ── angkat transactions ───────────────────────────────────
    if ($payment_method === 'all' || $payment_method === 'angkat') {
        $aq = "SELECT
                a.id,
                a.transaction_number,
                a.created_at as transaction_date,
                a.total_value as total_amount,
                a.total_cost,
                (a.total_value - a.total_cost) as total_profit,
                a.amount_collected,
                a.status,
                'angkat' as payment_method,
                a.retailer_name as angkat_retailer,
                a.transaction_number as angkat_transaction_number,
                a.device_id,
                NULL as products,
                NULL as recipient_name, NULL as recipient_address, NULL as delivery_status
               FROM angkat_transactions a
               WHERE a.is_deleted = 0";
        $ap = []; $at = '';

        if ($_view_as_device_filter) { $aq .= " AND a.device_id = ?"; $ap[] = $_view_as_device_filter; $at .= 's'; }
        elseif ($_force_store_filter) { $aq .= " AND a.store_id = ?"; $ap[] = $userStore['id']; $at .= 'i'; }
        if ($device_filter !== 'all' && in_array($currentUser['role'], ['admin', 'super_admin'])) { $aq .= " AND a.device_id = ?"; $ap[] = $device_filter; $at .= 's'; }
        if ($status !== 'all')       { $aq .= " AND a.status = ?";   $ap[] = $status;          $at .= 's'; }
        if ($date_from) { $aq .= " AND DATE(a.created_at) >= ?"; $ap[] = $date_from; $at .= 's'; }
        if ($date_to)   { $aq .= " AND DATE(a.created_at) <= ?"; $ap[] = $date_to;   $at .= 's'; }
        $aq .= " ORDER BY a.created_at DESC LIMIT 100";

        $astmt = $conn->prepare($aq);
        if (!empty($ap)) $astmt->bind_param($at, ...$ap);
        $astmt->execute();
        $angkat_rows = $astmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $astmt->close();

        // add angkat items list to each row
        foreach ($angkat_rows as &$ar) {
            $istmt = $conn->prepare("SELECT product_name, quantity_given FROM angkat_items WHERE angkat_id = ? AND is_deleted = 0");
            $istmt->bind_param("i", $ar['id']);
            $istmt->execute();
            $aitems = $istmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $istmt->close();
            $ar['products'] = implode(', ', array_map(fn($i) => $i['product_name'].'(×'.$i['quantity_given'].')', $aitems));
            $ar['is_angkat'] = true;
        }
        unset($ar);

        $transactions = array_merge($transactions, $angkat_rows);
        // sort combined by date desc
        usort($transactions, fn($a,$b) => strtotime($b['transaction_date']) - strtotime($a['transaction_date']));
        $transactions = array_slice($transactions, 0, 150);
    }

    // ── stock receipts (stock_in) ────────────────────────────
    if ($payment_method === 'all' || $payment_method === 'stock_in') {
        $sq = "SELECT sr.id, CONCAT('SR-', LPAD(sr.id,6,'0')) as transaction_number,
                sr.created_at as transaction_date, sr.total_cost as total_amount,
                0 as total_profit, sr.total_selling_value, sr.total_items as items_count,
                'completed' as status, 'stock_in' as payment_method,
                sr.supplier_name, sr.invoice_number, sr.notes as receipt_notes,
                sr.total_items, sr.user_name, sr.device_id,
                NULL as products, NULL as recipient_name, NULL as recipient_address,
                NULL as delivery_status, NULL as angkat_retailer, NULL as angkat_transaction_number
               FROM stock_receipts sr WHERE sr.is_deleted = 0";
        $sp = []; $st2 = '';
        if ($_view_as_device_filter) { $sq .= " AND sr.device_id = ?"; $sp[] = $_view_as_device_filter; $st2 .= 's'; }
        elseif ($_force_store_filter) { $sq .= " AND sr.store_id = ?"; $sp[] = $userStore['id']; $st2 .= 'i'; }
        if ($device_filter !== 'all' && in_array($currentUser['role'], ['admin', 'super_admin'])) { $sq .= " AND sr.device_id = ?"; $sp[] = $device_filter; $st2 .= 's'; }
        if ($date_from) { $sq .= " AND DATE(sr.created_at) >= ?"; $sp[] = $date_from; $st2 .= 's'; }
        if ($date_to)   { $sq .= " AND DATE(sr.created_at) <= ?"; $sp[] = $date_to;   $st2 .= 's'; }
        $sq .= " ORDER BY sr.created_at DESC LIMIT 100";
        $sstmt = $conn->prepare($sq);
        if (!empty($sp)) $sstmt->bind_param($st2, ...$sp);
        $sstmt->execute();
        $stock_rows = $sstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sstmt->close();

        foreach ($stock_rows as &$sr) {
            $istmt = $conn->prepare("SELECT product_name, quantity, purchase_price, selling_price FROM stock_receipt_items WHERE receipt_id = ?");
            $istmt->bind_param("i", $sr['id']);
            $istmt->execute();
            $sitems = $istmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $istmt->close();
            $sr['products'] = implode(', ', array_map(fn($i) => $i['product_name'].'(×'.$i['quantity'].')', $sitems));
            $sr['is_stock_in'] = true;
        }
        unset($sr);

        $transactions = array_merge($transactions, $stock_rows);
        usort($transactions, fn($a,$b) => strtotime($b['transaction_date']) - strtotime($a['transaction_date']));
        $transactions = array_slice($transactions, 0, 200);
    }

    // ── gcash transactions ─────────────────────────────────────
    if ($payment_method === 'all' || $payment_method === 'gcash_service') {
        $gq = "SELECT g.id, g.reference_number as transaction_number,
                g.transaction_date, g.total_amount as total_amount, g.fee as total_profit,
                g.amount, g.fee as gcash_fee,
                'completed' as status, 'gcash_service' as payment_method,
                g.transaction_type as gcash_type,
                ga.account_name as gcash_acct_name,
                g.device_id,
                NULL as products, NULL as recipient_name, NULL as recipient_address,
                NULL as delivery_status, NULL as angkat_retailer, NULL as angkat_transaction_number,
                NULL as supplier_name, NULL as invoice_number
               FROM gcash_transactions g
               LEFT JOIN gcash_accounts ga ON g.gcash_account_id = ga.id
               WHERE g.is_deleted = 0 AND g.status = 'completed'
               AND g.transaction_type IN ('cash_in','cash_out')
               AND (g.user_reference IS NULL OR g.user_reference != 'SETBAL')";
        $gp = []; $gt = '';
        if ($_view_as_device_filter) { $gq .= " AND g.device_id = ?"; $gp[] = $_view_as_device_filter; $gt .= 's'; }
        elseif ($_force_store_filter && $userStore) { $gq .= " AND g.store_id = ?"; $gp[] = $userStore['id']; $gt .= 'i'; }
        if ($device_filter !== 'all' && in_array($currentUser['role'], ['admin', 'super_admin'])) { $gq .= " AND g.device_id = ?"; $gp[] = $device_filter; $gt .= 's'; }
        if ($date_from) { $gq .= " AND DATE(g.transaction_date) >= ?"; $gp[] = $date_from; $gt .= 's'; }
        if ($date_to)   { $gq .= " AND DATE(g.transaction_date) <= ?"; $gp[] = $date_to;   $gt .= 's'; }
        $gq .= " ORDER BY g.transaction_date DESC LIMIT 100";
        $gstmt = $conn->prepare($gq);
        if (!empty($gp)) $gstmt->bind_param($gt, ...$gp);
        $gstmt->execute();
        $gcash_rows = $gstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $gstmt->close();

        foreach ($gcash_rows as &$gr) {
            $gr['products'] = ($gr['gcash_type'] === 'cash_in' ? 'Cash In' : 'Cash Out') . ' ₱' . number_format($gr['amount'], 0) . ($gr['gcash_acct_name'] ? ' · ' . $gr['gcash_acct_name'] : '');
            $gr['is_gcash_service'] = true;
            $gr['total_items'] = 1;
        }
        unset($gr);

        $transactions = array_merge($transactions, $gcash_rows);
        usort($transactions, fn($a,$b) => strtotime($b['transaction_date']) - strtotime($a['transaction_date']));
        $transactions = array_slice($transactions, 0, 200);
    }

    // ── atm transactions (from atm_transactions table, fee as revenue) ─
    if ($payment_method === 'all' || $payment_method === 'atm_service') {
        $aq2 = "SELECT a.id, CONCAT('ATM-', LPAD(a.id,5,'0')) as transaction_number,
                a.transaction_date, (a.amount + COALESCE(a.service_charge, 12)) as total_amount,
                COALESCE(a.service_charge, 12) as total_profit,
                a.amount, COALESCE(a.service_charge, 12) as atm_fee, a.reference_number as atm_ref, a.customer_name,
                'completed' as status, 'atm_service' as payment_method,
                ba.bank_name as atm_bank_name, a.device_id,
                NULL as products, NULL as recipient_name, NULL as recipient_address,
                NULL as delivery_status, NULL as angkat_retailer, NULL as angkat_transaction_number,
                NULL as supplier_name, NULL as invoice_number, NULL as gcash_type, NULL as gcash_acct_name
               FROM atm_transactions a
               LEFT JOIN bank_accounts ba ON a.bank_account_id = ba.id
               WHERE a.is_deleted = 0 AND a.status = 'completed'";
        $ap2 = []; $at2 = '';
        if ($_view_as_device_filter) { $aq2 .= " AND a.device_id = ?"; $ap2[] = $_view_as_device_filter; $at2 .= 's'; }
        elseif ($_force_store_filter && $userStore) { $aq2 .= " AND a.store_id = ?"; $ap2[] = $userStore['id']; $at2 .= 'i'; }
        if ($device_filter !== 'all' && in_array($currentUser['role'], ['admin', 'super_admin'])) { $aq2 .= " AND a.device_id = ?"; $ap2[] = $device_filter; $at2 .= 's'; }
        if ($date_from) { $aq2 .= " AND DATE(a.transaction_date) >= ?"; $ap2[] = $date_from; $at2 .= 's'; }
        if ($date_to)   { $aq2 .= " AND DATE(a.transaction_date) <= ?"; $ap2[] = $date_to;   $at2 .= 's'; }
        $aq2 .= " ORDER BY a.transaction_date DESC LIMIT 100";
        $astmt2 = $conn->prepare($aq2);
        if (!empty($ap2)) $astmt2->bind_param($at2, ...$ap2);
        $astmt2->execute();
        $atm_rows = $astmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $astmt2->close();

        foreach ($atm_rows as &$ar2) {
            $ar2['products'] = 'ATM Withdrawal ₱' . number_format($ar2['amount'], 0) . ($ar2['customer_name'] ? ' · ' . $ar2['customer_name'] : '') . ($ar2['atm_bank_name'] ? ' → ' . $ar2['atm_bank_name'] : '');
            $ar2['is_atm_service'] = true;
            $ar2['total_items'] = 1;
        }
        unset($ar2);

        $transactions = array_merge($transactions, $atm_rows);
        usort($transactions, fn($a,$b) => strtotime($b['transaction_date']) - strtotime($a['transaction_date']));
        $transactions = array_slice($transactions, 0, 200);
    }

    // ── expenses ─────────────────────────────────────────────
    if ($payment_method === 'all' || $payment_method === 'expense') {
        $eq = "SELECT e.id, CONCAT('EXP-', LPAD(e.id,4,'0')) as transaction_number,
                e.created_at as transaction_date, e.amount as total_amount,
                0 as total_profit, e.amount,
                'completed' as status, 'expense' as payment_method,
                e.category as expense_category, e.description as expense_desc, e.device_id,
                NULL as products, NULL as recipient_name, NULL as recipient_address,
                NULL as delivery_status, NULL as angkat_retailer, NULL as angkat_transaction_number,
                NULL as supplier_name, NULL as invoice_number, NULL as gcash_type, NULL as gcash_acct_name
               FROM expenses e WHERE e.is_deleted = 0";
        $ep = []; $et = '';
        if ($_view_as_device_filter) { $eq .= " AND (e.device_id = ? OR (e.device_id IS NULL AND e.store_id = ?))"; $ep[] = $_view_as_device_filter; $ep[] = $userStore['id']; $et .= 'si'; }
        elseif ($_force_store_filter && $userStore) { $eq .= " AND e.store_id = ?"; $ep[] = $userStore['id']; $et .= 'i'; }
        if ($device_filter !== 'all' && in_array($currentUser['role'], ['admin', 'super_admin'])) { $eq .= " AND e.device_id = ?"; $ep[] = $device_filter; $et .= 's'; }
        if ($date_from) { $eq .= " AND DATE(e.created_at) >= ?"; $ep[] = $date_from; $et .= 's'; }
        if ($date_to)   { $eq .= " AND DATE(e.created_at) <= ?"; $ep[] = $date_to;   $et .= 's'; }
        $eq .= " ORDER BY e.created_at DESC LIMIT 100";
        $estmt = $conn->prepare($eq);
        if (!empty($ep)) $estmt->bind_param($et, ...$ep);
        $estmt->execute();
        $expense_rows = $estmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $estmt->close();

        foreach ($expense_rows as &$er) {
            $er['products'] = $er['expense_category'] . ($er['expense_desc'] ? ' — ' . $er['expense_desc'] : '');
            $er['is_expense'] = true;
            $er['total_items'] = 1;
        }
        unset($er);

        $transactions = array_merge($transactions, $expense_rows);
        usort($transactions, fn($a,$b) => strtotime($b['transaction_date']) - strtotime($a['transaction_date']));
        $transactions = array_slice($transactions, 0, 200);
    }

    // ── search filter ─────────────────────────────────────────
    if ($search) {
        $transactions = array_values(array_filter($transactions, function($t) use ($search) {
            return stripos($t['id'] ?? '',                   $search) !== false ||
                   stripos($t['transaction_number'] ?? '',   $search) !== false ||
                   stripos($t['products'] ?? '',             $search) !== false ||
                   stripos($t['recipient_name'] ?? '',       $search) !== false ||
                   stripos($t['angkat_retailer'] ?? '',      $search) !== false ||
                   stripos($t['supplier_name'] ?? '',        $search) !== false ||
                   stripos($t['invoice_number'] ?? '',       $search) !== false;
        }));
    }

    echo json_encode($transactions);
    $conn->close(); exit;
}

/* ══════════════════════════════════════════════════════════════
   AJAX — get_gcash_details (for GCash service rows)
   ══════════════════════════════════════════════════════════════ */
if (isset($_GET['action']) && $_GET['action'] === 'get_gcash_details') {
    header('Content-Type: application/json');
    $gid = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT g.*, ga.account_name, ga.phone_number FROM gcash_transactions g LEFT JOIN gcash_accounts ga ON g.gcash_account_id = ga.id WHERE g.id = ? AND g.is_deleted = 0");
    $stmt->bind_param("i", $gid); $stmt->execute();
    $tx = $stmt->get_result()->fetch_assoc(); $stmt->close();
    echo json_encode(['success' => !!$tx, 'transaction' => $tx]);
    $conn->close(); exit;
}

/* ══════════════════════════════════════════════════════════════
   AJAX — get_details
   ══════════════════════════════════════════════════════════════ */
if (isset($_GET['action']) && $_GET['action'] === 'get_details') {
    header('Content-Type: application/json');
    $tid      = intval($_GET['id']);
    $is_angkat = (isset($_GET['is_angkat']) && $_GET['is_angkat'] == '1');

    if ($is_angkat) {
        $stmt = $conn->prepare("SELECT a.*, 'angkat' as payment_method FROM angkat_transactions a WHERE a.id = ?");
        $stmt->bind_param("i", $tid); $stmt->execute();
        $transaction = $stmt->get_result()->fetch_assoc(); $stmt->close();

        $stmt = $conn->prepare("
            SELECT ai.*, p.individual_pieces_per_pack, p.individual_selling_price,
                   p.stock as current_stock
            FROM angkat_items ai
            LEFT JOIN products p ON ai.product_id = p.id
            WHERE ai.angkat_id = ? AND ai.is_deleted = 0");
        $stmt->bind_param("i", $tid); $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

        $stmt = $conn->prepare("SELECT * FROM angkat_payments WHERE angkat_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $tid); $stmt->execute();
        $payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

        echo json_encode([
            'transaction'    => $transaction,
            'items'          => $items,
            'delivery_items' => [],
            'related'        => ['original'=>null,'edits'=>[]],
            'payments'       => $payments,
            'is_angkat'      => true
        ]);
    } else {
        $stmt = $conn->prepare("SELECT t.*,
                               d.recipient_name, d.recipient_address, d.status as delivery_status, d.id as delivery_id,
                               c.customer_name, c.customer_contact, c.status as credit_status, c.amount_paid as credit_paid, c.amount_due as credit_due, c.additional_charge as credit_fee
                               FROM transactions t
                               LEFT JOIN deliveries d ON t.id = d.transaction_id
                               LEFT JOIN credits c ON t.id = c.transaction_id AND c.is_deleted = 0
                               WHERE t.id = ?");
        $stmt->bind_param("i", $tid); $stmt->execute();
        $transaction = $stmt->get_result()->fetch_assoc(); $stmt->close();

        $stmt = $conn->prepare("SELECT ti.*, p.stock as current_stock FROM transaction_items ti LEFT JOIN products p ON ti.product_id = p.id WHERE ti.transaction_id = ?");
        $stmt->bind_param("i", $tid); $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

        $delivery_items = [];
        if ($transaction['delivery_id']) {
            $stmt = $conn->prepare("SELECT * FROM delivery_items WHERE delivery_id = ?");
            $stmt->bind_param("i", $transaction['delivery_id']); $stmt->execute();
            $delivery_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
        }

        $related = [];
        if ($transaction['original_transaction_id']) {
            $stmt = $conn->prepare("SELECT * FROM transactions WHERE id = ?");
            $stmt->bind_param("i", $transaction['original_transaction_id']); $stmt->execute();
            $related['original'] = $stmt->get_result()->fetch_assoc(); $stmt->close();
        }
        $stmt = $conn->prepare("SELECT * FROM transactions WHERE original_transaction_id = ? ORDER BY transaction_date DESC");
        $stmt->bind_param("i", $tid); $stmt->execute();
        $related['edits'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

        echo json_encode([
            'transaction'    => $transaction,
            'items'          => $items,
            'delivery_items' => $delivery_items,
            'related'        => $related,
            'payments'       => [],
            'is_angkat'      => false
        ]);
    }
    $conn->close(); exit;
}

/* ══════════════════════════════════════════════════════════════
   PERIOD FILTER
   ══════════════════════════════════════════════════════════════ */
$period = $_GET['period'] ?? 'today';
$p_from = $_GET['pfrom'] ?? '';
$p_to = $_GET['pto'] ?? '';

if ($p_from && $p_to) {
    $date_from_p = $p_from;
    $date_to_p = $p_to;
    $period_label = date('M j', strtotime($p_from)) . ' – ' . date('M j, Y', strtotime($p_to));
} else {
    switch ($period) {
        case 'week':
            $date_from_p = date('Y-m-d', strtotime('monday this week'));
            $date_to_p = date('Y-m-d');
            $period_label = 'This Week';
            break;
        case 'month':
            $date_from_p = date('Y-m-01');
            $date_to_p = date('Y-m-d');
            $period_label = 'This Month';
            break;
        case 'year':
            $date_from_p = date('Y-01-01');
            $date_to_p = date('Y-m-d');
            $period_label = 'This Year';
            break;
        case 'all':
            $date_from_p = '';
            $date_to_p = '';
            $period_label = 'All Time';
            break;
        default: // today
            $date_from_p = date('Y-m-d');
            $date_to_p = date('Y-m-d');
            $period_label = 'Today';
            break;
    }
}

// Build date condition strings for each table alias
function dateFilter($alias, $col, $from, $to) {
    if (!$from && !$to) return '';
    $s = '';
    if ($from) $s .= " AND DATE($alias.$col) >= '$from'";
    if ($to) $s .= " AND DATE($alias.$col) <= '$to'";
    return $s;
}
$df  = dateFilter('t', 'transaction_date', $date_from_p, $date_to_p);
$dfc = dateFilter('c', 'created_at', $date_from_p, $date_to_p);
$dfd = dateFilter('d', 'created_at', $date_from_p, $date_to_p);
$dfa = dateFilter('a', 'created_at', $date_from_p, $date_to_p);
$dfr = dateFilter('sr', 'created_at', $date_from_p, $date_to_p);
$dfg = $date_from_p ? "AND DATE(transaction_date) >= '$date_from_p'" : '';
$dfg .= $date_to_p ? " AND DATE(transaction_date) <= '$date_to_p'" : '';

/* ══════════════════════════════════════════════════════════════
   PAGE-LOAD STATS
   ══════════════════════════════════════════════════════════════ */
$sf  = ($_force_store_filter) ? "AND t.store_id = {$userStore['id']}" : "";
$sfc = ($_force_store_filter) ? "AND c.store_id = {$userStore['id']}" : "";
$sfd = ($_force_store_filter) ? "AND d.store_id = {$userStore['id']}" : "";
$sfa = ($_force_store_filter) ? "AND a.store_id = {$userStore['id']}" : "";

$stats = [];
$stats['total_transactions']   = $conn->query("SELECT COUNT(*) as count FROM transactions t WHERE status IN ('completed','pending') $sf $df")->fetch_assoc()['count'];
$stats['edited_transactions']  = $conn->query("SELECT COUNT(*) as count FROM transactions t WHERE status='edited' $sf $df")->fetch_assoc()['count'];
$stats['total_revenue']        = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as total FROM transactions t WHERE status IN ('completed','pending') AND payment_method NOT IN ('cash_return_to_register','atm_cash_withdrawal','atm_withdrawal','cash_adjustment','expense') $sf $df")->fetch_assoc()['total']);
$stats['total_revenue']       += floatval($conn->query("SELECT COALESCE(SUM(fee),0) as total FROM gcash_transactions WHERE status='completed' AND is_deleted=0 AND (user_reference IS NULL OR user_reference != 'SETBAL') $dfg")->fetch_assoc()['total']);
$stats['total_profit']         = floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as total FROM transactions t WHERE status IN ('completed','pending') $sf $df")->fetch_assoc()['total']);
$stats['total_profit']        += floatval($conn->query("SELECT COALESCE(SUM(fee),0) as total FROM gcash_transactions WHERE status='completed' AND is_deleted=0 AND (user_reference IS NULL OR user_reference != 'SETBAL') $dfg")->fetch_assoc()['total']);
$stats['total_profit']        += floatval($conn->query("SELECT COALESCE(SUM(d.delivery_fee),0) as total FROM deliveries d WHERE d.is_deleted=0 $sfd $dfd")->fetch_assoc()['total']);
$stats['total_profit']        += floatval($conn->query("SELECT COALESCE(SUM(c.additional_charge),0) as total FROM credits c WHERE c.is_deleted=0 $sfc $dfc")->fetch_assoc()['total']);
$stats['today_revenue']        = $stats['total_revenue'];
$stats['today_transactions']   = $stats['total_transactions'];
$stats['pending_deliveries']   = $conn->query("SELECT COUNT(*) as count FROM deliveries d WHERE status='pending' $sfd $dfd")->fetch_assoc()['count'];
$stats['total_deliveries']     = $conn->query("SELECT COUNT(*) as count FROM transactions t WHERE payment_method='delivery' $sf $df")->fetch_assoc()['count'];
$stats['total_credits']        = $conn->query("SELECT COUNT(*) as count FROM credits c WHERE is_deleted=0 $sfc $dfc")->fetch_assoc()['count'];
$stats['unpaid_credits']       = $conn->query("SELECT COUNT(*) as count FROM credits c WHERE status='unpaid' AND is_deleted=0 $sfc $dfc")->fetch_assoc()['count'];
$stats['total_credits_amount'] = floatval($conn->query("SELECT COALESCE(SUM(amount_due),0) as total FROM credits c WHERE is_deleted=0 $sfc $dfc")->fetch_assoc()['total']);
$stats['partial_credits']      = $conn->query("SELECT COUNT(*) as count FROM credits c WHERE status='partial' AND is_deleted=0 $sfc $dfc")->fetch_assoc()['count'];
$stats['collected_credits']    = floatval($conn->query("SELECT COALESCE(SUM(amount_paid),0) as total FROM credits c WHERE is_deleted=0 $sfc $dfc")->fetch_assoc()['total']);
$stats['completed_deliveries'] = $conn->query("SELECT COUNT(*) as count FROM deliveries d WHERE status='completed' AND is_deleted=0 $sfd $dfd")->fetch_assoc()['count'];
$stats['total_delivery_amount']= floatval($conn->query("SELECT COALESCE(SUM(t.total_amount),0) as total FROM deliveries d LEFT JOIN transactions t ON d.transaction_id = t.id WHERE d.is_deleted=0 $sfd $dfd")->fetch_assoc()['total']);
$stats['total_delivery_count'] = $conn->query("SELECT COUNT(*) as count FROM deliveries d WHERE is_deleted=0 $sfd $dfd")->fetch_assoc()['count'];
$stats['delivery_outstanding'] = floatval($conn->query("SELECT COALESCE(SUM(t.total_amount),0) as total FROM deliveries d LEFT JOIN transactions t ON d.transaction_id = t.id WHERE d.status='pending' AND d.is_deleted=0 $sfd $dfd")->fetch_assoc()['total']);
$stats['delivery_paid']        = floatval($conn->query("SELECT COALESCE(SUM(t.total_amount),0) as total FROM deliveries d LEFT JOIN transactions t ON d.transaction_id = t.id WHERE d.status='completed' AND d.is_deleted=0 $sfd $dfd")->fetch_assoc()['total']);
$stats['delivery_profit']      = floatval($conn->query("SELECT COALESCE(SUM(t.total_profit),0) as total FROM deliveries d LEFT JOIN transactions t ON d.transaction_id = t.id WHERE d.is_deleted=0 $sfd $dfd")->fetch_assoc()['total']);
$stats['credit_outstanding']   = floatval($conn->query("SELECT COALESCE(SUM(amount_due),0) as total FROM credits c WHERE status IN ('unpaid','partial') AND is_deleted=0 $sfc $dfc")->fetch_assoc()['total']);
$stats['credit_total_amount']  = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as total FROM credits c WHERE is_deleted=0 $sfc $dfc")->fetch_assoc()['total']);
$stats['credit_profit']        = floatval($conn->query("SELECT COALESCE(SUM(t.total_profit),0) as total FROM credits c LEFT JOIN transactions t ON c.transaction_id = t.id WHERE c.is_deleted=0 $sfc $dfc")->fetch_assoc()['total']);

// ── angkat stats ──────────────────────────────────────────────
$stats['total_angkat']          = $conn->query("SELECT COUNT(*) as c FROM angkat_transactions a WHERE is_deleted=0 $sfa $dfa")->fetch_assoc()['c'];
$stats['active_angkat']         = $conn->query("SELECT COUNT(*) as c FROM angkat_transactions a WHERE status='active' AND is_deleted=0 $sfa $dfa")->fetch_assoc()['c'];
$stats['angkat_outstanding']    = floatval($conn->query("SELECT COALESCE(SUM(total_value - amount_collected),0) as t FROM angkat_transactions a WHERE status='active' AND is_deleted=0 $sfa $dfa")->fetch_assoc()['t']);
$stats['angkat_total_value']    = floatval($conn->query("SELECT COALESCE(SUM(total_value),0) as t FROM angkat_transactions a WHERE is_deleted=0 $sfa $dfa")->fetch_assoc()['t']);
$stats['angkat_collected']      = floatval($conn->query("SELECT COALESCE(SUM(amount_collected),0) as t FROM angkat_transactions a WHERE is_deleted=0 $sfa $dfa")->fetch_assoc()['t']);
$stats['angkat_profit']         = floatval($conn->query("SELECT COALESCE(SUM(total_value - total_cost),0) as t FROM angkat_transactions a WHERE status='completed' AND is_deleted=0 $sfa $dfa")->fetch_assoc()['t']);
$stats['today_angkat_revenue']  = floatval($conn->query("SELECT COALESCE(SUM(amount_collected),0) as t FROM angkat_transactions a WHERE status='completed' AND is_deleted=0 $sfa $dfa")->fetch_assoc()['t']);

// ── supply stats ─────────────────────────────────────────────
$sfr = ($_force_store_filter) ? "AND sr.store_id = {$userStore['id']}" : "";
$stats['total_supply_cost']   = floatval($conn->query("SELECT COALESCE(SUM(total_cost),0) as t FROM stock_receipts sr WHERE is_deleted=0 $sfr $dfr")->fetch_assoc()['t']);
$stats['total_supply_count']  = $conn->query("SELECT COUNT(*) as c FROM stock_receipts sr WHERE is_deleted=0 $sfr $dfr")->fetch_assoc()['c'];
$stats['today_supply_cost']   = $stats['total_supply_cost'];
$stats['month_supply_cost']   = $stats['total_supply_cost'];

/* ══════════════════════════════════════════════════════════════
   NEW DASHBOARD QUERIES
   ══════════════════════════════════════════════════════════════ */
$today      = new DateTime();
$weekStart  = clone $today; $weekStart->modify('monday this week');
$weekEnd    = clone $weekStart; $weekEnd->modify('+6 days');
$monthStart = (new DateTime())->modify('first day of this month');
$monthEnd   = (new DateTime())->modify('last day of this month');
$ws=$weekStart->format('Y-m-d'); $we=$weekEnd->format('Y-m-d');
$ms=$monthStart->format('Y-m-d'); $me=$monthEnd->format('Y-m-d');

$stats['today_profit']      = $stats['total_profit'];
$stats['today_cogs']        = $stats['today_revenue'] - $stats['today_profit'];
$stats['yesterday_revenue'] = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM transactions t WHERE DATE(transaction_date)=CURDATE()-1 AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$stats['yesterday_profit']  = floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as t FROM transactions t WHERE DATE(transaction_date)=CURDATE()-1 AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);

$stats['week_revenue'] = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM transactions t WHERE transaction_date BETWEEN '$ws' AND '$we 23:59:59' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$stats['week_profit']  = floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as t FROM transactions t WHERE transaction_date BETWEEN '$ws' AND '$we 23:59:59' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$stats['week_cogs']    = $stats['week_revenue'] - $stats['week_profit'];

$stats['month_revenue']   = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM transactions t WHERE transaction_date BETWEEN '$ms' AND '$me 23:59:59' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$stats['month_profit']    = floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as t FROM transactions t WHERE transaction_date BETWEEN '$ms' AND '$me 23:59:59' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
$stats['month_cogs']      = $stats['month_revenue'] - $stats['month_profit'];
$stats['month_txn_count'] = $conn->query("SELECT COUNT(*) as c FROM transactions t WHERE transaction_date BETWEEN '$ms' AND '$me 23:59:59' AND status IN ('completed','pending') $sf")->fetch_assoc()['c'];

// add angkat revenue to monthly totals
$stats['month_angkat_revenue'] = floatval($conn->query("SELECT COALESCE(SUM(amount_collected),0) as t FROM angkat_transactions a WHERE completed_at BETWEEN '$ms' AND '$me 23:59:59' AND status='completed' $sfa")->fetch_assoc()['t']);
$stats['month_angkat_profit']  = floatval($conn->query("SELECT COALESCE(SUM(total_value - total_cost),0) as t FROM angkat_transactions a WHERE completed_at BETWEEN '$ms' AND '$me 23:59:59' AND status='completed' $sfa")->fetch_assoc()['t']);

// payroll this month
$emp_sf = ($_force_store_filter) ? "AND e.store_id = {$userStore['id']}" : "";
$employees_for_payroll = $conn->query("SELECT id, daily_salary FROM employees e WHERE e.status='active' $emp_sf")->fetch_all(MYSQLI_ASSOC);
$month_att_rows = $conn->query("SELECT * FROM employee_attendance WHERE attendance_date BETWEEN '$ms' AND '$me'")->fetch_all(MYSQLI_ASSOC);
$month_att_map  = [];
foreach ($month_att_rows as $a) { $month_att_map[$a['employee_id']][$a['attendance_date']] = $a['attendance_type']; }
$stats['month_payroll'] = 0.0;
foreach ($employees_for_payroll as $emp) {
    $rate = floatval($emp['daily_salary']);
    $attMap = $month_att_map[$emp['id']] ?? [];
    $cur = new DateTime($ms); $end = new DateTime($me);
    while ($cur <= $end) {
        $ds   = $cur->format('Y-m-d');
        $type = $attMap[$ds] ?? null;
        if ($type === 'whole_day')    $stats['month_payroll'] += $rate;
        elseif ($type === 'half_day') $stats['month_payroll'] += $rate * 0.5;
        $cur->modify('+1 day');
    }
}

// combined revenue = regular + angkat
$stats['month_revenue_combined'] = $stats['month_revenue'] + $stats['month_angkat_revenue'];
$stats['month_profit_combined']  = $stats['month_profit']  + $stats['month_angkat_profit'];
$stats['month_net']              = $stats['month_profit_combined'] - $stats['month_payroll'];
$daysElapsed                     = max(1, (int)$today->format('j'));
$stats['avg_daily_revenue']      = $stats['month_revenue_combined'] / $daysElapsed;
$stats['avg_daily_profit']       = $stats['month_profit_combined']  / $daysElapsed;
$stats['month_margin']           = $stats['month_revenue_combined'] > 0
    ? ($stats['month_profit_combined'] / $stats['month_revenue_combined']) * 100 : 0;
$stats['month_cogs_combined']    = $stats['month_revenue_combined'] - $stats['month_profit_combined'];

// loss products today
$sf_ti = ($_force_store_filter) ? "AND t.store_id = {$userStore['id']}" : "";
$loss_items = $conn->query("
    SELECT ti.product_name, ti.quantity, ti.price, ti.purchase_price, ti.profit, t.transaction_number
    FROM transaction_items ti
    JOIN transactions t ON ti.transaction_id = t.id
    WHERE DATE(t.transaction_date)=CURDATE() AND t.status IN ('completed','pending') AND ti.profit < 0 $sf_ti
    ORDER BY ti.profit ASC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// 7-day chart (regular + angkat)
$chart7_labels=[]; $chart7_revenue=[]; $chart7_profit=[]; $chart7_payroll=[];
for ($i=6; $i>=0; $i--) {
    $d  = (new DateTime())->modify("-$i days");
    $ds = $d->format('Y-m-d');
    $chart7_labels[] = $d->format('D');
    $rev  = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM transactions t WHERE DATE(transaction_date)='$ds' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
    $arev = floatval($conn->query("SELECT COALESCE(SUM(amount_collected),0) as t FROM angkat_transactions a WHERE DATE(completed_at)='$ds' AND status='completed' $sfa")->fetch_assoc()['t']);
    $prof  = floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as t FROM transactions t WHERE DATE(transaction_date)='$ds' AND status IN ('completed','pending') $sf")->fetch_assoc()['t']);
    $aprof = floatval($conn->query("SELECT COALESCE(SUM(total_value - total_cost),0) as t FROM angkat_transactions a WHERE DATE(completed_at)='$ds' AND status='completed' $sfa")->fetch_assoc()['t']);
    $chart7_revenue[] = $rev + $arev;
    $chart7_profit[]  = $prof + $aprof;
    $dayPay = 0.0;
    foreach ($employees_for_payroll as $emp) {
        $att = $conn->query("SELECT attendance_type FROM employee_attendance WHERE employee_id={$emp['id']} AND attendance_date='$ds'")->fetch_assoc();
        if ($att) { $r = floatval($emp['daily_salary']); if ($att['attendance_type']==='whole_day') $dayPay+=$r; elseif ($att['attendance_type']==='half_day') $dayPay+=$r*0.5; }
    }
    $chart7_payroll[] = $dayPay;
}

// payment method breakdown (month) — includes angkat as its own method
$pm_rows = $conn->query("
    SELECT payment_method, COALESCE(SUM(total_amount),0) as revenue, COUNT(*) as cnt
    FROM transactions t
    WHERE transaction_date BETWEEN '$ms' AND '$me 23:59:59' AND status IN ('completed','pending') $sf
    GROUP BY payment_method ORDER BY revenue DESC
")->fetch_all(MYSQLI_ASSOC);
// add angkat row if there's angkat revenue this month
if ($stats['month_angkat_revenue'] > 0) {
    $angkat_cnt = $conn->query("SELECT COUNT(*) as c FROM angkat_transactions a WHERE completed_at BETWEEN '$ms' AND '$me 23:59:59' AND status='completed' $sfa")->fetch_assoc()['c'];
    $pm_rows[] = ['payment_method'=>'angkat','revenue'=>$stats['month_angkat_revenue'],'cnt'=>$angkat_cnt];
}

// top products this month
$tp_where = "t.status='completed' $sf_ti";
if ($date_from_p) $tp_where .= " AND DATE(t.transaction_date) >= '$date_from_p'";
if ($date_to_p) $tp_where .= " AND DATE(t.transaction_date) <= '$date_to_p'";
$top_products = $conn->query("
    SELECT ti.product_id, ti.product_name,
           CASE WHEN p.parent_product_id IS NOT NULL THEN CONCAT(ti.product_name, ' (', COALESCE(pp.individual_sell_unit, 'unit'), ')') ELSE ti.product_name END as display_name,
           p.parent_product_id,
           COALESCE(pc.category_name, ppc.category_name) as category_name,
           COALESCE(pb.brand_name, ppb.brand_name) as brand_name,
           SUM(ti.subtotal) as revenue, SUM(ti.profit) as profit, SUM(ti.quantity) as qty
    FROM transaction_items ti
    JOIN transactions t ON ti.transaction_id = t.id
    LEFT JOIN products p ON ti.product_id = p.id
    LEFT JOIN products pp ON p.parent_product_id = pp.id
    LEFT JOIN product_categories pc ON p.category_id = pc.id AND pc.is_deleted = 0
    LEFT JOIN product_categories ppc ON pp.category_id = ppc.id AND ppc.is_deleted = 0
    LEFT JOIN product_brands pb ON p.brand_id = pb.id AND pb.is_deleted = 0
    LEFT JOIN product_brands ppb ON pp.brand_id = ppb.id AND ppb.is_deleted = 0
    WHERE $tp_where
    GROUP BY ti.product_id ORDER BY profit DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Profit allocation by source
$pa = [];
$pa_date = '';
if ($date_from_p) $pa_date .= " AND DATE(t.transaction_date) >= '$date_from_p'";
if ($date_to_p) $pa_date .= " AND DATE(t.transaction_date) <= '$date_to_p'";
$pa_dateg = '';
if ($date_from_p) $pa_dateg .= " AND DATE(transaction_date) >= '$date_from_p'";
if ($date_to_p) $pa_dateg .= " AND DATE(transaction_date) <= '$date_to_p'";

// Revenue by source
$ra = [];
$ra['cash'] = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as r FROM transactions t WHERE payment_method='cash' AND status IN ('completed','pending') $sf $pa_date")->fetch_assoc()['r']);
$ra['delivery'] = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as r FROM transactions t WHERE payment_method='delivery' AND status IN ('completed','pending') $sf $pa_date")->fetch_assoc()['r']);
$ra['credit'] = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as r FROM transactions t WHERE payment_method='credit' AND status IN ('completed','pending') $sf $pa_date")->fetch_assoc()['r']);

// Cash sales profit
$pa['cash'] = floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as p FROM transactions t WHERE payment_method='cash' AND status IN ('completed','pending') $sf $pa_date")->fetch_assoc()['p']);
// Delivery product profit
$pa['delivery_product'] = floatval($conn->query("SELECT COALESCE(SUM(t.total_profit),0) as p FROM transactions t WHERE payment_method='delivery' AND status IN ('completed','pending') $sf $pa_date")->fetch_assoc()['p']);
// Delivery fees
$dfd2 = '';
if ($date_from_p) $dfd2 .= " AND DATE(d.created_at) >= '$date_from_p'";
if ($date_to_p) $dfd2 .= " AND DATE(d.created_at) <= '$date_to_p'";
$pa['delivery_fee'] = floatval($conn->query("SELECT COALESCE(SUM(d.delivery_fee),0) as p FROM deliveries d WHERE d.is_deleted=0 $sfd $dfd2")->fetch_assoc()['p']);
// Credit product profit
$pa['credit_product'] = floatval($conn->query("SELECT COALESCE(SUM(t.total_profit),0) as p FROM transactions t WHERE payment_method='credit' AND status IN ('completed','pending') $sf $pa_date")->fetch_assoc()['p']);
// Credit fees
$dfc2 = '';
if ($date_from_p) $dfc2 .= " AND DATE(c.created_at) >= '$date_from_p'";
if ($date_to_p) $dfc2 .= " AND DATE(c.created_at) <= '$date_to_p'";
$pa['credit_fee'] = floatval($conn->query("SELECT COALESCE(SUM(c.additional_charge),0) as p FROM credits c WHERE c.is_deleted=0 $sfc $dfc2")->fetch_assoc()['p']);
// Angkat profit
$pa['angkat'] = floatval($conn->query("SELECT COALESCE(SUM(total_value - total_cost),0) as p FROM angkat_transactions a WHERE status='completed' AND is_deleted=0 $sfa $dfa")->fetch_assoc()['p']);
// GCash fees
$pa['gcash'] = floatval($conn->query("SELECT COALESCE(SUM(fee),0) as p FROM gcash_transactions WHERE status='completed' AND is_deleted=0 AND (user_reference IS NULL OR user_reference != 'SETBAL') $pa_dateg")->fetch_assoc()['p']);
// ATM fees
$pa['atm'] = floatval($conn->query("SELECT COALESCE(SUM(total_profit),0) as p FROM transactions t WHERE payment_method='atm_withdrawal' AND status='completed' $sf $pa_date")->fetch_assoc()['p']);

$pa_total = array_sum($pa);

// Remaining revenue
$ra['angkat'] = floatval($conn->query("SELECT COALESCE(SUM(total_value),0) as r FROM angkat_transactions a WHERE status='completed' AND is_deleted=0 $sfa $dfa")->fetch_assoc()['r']);
$ra['gcash'] = $pa['gcash']; // fee IS the revenue for gcash
$ra['atm'] = $pa['atm']; // fee IS the revenue for atm
// Delivery/credit fees already included in their transaction total_amount, don't add again
$ra_total = $ra['cash'] + $ra['delivery'] + $ra['credit'] + $ra['angkat'] + $ra['gcash'] + $ra['atm'];

// Expenses
$exp_date = '';
if ($date_from_p) $exp_date .= " AND DATE(created_at) >= '$date_from_p'";
if ($date_to_p) $exp_date .= " AND DATE(created_at) <= '$date_to_p'";
$total_expenses = floatval($conn->query("SELECT COALESCE(SUM(amount),0) as t FROM expenses WHERE is_deleted=0 $exp_date")->fetch_assoc()['t']);

$sales_total = $pa['cash'] + $pa['delivery_product'] + $pa['credit_product'] + $pa['angkat'];
$fees_total = $pa['delivery_fee'] + $pa['credit_fee'] + $pa['gcash'] + $pa['atm'];
$take_home = $pa_total - $total_expenses;

// Sync stat cards with breakdown totals so they always match
$stats['total_revenue'] = $ra_total;
$stats['today_revenue'] = $ra_total;
$stats['total_profit'] = $pa_total;
$stats['today_profit'] = $pa_total;

// COGS / Inventory stock tracking
// Super admin: sees ALL stores combined. Branch admin: own store only.
// "View As" overrides this for super admin.
$_is_super_admin = ($currentUser['role'] ?? '') === 'super_admin';
$store_filter_inv = 0;
if (!$_is_super_admin || $_view_as_store) {
    $store_filter_inv = ($userStore ? $userStore['id'] : (!empty($currentUser['store_id']) ? $currentUser['store_id'] : 0));
    if (!$store_filter_inv) {
        @include_once __DIR__ . '/../sync/config.php';
        $dev_id = defined('LOCAL_DEVICE_ID') ? LOCAL_DEVICE_ID : 'DEVICE_A';
        $ds = $conn->query("SELECT id FROM stores WHERE device_id = '" . $conn->real_escape_string($dev_id) . "' AND status='active' LIMIT 1");
        if ($ds && $dr = $ds->fetch_assoc()) $store_filter_inv = $dr['id'];
    }
}
if (!$store_filter_inv && !$_is_super_admin) {
    $first_store = $conn->query("SELECT id FROM stores WHERE status='active' LIMIT 1")->fetch_assoc();
    $store_filter_inv = $first_store ? $first_store['id'] : 0;
}

// Current inventory value
$inv_store_filter = $store_filter_inv ? "sp.store_id = $store_filter_inv AND" : "";
$inv_val = $conn->query("SELECT COALESCE(SUM(sp.stock * sp.purchase_price),0) as cost, COALESCE(SUM(sp.stock * sp.price),0) as retail, COALESCE(SUM(sp.stock),0) as units
    FROM store_prices sp JOIN products p ON sp.product_id = p.id AND p.is_deleted = 0 AND p.parent_product_id IS NULL
    WHERE $inv_store_filter sp.is_deleted = 0")->fetch_assoc();
$stats['inv_cost'] = floatval($inv_val['cost']);
$stats['inv_retail'] = floatval($inv_val['retail']);
$stats['inv_units'] = intval($inv_val['units']);

// Supply spending in current period
$_supply_sf = $store_filter_inv ? " AND sr.store_id = $store_filter_inv" : "";
$supply_period_cost = floatval($conn->query("SELECT COALESCE(SUM(total_cost),0) as t FROM stock_receipts sr WHERE is_deleted=0$_supply_sf" . ($date_from_p ? " AND DATE(created_at) >= '$date_from_p'" : "") . ($date_to_p ? " AND DATE(created_at) <= '$date_to_p'" : ""))->fetch_assoc()['t']);
$supply_period_count = intval($conn->query("SELECT COUNT(*) as c FROM stock_receipts sr WHERE is_deleted=0$_supply_sf" . ($date_from_p ? " AND DATE(created_at) >= '$date_from_p'" : "") . ($date_to_p ? " AND DATE(created_at) <= '$date_to_p'" : ""))->fetch_assoc()['c']);

// Stock comparison: get products with current stock, recent receipt, and previous receipt
$stock_compare = $conn->query("SELECT p.id, p.name,
    sp.stock as current_stock, sp.purchase_price,
    COALESCE(pc.category_name,'') as category, COALESCE(pb.brand_name,'') as brand,
    (SELECT sri.quantity FROM stock_receipt_items sri JOIN stock_receipts sr ON sri.receipt_id = sr.id
     WHERE sri.product_id = p.id AND sr.is_deleted = 0 ORDER BY sr.created_at DESC LIMIT 1) as last_added,
    (SELECT sr.created_at FROM stock_receipt_items sri JOIN stock_receipts sr ON sri.receipt_id = sr.id
     WHERE sri.product_id = p.id AND sr.is_deleted = 0 ORDER BY sr.created_at DESC LIMIT 1) as last_restock_date,
    (SELECT sri.quantity FROM stock_receipt_items sri JOIN stock_receipts sr ON sri.receipt_id = sr.id
     WHERE sri.product_id = p.id AND sr.is_deleted = 0 ORDER BY sr.created_at DESC LIMIT 1 OFFSET 1) as prev_added
    FROM products p
    INNER JOIN store_prices sp ON p.id = sp.product_id AND $inv_store_filter sp.is_deleted = 0
    LEFT JOIN product_categories pc ON p.category_id = pc.id AND pc.is_deleted = 0
    LEFT JOIN product_brands pb ON p.brand_id = pb.id AND pb.is_deleted = 0
    WHERE p.is_deleted = 0 AND p.parent_product_id IS NULL
    ORDER BY p.name")->fetch_all(MYSQLI_ASSOC);

// hourly today
$hourly_raw = $conn->query("
    SELECT HOUR(transaction_date) as hr, COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as rev
    FROM transactions t WHERE DATE(transaction_date)=CURDATE() AND status='completed' $sf
    GROUP BY HOUR(transaction_date)
")->fetch_all(MYSQLI_ASSOC);
$hourly_map=[]; foreach ($hourly_raw as $h) { $hourly_map[(int)$h['hr']]=$h; }
$hourly_labels=[]; $hourly_cnt=[]; $hourly_rev=[];
for ($h=0;$h<=23;$h++){
    $hourly_labels[] = str_pad($h,2,'0',STR_PAD_LEFT).':00';
    $hourly_cnt[]    = isset($hourly_map[$h]) ? (int)$hourly_map[$h]['cnt']     : 0;
    $hourly_rev[]    = isset($hourly_map[$h]) ? floatval($hourly_map[$h]['rev']) : 0;
}

// ATM/Card stats
$stats['atm_total_withdrawn'] = floatval($conn->query("SELECT COALESCE(SUM(ABS(total_amount)),0) as t FROM transactions t WHERE payment_method='atm_cash_withdrawal' AND status='completed' $sf")->fetch_assoc()['t']);
$stats['atm_withdrawal_count'] = $conn->query("SELECT COUNT(*) as c FROM transactions t WHERE payment_method='atm_cash_withdrawal' AND status='completed' $sf")->fetch_assoc()['c'];
$stats['atm_service_fees'] = floatval($conn->query("SELECT COALESCE(SUM(total_amount),0) as t FROM transactions t WHERE payment_method='atm_service_charge' AND status='completed' $sf")->fetch_assoc()['t']);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Transaction History – Oro Store</title>
<link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
<link rel="stylesheet" href="/oro-store/style.css">
<link rel="stylesheet" href="/oro-store/transactions/transaction_history_styles.css">
<style>
/* ── Angkat-specific additions ────────────────────────────── */
.type-angkat{background:#f3e8ff;color:#7c3aed;border:1px solid #c4b5fd;}
.type-stock-in{background:#eef2ff;color:#4338ca;border:1px solid #a5b4fc;}
.type-gcash{background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc;}
.type-expense{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;}
.type-atm{background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;}
.payment-badge.angkat-badge{background:#7c3aed;color:#fff;}
.angkat-detail-header{background:linear-gradient(135deg,#7c3aed,#a78bfa);color:#fff;border-radius:8px;padding:16px 20px;margin-bottom:16px;display:flex;align-items:center;gap:12px;}
.angkat-detail-header h3{margin:0;font-size:16px;}
.angkat-detail-header .retailer{font-size:13px;opacity:.85;margin-top:3px;}
.angkat-stat-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.angkat-stat{flex:1;min-width:110px;background:#f8f4ff;border:1px solid #e9d5ff;border-radius:6px;padding:10px 14px;display:flex;flex-direction:column;gap:2px;}
.angkat-stat label{font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:#7c3aed;font-weight:600;}
.angkat-stat span{font-size:15px;font-weight:700;color:#1e1b4b;}
.angkat-stat span.red{color:#dc2626;}
.angkat-stat span.green{color:#16a34a;}
.angkat-stat span.amber{color:#d97706;}
.angkat-payments-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;}
.angkat-payments-table th{background:#f3e8ff;color:#7c3aed;padding:6px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.5px;}
.angkat-payments-table td{padding:7px 10px;border-bottom:1px solid #f0e6ff;}
.angkat-items-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px;}
.angkat-items-table th{background:#f3e8ff;color:#7c3aed;padding:7px 10px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.5px;}
.angkat-items-table td{padding:8px 10px;border-bottom:1px solid #f0e6ff;}
.angkat-items-table tr:hover td{background:#faf5ff;}
.half-rate{color:#d97706;font-size:10px;font-weight:600;}
.unit-rate{color:#6b7280;font-size:10px;}
.stat-card.angkat{border-left:4px solid #7c3aed;cursor:pointer;}
.stat-card.angkat:hover{background:#faf5ff;}
.nc-angkat{border-left:4px solid #7c3aed!important;}
</style>
</head>
<body>
<?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>
<main class="main-content">
<div class="th-page">

<!-- HEADER -->
<div class="history-header">
    <div>
        <h1>Transaction History</h1>
        <?php if ($_view_as_store): ?>
            <span class="store-note" style="color:#667eea;">Viewing as: <?php echo htmlspecialchars($_view_as_store['store_name']); ?> (<?php echo htmlspecialchars($_view_as_store['device_id']); ?>)</span>
        <?php elseif ($isAdmin): ?>
            <span class="store-note">Admin - All Stores</span>
        <?php elseif ($userStore): ?>
            <span class="store-note"><?php echo htmlspecialchars($userStore['store_name']); ?></span>
        <?php endif; ?>
    </div>
    <?php if (isSuperAdmin() && !empty($_all_stores)): ?>
    <div style="display:flex;gap:4px;align-items:center;">
        <?php $period_q = $period !== 'today' ? '&period=' . urlencode($period) : ''; ?>
        <span style="font-size:11px;color:#94a3b8;font-weight:600;margin-right:4px;">View as:</span>
        <a href="?<?php echo ltrim($period_q, '&'); ?>" class="va-tab <?php echo empty($_GET['view_as']) ? 'active' : ''; ?>">All</a>
        <?php foreach ($_all_stores as $st): if (!$st['device_id']) continue; ?>
        <a href="?view_as=<?php echo urlencode($st['device_id']) . $period_q; ?>" class="va-tab <?php echo (($_GET['view_as'] ?? '') === $st['device_id']) ? 'active' : ''; ?>">
            <?php echo htmlspecialchars(str_replace('DEVICE_', '', $st['device_id'])); ?> — <?php echo htmlspecialchars($st['store_name']); ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<style>
.va-tab{padding:5px 12px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;font-size:11px;font-weight:600;color:#64748b;cursor:pointer;transition:all .15s;text-decoration:none;}
.va-tab:hover{background:#e2e8f0;}
.va-tab.active{background:#667eea;color:#fff;border-color:#667eea;}
</style>

<!-- PERIOD SELECTOR -->
<style>
.period-btn{display:inline-block;padding:7px 14px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;font-weight:600;color:#475569;cursor:pointer;transition:all .15s;text-decoration:none;}
.period-btn:hover{background:#e2e8f0;}
.period-btn.active{background:#6366f1;color:#fff;border-color:#6366f1;}
</style>
<div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;align-items:center;">
    <?php $va_q = $_view_as_device ? '&view_as=' . urlencode($_view_as_device) : ''; ?>
    <a href="?period=today<?php echo $va_q; ?>" class="period-btn <?php echo $period==='today' && !$p_from ? 'active' : ''; ?>">Today</a>
    <a href="?period=week<?php echo $va_q; ?>" class="period-btn <?php echo $period==='week' ? 'active' : ''; ?>">This Week</a>
    <a href="?period=month<?php echo $va_q; ?>" class="period-btn <?php echo $period==='month' ? 'active' : ''; ?>">This Month</a>
    <a href="?period=year<?php echo $va_q; ?>" class="period-btn <?php echo $period==='year' ? 'active' : ''; ?>">This Year</a>
    <a href="?period=all<?php echo $va_q; ?>" class="period-btn <?php echo $period==='all' ? 'active' : ''; ?>">All Time</a>
    <?php
    @include_once __DIR__ . '/../sync/config.php';
    if (defined('LOCAL_DEVICE_ID') && LOCAL_DEVICE_ID !== 'DEVICE_A'): ?>
    <button class="period-btn" style="background:#0f172a;color:#f59e0b;border-color:#334155;" onclick="fetchFromServer('<?php echo LOCAL_DEVICE_ID; ?>')" id="fetch-server-btn">Fetch My History</button>
    <?php if (in_array($currentUser['role'], ['admin', 'super_admin'])): ?>
    <button class="period-btn" style="background:#0f172a;color:#22c55e;border-color:#334155;" onclick="fetchFromServer('all')" id="fetch-all-btn">Fetch All Devices</button>
    <?php endif; ?>
    <script>
    function fetchFromServer(device){
        var btn=device==='all'?document.getElementById('fetch-all-btn'):document.getElementById('fetch-server-btn');
        btn.textContent='Fetching...';btn.disabled=true;
        var devParam=device==='all'?'':'&device='+device;
        fetch('/oro-store/sync/pull_history.php?run=1&period=<?php echo $period; ?>'+devParam)
        .then(function(r){return r.json()}).then(function(d){
            if(d.success){
                btn.textContent='Fetched '+d.inserted+' records';
                btn.style.background='#166534';btn.style.color='#fff';
                setTimeout(function(){location.reload()},1500);
            } else {
                btn.textContent=d.message||'Failed';btn.style.background='#dc2626';
                setTimeout(function(){btn.textContent='Fetch from Server';btn.style.background='#0f172a';btn.style.color='#f59e0b';btn.disabled=false;},3000);
            }
        }).catch(function(e){
            btn.textContent='Error: '+e.message;btn.style.background='#dc2626';
            setTimeout(function(){btn.textContent='Fetch from Server';btn.style.background='#0f172a';btn.style.color='#f59e0b';btn.disabled=false;},3000);
        });
    }
    </script>
    <?php endif; ?>
    <div style="position:relative;display:inline-block;">
        <button class="period-btn <?php echo $p_from ? 'active' : ''; ?>" onclick="document.getElementById('more-menu').style.display = document.getElementById('more-menu').style.display==='none'?'block':'none'" id="more-btn">More ▾</button>
        <div id="more-menu" style="display:none;position:absolute;top:100%;left:0;z-index:100;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.15);padding:12px;min-width:220px;margin-top:4px;">
            <div style="font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;">SPECIFIC MONTH</div>
            <form method="GET" style="display:flex;gap:4px;margin-bottom:10px;">
                <input type="hidden" name="period" value="custom">
                <select name="pm" style="flex:1;padding:6px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;" onchange="this.form.pfrom.value=this.form.py.value+'-'+String(this.value).padStart(2,'0')+'-01'; var d=new Date(this.form.py.value,this.value,0); this.form.pto.value=this.form.py.value+'-'+String(this.value).padStart(2,'0')+'-'+d.getDate();">
                    <?php for ($m=1; $m<=12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php echo $m == date('n') ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                    <?php endfor; ?>
                </select>
                <select name="py" style="width:80px;padding:6px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;">
                    <?php for ($y=date('Y'); $y>=date('Y')-3; $y--): ?>
                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
                <input type="hidden" name="pfrom" value="<?php echo date('Y-m-01'); ?>">
                <input type="hidden" name="pto" value="<?php echo date('Y-m-t'); ?>">
                <button type="submit" style="padding:6px 10px;background:#6366f1;color:#fff;border:none;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">Go</button>
            </form>
            <div style="font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;">SPECIFIC YEAR</div>
            <form method="GET" style="display:flex;gap:4px;">
                <input type="hidden" name="period" value="custom">
                <select name="pfrom" style="flex:1;padding:6px;border:1px solid #d1d5db;border-radius:4px;font-size:12px;" onchange="this.form.pto.value=this.value.replace('-01-01','-12-31')">
                    <?php for ($y=date('Y'); $y>=date('Y')-5; $y--): ?>
                    <option value="<?php echo $y; ?>-01-01"><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
                <input type="hidden" name="pto" value="<?php echo date('Y'); ?>-12-31">
                <button type="submit" style="padding:6px 10px;background:#6366f1;color:#fff;border:none;border-radius:4px;font-size:11px;font-weight:600;cursor:pointer;">Go</button>
            </form>
        </div>
    </div>
    <span style="font-size:12px;color:#64748b;font-weight:600;margin-left:8px;"><?php echo $period_label; ?><?php if ($date_from_p && $date_to_p && $period !== 'all'): ?> <span style="font-size:10px;color:#94a3b8;">(<?php echo date('M j', strtotime($date_from_p)); ?> – <?php echo date('M j', strtotime($date_to_p)); ?>)</span><?php endif; ?></span>
</div>

<!-- NET CARDS -->
<?php
$revDiff  = $stats['today_revenue'] - $stats['yesterday_revenue'];
$profDiff = $stats['today_profit']  - $stats['yesterday_profit'];
?>
<div class="net-row">
    <div class="net-card nc-today" style="border-left:4px solid #3b82f6;">
        <span class="nc-label">Revenue</span>
        <span class="nc-val" style="color:#3b82f6;">₱<?php echo number_format($stats['today_revenue'],2); ?></span>
        <div class="nc-row">
            <div class="nc-mini"><span class="nc-mini-label">Transactions</span><span class="nc-mini-val"><?php echo $stats['today_transactions']; ?></span></div>
            <div class="nc-mini"><span class="nc-mini-label">COGS</span><span class="nc-mini-val red">₱<?php echo number_format($stats['today_cogs'],2); ?></span></div>
        </div>
    </div>
    <div class="net-card nc-today" style="border-left:4px solid #16a34a;">
        <span class="nc-label">Net Profit</span>
        <span class="nc-val <?php echo $stats['today_profit']>=0?'green':'red'; ?>">₱<?php echo number_format($stats['today_profit'],2); ?></span>
        <div class="nc-row">
            <div class="nc-mini"><span class="nc-mini-label">Margin</span><span class="nc-mini-val"><?php echo $stats['today_revenue'] > 0 ? number_format(($stats['today_profit']/$stats['today_revenue'])*100, 1) : '0'; ?>%</span></div>
            <div class="nc-mini"><span class="nc-mini-label">Fees</span><span class="nc-mini-val" style="color:#f59e0b;">₱<?php echo number_format($fees_total,2); ?></span></div>
        </div>
    </div>
    <div class="net-card nc-today" style="border-left:4px solid #ef4444;">
        <span class="nc-label">Expenses</span>
        <span class="nc-val" style="color:#ef4444;">₱<?php echo number_format($total_expenses,2); ?></span>
        <div class="nc-row">
            <div class="nc-mini"><span class="nc-mini-label">Inv. Cost</span><span class="nc-mini-val">₱<?php echo number_format($stats['inv_cost'],0); ?></span></div>
            <div class="nc-mini"><span class="nc-mini-label">Supply</span><span class="nc-mini-val">₱<?php echo number_format($supply_period_cost,0); ?></span></div>
        </div>
    </div>
    <div class="net-card nc-today" style="border-left:4px solid <?php echo $take_home >= 0 ? '#059669' : '#dc2626'; ?>;">
        <span class="nc-label">Take-Home</span>
        <span class="nc-val" style="color:<?php echo $take_home >= 0 ? '#059669' : '#dc2626'; ?>;">₱<?php echo number_format($take_home,2); ?></span>
        <div class="nc-row">
            <div class="nc-mini"><span class="nc-mini-label">Profit</span><span class="nc-mini-val green">₱<?php echo number_format($pa_total,2); ?></span></div>
            <div class="nc-mini"><span class="nc-mini-label">Expenses</span><span class="nc-mini-val red">-₱<?php echo number_format($total_expenses,2); ?></span></div>
        </div>
    </div>
    <!-- Expected Money -->
    <?php
    $remaining_retail = floatval($stats['inv_retail']);

    // GCash wallet balance
    require_once __DIR__ . '/../core/db_config.php';
    $conn2_tmp = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $_gcash_sf = $store_filter_inv ? " AND store_id = $store_filter_inv" : "";
    $gw = $conn2_tmp->query("SELECT
        COALESCE(SUM(CASE WHEN transaction_type='cash_out' THEN amount ELSE 0 END),0)
        - COALESCE(SUM(CASE WHEN transaction_type='cash_in' THEN amount ELSE 0 END),0)
        - COALESCE(SUM(CASE WHEN transaction_type IN ('send_gcash','bank_transfer') THEN amount ELSE 0 END),0) as bal
        FROM gcash_transactions WHERE status='completed' AND is_deleted=0$_gcash_sf");
    $gcash_wallet = $gw ? floatval($gw->fetch_assoc()['bal']) : 0;

    // Bank balance (no store_id column in bank_transactions)
    $bw = $conn2_tmp->query("SELECT
        COALESCE(SUM(CASE WHEN transaction_type='deposit' THEN amount ELSE 0 END),0)
        - COALESCE(SUM(CASE WHEN transaction_type='withdraw' THEN amount ELSE 0 END),0)
        - COALESCE(SUM(fee),0) as bal
        FROM bank_transactions WHERE is_deleted=0");
    $bank_balance = $bw ? floatval($bw->fetch_assoc()['bal']) : 0;
    $conn2_tmp->close();

    $expected_total = $ra_total + $remaining_retail + $gcash_wallet + $bank_balance - $total_expenses;
    ?>
    <div class="net-card" style="border-left:4px solid #1e293b;grid-column:1/-1;background:linear-gradient(135deg,#f8fafc,#f1f5f9);">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <span class="nc-label" style="font-size:13px;">💰 Expected Money (If All Stock Sold)</span>
                <div style="display:flex;gap:12px;margin-top:6px;font-size:11px;flex-wrap:wrap;">
                    <span style="color:#16a34a;">Revenue earned: ₱<?php echo number_format($ra_total, 0); ?></span>
                    <span style="color:#3b82f6;">Unsold stock (retail): ₱<?php echo number_format($remaining_retail, 0); ?></span>
                    <span style="color:#0ea5e9;">GCash wallet: ₱<?php echo number_format($gcash_wallet, 0); ?></span>
                    <span style="color:#6366f1;">Bank: ₱<?php echo number_format($bank_balance, 0); ?></span>
                    <span style="color:#dc2626;">Expenses: -₱<?php echo number_format($total_expenses, 0); ?></span>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:10px;color:#64748b;font-weight:700;text-transform:uppercase;">Expected Total</div>
                <div style="font-size:22px;font-weight:800;color:<?php echo $expected_total >= 0 ? '#16a34a' : '#dc2626'; ?>;">₱<?php echo number_format($expected_total, 2); ?></div>
            </div>
        </div>
    </div>
    <!-- Delivery card -->
    <div class="net-card" style="border-left:4px solid #ef4444;cursor:pointer;" onclick="filterByType('delivery')" title="Click to filter delivery transactions">
        <span class="nc-label">🚚 Deliveries</span>
        <span class="nc-val" style="color:#ef4444;">₱<?php echo number_format($stats['total_delivery_amount'],2); ?></span>
        <span style="font-size:12px;font-weight:400;color:#16a34a;">Paid: ₱<?php echo number_format($stats['delivery_paid'],2); ?></span>
        <span style="font-size:12px;font-weight:400;color:<?php echo $stats['delivery_profit']>=0?'#16a34a':'#dc2626'; ?>;">Profit: ₱<?php echo number_format($stats['delivery_profit'],2); ?></span>
        <div class="nc-row">
            <div class="nc-mini"><span class="nc-mini-label">Pending</span><span class="nc-mini-val red"><?php echo $stats['pending_deliveries']; ?></span></div>
            <div class="nc-mini"><span class="nc-mini-label">Outstanding</span><span class="nc-mini-val red">₱<?php echo number_format($stats['delivery_outstanding'],2); ?></span></div>
        </div>
        <span class="nc-trend"><?php echo $stats['total_delivery_count']; ?> total · <?php echo $stats['completed_deliveries']; ?> completed</span>
    </div>
    <!-- Credit card -->
    <div class="net-card" style="border-left:4px solid #667eea;cursor:pointer;" onclick="filterByType('credit')" title="Click to filter credit transactions">
        <span class="nc-label">💳 Credits</span>
        <span class="nc-val" style="color:#667eea;">₱<?php echo number_format($stats['credit_total_amount'],2); ?></span>
        <span style="font-size:12px;font-weight:400;color:#16a34a;">Paid: ₱<?php echo number_format($stats['collected_credits'],2); ?></span>
        <span style="font-size:12px;font-weight:400;color:<?php echo $stats['credit_profit']>=0?'#16a34a':'#dc2626'; ?>;">Profit: ₱<?php echo number_format($stats['credit_profit'],2); ?></span>
        <div class="nc-row">
            <div class="nc-mini"><span class="nc-mini-label">Unpaid</span><span class="nc-mini-val red"><?php echo $stats['unpaid_credits']; ?></span></div>
            <div class="nc-mini"><span class="nc-mini-label">Outstanding</span><span class="nc-mini-val red">₱<?php echo number_format($stats['credit_outstanding'],2); ?></span></div>
        </div>
        <span class="nc-trend"><?php echo $stats['total_credits']; ?> total (<?php echo $stats['partial_credits']; ?> partial)</span>
    </div>
    <!-- Angkat card -->
    <div class="net-card nc-angkat" onclick="filterByAngkat()" style="cursor:pointer;" title="Click to filter angkat transactions">
        <span class="nc-label">📦 Angkat</span>
        <span class="nc-val" style="color:#7c3aed;">₱<?php echo number_format($stats['angkat_total_value'],2); ?></span>
        <span style="font-size:12px;font-weight:400;color:#16a34a;">Collected: ₱<?php echo number_format($stats['angkat_collected'],2); ?></span>
        <span style="font-size:12px;font-weight:400;color:<?php echo $stats['angkat_profit']>=0?'#16a34a':'#dc2626'; ?>;">Profit: ₱<?php echo number_format($stats['angkat_profit'],2); ?></span>
        <div class="nc-row">
            <div class="nc-mini"><span class="nc-mini-label">Active</span><span class="nc-mini-val" style="color:#7c3aed;"><?php echo $stats['active_angkat']; ?></span></div>
            <div class="nc-mini"><span class="nc-mini-label">Outstanding</span><span class="nc-mini-val red">₱<?php echo number_format($stats['angkat_outstanding'],2); ?></span></div>
        </div>
        <span class="nc-trend"><?php echo $stats['total_angkat']; ?> total</span>
    </div>
    <!-- Card/ATM card -->
    <div class="net-card" style="border-left:4px solid #1e40af;cursor:pointer;" onclick="filterByType('atm')" title="Click to filter ATM transactions">
        <span class="nc-label">💳 Card/ATM</span>
        <span class="nc-val" style="color:#1e40af;">₱<?php echo number_format($stats['atm_total_withdrawn'],2); ?></span>
        <div class="nc-row">
            <div class="nc-mini"><span class="nc-mini-label">Withdrawals</span><span class="nc-mini-val"><?php echo $stats['atm_withdrawal_count']; ?></span></div>
            <div class="nc-mini"><span class="nc-mini-label">Service Fees</span><span class="nc-mini-val green">₱<?php echo number_format($stats['atm_service_fees'],2); ?></span></div>
        </div>
        <span class="nc-trend">click to filter</span>
    </div>
</div>

<!-- TOP PRODUCTS -->
<div class="panel" style="margin-bottom:16px;">
    <div class="panel-head" style="display:flex;justify-content:space-between;align-items:center;">
        <h2>Top Products</h2>
        <input type="text" id="tp-search" placeholder="Search product..." oninput="filterTopProducts()" style="padding:5px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;width:180px;">
    </div>
    <div class="panel-body" style="padding-top:6px;">
        <table class="tp-table" id="tp-table">
            <thead><tr>
                <th>#</th><th>Product</th><th class="num">Qty</th><th class="num">Revenue</th>
                <th class="num" style="cursor:pointer;" onclick="sortTopProducts()" title="Click to sort">Profit ⇅</th>
            </tr></thead>
            <tbody>
            <?php foreach ($top_products as $idx => $tp): ?>
                <tr data-profit="<?php echo floatval($tp['profit']); ?>" data-name="<?php echo htmlspecialchars(strtolower($tp['display_name'] . ' ' . ($tp['category_name'] ?? '') . ' ' . ($tp['brand_name'] ?? ''))); ?>">
                    <td style="color:#aaa;font-weight:700;"><?php echo $idx+1; ?></td>
                    <td>
                        <?php echo htmlspecialchars($tp['display_name']); ?>
                        <?php if ($tp['parent_product_id']): ?><span style="font-size:9px;color:#94a3b8;margin-left:2px;">child</span><?php endif; ?>
                        <?php if (!empty($tp['category_name'])): ?><span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#ede9fe;color:#7c3aed;margin-left:3px;"><?php echo htmlspecialchars($tp['category_name']); ?></span><?php endif; ?>
                        <?php if (!empty($tp['brand_name'])): ?><span style="font-size:9px;font-weight:600;padding:1px 5px;border-radius:3px;background:#dbeafe;color:#1d4ed8;margin-left:2px;"><?php echo htmlspecialchars($tp['brand_name']); ?></span><?php endif; ?>
                    </td>
                    <td class="num"><?php echo $tp['qty']; ?></td>
                    <td class="num">₱<?php echo number_format($tp['revenue'],2); ?></td>
                    <td class="num <?php echo floatval($tp['profit'])>=0?'green':'red'; ?>">₱<?php echo number_format($tp['profit'],2); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($top_products)): ?><tr><td colspan="5" style="text-align:center;color:#aaa;padding:22px;">No sales in this period</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
let tpSortAsc = false;
function sortTopProducts() {
    tpSortAsc = !tpSortAsc;
    const tbody = document.querySelector('#tp-table tbody');
    const rows = Array.from(tbody.querySelectorAll('tr[data-profit]'));
    rows.sort((a, b) => tpSortAsc
        ? parseFloat(a.dataset.profit) - parseFloat(b.dataset.profit)
        : parseFloat(b.dataset.profit) - parseFloat(a.dataset.profit));
    rows.forEach((r, i) => { r.cells[0].textContent = i + 1; tbody.appendChild(r); });
}
function filterTopProducts() {
    const q = document.getElementById('tp-search').value.toLowerCase();
    document.querySelectorAll('#tp-table tbody tr[data-name]').forEach(r => {
        r.style.display = r.dataset.name.includes(q) ? '' : 'none';
    });
}
</script>

<!-- PROFIT ALLOCATION -->
<div class="panel" style="margin-bottom:16px;">
    <div class="panel-head"><h2>Profit Breakdown by Source</h2></div>
    <div class="panel-body" style="padding:0;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="padding:8px 14px;text-align:left;font-size:11px;color:#64748b;">Source</th>
                <th style="padding:8px 14px;text-align:right;font-size:11px;color:#64748b;">Revenue</th>
                <th style="padding:8px 14px;text-align:right;font-size:11px;color:#64748b;">Sales Profit</th>
                <th style="padding:8px 14px;text-align:right;font-size:11px;color:#64748b;">Fee</th>
                <th style="padding:8px 14px;text-align:right;font-size:11px;color:#64748b;">Total Profit</th>
                <th style="padding:8px 14px;text-align:right;font-size:11px;color:#64748b;">Share</th>
                <th style="padding:8px 14px;text-align:left;font-size:11px;color:#64748b;width:20%;"></th>
            </tr></thead>
            <tbody>
            <?php
            $pa_rows = [
                ['Cash',     $ra['cash'],     $pa['cash'],             0,                  '#22c55e'],
                ['Delivery', $ra['delivery'], $pa['delivery_product'],  $pa['delivery_fee'], '#f97316'],
                ['Credit',   $ra['credit'],   $pa['credit_product'],    $pa['credit_fee'],   '#3b82f6'],
                ['Angkat',   $ra['angkat'],   $pa['angkat'],            0,                  '#8b5cf6'],
                ['GCash',    $ra['gcash'],    0,                        $pa['gcash'],        '#0ea5e9'],
                ['ATM',      $ra['atm'],      0,                        $pa['atm'],          '#475569'],
            ];
            foreach ($pa_rows as $row):
                $row_profit = $row[2] + $row[3];
                $pct = $pa_total > 0 ? ($row_profit / $pa_total) * 100 : 0;
            ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:7px 14px;font-weight:600;color:#1e293b;">
                    <span style="display:inline-block;width:8px;height:8px;border-radius:2px;background:<?php echo $row[4]; ?>;margin-right:6px;"></span><?php echo $row[0]; ?>
                </td>
                <td style="padding:7px 14px;text-align:right;font-weight:600;color:<?php echo $row[1] > 0 ? '#1e293b' : '#94a3b8'; ?>;"><?php echo $row[1] > 0 ? '₱' . number_format($row[1], 2) : '—'; ?></td>
                <td style="padding:7px 14px;text-align:right;font-weight:600;color:<?php echo $row[2] > 0 ? '#16a34a' : '#94a3b8'; ?>;"><?php echo $row[2] > 0 ? '₱' . number_format($row[2], 2) : '—'; ?></td>
                <td style="padding:7px 14px;text-align:right;font-weight:600;color:<?php echo $row[3] > 0 ? '#f59e0b' : '#94a3b8'; ?>;"><?php echo $row[3] > 0 ? '₱' . number_format($row[3], 2) : '—'; ?></td>
                <td style="padding:7px 14px;text-align:right;font-weight:700;color:<?php echo $row_profit > 0 ? '#1e293b' : '#94a3b8'; ?>;">₱<?php echo number_format($row_profit, 2); ?></td>
                <td style="padding:7px 14px;text-align:right;color:#64748b;font-size:12px;"><?php echo number_format($pct, 1); ?>%</td>
                <td style="padding:7px 14px;"><div style="background:#f1f5f9;border-radius:3px;height:12px;overflow:hidden;"><div style="background:<?php echo $row[4]; ?>;height:100%;width:<?php echo min(max($pct, 1), 100); ?>%;border-radius:3px;"></div></div></td>
            </tr>
            <?php endforeach; ?>
            <tr style="border-top:2px solid #1e293b;background:#f8fafc;">
                <td style="padding:9px 14px;font-weight:800;font-size:13px;">TOTAL PROFIT</td>
                <td style="padding:9px 14px;text-align:right;font-weight:800;color:#1e293b;">₱<?php echo number_format($ra_total, 2); ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:800;color:#16a34a;">₱<?php echo number_format($sales_total, 2); ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:800;color:#f59e0b;">₱<?php echo number_format($fees_total, 2); ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:800;font-size:14px;color:#1e293b;">₱<?php echo number_format($pa_total, 2); ?></td>
                <td style="padding:9px 14px;text-align:right;font-weight:700;">100%</td>
                <td></td>
            </tr>
            <?php if ($total_expenses > 0): ?>
            <tr style="background:#fee2e2;">
                <td style="padding:9px 14px;font-weight:700;color:#991b1b;">Less: Expenses</td>
                <td colspan="3"></td>
                <td style="padding:9px 14px;text-align:right;font-weight:800;color:#dc2626;">-₱<?php echo number_format($total_expenses, 2); ?></td>
                <td colspan="2"></td>
            </tr>
            <tr style="background:#f0fdf4;border-top:2px solid #1e293b;">
                <td style="padding:9px 14px;font-weight:800;font-size:14px;">TAKE-HOME</td>
                <td colspan="3"></td>
                <td style="padding:9px 14px;text-align:right;font-weight:800;font-size:15px;color:<?php echo $take_home >= 0 ? '#16a34a' : '#dc2626'; ?>;">₱<?php echo number_format($take_home, 2); ?></td>
                <td colspan="2"></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- STOCK / COGS TABLE -->
<div class="panel" style="margin-bottom:16px;">
    <div class="panel-head" style="display:flex;justify-content:space-between;align-items:center;">
        <h2>Stock &amp; Supply Tracking</h2>
        <div style="display:flex;gap:6px;align-items:center;">
            <input type="text" id="stock-search" placeholder="Search product..." oninput="filterStockTable()" style="padding:5px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;width:160px;" list="brand-list">
            <datalist id="brand-list">
                <?php
                $brands_unique = [];
                foreach ($stock_compare as $sc) { if ($sc['brand'] && !in_array($sc['brand'], $brands_unique)) $brands_unique[] = $sc['brand']; }
                foreach ($brands_unique as $b): ?>
                <option value="<?php echo htmlspecialchars($b); ?>">
                <?php endforeach; ?>
                <?php
                $cats_unique = [];
                foreach ($stock_compare as $sc) { if ($sc['category'] && !in_array($sc['category'], $cats_unique)) $cats_unique[] = $sc['category']; }
                foreach ($cats_unique as $c): ?>
                <option value="<?php echo htmlspecialchars($c); ?>">
                <?php endforeach; ?>
            </datalist>
        </div>
    </div>
    <div class="panel-body" style="padding:0;overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;" id="stock-table">
            <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
                <th style="padding:8px 10px;text-align:left;font-size:11px;color:#64748b;">Product</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;color:#64748b;">Cost</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;color:#64748b;cursor:pointer;" onclick="sortStockTable('stock')">Stock ⇅</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;color:#64748b;">Last Added</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;color:#64748b;">Prev Added</th>
                <th style="padding:8px 10px;text-align:center;font-size:11px;color:#64748b;">Change</th>
                <th style="padding:8px 10px;text-align:left;font-size:11px;color:#64748b;">Last Restock</th>
                <th style="padding:8px 10px;text-align:right;font-size:11px;color:#64748b;">Supply Cost</th>
            </tr></thead>
            <tbody>
            <?php if (empty($stock_compare)): ?>
                <tr><td colspan="8" style="padding:20px;text-align:center;color:#94a3b8;">No products found</td></tr>
            <?php else: ?>
                <?php $total_supply_cost_col = 0; ?>
                <?php foreach ($stock_compare as $sc):
                    $last = (int)($sc['last_added'] ?? 0);
                    $prev = (int)($sc['prev_added'] ?? 0);
                    $supply_cost_item = $last * floatval($sc['purchase_price']);
                    $total_supply_cost_col += $supply_cost_item;
                    $diff = $last - $prev;
                    $pct_change = $prev > 0 ? (($diff / $prev) * 100) : ($last > 0 ? 100 : 0);
                    $arrow = $diff > 0 ? '▲' : ($diff < 0 ? '▼' : '—');
                    $arrow_color = $diff > 0 ? '#dc2626' : ($diff < 0 ? '#16a34a' : '#94a3b8');
                    // Red = bought more (spent more), Green = bought less (saved)
                ?>
                <tr data-stock="<?php echo $sc['current_stock']; ?>" data-name="<?php echo htmlspecialchars(strtolower($sc['name'] . ' ' . $sc['category'] . ' ' . $sc['brand'])); ?>" style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:7px 10px;font-weight:600;">
                        <?php echo htmlspecialchars($sc['name']); ?>
                        <?php if ($sc['category']): ?><span style="font-size:9px;padding:1px 4px;border-radius:3px;background:#ede9fe;color:#7c3aed;margin-left:3px;"><?php echo htmlspecialchars($sc['category']); ?></span><?php endif; ?>
                        <?php if ($sc['brand']): ?><span style="font-size:9px;padding:1px 4px;border-radius:3px;background:#dbeafe;color:#1d4ed8;margin-left:2px;"><?php echo htmlspecialchars($sc['brand']); ?></span><?php endif; ?>
                    </td>
                    <td style="padding:7px 10px;text-align:right;color:#64748b;">₱<?php echo number_format($sc['purchase_price'], 2); ?></td>
                    <td style="padding:7px 10px;text-align:right;font-weight:700;color:<?php echo $sc['current_stock'] <= 5 ? ($sc['current_stock'] == 0 ? '#dc2626' : '#f59e0b') : '#1e293b'; ?>;"><?php echo $sc['current_stock']; ?></td>
                    <td style="padding:7px 10px;text-align:right;font-weight:600;color:#3b82f6;"><?php echo $last > 0 ? '+' . $last : '—'; ?></td>
                    <td style="padding:7px 10px;text-align:right;color:#64748b;"><?php echo $prev > 0 ? '+' . $prev : '—'; ?></td>
                    <td style="padding:7px 10px;text-align:center;">
                        <?php if ($last > 0 || $prev > 0): ?>
                            <span style="color:<?php echo $arrow_color; ?>;font-weight:700;font-size:11px;">
                                <?php echo $arrow; ?> <?php echo $diff != 0 ? abs(number_format($pct_change, 0)) . '%' : ''; ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#94a3b8;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:7px 10px;font-size:11px;color:#64748b;"><?php echo $sc['last_restock_date'] ? date('M j', strtotime($sc['last_restock_date'])) : '—'; ?></td>
                    <td style="padding:7px 10px;text-align:right;font-weight:600;color:<?php echo $supply_cost_item > 0 ? '#dc2626' : '#94a3b8'; ?>;"><?php echo $supply_cost_item > 0 ? '₱' . number_format($supply_cost_item, 2) : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (!empty($stock_compare)): ?>
    <div style="text-align:right;padding:10px 14px;font-size:13px;border-top:2px solid #1e293b;">
        <span style="color:#64748b;">Total Supply Cost (Last Restock):</span>
        <strong style="color:#dc2626;font-size:15px;margin-left:6px;">₱<?php echo number_format($total_supply_cost_col, 2); ?></strong>
    </div>
    <?php endif; ?>
</div>
<script>
let stockSortAsc = true;
function sortStockTable(col) {
    stockSortAsc = !stockSortAsc;
    const tbody = document.querySelector('#stock-table tbody');
    const rows = Array.from(tbody.querySelectorAll('tr[data-stock]'));
    rows.sort((a, b) => stockSortAsc ? a.dataset.stock - b.dataset.stock : b.dataset.stock - a.dataset.stock);
    rows.forEach(r => tbody.appendChild(r));
}
function filterStockTable() {
    const q = document.getElementById('stock-search').value.toLowerCase();
    document.querySelectorAll('#stock-table tbody tr[data-name]').forEach(r => {
        r.style.display = r.dataset.name.includes(q) ? '' : 'none';
    });
}
</script>

<!-- STAT CARDS -->
<div class="stats-dashboard">
    <div class="stat-card"><h3>Total Transactions</h3><p class="stat-value"><?php echo $stats['total_transactions'];?></p><span class="stat-label">Completed</span></div>
    <div class="stat-card"><h3>Total Revenue</h3><p class="stat-value">₱<?php echo number_format($stats['total_revenue'],2);?></p><span class="stat-label">All Time</span></div>
    <div class="stat-card"><h3>Total Profit</h3><p class="stat-value" style="color:<?php echo $stats['total_profit']>=0?'#28a745':'#dc3545';?>">₱<?php echo number_format($stats['total_profit'],2);?></p><span class="stat-label">Before expenses</span></div>
    <div class="stat-card"><h3>Expenses</h3><p class="stat-value" style="color:#dc3545;">₱<?php echo number_format($total_expenses,2);?></p><span class="stat-label">This period</span></div>
    <div class="stat-card"><h3>Take-Home</h3><p class="stat-value" style="color:<?php echo $take_home>=0?'#28a745':'#dc3545';?>">₱<?php echo number_format($take_home,2);?></p><span class="stat-label">Profit - Expenses</span></div>
    <div class="stat-card warning" onclick="window.open('/oro-store/transactions/edited_transactions.php','_blank')"><h3>⚠️ Edited</h3><p class="stat-value"><?php echo $stats['edited_transactions'];?></p><span class="stat-label">Requires Review</span></div>
    <div class="stat-card" onclick="document.getElementById('filter-payment').value='stock_in';applyFilters();" style="cursor:pointer;"><h3>📦 Supply In</h3><p class="stat-value"><?php echo $stats['total_supply_count'];?></p><span class="stat-label">₱<?php echo number_format($stats['total_supply_cost'],0);?> total cost</span></div>
</div>

<!-- FILTERS -->
<div class="filters-section">
    <div class="filter-group"><label>Status</label>
        <select id="filter-status">
            <option value="all" selected>All</option>
            <option value="completed">Completed</option>
            <option value="active">Active</option>
            <option value="edited">Edited</option>
            <option value="pending">Pending</option>
            <option value="cancelled">Cancelled</option>
        </select>
    </div>
    <div class="filter-group"><label>Payment / Type</label>
        <select id="filter-payment">
            <option value="all">All Methods</option>
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
            <option value="delivery">Delivery</option>
            <option value="credit">Credit</option>
            <option value="angkat">Angkat</option>
            <option value="atm">Card/ATM</option>
            <option value="stock_in">Stock In (Supply)</option>
            <option value="gcash_service">GCash (Fees)</option>
            <option value="atm_service">ATM (Fees)</option>
            <option value="expense">Expenses</option>
        </select>
    </div>
    <?php if (in_array($currentUser['role'], ['admin', 'super_admin']) && !empty($_all_stores)): ?>
    <div class="filter-group"><label>Device / Store</label>
        <select id="filter-device">
            <option value="all">All Devices</option>
            <?php foreach ($_all_stores as $st): ?>
            <option value="<?php echo htmlspecialchars($st['device_id'] ?? ''); ?>">
                <?php echo htmlspecialchars($st['device_id'] ?? 'No Device'); ?> — <?php echo htmlspecialchars($st['store_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="filter-group"><label>From</label><input type="date" id="filter-date-from"></div>
    <div class="filter-group"><label>To</label><input type="date" id="filter-date-to"></div>
    <div class="filter-group"><label>Search</label><input type="text" id="filter-search" placeholder="ID / Product / Retailer / Recipient"></div>
    <button onclick="applyFilters()" class="btn-filter">Apply</button>
    <button onclick="resetFilters()" class="btn-reset">Reset</button>
    <button onclick="exportToCSV()" class="btn-export">CSV Export</button>
</div>

<!-- TABLE -->
<div style="display:flex;justify-content:flex-end;margin-bottom:8px;">
    <button id="breakdownToggle" onclick="toggleBreakdown()" class="btn-filter" style="font-size:12px;padding:6px 14px;">📊 Breakdown</button>
</div>
<div class="table-container"><div class="table-scroll">
<table class="transactions-table">
<thead id="transactions-thead"><tr>
    <th>ID</th><th>Date</th><th style="text-align:center;">Type</th><th>Details</th>
    <th style="text-align:center;">Items</th><th style="text-align:right;">Total</th>
    <th style="text-align:center;">Payment</th>
    <th style="text-align:center;">Device</th>
    <th style="text-align:center;">Status</th><th style="text-align:center;">Action</th>
</tr></thead>
<tbody id="transactions-tbody"><tr><td colspan="10" style="text-align:center;padding:24px;color:#aaa;">Loading…</td></tr></tbody>
</table>
</div></div>

</div><!-- .th-page -->

<!-- MODAL -->
<div class="modal" id="details-modal">
    <div class="modal-content large-modal">
        <div class="modal-header"><h2>Transaction Details</h2><button onclick="closeDetailsModal()" class="btn-close-modal">&times;</button></div>
        <div id="details-content" class="modal-body">Loading…</div>
    </div>
</div>

</main>
<!-- SCRIPTS -->
<script>
/* ═══════════════════════════════════════════════════════════════
   TABLE + MODAL
═══════════════════════════════════════════════════════════════ */
let currentTransactions = [];
let breakdownMode = false;
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('filter-date-from').value = '<?php echo $date_from_p; ?>';
    document.getElementById('filter-date-to').value = '<?php echo $date_to_p; ?>';
    applyFilters();
});

// Close more menu on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('#more-btn') && !e.target.closest('#more-menu')) {
        const m = document.getElementById('more-menu');
        if (m) m.style.display = 'none';
    }
});

function applyFilters() {
    const deviceEl = document.getElementById('filter-device');
    const viewAs = new URLSearchParams(window.location.search).get('view_as') || '';
    const p = new URLSearchParams({
        action:         'filter_transactions',
        status:         document.getElementById('filter-status').value,
        payment_method: document.getElementById('filter-payment').value,
        date_from:      document.getElementById('filter-date-from').value,
        date_to:        document.getElementById('filter-date-to').value,
        search:         document.getElementById('filter-search').value,
        device:         deviceEl ? deviceEl.value : 'all',
        view_as:        viewAs
    });
    fetch('/oro-store/transactions/transaction_history.php?' + p)
        .then(r => r.json())
        .then(data => { currentTransactions = data; breakdownMode ? displayBreakdown(data) : displayTransactions(data); })
        .catch(e => { console.error(e); alert('Error loading transactions'); });
}

function switchDevice(btn, deviceId) {
    document.querySelectorAll('.device-tab').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    var devEl = document.getElementById('filter-device');
    if (devEl) devEl.value = deviceId;
    applyFilters();
}

function filterByAngkat() {
    document.getElementById('filter-payment').value = 'angkat';
    document.getElementById('filter-status').value  = 'all';
    applyFilters();
    document.querySelector('.filters-section')?.scrollIntoView({behavior:'smooth'});
}

function filterByType(type) {
    document.getElementById('filter-payment').value = type;
    document.getElementById('filter-status').value  = 'all';
    applyFilters();
    document.querySelector('.filters-section')?.scrollIntoView({behavior:'smooth'});
}

function displayTransactions(transactions) {
    const thead = document.getElementById('transactions-thead');
    thead.innerHTML = `<tr>
        <th>ID</th><th>Date</th><th style="text-align:center;">Type</th><th>Details</th>
        <th style="text-align:center;">Items</th><th style="text-align:right;">Total</th>
        <th style="text-align:center;">Payment</th>
        <th style="text-align:center;">Status</th><th style="text-align:center;">Action</th>
    </tr>`;
    const tbody = document.getElementById('transactions-tbody');
    if (!transactions.length) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:24px;color:#aaa;">No transactions found</td></tr>';
        return;
    }
    const typeMap = {
        delivery:            ['🚚 DELIVERY','type-delivery'],
        delivery_payment:    ['🚚 DEL PAID','type-delivery'],
        credit:              ['💳 CREDIT','type-credit'],
        credit_payment:      ['💳 CR PAID','type-credit'],
        gcash:               ['📱 GCASH','type-gcash'],
        angkat:              ['📦 ANGKAT','type-angkat'],
        atm_cash_withdrawal: ['💳 ATM OUT','type-atm'],
        atm_service_charge:  ['💳 ATM FEE','type-atm'],
        atm_withdrawal:      ['💳 ATM','type-atm'],
        stock_in:            ['📦 STOCK IN','type-stock-in'],
        gcash_service:       ['💰 GCASH','type-gcash'],
        atm_service:         ['💳 ATM','type-atm'],
        expense:             ['💸 EXPENSE','type-expense']
    };
    let html = '';
    transactions.forEach(t => {
        const isAngkat = t.is_angkat || t.payment_method === 'angkat';
        const isStockIn = t.is_stock_in || t.payment_method === 'stock_in';
        const isGcashSvc = t.is_gcash_service || t.payment_method === 'gcash_service';
        const isAtmSvc = t.is_atm_service || t.payment_method === 'atm_service';
        const date     = new Date(t.transaction_date).toLocaleString();
        const statusCls = 'status-' + (t.status || 'completed');
        const [typeTxt, typeCls] = typeMap[t.payment_method] || ['💰 SALE','type-sale'];

        let details;
        const isExpense = t.is_expense || t.payment_method === 'expense';
        if (isExpense) {
            details = `💸 ${t.products || 'Expense'}`;
        } else if (isAtmSvc) {
            details = t.products || '💳 ATM Fee';
        } else if (isGcashSvc) {
            details = t.products || '💰 GCash Fee';
        } else if (isStockIn) {
            details = `📦 <strong>${escHtml(t.supplier_name || 'No supplier')}</strong>`;
            if (t.invoice_number) details += ` <span style="color:#6366f1;font-size:12px;">#${escHtml(t.invoice_number)}</span>`;
            if (t.products) details += `<br><small style="color:#666;">${t.products.length>50?t.products.slice(0,50)+'…':t.products}</small>`;
        } else if (isAngkat) {
            details = `📦 <strong>${escHtml(t.angkat_retailer || t.retailer_name || '—')}</strong>`;
            if (t.products) details += `<br><small style="color:#666;">${t.products.length>50?t.products.slice(0,50)+'…':t.products}</small>`;
        } else if (t.payment_method === 'delivery' && t.recipient_name) {
            details = `🚚 ${escHtml(t.recipient_name)}`+(t.recipient_address?`<br><small style="color:#666">${t.recipient_address.slice(0,35)}${t.recipient_address.length>35?'…':''}</small>`:'');
        } else {
            details = t.products ? (t.products.length>42?t.products.slice(0,42)+'…':t.products) : '—';
        }

        let payBadge;
        if (isExpense) {
            payBadge = `<span class="payment-badge" style="background:#dc2626;color:#fff;">💸 EXPENSE</span>`;
        } else if (isAtmSvc) {
            payBadge = `<span class="payment-badge" style="background:#475569;color:#fff;">💳 ATM FEE</span>`;
        } else if (isGcashSvc) {
            const gcType = t.gcash_type === 'cash_in' ? 'CASH IN' : 'CASH OUT';
            payBadge = `<span class="payment-badge" style="background:#0284c7;color:#fff;">💰 ${gcType}</span>`;
        } else if (isStockIn) {
            payBadge = `<span class="payment-badge" style="background:#6366f1;color:#fff;">📦 SUPPLY</span>`;
        } else if (isAngkat) {
            const statusColor = t.status==='active'?'#7c3aed':t.status==='completed'?'#28a745':'#dc3545';
            payBadge = `<span class="payment-badge angkat-badge" style="background:${statusColor}">📦 ${(t.status||'active').toUpperCase()}</span>`;
        } else if (t.payment_method === 'delivery') {
            payBadge = `<span class="payment-badge" style="background:${t.delivery_status==='pending'?'#ffc107':t.delivery_status==='completed'?'#28a745':'#dc3545'}">🚚 ${(t.delivery_status||'pending').toUpperCase()}</span>`;
        } else {
            payBadge = `<span class="payment-badge">${(t.payment_method||'cash').toUpperCase()}</span>`;
        }

        const total = parseFloat(t.total_amount || 0);
        const itemCount = isStockIn ? (t.total_items || '—') : isAngkat ? (t.products ? t.products.split(',').length : '—') : (t.total_items || t.items_count || 0);

        const isGcashSvc3 = t.is_gcash_service || t.payment_method === 'gcash_service';
        const isAtmSvc3 = t.is_atm_service || t.payment_method === 'atm_service';
        const viewCall = isStockIn
            ? `viewStockReceipt(${t.id})`
            : isGcashSvc3
            ? `viewGcashDetails(${t.id})`
            : isAngkat
            ? `viewDetails(${t.id}, true)`
            : `viewDetails(${t.id}, false)`;

        html += `<tr>
            <td>#${t.transaction_number || t.id}</td>
            <td>${date}</td>
            <td style="text-align:center;"><span class="type-badge ${typeCls}">${typeTxt}</span></td>
            <td>${details}</td>
            <td style="text-align:center;">${itemCount}</td>
            <td style="text-align:right;font-weight:600;">₱${total.toFixed(2)}</td>
            <td style="text-align:center;">${payBadge}</td>
            <td style="text-align:center;"><span style="font-size:10px;padding:2px 6px;border-radius:4px;font-weight:700;background:${(t.device_id||'').includes('_A')?'#eef2ff':((t.device_id||'').includes('_B')?'#fffbeb':'#f1f5f9')};color:${(t.device_id||'').includes('_A')?'#6366f1':((t.device_id||'').includes('_B')?'#d97706':'#64748b')}">${t.device_id ? t.device_id.replace('DEVICE_','') : '—'}</span></td>
            <td style="text-align:center;"><span class="status-badge ${statusCls}">${(t.status||'').toUpperCase()}</span></td>
            <td style="text-align:center;"><button onclick="${viewCall}" class="btn-view">Details</button></td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function toggleBreakdown() {
    breakdownMode = !breakdownMode;
    const btn = document.getElementById('breakdownToggle');
    btn.textContent = breakdownMode ? '📋 Summary' : '📊 Breakdown';
    btn.style.background = breakdownMode ? '#667eea' : '';
    btn.style.color = breakdownMode ? '#fff' : '';
    breakdownMode ? displayBreakdown(currentTransactions) : displayTransactions(currentTransactions);
}

function displayBreakdown(transactions) {
    const thead = document.getElementById('transactions-thead');
    const tbody = document.getElementById('transactions-tbody');
    thead.innerHTML = `<tr>
        <th>ID</th><th>Date</th><th style="text-align:center;">Type</th><th>Details</th>
        <th style="text-align:right;">Subtotal</th><th style="text-align:right;">Fee</th>
        <th style="text-align:right;">Profit</th><th style="text-align:center;">Payment</th>
        <th style="text-align:center;">Status</th>
    </tr>`;
    if (!transactions.length) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:24px;color:#aaa;">No transactions found</td></tr>';
        return;
    }
    const typeMap = {
        delivery:['🚚 DEL','type-delivery'], delivery_payment:['🚚 DEL PAID','type-delivery'],
        credit:['💳 CR','type-credit'], credit_payment:['💳 CR PAID','type-credit'],
        gcash:['📱 GCASH','type-gcash'], angkat:['📦 ANGKAT','type-angkat'],
        atm_cash_withdrawal:['💳 ATM OUT','type-atm'], atm_service_charge:['💳 ATM FEE','type-atm'], atm_withdrawal:['💳 ATM','type-atm'],
        stock_in:['📦 STOCK IN','type-stock-in'],
        gcash_service:['💰 GCASH','type-gcash'],
        atm_service:['💳 ATM','type-atm']
    };
    let html = '';
    transactions.forEach(t => {
        const isAngkat = t.is_angkat || t.payment_method === 'angkat';
        const isStockIn = t.is_stock_in || t.payment_method === 'stock_in';
        const date = new Date(t.transaction_date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
        const [typeTxt, typeCls] = typeMap[t.payment_method] || ['💰 SALE','type-sale'];
        const statusCls = 'status-' + (t.status || 'completed');

        let details;
        if (isStockIn) details = `📦 ${escHtml(t.supplier_name || 'No supplier')}${t.invoice_number ? ' #'+escHtml(t.invoice_number) : ''}`;
        else if (isAngkat) details = escHtml(t.angkat_retailer || t.retailer_name || '—');
        else if (t.payment_method === 'delivery' && t.recipient_name) details = escHtml(t.recipient_name);
        else details = t.products ? (t.products.length > 30 ? t.products.slice(0,30)+'…' : t.products) : '—';

        const isGcashSvc2 = t.is_gcash_service || t.payment_method === 'gcash_service';
        const isAtmSvc2 = t.is_atm_service || t.payment_method === 'atm_service';
        const total = parseFloat(t.total_amount || 0);
        const profit = isStockIn ? 0 : parseFloat(t.total_profit || 0);
        let fee, subtotal;
        if (isGcashSvc2 || isAtmSvc2) {
            subtotal = parseFloat(t.amount || 0);
            fee = profit;
        } else if (isStockIn) {
            subtotal = parseFloat(t.total_selling_value || 0);
            fee = total;
        } else if (isAngkat) {
            subtotal = total;
            fee = parseFloat(t.total_cost || 0);
        } else if (t.payment_method === 'credit') {
            subtotal = total;
            fee = parseFloat(t.credit_fee || 0);
        } else if (t.payment_method === 'delivery') {
            subtotal = total;
            fee = parseFloat(t.delivery_fee || 0);
        } else {
            subtotal = total;
            fee = 0;
        }
        const profitColor = isStockIn ? '#4338ca' : profit < 0 ? '#dc2626' : profit > 0 ? '#16a34a' : '#94a3b8';

        let payBadge;
        if (isStockIn) {
            payBadge = `<span class="payment-badge" style="background:#6366f1;color:#fff;">📦 SUPPLY</span>`;
        } else {
            payBadge = `<span class="payment-badge">${(t.payment_method||'cash').toUpperCase()}</span>`;
        }
        if (isAngkat) {
            const sc = t.status==='active'?'#7c3aed':t.status==='completed'?'#28a745':'#dc3545';
            payBadge = `<span class="payment-badge angkat-badge" style="background:${sc}">📦 ${(t.status||'active').toUpperCase()}</span>`;
        }

        html += `<tr>
            <td>#${t.transaction_number || t.id}</td>
            <td>${date}</td>
            <td style="text-align:center;"><span class="type-badge ${typeCls}">${typeTxt}</span></td>
            <td>${details}</td>
            <td style="text-align:right;">₱${subtotal.toFixed(2)}</td>
            <td style="text-align:right;color:#94a3b8;">₱${fee.toFixed(2)}</td>
            <td style="text-align:right;color:${profitColor};font-weight:600;">₱${profit.toFixed(2)}</td>
            <td style="text-align:center;">${payBadge}</td>
            <td style="text-align:center;"><span class="status-badge ${statusCls}">${(t.status||'').toUpperCase()}</span></td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

/* ── viewGcashDetails — for GCash service transactions ──── */
function viewGcashDetails(id) {
    fetch(`transaction_history.php?action=get_gcash_details&id=${id}`)
        .then(r=>r.json()).then(data=>{
            if (!data.success || !data.transaction) { alert('GCash transaction not found'); return; }
            const t = data.transaction;
            const typeLabel = t.transaction_type === 'cash_in' ? 'Cash In' : t.transaction_type === 'cash_out' ? 'Cash Out' : t.transaction_type === 'send_gcash' ? 'Send GCash' : 'Bank Transfer';
            const amount = parseFloat(t.amount||0);
            const fee = parseFloat(t.fee||0);
            const total = parseFloat(t.total_amount||0);
            const date = new Date(t.transaction_date).toLocaleString();

            let html = `<div class="detail-section"><h3>Transaction Information</h3><table class="detail-table">
                <tr><td>ID</td><td><strong>#${escHtml(t.reference_number||t.id)}</strong></td><td>Date</td><td>${date}</td></tr>
                <tr><td>Type</td><td><strong style="color:#3b82f6;">GCash ${escHtml(typeLabel)}</strong></td><td>Status</td><td><span class="status-badge status-${t.status}">${(t.status||'').toUpperCase()}</span></td></tr>
                <tr><td>Amount</td><td>₱${amount.toFixed(2)}</td><td>Fee (Profit)</td><td style="color:#16a34a;font-weight:700;">₱${fee.toFixed(2)}</td></tr>
                <tr><td>Total</td><td><strong>₱${total.toFixed(2)}</strong></td><td>Cashier</td><td>${escHtml(t.user_name||'—')}</td></tr>`;
            if (t.user_reference) html += `<tr><td>Reference #</td><td colspan="3" style="font-family:monospace;font-weight:700;">${escHtml(t.user_reference)}</td></tr>`;
            if (t.customer_number) html += `<tr><td>Customer #</td><td colspan="3">${escHtml(t.customer_number)}</td></tr>`;
            if (t.account_name) html += `<tr><td>Account</td><td colspan="3">${escHtml(t.account_name)} (${escHtml(t.phone_number||'')})</td></tr>`;
            if (t.destination) html += `<tr><td>Destination</td><td colspan="3">${escHtml(t.destination)}</td></tr>`;
            if (t.notes) html += `<tr><td>Notes</td><td colspan="3">${escHtml(t.notes)}</td></tr>`;
            html += `</table></div>`;

            document.getElementById('details-content').innerHTML = html;
            document.getElementById('details-modal').classList.add('active');
        }).catch(e=>{ console.error(e); alert('Error loading GCash details'); });
}

/* ── viewDetails — routes to regular or angkat ────────────── */
function viewDetails(id, isAngkat) {
    const url = `transaction_history.php?action=get_details&id=${id}&is_angkat=${isAngkat?1:0}`;
    fetch(url).then(r=>r.json()).then(data=>{
        if (data.is_angkat) displayAngkatDetails(data);
        else                displayDetails(data);
        document.getElementById('details-modal').classList.add('active');
    }).catch(e=>{ console.error(e); alert('Error loading details'); });
}

/* ── Stock Receipt detail viewer ──────────────────────────── */
function viewStockReceipt(id) {
    fetch(`/oro-store/stock/add_stock.php?action=get_receipt_items&receipt_id=${id}`)
    .then(r=>r.json()).then(items => {
        let totalCost=0, totalSell=0;
        let rows = items.map(it => {
            const cost = it.quantity * it.purchase_price;
            const sell = it.quantity * it.selling_price;
            totalCost += cost; totalSell += sell;
            return `<tr>
                <td>${escHtml(it.product_name)}</td>
                <td style="text-align:center;">${it.quantity}</td>
                <td style="text-align:right;">₱${parseFloat(it.purchase_price).toFixed(2)}</td>
                <td style="text-align:right;">₱${parseFloat(it.selling_price).toFixed(2)}</td>
                <td style="text-align:right;font-weight:600;color:#dc2626;">₱${cost.toFixed(2)}</td>
                <td style="text-align:center;">${it.old_stock} → ${it.new_stock}</td>
            </tr>`;
        }).join('');
        const margin = totalSell - totalCost;
        const marginPct = totalCost > 0 ? ((margin/totalCost)*100).toFixed(1) : 0;
        const html = `
            <div style="text-align:center;margin-bottom:16px;">
                <div style="font-size:28px;">📦</div>
                <h2 style="margin:4px 0;">Stock Receipt #${id}</h2>
                <span style="background:#eef2ff;color:#4338ca;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:700;">SUPPLY IN</span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:16px;">
                <div style="background:#fee2e2;padding:10px;border-radius:8px;text-align:center;">
                    <div style="font-size:11px;color:#991b1b;">Total Cost</div>
                    <div style="font-size:18px;font-weight:800;color:#dc2626;">₱${totalCost.toFixed(2)}</div>
                </div>
                <div style="background:#dbeafe;padding:10px;border-radius:8px;text-align:center;">
                    <div style="font-size:11px;color:#1e40af;">Selling Value</div>
                    <div style="font-size:18px;font-weight:800;color:#2563eb;">₱${totalSell.toFixed(2)}</div>
                </div>
                <div style="background:#dcfce7;padding:10px;border-radius:8px;text-align:center;">
                    <div style="font-size:11px;color:#166534;">Margin</div>
                    <div style="font-size:18px;font-weight:800;color:#16a34a;">₱${margin.toFixed(2)} (${marginPct}%)</div>
                </div>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="background:#f1f5f9;">
                    <th style="padding:8px;text-align:left;">Product</th>
                    <th style="padding:8px;text-align:center;">Qty</th>
                    <th style="padding:8px;text-align:right;">Cost</th>
                    <th style="padding:8px;text-align:right;">Sell</th>
                    <th style="padding:8px;text-align:right;">Total Cost</th>
                    <th style="padding:8px;text-align:center;">Stock</th>
                </tr></thead>
                <tbody>${rows}</tbody>
            </table>`;
        document.getElementById('details-content').innerHTML = html;
        document.getElementById('details-modal').classList.add('active');
    }).catch(e=>{ console.error(e); alert('Error loading receipt'); });
}

/* ── Angkat detail renderer ───────────────────────────────── */
function displayAngkatDetails(data) {
    const t        = data.transaction;
    const items    = data.items;
    const payments = data.payments || [];

    const totalValue     = parseFloat(t.total_value || 0);
    const totalCost      = parseFloat(t.total_cost  || 0);
    const amtCollected   = parseFloat(t.amount_collected || 0);
    const grossProfit    = totalValue - totalCost;
    const balance        = totalValue - amtCollected;
    const margin         = totalValue > 0 ? (grossProfit / totalValue) * 100 : 0;

    const statusColor = t.status==='active'?'#7c3aed':t.status==='completed'?'#16a34a':'#dc2626';

    let html = `
    <div class="angkat-detail-header">
        <div style="font-size:28px;">📦</div>
        <div>
            <h3>Angkat #${escHtml(t.transaction_number)}</h3>
            <div class="retailer">👤 ${escHtml(t.retailer_name)}${t.retailer_contact?` &nbsp;📞 ${escHtml(t.retailer_contact)}`:''}</div>
        </div>
        <span style="margin-left:auto;background:${statusColor};padding:4px 12px;border-radius:12px;font-size:12px;font-weight:700;">${(t.status||'').toUpperCase()}</span>
    </div>

    <div class="angkat-stat-row">
        <div class="angkat-stat"><label>Total Value</label><span>₱${totalValue.toFixed(2)}</span></div>
        <div class="angkat-stat"><label>Total Cost</label><span class="red">₱${totalCost.toFixed(2)}</span></div>
        <div class="angkat-stat"><label>Gross Profit</label><span class="${grossProfit>=0?'green':'red'}">₱${grossProfit.toFixed(2)}</span></div>
        <div class="angkat-stat"><label>Margin</label><span class="${margin>=0?'green':'red'}">${margin.toFixed(1)}%</span></div>
        <div class="angkat-stat"><label>Collected</label><span class="green">₱${amtCollected.toFixed(2)}</span></div>
        <div class="angkat-stat"><label>Balance Due</label><span class="${balance>0?'red amber':''}">${balance>0?'₱'+balance.toFixed(2):'✓ Paid'}</span></div>
        <div class="angkat-stat"><label>Created</label><span style="font-size:12px;font-weight:500;">${new Date(t.created_at).toLocaleString()}</span></div>
        ${t.completed_at?`<div class="angkat-stat"><label>Completed</label><span style="font-size:12px;font-weight:500;">${new Date(t.completed_at).toLocaleString()}</span></div>`:''}
    </div>`;

    // items table
    html += `
    <div class="detail-section">
        <h3>Items</h3>
        <table class="angkat-items-table">
        <thead><tr>
            <th>Product</th><th>Given</th><th>Whole Sold</th><th>Whole Return</th>
            <th>Ind Sold</th><th>Ind Return</th><th>Price</th><th>Cost</th><th>Sold Value</th>
        </tr></thead>
        <tbody>`;

    let computedTotal = 0;
    items.forEach(item => {
        const price       = parseFloat(item.price || 0);
        const costPrice   = parseFloat(item.cost_price || 0);
        const ws          = parseInt(item.quantity_sold || 0);
        const wr          = parseInt(item.quantity_returned || 0);
        const is_         = parseInt(item.individual_sold || 0);
        const ir          = parseInt(item.individual_returned || 0);
        const unitsPerPack= parseInt(item.individual_pieces_per_pack || 1);
        const halfQty     = Math.floor(unitsPerPack / 2);
        const indSellPrice= parseFloat(item.individual_selling_price || 0);
        // half-pack pricing: if ind_sold === half → use individual_selling_price, else use price/units
        const indUnitPrice= (is_ === halfQty && halfQty > 0)
            ? indSellPrice
            : (parseFloat(item.individual_price || 0) || (price / unitsPerPack));
        const soldValue   = (ws * price) + (is_ * indUnitPrice);
        computedTotal    += soldValue;

        const priceLabel  = is_ > 0
            ? (is_ === halfQty && halfQty > 0
                ? `<br><span class="half-rate">½pack rate ₱${indUnitPrice.toFixed(2)}/pc</span>`
                : `<br><span class="unit-rate">unit rate ₱${indUnitPrice.toFixed(2)}/pc</span>`)
            : '';

        html += `<tr>
            <td><strong>${escHtml(item.product_name)}</strong></td>
            <td style="text-align:center;">${item.quantity_given}</td>
            <td style="text-align:center;color:#2563eb;font-weight:700;">${ws}</td>
            <td style="text-align:center;color:#d97706;">${wr}</td>
            <td style="text-align:center;color:#059669;font-weight:700;">${is_}</td>
            <td style="text-align:center;color:#d97706;">${ir}</td>
            <td>₱${price.toFixed(2)}${priceLabel}</td>
            <td style="color:#dc2626;">₱${costPrice.toFixed(2)}</td>
            <td style="font-weight:700;color:#16a34a;">₱${soldValue.toFixed(2)}</td>
        </tr>`;
    });

    html += `</tbody>
    <tfoot><tr>
        <td colspan="8" style="text-align:right;font-weight:700;padding:8px 10px;">Total Sold Value</td>
        <td style="font-weight:700;color:#16a34a;padding:8px 10px;">₱${computedTotal.toFixed(2)}</td>
    </tr></tfoot>
    </table></div>`;

    // payment history
    if (payments.length) {
        html += `<div class="detail-section">
        <h3>Payment History</h3>
        <table class="angkat-payments-table">
        <thead><tr><th>#</th><th>Date</th><th>Method</th><th>Amount</th></tr></thead>
        <tbody>`;
        payments.forEach((p, i) => {
            html += `<tr>
                <td>${i+1}</td>
                <td>${new Date(p.created_at).toLocaleString()}</td>
                <td>${(p.payment_method||'cash').toUpperCase()}</td>
                <td style="color:#16a34a;font-weight:700;">₱${parseFloat(p.amount).toFixed(2)}</td>
            </tr>`;
        });
        html += `</tbody></table></div>`;
    } else if (t.status !== 'completed') {
        html += `<div class="detail-section"><p style="color:#7c3aed;font-style:italic;">No payments recorded yet for this angkat transaction.</p></div>`;
    }

    document.getElementById('details-content').innerHTML = html;
}

/* ── Regular transaction detail renderer (original + profit) ─ */
function displayDetails(data) {
    const t=data.transaction, items=data.items, di=data.delivery_items, rel=data.related;
    const profit=parseFloat(t.total_profit||0), cogs=parseFloat(t.total_amount)-profit;
    const margin=parseFloat(t.total_amount)>0?(profit/parseFloat(t.total_amount))*100:0;
    const isCredit = t.payment_method==='credit';
    const isDelivery = t.payment_method==='delivery';

    let html=`<div class="detail-section"><h3>Transaction Information</h3><div class="detail-grid">
        <div><strong>ID:</strong> #${t.transaction_number||t.id}</div>
        <div><strong>Date:</strong> ${new Date(t.transaction_date).toLocaleString()}</div>
        <div><strong>Status:</strong> <span class="status-badge status-${t.status}">${t.status.toUpperCase()}</span></div>
        <div><strong>Payment:</strong> ${(t.payment_method||'cash').toUpperCase()}</div>
        <div><strong>Total:</strong> ₱${parseFloat(t.total_amount).toFixed(2)}</div>
        <div><strong>Profit:</strong> <span style="color:${profit<0?'#dc3545':'#28a745'};font-weight:700">₱${profit.toFixed(2)}</span></div>
    </div></div>`;

    if(isCredit&&t.customer_name)
        html+=`<div class="detail-section" style="background:#f5f3ff;border:1px solid #ddd8fe;border-radius:8px;padding:14px;margin-bottom:14px;"><h3 style="margin:0 0 8px;font-size:14px;">💳 Credit</h3><div class="detail-grid">
            <div><strong>Customer:</strong> ${escHtml(t.customer_name)}</div>
            <div><strong>Contact:</strong> ${escHtml(t.customer_contact||'—')}</div>
            <div><strong>Credit Status:</strong> <span class="status-badge status-${t.credit_status==='paid'?'completed':t.credit_status||'pending'}">${(t.credit_status||'unpaid').toUpperCase()}</span></div>
            <div><strong>Paid:</strong> <span style="color:#28a745;font-weight:600">₱${parseFloat(t.credit_paid||0).toFixed(2)}</span></div>
            <div><strong>Due:</strong> <span style="color:#dc3545;font-weight:600">₱${parseFloat(t.credit_due||0).toFixed(2)}</span></div>
        </div></div>`;

    if(isDelivery&&t.recipient_name)
        html+=`<div class="detail-section delivery-section"><h3>🚚 Delivery</h3><div class="detail-grid">
            <div><strong>Recipient:</strong> ${escHtml(t.recipient_name)}</div><div><strong>Address:</strong> ${escHtml(t.recipient_address||'—')}</div>
            <div><strong>Status:</strong> <span class="status-badge status-${t.delivery_status||'pending'}">${(t.delivery_status||'pending').toUpperCase()}</span></div></div></div>`;

    if(t.original_transaction_id)
        html+=`<div class="detail-section warning-section"><h3>⚠️ Edited Transaction</h3><p>Original: <a href="#" onclick="viewDetails(${t.original_transaction_id},false);return false;">#${t.original_transaction_id}</a></p></div>`;
    if(rel.edits&&rel.edits.length)
        html+=`<div class="detail-section warning-section"><h3>⚠️ Later Edits</h3><ul>`+rel.edits.map(e=>`<li><a href="#" onclick="viewDetails(${e.id},false);return false;">#${e.transaction_number||e.id}</a> – ${new Date(e.transaction_date).toLocaleString()} (₱${parseFloat(e.total_amount).toFixed(2)})</li>`).join('')+`</ul></div>`;

    const hasDI=di.length>0;
    html+=`<div class="detail-section"><h3>Items</h3><table class="items-table"><thead><tr>
        <th>Product</th><th>Qty</th>${hasDI?'<th>Delivered</th><th>Lacking</th>':''}<th>Price</th><th>Subtotal</th><th>Profit</th>
    </tr></thead><tbody>`
    +items.map(item=>{
        const dItem=di.find(d=>d.transaction_item_id==item.id);
        const p=parseFloat(item.profit);
        return `<tr><td>${escHtml(item.product_name)}</td><td style="text-align:center">${item.quantity}</td>
            ${dItem?`<td style="color:#28a745;font-weight:700;text-align:center">${dItem.quantity_delivered}</td><td style="color:${dItem.quantity_lacking>0?'#dc3545':'#888'};font-weight:700;text-align:center">${dItem.quantity_lacking||'—'}</td>`:''}
            <td>₱${parseFloat(item.price).toFixed(2)}</td>
            <td>₱${parseFloat(item.subtotal).toFixed(2)}</td>
            <td style="color:${p<0?'#dc3545':'#28a745'};font-weight:600">₱${p.toFixed(2)}</td>`;
    }).join('')
    +`</tbody><tfoot><tr><td colspan="${hasDI?'5':'3'}" style="text-align:right"><strong>TOTAL</strong></td>
        <td><strong>₱${parseFloat(t.total_amount).toFixed(2)}</strong></td>
        <td style="color:${profit<0?'#dc3545':'#28a745'}"><strong>₱${profit.toFixed(2)}</strong></td>
    </tr></tfoot></table></div>`;

    if(isCredit && parseFloat(t.credit_fee||0) > 0) {
        const itemsTotal = items.reduce((s,i)=>s+parseFloat(i.subtotal||0),0);
        html+=`<div style="display:flex;flex-direction:column;gap:4px;padding:0 8px;margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;font-size:13px;color:#64748b"><span>Items Subtotal</span><span style="font-weight:600;color:#1e293b">₱${itemsTotal.toFixed(2)}</span></div>
            <div style="display:flex;justify-content:space-between;font-size:13px;color:#64748b"><span>Credit Fee</span><span style="font-weight:600;color:#f59e0b">₱${parseFloat(t.credit_fee).toFixed(2)}</span></div>
            <div style="display:flex;justify-content:space-between;font-size:14px;font-weight:700;padding-top:6px;border-top:1px solid #e2e8f0"><span>Total</span><span>₱${parseFloat(t.total_amount).toFixed(2)}</span></div>
        </div>`;
    }

    document.getElementById('details-content').innerHTML = html;
}

function closeDetailsModal(){ document.getElementById('details-modal').classList.remove('active'); }

function resetFilters(){
    document.getElementById('filter-status').value   = 'completed';
    document.getElementById('filter-payment').value  = 'all';
    document.getElementById('filter-date-from').value= '';
    document.getElementById('filter-date-to').value  = '';
    document.getElementById('filter-search').value   = '';
    var devEl = document.getElementById('filter-device');
    if (devEl) devEl.value = 'all';
    applyFilters();
}

function exportToCSV(){
    if(!currentTransactions.length){ alert('Nothing to export'); return; }
    let csv = 'ID,Date,Type,Details,Items,Total,Payment,Status\n';
    currentTransactions.forEach(t=>{
        const isAngkat = t.is_angkat || t.payment_method==='angkat';
        const det = (isAngkat
            ? (t.angkat_retailer||'Angkat')
            : (t.payment_method==='delivery'&&t.recipient_name ? t.recipient_name : (t.products||'N/A'))
        ).replace(/,/g,';');
        const type = isAngkat ? 'ANGKAT' : (t.payment_method||'cash').toUpperCase();
        csv += `#${t.transaction_number||t.id},"${new Date(t.transaction_date).toLocaleString()}","${type}","${det}",${t.total_items||0},${t.total_amount},${t.payment_method||'cash'},${t.status}\n`;
    });
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));
    a.download=`transactions_${new Date().toISOString().split('T')[0]}.csv`;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

function escHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeDetailsModal(); });
</script>
</body>
</html>