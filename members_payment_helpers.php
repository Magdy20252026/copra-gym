<?php

function membersPaymentTypeColumnExists(PDO $pdo): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `members` LIKE :column_name");
    $stmt->execute([':column_name' => 'payment_type']);

    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function ensureMembersPaymentTypeSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    if (!membersPaymentTypeColumnExists($pdo)) {
        try {
            $pdo->exec("ALTER TABLE `members` ADD COLUMN `payment_type` varchar(50) NOT NULL DEFAULT 'كاش' COMMENT 'نوع الدفع' AFTER `paid_amount`");
        } catch (PDOException $e) {
            if (!membersPaymentTypeColumnExists($pdo)) {
                throw new RuntimeException('تعذر تجهيز عمود نوع الدفع للمشتركين: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    $schemaReady = true;
}

function partialPaymentsPaymentTypeColumnExists(PDO $pdo): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `partial_payments` LIKE :column_name");
    $stmt->execute([':column_name' => 'payment_type']);

    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function ensurePartialPaymentsPaymentTypeSchema(PDO $pdo): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    if (!partialPaymentsPaymentTypeColumnExists($pdo)) {
        try {
            $pdo->exec("ALTER TABLE `partial_payments` ADD COLUMN `payment_type` varchar(50) DEFAULT NULL COMMENT 'نوع الدفع وقت السداد' AFTER `paid_amount`");
        } catch (PDOException $e) {
            if (!partialPaymentsPaymentTypeColumnExists($pdo)) {
                throw new RuntimeException('تعذر تجهيز عمود نوع الدفع لمدفوعات البواقي: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    $schemaReady = true;
}

function renewalsLogPaymentTypeColumnExists(PDO $pdo): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `renewals_log` LIKE :column_name");
    $stmt->execute([':column_name' => 'payment_type']);

    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function ensureRenewalsLogPaymentTypeSchema(PDO $pdo): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    if (!renewalsLogPaymentTypeColumnExists($pdo)) {
        try {
            $pdo->exec("ALTER TABLE `renewals_log` ADD COLUMN `payment_type` varchar(50) DEFAULT NULL COMMENT 'نوع الدفع وقت التجديد' AFTER `paid_amount`");
        } catch (PDOException $e) {
            if (!renewalsLogPaymentTypeColumnExists($pdo)) {
                throw new RuntimeException('تعذر تجهيز عمود نوع الدفع للتجديدات: ' . $e->getMessage(), 0, $e);
            }
        }
    }

    $schemaReady = true;
}

function getAllowedMemberPaymentTypes(): array
{
    return ['كاش', 'فيزا', 'انستاباي', 'محفظة'];
}

function getDefaultMemberPaymentType(): string
{
    return 'كاش';
}

function isValidMemberPaymentStatsDate(string $date): bool
{
    $dateObject = DateTime::createFromFormat('Y-m-d', $date);

    return $dateObject instanceof DateTime && $dateObject->format('Y-m-d') === $date;
}

function getMemberPaymentTotalsByRange(PDO $pdo, string $startDate, string $endDate): array
{
    if (!isValidMemberPaymentStatsDate($startDate) || !isValidMemberPaymentStatsDate($endDate)) {
        throw new InvalidArgumentException('صيغة تاريخ إحصائيات المدفوعات غير صحيحة. الصيغة المطلوبة هي Y-m-d.');
    }

    if ($startDate > $endDate) {
        throw new InvalidArgumentException('تاريخ بداية إحصائيات المدفوعات يجب أن يسبق أو يساوي تاريخ النهاية.');
    }

    $allowedPaymentTypes = getAllowedMemberPaymentTypes();
    $defaultPaymentType = getDefaultMemberPaymentType();
    $totals = [];

    foreach ($allowedPaymentTypes as $paymentType) {
        $totals[$paymentType] = 0.0;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT payment_type, COALESCE(SUM(amount), 0) AS total_amount
            FROM (
                SELECT
                    COALESCE(NULLIF(TRIM(m.payment_type), ''), :default_payment_type) AS payment_type,
                    m.initial_paid_amount AS amount
                FROM members m
                WHERE DATE(m.created_at) BETWEEN :start_date AND :end_date
                  AND m.initial_paid_amount > 0

                UNION ALL

                SELECT
                    COALESCE(NULLIF(TRIM(pp.payment_type), ''), NULLIF(TRIM(m.payment_type), ''), :default_payment_type) AS payment_type,
                    pp.paid_amount AS amount
                FROM partial_payments pp
                INNER JOIN members m ON m.id = pp.member_id
                WHERE DATE(pp.paid_at) BETWEEN :start_date AND :end_date
                  AND pp.paid_amount > 0

                UNION ALL

                SELECT
                    renewal_totals.payment_type,
                    renewal_totals.amount
                FROM (
                    SELECT
                        COALESCE(NULLIF(TRIM(rl.payment_type), ''), NULLIF(TRIM(m.payment_type), ''), :default_payment_type) AS payment_type,
                        -- نعتمد paid_now أولاً لأنه يمثل المبلغ المدفوع فعلياً الآن، ثم paid_amount للتوافق مع السجلات الأقدم،
                        -- ثم new_subscription_amount كآخر fallback لبعض البيانات القديمة التي كانت تحفظ نفس قيمة الدفع هناك.
                        CASE
                            WHEN rl.paid_now > 0 THEN rl.paid_now
                            WHEN rl.paid_amount > 0 THEN rl.paid_amount
                            ELSE rl.new_subscription_amount
                        END AS amount,
                        rl.renewed_at
                    FROM renewals_log rl
                    INNER JOIN members m ON m.id = rl.member_id
                ) renewal_totals
                WHERE DATE(renewal_totals.renewed_at) BETWEEN :start_date AND :end_date
                  AND renewal_totals.amount > 0
            ) payment_totals
            GROUP BY payment_type
        ");
        $stmt->execute([
            ':default_payment_type' => $defaultPaymentType,
            ':start_date' => $startDate,
            ':end_date' => $endDate,
        ]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $paymentType = trim((string)($row['payment_type'] ?? ''));
            if ($paymentType === '' || !in_array($paymentType, $allowedPaymentTypes, true)) {
                $paymentType = $defaultPaymentType;
            }

            $totals[$paymentType] += (float)($row['total_amount'] ?? 0);
        }
    } catch (Exception $e) {
        error_log('Failed to aggregate member payment totals by range: ' . $e->getMessage());
        throw new RuntimeException('تعذر تجميع إحصائيات المدفوعات حسب نوع الدفع.', 0, $e);
    }

    $totals['الإجمالي'] = array_sum($totals);

    return $totals;
}
