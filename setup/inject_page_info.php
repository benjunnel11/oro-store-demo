<?php
$base = __DIR__ . '/..';

$pageInfoMap = [
    'transactions/transaction_history.php' => ['Transaction History', [
        'Features' => [
            'View all sales transactions with date, type, total, and status',
            'Filter by period: Today, This Week, This Month, Custom',
            'View As: switch between devices to see other store transactions',
            'Types: Sale (cash), Delivery, Credit, GCash, ATM',
            'Statuses: Completed, Voided, Edited, Re-edited, Pending',
        ],
        'Actions' => [
            'Click a row to view full transaction details with items',
            'GCash details modal for GCash service rows',
            'Inventory stats filtered by viewed device',
            'Supply and expense totals shown per period',
        ],
    ]],
    'transactions/gcash_transaction_history.php' => ['GCash History', [
        'Features' => [
            'All GCash transactions: Cash In, Cash Out, Send, Bank Transfer',
            'Filter by date range and transaction type',
            'Shows amount, fee, total, reference number, account name',
            'Wallet balance calculation (net of all transactions)',
        ],
    ]],
    'transactions/card_transaction.php' => ['ATM / Card Transaction', [
        'How It Works' => [
            'Process ATM withdrawal or card payment transactions',
            'Enter amount and card details',
            'Fee auto-calculated based on settings',
            'Transaction saved and receipt printable',
        ],
    ]],
    'transactions/card_transaction_history.php' => ['ATM/Card History', [
        'Features' => [
            'View all ATM and card transactions',
            'Filter by date and status',
            'Shows amount, fee, and settlement status',
        ],
    ]],
    'transactions/card_transaction_history_user.php' => ['ATM History (User)', [
        'Features' => [
            'User-specific ATM transaction history',
            'Filtered by current logged-in user',
        ],
    ]],
    'transactions/other_transaction.php' => ['Other Transactions', [
        'Features' => [
            'Handles stock budget cash out and return',
            'Expense recording for operational costs',
        ],
    ]],
    'transactions/edited_transactions.php' => ['Edited Transactions', [
        'Features' => [
            'View all re-edited transactions',
            'Shows original and new transaction IDs',
            'Tracks what changed between edits',
        ],
    ]],
    'credit/credit.php' => ['Credit Transaction', [
        'How It Works' => [
            'Create a credit sale — customer pays later',
            'Select products and quantities like normal cashier',
            'Enter customer name and contact number',
            'Credit charge per category auto-applied',
            'Transaction saved as pending credit',
            'Receipt printable via RawBT thermal printer',
        ],
    ]],
    'credit/credit_management.php' => ['Credit Management', [
        'Features' => [
            'View all credit accounts with outstanding balances',
            'Filter: Unpaid, Partial, Paid, All',
            'Record payments against credit balances',
            'Track payment history per customer',
            'Total outstanding amount shown at top',
        ],
    ]],
    'credit/credit_details.php' => ['Credit Details', [
        'Features' => [
            'Detailed view of all credit orders',
            'Group by customer with item breakdown',
            'Select multiple orders for batch operations',
            'Print selected orders via thermal printer',
        ],
    ]],
    'delivery/delivery.php' => ['Delivery Transaction', [
        'How It Works' => [
            'Create a delivery sale — products delivered to customer',
            'Select products and quantities',
            'Enter delivery address and customer details',
            'Delivery fee per category auto-applied',
            'Transaction saved as pending delivery',
            'Receipt printable via RawBT',
        ],
    ]],
    'delivery/delivery_management.php' => ['Delivery Management', [
        'Features' => [
            'View all deliveries with status tracking',
            'Filter: Pending, On Delivery, Completed, Cancelled',
            'Update delivery status',
            'Track delivery amounts and payment collection',
        ],
    ]],
    'angkat/angkat.php' => ['Angkat Transaction', [
        'How It Works' => [
            'Angkat = consignment — give products to retailer on credit',
            'Select products and quantities to consign',
            'Enter retailer name and contact',
            'Angkat charge per category auto-applied',
            'Track what retailer sells and collects payment',
        ],
    ]],
    'angkat/angkat_management.php' => ['Angkat Management', [
        'Features' => [
            'View all angkat accounts with balances',
            'Filter: Active, Settled, All',
            'Record collections and returns',
            'Track total value vs amount collected',
        ],
    ]],
    'angkat/angkat_details.php' => ['Angkat Details', [
        'Features' => [
            'Detailed view of all angkat orders by retailer',
            'Select orders for batch operations',
            'Print selected orders via thermal printer',
            'Status tags: Active, Settled, Cancelled',
        ],
    ]],
    'admin/manage_users.php' => ['User Management', [
        'Features' => [
            'Create, edit, and delete user accounts',
            'Roles: Super Admin, Admin, Manager, Cashier, Kiosk',
            'Assign users to specific stores',
            'Password reset and status toggle (active/deactivated)',
            'Changes sync across devices via cloud shared data',
        ],
    ]],
    'admin/manage_stores.php' => ['Store Management', [
        'Features' => [
            'Add and edit store locations',
            'Set store name, code, address, and device ID',
            'Device ID links the store to a physical device',
            'Active/inactive status toggle',
        ],
    ]],
    'admin/activity_log.php' => ['Activity Log', [
        'Features' => [
            'All system actions logged with timestamp, user, and details',
            'Categories: Auth, Product, Transaction, System',
            'Filter by date range and category',
            'Shows IP address and user agent for security auditing',
        ],
    ]],
    'admin/daily_summary.php' => ['Daily Summary', [
        'Features' => [
            'End-of-day report with total sales, expenses, and profit',
            'Breakdown by payment method (cash, GCash, credit, delivery)',
            'Top selling products list',
            'Cash register reconciliation',
        ],
    ]],
    'admin/admin_stats.php' => ['Statistics', [
        'Features' => [
            'Sales trends and analytics charts',
            'Revenue and profit over time',
            'Product performance rankings',
            'Store comparison (multi-device)',
        ],
    ]],
    'admin/connection.php' => ['Connection Status', [
        'Features' => [
            'Device-to-device sync status via ZeroTier VPN',
            'Remote device reachability check',
            'Sync log with push/pull counts',
            'Manual sync trigger button',
        ],
    ]],
    'admin/change_db_password.php' => ['Change DB Password', [
        'Features' => [
            'Change the MySQL database password from browser',
            'Updates both MySQL user and the .local_env config file',
            'Requires current password verification',
        ],
    ]],
    'admin/change_network_password.php' => ['Change Network Password', [
        'Features' => [
            'Change the network access gate password',
            'Protects the POS from unauthorized network access (piso wifi)',
            'Stored in .local_env file, not in code',
        ],
    ]],
    'admin/reset_data.php' => ['Reset Data', [
        'Features' => [
            'Safe Wipe: clear transactions, keep products and settings',
            'Full Reset: delete everything except super admin accounts',
            'Preserves store configuration and device settings',
            'Requires confirmation before executing',
        ],
    ]],
    'admin/branch_wipe.php' => ['Branch Wipe', [
        'Features' => [
            'Remote wipe data on a branch device',
            'Super admin only — clears branch transaction data',
            'Preserves product catalog and user accounts',
        ],
    ]],
    'stock/stock_transfer.php' => ['Stock Transfer', [
        'Features' => [
            'Transfer stock between stores',
            'Select source and destination store',
            'Pick products and quantities to transfer',
            'Stock deducted from source, added to destination',
            'Transfer receipt saved for audit trail',
        ],
    ]],
    'print/inventory_sheet.php' => ['Inventory Sheet', [
        'Features' => [
            'Printable inventory list for physical stock counting',
            'Shows product name, current stock, price, and barcode',
            'Grouped by category',
            'Print-optimized layout',
        ],
    ]],
    'payroll/payroll.php' => ['Payroll', [
        'Features' => [
            'Employee attendance tracking (time in/out)',
            'Daily wage calculation based on hours worked',
            'Cash advance recording and deduction',
            'Overtime and holiday pay computation',
        ],
    ]],
    'payroll/payroll_summary.php' => ['Payroll Summary', [
        'Features' => [
            'Period summary of employee wages and deductions',
            'Filter by date range',
            'Total payroll cost breakdown',
            'Export/print-ready layout',
        ],
    ]],
    'manager/manager_panel.php' => ['Manager Dashboard', [
        'Features' => [
            'Store-specific dashboard for assigned manager',
            'Sales stats, stock alerts, pending deliveries/credits',
            'Quick access to cashier, products, and reports',
            'Limited to own store data only',
        ],
    ]],
    'manager/manager_products.php' => ['Manager Products', [
        'Features' => [
            'View and manage products for assigned store',
            'Edit store-specific prices and stock',
            'Cannot modify global product settings',
        ],
    ]],
    'manager/manager_attendance.php' => ['Attendance', [
        'Features' => [
            'Employee time-in and time-out recording',
            'Daily attendance log for the store',
            'Late/absent tracking',
        ],
    ]],
    'auth/login.php' => ['Login', [
        'Features' => [
            'Username + password authentication',
            'Rate limiting: 5 failed attempts = 15 minute lockout',
            'Role-based redirect after login (admin/manager/cashier/kiosk)',
            'Session timeout: 30 min for admin, 3 hours for cashier',
        ],
    ]],
    'admin/admin_credit_details.php' => ['Admin Credit Details', [
        'Features' => [
            'Admin view of all credit details across stores',
            'Same as Credit Details but with multi-store visibility',
        ],
    ]],
    'admin/admin_delivery_details.php' => ['Admin Delivery Details', [
        'Features' => [
            'Admin view of all delivery details across stores',
            'Same as Delivery Details but with multi-store visibility',
        ],
    ]],
    'admin/admin_view_details.php' => ['View Details', [
        'Features' => [
            'Detailed transaction view with all items',
            'Shows prices, quantities, subtotals, and profit',
        ],
    ]],
    'products/product_history.php' => ['Product History', [
        'Features' => [
            'Change log for a specific product',
            'Tracks price changes, stock adjustments, and edits',
            'Shows who made each change and when',
        ],
    ]],
    'products/products.php' => ['Products List', [
        'Features' => [
            'Simple product listing page',
            'Shows name, price, stock, category, brand',
        ],
    ]],
    'products/edit_product.php' => ['Edit Product', [
        'Features' => [
            'Update product details: name, price, cost, description',
            'Change category and brand assignments',
            'Modify barcode and individual selling settings',
        ],
    ]],
];

$count = 0;
foreach ($pageInfoMap as $file => $config) {
    $path = $base . '/' . $file;
    if (!file_exists($path)) continue;

    $content = file_get_contents($path);
    if (strpos($content, 'page_info') !== false) continue;

    $title = $config[0];
    $sections = $config[1];

    $phpCode = "<?php\ninclude_once __DIR__ . '/" . str_repeat('../', substr_count($file, '/')) . "core/page_info.php';\nrenderPageInfo(" . var_export($title, true) . ", " . var_export($sections, true) . ");\n?>";

    $content = str_replace('</body>', $phpCode . "\n</body>", $content);
    file_put_contents($path, $content);
    $count++;
    echo "Added: $file\n";
}

echo "\nDone! Added info to $count pages.\n";