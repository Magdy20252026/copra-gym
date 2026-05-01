<?php

function ensureMemberNotificationsSchema(PDO $pdo): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `member_notifications` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `member_id` int DEFAULT NULL,
            `audience` varchar(20) NOT NULL DEFAULT 'all',
            `notification_type` varchar(50) NOT NULL DEFAULT 'manual',
            `title` varchar(255) NOT NULL,
            `message` text NOT NULL,
            `end_date_reference` date DEFAULT NULL,
            `created_by_user_id` int DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_member_notifications_member` (`member_id`),
            KEY `idx_member_notifications_audience` (`audience`),
            KEY `idx_member_notifications_type` (`notification_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $schemaReady = true;
}

function createBroadcastMemberNotification(PDO $pdo, string $title, string $message, ?int $createdByUserId = null): void
{
    ensureMemberNotificationsSchema($pdo);

    $stmt = $pdo->prepare("
        INSERT INTO member_notifications
            (member_id, audience, notification_type, title, message, created_by_user_id)
        VALUES
            (NULL, 'all', 'manual', :title, :message, :created_by_user_id)
    ");
    $stmt->execute([
        ':title' => $title,
        ':message' => $message,
        ':created_by_user_id' => $createdByUserId ?: null,
    ]);
}

function getManualBroadcastMemberNotifications(PDO $pdo, int $limit = 100): array
{
    ensureMemberNotificationsSchema($pdo);

    $limit = max(1, min(200, $limit));
    $stmt = $pdo->query("
        SELECT id, title, message, created_at
        FROM member_notifications
        WHERE audience = 'all' AND notification_type = 'manual'
        ORDER BY created_at DESC, id DESC
        LIMIT {$limit}
    ");

    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function deleteManualBroadcastMemberNotification(PDO $pdo, int $notificationId): bool
{
    ensureMemberNotificationsSchema($pdo);

    $stmt = $pdo->prepare("
        DELETE FROM member_notifications
        WHERE id = :id
          AND audience = 'all'
          AND notification_type = 'manual'
        LIMIT 1
    ");
    $stmt->execute([':id' => $notificationId]);

    return $stmt->rowCount() > 0;
}

function memberNotificationExists(PDO $pdo, int $memberId, string $notificationType, ?string $endDateReference): bool
{
    ensureMemberNotificationsSchema($pdo);

    if ($endDateReference === null || $endDateReference === '') {
        $stmt = $pdo->prepare("
            SELECT id
            FROM member_notifications
            WHERE member_id = :member_id
              AND audience = 'member'
              AND notification_type = :notification_type
              AND end_date_reference IS NULL
            LIMIT 1
        ");
        $stmt->execute([
            ':member_id' => $memberId,
            ':notification_type' => $notificationType,
        ]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id
            FROM member_notifications
            WHERE member_id = :member_id
              AND audience = 'member'
              AND notification_type = :notification_type
              AND end_date_reference = :end_date_reference
            LIMIT 1
        ");
        $stmt->execute([
            ':member_id' => $memberId,
            ':notification_type' => $notificationType,
            ':end_date_reference' => $endDateReference,
        ]);
    }

    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function createMemberSpecificNotification(
    PDO $pdo,
    int $memberId,
    string $notificationType,
    string $title,
    string $message,
    ?string $endDateReference = null
): void {
    ensureMemberNotificationsSchema($pdo);

    if (memberNotificationExists($pdo, $memberId, $notificationType, $endDateReference)) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO member_notifications
            (member_id, audience, notification_type, title, message, end_date_reference)
        VALUES
            (:member_id, 'member', :notification_type, :title, :message, :end_date_reference)
    ");
    $stmt->execute([
        ':member_id' => $memberId,
        ':notification_type' => $notificationType,
        ':title' => $title,
        ':message' => $message,
        ':end_date_reference' => $endDateReference ?: null,
    ]);
}

function syncMemberSubscriptionNotifications(PDO $pdo, int $memberId): void
{
    ensureMemberNotificationsSchema($pdo);

    $stmt = $pdo->prepare("
        SELECT id, name, end_date, sessions_remaining, status
        FROM members
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $memberId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        return;
    }

    $endDateText = trim((string)($member['end_date'] ?? ''));
    if ($endDateText === '') {
        return;
    }

    try {
        $today = new DateTimeImmutable(date('Y-m-d'));
        $endDate = new DateTimeImmutable($endDateText);
    } catch (Exception $e) {
        return;
    }

    $memberName = trim((string)($member['name'] ?? 'المشترك'));
    $sessionsRemaining = (int)($member['sessions_remaining'] ?? 0);
    $memberStatus = trim((string)($member['status'] ?? ''));
    $isExpired = ($endDate < $today) || $sessionsRemaining <= 0 || $memberStatus === 'منتهي';

    if ($isExpired) {
        try {
            $stmtUpdate = $pdo->prepare("UPDATE members SET status = 'منتهي' WHERE id = :id AND status <> 'منتهي'");
            $stmtUpdate->execute([':id' => $memberId]);
        } catch (Exception $e) {
        }

        createMemberSpecificNotification(
            $pdo,
            $memberId,
            'expired',
            'انتهى اشتراكك',
            'مرحباً ' . $memberName . '، انتهى اشتراكك بتاريخ ' . $endDateText . '، برجاء التواصل مع الإدارة لتجديد الاشتراك.',
            $endDateText
        );
        return;
    }

    if ($endDate >= $today) {
        $daysLeft = (int)$today->diff($endDate)->days;
        if ($daysLeft <= 5) {
            $daysText = 'اليوم';
            if ($daysLeft === 1) {
                $daysText = 'بعد يوم واحد';
            } elseif ($daysLeft === 2) {
                $daysText = 'بعد يومين';
            } elseif ($daysLeft >= 3) {
                $daysText = 'بعد ' . $daysLeft . ' أيام';
            }

            createMemberSpecificNotification(
                $pdo,
                $memberId,
                'expiring_soon',
                'اشتراكك أوشك على الانتهاء',
                'مرحباً ' . $memberName . '، اشتراكك سينتهي ' . $daysText . ' بتاريخ ' . $endDateText . '، برجاء التواصل مع الإدارة قبل انتهاء الاشتراك.',
                $endDateText
            );
        }
    }
}

function getMemberPortalNotifications(PDO $pdo, int $memberId, int $limit = 20): array
{
    ensureMemberNotificationsSchema($pdo);

    $limit = max(1, min(50, $limit));
    $stmt = $pdo->prepare("
        SELECT id, audience, notification_type, title, message, created_at
        FROM member_notifications
        WHERE audience = 'all'
           OR (audience = 'member' AND member_id = :member_id)
        ORDER BY created_at DESC, id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([':member_id' => $memberId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
?>
