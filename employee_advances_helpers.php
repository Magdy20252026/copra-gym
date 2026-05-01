<?php

require_once __DIR__ . '/employees_helpers.php';

function ensureEmployeeAdvancesSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    ensureEmployeesSchema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS employee_advances (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            employee_id int(10) UNSIGNED NOT NULL,
            advance_date date NOT NULL,
            amount decimal(10,2) NOT NULL DEFAULT 0.00,
            notes varchar(255) DEFAULT NULL,
            created_by int(11) NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY idx_employee_advances_month (advance_date),
            KEY idx_employee_advances_employee (employee_id),
            KEY idx_employee_advances_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `employee_advances` LIKE :column_name");
        $stmt->execute([':column_name' => 'notes']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `employee_advances`
                ADD COLUMN `notes` varchar(255) DEFAULT NULL
                AFTER `amount`
            ");
        }
    } catch (Exception $e) {
        // قد تكون بنية الجدول محدثة بالفعل في بعض البيئات، لذلك نتجاوز فشل الإضافة المتأخر للحقل.
    }

    try {
        $stmt = $pdo->prepare("SHOW INDEX FROM `employee_advances` WHERE Key_name = :index_name");
        $stmt->execute([':index_name' => 'idx_employee_advances_created_at']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `employee_advances`
                ADD KEY `idx_employee_advances_created_at` (`created_at`)
            ");
        }
    } catch (Exception $e) {
    }

    $schemaReady = true;
}

function normalizeEmployeeAdvanceMonth($month)
{
    $month = trim((string)$month);
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        return date('Y-m');
    }

    return $month;
}

function getEmployeeAdvanceMonthDateRange($month)
{
    $month = normalizeEmployeeAdvanceMonth($month);
    $start = $month . '-01';
    $lastDay = date('t', strtotime($start));

    return [
        'month' => $month,
        'start' => $start,
        'end' => $month . '-' . str_pad((string)$lastDay, 2, '0', STR_PAD_LEFT),
    ];
}

function getEmployeeAdvanceSelectableEmployees(PDO $pdo)
{
    ensureEmployeesSchema($pdo);

    $stmt = $pdo->query("
        SELECT id, barcode, name, job_title
        FROM employees
        ORDER BY name ASC, id ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getEmployeeAdvanceRows(PDO $pdo, $month)
{
    ensureEmployeeAdvancesSchema($pdo);

    $range = getEmployeeAdvanceMonthDateRange($month);
    $stmt = $pdo->prepare("
        SELECT
            ea.id,
            ea.employee_id,
            ea.advance_date,
            ea.amount,
            ea.notes,
            ea.created_at,
            e.barcode,
            e.name,
            e.job_title,
            u.username AS created_by_username
        FROM employee_advances ea
        INNER JOIN employees e ON e.id = ea.employee_id
        LEFT JOIN users u ON u.id = ea.created_by
        WHERE ea.advance_date BETWEEN :start_date AND :end_date
        ORDER BY ea.advance_date DESC, ea.id DESC
    ");
    $stmt->execute([
        ':start_date' => $range['start'],
        ':end_date' => $range['end'],
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getEmployeeAdvanceMonthSummary(PDO $pdo, $month)
{
    ensureEmployeeAdvancesSchema($pdo);

    $range = getEmployeeAdvanceMonthDateRange($month);
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS advances_count,
            COALESCE(SUM(amount), 0) AS total_amount
        FROM employee_advances
        WHERE advance_date BETWEEN :start_date AND :end_date
    ");
    $stmt->execute([
        ':start_date' => $range['start'],
        ':end_date' => $range['end'],
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'advances_count' => isset($row['advances_count']) ? (int)$row['advances_count'] : 0,
        'total_amount' => isset($row['total_amount']) ? (float)$row['total_amount'] : 0.0,
    ];
}

function findEmployeeAdvanceEmployeeById(PDO $pdo, $employeeId)
{
    ensureEmployeesSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT id, barcode, name, job_title
        FROM employees
        WHERE id = :employee_id
        LIMIT 1
    ");
    $stmt->execute([':employee_id' => (int)$employeeId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function findEmployeeAdvanceById(PDO $pdo, $advanceId)
{
    ensureEmployeeAdvancesSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT id, employee_id, advance_date, amount, notes, created_by, created_at
        FROM employee_advances
        WHERE id = :advance_id
        LIMIT 1
    ");
    $stmt->execute([':advance_id' => (int)$advanceId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateEmployeeAdvance(PDO $pdo, $advanceId, $employeeId, $advanceDate, $amount, $notes)
{
    ensureEmployeeAdvancesSchema($pdo);

    $stmt = $pdo->prepare("
        UPDATE employee_advances
        SET employee_id = :employee_id,
            advance_date = :advance_date,
            amount = :amount,
            notes = :notes
        WHERE id = :advance_id
        LIMIT 1
    ");

    return $stmt->execute([
        ':employee_id' => (int)$employeeId,
        ':advance_date' => $advanceDate,
        ':amount' => (float)$amount,
        ':notes' => $notes !== '' ? $notes : null,
        ':advance_id' => (int)$advanceId,
    ]);
}

function deleteEmployeeAdvance(PDO $pdo, $advanceId)
{
    ensureEmployeeAdvancesSchema($pdo);

    $stmt = $pdo->prepare("
        DELETE FROM employee_advances
        WHERE id = :advance_id
        LIMIT 1
    ");

    return $stmt->execute([':advance_id' => (int)$advanceId]);
}
