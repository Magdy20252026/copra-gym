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

// منع التخزين المؤقت
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? '';
$userId   = (int)($_SESSION['user_id'] ?? 0);

// هنا نسمح فقط للمدير مثلاً
if ($role !== 'مدير') {
    header("Location: dashboard.php");
    exit;
}

$errors  = [];
$success = "";

/*
 * منطق التقفيل الشهري:
 * - نعتمد على آخر وقت تقفيل فعلي closed_at من جدول weekly_closings
 * - الإحصائيات تُحسب من بعد هذا الوقت حتى الآن
 * - المصروفات تعتمد على expenses.created_at مثل باقي الحركات
 */

// جلب آخر وقت تقفيل (closed_at) من جدول weekly_closings
$lastMonthCloseDateTime = null;
try {
    $stmt = $pdo->query("SELECT MAX(closed_at) AS last_close FROM weekly_closings");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['last_close']) {
        $lastMonthCloseDateTime = $row['last_close']; // مثال: 2026-02-01 23:59:59
    }
} catch (Exception $e) {
    $errors[] = "حدث خطأ أثناء جلب آخر وقت تقفيل شهر.";
}

$nowDateTime = date('Y-m-d H:i:s');
$today       = date('Y-m-d');

// تحديد وقت بداية الإحصائيات للفترة الحالية
if ($lastMonthCloseDateTime) {
    $periodStartDateTime = $lastMonthCloseDateTime;
} else {
    $periodStartDateTime = '1970-01-01 00:00:00';
}

// تفاصيل: الرد Ajax/تصدير Excel
if (isset($_GET['details']) && in_array($_GET['details'], ['newsubs','partials','renewals','singles','sales','expenses'])) {
    $type = $_GET['details'];
    $start = $periodStartDateTime;
    $end = $nowDateTime;

    header('Content-Type: application/json; charset=utf-8');
    switch ($type) {
        case 'newsubs':
            $stmt = $pdo->prepare("SELECT name, phone, initial_paid_amount AS 'المدفوع', created_at AS 'تاريخ التسجيل' FROM members WHERE created_at > :start AND created_at <= :end");
            $stmt->execute([':start'=>$start, ':end'=>$end]);
            echo json_encode(['headings'=>['اسم المشترك','رقم الهاتف','المدفوع','تاريخ التسجيل'],'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
        case 'partials':
            $stmt = $pdo->prepare("SELECT m.name AS 'اسم المشترك', m.phone AS 'رقم الهاتف', pp.paid_amount AS 'المدفوع', pp.paid_at AS 'تاريخ السداد' FROM partial_payments pp LEFT JOIN members m ON m.id=pp.member_id WHERE pp.paid_at > :start AND pp.paid_at <= :end");
            $stmt->execute([':start'=>$start, ':end'=>$end]);
            echo json_encode(['headings'=>['اسم المشترك','رقم الهاتف','المدفوع','تاريخ السداد'],'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
        case 'renewals':
            // ✅ FIX: عرض "المدفوع الحقيقي" للتجديد
            $stmt = $pdo->prepare("
                SELECT
                    m.name AS 'اسم المشترك',
                    m.phone AS 'رقم الهاتف',
                    CASE
                        WHEN rl.paid_now > 0 THEN rl.paid_now
                        WHEN rl.paid_amount > 0 THEN rl.paid_amount
                        ELSE rl.new_subscription_amount
                    END AS 'المدفوع',
                    rl.renewed_at AS 'تاريخ التجديد'
                FROM renewals_log rl
                LEFT JOIN members m ON m.id=rl.member_id
                WHERE rl.renewed_at > :start AND rl.renewed_at <= :end
            ");
            $stmt->execute([':start'=>$start, ':end'=>$end]);
            echo json_encode(['headings'=>['اسم المشترك','رقم الهاتف','المدفوع','تاريخ التجديد'],'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
        case 'singles':
            $stmt = $pdo->prepare("SELECT name AS 'الاسم', phone AS 'الهاتف', single_paid AS 'المدفوع', created_at AS 'التاريخ' FROM attendance WHERE type='حصة_واحدة' AND created_at > :start AND created_at <= :end");
            $stmt->execute([':start'=>$start, ':end'=>$end]);
            echo json_encode(['headings'=>['الاسم','الهاتف','المدفوع','التاريخ'],'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
        case 'sales':
            $stmt = $pdo->prepare("SELECT transaction_type AS 'نوع العملية', cashier_name AS 'الكاشير', item_name AS 'الصنف', quantity AS 'العدد', unit_price AS 'سعر الوحدة', total_amount AS 'الإجمالي', created_at AS 'وقت العملية' FROM sales WHERE created_at > :start AND created_at <= :end ORDER BY created_at DESC, id DESC");
            $stmt->execute([':start'=>$start, ':end'=>$end]);
            echo json_encode(['headings'=>['نوع العملية','الكاشير','الصنف','العدد','سعر الوحدة','الإجمالي','وقت العملية'],'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE); exit;
        case 'expenses':
            $expenseRows = getEmployeePayrollExpenseDetailsByTimestampRange($pdo, $start, $end);
            $formattedRows = [];
            foreach ($expenseRows as $expenseRow) {
                $formattedRows[] = [
                    'النوع' => $expenseRow['expense_type'],
                    'البند' => $expenseRow['item'],
                    'القيمة' => $expenseRow['amount'],
                    'تاريخ المصروف' => $expenseRow['expense_date'],
                    'وقت الإدخال' => $expenseRow['created_at'],
                ];
            }
            echo json_encode(['headings'=>['النوع','البند','القيمة','تاريخ المصروف','وقت الإدخال'],'rows'=>$formattedRows], JSON_UNESCAPED_UNICODE); exit;
    }
}
if (isset($_GET['export']) && in_array($_GET['export'], ['newsubs','partials','renewals','singles','sales','expenses'])) {
    $type = $_GET['export'];
    $start = $periodStartDateTime;
    $end = $nowDateTime;
    switch ($type) {
        case 'newsubs':
            $stmt = $pdo->prepare("SELECT name, phone, initial_paid_amount, created_at FROM members WHERE created_at > :start AND created_at <= :end");
            $stmt->execute([':start'=>$start, ':end'=>$end]);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            $headers = ['اسم المشترك','رقم الهاتف','المدفوع','تاريخ التسجيل'];
            $filename = 'تفاصيل_الاشتراكات_الجديدة_'.date('Ymd_His').'.xls';
            break;
        case 'partials':
            $stmt = $pdo->prepare("SELECT m.name, m.phone, pp.paid_amount, pp.paid_at FROM partial_payments pp LEFT JOIN members m ON m.id=pp.member_id WHERE pp.paid_at > :start AND pp.paid_at <= :end");
            $stmt->execute([':start'=>$start, ':end'=>$end]);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            $headers = ['اسم المشترك','رقم الهاتف','المدفوع','تاريخ السداد'];
            $filename = 'تفاصيل_سداد_البواقي_'.date('Ymd_His').'.xls';
            break;
        case 'renewals':
            // ✅ FIX: تصدير "المدفوع الحقيقي" للتجديد
            $stmt = $pdo->prepare("
                SELECT
                    m.name,
                    m.phone,
                    CASE
                        WHEN rl.paid_now > 0 THEN rl.paid_now
                        WHEN rl.paid_amount > 0 THEN rl.paid_amount
                        ELSE rl.new_subscription_amount
                    END,
                    rl.renewed_at
                FROM renewals_log rl
                LEFT JOIN members m ON m.id=rl.member_id
                WHERE rl.renewed_at > :start AND rl.renewed_at <= :end
            ");
            $stmt->execute([':start'=>$start, ':end'=>$end]);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            $headers = ['اسم المشترك','رقم الهاتف','المدفوع','تاريخ التجديد'];
            $filename = 'تفاصيل_تجديدات_الاشتراكات_'.date('Ymd_His').'.xls';
            break;
        case 'singles':
            $stmt = $pdo->prepare("SELECT name, phone, single_paid, created_at FROM attendance WHERE type='حصة_واحدة' AND created_at > :start AND created_at <= :end");
            $stmt->execute([':start'=>$start, ':end'=>$end]);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            $headers = ['الاسم','الهاتف','المدفوع','التاريخ'];
            $filename = 'تفاصيل_حصص_التمرين_الواحد_'.date('Ymd_His').'.xls';
            break;
        case 'sales':
            $stmt = $pdo->prepare("SELECT transaction_type, cashier_name, item_name, quantity, unit_price, total_amount, created_at FROM sales WHERE created_at > :start AND created_at <= :end ORDER BY created_at DESC, id DESC");
            $stmt->execute([':start'=>$start, ':end'=>$end]);
            $rows = $stmt->fetchAll(PDO::FETCH_NUM);
            $headers = ['نوع العملية','الكاشير','الصنف','العدد','سعر الوحدة','الإجمالي','وقت العملية'];
            $filename = 'تفاصيل_المبيعات_'.date('Ymd_His').'.xls';
            break;
        case 'expenses':
            $expenseRows = getEmployeePayrollExpenseDetailsByTimestampRange($pdo, $start, $end);
            $rows = [];
            foreach ($expenseRows as $expenseRow) {
                $rows[] = [
                    $expenseRow['expense_type'],
                    $expenseRow['item'],
                    $expenseRow['amount'],
                    $expenseRow['expense_date'],
                    $expenseRow['created_at'],
                ];
            }
            $headers = ['النوع','البند','القيمة','تاريخ المصروف','وقت الإدخال'];
            $filename = 'تفاصيل_المصروفات_'.date('Ymd_His').'.xls';
            break;
        default: exit;
    }
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo "\xEF\xBB\xBF";
    echo implode("\t", $headers)."\n";
    foreach($rows as $row){ echo implode("\t", $row)."\n"; }
    exit;
}

// متغيرات الإحصاء
$newSubsCount  = 0;
$totalPaidNew  = 0.00;
$partialCount  = 0;
$partialTotal  = 0.00;
$renewCount    = 0;
$renewTotal    = 0.00;
$regularExpenses = 0.00;
$employeeAdvancesExpenses = 0.00;
$trainerAdvancesExpenses = 0.00;
$employeeSalariesExpenses = 0.00;
$totalExpenses = 0.00;
$singleCount   = 0;
$singleTotal   = 0.00;
$salesCount    = 0;
$totalSales    = 0.00;

// حساب الإحصائيات للفترة
try {
    // اشتراكات جديدة
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS new_subscriptions_count,
            COALESCE(SUM(initial_paid_amount), 0) AS total_paid_for_new_subs
        FROM members
        WHERE created_at > :start
          AND created_at <= :end
    ");
    $stmt->execute([
        ':start' => $periodStartDateTime,
        ':end'   => $nowDateTime,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $newSubsCount = (int)$row['new_subscriptions_count'];
        $totalPaidNew = (float)$row['total_paid_for_new_subs'];
    }

    // سداد البواقي
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS partial_payments_count,
            COALESCE(SUM(paid_amount), 0) AS total_partial_payments
        FROM partial_payments
        WHERE paid_at > :start
          AND paid_at <= :end
    ");
    $stmt->execute([
        ':start' => $periodStartDateTime,
        ':end'   => $nowDateTime,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $partialCount = (int)$row['partial_payments_count'];
        $partialTotal = (float)$row['total_partial_payments'];
    }

    // التجديدات
    // ✅ FIX: جمع المدفوع الحقيقي (paid_now)
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS renewals_count,
            COALESCE(SUM(
                CASE
                    WHEN paid_now > 0 THEN paid_now
                    WHEN paid_amount > 0 THEN paid_amount
                    ELSE new_subscription_amount
                END
            ), 0) AS total_renewals_amount
        FROM renewals_log
        WHERE renewed_at > :start
          AND renewed_at <= :end
    ");
    $stmt->execute([
        ':start' => $periodStartDateTime,
        ':end'   => $nowDateTime,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $renewCount = (int)$row['renewals_count'];
        $renewTotal = (float)$row['total_renewals_amount'];
    }

    $expenseSummary = getEmployeePayrollExpenseSummaryByTimestampRange($pdo, $periodStartDateTime, $nowDateTime);
    $regularExpenses = (float)$expenseSummary['regular_expenses'];
    $employeeAdvancesExpenses = (float)$expenseSummary['employee_advances'];
    $trainerAdvancesExpenses = (float)($expenseSummary['trainer_advances'] ?? 0);
    $employeeSalariesExpenses = (float)$expenseSummary['employee_salaries'];
    $totalExpenses = (float)$expenseSummary['total_expenses'];

    // حصص التمرينة الواحدة
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS single_sessions_count,
            COALESCE(SUM(single_paid), 0) AS total_single_paid
        FROM attendance
        WHERE type = 'حصة_واحدة'
          AND created_at > :start
          AND created_at <= :end
    ");
    $stmt->execute([
        ':start' => $periodStartDateTime,
        ':end'   => $nowDateTime,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $singleCount = (int)$row['single_sessions_count'];
        $singleTotal = (float)$row['total_single_paid'];
    }

    $salesSummary = getSalesSummary($pdo, $periodStartDateTime, $nowDateTime, false);
    $salesCount = (int)$salesSummary['operations_count'];
    $totalSales = (float)$salesSummary['net_sales_amount'];

    // إجمالي صافي الشهر = (اجمالي الاشتراكات + اجمالي التجديدات + اجمالي تمرينة واحدة + اجمالي سداد البواقي + المبيعات) - المصروفات
    $netTotal = ($totalPaidNew + $renewTotal + $singleTotal + $partialTotal + $totalSales) - $totalExpenses;

} catch (Exception $e) {
    $errors[] = "حدث خطأ أثناء حساب إحصائيات الفترة الحالية.";
}

// عند الضغط على "تأكيد تقفيل الشهر"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && $_POST['confirm'] === '1') {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO weekly_closings
                (week_start, week_end,
                 new_subscriptions_count, total_paid_for_new_subs,
                 partial_payments_count, total_partial_payments,
                 renewals_count, total_renewals_amount,
                 single_sessions_count, total_single_sessions_amount,
                 sales_operations_count, total_sales_amount,
                 total_expenses, net_total, closed_by_user_id)
            VALUES
                (:ws, :we,
                 :new_subs, :total_new,
                 :partial_count, :partial_total,
                 :renew_count, :renew_total,
                 :single_count, :single_total,
                 :sales_count, :sales_total,
                 :expenses, :net_total, :uid)
        ");
        $stmt->execute([
            ':ws'            => date('Y-m-d', strtotime($periodStartDateTime)),
            ':we'            => $today,
            ':new_subs'      => $newSubsCount,
            ':total_new'     => $totalPaidNew,
            ':partial_count' => $partialCount,
            ':partial_total' => $partialTotal,
            ':renew_count'   => $renewCount,
            ':renew_total'   => $renewTotal,
            ':single_count'  => $singleCount,
            ':single_total'  => $singleTotal,
            ':sales_count'   => $salesCount,
            ':sales_total'   => $totalSales,
            ':expenses'      => $totalExpenses,
            ':net_total'     => $netTotal,
            ':uid'           => $userId,
        ]);

        $success = "تم تقفيل الشهر بنجاح، وستبدأ الإحصائيات من جديد من الآن.";
        header("Location: close_month.php?done=1");
        exit;
    } catch (Exception $e) {
        $errors[] = "حدث خطأ أثناء حفظ بيانات تقفيل الشهر.";
    }
}

if (isset($_GET['done']) && !$success) {
    $success = "تم تقفيل الشهر بنجاح، وستبدأ الإحصائيات من جديد من الآن.";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تقفيل شهر - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        :root {
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --primary: #2563eb;
            --primary-soft: rgba(37,99,235,0.15);
            --danger: #ef4444;
            --border: #e5e7eb;
        }
        body.dark {
            --bg: #020617;
            --card-bg: #020617;
            --text-main: #ffffff;
            --text-muted: #e5e7eb;
            --primary: #38bdf8;
            --primary-soft: rgba(56,189,248,0.3);
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
        .page {
            max-width: 900px;
            margin: 30px auto 50px;
            padding: 0 20px;
        }
        .header-bar {
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:22px;
        }
        .title-main { font-size:26px;font-weight:900; }
        .title-sub { margin-top:6px;font-size:16px;color:var(--text-muted);font-weight:800; }
        .back-button {
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            padding:11px 22px;border-radius:999px;border:none;cursor:pointer;
            font-size:16px;font-weight:900;
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 16px 38px rgba(79,70,229,0.55);
            text-decoration:none;
        }
        .back-button:hover { filter:brightness(1.05); }
        .card {
            background:var(--card-bg);
            border-radius:24px;
            padding:20px 20px 24px;
            box-shadow:0 22px 60px rgba(15,23,42,0.22),
                       0 0 0 1px rgba(255,255,255,0.65);
        }
        .theme-toggle {
            display:flex;
            justify-content:flex-end;
            margin-bottom:14px;
        }
        .theme-switch {
            position:relative;width:72px;height:34px;border-radius:999px;
            background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);
            cursor:pointer;display:flex;align-items:center;justify-content:space-between;
            padding:0 8px;font-size:16px;color:#6b7280;font-weight:900;
        }
        .theme-switch span { z-index:2;user-select:none; }
        .theme-thumb {
            position:absolute;top:3px;right:3px;width:26px;height:26px;border-radius:999px;
            background:#facc15;box-shadow:0 4px 10px rgba(250,204,21,0.7);
            display:flex;align-items:center;justify-content:center;font-size:16px;
            transition:transform .25s ease,background .25s.ease,box-shadow .25s.ease;
        }
        body.dark .theme-switch {
            background:#020617;
            box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);
            color:#e5e7eb;
        }
        body.dark .theme-thumb {
            transform:translateX(-36px);
            background:#0f172a;
            box-shadow:0 4px 12px rgba(15,23,42,0.9);
        }
        .alert {
            padding:11px 13px;
            border-radius:12px;
            font-size:16px;
            margin-bottom:12px;
            font-weight:900;
        }
        .alert-error {
            background:rgba(239,68,68,0.08);
            border:1px solid rgba(239,68,68,0.8);
            color:var(--danger);
        }
        .alert-success {
            background:rgba(37,197,94,0.08);
            border:1px solid rgba(37,197,94,0.8);
            color:var(--primary);
        }
        .stats-grid {
            display:grid;
            grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
            gap:12px;
            margin:14px 0 18px;
        }
        .stat-card {
            border-radius:18px;
            border:1px solid var(--border);
            padding:12px 14px;
            background:rgba(15,23,42,0.01);
        }
        body.dark .stat-card {
            background:rgba(15,23,42,0.3);
        }
        .stat-label {
            font-size:14px;
            color:var(--text-muted);
            margin-bottom:4px;
        }
        .stat-value {
            font-size:22px;
            font-weight:900;
        }
        .muted { font-size:14px;color:var(--text-muted);font-weight:700;margin-top:4px; }
        .btn-confirm {
            margin-top:8px;
            border-radius:999px;
            padding:11px 22px;
            border:none;
            cursor:pointer;
            font-size:18px;
            font-weight:900;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            background:linear-gradient(90deg,#2563eb,#22c55e);
            color:#f9fafb;
            box-shadow:0 18px 40px rgba(37,99,235,0.7);
        }
        .btn-confirm:hover { filter:brightness(1.05); }
        .detail-btn {display:inline-block;margin-bottom:6px;padding:2px 13px 2px 13px;border-radius:8px;border:none;color:#fff;background:#0ea5e9;cursor:pointer;font-size:14px;margin-right:6px}
        .detail-btn:hover {background:#0369a1}
        #modal-overlay {position:fixed;top:0;left:0;right:0;bottom:0;z-index:201;background:rgba(0,0,0,0.32);display:none;align-items:center;justify-content:center;}
        #modal-content {background:#fff;color:#222;direction:rtl;min-width:340px;max-width:95vw;padding:23px 11px 14px 11px;border-radius:20px;box-shadow:0 10px 35px rgba(0,0,0,0.17)}
        body.dark #modal-content {background:#252528;color:#fff;}
        /* تمرير احترافي لصندوق التفاصيل */
        #modalbody {
            max-height: 370px;
            overflow-y: auto;
            margin-bottom:10px;
            border-radius:8px;
            scrollbar-width: thin;
            scrollbar-color: #2563eb #e5e7eb;
        }
        #modalbody::-webkit-scrollbar {
            height: 8px;
            width: 9px;
            background: #f3f4f6;
            border-radius: 8px;
        }
        #modalbody::-webkit-scrollbar-thumb {
            background: #2563eb;
            border-radius: 8px;
        }
        #modalbody::-webkit-scrollbar-track {
            background: #e5e7eb;
            border-radius: 8px;
        }
        body.dark #modalbody::-webkit-scrollbar {
            background: #020617;
        }
        body.dark #modalbody::-webkit-scrollbar-thumb {
            background: #38bdf8;
        }
        body.dark #modalbody::-webkit-scrollbar-track {
            background: #1f2937;
        }
        body.dark #modalbody {
            scrollbar-color: #38bdf8 #1f2937;
        }
        #modal-content table{width:100%;font-size:15px;}
        #modal-content th,#modal-content td{border-bottom:1px solid #eee;padding:4px 5px}
        .modal-head{font-size:19px;font-weight:900;margin-bottom:12px;}
        #close-modal{float:left;font-size:24px;appearance:none;border:none;background:transparent;font-weight:900;cursor:pointer;}
        .modal-excel-btn{padding:5px 19px;border-radius:9px;background:#22c55e;color:white;border:none;font-size:16px;font-weight:800;margin-top:10px}
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">تقفيل شهر</div>
            <div class="title-sub">
            </div>
        </div>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <a href="dashboard.php" class="back-button">
                <span>📊</span>
                <span>العودة للوحة التحكم</span>
            </a>
            <a href="monthly_closings_log.php"
               class="back-button"
               style="background:linear-gradient(90deg,#0ea5e9,#6366f1);box-shadow:0 16px 38px rgba(14,165,233,0.55);">
                <span>📜</span>
                <span>سجل التقفيلات الشهرية</span>
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

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?>
                    <div>• <?php echo htmlspecialchars($e); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="muted"></div>

        <div class="stats-grid">
            <div class="stat-card">
                <button class="detail-btn" onclick="showDetails('newsubs','تفاصيل الاشتراكات الجديدة')">تفاصيل</button>
                <div class="stat-label">عدد الاشتراكات الجديدة</div>
                <div class="stat-value"><?php echo $newSubsCount; ?></div>
            </div>
            <div class="stat-card">
                <button class="detail-btn" onclick="showDetails('partials','تفاصيل سداد البواقي')">تفاصيل</button>
                <div class="stat-label">عدد عمليات سداد البواقي</div>
                <div class="stat-value"><?php echo $partialCount; ?></div>
            </div>
            <div class="stat-card">
                <button class="detail-btn" onclick="showDetails('renewals','تفاصيل التجديدات')">تفاصيل</button>
                <div class="stat-label">عدد التجديدات</div>
                <div class="stat-value"><?php echo $renewCount; ?></div>
            </div>
            <div class="stat-card">
                <button class="detail-btn" onclick="showDetails('singles','تفاصيل حصص التمرينة الواحدة')">تفاصيل</button>
                <div class="stat-label">عدد حصص التمرينة الواحدة</div>
                <div class="stat-value"><?php echo $singleCount; ?></div>
            </div>
            <div class="stat-card">
                <button class="detail-btn" onclick="showDetails('sales','تفاصيل المبيعات')">تفاصيل</button>
                <div class="stat-label">عدد عمليات المبيعات والمرتجع</div>
                <div class="stat-value"><?php echo $salesCount; ?></div>
            </div>
            <div class="stat-card">
                <button class="detail-btn" onclick="showDetails('expenses','تفاصيل المصروفات')">تفاصيل</button>
                <div class="stat-label">إجمالي المصروفات (الفترة)</div>
                <div class="stat-value"><?php echo number_format($totalExpenses, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">المصروفات العامة</div>
                <div class="stat-value"><?php echo number_format($regularExpenses, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">سلف الموظفين</div>
                <div class="stat-value"><?php echo number_format($employeeAdvancesExpenses, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">سلف المدربين</div>
                <div class="stat-value"><?php echo number_format($trainerAdvancesExpenses, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">الرواتب المصروفة</div>
                <div class="stat-value"><?php echo number_format($employeeSalariesExpenses, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي ال��دفوع في الاشتراكات الجديدة</div>
                <div class="stat-value"><?php echo number_format($totalPaidNew, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي سداد البواقي</div>
                <div class="stat-value"><?php echo number_format($partialTotal, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي مبالغ التجديدات</div>
                <div class="stat-value"><?php echo number_format($renewTotal, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي المبلغ من حصص التمرينة الواحدة</div>
                <div class="stat-value"><?php echo number_format($singleTotal, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي المبيعات بعد المرتجع</div>
                <div class="stat-value"><?php echo number_format($totalSales, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">إجمالي صافي الشهر</div>
                <div class="stat-value"><?php echo number_format($netTotal, 2); ?></div>
            </div>
        </div>

        <div class="muted"></div>

        <form method="post" action="">
            <input type="hidden" name="confirm" value="1">
            <button type="submit" class="btn-confirm">
                <span>🔒</span>
                <span>تأكيد تقفيل الشهر</span>
            </button>
        </form>
    </div>
</div>
<div id="modal-overlay" onclick="closeModal(event)">
    <div id="modal-content" onclick="event.stopPropagation()">
        <button id="close-modal" onclick="closeModal(event)">&times;</button>
        <div class="modal-head" id="modaltitle">التفاصيل</div>
        <div id="modalbody" style="overflow-x:auto;">جاري التحميل ...</div>
        <button style="display:none" id="modal-excel-btn" class="modal-excel-btn">تصدير Excel</button>
    </div>
</div>
<script>
let lastType = '';
function showDetails(type, title){
    lastType = type;
    document.getElementById('modaltitle').innerText = title;
    document.getElementById('modal-overlay').style.display='flex';
    document.getElementById('modalbody').innerHTML = 'جاري التحميل...';
    document.getElementById('modal-excel-btn').style.display='none';

    fetch('?details='+type)
      .then(r=>r.json())
      .then(d=>{
        let html = "<table><thead><tr>";
        d.headings.forEach(h=>html+="<th>"+h+"</th>");
        html+="</tr></thead><tbody>";
        if(d.rows.length===0) html+='<tr><td colspan="'+d.headings.length+'">لا يوجد بيانات</td></tr>';
        d.rows.forEach(row=>{
            html+="<tr>";
            Object.values(row).forEach(v=>html+="<td>"+v+"</td>");
            html+="</tr>";
        });
        html+="</tbody></table>";
        document.getElementById('modalbody').innerHTML = html;
        document.getElementById('modal-excel-btn').style.display='inline-block';
      });
}
function closeModal(e){if(e)e.preventDefault(); document.getElementById('modal-overlay').style.display='none';}
document.getElementById('modal-excel-btn').onclick = function(){
    window.open('?export='+lastType,'_blank');
}
const body = document.body;
const switchEl = document.getElementById('themeSwitch');
const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';

function applyTheme(mode) {
    if (mode === 'dark') body.classList.add('dark');
    else body.classList.remove('dark');
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
