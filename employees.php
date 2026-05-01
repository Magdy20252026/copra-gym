<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'employees_helpers.php';
require_once 'employee_payroll_helpers.php';
require_once 'user_permissions_helpers.php';

ensureEmployeesSchema($pdo);
ensureEmployeePayrollSchema($pdo);
ensureUserPermissionsSchema($pdo);

function redirectEmployeesPage($status)
{
    header("Location: employees.php?status=" . urlencode($status));
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

$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? '';
$userId   = (int)($_SESSION['user_id'] ?? 0);

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
            $canViewPage = isset($rowPerm['can_view_employees'])
                ? ((int)$rowPerm['can_view_employees'] === 1)
                : ((int)$defaultPermissions['can_view_employees'] === 1);
        } else {
            $canViewPage = (int)$defaultPermissions['can_view_employees'] === 1;
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
$successMessages = [
    'added'   => 'تمت إضافة الموظف بنجاح.',
    'updated' => 'تم تعديل بيانات الموظف بنجاح.',
    'deleted' => 'تم حذف الموظف بنجاح.',
];
$success = $successMessages[$status] ?? '';

$formData = [
    'action'             => 'add',
    'employee_id'        => '',
    'barcode'            => '',
    'name'               => '',
    'phone'              => '',
    'job_title'          => '',
    'attendance_hour'    => '08',
    'attendance_minute'  => '00',
    'attendance_period'  => 'AM',
    'departure_hour'     => '05',
    'departure_minute'   => '00',
    'departure_period'   => 'PM',
    'off_days'           => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $employeeId = (int)($_POST['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            $errors[] = 'معرّف الموظف غير صحيح.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("DELETE FROM employee_attendance WHERE employee_id = :id");
                $stmt->execute([':id' => $employeeId]);

                $stmt = $pdo->prepare("DELETE FROM employee_advances WHERE employee_id = :id");
                $stmt->execute([':id' => $employeeId]);

                $stmt = $pdo->prepare("DELETE FROM employee_payroll WHERE employee_id = :id");
                $stmt->execute([':id' => $employeeId]);

                $stmt = $pdo->prepare("DELETE FROM employees WHERE id = :id");
                $stmt->execute([':id' => $employeeId]);

                $pdo->commit();
                redirectEmployeesPage('deleted');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'حدث خطأ أثناء حذف الموظف.';
            }
        }
    } elseif ($action === 'add' || $action === 'edit') {
        $formData['action'] = $action;
        $formData['employee_id'] = trim((string)($_POST['employee_id'] ?? ''));
        $formData['barcode'] = trim((string)($_POST['barcode'] ?? ''));
        $formData['name'] = trim((string)($_POST['name'] ?? ''));
        $formData['phone'] = trim((string)($_POST['phone'] ?? ''));
        $formData['job_title'] = trim((string)($_POST['job_title'] ?? ''));
        $formData['attendance_hour'] = trim((string)($_POST['attendance_hour'] ?? ''));
        $formData['attendance_minute'] = trim((string)($_POST['attendance_minute'] ?? ''));
        $formData['attendance_period'] = ($_POST['attendance_period'] ?? 'AM') === 'PM' ? 'PM' : 'AM';
        $formData['departure_hour'] = trim((string)($_POST['departure_hour'] ?? ''));
        $formData['departure_minute'] = trim((string)($_POST['departure_minute'] ?? ''));
        $formData['departure_period'] = ($_POST['departure_period'] ?? 'AM') === 'PM' ? 'PM' : 'AM';
        $formData['off_days'] = normalizeEmployeeOffDays($_POST['off_days'] ?? []);

        $employeeId = (int)$formData['employee_id'];
        $attendanceTime = buildEmployeeTimeValue(
            $formData['attendance_hour'],
            $formData['attendance_minute'],
            $formData['attendance_period']
        );
        $departureTime = buildEmployeeTimeValue(
            $formData['departure_hour'],
            $formData['departure_minute'],
            $formData['departure_period']
        );

        if ($formData['barcode'] === '') {
            $errors[] = 'من فضلك أدخل باركود الموظف.';
        }
        if ($formData['name'] === '') {
            $errors[] = 'من فضلك أدخل اسم الموظف.';
        }
        if ($formData['phone'] === '') {
            $errors[] = 'من فضلك أدخل رقم الهاتف.';
        }
        if ($formData['job_title'] === '') {
            $errors[] = 'من فضلك أدخل وظيفة الموظف.';
        }
        if ($attendanceTime === null) {
            $errors[] = 'من فضلك أدخل ميعاد حضور صحيح.';
        }
        if ($departureTime === null) {
            $errors[] = 'من فضلك أدخل ميعاد انصراف صحيح.';
        }
        if (!$formData['off_days']) {
            $errors[] = 'من فضلك اختر يوم إجازة واحداً على الأقل.';
        }
        if ($action === 'edit' && $employeeId <= 0) {
            $errors[] = 'معرّف الموظف غير صحيح.';
        }

        if (!$errors) {
            try {
                if ($action === 'edit') {
                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM employees
                        WHERE barcode = :barcode
                          AND id <> :employee_id
                        LIMIT 1
                    ");
                    $stmt->execute([
                        ':barcode'     => $formData['barcode'],
                        ':employee_id' => $employeeId,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM employees
                        WHERE barcode = :barcode
                        LIMIT 1
                    ");
                    $stmt->execute([
                        ':barcode' => $formData['barcode'],
                    ]);
                }

                if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                    $errors[] = 'باركود الموظف مسجل بالفعل.';
                } elseif ($action === 'add') {
                    $stmt = $pdo->prepare("
                        INSERT INTO employees (
                            barcode,
                            name,
                            phone,
                            job_title,
                            attendance_time,
                            departure_time,
                            off_days
                        ) VALUES (
                            :barcode,
                            :name,
                            :phone,
                            :job_title,
                            :attendance_time,
                            :departure_time,
                            :off_days
                        )
                    ");
                    $stmt->execute([
                        ':barcode'         => $formData['barcode'],
                        ':name'            => $formData['name'],
                        ':phone'           => $formData['phone'],
                        ':job_title'       => $formData['job_title'],
                        ':attendance_time' => $attendanceTime,
                        ':departure_time'  => $departureTime,
                        ':off_days'        => implode(',', $formData['off_days']),
                    ]);
                    redirectEmployeesPage('added');
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE employees
                        SET
                            barcode = :barcode,
                            name = :name,
                            phone = :phone,
                            job_title = :job_title,
                            attendance_time = :attendance_time,
                            departure_time = :departure_time,
                            off_days = :off_days
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':barcode'         => $formData['barcode'],
                        ':name'            => $formData['name'],
                        ':phone'           => $formData['phone'],
                        ':job_title'       => $formData['job_title'],
                        ':attendance_time' => $attendanceTime,
                        ':departure_time'  => $departureTime,
                        ':off_days'        => implode(',', $formData['off_days']),
                        ':id'              => $employeeId,
                    ]);
                    redirectEmployeesPage('updated');
                }
            } catch (Exception $e) {
                $errors[] = 'حدث خطأ أثناء حفظ بيانات الموظف.';
            }
        }
    }
}

$employees = [];
try {
    $employees = getAllEmployees($pdo);
} catch (Exception $e) {
    $errors[] = 'حدث خطأ أثناء تحميل جدول الموظفين.';
}

$hourOptions = getEmployeeHourOptions();
$minuteOptions = getEmployeeMinuteOptions();
$offDayOptions = getEmployeeOffDayOptions();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>الموظفين - <?php echo htmlspecialchars($siteName); ?></title>
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
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 900;
            font-size: 18px;
        }
        .page { max-width: 1380px; margin: 30px auto 40px; padding: 0 20px; }
        .header-bar { display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;gap:12px;flex-wrap:wrap; }
        .title-main{font-size:28px;font-weight:900;}
        .title-sub{margin-top:6px;font-size:16px;color:var(--text-muted);font-weight:800;}
        .back-button{
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            padding:11px 22px;border-radius:999px;border:none;cursor:pointer;
            font-size:16px;font-weight:900;background:linear-gradient(90deg,#6366f1,#22c55e);
            color:#f9fafb;box-shadow:0 16px 38px rgba(79,70,229,0.55);text-decoration:none;
        }
        .back-button:hover{filter:brightness(1.05);}
        .card{
            background:var(--card-bg);border-radius:24px;padding:20px 22px 22px;
            box-shadow:0 22px 60px rgba(15,23,42,0.22),0 0 0 1px rgba(255,255,255,0.65);
        }
        .theme-toggle{display:flex;justify-content:flex-end;margin-bottom:14px;}
        .theme-switch{
            position:relative;width:72px;height:34px;border-radius:999px;background:#e5e7eb;
            box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);cursor:pointer;display:flex;
            align-items:center;justify-content:space-between;padding:0 8px;font-size:16px;color:#6b7280;font-weight:900;
        }
        .theme-switch span{z-index:2;user-select:none;}
        .theme-thumb{
            position:absolute;top:3px;right:3px;width:26px;height:26px;border-radius:999px;background:#facc15;
            box-shadow:0 4px 10px rgba(250,204,21,0.7);display:flex;align-items:center;justify-content:center;
            font-size:16px;transition:transform .25s ease,background .25s ease,box-shadow .25s ease;
        }
        body.dark .theme-switch{background:#020617;box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);color:#e5e7eb;}
        body.dark .theme-thumb{transform:translateX(-36px);background:#0f172a;box-shadow:0 4px 12px rgba(15,23,42,0.9);}
        .alert{padding:11px 13px;border-radius:12px;font-size:16px;margin-bottom:12px;font-weight:900;}
        .alert-error{background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.9);color:var(--danger);}
        .alert-success{background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.9);color:var(--accent-green);}
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;align-items:start;}
        .field{display:flex;flex-direction:column;gap:6px;}
        .field label{font-size:15px;color:var(--text-muted);font-weight:900;}
        input[type="text"],select{
            width:100%;padding:10px 12px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:16px;font-weight:800;color:var(--text-main);
        }
        input:focus,select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft);}
        .time-group,.days-group,.actions{display:flex;gap:8px;flex-wrap:wrap;}
        .time-group select{flex:1 1 85px;}
        .days-group{
            padding:12px;border:1px solid var(--border);border-radius:18px;background:var(--input-bg);
        }
        .days-group label{
            display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;
            background:var(--card-bg);border:1px solid var(--border);font-size:14px;color:var(--text-main);
        }
        .btn-primary,.btn-save,.btn-cancel,.btn-edit,.btn-danger{
            border:none;border-radius:999px;cursor:pointer;font-size:15px;font-weight:900;
            display:inline-flex;align-items:center;justify-content:center;gap:6px;
        }
        .btn-primary,.btn-save,.btn-cancel{padding:10px 18px;}
        .btn-primary{background:linear-gradient(90deg,#22c55e,#16a34a);color:#f9fafb;box-shadow:0 14px 32px rgba(22,163,74,0.6);}
        .btn-save{background:linear-gradient(90deg,#2563eb,#38bdf8);color:#f9fafb;box-shadow:0 14px 32px rgba(37,99,235,0.5);}
        .btn-cancel{background:#e5e7eb;color:#0f172a;}
        .btn-edit{background:var(--warning);color:#fff;padding:7px 14px;}
        .btn-danger{background:#ef4444;color:#fff;padding:7px 14px;}
        .btn-primary:hover,.btn-save:hover,.btn-cancel:hover,.btn-edit:hover,.btn-danger:hover{filter:brightness(1.05);}
        .table-wrapper{margin-top:18px;border-radius:20px;border:1px solid var(--border);overflow:auto;}
        table{width:100%;border-collapse:collapse;font-size:15px;}
        thead{background:rgba(15,23,42,0.04);}
        body.dark thead{background:rgba(15,23,42,0.9);}
        th,td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;}
        th{font-weight:900;color:var(--text-muted);}
        td{font-weight:800;}
        .empty{padding:20px;text-align:center;color:var(--text-muted);font-weight:800;}
        .muted-note{font-size:14px;color:var(--text-muted);margin-top:8px;}
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">إدارة الموظفين</div>
            <div class="title-sub">مستخدم مسجل: <?php echo htmlspecialchars($username); ?> — الصلاحية: <?php echo htmlspecialchars($role); ?></div>
        </div>
        <a href="dashboard.php" class="back-button">
            <span>📊</span>
            <span>العودة إلى لوحة التحكم</span>
        </a>
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

        <form method="post" action="" id="employeeForm">
            <input type="hidden" name="action" id="formAction" value="<?php echo htmlspecialchars($formData['action']); ?>">
            <input type="hidden" name="employee_id" id="employeeId" value="<?php echo htmlspecialchars($formData['employee_id']); ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="barcode">باركود الموظف</label>
                    <input type="text" id="barcode" name="barcode" value="<?php echo htmlspecialchars($formData['barcode']); ?>" required>
                </div>

                <div class="field">
                    <label for="name">اسم الموظف</label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($formData['name']); ?>" required>
                </div>

                <div class="field">
                    <label for="phone">رقم الهاتف</label>
                    <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($formData['phone']); ?>" required>
                </div>

                <div class="field">
                    <label for="job_title">الوظيفة</label>
                    <input type="text" id="job_title" name="job_title" value="<?php echo htmlspecialchars($formData['job_title']); ?>" required>
                </div>

                <div class="field">
                    <label>ميعاد الحضور (ساعة / دقيقة / ص-م)</label>
                    <div class="time-group">
                        <select id="attendance_hour" name="attendance_hour">
                            <?php foreach ($hourOptions as $hourOption): ?>
                                <option value="<?php echo $hourOption; ?>" <?php echo $formData['attendance_hour'] === $hourOption ? 'selected' : ''; ?>><?php echo $hourOption; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="attendance_minute" name="attendance_minute">
                            <?php foreach ($minuteOptions as $minuteOption): ?>
                                <option value="<?php echo $minuteOption; ?>" <?php echo $formData['attendance_minute'] === $minuteOption ? 'selected' : ''; ?>><?php echo $minuteOption; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="attendance_period" name="attendance_period">
                            <option value="AM" <?php echo $formData['attendance_period'] === 'AM' ? 'selected' : ''; ?>>ص</option>
                            <option value="PM" <?php echo $formData['attendance_period'] === 'PM' ? 'selected' : ''; ?>>م</option>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label>ميعاد الانصراف (ساعة / دقيقة / ص-م)</label>
                    <div class="time-group">
                        <select id="departure_hour" name="departure_hour">
                            <?php foreach ($hourOptions as $hourOption): ?>
                                <option value="<?php echo $hourOption; ?>" <?php echo $formData['departure_hour'] === $hourOption ? 'selected' : ''; ?>><?php echo $hourOption; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="departure_minute" name="departure_minute">
                            <?php foreach ($minuteOptions as $minuteOption): ?>
                                <option value="<?php echo $minuteOption; ?>" <?php echo $formData['departure_minute'] === $minuteOption ? 'selected' : ''; ?>><?php echo $minuteOption; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="departure_period" name="departure_period">
                            <option value="AM" <?php echo $formData['departure_period'] === 'AM' ? 'selected' : ''; ?>>ص</option>
                            <option value="PM" <?php echo $formData['departure_period'] === 'PM' ? 'selected' : ''; ?>>م</option>
                        </select>
                    </div>
                </div>

                <div class="field" style="grid-column:1/-1;">
                    <label>أيام الإجازة</label>
                    <div class="days-group">
                        <?php foreach ($offDayOptions as $offDayKey => $offDayLabel): ?>
                            <label>
                                <input type="checkbox" name="off_days[]" value="<?php echo htmlspecialchars($offDayKey); ?>" <?php echo in_array($offDayKey, $formData['off_days'], true) ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($offDayLabel); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="muted-note">يمكن اختيار أكثر من يوم إجازة للموظف الواحد.</div>
                </div>

                <div class="field" id="addButtonWrap">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn-primary">
                        <span>➕</span>
                        <span>إضافة موظف</span>
                    </button>
                </div>

                <div class="field" id="editButtons" style="display:none;">
                    <label>&nbsp;</label>
                    <div class="actions">
                        <button type="submit" class="btn-save">
                            <span>💾</span>
                            <span>حفظ التعديل</span>
                        </button>
                        <button type="button" class="btn-cancel" onclick="resetFormToAdd()">إلغاء</button>
                    </div>
                </div>
            </div>
        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>الباركود</th>
                    <th>اسم الموظف</th>
                    <th>رقم الهاتف</th>
                    <th>الوظيفة</th>
                    <th>ميعاد الحضور</th>
                    <th>ميعاد الانصراف</th>
                    <th>أيام الإجازة</th>
                    <th>تاريخ الإضافة</th>
                    <th>إجراءات</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="10" class="empty">لا يوجد موظفون مسجلون حتى الآن.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($employees as $employee): ?>
                        <?php
                        $attendanceParts = splitEmployeeTimeValue($employee['attendance_time']);
                        $departureParts = splitEmployeeTimeValue($employee['departure_time']);
                        ?>
                        <tr>
                            <td><?php echo (int)$employee['id']; ?></td>
                            <td><?php echo htmlspecialchars($employee['barcode']); ?></td>
                            <td><?php echo htmlspecialchars($employee['name']); ?></td>
                            <td><?php echo htmlspecialchars($employee['phone']); ?></td>
                            <td><?php echo htmlspecialchars($employee['job_title']); ?></td>
                            <td><?php echo htmlspecialchars(formatEmployeeTime($employee['attendance_time'])); ?></td>
                            <td><?php echo htmlspecialchars(formatEmployeeTime($employee['departure_time'])); ?></td>
                            <td><?php echo htmlspecialchars(formatEmployeeOffDays($employee['off_days'])); ?></td>
                            <td><?php echo htmlspecialchars(formatAppDateTime12Hour($employee['created_at'])); ?></td>
                            <td>
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="btn-edit"
                                        data-id="<?php echo (int)$employee['id']; ?>"
                                        data-barcode="<?php echo htmlspecialchars($employee['barcode'], ENT_QUOTES); ?>"
                                        data-name="<?php echo htmlspecialchars($employee['name'], ENT_QUOTES); ?>"
                                        data-phone="<?php echo htmlspecialchars($employee['phone'], ENT_QUOTES); ?>"
                                        data-job-title="<?php echo htmlspecialchars($employee['job_title'], ENT_QUOTES); ?>"
                                        data-attendance-hour="<?php echo htmlspecialchars($attendanceParts['hour'], ENT_QUOTES); ?>"
                                        data-attendance-minute="<?php echo htmlspecialchars($attendanceParts['minute'], ENT_QUOTES); ?>"
                                        data-attendance-period="<?php echo htmlspecialchars($attendanceParts['period'], ENT_QUOTES); ?>"
                                        data-departure-hour="<?php echo htmlspecialchars($departureParts['hour'], ENT_QUOTES); ?>"
                                        data-departure-minute="<?php echo htmlspecialchars($departureParts['minute'], ENT_QUOTES); ?>"
                                        data-departure-period="<?php echo htmlspecialchars($departureParts['period'], ENT_QUOTES); ?>"
                                        data-off-days="<?php echo htmlspecialchars($employee['off_days'], ENT_QUOTES); ?>"
                                        onclick="fillEditForm(this)"
                                    >تعديل</button>
                                    <form method="post" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذا الموظف؟');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="employee_id" value="<?php echo (int)$employee['id']; ?>">
                                        <button type="submit" class="btn-danger">حذف</button>
                                    </form>
                                </div>
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

    const formAction = document.getElementById('formAction');
    const employeeIdInput = document.getElementById('employeeId');
    const barcodeInput = document.getElementById('barcode');
    const nameInput = document.getElementById('name');
    const phoneInput = document.getElementById('phone');
    const jobTitleInput = document.getElementById('job_title');
    const attendanceHourInput = document.getElementById('attendance_hour');
    const attendanceMinuteInput = document.getElementById('attendance_minute');
    const attendancePeriodInput = document.getElementById('attendance_period');
    const departureHourInput = document.getElementById('departure_hour');
    const departureMinuteInput = document.getElementById('departure_minute');
    const departurePeriodInput = document.getElementById('departure_period');
    const offDaysInputs = document.querySelectorAll('input[name="off_days[]"]');
    const addButtonWrap = document.getElementById('addButtonWrap');
    const editButtons = document.getElementById('editButtons');

    function setCheckedOffDays(offDays) {
        const selectedDays = offDays ? offDays.split(',') : [];
        offDaysInputs.forEach((input) => {
            input.checked = selectedDays.includes(input.value);
        });
    }

    function fillEditForm(button) {
        const data = button.dataset;

        formAction.value = 'edit';
        employeeIdInput.value = data.id || '';
        barcodeInput.value = data.barcode || '';
        nameInput.value = data.name || '';
        phoneInput.value = data.phone || '';
        jobTitleInput.value = data.jobTitle || '';
        attendanceHourInput.value = data.attendanceHour || '08';
        attendanceMinuteInput.value = data.attendanceMinute || '00';
        attendancePeriodInput.value = data.attendancePeriod || 'AM';
        departureHourInput.value = data.departureHour || '05';
        departureMinuteInput.value = data.departureMinute || '00';
        departurePeriodInput.value = data.departurePeriod || 'PM';
        setCheckedOffDays(data.offDays || '');

        if (addButtonWrap) {
            addButtonWrap.style.display = 'none';
        }
        if (editButtons) {
            editButtons.style.display = 'block';
        }

        barcodeInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function resetFormToAdd() {
        formAction.value = 'add';
        employeeIdInput.value = '';
        barcodeInput.value = '';
        nameInput.value = '';
        phoneInput.value = '';
        jobTitleInput.value = '';
        attendanceHourInput.value = '08';
        attendanceMinuteInput.value = '00';
        attendancePeriodInput.value = 'AM';
        departureHourInput.value = '05';
        departureMinuteInput.value = '00';
        departurePeriodInput.value = 'PM';
        setCheckedOffDays('');

        if (addButtonWrap) {
            addButtonWrap.style.display = 'block';
        }
        if (editButtons) {
            editButtons.style.display = 'none';
        }
    }

    <?php if ($formData['action'] === 'edit' && $errors): ?>
    if (addButtonWrap) {
        addButtonWrap.style.display = 'none';
    }
    if (editButtons) {
        editButtons.style.display = 'block';
    }
    <?php endif; ?>
</script>
</body>
</html>
