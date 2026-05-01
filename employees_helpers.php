<?php

if (!defined('EMPLOYEE_ATTENDANCE_GRACE_MINUTES')) {
    define('EMPLOYEE_ATTENDANCE_GRACE_MINUTES', 15);
}

if (!defined('SECONDS_PER_MINUTE')) {
    define('SECONDS_PER_MINUTE', 60);
}

if (!defined('EMPLOYEE_TIME_REFERENCE_DATE')) {
    define('EMPLOYEE_TIME_REFERENCE_DATE', '2000-01-01');
}

function getEmployeeOffDayOptions()
{
    return [
        'saturday'  => 'السبت',
        'sunday'    => 'الأحد',
        'monday'    => 'الاثنين',
        'tuesday'   => 'الثلاثاء',
        'wednesday' => 'الأربعاء',
        'thursday'  => 'الخميس',
        'friday'    => 'الجمعة',
    ];
}

function getEmployeeHourOptions()
{
    $hours = [];
    for ($hour = 1; $hour <= 12; $hour++) {
        $hours[] = str_pad((string)$hour, 2, '0', STR_PAD_LEFT);
    }

    return $hours;
}

function getEmployeeMinuteOptions()
{
    $minutes = [];
    for ($minute = 0; $minute <= 59; $minute++) {
        $minutes[] = str_pad((string)$minute, 2, '0', STR_PAD_LEFT);
    }

    return $minutes;
}

function buildEmployeeTimeValue($hour, $minute, $period)
{
    $hour = trim((string)$hour);
    $minute = trim((string)$minute);
    $period = $period === 'PM' ? 'PM' : 'AM';

    if (!preg_match('/^(0[1-9]|1[0-2])$/', $hour) || !preg_match('/^[0-5][0-9]$/', $minute)) {
        return null;
    }

    $hour24 = (int)$hour;
    if ($period === 'AM') {
        if ($hour24 === 12) {
            $hour24 = 0;
        }
    } elseif ($hour24 !== 12) {
        $hour24 += 12;
    }

    return str_pad((string)$hour24, 2, '0', STR_PAD_LEFT) . ':' . $minute . ':00';
}

function splitEmployeeTimeValue($time)
{
    $parts = explode(':', (string)$time);
    $hour24 = isset($parts[0]) ? (int)$parts[0] : 0;
    $minute = isset($parts[1]) ? str_pad((string)((int)$parts[1]), 2, '0', STR_PAD_LEFT) : '00';
    $period = $hour24 >= 12 ? 'PM' : 'AM';
    $hour12 = $hour24 % 12;
    if ($hour12 === 0) {
        $hour12 = 12;
    }

    return [
        'hour'   => str_pad((string)$hour12, 2, '0', STR_PAD_LEFT),
        'minute' => $minute,
        'period' => $period,
    ];
}

function formatEmployeeTime($time)
{
    $parts = splitEmployeeTimeValue($time);
    return $parts['hour'] . ':' . $parts['minute'] . ' ' . ($parts['period'] === 'AM' ? 'ص' : 'م');
}

function normalizeEmployeeOffDays($days)
{
    $options = getEmployeeOffDayOptions();
    $normalized = [];

    foreach ((array)$days as $day) {
        $day = trim((string)$day);
        if ($day !== '' && isset($options[$day]) && !in_array($day, $normalized, true)) {
            $normalized[] = $day;
        }
    }

    return $normalized;
}

function formatEmployeeOffDays($offDaysText)
{
    $options = getEmployeeOffDayOptions();
    $labels = [];
    foreach (normalizeEmployeeOffDays(explode(',', (string)$offDaysText)) as $day) {
        $labels[] = $options[$day];
    }

    return $labels ? implode(' - ', $labels) : '—';
}

function ensureEmployeesSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employees (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            barcode varchar(100) NOT NULL,
            name varchar(255) NOT NULL,
            phone varchar(50) NOT NULL,
            job_title varchar(255) NOT NULL,
            attendance_time time NOT NULL,
            departure_time time NOT NULL,
            off_days varchar(255) NOT NULL DEFAULT '',
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uniq_employees_barcode (barcode)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $schemaReady = true;
}

function ensureEmployeeAttendanceSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_attendance (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id int(10) UNSIGNED NOT NULL,
            attendance_date date NOT NULL,
            attendance_at datetime DEFAULT NULL,
            departure_at datetime DEFAULT NULL,
            attendance_status varchar(50) DEFAULT NULL,
            departure_status varchar(50) DEFAULT NULL,
            scheduled_attendance_time time NOT NULL,
            scheduled_departure_time time NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uniq_employee_attendance_day (employee_id, attendance_date),
            KEY idx_employee_attendance_date (attendance_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $schemaReady = true;
}

function getEmployeeDayKeyFromDate($date)
{
    $timestamp = strtotime((string)$date);
    if ($timestamp === false) {
        return '';
    }

    return strtolower((string)date('l', $timestamp));
}

function getEmployeeDayLabelFromDate($date)
{
    $labels = getEmployeeOffDayOptions();
    $dayKey = getEmployeeDayKeyFromDate($date);

    return $labels[$dayKey] ?? $dayKey;
}

function isEmployeeOffDay($offDaysText, $date)
{
    $dayKey = getEmployeeDayKeyFromDate($date);
    if ($dayKey === '') {
        return false;
    }

    return in_array($dayKey, normalizeEmployeeOffDays(explode(',', (string)$offDaysText)), true);
}

function classifyEmployeeAttendanceStatus($date, $scheduledTime, $actualDateTime)
{
    $scheduledTimestamp = strtotime((string)$date . ' ' . (string)$scheduledTime);
    $actualTimestamp = strtotime((string)$actualDateTime);

    if ($scheduledTimestamp === false || $actualTimestamp === false) {
        return '';
    }

    return ($actualTimestamp - $scheduledTimestamp) > (EMPLOYEE_ATTENDANCE_GRACE_MINUTES * SECONDS_PER_MINUTE) ? 'متأخر' : 'في الموعد';
}

function isEmployeeOvernightShift($scheduledAttendanceTime, $scheduledDepartureTime)
{
    $scheduledAttendanceTimestamp = strtotime(EMPLOYEE_TIME_REFERENCE_DATE . ' ' . (string)$scheduledAttendanceTime);
    $scheduledDepartureTimestamp = strtotime(EMPLOYEE_TIME_REFERENCE_DATE . ' ' . (string)$scheduledDepartureTime);

    if ($scheduledAttendanceTimestamp === false || $scheduledDepartureTimestamp === false) {
        return false;
    }

    return $scheduledDepartureTimestamp < $scheduledAttendanceTimestamp;
}

function getEmployeeScheduledDepartureTimestamp($date, $scheduledAttendanceTime, $scheduledDepartureTime)
{
    $scheduledDate = (string)$date;

    if (isEmployeeOvernightShift($scheduledAttendanceTime, $scheduledDepartureTime)) {
        $nextDayTimestamp = strtotime($scheduledDate . ' +1 day');
        if ($nextDayTimestamp !== false) {
            $scheduledDate = date('Y-m-d', $nextDayTimestamp);
        }
    }

    return strtotime($scheduledDate . ' ' . (string)$scheduledDepartureTime);
}

function classifyEmployeeDepartureStatus($date, $scheduledTime, $actualDateTime)
{
    $scheduledTimestamp = strtotime((string)$date . ' ' . (string)$scheduledTime);
    $actualTimestamp = strtotime((string)$actualDateTime);

    if ($scheduledTimestamp === false || $actualTimestamp === false) {
        return '';
    }

    return $actualTimestamp < $scheduledTimestamp ? 'انصراف مبكر' : 'في الموعد';
}

function classifyEmployeeShiftDepartureStatus($date, $scheduledAttendanceTime, $scheduledDepartureTime, $actualDateTime)
{
    $scheduledTimestamp = getEmployeeScheduledDepartureTimestamp($date, $scheduledAttendanceTime, $scheduledDepartureTime);
    $actualTimestamp = strtotime((string)$actualDateTime);

    if ($scheduledTimestamp === false || $actualTimestamp === false) {
        return '';
    }

    return $actualTimestamp < $scheduledTimestamp ? 'انصراف مبكر' : 'في الموعد';
}

function getEmployeeScheduledTimeValue(array $scheduleData, $scheduledKey, $fallbackKey)
{
    $time = (string)($scheduleData[$scheduledKey] ?? '');
    if ($time === '') {
        $time = (string)($scheduleData[$fallbackKey] ?? '');
    }

    return $time;
}

function resolveEmployeeDepartureStatus(array $row)
{
    if (empty($row['departure_at'])) {
        return (string)($row['departure_status'] ?? '');
    }

    $attendanceDate = $row['attendance_date'] ?? '';
    $scheduledAttendanceTime = getEmployeeScheduledTimeValue($row, 'scheduled_attendance_time', 'attendance_time');
    $scheduledDepartureTime = getEmployeeScheduledTimeValue($row, 'scheduled_departure_time', 'departure_time');

    if ($attendanceDate === '' || $scheduledAttendanceTime === '' || $scheduledDepartureTime === '') {
        return (string)($row['departure_status'] ?? '');
    }

    $status = classifyEmployeeShiftDepartureStatus($attendanceDate, $scheduledAttendanceTime, $scheduledDepartureTime, $row['departure_at']);

    return $status !== '' ? $status : (string)($row['departure_status'] ?? '');
}

function getEmployeeAttendanceDayStatus(array $row)
{
    if (!empty($row['is_off_day'])) {
        return 'إجازة';
    }

    $hasAttendance = !empty($row['attendance_at']);
    $hasDeparture = !empty($row['departure_at']);

    if (!$hasAttendance && !$hasDeparture) {
        return 'غياب';
    }

    if ($hasAttendance && !$hasDeparture) {
        return 'حضور بدون انصراف';
    }

    if (!$hasAttendance && $hasDeparture) {
        return 'انصراف فقط';
    }

    return 'حضور وانصراف مكتمل';
}

function getEmployeeAttendanceReportRows(PDO $pdo, $date)
{
    ensureEmployeesSchema($pdo);
    ensureEmployeeAttendanceSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT
            e.id AS employee_id,
            e.barcode,
            e.name,
            e.phone,
            e.job_title,
            e.attendance_time,
            e.departure_time,
            e.off_days,
            ea.id AS attendance_id,
            ea.attendance_date,
            ea.attendance_at,
            ea.departure_at,
            ea.attendance_status,
            ea.departure_status,
            ea.scheduled_attendance_time,
            ea.scheduled_departure_time
        FROM employees e
        LEFT JOIN employee_attendance ea
            ON ea.employee_id = e.id
           AND ea.attendance_date = :attendance_date
        ORDER BY e.name ASC, e.id ASC
    ");
    $stmt->execute([':attendance_date' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['day_name'] = getEmployeeDayLabelFromDate($date);
        $row['is_off_day'] = isEmployeeOffDay($row['off_days'] ?? '', $date) ? 1 : 0;
        $row['scheduled_attendance_display'] = $row['scheduled_attendance_time'] ?: ($row['attendance_time'] ?? '');
        $row['scheduled_departure_display'] = $row['scheduled_departure_time'] ?: ($row['departure_time'] ?? '');
        $row['departure_status'] = resolveEmployeeDepartureStatus($row);
        $row['day_status'] = getEmployeeAttendanceDayStatus($row);
    }
    unset($row);

    return $rows;
}

function getAllEmployees(PDO $pdo)
{
    ensureEmployeesSchema($pdo);

    $stmt = $pdo->query("
        SELECT id, barcode, name, phone, job_title, attendance_time, departure_time, off_days, created_at
        FROM employees
        ORDER BY id DESC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
