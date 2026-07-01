<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';

if (!isAdmin()) {
    header("Location: /oro-store-demo/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

require_once __DIR__ . '/../sync/sync_helper.php';
$db = new SyncDB();

// Ensure role column is VARCHAR (not ENUM) to support all roles including kiosk
$conn->query("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'cashier'");


// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_user') {
            $username = $_POST['username'];
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $full_name = $_POST['full_name'];
            $role = $_POST['role'];
            $store_id = !empty($_POST['store_id']) ? intval($_POST['store_id']) : NULL;
            
            // Check if username already exists
            $dup = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $dup->bind_param("s", $username);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $error = "Username '$username' already exists!";
                $dup->close();
            } else {
                $dup->close();
            $new_user_id = $db->insert('users', [
                'username' => $username,
                'password' => $password,
                'full_name' => $full_name,
                'role' => $role,
                'store_id' => $store_id,
                'status' => 'active'
            ]);
           if ($new_user_id) {
    logUserAction($currentUser['id'], 'add', $new_user_id, $username, [
        'full_name' => $full_name,
        'role' => $role,
        'store_id' => $store_id
    ]);
    $success = "User added successfully!";
} else {
                $error = "Error adding user.";
            }
            }
        } elseif ($_POST['action'] === 'edit_user') {
            $user_id = intval($_POST['user_id']);
            $full_name = $_POST['full_name'];
            $role = $_POST['role'];
            $store_id = !empty($_POST['store_id']) ? intval($_POST['store_id']) : NULL;
            
            $db->update('users', [
                'full_name' => $full_name,
                'role' => $role,
                'store_id' => $store_id
            ], "id = $user_id");
            logUserAction($currentUser['id'], 'edit', $user_id, $full_name, [
                'role' => $role,
                'store_id' => $store_id
            ]);
            $success = "User updated successfully!";
        } elseif ($_POST['action'] === 'toggle_status') {
            $user_id = intval($_POST['user_id']);
            $cur = $conn->query("SELECT status FROM users WHERE id = $user_id")->fetch_assoc();
            $new_status = ($cur && $cur['status'] === 'active') ? 'deactivated' : 'active';
            $db->update('users', ['status' => $new_status], "id = $user_id");
            $action_label = $new_status === 'active' ? 'activate' : 'deactivate';
            logUserAction($currentUser['id'], $action_label, $user_id, '');
            $success = "User status updated successfully!";
        } elseif ($_POST['action'] === 'delete_user') {
            $user_id = intval($_POST['user_id']);
            if ($user_id == $currentUser['id']) {
                $error = "You cannot delete your own account!";
            } else {
                $conn->query("DELETE FROM user_sessions WHERE user_id = $user_id");
                $conn->query("DELETE FROM users WHERE id = $user_id");
                $db->logChange('users', $user_id, 'DELETE', ['hard_delete' => true]);
                logUserAction($currentUser['id'], 'delete', $user_id, '');
                $success = "User deleted successfully!";
            }
        } elseif ($_POST['action'] === 'reset_password') {
            $user_id = intval($_POST['user_id']);
            $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $db->update('users', ['password' => $new_password], "id = $user_id");
            logUserAction($currentUser['id'], 'reset_password', $user_id, '');
            $success = "Password reset successfully!";
        }
    }
}

// Fetch all users with their store information
$users = $conn->query("
    SELECT u.*, s.store_name, s.store_code
    FROM users u
    LEFT JOIN stores s ON u.store_id = s.id
    WHERE u.is_deleted = 0
    ORDER BY u.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Fetch all stores for the dropdown
$stores = $conn->query("SELECT * FROM stores WHERE status = 'active' ORDER BY store_name")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Manage Users - Oro Store</title>
    <link rel="stylesheet" href="/oro-store-demo/admin/admin_layout.css">
    <style>
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            overflow-x: auto;
            display: block;
        }

        .users-table thead,
        .users-table tbody {
            display: table;
            width: 100%;
            table-layout: fixed;
        }

        .users-table th,
        .users-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e4e6eb;
        }

        .users-table th {
            background: #f0f2f5;
            font-weight: 600;
            color: #050505;
        }

        .users-table tbody tr:hover {
            background: #f0f2f5;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .role-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .role-admin {
            background: #cfe2ff;
            color: #084298;
        }

        .role-cashier {
            background: #e2e3e5;
            color: #41464b;
        }

        .role-manager {
            background: #fff3cd;
            color: #856404;
        }

        .role-kiosk {
            background: #ede9fe;
            color: #5b21b6;
        }

        .store-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            background: #e7f3ff;
            color: #004085;
        }
    </style>
</head>
<body>
    <?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>
    <main class="main-content">
        <div class="page-header">
            <h1>Manage Users</h1>
            <p>Add, edit, and manage user accounts and roles</p>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <!-- Add User Form -->
        <div class="content-section">
            <h2 class="section-title">Add New User</h2>
            <form method="POST">
                <input type="hidden" name="action" value="add_user">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" required>
                    </div>
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" required>
                    </div>
                    <div class="form-group">
                        <label>Role *</label>
                        <select name="role" required>
                            <option value="cashier">Cashier</option>
                            <option value="kiosk">Kiosk</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Assigned Store (Optional)</label>
                        <select name="store_id">
                            <option value="">-- No Store Assigned --</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>">
                                    <?php echo htmlspecialchars($store['store_name']); ?> (<?php echo htmlspecialchars($store['store_code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-success">Add User</button>
            </form>
        </div>

        <!-- Users List -->
        <div class="content-section">
            <h2 class="section-title">All Users</h2>
            <table class="users-table">
                <thead>
                    <tr>
                        <th style="width: 5%;">ID</th>
                        <th style="width: 12%;">Username</th>
                        <th style="width: 15%;">Full Name</th>
                        <th style="width: 8%;">Role</th>
                        <th style="width: 12%;">Store</th>
                        <th style="width: 8%;">Status</th>
                        <th style="width: 12%;">Last Login</th>
                        <th style="width: 10%;">Created</th>
                        <th style="width: 18%;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>#<?php echo $user['id']; ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td>
                                <span class="role-badge role-<?php echo $user['role']; ?>">
                                    <?php echo strtoupper($user['role']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($user['store_name']): ?>
                                    <span class="store-badge">
                                        <?php echo htmlspecialchars($user['store_code']); ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #999;">No Store</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td><?php echo $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never'; ?></td>
                            <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button onclick='editUser(<?php echo json_encode($user); ?>)' class="btn btn-info btn-sm">
                                        Edit
                                    </button>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="btn btn-warning btn-sm">
                                            <?php echo $user['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                        </button>
                                    </form>
                                    <button onclick="openResetModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" class="btn btn-primary btn-sm">
                                        Reset PW
                                    </button>
                                    <?php if ($user['id'] != $currentUser['id']): ?>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>

    <!-- Edit User Modal -->
    <div class="modal" id="edit-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit User</h2>
                <button class="btn-close-modal" onclick="closeEditModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit-user-id">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="edit-username" disabled style="background: #f0f2f5;">
                </div>
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" id="edit-full-name" required>
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" id="edit-role" required>
                        <option value="cashier">Cashier</option>
                        <option value="kiosk">Kiosk</option>
                        <option value="manager">Manager</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Assigned Store</label>
                    <select name="store_id" id="edit-store-id">
                        <option value="">-- No Store Assigned --</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>">
                                <?php echo htmlspecialchars($store['store_name']); ?> (<?php echo htmlspecialchars($store['store_code']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-success">Update User</button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reset Password Modal -->
    <div class="modal" id="reset-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Reset Password</h2>
                <button class="btn-close-modal" onclick="closeResetModal()">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="reset-user-id">
                <div class="form-group">
                    <label>Username: <strong id="reset-username"></strong></label>
                </div>
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" id="new-password" required>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="btn btn-success">Reset Password</button>
                    <button type="button" class="btn btn-secondary" onclick="closeResetModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function editUser(user) {
            document.getElementById('edit-user-id').value = user.id;
            document.getElementById('edit-username').value = user.username;
            document.getElementById('edit-full-name').value = user.full_name;
            document.getElementById('edit-role').value = user.role;
            document.getElementById('edit-store-id').value = user.store_id || '';
            document.getElementById('edit-modal').classList.add('active');
        }

        function closeEditModal() {
            document.getElementById('edit-modal').classList.remove('active');
        }

        function openResetModal(userId, username) {
            document.getElementById('reset-user-id').value = userId;
            document.getElementById('reset-username').textContent = username;
            document.getElementById('new-password').value = '';
            document.getElementById('reset-modal').classList.add('active');
        }

        function closeResetModal() {
            document.getElementById('reset-modal').classList.remove('active');
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeResetModal();
                closeEditModal();
            }
        });

        // Close modal when clicking outside
        document.getElementById('reset-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeResetModal();
            }
        });

        document.getElementById('edit-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
    </script>
<?php
include_once __DIR__ . '/../core/page_info.php';
renderPageInfo('User Management', array (
  'Features' => 
  array (
    0 => 'Create, edit, and delete user accounts',
    1 => 'Roles: Super Admin, Admin, Manager, Cashier, Kiosk',
    2 => 'Assign users to specific stores',
    3 => 'Password reset and status toggle (active/deactivated)',
    4 => 'Changes sync across devices via cloud shared data',
  ),
));
?>
</body>
</html>