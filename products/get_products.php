<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

header('Content-Type: application/json');

try {
    $currentUser = getCurrentUser();

    $userStore = null;
    if ($currentUser['store_id']) {
        $stmt = $conn->prepare("SELECT * FROM stores WHERE id = ? AND status = 'active'");
        $stmt->bind_param("i", $currentUser['store_id']);
        $stmt->execute();
        $userStore = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    // Fetch products
    if ($userStore) {
        $stmt = $conn->prepare("SELECT p.id, p.name, p.description, p.barcode,
                               p.parent_product_id,
                               sp.price as price,
                               sp.purchase_price as purchase_price,
                               sp.stock as stock,
                               COALESCE(pc.category_name, ppc.category_name) as category_name,
                               COALESCE(pb.brand_name, ppb.brand_name) as brand_name,
                               pp.individual_sell_unit
                               FROM products p
                               INNER JOIN store_prices sp ON p.id = sp.product_id AND sp.store_id = ?
                               LEFT JOIN product_categories pc ON p.category_id = pc.id AND pc.is_deleted = 0
                               LEFT JOIN product_brands pb ON p.brand_id = pb.id AND pb.is_deleted = 0
                               LEFT JOIN products pp ON p.parent_product_id = pp.id
                               LEFT JOIN product_categories ppc ON pp.category_id = ppc.id AND ppc.is_deleted = 0
                               LEFT JOIN product_brands ppb ON pp.brand_id = ppb.id AND ppb.is_deleted = 0
                               WHERE p.is_deleted = 0 AND sp.is_deleted = 0
                               ORDER BY p.name");
        $stmt->bind_param("i", $userStore['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $products = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $result = $conn->query("SELECT p.id, p.name,
                               COALESCE(sp.price, p.price) as price,
                               COALESCE(sp.purchase_price, p.purchase_price) as purchase_price,
                               COALESCE(sp.stock, p.stock) as stock,
                               p.description, p.barcode, p.parent_product_id,
                               COALESCE(pc.category_name, ppc.category_name) as category_name,
                               COALESCE(pb.brand_name, ppb.brand_name) as brand_name,
                               pp.individual_sell_unit
                               FROM products p
                               LEFT JOIN store_prices sp ON p.id = sp.product_id AND sp.is_deleted = 0
                               LEFT JOIN product_categories pc ON p.category_id = pc.id AND pc.is_deleted = 0
                               LEFT JOIN product_brands pb ON p.brand_id = pb.id AND pb.is_deleted = 0
                               LEFT JOIN products pp ON p.parent_product_id = pp.id
                               LEFT JOIN product_categories ppc ON pp.category_id = ppc.id AND ppc.is_deleted = 0
                               LEFT JOIN product_brands ppb ON pp.brand_id = ppb.id AND ppb.is_deleted = 0
                               WHERE p.is_deleted = 0
                               ORDER BY p.name");
        $products = $result->fetch_all(MYSQLI_ASSOC);
    }

    // Ensure all values are properly formatted
    foreach ($products as &$product) {
        $product['id'] = (int)$product['id'];
        $product['price'] = (float)$product['price'];
        $product['purchase_price'] = (float)$product['purchase_price'];
        $product['stock'] = (int)$product['stock'];
        $product['name'] = $product['name'] ?? '';
        $product['description'] = $product['description'] ?? '';
        $product['barcode'] = $product['barcode'] ?? '';
        $product['parent_product_id'] = $product['parent_product_id'] ? (int)$product['parent_product_id'] : null;
    }

    $json = json_encode($products);
    $etag = '"' . md5($json) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=30');
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }
    echo $json;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load products: ' . $e->getMessage()]);
}

$conn->close();
?>