<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'sales_helpers.php';
require_once 'site_settings_helpers.php';
require_once 'user_permissions_helpers.php';

ensureSalesSchema($pdo);
ensureExtendedSiteSettingsSchema($pdo);
ensureUserPermissionsSchema($pdo);

$siteName = 'Gym System';
$receiptPaperWidthMm = null;
$receiptPageMarginMm = null;
$receiptFooterText = 'الاشتراك لا يسترد';
try {
    $stmt = $pdo->query("SELECT site_name, receipt_paper_width_mm, receipt_page_margin_mm, receipt_footer_text FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
        $receiptPaperWidthMm = isset($row['receipt_paper_width_mm']) && $row['receipt_paper_width_mm'] !== null ? (int)$row['receipt_paper_width_mm'] : null;
        $receiptPageMarginMm = isset($row['receipt_page_margin_mm']) && $row['receipt_page_margin_mm'] !== null ? (int)$row['receipt_page_margin_mm'] : null;
        if (!empty($row['receipt_footer_text'])) {
            $receiptFooterText = $row['receipt_footer_text'];
        }
    }
} catch (Exception $e) {
}

$role   = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);

$canViewPage = false;
if ($role === 'مدير') {
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

$saleId = (int)($_GET['id'] ?? 0);
$invoice = getSaleInvoiceById($pdo, $saleId);

if (!$invoice) {
    http_response_code(404);
    echo 'الفاتورة غير موجودة.';
    exit;
}

$autoPrint = isset($_GET['print']) && $_GET['print'] === '1';
$settingsErrors = [];
$printSettingsReady = $receiptPaperWidthMm !== null && $receiptPageMarginMm !== null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_print_settings'])) {
    $paperWidthInput = trim((string)($_POST['receipt_paper_width_mm'] ?? ''));
    $pageMarginInput = trim((string)($_POST['receipt_page_margin_mm'] ?? ''));
    $footerTextInput = trim((string)($_POST['receipt_footer_text'] ?? 'الاشتراك لا يسترد'));

    $paperWidthValue = filter_var($paperWidthInput, FILTER_VALIDATE_INT);
    if ($paperWidthValue === false || $paperWidthValue < 50 || $paperWidthValue > 120) {
        $settingsErrors[] = 'عرض الورق يجب أن يكون رقماً صحيحاً بين 50 و 120 مم.';
    }

    $pageMarginValue = filter_var($pageMarginInput, FILTER_VALIDATE_INT);
    if ($pageMarginValue === false || $pageMarginValue < 0 || $pageMarginValue > 15) {
        $settingsErrors[] = 'الهامش يجب أن يكون رقماً صحيحاً بين 0 و 15 مم.';
    }

    if ($footerTextInput === '') {
        $footerTextInput = 'الاشتراك لا يسترد';
    }

    if (!$settingsErrors) {
        try {
            $settingsId = getFirstSiteSettingsId($pdo);
            if ($settingsId !== null) {
                $stmtSave = $pdo->prepare("
                    UPDATE site_settings
                    SET receipt_paper_width_mm = :receipt_width,
                        receipt_page_margin_mm = :receipt_margin,
                        receipt_footer_text = :receipt_footer
                    WHERE id = :id
                ");
                $stmtSave->execute([
                    ':receipt_width' => (int)$paperWidthValue,
                    ':receipt_margin' => (int)$pageMarginValue,
                    ':receipt_footer' => $footerTextInput,
                    ':id' => $settingsId,
                ]);
            } else {
                $stmtSave = $pdo->prepare("
                    INSERT INTO site_settings (site_name, receipt_paper_width_mm, receipt_page_margin_mm, receipt_footer_text)
                    VALUES (:site_name, :receipt_width, :receipt_margin, :receipt_footer)
                ");
                $stmtSave->execute([
                    ':site_name' => $siteName,
                    ':receipt_width' => (int)$paperWidthValue,
                    ':receipt_margin' => (int)$pageMarginValue,
                    ':receipt_footer' => $footerTextInput,
                ]);
            }

            $query = [
                'id' => $saleId,
            ];
            if ($autoPrint) {
                $query['print'] = '1';
            }

            header('Location: sales_receipt.php?' . http_build_query($query));
            exit;
        } catch (Exception $e) {
            $settingsErrors[] = 'تعذر حفظ إعدادات الطباعة حالياً.';
        }
    }

    $receiptPaperWidthMm = $paperWidthValue !== false ? (int)$paperWidthValue : $receiptPaperWidthMm;
    $receiptPageMarginMm = $pageMarginValue !== false ? (int)$pageMarginValue : $receiptPageMarginMm;
    $receiptFooterText = $footerTextInput;
}

$effectivePaperWidthMm = $receiptPaperWidthMm !== null ? $receiptPaperWidthMm : 72;
$effectivePageMarginMm = $receiptPageMarginMm !== null ? $receiptPageMarginMm : 3;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إيصال فاتورة رقم <?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
    <style>
        @page {
            size: <?php echo (int)$effectivePaperWidthMm; ?>mm auto;
            margin: <?php echo (int)$effectivePageMarginMm; ?>mm;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            width: <?php echo (int)$effectivePaperWidthMm; ?>mm;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #fff;
            color: #111827;
        }
        body { padding: <?php echo (int)$effectivePageMarginMm; ?>mm; }
        .receipt { width: 100%; }
        .title { text-align: center; font-size: 18px; font-weight: 900; margin-bottom: 3mm; }
        .meta, .total-row { font-size: 13px; margin-bottom: 2mm; }
        .line { border-top: 1px dashed #111827; margin: 2.5mm 0; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { padding: 1.5mm 0; text-align: right; border-bottom: 1px dashed #d1d5db; vertical-align: top; }
        th { font-weight: 900; }
        .total-row { font-size: 15px; font-weight: 900; display: flex; justify-content: space-between; }
        .footer-note {
            margin-top: 4mm;
            font-size: 12px;
            font-weight: 800;
            text-align: center;
            line-height: 1.8;
        }
        .settings-card {
            max-width: 520px;
            margin: 24px auto;
            border: 1px solid #d1d5db;
            border-radius: 18px;
            padding: 18px;
            font-size: 14px;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }
        .settings-card h2 {
            margin: 0 0 10px;
            font-size: 18px;
        }
        .settings-card p {
            margin: 0 0 14px;
            color: #4b5563;
            line-height: 1.8;
        }
        .settings-field { margin-bottom: 12px; }
        .settings-field label {
            display: block;
            margin-bottom: 6px;
            font-weight: 800;
        }
        .settings-field input,
        .settings-field textarea {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
            font-family: inherit;
        }
        .settings-field textarea {
            min-height: 92px;
            resize: vertical;
        }
        .settings-error {
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(239, 68, 68, 0.08);
            color: #b91c1c;
            border: 1px solid rgba(239, 68, 68, 0.28);
            line-height: 1.8;
        }
        .print-actions { margin-top: 4mm; display: flex; gap: 2mm; }
        .print-btn {
            flex: 1; border: none; border-radius: 999px; padding: 8px 10px;
            background: #2563eb; color: #fff; font-size: 13px; font-weight: 900; cursor: pointer;
        }
        .print-btn.secondary { background: #6b7280; }
        @media print {
            .settings-card,
            .print-actions { display: none; }
            body { padding-bottom: 0; }
        }
    </style>
</head>
<body>
<?php if (!$printSettingsReady): ?>
<div class="settings-card">
    <h2>إعداد طباعة فاتورة المبيعات لأول مرة</h2>
    <p>حدد مقاس الورقة والهامش المناسبين لطابعتك الحرارية، وسيتم حفظ هذه الإعدادات واستخدامها تلقائياً في كل فاتورة بعد ذلك.</p>

    <?php if ($settingsErrors): ?>
        <div class="settings-error">
            <?php foreach ($settingsErrors as $error): ?>
                <div>• <?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="save_print_settings" value="1">
        <div class="settings-field">
            <label for="receipt_paper_width_mm">عرض الورقة (مم)</label>
            <input type="number" id="receipt_paper_width_mm" name="receipt_paper_width_mm" min="50" max="120"
                   value="<?php echo htmlspecialchars((string)$effectivePaperWidthMm); ?>" required>
        </div>
        <div class="settings-field">
            <label for="receipt_page_margin_mm">الهامش (مم)</label>
            <input type="number" id="receipt_page_margin_mm" name="receipt_page_margin_mm" min="0" max="15"
                   value="<?php echo htmlspecialchars((string)$effectivePageMarginMm); ?>" required>
        </div>
        <div class="settings-field">
            <label for="receipt_footer_text">النص أسفل الفاتورة</label>
            <textarea id="receipt_footer_text" name="receipt_footer_text"><?php echo htmlspecialchars($receiptFooterText); ?></textarea>
        </div>
        <button type="submit" class="print-btn">حفظ الإعدادات ومتابعة الفاتورة</button>
    </form>
</div>
<?php endif; ?>

<div class="receipt">
    <div class="title"><?php echo htmlspecialchars($siteName); ?></div>

    <div class="meta">رقم الفاتورة: <?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
    <div class="meta">التاريخ والوقت: <?php echo htmlspecialchars(formatAppDateTime12Hour($invoice['created_at'])); ?></div>
    <div class="meta">نوع العملية: <?php echo htmlspecialchars($invoice['transaction_type']); ?></div>
    <div class="meta">اسم الكاشير: <?php echo htmlspecialchars($invoice['cashier_name']); ?></div>

    <div class="line"></div>

    <table>
        <thead>
        <tr>
            <th>الصنف</th>
            <th>العدد</th>
            <th>السعر</th>
            <th>الإجمالي</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($invoice['items'] as $item): ?>
            <tr>
                <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                <td><?php echo (int)$item['quantity']; ?></td>
                <td><?php echo number_format((float)$item['unit_price'], 2); ?></td>
                <td><?php echo number_format((float)$item['total_amount'], 2); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="line"></div>

    <div class="total-row">
        <span>الإجمالي</span>
        <span><?php echo number_format((float)$invoice['invoice_total'], 2); ?></span>
    </div>

    <div class="footer-note"><?php echo nl2br(htmlspecialchars($receiptFooterText)); ?></div>

    <div class="print-actions">
        <button type="button" class="print-btn" onclick="window.print()">🖨️ طباعة</button>
        <button type="button" class="print-btn secondary" onclick="window.close()">إغلاق</button>
    </div>
</div>

<?php if ($autoPrint): ?>
<script>
window.addEventListener('load', function () {
    if (document.querySelector('.settings-card')) {
        return;
    }
    setTimeout(function () {
        window.print();
    }, 200);
});
window.onafterprint = function () {
    window.close();
};
</script>
<?php endif; ?>
</body>
</html>
