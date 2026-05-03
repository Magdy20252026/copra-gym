<?php

function ensureSingleSessionSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    branchAwareSetDisabled(true);
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS single_session_price (
                id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                session_name varchar(255) NOT NULL,
                price decimal(10,2) NOT NULL,
                updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        ensureSingleSessionTableColumn(
            $pdo,
            'single_session_price',
            'session_name',
            "ALTER TABLE `single_session_price` ADD COLUMN `session_name` varchar(255) NOT NULL DEFAULT '' AFTER `id`"
        );

        $pdo->exec("
            UPDATE `single_session_price`
            SET `session_name` = CONCAT('تمرينة ', `id`)
            WHERE `session_name` = '' OR `session_name` IS NULL
        ");

        ensureAttendanceSingleSessionColumns($pdo);
        ensureAttendanceTypeEnum($pdo);
    } finally {
        branchAwareSetDisabled(false);
    }

    $schemaReady = true;
}

function buildSqlQuotedStringList(array $values): string
{
    return implode(', ', array_map(static function (string $value): string {
        return "'" . str_replace("'", "''", $value) . "'";
    }, $values));
}

function getSingleSessionAttendanceTypes(): array
{
    return ['حصة_واحدة', 'حصة واحدة'];
}

function ensureSingleSessionTableColumn(PDO $pdo, string $tableName, string $columnName, string $sql)
{
    if ($tableName === 'single_session_price') {
        $showColumnsSql = "SHOW COLUMNS FROM `single_session_price` LIKE :column_name";
    } elseif ($tableName === 'attendance') {
        $showColumnsSql = "SHOW COLUMNS FROM `attendance` LIKE :column_name";
    } else {
        throw new InvalidArgumentException('Unsupported table name.');
    }

    $stmt = $pdo->prepare($showColumnsSql);
    $stmt->execute([':column_name' => $columnName]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec($sql);
    }
}

function ensureAttendanceSingleSessionColumns(PDO $pdo)
{
    $stmt = $pdo->query("SHOW TABLES LIKE 'attendance'");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_NUM)) {
        return;
    }

    $columnsToAdd = [
        'single_paid'             => "ALTER TABLE `attendance` ADD COLUMN `single_paid` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `created_at`",
        'single_session_price_id' => "ALTER TABLE `attendance` ADD COLUMN `single_session_price_id` int(10) UNSIGNED DEFAULT NULL AFTER `single_paid`",
        'single_session_name'     => "ALTER TABLE `attendance` ADD COLUMN `single_session_name` varchar(255) DEFAULT NULL AFTER `single_session_price_id`",
        'single_session_price'    => "ALTER TABLE `attendance` ADD COLUMN `single_session_price` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `single_session_name`",
        'spa_used'                => "ALTER TABLE `attendance` ADD COLUMN `spa_used` tinyint(1) NOT NULL DEFAULT 0 AFTER `single_session_price`",
        'massage_used'            => "ALTER TABLE `attendance` ADD COLUMN `massage_used` tinyint(1) NOT NULL DEFAULT 0 AFTER `spa_used`",
        'jacuzzi_used'            => "ALTER TABLE `attendance` ADD COLUMN `jacuzzi_used` tinyint(1) NOT NULL DEFAULT 0 AFTER `massage_used`",
    ];

    foreach ($columnsToAdd as $columnName => $sql) {
        ensureSingleSessionTableColumn($pdo, 'attendance', $columnName, $sql);
    }
}

function ensureAttendanceTypeEnum(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW TABLES LIKE 'attendance'");
    if (!$stmt || !$stmt->fetch(PDO::FETCH_NUM)) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'attendance'
          AND COLUMN_NAME = 'type'
        LIMIT 1
    ");
    $stmt->execute();

    $columnType = (string)($stmt->fetchColumn() ?: '');
    if ($columnType === '' || stripos($columnType, 'enum(') !== 0) {
        return;
    }

    // استخراج قيم ENUM المحاطة بعلامات اقتباس مفردة مع مراعاة المحارف المهربة داخل التعريف.
    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $columnType, $matches);
    $enumValues = [];
    foreach ($matches[1] ?? [] as $rawValue) {
        $value = stripcslashes($rawValue);
        if ($value !== '' && !in_array($value, $enumValues, true)) {
            $enumValues[] = $value;
        }
    }

    $requiredValues = array_values(array_unique(array_merge(['مشترك', 'مدعو'], getSingleSessionAttendanceTypes())));
    $missingValues = array_diff($requiredValues, $enumValues);
    if (!$missingValues) {
        return;
    }

    $mergedValues = array_values(array_unique(array_merge($enumValues, $requiredValues)));
    $pdo->exec("
        ALTER TABLE `attendance`
        MODIFY COLUMN `type` ENUM(" . buildSqlQuotedStringList($mergedValues) . ") NOT NULL
    ");
}

function getSingleSessionOptions(PDO $pdo): array
{
    ensureSingleSessionSchema($pdo);

    $stmt = $pdo->query("
        SELECT id, session_name, price, updated_at
        FROM single_session_price
        ORDER BY session_name ASC, id ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getSingleSessionOptionById(PDO $pdo, int $id): ?array
{
    ensureSingleSessionSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT id, session_name, price, updated_at
        FROM single_session_price
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
