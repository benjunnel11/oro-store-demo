<?php 
require_once __DIR__ . '/../sync/sync_helper.php'; 
$db = new SyncDB(); 
$product_id = $db->insert('products', [ 
    'name' => 'Test Product from Device A - ' . date('H:i:s'), 
    'price' => 99.99, 
    'description' => 'Created on Device A' 
]); 
echo "Created product ID: $product_id on Device A\n"; 
?> 
