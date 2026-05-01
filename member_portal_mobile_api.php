<?php

require_once 'config.php';
require_once 'member_portal_nutrition_helpers.php';
require_once 'member_notifications_helpers.php';
require_once 'site_settings_helpers.php';

header('Content-Type: application/json; charset=UTF-8');

ensureExtendedSiteSettingsSchema($pdo);
ensureMemberNotificationsSchema($pdo);

function respondMemberPortalMobileApi(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function currentRequestBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
    $directory = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

    return $scheme . '://' . $host . ($directory === '' ? '' : $directory) . '/';
}

function absolutePortalUrl(?string $path, string $baseUrl): ?string
{
    $normalizedPath = trim((string)$path);
    if ($normalizedPath === '') {
        return null;
    }

    if (preg_match('/^https?:\/\//i', $normalizedPath)) {
        return $normalizedPath;
    }

    if (strpos($normalizedPath, '//') === 0) {
        return 'https:' . $normalizedPath;
    }

    return rtrim($baseUrl, '/') . '/' . ltrim($normalizedPath, '/');
}

$siteName = 'Gym System';
$logoPath = null;
try {
    $stmt = $pdo->query("SELECT site_name, logo_path FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $siteName = trim((string)($row['site_name'] ?? '')) ?: $siteName;
        $logoPath = $row['logo_path'] ?? null;
    }
} catch (Exception $e) {
}

$baseUrl = currentRequestBaseUrl();
$phone = trim((string)($_GET['phone'] ?? ''));
$afterId = max(0, (int)($_GET['after_id'] ?? 0));

$response = [
    'ok' => true,
    'app_name' => $siteName,
    'logo_url' => absolutePortalUrl($logoPath, $baseUrl),
    'portal_url' => absolutePortalUrl('member_portal.php', $baseUrl),
    'member_found' => false,
    'member_phone' => '',
    'notifications' => [],
    'latest_notification_id' => $afterId,
];

if ($phone === '') {
    respondMemberPortalMobileApi($response);
}

try {
    $memberData = memberPortalFindMemberData($pdo, $phone, $logoPath);
    if (!$memberData) {
        respondMemberPortalMobileApi($response);
    }

    syncMemberSubscriptionNotifications($pdo, (int)$memberData['id']);
    $notifications = getMemberPortalNotifications($pdo, (int)$memberData['id']);
    $latestNotificationId = $afterId;
    $newNotifications = [];

    foreach ($notifications as $notification) {
        $notificationId = (int)($notification['id'] ?? 0);
        if ($notificationId > $latestNotificationId) {
            $latestNotificationId = $notificationId;
        }

        if ($notificationId <= $afterId) {
            continue;
        }

        $newNotifications[] = [
            'id' => $notificationId,
            'title' => (string)($notification['title'] ?? ''),
            'message' => (string)($notification['message'] ?? ''),
            'type' => (string)($notification['notification_type'] ?? ''),
            'created_at' => formatAppDateTime12Hour($notification['created_at']),
        ];
    }

    $response['member_found'] = true;
    $response['member_phone'] = (string)$memberData['phone'];
    $response['notifications'] = $newNotifications;
    $response['latest_notification_id'] = $latestNotificationId;

    respondMemberPortalMobileApi($response);
} catch (Exception $e) {
    respondMemberPortalMobileApi([
        'ok' => false,
        'message' => 'تعذر جلب بيانات التطبيق حالياً.',
    ], 500);
}
