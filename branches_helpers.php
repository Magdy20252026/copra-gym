<?php

if (!class_exists('BranchAwarePDO')) {
    class BranchAwarePDO extends PDO
    {
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            return parent::prepare(branchAwareTransformSql($query), $options);
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            $query = branchAwareTransformSql($query);

            if ($fetchMode === null) {
                return parent::query($query);
            }

            return parent::query($query, $fetchMode, ...$fetchModeArgs);
        }

        public function exec(string $statement): int|false
        {
            return parent::exec(branchAwareTransformSql($statement));
        }
    }
}

function normalizeBranchAccessScope(?string $value): string
{
    return $value === 'single' ? 'single' : 'all';
}

function getBranchScopedTableNames(): array
{
    return [
        'attendance',
        'cashiers',
        'daily_closings',
        'employee_advances',
        'employee_attendance',
        'employee_payroll',
        'employees',
        'expenses',
        'items',
        'member_extensions',
        'member_freeze',
        'member_freeze_log',
        'member_notifications',
        'member_payments',
        'member_renewals',
        'member_service_usage',
        'members',
        'partial_payments',
        'renewals_log',
        'sales',
        'single_session_price',
        'site_settings',
        'subscriptions',
        'trainer_commissions',
        'trainers',
        'weekly_closings',
    ];
}

function getActiveBranchSessionId(): int
{
    return isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 0;
}

function setActiveBranchSession(int $branchId, string $branchName): void
{
    $_SESSION['branch_id'] = $branchId;
    $_SESSION['branch_name'] = $branchName;
}

function clearActiveBranchSession(): void
{
    unset($_SESSION['branch_id'], $_SESSION['branch_name']);
}

function getCurrentBranchName(): string
{
    return trim((string)($_SESSION['branch_name'] ?? ''));
}

function branchAwareTransformSql(string $sql): string
{
    if (!empty($GLOBALS['branchAwareDisabled'])) {
        return $sql;
    }

    $branchId = getActiveBranchSessionId();
    if ($branchId <= 0) {
        return $sql;
    }

    if (preg_match('/^\s*(SHOW|DESCRIBE|EXPLAIN|ALTER|CREATE|DROP|TRUNCATE|RENAME|SET|USE|CALL)\b/i', $sql)) {
        return $sql;
    }

    $statementType = strtoupper((string)preg_replace('/^(\w+).*$/s', '$1', trim($sql)));

    return match ($statementType) {
        'SELECT' => branchAwareTransformSelectSql($sql, $branchId),
        'UPDATE' => branchAwareTransformUpdateSql($sql, $branchId),
        'DELETE' => branchAwareTransformDeleteSql($sql, $branchId),
        'INSERT' => branchAwareTransformInsertSql($sql, $branchId),
        default => $sql,
    };
}

function branchAwareTransformSelectSql(string $sql, int $branchId): string
{
    $scopedTables = array_flip(getBranchScopedTableNames());
    $aliases = [];

    if (preg_match_all('/\b(?:FROM|JOIN)\s+(`?)([a-z_][a-z0-9_]*)\1(?:\s+(?:AS\s+)?([a-z_][a-z0-9_]*))?/i', $sql, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $tableName = strtolower($match[2]);
            if (!isset($scopedTables[$tableName])) {
                continue;
            }

            $alias = isset($match[3]) && $match[3] !== '' ? $match[3] : $match[2];
            $aliases[$alias] = true;
        }
    }

    if (!$aliases) {
        return $sql;
    }

    $conditions = [];
    foreach (array_keys($aliases) as $alias) {
        $conditions[] = sprintf('%s.`branch_id` = %d', branchAwareQuoteIdentifier($alias), $branchId);
    }

    return branchAwareAppendCondition($sql, implode(' AND ', $conditions));
}

function branchAwareTransformUpdateSql(string $sql, int $branchId): string
{
    $scopedTables = array_flip(getBranchScopedTableNames());
    if (!preg_match('/^\s*UPDATE\s+(`?)([a-z_][a-z0-9_]*)\1(?:\s+(?:AS\s+)?([a-z_][a-z0-9_]*))?/i', $sql, $match)) {
        return $sql;
    }

    $tableName = strtolower($match[2]);
    if (!isset($scopedTables[$tableName])) {
        return $sql;
    }

    $alias = isset($match[3]) && $match[3] !== '' ? $match[3] : $match[2];

    return branchAwareAppendCondition(
        $sql,
        sprintf('%s.`branch_id` = %d', branchAwareQuoteIdentifier($alias), $branchId)
    );
}

function branchAwareTransformDeleteSql(string $sql, int $branchId): string
{
    $scopedTables = array_flip(getBranchScopedTableNames());
    if (!preg_match('/^\s*DELETE\s+FROM\s+(`?)([a-z_][a-z0-9_]*)\1(?:\s+(?:AS\s+)?([a-z_][a-z0-9_]*))?/i', $sql, $match)) {
        return $sql;
    }

    $tableName = strtolower($match[2]);
    if (!isset($scopedTables[$tableName])) {
        return $sql;
    }

    $alias = isset($match[3]) && $match[3] !== '' ? $match[3] : $match[2];

    return branchAwareAppendCondition(
        $sql,
        sprintf('%s.`branch_id` = %d', branchAwareQuoteIdentifier($alias), $branchId)
    );
}

function branchAwareTransformInsertSql(string $sql, int $branchId): string
{
    $scopedTables = array_flip(getBranchScopedTableNames());
    if (!preg_match('/^\s*INSERT\s+INTO\s+(`?)([a-z_][a-z0-9_]*)\1/i', $sql, $match, PREG_OFFSET_CAPTURE)) {
        return $sql;
    }

    $tableName = strtolower($match[2][0]);
    if (!isset($scopedTables[$tableName])) {
        return $sql;
    }

    $tableMatchEnd = $match[0][1] + strlen($match[0][0]);
    $columnsStart = strpos($sql, '(', $tableMatchEnd);
    if ($columnsStart === false) {
        return $sql;
    }

    $columnsEnd = branchAwareFindMatchingParenthesis($sql, $columnsStart);
    if ($columnsEnd === null) {
        return $sql;
    }

    $valuesKeywordPosition = stripos($sql, 'VALUES', $columnsEnd);
    if ($valuesKeywordPosition === false) {
        return $sql;
    }

    $valuesStart = strpos($sql, '(', $valuesKeywordPosition);
    if ($valuesStart === false) {
        return $sql;
    }

    $valuesEnd = branchAwareFindMatchingParenthesis($sql, $valuesStart);
    if ($valuesEnd === null) {
        return $sql;
    }

    $columns = trim(substr($sql, $columnsStart + 1, $columnsEnd - $columnsStart - 1));
    if (preg_match('/(^|,)\s*`?branch_id`?\s*(,|$)/i', $columns)) {
        return $sql;
    }

    $values = trim(substr($sql, $valuesStart + 1, $valuesEnd - $valuesStart - 1));

    return substr($sql, 0, $columnsStart + 1)
        . $columns . ', `branch_id`'
        . substr($sql, $columnsEnd, $valuesStart - $columnsEnd + 1)
        . $values . ', ' . $branchId
        . substr($sql, $valuesEnd);
}

function branchAwareFindMatchingParenthesis(string $sql, int $openPosition): ?int
{
    $depth = 0;
    $length = strlen($sql);
    $inSingleQuote = false;
    $inDoubleQuote = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($index = $openPosition; $index < $length; $index++) {
        $character = $sql[$index];
        $nextCharacter = $index + 1 < $length ? $sql[$index + 1] : '';

        if ($inLineComment) {
            if ($character === "\n") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($character === '*' && $nextCharacter === '/') {
                $inBlockComment = false;
                $index++;
            }
            continue;
        }

        if ($inSingleQuote) {
            if ($character === '\\') {
                $index++;
                continue;
            }
            if ($character === "'") {
                $inSingleQuote = false;
            }
            continue;
        }

        if ($inDoubleQuote) {
            if ($character === '\\') {
                $index++;
                continue;
            }
            if ($character === '"') {
                $inDoubleQuote = false;
            }
            continue;
        }

        if ($inBacktick) {
            if ($character === '`') {
                $inBacktick = false;
            }
            continue;
        }

        if ($character === '-' && $nextCharacter === '-') {
            $inLineComment = true;
            $index++;
            continue;
        }

        if ($character === '/' && $nextCharacter === '*') {
            $inBlockComment = true;
            $index++;
            continue;
        }

        if ($character === "'") {
            $inSingleQuote = true;
            continue;
        }

        if ($character === '"') {
            $inDoubleQuote = true;
            continue;
        }

        if ($character === '`') {
            $inBacktick = true;
            continue;
        }

        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth--;
            if ($depth === 0) {
                return $index;
            }
        }
    }

    return null;
}

function branchAwareAppendCondition(string $sql, string $condition): string
{
    $trimmedSql = rtrim($sql);
    $insertPosition = branchAwareFindClausePosition($trimmedSql);
    $beforeClause = $insertPosition === null ? $trimmedSql : substr($trimmedSql, 0, $insertPosition);
    $afterClause = $insertPosition === null ? '' : substr($trimmedSql, $insertPosition);
    $hasWhere = preg_match('/\bWHERE\b/i', $beforeClause) === 1;

    return $beforeClause . ($hasWhere ? ' AND ' : ' WHERE ') . $condition . ($afterClause === '' ? '' : ' ' . ltrim($afterClause));
}

function branchAwareFindClausePosition(string $sql): ?int
{
    if (!preg_match('/\b(GROUP\s+BY|HAVING|ORDER\s+BY|LIMIT|UNION|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE)\b/i', $sql, $match, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    return (int)$match[0][1];
}

function branchAwareQuoteIdentifier(string $identifier): string
{
    $identifier = trim($identifier, '`');
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
        throw new InvalidArgumentException('Invalid SQL identifier.');
    }
    return '`' . str_replace('`', '', $identifier) . '`';
}

function ensureBranchesSchema(PDO $pdo): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    branchAwareSetDisabled(true);
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `branches` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `branch_name` varchar(255) NOT NULL,
                `is_active` tinyint(1) NOT NULL DEFAULT 1,
                `created_at` timestamp NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_branch_name` (`branch_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $countStmt = $pdo->query("SELECT COUNT(*) FROM `branches`");
        $branchCount = $countStmt ? (int)$countStmt->fetchColumn() : 0;
        if ($branchCount === 0) {
            $stmt = $pdo->prepare("INSERT INTO `branches` (`branch_name`, `is_active`) VALUES (:branch_name, 1)");
            $stmt->execute([':branch_name' => 'الفرع الرئيسي']);
        }
    } finally {
        branchAwareSetDisabled(false);
    }

    $schemaReady = true;
}

function ensureUserBranchesSchema(PDO $pdo): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    ensureBranchesSchema($pdo);

    branchAwareSetDisabled(true);
    try {
        $columns = [
            'branch_access_scope' => "ALTER TABLE `users` ADD COLUMN `branch_access_scope` varchar(20) NOT NULL DEFAULT 'all' AFTER `role`",
            'branch_id' => "ALTER TABLE `users` ADD COLUMN `branch_id` int(11) DEFAULT NULL AFTER `branch_access_scope`",
        ];

        foreach ($columns as $columnName => $sql) {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `users` LIKE :column_name");
            $stmt->execute([':column_name' => $columnName]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec($sql);
            }
        }
    } finally {
        branchAwareSetDisabled(false);
    }

    $schemaReady = true;
}

function ensureBranchScopedTablesSchema(PDO $pdo): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    ensureBranchesSchema($pdo);
    $defaultBranchId = getDefaultBranchId($pdo);
    if ($defaultBranchId <= 0) {
        $schemaReady = true;
        return;
    }

    branchAwareSetDisabled(true);
    try {
        foreach (getBranchScopedTableNames() as $tableName) {
            try {
                $tableStmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tableName));
                if (!$tableStmt || !$tableStmt->fetchColumn()) {
                    continue;
                }

                $columnStmt = $pdo->prepare("SHOW COLUMNS FROM `" . $tableName . "` LIKE :column_name");
                $columnStmt->execute([':column_name' => 'branch_id']);
                if (!$columnStmt->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->exec("ALTER TABLE `" . $tableName . "` ADD COLUMN `branch_id` int(11) DEFAULT NULL");
                }

                $pdo->exec("UPDATE `" . $tableName . "` SET `branch_id` = " . (int)$defaultBranchId . " WHERE `branch_id` IS NULL");

                $indexStmt = $pdo->query("SHOW INDEX FROM `" . $tableName . "` WHERE Key_name = 'idx_branch_id'");
                if (!$indexStmt || !$indexStmt->fetch(PDO::FETCH_ASSOC)) {
                    $pdo->exec("ALTER TABLE `" . $tableName . "` ADD INDEX `idx_branch_id` (`branch_id`)");
                }
            } catch (Exception $e) {
            }
        }
    } finally {
        branchAwareSetDisabled(false);
    }

    $schemaReady = true;
}

function branchAwareSetDisabled(bool $disabled): void
{
    $GLOBALS['branchAwareDisabled'] = $disabled;
}

function getDefaultBranchId(PDO $pdo): int
{
    ensureBranchesSchema($pdo);
    branchAwareSetDisabled(true);
    try {
        $stmt = $pdo->query("SELECT `id` FROM `branches` WHERE `is_active` = 1 ORDER BY `id` ASC LIMIT 1");
        $value = $stmt ? $stmt->fetchColumn() : false;
    } finally {
        branchAwareSetDisabled(false);
    }

    return $value ? (int)$value : 0;
}

function getBranches(PDO $pdo, bool $activeOnly = false): array
{
    ensureBranchesSchema($pdo);

    branchAwareSetDisabled(true);
    try {
        if ($activeOnly) {
            $stmt = $pdo->query("SELECT `id`, `branch_name`, `is_active` FROM `branches` WHERE `is_active` = 1 ORDER BY `branch_name` ASC");
        } else {
            $stmt = $pdo->query("SELECT `id`, `branch_name`, `is_active` FROM `branches` ORDER BY `branch_name` ASC");
        }
    } finally {
        branchAwareSetDisabled(false);
    }

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function getBranchById(PDO $pdo, int $branchId): ?array
{
    ensureBranchesSchema($pdo);

    branchAwareSetDisabled(true);
    try {
        $stmt = $pdo->prepare("SELECT `id`, `branch_name`, `is_active` FROM `branches` WHERE `id` = :id LIMIT 1");
        $stmt->execute([':id' => $branchId]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    } finally {
        branchAwareSetDisabled(false);
    }

    return $branch ?: null;
}

function resolvePublicBranchSelection(PDO $pdo, int $requestedBranchId = 0): array
{
    $branches = getBranches($pdo, true);
    $selectedBranchId = $requestedBranchId > 0
        ? $requestedBranchId
        : (count($branches) === 1 ? (int)($branches[0]['id'] ?? 0) : 0);
    $selectedBranch = null;

    if ($selectedBranchId > 0) {
        $selectedBranch = getBranchById($pdo, $selectedBranchId);
        if ($selectedBranch && (int)($selectedBranch['is_active'] ?? 0) === 1) {
            setActiveBranchSession((int)$selectedBranch['id'], (string)$selectedBranch['branch_name']);
        } else {
            $selectedBranch = null;
            $selectedBranchId = 0;
            clearActiveBranchSession();
        }
    } else {
        clearActiveBranchSession();
    }

    return [
        'branches' => $branches,
        'selected_branch_id' => $selectedBranchId,
        'selected_branch' => $selectedBranch,
    ];
}

function userCanAccessBranch(array $user, int $branchId): bool
{
    if ($branchId <= 0) {
        return false;
    }

    $scope = normalizeBranchAccessScope($user['branch_access_scope'] ?? 'all');
    if ($scope === 'all') {
        return true;
    }

    return (int)($user['branch_id'] ?? 0) === $branchId;
}
