<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'user_permissions_helpers.php';

ensureUserPermissionsSchema($pdo);

// التحقق أن المستخدم الحالي مدير
$currentRole = $_SESSION['role'] ?? '';
if ($currentRole !== 'مدير') {
    http_response_code(403);
    echo "غير مسموح بالدخول إلى هذه الصفحة.";
    exit;
}

$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$errors  = [];
$success = "";

// جلب user_id المطلوب من GET أو POST
$targetUserId = (int)($_GET['user_id'] ?? ($_POST['user_id'] ?? 0));

// جلب بيانات المستخدم الهدف
$targetUser = null;
if ($targetUserId > 0) {
    $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $targetUserId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$targetUser || $targetUser['role'] !== 'مشرف') {
        $errors[] = "المستخدم غير موجود أو ليس مشرفاً.";
        $targetUser = null;
    }
} else {
    $errors[] = "لم يتم تحديد مستخدم.";
}

// تحميل الصلاحيات الحالية (أو القيم الافتراضية)
$perms = getDefaultUserPermissions();

if ($targetUser) {
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $targetUserId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            foreach ($perms as $k => $v) {
                if (isset($rowPerm[$k])) {
                    $perms[$k] = (int)$rowPerm[$k];
                }
            }
        }
    } catch (Exception $e) {}
}

// معالجة الحفظ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $targetUser) {
    // قراءة checkboxes (إذا لم تُرسل فالقيمة 0)
    $newPerms = [
        'can_view_members'        => isset($_POST['can_view_members']) ? 1 : 0,
        'can_view_trainers'       => isset($_POST['can_view_trainers']) ? 1 : 0,
        'can_view_employees'      => isset($_POST['can_view_employees']) ? 1 : 0,
        'can_view_employee_attendance' => isset($_POST['can_view_employee_attendance']) ? 1 : 0,
        'can_scan_employee_attendance' => isset($_POST['can_scan_employee_attendance']) ? 1 : 0,
        'can_use_employee_attendance_camera' => isset($_POST['can_use_employee_attendance_camera']) ? 1 : 0,
        'can_view_employee_attendance_report' => isset($_POST['can_view_employee_attendance_report']) ? 1 : 0,
        'can_export_employee_attendance_excel' => isset($_POST['can_export_employee_attendance_excel']) ? 1 : 0,
        'can_view_employee_advances' => isset($_POST['can_view_employee_advances']) ? 1 : 0,
        'can_view_employee_payroll' => isset($_POST['can_view_employee_payroll']) ? 1 : 0,
        'can_view_cashier'        => isset($_POST['can_view_cashier']) ? 1 : 0,
        'can_view_sales'          => isset($_POST['can_view_sales']) ? 1 : 0,
        'can_view_items'          => isset($_POST['can_view_items']) ? 1 : 0,
        'can_view_renew_members'  => isset($_POST['can_view_renew_members']) ? 1 : 0,
        'can_view_attendance'     => isset($_POST['can_view_attendance']) ? 1 : 0,
        'can_view_expenses'       => isset($_POST['can_view_expenses']) ? 1 : 0,
        'can_view_stats'          => isset($_POST['can_view_stats']) ? 1 : 0,
        'can_view_settings'       => isset($_POST['can_view_settings']) ? 1 : 0,
        'can_view_closing'        => isset($_POST['can_view_closing']) ? 1 : 0,
    ];

    try {
        // هل يوجد سطر صلاحيات لهذا المستخدم؟
        $stmtCheck = $pdo->prepare("SELECT id FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtCheck->execute([':uid' => $targetUserId]);
        if ($rowC = $stmtCheck->fetch(PDO::FETCH_ASSOC)) {
            // تحديث
            $stmtUpd = $pdo->prepare("
                UPDATE user_permissions
                SET
                    can_view_members       = :m,
                    can_view_trainers      = :tr,
                    can_view_employees     = :em,
                    can_view_employee_attendance = :eat,
                    can_scan_employee_attendance = :escan,
                    can_use_employee_attendance_camera = :ecam,
                    can_view_employee_attendance_report = :ereport,
                    can_export_employee_attendance_excel = :eexcel,
                    can_view_employee_advances = :eav,
                    can_view_employee_payroll = :epa,
                    can_view_cashier       = :ca,
                    can_view_sales         = :sa,
                    can_view_items         = :it,
                    can_view_renew_members = :rm,
                    can_view_attendance    = :att,
                    can_view_expenses      = :ex,
                    can_view_stats         = :st,
                    can_view_settings      = :se,
                    can_view_closing       = :cl
                WHERE user_id = :uid
            ");
        } else {
            // إدخال جديد
            $stmtUpd = $pdo->prepare("
                INSERT INTO user_permissions
                    (user_id, can_view_members, can_view_trainers, can_view_employees, can_view_employee_attendance, can_scan_employee_attendance, can_use_employee_attendance_camera, can_view_employee_attendance_report, can_export_employee_attendance_excel, can_view_employee_advances, can_view_employee_payroll, can_view_cashier, can_view_sales, can_view_items, can_view_renew_members, can_view_attendance,
                     can_view_expenses, can_view_stats, can_view_settings, can_view_closing)
                VALUES
                    (:uid, :m, :tr, :em, :eat, :escan, :ecam, :ereport, :eexcel, :eav, :epa, :ca, :sa, :it, :rm, :att, :ex, :st, :se, :cl)
            ");
        }

        $stmtUpd->execute([
            ':uid' => $targetUserId,
            ':m'   => $newPerms['can_view_members'],
            ':tr'  => $newPerms['can_view_trainers'],
            ':em'  => $newPerms['can_view_employees'],
            ':eat' => $newPerms['can_view_employee_attendance'],
            ':escan' => $newPerms['can_scan_employee_attendance'],
            ':ecam' => $newPerms['can_use_employee_attendance_camera'],
            ':ereport' => $newPerms['can_view_employee_attendance_report'],
            ':eexcel' => $newPerms['can_export_employee_attendance_excel'],
            ':eav' => $newPerms['can_view_employee_advances'],
            ':epa' => $newPerms['can_view_employee_payroll'],
            ':ca'  => $newPerms['can_view_cashier'],
            ':sa'  => $newPerms['can_view_sales'],
            ':it'  => $newPerms['can_view_items'],
            ':rm'  => $newPerms['can_view_renew_members'],
            ':att' => $newPerms['can_view_attendance'],
            ':ex'  => $newPerms['can_view_expenses'],
            ':st'  => $newPerms['can_view_stats'],
            ':se'  => $newPerms['can_view_settings'],
            ':cl'  => $newPerms['can_view_closing'],
        ]);

        $perms   = $newPerms;
        $success = "تم حفظ صلاحيات المستخدم بنجاح.";
    } catch (Exception $e) {
        $errors[] = "حدث خطأ أثناء حفظ الصلاحيات.";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>صلاحيات المستخدمين - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            background: #f3f4f6;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #111827;
        }
        .page {
            max-width: 900px;
            margin: 30px auto;
            padding: 0 16px;
        }
        .header-bar {
            display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;
        }
        .title-main {font-size:24px;font-weight:900;}
        .title-sub  {font-size:14px;color:#6b7280;margin-top:4px;}
        .back-button {
            display:inline-flex;align-items:center;gap:8px;
            padding:10px 18px;border-radius:999px;border:none;
            background:linear-gradient(90deg,#6366f1,#22c55e);
            color:#f9fafb;text-decoration:none;font-weight:800;
            box-shadow:0 10px 30px rgba(79,70,229,0.4);
        }
        .card {
            background:#ffffff;border-radius:20px;padding:20px;
            box-shadow:0 20px 45px rgba(15,23,42,0.15);
        }
        .alert {
            padding:10px 12px;border-radius:10px;font-size:14px;margin-bottom:10px;
        }
        .alert-error {
            background:rgba(239,68,68,0.08);
            border:1px solid rgba(239,68,68,0.8);
            color:#b91c1c;
        }
        .alert-success {
            background:rgba(34,197,94,0.08);
            border:1px solid rgba(34,197,94,0.8);
            color:#166534;
        }
        .perm-group {
            display:grid;
            grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
            gap:12px;
            margin-top:14px;
        }
        .perm-item {
            display:flex;align-items:center;gap:8px;
            padding:8px 10px;border-radius:12px;
            background:#f9fafb;border:1px solid #e5e7eb;
            font-size:14px;
        }
        .perm-item input[type="checkbox"] {
            width:18px;height:18px;
        }
        .btn-save {
            margin-top:18px;
            border:none;border-radius:999px;padding:10px 22px;
            background:#22c55e;color:#f9fafb;font-weight:800;
            cursor:pointer;box-shadow:0 12px 30px rgba(34,197,94,0.4);
        }
        .muted {font-size:13px;color:#6b7280;margin-top:4px;}
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">صلاحيات المستخدمين</div>
            <div class="title-sub">تحديد الصفحات والأزرار التي يمكن للمشرف رؤيتها واستخدامها داخل لوحة التحكم.</div>
        </div>
        <a href="dashboard.php" class="back-button">
            <span>🏠</span>
            <span>العودة للوحة التحكم</span>
        </a>
    </div>

    <div class="card">
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

        <?php if ($targetUser): ?>
            <h3 style="margin-top:0;">المستخدم: <?php echo htmlspecialchars($targetUser['username']); ?> (مشرف)</h3>
            <div class="muted">قم باختيار الصفحات والأزرار التي تظهر لهذا الحساب داخل لوحة التحكم والصفحات الداخلية.</div>

            <form method="post" action="user_permissions.php">
                <input type="hidden" name="user_id" value="<?php echo (int)$targetUser['id']; ?>">

                <div class="perm-group">
                    <label class="perm-item">
                        <input type="checkbox" name="can_view_members" <?php echo $perms['can_view_members'] ? 'checked' : ''; ?>>
                        <span>عرض زر "المشتركين"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_trainers" <?php echo $perms['can_view_trainers'] ? 'checked' : ''; ?>>
                        <span>عرض زر "المدربين"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_employees" <?php echo $perms['can_view_employees'] ? 'checked' : ''; ?>>
                        <span>عرض زر "الموظفين"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_employee_attendance" <?php echo $perms['can_view_employee_attendance'] ? 'checked' : ''; ?>>
                        <span>عرض زر "حضور الموظفين"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_scan_employee_attendance" <?php echo $perms['can_scan_employee_attendance'] ? 'checked' : ''; ?>>
                        <span>عرض زر "تسجيل الحركة" في حضور الموظفين</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_use_employee_attendance_camera" <?php echo $perms['can_use_employee_attendance_camera'] ? 'checked' : ''; ?>>
                        <span>عرض أزرار الكاميرا في حضور الموظفين</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_employee_attendance_report" <?php echo $perms['can_view_employee_attendance_report'] ? 'checked' : ''; ?>>
                        <span>عرض زر "عرض الكشف" وجدول حضور الموظفين</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_export_employee_attendance_excel" <?php echo $perms['can_export_employee_attendance_excel'] ? 'checked' : ''; ?>>
                        <span>عرض زر "تصدير Excel" لحضور الموظفين</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_employee_advances" <?php echo $perms['can_view_employee_advances'] ? 'checked' : ''; ?>>
                        <span>عرض زر "سلف الموظفين"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_employee_payroll" <?php echo $perms['can_view_employee_payroll'] ? 'checked' : ''; ?>>
                        <span>عرض زر "قبض الموظفين"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_cashier" <?php echo $perms['can_view_cashier'] ? 'checked' : ''; ?>>
                        <span>عرض زر "الكاشير"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_sales" <?php echo $perms['can_view_sales'] ? 'checked' : ''; ?>>
                        <span>عرض زر "المبيعات"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_items" <?php echo $perms['can_view_items'] ? 'checked' : ''; ?>>
                        <span>عرض زر "الأصناف"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_renew_members" <?php echo $perms['can_view_renew_members'] ? 'checked' : ''; ?>>
                        <span>عرض زر "تجديد الاشتراكات"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_attendance" <?php echo $perms['can_view_attendance'] ? 'checked' : ''; ?>>
                        <span>عرض زر "حضور المشتركين"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_expenses" <?php echo $perms['can_view_expenses'] ? 'checked' : ''; ?>>
                        <span>عرض زر "المصروفات"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_stats" <?php echo $perms['can_view_stats'] ? 'checked' : ''; ?>>
                        <span>عرض زر "الإحصائيات"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_settings" <?php echo $perms['can_view_settings'] ? 'checked' : ''; ?>>
                        <span>عرض زر "إعدادات الموقع"</span>
                    </label>

                    <label class="perm-item">
                        <input type="checkbox" name="can_view_closing" <?php echo $perms['can_view_closing'] ? 'checked' : ''; ?>>
                        <span>عرض "زر التقفيل" (اليومي/الشهري)</span>
                    </label>
                </div>

                <button type="submit" class="btn-save">💾 حفظ الصلاحيات</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
