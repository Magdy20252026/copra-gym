<?php

require_once 'sales_helpers.php';

function ensureCashierSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cashiers (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uniq_cashiers_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $schemaReady = true;
}

function getCashierById(PDO $pdo, $cashierId)
{
    ensureCashierSchema($pdo);

    $cashierId = (int)$cashierId;
    if ($cashierId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, name, created_at
        FROM cashiers
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $cashierId]);

    $cashier = $stmt->fetch(PDO::FETCH_ASSOC);
    return $cashier ?: null;
}

function getAllCashiersWithDailyBalance(PDO $pdo)
{
    ensureCashierSchema($pdo);
    ensureSalesSchema($pdo);

    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.name,
            c.created_at,
            COALESCE(SUM(CASE WHEN s.transaction_type = 'بيع' THEN s.total_amount ELSE -s.total_amount END), 0) AS daily_balance
        FROM cashiers c
        LEFT JOIN sales s
            ON s.cashier_id = c.id
           AND s.sale_date = :sale_date
        GROUP BY c.id, c.name, c.created_at
        ORDER BY c.name ASC, c.id ASC
    ");
    $stmt->execute([':sale_date' => $today]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCashierBalanceHistory(PDO $pdo, $cashierId)
{
    ensureCashierSchema($pdo);
    ensureSalesSchema($pdo);

    $cashierId = (int)$cashierId;
    if ($cashierId <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("
        SELECT
            sale_date AS balance_date,
            COALESCE(SUM(CASE WHEN transaction_type = 'بيع' THEN total_amount ELSE -total_amount END), 0) AS balance_amount,
            MAX(created_at) AS updated_at
        FROM sales
        WHERE cashier_id = :cashier_id
        GROUP BY sale_date
        ORDER BY sale_date DESC, updated_at DESC
    ");
    $stmt->execute([':cashier_id' => $cashierId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCashierSalesCount(PDO $pdo, $cashierId)
{
    ensureCashierSchema($pdo);
    ensureSalesSchema($pdo);

    $cashierId = (int)$cashierId;
    if ($cashierId <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS sales_count
        FROM sales
        WHERE cashier_id = :cashier_id
    ");
    $stmt->execute([':cashier_id' => $cashierId]);

    return (int)$stmt->fetchColumn();
}
