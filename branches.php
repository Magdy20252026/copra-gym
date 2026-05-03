<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

$role = $_SESSION['role'] ?? '';
if ($role !== 'مدير') {
    header("Location: dashboard.php");
    exit;
}

$siteName = "Gym System";
$currentBranchName = getCurrentBranchName();
$errors = [];
$success = "";

try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $branchName = trim($_POST['branch_name'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($branchName === '') {
        $errors[] = "من فضلك أدخل اسم الفرع.";
    }

    if ($action === 'add' && !$errors) {
        try {
            $check = $pdo->prepare("SELECT id FROM branches WHERE branch_name = :branch_name LIMIT 1");
            $check->execute([':branch_name' => $branchName]);
            if ($check->fetch()) {
                $errors[] = "اسم الفرع موجود بالفعل.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO branches (branch_name, is_active) VALUES (:branch_name, :is_active)");
                $stmt->execute([
                    ':branch_name' => $branchName,
                    ':is_active' => $isActive,
                ]);
                $success = "تم إضافة الفرع بنجاح.";
            }
        } catch (Exception $e) {
            $errors[] = "حدث خطأ أثناء إضافة الفرع.";
        }
    }

    if ($action === 'edit' && !$errors) {
        $branchId = (int)($_POST['id'] ?? 0);

        if ($branchId <= 0) {
            $errors[] = "بيانات التعديل غير مكتملة.";
        } else {
            try {
                $check = $pdo->prepare("SELECT id FROM branches WHERE branch_name = :branch_name AND id <> :id LIMIT 1");
                $check->execute([
                    ':branch_name' => $branchName,
                    ':id' => $branchId,
                ]);
                if ($check->fetch()) {
                    $errors[] = "اسم الفرع مستخدم بالفعل.";
                } else {
                    branchAwareSetDisabled(true);
                    try {
                        $activeBranchesCount = (int)$pdo->query("SELECT COUNT(*) FROM branches WHERE is_active = 1")->fetchColumn();
                    } finally {
                        branchAwareSetDisabled(false);
                    }
                    $editedBranch = getBranchById($pdo, $branchId);

                    if ((int)($_SESSION['branch_id'] ?? 0) === $branchId && $isActive === 0) {
                        $errors[] = "لا يمكن إيقاف الفرع المحدد حالياً.";
                    } elseif ($editedBranch && (int)$editedBranch['is_active'] === 1 && $isActive === 0 && $activeBranchesCount <= 1) {
                        $errors[] = "يجب إبقاء فرع واحد نشط على الأقل.";
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE branches
                            SET branch_name = :branch_name,
                                is_active = :is_active
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':branch_name' => $branchName,
                            ':is_active' => $isActive,
                            ':id' => $branchId,
                        ]);

                        if ((int)($_SESSION['branch_id'] ?? 0) === $branchId && $isActive === 1) {
                            setActiveBranchSession($branchId, $branchName);
                        }

                        $success = "تم تحديث بيانات الفرع بنجاح.";
                    }
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء تعديل الفرع.";
            }
        }
    }
}

$branches = [];
try {
    $stmt = $pdo->query("
        SELECT
            branches.id,
            branches.branch_name,
            branches.is_active,
            COUNT(users.id) AS assigned_users_count
        FROM branches
        LEFT JOIN users
            ON users.branch_access_scope = 'single'
           AND users.branch_id = branches.id
        GROUP BY branches.id, branches.branch_name, branches.is_active
        ORDER BY branches.branch_name ASC
    ");
    $branches = $stmt->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>إدارة الفروع - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }

        :root {
            --bg: #eef2ff;
            --card-bg: rgba(255,255,255,0.92);
            --text-main: #0f172a;
            --text-muted: #475569;
            --border: #dbe4f0;
            --input-bg: #f8fafc;
            --primary: #2563eb;
            --primary-soft: rgba(37,99,235,0.14);
            --success: #16a34a;
            --danger: #dc2626;
            --shadow: 0 24px 60px rgba(15,23,42,0.18);
        }

        body.dark {
            --bg: #020617;
            --card-bg: rgba(15,23,42,0.92);
            --text-main: #f8fafc;
            --text-muted: #cbd5e1;
            --border: #1e293b;
            --input-bg: #020617;
            --primary: #38bdf8;
            --primary-soft: rgba(56,189,248,0.2);
            --success: #4ade80;
            --danger: #fb7185;
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
            width: min(1200px, 100%);
            margin: 0 auto;
            padding: 18px;
        }

        .header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 18px;
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
            transition: transform .25s ease;
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

        .stack {
            display: grid;
            gap: 18px;
        }

        .card {
            background: var(--card-bg);
            border-radius: 28px;
            border: 1px solid rgba(255,255,255,0.3);
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
            border: 1px solid rgba(220,38,38,0.24);
        }

        .alert-success {
            background: rgba(34,197,94,0.10);
            color: var(--success);
            border: 1px solid rgba(34,197,94,0.24);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .stat-card {
            padding: 18px;
            border-radius: 22px;
            background: rgba(255,255,255,0.2);
            border: 1px solid var(--border);
        }

        .stat-label {
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 900;
        }

        .stat-value {
            margin-top: 10px;
            font-size: 28px;
            font-weight: 900;
        }

        .form-grid {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) auto auto;
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

        input[type="text"] {
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

        input[type="text"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
        }

        .toggle-field {
            min-height: 52px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0 16px;
            border-radius: 18px;
            border: 1px solid var(--border);
            background: var(--input-bg);
        }

        .btn-primary,
        .btn-save,
        .btn-cancel,
        .btn-edit {
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

        .btn-cancel,
        .btn-edit {
            background: rgba(148,163,184,0.16);
            color: var(--text-main);
        }

        .branches-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .branch-card {
            padding: 18px;
            border-radius: 22px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,0.16);
        }

        .branch-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
        }

        .branch-name {
            font-size: 20px;
            font-weight: 900;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 900;
        }

        .badge-active {
            background: rgba(34,197,94,0.14);
            color: var(--success);
        }

        .badge-inactive {
            background: rgba(220,38,38,0.14);
            color: var(--danger);
        }

        .branch-meta {
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 800;
            display: grid;
            gap: 8px;
        }

        .branch-actions {
            margin-top: 16px;
        }

        @media (max-width: 1024px) {
            .stats,
            .branches-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .form-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .page {
                padding: 12px;
            }

            .stats,
            .branches-grid,
            .form-grid {
                grid-template-columns: minmax(0, 1fr);
            }

            .card-body {
                padding: 16px;
            }

            .title-main {
                font-size: 24px;
            }

            .toolbar,
            .btn-primary,
            .btn-save,
            .btn-cancel,
            .btn-edit,
            .back-button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">إدارة الفروع</div>
            <div class="title-sub">إنشاء الفروع وتحديد الفروع النشطة داخل النظام.</div>
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

    <div class="stack">
        <div class="card">
            <div class="card-body">
                <div class="stats">
                    <div class="stat-card">
                        <div class="stat-label">عدد الفروع</div>
                        <div class="stat-value"><?php echo count($branches); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">الفروع النشطة</div>
                        <div class="stat-value"><?php echo count(array_filter($branches, static fn($branch) => (int)$branch['is_active'] === 1)); ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">الفرع الحالي</div>
                        <div class="stat-value" style="font-size:20px;"><?php echo htmlspecialchars($currentBranchName !== '' ? $currentBranchName : 'غير محدد'); ?></div>
                    </div>
                </div>
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

                <form method="post" id="branchForm">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="branchId" value="">

                    <div class="form-grid">
                        <div class="field">
                            <label for="branch_name">اسم الفرع</label>
                            <input type="text" id="branch_name" name="branch_name" placeholder="اسم الفرع" required>
                        </div>

                        <div class="field">
                            <label for="is_active_toggle">الحالة</label>
                            <label class="toggle-field" for="is_active_toggle">
                                <input type="checkbox" id="is_active_toggle" name="is_active" checked>
                                <span>فرع نشط</span>
                            </label>
                        </div>

                        <button type="submit" class="btn-primary" id="btnAdd">
                            <span>➕</span>
                            <span>إضافة فرع</span>
                        </button>

                        <div style="display:flex; gap:10px; flex-wrap:wrap;" id="editActions">
                            <button type="submit" class="btn-save" id="btnSave" style="display:none;">
                                <span>💾</span>
                                <span>حفظ التعديل</span>
                            </button>
                            <button type="button" class="btn-cancel" id="btnCancel" style="display:none;">إلغاء</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="branches-grid">
            <?php foreach ($branches as $branch): ?>
                <div class="card branch-card">
                    <div class="branch-head">
                        <div class="branch-name"><?php echo htmlspecialchars($branch['branch_name']); ?></div>
                        <span class="badge <?php echo (int)$branch['is_active'] === 1 ? 'badge-active' : 'badge-inactive'; ?>">
                            <?php echo (int)$branch['is_active'] === 1 ? 'نشط' : 'غير نشط'; ?>
                        </span>
                    </div>
                    <div class="branch-meta">
                        <div>المستخدمون المرتبطون بهذا الفرع: <?php echo (int)$branch['assigned_users_count']; ?></div>
                        <div><?php echo $currentBranchName === $branch['branch_name'] ? 'الفرع المحدد حالياً' : 'فرع متاح للاختيار'; ?></div>
                    </div>
                    <div class="branch-actions">
                        <button
                            type="button"
                            class="btn-edit"
                            data-id="<?php echo (int)$branch['id']; ?>"
                            data-name="<?php echo htmlspecialchars($branch['branch_name'], ENT_QUOTES); ?>"
                            data-active="<?php echo (int)$branch['is_active']; ?>"
                        >
                            ✏️ تعديل
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
    const body = document.body;
    const themeSwitch = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';
    const formAction = document.getElementById('formAction');
    const branchId = document.getElementById('branchId');
    const branchName = document.getElementById('branch_name');
    const isActiveToggle = document.getElementById('is_active_toggle');
    const btnAdd = document.getElementById('btnAdd');
    const btnSave = document.getElementById('btnSave');
    const btnCancel = document.getElementById('btnCancel');

    function applyTheme(mode) {
        body.classList.toggle('dark', mode === 'dark');
        localStorage.setItem('gymDashboardTheme', mode);
    }

    function resetForm() {
        formAction.value = 'add';
        branchId.value = '';
        branchName.value = '';
        isActiveToggle.checked = true;
        btnAdd.style.display = 'inline-flex';
        btnSave.style.display = 'none';
        btnCancel.style.display = 'none';
    }

    function fillForm(button) {
        formAction.value = 'edit';
        branchId.value = button.dataset.id || '';
        branchName.value = button.dataset.name || '';
        isActiveToggle.checked = button.dataset.active === '1';
        btnAdd.style.display = 'none';
        btnSave.style.display = 'inline-flex';
        btnCancel.style.display = 'inline-flex';
        branchName.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    applyTheme(savedTheme);

    if (themeSwitch) {
        themeSwitch.addEventListener('click', () => {
            applyTheme(body.classList.contains('dark') ? 'light' : 'dark');
        });
    }

    btnCancel.addEventListener('click', resetForm);
    document.querySelectorAll('.btn-edit').forEach((button) => {
        button.addEventListener('click', () => fillForm(button));
    });

    <?php if (!$errors && !$success): ?>
    resetForm();
    <?php endif; ?>
</script>
</body>
</html>
