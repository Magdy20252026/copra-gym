<?php
session_start();

// منع الدخول بدون تسجيل
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// منع التخزين المؤقت حتى لا تُعرض نسخة قديمة من الصفحة
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'config.php';
require_once 'user_permissions_helpers.php';
require_once 'single_session_helpers.php';
require_once 'ai_assistant_helpers.php';

ensureUserPermissionsSchema($pdo);
ensureSingleSessionSchema($pdo);

// جلب اسم الموقع وعدد المستخدمين وعدد الاشتراكات وعدد تمرينات الحصة الواحدة وعدد المشتركين الجدد
$siteName           = "Gym System";
$logoPath           = null;
$memberCount        = 0;
$membersWithDebtsCount = 0;
$subscriptionCount  = 0;
$singleSessionCount = 0;
$newMembersCount    = 0; // اليوم
$newMembersWeek     = 0; // خلال أسبوع
$newMembersMonth    = 0; // خلال الشهر
$activeBranchId     = getActiveBranchSessionId();

try {
    $stmt = $pdo->query("SELECT site_name, logo_path FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
        $logoPath = $row['logo_path'];
    }

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_members,
            SUM(CASE WHEN remaining_amount > 0 THEN 1 ELSE 0 END) AS members_with_debts
        FROM members
        WHERE branch_id = :branch_id
    ");
    $stmt->execute([':branch_id' => $activeBranchId]);
    if ($row = $stmt->fetch()) {
        $memberCount = (int)($row['total_members'] ?? 0);
        $membersWithDebtsCount = (int)($row['members_with_debts'] ?? 0);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM subscriptions WHERE branch_id = :branch_id");
    $stmt->execute([':branch_id' => $activeBranchId]);
    $subscriptionCount = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM single_session_price WHERE branch_id = :branch_id");
    $stmt->execute([':branch_id' => $activeBranchId]);
    $singleSessionCount = (int)$stmt->fetch()['c'];

    // عدد المشتركين الجدد (اليوم)
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM members WHERE branch_id = :branch_id AND DATE(created_at) = :today");
    $stmt->execute([':branch_id' => $activeBranchId, ':today' => $today]);
    $newMembersCount = (int)$stmt->fetch()['c'];

    // عدد المشتركين الجدد خلال آخر 7 أيام (بما فيهم اليوم)
    $weekAgo = date('Y-m-d', strtotime('-6 days')); // من 6 أيام + اليوم = 7 أيام
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM members WHERE branch_id = :branch_id AND DATE(created_at) BETWEEN :weekAgo AND :today");
    $stmt->execute([':branch_id' => $activeBranchId, ':weekAgo' => $weekAgo, ':today' => $today]);
    $newMembersWeek = (int)$stmt->fetch()['c'];

    // عدد المشتركين الجدد خلال الشهر الحالي
    $monthStart = date('Y-m-01'); // أول يوم في الشهر الحالي
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM members WHERE branch_id = :branch_id AND DATE(created_at) BETWEEN :monthStart AND :today");
    $stmt->execute([':branch_id' => $activeBranchId, ':monthStart' => $monthStart, ':today' => $today]);
    $newMembersMonth = (int)$stmt->fetch()['c'];

} catch (Exception $e) {}

$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? '';
$userId   = $_SESSION['user_id'] ?? 0;
$currentBranchName = getCurrentBranchName();
$safeAiGreetingSiteName = trim(strip_tags((string)$siteName));
$aiGreetingMessage = 'مرحباً، أنا Megz مساعدك الذكي لإدارة نظام جيم ' . $safeAiGreetingSiteName . ".
اسألني عن الإيرادات والمصروفات والمبيعات والاشتراكات والموظفين أو عن أي جزء داخل النظام.";

// مصفوفة صلاحيات افتراضية (المدير يرى كل شيء)
$perms = getDefaultUserPermissions();

// لو المستخدم مشرف نقرأ صلاحياته من جدول user_permissions
if ($role === 'مشرف' && $userId) {
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            foreach ($perms as $permissionKey => $defaultValue) {
                if (isset($rowPerm[$permissionKey])) {
                    $perms[$permissionKey] = (int)$rowPerm[$permissionKey];
                }
            }
        }
    } catch (Exception $e) {
        // في حالة الخطأ نترك القيم الافتراضية (كلها 1)
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>لوحة التحكم - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }

        :root {
            --bg: #e5e7eb;
            --sidebar-bg: #f9fafb;
            --sidebar-border: #d1d5db;
            --main-bg: radial-gradient(circle at top, #e5e7eb, #e0f2fe);
            --card-bg: #ffffff;
            --text-main: #111827;
            --text-muted: #4b5563;
            --primary: #22c55e;
            --primary-soft: rgba(34, 197, 94, 0.10);
            --accent-blue: #2563eb;
            --danger: #ef4444;
            --shadow-soft: 0 20px 45px rgba(15,23,42,0.15);
        }

        body.dark {
            --bg: #020617;
            --sidebar-bg: #020617;
            --sidebar-border: #1f2937;
            --main-bg: radial-gradient(circle at top, #020617, #020617);
            --card-bg: #020617;
            --text-main: #ffffff;
            --text-muted: #e5e7eb;
            --primary: #22c55e;
            --primary-soft: rgba(34, 197, 94, 0.18);
            --accent-blue: #38bdf8;
            --danger: #fb7185;
            --shadow-soft: 0 20px 45px rgba(0,0,0,0.75);
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text-main);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 600;
            display: flex;
            transition: background 0.4s ease, color 0.3s ease;
        }

        .layout { display: flex; width: 100%; min-height: 100vh; }

        /* سويتش الثيم */
        .theme-toggle { display: flex; align-items: center; }
        .theme-switch {
            position: relative;
            width: 64px;
            height: 30px;
            border-radius: 999px;
            background: #e5e7eb;
            box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.8);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 7px;
            font-size: 14px;
            color: #6b7280;
            font-weight: 600;
        }
        .theme-switch span { z-index: 2; user-select: none; }
        .theme-thumb {
            position: absolute;
            top: 3px;
            right: 3px;
            width: 24px;
            height: 24px;
            border-radius: 999px;
            background: #facc15;
            box-shadow: 0 4px 10px rgba(250, 204, 21, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: transform 0.25s ease, background 0.25s ease, box-shadow 0.25s ease;
        }
        body.dark .theme-switch {
            background: #020617;
            box-shadow: inset 0 0 0 1px rgba(30, 64, 175, 0.9);
            color: #e5e7eb;
        }
        body.dark .theme-thumb {
            transform: translateX(-32px);
            background: #0f172a;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.9);
        }

        /* الشريط الجانبي */
        .sidebar {
            width: 270px;
            background: var(--sidebar-bg);
            border-left: 1px solid var(--sidebar-border);
            padding: 20px 18px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            box-shadow: var(--shadow-soft);
            z-index: 2;
        }

        .brand { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }

        .brand-logo {
            width: 68px;
            height: 68px;
            border-radius: 22px;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            box-shadow: 0 14px 30px rgba(15,23,42,0.25);
            overflow: hidden;
        }
        .brand-logo img {
            width: 90%;
            height: 90%;
            object-fit: contain;
        }

        .brand-text-main { font-size: 18px; font-weight: 800; }
        .brand-text-sub  { font-size: 12px; color: var(--text-muted); font-weight: 600; }

        .user-info { margin-top: 8px; font-size: 13px; color: var(--text-muted); }
        .user-info strong { color: var(--text-main); }
        .branch-chip {
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: #166534;
            font-size: 13px;
            font-weight: 800;
        }
        body.dark .branch-chip { color: #bbf7d0; }

        .sidebar-section-title {
            font-size: 11px;
            color: var(--text-muted);
            margin: 12px 4px 8px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-weight: 800;
        }

        .menu { display: flex; flex-direction: column; gap: 10px; }

        .menu-button {
            width: 100%;
            border-radius: 14px;
            padding: 12px 12px;
            border: none;
            background: var(--card-bg);
            color: var(--text-main);
            font-size: 14px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            box-shadow:
                0 0 0 1px var(--sidebar-border),
                0 5px 14px rgba(15,23,42,0.18);
            transition: background 0.15s, transform 0.08s, box-shadow 0.15s;
        }
        .menu-button:hover {
            transform: translateY(-1px);
            box-shadow:
                0 0 0 1px rgba(148,163,184,0.9),
                0 8px 18px rgba(15,23,42,0.25);
        }
        .menu-button.active {
            background: linear-gradient(90deg, rgba(34,197,94,0.22), rgba(34,197,94,0.10));
            box-shadow:
                0 0 0 1px rgba(34,197,94,0.95),
                0 10px 22px rgba(34,197,94,0.38);
        }

        .menu-left { display: flex; align-items: center; gap: 10px; }
        .menu-icon {
            width: 30px; height: 30px; border-radius: 999px;
            background: rgba(15,23,42,0.95);
            display:flex;align-items:center;justify-content:center;
            font-size:18px;color:#f9fafb;
        }
        .menu-label { white-space: nowrap; }

        .badge {
            font-size: 12px;
            padding: 3px 9px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: #166534;
            font-weight: 800;
        }
        body.dark .badge { color: #bbf7d0; }

        .logout-btn {
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid var(--sidebar-border);
        }
        .logout-btn button {
            width: 100%;
            border-radius: 12px;
            padding: 10px 12px;
            border: none;
            background: rgba(239,68,68,0.12);
            color: var(--danger);
            font-size: 13px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            cursor: pointer;
            box-shadow: 0 0 0 1px rgba(239,68,68,0.65);
        }
        .logout-btn button:hover { background: rgba(239,68,68,0.2); }

        /* المنطقة الرئيسية */
        .main {
            flex: 1;
            background: var(--main-bg);
            padding: 20px 22px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }

        .top-title { font-size: 20px; font-weight: 900; }
        .breadcrumbs { font-size: 12px; color: var(--text-muted); }
        .top-branch {
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--card-bg);
            box-shadow: 0 0 0 1px var(--sidebar-border);
            font-size: 13px;
            font-weight: 800;
        }

        .stat-cards {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .stats-branch-note {
            margin: 4px 0 2px;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 16px 18px;
            min-width: 260px;
            min-height: 110px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: var(--shadow-soft);
        }
        .stat-main {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 6px;
            font-weight: 700;
        }
        .stat-number { font-size: 30px; font-weight: 900; }
        .stat-icon {
            width: 52px; height: 52px; border-radius: 18px;
            background: radial-gradient(circle at 30% 0, #22c55e, #16a34a);
            display:flex;align-items:center;justify-content:center;
            font-size:26px;color:#f9fafb;
        }

        .contact-footer {
            display: flex;
            justify-content: flex-start;
            justify-content: start;
        }
        .contact-footer-inner {
            width: 100%;
            max-width: 360px;
            min-width: 0;
        }
        .contact-footer-number {
            font-size: 20px;
            font-weight: 800;
        }
        .contact-footer-name {
            margin-top: 4px;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 700;
        }
        .stat-icon-phone {
            background: radial-gradient(circle at 30% 0, #22c55e, #16a34a);
        }
        .developer-footer {
            margin: 16px 0 6px;
            text-align: center;
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 800;
        }

        /* مجموعات القوائم المنسدلة (التقفيل + صلاحيات المستخدمين) */
        .closing-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .closing-subbuttons {
            display: none; /* مخفي افتراضياً */
            flex-direction: column;
            gap: 6px;
            margin-top: 4px;
            padding-right: 38px;
        }
        .closing-subbtn {
            width: 100%;
            border-radius: 12px;
            padding: 8px 10px;
            border: none;
            background: rgba(15,23,42,0.03);
            color: var(--text-main);
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
        }
        .closing-subbtn span:first-child {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .ai-chat-toggle {
            position: fixed;
            left: 22px;
            bottom: 22px;
            width: 64px;
            height: 64px;
            border: none;
            border-radius: 22px;
            background: linear-gradient(135deg, #2563eb, #22c55e);
            color: #ffffff;
            font-size: 28px;
            cursor: pointer;
            box-shadow: 0 20px 40px rgba(37, 99, 235, 0.35);
            z-index: 40;
        }

        .ai-chat-panel {
            position: fixed;
            left: 22px;
            bottom: 100px;
            width: min(420px, calc(100vw - 26px));
            max-height: calc(100vh - 130px);
            background: var(--card-bg);
            border-radius: 28px;
            box-shadow: 0 25px 55px rgba(15, 23, 42, 0.30);
            border: 1px solid var(--sidebar-border);
            overflow: hidden;
            display: none;
            flex-direction: column;
            z-index: 39;
        }

        .ai-chat-panel.open { display: flex; }

        .ai-chat-header {
            padding: 18px 18px 14px;
            background: linear-gradient(135deg, rgba(37,99,235,0.98), rgba(34,197,94,0.92));
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }

        .ai-chat-title-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .ai-chat-avatar {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            background: rgba(255,255,255,0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .ai-chat-title {
            font-size: 16px;
            font-weight: 900;
        }

        .ai-chat-subtitle {
            margin-top: 3px;
            font-size: 12px;
            font-weight: 700;
            opacity: 0.9;
        }

        .ai-chat-close {
            width: 38px;
            height: 38px;
            border: none;
            border-radius: 12px;
            background: rgba(255,255,255,0.14);
            color: #ffffff;
            font-size: 22px;
            cursor: pointer;
            flex-shrink: 0;
        }

        .ai-chat-suggestions {
            padding: 12px 14px 0;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            max-height: 120px;
            overflow-y: auto;
        }

        .ai-chat-suggestion {
            border: none;
            border-radius: 999px;
            padding: 8px 12px;
            background: rgba(37,99,235,0.10);
            color: var(--accent-blue);
            font-size: 12px;
            font-weight: 800;
            cursor: pointer;
        }

        .ai-chat-messages {
            padding: 16px 14px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-height: 260px;
            max-height: min(52vh, 520px);
            scroll-behavior: smooth;
            scrollbar-width: thin;
            scrollbar-color: rgba(37,99,235,0.45) transparent;
        }

        .ai-chat-messages::-webkit-scrollbar,
        .ai-chat-suggestions::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        .ai-chat-messages::-webkit-scrollbar-thumb,
        .ai-chat-suggestions::-webkit-scrollbar-thumb {
            background: rgba(37,99,235,0.35);
            border-radius: 999px;
        }

        .ai-chat-message {
            max-width: 88%;
            padding: 12px 14px;
            border-radius: 18px;
            font-size: 13px;
            line-height: 1.8;
            font-weight: 700;
            white-space: pre-line;
            word-break: break-word;
            box-shadow: 0 12px 24px rgba(15,23,42,0.10);
        }

        .ai-chat-message.assistant {
            align-self: flex-start;
            background: rgba(37,99,235,0.08);
            color: var(--text-main);
            border-top-right-radius: 8px;
        }

        .ai-chat-message.user {
            align-self: flex-end;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            border-top-left-radius: 8px;
        }

        .ai-chat-input-wrap {
            padding: 12px 14px 14px;
            border-top: 1px solid var(--sidebar-border);
            background: rgba(148,163,184,0.05);
        }

        .ai-chat-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .ai-chat-input {
            flex: 1;
            min-height: 48px;
            max-height: 120px;
            resize: vertical;
            border: 1px solid var(--sidebar-border);
            border-radius: 16px;
            padding: 12px 14px;
            font: inherit;
            color: var(--text-main);
            background: var(--card-bg);
        }

        .ai-chat-send {
            min-width: 92px;
            border: none;
            border-radius: 16px;
            padding: 12px 14px;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            color: #ffffff;
            font-size: 13px;
            font-weight: 900;
            cursor: pointer;
        }

        .ai-chat-hint {
            margin-top: 8px;
            color: var(--text-muted);
            font-size: 11px;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .layout {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                border-left: none;
                border-bottom: 1px solid var(--sidebar-border);
            }

            .main {
                padding: 16px 12px 90px;
            }

            .top-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }

            .stat-cards {
                flex-direction: column;
            }

            .stat-card {
                min-width: 0;
                width: 100%;
            }

            .ai-chat-toggle {
                left: 16px;
                bottom: 16px;
                width: 58px;
                height: 58px;
                border-radius: 18px;
            }

            .ai-chat-panel {
                left: 12px;
                right: 12px;
                bottom: 84px;
                width: auto;
                max-height: calc(100vh - 108px);
                border-radius: 24px;
            }

            .ai-chat-messages {
                max-height: 48vh;
            }
        }
    </style>
</head>
<body>
<div class="layout">
    <!-- الشريط الجانبي -->
    <aside class="sidebar">
        <div>
            <div class="brand">
                <div class="brand-logo">
                    <?php if ($logoPath): ?>
                        <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="شعار">
                    <?php else: ?>
                        <span>🏋️‍♂️</span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="brand-text-main"><?php echo htmlspecialchars($siteName); ?></div>
                    <div class="brand-text-sub">لوحة إدارة نظام الجيم</div>
                </div>
            </div>

            <div class="user-info">
                مستخدم مسجّل: <strong><?php echo htmlspecialchars($username); ?></strong>
                — صلاحية: <strong><?php echo htmlspecialchars($role); ?></strong>
            </div>
            <div class="branch-chip">
                <span>🏢</span>
                <span><?php echo htmlspecialchars($currentBranchName !== '' ? $currentBranchName : 'بدون فرع'); ?></span>
            </div>
        </div>

        <div>
            <div class="sidebar-section-title">القائمة الرئيسية</div>
            <div class="menu">
                <!-- اللوحة الرئيسية -->
                <button class="menu-button active" type="button" onclick="location.href='dashboard.php'">
                    <div class="menu-left">
                        <div class="menu-icon">📊</div>
                        <div class="menu-label">اللوحة الرئيسية</div>
                    </div>
                    <span class="badge">الآن</span>
                </button>

                <!-- إدارة المستخدمين -->
                <?php if ($role === 'مدير'): ?>
                    <button class="menu-button" type="button" onclick="location.href='users.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(37,99,235,0.95);">👥</div>
                            <div class="menu-label">المستخدمون</div>
                        </div>
                    </button>
                    <button class="menu-button" type="button" onclick="location.href='branches.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(14,165,233,0.95);">🏢</div>
                            <div class="menu-label">الفروع</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- الاشتراكات -->
                <button class="menu-button" type="button" onclick="location.href='subscriptions.php'">
                    <div class="menu-left">
                        <div class="menu-icon" style="background:rgba(59,130,246,0.95);">📅</div>
                        <div class="menu-label">الاشتراكات</div>
                    </div>
                </button>

                <!-- سعر التمرينة الواحدة -->
                <button class="menu-button" type="button" onclick="location.href='single_session.php'">
                    <div class="menu-left">
                        <div class="menu-icon" style="background:rgba(34,197,94,0.95);">💪</div>
                        <div class="menu-label">سعر التمرينة الواحدة</div>
                    </div>
                </button>

                <?php if ($role === 'مدير' || $perms['can_view_items']): ?>
                    <button class="menu-button" type="button" onclick="location.href='items.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(168,85,247,0.95);">📦</div>
                            <div class="menu-label">الأصناف</div>
                        </div>
                    </button>
                <?php endif; ?>

                <?php if ($role === 'مدير' || $perms['can_view_sales']): ?>
                    <button class="menu-button" type="button" onclick="location.href='sales.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(16,185,129,0.95);">🛒</div>
                            <div class="menu-label">المبيعات</div>
                        </div>
                    </button>
                <?php endif; ?>

                <?php if ($role === 'مدير' || $perms['can_view_cashier']): ?>
                    <button class="menu-button" type="button" onclick="location.href='cashier.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(14,165,233,0.95);">💵</div>
                            <div class="menu-label">الكاشير</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- المشتركين -->
                <?php if ($role === 'مدير' || $perms['can_view_members']): ?>
                    <button class="menu-button" type="button" onclick="location.href='members.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(236,72,153,0.95);">🧍</div>
                            <div class="menu-label">المشتركين</div>
                        </div>
                    </button>
                <?php endif; ?>

                <?php if ($role === 'مدير' || $perms['can_view_members']): ?>
                    <button class="menu-button" type="button" onclick="location.href='whatsapp_messaging.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(34,197,94,0.95);">💬</div>
                            <div class="menu-label">رسائل واتساب</div>
                        </div>
                    </button>
                <?php endif; ?>

                <?php if ($role === 'مدير'): ?>
                    <button class="menu-button" type="button" onclick="location.href='member_notifications.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(234,88,12,0.95);">🔔</div>
                            <div class="menu-label">إشعارات المشتركين</div>
                        </div>
                    </button>
                <?php endif; ?>


                <?php if ($role === 'مدير' || $perms['can_view_trainers']): ?>
                    <button class="menu-button" type="button" onclick="location.href='trainers.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(249,115,22,0.95);">🏅</div>
                            <div class="menu-label">المدربين</div>
                        </div>
                    </button>
                <?php endif; ?>

                <?php if ($role === 'مدير' || $perms['can_view_employees']): ?>
                    <button class="menu-button" type="button" onclick="location.href='employees.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(79,70,229,0.95);">🧑‍💼</div>
                            <div class="menu-label">الموظفين</div>
                        </div>
                    </button>
                <?php endif; ?>

                <?php if ($role === 'مدير' || $perms['can_view_employee_attendance']): ?>
                    <button class="menu-button" type="button" onclick="location.href='employee_attendance.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(2,132,199,0.95);">🕘</div>
                            <div class="menu-label">حضور الموظفين</div>
                        </div>
                    </button>
                <?php endif; ?>

                <?php if ($role === 'مدير' || $perms['can_view_employee_advances']): ?>
                    <button class="menu-button" type="button" onclick="location.href='employee_advances.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(8,145,178,0.95);">💳</div>
                            <div class="menu-label">سلف الموظفين</div>
                        </div>
                    </button>
                <?php endif; ?>

                <?php if ($role === 'مدير' || $perms['can_view_employee_payroll']): ?>
                    <button class="menu-button" type="button" onclick="location.href='employee_payroll.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(21,128,61,0.95);">💵</div>
                            <div class="menu-label">قبض الموظفين</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- تجديد الاشتراكات -->
                <?php if ($role === 'مدير' || $perms['can_view_renew_members']): ?>
                    <button class="menu-button" type="button" onclick="location.href='renew_members.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(14,116,144,0.95);">🔁</div>
                            <div class="menu-label">تجديد الاشتراكات</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- حضور المشتركين -->
                <?php if ($role === 'مدير' || $perms['can_view_attendance']): ?>
                    <button class="menu-button" type="button" onclick="location.href='attendance.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(16,185,129,0.95);">✅</div>
                            <div class="menu-label">حضور المشتركين</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- صفحة الفريز الجديدة -->
                <?php if ($role === 'مدير' || $perms['can_view_members']): ?>
                    <button class="menu-button" type="button" onclick="location.href='freeze.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(107,114,128,0.95);">⏸️</div>
                            <div class="menu-label">إيقاف مؤقت (Freeze)</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- المصروفات -->
                <?php if ($role === 'مدير' || $perms['can_view_expenses']): ?>
                    <button class="menu-button" type="button" onclick="location.href='expenses.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(239,68,68,0.95);">💸</div>
                            <div class="menu-label">المصروفات</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- الإحصائيات -->
                <?php if ($role === 'مدير' || $perms['can_view_stats']): ?>
                    <button class="menu-button" type="button" onclick="location.href='stats.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(56,189,248,0.95);">📈</div>
                            <div class="menu-label">الإحصائيات</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- إعدادات الموقع -->
                <?php if ($role === 'مدير' || $perms['can_view_settings']): ?>
                    <button class="menu-button" type="button" onclick="location.href='settings.php'">
                        <div class="menu-left">
                            <div class="menu-icon" style="background:rgba(234,179,8,0.95);">⚙️</div>
                            <div class="menu-label">إعدادات الموقع</div>
                        </div>
                    </button>
                <?php endif; ?>

                <!-- زر التقفيل -->
                <?php if ($role === 'مدير' || $perms['can_view_closing']): ?>
                    <div class="closing-group">
                        <button class="menu-button" type="button" id="btnClosingToggle">
                            <div class="menu-left">
                                <div class="menu-icon" style="background:rgba(55,65,81,0.95);">🔒</div>
                                <div class="menu-label">زر التقفيل</div>
                            </div>
                            <span id="closingArrow">＋</span>
                        </button>
                        <div class="closing-subbuttons" id="closingSubButtons">
                            <button class="closing-subbtn" type="button" onclick="location.href='close_day.php'">
                                <span>
                                    <span>📅</span>
                                    <span>تقفيل يومي</span>
                                </span>
                                <span>›</span>
                            </button>
                            <button class="closing-subbtn" type="button" onclick="location.href='close_month.php'">
                                <span>
                                    <span>🗓️</span>
                                    <span>تقفيل شهر</span>
                                </span>
                                <span>›</span>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- زر صلاحيات المستخدمين (للمدير فقط) -->
                <?php if ($role === 'مدير'): ?>
                    <div class="closing-group">
                        <button class="menu-button" type="button" id="btnPermissionsToggle">
                            <div class="menu-left">
                                <div class="menu-icon" style="background:rgba(147,51,234,0.95);">🛡️</div>
                                <div class="menu-label">صلاحيات المستخدمين</div>
                            </div>
                            <span id="permissionsArrow">＋</span>
                        </button>
                        <div class="closing-subbuttons" id="permissionsSubButtons" style="padding-right: 18px;">
                            <form method="get" action="user_permissions.php" style="width:100%;display:flex;flex-direction:column;gap:6px;">
                                <select name="user_id" required
                                        style="width:100%;padding:8px 10px;border-radius:12px;border:1px solid var(--sidebar-border);font-size:13px;">
                                    <option value="">اختر مستخدم مشرف...</option>
                                    <?php
                                    try {
                                        $stmtMods = $pdo->query("SELECT id, username FROM users WHERE role = 'مشرف' ORDER BY username ASC");
                                        while ($mod = $stmtMods->fetch(PDO::FETCH_ASSOC)) {
                                            echo '<option value="'.(int)$mod['id'].'">'.htmlspecialchars($mod['username']).'</option>';
                                        }
                                    } catch (Exception $e) {}
                                    ?>
                                </select>
                                <button type="submit" class="closing-subbtn">
                                    <span>
                                        <span>⚙️</span>
                                        <span>إدارة صلاحيات هذا الحساب</span>
                                    </span>
                                    <span>›</span>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <div class="logout-btn">
            <form method="post" action="logout.php">
                <button type="submit">
                    <span>🚪</span>
                    <span>تسجيل الخروج</span>
                </button>
            </form>
        </div>
    </aside>

    <!-- المنطقة الرئيسية -->
    <main class="main">
        <div class="top-bar">
            <div>
                <div class="top-title">اللوحة الرئيسية</div>
                <div class="breadcrumbs">نظام إدارة الجيم › لوحة التحكم</div>
                <div class="top-branch">
                    <span>📍</span>
                    <span>الفرع الحالي: <?php echo htmlspecialchars($currentBranchName !== '' ? $currentBranchName : 'غير محدد'); ?></span>
                </div>
            </div>

            <div class="theme-toggle">
                <div class="theme-switch" id="themeSwitch">
                    <span>🌙</span>
                    <span>☀️</span>
                    <div class="theme-thumb" id="themeThumb">☀️</div>
                </div>
            </div>
        </div>

        <div class="stats-branch-note">
            الإحصائيات المعروضة تخص الفرع الحالي: <?php echo htmlspecialchars($currentBranchName !== '' ? $currentBranchName : 'غير محدد'); ?>
        </div>

        <!-- كروت الإحصائيات -->
        <div class="stat-cards">
            <div class="stat-card">
                <div>
                    <div class="stat-main">إجمالي المشتركين بالفرع</div>
                    <div class="stat-number"><?php echo $memberCount; ?></div>
                </div>
                <div class="stat-icon">🧍</div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-main">المشتركون عليهم مبالغ متبقية</div>
                    <div class="stat-number"><?php echo $membersWithDebtsCount; ?></div>
                </div>
                <div class="stat-icon" style="background:radial-gradient(circle at 30% 0,#0ea5e9,#0284c7);">
                    💳
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-main">عدد الاشتراكات</div>
                    <div class="stat-number"><?php echo $subscriptionCount; ?></div>
                </div>
                <div class="stat-icon" style="background:radial-gradient(circle at 30% 0,#2563eb,#1d4ed8);">
                    📅
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-main">عدد تمرينات الحصة الواحدة</div>
                    <div class="stat-number"><?php echo $singleSessionCount; ?></div>
                </div>
                <div class="stat-icon" style="background:radial-gradient(circle at 30% 0,#f97316,#ea580c);">
                    💪
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-main">المشتركين الجدد اليوم</div>
                    <div class="stat-number"><?php echo $newMembersCount; ?></div>
                </div>
                <div class="stat-icon" style="background:radial-gradient(circle at 30% 0,#ec4899,#db2777);">
                    🧍
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-main">المشتركين الجدد خلال أسبوع</div>
                    <div class="stat-number"><?php echo $newMembersWeek; ?></div>
                </div>
                <div class="stat-icon" style="background:radial-gradient(circle at 30% 0,#22c55e,#16a34a);">
                    📆
                </div>
            </div>

            <div class="stat-card">
                <div>
                    <div class="stat-main">المشتركين الجدد خلال الشهر</div>
                    <div class="stat-number"><?php echo $newMembersMonth; ?></div>
                </div>
                <div class="stat-icon" style="background:radial-gradient(circle at 30% 0,#0ea5e9,#0369a1);">
                    📅
                </div>
            </div>
        </div>

        <div class="contact-footer">
            <div class="stat-card contact-footer-inner">
                <div>
                    <div class="stat-main">للتواصل</div>
                    <div class="contact-footer-number">01000380093</div>
                    <div class="contact-footer-name">Eslam Farrag</div>
                </div>
                <div class="stat-icon stat-icon-phone">📞</div>
            </div>
        </div>
    </main>
</div>

<?php if ($role === 'مدير'): ?>
    <button type="button" class="ai-chat-toggle" id="aiChatToggle" aria-label="فتح المساعد الذكي">🤖</button>
    <section class="ai-chat-panel" id="aiChatPanel" aria-label="Megz AI Assistant">
        <div class="ai-chat-header">
            <div class="ai-chat-title-wrap">
                <div class="ai-chat-avatar">🤖</div>
                <div>
                    <div class="ai-chat-title">Megz AI</div>
                    <div class="ai-chat-subtitle">مساعد المدير الذكي داخل <?php echo htmlspecialchars($siteName); ?></div>
                </div>
            </div>
            <button type="button" class="ai-chat-close" id="aiChatClose" aria-label="إغلاق">×</button>
        </div>
        <div class="ai-chat-suggestions" id="aiChatSuggestions"></div>
        <div class="ai-chat-messages" id="aiChatMessages"></div>
        <div class="ai-chat-input-wrap">
            <form class="ai-chat-form" id="aiChatForm">
                <textarea class="ai-chat-input" id="aiChatInput" placeholder="اسأل Megz عن النظام أو المؤشرات اليومية..." required></textarea>
                <button type="submit" class="ai-chat-send" id="aiChatSend">إرسال</button>
            </form>
            <div class="ai-chat-hint">متاح لحساب المدير فقط — يمكنك السؤال بالعربية عن النظام أو الأرقام الحالية.</div>
        </div>
    </section>
<?php endif; ?>

<script>
    const body   = document.body;
    const switchEl = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';

    function applyTheme(mode) {
        if (mode === 'dark') {
            body.classList.add('dark');
        } else {
            body.classList.remove('dark');
        }
        localStorage.setItem('gymDashboardTheme', mode);
    }

    applyTheme(savedTheme);

    if (switchEl) {
        switchEl.addEventListener('click', () => {
            const isDark = body.classList.contains('dark');
            applyTheme(isDark ? 'light' : 'dark');
        });
    }

    // فتح/غلق أزرار التقفيل اليومي والشهري
    const btnClosingToggle   = document.getElementById('btnClosingToggle');
    const closingSubButtons  = document.getElementById('closingSubButtons');
    const closingArrow       = document.getElementById('closingArrow');

    if (btnClosingToggle && closingSubButtons && closingArrow) {
        btnClosingToggle.addEventListener('click', () => {
            const isVisible = closingSubButtons.style.display === 'flex';
            if (isVisible) {
                closingSubButtons.style.display = 'none';
                closingArrow.textContent = '＋';
            } else {
                closingSubButtons.style.display = 'flex';
                closingArrow.textContent = '－';
            }
        });
    }

    // فتح/غلق زر صلاحيات المستخدمين
    const btnPermissionsToggle  = document.getElementById('btnPermissionsToggle');
    const permissionsSubButtons = document.getElementById('permissionsSubButtons');
    const permissionsArrow      = document.getElementById('permissionsArrow');

    if (btnPermissionsToggle && permissionsSubButtons && permissionsArrow) {
        btnPermissionsToggle.addEventListener('click', () => {
            const isVisible = permissionsSubButtons.style.display === 'flex';
            if (isVisible) {
                permissionsSubButtons.style.display = 'none';
                permissionsArrow.textContent = '＋';
            } else {
                permissionsSubButtons.style.display = 'flex';
                permissionsArrow.textContent = '－';
            }
        });
    }

    // منع الرجوع للخلف
    history.pushState(null, document.title, location.href);
    window.addEventListener('popstate', function () {
        history.pushState(null, document.title, location.href);
    });

    <?php if ($role === 'مدير'): ?>
    const aiChatPanel = document.getElementById('aiChatPanel');
    const aiChatToggle = document.getElementById('aiChatToggle');
    const aiChatClose = document.getElementById('aiChatClose');
    const aiChatMessages = document.getElementById('aiChatMessages');
    const aiChatSuggestions = document.getElementById('aiChatSuggestions');
    const aiChatForm = document.getElementById('aiChatForm');
    const aiChatInput = document.getElementById('aiChatInput');
    const aiChatSend = document.getElementById('aiChatSend');
    const aiGreeting = <?php echo json_encode($aiGreetingMessage, JSON_UNESCAPED_UNICODE); ?>;
    const aiSuggestedQuestions = <?php echo json_encode(aiAssistantDefaultSuggestedQuestions(), JSON_UNESCAPED_UNICODE); ?>;

    function toggleAiChat(forceOpen) {
        if (!aiChatPanel) {
            return;
        }

        const shouldOpen = typeof forceOpen === 'boolean' ? forceOpen : !aiChatPanel.classList.contains('open');
        aiChatPanel.classList.toggle('open', shouldOpen);
        if (shouldOpen && aiChatInput) {
            aiChatInput.focus();
        }
    }

    function appendAiMessage(type, text) {
        if (!aiChatMessages) {
            return null;
        }

        const message = document.createElement('div');
        message.className = 'ai-chat-message ' + type;
        message.textContent = text;
        aiChatMessages.appendChild(message);
        aiChatMessages.scrollTop = aiChatMessages.scrollHeight;
        return message;
    }

    function renderAiSuggestions(questions) {
        if (!aiChatSuggestions) {
            return;
        }

        aiChatSuggestions.innerHTML = '';
        (questions || []).forEach((question) => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'ai-chat-suggestion';
            chip.textContent = question;
            chip.addEventListener('click', () => {
                if (aiChatInput) {
                    aiChatInput.value = question;
                    aiChatInput.focus();
                }
                toggleAiChat(true);
            });
            aiChatSuggestions.appendChild(chip);
        });
    }

    async function sendAiQuestion(question) {
        const userQuestion = (question || '').trim();
        if (!userQuestion || !aiChatInput || !aiChatSend) {
            return;
        }

        appendAiMessage('user', userQuestion);
        aiChatInput.value = '';
        aiChatSend.disabled = true;
        const reviewingMessage = appendAiMessage('assistant', 'Megz يراجع البيانات الآن...');

        try {
            const response = await fetch('ai_assistant.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ question: userQuestion }),
            });

            const data = await response.json();
            if (reviewingMessage) {
                reviewingMessage.remove();
            }

            if (!response.ok || !data.ok) {
                appendAiMessage('assistant', data.message || 'حدث خطأ أثناء التواصل مع المساعد الذكي.');
            } else {
                appendAiMessage('assistant', data.answer || 'تم استلام سؤالك.');
                renderAiSuggestions(data.suggested_questions || aiSuggestedQuestions);
            }
        } catch (error) {
            if (reviewingMessage) {
                reviewingMessage.remove();
            }
            appendAiMessage('assistant', 'تعذر الوصول إلى المساعد الآن، حاول مرة أخرى بعد لحظات.');
        } finally {
            aiChatSend.disabled = false;
            aiChatMessages.scrollTop = aiChatMessages.scrollHeight;
        }
    }

    if (aiChatToggle) {
        aiChatToggle.addEventListener('click', () => toggleAiChat());
    }

    if (aiChatClose) {
        aiChatClose.addEventListener('click', () => toggleAiChat(false));
    }

    if (aiChatForm) {
        aiChatForm.addEventListener('submit', (event) => {
            event.preventDefault();
            sendAiQuestion(aiChatInput.value);
        });
    }

    if (aiChatInput) {
        aiChatInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendAiQuestion(aiChatInput.value);
            }
        });
    }

    appendAiMessage('assistant', aiGreeting);
    renderAiSuggestions(aiSuggestedQuestions);
    <?php endif; ?>
</script>
</body>
</html>
