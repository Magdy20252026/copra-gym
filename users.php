<?php
session_start();

// منع الدخول بدون تسجيل
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

// جلب اسم الموقع
$siteName = "Gym System";
$logoPath = null;

try {
    $stmt = $pdo->query("SELECT site_name, logo_path FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
        $logoPath = $row['logo_path'];
    }
} catch (Exception $e) {}

$username  = $_SESSION['username'] ?? '';
$role      = $_SESSION['role'] ?? '';
$isManager = ($role === 'مدير');

$errors  = [];
$success = "";

// عمليات إضافة / تعديل / حذف
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // إضافة
    if ($action === 'add') {
        $new_username = trim($_POST['username'] ?? '');
        $new_password = trim($_POST['password'] ?? '');
        $new_role     = $_POST['role'] ?? 'مشرف';

        if ($new_username === '' || $new_password === '') {
            $errors[] = "من فضلك أدخل اسم المستخدم وكلمة السر.";
        } elseif (!preg_match('/^\d{4,}$/', $new_password)) {
            $errors[] = "كلمة السر يجب أن تكون 4 أرقام على الأقل.";
        } elseif (!in_array($new_role, ['مدير', 'مشرف'], true)) {
            $errors[] = "الصلاحية غير صحيحة.";
        } else {
            try {
                $check = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
                $check->execute([':u' => $new_username]);
                if ($check->fetch()) {
                    $errors[] = "اسم المستخدم موجود بالفعل.";
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO users (username, password, role) VALUES (:u, MD5(:p), :r)"
                    );
                    $stmt->execute([
                        ':u' => $new_username,
                        ':p' => $new_password,
                        ':r' => $new_role,
                    ]);
                    $success = "تم إضافة المستخدم بنجاح.";
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء إضافة المستخدم.";
            }
        }
    }

    // تعديل
    if ($action === 'edit' && $isManager) {
        $edit_id       = (int)($_POST['id'] ?? 0);
        $edit_username = trim($_POST['username'] ?? '');
        $edit_password = trim($_POST['password'] ?? '');
        $edit_role     = $_POST['role'] ?? 'مشرف';

        if ($edit_id <= 0 || $edit_username === '') {
            $errors[] = "بيانات التعديل غير مكتملة.";
        } elseif ($edit_password !== '' && !preg_match('/^\d{4,}$/', $edit_password)) {
            $errors[] = "كلمة السر يجب أن تكون 4 أرقام على الأقل.";
        } elseif (!in_array($edit_role, ['مدير', 'مشرف'], true)) {
            $errors[] = "الصلاحية غير صحيحة.";
        } else {
            try {
                $check = $pdo->prepare("SELECT id FROM users WHERE username = :u AND id <> :id LIMIT 1");
                $check->execute([
                    ':u'  => $edit_username,
                    ':id' => $edit_id,
                ]);
                if ($check->fetch()) {
                    $errors[] = "اسم المستخدم مستخدم من حساب آخر.";
                } else {
                    if ($edit_password !== '') {
                        $stmt = $pdo->prepare(
                            "UPDATE users SET username = :u, password = MD5(:p), role = :r WHERE id = :id"
                        );
                        $stmt->execute([
                            ':u'  => $edit_username,
                            ':p'  => $edit_password,
                            ':r'  => $edit_role,
                            ':id' => $edit_id,
                        ]);
                    } else {
                        $stmt = $pdo->prepare(
                            "UPDATE users SET username = :u, role = :r WHERE id = :id"
                        );
                        $stmt->execute([
                            ':u'  => $edit_username,
                            ':r'  => $edit_role,
                            ':id' => $edit_id,
                        ]);
                    }
                    $success = "تم تعديل بيانات المستخدم بنجاح.";
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء تعديل المستخدم.";
            }
        }
    }

    // حذف
    if ($action === 'delete' && $isManager) {
        $delete_id = (int)($_POST['id'] ?? 0);

        if ($delete_id <= 0) {
            $errors[] = "معرّف المستخدم غير صحيح.";
        } else {
            try {
                if ($delete_id == ($_SESSION['user_id'] ?? 0)) {
                    $errors[] = "لا يمكنك حذف الحساب الذي سجلت به الدخول.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
                    $stmt->execute([':id' => $delete_id]);
                    $success = "تم حذف المستخدم بنجاح.";
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء حذف المستخدم.";
            }
        }
    }
}

// جلب المستخدمين
$users = [];
try {
    $stmt = $pdo->query("SELECT id, username, role FROM users ORDER BY id DESC");
    $users = $stmt->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة اسم المستخدمين - <?php echo htmlspecialchars($siteName); ?></title>
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
            font-weight: 800; /* سميك أكثر */
            font-size: 18px;  /* حجم أساسي أكبر */
        }

        .page {
            max-width: 1200px;
            margin: 26px auto 40px;
            padding: 0 20px;
        }

        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 22px;
        }

        .title-main {
            font-size: 26px;
            font-weight: 900;
        }

        .title-sub {
            margin-top: 6px;
            font-size: 16px;
            color: var(--text-muted);
            font-weight: 700;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 20px;
            border-radius: 999px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 900;
            background: linear-gradient(90deg, #6366f1, #22c55e);
            color: #f9fafb;
            box-shadow: 0 16px 38px rgba(79,70,229,0.55);
            text-decoration: none;
        }

        .back-button:hover { filter: brightness(1.05); }

        .card {
            background: var(--card-bg);
            border-radius: 26px;
            padding: 22px 22px 24px;
            box-shadow:
                0 22px 60px rgba(15,23,42,0.22),
                0 0 0 1px rgba(255,255,255,0.65);
        }

        /* سويتش الثيم */
        .theme-toggle {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 18px;
        }
        .theme-switch {
            position: relative;
            width: 72px;
            height: 34px;
            border-radius: 999px;
            background: #e5e7eb;
            box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.9);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 8px;
            font-size: 16px;
            color: #6b7280;
            font-weight: 800;
        }
        .theme-switch span { z-index: 2; user-select: none; }
        .theme-thumb {
            position: absolute;
            top: 3px;
            right: 3px;
            width: 26px;
            height: 26px;
            border-radius: 999px;
            background: #facc15;
            box-shadow: 0 4px 10px rgba(250, 204, 21, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: transform .25s ease, background .25s ease, box-shadow .25s.ease;
        }
        body.dark .theme-switch {
            background: #020617;
            box-shadow: inset 0 0 0 1px rgba(30, 64, 175, 0.9);
            color: #e5e7eb;
        }
        body.dark .theme-thumb {
            transform: translateX(-36px);
            background: #0f172a;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.9);
        }

        /* سطر النموذج */
        .form-row-line {
            display: grid;
            grid-template-columns: minmax(0, 2.2fr) minmax(0, 2.2fr) minmax(0, 1.4fr) auto auto;
            gap: 14px;
            align-items: center;
            margin-bottom: 18px;
        }

        @media (max-width: 1000px) {
            .form-row-line {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field label {
            font-size: 16px;
            color: var(--text-muted);
            font-weight: 900;
        }

        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            padding: 13px 14px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: var(--input-bg);
            font-size: 18px;
            font-weight: 800;
            color: var(--text-main);
        }

        input::placeholder,
        select::placeholder {
            font-weight: 700;
            color: #9ca3af;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-soft);
        }

        .btn-primary,
        .btn-save,
        .btn-cancel {
            border-radius: 999px;
            padding: 11px 24px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(90deg, #1d4ed8, #2563eb);
            color: #f9fafb;
            box-shadow: 0 14px 34px rgba(37,99,235,0.55);
        }

        .btn-save {
            background: #4b5563;
            color: #f9fafb;
        }

        .btn-cancel {
            background: #e5e7eb;
            color: #4b5563;
        }

        body.dark .btn-cancel {
            background: #111827;
            color: #e5e7eb;
        }

        .btn-primary:hover,
        .btn-save:hover,
        .btn-cancel:hover { filter: brightness(1.05); }

        /* بحث */
        .search-row {
            margin: 10px 2px 8px;
        }

        .search-row label {
            display: block;
            font-size: 16px;
            color: var(--text-muted);
            margin-bottom: 6px;
            font-weight: 900;
        }

        .search-row input {
            width: 100%;
            padding: 12px 14px;
            border-radius: 999px;
            border: 1px solid var(--border);
            background: var(--input-bg);
            font-size: 18px;
            font-weight: 700;
            color: var(--text-main);
        }

        /* جدول المستخدمين */
        .table-wrapper {
            margin-top: 18px;
            border-radius: 20px;
            border: 1px solid var(--border);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 18px;
        }

        thead {
            background: rgba(15,23,42,0.03);
        }
        body.dark thead {
            background: rgba(15,23,42,0.9);
        }

        th, td {
            padding: 12px 18px;
            border-bottom: 1px solid var(--border);
            text-align: right;
        }

        th {
            font-weight: 900;
            color: var(--text-muted);
        }

        td {
            font-weight: 800;
        }

        .badge-role {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 900;
            background: rgba(129,140,248,0.18);
            color: #4f46e5;
        }
        body.dark .badge-role {
            background: rgba(129,140,248,0.45);
            color: #e0e7ff;
        }

        .actions {
            display: flex;
            gap: 10px;
        }

        .btn-edit-row,
        .btn-delete-row {
            border-radius: 999px;
            padding: 9px 18px;
            border: none;
            cursor: pointer;
            font-size: 15px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            color: #f9fafb;
        }

        .btn-edit-row {
            background: #f59e0b;
        }

        .btn-delete-row {
            background: #ef4444;
        }

        .btn-edit-row:hover,
        .btn-delete-row:hover { filter: brightness(1.06); }

        .empty {
            text-align: center;
            color: var(--text-muted);
            font-size: 16px;
            padding: 18px 0;
            font-weight: 800;
        }

        .alert {
            padding: 11px 13px;
            border-radius: 12px;
            font-size: 16px;
            margin-bottom: 12px;
            font-weight: 800;
        }

        .alert-error {
            background: rgba(239,68,68,0.08);
            border: 1px solid rgba(239,68,68,0.8);
            color: var(--danger);
        }

        .alert-success {
            background: rgba(34,197,94,0.08);
            border: 1px solid rgba(34,197,94,0.8);
            color: var(--accent-green);
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">إدارة اسم المستخدمين</div>
            <div class="title-sub">تحديد الحسابات المسموح لها بالدخول إلى لوحة التحكم.</div>
        </div>
        <div>
            <a href="dashboard.php" class="back-button">
                <span>🏠</span>
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

        <!-- نموذج بالعرض -->
        <form method="post" action="" id="userForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="editId" value="">

            <div class="form-row-line">
                <div class="field">
                    <label for="username">اسم المستخدم</label>
                    <input type="text" id="username" name="username" placeholder="مثال: admin2" required>
                </div>

                <div class="field">
                    <label for="password">كلمة السر</label>
                    <input type="password" id="password" name="password" placeholder="٤ أرقام فأكثر" minlength="4" inputmode="numeric" pattern="\d{4,}">
                </div>

                <div class="field">
                    <label for="role">الصلاحية</label>
                    <select id="role" name="role" required>
                        <option value="مدير">مدير</option>
                        <option value="مشرف" selected>مشرف</option>
                    </select>
                </div>

                <div class="field" style="align-items:flex-start; justify-content:flex-end;">
                    <button type="submit" class="btn-primary" id="btnAdd">
                        <span>➕</span>
                        <span id="btnAddText">إضافة</span>
                    </button>
                </div>

                <div class="field" style="align-items:flex-start; justify-content:flex-end; display:none;" id="editButtons">
                    <button type="submit" class="btn-save" id="btnSave">
                        <span>💾</span>
                        <span>حفظ التعديل</span>
                    </button>
                    <button type="button" class="btn-cancel" onclick="resetFormToAdd()">
                        إلغاء
                    </button>
                </div>
            </div>
        </form>

        <!-- بحث -->
        <div class="search-row">
            <label for="search">بحث</label>
            <input type="text" id="search" placeholder="بحث باسم المستخدم أو الصلاحية...">
        </div>

        <!-- جدول المستخدمين -->
        <div class="table-wrapper">
            <table id="usersTable">
                <thead>
                <tr>
                    <th>اسم المستخدم</th>
                    <th>الصلاحية</th>
                    <th>الإجراءات</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$users): ?>
                    <tr>
                        <td colspan="3" class="empty">لا يوجد مستخدمون مسجلون حتى الآن.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                            <td>
                                <span class="badge-role">
                                    <?php echo htmlspecialchars($u['role']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions">
                                    <?php if ($isManager): ?>
                                        <button
                                            type="button"
                                            class="btn-edit-row"
                                            onclick="fillEditForm(<?php echo (int)$u['id']; ?>,'<?php echo htmlspecialchars($u['username'], ENT_QUOTES); ?>','<?php echo htmlspecialchars($u['role'], ENT_QUOTES); ?>')"
                                        >
                                            ✏️ تعديل
                                        </button>
                                        <form method="post" action="" onsubmit="return confirm('هل أنت متأكد من الحذف؟');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                            <button type="submit" class="btn-delete-row">🗑 حذف</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="font-size:16px;color:var(--text-muted);">بدون صلاحية</span>
                                    <?php endif; ?>
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
    // ثيم داكن/فاتح متوافق مع لوحة التحكم
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

    // تبديل النموذج بين إضافة وتعديل
    function fillEditForm(id, username, role) {
        const formAction = document.getElementById('formAction');
        const editId     = document.getElementById('editId');
        const userInput  = document.getElementById('username');
        const passInput  = document.getElementById('password');
        const roleSelect = document.getElementById('role');
        const btnAdd     = document.getElementById('btnAdd');
        const editBtns   = document.getElementById('editButtons');

        formAction.value = 'edit';
        editId.value     = id;
        userInput.value  = username;
        roleSelect.value = role || 'مشرف';
        passInput.value  = '';

        btnAdd.style.display   = 'none';
        editBtns.style.display = 'flex';

        userInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function resetFormToAdd() {
        const formAction = document.getElementById('formAction');
        const editId     = document.getElementById('editId');
        const userInput  = document.getElementById('username');
        const passInput  = document.getElementById('password');
        const roleSelect = document.getElementById('role');
        const btnAdd     = document.getElementById('btnAdd');
        const editBtns   = document.getElementById('editButtons');

        formAction.value = 'add';
        editId.value     = '';
        userInput.value  = '';
        passInput.value  = '';
        roleSelect.value = 'مشرف';

        btnAdd.style.display   = 'inline-flex';
        editBtns.style.display = 'none';
    }

    // لو لا يوجد أخطاء ولا رسالة نجاح نرجع لوضع الإضافة
    <?php if (!$errors && !$success): ?>
    resetFormToAdd();
    <?php endif; ?>

    // بحث بسيط في الجدول
    const searchInput = document.getElementById('search');
    const table       = document.getElementById('usersTable');
    if (searchInput && table) {
        searchInput.addEventListener('input', function () {
            const value = this.value.toLowerCase();
            const rows  = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const cellsText = row.innerText.toLowerCase();
                row.style.display = cellsText.includes(value) ? '' : 'none';
            });
        });
    }
</script>
</body>
</html>
