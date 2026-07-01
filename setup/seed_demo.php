<?php
require_once __DIR__ . '/../core/db_connection.php';

$conn->query("INSERT IGNORE INTO stores (id, store_name, store_code, device_id, status) VALUES (1, 'Demo Store', 'DEMO', 'DEMO_DEVICE', 'active')");

$hash = password_hash('demo123', PASSWORD_DEFAULT);

$conn->query("DELETE FROM users WHERE username IN ('demo_admin','demo_manager','demo_cashier','demo_kiosk')");
$conn->query("INSERT INTO users (username, password, full_name, role, store_id, status) VALUES
    ('demo_admin', '$hash', 'Demo Admin', 'super_admin', NULL, 'active'),
    ('demo_manager', '$hash', 'Demo Manager', 'manager', 1, 'active'),
    ('demo_cashier', '$hash', 'Demo Cashier', 'cashier', 1, 'active'),
    ('demo_kiosk', '$hash', 'Demo Kiosk', 'kiosk', 1, 'active')
");

$conn->query("INSERT IGNORE INTO product_categories (id, category_name) VALUES (1, 'Feeds'), (2, 'Rice'), (3, 'Beverages')");
$conn->query("INSERT IGNORE INTO product_brands (id, brand_name) VALUES (1, 'Nutrigro'), (2, 'B-MEG'), (3, 'Coca-Cola')");

$conn->query("INSERT IGNORE INTO products (id, name, price, purchase_price, stock, category_id, brand_id, barcode, description) VALUES
    (1, 'Nutrigro Grower 50kg', 1890, 1850, 10, 1, 1, '1234567890123', 'Premium poultry feeds'),
    (2, 'B-MEG Starter 50kg', 1950, 1900, 8, 1, 2, '1234567890124', 'Starter feeds for chicks'),
    (3, 'Sinandomeng Rice 25kg', 1250, 1180, 15, 2, NULL, '1234567890125', 'Premium rice'),
    (4, 'Coca-Cola 1.5L', 85, 72, 24, 3, 3, '1234567890126', 'Softdrink'),
    (5, 'Nutrigro Layer 50kg', 1850, 1800, 6, 1, 1, '1234567890127', 'Layer feeds')");

$conn->query("INSERT IGNORE INTO store_prices (product_id, store_id, price, purchase_price, stock) VALUES
    (1, 1, 1890, 1850, 10),
    (2, 1, 1950, 1900, 8),
    (3, 1, 1250, 1180, 15),
    (4, 1, 85, 72, 24),
    (5, 1, 1850, 1800, 6)");

echo "Demo data seeded successfully!\n";
echo "Accounts created (all password: demo123):\n";
echo "  demo_admin (Super Admin)\n";
echo "  demo_manager (Manager)\n";
echo "  demo_cashier (Cashier)\n";
echo "  demo_kiosk (Kiosk)\n";
$conn->close();