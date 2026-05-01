<?php

function ensureSalesSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sales (
            id int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            sale_date date NOT NULL,
            invoice_number varchar(50) NOT NULL,
            transaction_type enum('بيع','مرتجع') NOT NULL DEFAULT 'بيع',
            item_id int(11) UNSIGNED NOT NULL,
            item_name varchar(255) NOT NULL,
            quantity int(11) UNSIGNED NOT NULL DEFAULT 1,
            unit_price decimal(10,2) NOT NULL DEFAULT 0.00,
            total_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            cashier_id int(10) UNSIGNED NOT NULL,
            cashier_name varchar(255) NOT NULL,
            created_by_user_id int(11) NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY idx_sales_sale_date (sale_date),
            KEY idx_sales_invoice_number (invoice_number),
            KEY idx_sales_cashier (cashier_id),
            KEY idx_sales_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    ensureSalesTableColumn($pdo, 'invoice_number', "ALTER TABLE `sales` ADD COLUMN `invoice_number` varchar(50) NOT NULL DEFAULT '' AFTER `sale_date`");
    ensureSalesTableIndex($pdo, 'idx_sales_invoice_number', "ALTER TABLE `sales` ADD KEY `idx_sales_invoice_number` (`invoice_number`)");

    $missingInvoiceStmt = $pdo->query("SELECT COUNT(*) FROM `sales` WHERE `invoice_number` = '' OR `invoice_number` IS NULL");
    if ($missingInvoiceStmt && (int)$missingInvoiceStmt->fetchColumn() > 0) {
        $pdo->exec("UPDATE `sales` SET `invoice_number` = CONCAT('INV-', LPAD(`id`, 8, '0')) WHERE `invoice_number` = '' OR `invoice_number` IS NULL");
    }

    ensureSalesClosingColumns($pdo, 'daily_closings');
    ensureSalesClosingColumns($pdo, 'weekly_closings');

    $schemaReady = true;
}

function ensureSalesTableColumn(PDO $pdo, string $columnName, string $sql)
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `sales` LIKE :column_name");
    $stmt->execute([':column_name' => $columnName]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec($sql);
    }
}

function ensureSalesTableIndex(PDO $pdo, string $indexName, string $sql)
{
    $stmt = $pdo->prepare("SHOW INDEX FROM `sales` WHERE Key_name = :index_name");
    $stmt->execute([':index_name' => $indexName]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec($sql);
    }
}

function ensureSalesClosingColumns(PDO $pdo, string $tableName)
{
    $stmt = $pdo->prepare("SHOW TABLES LIKE :table_name");
    $stmt->execute([':table_name' => $tableName]);
    if (!$stmt->fetchColumn()) {
        return;
    }

    $columnsToAdd = [
        'sales_operations_count' => "ALTER TABLE `{$tableName}` ADD COLUMN `sales_operations_count` int(11) UNSIGNED NOT NULL DEFAULT 0 AFTER `total_single_sessions_amount`",
        'total_sales_amount'     => "ALTER TABLE `{$tableName}` ADD COLUMN `total_sales_amount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `sales_operations_count`",
    ];

    foreach ($columnsToAdd as $columnName => $sql) {
        $columnStmt = $pdo->prepare("SHOW COLUMNS FROM `{$tableName}` LIKE :column_name");
        $columnStmt->execute([':column_name' => $columnName]);
        if (!$columnStmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec($sql);
        }
    }
}

function generateSalesInvoiceNumber(): string
{
    return 'INV-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function getSalesSummary(PDO $pdo, string $start, string $end, bool $useSaleDate = true): array
{
    ensureSalesSchema($pdo);

    $summary = [
        'operations_count'        => 0,
        'sales_operations_count'  => 0,
        'return_operations_count' => 0,
        'gross_sales_amount'      => 0.0,
        'total_return_amount'     => 0.0,
        'net_sales_amount'        => 0.0,
    ];

    $whereClause = $useSaleDate
        ? 'sale_date BETWEEN :start AND :end'
        : 'created_at > :start AND created_at <= :end';

    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT invoice_number) AS operations_count,
            COUNT(DISTINCT CASE WHEN transaction_type = 'بيع' THEN invoice_number ELSE NULL END) AS sales_operations_count,
            COUNT(DISTINCT CASE WHEN transaction_type = 'مرتجع' THEN invoice_number ELSE NULL END) AS return_operations_count,
            COALESCE(SUM(CASE WHEN transaction_type = 'بيع' THEN total_amount ELSE 0 END), 0) AS gross_sales_amount,
            COALESCE(SUM(CASE WHEN transaction_type = 'مرتجع' THEN total_amount ELSE 0 END), 0) AS total_return_amount,
            COALESCE(SUM(CASE WHEN transaction_type = 'بيع' THEN total_amount WHEN transaction_type = 'مرتجع' THEN -total_amount ELSE 0 END), 0) AS net_sales_amount
        FROM sales
        WHERE {$whereClause}
    ");
    $stmt->execute([
        ':start' => $start,
        ':end'   => $end,
    ]);

    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $summary['operations_count'] = (int)($row['operations_count'] ?? 0);
        $summary['sales_operations_count'] = (int)($row['sales_operations_count'] ?? 0);
        $summary['return_operations_count'] = (int)($row['return_operations_count'] ?? 0);
        $summary['gross_sales_amount'] = (float)($row['gross_sales_amount'] ?? 0);
        $summary['total_return_amount'] = (float)($row['total_return_amount'] ?? 0);
        $summary['net_sales_amount'] = (float)($row['net_sales_amount'] ?? 0);
    }

    return $summary;
}

function getAllSalesForDate(PDO $pdo, string $saleDate): array
{
    ensureSalesSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT
            MIN(id) AS id,
            sale_date,
            invoice_number,
            transaction_type,
            cashier_id,
            cashier_name,
            COUNT(*) AS item_lines_count,
            COALESCE(SUM(quantity), 0) AS total_quantity,
            COALESCE(SUM(total_amount), 0) AS invoice_total,
            GROUP_CONCAT(CONCAT(item_name, ' × ', quantity) ORDER BY id SEPARATOR '، ') AS item_summary,
            MAX(created_at) AS created_at
        FROM sales
        WHERE sale_date = :sale_date
        GROUP BY sale_date, invoice_number, transaction_type, cashier_id, cashier_name
        ORDER BY MAX(created_at) DESC, MIN(id) DESC
    ");
    $stmt->execute([':sale_date' => $saleDate]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSaleInvoiceById(PDO $pdo, $saleId)
{
    ensureSalesSchema($pdo);

    $saleId = (int)$saleId;
    if ($saleId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT invoice_number FROM sales WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $saleId]);
    $invoiceNumber = $stmt->fetchColumn();
    if (!$invoiceNumber) {
        return null;
    }

    return getSaleInvoiceByNumber($pdo, $invoiceNumber);
}

function getSaleInvoiceByNumber(PDO $pdo, string $invoiceNumber)
{
    ensureSalesSchema($pdo);

    $invoiceNumber = trim($invoiceNumber);
    if ($invoiceNumber === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            s.*, u.username AS created_by_username
        FROM sales s
        LEFT JOIN users u ON u.id = s.created_by_user_id
        WHERE s.invoice_number = :invoice_number
        ORDER BY s.id ASC
    ");
    $stmt->execute([':invoice_number' => $invoiceNumber]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        return null;
    }

    $firstRow = $rows[0];
    $invoice = [
        'id'                => (int)$firstRow['id'],
        'invoice_number'    => $invoiceNumber,
        'sale_date'         => $firstRow['sale_date'],
        'transaction_type'  => $firstRow['transaction_type'],
        'cashier_id'        => (int)$firstRow['cashier_id'],
        'cashier_name'      => $firstRow['cashier_name'],
        'created_by_user_id'=> (int)$firstRow['created_by_user_id'],
        'created_by_username' => $firstRow['created_by_username'] ?? '',
        'created_at'        => $firstRow['created_at'],
        'invoice_total'     => 0.0,
        'items'             => [],
    ];

    foreach ($rows as $row) {
        $invoice['invoice_total'] += (float)$row['total_amount'];
        $invoice['items'][] = [
            'id'          => (int)$row['id'],
            'item_id'     => (int)$row['item_id'],
            'item_name'   => $row['item_name'],
            'quantity'    => (int)$row['quantity'],
            'unit_price'  => (float)$row['unit_price'],
            'total_amount'=> (float)$row['total_amount'],
        ];
    }

    $invoice['created_at'] = (string)$rows[count($rows) - 1]['created_at'];
    $invoice['invoice_total'] = round((float)$invoice['invoice_total'], 2);

    return $invoice;
}

function getSubscriptionReceiptById(PDO $pdo, $memberId)
{
    $memberId = (int)$memberId;
    if ($memberId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            m.id,
            m.name AS member_name,
            m.barcode,
            m.start_date,
            m.end_date,
            m.subscription_amount,
            m.paid_amount,
            m.remaining_amount,
            m.created_at,
            u.username AS created_by_username,
            s.name AS subscription_name,
            t.name AS trainer_name
        FROM members m
        JOIN subscriptions s ON s.id = m.subscription_id
        LEFT JOIN users u ON u.id = m.created_by_user_id
        LEFT JOIN trainers t ON t.id = m.trainer_id
        WHERE m.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $memberId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'id' => (int)$row['id'],
        'member_name' => (string)($row['member_name'] ?? ''),
        'barcode' => (string)($row['barcode'] ?? ''),
        'start_date' => (string)($row['start_date'] ?? ''),
        'end_date' => (string)($row['end_date'] ?? ''),
        'subscription_name' => (string)($row['subscription_name'] ?? ''),
        'trainer_name' => isset($row['trainer_name']) && $row['trainer_name'] !== '' ? (string)$row['trainer_name'] : null,
        'subscription_amount' => round((float)($row['subscription_amount'] ?? 0), 2),
        'paid_amount' => round((float)($row['paid_amount'] ?? 0), 2),
        'remaining_amount' => round((float)($row['remaining_amount'] ?? 0), 2),
        'created_at' => (string)($row['created_at'] ?? ''),
        'created_by_username' => (string)($row['created_by_username'] ?? ''),
    ];
}
