let currentDate = new Date();
let selectedDate = new Date();
let currentWeekStart = null;
let isNavigating = false; // Prevent multiple navigations

// Initialize payroll with week start date from PHP
function initializePayroll(weekStartStr) {
    currentWeekStart = new Date(weekStartStr + 'T00:00:00');
    selectedDate = new Date(); // Set to today initially
    renderCalendar();
    
    // Add click handlers to week calendar days
    attachWeekDayListeners();
    
    console.log('Payroll initialized for week:', weekStartStr);
}

// Attach click listeners to week calendar days
function attachWeekDayListeners() {
    document.querySelectorAll('.week-day').forEach(day => {
        day.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const dateStr = this.getAttribute('data-date');
            if (dateStr) {
                selectDateFromString(dateStr);
            }
        });
    });
}

// Get Monday of the week for a given date
function getMonday(date) {
    const d = new Date(date);
    const day = d.getDay();
    const diff = d.getDate() - day + (day === 0 ? -6 : 1);
    return new Date(d.setDate(diff));
}

// Navigate to previous/next week - SIMPLE VERSION
function changeWeek(delta) {
    if (isNavigating) return; // Prevent double-clicks
    isNavigating = true;
    
    const newWeekStart = new Date(currentWeekStart);
    newWeekStart.setDate(newWeekStart.getDate() + (delta * 7));
    
    const weekStartStr = formatDateForSQL(newWeekStart);
    console.log('Navigating to week:', weekStartStr);
    
    // Reload page with new week
    window.location.href = '/oro-store-demo/payroll/payroll.php?week=' + weekStartStr;
}

// Format date for SQL (YYYY-MM-DD)
function formatDateForSQL(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// Format date for display (Mon DD, YYYY)
function formatDateForDisplay(date) {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return months[date.getMonth()] + ' ' + date.getDate() + ', ' + date.getFullYear();
}

// Initialize calendar
function renderCalendar() {
    const year = currentDate.getFullYear();
    const month = currentDate.getMonth();
    
    // Update month display
    const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                      'July', 'August', 'September', 'October', 'November', 'December'];
    const monthDisplay = document.getElementById('calendar-month');
    if (monthDisplay) {
        monthDisplay.textContent = monthNames[month] + ' ' + year;
    }
    
    // Get first day of month and number of days
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrevMonth = new Date(year, month, 0).getDate();
    
    // Build calendar grid
    const grid = document.getElementById('calendar-grid');
    if (!grid) return;
    
    grid.innerHTML = '';
    
    // Day headers
    const dayHeaders = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
    dayHeaders.forEach(day => {
        const header = document.createElement('div');
        header.className = 'calendar-day-header';
        header.textContent = day;
        grid.appendChild(header);
    });
    
    // Previous month days
    for (let i = firstDay - 1; i >= 0; i--) {
        const day = document.createElement('div');
        day.className = 'calendar-day other-month';
        day.textContent = daysInPrevMonth - i;
        
        const prevMonth = month === 0 ? 11 : month - 1;
        const prevYear = month === 0 ? year - 1 : year;
        day.onclick = () => selectDate(prevYear, prevMonth, daysInPrevMonth - i);
        
        grid.appendChild(day);
    }
    
    // Current month days
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    for (let i = 1; i <= daysInMonth; i++) {
        const day = document.createElement('div');
        day.className = 'calendar-day';
        day.textContent = i;
        
        const dateStr = new Date(year, month, i);
        dateStr.setHours(0, 0, 0, 0);
        
        // Check if today
        if (dateStr.getTime() === today.getTime()) {
            day.classList.add('today');
        }
        
        // Check if selected
        const selectedDateCopy = new Date(selectedDate);
        selectedDateCopy.setHours(0, 0, 0, 0);
        if (dateStr.getTime() === selectedDateCopy.getTime()) {
            day.classList.add('selected');
        }
        
        day.onclick = () => selectDate(year, month, i);
        grid.appendChild(day);
    }
    
    // Next month days
    const remainingCells = 42 - (firstDay + daysInMonth);
    for (let i = 1; i <= remainingCells; i++) {
        const day = document.createElement('div');
        day.className = 'calendar-day other-month';
        day.textContent = i;
        
        const nextMonth = month === 11 ? 0 : month + 1;
        const nextYear = month === 11 ? year + 1 : year;
        day.onclick = () => selectDate(nextYear, nextMonth, i);
        
        grid.appendChild(day);
    }
}

function changeMonth(delta) {
    currentDate.setMonth(currentDate.getMonth() + delta);
    renderCalendar();
}

function selectDate(year, month, day) {
    if (isNavigating) return; // Prevent during navigation
    
    selectedDate = new Date(year, month, day);
    selectedDate.setHours(0, 0, 0, 0);
    
    // Update current date if selecting from different month
    if (month !== currentDate.getMonth() || year !== currentDate.getFullYear()) {
        currentDate = new Date(year, month, day);
    }
    
    // Check if selected date is in current week view
    const selectedMonday = getMonday(selectedDate);
    const currentMonday = new Date(currentWeekStart);
    currentMonday.setHours(0, 0, 0, 0);
    selectedMonday.setHours(0, 0, 0, 0);
    
    // If selected date is in a different week, reload page with that week
    if (selectedMonday.getTime() !== currentMonday.getTime()) {
        isNavigating = true;
        const weekStartStr = formatDateForSQL(selectedMonday);
        console.log('Switching to week:', weekStartStr);
        window.location.href = '/oro-store-demo/payroll/payroll.php?week=' + weekStartStr;
        return;
    }
    
    renderCalendar();
    updateSelectedDateDisplay();
    highlightWeekCalendarDay();
}

function selectDateFromString(dateStr) {
    const date = new Date(dateStr + 'T00:00:00');
    selectedDate = date;
    currentDate = new Date(date);
    
    renderCalendar();
    updateSelectedDateDisplay();
    highlightWeekCalendarDay();
}

function updateSelectedDateDisplay() {
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const dateDisplay = months[selectedDate.getMonth()] + ' ' + selectedDate.getDate() + ', ' + selectedDate.getFullYear();
    const displayElement = document.getElementById('selected-date-display');
    if (displayElement) {
        displayElement.textContent = dateDisplay;
    }
}

function highlightWeekCalendarDay() {
    // Remove previous highlights
    document.querySelectorAll('.week-day.selected-day').forEach(el => {
        el.classList.remove('selected-day');
    });
    
    // Add highlight to matching date
    const selectedDateStr = formatDateForSQL(selectedDate);
    document.querySelectorAll('.week-day[data-date]').forEach(day => {
        if (day.getAttribute('data-date') === selectedDateStr) {
            day.classList.add('selected-day');
        }
    });
}

// Create new role
function createNewRole() {
    const roleName = document.getElementById('new-role-name').value.trim();
    
    if (!roleName) {
        alert('Please enter a role name');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add_role');
    formData.append('role_name', roleName);
    
    fetch('/oro-store-demo/payroll/payroll.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Add to both dropdowns
            const option1 = new Option(data.role_name, data.role_id);
            const option2 = new Option(data.role_name, data.role_id);
            document.getElementById('employee-role').add(option1);
            document.getElementById('edit-employee-role').add(option2);
            document.getElementById('employee-role').value = data.role_id;
            document.getElementById('new-role-name').value = '';
            alert('✓ Role created successfully!');
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// Add employee
document.getElementById('add-employee-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'add_employee');
    formData.append('name', document.getElementById('employee-name').value);
    formData.append('role_id', document.getElementById('employee-role').value);
    formData.append('store_id', document.getElementById('employee-store').value);
    formData.append('start_date', document.getElementById('employee-start-date').value);
    formData.append('day_off', document.getElementById('employee-day-off').value);
    formData.append('daily_salary', document.getElementById('employee-salary').value);
    formData.append('year_end_bonus', document.getElementById('employee-bonus').value);
    
    fetch('/oro-store-demo/payroll/payroll.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Employee added successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
});

// Update bonus deduction
document.getElementById('bonus-deduction-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const deduction = document.getElementById('bonus-deduction-amount').value;
    
    if (!confirm(`Set bonus deduction to ₱${parseFloat(deduction).toFixed(2)} per absence?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_bonus_deduction');
    formData.append('deduction', deduction);
    
    fetch('/oro-store-demo/payroll/payroll.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Bonus deduction setting updated successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
});

// Mark attendance - use selected date
function markAttendance(employeeId, type) {
    const dateStr = formatDateForSQL(selectedDate);
    
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const dateDisplay = months[selectedDate.getMonth()] + ' ' + selectedDate.getDate() + ', ' + selectedDate.getFullYear();
    
    if (!confirm(`Mark attendance as "${type.replace('_', ' ')}" for ${dateDisplay}?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'mark_attendance');
    formData.append('employee_id', employeeId);
    formData.append('date', dateStr);
    formData.append('attendance_type', type);
    
    fetch('/oro-store-demo/payroll/payroll.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Attendance marked successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// Open edit modal and populate with employee data
function openEditModal(employeeId) {
    const formData = new FormData();
    formData.append('action', 'get_employee');
    formData.append('employee_id', employeeId);
    
    fetch('/oro-store-demo/payroll/payroll.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.employee) {
            const emp = data.employee;
            
            document.getElementById('edit-employee-id').value = emp.id;
            document.getElementById('edit-employee-name').value = emp.name;
            document.getElementById('edit-employee-role').value = emp.role_id;
            document.getElementById('edit-employee-store').value = emp.store_id || '';
            document.getElementById('edit-employee-start-date').value = emp.start_date;
            document.getElementById('edit-employee-day-off').value = emp.day_off;
            document.getElementById('edit-employee-salary').value = emp.daily_salary;
            document.getElementById('edit-employee-bonus').value = emp.year_end_bonus || 0;
            
            document.getElementById('edit-modal').classList.add('active');
        } else {
            alert('Error: Could not load employee data');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error: Could not load employee data');
    });
}

function closeEditModal() {
    document.getElementById('edit-modal').classList.remove('active');
}

document.getElementById('edit-employee-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'update_employee');
    formData.append('employee_id', document.getElementById('edit-employee-id').value);
    formData.append('name', document.getElementById('edit-employee-name').value);
    formData.append('role_id', document.getElementById('edit-employee-role').value);
    formData.append('store_id', document.getElementById('edit-employee-store').value);
    formData.append('start_date', document.getElementById('edit-employee-start-date').value);
    formData.append('day_off', document.getElementById('edit-employee-day-off').value);
    formData.append('daily_salary', document.getElementById('edit-employee-salary').value);
    formData.append('year_end_bonus', document.getElementById('edit-employee-bonus').value);
    
    fetch('/oro-store-demo/payroll/payroll.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Employee updated successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
});

function openCashAdvanceModal(employeeId, employeeName) {
    document.getElementById('ca-employee-id').value = employeeId;
    document.getElementById('ca-employee-name').textContent = employeeName;
    document.getElementById('ca-modal').classList.add('active');
}

function closeCashAdvanceModal() {
    document.getElementById('ca-modal').classList.remove('active');
    document.getElementById('cash-advance-form').reset();
}

document.getElementById('cash-advance-form').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData();
    formData.append('action', 'add_cash_advance');
    formData.append('employee_id', document.getElementById('ca-employee-id').value);
    formData.append('amount', document.getElementById('ca-amount').value);
    formData.append('week_start', document.getElementById('ca-week-start').value);
    formData.append('notes', document.getElementById('ca-notes').value);
    
    fetch('/oro-store-demo/payroll/payroll.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Cash advance recorded successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
});

function removeEmployee(employeeId, employeeName) {
    if (!confirm(`Remove ${employeeName} from active employees?\n\nThis will mark them as inactive.`)) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'remove_employee');
    formData.append('employee_id', employeeId);

    fetch('/oro-store-demo/payroll/payroll.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✓ Employee removed successfully!');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}

// Time In — marks attendance as whole_day and records current time as time_in
function timeIn(employeeId) {
    const dateStr = formatDateForSQL(selectedDate);
    const now = new Date();
    const timeStr = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');

    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const dateDisplay = months[selectedDate.getMonth()] + ' ' + selectedDate.getDate() + ', ' + selectedDate.getFullYear();

    if (!confirm(`Time In for ${dateDisplay} at ${timeStr}?`)) return;

    // First mark attendance as whole_day
    const fd1 = new FormData();
    fd1.append('action', 'mark_attendance');
    fd1.append('employee_id', employeeId);
    fd1.append('date', dateStr);
    fd1.append('attendance_type', 'whole_day');

    fetch('/oro-store-demo/payroll/payroll.php', { method: 'POST', body: fd1 })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert('Error: ' + (d.error || 'Failed')); return; }
            // Then save time_in
            const fd2 = new FormData();
            fd2.append('action', 'update_time');
            fd2.append('employee_id', employeeId);
            fd2.append('date', dateStr);
            fd2.append('time_in', timeStr);
            fd2.append('time_out', '');
            return fetch('/oro-store-demo/payroll/payroll.php', { method: 'POST', body: fd2 });
        })
        .then(r => r ? r.json() : null)
        .then(d => {
            if (d && d.success) location.reload();
            else if (d) alert('Error saving time: ' + (d.error || 'Failed'));
        });
}

// Time Out — records current time as time_out (keeps existing time_in)
function timeOut(employeeId) {
    const dateStr = formatDateForSQL(selectedDate);
    const now = new Date();
    const timeStr = String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');

    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const dateDisplay = months[selectedDate.getMonth()] + ' ' + selectedDate.getDate() + ', ' + selectedDate.getFullYear();

    if (!confirm(`Time Out for ${dateDisplay} at ${timeStr}?`)) return;

    // Find the existing time_in for this employee on this date from the DOM
    let existingIn = '';
    document.querySelectorAll(`.week-day[data-emp="${employeeId}"][data-date="${dateStr}"]`).forEach(el => {
        existingIn = el.dataset.tin || '';
    });

    // If no attendance record yet, mark as whole_day first
    const fd1 = new FormData();
    fd1.append('action', 'mark_attendance');
    fd1.append('employee_id', employeeId);
    fd1.append('date', dateStr);
    fd1.append('attendance_type', 'whole_day');

    fetch('/oro-store-demo/payroll/payroll.php', { method: 'POST', body: fd1 })
        .then(r => r.json())
        .then(d => {
            if (!d.success) { alert('Error: ' + (d.error || 'Failed')); return; }
            const fd2 = new FormData();
            fd2.append('action', 'update_time');
            fd2.append('employee_id', employeeId);
            fd2.append('date', dateStr);
            fd2.append('time_in', existingIn);
            fd2.append('time_out', timeStr);
            return fetch('/oro-store-demo/payroll/payroll.php', { method: 'POST', body: fd2 });
        })
        .then(r => r ? r.json() : null)
        .then(d => {
            if (d && d.success) location.reload();
            else if (d) alert('Error saving time: ' + (d.error || 'Failed'));
        });
}

// Overtime settings form
const otForm = document.getElementById('overtime-settings-form');
if (otForm) {
    otForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const workHours = document.getElementById('ot-work-hours').value;
        const otPay = document.getElementById('ot-pay-rate').value;

        if (!confirm(`Set standard hours to ${workHours}h and overtime pay to ₱${parseFloat(otPay).toFixed(2)}/hr?`)) return;

        const formData = new FormData();
        formData.append('action', 'update_overtime_settings');
        formData.append('work_hours', workHours);
        formData.append('overtime_pay', otPay);

        fetch('/oro-store-demo/payroll/payroll.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(d => {
                if (d.success) { alert('✓ Overtime settings updated!'); location.reload(); }
                else alert('Error: ' + d.error);
            });
    });
}

// Time editing - click on week-day to edit time in/out
document.querySelectorAll('.week-day[data-emp]').forEach(day => {
    day.addEventListener('dblclick', function(e) {
        e.stopPropagation();
        const empId = this.dataset.emp;
        const date = this.dataset.date;
        const currentIn = this.dataset.tin || '';
        const currentOut = this.dataset.tout || '';

        // Check if it's a past/current date with attendance
        if (this.classList.contains('upcoming')) return;

        const timeIn = prompt('Time In (HH:MM, 24hr format):', currentIn ? currentIn.substring(0,5) : '08:00');
        if (timeIn === null) return;

        const timeOut = prompt('Time Out (HH:MM, 24hr format):', currentOut ? currentOut.substring(0,5) : '17:00');
        if (timeOut === null) return;

        const formData = new FormData();
        formData.append('action', 'update_time');
        formData.append('employee_id', empId);
        formData.append('date', date);
        formData.append('time_in', timeIn || '');
        formData.append('time_out', timeOut || '');

        fetch('/oro-store-demo/payroll/payroll.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(d => {
                if (d.success) location.reload();
                else alert('Error: ' + (d.error || 'Failed to save time'));
            });
    });
});