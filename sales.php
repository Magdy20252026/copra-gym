<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'items_helpers.php';
require_once 'cashier_helpers.php';
require_once 'sales_helpers.php';
require_once 'user_permissions_helpers.php';

ensureItemsSchema($pdo);
ensureCashierSchema($pdo);
ensureSalesSchema($pdo);
ensureUserPermissionsSchema($pdo);

function redirectSalesPage($status)
{
    header("Location: sales.php?status=" . urlencode($status));
    exit;
}

$siteName = 'Gym System';
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
$today    = date('Y-m-d');

$isManager = ($role === 'مدير');
$canViewPage = false;

if ($isManager) {
    $canViewPage = true;
} elseif ($role === 'مشرف' && $userId > 0) {
    $defaultPermissions = getDefaultUserPermissions();
    $canViewPage = (int)$defaultPermissions['can_view_sales'] === 1;
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $canViewPage = isset($rowPerm['can_view_sales']) && ((int)$rowPerm['can_view_sales'] === 1);
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
    'invoice_added' => 'تم تسجيل الفاتورة بنجاح.',
];
$validatedStatus = isset($successMessages[$status]) ? $status : '';
$success = $validatedStatus ? $successMessages[$validatedStatus] : '';

$formData = [
    'cashier_id'       => '',
    'transaction_type' => 'بيع',
    'lines'            => [
        ['item_id' => '', 'quantity' => '1'],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData['cashier_id'] = trim((string)($_POST['cashier_id'] ?? ''));
    $formData['transaction_type'] = ($_POST['transaction_type'] ?? 'بيع') === 'مرتجع' ? 'مرتجع' : 'بيع';

    $postedItemIds = $_POST['item_id'] ?? [];
    $postedQuantities = $_POST['quantity'] ?? [];
    if (!is_array($postedItemIds)) {
        $postedItemIds = [$postedItemIds];
    }
    if (!is_array($postedQuantities)) {
        $postedQuantities = [$postedQuantities];
    }

    $lineCount = max(count($postedItemIds), count($postedQuantities));
    $formData['lines'] = [];
    for ($i = 0; $i < $lineCount; $i++) {
        $formData['lines'][] = [
            'item_id'  => trim((string)($postedItemIds[$i] ?? '')),
            'quantity' => trim((string)($postedQuantities[$i] ?? '1')),
        ];
    }
    if (!$formData['lines']) {
        $formData['lines'][] = ['item_id' => '', 'quantity' => '1'];
    }

    $cashierId = (int)$formData['cashier_id'];
    $transactionType = $formData['transaction_type'];
    $invoiceLines = [];
    $itemsCache = [];
    $requiredSaleQuantities = [];
    $returnQuantities = [];

    if ($cashierId <= 0) {
        $errors[] = 'من فضلك اختر الكاشير.';
    }

    foreach ($formData['lines'] as $index => $line) {
        $lineNumber = $index + 1;
        $itemIdRaw = $line['item_id'];
        $quantityRaw = $line['quantity'];

        if ($itemIdRaw === '' && $quantityRaw === '') {
            continue;
        }

        $itemId = (int)$itemIdRaw;
        $quantity = filter_var($quantityRaw, FILTER_VALIDATE_INT);

        if ($itemId <= 0) {
            $errors[] = 'من فضلك اختر الصنف في السطر رقم ' . $lineNumber . '.';
            continue;
        }
        if ($quantity === false || (int)$quantity <= 0) {
            $errors[] = 'من فضلك أدخل عدداً صحيحاً في السطر رقم ' . $lineNumber . '.';
            continue;
        }

        if (!isset($itemsCache[$itemId])) {
            $itemStmt = $pdo->prepare("SELECT id, name, has_quantity, item_count, price FROM items WHERE id = :id LIMIT 1");
            $itemStmt->execute([':id' => $itemId]);
            $itemsCache[$itemId] = $itemStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $item = $itemsCache[$itemId];
        if (!$item) {
            $errors[] = 'الصنف المحدد في السطر رقم ' . $lineNumber . ' غير موجود.';
            continue;
        }

        $unitPrice = round((float)($item['price'] ?? 0), 2);
        if ($unitPrice <= 0) {
            $errors[] = 'سعر الصنف غير صحيح في السطر رقم ' . $lineNumber . '.';
            continue;
        }

        $quantity = (int)$quantity;
        $hasQuantity = isset($item['has_quantity']) && (int)$item['has_quantity'] === 1;
        $currentStock = $hasQuantity ? (int)($item['item_count'] ?? 0) : null;
        $lineTotal = round($unitPrice * $quantity, 2);

        $invoiceLines[] = [
            'item_id'       => (int)$item['id'],
            'item_name'     => $item['name'],
            'quantity'      => $quantity,
            'unit_price'    => $unitPrice,
            'total_amount'  => $lineTotal,
            'has_quantity'  => $hasQuantity,
            'current_stock' => $currentStock,
        ];

        if ($hasQuantity) {
            if ($transactionType === 'بيع') {
                $requiredSaleQuantities[$itemId] = ($requiredSaleQuantities[$itemId] ?? 0) + $quantity;
            } else {
                $returnQuantities[$itemId] = ($returnQuantities[$itemId] ?? 0) + $quantity;
            }
        }
    }

    if (!$invoiceLines) {
        $errors[] = 'من فضلك أضف صنفاً واحداً على الأقل داخل الفاتورة.';
    }

    $cashier = null;
    if (!$errors) {
        $cashierStmt = $pdo->prepare("SELECT id, name FROM cashiers WHERE id = :id LIMIT 1");
        $cashierStmt->execute([':id' => $cashierId]);
        $cashier = $cashierStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$cashier) {
            $errors[] = 'الكاشير المحدد غير موجود.';
        }
    }

    if (!$errors && $transactionType === 'بيع') {
        foreach ($requiredSaleQuantities as $itemId => $requiredQuantity) {
            $item = $itemsCache[$itemId] ?? null;
            $currentStock = $item && isset($item['item_count']) ? (int)$item['item_count'] : 0;
            if ($item && (int)$item['has_quantity'] === 1 && $currentStock < $requiredQuantity) {
                $errors[] = 'الكمية المطلوبة للصنف "' . $item['name'] . '" غير متاحة في المخزون.';
            }
        }
    }

    if (!$errors) {
        try {
            $invoiceNumber = generateSalesInvoiceNumber();
            $pdo->beginTransaction();

            if ($transactionType === 'بيع') {
                foreach ($requiredSaleQuantities as $itemId => $requiredQuantity) {
                    $updateStock = $pdo->prepare("
                        UPDATE items
                        SET item_count = item_count - :quantity
                        WHERE id = :id AND item_count >= :quantity
                    ");
                    $updateStock->execute([
                        ':quantity' => $requiredQuantity,
                        ':id'       => $itemId,
                    ]);
                    if ($updateStock->rowCount() === 0) {
                        $itemName = isset($itemsCache[$itemId]['name']) ? $itemsCache[$itemId]['name'] : 'الصنف';
                        throw new RuntimeException('نفدت الكمية المتاحة للصنف "' . $itemName . '".');
                    }
                }
            } else {
                foreach ($returnQuantities as $itemId => $returnQuantity) {
                    $updateStock = $pdo->prepare("
                        UPDATE items
                        SET item_count = COALESCE(item_count, 0) + :quantity
                        WHERE id = :id
                    ");
                    $updateStock->execute([
                        ':quantity' => $returnQuantity,
                        ':id'       => $itemId,
                    ]);
                }
            }

            $insert = $pdo->prepare("
                INSERT INTO sales (
                    sale_date,
                    invoice_number,
                    transaction_type,
                    item_id,
                    item_name,
                    quantity,
                    unit_price,
                    total_amount,
                    cashier_id,
                    cashier_name,
                    created_by_user_id
                ) VALUES (
                    :sale_date,
                    :invoice_number,
                    :transaction_type,
                    :item_id,
                    :item_name,
                    :quantity,
                    :unit_price,
                    :total_amount,
                    :cashier_id,
                    :cashier_name,
                    :created_by_user_id
                )
            ");

            foreach ($invoiceLines as $invoiceLine) {
                $insert->execute([
                    ':sale_date'          => $today,
                    ':invoice_number'     => $invoiceNumber,
                    ':transaction_type'   => $transactionType,
                    ':item_id'            => $invoiceLine['item_id'],
                    ':item_name'          => $invoiceLine['item_name'],
                    ':quantity'           => $invoiceLine['quantity'],
                    ':unit_price'         => $invoiceLine['unit_price'],
                    ':total_amount'       => $invoiceLine['total_amount'],
                    ':cashier_id'         => $cashierId,
                    ':cashier_name'       => $cashier['name'],
                    ':created_by_user_id' => $userId,
                ]);
            }

            $pdo->commit();
            redirectSalesPage('invoice_added');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'حدث خطأ أثناء تسجيل الفاتورة.';
        }
    }
}

$items = [];
$cashiers = [];
$todaySales = [];
$todaySummary = [
    'operations_count'        => 0,
    'sales_operations_count'  => 0,
    'return_operations_count' => 0,
    'gross_sales_amount'      => 0.0,
    'total_return_amount'     => 0.0,
    'net_sales_amount'        => 0.0,
];

try {
    $itemsStmt = $pdo->query("SELECT id, name, has_quantity, item_count, price FROM items ORDER BY name ASC, id ASC");
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $cashiersStmt = $pdo->query("SELECT id, name FROM cashiers ORDER BY name ASC, id ASC");
    $cashiers = $cashiersStmt->fetchAll(PDO::FETCH_ASSOC);

    $todaySales = getAllSalesForDate($pdo, $today);
    $todaySummary = getSalesSummary($pdo, $today, $today, true);
} catch (Exception $e) {
    $errors[] = 'حدث خطأ أثناء تحميل بيانات المبيعات.';
}

if (!$formData['lines']) {
    $formData['lines'][] = ['item_id' => '', 'quantity' => '1'];
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>المبيعات - <?php echo htmlspecialchars($siteName); ?></title>
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
            --accent-red: #ef4444;
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
            --accent-red: #fb7185;
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
        .page { max-width: 1380px; margin: 30px auto 50px; padding: 0 22px; }
        .header-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; gap:12px; flex-wrap:wrap; }
        .title-main { font-size: 30px; font-weight: 900; }
        .subtitle { color: var(--text-muted); font-size: 15px; margin-top: 4px; }
        .back-button, .btn-main, .btn-print, .btn-secondary, .btn-remove {
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            border:none;cursor:pointer;text-decoration:none;border-radius:999px;
            padding:12px 22px;font-size:16px;font-weight:900;
        }
        .back-button { background:linear-gradient(90deg,#6366f1,#22c55e); color:#f9fafb; box-shadow:0 18px 40px rgba(79,70,229,0.55); }
        .btn-main { background:linear-gradient(90deg,#22c55e,#16a34a); color:#f9fafb; box-shadow:0 18px 40px rgba(22,163,74,0.55); }
        .btn-secondary { background:linear-gradient(90deg,#0ea5e9,#2563eb); color:#f9fafb; box-shadow:0 18px 40px rgba(14,165,233,0.45); }
        .btn-print { background:linear-gradient(90deg,#0ea5e9,#2563eb); color:#f9fafb; padding:8px 14px; font-size:14px; }
        .btn-remove { background:linear-gradient(90deg,#ef4444,#dc2626); color:#f9fafb; padding:10px 16px; font-size:14px; }
        .btn-remove[disabled] { opacity:.45; cursor:not-allowed; box-shadow:none; }
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
        .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
        .field { display:flex; flex-direction:column; gap:6px; margin-bottom:10px; }
        .field label { font-size:15px; color:var(--text-muted); font-weight:900; }
        input[type="text"], input[type="number"], select {
            width:100%;padding:11px 13px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:16px;font-weight:800;color:var(--text-main);
        }
        input[readonly] { opacity: 0.85; }
        .summary-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap:14px; margin-bottom:18px; }
        .summary-card { border-radius:18px;border:1px solid var(--border);padding:14px 16px;background:rgba(15,23,42,0.02); }
        body.dark .summary-card { background:rgba(15,23,42,0.35); }
        .summary-label { font-size:14px; color:var(--text-muted); margin-bottom:6px; }
        .summary-value { font-size:26px; font-weight:900; }
        .alert { padding:12px 14px;border-radius:14px;font-size:16px;margin-bottom:14px;font-weight:900; }
        .alert-error { background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.9);color:var(--accent-red); }
        .alert-success { background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.9);color:var(--accent-green); }
        .table-wrapper { margin-top:12px;border-radius:22px;border:1px solid var(--border);overflow:auto; }
        table { width:100%;border-collapse:collapse;font-size:16px; }
        thead { background:rgba(15,23,42,0.04); }
        body.dark thead { background:rgba(15,23,42,0.95); }
        th, td { padding:10px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap; }
        th { font-weight:900;color:var(--text-muted);font-size:15px; }
        td { font-weight:800;font-size:15px; }
        .empty-note, .helper-note { color: var(--text-muted); font-size: 15px; }
        .warning { color: var(--accent-red); font-size: 14px; margin-top: 4px; }
        .type-badge {
            display:inline-flex;align-items:center;justify-content:center;min-width:72px;
            padding:4px 10px;border-radius:999px;font-size:13px;font-weight:900;color:#fff;
        }
        .type-sale { background:#16a34a; }
        .type-return { background:#dc2626; }
        .invoice-lines { display:flex; flex-direction:column; gap:12px; margin-top:16px; }
        .invoice-row { border:1px solid var(--border); border-radius:22px; padding:14px; background:rgba(15,23,42,0.015); }
        body.dark .invoice-row { background:rgba(15,23,42,0.25); }
        .invoice-row-grid { grid-template-columns: minmax(240px,2fr) minmax(120px,1fr) minmax(130px,1fr) minmax(130px,1fr) auto; align-items:end; }
        .invoice-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:16px; }
        .invoice-total-box { margin-top:16px; max-width:320px; }
        .row-note { margin-top:4px; }
        .row-warning { margin-top:4px; min-height:20px; }
        @media (max-width: 768px) {
            .page { padding: 0 14px; }
            .header-bar { align-items:flex-start; }
            .invoice-row-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">المبيعات</div>
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

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">صافي مبيعات اليوم بعد المرتجعات</div>
                <div class="summary-value"><?php echo number_format((float)$todaySummary['net_sales_amount'], 2); ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">عدد فواتير البيع اليوم</div>
                <div class="summary-value"><?php echo (int)$todaySummary['sales_operations_count']; ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">عدد فواتير المرتجع اليوم</div>
                <div class="summary-value"><?php echo (int)$todaySummary['return_operations_count']; ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-label">إجمالي المرتجعات اليوم</div>
                <div class="summary-value"><?php echo number_format((float)$todaySummary['total_return_amount'], 2); ?></div>
            </div>
        </div>

        <form method="post" action="" id="salesForm" autocomplete="off">
            <div class="grid">
                <div class="field">
                    <label for="cashier_id">الكاشير</label>
                    <select id="cashier_id" name="cashier_id" required>
                        <option value="">اختر الكاشير</option>
                        <?php foreach ($cashiers as $cashier): ?>
                            <option value="<?php echo (int)$cashier['id']; ?>" <?php echo ((string)$cashier['id'] === $formData['cashier_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cashier['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="transaction_type">نوع العملية</label>
                    <select id="transaction_type" name="transaction_type" required>
                        <option value="بيع" <?php echo $formData['transaction_type'] === 'بيع' ? 'selected' : ''; ?>>بيع</option>
                        <option value="مرتجع" <?php echo $formData['transaction_type'] === 'مرتجع' ? 'selected' : ''; ?>>مرتجع</option>
                    </select>
                </div>
            </div>

            <div class="subtitle" style="margin-top:16px;">يمكنك إضافة أكثر من صنف داخل نفس الفاتورة.</div>

            <div class="invoice-lines" id="invoiceLines">
                <?php foreach ($formData['lines'] as $lineIndex => $line): ?>
                    <div class="invoice-row" data-row>
                        <div class="grid invoice-row-grid">
                            <div class="field">
                                <label>الصنف</label>
                                <select name="item_id[]" class="item-select" required>
                                    <option value="">اختر الصنف</option>
                                    <?php foreach ($items as $item): ?>
                                        <?php
                                            $hasQuantity = (int)($item['has_quantity'] ?? 0) === 1;
                                            $stockValue = $hasQuantity ? (int)($item['item_count'] ?? 0) : '';
                                        ?>
                                        <option
                                            value="<?php echo (int)$item['id']; ?>"
                                            data-price="<?php echo htmlspecialchars((string)round((float)($item['price'] ?? 0), 2)); ?>"
                                            data-stock="<?php echo htmlspecialchars((string)$stockValue); ?>"
                                            data-has-quantity="<?php echo $hasQuantity ? '1' : '0'; ?>"
                                            <?php echo ((string)$item['id'] === $line['item_id']) ? 'selected' : ''; ?>
                                        >
                                            <?php echo htmlspecialchars($item['name']); ?>
                                            <?php if ($hasQuantity): ?>
                                                — المتاح: <?php echo (int)$item['item_count']; ?>
                                            <?php else: ?>
                                                — بدون رصيد عددي
                                            <?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label>العدد</label>
                                <input type="number" name="quantity[]" class="quantity-input" min="1" step="1" value="<?php echo htmlspecialchars($line['quantity']); ?>" required>
                            </div>
                            <div class="field">
                                <label>سعر الوحدة</label>
                                <input type="text" class="unit-price-input" value="0.00" readonly>
                            </div>
                            <div class="field">
                                <label>إجمالي السطر</label>
                                <input type="text" class="line-total-input" value="0.00" readonly>
                            </div>
                            <div class="field">
                                <label>&nbsp;</label>
                                <button type="button" class="btn-remove remove-line-btn">حذف السطر</button>
                            </div>
                        </div>
                        <div class="helper-note row-note">اختر الصنف لمعرفة الرصيد المتاح وسعر الوحدة.</div>
                        <div class="warning row-warning"></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="invoice-actions">
                <button type="button" class="btn-secondary" id="addLineBtn">
                    <span>➕</span>
                    <span>إضافة صنف جديد</span>
                </button>
            </div>

            <div class="invoice-total-box">
                <div class="field">
                    <label for="invoice_total">إجمالي الفاتورة</label>
                    <input type="text" id="invoice_total" value="0.00" readonly>
                </div>
            </div>

            <div class="warning" id="invoiceWarning"></div>

            <div class="form-actions">
                <button type="submit" class="btn-main" id="saveInvoiceBtn">
                    <span>💾</span>
                    <span>تسجيل الفاتورة</span>
                </button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="title-main" style="font-size:24px;">سجل فواتير اليوم</div>
        <div class="subtitle">هنا تظهر كل فواتير البيع والمرتجع الخاصة بيوم <?php echo htmlspecialchars($today); ?>.</div>

        <?php if (!$todaySales): ?>
            <div class="empty-note" style="margin-top:16px;">لا توجد فواتير مبيعات مسجلة اليوم.</div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>وقت الفاتورة</th>
                        <th>رقم الفاتورة</th>
                        <th>نوع العملية</th>
                        <th>الكاشير</th>
                        <th>عدد الأصناف</th>
                        <th>ملخص الأصناف</th>
                        <th>إجمالي الفاتورة</th>
                        <th>طباعة</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($todaySales as $sale): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sale['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                            <td>
                                <span class="type-badge <?php echo $sale['transaction_type'] === 'مرتجع' ? 'type-return' : 'type-sale'; ?>">
                                    <?php echo htmlspecialchars($sale['transaction_type']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($sale['cashier_name']); ?></td>
                            <td><?php echo (int)$sale['item_lines_count']; ?></td>
                            <td><?php echo htmlspecialchars($sale['item_summary']); ?></td>
                            <td><?php echo number_format((float)$sale['invoice_total'], 2); ?></td>
                            <td>
                                <a class="btn-print" target="_blank" href="sales_receipt.php?id=<?php echo (int)$sale['id']; ?>&print=1">
                                    <span>🖨️</span>
                                    <span>طباعة</span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="itemOptionsTemplate" hidden>
    <select>
        <option value="">اختر الصنف</option>
        <?php foreach ($items as $item): ?>
            <?php
                $hasQuantity = (int)($item['has_quantity'] ?? 0) === 1;
                $stockValue = $hasQuantity ? (int)($item['item_count'] ?? 0) : '';
            ?>
            <option
                value="<?php echo (int)$item['id']; ?>"
                data-price="<?php echo htmlspecialchars((string)round((float)($item['price'] ?? 0), 2)); ?>"
                data-stock="<?php echo htmlspecialchars((string)$stockValue); ?>"
                data-has-quantity="<?php echo $hasQuantity ? '1' : '0'; ?>"
            >
                <?php echo htmlspecialchars($item['name']); ?>
                <?php if ($hasQuantity): ?>
                    — المتاح: <?php echo (int)$item['item_count']; ?>
                <?php else: ?>
                    — بدون رصيد عددي
                <?php endif; ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<script>
const body = document.body;
const switchEl = document.getElementById('themeSwitch');
const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';
const invoiceLines = document.getElementById('invoiceLines');
const addLineBtn = document.getElementById('addLineBtn');
const salesForm = document.getElementById('salesForm');
const transactionTypeSelect = document.getElementById('transaction_type');
const invoiceTotalInput = document.getElementById('invoice_total');
const invoiceWarning = document.getElementById('invoiceWarning');
const saveInvoiceBtn = document.getElementById('saveInvoiceBtn');
const itemOptionsHtml = document.querySelector('#itemOptionsTemplate select').innerHTML;
const shouldResetSalesForm = <?php echo json_encode($validatedStatus === 'invoice_added'); ?>;

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

function createInvoiceRow() {
    const wrapper = document.createElement('div');
    wrapper.className = 'invoice-row';
    wrapper.setAttribute('data-row', '');
    wrapper.innerHTML = `
        <div class="grid invoice-row-grid">
            <div class="field">
                <label>الصنف</label>
                <select name="item_id[]" class="item-select" required>${itemOptionsHtml}</select>
            </div>
            <div class="field">
                <label>العدد</label>
                <input type="number" name="quantity[]" class="quantity-input" min="1" step="1" value="1" required>
            </div>
            <div class="field">
                <label>سعر الوحدة</label>
                <input type="text" class="unit-price-input" value="0.00" readonly>
            </div>
            <div class="field">
                <label>إجمالي السطر</label>
                <input type="text" class="line-total-input" value="0.00" readonly>
            </div>
            <div class="field">
                <label>&nbsp;</label>
                <button type="button" class="btn-remove remove-line-btn">حذف السطر</button>
            </div>
        </div>
        <div class="helper-note row-note">اختر الصنف لمعرفة الرصيد المتاح وسعر الوحدة.</div>
        <div class="warning row-warning"></div>
    `;
    invoiceLines.appendChild(wrapper);
    updateInvoicePreview();
}

function resetSalesFormState() {
    if (!salesForm || !invoiceLines) {
        return;
    }

    salesForm.reset();
    const firstRow = invoiceLines.firstElementChild;
    if (!firstRow) {
        createInvoiceRow();
        return;
    }
    if (invoiceLines.children.length > 1) {
        invoiceLines.replaceChildren(firstRow);
    }
    updateInvoicePreview();
}

function updateRemoveButtons() {
    const rows = invoiceLines.querySelectorAll('[data-row]');
    rows.forEach((row) => {
        const removeBtn = row.querySelector('.remove-line-btn');
        removeBtn.disabled = rows.length === 1;
    });
}

function getNonNegativeQuantity(quantityInput) {
    return Math.max(parseInt(quantityInput.value, 10) || 0, 0);
}

function getSelectedQuantityMap() {
    const usageMap = {};
    const transactionType = transactionTypeSelect.value;
    if (transactionType !== 'بيع') {
        return usageMap;
    }

    invoiceLines.querySelectorAll('[data-row]').forEach((row) => {
        const select = row.querySelector('.item-select');
        const quantityInput = row.querySelector('.quantity-input');
        const itemId = select.value;
        const quantity = getNonNegativeQuantity(quantityInput);
        if (itemId && quantity > 0) {
            usageMap[itemId] = (usageMap[itemId] || 0) + quantity;
        }
    });
    return usageMap;
}

function updateInvoicePreview() {
    const usageMap = getSelectedQuantityMap();
    const transactionType = transactionTypeSelect.value;
    let invoiceTotal = 0;
    let warnings = [];

    invoiceLines.querySelectorAll('[data-row]').forEach((row) => {
        const select = row.querySelector('.item-select');
        const quantityInput = row.querySelector('.quantity-input');
        const unitPriceInput = row.querySelector('.unit-price-input');
        const lineTotalInput = row.querySelector('.line-total-input');
        const rowNote = row.querySelector('.row-note');
        const rowWarning = row.querySelector('.row-warning');
        const option = select.options[select.selectedIndex];

        const price = option ? parseFloat(option.getAttribute('data-price') || '0') : 0;
        const hasQuantity = option ? option.getAttribute('data-has-quantity') === '1' : false;
        const stockRaw = option ? option.getAttribute('data-stock') : '';
        const stock = stockRaw === '' ? null : parseInt(stockRaw || '0', 10);
        const quantity = getNonNegativeQuantity(quantityInput);
        const lineTotal = price * quantity;

        unitPriceInput.value = price.toFixed(2);
        lineTotalInput.value = lineTotal.toFixed(2);
        invoiceTotal += lineTotal;

        if (!option || !option.value) {
            rowNote.textContent = 'اختر الصنف لمعرفة الرصيد المتاح وسعر الوحدة.';
            rowWarning.textContent = '';
            return;
        }

        if (hasQuantity) {
            rowNote.textContent = 'الرصيد الحالي للصنف: ' + (stock === null ? '0' : stock) + ' قطعة.';
        } else {
            rowNote.textContent = 'هذا الصنف غير مرتبط برصيد عددي داخل صفحة الأصناف.';
        }

        if (transactionType === 'بيع' && hasQuantity && stock !== null && (usageMap[select.value] || 0) > stock) {
            rowWarning.textContent = stock <= 0
                ? 'لا يمكن بيع هذا الصنف لأن رصيده أصبح 0.'
                : 'إجمالي الكمية المطلوبة لهذا الصنف داخل الفاتورة أكبر من الرصيد المتاح.';
            warnings.push(rowWarning.textContent);
        } else {
            rowWarning.textContent = '';
        }
    });

    invoiceTotalInput.value = invoiceTotal.toFixed(2);
    invoiceWarning.textContent = warnings.length ? warnings[0] : '';
    saveInvoiceBtn.disabled = warnings.length > 0;
    saveInvoiceBtn.title = warnings.length > 0 ? warnings[0] : '';
    updateRemoveButtons();
}

addLineBtn.addEventListener('click', createInvoiceRow);
transactionTypeSelect.addEventListener('change', updateInvoicePreview);

invoiceLines.addEventListener('input', function (event) {
    if (event.target.classList.contains('quantity-input')) {
        updateInvoicePreview();
    }
});

invoiceLines.addEventListener('change', function (event) {
    if (event.target.classList.contains('item-select')) {
        updateInvoicePreview();
    }
});

invoiceLines.addEventListener('click', function (event) {
    const button = event.target.closest('.remove-line-btn');
    if (!button) {
        return;
    }
    const row = button.closest('[data-row]');
    if (!row) {
        return;
    }
    if (invoiceLines.querySelectorAll('[data-row]').length > 1) {
        row.remove();
        updateInvoicePreview();
    }
});

if (shouldResetSalesForm) {
    resetSalesFormState();
} else {
    updateInvoicePreview();
}
</script>
</body>
</html>
