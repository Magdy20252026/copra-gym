<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'sales_helpers.php';
require_once 'employee_payroll_helpers.php';

ensureSalesSchema($pdo);
ensureEmployeePayrollSchema($pdo);

$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$username  = $_SESSION['username'] ?? '';
$role      = $_SESSION['role'] ?? '';
$isManager = ($role === 'مدير');

$errors = [];

$today      = date('Y-m-d');
$weekStart  = date('Y-m-d', strtotime('-6 days'));
$monthStart = date('Y-m-01');

/**
 * إحصائيات الفترة (يومي / أسبوعي / شهري)
 * تم إصلاح حساب حصص التمرينة الواحدة باستخدام عمود single_paid بدلاً من REGEXP على notes
 * وتم إصلاح التجديدات لتستخدم مبلغ المدفوع الحقيقي paid_amount بدل قيمة الاشتراك new_subscription_amount
 */
function getStatsForRange(PDO $pdo, string $start, string $end): array {
    $result = [
        'new_subscriptions_count'      => 0,
        'total_paid_for_new_subs'      => 0.0,
        'partial_payments_count'       => 0,
        'total_partial_payments'       => 0.0,
        'renewals_count'               => 0,
        'total_renewals_amount'        => 0.0,
        'regular_expenses'             => 0.0,
        'employee_advances'            => 0.0,
        'trainer_advances'             => 0.0,
        'employee_salaries'            => 0.0,
        'total_expenses'               => 0.0,
        'single_sessions_count'        => 0,
        'total_single_sessions_amount' => 0.0,
        'sales_operations_count'       => 0,
        'total_sales_amount'           => 0.0,
    ];

    try {
        // اشتراكات جديدة
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)                 AS c,
                COALESCE(SUM(initial_paid_amount), 0) AS s
            FROM members
            WHERE DATE(created_at) BETWEEN :start AND :end
        ");
        $stmt->execute([':start' => $start, ':end' => $end]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result['new_subscriptions_count'] = (int)($row['c'] ?? 0);
            $result['total_paid_for_new_subs'] = (float)($row['s'] ?? 0.0);
        }

        // سداد البواقي
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)         AS c,
                COALESCE(SUM(paid_amount), 0) AS s
            FROM partial_payments
            WHERE DATE(paid_at) BETWEEN :start AND :end
        ");
        $stmt->execute([':start' => $start, ':end' => $end]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result['partial_payments_count'] = (int)($row['c'] ?? 0);
            $result['total_partial_payments'] = (float)($row['s'] ?? 0.0);
        }

        // التجديدات (IMPORTANT: مجموع التجديدات = مجموع المدفوع الحقيقي paid_amount)
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*)                     AS c,
                COALESCE(SUM(
                    CASE
                        WHEN paid_now > 0 THEN paid_now
                        WHEN paid_amount > 0 THEN paid_amount
                        ELSE new_subscription_amount
                    END
                ), 0) AS s
            FROM renewals_log
            WHERE DATE(renewed_at) BETWEEN :start AND :end
        ");
        $stmt->execute([':start' => $start, ':end' => $end]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result['renewals_count']        = (int)($row['c'] ?? 0);
            $result['total_renewals_amount'] = (float)($row['s'] ?? 0.0);
        }

        $expenseSummary = getEmployeePayrollExpenseSummaryByDateRange($pdo, $start, $end);
        $result['regular_expenses'] = (float)$expenseSummary['regular_expenses'];
        $result['employee_advances'] = (float)$expenseSummary['employee_advances'];
        $result['trainer_advances'] = (float)($expenseSummary['trainer_advances'] ?? 0);
        $result['employee_salaries'] = (float)$expenseSummary['employee_salaries'];
        $result['total_expenses'] = (float)$expenseSummary['total_expenses'];

        // حصص التمرينة الو��حدة (باستخدام عمود single_paid بعد الإصلاح)
        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) AS single_sessions_count,
                COALESCE(SUM(single_paid), 0) AS total_single_paid
            FROM attendance
            WHERE type = 'حصة_واحدة'
              AND DATE(created_at) BETWEEN :start AND :end
        ");
        $stmt->execute([':start' => $start, ':end' => $end]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result['single_sessions_count']        = (int)($row['single_sessions_count'] ?? 0);
            $result['total_single_sessions_amount'] = (float)($row['total_single_paid'] ?? 0.0);
        }

        $salesSummary = getSalesSummary($pdo, $start, $end, true);
        $result['sales_operations_count'] = (int)$salesSummary['operations_count'];
        $result['total_sales_amount'] = (float)$salesSummary['net_sales_amount'];

    } catch (Exception $e) {
        // ممكن تسجّل الخطأ في ملف لوج إن أحببت
    }

    return $result;
}

$statsDay   = getStatsForRange($pdo, $today, $today);
$statsWeek  = getStatsForRange($pdo, $weekStart, $today);
$statsMonth = getStatsForRange($pdo, $monthStart, $today);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>الإحصائيات - <?php echo htmlspecialchars($siteName); ?></title>
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
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text-main);
            font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            font-weight: 900;
            font-size: 18px;
        }
        .page { max-width: 1200px; margin: 30px auto 40px; padding: 0 20px; }
        .header-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
        .title-main { font-size:28px; font-weight:900; }
        .back-button{
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            padding:11px 22px;border-radius:999px;border:none;cursor:pointer;
            font-size:16px;font-weight:900;
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 16px 38px rgba(79,70,229,0.55);text-decoration:none;
        }
        .back-button:hover { filter:brightness(1.05); }
        .card{
            background:var(--card-bg);border-radius:24px;padding:20px 22px 22px;
            box-shadow:0 22px 60px rgba(15,23,42,0.22),0 0 0 1px rgba(255,255,255,0.65);
            margin-bottom:18px;
        }
        .theme-toggle{display:flex;justify-content:flex-end;margin-bottom:14px;}
        .theme-switch{
            position:relative;width:78px;height:36px;border-radius:999px;
            background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);
            cursor:pointer;display:flex;align-items:center;justify-content:space-between;
            padding:0 8px;font-size:17px;color:#6b7280;font-weight:900;
        }
        .theme-switch span{z-index:2;user-select:none;}
        .theme-thumb{
            position:absolute;top:4px;right:4px;width:28px;height:28px;border-radius:999px;
            background:#facc15;box-shadow:0 4px 10px rgba(250,204,21,0.7);
            display:flex;align-items:center;justify-content:center;font-size:17px;
            transition:transform .25s ease,background .25s ease,box-shadow .25s ease;
        }
        body.dark .theme-switch{background:#020617;box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);color:#e5e7eb;}
        body.dark .theme-thumb{transform:translateX(-38px);background:#0f172a;box-shadow:0 4px 12px rgba(15,23,42,0.9);}
        .grid { display:flex;flex-wrap:wrap;gap:16px; }
        .stat-block{
            flex:1 1 320px;
            border-radius:18px;
            border:1px solid var(--border);
            padding:14px 16px;
        }
        .stat-block h3{margin:0 0 10px;font-size:20px;}
        .stat-row{display:flex;justify-content:space-between;margin-bottom:6px;font-size:16px;}
        .label{color:var(--text-muted);}
        .value{font-weight:900;}
        .note{margin-top:8px;font-size:14px;color:var(--text-muted);font-weight:700;}
        .alert{padding:11px 13px;border-radius:12px;font-size:16px;margin-bottom:12px;font-weight:900;}
        .alert-error{background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.9);color:var(--danger);}
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div class="title-main">الإحصائيات (يومي / أسبوعي / شهري)</div>
        <div>
            <a href="dashboard.php" class="back-button">
                <span>📊</span>
                <span>العودة إلى لوحة التحكم</span>
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

        <?php if (!$isManager): ?>
            <div class="alert alert-error">
                هذه الصفحة مخصّصة للمدير فقط، لا تملك صلاحية عرض الإحصائيات التفصيلية.
            </div>
        <?php else: ?>
            <div class="grid">
                <!-- إحصائيات اليوم -->
                <div class="stat-block">
                    <h3>تقرير اليوم</h3>
                    <div class="stat-row">
                        <span class="label">عدد الاشتراكات الجديدة:</span>
                        <span class="value"><?php echo $statsDay['new_subscriptions_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي المدفوع في الاشتراكات الجديدة:</span>
                        <span class="value"><?php echo number_format($statsDay['total_paid_for_new_subs'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">عدد عمليات سداد الباقي:</span>
                        <span class="value"><?php echo $statsDay['partial_payments_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي المبالغ المسددة (سداد باقي):</span>
                        <span class="value"><?php echo number_format($statsDay['total_partial_payments'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">عدد تجديدات الاشتراكات:</span>
                        <span class="value"><?php echo $statsDay['renewals_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي قيمة التجديدات (المدفوع الحقيقي):</span>
                        <span class="value"><?php echo number_format($statsDay['total_renewals_amount'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">عدد حصص التمرينة الواحدة:</span>
                        <span class="value"><?php echo $statsDay['single_sessions_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي المبلغ من حصص التمرينة الواحدة:</span>
                        <span class="value"><?php echo number_format($statsDay['total_single_sessions_amount'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">عدد عمليات المبيعات والمرتجع:</span>
                        <span class="value"><?php echo $statsDay['sales_operations_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي المبيعات بعد المرتجع:</span>
                        <span class="value"><?php echo number_format($statsDay['total_sales_amount'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">المصروفات العامة:</span>
                        <span class="value"><?php echo number_format($statsDay['regular_expenses'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">سلف الموظفين:</span>
                        <span class="value"><?php echo number_format($statsDay['employee_advances'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">سلف المدربين:</span>
                        <span class="value"><?php echo number_format($statsDay['trainer_advances'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">الرواتب المصروفة:</span>
                        <span class="value"><?php echo number_format($statsDay['employee_salaries'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">مجموع المصروفات اليومية:</span>
                        <span class="value"><?php echo number_format($statsDay['total_expenses'], 2); ?></span>
                    </div>
                    <div class="note">الفترة: اليوم فقط.</div>
                </div>

                <!-- إحصائيات الأسبوع -->
                <div class="stat-block">
                    <h3>تقرير أسبوعي</h3>
                    <div class="stat-row">
                        <span class="label">عدد الاشتراكات الجديدة:</span>
                        <span class="value"><?php echo $statsWeek['new_subscriptions_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي المدفوع في الاشتراكات الجديدة:</span>
                        <span class="value"><?php echo number_format($statsWeek['total_paid_for_new_subs'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">عدد عمليات سداد الباقي:</span>
                        <span class="value"><?php echo $statsWeek['partial_payments_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي المبالغ المسددة (سداد باقي):</span>
                        <span class="value"><?php echo number_format($statsWeek['total_partial_payments'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">عدد تجديدات الاشتراكات:</span>
                        <span class="value"><?php echo $statsWeek['renewals_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي قيمة التجديدات (المدفوع الحقيقي):</span>
                        <span class="value"><?php echo number_format($statsWeek['total_renewals_amount'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">عدد حصص التمرينة الواحدة:</span>
                        <span class="value"><?php echo $statsWeek['single_sessions_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي المبلغ من حصص التمرينة الواحدة:</span>
                        <span class="value"><?php echo number_format($statsWeek['total_single_sessions_amount'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">عدد عمليات المبيعات والمرتجع:</span>
                        <span class="value"><?php echo $statsWeek['sales_operations_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي المبيعات بعد المرتجع:</span>
                        <span class="value"><?php echo number_format($statsWeek['total_sales_amount'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">المصروفات العامة:</span>
                        <span class="value"><?php echo number_format($statsWeek['regular_expenses'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">سلف الموظفين:</span>
                        <span class="value"><?php echo number_format($statsWeek['employee_advances'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">سلف المدربين:</span>
                        <span class="value"><?php echo number_format($statsWeek['trainer_advances'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">الرواتب المصروفة:</span>
                        <span class="value"><?php echo number_format($statsWeek['employee_salaries'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">مجموع المصروفات الأسبوعية:</span>
                        <span class="value"><?php echo number_format($statsWeek['total_expenses'], 2); ?></span>
                    </div>
                    <div class="note">الفترة: آخر 7 أيام (اليوم + 6 أيام سابقة).</div>
                </div>

                <!-- إحصائيات الشهر -->
                <div class="stat-block">
                    <h3>تقرير شهري</h3>
                    <div class="stat-row">
                        <span class="label">عدد الاشتراكات الجديدة:</span>
                        <span class="value"><?php echo $statsMonth['new_subscriptions_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي المدفوع في الاشتراكات الجديدة:</span>
                        <span class="value"><?php echo number_format($statsMonth['total_paid_for_new_subs'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">عدد عمليات سداد الباقي:</span>
                        <span class="value"><?php echo $statsMonth['partial_payments_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي المبالغ المسددة (سداد باقي):</span>
                        <span class="value"><?php echo number_format($statsMonth['total_partial_payments'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">عدد تجديدات الاشتراكات:</span>
                        <span class="value"><?php echo $statsMonth['renewals_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي قيمة التجديدات (المدفوع الحقيقي):</span>
                        <span class="value"><?php echo number_format($statsMonth['total_renewals_amount'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">عدد حصص التمرينة الواحدة:</span>
                        <span class="value"><?php echo $statsMonth['single_sessions_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي المبلغ من حصص التمرينة الواحدة:</span>
                        <span class="value"><?php echo number_format($statsMonth['total_single_sessions_amount'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">عدد عمليات المبيعات والمرتجع:</span>
                        <span class="value"><?php echo $statsMonth['sales_operations_count']; ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">إجمالي المبيعات بعد المرتجع:</span>
                        <span class="value"><?php echo number_format($statsMonth['total_sales_amount'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">المصروفات العامة:</span>
                        <span class="value"><?php echo number_format($statsMonth['regular_expenses'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">سلف الموظفين:</span>
                        <span class="value"><?php echo number_format($statsMonth['employee_advances'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">سلف المدربين:</span>
                        <span class="value"><?php echo number_format($statsMonth['trainer_advances'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">الرواتب المصروفة:</span>
                        <span class="value"><?php echo number_format($statsMonth['employee_salaries'], 2); ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="label">مجموع المصروفات الشهرية:</span>
                        <span class="value"><?php echo number_format($statsMonth['total_expenses'], 2); ?></span>
                    </div>
                    <div class="note">الفترة: من أول الشهر الحالي حتى اليوم.</div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const body      = document.body;
    const switchEl  = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';

    function applyTheme(mode) {
        if (mode === 'dark') body.classList.add('dark'); else body.classList.remove('dark');
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
