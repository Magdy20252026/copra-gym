<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'trainers_helpers.php';
require_once 'user_permissions_helpers.php';

ensureTrainersSchema($pdo);
ensureUserPermissionsSchema($pdo);

function redirectTrainersPage($status, array $extraParams = [])
{
    $params = array_merge(['status' => $status], $extraParams);
    header('Location: trainers.php?' . http_build_query($params));
    exit;
}

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

$isManager    = ($role === 'مدير');
$isSupervisor = ($role === 'مشرف');
$canViewPage  = false;

if ($isManager) {
    $canViewPage = true;
} elseif ($isSupervisor && $userId > 0) {
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $canViewPage = isset($rowPerm['can_view_trainers']) && ((int)$rowPerm['can_view_trainers'] === 1);
        }
    } catch (Exception $e) {
        $canViewPage = false;
    }
}

if (!$canViewPage) {
    header("Location: dashboard.php");
    exit;
}

$canManageTrainers = ($isManager || ($isSupervisor && $canViewPage));
$errors  = [];
$status = $_GET['status'] ?? '';
$csrfToken = $_SESSION['trainers_csrf_token'] ?? '';
if (!is_string($csrfToken) || $csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['trainers_csrf_token'] = $csrfToken;
}

$successMessages = [
    'added'   => 'تم إضافة المدرب بنجاح.',
    'updated' => 'تم تعديل بيانات المدرب بنجاح.',
    'deleted' => 'تم حذف المدرب بنجاح.',
    'withdrawn' => 'تم تسجيل سحب سلفة من رصيد المدرب بنجاح.',
];
$success = $successMessages[$status] ?? '';

$formData = [
    'action' => 'add_trainer',
    'trainer_id' => '',
    'name' => '',
    'commission_percentage' => '',
];
$withdrawalFormData = [
    'trainer_id' => '',
    'withdrawal_date' => date('Y-m-d'),
    'amount' => '',
    'notes' => '',
];
$currentMonthStart = date('Y-m-01 00:00:00');
$nextMonthStart    = date('Y-m-01 00:00:00', strtotime('+1 month'));
$currentYearMonth = date('Y-m');
$currentMonthEndDate = date('Y-m-t');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageTrainers) {
    $action = $_POST['action'] ?? '';
    $submittedCsrfToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $submittedCsrfToken)) {
        $errors[] = "تعذر التحقق من الطلب. من فضلك أعد المحاولة.";
    } elseif ($action === 'delete_trainer') {
        $trainerId = (int)($_POST['trainer_id'] ?? 0);
        if ($trainerId <= 0) {
            $errors[] = "معرّف المدرب غير صحيح.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("UPDATE members SET trainer_id = NULL WHERE trainer_id = :trainer_id");
                $stmt->execute([':trainer_id' => $trainerId]);

                $stmt = $pdo->prepare("DELETE FROM trainer_commissions WHERE trainer_id = :trainer_id");
                $stmt->execute([':trainer_id' => $trainerId]);

                $stmt = $pdo->prepare("DELETE FROM trainers WHERE id = :trainer_id");
                $stmt->execute([':trainer_id' => $trainerId]);

                if ($stmt->rowCount() < 1) {
                    $pdo->rollBack();
                    $errors[] = "المدرب المطلوب غير موجود.";
                } else {
                    $pdo->commit();
                    redirectTrainersPage('deleted');
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "حدث خطأ أثناء حذف المدرب.";
            }
        }
    } elseif ($action === 'withdraw_balance_advance') {
        $withdrawalFormData['trainer_id'] = trim((string)($_POST['trainer_id'] ?? ''));
        $withdrawalFormData['withdrawal_date'] = trim((string)($_POST['withdrawal_date'] ?? date('Y-m-d')));
        $withdrawalFormData['amount'] = trim((string)($_POST['amount'] ?? ''));
        $withdrawalFormData['notes'] = trim((string)($_POST['notes'] ?? ''));

        $trainerId = (int)$withdrawalFormData['trainer_id'];
        $withdrawalAmount = is_numeric($withdrawalFormData['amount']) ? (float)$withdrawalFormData['amount'] : 0.0;
        $withdrawalDate = $withdrawalFormData['withdrawal_date'];
        $isValidWithdrawalDate = ($withdrawalDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $withdrawalDate) === 1);

        if ($trainerId <= 0) {
            $errors[] = "من فضلك اختر المدرب المطلوب خصم السلفة من رصيده.";
        }
        if (!$isValidWithdrawalDate) {
            $errors[] = "من فضلك أدخل تاريخاً صحيحاً لسحب السلفة.";
        }
        if ($isValidWithdrawalDate && substr($withdrawalDate, 0, 7) !== $currentYearMonth) {
            $errors[] = "يمكن تسجيل سحب السلفة من رصيد الشهر الحالي فقط.";
        }
        if ($withdrawalAmount <= 0) {
            $errors[] = "من فضلك أدخل مبلغ سلفة صحيحاً.";
        }
        if (mb_strlen($withdrawalFormData['notes']) > 255) {
            $errors[] = "الملاحظات يجب ألا تتجاوز 255 حرفاً.";
        }

        if (!$errors) {
            try {
                $trainer = getTrainerById($pdo, $trainerId);
                if (!$trainer) {
                    $errors[] = "المدرب المحدد غير موجود.";
                } else {
                    $availableBalance = getTrainerBalanceBetween($pdo, $trainerId, $currentMonthStart, $nextMonthStart);
                    if ($withdrawalAmount > $availableBalance) {
                        $errors[] = "مبلغ السلفة أكبر من الرصيد المتاح للمدرب خلال هذا الشهر.";
                    } else {
                        if (!addTrainerAdvanceWithdrawal($pdo, $trainerId, $withdrawalAmount, $withdrawalDate, $withdrawalFormData['notes'])) {
                            throw new RuntimeException('تعذر حفظ سحب السلفة.');
                        }
                        redirectTrainersPage('withdrawn', ['history_trainer_id' => $trainerId]);
                    }
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء تسجيل سحب السلفة من رصيد المدرب.";
            }
        }
    } elseif ($action === 'add_trainer' || $action === 'edit_trainer') {
        $formData['action'] = $action;
        $formData['trainer_id'] = trim((string)($_POST['trainer_id'] ?? ''));
        $formData['name'] = trim($_POST['name'] ?? '');
        $formData['commission_percentage'] = trim((string)($_POST['commission_percentage'] ?? ''));

        $trainerId = (int)$formData['trainer_id'];
        $name = $formData['name'];
        $commissionPercentageValue = is_numeric($formData['commission_percentage']) ? (float)$formData['commission_percentage'] : null;
        $commissionPercentage = 0.0;

        if ($name === '') {
            $errors[] = "من فضلك أدخل اسم المدرب.";
        }

        if ($formData['commission_percentage'] === '') {
            $errors[] = "من فضلك أدخل نسبة المدرب.";
        } elseif ($commissionPercentageValue === null || $commissionPercentageValue < 0 || $commissionPercentageValue > 100) {
            $errors[] = "نسبة المدرب يجب أن تكون بين 0 و 100.";
        } else {
            $commissionPercentage = $commissionPercentageValue;
        }

        if ($action === 'edit_trainer' && $trainerId <= 0) {
            $errors[] = "معرّف المدرب غير صحيح.";
        }

        if (!$errors) {
            try {
                if ($action === 'edit_trainer') {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) AS c
                        FROM trainers
                        WHERE name = :name
                          AND id <> :trainer_id
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':trainer_id' => $trainerId,
                    ]);
                } else {
                    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM trainers WHERE name = :name");
                    $stmt->execute([':name' => $name]);
                }

                if ((int)$stmt->fetch()['c'] > 0) {
                    $errors[] = "اسم المدرب مسجل بالفعل.";
                } elseif ($action === 'add_trainer') {
                    $stmt = $pdo->prepare("
                        INSERT INTO trainers (name, commission_percentage)
                        VALUES (:name, :commission_percentage)
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':commission_percentage' => $commissionPercentage,
                    ]);
                    redirectTrainersPage('added');
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE trainers
                        SET
                            name = :name,
                            commission_percentage = :commission_percentage
                        WHERE id = :trainer_id
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':commission_percentage' => $commissionPercentage,
                        ':trainer_id' => $trainerId,
                    ]);
                    redirectTrainersPage('updated', ['history_trainer_id' => $trainerId]);
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء حفظ بيانات المدرب.";
            }
        }
    }
}

$editTrainerId = (int)($_GET['edit_trainer_id'] ?? 0);
if ($editTrainerId > 0 && !$errors && $canManageTrainers) {
    try {
        $editTrainer = getTrainerById($pdo, $editTrainerId);
        if ($editTrainer) {
            $formData['action'] = 'edit_trainer';
            $formData['trainer_id'] = (string)$editTrainer['id'];
            $formData['name'] = (string)$editTrainer['name'];
            $formData['commission_percentage'] = (string)$editTrainer['commission_percentage'];
        } else {
            $errors[] = "المدرب المطلوب تعديله غير موجود.";
        }
    } catch (Exception $e) {
        $errors[] = "حدث خطأ أثناء تحميل بيانات المدرب للتعديل.";
    }
}

$trainers = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            t.id,
            t.name,
            t.commission_percentage,
            COALESCE(SUM(
                CASE
                    WHEN tc.created_at >= :month_start AND tc.created_at < :next_month_start
                    THEN tc.commission_amount
                    ELSE 0
                END
            ), 0) AS current_month_balance
        FROM trainers t
        LEFT JOIN trainer_commissions tc ON tc.trainer_id = t.id
        GROUP BY t.id, t.name, t.commission_percentage
        ORDER BY t.name ASC, t.id ASC
    ");
    $stmt->execute([
        ':month_start'      => $currentMonthStart,
        ':next_month_start' => $nextMonthStart,
    ]);
    $trainers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = "حدث خطأ أثناء تحميل قائمة المدربين.";
}

$selectedTrainerId = (int)($_GET['history_trainer_id'] ?? ($_POST['trainer_id'] ?? 0));
$selectedTrainer = null;
$previousMonths = [];
$balanceMovements = [];

if ($selectedTrainerId > 0) {
    try {
        $selectedTrainer = getTrainerById($pdo, $selectedTrainerId);
        if ($selectedTrainer) {
            $stmt = $pdo->prepare("
                SELECT
                    DATE_FORMAT(created_at, '%Y-%m') AS month_label,
                    COUNT(*) AS operations_count,
                    COALESCE(SUM(CASE WHEN commission_amount > 0 THEN commission_amount ELSE 0 END), 0) AS commissions_total,
                    COALESCE(SUM(CASE WHEN commission_amount < 0 THEN ABS(commission_amount) ELSE 0 END), 0) AS withdrawals_total,
                    COALESCE(SUM(commission_amount), 0) AS month_total
                FROM trainer_commissions
                WHERE trainer_id = :trainer_id
                GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                ORDER BY month_label DESC
            ");
            $stmt->execute([
                ':trainer_id'  => $selectedTrainerId,
            ]);
            $previousMonths = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // نعرض أحدث 100 حركة فقط حتى تظل الصفحة سريعة وواضحة دون تحميل سجل كامل قد يكون كبيراً.
            $balanceMovements = getTrainerBalanceMovementsDetailed($pdo, $selectedTrainerId, 100);
        }
    } catch (Exception $e) {
        $errors[] = "حدث خطأ أثناء تحميل تفاصيل أرصدة الشهور.";
    }
}

$totalCurrentMonthBalance = 0.0;
$trainersWithBalanceCount = 0;
foreach ($trainers as $trainerRow) {
    $trainerBalance = isset($trainerRow['current_month_balance']) ? (float)$trainerRow['current_month_balance'] : 0.0;
    $totalCurrentMonthBalance += $trainerBalance;
    if ($trainerBalance > 0) {
        $trainersWithBalanceCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>المدربين - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        :root {
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --primary: #2563eb;
            --accent: #22c55e;
            --border: #e5e7eb;
            --danger: #ef4444;
            --input-bg: #f9fafb;
            --table-min-width: 940px;
        }
        body.dark {
            --bg: #020617;
            --card-bg: #020617;
            --text-main: #ffffff;
            --text-muted: #e5e7eb;
            --primary: #38bdf8;
            --accent: #22c55e;
            --border: #1f2937;
            --danger: #fb7185;
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
        .header-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; gap:12px; flex-wrap:wrap; }
        .title-main { font-size: 30px; font-weight: 900; }
        .subtitle { color: var(--text-muted); font-size: 15px; margin-top: 4px; }
        .back-button, .btn-main, .btn-secondary {
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            border:none;cursor:pointer;text-decoration:none;
            border-radius:999px;padding:12px 22px;font-size:16px;font-weight:900;
        }
        .back-button { background:linear-gradient(90deg,#6366f1,#22c55e); color:#f9fafb; box-shadow:0 18px 40px rgba(79,70,229,0.55); }
        .btn-main { background:linear-gradient(90deg,#22c55e,#16a34a); color:#f9fafb; box-shadow:0 18px 40px rgba(22,163,74,0.55); }
        .btn-secondary { background:linear-gradient(90deg,#2563eb,#38bdf8); color:#f9fafb; box-shadow:0 18px 40px rgba(37,99,235,0.45); }
        .card {
            background: var(--card-bg);
            border-radius: 28px;
            padding: 22px 24px 24px;
            box-shadow: 0 24px 60px rgba(15,23,42,0.24),0 0 0 1px rgba(255,255,255,0.7);
            margin-bottom: 18px;
        }
        .theme-toggle{display:flex;justify-content:flex-end;margin-bottom:16px;}
        .theme-switch{
            position:relative;width:80px;height:38px;border-radius:999px;background:#e5e7eb;
            box-shadow:inset 0 0 0 1px rgba(148,163,184,0.95);cursor:pointer;display:flex;align-items:center;
            justify-content:space-between;padding:0 9px;font-size:18px;color:#6b7280;font-weight:900;
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
        .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
        .field { display:flex; flex-direction:column; gap:6px; margin-bottom:10px; }
        .field label { font-size:15px; color:var(--text-muted); font-weight:900; }
        input[type="text"], input[type="number"], input[type="date"], select {
            width:100%;padding:11px 13px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:16px;font-weight:800;color:var(--text-main);
        }
        .summary-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap:14px; margin:0 0 18px; }
        .summary-card { border-radius:18px;border:1px solid var(--border);padding:14px 16px;background:rgba(15,23,42,0.02); }
        body.dark .summary-card { background:rgba(15,23,42,0.35); }
        .summary-label { font-size:14px; color:var(--text-muted); margin-bottom:6px; }
        .summary-value { font-size:26px; font-weight:900; }
        .form-actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        .table-wrapper {
            position:relative;
            margin-top:12px;
            padding:1px;
            border-radius:24px;
            border:1px solid rgba(148, 163, 184, 0.22);
            background:
                linear-gradient(var(--card-bg),var(--card-bg)) padding-box,
                linear-gradient(135deg, rgba(37,99,235,0.24), rgba(56,189,248,0.10), rgba(34,197,94,0.18)) border-box;
            box-shadow:0 18px 45px rgba(15,23,42,0.10);
        }
        .table-wrapper::after {
            content:"";
            position:absolute;
            inset:6px;
            border-radius:18px;
            pointer-events:none;
            box-shadow:inset 0 0 0 1px rgba(148, 163, 184, 0.18);
        }
        body.dark .table-wrapper::after {
            box-shadow:inset 0 0 0 1px rgba(148, 163, 184, 0.12);
        }
        .table-scroll {
            position:relative;
            max-width:100%;
            max-height:60vh;
            overflow:auto;
            -webkit-overflow-scrolling:touch;
            scrollbar-gutter:stable both-edges;
            border-radius:18px;
            background:var(--card-bg);
        }
        .table-scroll::-webkit-scrollbar { width:12px; height:12px; }
        .table-scroll::-webkit-scrollbar-track {
            background: rgba(148, 163, 184, 0.18);
            border-radius: 999px;
            margin: 10px;
        }
        .table-scroll::-webkit-scrollbar-thumb {
            background: rgba(37,99,235,0.55);
            border-radius: 999px;
            border: 2px solid rgba(255,255,255,0.55);
        }
        body.dark .table-scroll::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.08);
        }
        body.dark .table-scroll::-webkit-scrollbar-thumb {
            background: rgba(56, 189, 248, 0.55);
            border: 2px solid rgba(2, 6, 23, 0.6);
        }
        .table-scroll::-webkit-scrollbar-corner { background:transparent; }
        .table-scroll {
            scrollbar-width: thin;
            scrollbar-color: rgba(37,99,235,0.60) rgba(148,163,184,0.18);
        }
        body.dark .table-scroll {
            scrollbar-color: rgba(56,189,248,0.60) rgba(255,255,255,0.08);
        }
        table { width:100%;border-collapse:collapse;font-size:16px;min-width:var(--table-min-width); }
        thead { background:rgba(15,23,42,0.04); }
        body.dark thead { background:rgba(15,23,42,0.95); }
        th, td { padding:10px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap; }
        th { font-weight:900;color:var(--text-muted);font-size:15px; }
        td { font-weight:800;font-size:15px; }
        thead th {
            position:sticky;
            top:0;
            z-index:3;
            background:rgba(15,23,42,0.04);
            backdrop-filter:blur(8px);
        }
        body.dark thead th {
            background:rgba(15,23,42,0.92);
        }
        tbody tr:hover { background: rgba(37,99,235,0.06); }
        body.dark tbody tr:hover { background: rgba(56,189,248,0.10); }
        .actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .btn-edit-row, .btn-delete-row {
            display:inline-flex;align-items:center;justify-content:center;gap:6px;
            border:none;cursor:pointer;border-radius:999px;padding:10px 14px;
            font-size:14px;font-weight:900;color:#f9fafb;text-decoration:none;
        }
        .btn-edit-row { background:linear-gradient(90deg,#2563eb,#38bdf8); box-shadow:0 18px 40px rgba(37,99,235,0.35); }
        .btn-delete-row { background:linear-gradient(90deg,#ef4444,#fb7185); box-shadow:0 18px 40px rgba(239,68,68,0.30); }
        .alert { padding:12px 14px;border-radius:14px;font-size:16px;margin-bottom:14px;font-weight:900; }
        .alert-error { background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.9);color:var(--danger); }
        .alert-success { background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.9);color:var(--accent); }
        .empty-note { color: var(--text-muted); font-size: 15px; }
        .amount { color: var(--accent); }
        .amount-negative { color: var(--danger); }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">المدربين</div>
            <div class="subtitle">مستخدم مسجل: <?php echo htmlspecialchars($username); ?> — الصلاحية: <?php echo htmlspecialchars($role); ?></div>
        </div>
        <div class="actions">
            <?php if ($trainers): ?>
                <a href="export_trainer_balance_excel.php" class="btn-secondary">
                    <span>📥</span>
                    <span>استخراج Excel لكل المدربين</span>
                </a>
            <?php endif; ?>
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
                <div class="theme-thumb">☀️</div>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $error): ?>
                    <div>• <?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">إجمالي رصيد المدربين هذا الشهر</div>
                <div class="summary-value"><?php echo number_format($totalCurrentMonthBalance, 2); ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">عدد المدربين المسجلين</div>
                <div class="summary-value"><?php echo count($trainers); ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">مدربون لديهم رصيد متاح حالياً</div>
                <div class="summary-value"><?php echo $trainersWithBalanceCount; ?></div>
            </div>
        </div>

        <?php if ($canManageTrainers): ?>
            <form method="post" action="">
                <input type="hidden" name="action" value="<?php echo htmlspecialchars($formData['action']); ?>">
                <input type="hidden" name="trainer_id" value="<?php echo htmlspecialchars($formData['trainer_id']); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="grid">
                    <div class="field">
                        <label for="trainer_name">اسم المدرب</label>
                        <input type="text" id="trainer_name" name="name" value="<?php echo htmlspecialchars($formData['name']); ?>" required>
                    </div>
                    <div class="field">
                        <label for="trainer_percentage">نسبة المدرب (%)</label>
                        <input type="number" step="0.01" min="0" max="100" id="trainer_percentage" name="commission_percentage" value="<?php echo htmlspecialchars($formData['commission_percentage']); ?>" required>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-main">
                        <span><?php echo $formData['action'] === 'edit_trainer' ? '✏️' : '💾'; ?></span>
                        <span><?php echo $formData['action'] === 'edit_trainer' ? 'تحديث بيانات المدرب' : 'حفظ المدرب'; ?></span>
                    </button>
                    <?php if ($formData['action'] === 'edit_trainer'): ?>
                        <a href="trainers.php" class="btn-secondary">
                            <span>↩️</span>
                            <span>إلغاء التعديل</span>
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="title-main" style="font-size:24px; margin-top:22px;">سحب سلفة من رصيد المدرب</div>
            <div class="subtitle" style="margin-bottom:10px;">يتم خصم السلفة مباشرة من رصيد الشهر الحالي، وتظهر ضمن تفاصيل الأشهر السابقة باسم سحب سلفة من الرصيد.</div>
            <form method="post" action="">
                <input type="hidden" name="action" value="withdraw_balance_advance">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="grid">
                    <div class="field">
                        <label for="withdraw_trainer_id">المدرب</label>
                        <select id="withdraw_trainer_id" name="trainer_id" required>
                            <option value="">اختر المدرب</option>
                            <?php foreach ($trainers as $trainer): ?>
                                <option value="<?php echo (int)$trainer['id']; ?>" <?php echo ((string)$trainer['id'] === $withdrawalFormData['trainer_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($trainer['name']); ?> — الرصيد الحالي <?php echo number_format((float)$trainer['current_month_balance'], 2); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="withdrawal_date">تاريخ سحب السلفة</label>
                        <input type="date" id="withdrawal_date" name="withdrawal_date" min="<?php echo htmlspecialchars(substr($currentMonthStart, 0, 10)); ?>" max="<?php echo htmlspecialchars($currentMonthEndDate); ?>" value="<?php echo htmlspecialchars($withdrawalFormData['withdrawal_date']); ?>" required>
                    </div>
                    <div class="field">
                        <label for="withdraw_amount">مبلغ السلفة</label>
                        <input type="number" step="0.01" min="0.01" id="withdraw_amount" name="amount" value="<?php echo htmlspecialchars($withdrawalFormData['amount']); ?>" required>
                    </div>
                    <div class="field">
                        <label for="withdraw_notes">بيان السلفة</label>
                        <input type="text" id="withdraw_notes" name="notes" maxlength="255" value="<?php echo htmlspecialchars($withdrawalFormData['notes']); ?>" placeholder="اختياري">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn-secondary" <?php echo $trainers ? '' : 'disabled'; ?>>
                        <span>💸</span>
                        <span>حفظ سحب السلفة من الرصيد</span>
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="title-main" style="font-size:24px;">جدول المدربين المسجلين</div>
        <div class="subtitle">رصيد الشهر الحالي يتم احتسابه من الاشتراكات الجديدة وسداد المتبقي والتجديدات خلال الشهر الحالي.</div>

        <div class="table-wrapper">
            <div class="table-scroll">
            <table>
                <thead>
                <tr>
                    <th>اسم المدرب</th>
                    <th>نسبة المدرب</th>
                    <th>رصيد الشهر الحالي</th>
                    <th>التفاصيل</th>
                    <?php if ($canManageTrainers): ?>
                        <th>الإجراءات</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (!$trainers): ?>
                    <tr>
                        <td colspan="<?php echo $canManageTrainers ? 5 : 4; ?>" class="empty-note">لا يوجد مدربين مسجلين حالياً.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($trainers as $trainer): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($trainer['name']); ?></td>
                            <td><?php echo number_format((float)$trainer['commission_percentage'], 2); ?>%</td>
                            <td class="amount"><?php echo number_format((float)$trainer['current_month_balance'], 2); ?></td>
                            <td>
                                <a class="btn-secondary" href="trainers.php?history_trainer_id=<?php echo (int)$trainer['id']; ?>#historyCard">
                                    <span>📅</span>
                                    <span>تفاصيل رصيد الشهور</span>
                                </a>
                            </td>
                            <?php if ($canManageTrainers): ?>
                                <td>
                                    <div class="actions">
                                        <a class="btn-edit-row" aria-label="تعديل بيانات المدرب <?php echo htmlspecialchars($trainer['name']); ?>" href="trainers.php?edit_trainer_id=<?php echo (int)$trainer['id']; ?>#trainer_name">
                                            <span>✏️</span>
                                            <span>تعديل</span>
                                        </a>
                                        <form method="post" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذا المدرب؟ سيتم فك ارتباطه من الأعضاء وحذف أرصدة العمولات المسجلة له.');">
                                            <input type="hidden" name="action" value="delete_trainer">
                                            <input type="hidden" name="trainer_id" value="<?php echo (int)$trainer['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <button type="submit" class="btn-delete-row" aria-label="حذف المدرب <?php echo htmlspecialchars($trainer['name']); ?>">
                                                <span>🗑️</span>
                                                <span>حذف</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div class="card" id="historyCard">
        <div class="title-main" style="font-size:24px;">تفاصيل رصيد الشهور</div>
        <?php if (!$selectedTrainer): ?>
            <p class="empty-note">اختر المدرب من الجدول أعلاه لعرض تفاصيل أرصدة الشهور الحالية والسابقة.</p>
        <?php else: ?>
            <div class="subtitle" style="margin-bottom:10px;">
                المدرب: <?php echo htmlspecialchars($selectedTrainer['name']); ?> — النسبة: <?php echo number_format((float)$selectedTrainer['commission_percentage'], 2); ?>%
            </div>
            <div class="table-wrapper">
                <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>الشهر</th>
                        <th>عدد العمليات</th>
                        <th>إجمالي العمولات</th>
                        <th>السلف المسحوبة من الرصيد</th>
                        <th>صافي الرصيد بعد الخصم</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$previousMonths): ?>
                        <tr>
                            <td colspan="5" class="empty-note">لا توجد أرصدة شهرية مسجلة لهذا المدرب حتى الآن.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($previousMonths as $month): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($month['month_label']); ?></td>
                                <td><?php echo (int)$month['operations_count']; ?></td>
                                <td class="amount"><?php echo number_format((float)$month['commissions_total'], 2); ?></td>
                                <td class="amount-negative"><?php echo number_format((float)$month['withdrawals_total'], 2); ?></td>
                                <td class="amount"><?php echo number_format((float)$month['month_total'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="title-main" style="font-size:22px; margin-top:18px;">تفاصيل الحركات المسجلة على الرصيد</div>
            <div class="subtitle" style="margin-bottom:10px;">يظهر هنا كل ما تم إضافته أو خصمه من رصيد المدرب، بما في ذلك سحب السلفة من الرصيد.</div>
            <div class="form-actions" style="margin-bottom:10px;">
                <a href="export_trainer_balance_excel.php?trainer_id=<?php echo (int)$selectedTrainer['id']; ?>" class="btn-secondary">
                    <span>📥</span>
                    <span>استخراج Excel لهذا المدرب</span>
                </a>
            </div>
            <div class="table-wrapper">
                <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>التاريخ</th>
                        <th>نوع الحركة</th>
                        <th>البيان</th>
                        <th>اسم المشترك</th>
                        <th>كود المشترك</th>
                        <th>نوع الاشتراك</th>
                        <th>المبلغ المدفوع</th>
                        <th>تاريخ بداية الاشتراك</th>
                        <th>تاريخ نهاية الاشتراك</th>
                        <th>القيمة</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$balanceMovements): ?>
                        <tr>
                            <td colspan="10" class="empty-note">لا توجد حركات مسجلة على رصيد هذا المدرب.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($balanceMovements as $movement): ?>
                            <?php
                                $movementDisplay = formatTrainerMovementForDisplay($movement);
                                $movementAmount = (float)$movementDisplay['commission_amount'];
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($movementDisplay['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($movementDisplay['movement_type_label']); ?></td>
                                <td><?php echo htmlspecialchars($movementDisplay['statement']); ?></td>
                                <td><?php echo htmlspecialchars($movementDisplay['member_name']); ?></td>
                                <td><?php echo htmlspecialchars($movementDisplay['member_code']); ?></td>
                                <td><?php echo htmlspecialchars($movementDisplay['subscription_name']); ?></td>
                                <td><?php echo htmlspecialchars($movementDisplay['paid_amount_display']); ?></td>
                                <td><?php echo htmlspecialchars($movementDisplay['subscription_start_date']); ?></td>
                                <td><?php echo htmlspecialchars($movementDisplay['subscription_end_date']); ?></td>
                                <td class="<?php echo $movementAmount < 0 ? 'amount-negative' : 'amount'; ?>">
                                    <?php echo htmlspecialchars($movementDisplay['commission_amount_display']); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const body = document.body;
    const switchEl = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';

    function applyTheme(mode) {
        if (mode === 'dark') body.classList.add('dark'); else body.classList.remove('dark');
        localStorage.setItem('gymDashboardTheme', mode);
    }

    applyTheme(savedTheme);
    if (switchEl) {
        switchEl.addEventListener('click', () => {
            applyTheme(body.classList.contains('dark') ? 'light' : 'dark');
        });
    }
</script>
</body>
</html>
