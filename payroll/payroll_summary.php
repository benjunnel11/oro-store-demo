<?php
require_once __DIR__ . '/../core/db_connection.php';
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/system_logger.php';

if (!isAdmin()) {
    header("Location: /oro-store/cashier/cashier.php");
    exit;
}

$currentUser = getCurrentUser();

// ─── DATE BOUNDARIES ────────────────────────────────────────────
$today          = new DateTime();
$weekStart      = clone $today;
$weekStart->modify('monday this week');
$weekEnd        = clone $weekStart;
$weekEnd->modify('+6 days');

$monthStart     = (new DateTime())->modify('first day of this month');
$monthEnd       = (new DateTime())->modify('last day of this month');

$yearStart      = new DateTime('first day of january this year');
$yearEnd        = new DateTime('last day of december this year');

$ws = $weekStart->format('Y-m-d');
$we = $weekEnd->format('Y-m-d');
$ms = $monthStart->format('Y-m-d');
$me = $monthEnd->format('Y-m-d');
$ys = $yearStart->format('Y-m-d');
$ye = $yearEnd->format('Y-m-d');

// Overtime settings
$ot_h_r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'standard_work_hours'");
$standard_work_hours = ($ot_h_r && $ot_h_r->num_rows > 0) ? floatval($ot_h_r->fetch_assoc()['setting_value']) : 8;
$ot_p_r = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'overtime_pay_rate'");
$overtime_pay_rate = ($ot_p_r && $ot_p_r->num_rows > 0) ? floatval($ot_p_r->fetch_assoc()['setting_value']) : 0;

// ─── REVENUE QUERIES (mirrors admin_panel logic exactly) ────────
// Weekly revenue
$row = $conn->query("
    SELECT COALESCE(SUM(total_amount),0) as total
    FROM transactions
    WHERE transaction_date BETWEEN '$ws' AND '$we 23:59:59'
      AND status IN ('completed','pending')
      AND payment_method NOT IN ('cash_return_to_register','atm_cash_withdrawal')
")->fetch_assoc();
$weekly_revenue = floatval($row['total']);

// Monthly revenue
$row = $conn->query("
    SELECT COALESCE(SUM(total_amount),0) as total
    FROM transactions
    WHERE transaction_date BETWEEN '$ms' AND '$me 23:59:59'
      AND status IN ('completed','pending')
      AND payment_method NOT IN ('cash_return_to_register','atm_cash_withdrawal')
")->fetch_assoc();
$monthly_revenue = floatval($row['total']);

// Annual revenue
$row = $conn->query("
    SELECT COALESCE(SUM(total_amount),0) as total
    FROM transactions
    WHERE transaction_date BETWEEN '$ys' AND '$ye 23:59:59'
      AND status IN ('completed','pending')
      AND payment_method NOT IN ('cash_return_to_register','atm_cash_withdrawal')
")->fetch_assoc();
$annual_revenue = floatval($row['total']);

// ─── PAYROLL QUERIES ────────────────────────────────────────────
// Fetch ALL active employees with role + store
$employees = $conn->query("
    SELECT e.*, r.role_name, s.store_name, s.store_code
    FROM employees e
    LEFT JOIN employee_roles r ON e.role_id = r.id
    LEFT JOIN stores s ON e.store_id = s.id
    WHERE e.status = 'active'
    ORDER BY e.name
")->fetch_all(MYSQLI_ASSOC);

// Fetch attendance for WEEK
$week_att = $conn->query("
    SELECT * FROM employee_attendance
    WHERE attendance_date BETWEEN '$ws' AND '$we'
")->fetch_all(MYSQLI_ASSOC);
$week_att_map = [];
foreach ($week_att as $a) {
    $week_att_map[$a['employee_id']][$a['attendance_date']] = $a;
}

// Fetch attendance for MONTH
$month_att = $conn->query("
    SELECT * FROM employee_attendance
    WHERE attendance_date BETWEEN '$ms' AND '$me'
")->fetch_all(MYSQLI_ASSOC);
$month_att_map = [];
foreach ($month_att as $a) {
    $month_att_map[$a['employee_id']][$a['attendance_date']] = $a;
}

// Fetch attendance for YEAR
$year_att = $conn->query("
    SELECT * FROM employee_attendance
    WHERE attendance_date BETWEEN '$ys' AND '$ye'
")->fetch_all(MYSQLI_ASSOC);
$year_att_map = [];
foreach ($year_att as $a) {
    $year_att_map[$a['employee_id']][$a['attendance_date']] = $a;
}

// Cash advances this month (for net salary)
$ca_month = $conn->query("
    SELECT employee_id, SUM(amount) as total
    FROM employee_cash_advances
    WHERE week_start_date BETWEEN '$ms' AND '$me'
    GROUP BY employee_id
")->fetch_all(MYSQLI_ASSOC);
$ca_month_map = [];
foreach ($ca_month as $c) { $ca_month_map[$c['employee_id']] = floatval($c['total']); }

// ─── HELPER: hours-based salary calculation with overtime ───────
function calcPay(array $attMap, float $dailySalary, string $startDate, string $endDate, float $stdHours = 8, float $otRate = 0): array {
    $days = 0; $absent = 0; $std_h = 0; $ot_h = 0; $base = 0;
    $hourly = $stdHours > 0 ? $dailySalary / $stdHours : 0;
    $cur = new DateTime($startDate);
    $end = new DateTime($endDate);
    while ($cur <= $end) {
        $ds = $cur->format('Y-m-d');
        $att = $attMap[$ds] ?? null;
        $type = is_array($att) ? ($att['attendance_type'] ?? null) : $att;
        if ($type === 'whole_day' || $type === 'half_day') {
            $days++;
            $ti = is_array($att) ? ($att['time_in'] ?? '') : '';
            $to = is_array($att) ? ($att['time_out'] ?? '') : '';
            if ($ti && $to) {
                $diff = (new DateTime($ti))->diff(new DateTime($to));
                $h = $diff->h + ($diff->i / 60);
                $std_h += min($h, $stdHours);
                $ot_h += max(0, $h - $stdHours);
                $base += $h >= $stdHours ? $dailySalary : $h * $hourly;
            } else {
                $std_h += $stdHours;
                $base += $dailySalary;
            }
        } elseif ($type === 'absent') {
            $absent++;
        }
        $cur->modify('+1 day');
    }
    $ot_pay = $ot_h * $otRate;
    return ['days' => $days, 'absent' => $absent, 'std_h' => $std_h, 'ot_h' => $ot_h, 'base' => $base, 'ot_pay' => $ot_pay, 'pay' => $base + $ot_pay];
}

// ─── BUILD PER-EMPLOYEE ROWS ────────────────────────────────────
$total_weekly_payroll  = 0;
$total_monthly_payroll = 0;
$total_annual_payroll  = 0;
$total_ca_month        = 0;

// Attendance totals for the summary pills
$total_worked_month  = 0;
$total_absent_month  = 0;

$employee_rows = [];
foreach ($employees as $emp) {
    $id   = $emp['id'];
    $rate = floatval($emp['daily_salary']);

    $w = calcPay($week_att_map[$id]  ?? [], $rate, $ws, $we, $standard_work_hours, $overtime_pay_rate);
    $m = calcPay($month_att_map[$id] ?? [], $rate, $ms, $me, $standard_work_hours, $overtime_pay_rate);
    $y = calcPay($year_att_map[$id]  ?? [], $rate, $ys, $ye, $standard_work_hours, $overtime_pay_rate);

    $ca = $ca_month_map[$id] ?? 0;

    $total_weekly_payroll  += $w['pay'];
    $total_monthly_payroll += $m['pay'];
    $total_annual_payroll  += $y['pay'];
    $total_ca_month        += $ca;

    $total_worked_month  += $m['days'];
    $total_absent_month  += $m['absent'];

    $employee_rows[] = [
        'id'         => $id,
        'name'       => $emp['name'],
        'role'       => $emp['role_name'] ?? '—',
        'store'      => $emp['store_code'] ?? '—',
        'daily_rate' => $rate,
        'week_days'  => $w['days'],
        'week_std_h' => $w['std_h'],
        'week_ot_h'  => $w['ot_h'],
        'week_pay'   => $w['pay'],
        'month_pay'  => $m['pay'],
        'year_pay'   => $y['pay'],
        'ca_month'   => $ca,
        'net_month'  => $m['pay'] - $ca,
    ];
}

// ─── MONTHLY BREAKDOWN FOR EACH EMPLOYEE (Jan-Dec + Total) ─────
$current_year = date('Y');
$monthly_breakdown = [];
$monthly_totals = array_fill(1, 13, 0); // months 1-12 + total

foreach ($employees as $emp) {
    $id = $emp['id'];
    $rate = floatval($emp['daily_salary']);
    $monthly_salaries = [];
    $annual_total = 0;
    
    for ($month = 1; $month <= 12; $month++) {
        // Get start and end of this month
        $month_start = new DateTime("$current_year-$month-01");
        $month_end = clone $month_start;
        $month_end->modify('last day of this month');
        
        $m_start = $month_start->format('Y-m-d');
        $m_end = $month_end->format('Y-m-d');
        
        // Get attendance for this month
        $att_query = $conn->query("
            SELECT * FROM employee_attendance
            WHERE employee_id = $id
            AND attendance_date BETWEEN '$m_start' AND '$m_end'
        ")->fetch_all(MYSQLI_ASSOC);
        
        $att_map = [];
        foreach ($att_query as $a) {
            $att_map[$a['attendance_date']] = $a;
        }

        $month_calc = calcPay($att_map, $rate, $m_start, $m_end, $standard_work_hours, $overtime_pay_rate);
        $monthly_salaries[$month] = $month_calc['pay'];
        $annual_total += $month_calc['pay'];
        $monthly_totals[$month] += $month_calc['pay'];
    }
    
    $monthly_salaries[13] = $annual_total; // Total column
    $monthly_totals[13] += $annual_total;
    
    $monthly_breakdown[] = [
        'name' => $emp['name'],
        'salaries' => $monthly_salaries
    ];
}

// ─── NET PROFIT / LOSS (Revenue - Payroll) ─────────────────────
$weekly_net  = $weekly_revenue  - $total_weekly_payroll;
$monthly_net = $monthly_revenue - $total_monthly_payroll;
$annual_net  = $annual_revenue  - $total_annual_payroll;

// ─── PER-STORE BREAKDOWN ────────────────────────────────────────
$stores = $conn->query("SELECT * FROM stores WHERE status='active' ORDER BY store_name")->fetch_all(MYSQLI_ASSOC);

$store_summary = [];
foreach ($stores as $store) {
    $sid = $store['id'];

    // Revenue this month for this store  (transactions are tied to store via users or store_id)
    // Using store_id column on transactions if exists, else fallback to user->store join
    $rev = $conn->query("
        SELECT COALESCE(SUM(t.total_amount),0) as total
        FROM transactions t
        WHERE t.transaction_date BETWEEN '$ms' AND '$me 23:59:59'
          AND t.status IN ('completed','pending')
          AND t.payment_method NOT IN ('cash_return_to_register','atm_cash_withdrawal')
          AND t.store_id = $sid
    ")->fetch_assoc();
    $store_revenue = floatval($rev['total']);

    // Payroll cost for employees assigned to this store this month
    $store_payroll = 0;
    $store_emp_count = 0;
    foreach ($employees as $emp) {
        if ((int)$emp['store_id'] === $sid) {
            $store_emp_count++;
            $m = calcPay($month_att_map[$emp['id']] ?? [], floatval($emp['daily_salary']), $ms, $me, $standard_work_hours, $overtime_pay_rate);
            $store_payroll += $m['pay'];
        }
    }

    $store_summary[] = [
        'name'       => $store['store_name'],
        'code'       => $store['store_code'],
        'revenue'    => $store_revenue,
        'payroll'    => $store_payroll,
        'profit'     => $store_revenue - $store_payroll,
        'emp_count'  => $store_emp_count,
    ];
}

// ─── LAST 30 DAYS: daily revenue + daily payroll for chart ─────
$chart_revenue = [];
$chart_payroll = [];
$chart_labels  = [];

for ($i = 29; $i >= 0; $i--) {
    $d = (new DateTime())->modify("-$i days");
    $ds = $d->format('Y-m-d');
    $chart_labels[] = $d->format('M j');

    // daily revenue
    $r = $conn->query("
        SELECT COALESCE(SUM(total_amount),0) as total
        FROM transactions
        WHERE DATE(transaction_date) = '$ds'
          AND status IN ('completed','pending')
          AND payment_method NOT IN ('cash_return_to_register','atm_cash_withdrawal')
    ")->fetch_assoc();
    $chart_revenue[] = floatval($r['total']);

    // daily payroll: sum pay for each employee who worked that day
    $dayPay = 0;
    foreach ($employees as $emp) {
        $att = $conn->query("
            SELECT attendance_type, time_in, time_out FROM employee_attendance
            WHERE employee_id = {$emp['id']} AND attendance_date = '$ds'
        ")->fetch_assoc();
        if ($att && ($att['attendance_type'] === 'whole_day' || $att['attendance_type'] === 'half_day')) {
            $rate = floatval($emp['daily_salary']);
            $hourly = $standard_work_hours > 0 ? $rate / $standard_work_hours : 0;
            if ($att['time_in'] && $att['time_out']) {
                $diff = (new DateTime($att['time_in']))->diff(new DateTime($att['time_out']));
                $h = $diff->h + ($diff->i / 60);
                $dayPay += $h >= $standard_work_hours ? $rate : $h * $hourly;
                $dayPay += max(0, $h - $standard_work_hours) * $overtime_pay_rate;
            } else {
                $dayPay += $rate;
            }
        }
    }
    $chart_payroll[] = $dayPay;
}

// ─── EMPLOYEES UNASSIGNED TO ANY STORE (for the "No Store" bucket) ─
$unassigned_payroll = 0;
$unassigned_count   = 0;
foreach ($employees as $emp) {
    if (empty($emp['store_id'])) {
        $unassigned_count++;
        $m = calcPay($month_att_map[$emp['id']] ?? [], floatval($emp['daily_salary']), $ms, $me, $standard_work_hours, $overtime_pay_rate);
        $unassigned_payroll += $m['pay'];
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Payroll Summary – Oro Store</title>
    <link rel="stylesheet" href="/oro-store/admin/admin_layout.css">
    <link rel="stylesheet" href="/oro-store/payroll/payroll_summary_styles.css">
</head>
<body>

<?php include_once __DIR__ . '/../admin/admin_sidebar.php'; ?>

<main class="main-content">

<!-- ═══════════════════════════════════════════════════════════════
     TOP KPI CARDS  –  Revenue vs Payroll = Net
     ═══════════════════════════════════════════════════════════ -->
<div class="kpi-row">
    <!-- WEEKLY -->
    <div class="kpi-card <?php echo $weekly_net >= 0 ? 'kpi-gain' : 'kpi-loss'; ?>">
        <div class="kpi-label">📅 This Week</div>
        <div class="kpi-net <?php echo $weekly_net >= 0 ? 'gain' : 'loss'; ?>">
            <?php echo $weekly_net >= 0 ? '+' : ''; ?>₱<?php echo number_format($weekly_net, 2); ?>
            <span class="kpi-badge"><?php echo $weekly_net >= 0 ? 'PROFIT' : 'LOSS'; ?></span>
        </div>
        <div class="kpi-sub-row">
            <div class="kpi-sub"><span class="kpi-sub-label">Revenue</span><span class="kpi-sub-val green">₱<?php echo number_format($weekly_revenue, 2); ?></span></div>
            <div class="kpi-sub"><span class="kpi-sub-label">Payroll</span><span class="kpi-sub-val red">₱<?php echo number_format($total_weekly_payroll, 2); ?></span></div>
        </div>
        <div class="kpi-footer">
            <?php
            $pct = $weekly_revenue > 0 ? ($total_weekly_payroll / $weekly_revenue) * 100 : 0;
            ?>
            Payroll is <strong><?php echo number_format($pct, 1); ?>%</strong> of revenue
        </div>
    </div>

    <!-- MONTHLY -->
    <div class="kpi-card <?php echo $monthly_net >= 0 ? 'kpi-gain' : 'kpi-loss'; ?>">
        <div class="kpi-label">📆 This Month</div>
        <div class="kpi-net <?php echo $monthly_net >= 0 ? 'gain' : 'loss'; ?>">
            <?php echo $monthly_net >= 0 ? '+' : ''; ?>₱<?php echo number_format($monthly_net, 2); ?>
            <span class="kpi-badge"><?php echo $monthly_net >= 0 ? 'PROFIT' : 'LOSS'; ?></span>
        </div>
        <div class="kpi-sub-row">
            <div class="kpi-sub"><span class="kpi-sub-label">Revenue</span><span class="kpi-sub-val green">₱<?php echo number_format($monthly_revenue, 2); ?></span></div>
            <div class="kpi-sub"><span class="kpi-sub-label">Payroll</span><span class="kpi-sub-val red">₱<?php echo number_format($total_monthly_payroll, 2); ?></span></div>
        </div>
        <div class="kpi-footer">
            <?php
            $pct = $monthly_revenue > 0 ? ($total_monthly_payroll / $monthly_revenue) * 100 : 0;
            ?>
            Payroll is <strong><?php echo number_format($pct, 1); ?>%</strong> of revenue
        </div>
    </div>

    <!-- ANNUAL -->
    <div class="kpi-card <?php echo $annual_net >= 0 ? 'kpi-gain' : 'kpi-loss'; ?>">
        <div class="kpi-label">📈 This Year</div>
        <div class="kpi-net <?php echo $annual_net >= 0 ? 'gain' : 'loss'; ?>">
            <?php echo $annual_net >= 0 ? '+' : ''; ?>₱<?php echo number_format($annual_net, 2); ?>
            <span class="kpi-badge"><?php echo $annual_net >= 0 ? 'PROFIT' : 'LOSS'; ?></span>
        </div>
        <div class="kpi-sub-row">
            <div class="kpi-sub"><span class="kpi-sub-label">Revenue</span><span class="kpi-sub-val green">₱<?php echo number_format($annual_revenue, 2); ?></span></div>
            <div class="kpi-sub"><span class="kpi-sub-label">Payroll</span><span class="kpi-sub-val red">₱<?php echo number_format($total_annual_payroll, 2); ?></span></div>
        </div>
        <div class="kpi-footer">
            <?php
            $pct = $annual_revenue > 0 ? ($total_annual_payroll / $annual_revenue) * 100 : 0;
            ?>
            Payroll is <strong><?php echo number_format($pct, 1); ?>%</strong> of revenue
        </div>
    </div>

    <!-- WORKFORCE SNAPSHOT -->
    <div class="kpi-card kpi-neutral">
        <div class="kpi-label">👥 Workforce</div>
        <div class="kpi-net neutral">
            <?php echo count($employees); ?>
            <span class="kpi-badge neutral">EMPLOYEES</span>
        </div>
        <div class="kpi-sub-row">
            <div class="kpi-sub"><span class="kpi-sub-label">Avg Daily</span><span class="kpi-sub-val">₱<?php echo count($employees) > 0 ? number_format(array_sum(array_column($employees,'daily_salary')) / count($employees), 2) : '0.00'; ?></span></div>
            <div class="kpi-sub"><span class="kpi-sub-label">CA (month)</span><span class="kpi-sub-val red">₱<?php echo number_format($total_ca_month, 2); ?></span></div>
        </div>
        <div class="kpi-footer">
            <?php echo count($stores); ?> active store<?php echo count($stores) !== 1 ? 's' : ''; ?> &nbsp;|&nbsp; <?php echo $unassigned_count; ?> unassigned
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     ATTENDANCE PILLS  (this month quick stats)
     ═══════════════════════════════════════════════════════════ -->
<div class="pills-row">
    <div class="pill pill-present">✓ Days Worked <strong><?php echo $total_worked_month; ?></strong></div>
    <div class="pill pill-absent">✗ Absent <strong><?php echo $total_absent_month; ?></strong> days</div>
    <div class="pill pill-ca">💵 Cash Advances <strong>₱<?php echo number_format($total_ca_month, 2); ?></strong></div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EMPLOYEE BREAKDOWN TABLE
     ═══════════════════════════════════════════════════════════ -->
<div class="summary-panel">
    <div class="panel-header"><h2>👤 Employee Salary Breakdown</h2></div>
    <div class="table-scroll">
        <table class="summary-table">
            <thead>
                <tr>
                    <th class="col-name">Employee</th>
                    <th>Role</th>
                    <th>Store</th>
                    <th class="num">Daily Rate</th>
                    <th class="num">Hours</th>
                    <th class="num">Weekly Pay</th>
                    <th class="num">Monthly Pay</th>
                    <th class="num">Annual Pay</th>
                    <th class="num">CA (Month)</th>
                    <th class="num">Net Monthly</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employee_rows as $er): ?>
                <tr>
                    <td class="col-name"><?php echo htmlspecialchars($er['name']); ?></td>
                    <td><?php echo htmlspecialchars($er['role']); ?></td>
                    <td><?php echo htmlspecialchars($er['store']); ?></td>
                    <td class="num">₱<?php echo number_format($er['daily_rate'],2); ?></td>
                    <td class="num">
                        <?php echo number_format($er['week_std_h'], 0); ?>h
                        <?php if ($er['week_ot_h'] > 0): ?>
                            <span style="color:#f59e0b;font-size:11px;">+<?php echo number_format($er['week_ot_h'], 1); ?>ot</span>
                        <?php endif; ?>
                    </td>
                    <td class="num">₱<?php echo number_format($er['week_pay'],2); ?></td>
                    <td class="num">₱<?php echo number_format($er['month_pay'],2); ?></td>
                    <td class="num">₱<?php echo number_format($er['year_pay'],2); ?></td>
                    <td class="num <?php echo $er['ca_month'] > 0 ? 'red' : ''; ?>">
                        <?php echo $er['ca_month'] > 0 ? '-' : ''; ?>₱<?php echo number_format($er['ca_month'],2); ?>
                    </td>
                    <td class="num <?php echo $er['net_month'] < 0 ? 'red' : 'green'; ?>">
                        ₱<?php echo number_format($er['net_month'],2); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" class="col-name"><strong>TOTALS</strong></td>
                    <td class="num"><strong>₱<?php echo number_format($total_weekly_payroll,2); ?></strong></td>
                    <td class="num"><strong>₱<?php echo number_format($total_monthly_payroll,2); ?></strong></td>
                    <td class="num"><strong>₱<?php echo number_format($total_annual_payroll,2); ?></strong></td>
                    <td class="num red"><strong>₱<?php echo number_format($total_ca_month,2); ?></strong></td>
                    <td class="num <?php echo ($total_monthly_payroll - $total_ca_month) < 0 ? 'red' : 'green'; ?>">
                        <strong>₱<?php echo number_format($total_monthly_payroll - $total_ca_month,2); ?></strong>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     EMPLOYEE MONTHLY BREAKDOWN TABLE
     ═══════════════════════════════════════════════════════════ -->
<div class="summary-panel">
    <div class="panel-header"><h2>📅 Employee Monthly Salary Breakdown (<?php echo $current_year; ?>)</h2></div>
    <div class="table-scroll">
        <table class="summary-table monthly-table">
            <thead>
                <tr>
                    <th class="col-name sticky-col">Employee Name</th>
                    <th class="num">Jan</th>
                    <th class="num">Feb</th>
                    <th class="num">Mar</th>
                    <th class="num">Apr</th>
                    <th class="num">May</th>
                    <th class="num">Jun</th>
                    <th class="num">Jul</th>
                    <th class="num">Aug</th>
                    <th class="num">Sep</th>
                    <th class="num">Oct</th>
                    <th class="num">Nov</th>
                    <th class="num">Dec</th>
                    <th class="num total-col">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthly_breakdown as $emp): ?>
                <tr>
                    <td class="col-name sticky-col"><?php echo htmlspecialchars($emp['name']); ?></td>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <td class="num"><?php echo $emp['salaries'][$m] > 0 ? '₱' . number_format($emp['salaries'][$m], 2) : '—'; ?></td>
                    <?php endfor; ?>
                    <td class="num total-col"><strong>₱<?php echo number_format($emp['salaries'][13], 2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="total-row">
                    <td class="col-name sticky-col"><strong>MONTHLY TOTALS</strong></td>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <td class="num"><strong>₱<?php echo number_format($monthly_totals[$m], 2); ?></strong></td>
                    <?php endfor; ?>
                    <td class="num total-col"><strong>₱<?php echo number_format($monthly_totals[13], 2); ?></strong></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="table-footer-note">
        <strong>Grand Total Annual Payroll:</strong> ₱<?php echo number_format($monthly_totals[13], 2); ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     30-DAY CHART  –  Revenue vs Payroll per day
     ═══════════════════════════════════════════════════════════ -->
<div class="summary-panel">
    <div class="panel-header">
        <h2>📊 Last 30 Days – Daily Revenue vs Payroll</h2>
    </div>
    <div class="chart-wrap">
        <canvas id="chart30" height="260"></canvas>
    </div>
    <div class="chart-legend">
        <div class="legend-item"><span class="legend-dot legend-revenue"></span> Daily Revenue</div>
        <div class="legend-item"><span class="legend-dot legend-payroll"></span> Daily Payroll Cost</div>
        <div class="legend-item"><span class="legend-dot legend-profit"></span> Daily Profit</div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     PER-STORE SUMMARY CARDS
     ═══════════════════════════════════════════════════════════ -->
<div class="summary-panel">
    <div class="panel-header"><h2>🏪 Per-Store Breakdown (This Month)</h2></div>
    <div class="store-cards">
        <?php foreach ($store_summary as $s): ?>
        <div class="store-card <?php echo $s['profit'] >= 0 ? 'store-gain' : 'store-loss'; ?>">
            <div class="store-card-header">
                <span class="store-code-badge"><?php echo htmlspecialchars($s['code']); ?></span>
                <span class="store-emp-badge"><?php echo $s['emp_count']; ?> emp</span>
            </div>
            <div class="store-card-name"><?php echo htmlspecialchars($s['name']); ?></div>
            <div class="store-card-rows">
                <div class="store-row"><span>Revenue</span><span class="green">₱<?php echo number_format($s['revenue'],2); ?></span></div>
                <div class="store-row"><span>Payroll</span><span class="red">₱<?php echo number_format($s['payroll'],2); ?></span></div>
                <div class="store-row store-row-profit">
                    <span>Net</span>
                    <span class="<?php echo $s['profit'] >= 0 ? 'green' : 'red'; ?>">
                        <?php echo $s['profit'] >= 0 ? '+' : ''; ?>₱<?php echo number_format($s['profit'],2); ?>
                    </span>
                </div>
            </div>
            <?php
            $spct = $s['revenue'] > 0 ? ($s['payroll'] / $s['revenue']) * 100 : 0;
            ?>
            <div class="store-bar-bg"><div class="store-bar-fill" style="width:<?php echo min($spct,100); ?>%"></div></div>
            <div class="store-bar-label">Payroll <?php echo number_format($spct,1); ?>% of revenue</div>
        </div>
        <?php endforeach; ?>

        <!-- Unassigned employees bucket -->
        <?php if ($unassigned_count > 0): ?>
        <div class="store-card store-neutral">
            <div class="store-card-header">
                <span class="store-code-badge neutral">—</span>
                <span class="store-emp-badge"><?php echo $unassigned_count; ?> emp</span>
            </div>
            <div class="store-card-name">No Store Assigned</div>
            <div class="store-card-rows">
                <div class="store-row"><span>Revenue</span><span class="green">₱0.00</span></div>
                <div class="store-row"><span>Payroll</span><span class="red">₱<?php echo number_format($unassigned_payroll,2); ?></span></div>
                <div class="store-row store-row-profit">
                    <span>Net</span>
                    <span class="red">-₱<?php echo number_format($unassigned_payroll,2); ?></span>
                </div>
            </div>
            <div class="store-bar-bg"><div class="store-bar-fill" style="width:100%;"></div></div>
            <div class="store-bar-label">100% unlinked cost</div>
        </div>
        <?php endif; ?>
    </div>
</div>

</main>

<!-- ─── CHART JS (vanilla canvas) ─────────────────────────── -->
<script>
(function(){
    const labels   = <?php echo json_encode($chart_labels); ?>;
    const revenue  = <?php echo json_encode($chart_revenue); ?>;
    const payroll  = <?php echo json_encode($chart_payroll); ?>;

    const canvas = document.getElementById('chart30');
    const ctx    = canvas.getContext('2d');

    // Make canvas fill its container
    function resize() {
        canvas.width  = canvas.offsetWidth;
        canvas.height = canvas.offsetHeight;
        draw();
    }

    function draw() {
        const W = canvas.width, H = canvas.height;
        const pad = { top: 20, right: 20, bottom: 40, left: 72 };
        const cW  = W - pad.left - pad.right;
        const cH  = H - pad.top  - pad.bottom;

        ctx.clearRect(0, 0, W, H);

        // Compute max
        let maxVal = 1;
        labels.forEach((_,i) => {
            maxVal = Math.max(maxVal, revenue[i], payroll[i]);
        });
        // Round up to nice number
        const step = Math.pow(10, Math.floor(Math.log10(maxVal)));
        maxVal = Math.ceil(maxVal / step) * step;

        const barW   = Math.max(4, (cW / labels.length) - 6);
        const gapW   = (cW / labels.length) - barW;
        const barW2  = barW / 2 - 1;

        // ── grid lines & Y labels ──
        ctx.strokeStyle = '#e8eaed';
        ctx.lineWidth   = 1;
        ctx.fillStyle   = '#666';
        ctx.font        = '11px sans-serif';
        ctx.textAlign   = 'right';
        const ySteps = 5;
        for (let i = 0; i <= ySteps; i++) {
            const y = pad.top + cH - (cH * i / ySteps);
            const v = (maxVal * i / ySteps);
            ctx.beginPath();
            ctx.moveTo(pad.left, y);
            ctx.lineTo(W - pad.right, y);
            ctx.stroke();
            ctx.fillText('₱' + (v >= 1000 ? (v/1000).toFixed(1) + 'k' : v.toFixed(0)), pad.left - 6, y + 4);
        }

        // ── bars ──
        labels.forEach((label, i) => {
            const x     = pad.left + i * (cW / labels.length);
            const rH    = (revenue[i] / maxVal) * cH;
            const pH    = (payroll[i] / maxVal) * cH;
            const profit= revenue[i] - payroll[i];
            const prH   = Math.abs(profit) / maxVal * cH;

            // Revenue bar
            ctx.fillStyle = '#28a745';
            ctx.fillRect(x + 1, pad.top + cH - rH, barW2, rH);

            // Payroll bar
            ctx.fillStyle = '#dc3545';
            ctx.fillRect(x + barW2 + 2, pad.top + cH - pH, barW2, pH);

            // Profit indicator dot on top of revenue bar
            if (profit !== 0) {
                ctx.fillStyle = profit >= 0 ? '#007bff' : '#ffc107';
                ctx.beginPath();
                ctx.arc(x + barW / 2, pad.top + cH - Math.max(rH, pH) - 5, 3, 0, Math.PI * 2);
                ctx.fill();
            }

            // X label (show every 3rd to avoid overlap)
            if (i % 3 === 0) {
                ctx.fillStyle   = '#666';
                ctx.font        = '10px sans-serif';
                ctx.textAlign   = 'center';
                ctx.fillText(label, x + barW / 2, pad.top + cH + 18);
            }
        });
    }

    window.addEventListener('resize', resize);
    resize();
})();
</script>


</body>
</html>