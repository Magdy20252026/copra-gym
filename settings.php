<?php
session_start();

// منع الدخول بدون تسجيل
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'site_settings_helpers.php';

ensureExtendedSiteSettingsSchema($pdo);

define('SITE_SETTINGS_TIME_REFERENCE_DATE', '1970-01-01');

function detectUploadedImageMimeType(string $filePath): ?string
{
    if (!is_file($filePath)) {
        return null;
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mimeType = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }
    }

    $imageInfo = @getimagesize($filePath);
    if (!empty($imageInfo['mime']) && is_string($imageInfo['mime'])) {
        return $imageInfo['mime'];
    }

    return null;
}

// تحميل الإعدادات الحالية
$siteName = "Gym System";
$logoPath = null;
$receiptPaperWidthMm = '';
$receiptPageMarginMm = '';
$receiptFooterText = 'الاشتراك لا يسترد';
$transferNumbers = [];
$workSchedules = [];

try {
    $stmt = $pdo->query("SELECT site_name, logo_path, receipt_paper_width_mm, receipt_page_margin_mm, receipt_footer_text, transfer_numbers_json, work_schedules_json FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
        $logoPath = $row['logo_path'];
        $receiptPaperWidthMm = isset($row['receipt_paper_width_mm']) && $row['receipt_paper_width_mm'] !== null ? (string)(int)$row['receipt_paper_width_mm'] : '';
        $receiptPageMarginMm = isset($row['receipt_page_margin_mm']) && $row['receipt_page_margin_mm'] !== null ? (string)(int)$row['receipt_page_margin_mm'] : '';
        if (!empty($row['receipt_footer_text'])) {
            $receiptFooterText = $row['receipt_footer_text'];
        }
        $transferNumbers = decodeSiteSettingsJsonList($row['transfer_numbers_json'] ?? null);
        $workSchedules = decodeSiteSettingsJsonList($row['work_schedules_json'] ?? null);
    }
} catch (Exception $e) {}

$transferTypeOptions = getSiteTransferTypeOptions();
$scheduleAudienceOptions = getSiteScheduleAudienceOptions();

if (!$transferNumbers) {
    $transferNumbers = [
        ['number' => '', 'type' => 'wallet'],
    ];
}

if (!$workSchedules) {
    $workSchedules = [
        ['label' => '', 'from' => '', 'to' => '', 'audience' => 'all'],
    ];
}

$username  = $_SESSION['username'] ?? '';
$role      = $_SESSION['role'] ?? '';
$isManager = ($role === 'مدير'); // نفترض أن المدير فقط يغير الإعدادات

$errors  = [];
$success = "";

// معالجة حفظ الإعدادات
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isManager) {
    $newSiteName = trim($_POST['site_name'] ?? '');
    $newReceiptPaperWidthMm = trim((string)($_POST['receipt_paper_width_mm'] ?? ''));
    $newReceiptPageMarginMm = trim((string)($_POST['receipt_page_margin_mm'] ?? ''));
    $newReceiptFooterText = trim((string)($_POST['receipt_footer_text'] ?? 'الاشتراك لا يسترد'));
    $postedTransferNumbers = $_POST['transfer_number'] ?? [];
    $postedTransferTypes = $_POST['transfer_type'] ?? [];
    $postedScheduleLabels = $_POST['schedule_label'] ?? [];
    $postedScheduleFrom = $_POST['schedule_from'] ?? [];
    $postedScheduleTo = $_POST['schedule_to'] ?? [];
    $postedScheduleAudiences = $_POST['schedule_audience'] ?? [];

    if ($newSiteName === '') {
        $errors[] = "من فضلك أدخل اسم الموقع.";
    }

    $receiptPaperWidthValue = filter_var($newReceiptPaperWidthMm, FILTER_VALIDATE_INT);
    if ($receiptPaperWidthValue === false || $receiptPaperWidthValue < 50 || $receiptPaperWidthValue > 120) {
        $errors[] = "عرض ورقة الفاتورة يجب أن يكون رقماً صحيحاً بين 50 و 120 مم.";
    }

    $receiptPageMarginValue = filter_var($newReceiptPageMarginMm, FILTER_VALIDATE_INT);
    if ($receiptPageMarginValue === false || $receiptPageMarginValue < 0 || $receiptPageMarginValue > 15) {
        $errors[] = "هامش الفاتورة يجب أن يكون رقماً صحيحاً بين 0 و 15 مم.";
    }

    if ($newReceiptFooterText === '') {
        $newReceiptFooterText = 'الاشتراك لا يسترد';
    }

    $newTransferNumbers = [];
    $postedTransferEntries = [];
    $transferRowsCount = max(
        is_array($postedTransferNumbers) ? count($postedTransferNumbers) : 0,
        is_array($postedTransferTypes) ? count($postedTransferTypes) : 0
    );
    for ($i = 0; $i < $transferRowsCount; $i++) {
        $transferNumber = trim((string)($postedTransferNumbers[$i] ?? ''));
        $transferType = trim((string)($postedTransferTypes[$i] ?? 'wallet'));
        if (!isset($transferTypeOptions[$transferType])) {
            $transferType = 'wallet';
        }
        $postedTransferEntries[] = [
            'number' => $transferNumber,
            'type' => $transferType,
        ];
        if ($transferNumber === '') {
            continue;
        }
        $newTransferNumbers[] = [
            'number' => $transferNumber,
            'type' => $transferType,
        ];
    }

    $newWorkSchedules = [];
    $postedWorkScheduleEntries = [];
    $scheduleRowsCount = max(
        is_array($postedScheduleLabels) ? count($postedScheduleLabels) : 0,
        is_array($postedScheduleFrom) ? count($postedScheduleFrom) : 0,
        is_array($postedScheduleTo) ? count($postedScheduleTo) : 0,
        is_array($postedScheduleAudiences) ? count($postedScheduleAudiences) : 0
    );
    for ($i = 0; $i < $scheduleRowsCount; $i++) {
        $scheduleLabel = trim((string)($postedScheduleLabels[$i] ?? ''));
        $scheduleFrom = trim((string)($postedScheduleFrom[$i] ?? ''));
        $scheduleTo = trim((string)($postedScheduleTo[$i] ?? ''));
        $scheduleAudience = trim((string)($postedScheduleAudiences[$i] ?? 'all'));
        if (!isset($scheduleAudienceOptions[$scheduleAudience])) {
            $scheduleAudience = 'all';
        }
        $postedWorkScheduleEntries[] = [
            'label' => $scheduleLabel,
            'from' => $scheduleFrom,
            'to' => $scheduleTo,
            'audience' => $scheduleAudience,
        ];
        if ($scheduleLabel === '' && $scheduleFrom === '' && $scheduleTo === '') {
            continue;
        }
        if ($scheduleFrom === '' || $scheduleTo === '') {
            $errors[] = "كل موعد عمل يجب أن يحتوي على وقت بداية ووقت نهاية.";
            continue;
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $scheduleFrom) || !preg_match('/^\d{2}:\d{2}$/', $scheduleTo)) {
            $errors[] = "صيغة مواعيد العمل غير صحيحة.";
            continue;
        }
        $scheduleFromTimestamp = strtotime(SITE_SETTINGS_TIME_REFERENCE_DATE . ' ' . $scheduleFrom);
        $scheduleToTimestamp = strtotime(SITE_SETTINGS_TIME_REFERENCE_DATE . ' ' . $scheduleTo);
        if ($scheduleFromTimestamp === false || $scheduleToTimestamp === false || $scheduleFromTimestamp >= $scheduleToTimestamp) {
            $errors[] = "وقت بداية الموعد يجب أن يكون قبل وقت النهاية.";
            continue;
        }
        if ($scheduleLabel === '') {
            $scheduleLabel = 'من ' . formatAppTime12Hour($scheduleFrom) . ' إلى ' . formatAppTime12Hour($scheduleTo);
        }
        $newWorkSchedules[] = [
            'label' => $scheduleLabel,
            'from' => $scheduleFrom,
            'to' => $scheduleTo,
            'audience' => $scheduleAudience,
        ];
    }

    if (!$newTransferNumbers) {
        $newTransferNumbers = [
            ['number' => '', 'type' => 'wallet'],
        ];
    }
    if (!$postedTransferEntries) {
        $postedTransferEntries = [
            ['number' => '', 'type' => 'wallet'],
        ];
    }

    if (!$newWorkSchedules) {
        $newWorkSchedules = [
            ['label' => '', 'from' => '', 'to' => '', 'audience' => 'all'],
        ];
    }
    if (!$postedWorkScheduleEntries) {
        $postedWorkScheduleEntries = [
            ['label' => '', 'from' => '', 'to' => '', 'audience' => 'all'],
        ];
    }

    $transferNumbers = $postedTransferEntries;
    $workSchedules = $postedWorkScheduleEntries;

    // معالجة الشعار
    $newLogoPath = $logoPath; // القيمة القديمة افتراضيًا

    if (!empty($_FILES['logo']['name'])) {
        $file      = $_FILES['logo'];
        $allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            // بعض المتصفحات أو الخوادم القديمة قد ترسل PNG بهذه الصيغة غير القياسية.
            'image/x-png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        $maxSize   = 2 * 1024 * 1024; // 2MB

        if ($file['error'] === UPLOAD_ERR_OK) {
            $detectedMimeType = null;
            if ($file['size'] > $maxSize) {
                $errors[] = "حجم ملف الشعار يجب ألا يزيد عن 2 ميجابايت.";
            } else {
                $detectedMimeType = detectUploadedImageMimeType($file['tmp_name'] ?? '');
                if ($detectedMimeType === null || !isset($allowedMimeTypes[$detectedMimeType])) {
                    $errors[] = "نوع ملف الشعار غير مسموح (يُسمح بـ JPG/PNG/WebP/GIF).";
                } else {
                    // مجلد الرفع (تأكد من وجوده وصلاحياته)
                    $uploadDir  = 'uploads/';
                    if (!is_dir($uploadDir)) {
                        @mkdir($uploadDir, 0777, true);
                    }

                    $ext = $allowedMimeTypes[$detectedMimeType];
                    try {
                        $fileName = 'logo_' . bin2hex(random_bytes(16)) . '.' . $ext;
                    } catch (Exception $e) {
                        $fileName = 'logo_' . uniqid('', true) . '.' . $ext;
                    }
                    $targetPath = $uploadDir . $fileName;

                    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                        $newLogoPath = $targetPath;
                    } else {
                        $errors[] = "فشل رفع ملف الشعار.";
                    }
                }
            }
        } else {
            $errors[] = "حدث خطأ أثناء رفع ملف الشعار.";
        }
    }

    if (!$errors) {
        try {
            // إذا توجد صف واحد فقط، نحدثه، وإلا ننشئ
            $settingsId = getFirstSiteSettingsId($pdo);
            if ($settingsId !== null) {
                $update = $pdo->prepare("
                    UPDATE site_settings
                    SET site_name = :n,
                        logo_path = :p,
                        receipt_paper_width_mm = :receipt_width,
                        receipt_page_margin_mm = :receipt_margin,
                        receipt_footer_text = :receipt_footer,
                        transfer_numbers_json = :transfer_numbers_json,
                        work_schedules_json = :work_schedules_json
                    WHERE id = :id
                ");
                $update->execute([
                    ':n' => $newSiteName,
                    ':p' => $newLogoPath,
                    ':receipt_width' => (int)$receiptPaperWidthValue,
                    ':receipt_margin' => (int)$receiptPageMarginValue,
                    ':receipt_footer' => $newReceiptFooterText,
                    ':transfer_numbers_json' => json_encode($newTransferNumbers, JSON_UNESCAPED_UNICODE),
                    ':work_schedules_json' => json_encode($newWorkSchedules, JSON_UNESCAPED_UNICODE),
                    ':id' => $settingsId,
                ]);
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO site_settings (
                        site_name,
                        logo_path,
                        receipt_paper_width_mm,
                        receipt_page_margin_mm,
                        receipt_footer_text,
                        transfer_numbers_json,
                        work_schedules_json
                    ) VALUES (:n, :p, :receipt_width, :receipt_margin, :receipt_footer, :transfer_numbers_json, :work_schedules_json)
                ");
                $insert->execute([
                    ':n' => $newSiteName,
                    ':p' => $newLogoPath,
                    ':receipt_width' => (int)$receiptPaperWidthValue,
                    ':receipt_margin' => (int)$receiptPageMarginValue,
                    ':receipt_footer' => $newReceiptFooterText,
                    ':transfer_numbers_json' => json_encode($newTransferNumbers, JSON_UNESCAPED_UNICODE),
                    ':work_schedules_json' => json_encode($newWorkSchedules, JSON_UNESCAPED_UNICODE),
                ]);
            }

            $siteName = $newSiteName;
            $logoPath = $newLogoPath;
            $receiptPaperWidthMm = (string)(int)$receiptPaperWidthValue;
            $receiptPageMarginMm = (string)(int)$receiptPageMarginValue;
            $receiptFooterText = $newReceiptFooterText;
            $transferNumbers = $newTransferNumbers;
            $workSchedules = $newWorkSchedules;
            $success  = "تم حفظ إعدادات الموقع بنجاح.";
        } catch (Exception $e) {
            $errors[] = "حدث خطأ أثناء حفظ الإعدادات.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إعدادات الموقع - <?php echo htmlspecialchars($siteName); ?></title>
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
            font-weight: 800;
            font-size: 18px;
        }

        .page {
            max-width: 1100px;
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
            padding: 22px;
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
            transition: transform .25s ease, background .25s ease, box-shadow .25s ease;
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

        .grid {
            display: grid;
            grid-template-columns: minmax(0, 2.2fr) minmax(0, 1.8fr);
            gap: 20px;
        }

        @media (max-width: 900px) {
            .grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 14px;
        }

        .field label {
            font-size: 16px;
            color: var(--text-muted);
            font-weight: 900;
        }

        input[type="text"],
        input[type="number"],
        input[type="time"],
        select,
        textarea {
            width: 100%;
            padding: 13px 14px;
            border-radius: 18px;
            border: 1px solid var(--border);
            background: var(--input-bg);
            font-size: 18px;
            font-weight: 800;
            color: var(--text-main);
            font-family: inherit;
        }

        input[type="file"] {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-muted);
        }

        textarea {
            min-height: 110px;
            resize: vertical;
            line-height: 1.8;
        }

        input[type="text"]:focus,
        input[type="number"]:focus,
        input[type="time"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-soft);
        }

        .logo-preview {
            border-radius: 18px;
            border: 1px solid var(--border);
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .logo-box {
            width: 110px;
            height: 110px;
            border-radius: 26px;
            background: radial-gradient(circle at 30% 0, #22c55e, #16a34a, #0f766e);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 16px 34px rgba(34,197,94,0.6);
        }

        .logo-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .logo-title {
            font-size: 18px;
            font-weight: 900;
        }

        .logo-sub {
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 700;
        }

        .btn-save-main {
            margin-top: 10px;
            border-radius: 999px;
            padding: 12px 26px;
            border: none;
            cursor: pointer;
            font-size: 18px;
            font-weight: 900;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: linear-gradient(90deg, #16a34a, #22c55e);
            color: #f9fafb;
            box-shadow: 0 18px 40px rgba(22,163,74,0.7);
        }
        .btn-save-main:hover { filter: brightness(1.05); }

        .btn-small {
            border-radius: 999px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 900;
            padding: 10px 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: linear-gradient(90deg, #2563eb, #38bdf8);
            color: #f9fafb;
            box-shadow: 0 14px 30px rgba(37,99,235,0.35);
        }
        .btn-small.btn-danger {
            background: linear-gradient(90deg, #dc2626, #ef4444);
            box-shadow: 0 14px 30px rgba(220,38,38,0.28);
        }

        .settings-block {
            margin-top: 20px;
            padding: 16px;
            border-radius: 18px;
            border: 1px solid var(--border);
            background: rgba(37,99,235,0.04);
        }
        body.dark .settings-block {
            background: rgba(15,23,42,0.72);
        }
        .settings-block-title {
            font-size: 18px;
            font-weight: 900;
            margin-bottom: 6px;
        }
        .settings-block-subtitle {
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 700;
            margin-bottom: 14px;
            line-height: 1.8;
        }
        .repeater-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .repeater-item {
            border: 1px dashed var(--border);
            border-radius: 18px;
            padding: 12px;
            background: rgba(255,255,255,0.78);
        }
        body.dark .repeater-item {
            background: rgba(2,6,23,0.82);
        }
        .repeater-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            align-items: end;
        }
        .repeater-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 12px;
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

        .muted {
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 700;
        }

        .disabled-note {
            font-size: 15px;
            color: var(--danger);
            font-weight: 800;
            margin-top: 6px;
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">إعدادات الموقع</div>
            <div class="title-sub">تغيير اسم النظام والشعار وبيانات التحويل ومواعيد العمل الظاهرة للمشتركين.</div>
        </div>
        <div>
            <a href="dashboard.php" class="back-button">
                <span>⚙️</span>
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

        <?php if (!$isManager): ?>
            <div class="disabled-note">
                لا تملك صلاحية تعديل إعدادات الموقع (الصلاحية المطلوبة: مدير).
            </div>
        <?php endif; ?>

        <form method="post" action="" enctype="multipart/form-data">
            <div class="grid">
                <!-- عمود البيانات النصية -->
                <div>
                    <div class="field">
                        <label for="site_name">اسم الموقع</label>
                        <input type="text" id="site_name" name="site_name"
                               value="<?php echo htmlspecialchars($siteName); ?>"
                               <?php echo !$isManager ? 'disabled' : ''; ?>>
                        <div class="muted">مثال: نظام إدارة الجيم، Gym Admin Panel، ...</div>
                    </div>

                    <div class="field">
                        <label for="logo">شعار الموقع (اختياري)</label>
                        <input type="file" id="logo" name="logo" accept="image/*" <?php echo !$isManager ? 'disabled' : ''; ?>>
                        <div class="muted">يُفضل رفع صورة مربعة PNG أو JPG بحجم أقل من 2MB.</div>
                    </div>

                    <div class="field">
                        <label for="receipt_paper_width_mm">عرض ورقة فاتورة المبيعات (مم)</label>
                        <input type="number" id="receipt_paper_width_mm" name="receipt_paper_width_mm" min="50" max="120"
                               value="<?php echo htmlspecialchars($receiptPaperWidthMm !== '' ? $receiptPaperWidthMm : '72'); ?>"
                               <?php echo !$isManager ? 'disabled' : ''; ?>>
                        <div class="muted">يستخدم هذا العرض في نافذة طباعة فاتورة المبيعات، والإعدادات تُحفظ للاستخدام في كل مرة.</div>
                    </div>

                    <div class="field">
                        <label for="receipt_page_margin_mm">هامش فاتورة المبيعات (مم)</label>
                        <input type="number" id="receipt_page_margin_mm" name="receipt_page_margin_mm" min="0" max="15"
                               value="<?php echo htmlspecialchars($receiptPageMarginMm !== '' ? $receiptPageMarginMm : '3'); ?>"
                               <?php echo !$isManager ? 'disabled' : ''; ?>>
                        <div class="muted">يمكنك ضبط الهامش بحسب نوع الطابعة الحرارية المستخدمة.</div>
                    </div>

                    <div class="field">
                        <label for="receipt_footer_text">الجملة المعروضة أسفل الفاتورة</label>
                        <textarea id="receipt_footer_text" name="receipt_footer_text" <?php echo !$isManager ? 'disabled' : ''; ?>><?php echo htmlspecialchars($receiptFooterText); ?></textarea>
                        <div class="muted">سيتم إظهار هذه الجملة في نهاية فاتورة المبيعات، مثل: الاشتراك لا يسترد.</div>
                    </div>

                    <div class="settings-block">
                        <div class="settings-block-title">أرقام التحويل</div>
                        <div class="settings-block-subtitle">أضف أرقام التحويل وحدد هل كل رقم محفظة إلكترونية أو انستا باي، وسيتم عرضها أسفل صفحة استعلام المشترك.</div>
                        <div class="repeater-list" id="transferNumbersList">
                            <?php foreach ($transferNumbers as $transferEntry): ?>
                                <?php
                                $transferNumberValue = trim((string)($transferEntry['number'] ?? ''));
                                $transferTypeValue = trim((string)($transferEntry['type'] ?? 'wallet'));
                                if (!isset($transferTypeOptions[$transferTypeValue])) {
                                    $transferTypeValue = 'wallet';
                                }
                                ?>
                                <div class="repeater-item">
                                    <div class="repeater-grid">
                                        <div class="field">
                                            <label>رقم التحويل</label>
                                            <input type="text" name="transfer_number[]" value="<?php echo htmlspecialchars($transferNumberValue); ?>" <?php echo !$isManager ? 'disabled' : ''; ?>>
                                        </div>
                                        <div class="field">
                                            <label>نوع الرقم</label>
                                            <select name="transfer_type[]" <?php echo !$isManager ? 'disabled' : ''; ?>>
                                                <?php foreach ($transferTypeOptions as $transferTypeKey => $transferTypeLabel): ?>
                                                    <option value="<?php echo htmlspecialchars($transferTypeKey); ?>" <?php echo $transferTypeValue === $transferTypeKey ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($transferTypeLabel); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php if ($isManager): ?>
                                        <div class="repeater-actions">
                                            <span class="muted">يمكنك إضافة أكثر من رقم تحويل حسب الحاجة.</span>
                                            <button type="button" class="btn-small btn-danger js-remove-repeater">حذف</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($isManager): ?>
                            <div class="repeater-actions">
                                <span class="muted">أضف صفاً جديداً لإضافة رقم تحويل آخر.</span>
                                <button type="button" class="btn-small" id="addTransferRow">+ إضافة رقم تحويل</button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="settings-block">
                        <div class="settings-block-title">مواعيد العمل</div>
                        <div class="settings-block-subtitle">اكتب اسم الموعد أو اليوم، وحدد وقت البداية والنهاية، ثم اختر هل الموعد للرجال فقط أو السيدات فقط أو للجميع.</div>
                        <div class="repeater-list" id="workSchedulesList">
                            <?php foreach ($workSchedules as $scheduleEntry): ?>
                                <?php
                                $scheduleLabelValue = trim((string)($scheduleEntry['label'] ?? ''));
                                $scheduleFromValue = trim((string)($scheduleEntry['from'] ?? ''));
                                $scheduleToValue = trim((string)($scheduleEntry['to'] ?? ''));
                                $scheduleAudienceValue = trim((string)($scheduleEntry['audience'] ?? 'all'));
                                if (!isset($scheduleAudienceOptions[$scheduleAudienceValue])) {
                                    $scheduleAudienceValue = 'all';
                                }
                                ?>
                                <div class="repeater-item">
                                    <div class="repeater-grid">
                                        <div class="field">
                                            <label>اسم الموعد / اليوم</label>
                                            <input type="text" name="schedule_label[]" value="<?php echo htmlspecialchars($scheduleLabelValue); ?>" placeholder="مثال: الجمعة أو الفترة الصباحية" <?php echo !$isManager ? 'disabled' : ''; ?>>
                                        </div>
                                        <div class="field">
                                            <label>من</label>
                                            <input type="time" name="schedule_from[]" value="<?php echo htmlspecialchars($scheduleFromValue); ?>" <?php echo !$isManager ? 'disabled' : ''; ?>>
                                        </div>
                                        <div class="field">
                                            <label>إلى</label>
                                            <input type="time" name="schedule_to[]" value="<?php echo htmlspecialchars($scheduleToValue); ?>" <?php echo !$isManager ? 'disabled' : ''; ?>>
                                        </div>
                                        <div class="field">
                                            <label>الفئة</label>
                                            <select name="schedule_audience[]" <?php echo !$isManager ? 'disabled' : ''; ?>>
                                                <?php foreach ($scheduleAudienceOptions as $audienceKey => $audienceLabel): ?>
                                                    <option value="<?php echo htmlspecialchars($audienceKey); ?>" <?php echo $scheduleAudienceValue === $audienceKey ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($audienceLabel); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <?php if ($isManager): ?>
                                        <div class="repeater-actions">
                                            <span class="muted">اكتب الوصف كما تريد أن يظهر في صفحة المشترك.</span>
                                            <button type="button" class="btn-small btn-danger js-remove-repeater">حذف</button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($isManager): ?>
                            <div class="repeater-actions">
                                <span class="muted">أضف أكثر من موعد عمل عند الحاجة.</span>
                                <button type="button" class="btn-small" id="addScheduleRow">+ إضافة موعد عمل</button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($isManager): ?>
                        <button type="submit" class="btn-save-main">
                            <span>💾</span>
                            <span>حفظ إعدادات الموقع</span>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- عمود المعاينة -->
                <div>
                    <div class="logo-preview">
                        <div class="logo-box" id="logoPreviewBox">
                            <?php if ($logoPath): ?>
                                <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="شعار الموقع" id="logoPreviewImg">
                            <?php else: ?>
                                <span style="font-size:40px;">🏋️‍♂️</span>
                            <?php endif; ?>
                        </div>
                        <div class="logo-title" id="previewTitle">
                            <?php echo htmlspecialchars($siteName); ?>
                        </div>
                        <div class="logo-sub">
                            هذه المعاينة توضح شكل الشعار واسم الموقع في صفحات تسجيل الدخول ولوحة التحكم.
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($isManager): ?>
    <template id="transferRowTemplate">
        <div class="repeater-item">
            <div class="repeater-grid">
                <div class="field">
                    <label>رقم التحويل</label>
                    <input type="text" name="transfer_number[]">
                </div>
                <div class="field">
                    <label>نوع الرقم</label>
                    <select name="transfer_type[]">
                        <?php foreach ($transferTypeOptions as $transferTypeKey => $transferTypeLabel): ?>
                            <option value="<?php echo htmlspecialchars($transferTypeKey); ?>">
                                <?php echo htmlspecialchars($transferTypeLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="repeater-actions">
                <span class="muted">يمكنك إضافة أكثر من رقم تحويل حسب الحاجة.</span>
                <button type="button" class="btn-small btn-danger js-remove-repeater">حذف</button>
            </div>
        </div>
    </template>

    <template id="scheduleRowTemplate">
        <div class="repeater-item">
            <div class="repeater-grid">
                <div class="field">
                    <label>اسم الموعد / اليوم</label>
                    <input type="text" name="schedule_label[]" placeholder="مثال: الجمعة أو الفترة الصباحية">
                </div>
                <div class="field">
                    <label>من</label>
                    <input type="time" name="schedule_from[]">
                </div>
                <div class="field">
                    <label>إلى</label>
                    <input type="time" name="schedule_to[]">
                </div>
                <div class="field">
                    <label>الفئة</label>
                    <select name="schedule_audience[]">
                        <?php foreach ($scheduleAudienceOptions as $audienceKey => $audienceLabel): ?>
                            <option value="<?php echo htmlspecialchars($audienceKey); ?>">
                                <?php echo htmlspecialchars($audienceLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="repeater-actions">
                <span class="muted">اكتب الوصف كما تريد أن يظهر في صفحة المشترك.</span>
                <button type="button" class="btn-small btn-danger js-remove-repeater">حذف</button>
            </div>
        </div>
    </template>
<?php endif; ?>

<script>
    // ثيم داكن/فاتح مثل لوحة التحكم
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

    // معاينة فورية لاسم الموقع والشعار
    const siteNameInput = document.getElementById('site_name');
    const previewTitle  = document.getElementById('previewTitle');
    const logoInput     = document.getElementById('logo');
    const logoBox       = document.getElementById('logoPreviewBox');

    if (siteNameInput && previewTitle) {
        siteNameInput.addEventListener('input', function () {
            const v = this.value.trim();
            previewTitle.textContent = v !== '' ? v : '<?php echo htmlspecialchars($siteName); ?>';
        });
    }

    if (logoInput && logoBox) {
        logoInput.addEventListener('change', function () {
            const file = this.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function (e) {
                let img = document.getElementById('logoPreviewImg');
                if (!img) {
                    img = document.createElement('img');
                    img.id = 'logoPreviewImg';
                    logoBox.innerHTML = '';
                    logoBox.appendChild(img);
                }
                img.src = e.target.result;
            };
            reader.readAsDataURL(file);
        });
    }

    const transferNumbersList = document.getElementById('transferNumbersList');
    const workSchedulesList = document.getElementById('workSchedulesList');
    const transferRowTemplate = document.getElementById('transferRowTemplate');
    const scheduleRowTemplate = document.getElementById('scheduleRowTemplate');
    const addTransferRowButton = document.getElementById('addTransferRow');
    const addScheduleRowButton = document.getElementById('addScheduleRow');

    function bindRepeaterRemoveButtons(scope) {
        if (!scope) return;
        const buttons = scope.querySelectorAll('.js-remove-repeater');
        buttons.forEach((button) => {
            if (button.dataset.bound === '1') return;
            button.dataset.bound = '1';
            button.addEventListener('click', function () {
                const item = this.closest('.repeater-item');
                const parent = item ? item.parentElement : null;
                if (!item || !parent || parent.children.length <= 1) {
                    return;
                }
                item.remove();
            });
        });
    }

    bindRepeaterRemoveButtons(document);

    if (addTransferRowButton && transferNumbersList && transferRowTemplate) {
        addTransferRowButton.addEventListener('click', function () {
            const fragment = transferRowTemplate.content.cloneNode(true);
            bindRepeaterRemoveButtons(fragment);
            transferNumbersList.appendChild(fragment);
        });
    }

    if (addScheduleRowButton && workSchedulesList && scheduleRowTemplate) {
        addScheduleRowButton.addEventListener('click', function () {
            const fragment = scheduleRowTemplate.content.cloneNode(true);
            bindRepeaterRemoveButtons(fragment);
            workSchedulesList.appendChild(fragment);
        });
    }
</script>
</body>
</html>
