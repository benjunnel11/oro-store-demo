<?php
// sync/migrate_to_uuid.php
// IMPORTANT: BACKUP YOUR DATABASE BEFORE RUNNING THIS
// In phpMyAdmin: Export > Quick > SQL > Go
// Access via: http://localhost/oro-store/sync/migrate_to_uuid.php

require_once 'config.php';

echo "<pre style='font-family:monospace;font-size:13px;background:#0e1219;color:#d0daf0;padding:24px;'>";
echo "=== UUID Migration Script ===\n";
echo "Device: " . LOCAL_DEVICE_ID . "\n";
echo "Time:   " . date('Y-m-d H:i:s') . "\n\n";

$conn = getLocalConnection();
$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$conn->query("SET sql_mode = ''");

$tables_fk = [
    'stores'                   => [],
    'users'                    => [],
    'employee_roles'           => [],
    'products'                 => [],
    'customers'                => [],
    'transactions'             => [],
    'angkat_transactions'      => [],
    'transaction_items'        => [],
    'deliveries'               => [
        ['transaction_id', 'transactions'],
        ['created_by',     'users'],
        ['store_id',       'stores'],
    ],
    'employees' => [
        ['role_id',  'employee_roles'],
        ['store_id', 'stores'],
    ],
    'product_history' => [
        ['product_id', 'products'],
    ],
    'product_history_backup'   => [],
    'product_categories'       => [],
    'store_prices'             => [],
    'store_products'           => [],
    'gcash_transactions' => [
        ['original_transaction_id', 'gcash_transactions'],
        ['store_id',                'stores'],
    ],
    'atm_transactions' => [
        ['user_id',  'users'],
        ['store_id', 'stores'],
    ],
    'atm_settlements'          => [],
    'cash_transactions'        => [],
    'credits' => [
        ['transaction_id', 'transactions'],
        ['customer_id',    'customers'],
    ],
    'angkat_items' => [
        ['angkat_id', 'angkat_transactions'],
    ],
    'angkat_payments' => [
        ['angkat_id', 'angkat_transactions'],
    ],
    'angkat_returns' => [
        ['angkat_id',      'angkat_transactions'],
        ['angkat_item_id', 'angkat_items'],
    ],
    'delivery_items' => [
        ['delivery_id',         'deliveries'],
        ['product_id',          'products'],
        ['transaction_item_id', 'transaction_items'],
    ],
    'employee_attendance' => [
        ['employee_id', 'employees'],
    ],
    'employee_cash_advances' => [
        ['employee_id', 'employees'],
        ['created_by',  'users'],
    ],
    'reprints' => [
        ['transaction_id', 'transactions'],
        ['reprinted_by',   'users'],
        ['store_id',       'stores'],
    ],
    'system_logs'              => [],
    'system_settings'          => [],
    'user_sessions'            => [],
    'activity_logs'            => [],
    'sync_log'                 => [],
    'sync_status'              => [],
    'credit_charge_categories' => [],
    'delivery_fee_categories'  => [],
];

// ── Step 0: Check if fully migrated ──────────────────────────────────────────
$needs_migration = false;
foreach (array_keys($tables_fk) as $st) {
    $chk = $conn->query("SHOW TABLES LIKE '$st'");
    if ($chk->num_rows === 0) continue;
    $col = $conn->query("SHOW COLUMNS FROM `$st` LIKE 'id'")->fetch_assoc();
    if ($col && strpos(strtolower($col['Type']), 'int') !== false) {
        $needs_migration = true;
        break;
    }
    $nu = $conn->query("SHOW COLUMNS FROM `$st` LIKE 'new_uuid'");
    if ($nu && $nu->num_rows > 0) {
        $needs_migration = true;
        break;
    }
}
if (!$needs_migration) {
    echo "✓ Already fully migrated to UUID. Nothing to do.\n";
    echo "</pre>";
    exit;
}
echo "⚠ Partial migration detected — resuming...\n\n";

// ── Step 1: Add uuid columns ──────────────────────────────────────────────────
echo "Step 1: Adding uuid columns...\n";
foreach ($tables_fk as $table => $fks) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check->num_rows === 0) { echo "  ⚠ Skipped (not found): $table\n"; continue; }

    $conn->query("ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `new_uuid` VARCHAR(36) NULL");
    foreach ($fks as $fk) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN IF NOT EXISTS `{$fk[0]}_uuid` VARCHAR(36) NULL");
    }
    echo "  ✓ $table\n";
}

// ── Step 2: Generate UUIDs ────────────────────────────────────────────────────
echo "\nStep 2: Generating UUIDs for existing rows...\n";
foreach (array_keys($tables_fk) as $table) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check->num_rows === 0) continue;

    // Skip if id is already varchar (already swapped)
    $col = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'id'")->fetch_assoc();
    if ($col && strpos(strtolower($col['Type']), 'varchar') !== false) {
        echo "  - $table (already UUID)\n"; continue;
    }

    $rows = $conn->query("SELECT id FROM `$table` WHERE new_uuid IS NULL");
    if (!$rows) continue;
    $count = 0;
    while ($row = $rows->fetch_assoc()) {
        $uuid = generateUUID();
        $conn->query("UPDATE `$table` SET new_uuid = '$uuid' WHERE id = {$row['id']}");
        $count++;
    }
    echo "  ✓ $table — $count rows\n";
}

// ── Step 3: Resolve FK UUIDs ──────────────────────────────────────────────────
echo "\nStep 3: Resolving foreign key UUIDs...\n";
foreach ($tables_fk as $table => $fks) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check->num_rows === 0) continue;
    foreach ($fks as $fk) {
        [$fk_col, $ref_table] = $fk;
        $ref_check = $conn->query("SHOW TABLES LIKE '$ref_table'");
        if ($ref_check->num_rows === 0) continue;

        // Check if ref table uses new_uuid or already swapped id
        $ref_col = $conn->query("SHOW COLUMNS FROM `$ref_table` LIKE 'id'")->fetch_assoc();
        $ref_is_uuid = $ref_col && strpos(strtolower($ref_col['Type']), 'varchar') !== false;

        // Check if already resolved
        $already_resolved = $conn->query("SELECT COUNT(*) as c FROM `$table` WHERE `{$fk_col}_uuid` IS NOT NULL")->fetch_assoc()['c'];
        if ($already_resolved > 0) {
            echo "  - $table.$fk_col (already resolved: $already_resolved rows)\n";
            continue;
        }

        if ($ref_is_uuid) {
            // ref table already swapped to UUID — its new_uuid column is gone
            // We can't match INT to UUID anymore — set NULL for empty tables, warn for data
            $total_with_fk = $conn->query("SELECT COUNT(*) as c FROM `$table` WHERE `$fk_col` IS NOT NULL")->fetch_assoc()['c'];
            if ($total_with_fk == 0) {
                echo "  - $table.$fk_col (no rows, skip)\n";
                continue;
            }
            echo "  ⚠ $table.$fk_col — cannot auto-resolve ($total_with_fk rows affected), will set NULL\n";
            continue;
        } else {
            $sql = "UPDATE `$table` t
                    JOIN `$ref_table` r ON t.`$fk_col` = r.id
                    SET t.`{$fk_col}_uuid` = r.new_uuid
                    WHERE t.`$fk_col` IS NOT NULL AND t.`{$fk_col}_uuid` IS NULL";
        }

        if ($conn->query($sql)) echo "  ✓ $table.$fk_col → $ref_table\n";
        else echo "  ⚠ $table.$fk_col: " . $conn->error . "\n";
    }
}

// ── Step 4: Drop FK constraints ───────────────────────────────────────────────
echo "\nStep 4: Dropping foreign key constraints...\n";
$fk_result = $conn->query("
    SELECT TABLE_NAME, CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE REFERENCED_TABLE_SCHEMA = '" . DB_NAME . "'
    AND CONSTRAINT_NAME != 'PRIMARY'
");
while ($fk = $fk_result->fetch_assoc()) {
    $conn->query("ALTER TABLE `{$fk['TABLE_NAME']}` DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
    echo "  ✓ Dropped: {$fk['TABLE_NAME']}.{$fk['CONSTRAINT_NAME']}\n";
}

// ── Step 5: Swap id columns ───────────────────────────────────────────────────
echo "\nStep 5: Swapping id columns to UUID...\n";
foreach (array_keys($tables_fk) as $table) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check->num_rows === 0) continue;

    // Skip if already varchar
    $col = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'id'")->fetch_assoc();
    if ($col && strpos(strtolower($col['Type']), 'varchar') !== false) {
        echo "  - $table (already swapped)\n"; continue;
    }

    // Skip if no new_uuid
    $nu = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'new_uuid'");
    if ($nu->num_rows === 0) { echo "  - $table (no new_uuid)\n"; continue; }

    $conn->query("ALTER TABLE `$table` MODIFY `id` INT NOT NULL");
    // Only drop PK if it exists
    $pk_check = $conn->query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
    if ($pk_check->num_rows > 0) {
        $conn->query("ALTER TABLE `$table` DROP PRIMARY KEY");
    }
    $conn->query("ALTER TABLE `$table` CHANGE `id` `old_id` INT");
    $conn->query("ALTER TABLE `$table` CHANGE `new_uuid` `id` VARCHAR(36) NOT NULL");
    $conn->query("ALTER TABLE `$table` ADD PRIMARY KEY (`id`)");

    foreach ($tables_fk[$table] as $fk) {
        $fk_col = $fk[0];
        $uuid_col = $conn->query("SHOW COLUMNS FROM `$table` LIKE '{$fk_col}_uuid'");
        if ($uuid_col->num_rows > 0) {
            $conn->query("ALTER TABLE `$table` CHANGE `$fk_col` `{$fk_col}_old` INT");
            $conn->query("ALTER TABLE `$table` CHANGE `{$fk_col}_uuid` `$fk_col` VARCHAR(36)");
        }
    }

    $conn->query("ALTER TABLE `$table` DROP COLUMN IF EXISTS `old_id`");
    foreach ($tables_fk[$table] as $fk) {
        $conn->query("ALTER TABLE `$table` DROP COLUMN IF EXISTS `{$fk[0]}_old`");
    }

    echo "  ✓ $table\n";
}

// ── Step 6: Set UUID default ──────────────────────────────────────────────────
echo "\nStep 6: Setting UUID default for new inserts...\n";
foreach (array_keys($tables_fk) as $table) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check->num_rows === 0) continue;
    $conn->query("ALTER TABLE `$table` MODIFY `id` VARCHAR(36) NOT NULL DEFAULT (UUID())");
    echo "  ✓ $table\n";
}

// ── Step 7: Re-add FK constraints ────────────────────────────────────────────
echo "\nStep 7: Re-adding foreign key constraints...\n";
mysqli_report(MYSQLI_REPORT_OFF);
foreach ($tables_fk as $table => $fks) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check->num_rows === 0) continue;
    foreach ($fks as $fk) {
        [$fk_col, $ref_table] = $fk;
        $ref_check = $conn->query("SHOW TABLES LIKE '$ref_table'");
        if ($ref_check->num_rows === 0) continue;
        $constraint = "fk_{$table}_{$fk_col}_uuid";
        // Check if column is nullable to decide ON DELETE behavior
        $col_info = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$fk_col'")->fetch_assoc();
        $is_nullable = $col_info && strtolower($col_info['Null']) === 'yes';
        $on_delete = $is_nullable ? 'ON DELETE SET NULL' : 'ON DELETE RESTRICT';
        $sql = "ALTER TABLE `$table` ADD CONSTRAINT `$constraint`
                FOREIGN KEY (`$fk_col`) REFERENCES `$ref_table`(`id`)
                $on_delete ON UPDATE CASCADE";
        try {
            if ($conn->query($sql)) echo "  ✓ $table.$fk_col → $ref_table\n";
            else echo "  ⚠ $table.$fk_col: " . $conn->error . " (skipped)\n";
        } catch (Exception $e) {
            echo "  ⚠ $table.$fk_col: " . $e->getMessage() . " (skipped)\n";
        }
    }
}

// ── Step 8: Stamp device_id ───────────────────────────────────────────────────
echo "\nStep 8: Stamping device_id on existing rows...\n";
foreach (array_keys($tables_fk) as $table) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($check->num_rows === 0) continue;
    $col_check = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'device_id'");
    if ($col_check->num_rows > 0) {
        $conn->query("UPDATE `$table` SET device_id = '" . LOCAL_DEVICE_ID . "' WHERE device_id IS NULL OR device_id = ''");
        echo "  ✓ $table\n";
    }
}

$conn->query("SET FOREIGN_KEY_CHECKS = 1");

echo "\n=== Migration Complete ===\n";
echo "Device: " . LOCAL_DEVICE_ID . "\n";
echo "Time:   " . date('Y-m-d H:i:s') . "\n\n";
echo "Next steps:\n";
echo "1. Verify data in phpMyAdmin\n";
echo "2. Export Device A database (phpMyAdmin > Export > SQL)\n";
echo "3. Import into Device B (phpMyAdmin > Import)\n";
echo "4. Run this script on Device B to stamp DEVICE_B device_id\n";
echo "5. Both devices ready to sync!\n";
echo "</pre>";

function generateUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}