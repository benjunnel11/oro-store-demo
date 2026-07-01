<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../sync/sync_helper.php';
$db = new SyncDB();
$currentUser = getCurrentUser();

if (!isAdmin()) {
    header("Location: index.php");
    exit;
}

// Ensure device_id column exists
$col_check = $conn->query("SHOW COLUMNS FROM stores LIKE 'device_id'");
if ($col_check && $col_check->num_rows === 0) {
    $conn->query("ALTER TABLE stores ADD COLUMN device_id VARCHAR(50) DEFAULT NULL");
}
$col_check2 = $conn->query("SHOW COLUMNS FROM stores LIKE 'device_ip'");
if ($col_check2 && $col_check2->num_rows === 0) {
    $conn->query("ALTER TABLE stores ADD COLUMN device_ip VARCHAR(50) DEFAULT NULL");
}

// Handle store actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_store') {
            $store_name = $_POST['store_name'];
            $store_code = strtoupper($_POST['store_code']);
            $address = $_POST['address'];
            $contact = $_POST['contact_number'];
            $device_id = $_POST['device_id'] ?? null;
            $device_ip = trim($_POST['device_ip'] ?? '');
            if ($device_id === '') $device_id = null;
            if ($device_ip === '') $device_ip = null;

            if ($device_id) {
                $conn->query("UPDATE stores SET device_id = NULL, device_ip = NULL WHERE device_id = '" . $conn->real_escape_string($device_id) . "'");
            }

            $stmt = $conn->prepare("INSERT INTO stores (store_name, store_code, address, contact_number, device_id, device_ip) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $store_name, $store_code, $address, $contact, $device_id, $device_ip);

            if ($stmt->execute()) {
                $new_id = $conn->insert_id;
                $db->logChange('stores', $new_id, 'INSERT', [
                    'store_name' => $store_name, 'store_code' => $store_code,
                    'address' => $address, 'contact_number' => $contact, 'device_id' => $device_id, 'device_ip' => $device_ip
                ]);
                $message = "Store added successfully!";
            } else {
                $error = "Error: " . $stmt->error;
            }
            $stmt->close();
        } elseif ($_POST['action'] === 'edit_store') {
            $store_id = intval($_POST['store_id']);
            $store_name = $_POST['store_name'];
            $address = $_POST['address'];
            $contact = $_POST['contact_number'];
            $status = $_POST['status'];
            $device_id = $_POST['device_id'] ?? null;
            $device_ip = trim($_POST['device_ip'] ?? '');
            if ($device_id === '') $device_id = null;
            if ($device_ip === '') $device_ip = null;

            if ($device_id) {
                $conn->query("UPDATE stores SET device_id = NULL, device_ip = NULL WHERE device_id = '" . $conn->real_escape_string($device_id) . "' AND id != $store_id");
            }

            $stmt = $conn->prepare("UPDATE stores SET store_name = ?, address = ?, contact_number = ?, status = ?, device_id = ?, device_ip = ? WHERE id = ?");
            $stmt->bind_param("ssssssi", $store_name, $address, $contact, $status, $device_id, $device_ip, $store_id);

            if ($stmt->execute()) {
                $db->logChange('stores', $store_id, 'UPDATE', [
                    'store_name' => $store_name, 'address' => $address,
                    'contact_number' => $contact, 'status' => $status, 'device_id' => $device_id, 'device_ip' => $device_ip
                ]);
                $message = "Store updated successfully!";
            } else {
                $error = "Error: " . $stmt->error;
            }
            $stmt->close();
        } elseif ($_POST['action'] === 'delete_store') {
            $store_id = intval($_POST['store_id']);
            
            // Check if store has users
            $check = $conn->query("SELECT COUNT(*) as count FROM users WHERE store_id = $store_id");
            $row = $check->fetch_assoc();
            
            if ($row['count'] > 0) {
                $error = "Cannot delete store with assigned users!";
            } else {
                $stmt = $conn->prepare("DELETE FROM stores WHERE id = ?");
                $stmt->bind_param("i", $store_id);

                if ($stmt->execute()) {
                    $db->logChange('stores', $store_id, 'DELETE', []);
                    $message = "Store deleted successfully!";
                } else {
                    $error = "Error: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// Fetch all stores
$stores = $conn->query("SELECT * FROM stores ORDER BY store_name");

// Get taken device IDs
$_taken_devs = [];
$_td_q = $conn->query("SELECT device_id FROM stores WHERE device_id IS NOT NULL AND status = 'active'");
if ($_td_q) { while ($r = $_td_q->fetch_assoc()) $_taken_devs[$r['device_id']] = true; }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manage Stores</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <style>
        .header-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: flex-end;
        }

        .btn-edit {
            background: #ffc107;
            color: #000;
        }

        .btn-edit:hover {
            background: #e0a800;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
        }

        .btn-delete:hover {
            background: #c82333;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1>Manage Stores</h1>
            <p>Add, edit, and manage store locations</p>
        </div>

        <?php if (isset($message)): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="header-actions">
            <button onclick="openAddStoreModal()" class="btn btn-primary">+ Add New Store</button>
        </div>
        
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Store Name</th>
                    <th>Store Code</th>
                    <th>Address</th>
                    <th>Contact</th>
                    <th>Device</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($store = $stores->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $store['id']; ?></td>
                    <td><?php echo htmlspecialchars($store['store_name']); ?></td>
                    <td><strong><?php echo htmlspecialchars($store['store_code']); ?></strong></td>
                    <td><?php echo htmlspecialchars($store['address']); ?></td>
                    <td><?php echo htmlspecialchars($store['contact_number']); ?></td>
                    <td>
                        <?php if (!empty($store['device_id'])):
                            $_dl = substr($store['device_id'], -1);
                            $_dc = ['A'=>'#6366f1','B'=>'#f59e0b','C'=>'#16a34a','D'=>'#dc2626','E'=>'#8b5cf6'][substr($store['device_id'],-1)] ?? '#64748b';
                        ?>
                            <div>
                                <span style="background:<?php echo $_dc; ?>15;color:<?php echo $_dc; ?>;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:700;">
                                    Store <?php echo $_dl; ?>
                                </span>
                            </div>
                            <?php if (!empty($store['device_ip'])): ?>
                            <div style="font-size:10px;color:#64748b;font-family:monospace;margin-top:2px;"><?php echo htmlspecialchars($store['device_ip']); ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#94a3b8;font-size:11px;">Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $store['status'] === 'active' ? 'success' : 'danger'; ?>">
                            <?php echo strtoupper($store['status']); ?>
                        </span>
                    </td>
                    <td>
                        <button onclick='editStore(<?php echo json_encode($store); ?>)' class="btn btn-sm btn-edit">Edit</button>
                        <button onclick="deleteStore(<?php echo $store['id']; ?>)" class="btn btn-sm btn-delete">Delete</button>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

    <!-- Add Store Modal -->
    <div class="modal" id="add-store-modal">
        <div class="modal-content">
            <h2>Add New Store</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_store">
                
                <div class="form-group">
                    <label>Store Name: *</label>
                    <input type="text" name="store_name" required placeholder="e.g., Main Branch">
                </div>
                
                <div class="form-group">
                    <label>Store Code: *</label>
                    <input type="text" name="store_code" required maxlength="20" placeholder="e.g., MAIN, BR01" style="text-transform: uppercase;">
                </div>
                
                <div class="form-group">
                    <label>Address:</label>
                    <textarea name="address" rows="3" placeholder="Enter store address"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Contact Number:</label>
                    <input type="text" name="contact_number" placeholder="e.g., +63 912 345 6789">
                </div>

                <div class="form-group">
                    <label>Assigned Device:</label>
                    <select name="device_id" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                        <option value="">-- None --</option>
                        <?php for ($di = 0; $di < 10; $di++): $dl = chr(65 + $di); $dv = "DEVICE_$dl"; $dv_taken = isset($_taken_devs[$dv]); ?>
                        <option value="<?php echo $dv; ?>" <?php echo $dv_taken ? 'disabled style="color:#94a3b8;"' : ''; ?> data-taken="<?php echo $dv_taken ? '1' : '0'; ?>"><?php echo $dv; ?> (Store <?php echo $dl; ?>)<?php echo $dv_taken ? ' — taken' : ''; ?></option>
                        <?php endfor; ?>
                    </select>
                    <small style="color:#64748b;">Links this store to a specific device for auto-selection</small>
                </div>

                <div class="form-group">
                    <label>Device ZeroTier IP:</label>
                    <input type="text" name="device_ip" placeholder="e.g., 10.219.18.250" style="font-family:monospace;">
                    <small style="color:#64748b;">ZeroTier IP of this store's device (for sync)</small>
                </div>

                <div class="modal-buttons">
                    <button type="submit" class="btn btn-primary">Add Store</button>
                    <button type="button" onclick="closeAddStoreModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Store Modal -->
    <div class="modal" id="edit-store-modal">
        <div class="modal-content">
            <h2>Edit Store</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit_store">
                <input type="hidden" name="store_id" id="edit-store-id">
                
                <div class="form-group">
                    <label>Store Name: *</label>
                    <input type="text" name="store_name" id="edit-store-name" required>
                </div>
                
                <div class="form-group">
                    <label>Store Code:</label>
                    <input type="text" id="edit-store-code" disabled style="background: #f0f2f5; text-transform: uppercase;">
                    <small style="color: #666;">Store code cannot be changed</small>
                </div>
                
                <div class="form-group">
                    <label>Address:</label>
                    <textarea name="address" id="edit-store-address" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Contact Number:</label>
                    <input type="text" name="contact_number" id="edit-store-contact">
                </div>
                
                <div class="form-group">
                    <label>Status: *</label>
                    <select name="status" id="edit-store-status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Assigned Device:</label>
                    <select name="device_id" id="edit-store-device" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                        <option value="">-- None --</option>
                        <?php for ($di = 0; $di < 10; $di++): $dl = chr(65 + $di); $dv = "DEVICE_$dl"; $dv_taken = isset($_taken_devs[$dv]); ?>
                        <option value="<?php echo $dv; ?>" <?php echo $dv_taken ? 'disabled style="color:#94a3b8;"' : ''; ?> data-taken="<?php echo $dv_taken ? '1' : '0'; ?>"><?php echo $dv; ?> (Store <?php echo $dl; ?>)<?php echo $dv_taken ? ' — taken' : ''; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Device ZeroTier IP:</label>
                    <input type="text" name="device_ip" id="edit-store-ip" placeholder="e.g., 10.219.18.250" style="font-family:monospace;">
                </div>

                <div class="modal-buttons">
                    <button type="submit" class="btn btn-primary">Update Store</button>
                    <button type="button" onclick="closeEditStoreModal()" class="btn btn-secondary">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openAddStoreModal() {
            document.getElementById('add-store-modal').classList.add('active');
        }
        
        function closeAddStoreModal() {
            document.getElementById('add-store-modal').classList.remove('active');
        }
        
        function editStore(store) {
            document.getElementById('edit-store-id').value = store.id;
            document.getElementById('edit-store-name').value = store.store_name;
            document.getElementById('edit-store-code').value = store.store_code;
            document.getElementById('edit-store-address').value = store.address;
            document.getElementById('edit-store-contact').value = store.contact_number;
            document.getElementById('edit-store-status').value = store.status;
            document.getElementById('edit-store-ip').value = store.device_ip || '';
            var sel = document.getElementById('edit-store-device');
            for (var j = 0; j < sel.options.length; j++) {
                var opt = sel.options[j];
                if (opt.dataset.taken === '1') {
                    opt.disabled = (opt.value !== (store.device_id || ''));
                }
            }
            sel.value = store.device_id || '';
            document.getElementById('edit-store-modal').classList.add('active');
        }
        
        function closeEditStoreModal() {
            document.getElementById('edit-store-modal').classList.remove('active');
        }
        
        function deleteStore(id) {
            if (confirm('Are you sure you want to delete this store? This cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_store">
                    <input type="hidden" name="store_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Close modals when clicking outside
        document.getElementById('add-store-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddStoreModal();
            }
        });

        document.getElementById('edit-store-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditStoreModal();
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddStoreModal();
                closeEditStoreModal();
            }
        });
    </script>
    </main>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('Store Management', array (
  'Features' => 
  array (
    0 => 'Add and edit store locations',
    1 => 'Set store name, code, address, and device ID',
    2 => 'Device ID links the store to a physical device',
    3 => 'Active/inactive status toggle',
  ),
));
?>
</body>
</html>