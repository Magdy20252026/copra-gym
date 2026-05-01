<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$subscriptionId = (int)($_GET['subscription_id'] ?? 0);
if ($subscriptionId <= 0) {
    header("Location: subscriptions.php");
    exit;
}

// جلب بيانات الاشتراك وإحصائياته
$subscription = null;
try {
    $stmt = $pdo->prepare("
        SELECT
            s.id,
            s.name,
            s.days,
            s.sessions,
            s.invites,
            s.freeze_days,
            s.price,
            s.price_after_discount,
            s.discount_percent,
            s.discount_end_date,
            COUNT(m.id) AS subscribers_count,
            SUM(CASE WHEN m.status = 'مستمر' THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN m.status = 'منتهي' THEN 1 ELSE 0 END) AS ended_count
        FROM subscriptions s
        LEFT JOIN members m ON m.subscription_id = s.id
        WHERE s.id = :sid
        GROUP BY
            s.id, s.name, s.days, s.sessions, s.invites, s.freeze_days,
            s.price, s.price_after_discount, s.discount_percent, s.discount_end_date
        LIMIT 1
    ");
    $stmt->execute([':sid' => $subscriptionId]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

if (!$subscription) {
    header("Location: subscriptions.php");
    exit;
}

// جلب قائمة المشتركين لهذا الاشتراك (مع عدد أيام الـ Freeze)
$members = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            id,
            name,
            phone,
            barcode,
            age,
            gender,
            address,
            days,
            sessions,
            sessions_remaining,
            invites,
            freeze_days,
            subscription_amount,
            paid_amount,
            remaining_amount,
            start_date,
            end_date,
            status
        FROM members
        WHERE subscription_id = :sid
        ORDER BY id DESC
    ");
    $stmt->execute([':sid' => $subscriptionId]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>مشتركو الاشتراك - <?php echo htmlspecialchars($subscription['name']); ?> - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        :root {
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --primary: #2563eb;
            --primary-soft: rgba(37,99,235,0.12);
            --accent-green: #22c55e;
            --danger: #ef4444;
            --border: #e5e7eb;
            --input-bg: #f9fafb;
        }
        body.dark {
            --bg: #020617;
            --card-bg: #020617;
            --text-main: #ffffff;
            --text-muted: #e5e7eb;
            --primary: #38bdf8;
            --primary-soft: rgba(56,189,248,0.25);
            --accent-green: #22c55e;
            --danger: #fb7185;
            --border: #1f2937;
            --input-bg: #020617;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text-main);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 900;
            font-size: 18px;
        }
        .page { max-width: 1200px; margin: 26px auto 40px; padding: 0 20px; }
        .header-bar { display:flex;justify-content:space-between;align-items:center;margin-bottom:22px; }
        .title-main{font-size:26px;font-weight:900;}
        .title-sub{margin-top:6px;font-size:16px;color:var(--text-muted);font-weight:800;}
        .back-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 22px;border-radius:999px;border:none;cursor:pointer;font-size:16px;font-weight:900;background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;box-shadow:0 16px 38px rgba(79,70,229,0.55);text-decoration:none;}
        .back-button:hover{filter:brightness(1.05);}
        .card{background:var(--card-bg);border-radius:26px;padding:20px 22px 22px;box-shadow:0 22px 60px rgba(15,23,42,0.22),0 0 0 1px rgba(255,255,255,0.65);}
        .theme-toggle{display:flex;justify-content:flex-end;margin-bottom:14px;}
        .theme-switch{position:relative;width:72px;height:34px;border-radius:999px;background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);cursor:pointer;display:flex;align-items:center;justify-content:space-between;padding:0 8px;font-size:16px;color:#6b7280;font-weight:900;}
        .theme-switch span{z-index:2;user-select:none;}
        .theme-thumb{position:absolute;top:3px;right:3px;width:26px;height:26px;border-radius:999px;background:#facc15;box-shadow:0 4px 10px rgba(250,204,21,0.7);display:flex;align-items:center;justify-content:center;font-size:16px;transition:transform .25s ease,background .25s ease,box-shadow .25s ease;}
        body.dark .theme-switch{background:#020617;box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);color:#e5e7eb;}
        body.dark .theme-thumb{transform:translateX(-36px);background:#0f172a;box-shadow:0 4px 12px rgba(15,23,42,0.9);}
        .stats-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;}
        .stat-box{
            flex:1 1 180px;
            background:rgba(15,23,42,0.02);
            border-radius:18px;
            padding:12px 14px;
            border:1px solid var(--border);
        }
        body.dark .stat-box{background:rgba(15,23,42,0.9);}
        .stat-title{font-size:14px;color:var(--text-muted);margin-bottom:4px;}
        .stat-value{font-size:20px;font-weight:900;}
        .table-wrapper{margin-top:10px;border-radius:20px;border:1px solid var(--border);overflow:auto;max-height:550px;}
        table{width:100%;border-collapse:collapse;font-size:16px;}
        thead{background:rgba(15,23,42,0.03);}
        body.dark thead{background:rgba(15,23,42,0.9);}
        th,td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;}
        th{font-weight:900;color:var(--text-muted);}
        td{font-weight:800;}
        .badge-status-active{color:#16a34a;}
        .badge-status-ended{color:#b91c1c;}
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">مشتركو الاشتراك: <?php echo htmlspecialchars($subscription['name']); ?></div>
            <div class="title-sub">
                عرض جميع المشتركين في هذا الاشتراك مع حالة كل اشتراك.
            </div>
        </div>
        <div>
            <a href="subscriptions.php" class="back-button">
                <span>↩</span>
                <span>العودة إلى إدارة الاشتراكات</span>
            </a>
        </div>
    </div>

    <div class="card">
        <div class="theme-toggle">
            <div class="theme-switch" id="themeSwitch">
                <span>🌙</span>
                <span>☀️</span>
                <div class="theme-thumb" id="themeThumb">☀️</div>
            </div>
        </div>

        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-title">إجمالي المشتركين في هذا الاشتراك</div>
                <div class="stat-value"><?php echo (int)$subscription['subscribers_count']; ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-title">الاشتراكات المستمرة</div>
                <div class="stat-value" style="color:#16a34a;"><?php echo (int)$subscription['active_count']; ?></div>
            </div>
            <div class="stat-box">
                <div class="stat-title">الاشتراكات المنتهية</div>
                <div class="stat-value" style="color:#b91c1c;"><?php echo (int)$subscription['ended_count']; ?></div>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>الاسم</th>
                    <th>الهاتف</th>
                    <th>الباركود</th>
                    <th>السن</th>
                    <th>النوع</th>
                    <th>الأيام</th>
                    <th>أيام الـ Freeze</th>
                    <th>التمارين المتبقية</th>
                    <th>المتبقي المالي</th>
                    <th>تاريخ البداية</th>
                    <th>تاريخ النهاية</th>
                    <th>الحالة</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$members): ?>
                    <tr>
                        <td colspan="12" style="text-align:center;color:var(--text-muted);font-weight:800;">
                            لا يوجد مشتركين مسجلين في هذا الاشتراك حتى الآن.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($m['name']); ?></td>
                            <td><?php echo htmlspecialchars($m['phone']); ?></td>
                            <td><?php echo htmlspecialchars($m['barcode']); ?></td>
                            <td><?php echo (int)$m['age']; ?></td>
                            <td><?php echo htmlspecialchars($m['gender']); ?></td>
                            <td><?php echo (int)$m['days']; ?></td>
                            <td><?php echo (int)$m['freeze_days']; ?></td>
                            <td><?php echo (int)$m['sessions_remaining']; ?></td>
                            <td><?php echo number_format($m['remaining_amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($m['start_date']); ?></td>
                            <td><?php echo htmlspecialchars($m['end_date']); ?></td>
                            <td>
                                <?php if ($m['status'] === 'مستمر'): ?>
                                    <span class="badge-status-active">مستمر</span>
                                <?php else: ?>
                                    <span class="badge-status-ended">منتهي</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const body = document.body;
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
</script>
</body>
</html>