<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

if (!isAdmin()) { header("Location: /oro-store-demo/cashier/cashier.php"); exit; }
$currentUser = getCurrentUser();

$store_id = isset($_GET['store']) ? intval($_GET['store']) : 0;
$stores = $conn->query("SELECT id, store_name, store_code FROM stores WHERE status = 'active' ORDER BY store_name")->fetch_all(MYSQLI_ASSOC);

// Auto-select first store if none selected
if (!$store_id && count($stores) > 0) $store_id = $stores[0]['id'];
$store = null;
foreach ($stores as $s) if ($s['id'] == $store_id) { $store = $s; break; }

// Get products — always use store_prices for accurate stock
$r = $conn->query("SELECT p.id, p.name, p.parent_product_id, sp.stock as system_stock,
    COALESCE(pc.category_name, '') as category,
    COALESCE(pb.brand_name, '') as brand,
    pp.individual_sell_unit
    FROM products p
    INNER JOIN store_prices sp ON p.id = sp.product_id AND sp.store_id = $store_id AND sp.is_deleted = 0
    LEFT JOIN product_categories pc ON p.category_id = pc.id AND pc.is_deleted = 0
    LEFT JOIN product_brands pb ON p.brand_id = pb.id AND pb.is_deleted = 0
    LEFT JOIN products pp ON p.parent_product_id = pp.id
    WHERE p.is_deleted = 0 ORDER BY p.name");
$all = $r->fetch_all(MYSQLI_ASSOC);

// Separate parents and children
$parents = [];
$children = [];
foreach ($all as $p) {
    if ($p['parent_product_id']) {
        $children[$p['parent_product_id']][] = $p;
    } else {
        $parents[] = $p;
    }
}

// For children, inherit parent's category/brand
foreach ($parents as &$par) {
    if (isset($children[$par['id']])) {
        foreach ($children[$par['id']] as &$ch) {
            if (!$ch['category']) $ch['category'] = $par['category'];
            if (!$ch['brand']) $ch['brand'] = $par['brand'];
        }
        unset($ch);
    }
}
unset($par);

// Build rows: parent then children
$rows = [];
foreach ($parents as $p) {
    $rows[] = ['type' => 'parent', 'name' => $p['name'], 'stock' => (int)$p['system_stock'], 'category' => $p['category'], 'brand' => $p['brand'], 'id' => $p['id']];
    if (isset($children[$p['id']])) {
        foreach ($children[$p['id']] as $c) {
            $unit = $c['individual_sell_unit'] ?? '';
            $rows[] = ['type' => 'child', 'name' => $c['name'], 'stock' => (int)$c['system_stock'], 'unit' => $unit];
        }
    }
}

$total_system = 0;
foreach ($rows as $r2) $total_system += $r2['stock'];
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Sheet - Oro Store</title>
    <style>
        @media screen {
            body { font-family:-apple-system,sans-serif; background:#f0f2f5; padding:16px; }
            .toolbar { display:flex; gap:8px; align-items:center; margin-bottom:12px; padding:10px 14px; background:#fff; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.1); flex-wrap:wrap; }
            .toolbar select,.toolbar button { padding:7px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:12px; }
            .toolbar button { background:#6366f1; color:#fff; border:none; cursor:pointer; font-weight:600; }
            .toolbar .btn-print { background:#16a34a; }
            .sheet { background:#fff; padding:16px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,.1); max-width:215mm; margin:0 auto; }
        }
        @media print {
            * { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            body { font-family:'Arial',sans-serif; margin:0; padding:0; }
            .toolbar { display:none !important; }
            .sheet { padding:6mm; }
            @page { size:letter portrait; margin:6mm; }
            .cols { height:auto; }
        }
        .hdr { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px; padding-bottom:6px; border-bottom:2px solid #000; }
        .hdr h1 { font-size:14px; margin:0; }
        .hdr .meta { font-size:8px; color:#444; text-align:right; line-height:1.5; }

        .cols { column-count:2; column-gap:10px; column-fill:auto; }

        table { width:100%; border-collapse:collapse; break-inside:auto; }
        thead { display:table-header-group; }
        tr { break-inside:avoid; }
        th { padding:3px 4px; text-align:left; font-size:7px; font-weight:700; text-transform:uppercase; color:#475569; border-bottom:1.5px solid #000; background:#f1f5f9; }
        td { padding:3px 4px; border-bottom:1px solid #e2e8f0; font-size:8px; vertical-align:middle; }
        .num { text-align:center; font-family:'Courier New',monospace; font-weight:700; width:30px; }
        .box-cell { width:38px; text-align:center; }
        .box { display:inline-block; width:32px; height:14px; border:1px solid #94a3b8; border-radius:2px; vertical-align:middle; }
        .parent td { font-weight:600; }
        .child td { padding-left:14px; color:#475569; font-size:7.5px; }
        .child .pname::before { content:'↳ '; color:#94a3b8; font-size:8px; }
        .tag { display:inline-block; font-size:6px; padding:0px 3px; border-radius:2px; font-weight:700; margin-left:3px; vertical-align:middle; }
        .tag-cat { background:#ede9fe; color:#7c3aed; }
        .tag-brand { background:#dbeafe; color:#1d4ed8; }
        .tag-unit { background:#fef3c7; color:#92400e; }

        .foot { text-align:right; font-size:9px; font-weight:700; margin-top:6px; padding-top:4px; border-top:2px solid #000; }
        .sign { display:flex; justify-content:space-between; margin-top:16px; padding-top:8px; border-top:1px solid #ccc; font-size:8px; }
        .sign-box { width:38%; text-align:center; }
        .sign-line { border-top:1px solid #000; margin-top:24px; padding-top:3px; }
    </style>
</head>
<body>

<div class="toolbar">
    <strong style="font-size:13px;">Inventory Check Sheet</strong>
    <form method="GET" style="display:flex;gap:6px;align-items:center;">
        <select name="store">
            <?php foreach ($stores as $s): ?>
            <option value="<?php echo $s['id']; ?>" <?php echo $store_id == $s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['store_name']); ?> (<?php echo $s['store_code']; ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Load</button>
    </form>
    <button class="btn-print" onclick="window.print()">Print</button>
    <a href="/oro-store-demo/admin/admin_products.php" style="font-size:11px;color:#6366f1;text-decoration:none;">← Products</a>
</div>

<div class="sheet">
    <div class="hdr">
        <div>
            <h1>INVENTORY CHECK SHEET</h1>
            <div style="font-size:9px;color:#64748b;">Oro Store<?php echo $store ? ' — ' . htmlspecialchars($store['store_name']) . ' (' . $store['store_code'] . ')' : ''; ?></div>
        </div>
        <div class="meta">
            Date: <?php echo date('M j, Y'); ?><br>
            Checked by: _______________<br>
            <?php echo count($parents); ?> products | System: <?php echo number_format($total_system); ?> units
        </div>
    </div>

    <div class="cols">
        <table>
            <thead><tr><th>Product</th><th class="num">Sys</th><th class="box-cell">Actual</th><th class="box-cell">Diff</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r2): ?>
                <?php if ($r2['type'] === 'parent'): ?>
                <tr class="parent">
                    <td>
                        <?php echo htmlspecialchars($r2['name']); ?>
                        <?php if ($r2['category']): ?><span class="tag tag-cat"><?php echo htmlspecialchars($r2['category']); ?></span><?php endif; ?>
                        <?php if ($r2['brand']): ?><span class="tag tag-brand"><?php echo htmlspecialchars($r2['brand']); ?></span><?php endif; ?>
                    </td>
                    <td class="num"><?php echo $r2['stock']; ?></td>
                    <td class="box-cell"><span class="box"></span></td>
                    <td class="box-cell"><span class="box"></span></td>
                </tr>
                <?php else: ?>
                <tr class="child">
                    <td>
                        <span class="pname"><?php echo htmlspecialchars($r2['name']); ?></span>
                        <?php if (!empty($r2['unit'])): ?><span class="tag tag-unit"><?php echo htmlspecialchars(ucfirst($r2['unit'])); ?></span><?php endif; ?>
                    </td>
                    <td class="num"><?php echo $r2['stock']; ?></td>
                    <td class="box-cell"><span class="box"></span></td>
                    <td class="box-cell"><span class="box"></span></td>
                </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="foot">
        System Total: <?php echo number_format($total_system); ?> units &nbsp;|&nbsp; Actual Total: _________ &nbsp;|&nbsp; Difference: _________
    </div>

    <div class="sign">
        <div class="sign-box"><div class="sign-line">Checked By</div></div>
        <div class="sign-box"><div class="sign-line">Verified By</div></div>
    </div>
</div>

<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Inventory Sheet', array (
  'Features' => 
  array (
    0 => 'Printable inventory list for physical stock counting',
    1 => 'Shows product name, current stock, price, and barcode',
    2 => 'Grouped by category',
    3 => 'Print-optimized layout',
  ),
));
?>
</body>
</html>
