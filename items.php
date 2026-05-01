<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'items_helpers.php';
require_once 'user_permissions_helpers.php';

ensureItemsSchema($pdo);
ensureUserPermissionsSchema($pdo);

function redirectItemsPage($status)
{
    header("Location: items.php?status=" . urlencode($status));
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

$isManager = ($role === 'مدير');
$canViewPage = false;

if ($isManager) {
    $canViewPage = true;
} elseif ($role === 'مشرف' && $userId > 0) {
    $defaultPermissions = getDefaultUserPermissions();
    $canViewPage = (int)$defaultPermissions['can_view_items'] === 1;
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $canViewPage = isset($rowPerm['can_view_items']) && ((int)$rowPerm['can_view_items'] === 1);
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
    'added'   => 'تمت إضافة الصنف بنجاح.',
    'updated' => 'تم تعديل الصنف بنجاح.',
    'deleted' => 'تم حذف الصنف بنجاح.',
];
$success = $successMessages[$status] ?? '';

$formData = [
    'action'       => 'add',
    'item_id'      => '',
    'name'         => '',
    'has_quantity' => '0',
    'item_count'   => '',
    'price'        => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        if ($itemId <= 0) {
            $errors[] = "معرّف الصنف غير صحيح.";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM items WHERE id = :id");
                $stmt->execute([':id' => $itemId]);
                redirectItemsPage('deleted');
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء حذف الصنف.";
            }
        }
    } elseif ($action === 'add' || $action === 'edit') {
        $formData['action']       = $action;
        $formData['item_id']      = trim((string)($_POST['item_id'] ?? ''));
        $formData['name']         = trim($_POST['name'] ?? '');
        $formData['has_quantity'] = ($_POST['has_quantity'] ?? '0') === '1' ? '1' : '0';
        $formData['item_count']   = trim((string)($_POST['item_count'] ?? ''));
        $formData['price']        = trim((string)($_POST['price'] ?? ''));

        $itemId = (int)$formData['item_id'];
        $name = $formData['name'];
        $hasQuantity = $formData['has_quantity'] === '1';
        $itemCount = null;
        $price = null;

        if ($name === '') {
            $errors[] = "من فضلك أدخل اسم الصنف.";
        }

        if ($hasQuantity) {
            if ($formData['item_count'] === '' || filter_var($formData['item_count'], FILTER_VALIDATE_INT) === false || (int)$formData['item_count'] <= 0) {
                $errors[] = "من فضلك أدخل عدداً صحيحاً للصنف.";
            } else {
                $itemCount = (int)$formData['item_count'];
            }
        }

        if ($formData['price'] === '' || !is_numeric($formData['price']) || (float)$formData['price'] <= 0) {
            $errors[] = "من فضلك أدخل سعراً صحيحاً للصنف.";
        } else {
            $price = round((float)$formData['price'], 2);
        }

        if ($action === 'edit' && $itemId <= 0) {
            $errors[] = "معرّف الصنف غير صحيح.";
        }

        if (!$errors) {
            try {
                if ($action === 'edit') {
                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM items
                        WHERE name = :name
                          AND id <> :item_id
                        LIMIT 1
                    ");
                    $stmt->execute([
                        ':name'    => $name,
                        ':item_id' => $itemId,
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        SELECT id
                        FROM items
                        WHERE name = :name
                        LIMIT 1
                    ");
                    $stmt->execute([
                        ':name' => $name,
                    ]);
                }

                if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                    $errors[] = "اسم الصنف مسجل بالفعل.";
                } elseif ($action === 'add') {
                    $stmt = $pdo->prepare("
                        INSERT INTO items (name, has_quantity, item_count, price)
                        VALUES (:name, :has_quantity, :item_count, :price)
                    ");
                    $stmt->execute([
                        ':name'         => $name,
                        ':has_quantity' => $hasQuantity ? 1 : 0,
                        ':item_count'   => $hasQuantity ? $itemCount : null,
                        ':price'        => $price,
                    ]);
                    redirectItemsPage('added');
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE items
                        SET
                            name = :name,
                            has_quantity = :has_quantity,
                            item_count = :item_count,
                            price = :price
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':name'         => $name,
                        ':has_quantity' => $hasQuantity ? 1 : 0,
                        ':item_count'   => $hasQuantity ? $itemCount : null,
                        ':price'        => $price,
                        ':id'           => $itemId,
                    ]);
                    redirectItemsPage('updated');
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء حفظ بيانات الصنف.";
            }
        }
    }
}

$items = [];
try {
    $items = getAllItems($pdo);
} catch (Exception $e) {
    $errors[] = "حدث خطأ أثناء تحميل جدول الأصناف.";
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>الأصناف - <?php echo htmlspecialchars($siteName); ?></title>
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
        .page { max-width: 1200px; margin: 30px auto 40px; padding: 0 20px; }
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
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;}
        .field{display:flex;flex-direction:column;gap:6px;}
        .field label{font-size:15px;color:var(--text-muted);font-weight:900;}
        input[type="text"],input[type="number"],select{
            width:100%;padding:10px 12px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:16px;font-weight:800;color:var(--text-main);
        }
        input:focus,select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft);}
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
        .actions{display:flex;gap:6px;flex-wrap:wrap;}
        .table-wrapper{margin-top:18px;border-radius:20px;border:1px solid var(--border);overflow:auto;}
        table{width:100%;border-collapse:collapse;font-size:15px;}
        thead{background:rgba(15,23,42,0.04);}
        body.dark thead{background:rgba(15,23,42,0.9);}
        th,td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;}
        th{font-weight:900;color:var(--text-muted);}
        td{font-weight:800;}
        .empty{padding:20px;text-align:center;color:var(--text-muted);font-weight:800;}
        .hidden-field{display:none;}
        .badge{
            display:inline-flex;align-items:center;justify-content:center;padding:5px 10px;border-radius:999px;
            background:var(--primary-soft);color:var(--primary);font-size:13px;font-weight:900;
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">إدارة الأصناف</div>
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

        <form method="post" action="" id="itemForm">
            <input type="hidden" name="action" id="formAction" value="<?php echo htmlspecialchars($formData['action']); ?>">
            <input type="hidden" name="item_id" id="itemId" value="<?php echo htmlspecialchars($formData['item_id']); ?>">

            <div class="form-grid">
                <div class="field">
                    <label for="name">اسم الصنف</label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($formData['name']); ?>" required>
                </div>

                <div class="field">
                    <label for="has_quantity">هل الصنف له عدد؟</label>
                    <select id="has_quantity" name="has_quantity">
                        <option value="0" <?php echo $formData['has_quantity'] === '0' ? 'selected' : ''; ?>>لا، يتم تسجيل السعر فقط</option>
                        <option value="1" <?php echo $formData['has_quantity'] === '1' ? 'selected' : ''; ?>>نعم، يتم تسجيل العدد بجانب السعر</option>
                    </select>
                </div>

                <div class="field" id="itemCountField">
                    <label for="item_count">عدد الصنف</label>
                    <input type="number" id="item_count" name="item_count" min="1" value="<?php echo htmlspecialchars($formData['item_count']); ?>">
                </div>

                <div class="field" id="priceField">
                    <label for="price">سعر الصنف</label>
                    <input type="number" step="0.01" id="price" name="price" min="0.01" value="<?php echo htmlspecialchars($formData['price']); ?>">
                </div>

                <div class="field" id="addButtonWrap">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn-primary" id="btnAdd">
                        <span>➕</span>
                        <span>إضافة صنف</span>
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
                    <th>اسم الصنف</th>
                    <th>الحالة</th>
                    <th>العدد</th>
                    <th>السعر</th>
                    <th>تاريخ الإضافة</th>
                    <th>إجراءات</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr>
                        <td colspan="7" class="empty">لا توجد أصناف مسجلة حتى الآن.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo (int)$item['id']; ?></td>
                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                            <td>
                                <span class="badge"><?php echo (int)$item['has_quantity'] === 1 ? 'له عدد' : 'سعري'; ?></span>
                            </td>
                            <td><?php echo (int)$item['has_quantity'] === 1 ? (int)$item['item_count'] : '—'; ?></td>
                            <td><?php echo is_null($item['price']) ? '—' : number_format((float)$item['price'], 2); ?></td>
                            <td><?php echo htmlspecialchars(formatAppDateTime12Hour($item['created_at'])); ?></td>
                            <td>
                                <div class="actions">
                                    <button
                                        type="button"
                                        class="btn-edit"
                                        onclick="fillEditForm(
                                            <?php echo (int)$item['id']; ?>,
                                            '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>',
                                            <?php echo (int)$item['has_quantity']; ?>,
                                            <?php echo $item['item_count'] === null ? 'null' : (int)$item['item_count']; ?>,
                                            <?php echo $item['price'] === null ? 'null' : (float)$item['price']; ?>
                                        )"
                                    >تعديل</button>
                                    <form method="post" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذا الصنف؟');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="item_id" value="<?php echo (int)$item['id']; ?>">
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
    const itemIdInput = document.getElementById('itemId');
    const nameInput = document.getElementById('name');
    const hasQuantityInput = document.getElementById('has_quantity');
    const itemCountField = document.getElementById('itemCountField');
    const itemCountInput = document.getElementById('item_count');
    const priceField = document.getElementById('priceField');
    const priceInput = document.getElementById('price');
    const addButtonWrap = document.getElementById('addButtonWrap');
    const editButtons = document.getElementById('editButtons');

    function syncItemMode() {
        const hasQuantity = hasQuantityInput.value === '1';

        itemCountField.classList.toggle('hidden-field', !hasQuantity);
        priceField.classList.remove('hidden-field');

        itemCountInput.disabled = !hasQuantity;
        itemCountInput.required = hasQuantity;

        priceInput.disabled = false;
        priceInput.required = true;
    }

    function fillEditForm(id, name, hasQuantity, itemCount, price) {
        formAction.value = 'edit';
        itemIdInput.value = id;
        nameInput.value = name;
        hasQuantityInput.value = hasQuantity ? '1' : '0';
        itemCountInput.value = itemCount === null ? '' : itemCount;
        priceInput.value = price === null ? '' : price;

        if (addButtonWrap) {
            addButtonWrap.style.display = 'none';
        }
        if (editButtons) {
            editButtons.style.display = 'block';
        }

        syncItemMode();
        nameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function resetFormToAdd() {
        formAction.value = 'add';
        itemIdInput.value = '';
        nameInput.value = '';
        hasQuantityInput.value = '0';
        itemCountInput.value = '';
        priceInput.value = '';

        if (addButtonWrap) {
            addButtonWrap.style.display = 'block';
        }
        if (editButtons) {
            editButtons.style.display = 'none';
        }

        syncItemMode();
    }

    hasQuantityInput.addEventListener('change', syncItemMode);

    <?php if ($formData['action'] === 'edit' && $errors): ?>
    if (addButtonWrap) {
        addButtonWrap.style.display = 'none';
    }
    if (editButtons) {
        editButtons.style.display = 'block';
    }
    <?php endif; ?>

    syncItemMode();
</script>
</body>
</html>
