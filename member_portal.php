<?php
session_start();
require_once 'config.php';
require_once 'member_portal_nutrition_helpers.php';
require_once 'member_notifications_helpers.php';
require_once 'site_settings_helpers.php';

ensureExtendedSiteSettingsSchema($pdo);

// جلب اسم الجيم واللوجو من site_settings
$siteName = "Gym System";
$logoPath = null;
$transferNumbers = [];
$workSchedules = [];
$transferTypeOptions = getSiteTransferTypeOptions();
$scheduleAudienceOptions = getSiteScheduleAudienceOptions();

try {
    $stmt = $pdo->query("SELECT site_name, logo_path, transfer_numbers_json, work_schedules_json FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $siteName = $row['site_name'];
        $logoPath = $row['logo_path'];
        $transferNumbers = decodeSiteSettingsJsonList($row['transfer_numbers_json'] ?? null);
        $workSchedules = decodeSiteSettingsJsonList($row['work_schedules_json'] ?? null);
    }
} catch (Exception $e) {}

// معالجة البحث برقم الهاتف
$phoneInput = trim($_GET['phone'] ?? '');
$memberData = null;
$memberNotifications = [];
$errorMsg   = '';

if ($phoneInput !== '') {
    try {
        $memberData = memberPortalFindMemberData($pdo, $phoneInput, $logoPath);

        if (!$memberData) {
            $errorMsg = 'لا يوجد مشترك بهذا رقم الهاتف.';
        } else {
            syncMemberSubscriptionNotifications($pdo, (int)$memberData['id']);
            $memberNotifications = getMemberPortalNotifications($pdo, (int)$memberData['id']);
        }
    } catch (Exception $e) {
        $errorMsg = 'حدث خطأ أثناء جلب بيانات المشترك.';
    }
}

// دالة توليد رابط صورة باركود (يمكن تغييرها لاحقًا لأي خدمة أخرى)
function barcodeImgUrl($text) {
    $encoded = urlencode($text);
    return "https://barcode.tec-it.com/barcode.ashx?data={$encoded}&code=Code128&dpi=96";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>استعلام عن اشتراك - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }

        :root {
            --bg: #e5e7eb;
            --card-bg: #f9fafb;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --primary: #22c55e;
            --primary-soft: rgba(34, 197, 94, 0.12);
            --danger: #ef4444;
            --border: #d1d5db;
            --input-bg: #ffffff;
        }
        body.dark {
            --bg: #020617;
            --card-bg: #020617;
            --text-main: #f9fafb;
            --text-muted: #e5e7eb;
            --primary: #38bdf8;
            --primary-soft: rgba(56,189,248,0.25);
            --danger: #fb7185;
            --border: #1f2937;
            --input-bg: #020617;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text-main);
            font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
        }

        .page {
            width: 100%;
            max-width: 960px;
            margin: 0 auto;
            padding: 16px;
        }

        .card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 14px 14px 18px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.15),
                        0 0 0 1px rgba(255,255,255,0.7);
        }

        .header-bar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:12px;
            gap:10px;
        }
        .brand {
            display:flex;
            align-items:center;
            gap:10px;
        }
        .logo {
            width:52px;height:52px;border-radius:16px;
            background:#ffffff;
            display:flex;align-items:center;justify-content:center;
            overflow:hidden;
            box-shadow:0 6px 16px rgba(15,23,42,0.25);
            flex-shrink:0;
        }
        .logo img {
            width:100%;height:100%;object-fit:contain;
        }
        .gym-name {
            font-size:18px;font-weight:900;
        }
        .gym-subtitle {
            font-size:12px;color:var(--text-muted);font-weight:700;
        }

        .theme-toggle {
            display:flex;
            justify-content:flex-end;
            flex-shrink:0;
        }
        .header-actions {
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
        }
        .theme-switch {
            position:relative;
            width:60px;
            height:28px;
            border-radius:999px;
            background:#e5e7eb;
            box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);
            cursor:pointer;
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:0 6px;
            font-size:12px;
            color:#6b7280;
            font-weight:800;
        }
        .theme-switch span { z-index:2; user-select:none; }
        .theme-thumb {
            position:absolute;
            top:3px;
            right:3px;
            width:22px;
            height:22px;
            border-radius:999px;
            background:#facc15;
            box-shadow:0 4px 10px rgba(250,204,21,0.7);
            display:flex;align-items:center;justify-content:center;
            font-size:12px;
            transition:transform .25s ease,background .25s ease,box-shadow .25s ease;
        }
        body.dark .theme-switch {
            background:#020617;
            box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);
            color:#e5e7eb;
        }
        body.dark .theme-thumb {
            transform:translateX(-28px);
            background:#0f172a;
            box-shadow:0 4px 12px rgba(15,23,42,0.9);
        }

        .search-box {
            margin-top:8px;
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            align-items:flex-end;
        }
        .field {
            display:flex;
            flex-direction:column;
            gap:4px;
        }
        .field label {
            font-size:13px;
            font-weight:800;
            color:var(--text-muted);
        }
        input[type="text"],
        input[type="number"] {
            padding:9px 12px;
            border-radius:999px;
            border:1px solid var(--border);
            background:var(--input-bg);
            font-size:15px;
            font-weight:800;
            color:var(--text-main);
            min-width:220px;
        }
        input[type="text"]:focus {
            outline:none;
            border-color:var(--primary);
            box-shadow:0 0 0 2px var(--primary-soft);
        }

        .btn-search {
            border-radius:999px;
            padding:9px 18px;
            border:none;
            cursor:pointer;
            font-size:14px;
            font-weight:900;
            background:linear-gradient(90deg,#22c55e,#16a34a);
            color:#f9fafb;
            box-shadow:0 10px 24px rgba(22,163,74,0.7);
            white-space:nowrap;
        }
        .btn-search:hover { filter:brightness(1.06); }

        .alert {
            margin-top:10px;
            padding:9px 11px;
            border-radius:10px;
            font-size:13px;
            font-weight:800;
        }
        .alert-error {
            background:rgba(239,68,68,0.08);
            border:1px solid rgba(239,68,68,0.9);
            color:var(--danger);
        }

        .member-card {
            margin-top:12px;
            border-radius:16px;
            border:1px solid var(--border);
            padding:10px 12px;
            background:rgba(15,23,42,0.01);
        }
        body.dark .member-card {
            background:rgba(15,23,42,0.7);
        }

        .member-header {
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            flex-wrap:wrap;
            gap:8px;
            margin-bottom:8px;
        }

        /* NEW: صورة المشترك في صفحة الاستعلام */
        .member-photo-wrap {
            display:flex;
            align-items:center;
            gap:10px;
        }
        .member-photo {
            width: 110px;
            height: 110px;
            border-radius: 20px;
            object-fit: cover;
            background: #ffffff;
            border: 1px solid var(--border);
            box-shadow: 0 10px 26px rgba(15,23,42,0.22);
            flex-shrink: 0;
        }
        body.dark .member-photo {
            background: #0b1220;
        }

        .member-main {
            font-size:16px;
            font-weight:900;
        }
        .member-sub {
            font-size:13px;
            color:var(--text-muted);
            font-weight:700;
        }

        .status-badge {
            border-radius:999px;
            padding:4px 10px;
            font-size:12px;
            font-weight:900;
        }
        .status-active {
            background:rgba(22,163,74,0.15);
            color:#166534;
        }
        .status-ended {
            background:rgba(239,68,68,0.15);
            color:#b91c1c;
        }
        .status-frozen {
            background:rgba(59,130,246,0.18);
            color:#1d4ed8;
        }

        .notifications-wrap {
            position:relative;
        }
        .notifications-bell {
            border:none;
            border-radius:999px;
            background:linear-gradient(90deg,#f59e0b,#ea580c);
            color:#fff;
            cursor:pointer;
            font-size:18px;
            font-weight:900;
            min-width:52px;
            height:44px;
            padding:0 14px;
            display:flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            box-shadow:0 10px 24px rgba(234,88,12,0.35);
        }
        .notifications-count {
            min-width:22px;
            height:22px;
            border-radius:999px;
            background:rgba(255,255,255,0.25);
            display:inline-flex;
            align-items:center;
            justify-content:center;
            font-size:12px;
            padding:0 6px;
        }
        .notifications-panel {
            display:none;
            margin-top:10px;
            border:1px solid var(--border);
            border-radius:16px;
            padding:12px;
            background:rgba(255,255,255,0.88);
        }
        body.dark .notifications-panel {
            background:rgba(2,6,23,0.84);
        }
        .notifications-panel.is-open {
            display:block;
        }
        .notifications-title {
            font-size:15px;
            font-weight:900;
            margin-bottom:10px;
        }
        .notifications-list {
            display:flex;
            flex-direction:column;
            gap:8px;
        }
        .notification-item {
            border:1px solid var(--border);
            border-radius:12px;
            padding:10px;
            background:rgba(34,197,94,0.05);
        }
        body.dark .notification-item {
            background:rgba(15,23,42,0.75);
        }
        .notification-item--manual {
            border-right:4px solid #2563eb;
        }
        .notification-item--expiring_soon {
            border-right:4px solid #f59e0b;
        }
        .notification-item--expired {
            border-right:4px solid #ef4444;
        }
        .notification-item-title {
            font-size:14px;
            font-weight:900;
            margin-bottom:4px;
        }
        .notification-item-message {
            font-size:13px;
            line-height:1.8;
            font-weight:700;
        }
        .notification-item-time {
            margin-top:6px;
            font-size:12px;
            color:var(--text-muted);
            font-weight:700;
        }

        .grid {
            display:grid;
            grid-template-columns: repeat(auto-fit,minmax(160px,1fr));
            gap:8px;
        }
        .info-item {
            border-radius:12px;
            border:1px solid var(--border);
            padding:7px 9px;
            font-size:13px;
        }
        .info-label {
            color:var(--text-muted);
            font-weight:700;
            margin-bottom:2px;
        }
        .info-value {
            font-weight:900;
        }

        .barcode-box {
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            gap:4px;
        }
        .barcode-box img {
            width:100%;
            max-width:210px;
            background:#ffffff;
            padding:5px;
            border-radius:8px;
            box-shadow:0 5px 16px rgba(15,23,42,0.3);
        }

        .assistant-panel {
            margin-top: 14px;
            border-radius: 16px;
            border: 1px solid var(--border);
            background: linear-gradient(180deg, rgba(34,197,94,0.08), rgba(59,130,246,0.05));
            padding: 14px;
        }
        .assistant-head {
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:12px;
        }
        .assistant-title {
            font-size:18px;
            font-weight:900;
            margin-bottom:4px;
        }
        .assistant-subtitle {
            font-size:13px;
            color:var(--text-muted);
            font-weight:700;
            line-height:1.7;
        }
        .assistant-pill {
            display:inline-flex;
            align-items:center;
            gap:6px;
            border-radius:999px;
            padding:7px 12px;
            background:rgba(15,23,42,0.08);
            font-size:12px;
            font-weight:900;
        }
        .assistant-chat {
            display:none;
            margin-top:10px;
        }
        .assistant-chat.is-open {
            display:block;
        }
        .assistant-messages {
            display:flex;
            flex-direction:column;
            gap:10px;
            max-height:340px;
            overflow:auto;
            padding-left:4px;
        }
        .assistant-message {
            max-width:88%;
            border-radius:16px;
            padding:11px 13px;
            line-height:1.8;
            font-size:14px;
            font-weight:700;
            white-space:pre-line;
            border:1px solid var(--border);
        }
        .assistant-message--bot {
            align-self:flex-start;
            background:#ffffff;
        }
        .assistant-message--user {
            align-self:flex-end;
            background:rgba(34,197,94,0.14);
        }
        body.dark .assistant-message--bot {
            background:rgba(2,6,23,0.85);
        }
        .assistant-input-row {
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            margin-top:12px;
        }
        .assistant-input-row input {
            flex:1 1 220px;
            min-width:0;
        }
        .assistant-actions {
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            margin-top:12px;
        }
        .btn-secondary {
            background:linear-gradient(90deg,#3b82f6,#1d4ed8);
            box-shadow:0 10px 24px rgba(29,78,216,0.35);
        }
        .btn-export {
            background:linear-gradient(90deg,#0ea5e9,#2563eb);
            box-shadow:0 10px 24px rgba(37,99,235,0.35);
            display:none;
        }
        .assistant-plan {
            display:none;
            margin-top:14px;
            border-radius:14px;
            border:1px solid var(--border);
            background:rgba(255,255,255,0.72);
            padding:14px;
        }
        body.dark .assistant-plan {
            background:rgba(2,6,23,0.7);
        }
        .assistant-plan.is-visible,
        .btn-export.is-visible {
            display:block;
        }
        .assistant-summary-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
            gap:8px;
            margin-top:10px;
        }
        .assistant-summary-item {
            border-radius:12px;
            border:1px solid var(--border);
            padding:10px;
        }
        .assistant-summary-item strong {
            display:block;
            margin-bottom:4px;
            font-size:13px;
        }
        .assistant-plan h4 {
            margin:16px 0 8px;
            font-size:15px;
        }
        .assistant-plan ul {
            margin:0;
            padding-right:18px;
            line-height:1.9;
        }
        .assistant-plan li + li {
            margin-top:4px;
        }

        .portal-footer-info {
            margin-top: 16px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 12px;
        }
        .portal-footer-card {
            border-radius: 16px;
            border: 1px solid var(--border);
            padding: 14px;
            background: rgba(255,255,255,0.8);
        }
        body.dark .portal-footer-card {
            background: rgba(2,6,23,0.8);
        }
        .portal-footer-title {
            font-size: 16px;
            font-weight: 900;
            margin-bottom: 10px;
        }
        .portal-footer-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .portal-footer-item {
            border-radius: 12px;
            border: 1px solid var(--border);
            padding: 10px;
            background: rgba(34,197,94,0.06);
        }
        body.dark .portal-footer-item {
            background: rgba(15,23,42,0.75);
        }
        .portal-footer-item strong {
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
        }
        .portal-footer-item span {
            display: block;
            font-size: 13px;
            line-height: 1.8;
            font-weight: 700;
        }
        .portal-footer-empty {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 700;
            line-height: 1.8;
        }

        @media (max-width: 480px) {
            .card { padding:12px 10px 16px; }
            .gym-name { font-size:16px; }
            .search-box { flex-direction:column; align-items:stretch; }
            .btn-search { width:100%; justify-content:center; }
            .assistant-message { max-width:100%; }

            /* صورة أوضح للموبايل */
            .member-photo { width: 95px; height: 95px; border-radius: 18px; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="card">
        <div class="header-bar">
            <div class="brand">
                <div class="logo">
                    <?php if ($logoPath): ?>
                        <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="شعار الجيم">
                    <?php else: ?>
                        <span style="font-size:30px;">🏋️‍♂️</span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="gym-name"><?php echo htmlspecialchars($siteName); ?></div>
                    <div class="gym-subtitle">استعلام حالة الاشتراك للمشتركين</div>
                </div>
            </div>
            <div class="header-actions">
                <?php if ($memberData): ?>
                    <div class="notifications-wrap">
                        <button type="button" class="notifications-bell" id="notificationsBell">
                            <span>🔔</span>
                            <span class="notifications-count"><?php echo count($memberNotifications); ?></span>
                        </button>
                    </div>
                <?php endif; ?>
                <div class="theme-toggle">
                    <div class="theme-switch" id="themeSwitch">
                        <span>🌙</span>
                        <span>☀️</span>
                        <div class="theme-thumb" id="themeThumb">☀️</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- نموذج البحث -->
        <form method="get" action="" class="search-box">
            <div class="field">
                <label for="phone">رقم الهاتف</label>
                <input type="text" id="phone" name="phone"
                       placeholder="اكتب رقم الهاتف واضغط بحث"
                       value="<?php echo htmlspecialchars($phoneInput); ?>">
            </div>
            <button type="submit" class="btn-search">🔍 بحث</button>
        </form>

        <?php if ($errorMsg): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($errorMsg); ?>
            </div>
        <?php endif; ?>

        <?php if ($memberData): ?>
                <div class="member-card">
                    <div class="notifications-panel" id="notificationsPanel">
                        <div class="notifications-title">إشعاراتك الحالية</div>
                        <?php if ($memberNotifications): ?>
                            <div class="notifications-list">
                                <?php foreach ($memberNotifications as $notification): ?>
                                    <div class="notification-item notification-item--<?php echo htmlspecialchars($notification['notification_type']); ?>">
                                        <div class="notification-item-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                                        <div class="notification-item-message"><?php echo htmlspecialchars($notification['message']); ?></div>
                                        <div class="notification-item-time"><?php echo htmlspecialchars(formatAppDateTime12Hour($notification['created_at'])); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="notification-item">
                                <div class="notification-item-message">لا توجد إشعارات حالياً.</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="member-header">
                        <div class="member-photo-wrap">
                            <?php if (!empty($memberData['display_photo'])): ?>
                            <img class="member-photo" src="<?php echo htmlspecialchars($memberData['display_photo']); ?>" alt="صورة المشترك">
                        <?php else: ?>
                            <div class="member-photo" style="display:flex;align-items:center;justify-content:center;font-size:42px;">
                                👤
                            </div>
                        <?php endif; ?>

                        <div>
                            <div class="member-main">
                                <?php echo htmlspecialchars($memberData['name']); ?>
                            </div>
                            <div class="member-sub">
                                هاتف: <?php echo htmlspecialchars($memberData['phone']); ?>
                            </div>
                        </div>
                    </div>

                    <div>
                        <?php
                        $status = $memberData['status'];
                        $statusClass = 'status-ended';
                        $statusText  = $status;
                        if ($status === 'مستمر') {
                            $statusClass = 'status-active';
                        } elseif ($status === 'مجمد') {
                            $statusClass = 'status-frozen';
                        }
                        ?>
                        <span class="status-badge <?php echo $statusClass; ?>">
                            حالة الاشتراك: <?php echo htmlspecialchars($statusText); ?>
                        </span>
                    </div>
                </div>

                <div class="grid">
                    <div class="info-item">
                        <div class="info-label">نوع الاشتراك</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($memberData['subscription_name']); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">تاريخ بداية الاشتراك</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($memberData['start_date']); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">تاريخ نهاية الاشتراك</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($memberData['end_date']); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">عدد الأيام المتبقية</div>
                        <div class="info-value">
                            <?php echo ($memberData['days_left'] !== null)
                                ? (int)$memberData['days_left']
                                : '—';
                            ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">عدد التمارين المتبقية</div>
                        <div class="info-value">
                            <?php echo (int)$memberData['sessions_remaining']; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">عدد جلسات السبا المتبقية</div>
                        <div class="info-value">
                            <?php echo (int)$memberData['spa_count']; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">عدد جلسات المساج المتبقية</div>
                        <div class="info-value">
                            <?php echo (int)$memberData['massage_count']; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">عدد جلسات الجاكوزي المتبقية</div>
                        <div class="info-value">
                            <?php echo (int)$memberData['jacuzzi_count']; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">أيام الـ Freeze المسموحة</div>
                        <div class="info-value">
                            <?php echo (int)$memberData['freeze_days']; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">أيام الـ Freeze المستخدمة</div>
                        <div class="info-value">
                            <?php echo (int)$memberData['freeze_used_days']; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">أيام الـ Freeze المتبقية</div>
                        <div class="info-value">
                            <?php echo (int)$memberData['freeze_left_days']; ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">المدفوع</div>
                        <div class="info-value">
                            <?php echo number_format($memberData['paid_amount'], 2); ?>
                        </div>
                    </div>

                    <div class="info-item">
                        <div class="info-label">المتبقي</div>
                        <div class="info-value">
                            <?php echo number_format($memberData['remaining_amount'], 2); ?>
                        </div>
                    </div>

                    <div class="info-item barcode-box">
                        <div class="info-label">الباركود</div>
                        <?php if (!empty($memberData['barcode'])): ?>
                            <div class="info-value">
                                <?php echo htmlspecialchars($memberData['barcode']); ?>
                            </div>
                            <img src="<?php echo htmlspecialchars(barcodeImgUrl($memberData['barcode'])); ?>"
                                 alt="باركود المشترك">
                        <?php else: ?>
                            <div class="info-value">لا يوجد باركود مسجل لهذا المشترك.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="assistant-panel" data-member-phone="<?php echo htmlspecialchars($memberData['phone']); ?>" data-member-name="<?php echo htmlspecialchars($memberData['name']); ?>" data-site-name="<?php echo htmlspecialchars($siteName); ?>">
                    <div class="assistant-head">
                        <div>
                            <div class="assistant-title">ابدأ محادثة مع كابتن MO</div>
                            <div class="assistant-subtitle">
                                بعد مراجعة تفاصيل اشتراكك، كابتن MO يسألك عن السن والوزن ونسبة الدهون
                                ثم يجهز لك نظامًا غذائيًا مناسبًا للأكل والفواكه المتوفرة في مصر.
                            </div>
                        </div>
                        <div class="assistant-pill">🤖 AI Coach</div>
                    </div>

                    <button type="button" class="btn-search btn-secondary" id="captainMegzStart">💬 ابدأ المحادثة</button>

                    <div class="assistant-chat" id="captainMegzChat">
                        <div class="assistant-messages" id="captainMegzMessages"></div>

                        <div class="assistant-input-row">
                            <input type="text" id="captainMegzInput" placeholder="اكتب ردك هنا">
                            <button type="button" class="btn-search" id="captainMegzSend">إرسال</button>
                        </div>

                        <div class="assistant-actions">
                            <button type="button" class="btn-search btn-secondary" id="captainMegzRestart">بدء من جديد</button>
                            <button type="button" class="btn-search btn-export" id="captainMegzExport">📷 تحميل النظام الغذائي كصورة PNG</button>
                        </div>

                        <div class="assistant-plan" id="captainMegzPlan"></div>
                    </div>
                </div>
        <?php endif; ?>

        <div class="portal-footer-info">
            <div class="portal-footer-card">
                <div class="portal-footer-title">أرقام التحويل المتاحة</div>
                <?php
                $hasTransferNumbers = false;
                foreach ($transferNumbers as $transferEntry):
                    $transferNumber = trim((string)($transferEntry['number'] ?? ''));
                    if ($transferNumber === '') {
                        continue;
                    }
                    $hasTransferNumbers = true;
                    $transferType = trim((string)($transferEntry['type'] ?? 'wallet'));
                    $transferTypeLabel = $transferTypeOptions[$transferType] ?? $transferTypeOptions['wallet'];
                ?>
                    <div class="portal-footer-item">
                        <strong><?php echo htmlspecialchars($transferTypeLabel); ?></strong>
                        <span><?php echo htmlspecialchars($transferNumber); ?></span>
                    </div>
                <?php endforeach; ?>

                <?php if (!$hasTransferNumbers): ?>
                    <div class="portal-footer-empty">لا توجد أرقام تحويل مضافة حالياً.</div>
                <?php endif; ?>
            </div>

            <div class="portal-footer-card">
                <div class="portal-footer-title">مواعيد العمل</div>
                <?php
                $hasWorkSchedules = false;
                foreach ($workSchedules as $scheduleEntry):
                    $scheduleFrom = trim((string)($scheduleEntry['from'] ?? ''));
                    $scheduleTo = trim((string)($scheduleEntry['to'] ?? ''));
                    if ($scheduleFrom === '' || $scheduleTo === '') {
                        continue;
                    }
                    $hasWorkSchedules = true;
                    $scheduleLabel = trim((string)($scheduleEntry['label'] ?? ''));
                    if ($scheduleLabel === '') {
                        $scheduleLabel = 'من ' . formatAppTime12Hour($scheduleFrom) . ' إلى ' . formatAppTime12Hour($scheduleTo);
                    }
                    $scheduleAudience = trim((string)($scheduleEntry['audience'] ?? 'all'));
                    $scheduleAudienceLabel = $scheduleAudienceOptions[$scheduleAudience] ?? $scheduleAudienceOptions['all'];
                ?>
                    <div class="portal-footer-item">
                        <strong><?php echo htmlspecialchars($scheduleLabel); ?></strong>
                        <span>الوقت: <?php echo htmlspecialchars(formatAppTime12Hour($scheduleFrom) . ' - ' . formatAppTime12Hour($scheduleTo)); ?></span>
                        <span>الفئة: <?php echo htmlspecialchars($scheduleAudienceLabel); ?></span>
                    </div>
                <?php endforeach; ?>

                <?php if (!$hasWorkSchedules): ?>
                    <div class="portal-footer-empty">لا توجد مواعيد عمل مضافة حالياً.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    // تبديل الثيم
    const body      = document.body;
    const switchEl  = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymPublicTheme') || 'light';

    function applyTheme(mode) {
        if (mode === 'dark') body.classList.add('dark');
        else body.classList.remove('dark');
        localStorage.setItem('gymPublicTheme', mode);
    }
    applyTheme(savedTheme);

    if (switchEl) {
        switchEl.addEventListener('click', () => {
            const isDark = body.classList.contains('dark');
            applyTheme(isDark ? 'light' : 'dark');
        });
    }

    const memberPhoneStorageKey = 'gymPortalLastMemberPhone';
    const phoneInputEl = document.getElementById('phone');
    const searchForm = document.querySelector('.search-box');
    const currentMemberPhone = <?php echo json_encode($memberData['phone'] ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const currentSearchPhone = <?php echo json_encode($phoneInput, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const wasAutoRestored = <?php echo json_encode(isset($_GET['restored']) && (string)$_GET['restored'] === '1'); ?>;

    if (currentMemberPhone) {
        localStorage.setItem(memberPhoneStorageKey, currentMemberPhone);
        if (window.TheClubGymAndroid && typeof window.TheClubGymAndroid.saveLastMemberPhone === 'function') {
            window.TheClubGymAndroid.saveLastMemberPhone(currentMemberPhone);
        }
    } else if (wasAutoRestored && currentSearchPhone) {
        localStorage.removeItem(memberPhoneStorageKey);
        if (window.TheClubGymAndroid && typeof window.TheClubGymAndroid.clearLastMemberPhone === 'function') {
            window.TheClubGymAndroid.clearLastMemberPhone();
        }
    } else if (!currentSearchPhone) {
        const savedPhone = localStorage.getItem(memberPhoneStorageKey);
        if (savedPhone) {
            const restoredUrl = new URL(window.location.href);
            restoredUrl.searchParams.set('phone', savedPhone);
            restoredUrl.searchParams.set('restored', '1');
            window.location.replace(restoredUrl.toString());
        }
    }

    if (searchForm && phoneInputEl) {
        searchForm.addEventListener('submit', () => {
            phoneInputEl.value = phoneInputEl.value.trim();
        });
    }

    const notificationsBell = document.getElementById('notificationsBell');
    const notificationsPanel = document.getElementById('notificationsPanel');
    if (notificationsBell && notificationsPanel) {
        notificationsBell.addEventListener('click', () => {
            notificationsPanel.classList.toggle('is-open');
        });
    }

    const assistantRoot = document.querySelector('[data-member-phone]');
    if (assistantRoot) {
        const startButton = document.getElementById('captainMegzStart');
        const chatBox = document.getElementById('captainMegzChat');
        const messagesEl = document.getElementById('captainMegzMessages');
        const inputEl = document.getElementById('captainMegzInput');
        const sendButton = document.getElementById('captainMegzSend');
        const restartButton = document.getElementById('captainMegzRestart');
        const planEl = document.getElementById('captainMegzPlan');
        const exportButton = document.getElementById('captainMegzExport');

        const prompts = [
            {
                key: 'age',
                placeholder: 'اكتب سنك',
                question: 'أهلاً بك، أنا كابتن MO 💪\nقبل ما أجهز لك نظامك الغذائي، محتاج أعرف كام سنة؟',
                validate(value) {
                    const number = Number(normalizeDigits(value));
                    if (!Number.isFinite(number) || number < 12 || number > 80) {
                        return 'من فضلك اكتب سن صحيح بين 12 و 80 سنة.';
                    }
                    return '';
                }
            },
            {
                key: 'weight',
                placeholder: 'اكتب وزنك بالكيلو',
                question: 'تمام 👌\nدلوقتي قولي وزنك الحالي كام كجم؟',
                validate(value) {
                    const number = Number(normalizeDigits(value));
                    if (!Number.isFinite(number) || number < 35 || number > 250) {
                        return 'من فضلك اكتب وزن صحيح بين 35 و 250 كجم.';
                    }
                    return '';
                }
            },
            {
                key: 'body_fat',
                placeholder: 'اكتب نسبة الدهون %',
                question: 'ممتاز.\nآخر حاجة: نسبة الدهون في جسمك كام تقريبًا؟ اكتب الرقم بالنسبة المئوية.',
                validate(value) {
                    const number = Number(normalizeDigits(value));
                    if (!Number.isFinite(number) || number < 4 || number > 60) {
                        return 'من فضلك اكتب نسبة دهون صحيحة بين 4% و 60%.';
                    }
                    return '';
                }
            }
        ];

        const state = {
            step: 0,
            answers: {},
            running: false,
            waiting: false,
            planData: null
        };

        function normalizeDigits(value) {
            const digitMap = {
                '٠': '0',
                '١': '1',
                '٢': '2',
                '٣': '3',
                '٤': '4',
                '٥': '5',
                '٦': '6',
                '٧': '7',
                '٨': '8',
                '٩': '9'
            };

            return String(value || '').trim().replace(/[٠-٩]/g, function (digit) {
                return digitMap[digit] || digit;
            }).replace(/[٫،]/g, '.');
        }

        function addMessage(role, text) {
            const message = document.createElement('div');
            message.className = 'assistant-message assistant-message--' + role;
            message.textContent = text;
            messagesEl.appendChild(message);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

        function resetPlanPreview() {
            planEl.innerHTML = '';
            planEl.classList.remove('is-visible');
            exportButton.classList.remove('is-visible');
            state.planData = null;
        }

        function openChat() {
            startButton.style.display = 'none';
            chatBox.classList.add('is-open');
            inputEl.focus();
        }

        function askCurrentQuestion() {
            const prompt = prompts[state.step];
            if (!prompt) {
                return;
            }
            inputEl.value = '';
            inputEl.placeholder = prompt.placeholder;
            addMessage('bot', prompt.question);
        }

        function startConversation() {
            state.step = 0;
            state.answers = {};
            state.running = true;
            state.waiting = false;
            messagesEl.innerHTML = '';
            resetPlanPreview();
            openChat();
            addMessage('bot', 'أنا هنا حتى أجهز لك نظامًا غذائيًا عمليًا يناسبك ويناسب الأكل المتاح في مصر.');
            askCurrentQuestion();
        }

        function renderPlan(data) {
            const summaryItems = [
                { label: 'الهدف', value: data.goal_label },
                { label: 'السعرات اليومية', value: data.daily_calories + ' سعرة' },
                { label: 'البروتين', value: data.protein_grams + ' جم' },
                { label: 'الكربوهيدرات', value: data.carbs_grams + ' جم' },
                { label: 'الدهون', value: data.fat_grams + ' جم' },
                { label: 'المياه', value: data.water_liters + ' لتر' }
            ];

            let html = '<div class="assistant-subtitle">' + escapeHtml(data.goal_note) + '</div>';
            html += '<div class="assistant-summary-grid">';
            summaryItems.forEach(function (item) {
                html += '<div class="assistant-summary-item"><strong>' + escapeHtml(item.label) + '</strong><span>' + escapeHtml(item.value) + '</span></div>';
            });
            html += '</div>';

            html += '<h4>الوجبات اليومية المقترحة</h4><ul>';
            data.meals.forEach(function (meal) {
                html += '<li><strong>' + escapeHtml(meal.name + ' - ' + meal.time) + '</strong><ul>';
                meal.items.forEach(function (item) {
                    html += '<li>' + escapeHtml(item) + '</li>';
                });
                html += '<li><strong>بديل:</strong> ' + escapeHtml(meal.alternatives) + '</li></ul></li>';
            });
            html += '</ul>';

            html += '<h4>نصائح كابتن MO</h4><ul>';
            data.tips.forEach(function (tip) {
                html += '<li>' + escapeHtml(tip) + '</li>';
            });
            html += '</ul>';

            planEl.innerHTML = html;
            planEl.classList.add('is-visible');
        }

        function buildPlanImageMarkup(data) {
            const summaryItems = [
                { label: 'اسم المشترك', value: data.member_name || assistantRoot.dataset.memberName || '-' },
                { label: 'رقم الهاتف', value: data.phone || assistantRoot.dataset.memberPhone || '-' },
                { label: 'نوع الاشتراك', value: data.subscription_name || '-' },
                { label: 'السن', value: data.age + ' سنة' },
                { label: 'الوزن', value: data.weight + ' كجم' },
                { label: 'نسبة الدهون', value: data.body_fat + '٪' },
                { label: 'الهدف', value: data.goal_label },
                { label: 'السعرات اليومية', value: data.daily_calories + ' سعرة' },
                { label: 'البروتين', value: data.protein_grams + ' جم' },
                { label: 'الكربوهيدرات', value: data.carbs_grams + ' جم' },
                { label: 'الدهون', value: data.fat_grams + ' جم' },
                { label: 'المياه', value: data.water_liters + ' لتر' }
            ];

            let html = '<div xmlns="http://www.w3.org/1999/xhtml" style="direction:rtl;font-family:Tahoma,Arial,sans-serif;background:linear-gradient(180deg,#eff6ff,#f8fafc);color:#0f172a;padding:32px;">';
            html += '<div style="max-width:880px;margin:0 auto;background:#ffffff;border-radius:24px;padding:32px;box-shadow:0 24px 50px rgba(15,23,42,0.12);border:1px solid #dbeafe;">';
            html += '<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;margin-bottom:20px;">';
            html += '<div><div style="font-size:14px;font-weight:700;color:#2563eb;margin-bottom:8px;">' + escapeHtml(assistantRoot.dataset.siteName || 'Gym System') + '</div>';
            html += '<div style="font-size:28px;font-weight:900;margin-bottom:8px;">النظام الغذائي المخصص</div>';
            html += '<div style="font-size:16px;font-weight:700;color:#475569;">' + escapeHtml(data.coach_name || 'كابتن MO') + '</div></div>';
            html += '<div style="background:#dbeafe;color:#1d4ed8;border-radius:999px;padding:10px 18px;font-size:14px;font-weight:800;">جاهز للتحميل كصورة</div>';
            html += '</div>';
            html += '<div style="font-size:16px;line-height:1.9;background:#eff6ff;border:1px solid #bfdbfe;border-radius:18px;padding:18px;margin-bottom:20px;">' + escapeHtml(data.goal_note) + '</div>';
            html += '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-bottom:24px;">';
            summaryItems.forEach(function (item) {
                html += '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;padding:14px 16px;">';
                html += '<div style="font-size:13px;font-weight:800;color:#475569;margin-bottom:6px;">' + escapeHtml(item.label) + '</div>';
                html += '<div style="font-size:18px;font-weight:900;color:#0f172a;">' + escapeHtml(item.value) + '</div>';
                html += '</div>';
            });
            html += '</div>';

            html += '<div style="margin-bottom:24px;"><div style="font-size:21px;font-weight:900;margin-bottom:14px;">الوجبات اليومية المقترحة</div>';
            data.meals.forEach(function (meal) {
                html += '<div style="border:1px solid #cbd5e1;border-radius:18px;padding:16px 18px;background:#ffffff;margin-bottom:12px;">';
                html += '<div style="font-size:18px;font-weight:900;color:#0f172a;margin-bottom:6px;">' + escapeHtml(meal.name) + '</div>';
                html += '<div style="font-size:14px;font-weight:700;color:#2563eb;margin-bottom:10px;">' + escapeHtml(meal.time) + '</div>';
                html += '<ul style="margin:0;padding-right:20px;line-height:1.9;font-size:15px;">';
                meal.items.forEach(function (item) {
                    html += '<li style="margin-bottom:4px;">' + escapeHtml(item) + '</li>';
                });
                html += '</ul>';
                html += '<div style="margin-top:10px;background:#f8fafc;border-radius:14px;padding:12px 14px;font-size:14px;line-height:1.8;"><strong>بديل مناسب:</strong> ' + escapeHtml(meal.alternatives) + '</div>';
                html += '</div>';
            });
            html += '</div>';

            html += '<div style="margin-bottom:24px;"><div style="font-size:21px;font-weight:900;margin-bottom:14px;">نصائح كابتن MO</div><ul style="margin:0;padding-right:20px;line-height:1.9;font-size:15px;">';
            data.tips.forEach(function (tip) {
                html += '<li style="margin-bottom:6px;">' + escapeHtml(tip) + '</li>';
            });
            html += '</ul></div>';

            html += '<div style="font-size:13px;color:#64748b;border-top:1px solid #e2e8f0;padding-top:16px;line-height:1.8;">';
            html += 'تم تجهيز هذه الخطة بناءً على البيانات المدخلة في صفحة المشترك، ويمكنك مشاركتها مباشرة كصورة.';
            html += '</div></div></div>';

            return html;
        }

        function wrapCanvasText(context, text, maxWidth) {
            const normalizedText = String(text || '').trim().replace(/\s+/g, ' ');
            if (!normalizedText) {
                return [''];
            }

            const words = normalizedText.split(' ');
            const lines = [];
            let currentLine = words.shift() || '';

            words.forEach(function (word) {
                const candidate = currentLine ? currentLine + ' ' + word : word;
                if (context.measureText(candidate).width <= maxWidth) {
                    currentLine = candidate;
                    return;
                }

                lines.push(currentLine);
                currentLine = word;
            });

            if (currentLine) {
                lines.push(currentLine);
            }

            return lines;
        }

        function measureCanvasTextBlock(context, text, maxWidth, font, lineHeight) {
            context.font = font;
            const lines = wrapCanvasText(context, text, maxWidth);

            return {
                lines: lines,
                height: Math.max(lineHeight, lines.length * lineHeight)
            };
        }

        function drawWrappedCanvasText(context, text, x, y, maxWidth, font, lineHeight, fillStyle) {
            const block = measureCanvasTextBlock(context, text, maxWidth, font, lineHeight);
            context.font = font;
            context.fillStyle = fillStyle;
            block.lines.forEach(function (line, index) {
                context.fillText(line, x, y + (index * lineHeight));
            });

            return {
                lines: block.lines,
                nextY: y + block.height
            };
        }

        function drawRoundedRect(context, x, y, width, height, radius, fillStyle, strokeStyle) {
            context.beginPath();
            context.moveTo(x + radius, y);
            context.arcTo(x + width, y, x + width, y + height, radius);
            context.arcTo(x + width, y + height, x, y + height, radius);
            context.arcTo(x, y + height, x, y, radius);
            context.arcTo(x, y, x + width, y, radius);
            context.closePath();

            if (fillStyle) {
                context.fillStyle = fillStyle;
                context.fill();
            }

            if (strokeStyle) {
                context.strokeStyle = strokeStyle;
                context.lineWidth = 1;
                context.stroke();
            }
        }

        async function canvasToPngBlob(canvas) {
            const blob = await new Promise(function (resolve) {
                canvas.toBlob(resolve, 'image/png');
            });

            if (blob) {
                return blob;
            }

            const dataUrl = canvas.toDataURL('image/png');
            const response = await fetch(dataUrl);
            return response.blob();
        }

        async function blobToDataUrl(blob) {
            return new Promise(function (resolve, reject) {
                const reader = new FileReader();
                reader.onload = function () {
                    resolve(reader.result);
                };
                reader.onerror = function () {
                    reject(reader.error || new Error('Failed to convert blob to data URL for download'));
                };
                reader.readAsDataURL(blob);
            });
        }

        async function buildPlanPngBlob(data) {
            if (document.fonts && document.fonts.ready) {
                try {
                    await document.fonts.ready;
                } catch (error) {
                    console.warn('Nutrition PNG export font readiness check failed', error);
                }
            }

            const pageWidth = 980;
            const outerPadding = 32;
            const cardPadding = 32;
            const contentWidth = pageWidth - (outerPadding * 2) - (cardPadding * 2);
            const sectionGap = 24;
            const boxGap = 12;
            const minCanvasScale = 2;
            const maxCanvasScale = 3;
            const readyBadgeWidth = 190;
            const readyBadgeHeight = 44;
            const readyBadgeText = 'جاهز للتحميل كصورة PNG';
            const footerText = 'تم تجهيز هذه الخطة بناءً على البيانات المدخلة في صفحة المشترك، ويمكنك مشاركتها مباشرة كصورة PNG.';
            const summaryItems = [
                { label: 'اسم المشترك', value: data.member_name || assistantRoot.dataset.memberName || '-' },
                { label: 'رقم الهاتف', value: data.phone || assistantRoot.dataset.memberPhone || '-' },
                { label: 'نوع الاشتراك', value: data.subscription_name || '-' },
                { label: 'السن', value: data.age + ' سنة' },
                { label: 'الوزن', value: data.weight + ' كجم' },
                { label: 'نسبة الدهون', value: data.body_fat + '٪' },
                { label: 'الهدف', value: data.goal_label },
                { label: 'السعرات اليومية', value: data.daily_calories + ' سعرة' },
                { label: 'البروتين', value: data.protein_grams + ' جم' },
                { label: 'الكربوهيدرات', value: data.carbs_grams + ' جم' },
                { label: 'الدهون', value: data.fat_grams + ' جم' },
                { label: 'المياه', value: data.water_liters + ' لتر' }
            ];
            const summaryColumnWidth = (contentWidth - boxGap) / 2;
            const scratchCanvas = document.createElement('canvas');
            const scratchContext = scratchCanvas.getContext('2d');

            scratchContext.direction = 'rtl';
            scratchContext.textAlign = 'right';
            scratchContext.textBaseline = 'top';

            const headerHeight = 120;
            const goalNoteBlock = measureCanvasTextBlock(scratchContext, data.goal_note, contentWidth - 36, '700 16px Tahoma, Arial, sans-serif', 30);

            const summaryBoxes = summaryItems.map(function (item) {
                const labelBlock = measureCanvasTextBlock(scratchContext, item.label, summaryColumnWidth - 32, '800 13px Tahoma, Arial, sans-serif', 20);
                const valueBlock = measureCanvasTextBlock(scratchContext, item.value, summaryColumnWidth - 32, '900 18px Tahoma, Arial, sans-serif', 28);
                return {
                    label: item.label,
                    value: item.value,
                    height: 28 + labelBlock.height + valueBlock.height
                };
            });

            let summaryGridHeight = 0;
            for (let index = 0; index < summaryBoxes.length; index += 2) {
                const nextSummaryBox = index + 1 < summaryBoxes.length ? summaryBoxes[index + 1] : null;
                const rowHeight = Math.max(summaryBoxes[index].height, nextSummaryBox ? nextSummaryBox.height : 0);
                summaryGridHeight += rowHeight;
                if (index + 2 < summaryBoxes.length) {
                    summaryGridHeight += boxGap;
                }
            }

            const mealLayouts = data.meals.map(function (meal) {
                const itemBlocks = meal.items.map(function (item) {
                    return measureCanvasTextBlock(scratchContext, '• ' + item, contentWidth - 76, '400 15px Tahoma, Arial, sans-serif', 28);
                });
                const altBlock = measureCanvasTextBlock(scratchContext, 'بديل مناسب: ' + meal.alternatives, contentWidth - 84, '400 14px Tahoma, Arial, sans-serif', 26);
                let height = 76 + altBlock.height + 28;

                itemBlocks.forEach(function (block) {
                    height += block.height + 4;
                });

                return {
                    meal: meal,
                    itemBlocks: itemBlocks,
                    altBlock: altBlock,
                    height: height
                };
            });

            let mealsSectionHeight = 38;
            mealLayouts.forEach(function (mealLayout, index) {
                mealsSectionHeight += mealLayout.height;
                if (index < mealLayouts.length - 1) {
                    mealsSectionHeight += 12;
                }
            });

            const tipBlocks = data.tips.map(function (tip) {
                return measureCanvasTextBlock(scratchContext, '• ' + tip, contentWidth - 28, '400 15px Tahoma, Arial, sans-serif', 30);
            });
            let tipsSectionHeight = 38;
            tipBlocks.forEach(function (block) {
                tipsSectionHeight += block.height + 6;
            });

            const footerBlock = measureCanvasTextBlock(scratchContext, footerText, contentWidth, '400 13px Tahoma, Arial, sans-serif', 24);
            const totalHeight = outerPadding + cardPadding + headerHeight + sectionGap + (goalNoteBlock.height + 36) + sectionGap + summaryGridHeight + sectionGap + mealsSectionHeight + sectionGap + tipsSectionHeight + sectionGap + (footerBlock.height + 18) + cardPadding + outerPadding;
            const scale = Math.max(minCanvasScale, Math.min(window.devicePixelRatio || 1, maxCanvasScale));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(pageWidth * scale);
            canvas.height = Math.round(totalHeight * scale);

            const context = canvas.getContext('2d');
            context.scale(scale, scale);
            context.direction = 'rtl';
            context.textAlign = 'right';
            context.textBaseline = 'top';

            const backgroundGradient = context.createLinearGradient(0, 0, 0, totalHeight);
            backgroundGradient.addColorStop(0, '#eff6ff');
            backgroundGradient.addColorStop(1, '#f8fafc');
            context.fillStyle = backgroundGradient;
            context.fillRect(0, 0, pageWidth, totalHeight);

            context.save();
            context.shadowColor = 'rgba(15,23,42,0.12)';
            context.shadowBlur = 28;
            context.shadowOffsetY = 12;
            drawRoundedRect(context, outerPadding, outerPadding, pageWidth - (outerPadding * 2), totalHeight - (outerPadding * 2), 24, '#ffffff', '#dbeafe');
            context.restore();

            let cursorY = outerPadding + cardPadding;
            const cardRight = pageWidth - outerPadding - cardPadding;
            const cardLeft = outerPadding + cardPadding;

            context.font = '700 14px Tahoma, Arial, sans-serif';
            context.fillStyle = '#2563eb';
            context.fillText(assistantRoot.dataset.siteName || 'Gym System', cardRight, cursorY);

            cursorY += 28;
            context.font = '900 30px Tahoma, Arial, sans-serif';
            context.fillStyle = '#0f172a';
            context.fillText('النظام الغذائي المخصص', cardRight, cursorY);

            cursorY += 46;
            context.font = '700 16px Tahoma, Arial, sans-serif';
            context.fillStyle = '#475569';
            context.fillText(data.coach_name || 'كابتن MO', cardRight, cursorY);

            drawRoundedRect(context, cardLeft, outerPadding + cardPadding, readyBadgeWidth, readyBadgeHeight, 22, '#dbeafe', null);
            context.font = '800 14px Tahoma, Arial, sans-serif';
            context.fillStyle = '#1d4ed8';
            context.textAlign = 'center';
            context.fillText(readyBadgeText, cardLeft + (readyBadgeWidth / 2), outerPadding + cardPadding + 12);
            context.textAlign = 'right';

            cursorY = outerPadding + cardPadding + headerHeight;
            drawRoundedRect(context, cardLeft, cursorY, contentWidth, goalNoteBlock.height + 36, 18, '#eff6ff', '#bfdbfe');
            drawWrappedCanvasText(context, data.goal_note, cardRight - 18, cursorY + 18, contentWidth - 36, '700 16px Tahoma, Arial, sans-serif', 30, '#0f172a');

            cursorY += goalNoteBlock.height + 36 + sectionGap;
            let rowTop = cursorY;
            summaryBoxes.forEach(function (item, index) {
                const column = index % 2;
                const rowIndex = Math.floor(index / 2);
                const pairedItem = column === 0 ? summaryBoxes[index + 1] : summaryBoxes[index - 1];
                const rowHeight = Math.max(item.height, pairedItem ? pairedItem.height : 0);
                const x = column === 0 ? cardRight - summaryColumnWidth : cardLeft;
                const y = rowTop;

                drawRoundedRect(context, x, y, summaryColumnWidth, rowHeight, 18, '#f8fafc', '#e2e8f0');
                drawWrappedCanvasText(context, item.label, x + summaryColumnWidth - 16, y + 14, summaryColumnWidth - 32, '800 13px Tahoma, Arial, sans-serif', 20, '#475569');
                drawWrappedCanvasText(context, item.value, x + summaryColumnWidth - 16, y + 14 + measureCanvasTextBlock(context, item.label, summaryColumnWidth - 32, '800 13px Tahoma, Arial, sans-serif', 20).height + 6, summaryColumnWidth - 32, '900 18px Tahoma, Arial, sans-serif', 28, '#0f172a');

                if (column === 1 || index === summaryBoxes.length - 1) {
                    rowTop += rowHeight + boxGap;
                }
            });

            cursorY += summaryGridHeight + sectionGap;
            context.font = '900 21px Tahoma, Arial, sans-serif';
            context.fillStyle = '#0f172a';
            context.fillText('الوجبات اليومية المقترحة', cardRight, cursorY);
            cursorY += 38;

            mealLayouts.forEach(function (mealLayout) {
                const meal = mealLayout.meal;
                drawRoundedRect(context, cardLeft, cursorY, contentWidth, mealLayout.height, 18, '#ffffff', '#cbd5e1');
                context.font = '900 18px Tahoma, Arial, sans-serif';
                context.fillStyle = '#0f172a';
                context.fillText(meal.name, cardRight - 18, cursorY + 16);

                context.font = '700 14px Tahoma, Arial, sans-serif';
                context.fillStyle = '#2563eb';
                context.fillText(meal.time, cardRight - 18, cursorY + 44);

                let itemY = cursorY + 76;
                mealLayout.itemBlocks.forEach(function (block, itemIndex) {
                    drawWrappedCanvasText(context, '• ' + meal.items[itemIndex], cardRight - 18, itemY, contentWidth - 76, '400 15px Tahoma, Arial, sans-serif', 28, '#0f172a');
                    itemY += block.height + 4;
                });

                drawRoundedRect(context, cardLeft + 18, itemY + 6, contentWidth - 36, mealLayout.altBlock.height + 24, 14, '#f8fafc', null);
                drawWrappedCanvasText(context, 'بديل مناسب: ' + meal.alternatives, cardRight - 36, itemY + 18, contentWidth - 84, '400 14px Tahoma, Arial, sans-serif', 26, '#0f172a');
                cursorY += mealLayout.height + 12;
            });

            cursorY += sectionGap - 12;
            context.font = '900 21px Tahoma, Arial, sans-serif';
            context.fillStyle = '#0f172a';
            context.fillText('نصائح كابتن MO', cardRight, cursorY);
            cursorY += 38;

            tipBlocks.forEach(function (block, tipIndex) {
                drawWrappedCanvasText(context, '• ' + data.tips[tipIndex], cardRight, cursorY, contentWidth - 28, '400 15px Tahoma, Arial, sans-serif', 30, '#0f172a');
                cursorY += block.height + 6;
            });

            context.strokeStyle = '#e2e8f0';
            context.lineWidth = 1;
            context.beginPath();
            context.moveTo(cardLeft, cursorY + 4);
            context.lineTo(cardRight, cursorY + 4);
            context.stroke();

            cursorY += 20;
            drawWrappedCanvasText(context, footerText, cardRight, cursorY, contentWidth, '400 13px Tahoma, Arial, sans-serif', 24, '#64748b');

            return canvasToPngBlob(canvas);
        }

        async function downloadBlob(blob, filename) {
            if (window.TheClubGymAndroid && typeof window.TheClubGymAndroid.downloadBase64File === 'function') {
                const dataUrl = await blobToDataUrl(blob);
                window.TheClubGymAndroid.downloadBase64File(dataUrl, filename, blob.type || 'image/png');
                return;
            }

            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(function () {
                URL.revokeObjectURL(url);
            }, 1000);
        }

        async function downloadPlanImage() {
            if (!state.planData) {
                addMessage('bot', 'من فضلك جهّز النظام الغذائي أولاً قبل التحميل.');
                return;
            }

            const fileSuffix = String(Date.now());

            try {
                const pngBlob = await buildPlanPngBlob(state.planData);
                await downloadBlob(pngBlob, 'captain_eslam_nutrition_plan_' + fileSuffix + '.png');
            } catch (error) {
                console.error('Nutrition PNG export failed', error);
                addMessage('bot', 'تعذر تجهيز صورة PNG الآن، حاول مرة أخرى.');
            }
        }

        function escapeHtml(value) {
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        async function generatePlan() {
            state.waiting = true;
            inputEl.disabled = true;
            sendButton.disabled = true;
            addMessage('bot', 'جاري تجهيز نظامك الغذائي المناسب...');

            try {
                const response = await fetch('member_portal_nutrition.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'generate_plan',
                        phone: assistantRoot.dataset.memberPhone,
                        age: state.answers.age,
                        weight: state.answers.weight,
                        body_fat: state.answers.body_fat
                    })
                });

                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.message || 'تعذر تجهيز النظام الغذائي.');
                }

                addMessage('bot', data.summary_lines.join('\n'));
                state.planData = data;
                renderPlan(data);
                exportButton.classList.add('is-visible');
            } catch (error) {
                addMessage('bot', error.message || 'حدث خطأ غير متوقع، حاول مرة أخرى.');
            } finally {
                state.waiting = false;
                inputEl.disabled = false;
                sendButton.disabled = false;
                inputEl.focus();
            }
        }

        function submitAnswer() {
            if (!state.running || state.waiting) {
                return;
            }

            const prompt = prompts[state.step];
            if (!prompt) {
                return;
            }

            const value = inputEl.value.trim();
            if (!value) {
                addMessage('bot', 'من فضلك اكتب الإجابة أولاً.');
                return;
            }

            const error = prompt.validate(value);
            if (error) {
                addMessage('bot', error);
                return;
            }

            state.answers[prompt.key] = normalizeDigits(value);
            addMessage('user', value);
            state.step += 1;

            if (state.step < prompts.length) {
                askCurrentQuestion();
                return;
            }

            generatePlan();
        }

        startButton.addEventListener('click', startConversation);
        sendButton.addEventListener('click', submitAnswer);
        restartButton.addEventListener('click', startConversation);
        exportButton.addEventListener('click', downloadPlanImage);
        inputEl.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitAnswer();
            }
        });
    }
</script>
</body>
</html>
