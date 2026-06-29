<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';

// Only admins can access
if (!isAdmin()) {
    header("Location: /oro-store/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    // Update bonus deduction
    if ($_POST['action'] === 'update_bonus_deduction') {
        $deduction = floatval($_POST['deduction']);
        
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) 
                               VALUES ('bonus_deduction_per_absence', ?, NOW()) 
                               ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
        $stmt->bind_param("dd", $deduction, $deduction);
        
        if ($stmt->execute()) {
            logActivity('payroll', "Updated bonus deduction per absence: ₱" . number_format($deduction, 2), $currentUser['id'], null);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update deduction']);
        }
        exit;
    }
    
    // Add new role
    if ($_POST['action'] === 'add_role') {
        $role_name = trim($_POST['role_name']);
        
        if (empty($role_name)) {
            echo json_encode(['success' => false, 'error' => 'Role name is required']);
            exit;
        }
        
        $stmt = $conn->prepare("INSERT INTO employee_roles (role_name, created_at) VALUES (?, NOW())");
        $stmt->bind_param("s", $role_name);
        
        if ($stmt->execute()) {
            $role_id = $conn->insert_id;
            logActivity('payroll', "Created new employee role: $role_name", $currentUser['id'], null, ['role_id' => $role_id]);
            echo json_encode(['success' => true, 'role_id' => $role_id, 'role_name' => $role_name]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to create role']);
        }
        exit;
    }
    
    // Add new employee
    if ($_POST['action'] === 'add_employee') {
        $name = trim($_POST['name']);
        $role_id = intval($_POST['role_id']);
        $store_id = !empty($_POST['store_id']) ? intval($_POST['store_id']) : null;
        $start_date = $_POST['start_date'];
        $day_off = $_POST['day_off'];
        $daily_salary = floatval($_POST['daily_salary']);
        $year_end_bonus = !empty($_POST['year_end_bonus']) ? floatval($_POST['year_end_bonus']) : 0;
        
        $stmt = $conn->prepare("INSERT INTO employees (name, role_id, store_id, start_date, day_off, daily_salary, year_end_bonus, status, created_at) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
        $stmt->bind_param("siissdd", $name, $role_id, $store_id, $start_date, $day_off, $daily_salary, $year_end_bonus);
        
        if ($stmt->execute()) {
            $employee_id = $conn->insert_id;
            logActivity('payroll', "Added new employee: $name", $currentUser['id'], $store_id, [
                'employee_id' => $employee_id,
                'employee_name' => $name,
                'daily_salary' => $daily_salary
            ]);
            echo json_encode(['success' => true, 'employee_id' => $employee_id]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to add employee']);
        }
        exit;
    }
    
    // Mark attendance
    if ($_POST['action'] === 'mark_attendance') {
        $employee_id = intval($_POST['employee_id']);
        $date = $_POST['date'];
        $attendance_type = $_POST['attendance_type'];
        
        // Check if date is in the future
        $selected_date = new DateTime($date);
        $today = new DateTime();
        $today->setTime(0, 0, 0); // Reset time to compare dates only
        $selected_date->setTime(0, 0, 0);
        
        if ($selected_date > $today) {
            echo json_encode([
                'success' => false, 
                'error' => 'Cannot mark attendance for future dates. Please select today or a past date.'
            ]);
            exit;
        }
        
        // Get employee name first
        $stmt = $conn->prepare("SELECT name FROM employees WHERE id = ?");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $employee_name = $employee ? $employee['name'] : 'Unknown';
        
        $stmt = $conn->prepare("SELECT id FROM employee_attendance WHERE employee_id = ? AND attendance_date = ?");
        $stmt->bind_param("is", $employee_id, $date);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        
        if ($existing) {
            $stmt = $conn->prepare("UPDATE employee_attendance SET attendance_type = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $attendance_type, $existing['id']);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("INSERT INTO employee_attendance (employee_id, attendance_date, attendance_type, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iss", $employee_id, $date, $attendance_type);
            $stmt->execute();
        }
        
        logActivity('payroll', "Marked attendance for $employee_name: $attendance_type on $date", $currentUser['id'], null, [
            'employee_id' => $employee_id,
            'employee_name' => $employee_name,
            'attendance_type' => $attendance_type,
            'date' => $date
        ]);
        echo json_encode(['success' => true]);
        exit;
    }
    
    // Add cash advance
    if ($_POST['action'] === 'add_cash_advance') {
        $employee_id = intval($_POST['employee_id']);
        $amount = floatval($_POST['amount']);
        $week_start = $_POST['week_start'];
        $notes = trim($_POST['notes']);
        
        // Get employee name first
        $stmt = $conn->prepare("SELECT name FROM employees WHERE id = ?");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $employee_name = $employee ? $employee['name'] : 'Unknown';
        
        $stmt = $conn->prepare("INSERT INTO employee_cash_advances (employee_id, amount, week_start_date, notes, created_at, created_by) 
                               VALUES (?, ?, ?, ?, NOW(), ?)");
        $stmt->bind_param("idssi", $employee_id, $amount, $week_start, $notes, $currentUser['id']);
        
        if ($stmt->execute()) {
            $ca_id = $conn->insert_id;
            logActivity('payroll', "Cash advance recorded for $employee_name: ₱" . number_format($amount, 2), $currentUser['id'], null, [
                'cash_advance_id' => $ca_id,
                'employee_id' => $employee_id,
                'employee_name' => $employee_name,
                'amount' => $amount
            ]);
            echo json_encode(['success' => true, 'ca_id' => $ca_id]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to record cash advance']);
        }
        exit;
    }
    
    // Get employee data
    if ($_POST['action'] === 'get_employee') {
        $employee_id = intval($_POST['employee_id']);
        
        $stmt = $conn->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        
        if ($employee) {
            echo json_encode(['success' => true, 'employee' => $employee]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Employee not found']);
        }
        exit;
    }
    
    // Update employee
    if ($_POST['action'] === 'update_employee') {
        $employee_id = intval($_POST['employee_id']);
        $name = trim($_POST['name']);
        $role_id = intval($_POST['role_id']);
        $store_id = !empty($_POST['store_id']) ? intval($_POST['store_id']) : null;
        $start_date = $_POST['start_date'];
        $day_off = $_POST['day_off'];
        $daily_salary = floatval($_POST['daily_salary']);
        $year_end_bonus = !empty($_POST['year_end_bonus']) ? floatval($_POST['year_end_bonus']) : 0;
        
        $stmt = $conn->prepare("UPDATE employees SET name = ?, role_id = ?, store_id = ?, start_date = ?, day_off = ?, daily_salary = ?, year_end_bonus = ?, updated_at = NOW() 
                               WHERE id = ?");
        $stmt->bind_param("siissddi", $name, $role_id, $store_id, $start_date, $day_off, $daily_salary, $year_end_bonus, $employee_id);
        
        if ($stmt->execute()) {
            logActivity('payroll', "Updated employee: $name", $currentUser['id'], $store_id, [
                'employee_id' => $employee_id,
                'employee_name' => $name
            ]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update employee']);
        }
        exit;
    }
    
    // Remove employee
    if ($_POST['action'] === 'remove_employee') {
        $employee_id = intval($_POST['employee_id']);
        
        // Get employee name first
        $stmt = $conn->prepare("SELECT name FROM employees WHERE id = ?");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $employee_name = $employee ? $employee['name'] : 'Unknown';
        
        $stmt = $conn->prepare("UPDATE employees SET status = 'inactive', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $employee_id);
        
        if ($stmt->execute()) {
            logActivity('payroll', "Deactivated employee: $employee_name", $currentUser['id'], null, [
                'employee_id' => $employee_id,
                'employee_name' => $employee_name
            ]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to remove employee']);
        }
        exit;
    }
    
    // Restore employee
    if ($_POST['action'] === 'restore_employee') {
        $employee_id = intval($_POST['employee_id']);
        
        // Get employee name first
        $stmt = $conn->prepare("SELECT name FROM employees WHERE id = ?");
        $stmt->bind_param("i", $employee_id);
        $stmt->execute();
        $employee = $stmt->get_result()->fetch_assoc();
        $employee_name = $employee ? $employee['name'] : 'Unknown';
        
        $stmt = $conn->prepare("UPDATE employees SET status = 'active', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $employee_id);
        
        if ($stmt->execute()) {
            logActivity('payroll', "Restored employee: $employee_name", $currentUser['id'], null, [
                'employee_id' => $employee_id,
                'employee_name' => $employee_name
            ]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to restore employee']);
        }
        exit;
    }
    
    // Update overtime settings
    if ($_POST['action'] === 'update_overtime_settings') {
        $work_hours = floatval($_POST['work_hours']);
        $ot_pay = floatval($_POST['overtime_pay']);

        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('standard_work_hours', ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
        $stmt->bind_param("dd", $work_hours, $work_hours);
        $stmt->execute();

        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('overtime_pay_rate', ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()");
        $stmt->bind_param("dd", $ot_pay, $ot_pay);
        $stmt->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    // Update time in/out
    if ($_POST['action'] === 'update_time') {
        $employee_id = intval($_POST['employee_id']);
        $date = $_POST['date'];
        $time_in = !empty($_POST['time_in']) ? $_POST['time_in'] : null;
        $time_out = !empty($_POST['time_out']) ? $_POST['time_out'] : null;

        $stmt = $conn->prepare("UPDATE employee_attendance SET time_in = ?, time_out = ?, updated_at = NOW() WHERE employee_id = ? AND attendance_date = ?");
        $stmt->bind_param("ssis", $time_in, $time_out, $employee_id, $date);
        $stmt->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    // Get attendance data for a specific date
    if ($_POST['action'] === 'get_attendance_data') {
        $date = $_POST['date'];
        
        $stmt = $conn->prepare("SELECT ea.*, e.name, e.daily_salary 
                               FROM employee_attendance ea 
                               JOIN employees e ON ea.employee_id = e.id 
                               WHERE ea.attendance_date = ? AND e.status = 'active'");
        $stmt->bind_param("s", $date);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $result]);
        exit;
    }
}

// Get week start from URL parameter or default to current week's Monday
if (isset($_GET['week'])) {
    $week_start = new DateTime($_GET['week']);
} else {
    $current_date = new DateTime();
    $current_weekday = $current_date->format('N');
    $days_to_subtract = $current_weekday - 1;
    $week_start = clone $current_date;
    $week_start->modify("-$days_to_subtract days");
}
$week_start_str = $week_start->format('Y-m-d');

// Keep current_date for today comparisons
$current_date = new DateTime();

// Auto-migrate: add time columns if missing
$colCheck = $conn->query("SHOW COLUMNS FROM employee_attendance LIKE 'time_in'");
if ($colCheck && $colCheck->num_rows === 0) {
    $conn->query("ALTER TABLE employee_attendance ADD COLUMN time_in TIME NULL AFTER attendance_type, ADD COLUMN time_out TIME NULL AFTER time_in");
}

// Get bonus deduction setting
$bonus_deduction_result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'bonus_deduction_per_absence'");
$bonus_deduction = 0;
if ($bonus_deduction_result && $bonus_deduction_result->num_rows > 0) {
    $bonus_deduction = floatval($bonus_deduction_result->fetch_assoc()['setting_value']);
}

// Get overtime settings
$ot_hours_result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'standard_work_hours'");
$standard_work_hours = 8;
if ($ot_hours_result && $ot_hours_result->num_rows > 0) {
    $standard_work_hours = floatval($ot_hours_result->fetch_assoc()['setting_value']);
}
$ot_pay_result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'overtime_pay_rate'");
$overtime_pay_rate = 0;
if ($ot_pay_result && $ot_pay_result->num_rows > 0) {
    $overtime_pay_rate = floatval($ot_pay_result->fetch_assoc()['setting_value']);
}

// Fetch employees
$employees_query = "SELECT e.*, r.role_name, s.store_name, s.store_code 
                   FROM employees e 
                   LEFT JOIN employee_roles r ON e.role_id = r.id 
                   LEFT JOIN stores s ON e.store_id = s.id 
                   WHERE e.status = 'active' 
                   ORDER BY e.name";
$employees_result = $conn->query($employees_query);
$employees = $employees_result->fetch_all(MYSQLI_ASSOC);

// Fetch removed (inactive) employees
$removed_employees_query = "SELECT e.*, r.role_name, s.store_name, s.store_code 
                           FROM employees e 
                           LEFT JOIN employee_roles r ON e.role_id = r.id 
                           LEFT JOIN stores s ON e.store_id = s.id 
                           WHERE e.status = 'inactive' 
                           ORDER BY e.updated_at DESC";
$removed_employees_result = $conn->query($removed_employees_query);
$removed_employees = $removed_employees_result->fetch_all(MYSQLI_ASSOC);

// Fetch roles
$roles_result = $conn->query("SELECT * FROM employee_roles ORDER BY role_name");
$roles = $roles_result->fetch_all(MYSQLI_ASSOC);

// Fetch stores
$stores_result = $conn->query("SELECT * FROM stores WHERE status = 'active' ORDER BY store_name");
$stores = $stores_result->fetch_all(MYSQLI_ASSOC);

// Fetch attendance for current week
$week_end = clone $week_start;
$week_end->modify('+6 days');
$week_end_str = $week_end->format('Y-m-d');

$attendance_query = "SELECT * FROM employee_attendance 
                    WHERE attendance_date BETWEEN ? AND ?";
$stmt = $conn->prepare($attendance_query);
$stmt->bind_param("ss", $week_start_str, $week_end_str);
$stmt->execute();
$attendance_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Organize attendance by employee
$attendance_by_employee = [];
foreach ($attendance_result as $att) {
    $attendance_by_employee[$att['employee_id']][$att['attendance_date']] = $att;
}

// Fetch monthly absences for bonus deduction calculation
$month_start = (new DateTime())->modify('first day of this month')->format('Y-m-d');
$month_end = (new DateTime())->modify('last day of this month')->format('Y-m-d');

$monthly_absence_query = "SELECT employee_id, COUNT(*) as absence_count 
                         FROM employee_attendance 
                         WHERE attendance_date BETWEEN ? AND ? 
                         AND attendance_type = 'absent'
                         GROUP BY employee_id";
$stmt = $conn->prepare($monthly_absence_query);
$stmt->bind_param("ss", $month_start, $month_end);
$stmt->execute();
$monthly_absences_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Organize monthly absences by employee
$monthly_absences = [];
foreach ($monthly_absences_result as $abs) {
    $monthly_absences[$abs['employee_id']] = $abs['absence_count'];
}

// Fetch cash advances for current week
$ca_query = "SELECT * FROM employee_cash_advances WHERE week_start_date = ?";
$stmt = $conn->prepare($ca_query);
$stmt->bind_param("s", $week_start_str);
$stmt->execute();
$ca_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$cash_advances_by_employee = [];
foreach ($ca_result as $ca) {
    if (!isset($cash_advances_by_employee[$ca['employee_id']])) {
        $cash_advances_by_employee[$ca['employee_id']] = 0;
    }
    $cash_advances_by_employee[$ca['employee_id']] += $ca['amount'];
}

// Helper: calculate hours-based salary for a date range
function calcPeriodSalary($conn, $eid, $date_from, $date_to, $daily_salary, $std_hours, $ot_rate) {
    $rows = $conn->query("SELECT attendance_type, time_in, time_out FROM employee_attendance
        WHERE employee_id={$eid} AND attendance_date BETWEEN '{$date_from}' AND '{$date_to}'
        AND attendance_type IN ('whole_day','half_day')")->fetch_all(MYSQLI_ASSOC);
    $days = 0; $std_h = 0; $ot_h = 0; $base = 0;
    $hourly = $std_hours > 0 ? $daily_salary / $std_hours : 0;
    foreach ($rows as $r) {
        $days++;
        if ($r['time_in'] && $r['time_out']) {
            $diff = (new DateTime($r['time_in']))->diff(new DateTime($r['time_out']));
            $h = $diff->h + ($diff->i / 60);
            $std_h += min($h, $std_hours);
            $ot_h += max(0, $h - $std_hours);
            $base += $h >= $std_hours ? $daily_salary : $h * $hourly;
        } else {
            $std_h += $std_hours;
            $base += $daily_salary;
        }
    }
    $ot_pay = $ot_h * $ot_rate;
    return ['days' => $days, 'std_h' => $std_h, 'ot_h' => $ot_h, 'base' => $base, 'ot_pay' => $ot_pay, 'gross' => $base + $ot_pay];
}

// Past 4 weeks salary history per employee
$salary_history = [];
foreach ($employees as $emp) {
    $eid = $emp['id'];
    $salary_history[$eid] = ['weeks' => [], 'months' => []];
    for ($w = 0; $w < 4; $w++) {
        $ws = clone $week_start;
        $ws->modify("-" . ($w * 7) . " days");
        $we = clone $ws;
        $we->modify('+6 days');
        $wss = $ws->format('Y-m-d');
        $wes = $we->format('Y-m-d');
        $p = calcPeriodSalary($conn, $eid, $wss, $wes, $emp['daily_salary'], $standard_work_hours, $overtime_pay_rate);
        $ca_r = $conn->query("SELECT SUM(amount) as total FROM employee_cash_advances WHERE employee_id={$eid} AND week_start_date='{$wss}'")->fetch_assoc();
        $ca_amt = (float)($ca_r['total'] ?? 0);
        $salary_history[$eid]['weeks'][] = [
            'label' => $ws->format('M j') . '-' . $we->format('j'),
            'days' => $p['days'], 'std_h' => $p['std_h'], 'ot_h' => $p['ot_h'],
            'gross' => $p['gross'], 'ot_pay' => $p['ot_pay'],
            'ca' => $ca_amt, 'net' => $p['gross'] - $ca_amt
        ];
    }
    for ($m = 0; $m < 3; $m++) {
        $ms = (new DateTime())->modify("-{$m} months")->modify('first day of this month');
        $me = (clone $ms)->modify('last day of this month');
        $mss = $ms->format('Y-m-d');
        $mes = $me->format('Y-m-d');
        $p = calcPeriodSalary($conn, $eid, $mss, $mes, $emp['daily_salary'], $standard_work_hours, $overtime_pay_rate);
        // Count absences for this month
        $abs_r = $conn->query("SELECT COUNT(*) as c FROM employee_attendance WHERE employee_id={$eid} AND attendance_date BETWEEN '{$mss}' AND '{$mes}' AND attendance_type='absent'")->fetch_assoc();
        $abs_count = (int)($abs_r['c'] ?? 0);
        $bonus_ded = ($emp['year_end_bonus'] > 0 && $bonus_deduction > 0) ? $abs_count * $bonus_deduction : 0;
        $salary_history[$eid]['months'][] = [
            'label' => $ms->format('M Y'), 'days' => $p['days'], 'std_h' => $p['std_h'], 'ot_h' => $p['ot_h'],
            'gross' => $p['gross'], 'ot_pay' => $p['ot_pay'],
            'absences' => $abs_count, 'bonus_ded' => $bonus_ded
        ];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Payroll Management - Oro Store</title>
    <link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
    <link rel="stylesheet" href="/oro-store/payroll/payroll_styles.css">
</head>
<body>
<?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

<main class="main-content">
    <div class="payroll-container">
        <!-- Calendar Section -->
        <div class="calendar-section">
            <div class="calendar-header">
                <h2>📅 Calendar</h2>
                <p style="font-size: 12px; color: #666; margin: 5px 0;">Click dates to edit attendance</p>
            </div>
            <div class="calendar-nav">
                <button onclick="changeMonth(-1)">←</button>
                <span class="calendar-month" id="calendar-month"></span>
                <button onclick="changeMonth(1)">→</button>
            </div>
            <div class="calendar-grid" id="calendar-grid"></div>
            
            <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                <div style="font-weight: bold; margin-bottom: 10px; text-align: center;">
                    Selected Date
                </div>
                <div id="selected-date-display" style="text-align: center; font-size: 14px; color: #667eea; font-weight: bold;">
                    <?php echo $current_date->format('M d, Y'); ?>
                </div>
                <div style="font-size: 11px; color: #666; text-align: center; margin-top: 5px;">
                    Use attendance buttons below
                </div>
            </div>
            
            <div style="margin-top: 15px; padding: 15px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
                <div style="font-weight: bold; margin-bottom: 8px; font-size: 13px;">
                    Bonus Deduction
                </div>
                <div style="font-size: 12px; color: #856404; line-height: 1.6;">
                    <strong>&#8369;<?php echo number_format($bonus_deduction, 2); ?></strong> per absence
                </div>
            </div>

            <!-- Add Employee (compact) -->
            <div class="sidebar-panel">
                <div class="sidebar-panel-header" onclick="togglePanel('add-emp-panel')">
                    <span>&#10133; Add New Employee</span>
                    <span class="panel-toggle" id="add-emp-panel-icon">&#9654;</span>
                </div>
                <div class="sidebar-panel-body" id="add-emp-panel" style="display:none;">
                    <form id="add-employee-form">
                        <div class="sp-field">
                            <label>Name *</label>
                            <input type="text" id="employee-name" required>
                        </div>
                        <div class="sp-field">
                            <label>Role *</label>
                            <select id="employee-role" required>
                                <option value="">Select</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sp-row">
                            <div class="sp-field">
                                <label>Store</label>
                                <select id="employee-store">
                                    <option value="">None</option>
                                    <?php foreach ($stores as $store): ?>
                                        <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sp-field">
                                <label>Day Off *</label>
                                <select id="employee-day-off" required>
                                    <option value="">Day</option>
                                    <option value="monday">Mon</option>
                                    <option value="tuesday">Tue</option>
                                    <option value="wednesday">Wed</option>
                                    <option value="thursday">Thu</option>
                                    <option value="friday">Fri</option>
                                    <option value="saturday">Sat</option>
                                    <option value="sunday">Sun</option>
                                </select>
                            </div>
                        </div>
                        <div class="sp-row">
                            <div class="sp-field">
                                <label>Start Date *</label>
                                <input type="date" id="employee-start-date" required>
                            </div>
                            <div class="sp-field">
                                <label>Daily Salary *</label>
                                <input type="number" id="employee-salary" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="sp-field">
                            <label>Year-End Bonus</label>
                            <input type="number" id="employee-bonus" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="sp-row" style="gap:6px;margin-top:8px;">
                            <input type="text" id="new-role-name" placeholder="New role name" style="flex:1;padding:7px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:12px;">
                            <button type="button" class="sp-btn-sm green" onclick="createNewRole()">+ Role</button>
                        </div>
                        <button type="submit" class="sp-btn" style="margin-top:12px;">Add Employee</button>
                    </form>
                </div>
            </div>

            <!-- Bonus Deduction Settings (compact) -->
            <div class="sidebar-panel">
                <div class="sidebar-panel-header" onclick="togglePanel('bonus-panel')">
                    <span>&#9881; Deduction Settings</span>
                    <span class="panel-toggle" id="bonus-panel-icon">&#9654;</span>
                </div>
                <div class="sidebar-panel-body" id="bonus-panel" style="display:none;">
                    <form id="bonus-deduction-form">
                        <div class="sp-field">
                            <label>Per Absence</label>
                            <input type="number" id="bonus-deduction-amount" step="0.01" min="0" value="<?php echo $bonus_deduction; ?>" required>
                            <span style="font-size:11px;color:#94a3b8;margin-top:3px;display:block;">Deducted from year-end bonus per absence/month</span>
                        </div>
                        <button type="submit" class="sp-btn" style="margin-top:8px;">Save Setting</button>
                    </form>
                </div>
            </div>

            <!-- Overtime Settings (compact) -->
            <div class="sidebar-panel">
                <div class="sidebar-panel-header" onclick="togglePanel('overtime-panel')">
                    <span>&#9881; Overtime Settings</span>
                    <span class="panel-toggle" id="overtime-panel-icon">&#9654;</span>
                </div>
                <div class="sidebar-panel-body" id="overtime-panel" style="display:none;">
                    <form id="overtime-settings-form">
                        <div class="sp-field">
                            <label>Standard Work Hours</label>
                            <input type="number" id="ot-work-hours" step="0.5" min="0" max="24" value="<?php echo $standard_work_hours; ?>" required>
                            <span style="font-size:11px;color:#94a3b8;margin-top:3px;display:block;">Hours before overtime kicks in</span>
                        </div>
                        <div class="sp-field">
                            <label>Overtime Pay (&#8369;/hr)</label>
                            <input type="number" id="ot-pay-rate" step="0.01" min="0" value="<?php echo $overtime_pay_rate; ?>" required>
                            <span style="font-size:11px;color:#94a3b8;margin-top:3px;display:block;">Set to 0 for no overtime pay</span>
                        </div>
                        <button type="submit" class="sp-btn" style="margin-top:8px;">Save Setting</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Employees Section -->
        <div class="employees-section">
            <!-- Employee List -->
            <div class="employees-list">
                <div class="list-header">
                    <h2>👥 Employees Attendance</h2>
                    <div class="week-info" id="week-info">
                        Week of <?php echo $week_start->format('M d, Y'); ?>
                    </div>
                </div>
                
                <!-- Week Navigation -->
                <div class="week-navigation" style="display: flex; justify-content: center; align-items: center; gap: 15px; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                    <button onclick="changeWeek(-1)" class="btn-week-nav" style="padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: background 0.2s;">
                        ← Previous Week
                    </button>
                    <div class="current-week-display" style="font-weight: bold; font-size: 16px; min-width: 250px; text-align: center;">
                        <?php 
                        $week_end_display = clone $week_start;
                        $week_end_display->modify('+6 days');
                        echo $week_start->format('M d') . ' - ' . $week_end_display->format('M d, Y'); 
                        ?>
                    </div>
                    <button onclick="changeWeek(1)" class="btn-week-nav" style="padding: 10px 20px; background: #667eea; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; transition: background 0.2s;">
                        Next Week →
                    </button>
                </div>
                
                <div id="employees-container">
                    <?php if (empty($employees)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">👤</div>
                            <h3>No Employees Found</h3>
                            <p>Add your first employee using the form below</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($employees as $employee): ?>
                            <?php
                            $employee_id = $employee['id'];
                            $daily_salary = $employee['daily_salary'];
                            
                            $days_worked = 0;
                            $total_standard_hrs = 0;
                            $total_overtime_hrs = 0;
                            $base_salary = 0;
                            $hourly_rate = $standard_work_hours > 0 ? $daily_salary / $standard_work_hours : 0;

                            for ($i = 0; $i < 7; $i++) {
                                $check_date = clone $week_start;
                                $check_date->modify("+$i days");
                                $date_str = $check_date->format('Y-m-d');

                                if ($check_date > $current_date) continue;

                                $attendance = $attendance_by_employee[$employee_id][$date_str] ?? null;

                                if ($attendance && ($attendance['attendance_type'] === 'whole_day' || $attendance['attendance_type'] === 'half_day')) {
                                    $days_worked++;
                                    $ti = $attendance['time_in'] ?? '';
                                    $to = $attendance['time_out'] ?? '';
                                    if ($ti && $to) {
                                        $dti = new DateTime($ti);
                                        $dto = new DateTime($to);
                                        $diff = $dti->diff($dto);
                                        $day_hrs = $diff->h + ($diff->i / 60);
                                        $total_standard_hrs += min($day_hrs, $standard_work_hours);
                                        $total_overtime_hrs += max(0, $day_hrs - $standard_work_hours);
                                        $base_salary += $day_hrs >= $standard_work_hours ? $daily_salary : $day_hrs * $hourly_rate;
                                    } else {
                                        $total_standard_hrs += $standard_work_hours;
                                        $base_salary += $daily_salary;
                                    }
                                }
                            }

                            $overtime_pay = $total_overtime_hrs * $overtime_pay_rate;
                            $weekly_salary = $base_salary + $overtime_pay;
                            $cash_advance = $cash_advances_by_employee[$employee_id] ?? 0;

                            $monthly_absence_count = $monthly_absences[$employee_id] ?? 0;
                            $total_bonus_deduction = 0;

                            if ($employee['year_end_bonus'] > 0 && $bonus_deduction > 0 && $monthly_absence_count > 0) {
                                $total_bonus_deduction = $monthly_absence_count * $bonus_deduction;
                            }

                            $net_salary = $weekly_salary - $cash_advance;
                            ?>
                            
                            <div class="employee-card" data-employee-id="<?php echo $employee_id; ?>">
                                <!-- Name bar -->
                                <div class="ec-name-bar">
                                    <div class="employee-name"><?php echo htmlspecialchars($employee['name']); ?></div>
                                    <div class="employee-details">
                                        <span>&#128084; <?php echo htmlspecialchars($employee['role_name']); ?></span>
                                        <span>&#8369;<?php echo number_format($daily_salary, 2); ?>/day</span>
                                        <?php if ($employee['store_name']): ?>
                                            <span>&#127978; <?php echo htmlspecialchars($employee['store_code']); ?></span>
                                        <?php endif; ?>
                                        <span>Off: <?php echo ucfirst($employee['day_off']); ?></span>
                                    </div>
                                </div>

                                <!-- Two-column layout -->
                                <div class="ec-columns">
                                    <!-- LEFT: Week calendar + salary breakdown -->
                                    <div class="ec-left">
                                        <div class="week-calendar" data-week-start="<?php echo $week_start_str; ?>">
                                            <?php
                                            $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                            for ($i = 0; $i < 7; $i++):
                                                $check_date = clone $week_start;
                                                $check_date->modify("+$i days");
                                                $date_str = $check_date->format('Y-m-d');
                                                $day_name = $days[$check_date->format('N') - 1];
                                                $attendance = $attendance_by_employee[$employee_id][$date_str] ?? null;
                                                $class = 'upcoming';
                                                if ($check_date <= $current_date) {
                                                    if ($attendance && $attendance['attendance_type'] === 'whole_day') { $class = 'present'; }
                                                    elseif ($attendance && $attendance['attendance_type'] === 'half_day') { $class = 'half'; }
                                                    else { $class = 'absent'; }
                                                }
                                            ?>
                                                <?php
                                                    $att_record = $attendance_by_employee[$employee_id][$date_str] ?? null;
                                                    $t_in = $att_record['time_in'] ?? '';
                                                    $t_out = $att_record['time_out'] ?? '';
                                                    $hrs = '';
                                                    $ot = '';
                                                    if ($t_in && $t_out) {
                                                        $dt_in = new DateTime($t_in);
                                                        $dt_out = new DateTime($t_out);
                                                        $diff = $dt_in->diff($dt_out);
                                                        $total_hrs = $diff->h + ($diff->i / 60);
                                                        $hrs = number_format($total_hrs, 1);
                                                        $ot_hrs = max(0, $total_hrs - $standard_work_hours);
                                                        $ot = $ot_hrs > 0 ? number_format($ot_hrs, 1) : '0';
                                                    }
                                                ?>
                                                <div class="week-day <?php echo $class; ?>" data-date="<?php echo $date_str; ?>"
                                                     data-emp="<?php echo $employee_id; ?>"
                                                     data-tin="<?php echo $t_in; ?>"
                                                     data-tout="<?php echo $t_out; ?>">
                                                    <div class="wd-head"><?php echo $day_name; ?> <strong><?php echo $check_date->format('j'); ?></strong></div>
                                                    <?php if ($check_date <= $current_date && $att_record): ?>
                                                    <div class="wd-times">
                                                        <div>In: <span class="wd-time"><?php echo $t_in ? date('g:ia', strtotime($t_in)) : '—'; ?></span></div>
                                                        <div>Out: <span class="wd-time"><?php echo $t_out ? date('g:ia', strtotime($t_out)) : '—'; ?></span></div>
                                                        <div>Hrs: <span class="wd-hrs"><?php echo $hrs ?: '—'; ?></span></div>
                                                        <div>OT: <span class="wd-ot"><?php echo $ot ?: '—'; ?></span></div>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endfor; ?>
                                        </div>

                                        <div class="ec-salary">
                                            <div class="ec-sal-row">
                                                <span>Hours</span>
                                                <span><?php echo number_format($total_standard_hrs, 1); ?> std + <?php echo number_format($total_overtime_hrs, 1); ?> OT</span>
                                            </div>
                                            <div class="ec-sal-row">
                                                <span>Daily Salary (<?php echo $days_worked; ?> day<?php echo $days_worked != 1 ? 's' : ''; ?>)</span>
                                                <span>&#8369;<?php echo number_format($base_salary, 2); ?></span>
                                            </div>
                                            <?php if ($overtime_pay > 0): ?>
                                            <div class="ec-sal-row" style="color:#f59e0b;">
                                                <span>Overtime (<?php echo number_format($total_overtime_hrs, 1); ?>h × &#8369;<?php echo number_format($overtime_pay_rate, 2); ?>)</span>
                                                <span>+&#8369;<?php echo number_format($overtime_pay, 2); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <div class="ec-sal-row">
                                                <span>Gross (Week)</span>
                                                <span>&#8369;<?php echo number_format($weekly_salary, 2); ?></span>
                                            </div>
                                            <?php if ($cash_advance > 0): ?>
                                            <div class="ec-sal-row ec-red">
                                                <span>Cash Advance</span>
                                                <span>-&#8369;<?php echo number_format($cash_advance, 2); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <div class="ec-sal-row ec-total">
                                                <span>Weekly Salary</span>
                                                <span>&#8369;<?php echo number_format($net_salary, 2); ?></span>
                                            </div>
                                            <?php if ($employee['year_end_bonus'] > 0): ?>
                                            <div class="ec-sal-row ec-bonus-row">
                                                <span>Year-End Bonus (Rem.)</span>
                                                <span>&#8369;<?php echo number_format(max(0, $employee['year_end_bonus'] - $total_bonus_deduction), 2); ?></span>
                                            </div>
                                            <?php if ($total_bonus_deduction > 0): ?>
                                            <div class="ec-sal-row" style="font-size:11px;color:#94a3b8;">
                                                <span>(<?php echo $monthly_absence_count; ?> absence<?php echo $monthly_absence_count != 1 ? 's' : ''; ?> × &#8369;<?php echo number_format($bonus_deduction, 2); ?>)</span>
                                                <span>-&#8369;<?php echo number_format($total_bonus_deduction, 2); ?></span>
                                            </div>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- RIGHT: Actions + salary history -->
                                    <div class="ec-right">
                                        <!-- Time In/Out buttons -->
                                        <div class="ec-att-btns">
                                            <button class="btn-attendance btn-whole-day" onclick="timeIn(<?php echo $employee_id; ?>)">&#128337; Time In</button>
                                            <button class="btn-attendance btn-half-day" onclick="timeOut(<?php echo $employee_id; ?>)">&#128340; Time Out</button>
                                            <button class="btn-attendance btn-absent" onclick="markAttendance(<?php echo $employee_id; ?>, 'absent')">&#10007; Absent</button>
                                        </div>

                                        <!-- Action buttons -->
                                        <div class="ec-action-btns">
                                            <button class="btn-action btn-ca" onclick="openCashAdvanceModal(<?php echo $employee_id; ?>, '<?php echo htmlspecialchars($employee['name']); ?>')">&#128181; CA</button>
                                            <button class="btn-action btn-edit" onclick="openEditModal(<?php echo $employee_id; ?>)">&#9998; Edit</button>
                                            <button class="btn-action btn-remove" onclick="removeEmployee(<?php echo $employee_id; ?>, '<?php echo htmlspecialchars($employee['name']); ?>')">&#128465;</button>
                                        </div>

                                        <!-- Salary History -->
                                        <?php $hist = $salary_history[$employee_id]; ?>
                                        <div class="ec-history">
                                            <div class="sh-title">Past 4 Weeks</div>
                                            <table class="sh-table">
                                                <thead><tr><th>Week</th><th>Hrs</th><th>OT</th><th>Gross</th><th>CA</th><th>Net</th></tr></thead>
                                                <tbody>
                                                <?php foreach ($hist['weeks'] as $w): ?>
                                                <tr>
                                                    <td><?php echo $w['label']; ?></td>
                                                    <td><?php echo number_format($w['std_h'], 0); ?>h</td>
                                                    <td style="<?php echo $w['ot_h'] > 0 ? 'color:#f59e0b' : ''; ?>"><?php echo $w['ot_h'] > 0 ? number_format($w['ot_h'], 1) . 'h' : '-'; ?></td>
                                                    <td>&#8369;<?php echo number_format($w['gross'], 0); ?></td>
                                                    <td class="<?php echo $w['ca'] > 0 ? 'sh-red' : ''; ?>"><?php echo $w['ca'] > 0 ? '-&#8369;' . number_format($w['ca'], 0) : '-'; ?></td>
                                                    <td class="sh-bold">&#8369;<?php echo number_format($w['net'], 0); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>

                                            <div class="sh-title" style="margin-top:10px;">Past 3 Months</div>
                                            <table class="sh-table">
                                                <thead><tr><th>Month</th><th>Hrs</th><th>OT</th><th>Gross</th><th>Abs</th><th>Bonus Ded</th></tr></thead>
                                                <tbody>
                                                <?php foreach ($hist['months'] as $m): ?>
                                                <tr>
                                                    <td><?php echo $m['label']; ?></td>
                                                    <td><?php echo number_format($m['std_h'], 0); ?>h</td>
                                                    <td style="<?php echo $m['ot_h'] > 0 ? 'color:#f59e0b' : ''; ?>"><?php echo $m['ot_h'] > 0 ? number_format($m['ot_h'], 1) . 'h' : '-'; ?></td>
                                                    <td class="sh-bold">&#8369;<?php echo number_format($m['gross'], 0); ?></td>
                                                    <td class="<?php echo $m['absences'] > 0 ? 'sh-red' : ''; ?>"><?php echo $m['absences'] > 0 ? $m['absences'] : '-'; ?></td>
                                                    <td class="<?php echo $m['bonus_ded'] > 0 ? 'sh-red' : ''; ?>"><?php echo $m['bonus_ded'] > 0 ? '-&#8369;' . number_format($m['bonus_ded'], 0) : '-'; ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Old forms removed — now in sidebar panel -->

            <!-- Removed Employees Section -->
            <div class="add-employee-section removed-employees-section">
                <div class="removed-section-header">
                    <h2 class="section-title">🗑️ Removed Employees</h2>
                    <?php if (!empty($removed_employees)): ?>
                        <span class="removed-count-badge"><?php echo count($removed_employees); ?></span>
                    <?php endif; ?>
                </div>

                <?php if (empty($removed_employees)): ?>
                    <div class="removed-empty-state">
                        <p>No removed employees.</p>
                    </div>
                <?php else: ?>
                    <div class="removed-employees-list">
                        <?php foreach ($removed_employees as $emp): ?>
                            <div class="removed-employee-card">
                                <div class="removed-employee-info">
                                    <div class="removed-employee-name"><?php echo htmlspecialchars($emp['name']); ?></div>
                                    <div class="removed-employee-details">
                                        <?php if ($emp['role_name']): ?>
                                            <span>👔 <?php echo htmlspecialchars($emp['role_name']); ?></span>
                                        <?php endif; ?>
                                        <?php if ($emp['store_name']): ?>
                                            <span>🏪 <?php echo htmlspecialchars($emp['store_name']); ?></span>
                                        <?php endif; ?>
                                        <span>💰 ₱<?php echo number_format($emp['daily_salary'], 2); ?>/day</span>
                                        <span>📅 Started: <?php echo (new DateTime($emp['start_date']))->format('M d, Y'); ?></span>
                                        <span class="removed-date">Removed: <?php echo (new DateTime($emp['updated_at']))->format('M d, Y'); ?></span>
                                    </div>
                                </div>
                                <button class="btn-restore" onclick="restoreEmployee(<?php echo $emp['id']; ?>, '<?php echo htmlspecialchars($emp['name']); ?>')">
                                    ↩️ Restore
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Edit Employee Modal -->
    <div class="modal" id="edit-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>✏️ Edit Employee</h2>
                <button class="btn-close-modal" onclick="closeEditModal()">×</button>
            </div>
            
            <form id="edit-employee-form">
                <input type="hidden" id="edit-employee-id">
                
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" id="edit-employee-name" required>
                </div>
                
                <div class="form-group">
                    <label>Role</label>
                    <select id="edit-employee-role" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['role_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Store Assignment</label>
                    <select id="edit-employee-store">
                        <option value="">No Store</option>
                        <?php foreach ($stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" id="edit-employee-start-date" required>
                </div>
                
                <div class="form-group">
                    <label>Day Off</label>
                    <select id="edit-employee-day-off" required>
                        <option value="monday">Monday</option>
                        <option value="tuesday">Tuesday</option>
                        <option value="wednesday">Wednesday</option>
                        <option value="thursday">Thursday</option>
                        <option value="friday">Friday</option>
                        <option value="saturday">Saturday</option>
                        <option value="sunday">Sunday</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Daily Salary</label>
                    <input type="number" id="edit-employee-salary" step="0.01" min="0" required>
                </div>
                
                <div class="form-group">
                    <label>Year-End Bonus</label>
                    <input type="number" id="edit-employee-bonus" step="0.01" min="0">
                </div>
                
                <div class="modal-buttons">
                    <button type="submit" class="btn-confirm">Save Changes</button>
                    <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Cash Advance Modal -->
    <div class="modal" id="ca-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>💵 Cash Advance</h2>
                <button class="btn-close-modal" onclick="closeCashAdvanceModal()">×</button>
            </div>
            
            <form id="cash-advance-form">
                <input type="hidden" id="ca-employee-id">
                <input type="hidden" id="ca-week-start" value="<?php echo $week_start_str; ?>">
                
                <p style="margin-bottom: 15px;">
                    <strong>Employee:</strong> <span id="ca-employee-name"></span>
                </p>
                
                <div class="form-group">
                    <label>Amount <span style="color: red;">*</span></label>
                    <input type="number" id="ca-amount" step="0.01" min="0.01" required>
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <input type="text" id="ca-notes" placeholder="Optional notes">
                </div>
                
                <div class="modal-buttons">
                    <button type="submit" class="btn-confirm">Record Cash Advance</button>
                    <button type="button" class="btn-cancel" onclick="closeCashAdvanceModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script src="/oro-store/payroll/payroll_script.js"></script>
    <script>
        // Initialize on page load with PHP data
        const weekStartStr = '<?php echo $week_start_str; ?>';
        initializePayroll(weekStartStr);

        // Toggle sidebar panels
        function togglePanel(id) {
            const body = document.getElementById(id);
            const icon = document.getElementById(id + '-icon');
            if (body.style.display === 'none') {
                body.style.display = 'block';
                icon.classList.add('open');
            } else {
                body.style.display = 'none';
                icon.classList.remove('open');
            }
        }

        // Toggle salary history
        function toggleHistory(empId) {
            const body = document.getElementById('sh-body-' + empId);
            const arrow = document.getElementById('sh-arrow-' + empId);
            if (body.style.display === 'none') {
                body.style.display = 'block';
                arrow.classList.add('open');
            } else {
                body.style.display = 'none';
                arrow.classList.remove('open');
            }
        }

        // Restore employee
        function restoreEmployee(employeeId, name) {
            if (!confirm('Restore "' + name + '" back to active?')) return;

            fetch('/oro-store/payroll/payroll.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=restore_employee&employee_id=' + employeeId
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Failed to restore employee.');
                }
            });
        }
    </script>
</main>
</body>
</html>