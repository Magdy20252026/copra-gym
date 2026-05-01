<?php

function ensureTrainersSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trainers (
            id int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            commission_percentage decimal(5,2) NOT NULL DEFAULT 0.00,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trainer_commissions (
            id int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id int(10) UNSIGNED NOT NULL,
            member_id int(10) UNSIGNED DEFAULT NULL,
            source_type enum('new_subscription','partial_payment','renewal','advance_withdrawal') NOT NULL,
            source_id int(11) UNSIGNED DEFAULT NULL,
            base_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            commission_percentage decimal(5,2) NOT NULL DEFAULT 0.00,
            commission_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            notes varchar(255) DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY idx_trainer_commissions_trainer (trainer_id),
            KEY idx_trainer_commissions_source_member (source_type, member_id),
            KEY idx_trainer_commissions_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    ensureMembersTrainerIdColumn($pdo);
    ensureTrainerCommissionSourceTypeSchema($pdo);
    ensureTrainerCommissionNotesColumn($pdo);

    $schemaReady = true;
}

function ensureMembersTrainerIdColumn(PDO $pdo)
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `members` LIKE :column_name");
    $stmt->execute([':column_name' => 'trainer_id']);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE `members` ADD COLUMN `trainer_id` int(10) UNSIGNED DEFAULT NULL");
    }
}

function ensureTrainerCommissionSourceTypeSchema(PDO $pdo)
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `trainer_commissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'source_type']);
        $column = $stmt->fetch(PDO::FETCH_ASSOC);
        $columnType = isset($column['Type']) ? (string)$column['Type'] : '';
        $enumValues = [];

        if ($columnType !== '' && preg_match_all("/'([^']+)'/", $columnType, $matches)) {
            $enumValues = $matches[1];
        }

        if ($columnType !== '' && !in_array('advance_withdrawal', $enumValues, true)) {
            $pdo->exec("
                ALTER TABLE `trainer_commissions`
                MODIFY COLUMN `source_type` enum('new_subscription','partial_payment','renewal','advance_withdrawal') NOT NULL
            ");
        }
    } catch (Exception $e) {
        error_log('Trainer schema migration skipped while updating source_type: ' . $e->getMessage());
    }
}

function ensureTrainerCommissionNotesColumn(PDO $pdo)
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `trainer_commissions` LIKE :column_name");
        $stmt->execute([':column_name' => 'notes']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `trainer_commissions`
                ADD COLUMN `notes` varchar(255) DEFAULT NULL
                AFTER `commission_amount`
            ");
        }
    } catch (Exception $e) {
        error_log('Trainer schema migration skipped while adding notes column: ' . $e->getMessage());
    }
}

function getAllTrainers(PDO $pdo)
{
    ensureTrainersSchema($pdo);

    $stmt = $pdo->query("
        SELECT id, name, commission_percentage, created_at
        FROM trainers
        ORDER BY name ASC, id ASC
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTrainerById(PDO $pdo, $trainerId)
{
    ensureTrainersSchema($pdo);

    $trainerId = (int)$trainerId;
    if ($trainerId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, name, commission_percentage
        FROM trainers
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $trainerId]);
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);

    return $trainer ?: null;
}

function normalizeTrainerId($value)
{
    $trainerId = (int)$value;
    return $trainerId > 0 ? $trainerId : null;
}

function calculateTrainerCommissionAmount($paidAmount, $commissionPercentage)
{
    $paidAmount = (float)$paidAmount;
    $commissionPercentage = (float)$commissionPercentage;

    if ($paidAmount <= 0 || $commissionPercentage <= 0) {
        return 0.00;
    }

    return round($paidAmount * ($commissionPercentage / 100), 2);
}

function addTrainerCommission(PDO $pdo, $trainerId, $memberId, $sourceType, $sourceId, $baseAmount)
{
    ensureTrainersSchema($pdo);

    $trainerId = normalizeTrainerId($trainerId);
    $memberId  = (int)$memberId;
    $baseAmount = (float)$baseAmount;

    if ($trainerId === null || $memberId <= 0 || $baseAmount <= 0) {
        return 0.00;
    }

    $trainer = getTrainerById($pdo, $trainerId);
    if (!$trainer) {
        return 0.00;
    }

    $commissionPercentage = (float)$trainer['commission_percentage'];
    $commissionAmount = calculateTrainerCommissionAmount($baseAmount, $commissionPercentage);
    if ($commissionAmount <= 0) {
        return 0.00;
    }

    $stmt = $pdo->prepare("
        INSERT INTO trainer_commissions
            (trainer_id, member_id, source_type, source_id, base_amount, commission_percentage, commission_amount)
        VALUES
            (:trainer_id, :member_id, :source_type, :source_id, :base_amount, :commission_percentage, :commission_amount)
    ");
    $stmt->execute([
        ':trainer_id'            => $trainerId,
        ':member_id'             => $memberId,
        ':source_type'           => $sourceType,
        ':source_id'             => $sourceId,
        ':base_amount'           => $baseAmount,
        ':commission_percentage' => $commissionPercentage,
        ':commission_amount'     => $commissionAmount,
    ]);

    return $commissionAmount;
}

function getTrainerBalanceBetween(PDO $pdo, $trainerId, $rangeStart, $rangeEnd)
{
    ensureTrainersSchema($pdo);

    $trainerId = normalizeTrainerId($trainerId);
    if ($trainerId === null) {
        return 0.00;
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(commission_amount), 0) AS balance_total
        FROM trainer_commissions
        WHERE trainer_id = :trainer_id
          AND created_at >= :range_start
          AND created_at < :range_end
    ");
    $stmt->execute([
        ':trainer_id' => $trainerId,
        ':range_start' => $rangeStart,
        ':range_end' => $rangeEnd,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return isset($row['balance_total']) ? (float)$row['balance_total'] : 0.00;
}

function addTrainerAdvanceWithdrawal(PDO $pdo, $trainerId, $amount, $withdrawalDate, $notes = '')
{
    ensureTrainersSchema($pdo);

    $trainerId = normalizeTrainerId($trainerId);
    $amount = round((float)$amount, 2);
    $withdrawalDate = trim((string)$withdrawalDate);
    $notes = trim((string)$notes);

    if ($trainerId === null || $amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $withdrawalDate)) {
        return false;
    }

    $createdAt = $withdrawalDate . ' 00:00:00';
    $stmt = $pdo->prepare("
        INSERT INTO trainer_commissions (
            trainer_id,
            member_id,
            source_type,
            source_id,
            base_amount,
            commission_percentage,
            commission_amount,
            notes,
            created_at
        ) VALUES (
            :trainer_id,
            NULL,
            'advance_withdrawal',
            NULL,
            :base_amount,
            0.00,
            :commission_amount,
            :notes,
            :created_at
        )
    ");

    return $stmt->execute([
        ':trainer_id' => $trainerId,
        ':base_amount' => $amount,
        ':commission_amount' => -1 * $amount,
        ':notes' => $notes !== '' ? $notes : null,
        ':created_at' => $createdAt,
    ]);
}

function getTrainerMovementTypeLabels(): array
{
    return [
        'new_subscription' => 'عمولة اشتراك جديد',
        'partial_payment' => 'عمولة سداد متبقي',
        'renewal' => 'عمولة تجديد',
        'advance_withdrawal' => 'سحب سلفة من الرصيد',
    ];
}

function isCommissionFromSubscription($sourceType): bool
{
    return in_array((string)$sourceType, ['new_subscription', 'partial_payment', 'renewal'], true);
}

function getTrainerMovementMemberCode(array $movement): string
{
    $barcode = trim((string)($movement['member_barcode'] ?? ''));
    if ($barcode !== '') {
        return $barcode;
    }

    $memberId = (int)($movement['member_id'] ?? 0);
    return $memberId > 0 ? (string)$memberId : '—';
}

function formatTrainerMovementForDisplay(array $movement): array
{
    $movementTypeLabels = getTrainerMovementTypeLabels();
    $sourceType = (string)($movement['source_type'] ?? '');
    $isSubscriptionMovement = isCommissionFromSubscription($sourceType);
    $movementAmount = isset($movement['commission_amount']) ? (float)$movement['commission_amount'] : 0.0;
    $paidAmount = isset($movement['paid_amount']) ? (float)$movement['paid_amount'] : 0.0;
    $statement = trim((string)($movement['notes'] ?? ''));
    $memberName = trim((string)($movement['member_name'] ?? ''));
    $subscriptionName = trim((string)($movement['subscription_name'] ?? ''));
    $startDate = trim((string)($movement['subscription_start_date'] ?? ''));
    $endDate = trim((string)($movement['subscription_end_date'] ?? ''));

    return [
        'created_at' => (string)($movement['created_at'] ?? ''),
        'movement_type_label' => $movementTypeLabels[$sourceType] ?? $sourceType,
        'statement' => $statement !== '' ? $statement : '—',
        'member_name' => $isSubscriptionMovement && $memberName !== '' ? $memberName : '—',
        'member_code' => $isSubscriptionMovement ? getTrainerMovementMemberCode($movement) : '—',
        'subscription_name' => $isSubscriptionMovement && $subscriptionName !== '' ? $subscriptionName : '—',
        'paid_amount' => $isSubscriptionMovement ? $paidAmount : null,
        'paid_amount_display' => $isSubscriptionMovement ? number_format($paidAmount, 2) : '—',
        'subscription_start_date' => $isSubscriptionMovement && $startDate !== '' ? $startDate : '—',
        'subscription_end_date' => $isSubscriptionMovement && $endDate !== '' ? $endDate : '—',
        'commission_amount' => $movementAmount,
        'commission_amount_display' => number_format($movementAmount, 2),
    ];
}

function getTrainerBalanceMovementsDetailed(PDO $pdo, int $trainerId, ?int $limit = 100): array
{
    ensureTrainersSchema($pdo);

    $trainerId = normalizeTrainerId($trainerId);
    if ($trainerId === null) {
        return [];
    }

    $sql = "
        SELECT
            tc.id,
            tc.created_at,
            tc.source_type,
            tc.commission_amount,
            tc.base_amount,
            tc.notes,
            tc.member_id,
            m.name AS member_name,
            m.barcode AS member_barcode,
            COALESCE(
                CASE
                    WHEN tc.source_type = 'renewal' THEN renewal_subscription.name
                    ELSE current_subscription.name
                END,
                current_subscription.name
            ) AS subscription_name,
            COALESCE(
                CASE
                    WHEN tc.source_type = 'partial_payment' THEN pp.paid_amount
                    WHEN tc.source_type = 'renewal' THEN
                        CASE
                            WHEN rl.paid_now > 0 THEN rl.paid_now
                            WHEN rl.paid_amount > 0 THEN rl.paid_amount
                            ELSE rl.new_subscription_amount
                        END
                    ELSE tc.base_amount
                END,
                tc.base_amount
            ) AS paid_amount,
            COALESCE(
                CASE
                    WHEN tc.source_type = 'renewal' THEN DATE(rl.renewed_at)
                    ELSE NULL
                END,
                m.start_date
            ) AS subscription_start_date,
            m.end_date AS subscription_end_date
        FROM trainer_commissions tc
        LEFT JOIN members m
            ON m.id = tc.member_id
        LEFT JOIN subscriptions current_subscription
            ON current_subscription.id = m.subscription_id
        LEFT JOIN partial_payments pp
            ON tc.source_type = 'partial_payment'
           AND pp.id = tc.source_id
        LEFT JOIN renewals_log rl
            ON tc.source_type = 'renewal'
           AND rl.id = tc.source_id
        LEFT JOIN subscriptions renewal_subscription
            ON renewal_subscription.id = rl.new_subscription_id
        WHERE tc.trainer_id = :trainer_id
        ORDER BY tc.created_at DESC, tc.id DESC
    ";

    if ($limit !== null) {
        $limit = max(1, (int)$limit);
        $sql .= " LIMIT " . $limit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':trainer_id' => $trainerId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
