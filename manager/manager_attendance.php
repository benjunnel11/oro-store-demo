<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';

if (!isManager()) {
    header("Location: /oro-store/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();
$store_id = $currentUser['store_id'] ?? null;

// Handle AJAX - mark attendance only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'add_employee') {
        $name       = trim($_POST['name']);
        $role_id    = intval($_POST['role_id']);
        $day_off    = $_POST['day_off'];
        $start_date = $_POST['start_date'];
        $salary     = floatval($_POST['daily_salary']);
        $bonus      = floatval($_POST['year_end_bonus'] ?? 0);

        $stmt = $conn->prepare("INSERT INTO employees (name, role_id, store_id, day_off, start_date, daily_salary, year_end_bonus, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->bind_param("siissdd", $name, $role_id, $store_id, $day_off, $start_date, $salary, $bonus);
        if ($stmt->execute()) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to add employee']);
        }
        exit;
    }

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

    if ($_POST['action'] === 'mark_attendance') {
        $employee_id = intval($_POST['employee_id']);
        $date        = $_POST['date'];
        $attendance_type = $_POST['attendance_type'];

        $selected_date = new DateTime($date);
        $today = new DateTime();
        $today->setTime(0, 0, 0);
        $selected_date->setTime(0, 0, 0);

        if ($selected_date > $today) {
            echo json_encode(['success' => false, 'error' => 'Cannot mark attendance for future dates. Please select today or a past date.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT id FROM employee_attendance WHERE employee_id = ? AND attendance_date = ?");
        $stmt->bind_param("is", $employee_id, $date);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();

        if ($existing) {
            $stmt = $conn->prepare("UPDATE employee_attendance SET attendance_type = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("si", $attendance_type, $existing['id']);
        } else {
            $stmt = $conn->prepare("INSERT INTO employee_attendance (employee_id, attendance_date, attendance_type, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iss", $employee_id, $date, $attendance_type);
        }
        $stmt->execute();
        echo json_encode(['success' => true]);
        exit;
    }
}

// Week navigation
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
$current_date = new DateTime();

// Fetch only this manager's store employees
$stmt = $conn->prepare("SELECT e.*, r.role_name, s.store_name, s.store_code
    FROM employees e
    LEFT JOIN employee_roles r ON e.role_id = r.id
    LEFT JOIN stores s ON e.store_id = s.id
    WHERE e.status = 'active' AND e.store_id = ?
    ORDER BY e.name");
$stmt->bind_param("i", $store_id);
$stmt->execute();
$employees = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch attendance for the week
$week_end = clone $week_start;
$week_end->modify('+6 days');
$week_end_str = $week_end->format('Y-m-d');

$stmt = $conn->prepare("SELECT * FROM employee_attendance WHERE attendance_date BETWEEN ? AND ?");
$stmt->bind_param("ss", $week_start_str, $week_end_str);
$stmt->execute();
$attendance_result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$attendance_by_employee = [];
foreach ($attendance_result as $att) {
    $attendance_by_employee[$att['employee_id']][$att['attendance_date']] = $att;
}

// Overtime settings
$ot_h_r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'standard_work_hours'");
$standard_work_hours = ($ot_h_r && $ot_h_r->num_rows > 0) ? floatval($ot_h_r->fetch_assoc()['setting_value']) : 8;

// Fetch roles for add employee form
$roles = $conn->query("SELECT * FROM employee_roles ORDER BY role_name")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance - Oro Store</title>
    <link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
    <link rel="stylesheet" href="/oro-store/payroll/payroll_styles.css">
</head>
<body>
<?php include_once __DIR__ . '/../manager/manager_sidebar.php'; ?>

<main class="main-content">
    <div class="payroll-container">

        <!-- Calendar Section (left sidebar) -->
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

            <!-- Add Employee -->
            <div class="sidebar-panel" style="margin-top: 16px;">
                <div class="sidebar-panel-header" onclick="togglePanel('add-emp-panel')">
                    <span>&#10133; Add New Employee</span>
                    <span class="panel-toggle" id="add-emp-panel-icon">&#9654;</span>
                </div>
                <div class="sidebar-panel-body" id="add-emp-panel" style="display:none;">
                    <form id="add-employee-form" onsubmit="return submitNewEmployee(event)">
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
                            <div class="sp-field">
                                <label>Start Date *</label>
                                <input type="date" id="employee-start-date" required>
                            </div>
                        </div>
                        <div class="sp-row">
                            <div class="sp-field">
                                <label>Daily Salary *</label>
                                <input type="number" id="employee-salary" step="0.01" min="0" required>
                            </div>
                            <div class="sp-field">
                                <label>Year-End Bonus</label>
                                <input type="number" id="employee-bonus" step="0.01" min="0" placeholder="0.00">
                            </div>
                        </div>
                        <button type="submit" class="sp-btn" style="margin-top:12px;">Add Employee</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Employees Section -->
        <div class="employees-section">
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
                            <p>Contact your admin to assign employees to your store.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($employees as $employee): ?>
                            <?php $employee_id = $employee['id']; ?>

                            <div class="employee-card" data-employee-id="<?php echo $employee_id; ?>">
                                <!-- Name bar -->
                                <div class="ec-name-bar">
                                    <div class="employee-name"><?php echo htmlspecialchars($employee['name']); ?></div>
                                    <div class="employee-details">
                                        <span>&#128084; <?php echo htmlspecialchars($employee['role_name']); ?></span>
                                        <span>&#8369;<?php echo number_format($employee['daily_salary'], 2); ?>/day</span>
                                        <?php if ($employee['store_name']): ?>
                                            <span>&#127978; <?php echo htmlspecialchars($employee['store_code']); ?></span>
                                        <?php endif; ?>
                                        <span>Off: <?php echo ucfirst($employee['day_off']); ?></span>
                                    </div>
                                </div>

                                <!-- Two-column layout -->
                                <div class="ec-columns">
                                    <!-- LEFT: Week calendar -->
                                    <div class="ec-left">
                                        <div class="week-calendar" data-week-start="<?php echo $week_start_str; ?>">
                                            <?php
                                            $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                                            for ($i = 0; $i < 7; $i++):
                                                $check_date = clone $week_start;
                                                $check_date->modify("+$i days");
                                                $date_str = $check_date->format('Y-m-d');
                                                $attendance = $attendance_by_employee[$employee_id][$date_str] ?? null;
                                                $class = 'upcoming';
                                                if ($check_date <= $current_date) {
                                                    if ($attendance && $attendance['attendance_type'] === 'whole_day') { $class = 'present'; }
                                                    elseif ($attendance && $attendance['attendance_type'] === 'half_day') { $class = 'half'; }
                                                    else { $class = 'absent'; }
                                                }
                                                $t_in = $attendance['time_in'] ?? '';
                                                $t_out = $attendance['time_out'] ?? '';
                                                $hrs = ''; $ot = '';
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
                                                    <div class="wd-head"><?php echo $days[$check_date->format('N') - 1]; ?> <strong><?php echo $check_date->format('j'); ?></strong></div>
                                                    <?php if ($check_date <= $current_date && $attendance): ?>
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
                                    </div>

                                    <!-- RIGHT: Attendance buttons only -->
                                    <div class="ec-right">
                                        <div class="ec-att-btns">
                                            <button class="btn-attendance btn-whole-day" onclick="timeIn(<?php echo $employee_id; ?>)">&#128337; Time In</button>
                                            <button class="btn-attendance btn-half-day" onclick="timeOut(<?php echo $employee_id; ?>)">&#128340; Time Out</button>
                                            <button class="btn-attendance btn-absent" onclick="markAttendance(<?php echo $employee_id; ?>, 'absent')">&#10007; Absent</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</main>

<script src="/oro-store/payroll/payroll_script.js"></script>
<script>
    const weekStartStr = '<?php echo $week_start_str; ?>';
    initializePayroll(weekStartStr);

    const POST_URL = 'manager_attendance.php';

    // Track selected date from calendar clicks
    window._selectedDate = '<?php echo $current_date->format('Y-m-d'); ?>';
    document.addEventListener('click', function(e) {
        const cell = e.target.closest('[data-date]');
        if (cell && cell.classList.contains('calendar-day')) {
            window._selectedDate = cell.dataset.date;
        }
    });

    function getSelectedDate() {
        return window._selectedDate || '<?php echo $current_date->format('Y-m-d'); ?>';
    }

    // Override markAttendance to post to this file
    function markAttendance(employeeId, attendanceType) {
        const dateStr = getSelectedDate();
        fetch(POST_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=mark_attendance&employee_id=${employeeId}&date=${dateStr}&attendance_type=${attendanceType}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.error || 'Failed to mark attendance.');
        })
        .catch(() => alert('Network error. Please try again.'));
    }

    // Time In — marks whole_day + records current time
    function timeIn(employeeId) {
        const dateStr = getSelectedDate();
        const now = new Date();
        const timeStr = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
        if (!confirm(`Time In at ${timeStr}?`)) return;

        fetch(POST_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=mark_attendance&employee_id=${employeeId}&date=${dateStr}&attendance_type=whole_day`
        })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert(d.error || 'Failed'); return; }
            return fetch(POST_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update_time&employee_id=${employeeId}&date=${dateStr}&time_in=${timeStr}&time_out=`
            });
        })
        .then(r => r ? r.json() : null)
        .then(d => { if (d && d.success) location.reload(); else if (d) alert(d.error || 'Failed'); });
    }

    // Time Out — records current time, keeps existing time_in
    function timeOut(employeeId) {
        const dateStr = getSelectedDate();
        const now = new Date();
        const timeStr = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');
        if (!confirm(`Time Out at ${timeStr}?`)) return;

        let existingIn = '';
        document.querySelectorAll(`.week-day[data-emp="${employeeId}"][data-date="${dateStr}"]`).forEach(el => {
            existingIn = el.dataset.tin || '';
        });

        fetch(POST_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=mark_attendance&employee_id=${employeeId}&date=${dateStr}&attendance_type=whole_day`
        })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert(d.error || 'Failed'); return; }
            return fetch(POST_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update_time&employee_id=${employeeId}&date=${dateStr}&time_in=${existingIn}&time_out=${timeStr}`
            });
        })
        .then(r => r ? r.json() : null)
        .then(d => { if (d && d.success) location.reload(); else if (d) alert(d.error || 'Failed'); });
    }

    // Double-click week-day to manually edit times
    document.querySelectorAll('.week-day[data-emp]').forEach(day => {
        day.addEventListener('dblclick', function(e) {
            e.stopPropagation();
            if (this.classList.contains('upcoming')) return;
            const empId = this.dataset.emp;
            const date = this.dataset.date;
            const curIn = this.dataset.tin || '';
            const curOut = this.dataset.tout || '';

            const ti = prompt('Time In (HH:MM, 24hr):', curIn ? curIn.substring(0,5) : '08:00');
            if (ti === null) return;
            const to = prompt('Time Out (HH:MM, 24hr):', curOut ? curOut.substring(0,5) : '17:00');
            if (to === null) return;

            fetch(POST_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=update_time&employee_id=${empId}&date=${date}&time_in=${ti}&time_out=${to}`
            })
            .then(r => r.json())
            .then(d => { if (d.success) location.reload(); else alert(d.error || 'Failed'); });
        });
    });

    function togglePanel(panelId) {
        const panel = document.getElementById(panelId);
        const icon = document.getElementById(panelId + '-icon');
        if (panel.style.display === 'none') {
            panel.style.display = '';
            if (icon) icon.classList.add('open');
        } else {
            panel.style.display = 'none';
            if (icon) icon.classList.remove('open');
        }
    }

    function submitNewEmployee(e) {
        e.preventDefault();
        const name      = document.getElementById('employee-name').value.trim();
        const role_id   = document.getElementById('employee-role').value;
        const day_off   = document.getElementById('employee-day-off').value;
        const start_date = document.getElementById('employee-start-date').value;
        const salary    = document.getElementById('employee-salary').value;
        const bonus     = document.getElementById('employee-bonus').value || '0';

        if (!name || !role_id || !day_off || !start_date || !salary) {
            alert('Please fill in all required fields.');
            return false;
        }

        fetch('/oro-store/manager/manager_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=add_employee&name=${encodeURIComponent(name)}&role_id=${role_id}&day_off=${day_off}&start_date=${start_date}&daily_salary=${salary}&year_end_bonus=${bonus}`
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('Employee added successfully!');
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Failed to add employee'));
            }
        })
        .catch(() => alert('Network error. Please try again.'));
        return false;
    }

    function changeWeek(direction) {
        const current = new Date(weekStartStr);
        current.setDate(current.getDate() + (direction * 7));
        const y = current.getFullYear();
        const m = String(current.getMonth() + 1).padStart(2, '0');
        const d = String(current.getDate()).padStart(2, '0');
        window.location.href = '/oro-store/manager/manager_attendance.php?week=' + y + '-' + m + '-' + d;
    }
</script>
</body>
</html>