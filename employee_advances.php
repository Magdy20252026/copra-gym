<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'employees_helpers.php';
require_once 'employee_advances_helpers.php';
require_once 'user_permissions_helpers.php';

ensureEmployeesSchema($pdo);
ensureEmployeeAdvancesSchema($pdo);
ensureUserPermissionsSchema($pdo);

function redirectEmployeeAdvancesPage($status, $month)
{
    $query = http_build_query([
        'status' => $status,
        'month' => normalizeEmployeeAdvanceMonth($month),
    ]);
    header("Location: employee_advances.php?" . $query);
    exit;
}

$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {
}

$role   = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);

$defaultPermissions = getDefaultUserPermissions();
$isManager = ($role === 'مدير');
$isSupervisor = ($role === 'مشرف');
$canViewPage = false;

if ($isManager) {
    $canViewPage = true;
} elseif ($isSupervisor && $userId > 0) {
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $canViewPage = isset($rowPerm['can_view_employee_advances'])
                ? ((int)$rowPerm['can_view_employee_advances'] === 1)
                : ((int)$defaultPermissions['can_view_employee_advances'] === 1);
        } else {
            $canViewPage = (int)$defaultPermissions['can_view_employee_advances'] === 1;
        }
    } catch (Exception $e) {
        $canViewPage = false;
    }
}

if (!$canViewPage) {
    header("Location: dashboard.php");
    exit;
}

$errors = [];
$status = $_GET['status'] ?? '';
$selectedMonth = normalizeEmployeeAdvanceMonth($_GET['month'] ?? ($_POST['month'] ?? date('Y-m')));
$monthRange = getEmployeeAdvanceMonthDateRange($selectedMonth);

$successMessages = [
    'added' => 'تم تسجيل سلفة الموظف بنجاح.',
    'updated' => 'تم تعديل سلفة الموظف بنجاح.',
    'deleted' => 'تم حذف سلفة الموظف بنجاح.',
];
$success = $successMessages[$status] ?? '';

$formData = [
    'action' => 'add',
    'advance_id' => '',
    'employee_id' => '',
    'employee_search' => '',
    'advance_date' => date('Y-m-d'),
    'amount' => '',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['action'] = trim((string)($_POST['action'] ?? 'add'));
    $formData['advance_id'] = trim((string)($_POST['advance_id'] ?? ''));
    $formData['employee_id'] = trim((string)($_POST['employee_id'] ?? ''));
    $formData['employee_search'] = trim((string)($_POST['employee_search'] ?? ''));
    $formData['advance_date'] = trim((string)($_POST['advance_date'] ?? date('Y-m-d')));
    $formData['amount'] = trim((string)($_POST['amount'] ?? ''));
    $formData['notes'] = trim((string)($_POST['notes'] ?? ''));
    $selectedMonth = normalizeEmployeeAdvanceMonth($_POST['month'] ?? $selectedMonth);
    $monthRange = getEmployeeAdvanceMonthDateRange($selectedMonth);

    $action = $formData['action'] === 'edit' ? 'edit' : ($formData['action'] === 'delete' ? 'delete' : 'add');
    $advanceId = (int)$formData['advance_id'];

    if (($action === 'edit' || $action === 'delete') && !$isManager) {
        $errors[] = 'لا تملك صلاحية تعديل أو حذف سلف الموظفين.';
    }

    if ($action === 'delete') {
        if ($advanceId <= 0) {
            $errors[] = 'معرّف السلفة غير صحيح.';
        }

        if (!$errors) {
            try {
                $advanceRow = findEmployeeAdvanceById($pdo, $advanceId);
                if (!$advanceRow) {
                    $errors[] = 'سلفة الموظف المحددة غير موجودة.';
                } else {
                    deleteEmployeeAdvance($pdo, $advanceId);
                    redirectEmployeeAdvancesPage('deleted', substr((string)$advanceRow['advance_date'], 0, 7));
                }
            } catch (Exception $e) {
                $errors[] = 'حدث خطأ أثناء حذف سلفة الموظف.';
            }
        }
    } else {
        $employeeId = (int)$formData['employee_id'];
        $amount = (float)$formData['amount'];

        if ($action === 'edit' && $advanceId <= 0) {
            $errors[] = 'معرّف السلفة غير صحيح.';
        }
        if ($employeeId <= 0) {
            $errors[] = 'من فضلك اختر الموظف من القائمة.';
        }
        if ($formData['advance_date'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $formData['advance_date'])) {
            $errors[] = 'من فضلك أدخل تاريخاً صحيحاً للسلفة.';
        }
        if ($amount <= 0) {
            $errors[] = 'من فضلك أدخل مبلغ سلفة صحيحاً.';
        }
        if (mb_strlen($formData['notes']) > 255) {
            $errors[] = 'الملاحظات يجب ألا تتجاوز 255 حرفاً.';
        }

        $selectedEmployee = null;
        if (!$errors) {
            try {
                if ($action === 'edit' && !findEmployeeAdvanceById($pdo, $advanceId)) {
                    $errors[] = 'سلفة الموظف المحددة غير موجودة.';
                } else {
                    $selectedEmployee = findEmployeeAdvanceEmployeeById($pdo, $employeeId);
                    if (!$selectedEmployee) {
                        $errors[] = 'الموظف المحدد غير موجود.';
                    }
                }
            } catch (Exception $e) {
                $errors[] = 'تعذر التحقق من بيانات الموظف.';
            }
        }

        if (!$errors) {
            try {
                if ($action === 'edit') {
                    updateEmployeeAdvance($pdo, $advanceId, $employeeId, $formData['advance_date'], $amount, $formData['notes']);
                    redirectEmployeeAdvancesPage('updated', substr($formData['advance_date'], 0, 7));
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO employee_advances (
                            employee_id,
                            advance_date,
                            amount,
                            notes,
                            created_by
                        ) VALUES (
                            :employee_id,
                            :advance_date,
                            :amount,
                            :notes,
                            :created_by
                        )
                    ");
                    $stmt->execute([
                        ':employee_id' => $employeeId,
                        ':advance_date' => $formData['advance_date'],
                        ':amount' => $amount,
                        ':notes' => $formData['notes'] !== '' ? $formData['notes'] : null,
                        ':created_by' => $userId,
                    ]);
                    redirectEmployeeAdvancesPage('added', substr($formData['advance_date'], 0, 7));
                }
            } catch (Exception $e) {
                $errors[] = $action === 'edit'
                    ? 'حدث خطأ أثناء تعديل سلفة الموظف.'
                    : 'حدث خطأ أثناء تسجيل سلفة الموظف.';
            }
        }
    }
}

$employees = [];
$rows = [];
$summary = [
    'advances_count' => 0,
    'total_amount' => 0.0,
];
$selectedEmployeeData = null;

try {
    $employees = getEmployeeAdvanceSelectableEmployees($pdo);
    $rows = getEmployeeAdvanceRows($pdo, $selectedMonth);
    $summary = getEmployeeAdvanceMonthSummary($pdo, $selectedMonth);
    if ((int)$formData['employee_id'] > 0) {
        $selectedEmployeeData = findEmployeeAdvanceEmployeeById($pdo, (int)$formData['employee_id']);
    }
} catch (Exception $e) {
    $errors[] = 'حدث خطأ أثناء تحميل بيانات سلف الموظفين.';
}

if ($selectedEmployeeData && $formData['employee_search'] === '') {
    $formData['employee_search'] = $selectedEmployeeData['barcode'] . ' — ' . $selectedEmployeeData['name'];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سلف الموظفين - <?php echo htmlspecialchars($siteName); ?></title>
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
            --warning: #f59e0b;
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
            --warning: #fbbf24;
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
        .page { max-width: 1360px; margin: 30px auto 40px; padding: 0 20px; }
        .header-bar {
            display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;gap:12px;flex-wrap:wrap;
        }
        .title-main { font-size: 28px; font-weight: 900; }
        .title-sub { margin-top: 6px; font-size: 16px; color: var(--text-muted); font-weight: 800; }
        .header-actions { display:flex;align-items:center;gap:10px;flex-wrap:wrap; }
        .back-button,.btn-primary,.btn-secondary{
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            padding:11px 20px;border-radius:999px;border:none;cursor:pointer;
            font-size:15px;font-weight:900;text-decoration:none;
        }
        .back-button{
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 16px 38px rgba(79,70,229,0.55);
        }
        .btn-primary{
            background:linear-gradient(90deg,#22c55e,#16a34a);color:#f9fafb;
            box-shadow:0 14px 32px rgba(22,163,74,0.7);
        }
        .btn-secondary{
            background:linear-gradient(90deg,#2563eb,#38bdf8);color:#f9fafb;
            box-shadow:0 14px 32px rgba(37,99,235,0.5);
        }
        .back-button:hover,.btn-primary:hover,.btn-secondary:hover{filter:brightness(1.05);}
        .grid {
            display:grid;
            grid-template-columns:minmax(340px, 430px) minmax(0, 1fr);
            gap:18px;
            align-items:start;
        }
        .card{
            background:var(--card-bg);border-radius:24px;padding:20px 22px 22px;
            box-shadow:0 22px 60px rgba(15,23,42,0.22),0 0 0 1px rgba(255,255,255,0.6);
        }
        .theme-toggle{display:flex;justify-content:flex-end;margin-bottom:14px;}
        .theme-switch{
            position:relative;width:72px;height:34px;border-radius:999px;
            background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);
            cursor:pointer;display:flex;align-items:center;justify-content:space-between;
            padding:0 8px;font-size:16px;color:#6b7280;font-weight:900;
        }
        .theme-switch span{z-index:2;user-select:none;}
        .theme-thumb{
            position:absolute;top:3px;right:3px;width:26px;height:26px;border-radius:999px;
            background:#facc15;box-shadow:0 4px 10px rgba(250,204,21,0.7);
            display:flex;align-items:center;justify-content:center;font-size:16px;
            transition:transform .25s ease,background .25s ease,box-shadow .25s ease;
        }
        body.dark .theme-switch{background:#020617;box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);color:#e5e7eb;}
        body.dark .theme-thumb{transform:translateX(-36px);background:#0f172a;box-shadow:0 4px 12px rgba(15,23,42,0.9);}
        .alert{padding:11px 13px;border-radius:12px;font-size:16px;margin-bottom:12px;font-weight:900;}
        .alert-error{background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.9);color:var(--danger);}
        .alert-success{background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.9);color:var(--accent-green);}
        .alert-info{background:var(--primary-soft);border:1px solid rgba(37,99,235,0.35);color:var(--primary);}
        .form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
        .field{display:flex;flex-direction:column;gap:6px;}
        .field-wide{grid-column:1 / -1;}
        .field label{font-size:15px;color:var(--text-muted);font-weight:900;}
        input[type="date"],input[type="month"],input[type="number"],input[type="text"],textarea{
            width:100%;padding:11px 12px;border-radius:18px;border:1px solid var(--border);
            background:var(--input-bg);font-size:16px;font-weight:800;color:var(--text-main);
        }
        textarea{min-height:108px;resize:vertical;border-radius:20px;}
        input:focus,textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft);}
        .picker-shell{position:relative;}
        .employee-search{
            border-radius:20px;
            padding-left:46px;
        }
        .picker-icon{
            position:absolute;left:14px;top:50%;transform:translateY(-50%);
            font-size:18px;color:var(--text-muted);pointer-events:none;
        }
        .employee-dropdown{
            position:absolute;top:calc(100% + 8px);right:0;left:0;z-index:30;
            background:var(--card-bg);border:1px solid var(--border);border-radius:20px;
            box-shadow:0 20px 45px rgba(15,23,42,0.16);padding:8px;display:none;
            max-height:280px;overflow:auto;
        }
        .employee-dropdown.show{display:block;}
        .employee-option{
            width:100%;border:none;background:transparent;color:var(--text-main);
            display:flex;flex-direction:column;align-items:flex-start;gap:4px;
            text-align:right;padding:10px 12px;border-radius:16px;cursor:pointer;
        }
        .employee-option:hover,.employee-option.active{background:var(--primary-soft);}
        .employee-option small{color:var(--text-muted);font-size:13px;font-weight:800;}
        .picker-empty{
            padding:12px;border-radius:16px;background:rgba(239,68,68,0.06);
            color:var(--text-muted);font-size:14px;text-align:center;
        }
        .employee-meta{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;}
        .meta-chip{
            display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;
            background:var(--input-bg);border:1px solid var(--border);font-size:14px;
        }
        .meta-chip strong{color:var(--primary);}
        .submit-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;}
        .stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;}
        .stat-box{
            border-radius:20px;padding:16px;background:var(--input-bg);border:1px solid var(--border);
        }
        .stat-label{font-size:14px;color:var(--text-muted);margin-bottom:6px;}
        .stat-value{font-size:26px;color:var(--text-main);}
        .toolbar{
            display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:16px;
        }
        .toolbar form{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;}
        .table-wrapper{border-radius:20px;border:1px solid var(--border);overflow:auto;}
        table{width:100%;border-collapse:collapse;font-size:15px;}
        thead{background:rgba(15,23,42,0.04);}
        body.dark thead{background:rgba(15,23,42,0.9);}
        th,td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;}
        th{font-weight:900;color:var(--text-muted);}
        td{font-weight:800;}
        .amount-cell{color:var(--accent-green);}
        .actions-cell{white-space:nowrap;}
        .table-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
        .btn-row{
            display:inline-flex;align-items:center;justify-content:center;gap:6px;
            padding:8px 12px;border:none;border-radius:12px;cursor:pointer;
            font-size:13px;font-weight:900;color:#fff;background:linear-gradient(90deg,#2563eb,#38bdf8);
        }
        .btn-row-danger{
            background:linear-gradient(90deg,#ef4444,#f97316);
        }
        .muted{color:var(--text-muted);font-size:14px;}
        .empty-state{
            padding:26px 18px;text-align:center;color:var(--text-muted);font-size:15px;font-weight:800;
        }
        @media (max-width: 980px) {
            .grid{grid-template-columns:1fr;}
        }
        @media (max-width: 640px) {
            .form-grid{grid-template-columns:1fr;}
            .header-actions,.toolbar form{width:100%;}
            .header-actions a,.header-actions button,.toolbar .btn-secondary{width:100%;}
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">سلف الموظفين</div>
            <div class="title-sub">تسجيل سلف الموظفين ومتابعة كشف كل شهر مع تصدير Excel عربي حقيقي.</div>
        </div>
        <div class="header-actions">
            <a href="dashboard.php" class="back-button">
                <span>📊</span>
                <span>العودة إلى لوحة التحكم</span>
            </a>
        </div>
    </div>

    <div class="grid">
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
                    <?php foreach ($errors as $error): ?>
                        <div>• <?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if (!$employees): ?>
                <div class="alert alert-info">لا يوجد موظفون مسجلون حالياً. أضف موظفاً أولاً من صفحة الموظفين ثم سجّل السلف.</div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="action" id="formAction" value="<?php echo htmlspecialchars($formData['action']); ?>">
                <input type="hidden" name="advance_id" id="advanceId" value="<?php echo htmlspecialchars($formData['advance_id']); ?>">
                <input type="hidden" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                <div class="form-grid">
                    <div class="field field-wide">
                        <label for="employeeSearch">اختيار الموظف من القائمة</label>
                        <div class="picker-shell" id="employeePicker">
                            <input type="hidden" name="employee_id" id="employeeId" value="<?php echo htmlspecialchars($formData['employee_id']); ?>">
                            <input
                                type="text"
                                name="employee_search"
                                id="employeeSearch"
                                class="employee-search"
                                value="<?php echo htmlspecialchars($formData['employee_search']); ?>"
                                placeholder="ابحث بالباركود أو اسم الموظف"
                                autocomplete="off"
                                <?php echo $employees ? '' : 'disabled'; ?>
                            >
                            <span class="picker-icon">🔎</span>
                            <div class="employee-dropdown" id="employeeDropdown"></div>
                        </div>
                        <div class="employee-meta">
                            <div class="meta-chip">الباركود: <strong id="selectedBarcode"><?php echo htmlspecialchars($selectedEmployeeData['barcode'] ?? '—'); ?></strong></div>
                            <div class="meta-chip">الاسم: <strong id="selectedName"><?php echo htmlspecialchars($selectedEmployeeData['name'] ?? '—'); ?></strong></div>
                            <div class="meta-chip">الوظيفة: <strong id="selectedJobTitle"><?php echo htmlspecialchars($selectedEmployeeData['job_title'] ?? '—'); ?></strong></div>
                        </div>
                    </div>

                    <div class="field">
                        <label for="advance_date">تاريخ السلفة</label>
                        <input type="date" id="advance_date" name="advance_date" value="<?php echo htmlspecialchars($formData['advance_date']); ?>">
                    </div>

                    <div class="field">
                        <label for="amount">مبلغ السلفة</label>
                        <input type="number" id="amount" name="amount" value="<?php echo htmlspecialchars($formData['amount']); ?>" min="0.01" step="0.01" placeholder="مثال: 500">
                    </div>

                    <div class="field field-wide">
                        <label for="notes">ملاحظات (اختياري)</label>
                        <textarea id="notes" name="notes" placeholder="أي ملاحظات مرتبطة بالسلفة"><?php echo htmlspecialchars($formData['notes']); ?></textarea>
                    </div>
                </div>

                <div class="submit-row">
                    <button type="submit" class="btn-primary" <?php echo ($employees || $formData['action'] === 'edit') ? '' : 'disabled'; ?>>
                        <span id="submitIcon"><?php echo $formData['action'] === 'edit' ? '💾' : '💰'; ?></span>
                        <span id="submitText"><?php echo $formData['action'] === 'edit' ? 'حفظ التعديل' : 'تسجيل السلفة'; ?></span>
                    </button>
                    <button type="button" class="btn-secondary" id="cancelEditButton" style="<?php echo $formData['action'] === 'edit' ? '' : 'display:none;'; ?>">
                        <span>↩️</span>
                        <span>إلغاء التعديل</span>
                    </button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="stats-row">
                <div class="stat-box">
                    <div class="stat-label">الشهر المحدد</div>
                    <div class="stat-value"><?php echo htmlspecialchars($selectedMonth); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">عدد السلف خلال الشهر</div>
                    <div class="stat-value"><?php echo (int)$summary['advances_count']; ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">إجمالي السلف خلال الشهر</div>
                    <div class="stat-value"><?php echo number_format((float)$summary['total_amount'], 2); ?></div>
                </div>
            </div>

            <div class="toolbar">
                <form method="get" action="">
                    <div class="field">
                        <label for="month">عرض كشف شهر</label>
                        <input type="month" id="month" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                    </div>
                    <button type="submit" class="btn-primary">
                        <span>📅</span>
                        <span>عرض الشهر</span>
                    </button>
                </form>

                <a href="export_employee_advances_excel.php?month=<?php echo urlencode($selectedMonth); ?>" class="btn-secondary">
                    <span>📥</span>
                    <span>استخراج Excel للشهر</span>
                </a>
            </div>

            <div class="muted">الفترة الحالية من <?php echo htmlspecialchars($monthRange['start']); ?> إلى <?php echo htmlspecialchars($monthRange['end']); ?></div>

            <div class="table-wrapper" style="margin-top:12px;">
                <?php if ($rows): ?>
                    <table>
                        <thead>
                        <tr>
                            <th>تاريخ السلفة</th>
                            <th>الباركود</th>
                            <th>اسم الموظف</th>
                            <th>الوظيفة</th>
                            <th>المبلغ</th>
                            <th>الملاحظات</th>
                            <th>تمت بواسطة</th>
                            <th>وقت التسجيل</th>
                            <?php if ($isManager): ?>
                                <th>الإجراءات</th>
                            <?php endif; ?>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['advance_date']); ?></td>
                                <td><?php echo htmlspecialchars($row['barcode']); ?></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['job_title']); ?></td>
                                <td class="amount-cell"><?php echo number_format((float)$row['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($row['notes'] ?: '—'); ?></td>
                                <td><?php echo htmlspecialchars($row['created_by_username'] ?: '—'); ?></td>
                                <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                <?php if ($isManager): ?>
                                    <td class="actions-cell">
                                        <div class="table-actions">
                                            <button
                                                type="button"
                                                class="btn-row"
                                                onclick='fillAdvanceForm(<?php echo json_encode([
                                                    'id' => (int)$row['id'],
                                                    'employee_id' => (int)$row['employee_id'],
                                                    'employee_label' => (string)$row['barcode'] . ' — ' . (string)$row['name'],
                                                    'barcode' => (string)$row['barcode'],
                                                    'name' => (string)$row['name'],
                                                    'job_title' => (string)$row['job_title'],
                                                    'advance_date' => (string)$row['advance_date'],
                                                    'amount' => (string)$row['amount'],
                                                    'notes' => (string)($row['notes'] ?? ''),
                                                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'
                                            >
                                                ✏️ تعديل
                                            </button>
                                            <form method="post" action="" onsubmit="return confirm('هل أنت متأكد من حذف السلفة؟');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="advance_id" value="<?php echo (int)$row['id']; ?>">
                                                <input type="hidden" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                                                <button type="submit" class="btn-row btn-row-danger">🗑 حذف</button>
                                            </form>
                                        </div>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">لا توجد سلف موظفين مسجلة لهذا الشهر حتى الآن.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const switchEl = document.getElementById('themeSwitch');
        const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';
        if (savedTheme === 'dark') {
            document.body.classList.add('dark');
        }
        if (switchEl) {
            switchEl.addEventListener('click', function () {
                const isDark = document.body.classList.toggle('dark');
                localStorage.setItem('gymDashboardTheme', isDark ? 'dark' : 'light');
            });
        }
    })();

    (function () {
        const employees = <?php
            $employeeOptions = [];
            foreach ($employees as $employee) {
                $employeeOptions[] = [
                    'id' => (int)$employee['id'],
                    'barcode' => (string)$employee['barcode'],
                    'name' => (string)$employee['name'],
                    'job_title' => (string)$employee['job_title'],
                    'label' => (string)$employee['barcode'] . ' — ' . (string)$employee['name'],
                ];
            }
            echo json_encode($employeeOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ?>;

        const searchInput = document.getElementById('employeeSearch');
        const hiddenInput = document.getElementById('employeeId');
        const dropdown = document.getElementById('employeeDropdown');
        const selectedBarcode = document.getElementById('selectedBarcode');
        const selectedName = document.getElementById('selectedName');
        const selectedJobTitle = document.getElementById('selectedJobTitle');
        const formAction = document.getElementById('formAction');
        const advanceIdInput = document.getElementById('advanceId');
        const advanceDateInput = document.getElementById('advance_date');
        const amountInput = document.getElementById('amount');
        const notesInput = document.getElementById('notes');
        const submitText = document.getElementById('submitText');
        const submitIcon = document.getElementById('submitIcon');
        const cancelEditButton = document.getElementById('cancelEditButton');

        if (!searchInput || !hiddenInput || !dropdown) {
            return;
        }

        function findEmployee(id) {
            id = parseInt(id, 10);
            for (let i = 0; i < employees.length; i += 1) {
                if (employees[i].id === id) {
                    return employees[i];
                }
            }
            return null;
        }

        function applySelection(employee) {
            if (!employee) {
                hiddenInput.value = '';
                selectedBarcode.textContent = '—';
                selectedName.textContent = '—';
                selectedJobTitle.textContent = '—';
                return;
            }

            hiddenInput.value = employee.id;
            searchInput.value = employee.label;
            selectedBarcode.textContent = employee.barcode || '—';
            selectedName.textContent = employee.name || '—';
            selectedJobTitle.textContent = employee.job_title || '—';
            dropdown.classList.remove('show');
        }

        function renderOptions(keyword) {
            const query = (keyword || '').trim().toLowerCase();
            const filtered = employees.filter(function (employee) {
                const showAllEmployees = query === '';
                if (showAllEmployees) {
                    return true;
                }
                return employee.label.toLowerCase().indexOf(query) !== -1
                    || employee.name.toLowerCase().indexOf(query) !== -1
                    || employee.barcode.toLowerCase().indexOf(query) !== -1;
            }).slice(0, 25);

            dropdown.innerHTML = '';

            if (!filtered.length) {
                const empty = document.createElement('div');
                empty.className = 'picker-empty';
                empty.textContent = 'لا توجد نتائج مطابقة لبحثك.';
                dropdown.appendChild(empty);
                dropdown.classList.add('show');
                return;
            }

            filtered.forEach(function (employee) {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'employee-option';
                option.innerHTML =
                    '<span>' + employee.label + '</span>' +
                    '<small>' + (employee.job_title || 'بدون وظيفة محددة') + '</small>';
                option.addEventListener('click', function () {
                    applySelection(employee);
                });
                dropdown.appendChild(option);
            });

            dropdown.classList.add('show');
        }

        searchInput.addEventListener('focus', function () {
            renderOptions(searchInput.value);
        });

        searchInput.addEventListener('input', function () {
            hiddenInput.value = '';
            selectedBarcode.textContent = '—';
            selectedName.textContent = '—';
            selectedJobTitle.textContent = '—';
            renderOptions(searchInput.value);
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('#employeePicker')) {
                dropdown.classList.remove('show');
            }
        });

        const initialEmployee = findEmployee(hiddenInput.value);
        if (initialEmployee) {
            applySelection(initialEmployee);
        }

        window.fillAdvanceForm = function (advance) {
            if (!advance) {
                return;
            }
            formAction.value = 'edit';
            advanceIdInput.value = advance.id || '';
            applySelection({
                id: parseInt(advance.employee_id, 10) || '',
                label: advance.employee_label || '',
                barcode: advance.barcode || '',
                name: advance.name || '',
                job_title: advance.job_title || ''
            });
            advanceDateInput.value = advance.advance_date || '';
            amountInput.value = advance.amount || '';
            notesInput.value = advance.notes || '';
            if (submitText) {
                submitText.textContent = 'حفظ التعديل';
            }
            if (submitIcon) {
                submitIcon.textContent = '💾';
            }
            if (cancelEditButton) {
                cancelEditButton.style.display = 'inline-flex';
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
        };

        function resetAdvanceForm() {
            formAction.value = 'add';
            advanceIdInput.value = '';
            hiddenInput.value = '';
            searchInput.value = '';
            advanceDateInput.value = <?php echo json_encode(date('Y-m-d')); ?>;
            amountInput.value = '';
            notesInput.value = '';
            selectedBarcode.textContent = '—';
            selectedName.textContent = '—';
            selectedJobTitle.textContent = '—';
            if (submitText) {
                submitText.textContent = 'تسجيل السلفة';
            }
            if (submitIcon) {
                submitIcon.textContent = '💰';
            }
            if (cancelEditButton) {
                cancelEditButton.style.display = 'none';
            }
        }

        if (cancelEditButton) {
            cancelEditButton.addEventListener('click', resetAdvanceForm);
        }
    })();
</script>
</body>
</html>
