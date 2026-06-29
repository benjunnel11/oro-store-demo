<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/sync_helper.php';
require_once __DIR__ . '/../core/system_logger.php';

$db = new SyncDB();
$currentUser = getCurrentUser();

$userStore = null;
if ($currentUser['store_id'] && !isAdmin()) {
    $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
    $stmt->bind_param("i", $currentUser['store_id']);
    $stmt->execute();
    $userStore = $stmt->get_result()->fetch_assoc();
}

// Ensure angkat_charge_categories table exists
$conn->query("CREATE TABLE IF NOT EXISTS angkat_charge_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(255) NOT NULL,
    charge_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// ─── AJAX: Get angkat charge categories ───────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_angkat_charges') {
    header('Content-Type: application/json');
    $sql = "SELECT pc.id, pc.category_name,
                   COALESCE(acc.charge_amount, 0.00) AS charge_amount,
                   COALESCE(acc.id, 0) AS charge_id
            FROM product_categories pc
            LEFT JOIN angkat_charge_categories acc
                ON acc.category_name = pc.category_name AND acc.is_deleted = 0
            WHERE pc.is_deleted = 0
            ORDER BY pc.category_name ASC";
    $res = $conn->query($sql);
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $indSql = "SELECT DISTINCT p.individual_sell_unit AS unit_name
               FROM products p
               WHERE p.parent_product_id IS NOT NULL AND p.is_deleted = 0
               AND p.individual_sell_unit IS NOT NULL AND p.individual_sell_unit != ''";
    $indRes = $conn->query($indSql);
    $units = $indRes ? $indRes->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($units as $u) {
        $uName = ucfirst($u['unit_name']);
        $already = false;
        foreach ($rows as $r) { if (strcasecmp($r['category_name'], $uName) === 0) { $already = true; break; } }
        if (!$already) {
            $chk = $conn->query("SELECT id, charge_amount FROM angkat_charge_categories WHERE category_name = '" . $conn->real_escape_string($uName) . "' AND is_deleted = 0");
            $ex = $chk ? $chk->fetch_assoc() : null;
            $rows[] = ['id' => 0, 'category_name' => $uName, 'charge_amount' => $ex ? (float)$ex['charge_amount'] : 0, 'charge_id' => $ex ? (int)$ex['id'] : 0];
        }
    }
    echo json_encode($rows);
    $conn->close(); exit;
}

// ─── POST: Save angkat charge ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_angkat_charge') {
    header('Content-Type: application/json');
    try {
        $charge_id = intval($_POST['charge_id'] ?? 0);
        $category_name = trim($_POST['category_name'] ?? '');
        $charge_amount = floatval($_POST['charge_amount'] ?? 0);
        if (!$category_name) throw new Exception('Category name is required');
        if ($charge_id > 0) {
            $stmt = $conn->prepare("UPDATE angkat_charge_categories SET charge_amount = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
            $stmt->bind_param("di", $charge_amount, $charge_id);
            $stmt->execute(); $stmt->close();
        } else {
            $stmt = $conn->prepare("SELECT id FROM angkat_charge_categories WHERE category_name = ? AND is_deleted = 0");
            $stmt->bind_param("s", $category_name); $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if ($existing) {
                $stmt = $conn->prepare("UPDATE angkat_charge_categories SET charge_amount = ?, updated_at = NOW() WHERE id = ? AND is_deleted = 0");
                $stmt->bind_param("di", $charge_amount, $existing['id']);
                $stmt->execute(); $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO angkat_charge_categories (category_name, charge_amount, is_deleted) VALUES (?, ?, 0)");
                $stmt->bind_param("sd", $category_name, $charge_amount);
                $stmt->execute(); $stmt->close();
            }
        }
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// POST: Delete angkat charge category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_angkat_charge') {
    header('Content-Type: application/json');
    $charge_id = intval($_POST['charge_id'] ?? 0);
    if ($charge_id > 0) $conn->query("UPDATE angkat_charge_categories SET is_deleted = 1 WHERE id = $charge_id");
    echo json_encode(['success' => true]);
    $conn->close(); exit;
}

// ── Save settlement ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'save_settlement') {
    ob_clean();
    header('Content-Type: application/json');

    $angkat_id   = intval($_POST['angkat_id']);
    $settlements = json_decode($_POST['settlements'], true);
    $store_id    = $userStore ? $userStore['id'] : null;

    $conn->begin_transaction();
    try {
        foreach ($settlements as $s) {
            $item_id        = intval($s['item_id']);
            $product_id     = intval($s['product_id']);
            $whole_sold     = intval($s['whole_sold']);
            $whole_returned = intval($s['whole_returned']);
            $ind_sold       = intval($s['ind_sold']);
            $ind_returned   = intval($s['ind_returned']);
            $units_per_pack = intval($s['units_per_pack']) ?: 1;

            $prev_stmt = $conn->prepare("SELECT quantity_returned, individual_returned FROM angkat_items WHERE id = ?");
            $prev_stmt->bind_param("i", $item_id);
            $prev_stmt->execute();
            $prev = $prev_stmt->get_result()->fetch_assoc();
            $prev_stmt->close();

            $prev_whole_returned = intval($prev['quantity_returned'] ?? 0);
            $prev_ind_returned   = intval($prev['individual_returned'] ?? 0);
            $diff_whole = $whole_returned - $prev_whole_returned;
            $diff_ind   = $ind_returned   - $prev_ind_returned;

            $db->update('angkat_items', [
                'quantity_sold'       => $whole_sold,
                'quantity_returned'   => $whole_returned,
                'individual_sold'     => $ind_sold,
                'individual_returned' => $ind_returned,
            ], "id = $item_id");

            if ($diff_whole != 0) {
                $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $stmt->bind_param("ii", $diff_whole, $product_id);
                $stmt->execute(); $stmt->close();
                if ($store_id) {
                    $stmt = $conn->prepare("UPDATE store_prices SET stock = stock + ? WHERE product_id = ? AND store_id = ?");
                    $stmt->bind_param("iii", $diff_whole, $product_id, $store_id);
                    $stmt->execute(); $stmt->close();
                }
            }

            if ($diff_ind != 0) {
                // ── Find the child product ID and parent product ID ────────────
                // The angkat item's product can be either:
                //   - A PARENT product (has child individual products)
                //   - A CHILD product  (has parent_product_id set, is individual-only)
                $prod_stmt = $conn->prepare("SELECT parent_product_id FROM products WHERE id = ?");
                $prod_stmt->bind_param("i", $product_id);
                $prod_stmt->execute();
                $prod_row = $prod_stmt->get_result()->fetch_assoc();
                $prod_stmt->close();

                if (!empty($prod_row['parent_product_id'])) {
                    // Angkat item IS the child product — units return to its own stock
                    $child_id  = $product_id;
                    $parent_id = intval($prod_row['parent_product_id']);
                } else {
                    // Angkat item is the parent — units return to its child product's stock
                    $ch_stmt = $conn->prepare(
                        "SELECT id FROM products WHERE parent_product_id = ? AND is_deleted = 0 LIMIT 1"
                    );
                    $ch_stmt->bind_param("i", $product_id);
                    $ch_stmt->execute();
                    $ch_row = $ch_stmt->get_result()->fetch_assoc();
                    $ch_stmt->close();

                    $child_id  = $ch_row ? intval($ch_row['id']) : null;
                    $parent_id = $product_id;
                }

                if ($child_id) {
                    // ── Read current child stock ───────────────────────────────
                    $stk_stmt = $conn->prepare("SELECT stock FROM products WHERE id = ?");
                    $stk_stmt->bind_param("i", $child_id);
                    $stk_stmt->execute();
                    $child_stock_before = intval($stk_stmt->get_result()->fetch_row()[0] ?? 0);
                    $stk_stmt->close();

                    // How many full packs were already in child stock before this save
                    $packs_before = intval($child_stock_before / $units_per_pack);

                    // Apply the diff — clamp result to >= 0 (prevents negative stock on corrections)
                    $child_stock_after = max(0, $child_stock_before + $diff_ind);

                    // How many full packs are in child stock after applying diff
                    $packs_after = intval($child_stock_after / $units_per_pack);

                    // Remainder stays as individual units in child stock
                    $remainder = $child_stock_after % $units_per_pack;

                    // Net new full packs to push up to parent
                    // (positive = units returned pushed enough to form new pack(s))
                    // (negative = units were un-returned, so packs come back down to child — rare correction case)
                    $packs_to_parent = $packs_after - $packs_before;

                    // Write remainder to child
                    $stmt = $conn->prepare("UPDATE products SET stock = ? WHERE id = ?");
                    $stmt->bind_param("ii", $remainder, $child_id);
                    $stmt->execute(); $stmt->close();
                    if ($store_id) {
                        $stmt = $conn->prepare(
                            "UPDATE store_prices SET stock = ? WHERE product_id = ? AND store_id = ?"
                        );
                        $stmt->bind_param("iii", $remainder, $child_id, $store_id);
                        $stmt->execute(); $stmt->close();
                    }

                    // Adjust parent by the net pack change (can be +, -, or 0)
                    if ($packs_to_parent != 0) {
                        $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                        $stmt->bind_param("ii", $packs_to_parent, $parent_id);
                        $stmt->execute(); $stmt->close();
                        if ($store_id) {
                            $stmt = $conn->prepare(
                                "UPDATE store_prices SET stock = stock + ? WHERE product_id = ? AND store_id = ?"
                            );
                            $stmt->bind_param("iii", $packs_to_parent, $parent_id, $store_id);
                            $stmt->execute(); $stmt->close();
                        }
                    }
                }
            }

            logActivity('angkat', 'Settlement saved', $currentUser['id'], $store_id, [
                'angkat_id' => $angkat_id, 'item_id' => $item_id, 'product_id' => $product_id,
                'whole_sold' => $whole_sold, 'whole_returned' => $whole_returned,
                'ind_sold' => $ind_sold, 'ind_returned' => $ind_returned,
                'diff_whole' => $diff_whole, 'diff_ind' => $diff_ind,
            ]);
        }

        $stmt = $conn->prepare("
            SELECT SUM(ai.quantity_sold * ai.price)
                 + SUM(ai.individual_sold * (ai.price / NULLIF(p.individual_pieces_per_pack, 0)))
            FROM angkat_items ai
            LEFT JOIN products p ON ai.product_id = p.id
            WHERE ai.angkat_id = ? AND ai.is_deleted = 0
        ");
        $stmt->bind_param("i", $angkat_id);
        $stmt->execute();
        $total_sold_value = floatval($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();

        $db->update('angkat_transactions', ['amount_collected' => $total_sold_value], "id = $angkat_id");
        $conn->commit();
        if (function_exists('flushStockPushes')) flushStockPushes();
        echo json_encode(['success' => true, 'total_sold_value' => $total_sold_value]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ── Get angkat items ───────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_angkat_items') {
    header('Content-Type: application/json');
    try {
        $angkat_id   = intval($_GET['angkat_id']);
        $storeClause = $userStore ? " AND a.store_id = {$userStore['id']}" : "";

        $query = "SELECT ai.*, a.retailer_name, a.retailer_contact, a.status as angkat_status,
                  a.transaction_number, a.total_value, a.total_cost, a.amount_collected,
                  p.individual_pieces_per_pack,
                  p.individual_selling_price,
                  p.individual_discounted_price,
                  p.individual_sell_unit AS unit,
                  p.can_sell_individually,
                  p.parent_product_id
                  FROM angkat_items ai
                  INNER JOIN angkat_transactions a ON ai.angkat_id = a.id
                  LEFT JOIN products p ON ai.product_id = p.id
                  WHERE ai.angkat_id = ? AND ai.is_deleted = 0 $storeClause";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $angkat_id);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($items as &$item) {
            $item['id']                          = (int)$item['id'];
            $item['angkat_id']                   = (int)$item['angkat_id'];
            $item['product_id']                  = (int)$item['product_id'];
            $item['quantity_given']              = (int)$item['quantity_given'];
            $item['quantity_sold']               = (int)$item['quantity_sold'];
            $item['quantity_returned']           = (int)$item['quantity_returned'];
            $item['price']                       = (float)$item['price'];
            $item['cost_price']                  = (float)$item['cost_price'];
            $item['total_value']                 = (float)$item['total_value'];
            $item['total_cost']                  = (float)$item['total_cost'];
            $item['amount_collected']            = (float)$item['amount_collected'];
            $item['individual_pieces_per_pack']  = (int)($item['individual_pieces_per_pack'] ?? 1);
            // individual_selling_price  = half-pack rate (when ind_sold === units_per_pack / 2)
            $item['individual_selling_price']    = (float)($item['individual_selling_price'] ?? 0);
            // individual_discounted_price = per-unit rate for any other partial qty
            $item['individual_discounted_price'] = (float)($item['individual_discounted_price'] ?? 0);
            $item['can_sell_individually']       = (int)($item['can_sell_individually'] ?? 0);
            $item['unit']                        = $item['unit'] ?? 'pack';
            $item['individual_sold']             = (int)($item['individual_sold'] ?? 0);
            $item['individual_returned']         = (int)($item['individual_returned'] ?? 0);
            $item['is_child_product']            = !empty($item['parent_product_id']) ? 1 : 0;
        }

        echo json_encode($items);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load items: ' . $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ── Record payment ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'record_payment') {
    header('Content-Type: application/json');
    $angkat_id      = intval($_POST['angkat_id']);
    $amount         = floatval($_POST['amount']);
    $payment_method = $_POST['payment_method'];
    $store_id       = $userStore ? $userStore['id'] : null;
    try {
        $db->insert('angkat_payments', [
            'angkat_id'      => $angkat_id,
            'amount'         => $amount,
            'payment_method' => $payment_method,
            'received_by'    => $currentUser['id'],
            'store_id'       => $store_id
        ]);
        $stmt = $conn->prepare("UPDATE angkat_transactions SET amount_collected = amount_collected + ? WHERE id = ?");
        $stmt->bind_param("di", $amount, $angkat_id);
        $stmt->execute(); $stmt->close();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ── Mark complete ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'mark_complete') {
    header('Content-Type: application/json');
    $angkat_id = intval($_POST['angkat_id']);
    $store_id  = $userStore ? $userStore['id'] : null;
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM angkat_transactions WHERE id = ?");
        $stmt->bind_param("i", $angkat_id);
        $stmt->execute();
        $angkat = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$angkat) throw new Exception("Angkat transaction not found");

        $stmt = $conn->prepare("
            SELECT SUM(ai.quantity_sold * ai.price)
                 + SUM(ai.individual_sold * (ai.price / NULLIF(p.individual_pieces_per_pack, 0))) AS sold_value
            FROM angkat_items ai LEFT JOIN products p ON ai.product_id = p.id
            WHERE ai.angkat_id = ? AND ai.is_deleted = 0
        ");
        $stmt->bind_param("i", $angkat_id);
        $stmt->execute();
        $sold_value = floatval($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();

        $db->update('angkat_transactions', [
            'status' => 'completed', 'completed_at' => date('Y-m-d H:i:s'), 'amount_collected' => $sold_value
        ], "id = $angkat_id");
        $db->update('angkat_items', ['status' => 'completed'], "angkat_id = $angkat_id");

        logActivity('angkat', 'Angkat marked complete: ' . $angkat['transaction_number'],
            $currentUser['id'], $store_id,
            ['angkat_id' => $angkat_id, 'sold_value' => $sold_value, 'retailer' => $angkat['retailer_name']]);

        $conn->commit();
        if (function_exists('flushStockPushes')) flushStockPushes();
        echo json_encode(['success' => true, 'sold_value' => $sold_value]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── AJAX: Get retailer angkat transactions (receipts) ───────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'get_retailer_angkats') {
    header('Content-Type: application/json');
    try {
        $retailer_name = trim($_GET['retailer_name'] ?? '');
        $storeClause = $userStore ? " AND a.store_id = {$userStore['id']}" : "";
        $query = "SELECT a.id as angkat_id, a.transaction_number, a.status, a.total_value,
                         a.amount_collected, a.created_at, a.completed_at,
                         COUNT(ai.id) as item_count,
                         GROUP_CONCAT(ai.product_name, ' x', ai.quantity_given ORDER BY ai.id SEPARATOR '||') as items_summary
                  FROM angkat_transactions a
                  LEFT JOIN angkat_items ai ON a.id = ai.angkat_id AND ai.is_deleted = 0
                  WHERE a.retailer_name = ? AND a.is_deleted = 0 $storeClause
                  GROUP BY a.id
                  ORDER BY a.created_at DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $retailer_name);
        $stmt->execute();
        $receipts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode($receipts);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    $conn->close(); exit;
}

// ─── Main query — group by retailer ─────────────────────────────────────────
$whereClause = $userStore ? " AND a.store_id = {$userStore['id']}" : "";

$query = "SELECT
    a.retailer_name,
    a.retailer_contact,
    COUNT(a.id)                                              AS total_angkats,
    SUM(CASE WHEN a.status='active'    THEN 1 ELSE 0 END)  AS active_count,
    SUM(CASE WHEN a.status='completed' THEN 1 ELSE 0 END)  AS completed_count,
    SUM(a.total_value)                                       AS total_value,
    SUM(a.amount_collected)                                  AS total_collected,
    SUM(a.total_value) - SUM(a.amount_collected)            AS total_balance,
    MAX(a.created_at)                                        AS last_angkat_date,
    (SELECT a2.status FROM angkat_transactions a2
        WHERE a2.retailer_name = a.retailer_name AND a2.is_deleted = 0
        ORDER BY a2.created_at DESC LIMIT 1)                AS last_status
FROM angkat_transactions a
WHERE a.is_deleted = 0 $whereClause
GROUP BY a.retailer_name, a.retailer_contact
ORDER BY total_balance DESC, last_angkat_date DESC";

$result    = $conn->query($query);
$retailers = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>Angkat Details<?php echo $userStore ? ' — ' . htmlspecialchars($userStore['store_name']) : ''; ?></title>
<link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
<style>
    /* ── Page Layout ── */
    .cd-container {
        max-width: 1100px;
        margin: 0 auto;
        padding: 24px 20px;
    }
    .cd-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 12px;
    }
    .cd-header h1 {
        font-size: 20px;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    .cd-header-right {
        display: flex;
        gap: 8px;
    }
    .btn {
        padding: 8px 18px;
        border: none;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: opacity .15s;
    }
    .btn:hover { opacity: .85; }
    .btn-primary   { background: #667eea; color: #fff; }
    .btn-secondary { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }
    .btn-success   { background: #22c55e; color: #fff; }
    .btn-warn      { background: #f59e0b; color: #fff; }
    .btn-danger    { background: #ef4444; color: #fff; }

    /* ── Toolbar ── */
    .cd-toolbar {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }
    .search-wrap {
        position: relative;
        flex: 1;
        max-width: 340px;
    }
    .search-wrap input {
        width: 100%;
        padding: 8px 12px 8px 34px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 13px;
        outline: none;
        background: #fff;
        color: #1e293b;
        transition: border .15s;
    }
    .search-wrap input:focus { border-color: #667eea; }
    .search-wrap::before {
        content: '\2315';
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 16px;
        pointer-events: none;
    }
    .filter-group { display: flex; gap: 4px; }
    .filter-btn {
        padding: 7px 14px;
        font-size: 12px;
        font-weight: 600;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        color: #64748b;
        border-radius: 6px;
        cursor: pointer;
        transition: all .15s;
    }
    .filter-btn.active, .filter-btn:hover { background: #667eea; color: #fff; border-color: #667eea; }
    .filter-btn[data-filter="active"].active    { background: #f59e0b; border-color: #f59e0b; }
    .filter-btn[data-filter="completed"].active { background: #22c55e; border-color: #22c55e; }
    .result-count { margin-left: auto; font-size: 12px; color: #94a3b8; }

    /* ── List ── */
    .cd-list {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
    }
    .cd-list-head {
        display: grid;
        grid-template-columns: 2fr 1.2fr 80px 1fr 1fr 1fr;
        padding: 10px 20px;
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        font-size: 11px;
        font-weight: 700;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: .6px;
    }
    .cd-row {
        display: grid;
        grid-template-columns: 2fr 1.2fr 80px 1fr 1fr 1fr;
        padding: 14px 20px;
        border-bottom: 1px solid #f1f5f9;
        align-items: center;
        cursor: pointer;
        transition: background .12s;
        position: relative;
    }
    .cd-row:last-child { border-bottom: none; }
    .cd-row:hover { background: #f8fafc; }
    .cd-row.selected { background: #eff6ff; }
    .cd-row.hidden { display: none; }
    .cd-row::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        border-radius: 3px 0 0 3px;
    }
    .cd-row.status-active::before    { background: #f59e0b; }
    .cd-row.status-completed::before { background: #22c55e; }

    .row-name { font-size: 13px; font-weight: 700; color: #1e293b; }
    .row-name small { font-size: 11px; font-weight: 400; color: #94a3b8; margin-left: 6px; }
    .row-contact { font-size: 12px; color: #64748b; }
    .row-credits { font-size: 12px; color: #94a3b8; text-align: center; }
    .row-amount  { font-size: 13px; font-weight: 700; color: #22c55e; text-align: right; }
    .row-due     { font-size: 13px; font-weight: 700; color: #ef4444; text-align: right; }
    .row-date    { font-size: 12px; color: #64748b; }

    .status-badge {
        display: inline-block;
        padding: 3px 9px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 700;
        text-align: center;
        letter-spacing: .4px;
    }
    .badge-active    { background: #fef3c7; color: #d97706; }
    .badge-completed { background: #dcfce7; color: #16a34a; }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #94a3b8;
    }
    .empty-state h3 { margin: 10px 0 6px; font-size: 16px; }

    /* ── Modal (shared) ── */
    .modal {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.45);
        backdrop-filter: blur(3px);
        z-index: 500;
        align-items: center;
        justify-content: center;
    }
    .modal.active { display: flex; }
    .modal-box {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 28px;
        width: 620px;
        max-width: 95vw;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0,0,0,.15);
    }
    .modal-title {
        font-size: 16px;
        font-weight: 700;
        color: #1e293b;
        margin-bottom: 18px;
        padding-bottom: 14px;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .modal-title .dim { font-size: 12px; color: #94a3b8; font-weight: 400; }
    .modal-info {
        display: flex;
        flex-direction: column;
        gap: 6px;
        margin-bottom: 18px;
        padding: 14px;
        background: #f8fafc;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
    }
    .modal-info-row { display: flex; gap: 10px; font-size: 13px; }
    .modal-info-label { color: #94a3b8; min-width: 80px; flex-shrink: 0; }
    .modal-info-value { color: #1e293b; font-weight: 500; }
    .modal-btns { display: flex; gap: 10px; }
    .modal-btns button {
        flex: 1;
        padding: 10px;
        border: none;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: opacity .15s;
    }
    .modal-btns button:hover { opacity: .85; }

    /* Items table inside modal */
    .items-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        margin-bottom: 16px;
    }
    .items-table thead tr {
        background: #f8fafc;
        border-bottom: 2px solid #e2e8f0;
    }
    .items-table th {
        padding: 9px 12px;
        text-align: left;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: #94a3b8;
    }
    .items-table td {
        padding: 10px 12px;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
    }

    /* Credit info grid in detail modal */
    .credit-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-bottom: 16px;
    }
    .credit-info-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 12px;
    }
    .credit-info-box .label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: #94a3b8;
        font-weight: 700;
        margin-bottom: 4px;
    }
    .credit-info-box .value {
        font-size: 15px;
        font-weight: 700;
        color: #1e293b;
    }

    .modal-total {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 16px;
        background: #fef2f2;
        border-radius: 8px;
        border: 1px solid #fecaca;
        margin-bottom: 16px;
    }
    .modal-total-label { font-size: 13px; color: #64748b; }
    .modal-total-value { font-size: 17px; font-weight: 700; color: #dc2626; }

    /* field inputs in modals */
    .field-label {
        font-size: 11px;
        color: #94a3b8;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        margin-bottom: 6px;
    }
    .field-input {
        width: 100%;
        padding: 9px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 13px;
        outline: none;
        margin-bottom: 16px;
        color: #1e293b;
        transition: border .15s;
    }
    .field-input:focus { border-color: #667eea; }
    .field-select {
        width: 100%;
        padding: 9px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 13px;
        outline: none;
        color: #1e293b;
        transition: border .15s;
        margin-bottom: 12px;
    }
    .field-select:focus { border-color: #667eea; }

    /* ── Drawer ── */
    .drawer-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.45);
        backdrop-filter: blur(3px);
        z-index: 200;
        opacity: 0;
        pointer-events: none;
        transition: opacity .25s;
    }
    .drawer-overlay.open { opacity: 1; pointer-events: all; }
    .drawer {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        width: 700px;
        max-width: 95vw;
        background: #fff;
        border-left: 1px solid #e2e8f0;
        z-index: 201;
        transform: translateX(100%);
        transition: transform .3s cubic-bezier(.4,0,.2,1);
        display: flex;
        flex-direction: column;
    }
    .drawer.open { transform: translateX(0); }
    .drawer-head {
        padding: 20px 24px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        flex-shrink: 0;
        background: #f8fafc;
    }
    .drawer-head-info h2 { font-size: 16px; font-weight: 700; color: #1e293b; margin-bottom: 3px; }
    .drawer-head-info p { font-size: 12px; color: #64748b; }
    .drawer-close {
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        color: #64748b;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all .15s;
    }
    .drawer-close:hover { background: #ef4444; color: #fff; border-color: #ef4444; }
    .drawer-stats {
        display: flex;
        gap: 1px;
        background: #e2e8f0;
        border-bottom: 1px solid #e2e8f0;
        flex-shrink: 0;
    }
    .drawer-stat {
        flex: 1;
        padding: 12px 16px;
        background: #fff;
        display: flex;
        flex-direction: column;
        gap: 3px;
    }
    .drawer-stat-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: #94a3b8;
        font-weight: 700;
    }
    .drawer-stat-value { font-size: 15px; font-weight: 700; }
    .drawer-body { flex: 1; overflow-y: auto; padding: 0; }
    .receipt-card {
        border-bottom: 1px solid #f1f5f9;
        padding: 16px 24px;
        cursor: pointer;
        transition: background .1s;
    }
    .receipt-card:hover { background: #f8fafc; }
    .receipt-card.receipt-selected { background: #eff6ff; border-left: 3px solid #667eea; }
    .receipt-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 6px;
    }
    .receipt-txn { font-size: 13px; color: #667eea; font-weight: 700; }
    .receipt-date { font-size: 12px; color: #94a3b8; }
    .receipt-amounts { display: flex; gap: 16px; margin-bottom: 8px; flex-wrap: wrap; }
    .receipt-amt-item { display: flex; flex-direction: column; gap: 2px; }
    .receipt-amt-label {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: #94a3b8;
        font-weight: 700;
    }
    .receipt-amt-value { font-size: 13px; font-weight: 700; color: #1e293b; }
    .receipt-items-wrap { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
    .item-chip {
        padding: 2px 8px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        font-size: 11px;
        color: #64748b;
    }
    .drawer-loading {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 200px;
        color: #94a3b8;
        font-size: 13px;
        gap: 10px;
    }
    .spinner {
        width: 18px;
        height: 18px;
        border: 2px solid #e2e8f0;
        border-top-color: #667eea;
        border-radius: 50%;
        animation: spin .7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    .drawer-foot {
        padding: 14px 24px;
        border-top: 1px solid #e2e8f0;
        display: flex;
        gap: 10px;
        flex-shrink: 0;
        background: #f8fafc;
    }

    /* ── Settlement table (detail modal) ── */
    .settlement-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 16px; }
    .stbl { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 650px; }
    .stbl thead tr:first-child { background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
    .stbl thead tr:last-child { background: #f8fafc; border-bottom: 2px solid #e2e8f0; }
    .stbl th { padding: 8px 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #94a3b8; white-space: nowrap; }
    .stbl th.blue-hd { background: #eff6ff; color: #2563eb; }
    .stbl th.green-hd { background: #f0fdf4; color: #16a34a; }
    .stbl tbody tr { border-bottom: 1px solid #f1f5f9; transition: background .12s, outline .12s; }
    .stbl td { padding: 9px 8px; vertical-align: middle; color: #334155; }
    .stbl tfoot td { padding: 10px 8px; font-weight: 700; border-top: 2px solid #e2e8f0; background: #f8fafc; color: #1e293b; }
    .settlement-row.row-active { background: #eff6ff !important; outline: 1px solid #bfdbfe; }
    .settlement-row.row-error { background: #fef2f2 !important; outline: 2px solid #fca5a5 !important; }
    .settlement-row.row-ok { background: #f0fdf4 !important; }
    .stbl-input { width: 60px; padding: 5px 4px; background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; color: #1e293b; font-size: 12px; font-weight: 700; text-align: center; outline: none; transition: border .15s, box-shadow .15s; }
    .stbl-input:focus { border-color: #667eea; box-shadow: 0 0 0 2px rgba(102,126,234,.2); }
    .stbl-input.blue-inp { border-color: #bfdbfe; }
    .stbl-input.blue-inp:focus { border-color: #3b82f6; }
    .stbl-input.green-inp { border-color: #bbf7d0; }
    .stbl-input.green-inp:focus { border-color: #22c55e; }
    .stbl-input:disabled { opacity: .35; cursor: not-allowed; background: #f1f5f9; }
    .prod-name { font-weight: 600; color: #1e293b; }
    .prod-cost { font-size: 10px; color: #94a3b8; margin-top: 2px; }
    .child-badge { display: inline-block; font-size: 9px; padding: 1px 5px; border-radius: 4px; background: #ede9fe; color: #7c3aed; border: 1px solid #ddd6fe; margin-left: 5px; vertical-align: middle; }
    .validation-box { margin-bottom: 14px; padding: 10px 14px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; font-size: 12px; color: #dc2626; }
    .validation-box strong { display: block; margin-bottom: 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
    .validation-box ul { list-style: none; display: flex; flex-direction: column; gap: 3px; padding-left: 4px; }
    .validation-box ul li::before { content: '! '; opacity: .7; }

    .payment-summary { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; display: flex; flex-direction: column; gap: 6px; font-size: 13px; margin-bottom: 16px; }
    .payment-summary-row { display: flex; justify-content: space-between; }
    .payment-summary-row.total { border-top: 1px solid #e2e8f0; margin-top: 4px; padding-top: 6px; font-weight: 700; }

    /* ── Fee Table ── */
    .cd-main-wrap { display: flex; gap: 20px; align-items: flex-start; }
    .cd-main-wrap .cd-list-col { flex: 1; min-width: 0; }
    .fee-card {
        width: 240px; flex-shrink: 0; background: #fff;
        border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden;
    }
    .fee-card-head {
        padding: 10px 14px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
        font-size: 13px; font-weight: 700; color: #1e293b;
    }
    .fee-list { max-height: 400px; overflow-y: auto; }
    .fee-row {
        display: flex; justify-content: space-between; align-items: center;
        padding: 8px 14px; border-bottom: 1px solid #f1f5f9;
        font-size: 12px; cursor: pointer; transition: background .1s;
    }
    .fee-row:last-child { border-bottom: none; }
    .fee-row:hover { background: #f8fafc; }
    .fee-cat { color: #334155; font-weight: 500; }
    .fee-amt { color: #667eea; font-weight: 700; }
    .fee-amt.zero { color: #94a3b8; font-weight: 400; }
    @media (max-width: 900px) {
        .cd-main-wrap { flex-direction: column; }
        .fee-card { width: 100%; }
    }
</style>
</head>
<body>
<?php
if (isAdmin()) { include_once __DIR__ . '/../admin/admin_sidebar.php'; }
elseif ($currentUser['role'] === 'manager') { include_once __DIR__ . '/../manager/manager_sidebar.php'; }
else { ?>
<style>.cashier-topnav{display:flex;align-items:center;gap:10px;padding:10px 16px;background:#1a1a2e;position:fixed;top:0;left:0;right:0;z-index:100;}
.cashier-topnav a{color:#fff;text-decoration:none;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;background:#334155;transition:background .15s;}
.cashier-topnav a:hover{background:#475569;} .cashier-topnav a.active{background:#6366f1;}
.cashier-topnav .nav-title{color:#94a3b8;font-size:12px;margin-right:auto;font-weight:600;}
.main-content{margin-left:0 !important;padding-top:56px !important;}</style>
<div class="cashier-topnav">
    <span class="nav-title"><?php echo htmlspecialchars($currentUser['full_name']); ?> &middot; Cashier</span>
    <a href="/oro-store/cashier/cashier.php">Cashier</a>
    <a href="/oro-store/credit/credit_details.php">Credit</a>
    <a href="/oro-store/delivery/delivery_details.php">Delivery</a>
    <a href="/oro-store/angkat/angkat_details.php" class="active">Angkat</a>
</div>
<?php }
?>

<main class="main-content">
    <div class="cd-container">

        <!-- Header -->
        <div class="cd-header">
            <h1>📦 Angkat Details<?php echo $userStore ? ' <span style="font-size:14px;font-weight:500;color:#64748b">— ' . htmlspecialchars($userStore['store_name']) . '</span>' : ''; ?></h1>
            <div class="cd-header-right">
                <button class="btn btn-secondary" onclick="location.href='/oro-store/angkat/angkat.php'">+ New Angkat</button>
                <button class="btn btn-primary" onclick="location.href='/oro-store/cashier/cashier.php'">← Cashier</button>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="cd-toolbar">
            <div class="search-wrap">
                <input type="text" id="searchInput" placeholder="Search retailer, contact..." autofocus>
            </div>
            <div class="filter-group">
                <button class="filter-btn active" data-filter="all"       onclick="setFilter(this,'all')">All</button>
                <button class="filter-btn"         data-filter="active"    onclick="setFilter(this,'active')">Active</button>
                <button class="filter-btn"         data-filter="completed" onclick="setFilter(this,'completed')">Completed</button>
            </div>
            <div class="result-count" id="resultCount"></div>
        </div>

        <!-- List + Fee Table -->
        <div class="cd-main-wrap">
        <div class="cd-list-col">
        <?php if (empty($retailers)): ?>
            <div class="empty-state">
                <div style="font-size:48px;">📦</div>
                <h3>No angkat records found<?php echo $userStore ? ' for ' . htmlspecialchars($userStore['store_name']) : ''; ?></h3>
                <p>Create a new angkat to get started.</p>
            </div>
        <?php else: ?>
        <div class="cd-list">
            <div class="cd-list-head">
                <div>Retailer</div>
                <div>Contact</div>
                <div style="text-align:center">Angkats</div>
                <div style="text-align:right">Collected</div>
                <div style="text-align:right">Balance</div>
                <div>Last Date</div>
            </div>
            <?php foreach ($retailers as $i => $r):
                $hasActive = $r['active_count'] > 0;
                $displayStatus = $hasActive ? 'active' : 'completed';
            ?>
            <div class="cd-row status-<?php echo $displayStatus; ?>"
                 data-index="<?php echo $i; ?>"
                 data-status="<?php echo $displayStatus; ?>"
                 data-name="<?php echo htmlspecialchars($r['retailer_name']); ?>"
                 data-contact="<?php echo htmlspecialchars($r['retailer_contact'] ?? ''); ?>"
                 data-balance="<?php echo $r['total_balance']; ?>"
                 onclick="openRetailerDrawer('<?php echo addslashes(htmlspecialchars($r['retailer_name'])); ?>', '<?php echo addslashes(htmlspecialchars($r['retailer_contact'] ?? '')); ?>')">
                <div>
                    <div class="row-name">👤 <?php echo htmlspecialchars($r['retailer_name']); ?></div>
                </div>
                <div class="row-contact">📞 <?php echo htmlspecialchars($r['retailer_contact'] ?? '—'); ?></div>
                <div class="row-credits"><?php echo (int)$r['total_angkats']; ?> angkat<?php echo $r['total_angkats'] != 1 ? 's' : ''; ?></div>
                <?php if ($displayStatus === 'completed'): ?>
                    <div class="row-amount" style="color:#16a34a">Complete</div>
                    <div class="row-due" style="color:#94a3b8">—</div>
                <?php else: ?>
                    <div class="row-amount">₱<?php echo number_format($r['total_collected'],2); ?></div>
                    <div class="row-due">₱<?php echo number_format($r['total_balance'],2); ?></div>
                <?php endif; ?>
                <div class="row-date"><?php echo $r['last_angkat_date'] ? date('M d, Y', strtotime($r['last_angkat_date'])) : '—'; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </div><!-- /.cd-list-col -->

        <!-- Fee Table -->
        <div class="fee-card">
            <div class="fee-card-head">Angkat Fees</div>
            <div class="fee-list" id="feeList">
                <div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px;">Loading...</div>
            </div>
        </div>
        </div><!-- /.cd-main-wrap -->

    </div><!-- /.cd-container -->
</main>

<!-- Retailer Drawer -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="retailerDrawer">
    <div class="drawer-head">
        <div class="drawer-head-info">
            <h2 id="drawerName">—</h2>
            <p id="drawerContact">—</p>
        </div>
        <button class="drawer-close" onclick="closeDrawer()">×</button>
    </div>
    <div class="drawer-stats">
        <div class="drawer-stat">
            <span class="drawer-stat-label">Total Value</span>
            <span class="drawer-stat-value" id="dStatTotal" style="color:#1e293b">—</span>
        </div>
        <div class="drawer-stat">
            <span class="drawer-stat-label">Collected</span>
            <span class="drawer-stat-value" id="dStatCollected" style="color:#22c55e">—</span>
        </div>
        <div class="drawer-stat">
            <span class="drawer-stat-label">Balance</span>
            <span class="drawer-stat-value" id="dStatBalance" style="color:#ef4444">—</span>
        </div>
        <div class="drawer-stat">
            <span class="drawer-stat-label">Transactions</span>
            <span class="drawer-stat-value" id="dStatCount" style="color:#667eea">—</span>
        </div>
    </div>
    <div class="drawer-body" id="drawerBody">
        <div class="drawer-loading"><div class="spinner"></div> Loading transactions...</div>
    </div>
    <div class="drawer-foot">
        <button class="btn btn-success" id="drawerMarkCompleteBtn" onclick="markAllCompleteForRetailer()" style="display:none">✓ Mark All Active as Complete</button>
    </div>
</div>

<!-- Angkat Detail Modal (Settlement) -->
<div class="modal" id="detailOverlay">
    <div class="modal-box" style="width:880px;max-width:97vw">
        <div class="modal-title" id="detailTitle">Angkat Details</div>
        <div id="detailBody">Loading...</div>
        <div class="modal-btns" id="detailBtns">
            <button id="detailSaveBtn" style="background:#667eea;color:#fff" onclick="saveSettlement()">Save Settlement</button>
            <button id="detailCompleteBtn" style="background:#22c55e;color:#fff" onclick="showCompleteConfirmation()">Mark Complete</button>
            <button style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0" onclick="closeDetailModal()">Close</button>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal" id="paymentOverlay">
    <div class="modal-box" style="max-width:460px">
        <div class="modal-title">Record Payment</div>
        <div class="payment-summary">
            <div class="payment-summary-row"><span>Total Value</span><span id="pmTotal" style="font-weight:700">—</span></div>
            <div class="payment-summary-row"><span>Collected So Far</span><span id="pmCollected" style="color:#22c55e;font-weight:700">—</span></div>
            <div class="payment-summary-row total"><span>Balance Due</span><span id="pmBalance" style="color:#ef4444">—</span></div>
        </div>
        <div class="field-label">Amount to Collect *</div>
        <input type="number" class="field-input" id="pmAmount" step="0.01" min="0" placeholder="Enter amount">
        <div class="field-label">Payment Method *</div>
        <select class="field-select" id="pmMethod">
            <option value="cash">Cash</option>
            <option value="gcash">GCash</option>
            <option value="bank_transfer">Bank Transfer</option>
        </select>
        <div class="modal-btns">
            <button style="background:#22c55e;color:#fff" onclick="confirmPayment()">Confirm Payment</button>
            <button style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0" onclick="closePaymentModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Complete Confirmation Modal -->
<div class="modal" id="completeOverlay">
    <div class="modal-box" style="max-width:400px">
        <div class="modal-title">Mark as Complete?</div>
        <p style="font-size:13px;color:#64748b;margin-bottom:24px">This will mark the angkat transaction as completed. This action cannot be undone.</p>
        <div class="modal-btns">
            <button style="background:#22c55e;color:#fff" onclick="confirmComplete()">Yes, Complete</button>
            <button style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0" onclick="closeCompleteModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
let selectedRowIndex = 0;
let selectedReceiptIdx = 0;
let currentFilter = 'all';
let currentAngkatId = null;
let currentAngkatItems = [];
let currentAngkatInfo = null;
let currentRetailerName = null;
let drawerReceipts = [];

document.addEventListener('DOMContentLoaded', () => {
    setupSearch();
    updateRowSelection();
    updateResultCount();
});

// ── Helpers ──
function fmt(n) { return parseFloat(n||0).toLocaleString('en',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(s) { if(!s) return '—'; return new Date(s).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'}); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function ucFirst(str) { return str ? str.charAt(0).toUpperCase() + str.slice(1) : ''; }

// ── Search & Filter ──
function setupSearch() {
    document.getElementById('searchInput').addEventListener('input', applyFilter);
}
function setFilter(btn, filter) {
    currentFilter = filter;
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    applyFilter();
}
function applyFilter() {
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    let visible = 0;
    document.querySelectorAll('.cd-row').forEach(row => {
        const matchStatus = currentFilter === 'all' || row.dataset.status === currentFilter;
        const matchQuery  = !q ||
            row.dataset.name.toLowerCase().includes(q) ||
            row.dataset.contact.toLowerCase().includes(q);
        const show = matchStatus && matchQuery;
        row.classList.toggle('hidden', !show);
        if (show) visible++;
    });
    selectedRowIndex = 0;
    updateRowSelection();
    updateResultCount(visible);
}
function updateResultCount(count) {
    const rows  = count !== undefined ? count : document.querySelectorAll('.cd-row:not(.hidden)').length;
    const total = document.querySelectorAll('.cd-row').length;
    document.getElementById('resultCount').textContent = rows === total ? `${total} records` : `${rows} of ${total}`;
}

// ── Row Selection ──
function visibleRows() { return Array.from(document.querySelectorAll('.cd-row:not(.hidden)')); }
function updateRowSelection() {
    const rows = visibleRows();
    rows.forEach((r, i) => r.classList.toggle('selected', i === selectedRowIndex));
    if (rows[selectedRowIndex]) rows[selectedRowIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}
function openSelectedRow() {
    const row = visibleRows()[selectedRowIndex];
    if (row) openRetailerDrawer(row.dataset.name, row.dataset.contact);
}

// ── Drawer receipt selection ──
function receiptCards() {
    return Array.from(document.querySelectorAll('#drawerBody .receipt-card'));
}
function updateReceiptSelection() {
    receiptCards().forEach((c, i) => c.classList.toggle('receipt-selected', i === selectedReceiptIdx));
    const card = receiptCards()[selectedReceiptIdx];
    if (card) card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}
function openSelectedReceipt() {
    const r = drawerReceipts[selectedReceiptIdx];
    if (r) openAngkatDetail(r.angkat_id, { stopPropagation: () => {} });
}

// ── Retailer Drawer ──
function openRetailerDrawer(name, contact) {
    currentRetailerName = name;
    selectedReceiptIdx = 0;
    document.getElementById('drawerName').textContent    = name;
    document.getElementById('drawerContact').textContent = contact ? '📞 ' + contact : '—';
    ['dStatTotal','dStatCollected','dStatBalance','dStatCount'].forEach(sid => document.getElementById(sid).textContent = '—');
    document.getElementById('drawerMarkCompleteBtn').style.display = 'none';
    document.getElementById('drawerBody').innerHTML = '<div class="drawer-loading"><div class="spinner"></div> Loading...</div>';
    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('retailerDrawer').classList.add('open');

    fetch(`?action=get_retailer_angkats&retailer_name=${encodeURIComponent(name)}`)
        .then(r => r.json())
        .then(data => { drawerReceipts = data; renderDrawer(data); setTimeout(updateReceiptSelection, 30); })
        .catch(() => { document.getElementById('drawerBody').innerHTML = '<div class="drawer-loading">Failed to load.</div>'; });
}

function renderDrawer(receipts) {
    if (!receipts.length) {
        document.getElementById('drawerBody').innerHTML = '<div class="drawer-loading">No angkat transactions found.</div>';
        return;
    }
    const totalValue     = receipts.reduce((s,r) => s + parseFloat(r.total_value||0), 0);
    const totalCollected = receipts.reduce((s,r) => s + parseFloat(r.amount_collected||0), 0);
    const totalBalance   = totalValue - totalCollected;
    document.getElementById('dStatTotal').textContent     = '₱' + fmt(totalValue);
    document.getElementById('dStatCollected').textContent = '₱' + fmt(totalCollected);
    document.getElementById('dStatBalance').textContent   = '₱' + fmt(totalBalance);
    document.getElementById('dStatCount').textContent     = receipts.length;
    const hasActive = receipts.some(r => r.status === 'active');
    document.getElementById('drawerMarkCompleteBtn').style.display = hasActive ? '' : 'none';

    document.getElementById('drawerBody').innerHTML = receipts.map(r => {
        const items  = (r.items_summary || '').split('||').filter(Boolean);
        const chips  = items.slice(0,5).map(i => `<span class="item-chip">${esc(i)}</span>`).join('');
        const more   = items.length > 5 ? `<span class="item-chip">+${items.length-5} more</span>` : '';
        const isCompleted = r.status === 'completed';
        const balance = parseFloat(r.total_value||0) - parseFloat(r.amount_collected||0);
        return `<div class="receipt-card" onclick="openAngkatDetail(${r.angkat_id}, event)">
            <div class="receipt-top">
                <span class="receipt-txn">#${esc(r.transaction_number)}</span>
                <span class="receipt-date">${fmtDate(r.created_at)}</span>
            </div>
            ${isCompleted ? `
            <div class="receipt-amounts">
                <div class="receipt-amt-item"><span class="receipt-amt-label">Status</span><span class="receipt-amt-value" style="color:#16a34a;font-weight:700">Completed</span></div>
                <div class="receipt-amt-item"><span class="receipt-amt-label">Value</span><span class="receipt-amt-value">₱${fmt(r.total_value)}</span></div>
            </div>
            ` : `
            <div class="receipt-amounts">
                <div class="receipt-amt-item"><span class="receipt-amt-label">Value</span><span class="receipt-amt-value">₱${fmt(r.total_value)}</span></div>
                <div class="receipt-amt-item"><span class="receipt-amt-label">Collected</span><span class="receipt-amt-value" style="color:#22c55e">₱${fmt(r.amount_collected)}</span></div>
                <div class="receipt-amt-item"><span class="receipt-amt-label">Balance</span><span class="receipt-amt-value" style="color:#ef4444">₱${fmt(balance)}</span></div>
            </div>
            `}
            <div class="receipt-items-wrap">${chips}${more}</div>
        </div>`;
    }).join('');
}

function closeDrawer() {
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('retailerDrawer').classList.remove('open');
    updateRowSelection();
}

// ── Mark All Complete ──
function markAllCompleteForRetailer() {
    const activeIds = drawerReceipts.filter(r => r.status === 'active').map(r => r.angkat_id);
    if (!activeIds.length || !confirm(`Mark ${activeIds.length} angkat transaction(s) as complete?`)) return;
    let completed = 0;
    const total = activeIds.length;
    activeIds.reduce((chain, id) => {
        return chain.then(() => {
            const fd = new FormData();
            fd.append('action', 'mark_complete');
            fd.append('angkat_id', id);
            return fetch('', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
                if (d.success) completed++;
            });
        });
    }, Promise.resolve()).then(() => {
        if (completed > 0) { closeDrawer(); location.reload(); }
        else alert('Error completing transactions');
    });
}

// ── Angkat Detail Modal ──
function openAngkatDetail(angkatId, e) {
    e.stopPropagation();
    currentAngkatId = angkatId;
    document.getElementById('detailOverlay').classList.add('active');
    document.getElementById('detailBody').innerHTML = '<div style="padding:30px;text-align:center;color:#94a3b8;font-size:13px">Loading...</div>';

    fetch(`?action=get_angkat_items&angkat_id=${angkatId}`)
        .then(r => r.json())
        .then(data => {
            currentAngkatItems = data;
            if (!data.length) {
                document.getElementById('detailBody').innerHTML = '<p style="color:#94a3b8;padding:20px;text-align:center">No items found.</p>';
                return;
            }
            const first = data[0];
            currentAngkatInfo = {
                retailer_name: first.retailer_name,
                retailer_contact: first.retailer_contact,
                transaction_number: first.transaction_number,
                total_value: parseFloat(first.total_value),
                total_cost: parseFloat(first.total_cost),
                amount_collected: parseFloat(first.amount_collected),
                status: first.angkat_status
            };
            const isCompleted = first.angkat_status === 'completed';
            const statusBadge = isCompleted ? 'badge-completed' : 'badge-active';
            document.getElementById('detailTitle').innerHTML =
                `#${esc(first.transaction_number)} <span class="dim">· ${esc(first.retailer_name)}</span>
                 <span class="status-badge ${statusBadge}" style="margin-left:6px">${ucFirst(first.angkat_status)}</span>`;
            document.getElementById('detailSaveBtn').style.display    = isCompleted ? 'none' : '';
            document.getElementById('detailCompleteBtn').style.display = isCompleted ? 'none' : '';

            // Build settlement table
            let html = `
                <div class="credit-info-grid">
                    <div class="credit-info-box"><div class="label">Total Value</div><div class="value">₱${fmt(currentAngkatInfo.total_value)}</div></div>
                    <div class="credit-info-box"><div class="label">Collected</div><div class="value" style="color:#22c55e">₱${fmt(currentAngkatInfo.amount_collected)}</div></div>
                    <div class="credit-info-box"><div class="label">Balance</div><div class="value" style="color:#ef4444">₱${fmt(currentAngkatInfo.total_value - currentAngkatInfo.amount_collected)}</div></div>
                </div>
                <div id="validation-container"></div>
                <div class="settlement-wrap">
                <table class="stbl">
                <thead>
                    <tr>
                        <th style="text-align:left;min-width:160px;">Product</th>
                        <th style="text-align:center;min-width:40px;">Qty</th>
                        <th style="text-align:right;min-width:80px;">Price</th>
                        <th colspan="2" class="blue-hd" style="text-align:center;min-width:120px;">Whole Pack</th>
                        <th colspan="2" class="green-hd" style="text-align:center;min-width:120px;">Individual</th>
                        <th style="text-align:right;min-width:90px;">Sold Value</th>
                    </tr>
                    <tr>
                        <th></th><th></th><th></th>
                        <th class="blue-hd" style="text-align:center">Sold</th>
                        <th class="blue-hd" style="text-align:center">Return</th>
                        <th class="green-hd" style="text-align:center">Sold</th>
                        <th class="green-hd" style="text-align:center">Return</th>
                        <th></th>
                    </tr>
                </thead><tbody>`;

            data.forEach((item, idx) => {
                const qty = item.quantity_given;
                const wholeSold = item.quantity_sold;
                const wholeReturn = item.quantity_returned;
                const wholePrice = parseFloat(item.price);
                const costPrice = parseFloat(item.cost_price);
                const unitsPerPack = parseInt(item.individual_pieces_per_pack || 1);
                const isChild = item.is_child_product == 1;
                const indSoldInit = item.individual_sold || 0;
                const indRetInit = item.individual_returned || 0;

                const wdis = isChild ? 'disabled' : '';
                const wstyle = isChild ? 'opacity:0.35;cursor:not-allowed;background:#f1f5f9;' : '';
                const disabled = isCompleted ? 'disabled' : '';
                const disStyle = isCompleted ? 'opacity:0.6;cursor:not-allowed;background:#f1f5f9;' : '';

                html += `
                <tr class="settlement-row" data-idx="${idx}"
                    data-qty="${qty}"
                    data-whole-price="${wholePrice}"
                    data-units-per-pack="${unitsPerPack}"
                    data-half-qty="${Math.floor(unitsPerPack/2)}"
                    data-ind-selling="${item.individual_selling_price||0}"
                    data-ind-discounted="${item.individual_discounted_price||0}"
                    data-is-child="${isChild?1:0}">
                    <td>
                        <div class="prod-name">${esc(item.product_name)}${isChild?'<span class="child-badge">IND ONLY</span>':''}</div>
                        <div class="prod-cost">Cost: ₱${costPrice.toFixed(2)}</div>
                    </td>
                    <td style="text-align:center;font-weight:700">${qty}</td>
                    <td style="text-align:right">₱${wholePrice.toFixed(2)}</td>
                    <td style="padding:6px 4px;">
                        <input type="number" id="ws-${idx}" class="stbl-input blue-inp"
                               min="0" max="${qty}" value="${wholeSold}"
                               ${wdis || disabled} style="${wstyle || disStyle}"
                               oninput="onWholeSoldChange(${idx})" onchange="onWholeSoldChange(${idx})">
                    </td>
                    <td style="padding:6px 4px;">
                        <input type="number" id="wr-${idx}" class="stbl-input blue-inp"
                               min="0" max="${qty}" value="${wholeReturn}"
                               ${wdis || disabled} style="${wstyle || disStyle}"
                               oninput="onWholeReturnChange(${idx})" onchange="onWholeReturnChange(${idx})">
                    </td>
                    <td style="padding:6px 4px;">
                        <input type="number" id="is-${idx}" class="stbl-input green-inp"
                               min="0" max="${unitsPerPack}" value="${indSoldInit}"
                               ${disabled} style="${disStyle}"
                               oninput="onIndSoldChange(${idx})" onchange="onIndSoldChange(${idx})">
                    </td>
                    <td style="padding:6px 4px;">
                        <input type="number" id="ir-${idx}" class="stbl-input green-inp"
                               min="0" max="${unitsPerPack}" value="${indRetInit}"
                               ${disabled} style="${disStyle}"
                               oninput="onIndReturnChange(${idx})" onchange="onIndReturnChange(${idx})">
                    </td>
                    <td style="text-align:right;font-weight:700;color:#22c55e">
                        <span id="sv-${idx}">₱0.00</span><br>
                        <span id="rate-label-${idx}" style="font-size:10px;font-weight:400;color:#94a3b8"></span>
                    </td>
                </tr>`;
            });

            html += `</tbody>
                <tfoot><tr>
                    <td colspan="7" style="text-align:right;color:#64748b">Total Sold Value</td>
                    <td style="text-align:right;color:#22c55e;" id="total-sold-value">₱0.00</td>
                </tr></tfoot>
                </table></div>`;

            document.getElementById('detailBody').innerHTML = html;
            data.forEach((_, idx) => recalcRow(idx));
            recalcTotal();
        })
        .catch(() => { document.getElementById('detailBody').innerHTML = '<p style="color:#dc2626;padding:20px">Error loading angkat details.</p>'; });
}

function closeDetailModal() {
    document.getElementById('detailOverlay').classList.remove('active');
    currentAngkatId = null;
    currentAngkatItems = [];
    currentAngkatInfo = null;
}

// ── Pricing Helper ──
function getIndUnitPrice(item, indSoldQty) {
    const halfQty = Math.floor((item.individual_pieces_per_pack || 1) / 2);
    if (halfQty > 0 && indSoldQty === halfQty) {
        return item.individual_selling_price || (item.price / (item.individual_pieces_per_pack || 1));
    }
    return item.individual_discounted_price
        || item.individual_selling_price
        || (item.price / (item.individual_pieces_per_pack || 1));
}

// ── Settlement Input Handlers ──
function getRow(idx){ return document.querySelector(`.settlement-row[data-idx="${idx}"]`); }
function getVal(id) { const el=document.getElementById(id); return el?(parseInt(el.value)||0):0; }
function setVal(id,v){ const el=document.getElementById(id); if(el) el.value=v; }
function clamp(v,mn,mx){ return Math.max(mn,Math.min(mx,v)); }
function isChildRow(idx){ const r=getRow(idx); return r&&r.dataset.isChild==='1'; }

function onWholeSoldChange(idx) {
    if (isChildRow(idx)) return;
    const row = getRow(idx), qty = parseInt(row.dataset.qty);
    const is = getVal(`is-${idx}`), ir = getVal(`ir-${idx}`);
    const indSlot = (is+ir>0) ? 1 : 0;
    const maxWhole = qty - indSlot;
    const ws = clamp(getVal(`ws-${idx}`), 0, maxWhole);
    setVal(`ws-${idx}`, ws);
    setVal(`wr-${idx}`, clamp(maxWhole-ws, 0, maxWhole));
    clearRowError(idx); recalcRow(idx); recalcTotal();
}

function onWholeReturnChange(idx) {
    if (isChildRow(idx)) return;
    const row = getRow(idx), qty = parseInt(row.dataset.qty);
    const is = getVal(`is-${idx}`), ir = getVal(`ir-${idx}`);
    const indSlot = (is+ir>0) ? 1 : 0;
    const maxWhole = qty - indSlot;
    const wr = clamp(getVal(`wr-${idx}`), 0, maxWhole);
    setVal(`wr-${idx}`, wr);
    setVal(`ws-${idx}`, clamp(maxWhole-wr, 0, maxWhole));
    clearRowError(idx); recalcRow(idx); recalcTotal();
}

function onIndSoldChange(idx) {
    const row = getRow(idx);
    const unitsPerPack = parseInt(row.dataset.unitsPerPack);
    const is = clamp(getVal(`is-${idx}`), 0, unitsPerPack);
    setVal(`is-${idx}`, is);
    setVal(`ir-${idx}`, clamp(unitsPerPack - is, 0, unitsPerPack));

    if (!isChildRow(idx)) {
        const qty = parseInt(row.dataset.qty);
        const ir = getVal(`ir-${idx}`);
        const indSlot = (is+ir>0) ? 1 : 0;
        const maxWhole = qty - indSlot;
        const ws = clamp(getVal(`ws-${idx}`), 0, maxWhole);
        setVal(`ws-${idx}`, ws);
        setVal(`wr-${idx}`, clamp(maxWhole-ws, 0, maxWhole));
    }
    clearRowError(idx); recalcRow(idx); recalcTotal();
}

function onIndReturnChange(idx) {
    const row = getRow(idx);
    const unitsPerPack = parseInt(row.dataset.unitsPerPack);
    const ir = clamp(getVal(`ir-${idx}`), 0, unitsPerPack);
    setVal(`ir-${idx}`, ir);
    setVal(`is-${idx}`, clamp(unitsPerPack - ir, 0, unitsPerPack));

    if (!isChildRow(idx)) {
        const qty = parseInt(row.dataset.qty);
        const is = getVal(`is-${idx}`);
        const indSlot = (is+ir>0) ? 1 : 0;
        const maxWhole = qty - indSlot;
        const ws = clamp(getVal(`ws-${idx}`), 0, maxWhole);
        setVal(`ws-${idx}`, ws);
        setVal(`wr-${idx}`, clamp(maxWhole-ws, 0, maxWhole));
    }
    clearRowError(idx); recalcRow(idx); recalcTotal();
}

function recalcRow(idx) {
    const row = getRow(idx); if (!row) return;
    const item = currentAngkatItems[idx];
    const wholePrice = parseFloat(row.dataset.wholePrice);
    const ws = getVal(`ws-${idx}`);
    const is = getVal(`is-${idx}`);

    const indUnitPrice = getIndUnitPrice(item, is);
    const soldValue = (ws * wholePrice) + (is * indUnitPrice);

    const svEl = document.getElementById(`sv-${idx}`);
    if (svEl) svEl.textContent = '₱' + soldValue.toFixed(2);

    const lblEl = document.getElementById(`rate-label-${idx}`);
    if (lblEl) {
        if (is > 0) {
            const halfQty = parseInt(row.dataset.halfQty);
            const isHalf = (is === halfQty && halfQty > 0);
            lblEl.textContent = isHalf ? `½pack rate ₱${indUnitPrice.toFixed(2)}/pc` : `unit rate ₱${indUnitPrice.toFixed(2)}/pc`;
            lblEl.style.color = isHalf ? '#f59e0b' : '#94a3b8';
        } else {
            lblEl.textContent = '';
        }
    }
}

function recalcTotal() {
    let totalSold = 0;
    document.querySelectorAll('.settlement-row').forEach(row => {
        const idx = parseInt(row.dataset.idx);
        const item = currentAngkatItems[idx];
        const wholePrice = parseFloat(row.dataset.wholePrice);
        const is = getVal(`is-${idx}`);
        const indUnitPrice = getIndUnitPrice(item, is);
        totalSold += (getVal(`ws-${idx}`) * wholePrice) + (is * indUnitPrice);
    });
    const el = document.getElementById('total-sold-value'); if(el) el.textContent = '₱' + totalSold.toFixed(2);
}

// ── Validation ──
function validateSettlement() {
    const badRows = [];
    document.querySelectorAll('.settlement-row').forEach(row => {
        const idx = parseInt(row.dataset.idx);
        const qty = parseInt(row.dataset.qty);
        const ws = getVal(`ws-${idx}`);
        const wr = getVal(`wr-${idx}`);
        const is = getVal(`is-${idx}`);
        const ir = getVal(`ir-${idx}`);
        const indSlot = (is+ir>0) ? 1 : 0;
        const total = ws + wr + indSlot;
        if (total !== qty) {
            badRows.push({ idx, name: currentAngkatItems[idx]?.product_name || `Item ${idx+1}`, qty, total });
        }
    });
    return badRows;
}

function showValidationErrors(badRows) {
    clearAllRowErrors();
    const container = document.getElementById('validation-container');
    if (!badRows.length) { if(container) container.innerHTML=''; return; }

    badRows.forEach(({idx}) => {
        const row = getRow(idx);
        if (row) row.classList.add('row-error');
    });
    const firstRow = getRow(badRows[0].idx);
    if (firstRow) firstRow.scrollIntoView({block:'center',behavior:'smooth'});

    if (container) {
        const listItems = badRows.map(({name,qty,total}) => {
            const diff = qty - total;
            return `<li>${esc(name)} — ${total}/${qty} packs accounted (${diff>0?'+'+diff:diff} missing)</li>`;
        }).join('');
        container.innerHTML = `<div class="validation-box">
            <strong>Cannot save — ${badRows.length} product${badRows.length>1?'s':''} not fully accounted:</strong>
            <ul>${listItems}</ul>
        </div>`;
        container.scrollIntoView({block:'start',behavior:'smooth'});
    }
}

function clearRowError(idx) {
    const row = getRow(idx); if (!row) return;
    row.classList.remove('row-error');
    const qty = parseInt(row.dataset.qty);
    const ws = getVal(`ws-${idx}`);
    const wr = getVal(`wr-${idx}`);
    const is = getVal(`is-${idx}`);
    const ir = getVal(`ir-${idx}`);
    const indSlot = (is+ir>0)?1:0;
    if (ws+wr+indSlot===qty) row.classList.add('row-ok');
    else row.classList.remove('row-ok');
}

function clearAllRowErrors() {
    document.querySelectorAll('.settlement-row').forEach(r=>r.classList.remove('row-error','row-ok'));
    const c=document.getElementById('validation-container'); if(c) c.innerHTML='';
}

// ── Save Settlement ──
function saveSettlement() {
    if (!currentAngkatId) return;

    const badRows = validateSettlement();
    if (badRows.length) { showValidationErrors(badRows); return; }
    clearAllRowErrors();

    const settlements = [];
    document.querySelectorAll('.settlement-row').forEach(row => {
        const idx = parseInt(row.dataset.idx);
        const item = currentAngkatItems[idx];
        settlements.push({
            item_id: item.id, product_id: item.product_id,
            whole_sold: getVal(`ws-${idx}`), whole_returned: getVal(`wr-${idx}`),
            ind_sold: getVal(`is-${idx}`), ind_returned: getVal(`ir-${idx}`),
            units_per_pack: parseInt(row.dataset.unitsPerPack)
        });
    });

    const fd = new FormData();
    fd.append('action','save_settlement');
    fd.append('angkat_id', currentAngkatId);
    fd.append('settlements', JSON.stringify(settlements));
    fetch('', {method:'POST', body:fd})
        .then(r=>r.text())
        .then(text=>{
            let data; try{data=JSON.parse(text);}catch(e){alert('PHP Error:\n'+text.replace(/<[^>]+>/g,'')); return;}
            if(data.success){ alert('Settlement saved successfully!'); closeDetailModal(); closeDrawer(); location.reload(); }
            else alert('Error: '+(data.error||'Unknown error'));
        })
        .catch(err=>alert('Error: '+err.message));
}

// ── Complete & Payment ──
function showCompleteConfirmation(){ document.getElementById('completeOverlay').classList.add('active'); }
function closeCompleteModal(){ document.getElementById('completeOverlay').classList.remove('active'); }
function confirmComplete() {
    const fd = new FormData();
    fd.append('action','mark_complete');
    fd.append('angkat_id', currentAngkatId);
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d=>{
        if(d.success){ alert('Angkat transaction marked as complete!'); closeCompleteModal(); closeDetailModal(); closeDrawer(); location.reload(); }
        else alert('Error: '+d.error);
    }).catch(err=>alert('Error: '+err));
}

function showPaymentModal() {
    if (!currentAngkatInfo) return;
    const balance = currentAngkatInfo.total_value - currentAngkatInfo.amount_collected;
    document.getElementById('pmTotal').textContent     = '₱'+fmt(currentAngkatInfo.total_value);
    document.getElementById('pmCollected').textContent = '₱'+fmt(currentAngkatInfo.amount_collected);
    document.getElementById('pmBalance').textContent   = '₱'+fmt(balance);
    document.getElementById('pmAmount').value = '';
    document.getElementById('pmAmount').max = balance;
    document.getElementById('paymentOverlay').classList.add('active');
    setTimeout(()=>document.getElementById('pmAmount').focus(),100);
}
function closePaymentModal(){ document.getElementById('paymentOverlay').classList.remove('active'); }
function confirmPayment() {
    const amount = parseFloat(document.getElementById('pmAmount').value);
    const method = document.getElementById('pmMethod').value;
    if (!amount||amount<=0){ alert('Please enter a valid amount'); return; }
    const balance = currentAngkatInfo.total_value - currentAngkatInfo.amount_collected;
    if (amount>balance){ alert('Amount cannot exceed balance due (₱'+balance.toFixed(2)+')'); return; }
    const fd = new FormData();
    fd.append('action','record_payment');
    fd.append('angkat_id', currentAngkatId);
    fd.append('amount', amount);
    fd.append('payment_method', method);
    fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(d=>{
        if(d.success){ alert('Payment recorded! Amount: ₱'+amount.toFixed(2)); closePaymentModal(); closeDetailModal(); closeDrawer(); location.reload(); }
        else alert('Error: '+d.error);
    }).catch(err=>alert('Error: '+err));
}

// ── Keyboard ──
document.addEventListener('keydown', e => {
    const paymentOpen  = document.getElementById('paymentOverlay').classList.contains('active');
    const completeOpen = document.getElementById('completeOverlay').classList.contains('active');
    const detailOpen   = document.getElementById('detailOverlay').classList.contains('active');
    const drawerOpen   = document.getElementById('retailerDrawer').classList.contains('open');

    if (paymentOpen) {
        if (e.key === 'Enter')  { e.preventDefault(); confirmPayment(); }
        if (e.key === 'Escape') { e.preventDefault(); closePaymentModal(); }
        return;
    }
    if (completeOpen) {
        if (e.key === 'Enter')  { e.preventDefault(); confirmComplete(); }
        if (e.key === 'Escape') { e.preventDefault(); closeCompleteModal(); }
        return;
    }
    if (detailOpen) {
        if (e.key === 'Escape') { e.preventDefault(); closeDetailModal(); }
        return;
    }
    if (drawerOpen) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            selectedReceiptIdx = Math.min(selectedReceiptIdx + 1, drawerReceipts.length - 1);
            updateReceiptSelection();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            selectedReceiptIdx = Math.max(selectedReceiptIdx - 1, 0);
            updateReceiptSelection();
        } else if (e.key === 'Enter' || e.key === 'v' || e.key === 'V') {
            e.preventDefault();
            openSelectedReceipt();
        } else if (e.key === ' ') {
            e.preventDefault();
            markAllCompleteForRetailer();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closeDrawer();
        }
        return;
    }

    // Main list
    const rows = visibleRows();
    if (e.key === 'ArrowDown')  { e.preventDefault(); selectedRowIndex = Math.min(selectedRowIndex + 1, rows.length - 1); updateRowSelection(); }
    if (e.key === 'ArrowUp')    { e.preventDefault(); selectedRowIndex = Math.max(selectedRowIndex - 1, 0); updateRowSelection(); }
    if (e.key === 'Enter')      { e.preventDefault(); openSelectedRow(); }
    if (e.key === 'Escape') {
        const s = document.getElementById('searchInput');
        if (s.value) { s.value = ''; applyFilter(); s.focus(); } else location.reload();
    }
});

// ── Fee Table ──
let feeData = [];
function loadFees() {
    fetch('?action=get_angkat_charges')
        .then(r => r.json())
        .then(data => { feeData = data; renderFees(); })
        .catch(() => { document.getElementById('feeList').innerHTML = '<div style="padding:14px;color:#dc2626;font-size:12px;">Failed to load</div>'; });
}
function renderFees() {
    const el = document.getElementById('feeList');
    if (!feeData.length) { el.innerHTML = '<div style="padding:14px;color:#94a3b8;font-size:12px;text-align:center;">No categories found</div>'; return; }
    el.innerHTML = feeData.map((f, i) => {
        const amt = parseFloat(f.charge_amount || 0);
        const cid = f.charge_id || f.id || 0;
        return `<div class="fee-row" style="display:flex;align-items:center;gap:6px;">
            <span class="fee-cat" style="flex:1;cursor:pointer;" ondblclick="editFee(${i})">${esc(f.category_name)}</span>
            <span class="fee-amt ${amt === 0 ? 'zero' : ''}" style="cursor:pointer;" ondblclick="editFee(${i})">₱${amt.toFixed(2)}</span>
            ${cid > 0 ? `<button onclick="deleteFee(${cid},'${esc(f.category_name)}')" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:14px;padding:2px 4px;" title="Delete">&times;</button>` : ''}
        </div>`;
    }).join('');
}
function editFee(idx) {
    const f = feeData[idx];
    const newAmt = prompt('Fee for "' + f.category_name + '":', parseFloat(f.charge_amount || 0).toFixed(2));
    if (newAmt === null) return;
    const amt = parseFloat(newAmt);
    if (isNaN(amt) || amt < 0) { alert('Invalid amount'); return; }
    fetch('', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=save_angkat_charge&charge_id=${f.charge_id || 0}&category_name=${encodeURIComponent(f.category_name)}&charge_amount=${amt}`
    }).then(r => r.json()).then(d => {
        if (d.success) loadFees();
        else alert('Error: ' + (d.error || 'Failed'));
    });
}
function deleteFee(id, name) {
    if (!confirm('Delete fee for "' + name + '"?')) return;
    fetch('', { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=delete_angkat_charge&charge_id=' + id
    }).then(r => r.json()).then(d => { if (d.success) loadFees(); else alert('Error'); });
}
loadFees();
</script>
</body>
</html>
