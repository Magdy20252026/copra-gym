<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'employees_helpers.php';
require_once 'user_permissions_helpers.php';

ensureEmployeesSchema($pdo);
ensureEmployeeAttendanceSchema($pdo);
ensureUserPermissionsSchema($pdo);

function formatEmployeeAttendanceMoment($dateTime)
{
    if (!$dateTime) {
        return '—';
    }

    $timestamp = strtotime((string)$dateTime);
    if ($timestamp === false) {
        return (string)$dateTime;
    }

    return date('Y-m-d H:i:s', $timestamp);
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
$role = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);

$defaultPermissions = getDefaultUserPermissions();
$isManager = ($role === 'مدير');
$isSupervisor = ($role === 'مشرف');
$pagePermissions = $defaultPermissions;
$canViewPage = false;

if ($isManager) {
    $canViewPage = true;
} elseif ($isSupervisor && $userId > 0) {
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            foreach ($pagePermissions as $key => $value) {
                if (isset($rowPerm[$key])) {
                    $pagePermissions[$key] = (int)$rowPerm[$key];
                }
            }
            $canViewPage = (int)$pagePermissions['can_view_employee_attendance'] === 1;
        } else {
            $canViewPage = (int)$defaultPermissions['can_view_employee_attendance'] === 1;
        }
    } catch (Exception $e) {
        $canViewPage = false;
    }
}

if (!$canViewPage) {
    header("Location: dashboard.php");
    exit;
}

$canScanAttendance = $isManager || ((int)($pagePermissions['can_scan_employee_attendance'] ?? 0) === 1);
$canUseAttendanceCamera = $canScanAttendance && ($isManager || ((int)($pagePermissions['can_use_employee_attendance_camera'] ?? 0) === 1));
$canViewAttendanceReport = $isManager || ((int)($pagePermissions['can_view_employee_attendance_report'] ?? 0) === 1);
$canExportAttendanceExcel = $canViewAttendanceReport && ($isManager || ((int)($pagePermissions['can_export_employee_attendance_excel'] ?? 0) === 1));

$errors = [];
$success = '';
$today = date('Y-m-d');
$reportDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])
    ? (string)$_GET['date']
    : $today;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'scan_employee') {
        if (!$canScanAttendance) {
            $errors[] = 'غير مسموح لك بتسجيل حضور أو انصراف الموظفين.';
        } else {
            $barcode = trim((string)($_POST['barcode'] ?? ''));

            if ($barcode === '') {
                $errors[] = 'من فضلك أدخل باركود الموظف.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        SELECT id, barcode, name, phone, job_title, attendance_time, departure_time, off_days
                        FROM employees
                        WHERE barcode = :barcode
                        LIMIT 1
                    ");
                    $stmt->execute([':barcode' => $barcode]);
                    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$employee) {
                        $errors[] = 'لم يتم العثور على موظف بهذا الباركود.';
                    } else {
                        $now = date('Y-m-d H:i:s');
                        $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
                        $isOvernightShift = isEmployeeOvernightShift($employee['attendance_time'], $employee['departure_time']);
                        $pdo->beginTransaction();

                        $stmt = $pdo->prepare("
                            SELECT *
                            FROM employee_attendance
                            WHERE employee_id = :employee_id
                            AND attendance_date = :attendance_date
                            LIMIT 1
                            FOR UPDATE
                        ");
                        $stmt->execute([
                            ':employee_id' => (int)$employee['id'],
                            ':attendance_date' => $today,
                        ]);
                        $todayAttendanceRow = $stmt->fetch(PDO::FETCH_ASSOC);
                        $attendanceRow = $todayAttendanceRow;

                        $shouldCheckPreviousDayAttendance = !$attendanceRow && $isOvernightShift;
                        if ($shouldCheckPreviousDayAttendance) {
                            $stmt = $pdo->prepare("
                                SELECT *
                                FROM employee_attendance
                                WHERE employee_id = :employee_id
                                  AND attendance_date = :attendance_date
                                  AND departure_at IS NULL
                                LIMIT 1
                                FOR UPDATE
                            ");
                            $stmt->execute([
                                ':employee_id' => (int)$employee['id'],
                                ':attendance_date' => $yesterday,
                            ]);
                            $previousAttendanceRow = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($previousAttendanceRow) {
                                $attendanceRow = $previousAttendanceRow;
                            }
                        }

                        if (!$attendanceRow) {
                            if (isEmployeeOffDay($employee['off_days'] ?? '', $today)) {
                                $pdo->rollBack();
                                $errors[] = 'لا يمكن تسجيل حضور أو انصراف الموظف في يوم إجازته.';
                            } else {
                                $attendanceStatus = classifyEmployeeAttendanceStatus($today, $employee['attendance_time'], $now);
                                $stmt = $pdo->prepare("
                                    INSERT INTO employee_attendance (
                                        employee_id,
                                        attendance_date,
                                        attendance_at,
                                        attendance_status,
                                        scheduled_attendance_time,
                                        scheduled_departure_time
                                    ) VALUES (
                                        :employee_id,
                                        :attendance_date,
                                        :attendance_at,
                                        :attendance_status,
                                        :scheduled_attendance_time,
                                        :scheduled_departure_time
                                    )
                                ");
                                $stmt->execute([
                                    ':employee_id' => (int)$employee['id'],
                                    ':attendance_date' => $today,
                                    ':attendance_at' => $now,
                                    ':attendance_status' => $attendanceStatus,
                                    ':scheduled_attendance_time' => $employee['attendance_time'],
                                    ':scheduled_departure_time' => $employee['departure_time'],
                                ]);

                                $pdo->commit();
                                $success = 'تم تسجيل حضور الموظف "' . $employee['name'] . '" بنجاح. حالة الحضور: ' . $attendanceStatus . '.';
                            }
                        } elseif (empty($attendanceRow['departure_at'])) {
                            $scheduleData = array_merge($employee, $attendanceRow);
                            $scheduledAttendanceTime = getEmployeeScheduledTimeValue($scheduleData, 'scheduled_attendance_time', 'attendance_time');
                            $scheduledDepartureTime = getEmployeeScheduledTimeValue($scheduleData, 'scheduled_departure_time', 'departure_time');
                            $departureStatus = classifyEmployeeShiftDepartureStatus(
                                $attendanceRow['attendance_date'],
                                $scheduledAttendanceTime,
                                $scheduledDepartureTime,
                                $now
                            );

                            $stmt = $pdo->prepare("
                                UPDATE employee_attendance
                                SET departure_at = :departure_at,
                                    departure_status = :departure_status
                                WHERE id = :id
                            ");
                            $stmt->execute([
                                ':departure_at' => $now,
                                ':departure_status' => $departureStatus,
                                ':id' => (int)$attendanceRow['id'],
                            ]);

                            $pdo->commit();
                            $success = 'تم تسجيل انصراف الموظف "' . $employee['name'] . '" بنجاح. حالة الانصراف: ' . $departureStatus . '.';
                        } else {
                            $pdo->rollBack();
                            $errors[] = 'تم تسجيل حضور وانصراف الموظف اليوم بالفعل، ولا يمكن تكرار التسجيل.';
                        }
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = 'حدث خطأ أثناء تسجيل حضور/انصراف الموظف.';
                }
            }
        }
    }
}

$reportRows = [];
if ($canViewAttendanceReport) {
    try {
        $reportRows = getEmployeeAttendanceReportRows($pdo, $reportDate);
    } catch (Exception $e) {
        $errors[] = 'حدث خطأ أثناء تحميل كشف حضور الموظفين.';
    }
}

$summary = [
    'present' => 0,
    'late' => 0,
    'early_departure' => 0,
    'absent' => 0,
    'off_day' => 0,
];

foreach ($reportRows as $row) {
    if ($row['day_status'] === 'إجازة') {
        $summary['off_day']++;
        continue;
    }

    if ($row['day_status'] === 'غياب') {
        $summary['absent']++;
    } else {
        $summary['present']++;
    }

    if (($row['attendance_status'] ?? '') === 'متأخر') {
        $summary['late']++;
    }

    if (($row['departure_status'] ?? '') === 'انصراف مبكر') {
        $summary['early_departure']++;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>حضور الموظفين - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        :root {
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --primary: #2563eb;
            --primary-soft: rgba(37,99,235,0.15);
            --success: #16a34a;
            --danger: #dc2626;
            --warning: #d97706;
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
            --success: #22c55e;
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
        .page { max-width: 1420px; margin: 30px auto 40px; padding: 0 20px; }
        .header-bar { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:24px; }
        .title-main { font-size:30px; font-weight:900; }
        .title-sub { margin-top:6px; font-size:16px; color:var(--text-muted); font-weight:800; }
        .back-button {
            display:inline-flex; align-items:center; justify-content:center; gap:8px;
            padding:11px 22px; border-radius:999px; border:none; cursor:pointer;
            font-size:16px; font-weight:900; background:linear-gradient(90deg,#6366f1,#22c55e);
            color:#f9fafb; box-shadow:0 16px 38px rgba(79,70,229,0.55); text-decoration:none;
        }
        .card {
            background:var(--card-bg); border-radius:24px; padding:20px 22px 22px;
            box-shadow:0 22px 60px rgba(15,23,42,0.22),0 0 0 1px rgba(255,255,255,0.65);
            margin-bottom:18px;
        }
        .theme-toggle { display:flex; justify-content:flex-end; margin-bottom:14px; }
        .theme-switch {
            position:relative; width:72px; height:34px; border-radius:999px; background:#e5e7eb;
            box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9); cursor:pointer; display:flex;
            align-items:center; justify-content:space-between; padding:0 8px; font-size:16px; color:#6b7280; font-weight:900;
        }
        .theme-switch span { z-index:2; user-select:none; }
        .theme-thumb {
            position:absolute; top:3px; right:3px; width:26px; height:26px; border-radius:999px; background:#facc15;
            box-shadow:0 4px 10px rgba(250,204,21,0.7); display:flex; align-items:center; justify-content:center;
            font-size:16px; transition:transform .25s ease, background .25s ease, box-shadow .25s ease;
        }
        body.dark .theme-switch { background:#020617; box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9); color:#e5e7eb; }
        body.dark .theme-thumb { transform:translateX(-36px); background:#0f172a; box-shadow:0 4px 12px rgba(15,23,42,0.9); }
        .alert { padding:11px 13px; border-radius:12px; font-size:16px; margin-bottom:12px; font-weight:900; }
        .alert-error { background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.9); color:var(--danger); }
        .alert-success { background:rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.9); color:var(--success); }
        .row { display:flex; flex-wrap:wrap; gap:18px; }
        .col-half { flex:1 1 430px; }
        .field { display:flex; flex-direction:column; gap:6px; margin-bottom:12px; }
        .field label { font-size:15px; color:var(--text-muted); font-weight:900; }
        input[type="text"], input[type="date"] {
            width:100%; padding:11px 14px; border-radius:999px; border:1px solid var(--border);
            background:var(--input-bg); font-size:16px; font-weight:800; color:var(--text-main);
        }
        input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 2px var(--primary-soft); }
        .btn-main, .btn-secondary {
            border:none; border-radius:999px; cursor:pointer; font-size:15px; font-weight:900;
            display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:11px 18px;
            text-decoration:none;
        }
        .btn-main {
            background:linear-gradient(90deg,#2563eb,#38bdf8);
            color:#f9fafb;
            box-shadow:0 18px 42px rgba(37,99,235,0.45);
        }
        .btn-secondary {
            background:#e5e7eb;
            color:#0f172a;
        }
        body.dark .btn-secondary {
            background:#111827;
            color:#e5e7eb;
        }
        .summary-grid {
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));
            gap:12px;
            margin-top:14px;
        }
        .summary-card {
            border:1px solid var(--border);
            border-radius:18px;
            padding:14px 16px;
            background:var(--input-bg);
        }
        .summary-card .label { font-size:14px; color:var(--text-muted); margin-bottom:8px; }
        .summary-card .value { font-size:28px; }
        .table-wrapper {
            margin-top:18px;
            border-radius:20px;
            border:1px solid var(--border);
            overflow:auto;
        }
        table {
            width:100%;
            border-collapse:collapse;
            font-size:15px;
            min-width:1450px;
        }
        thead { background:rgba(15,23,42,0.04); }
        body.dark thead { background:rgba(15,23,42,0.9); }
        th, td {
            padding:10px 12px;
            border-bottom:1px solid var(--border);
            text-align:right;
            white-space:nowrap;
        }
        th { font-weight:900; color:var(--text-muted); }
        td { font-weight:800; }
        .tag {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border-radius:999px;
            padding:5px 10px;
            font-size:13px;
            font-weight:900;
        }
        .tag-on-time { background:rgba(34,197,94,0.18); color:#166534; }
        .tag-late, .tag-early { background:rgba(245,158,11,0.22); color:#9a3412; }
        .tag-absent { background:rgba(239,68,68,0.18); color:#b91c1c; }
        .tag-off { background:rgba(107,114,128,0.22); color:#374151; }
        .tag-open { background:rgba(37,99,235,0.18); color:#1d4ed8; }
        .muted-note { font-size:14px; color:var(--text-muted); margin-top:8px; }
        #cameraArea { margin-top:10px; display:none; }
        #reader { width:100%; max-width:380px; margin-top:8px; }
        .actions-row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    </style>
    <script src="assets/html5-qrcode.min.js"></script>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">حضور الموظفين</div>
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

        <div class="row">
            <?php if ($canScanAttendance): ?>
                <div class="col-half">
                    <h3 style="margin:0 0 12px;font-size:21px;">تسجيل حضور / انصراف الموظف</h3>
                    <form method="post" action="" autocomplete="off" id="scanForm">
                        <input type="hidden" name="action" value="scan_employee">

                        <div class="field">
                            <label for="barcode">باركود الموظف</label>
                            <input type="text" id="barcode" name="barcode" placeholder="اكتب الباركود أو امسحه بالقارئ أو بالكاميرا" autofocus>
                        </div>

                        <div class="actions-row">
                            <button type="submit" class="btn-main">
                                <span>✅</span>
                                <span>تسجيل الحركة</span>
                            </button>
                            <?php if ($canUseAttendanceCamera): ?>
                                <button type="button" class="btn-secondary" id="btnOpenCamera">
                                    <span>📷</span>
                                    <span>قراءة الباركود بالكاميرا</span>
                                </button>
                                <button type="button" class="btn-secondary" id="stopScanBtn" style="display:none;">
                                    <span>🛑</span>
                                    <span>إيقاف الكاميرا</span>
                                </button>
                            <?php endif; ?>
                        </div>

                        <div class="muted-note">أول تسجيل في اليوم يُحسب حضوراً، والثاني يُحسب انصرافاً، وبعد ذلك لا يُسمح بأي تسجيل إضافي لنفس الموظف في نفس اليوم.</div>

                        <?php if ($canUseAttendanceCamera): ?>
                            <div id="cameraArea">
                                <div class="muted-note">يمكن استخدام كاميرا الموبايل أو الكمبيوتر، وعند قراءة الباركود سيتم تعبئة الحقل وإرسال النموذج تلقائياً.</div>
                                <div id="reader"></div>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($canViewAttendanceReport): ?>
                <div class="col-half">
                    <h3 style="margin:0 0 12px;font-size:21px;">بحث كشف اليوم وتصديره</h3>
                    <form method="get" action="">
                        <div class="field">
                            <label for="date">تاريخ الكشف</label>
                            <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($reportDate); ?>">
                        </div>
                        <div class="actions-row">
                            <button type="submit" class="btn-main">
                                <span>🔎</span>
                                <span>عرض الكشف</span>
                            </button>
                            <?php if ($canExportAttendanceExcel): ?>
                                <a class="btn-secondary" href="export_employee_attendance_excel.php?date=<?php echo urlencode($reportDate); ?>">
                                    <span>📥</span>
                                    <span>تصدير Excel</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>

                    <div class="summary-grid">
                        <div class="summary-card">
                            <div class="label">تم تسجيلهم</div>
                            <div class="value"><?php echo (int)$summary['present']; ?></div>
                        </div>
                        <div class="summary-card">
                            <div class="label">حضور متأخر</div>
                            <div class="value"><?php echo (int)$summary['late']; ?></div>
                        </div>
                        <div class="summary-card">
                            <div class="label">انصراف مبكر</div>
                            <div class="value"><?php echo (int)$summary['early_departure']; ?></div>
                        </div>
                        <div class="summary-card">
                            <div class="label">غياب</div>
                            <div class="value"><?php echo (int)$summary['absent']; ?></div>
                        </div>
                        <div class="summary-card">
                            <div class="label">إجازات</div>
                            <div class="value"><?php echo (int)$summary['off_day']; ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$canScanAttendance && !$canViewAttendanceReport): ?>
            <div class="muted-note">لا توجد أزرار مفعلة لك حالياً في صفحة حضور الموظفين. يمكن للمدير تعديلها من صفحة الصلاحيات.</div>
        <?php endif; ?>
    </div>

    <?php if ($canViewAttendanceReport): ?>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
                <div style="font-size:18px;">
                    كشف حضور وانصراف الموظفين ليوم:
                    <strong><?php echo htmlspecialchars($reportDate); ?></strong>
                    — <span style="color:var(--text-muted);"><?php echo htmlspecialchars(getEmployeeDayLabelFromDate($reportDate)); ?></span>
                </div>
                <div class="muted-note">يتم احتساب الغياب تلقائياً للموظف الذي ليس لديه أي تسجيل في يوم عمله.</div>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>الباركود</th>
                        <th>اسم الموظف</th>
                        <th>رقم الهاتف</th>
                        <th>الوظيفة</th>
                        <th>أيام الإجازة</th>
                        <th>ميعاد الحضور</th>
                        <th>وقت الحضور الفعلي</th>
                        <th>حالة الحضور</th>
                        <th>ميعاد الانصراف</th>
                        <th>وقت الانصراف الفعلي</th>
                        <th>حالة الانصراف</th>
                        <th>حالة اليوم</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($reportRows): ?>
                        <?php foreach ($reportRows as $index => $row): ?>
                            <?php
                            $attendanceStatus = $row['attendance_status'] ?: '—';
                            $departureStatus = $row['departure_status'] ?: '—';
                            $dayStatusClass = 'tag-open';
                            if ($row['day_status'] === 'غياب') {
                                $dayStatusClass = 'tag-absent';
                            } elseif ($row['day_status'] === 'إجازة') {
                                $dayStatusClass = 'tag-off';
                            } elseif ($row['day_status'] === 'حضور وانصراف مكتمل') {
                                $dayStatusClass = 'tag-on-time';
                            }

                            $attendanceClass = $attendanceStatus === 'متأخر' ? 'tag-late' : ($attendanceStatus === 'في الموعد' ? 'tag-on-time' : 'tag-open');
                            $departureClass = $departureStatus === 'انصراف مبكر' ? 'tag-early' : ($departureStatus === 'في الموعد' ? 'tag-on-time' : 'tag-open');
                            ?>
                            <tr>
                                <td><?php echo (int)($index + 1); ?></td>
                                <td><?php echo htmlspecialchars($row['barcode']); ?></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['phone']); ?></td>
                                <td><?php echo htmlspecialchars($row['job_title']); ?></td>
                                <td><?php echo htmlspecialchars(formatEmployeeOffDays($row['off_days'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars(formatEmployeeTime($row['scheduled_attendance_display'])); ?></td>
                                <td><?php echo htmlspecialchars(formatEmployeeAttendanceMoment($row['attendance_at'])); ?></td>
                                <td><span class="tag <?php echo $attendanceClass; ?>"><?php echo htmlspecialchars($attendanceStatus); ?></span></td>
                                <td><?php echo htmlspecialchars(formatEmployeeTime($row['scheduled_departure_display'])); ?></td>
                                <td><?php echo htmlspecialchars(formatEmployeeAttendanceMoment($row['departure_at'])); ?></td>
                                <td><span class="tag <?php echo $departureClass; ?>"><?php echo htmlspecialchars($departureStatus); ?></span></td>
                                <td><span class="tag <?php echo $dayStatusClass; ?>"><?php echo htmlspecialchars($row['day_status']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="13" style="text-align:center;color:var(--text-muted);font-size:18px;padding:20px 0;">
                                لا يوجد موظفون مسجلون حتى الآن.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
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
            applyTheme(body.classList.contains('dark') ? 'light' : 'dark');
        });
    }

    let html5QrCode = null;
    let scannerRunning = false;
    const barcodeInput = document.getElementById('barcode');
    const scanForm = document.getElementById('scanForm');
    const btnOpenCamera = document.getElementById('btnOpenCamera');
    const stopScanBtn = document.getElementById('stopScanBtn');
    const cameraArea = document.getElementById('cameraArea');

    function stopScanner() {
        if (html5QrCode && scannerRunning) {
            html5QrCode.stop().then(() => {
                scannerRunning = false;
                cameraArea.style.display = 'none';
                stopScanBtn.style.display = 'none';
            }).catch(() => {
                cameraArea.style.display = 'none';
                stopScanBtn.style.display = 'none';
            });
        } else {
            cameraArea.style.display = 'none';
            stopScanBtn.style.display = 'none';
        }
    }

    if (btnOpenCamera) {
        btnOpenCamera.addEventListener('click', () => {
            if (!window.Html5Qrcode) {
                alert('مكتبة قراءة الباركود بالكاميرا غير متاحة.');
                return;
            }

            cameraArea.style.display = 'block';
            stopScanBtn.style.display = 'inline-flex';

            if (!html5QrCode) {
                html5QrCode = new Html5Qrcode('reader');
            }

            if (scannerRunning) {
                return;
            }

            Html5Qrcode.getCameras().then((devices) => {
                if (!devices || devices.length === 0) {
                    alert('لم يتم العثور على كاميرا متاحة.');
                    return;
                }

                const preferredCamera = devices.find((device) => /back|rear|environment/i.test(device.label || '')) || devices[0];

                html5QrCode.start(
                    preferredCamera.id,
                    { fps: 10, qrbox: { width: 250, height: 250 } },
                    (decodedText) => {
                        barcodeInput.value = decodedText;
                        stopScanner();
                        if (typeof scanForm.requestSubmit === 'function') {
                            scanForm.requestSubmit();
                        } else {
                            scanForm.submit();
                        }
                    }
                ).then(() => {
                    scannerRunning = true;
                }).catch(() => {
                    alert('تعذر تشغيل الكاميرا لقراءة الباركود.');
                });
            }).catch(() => {
                alert('تعذر الوصول إلى الكاميرا.');
            });
        });
    }

    if (stopScanBtn) {
        stopScanBtn.addEventListener('click', stopScanner);
    }
</script>
</body>
</html>
