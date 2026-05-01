<?php

require_once __DIR__ . '/employee_advances_helpers.php';
require_once __DIR__ . '/trainers_helpers.php';

function ensureEmployeePayrollSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    ensureEmployeesSchema($pdo);
    ensureEmployeeAttendanceSchema($pdo);
    ensureEmployeeAdvancesSchema($pdo);
    ensureTrainersSchema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_payroll (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id int(10) UNSIGNED NOT NULL,
            payment_month date NOT NULL,
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            notes varchar(255) DEFAULT NULL,
            paid_by int(11) NOT NULL,
            paid_at datetime NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uniq_employee_payroll_month (employee_id, payment_month),
            KEY idx_employee_payroll_month (payment_month),
            KEY idx_employee_payroll_paid_at (paid_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $schemaReady = true;
}

function normalizeEmployeePayrollMonth($month)
{
    $month = trim((string)$month);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return date('Y-m');
    }

    return $month;
}

function getEmployeePayrollMonthDateRange($month)
{
    $month = normalizeEmployeePayrollMonth($month);
    $start = $month . '-01';
    $lastDay = date('t', strtotime($start));

    return [
        'month' => $month,
        'start' => $start,
        'end' => $month . '-' . str_pad((string)$lastDay, 2, '0', STR_PAD_LEFT),
        'payment_month' => $start,
    ];
}

function getEmployeePayrollReportEndDate($month)
{
    $range = getEmployeePayrollMonthDateRange($month);
    $today = date('Y-m-d');

    if ($range['start'] > $today) {
        return null;
    }

    return $range['end'] < $today ? $range['end'] : $today;
}

function getEmployeePayrollSelectableEmployees(PDO $pdo, $month)
{
    ensureEmployeePayrollSchema($pdo);

    $range = getEmployeePayrollMonthDateRange($month);
    $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.barcode,
            e.name,
            e.job_title
        FROM employees e
        LEFT JOIN employee_payroll ep
            ON ep.employee_id = e.id
           AND ep.payment_month = :payment_month
        WHERE ep.id IS NULL
        ORDER BY e.name ASC, e.id ASC
    ");
    $stmt->execute([
        ':payment_month' => $range['payment_month'],
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function findEmployeePayrollEmployeeById(PDO $pdo, $employeeId)
{
    ensureEmployeesSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT id, barcode, name, phone, job_title, attendance_time, departure_time, off_days
        FROM employees
        WHERE id = :employee_id
        LIMIT 1
    ");
    $stmt->execute([':employee_id' => (int)$employeeId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hasEmployeePayrollForMonth(PDO $pdo, $employeeId, $month)
{
    ensureEmployeePayrollSchema($pdo);

    $range = getEmployeePayrollMonthDateRange($month);
    $stmt = $pdo->prepare("
        SELECT id
        FROM employee_payroll
        WHERE employee_id = :employee_id
          AND payment_month = :payment_month
        LIMIT 1
    ");
    $stmt->execute([
        ':employee_id' => (int)$employeeId,
        ':payment_month' => $range['payment_month'],
    ]);

    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function addEmployeePayrollPayment(PDO $pdo, $employeeId, $month, $amount, $notes, $paidBy)
{
    ensureEmployeePayrollSchema($pdo);

    $range = getEmployeePayrollMonthDateRange($month);
    $stmt = $pdo->prepare("
        INSERT INTO employee_payroll (
            employee_id,
            payment_month,
            amount,
            notes,
            paid_by,
            paid_at
        ) VALUES (
            :employee_id,
            :payment_month,
            :amount,
            :notes,
            :paid_by,
            :paid_at
        )
    ");

    return $stmt->execute([
        ':employee_id' => (int)$employeeId,
        ':payment_month' => $range['payment_month'],
        ':amount' => (float)$amount,
        ':notes' => $notes !== '' ? $notes : null,
        ':paid_by' => (int)$paidBy,
        ':paid_at' => date('Y-m-d H:i:s'),
    ]);
}

function findEmployeePayrollPaymentById(PDO $pdo, $payrollId)
{
    ensureEmployeePayrollSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT id, employee_id, payment_month, amount, notes, paid_by, paid_at, created_at, updated_at
        FROM employee_payroll
        WHERE id = :payroll_id
        LIMIT 1
    ");
    $stmt->execute([':payroll_id' => (int)$payrollId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateEmployeePayrollPayment(PDO $pdo, $payrollId, $amount, $notes)
{
    ensureEmployeePayrollSchema($pdo);

    $stmt = $pdo->prepare("
        UPDATE employee_payroll
        SET amount = :amount,
            notes = :notes
        WHERE id = :payroll_id
        LIMIT 1
    ");

    return $stmt->execute([
        ':amount' => (float)$amount,
        ':notes' => $notes !== '' ? $notes : null,
        ':payroll_id' => (int)$payrollId,
    ]);
}

function deleteEmployeePayrollPayment(PDO $pdo, $payrollId)
{
    ensureEmployeePayrollSchema($pdo);

    $stmt = $pdo->prepare("
        DELETE FROM employee_payroll
        WHERE id = :payroll_id
        LIMIT 1
    ");

    return $stmt->execute([':payroll_id' => (int)$payrollId]);
}

function getEmployeePayrollRows(PDO $pdo, $month)
{
    ensureEmployeePayrollSchema($pdo);

    $range = getEmployeePayrollMonthDateRange($month);
    $stmt = $pdo->prepare("
        SELECT
            ep.id,
            ep.employee_id,
            ep.payment_month,
            ep.amount,
            ep.notes,
            ep.paid_at,
            ep.created_at,
            e.barcode,
            e.name,
            e.job_title,
            u.username AS paid_by_username
        FROM employee_payroll ep
        INNER JOIN employees e ON e.id = ep.employee_id
        LEFT JOIN users u ON u.id = ep.paid_by
        WHERE ep.payment_month = :payment_month
        ORDER BY ep.paid_at DESC, ep.id DESC
    ");
    $stmt->execute([
        ':payment_month' => $range['payment_month'],
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getEmployeePayrollMonthSummary(PDO $pdo, $month)
{
    ensureEmployeePayrollSchema($pdo);

    $range = getEmployeePayrollMonthDateRange($month);
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS payroll_count,
            COALESCE(SUM(amount), 0) AS total_amount
        FROM employee_payroll
        WHERE payment_month = :payment_month
    ");
    $stmt->execute([
        ':payment_month' => $range['payment_month'],
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'payroll_count' => isset($row['payroll_count']) ? (int)$row['payroll_count'] : 0,
        'total_amount' => isset($row['total_amount']) ? (float)$row['total_amount'] : 0.0,
    ];
}

function getEmployeePayrollEmployeeMonthAttendance(PDO $pdo, $employeeId, $month)
{
    ensureEmployeePayrollSchema($pdo);

    $employee = findEmployeePayrollEmployeeById($pdo, $employeeId);
    if (!$employee) {
        return [
            'rows' => [],
            'summary' => [
                'attendance_days' => 0,
                'departure_days' => 0,
                'absent_days' => 0,
                'off_days' => 0,
                'late_days' => 0,
                'early_departure_days' => 0,
            ],
        ];
    }

    $range = getEmployeePayrollMonthDateRange($month);
    $reportEnd = getEmployeePayrollReportEndDate($month);

    $attendanceRows = [];
    if ($reportEnd !== null) {
        $stmt = $pdo->prepare("
            SELECT
                attendance_date,
                attendance_at,
                departure_at,
                attendance_status,
                departure_status,
                scheduled_attendance_time,
                scheduled_departure_time
            FROM employee_attendance
            WHERE employee_id = :employee_id
              AND attendance_date BETWEEN :start_date AND :end_date
            ORDER BY attendance_date ASC
        ");
        $stmt->execute([
            ':employee_id' => (int)$employeeId,
            ':start_date' => $range['start'],
            ':end_date' => $reportEnd,
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['departure_status'] = resolveEmployeeDepartureStatus(array_merge([
                'attendance_time' => $employee['attendance_time'],
                'departure_time' => $employee['departure_time'],
            ], $row));
            $attendanceRows[$row['attendance_date']] = $row;
        }
    }

    $summary = [
        'attendance_days' => 0,
        'departure_days' => 0,
        'absent_days' => 0,
        'off_days' => 0,
        'late_days' => 0,
        'early_departure_days' => 0,
    ];
    $rows = [];

    if ($reportEnd !== null) {
        $currentDate = $range['start'];
        while ($currentDate <= $reportEnd) {
            $row = $attendanceRows[$currentDate] ?? [
                'attendance_date' => $currentDate,
                'attendance_at' => null,
                'departure_at' => null,
                'attendance_status' => '',
                'departure_status' => '',
                'scheduled_attendance_time' => $employee['attendance_time'],
                'scheduled_departure_time' => $employee['departure_time'],
            ];

            $isOffDay = isEmployeeOffDay($employee['off_days'] ?? '', $currentDate);
            $dayStatus = getEmployeeAttendanceDayStatus([
                'attendance_at' => $row['attendance_at'],
                'departure_at' => $row['departure_at'],
                'is_off_day' => $isOffDay ? 1 : 0,
            ]);

            if ($isOffDay) {
                $summary['off_days']++;
            } else {
                if (!empty($row['attendance_at'])) {
                    $summary['attendance_days']++;
                }
                if (!empty($row['departure_at'])) {
                    $summary['departure_days']++;
                }
                if ($dayStatus === 'غياب') {
                    $summary['absent_days']++;
                }
                if (($row['attendance_status'] ?? '') === 'متأخر') {
                    $summary['late_days']++;
                }
                if (($row['departure_status'] ?? '') === 'انصراف مبكر') {
                    $summary['early_departure_days']++;
                }
            }

            $rows[] = [
                'attendance_date' => $currentDate,
                'day_label' => getEmployeeDayLabelFromDate($currentDate),
                'attendance_at' => $row['attendance_at'],
                'departure_at' => $row['departure_at'],
                'attendance_status' => $row['attendance_status'] ?: '—',
                'departure_status' => $row['departure_status'] ?: '—',
                'day_status' => $dayStatus,
            ];

            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }
    }

    return [
        'rows' => $rows,
        'summary' => $summary,
    ];
}

function getEmployeePayrollEmployeeMonthAdvances(PDO $pdo, $employeeId, $month)
{
    ensureEmployeePayrollSchema($pdo);

    $range = getEmployeePayrollMonthDateRange($month);
    $stmt = $pdo->prepare("
        SELECT
            ea.id,
            ea.advance_date,
            ea.amount,
            ea.notes,
            ea.created_at,
            u.username AS created_by_username
        FROM employee_advances ea
        LEFT JOIN users u ON u.id = ea.created_by
        WHERE ea.employee_id = :employee_id
          AND ea.advance_date BETWEEN :start_date AND :end_date
        ORDER BY ea.advance_date DESC, ea.id DESC
    ");
    $stmt->execute([
        ':employee_id' => (int)$employeeId,
        ':start_date' => $range['start'],
        ':end_date' => $range['end'],
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float)$row['amount'];
    }

    return [
        'rows' => $rows,
        'summary' => [
            'advances_count' => count($rows),
            'total_amount' => $total,
        ],
    ];
}

function getEmployeePayrollExpenseSummaryByDateRange(PDO $pdo, $startDate, $endDate)
{
    ensureEmployeePayrollSchema($pdo);

    $summary = [
        'regular_expenses' => 0.0,
        'employee_advances' => 0.0,
        'trainer_advances' => 0.0,
        'employee_salaries' => 0.0,
        'total_expenses' => 0.0,
    ];

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_amount
        FROM expenses
        WHERE expense_date BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ]);
    $summary['regular_expenses'] = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_amount
        FROM employee_advances
        WHERE advance_date BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ]);
    $summary['employee_advances'] = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(base_amount), 0) AS total_amount
        FROM trainer_commissions
        WHERE source_type = 'advance_withdrawal'
          AND DATE(created_at) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ]);
    $summary['trainer_advances'] = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_amount
        FROM employee_payroll
        WHERE DATE(paid_at) BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate,
    ]);
    $summary['employee_salaries'] = (float)$stmt->fetchColumn();

    $summary['total_expenses'] = $summary['regular_expenses']
        + $summary['employee_advances']
        + $summary['trainer_advances']
        + $summary['employee_salaries'];

    return $summary;
}

function getEmployeePayrollExpenseSummaryByTimestampRange(PDO $pdo, $startDateTime, $endDateTime)
{
    ensureEmployeePayrollSchema($pdo);

    $summary = [
        'regular_expenses' => 0.0,
        'employee_advances' => 0.0,
        'trainer_advances' => 0.0,
        'employee_salaries' => 0.0,
        'total_expenses' => 0.0,
    ];

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_amount
        FROM expenses
        WHERE created_at > :start_at
          AND created_at <= :end_at
    ");
    $stmt->execute([
        ':start_at' => $startDateTime,
        ':end_at' => $endDateTime,
    ]);
    $summary['regular_expenses'] = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_amount
        FROM employee_advances
        WHERE created_at > :start_at
          AND created_at <= :end_at
    ");
    $stmt->execute([
        ':start_at' => $startDateTime,
        ':end_at' => $endDateTime,
    ]);
    $summary['employee_advances'] = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(base_amount), 0) AS total_amount
        FROM trainer_commissions
        WHERE source_type = 'advance_withdrawal'
          AND created_at > :start_at
          AND created_at <= :end_at
    ");
    $stmt->execute([
        ':start_at' => $startDateTime,
        ':end_at' => $endDateTime,
    ]);
    $summary['trainer_advances'] = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount), 0) AS total_amount
        FROM employee_payroll
        WHERE paid_at > :start_at
          AND paid_at <= :end_at
    ");
    $stmt->execute([
        ':start_at' => $startDateTime,
        ':end_at' => $endDateTime,
    ]);
    $summary['employee_salaries'] = (float)$stmt->fetchColumn();

    $summary['total_expenses'] = $summary['regular_expenses']
        + $summary['employee_advances']
        + $summary['trainer_advances']
        + $summary['employee_salaries'];

    return $summary;
}

function getEmployeePayrollExpenseDetailsByTimestampRange(PDO $pdo, $startDateTime, $endDateTime)
{
    ensureEmployeePayrollSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT expense_type, item, amount, expense_date, created_at
        FROM (
            SELECT
                'مصروف عام' AS expense_type,
                item,
                amount,
                expense_date,
                created_at
            FROM expenses
            WHERE created_at > ? AND created_at <= ?

            UNION ALL

            SELECT
                'سلفة موظف' AS expense_type,
                CONCAT('سلفة للموظف ', e.name, ' - ', e.barcode) AS item,
                ea.amount AS amount,
                ea.advance_date AS expense_date,
                ea.created_at AS created_at
            FROM employee_advances ea
            INNER JOIN employees e ON e.id = ea.employee_id
            WHERE ea.created_at > ? AND ea.created_at <= ?

            UNION ALL

            SELECT
                'راتب موظف' AS expense_type,
                CONCAT('راتب للموظف ', e.name, ' - ', e.barcode) AS item,
                ep.amount AS amount,
                DATE(ep.paid_at) AS expense_date,
                ep.paid_at AS created_at
            FROM employee_payroll ep
            INNER JOIN employees e ON e.id = ep.employee_id
            WHERE ep.paid_at > ? AND ep.paid_at <= ?

            UNION ALL

            SELECT
                'سلفة مدرب' AS expense_type,
                CONCAT('سلفة للمدرب ', t.name) AS item,
                tc.base_amount AS amount,
                DATE(tc.created_at) AS expense_date,
                tc.created_at AS created_at
            FROM trainer_commissions tc
            INNER JOIN trainers t ON t.id = tc.trainer_id
            WHERE tc.source_type = 'advance_withdrawal'
              AND tc.created_at > ? AND tc.created_at <= ?
        ) expense_rows
        ORDER BY created_at DESC, expense_date DESC
    ");
    $stmt->execute([
        $startDateTime,
        $endDateTime,
        $startDateTime,
        $endDateTime,
        $startDateTime,
        $endDateTime,
        $startDateTime,
        $endDateTime,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
