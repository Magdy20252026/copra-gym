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

// تحميل الإعدادات الحالية
$siteName = "Gym System";
$logoPath = null;
$receiptPaperWidthMm = '';
$receiptPageMarginMm = '';
$receiptFooterText = 'الاشتراك لا يسترد';

try {
    $stmt = $pdo->query("SELECT site_name, logo_path, receipt_paper_width_mm, receipt_page_margin_mm, receipt_footer_text FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
        $logoPath = $row['logo_path'];
        $receiptPaperWidthMm = isset($row['receipt_paper_width_mm']) && $row['receipt_paper_width_mm'] !== null ? (string)(int)$row['receipt_paper_width_mm'] : '';
        $receiptPageMarginMm = isset($row['receipt_page_margin_mm']) && $row['receipt_page_margin_mm'] !== null ? (string)(int)$row['receipt_page_margin_mm'] : '';
        if (!empty($row['receipt_footer_text'])) {
            $receiptFooterText = $row['receipt_footer_text'];
        }
    }
} catch (Exception $e) {}

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

    // معالجة الشعار
    $newLogoPath = $logoPath; // القيمة القديمة افتراضيًا

    if (!empty($_FILES['logo']['name'])) {
        $file      = $_FILES['logo'];
        $allowed   = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $maxSize   = 2 * 1024 * 1024; // 2MB

        if ($file['error'] === UPLOAD_ERR_OK) {
            if (!in_array($file['type'], $allowed, true)) {
                $errors[] = "نوع ملف الشعار غير مسموح (يُسمح بـ JPG/PNG/WebP/GIF).";
            } elseif ($file['size'] > $maxSize) {
                $errors[] = "حجم ملف الشعار يجب ألا يزيد عن 2 ميجابايت.";
            } else {
                // مجلد الرفع (تأكد من وجوده وصلاحياته)
                $uploadDir  = 'uploads/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }

                $ext        = pathinfo($file['name'], PATHINFO_EXTENSION);
                $fileName   = 'logo_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                $targetPath = $uploadDir . $fileName;

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $newLogoPath = $targetPath;
                } else {
                    $errors[] = "فشل رفع ملف الشعار.";
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
                        receipt_footer_text = :receipt_footer
                    WHERE id = :id
                ");
                $update->execute([
                    ':n' => $newSiteName,
                    ':p' => $newLogoPath,
                    ':receipt_width' => (int)$receiptPaperWidthValue,
                    ':receipt_margin' => (int)$receiptPageMarginValue,
                    ':receipt_footer' => $newReceiptFooterText,
                    ':id' => $settingsId,
                ]);
            } else {
                $insert = $pdo->prepare("
                    INSERT INTO site_settings (
                        site_name,
                        logo_path,
                        receipt_paper_width_mm,
                        receipt_page_margin_mm,
                        receipt_footer_text
                    ) VALUES (:n, :p, :receipt_width, :receipt_margin, :receipt_footer)
                ");
                $insert->execute([
                    ':n' => $newSiteName,
                    ':p' => $newLogoPath,
                    ':receipt_width' => (int)$receiptPaperWidthValue,
                    ':receipt_margin' => (int)$receiptPageMarginValue,
                    ':receipt_footer' => $newReceiptFooterText,
                ]);
            }

            $siteName = $newSiteName;
            $logoPath = $newLogoPath;
            $receiptPaperWidthMm = (string)(int)$receiptPaperWidthValue;
            $receiptPageMarginMm = (string)(int)$receiptPageMarginValue;
            $receiptFooterText = $newReceiptFooterText;
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
            <div class="title-sub">تغيير اسم النظام والشعار الذي يظهر في صفحة الدخول ولوحة التحكم.</div>
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
                        <label for="receipt_footer_text">الجملة الظاهرة أسفل الفاتورة</label>
                        <textarea id="receipt_footer_text" name="receipt_footer_text" <?php echo !$isManager ? 'disabled' : ''; ?>><?php echo htmlspecialchars($receiptFooterText); ?></textarea>
                        <div class="muted">سيتم إظهار هذه الجملة في نهاية فاتورة المبيعات، مثل: الاشتراك لا يسترد.</div>
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
</script>
</body>
</html>
