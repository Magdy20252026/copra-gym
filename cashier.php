<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'sales_helpers.php';
require_once 'cashier_helpers.php';
require_once 'user_permissions_helpers.php';

ensureCashierSchema($pdo);
ensureSalesSchema($pdo);
ensureUserPermissionsSchema($pdo);

function redirectCashierPage($status, array $params = [], $fragment = '')
{
    $params = array_merge(['status' => $status], $params);
    $target = "cashier.php?" . http_build_query($params);
    if ($fragment !== '') {
        $target .= '#' . rawurlencode(ltrim($fragment, '#'));
    }
    header("Location: " . $target);
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
$today    = date('Y-m-d');

$isManager    = ($role === 'مدير');
$isSupervisor = ($role === 'مشرف');
$canViewPage  = false;

if ($isManager) {
    $canViewPage = true;
} elseif ($isSupervisor && $userId > 0) {
    $defaultPermissions = getDefaultUserPermissions();
    $canViewPage = (int)$defaultPermissions['can_view_cashier'] === 1;
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $canViewPage = isset($rowPerm['can_view_cashier']) && ((int)$rowPerm['can_view_cashier'] === 1);
        }
    } catch (Exception $e) {
        $canViewPage = false;
    }
}

if (!$canViewPage) {
    header("Location: dashboard.php");
    exit;
}

$errors  = [];
$status  = $_GET['status'] ?? '';
$successMessages = [
    'cashier_added' => 'تمت إضافة الكاشير بنجاح.',
    'cashier_updated' => 'تم تعديل بيانات الكاشير بنجاح.',
    'cashier_deleted' => 'تم حذف الكاشير بنجاح.',
];
$success = $successMessages[$status] ?? '';
$formData = [
    'action' => 'add_cashier',
    'cashier_id' => '',
    'name' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_cashier') {
        $cashierId = (int)($_POST['cashier_id'] ?? 0);

        if ($cashierId <= 0) {
            $errors[] = "معرّف الكاشير غير صحيح.";
        } else {
            try {
                $cashier = getCashierById($pdo, $cashierId);
                if (!$cashier) {
                    $errors[] = "الكاشير المحدد غير موجود.";
                } elseif (getCashierSalesCount($pdo, $cashierId) > 0) {
                    $errors[] = "لا يمكن حذف الكاشير لأنه مرتبط بعمليات مبيعات مسجلة.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM cashiers WHERE id = :id");
                    $stmt->execute([':id' => $cashierId]);
                    redirectCashierPage('cashier_deleted');
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء حذف بيانات الكاشير.";
            }
        }
    } elseif ($action === 'add_cashier' || $action === 'edit_cashier') {
        $formData['action'] = $action;
        $formData['cashier_id'] = trim((string)($_POST['cashier_id'] ?? ''));
        $formData['name'] = trim((string)($_POST['name'] ?? ''));

        $cashierId = (int)$formData['cashier_id'];

        if ($formData['name'] === '') {
            $errors[] = "من فضلك أدخل اسم الكاشير.";
        }
        if ($action === 'edit_cashier' && $cashierId <= 0) {
            $errors[] = "معرّف الكاشير غير صحيح.";
        }

        if (!$errors) {
            try {
                if ($action === 'edit_cashier' && !getCashierById($pdo, $cashierId)) {
                    $errors[] = "الكاشير المحدد غير موجود.";
                }

                if (!$errors) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) AS c
                        FROM cashiers
                        WHERE name = :name
                          AND id <> :cashier_id
                    ");
                    $stmt->execute([
                        ':name' => $formData['name'],
                        ':cashier_id' => $cashierId,
                    ]);

                    if ((int)$stmt->fetch()['c'] > 0) {
                        $errors[] = "اسم الكاشير مسجل بالفعل.";
                    } elseif ($action === 'edit_cashier') {
                        $stmt = $pdo->prepare("UPDATE cashiers SET name = :name WHERE id = :id");
                        $stmt->execute([
                            ':name' => $formData['name'],
                            ':id' => $cashierId,
                        ]);
                        redirectCashierPage('cashier_updated');
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO cashiers (name) VALUES (:name)");
                        $stmt->execute([':name' => $formData['name']]);
                        redirectCashierPage('cashier_added');
                    }
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء حفظ بيانات الكاشير.";
            }
        }
    }
}

$cashiers = [];
try {
    $cashiers = getAllCashiersWithDailyBalance($pdo);
} catch (Exception $e) {
    $errors[] = "حدث خطأ أثناء تحميل جدول الكاشير.";
}

$selectedCashierId = (int)($_GET['history_cashier_id'] ?? 0);
$selectedCashier = null;
$previousBalances = [];

if ($selectedCashierId > 0) {
    try {
        $selectedCashier = getCashierById($pdo, $selectedCashierId);
        if ($selectedCashier) {
            $previousBalances = getCashierBalanceHistory($pdo, $selectedCashierId);
        }
    } catch (Exception $e) {
        $errors[] = "حدث خطأ أثناء تحميل تفاصيل أرصدة الأيام السابقة.";
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>الكاشير - <?php echo htmlspecialchars($siteName); ?></title>
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
        input[type="text"], input[type="number"] {
            width:100%;padding:11px 13px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:16px;font-weight:800;color:var(--text-main);
        }
        .table-wrapper {
            margin-top:12px;
            border-radius:22px;
            border:1px solid transparent;
            overflow:auto;
            max-height:420px;
            background:
                linear-gradient(var(--card-bg), var(--card-bg)) padding-box,
                linear-gradient(135deg, rgba(37,99,235,0.18), rgba(34,197,94,0.18)) border-box;
            box-shadow: inset 0 0 0 1px rgba(148,163,184,0.16), 0 18px 35px rgba(15,23,42,0.08);
            scrollbar-width: thin;
            scrollbar-color: rgba(34,197,94,0.95) rgba(148,163,184,0.18);
            scrollbar-gutter: stable both-edges;
        }
        .table-wrapper::-webkit-scrollbar { width:12px; height:12px; }
        .table-wrapper::-webkit-scrollbar-track {
            background: rgba(148,163,184,0.16);
            border-radius: 999px;
        }
        .table-wrapper::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #22c55e, #2563eb);
            border-radius: 999px;
            border: 2px solid rgba(255,255,255,0.92);
        }
        body.dark .table-wrapper::-webkit-scrollbar-track { background: rgba(30,41,59,0.95); }
        body.dark .table-wrapper::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #22c55e, #38bdf8);
            border-color: rgba(2,6,23,0.95);
        }
        table { width:100%; min-width:720px; border-collapse:collapse; font-size:16px; }
        thead { background:rgba(15,23,42,0.04); }
        body.dark thead { background:rgba(15,23,42,0.95); }
        th, td { padding:10px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap; }
        th { font-weight:900;color:var(--text-muted);font-size:15px; }
        td { font-weight:800;font-size:15px; }
        .alert { padding:12px 14px;border-radius:14px;font-size:16px;margin-bottom:14px;font-weight:900; }
        .alert-error { background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.9);color:var(--danger); }
        .alert-success { background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.9);color:var(--accent); }
        .empty-note { color: var(--text-muted); font-size: 15px; }
        .amount { color: var(--accent); }
        .actions, .form-actions { display:flex; flex-wrap:wrap; gap:8px; }
        .btn-edit, .btn-danger, .btn-cancel {
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            border:none;cursor:pointer;text-decoration:none;
            border-radius:999px;padding:10px 16px;font-size:14px;font-weight:900;
        }
        .btn-edit { background:linear-gradient(90deg,#2563eb,#38bdf8); color:#f9fafb; box-shadow:0 12px 24px rgba(37,99,235,0.30); }
        .btn-danger { background:linear-gradient(90deg,#ef4444,#f97316); color:#f9fafb; box-shadow:0 12px 24px rgba(239,68,68,0.28); }
        .btn-cancel { background:linear-gradient(90deg,#64748b,#334155); color:#f9fafb; box-shadow:0 12px 24px rgba(51,65,85,0.28); }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">الكاشير</div>
            <div class="subtitle">مستخدم مسجل: <?php echo htmlspecialchars($username); ?> — الصلاحية: <?php echo htmlspecialchars($role); ?></div>
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

        <form method="post" action="" id="cashierForm">
            <input type="hidden" name="action" id="formAction" value="<?php echo htmlspecialchars($formData['action']); ?>">
            <input type="hidden" name="cashier_id" id="cashierId" value="<?php echo htmlspecialchars($formData['cashier_id']); ?>">
            <div class="grid">
                <div class="field">
                    <label for="cashier_name">اسم الكاشير</label>
                    <input type="text" id="cashier_name" name="name" value="<?php echo htmlspecialchars($formData['name']); ?>" required>
                </div>
                <div class="field" id="addButtonWrap">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn-main">
                        <span>💾</span>
                        <span>حفظ اسم الكاشير</span>
                    </button>
                </div>
                <div class="field" id="editButtons" style="display:none;">
                    <label>&nbsp;</label>
                    <div class="form-actions">
                        <button type="submit" class="btn-main">
                            <span>💾</span>
                            <span>حفظ التعديل</span>
                        </button>
                        <button type="button" class="btn-cancel" onclick="resetCashierForm()">إلغاء</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="title-main" style="font-size:24px;">جدول الكاشير المسجلين</div>
        <div class="subtitle">رصيد اليوم يُحتسب تلقائياً من عمليات المبيعات والمرتجع، لذلك لا يتم تسجيله يدوياً.</div>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>اسم الكاشير</th>
                    <th>رصيد اليوم</th>
                    <th>التفاصيل</th>
                    <th>الإجراءات</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$cashiers): ?>
                    <tr>
                        <td colspan="4" class="empty-note">لا يوجد كاشير مسجل حالياً.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($cashiers as $cashier): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cashier['name']); ?></td>
                            <td class="amount"><?php echo number_format((float)$cashier['daily_balance'], 2); ?></td>
                            <td>
                                <a class="btn-secondary" href="cashier.php?history_cashier_id=<?php echo (int)$cashier['id']; ?>#historyCard">
                                    <span>📅</span>
                                    <span>تفاصيل رصيد الأيام السابقة</span>
                                </a>
                            </td>
                            <td>
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="btn-edit"
                                        data-id="<?php echo (int)$cashier['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($cashier['name'], ENT_QUOTES); ?>"
                                        onclick="fillCashierForm(this)"
                                    >تعديل</button>
                                    <form method="post" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذا الكاشير؟');">
                                        <input type="hidden" name="action" value="delete_cashier">
                                        <input type="hidden" name="cashier_id" value="<?php echo (int)$cashier['id']; ?>">
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

    <div class="card" id="historyCard">
        <div class="title-main" style="font-size:24px;">تفاصيل رصيد الأيام السابقة</div>
        <?php if (!$selectedCashier): ?>
            <p class="empty-note">اختر الكاشير من الجدول أعلاه لعرض تفاصيل أرصدة الأيام السابقة.</p>
        <?php else: ?>
            <div class="subtitle" style="margin-bottom:10px;">
                الكاشير: <?php echo htmlspecialchars($selectedCashier['name']); ?> — ستظهر تفاصيل الأيام السابقة تلقائيا بعد برمجة المبيعات.
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>اليوم</th>
                        <th>رصيد اليوم</th>
                        <th>آخر تحديث</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$previousBalances): ?>
                        <tr>
                            <td colspan="3" class="empty-note">لا توجد أرصدة محسوبة للأيام السابقة حالياً لأن المبيعات لم تتم برمجتها بعد.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($previousBalances as $balance): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($balance['balance_date']); ?></td>
                                <td class="amount"><?php echo number_format((float)$balance['balance_amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($balance['updated_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const body = document.body;
    const switchEl = document.getElementById('themeSwitch');
    const formAction = document.getElementById('formAction');
    const cashierIdInput = document.getElementById('cashierId');
    const cashierNameInput = document.getElementById('cashier_name');
    const addButtonWrap = document.getElementById('addButtonWrap');
    const editButtons = document.getElementById('editButtons');
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

    function fillCashierForm(button) {
        const data = button.dataset;
        formAction.value = 'edit_cashier';
        cashierIdInput.value = data.id || '';
        cashierNameInput.value = data.name || '';

        if (addButtonWrap) {
            addButtonWrap.style.display = 'none';
        }
        if (editButtons) {
            editButtons.style.display = 'block';
        }

        cashierNameInput.focus();
        cashierNameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function resetCashierForm() {
        formAction.value = 'add_cashier';
        cashierIdInput.value = '';
        cashierNameInput.value = '';

        if (addButtonWrap) {
            addButtonWrap.style.display = 'block';
        }
        if (editButtons) {
            editButtons.style.display = 'none';
        }
    }

    <?php if ($formData['action'] === 'edit_cashier' && $errors): ?>
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
