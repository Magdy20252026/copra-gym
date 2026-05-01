<?php

function getDefaultUserPermissions()
{
    return [
        'can_view_members'        => 1,
        'can_view_trainers'       => 1,
        'can_view_employees'      => 1,
        'can_view_employee_attendance' => 1,
        'can_scan_employee_attendance' => 1,
        'can_use_employee_attendance_camera' => 1,
        'can_view_employee_attendance_report' => 1,
        'can_export_employee_attendance_excel' => 1,
        'can_view_employee_advances' => 1,
        'can_view_employee_payroll' => 1,
        'can_view_cashier'        => 1,
        'can_view_sales'          => 1,
        'can_view_items'          => 1,
        'can_view_renew_members'  => 1,
        'can_view_attendance'     => 1,
        'can_view_expenses'       => 1,
        'can_view_stats'          => 1,
        'can_view_settings'       => 1,
        'can_view_closing'        => 1,
    ];
}

function ensureUserPermissionsSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    try {
        $tableStmt = $pdo->query("SHOW TABLES LIKE 'user_permissions'");
        if (!$tableStmt || !$tableStmt->fetchColumn()) {
            $schemaReady = true;
            return;
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_trainers']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_trainers` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_members`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_trainers` = `can_view_members`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_employees']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_employees` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_trainers`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_employees` = `can_view_trainers`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_employee_attendance']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->beginTransaction();
            try {
                $pdo->exec("
                    ALTER TABLE `user_permissions`
                    ADD COLUMN `can_view_employee_attendance` tinyint(1) NOT NULL DEFAULT 1
                    AFTER `can_view_employees`
                ");
                $pdo->exec("UPDATE `user_permissions` SET `can_view_employee_attendance` = `can_view_employees`");
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_employee_advances']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_employee_advances` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_employee_attendance`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_employee_advances` = `can_view_employee_attendance`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_scan_employee_attendance']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_scan_employee_attendance` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_employee_attendance`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_scan_employee_attendance` = `can_view_employee_attendance`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_use_employee_attendance_camera']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_use_employee_attendance_camera` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_scan_employee_attendance`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_use_employee_attendance_camera` = `can_scan_employee_attendance`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_employee_attendance_report']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_employee_attendance_report` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_use_employee_attendance_camera`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_employee_attendance_report` = `can_view_employee_attendance`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_export_employee_attendance_excel']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_export_employee_attendance_excel` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_employee_attendance_report`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_export_employee_attendance_excel` = `can_view_employee_attendance_report`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_employee_payroll']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_employee_payroll` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_employee_advances`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_employee_payroll` = `can_view_employee_advances`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_cashier']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_cashier` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_employee_payroll`
            ");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_sales']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_sales` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_cashier`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_sales` = `can_view_cashier`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_items']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_items` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_sales`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_items` = `can_view_sales`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_renew_members']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_renew_members` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_items`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_renew_members` = `can_view_members`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_attendance']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_attendance` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_renew_members`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_attendance` = `can_view_members`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_expenses']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_expenses` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_attendance`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_expenses` = `can_view_cashier`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_stats']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_stats` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_expenses`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_stats` = `can_view_expenses`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_settings']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_settings` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_stats`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_settings` = `can_view_stats`");
        }

        $stmt = $pdo->prepare("SHOW COLUMNS FROM `user_permissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'can_view_closing']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `user_permissions`
                ADD COLUMN `can_view_closing` tinyint(1) NOT NULL DEFAULT 1
                AFTER `can_view_settings`
            ");
            $pdo->exec("UPDATE `user_permissions` SET `can_view_closing` = `can_view_settings`");
        }
    } catch (Exception $e) {
    }

    $schemaReady = true;
}
