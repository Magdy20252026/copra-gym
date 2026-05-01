<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['role'] ?? '') !== 'مدير') {
    header("Location: dashboard.php");
    exit;
}

require_once 'config.php';
require_once 'member_notifications_helpers.php';

ensureMemberNotificationsSchema($pdo);

if (empty($_SESSION['member_notifications_token'])) {
    $_SESSION['member_notifications_token'] = bin2hex(random_bytes(32));
}

$memberNotificationsToken = (string)$_SESSION['member_notifications_token'];
$errors = [];
$success = '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$siteName = 'Gym System';
$logoPath = null;

try {
    $stmt = $pdo->query("SELECT site_name, logo_path FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $siteName = $row['site_name'];
        $logoPath = $row['logo_path'] ?? null;
    }
} catch (Exception $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string)($_POST['member_notifications_token'] ?? '');
    if ($memberNotificationsToken === '' || $submittedToken === '' || !hash_equals($memberNotificationsToken, $submittedToken)) {
        $errors[] = 'تعذر التحقق من الطلب، من فضلك أعد المحاولة.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'create_notification') {
            $title = trim((string)($_POST['title'] ?? ''));
            $message = trim((string)($_POST['message'] ?? ''));

            if ($title === '') {
                $errors[] = 'من فضلك اكتب عنوان الإشعار.';
            }
            if ($message === '') {
                $errors[] = 'من فضلك اكتب نص الإشعار.';
            }
            if (mb_strlen($title) > 255) {
                $errors[] = 'عنوان الإشعار طويل جداً.';
            }

            if (!$errors) {
                try {
                    createBroadcastMemberNotification($pdo, $title, $message, $userId);
                    $success = 'تم إرسال الإشعار إلى المشتركين بنجاح.';
                    $_SESSION['member_notifications_token'] = bin2hex(random_bytes(32));
                    $memberNotificationsToken = (string)$_SESSION['member_notifications_token'];
                    $_POST = [];
                } catch (Exception $e) {
                    $errors[] = 'حدث خطأ أثناء حفظ الإشعار.';
                }
            }
        } elseif ($action === 'delete_notification') {
            $notificationId = (int)($_POST['notification_id'] ?? 0);
            if ($notificationId <= 0) {
                $errors[] = 'الإشعار المطلوب غير صالح.';
            } else {
                try {
                    if (deleteManualBroadcastMemberNotification($pdo, $notificationId)) {
                        $success = 'تم حذف الإشعار بنجاح.';
                    } else {
                        $errors[] = 'لم يتم العثور على الإشعار المطلوب.';
                    }
                } catch (Exception $e) {
                    $errors[] = 'حدث خطأ أثناء حذف الإشعار.';
                }
            }
        }
    }
}

$notifications = getManualBroadcastMemberNotifications($pdo, 100);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إشعارات المشتركين - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        :root {
            --bg: #e5e7eb;
            --card-bg: #f9fafb;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --border: #d1d5db;
            --primary: #ea580c;
            --primary-soft: rgba(234,88,12,0.12);
            --success: #16a34a;
            --danger: #dc2626;
        }
        body.dark {
            --bg: #020617;
            --card-bg: #020617;
            --text-main: #f9fafb;
            --text-muted: #e5e7eb;
            --border: #1f2937;
            --primary: #fb923c;
            --primary-soft: rgba(251,146,60,0.15);
            --success: #22c55e;
            --danger: #fb7185;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text-main);
            font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
        }
        .page {
            max-width: 1080px;
            margin: 0 auto;
            padding: 18px;
        }
        .card {
            background: var(--card-bg);
            border-radius: 22px;
            padding: 18px;
            box-shadow: 0 20px 45px rgba(15,23,42,0.16);
            border: 1px solid rgba(255,255,255,0.6);
        }
        .header-bar {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            margin-bottom:16px;
        }
        .brand {
            display:flex;
            align-items:center;
            gap:12px;
        }
        .logo {
            width:58px;
            height:58px;
            border-radius:18px;
            background:#fff;
            display:flex;
            align-items:center;
            justify-content:center;
            overflow:hidden;
            box-shadow:0 10px 24px rgba(15,23,42,0.18);
        }
        .logo img {
            width:100%;
            height:100%;
            object-fit:contain;
        }
        .brand-title {
            font-size:20px;
            font-weight:900;
        }
        .brand-subtitle {
            font-size:13px;
            color:var(--text-muted);
            font-weight:700;
        }
        .header-actions {
            display:flex;
            align-items:center;
            gap:10px;
            flex-wrap:wrap;
        }
        .theme-switch,
        .back-link,
        .btn-primary,
        .btn-delete {
            border:none;
            border-radius:999px;
            cursor:pointer;
            font-weight:900;
        }
        .theme-switch {
            position:relative;
            width:60px;
            height:28px;
            background:#e5e7eb;
            box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);
            display:flex;
            align-items:center;
            justify-content:space-between;
            padding:0 6px;
            color:#6b7280;
            font-size:12px;
        }
        body.dark .theme-switch {
            background:#020617;
            box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);
            color:#e5e7eb;
        }
        .theme-thumb {
            position:absolute;
            top:3px;
            right:3px;
            width:22px;
            height:22px;
            border-radius:999px;
            background:#facc15;
            display:flex;
            align-items:center;
            justify-content:center;
            transition:transform .25s ease;
        }
        body.dark .theme-thumb {
            transform:translateX(-28px);
            background:#0f172a;
        }
        .back-link,
        .btn-primary {
            padding:10px 18px;
            color:#fff;
            background:linear-gradient(90deg,#f97316,#ea580c);
            text-decoration:none;
            box-shadow:0 12px 26px rgba(234,88,12,0.28);
        }
        .section-grid {
            display:grid;
            grid-template-columns: minmax(280px, 360px) 1fr;
            gap:16px;
        }
        .panel {
            border:1px solid var(--border);
            border-radius:18px;
            padding:14px;
            background:rgba(255,255,255,0.55);
        }
        body.dark .panel {
            background:rgba(15,23,42,0.72);
        }
        .panel-title {
            font-size:18px;
            font-weight:900;
            margin-bottom:6px;
        }
        .panel-subtitle {
            font-size:13px;
            color:var(--text-muted);
            font-weight:700;
            line-height:1.8;
            margin-bottom:12px;
        }
        .field {
            display:flex;
            flex-direction:column;
            gap:6px;
            margin-bottom:12px;
        }
        .field label {
            font-size:13px;
            font-weight:800;
        }
        .field input,
        .field textarea {
            width:100%;
            border:1px solid var(--border);
            border-radius:14px;
            padding:11px 12px;
            background:#fff;
            color:var(--text-main);
            font:inherit;
        }
        body.dark .field input,
        body.dark .field textarea {
            background:#020617;
        }
        .field textarea {
            min-height:140px;
            resize:vertical;
        }
        .alert {
            margin-bottom:12px;
            border-radius:14px;
            padding:10px 12px;
            font-size:13px;
            font-weight:800;
        }
        .alert-success {
            background:rgba(22,163,74,0.12);
            color:var(--success);
            border:1px solid rgba(22,163,74,0.3);
        }
        .alert-error {
            background:rgba(220,38,38,0.1);
            color:var(--danger);
            border:1px solid rgba(220,38,38,0.28);
        }
        .notification-list {
            display:flex;
            flex-direction:column;
            gap:10px;
        }
        .notification-item {
            border:1px solid var(--border);
            border-radius:16px;
            padding:12px;
            background:rgba(234,88,12,0.05);
        }
        .notification-head {
            display:flex;
            align-items:flex-start;
            justify-content:space-between;
            gap:10px;
            margin-bottom:8px;
        }
        .notification-title {
            font-size:15px;
            font-weight:900;
        }
        .notification-time {
            font-size:12px;
            color:var(--text-muted);
            font-weight:700;
        }
        .notification-message {
            font-size:13px;
            line-height:1.9;
            font-weight:700;
            white-space:pre-line;
        }
        .btn-delete {
            padding:8px 12px;
            background:rgba(220,38,38,0.12);
            color:var(--danger);
        }
        .helper-box {
            margin-top:14px;
            border-radius:16px;
            padding:12px;
            background:var(--primary-soft);
            font-size:13px;
            font-weight:800;
            line-height:1.8;
        }
        @media (max-width: 820px) {
            .section-grid {
                grid-template-columns: 1fr;
            }
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
                        <span style="font-size:30px;">🔔</span>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="brand-title"><?php echo htmlspecialchars($siteName); ?></div>
                    <div class="brand-subtitle">إدارة إشعارات المشتركين من لوحة التحكم</div>
                </div>
            </div>
            <div class="header-actions">
                <a class="back-link" href="dashboard.php">↩ العودة للوحة التحكم</a>
                <button type="button" class="theme-switch" id="themeSwitch">
                    <span>🌙</span>
                    <span>☀️</span>
                    <div class="theme-thumb">☀️</div>
                </button>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="section-grid">
            <div class="panel">
                <div class="panel-title">إضافة إشعار جديد</div>
                <div class="panel-subtitle">
                    هذا الإشعار سيظهر داخل صفحة <strong>member_portal.php</strong> لكل المشتركين مع الجرس.
                </div>

                <form method="post">
                    <input type="hidden" name="action" value="create_notification">
                    <input type="hidden" name="member_notifications_token" value="<?php echo htmlspecialchars($memberNotificationsToken); ?>">

                    <div class="field">
                        <label for="title">عنوان الإشعار</label>
                        <input type="text" id="title" name="title" maxlength="255" required value="<?php echo htmlspecialchars((string)($_POST['title'] ?? '')); ?>">
                    </div>

                    <div class="field">
                        <label for="message">نص الإشعار</label>
                        <textarea id="message" name="message" required><?php echo htmlspecialchars((string)($_POST['message'] ?? '')); ?></textarea>
                    </div>

                    <button type="submit" class="btn-primary">📨 إرسال الإشعار</button>
                </form>

                <div class="helper-box">
                    سيتم أيضاً إنشاء إشعار تلقائي للمشترك قبل نهاية الاشتراك بخمسة أيام،
                    وإشعار آخر عند انتهاء الاشتراك فعلياً.
                </div>
            </div>

            <div class="panel">
                <div class="panel-title">الإشعارات المرسلة</div>
                <div class="panel-subtitle">يمكنك مراجعة الإشعارات العامة الحالية أو حذف أي إشعار يدوي.</div>

                <div class="notification-list">
                    <?php if ($notifications): ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item">
                                <div class="notification-head">
                                    <div>
                                        <div class="notification-title"><?php echo htmlspecialchars($notification['title']); ?></div>
                                        <div class="notification-time"><?php echo htmlspecialchars($notification['created_at']); ?></div>
                                    </div>
                                    <form method="post" onsubmit="return confirm('هل تريد حذف هذا الإشعار؟');">
                                        <input type="hidden" name="action" value="delete_notification">
                                        <input type="hidden" name="notification_id" value="<?php echo (int)$notification['id']; ?>">
                                        <input type="hidden" name="member_notifications_token" value="<?php echo htmlspecialchars($memberNotificationsToken); ?>">
                                        <button type="submit" class="btn-delete">حذف</button>
                                    </form>
                                </div>
                                <div class="notification-message"><?php echo nl2br(htmlspecialchars($notification['message'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notification-item">
                            <div class="notification-message">لا توجد إشعارات عامة مضافة حالياً.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const body = document.body;
    const themeSwitch = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymAdminTheme') || 'light';

    function applyTheme(mode) {
        if (mode === 'dark') {
            body.classList.add('dark');
        } else {
            body.classList.remove('dark');
        }
        localStorage.setItem('gymAdminTheme', mode);
    }

    applyTheme(savedTheme);

    if (themeSwitch) {
        themeSwitch.addEventListener('click', () => {
            applyTheme(body.classList.contains('dark') ? 'light' : 'dark');
        });
    }
</script>
</body>
</html>
