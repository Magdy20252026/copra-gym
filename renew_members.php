<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'trainers_helpers.php';
require_once 'members_payment_helpers.php';

ensureTrainersSchema($pdo);
ensureMembersPaymentTypeSchema($pdo);
ensureRenewalsLogPaymentTypeSchema($pdo);
ensureSubscriptionCategorySchema($pdo);

$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$username   = $_SESSION['username'] ?? '';
$role       = $_SESSION['role'] ?? '';
$userId     = (int)($_SESSION['user_id'] ?? 0);

// السماح للمدير أو المشرف (لو زر الصفحة ظاهر له من صلاحيات المستخدمين)
$isManager    = ($role === 'مدير');
$isSupervisor = ($role === 'مشرف');

// قراءة صلاحيات المشرف من جدول user_permissions (مثل ما يحصل في dashboard.php)
$perms = [
    'can_view_renew_members' => 0,
];

if ($isSupervisor && $userId) {
    try {
        $stmtPerm = $pdo->prepare("SELECT can_view_renew_members FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $perms['can_view_renew_members'] = (int)$rowPerm['can_view_renew_members'];
        }
    } catch (Exception $e) {
        // في حالة الخطأ نعتبر أنه لا يملك صلاحية
        $perms['can_view_renew_members'] = 0;
    }
}

// الآن نسمح بالدخول إذا:
// - مدير، أو
// - مشرف ولديه صلاحية can_view_renew_members = 1 (يعني الزر ظاهر له)
$canAccessPage = $isManager || ($isSupervisor && $perms['can_view_renew_members'] == 1);

if (!$canAccessPage) {
    header("Location: dashboard.php");
    exit;
}

$errors  = [];
$success = "";

function getRemainingSubscriptionDays($endDate, $referenceDate = null)
{
    $endDate = trim((string)$endDate);
    if ($endDate === '') {
        return 0;
    }

    $referenceDate = $referenceDate ? trim((string)$referenceDate) : date('Y-m-d');
    if ($referenceDate === '') {
        $referenceDate = date('Y-m-d');
    }

    $endTimestamp = strtotime($endDate . ' 00:00:00');
    $referenceTimestamp = strtotime($referenceDate . ' 00:00:00');
    if ($endTimestamp === false || $referenceTimestamp === false || $endTimestamp <= $referenceTimestamp) {
        return 0;
    }

    return (int)floor(($endTimestamp - $referenceTimestamp) / 86400);
}

// جلب الاشتراكات المتاحة
$subscriptions = [];
try {
    $stmt = $pdo->query("
        SELECT id, name, subscription_category, days, sessions, invites, price_after_discount
        FROM subscriptions
        ORDER BY name ASC
    ");
    $subscriptions = $stmt->fetchAll();
} catch (Exception $e) {
    $errors[] = "حدث خطأ أثناء تحميل بيانات الاشتراكات.";
}

$subscriptionCategories = [];
$hasUncategorizedSubscriptions = false;
foreach ($subscriptions as $subscriptionRow) {
    $categoryName = trim((string)($subscriptionRow['subscription_category'] ?? ''));
    if ($categoryName === '') {
        $hasUncategorizedSubscriptions = true;
        continue;
    }

    $subscriptionCategories[$categoryName] = $categoryName;
}
ksort($subscriptionCategories, SORT_NATURAL | SORT_FLAG_CASE);

$trainers = [];
$trainersById = [];
try {
    $trainers = getAllTrainers($pdo);
    foreach ($trainers as $trainerRow) {
        $trainersById[(int)$trainerRow['id']] = $trainerRow;
    }
} catch (Exception $e) {}

// معالجة طلب تجديد الاشتراك
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'renew'
) {
    $memberId    = (int)($_POST['member_id'] ?? 0);
    $newSubId    = (int)($_POST['subscription_id'] ?? 0);
    $trainerId   = normalizeTrainerId($_POST['trainer_id'] ?? 0);
    $confirmOld  = isset($_POST['confirm_old']) ? (int)$_POST['confirm_old'] : 0; // 0 = غير موافق, 1 = موافق
    $paidRenewal = (float)($_POST['paid_renewal'] ?? 0); // المبلغ المدفوع في عملية التجديد

    if ($memberId <= 0 || $newSubId <= 0) {
        $errors[] = "بيانات التجديد غير صحيحة.";
    } elseif ($trainerId !== null && !isset($trainersById[$trainerId])) {
        $errors[] = "من فضلك اختر مدرباً صالحاً أو اختر بدون مدرب.";
    } elseif ($paidRenewal < 0) {
        $errors[] = "قيمة المبلغ المدفوع في التجديد غير صحيحة.";
    } else {
        try {
            // جلب بيانات المشترك للتأكد من حالته والمتبقي القديم
            $stmt = $pdo->prepare("
                SELECT id, subscription_id, remaining_amount, subscription_amount,
                       start_date, end_date, status, paid_amount, payment_type
                FROM members
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $memberId]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                $errors[] = "المشترك المطلوب غير موجود.";
            } elseif (!in_array($member['status'], ['منتهي', 'مستمر'], true)) {
                $errors[] = "حالة هذا المشترك غير مدعومة في صفحة التجديد.";
            } else {
                $oldRemaining    = (float)$member['remaining_amount'];
                $oldSubId        = (int)$member['subscription_id'];
                $oldStartDate    = $member['start_date'];
                $oldEndDate      = $member['end_date'];
                $remainingDays   = getRemainingSubscriptionDays($oldEndDate);
                $memberPaymentType = trim((string)($member['payment_type'] ?? getDefaultMemberPaymentType()));
                if ($memberPaymentType === '' || !in_array($memberPaymentType, getAllowedMemberPaymentTypes(), true)) {
                    $memberPaymentType = getDefaultMemberPaymentType();
                }
                $paymentTypeRenewal = trim((string)($_POST['payment_type'] ?? $memberPaymentType));
                if ($paymentTypeRenewal === '' || !in_array($paymentTypeRenewal, getAllowedMemberPaymentTypes(), true)) {
                    $errors[] = "من فضلك اختر نوع دفع صحيح للتجديد.";
                }

                // إن كان هناك متبقي قديم والمستخدم لم يؤكد، لا نكمل العملية
                if (empty($errors) && $oldRemaining > 0 && $confirmOld !== 1) {
                    $errors[] = "لدى هذا المشترك مبلغ متبقي من الاشتراك السابق ("
                             . number_format($oldRemaining, 2)
                             . ")، من فضلك راجع المبلغ ثم أعد المحاولة واضغط على مربع التأكيد قبل التجديد.";
                } elseif (empty($errors)) {
                    // جلب بيانات الاشتراك الجديد
                    $subRow = null;
                    foreach ($subscriptions as $s) {
                        if ((int)$s['id'] === $newSubId) {
                            $subRow = $s;
                            break;
                        }
                    }

                    if (!$subRow) {
                        $errors[] = "الاشتراك الجديد المحدد غير موجود.";
                    } else {
                        $days     = (int)$subRow['days'];
                        $sessions = (int)$subRow['sessions'];
                        $invites  = (int)$subRow['invites'];
                        $amount   = (float)$subRow['price_after_discount'];
                        $totalDays = $days + $remainingDays;

                        // منطق الاحتساب:
                        // المتبقي النهائي = المتبقي القديم + قيمة الاشتراك الجديد - المبلغ المدفوع عند التجديد
                        $startDate         = date('Y-m-d');
                        $endDate           = date('Y-m-d', strtotime($startDate . ' + ' . $totalDays . ' days'));
                        $sessionsRemaining = $sessions;
                        $status            = 'مستمر';

                        $newTotalRemaining = $oldRemaining + $amount - $paidRenewal;
                        if ($newTotalRemaining < 0) {
                            $newTotalRemaining = 0;
                        }

                        // المبلغ المدفوع لهذه الدورة = مبلغ التجديد فقط
                        $paid = $paidRenewal;

                        $pdo->beginTransaction();

                        // تحديث بيانات المشترك
                        $stmt = $pdo->prepare("
                            UPDATE members
                            SET subscription_id     = :sid,
                                trainer_id          = :trainer_id,
                                days                = :d,
                                sessions            = :s,
                                sessions_remaining  = :sr,
                                invites             = :i,
                                subscription_amount = :amt,
                                paid_amount         = :paid,
                                payment_type        = :payment_type,
                                remaining_amount    = :rem,
                                start_date          = :sd,
                                end_date            = :ed,
                                status              = :st
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':sid'  => $newSubId,
                            ':trainer_id' => $trainerId,
                            ':d'    => $totalDays,
                            ':s'    => $sessions,
                            ':sr'   => $sessionsRemaining,
                            ':i'    => $invites,
                            ':amt'  => $amount,
                            ':paid' => $paid,
                            ':payment_type' => $paymentTypeRenewal,
                            ':rem'  => $newTotalRemaining,
                            ':sd'   => $startDate,
                            ':ed'   => $endDate,
                            ':st'   => $status,
                            ':id'   => $memberId,
                        ]);

                        // حفظ سجل التجديد في renewals_log
                        // ✅ IMPORTANT FIX:
                        // new_subscription_amount يجب أن يمثل "المبلغ المدفوع فعلياً الآن" وليس سعر الاشتراك
                        // ونحفظ نفس القيمة أيضاً في paid_now و paid_amount لضمان توافق الشاشات القديمة والجديدة
                        // إلى أن يتم توحيد الاعتماد على عمود واحد في قاعدة البيانات مستقبلاً.
                        $stmt = $pdo->prepare("
                            INSERT INTO renewals_log
                                (member_id, old_subscription_id, new_subscription_id,
                                 old_remaining, new_subscription_amount, paid_now, paid_amount, payment_type, new_total_remaining,
                                 renewed_by_user_id)
                            VALUES
                                (:mid, :old_sid, :new_sid,
                                 :old_rem, :new_amount, :paid_now, :paid_amount, :payment_type, :new_tot_rem,
                                 :uid)
                        ");
                        $stmt->execute([
                            ':mid'          => $memberId,
                            ':old_sid'      => $oldSubId,
                            ':new_sid'      => $newSubId,
                            ':old_rem'      => $oldRemaining,
                            ':new_amount'   => $paidRenewal,
                            ':paid_now'     => $paidRenewal,
                            ':paid_amount'  => $paidRenewal,
                            ':payment_type' => $paymentTypeRenewal,
                            ':new_tot_rem'  => $newTotalRemaining,
                            ':uid'          => $userId,
                        ]);

                        $renewalLogId = (int)$pdo->lastInsertId();
                        addTrainerCommission(
                            $pdo,
                            $trainerId,
                            $memberId,
                            'renewal',
                            $renewalLogId,
                            $paidRenewal
                        );

                        $pdo->commit();

                        $successMessage = "تم تجديد اشتراك المشترك بنجاح. تم احتساب المبلغ المتبقي القديم.";
                        if ($remainingDays > 0) {
                            $successMessage .= " كما تمت إضافة {$remainingDays} يوم متبقي إلى مدة الاشتراك الجديد.";
                        }
                        $success = $successMessage . " وتم تسجيل مبلغ التجديد الفعلي في سجل التجديدات.";
                    }
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = "حدث خطأ أثناء عملية التجديد.";
        }
    }
}

/*
 * بحث بالباركود أو الهاتف أو الاسم للمشتركين بحالة مستمر أو منتهي
 */
$searchTerm = '';
$endedMembers = [];
try {
    $baseSql = "
        SELECT
            m.id,
            m.name,
            m.phone,
            m.barcode,
            m.age,
            m.gender,
            m.address,
            s.name AS subscription_name,
            m.subscription_id,
            m.trainer_id,
            m.days,
            m.sessions,
            m.sessions_remaining,
            m.invites,
            m.subscription_amount,
            m.paid_amount,
            m.payment_type,
            m.remaining_amount,
            m.start_date,
            m.end_date,
            m.status,
            t.name AS trainer_name,
            t.commission_percentage AS trainer_percentage
        FROM members m
        JOIN subscriptions s ON s.id = m.subscription_id
        LEFT JOIN trainers t ON t.id = m.trainer_id
        WHERE m.status IN ('منتهي', 'مستمر')
    ";

    $params = [];

    if (isset($_GET['search']) && $_GET['search'] !== '') {
        $searchTerm = trim($_GET['search']);
        // البحث بالباركود أو رقم الهاتف أو الاسم
        $baseSql .= " AND (
            m.barcode = :exact_search
            OR m.phone LIKE :phone_like
            OR m.name LIKE :name_like
        )";
        $params[':exact_search'] = $searchTerm;
        $params[':phone_like']   = '%' . $searchTerm . '%';
        $params[':name_like']    = '%' . $searchTerm . '%';
    }

    $baseSql .= " ORDER BY m.end_date DESC, m.id DESC";

    $stmt = $pdo->prepare($baseSql);
    $stmt->execute($params);
    $endedMembers = $stmt->fetchAll();
    foreach ($endedMembers as &$endedMember) {
        $endedMember['remaining_days'] = getRemainingSubscriptionDays($endedMember['end_date']);
    }
    unset($endedMember);
} catch (Exception $e) {
    $errors[] = "حدث خطأ أثناء تحميل قائمة المشتركين المنتهين.";
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تجديد الاشتراكات - <?php echo htmlspecialchars($siteName); ?></title>
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
            font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            font-weight: 900;
            font-size: 18px;
        }
        .page { max-width: 1300px; margin: 30px auto 50px; padding: 0 22px; }
        .header-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
        .title-main{font-size:28px;font-weight:900;}
        .back-button{
            display:inline-flex;align-items:center;justify-content:center;gap:10px;
            padding:12px 24px;border-radius:999px;border:none;cursor:pointer;
            font-size:18px;font-weight:900;
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 18px 40px rgba(79,70,229,0.55);text-decoration:none;
        }
        .back-button:hover{filter:brightness(1.06);}
        .card{
            background:var(--card-bg);border-radius:28px;padding:22px 24px 24px;
            box-shadow:0 24px 60px rgba(15,23,42,0.24),0 0 0 1px rgba(255,255,255,0.7);
        }
        .theme-toggle{display:flex;justify-content:flex-end;margin-bottom:16px;}
        .theme-switch{
            position:relative;width:80px;height:38px;border-radius:999px;
            background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.95);
            cursor:pointer;display:flex;align-items:center;justify-content:space-between;
            padding:0 9px;font-size:18px;color:#6b7280;font-weight:900;
        }
        .theme-switch span{z-index:2;user-select:none;}
        .theme-thumb{
            position:absolute;top:4px;right:4px;width:30px;height:30px;border-radius:999px;
            background:#facc15;box-shadow:0 4px 12px rgba(250,204,21,0.8);
            display:flex;align-items:center;justify-content:center;font-size:18px;
            transition:transform .25s ease,background .25s ease,box-shadow .25s ease;
        }
        body.dark .theme-switch{background:#020617;box-shadow:inset 0 0 0 1px rgba(30,64,175,1);color:#e5e7eb;}
        body.dark .theme-thumb{transform:translateX(-40px);background:#0f172a;box-shadow:0 4px 14px rgba(15,23,42,1);}
        .table-wrapper{margin-top:12px;border-radius:22px;border:1px solid var(--border);overflow:auto;max-height:540px;}
        table{width:100%;border-collapse:collapse;font-size:16px;}
        thead{background:rgba(15,23,42,0.04);}
        body.dark thead{background:rgba(15,23,42,0.95);}
        th,td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;}
        th{font-weight:900;color:var(--text-muted);font-size:16px;}
        td{font-weight:800;font-size:16px;}
        .alert{padding:12px 14px;border-radius:14px;font-size:18px;margin-bottom:14px;font-weight:900;}
        .alert-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.9);color:var(--danger);}
        .alert-success{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.9);color:var(--accent-green);}
        .field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px;}
        .field label{font-size:16px;color:var(--text-muted);font-weight:900;}
        input[type="number"],input[type="text"],select{
            width:100%;padding:10px 13px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:18px;font-weight:800;color:var(--text-main);
        }
        input[type="number"]:focus,input[type="text"]:focus,select:focus{
            outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft);
        }
        .btn-main{
            border-radius:999px;padding:10px 22px;border:none;cursor:pointer;font-size:18px;
            font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:8px;
            background:linear-gradient(90deg,#22c55e,#16a34a);color:#f9fafb;
            box-shadow:0 20px 44px rgba(22,163,74,0.7);text-decoration:none;
        }
        .btn-main:hover{filter:brightness(1.06);}
        .search-bar{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            align-items:center;
            margin-bottom:10px;
        }
        .search-input-wrapper{
            flex:1 1 260px;
        }
        .search-button{
            border-radius:999px;
            padding:10px 20px;
            border:none;
            cursor:pointer;
            font-size:16px;
            font-weight:900;
            background:linear-gradient(90deg,#2563eb,#6366f1);
            color:#f9fafb;
            box-shadow:0 14px 30px rgba(37,99,235,0.6);
        }
        .search-button:hover{
            filter:brightness(1.06);
        }
        .search-hint{
            font-size:14px;
            color:var(--text-muted);
            font-weight:800;
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div class="title-main">تجديد الاشتراكات</div>
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

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?>
                    <div>• <?php echo htmlspecialchars($e); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- شريط البحث بالباركود أو الهاتف أو اسم المشترك -->
        <form method="get" action="" class="search-bar">
            <div class="search-input-wrapper">
                <input
                    type="text"
                    name="search"
                    placeholder="ابحث بالباركود أو رقم الهاتف أو اسم المشترك..."
                    value="<?php echo htmlspecialchars($searchTerm); ?>"
                >
            </div>
            <div>
                <button type="submit" class="search-button">بحث</button>
            </div>
            <div class="search-hint">
                اترك خانة البحث فارغة لعرض جميع الاشتراكات النشطة والمنتهية.
            </div>
        </form>

        <?php if (!$endedMembers): ?>
            <p>لا توجد اشتراكات تنطبق عليها شروط البحث حالياً.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>الهاتف</th>
                        <th>الباركود</th>
                        <th>الاشتراك الحالي</th>
                        <th>المدرب الحالي</th>
                        <th>الحالة</th>
                        <th>تاريخ النهاية</th>
                        <th>الأيام المتبقية</th>
                        <th>المتبقي القديم</th>
                        <th>تجديد الاشتراك</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($endedMembers as $m): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($m['name']); ?></td>
                            <td><?php echo htmlspecialchars($m['phone']); ?></td>
                            <td><?php echo htmlspecialchars($m['barcode']); ?></td>
                            <td><?php echo htmlspecialchars($m['subscription_name']); ?></td>
                            <td><?php echo htmlspecialchars($m['trainer_name'] ?: 'بدون مدرب'); ?></td>
                            <td><?php echo htmlspecialchars($m['status']); ?></td>
                            <td><?php echo htmlspecialchars($m['end_date']); ?></td>
                            <td><?php echo (int)($m['remaining_days'] ?? 0); ?></td>
                            <td><?php echo number_format($m['remaining_amount'], 2); ?></td>
                            <td>
                                <form method="post" action="" class="renew-form" style="display:flex;flex-direction:column;gap:6px;min-width:280px;">
                                    <input type="hidden" name="action" value="renew">
                                    <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">

                                    <div class="field">
                                        <label>تصنيف الاشتراك</label>
                                        <select class="renew-subscription-category">
                                            <option value="">اختر تصنيف الاشتراك...</option>
                                            <?php foreach ($subscriptionCategories as $categoryOption): ?>
                                                <option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES); ?>">
                                                    <?php echo htmlspecialchars($categoryOption); ?>
                                                </option>
                                            <?php endforeach; ?>
                                            <?php if ($hasUncategorizedSubscriptions): ?>
                                                <option value="__uncategorized__">بدون تصنيف</option>
                                            <?php endif; ?>
                                        </select>
                                    </div>

                                    <div class="field">
                                        <label>الاشتراك الجديد</label>
                                        <select name="subscription_id" class="renew-subscription-select" required>
                                            <option value="">اختر تصنيف الاشتراك أولاً...</option>
                                            <?php foreach ($subscriptions as $s): ?>
                                                <option value="<?php echo (int)$s['id']; ?>"
                                                        data-category="<?php echo htmlspecialchars(trim((string)($s['subscription_category'] ?? '')), ENT_QUOTES); ?>">
                                                    <?php echo htmlspecialchars($s['name']); ?> — سعر: <?php echo number_format($s['price_after_discount'], 2); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="field">
                                        <label>المبلغ المدفوع الآن في التجديد</label>
                                        <input type="number" step="0.01" name="paid_renewal" min="0" value="0">
                                    </div>

                                    <?php if ((int)($m['remaining_days'] ?? 0) > 0): ?>
                                        <div class="field" style="font-size:13px;color:var(--accent-green);">
                                            سيتم إضافة <?php echo (int)$m['remaining_days']; ?> يوم متبقي من الاشتراك الحالي إلى مدة الاشتراك الجديد.
                                        </div>
                                    <?php endif; ?>

                                    <div class="field">
                                        <label>نوع الدفع</label>
                                        <select name="payment_type" required>
                                            <?php
                                            $memberPaymentType = trim((string)($m['payment_type'] ?? getDefaultMemberPaymentType()));
                                            if ($memberPaymentType === '' || !in_array($memberPaymentType, getAllowedMemberPaymentTypes(), true)) {
                                                $memberPaymentType = getDefaultMemberPaymentType();
                                            }
                                            ?>
                                            <?php foreach (getAllowedMemberPaymentTypes() as $allowedPaymentType): ?>
                                                <option
                                                    value="<?php echo htmlspecialchars($allowedPaymentType); ?>"
                                                    <?php echo ($memberPaymentType === $allowedPaymentType ? 'selected' : ''); ?>
                                                >
                                                    <?php echo htmlspecialchars($allowedPaymentType); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="field">
                                        <label>المدرب</label>
                                        <select name="trainer_id">
                                            <option value="0">بدون مدرب</option>
                                            <?php foreach ($trainers as $trainer): ?>
                                                <option
                                                    value="<?php echo (int)$trainer['id']; ?>"
                                                    <?php echo ((int)($m['trainer_id'] ?? 0) === (int)$trainer['id'] ? 'selected' : ''); ?>
                                                >
                                                    <?php echo htmlspecialchars($trainer['name']); ?>
                                                    — <?php echo number_format((float)$trainer['commission_percentage'], 2); ?>%
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <?php if ($m['remaining_amount'] > 0): ?>
                                        <div class="field">
                                            <label>
                                                <input type="checkbox" name="confirm_old" value="1">
                                                أؤكد مراجعة المبلغ المتبقي القديم (<?php echo number_format($m['remaining_amount'], 2); ?>)
                                                وأوافق على إضافته مع قيمة الاشتراك الجديد.
                                            </label>
                                        </div>
                                    <?php else: ?>
                                        <input type="hidden" name="confirm_old" value="1">
                                    <?php endif; ?>

                                    <button type="submit" class="btn-main">تجديد الآن</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
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

    function getNormalizedSubscriptionCategory(value) {
        return value === '__uncategorized__' ? '' : String(value || '').trim();
    }

    document.querySelectorAll('.renew-form').forEach((form) => {
        const categorySelect = form.querySelector('.renew-subscription-category');
        const subscriptionSelect = form.querySelector('.renew-subscription-select');
        if (!categorySelect || !subscriptionSelect) return;

        const allSubscriptionOptions = Array.from(subscriptionSelect.querySelectorAll('option[data-category]'))
            .map((option) => option.cloneNode(true));
        const rebuildRenewSubscriptionOptions = () => {
            const selectedCategory = categorySelect.value;
            const normalizedCategory = getNormalizedSubscriptionCategory(selectedCategory);
            subscriptionSelect.innerHTML = '';
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = selectedCategory ? 'اختر اشتراكاً...' : 'اختر تصنيف الاشتراك أولاً...';
            subscriptionSelect.appendChild(placeholder);

            let matchedCount = 0;
            allSubscriptionOptions.forEach((option) => {
                const optionCategory = (option.getAttribute('data-category') || '').trim();
                if (!selectedCategory || optionCategory !== normalizedCategory) {
                    return;
                }

                subscriptionSelect.appendChild(option.cloneNode(true));
                matchedCount += 1;
            });

            if (selectedCategory && !matchedCount) {
                placeholder.textContent = 'لا توجد اشتراكات داخل هذا التصنيف.';
            }
        };

        rebuildRenewSubscriptionOptions();
        categorySelect.addEventListener('change', rebuildRenewSubscriptionOptions);
    });
</script>
</body>
</html>
