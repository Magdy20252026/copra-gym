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
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$username  = $_SESSION['username'] ?? '';
$role      = $_SESSION['role'] ?? '';

/*
 * الصلاحيات:
 * - المدير: إضافة + تعديل + حذف + عرض الجدول
 * - المشرف: إضافة فقط (لا تعديل ولا حذف ولا عرض الجدول)
 * - غير ذلك: بدون إضافة/تعديل/حذف/عرض
 */
$isManager = ($role === 'مدير');
$isSupervisor = ($role === 'مشرف');
$canAddExpenses = ($isManager || $isSupervisor);
$canViewExpensesTable = $isManager;

$errors  = [];
$success = "";

// معالجة POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // إضافة مصروف (مسموح للمدير والمشرف)
    if ($action === 'add' && $canAddExpenses) {
        $expenseId   = 0; // لا يتم استخدامه في الإضافة
        $expenseDate = trim($_POST['expense_date'] ?? '');
        $item        = trim($_POST['item'] ?? '');
        $amount      = (float)($_POST['amount'] ?? 0);

        if ($expenseDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
            $errors[] = "من فضلك أدخل تاريخاً صحيحاً للمصروف (YYYY-MM-DD).";
        }
        if ($item === '') {
            $errors[] = "من فضلك أدخل بند المصروف.";
        }
        if ($amount <= 0) {
            $errors[] = "من فضلك أدخل مبلغاً صحيحاً للمصروف.";
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO expenses (expense_date, item, amount, created_by)
                    VALUES (:d, :i, :a, :uid)
                ");
                $stmt->execute([
                    ':d'   => $expenseDate,
                    ':i'   => $item,
                    ':a'   => $amount,
                    ':uid' => (int)$_SESSION['user_id'],
                ]);
                $success = "تم إضافة المصروف بنجاح.";
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء حفظ بيانات المصروف.";
            }
        }
    }

    // تعديل مصروف (مسموح للمدير فقط)
    if ($action === 'edit' && $isManager) {
        $expenseId   = (int)($_POST['expense_id'] ?? 0);
        $expenseDate = trim($_POST['expense_date'] ?? '');
        $item        = trim($_POST['item'] ?? '');
        $amount      = (float)($_POST['amount'] ?? 0);

        if ($expenseDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
            $errors[] = "من فضلك أدخل تاريخاً صحيحاً للمصروف (YYYY-MM-DD).";
        }
        if ($item === '') {
            $errors[] = "من فضلك أدخل بند المصروف.";
        }
        if ($amount <= 0) {
            $errors[] = "من فضلك أدخل مبلغاً صحيحاً للمصروف.";
        }

        if (!$errors) {
            try {
                if ($expenseId <= 0) {
                    $errors[] = "معرّف المصروف غير صحيح.";
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE expenses
                        SET expense_date = :d,
                            item         = :i,
                            amount       = :a
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':d'  => $expenseDate,
                        ':i'  => $item,
                        ':a'  => $amount,
                        ':id' => $expenseId,
                    ]);
                    $success = "تم تعديل المصروف بنجاح.";
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء حفظ بيانات المصروف.";
            }
        }
    }

    // حذف مصروف (مسموح للمدير فقط)
    if ($action === 'delete' && $isManager) {
        $expenseId = (int)($_POST['expense_id'] ?? 0);
        if ($expenseId <= 0) {
            $errors[] = "معرّف المصروف غير صحيح.";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = :id");
                $stmt->execute([':id' => $expenseId]);
                $success = "تم حذف المصروف بنجاح.";
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء حذف المصروف.";
            }
        }
    }
}

// فلتر بتاريخ اليوم افتراضياً
$dateFilter = $_GET['date'] ?? date('Y-m-d');

// جلب المصروفات (للمدير فقط)
$expenses = [];
if ($canViewExpensesTable) {
    try {
        if ($dateFilter !== '') {
            $stmt = $pdo->prepare("
                SELECT e.*, u.username
                FROM expenses e
                LEFT JOIN users u ON u.id = e.created_by
                WHERE e.expense_date = :d
                ORDER BY e.id DESC
            ");
            $stmt->execute([':d' => $dateFilter]);
        } else {
            $stmt = $pdo->query("
                SELECT e.*, u.username
                FROM expenses e
                LEFT JOIN users u ON u.id = e.created_by
                ORDER BY e.expense_date DESC, e.id DESC
            ");
        }
        $expenses = $stmt->fetchAll();
    } catch (Exception $e) {
        $errors[] = "حدث خطأ أثناء جلب بيانات المصروفات.";
    }
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>المصروفات - <?php echo htmlspecialchars($siteName); ?></title>
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
        .page { max-width: 1200px; margin: 30px auto 40px; padding: 0 20px; }
        .header-bar { display:flex;justify-content:space-between;align-items:center;margin-bottom:24px; }
        .title-main{font-size:28px;font-weight:900;}
        .back-button{
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            padding:11px 22px;border-radius:999px;border:none;cursor:pointer;
            font-size:16px;font-weight:900;
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 16px 38px rgba(79,70,229,0.55);text-decoration:none;
        }
        .back-button:hover{filter:brightness(1.05);}
        .card{
            background:var(--card-bg);border-radius:24px;padding:20px 22px 22px;
            box-shadow:0 22px 60px rgba(15,23,42,0.22),0 0 0 1px rgba(255,255,255,0.6);
            margin-bottom:18px;
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
        .filter-row{display:flex;align-items:center;gap:10px;margin-bottom:12px;flex-wrap:wrap;}
        .field{display:flex;flex-direction:column;gap:6px;margin-bottom:8px;}
        .field label{font-size:15px;color:var(--text-muted);font-weight:900;}
        input[type="date"],input[type="text"],input[type="number"]{
            padding:9px 12px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:16px;font-weight:800;color:var(--text-main);
        }
        input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft);}
        .btn-primary{
            border-radius:999px;padding:9px 18px;border:none;cursor:pointer;font-size:15px;
            font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:6px;
            background:linear-gradient(90deg,#22c55e,#16a34a);color:#f9fafb;
            box-shadow:0 14px 32px rgba(22,163,74,0.7);text-decoration:none;
        }
        .btn-primary:hover{filter:brightness(1.06);}
        .btn-danger{
            border-radius:999px;padding:7px 14px;border:none;cursor:pointer;font-size:14px;
            font-weight:900;background:#ef4444;color:#f9fafb;
        }
        .btn-edit{
            border-radius:999px;padding:7px 14px;border:none;cursor:pointer;font-size:14px;
            font-weight:900;background:#f59e0b;color:#f9fafb;
        }
        .btn-danger:hover,.btn-edit:hover{filter:brightness(1.06);}
        .table-wrapper{margin-top:10px;border-radius:20px;border:1px solid var(--border);overflow:auto;max-height:480px;}
        table{width:100%;border-collapse:collapse;font-size:15px;}
        thead{background:rgba(15,23,42,0.04);}
        body.dark thead{background:rgba(15,23,42,0.9);}
        th,td{padding:9px 11px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;}
        th{font-weight:900;color:var(--text-muted);}
        td{font-weight:800;}
        .small-muted{font-size:13px;color:var(--text-muted);font-weight:700;}
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div class="title-main">إدارة المصروفات اليومية</div>
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

        <div class="filter-row">
            <form method="get" action="" style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;">
                <div class="field">
                    <label for="date">عرض مصروفات يوم</label>
                    <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($dateFilter); ?>">
                </div>
                <button type="submit" class="btn-primary">
                    <span>🔎</span>
                    <span>عرض</span>
                </button>
            </form>
        </div>

        <?php if (!$canAddExpenses): ?>
            <div class="alert alert-error">
                لا تملك صلاحية تسجيل المصروفات (الصلاحية المطلوبة: مدير أو مشرف).
            </div>
        <?php elseif ($isSupervisor): ?>
            <div class="alert alert-info">
                يمكنك إضافة المصروفات فقط، أما جدول المصروفات فيظهر للمدير فقط.
            </div>
        <?php endif; ?>

        <?php if ($canAddExpenses): ?>
            <!-- نموذج إضافة مصروف (فقط، لا تعديل للمشرف) -->
            <form method="post" action="" id="expenseForm" style="margin-top:10px;">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="expense_id" id="expenseId" value="">

                <div style="display:flex;flex-wrap:wrap;gap:10px;">
                    <div class="field">
                        <label for="expense_date">تاريخ المصروف</label>
                        <input type="date" id="expense_date" name="expense_date"
                               value="<?php echo htmlspecialchars($dateFilter); ?>" required>
                    </div>

                    <div class="field" style="flex:1 1 260px;">
                        <label for="item">البند / وصف المصروف</label>
                        <input type="text" id="item" name="item" required>
                    </div>

                    <div class="field">
                        <label for="amount">المبلغ</label>
                        <input type="number" step="0.01" id="amount" name="amount" min="0.01" required>
                    </div>

                    <div class="field" style="align-self:flex-end;">
                        <button type="submit" class="btn-primary" id="btnSave">
                            <span>➕</span>
                            <span id="btnSaveText">إضافة مصروف</span>
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($canViewExpensesTable): ?>
            <div class="table-wrapper" style="margin-top:16px;">
                <table>
                    <thead>
                    <tr>
                        <th>#</th>
                        <th>التاريخ</th>
                        <th>البند</th>
                        <th>المبلغ</th>
                        <th>مسجّل بواسطة</th>
                        <?php if ($isManager): ?>
                            <th>إجراءات</th>
                        <?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$expenses): ?>
                        <tr>
                            <td colspan="<?php echo $isManager ? 6 : 5; ?>" style="text-align:center;color:var(--text-muted);font-weight:800;">
                                لا توجد مصروفات مسجلة لهذا اليوم.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php
                        $total = 0;
                        foreach ($expenses as $ex):
                            $total += (float)$ex['amount'];
                        ?>
                            <tr>
                                <td><?php echo (int)$ex['id']; ?></td>
                                <td><?php echo htmlspecialchars($ex['expense_date']); ?></td>
                                <td><?php echo htmlspecialchars($ex['item']); ?></td>
                                <td><?php echo number_format($ex['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($ex['username'] ?? ''); ?></td>
                                <?php if ($isManager): ?>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn-edit"
                                            onclick="fillEditForm(
                                                <?php echo (int)$ex['id']; ?>,
                                                '<?php echo htmlspecialchars($ex['expense_date'], ENT_QUOTES); ?>',
                                                '<?php echo htmlspecialchars($ex['item'], ENT_QUOTES); ?>',
                                                <?php echo (float)$ex['amount']; ?>
                                            )"
                                        >تعديل</button>

                                        <form method="post" action="" style="display:inline-block;margin-right:4px;"
                                              onsubmit="return confirm('هل أنت متأكد من حذف هذا المصروف؟');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="expense_id" value="<?php echo (int)$ex['id']; ?>">
                                            <button type="submit" class="btn-danger">حذف</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td colspan="<?php echo $isManager ? 3 : 2; ?>" style="text-align:left;font-weight:900;">
                                إجمالي مصروفات اليوم:
                            </td>
                            <td colspan="<?php echo $isManager ? 3 : 3; ?>" style="font-weight:900;">
                                <?php echo number_format($total, 2); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
    const body       = document.body;
    const switchEl   = document.getElementById('themeSwitch');
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

    // وظائف التعديل (للمدير فقط)
    <?php if ($isManager): ?>
    function fillEditForm(id, date, item, amount) {
        const formAction = document.getElementById('formAction');
        const expenseId  = document.getElementById('expenseId');
        const dateInput  = document.getElementById('expense_date');
        const itemInput  = document.getElementById('item');
        const amountInput= document.getElementById('amount');
        const btnSaveText= document.getElementById('btnSaveText');

        formAction.value = 'edit';
        expenseId.value  = id;
        dateInput.value  = date;
        itemInput.value  = item;
        amountInput.value= amount;

        btnSaveText.textContent = 'حفظ التعديل';
    }
    <?php endif; ?>
</script>
</body>
</html>
