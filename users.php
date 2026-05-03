<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

$siteName = "Gym System";
$logoPath = null;

try {
    $stmt = $pdo->query("SELECT site_name, logo_path FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
        $logoPath = $row['logo_path'];
    }
} catch (Exception $e) {}

$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? '';
$isManager = ($role === 'مدير');

if (!$isManager) {
    header("Location: dashboard.php");
    exit;
}

$errors = [];
$success = "";
$branches = getBranches($pdo, false);
$branchesById = [];
foreach ($branches as $branch) {
    $branchesById[(int)$branch['id']] = $branch;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $formUsername = trim($_POST['username'] ?? '');
    $formPassword = trim($_POST['password'] ?? '');
    $formRole = $_POST['role'] ?? 'مشرف';
    $formScope = normalizeBranchAccessScope($_POST['branch_access_scope'] ?? 'all');
    $formBranchId = $formScope === 'single' ? (int)($_POST['branch_id'] ?? 0) : null;

    if ($formUsername === '') {
        $errors[] = "من فضلك أدخل اسم المستخدم.";
    }

    if (!in_array($formRole, ['مدير', 'مشرف'], true)) {
        $errors[] = "الصلاحية غير صحيحة.";
    }

    if ($formScope === 'single' && (!isset($branchesById[$formBranchId]) || (int)$branchesById[$formBranchId]['is_active'] !== 1)) {
        $errors[] = "من فضلك اختر فرعاً نشطاً للمستخدم.";
    }

    if ($action === 'add') {
        if ($formPassword === '') {
            $errors[] = "من فضلك أدخل كلمة السر.";
        } elseif (!preg_match('/^\d{4,}$/', $formPassword)) {
            $errors[] = "كلمة السر يجب أن تكون 4 أرقام على الأقل.";
        }

        if (!$errors) {
            try {
                $check = $pdo->prepare("SELECT id FROM users WHERE username = :username LIMIT 1");
                $check->execute([':username' => $formUsername]);
                if ($check->fetch()) {
                    $errors[] = "اسم المستخدم موجود بالفعل.";
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO users (username, password, role, branch_access_scope, branch_id)
                        VALUES (:username, MD5(:password), :role, :branch_access_scope, :branch_id)
                    ");
                    $stmt->execute([
                        ':username' => $formUsername,
                        ':password' => $formPassword,
                        ':role' => $formRole,
                        ':branch_access_scope' => $formScope,
                        ':branch_id' => $formScope === 'single' ? $formBranchId : null,
                    ]);
                    $success = "تم إضافة المستخدم بنجاح.";
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء إضافة المستخدم.";
            }
        }
    }

    if ($action === 'edit') {
        $editId = (int)($_POST['id'] ?? 0);

        if ($editId <= 0) {
            $errors[] = "بيانات التعديل غير مكتملة.";
        } elseif ($formPassword !== '' && !preg_match('/^\d{4,}$/', $formPassword)) {
            $errors[] = "كلمة السر يجب أن تكون 4 أرقام على الأقل.";
        }

        if (!$errors) {
            try {
                $check = $pdo->prepare("SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1");
                $check->execute([
                    ':username' => $formUsername,
                    ':id' => $editId,
                ]);
                if ($check->fetch()) {
                    $errors[] = "اسم المستخدم مستخدم من حساب آخر.";
                } else {
                    if ($formPassword !== '') {
                        $stmt = $pdo->prepare("
                            UPDATE users
                            SET username = :username,
                                password = MD5(:password),
                                role = :role,
                                branch_access_scope = :branch_access_scope,
                                branch_id = :branch_id
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':username' => $formUsername,
                            ':password' => $formPassword,
                            ':role' => $formRole,
                            ':branch_access_scope' => $formScope,
                            ':branch_id' => $formScope === 'single' ? $formBranchId : null,
                            ':id' => $editId,
                        ]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE users
                            SET username = :username,
                                role = :role,
                                branch_access_scope = :branch_access_scope,
                                branch_id = :branch_id
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':username' => $formUsername,
                            ':role' => $formRole,
                            ':branch_access_scope' => $formScope,
                            ':branch_id' => $formScope === 'single' ? $formBranchId : null,
                            ':id' => $editId,
                        ]);
                    }
                    $success = "تم تعديل بيانات المستخدم بنجاح.";
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء تعديل المستخدم.";
            }
        }
    }

    if ($action === 'delete') {
        $deleteId = (int)($_POST['id'] ?? 0);

        if ($deleteId <= 0) {
            $errors[] = "معرّف المستخدم غير صحيح.";
        } elseif ($deleteId === (int)($_SESSION['user_id'] ?? 0)) {
            $errors[] = "لا يمكنك حذف الحساب الذي سجلت به الدخول.";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
                $stmt->execute([':id' => $deleteId]);
                $success = "تم حذف المستخدم بنجاح.";
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء حذف المستخدم.";
            }
        }
    }
}

$users = [];
try {
    $stmt = $pdo->query("
        SELECT
            users.id,
            users.username,
            users.role,
            users.branch_access_scope,
            users.branch_id,
            branches.branch_name
        FROM users
        LEFT JOIN branches ON branches.id = users.branch_id
        ORDER BY users.id DESC
    ");
    $users = $stmt->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>إدارة المستخدمين - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }

        :root {
            --bg: #eef2ff;
            --card-bg: #ffffff;
            --panel-bg: rgba(255,255,255,0.88);
            --text-main: #0f172a;
            --text-muted: #475569;
            --border: #dbe4f0;
            --input-bg: #f8fafc;
            --primary: #2563eb;
            --primary-soft: rgba(37,99,235,0.12);
            --success: #16a34a;
            --danger: #dc2626;
            --warning: #f59e0b;
            --shadow: 0 24px 60px rgba(15,23,42,0.16);
        }

        body.dark {
            --bg: #020617;
            --card-bg: #0f172a;
            --panel-bg: rgba(15,23,42,0.92);
            --text-main: #f8fafc;
            --text-muted: #cbd5e1;
            --border: #1e293b;
            --input-bg: #020617;
            --primary: #38bdf8;
            --primary-soft: rgba(56,189,248,0.18);
            --success: #4ade80;
            --danger: #fb7185;
            --warning: #fbbf24;
            --shadow: 0 24px 60px rgba(0,0,0,0.45);
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: radial-gradient(circle at top, var(--bg), #dbeafe 120%);
            color: var(--text-main);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 700;
        }

        body.dark {
            background: radial-gradient(circle at top, #0f172a, #020617 72%);
        }

        .page {
            width: min(1280px, 100%);
            margin: 0 auto;
            padding: 18px;
        }

        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .title-main {
            font-size: 30px;
            font-weight: 900;
        }

        .title-sub {
            margin-top: 6px;
            color: var(--text-muted);
            font-size: 15px;
            font-weight: 800;
        }

        .toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .theme-switch,
        .back-button {
            border: none;
            border-radius: 999px;
            cursor: pointer;
            font: inherit;
        }

        .theme-switch {
            position: relative;
            width: 68px;
            height: 34px;
            padding: 0 8px;
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            background: #e2e8f0;
            color: #64748b;
            box-shadow: inset 0 0 0 1px rgba(148,163,184,0.7);
        }

        .theme-switch span { z-index: 2; }

        .theme-thumb {
            position: absolute;
            top: 4px;
            right: 4px;
            width: 26px;
            height: 26px;
            border-radius: 999px;
            background: #facc15;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform .25s ease, background .25s ease;
        }

        body.dark .theme-switch {
            background: #020617;
            color: #e2e8f0;
        }

        body.dark .theme-thumb {
            transform: translateX(-34px);
            background: #0f172a;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 18px;
            text-decoration: none;
            color: #ffffff;
            background: linear-gradient(135deg, #2563eb, #22c55e);
            box-shadow: 0 16px 34px rgba(37,99,235,0.35);
        }

        .card {
            background: var(--panel-bg);
            border: 1px solid rgba(255,255,255,0.32);
            border-radius: 28px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(16px);
            overflow: hidden;
        }

        .card-body {
            padding: 22px;
        }

        .alerts {
            display: grid;
            gap: 10px;
            margin-bottom: 18px;
        }

        .alert {
            padding: 12px 14px;
            border-radius: 16px;
            font-size: 15px;
            font-weight: 800;
        }

        .alert-error {
            background: rgba(220,38,38,0.10);
            color: var(--danger);
            border: 1px solid rgba(220,38,38,0.26);
        }

        .alert-success {
            background: rgba(34,197,94,0.10);
            color: var(--success);
            border: 1px solid rgba(34,197,94,0.26);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 14px;
            align-items: end;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field label {
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 900;
        }

        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            min-height: 52px;
            padding: 13px 16px;
            border-radius: 18px;
            border: 1px solid var(--border);
            background: var(--input-bg);
            color: var(--text-main);
            font-size: 16px;
            font-weight: 800;
            font-family: inherit;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
        }

        .field-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-primary,
        .btn-save,
        .btn-cancel,
        .btn-edit-row,
        .btn-delete-row {
            min-height: 50px;
            padding: 12px 20px;
            border-radius: 18px;
            border: none;
            cursor: pointer;
            font: inherit;
            font-size: 15px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-primary,
        .btn-save {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
        }

        .btn-cancel {
            background: rgba(148,163,184,0.16);
            color: var(--text-main);
        }

        .search-row {
            margin-top: 20px;
        }

        .table-shell {
            margin-top: 18px;
            border: 1px solid var(--border);
            border-radius: 22px;
            overflow: hidden;
        }

        .table-scroll {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        thead {
            background: rgba(15,23,42,0.04);
        }

        body.dark thead {
            background: rgba(255,255,255,0.03);
        }

        th, td {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border);
            text-align: right;
            vertical-align: middle;
        }

        th {
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 900;
        }

        td {
            font-size: 15px;
            font-weight: 800;
        }

        .badge-role,
        .badge-branch {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 13px;
            font-weight: 900;
        }

        .badge-role {
            background: rgba(37,99,235,0.14);
            color: var(--primary);
        }

        .badge-branch {
            background: rgba(34,197,94,0.14);
            color: var(--success);
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-edit-row {
            background: rgba(245,158,11,0.18);
            color: var(--warning);
        }

        .btn-delete-row {
            background: rgba(220,38,38,0.14);
            color: var(--danger);
        }

        .empty {
            padding: 20px;
            text-align: center;
            color: var(--text-muted);
            font-size: 15px;
            font-weight: 800;
        }

        @media (max-width: 1120px) {
            .grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .page {
                padding: 12px;
            }

            .grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .card-body {
                padding: 16px;
            }

            .title-main {
                font-size: 24px;
            }

            .toolbar,
            .field-actions,
            .actions {
                width: 100%;
            }

            .back-button,
            .btn-primary,
            .btn-save,
            .btn-cancel,
            .btn-edit-row,
            .btn-delete-row {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">إدارة المستخدمين</div>
            <div class="title-sub">تحديد صلاحية كل مستخدم على جميع الفروع أو على فرع واحد.</div>
        </div>
        <div class="toolbar">
            <button type="button" class="theme-switch" id="themeSwitch">
                <span>🌙</span>
                <span>☀️</span>
                <span class="theme-thumb">☀️</span>
            </button>
            <a href="dashboard.php" class="back-button">
                <span>🏠</span>
                <span>لوحة التحكم</span>
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <?php if ($errors || $success): ?>
                <div class="alerts">
                    <?php foreach ($errors as $error): ?>
                        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="post" id="userForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="editId" value="">

                <div class="grid">
                    <div class="field">
                        <label for="username">اسم المستخدم</label>
                        <input type="text" id="username" name="username" placeholder="اسم المستخدم" required>
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

                    <div class="field">
                        <label for="branch_access_scope">وصول الفروع</label>
                        <select id="branch_access_scope" name="branch_access_scope" required>
                            <option value="all" selected>كل الفروع</option>
                            <option value="single">فرع واحد</option>
                        </select>
                    </div>

                    <div class="field" id="branchField" style="display:none;">
                        <label for="branch_id">الفرع المحدد</label>
                        <select id="branch_id" name="branch_id">
                            <option value="">اختر الفرع</option>
                            <?php foreach ($branches as $branch): ?>
                                <?php if ((int)$branch['is_active'] !== 1) { continue; } ?>
                                <option value="<?php echo (int)$branch['id']; ?>"><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field-actions" style="margin-top:16px;">
                    <button type="submit" class="btn-primary" id="btnAdd">
                        <span>➕</span>
                        <span>إضافة مستخدم</span>
                    </button>
                    <button type="submit" class="btn-save" id="btnSave" style="display:none;">
                        <span>💾</span>
                        <span>حفظ التعديل</span>
                    </button>
                    <button type="button" class="btn-cancel" id="btnCancel" style="display:none;">إلغاء</button>
                </div>
            </form>

            <div class="search-row">
                <div class="field">
                    <label for="search">بحث</label>
                    <input type="text" id="search" placeholder="بحث باسم المستخدم أو الصلاحية أو الفرع">
                </div>
            </div>

            <div class="table-shell">
                <div class="table-scroll">
                    <table id="usersTable">
                        <thead>
                        <tr>
                            <th>اسم المستخدم</th>
                            <th>الصلاحية</th>
                            <th>وصول الفروع</th>
                            <th>الإجراءات</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$users): ?>
                            <tr>
                                <td colspan="4" class="empty">لا يوجد مستخدمون حتى الآن.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <?php
                                $scopeLabel = normalizeBranchAccessScope($user['branch_access_scope'] ?? 'all') === 'all'
                                    ? 'كل الفروع'
                                    : ((string)($user['branch_name'] ?? '') !== '' ? $user['branch_name'] : 'فرع واحد');
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><span class="badge-role"><?php echo htmlspecialchars($user['role']); ?></span></td>
                                    <td><span class="badge-branch"><?php echo htmlspecialchars($scopeLabel); ?></span></td>
                                    <td>
                                        <div class="actions">
                                            <button
                                                type="button"
                                                class="btn-edit-row"
                                                data-id="<?php echo (int)$user['id']; ?>"
                                                data-username="<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>"
                                                data-role="<?php echo htmlspecialchars($user['role'], ENT_QUOTES); ?>"
                                                data-scope="<?php echo htmlspecialchars(normalizeBranchAccessScope($user['branch_access_scope'] ?? 'all'), ENT_QUOTES); ?>"
                                                data-branch-id="<?php echo (int)($user['branch_id'] ?? 0); ?>"
                                            >
                                                ✏️ تعديل
                                            </button>
                                            <form method="post" onsubmit="return confirm('هل أنت متأكد من حذف المستخدم؟');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$user['id']; ?>">
                                                <button type="submit" class="btn-delete-row">🗑 حذف</button>
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
    </div>
</div>

<script>
    const body = document.body;
    const themeSwitch = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';
    const formAction = document.getElementById('formAction');
    const editId = document.getElementById('editId');
    const userInput = document.getElementById('username');
    const passInput = document.getElementById('password');
    const roleSelect = document.getElementById('role');
    const scopeSelect = document.getElementById('branch_access_scope');
    const branchField = document.getElementById('branchField');
    const branchSelect = document.getElementById('branch_id');
    const btnAdd = document.getElementById('btnAdd');
    const btnSave = document.getElementById('btnSave');
    const btnCancel = document.getElementById('btnCancel');
    const searchInput = document.getElementById('search');
    const table = document.getElementById('usersTable');

    function applyTheme(mode) {
        body.classList.toggle('dark', mode === 'dark');
        localStorage.setItem('gymDashboardTheme', mode);
    }

    function syncBranchField() {
        const singleScope = scopeSelect.value === 'single';
        branchField.style.display = singleScope ? 'flex' : 'none';
        branchSelect.required = singleScope;
        if (!singleScope) {
            branchSelect.value = '';
        }
    }

    function resetFormToAdd() {
        formAction.value = 'add';
        editId.value = '';
        userInput.value = '';
        passInput.value = '';
        roleSelect.value = 'مشرف';
        scopeSelect.value = 'all';
        branchSelect.value = '';
        syncBranchField();
        btnAdd.style.display = 'inline-flex';
        btnSave.style.display = 'none';
        btnCancel.style.display = 'none';
    }

    function fillEditForm(button) {
        formAction.value = 'edit';
        editId.value = button.dataset.id || '';
        userInput.value = button.dataset.username || '';
        passInput.value = '';
        roleSelect.value = button.dataset.role || 'مشرف';
        scopeSelect.value = button.dataset.scope || 'all';
        branchSelect.value = button.dataset.branchId || '';
        syncBranchField();
        btnAdd.style.display = 'none';
        btnSave.style.display = 'inline-flex';
        btnCancel.style.display = 'inline-flex';
        userInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    applyTheme(savedTheme);
    syncBranchField();

    if (themeSwitch) {
        themeSwitch.addEventListener('click', () => {
            applyTheme(body.classList.contains('dark') ? 'light' : 'dark');
        });
    }

    scopeSelect.addEventListener('change', syncBranchField);
    btnCancel.addEventListener('click', resetFormToAdd);

    document.querySelectorAll('.btn-edit-row').forEach((button) => {
        button.addEventListener('click', () => fillEditForm(button));
    });

    searchInput.addEventListener('input', function () {
        const value = this.value.toLowerCase();
        table.querySelectorAll('tbody tr').forEach((row) => {
            row.style.display = row.innerText.toLowerCase().includes(value) ? '' : 'none';
        });
    });

    <?php if (!$errors && !$success): ?>
    resetFormToAdd();
    <?php endif; ?>
</script>
</body>
</html>
