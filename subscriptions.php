<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

ensureSubscriptionCategorySchema($pdo);

$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$username  = $_SESSION['username'] ?? '';
$role      = $_SESSION['role'] ?? '';
$isManager = ($role === 'مدير');

$errors  = [];
$success = "";

// إلغاء الخصومات المنتهية
try {
    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        UPDATE subscriptions
        SET discount_percent = 0,
            price_after_discount = price,
            discount_end_date = NULL
        WHERE discount_end_date IS NOT NULL
          AND discount_end_date < :today
    ");
    $stmt->execute([':today' => $today]);
} catch (Exception $e) {
    // يمكن تجاهل الخطأ هنا أو تخزينه في لوج
}

// معالجة النموذج
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isManager) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $name                 = trim($_POST['name'] ?? '');
        $subscriptionCategory = trim($_POST['subscription_category'] ?? '');
        $days                 = (int)($_POST['days'] ?? 0);
        $sessions             = (int)($_POST['sessions'] ?? 0);
        $invites              = (int)($_POST['invites'] ?? 0);       // عدد الدعوات
        $freezeDays           = (int)($_POST['freeze_days'] ?? 0);   // عدد أيام الفريز
        $spaCount             = (int)($_POST['spa_count'] ?? 0);     // عدد جلسات السبا
        $massageCount         = (int)($_POST['massage_count'] ?? 0); // عدد جلسات المساج
        $jacuzziCount         = (int)($_POST['jacuzzi_count'] ?? 0); // عدد جلسات الجاكوزي
        $price                = (float)($_POST['price'] ?? 0);
        $discount             = (float)($_POST['discount_percent'] ?? 0);
        $endDate              = trim($_POST['discount_end_date'] ?? '');

        if ($name === '' || $days <= 0 || $sessions <= 0 || $price <= 0) {
            $errors[] = "من فضلك أدخل جميع بيانات الاشتراك الأساسية بشكل صحيح.";
        } elseif (function_exists('mb_strlen') ? mb_strlen($subscriptionCategory) > 255 : strlen($subscriptionCategory) > 255) {
            $errors[] = "تصنيف الاشتراك يجب ألا يزيد عن 255 حرفاً.";
        } elseif ($invites < 0) {
            $errors[] = "عدد الدعوات لا يمكن أن يكون سالباً.";
        } elseif ($freezeDays < 0) {
            $errors[] = "عدد أيام الـ Freeze لا يمكن أن يكون سالباً.";
        } elseif ($spaCount < 0) {
            $errors[] = "عدد جلسات السبا لا يمكن أن يكون سالباً.";
        } elseif ($massageCount < 0) {
            $errors[] = "عدد جلسات المساج لا يمكن أن يكون سالباً.";
        } elseif ($jacuzziCount < 0) {
            $errors[] = "عدد جلسات الجاكوزي لا يمكن أن يكون سالباً.";
        } elseif ($discount < 0 || $discount > 100) {
            $errors[] = "نسبة الخصم يجب أن تكون بين 0 و 100.";
        } else {
            // حساب السعر بعد الخصم
            $priceAfter = $price - ($price * ($discount / 100));

            // تنسيق تاريخ نهاية الخصم (يمكن أن يكون فارغ)
            $discountEndDate = null;
            if ($endDate !== '') {
                $discountEndDate = $endDate; // نفترض أنه بصيغة YYYY-MM-DD من الـ input[type=date]
            }

            if ($action === 'add') {
                try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO subscriptions
                         (name, subscription_category, days, sessions, invites, freeze_days, spa_count, massage_count, jacuzzi_count, price, discount_percent, price_after_discount, discount_end_date)
                         VALUES (:n, :cat, :d, :s, :i, :fz, :spa, :massage, :jacuzzi, :p, :dp, :pad, :ded)"
                    );
                    $stmt->execute([
                        ':n'       => $name,
                        ':cat'     => ($subscriptionCategory !== '' ? $subscriptionCategory : null),
                        ':d'       => $days,
                        ':s'       => $sessions,
                        ':i'       => $invites,
                        ':fz'      => $freezeDays,
                        ':spa'     => $spaCount,
                        ':massage' => $massageCount,
                        ':jacuzzi' => $jacuzziCount,
                        ':p'       => $price,
                        ':dp'      => $discount,
                        ':pad'     => $priceAfter,
                        ':ded'     => $discountEndDate,
                    ]);
                    $success = "تم إضافة الاشتراك بنجاح.";
                } catch (Exception $e) {
                    $errors[] = "حدث خطأ أثناء إضافة الاشتراك.";
                }
            } elseif ($action === 'edit') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) {
                    $errors[] = "معرّف الاشتراك غير صحيح.";
                } else {
                    try {
                        $stmt = $pdo->prepare(
                            "UPDATE subscriptions
                             SET name = :n,
                                  subscription_category = :cat,
                                  days = :d,
                                  sessions = :s,
                                  invites = :i,
                                 freeze_days = :fz,
                                 spa_count = :spa,
                                 massage_count = :massage,
                                 jacuzzi_count = :jacuzzi,
                                 price = :p,
                                 discount_percent = :dp,
                                 price_after_discount = :pad,
                                 discount_end_date = :ded
                             WHERE id = :id"
                        );
                        $stmt->execute([
                            ':n'       => $name,
                            ':cat'     => ($subscriptionCategory !== '' ? $subscriptionCategory : null),
                            ':d'       => $days,
                            ':s'       => $sessions,
                            ':i'       => $invites,
                            ':fz'      => $freezeDays,
                            ':spa'     => $spaCount,
                            ':massage' => $massageCount,
                            ':jacuzzi' => $jacuzziCount,
                            ':p'       => $price,
                            ':dp'      => $discount,
                            ':pad'     => $priceAfter,
                            ':ded'     => $discountEndDate,
                            ':id'      => $id,
                        ]);
                        $success = "تم تعديل الاشتراك بنجاح.";
                    } catch (Exception $e) {
                        $errors[] = "حدث خطأ أثناء تعديل الاشتراك.";
                    }
                }
            }
        }
    }

    if ($action === 'delete' && $isManager) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errors[] = "معرّف الاشتراك غير صحيح.";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM subscriptions WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $success = "تم حذف الاشتراك بنجاح.";
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء حذف الاشتراك.";
            }
        }
    }
}

$subscriptionCategories = getSubscriptionCategoryOptions($pdo);

// جلب الاشتراكات مع عدد المشتركين (إجمالي / مستمر / منتهي)
$subscriptions = [];
try {
    $stmt = $pdo->query("
        SELECT
            s.id,
            s.name,
            s.subscription_category,
            s.days,
            s.sessions,
            s.invites,
            s.freeze_days,
            s.spa_count,
            s.massage_count,
            s.jacuzzi_count,
            s.price,
            s.discount_percent,
            s.price_after_discount,
            s.discount_end_date,
            COUNT(m.id) AS subscribers_count,
            SUM(CASE WHEN m.status = 'مستمر' THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN m.status = 'منتهي' THEN 1 ELSE 0 END) AS ended_count
        FROM subscriptions s
        LEFT JOIN members m ON m.subscription_id = s.id
        GROUP BY
            s.id, s.name, s.subscription_category, s.days, s.sessions, s.invites, s.freeze_days, s.spa_count, s.massage_count, s.jacuzzi_count,
            s.price, s.discount_percent, s.price_after_discount, s.discount_end_date
        ORDER BY s.id DESC
    ");
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة ا��اشتراكات - <?php echo htmlspecialchars($siteName); ?></title>
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
            font-weight: 900;
            font-size: 18px;
        }
        .page { max-width: 1200px; margin: 26px auto 40px; padding: 0 20px; }
        .header-bar { display:flex;justify-content:space-between;align-items:center;margin-bottom:22px; }
        .title-main{font-size:26px;font-weight:900;}
        .title-sub{margin-top:6px;font-size:16px;color:var(--text-muted);font-weight:800;}
        .back-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 22px;border-radius:999px;border:none;cursor:pointer;font-size:16px;font-weight:900;background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;box-shadow:0 16px 38px rgba(79,70,229,0.55);text-decoration:none;}
        .back-button:hover{filter:brightness(1.05);}
        .card{background:var(--card-bg);border-radius:26px;padding:20px 22px 22px;box-shadow:0 22px 60px rgba(15,23,42,0.22),0 0 0 1px rgba(255,255,255,0.65);}
        .theme-toggle{display:flex;justify-content:flex-end;margin-bottom:14px;}
        .theme-switch{position:relative;width:72px;height:34px;border-radius:999px;background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);cursor:pointer;display:flex;align-items:center;justify-content:space-between;padding:0 8px;font-size:16px;color:#6b7280;font-weight:900;}
        .theme-switch span{z-index:2;user-select:none;}
        .theme-thumb{position:absolute;top:3px;right:3px;width:26px;height:26px;border-radius:999px;background:#facc15;box-shadow:0 4px 10px rgba(250,204,21,0.7);display:flex;align-items:center;justify-content:center;font-size:16px;transition:transform .25s ease,background .25s ease,box-shadow .25s ease;}
        body.dark .theme-switch{background:#020617;box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);color:#e5e7eb;}
        body.dark .theme-thumb{transform:translateX(-36px);background:#0f172a;box-shadow:0 4px 12px rgba(15,23,42,0.9);}
        .form-row-line{display:grid;grid-template-columns:repeat(10,minmax(0,1fr));gap:12px;align-items:flex-end;margin-bottom:14px;}
        @media (max-width:1000px){.form-row-line{grid-template-columns:minmax(0,1fr);}}
        .field{display:flex;flex-direction:column;gap:6px;}
        .field label{font-size:16px;color:var(--text-muted);font-weight:900;}
        input[type="text"],input[type="number"],input[type="date"]{width:100%;padding:11px 13px;border-radius:999px;border:1px solid var(--border);background:var(--input-bg);font-size:18px;font-weight:800;color:var(--text-main);}
        input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft);}
        .btn-primary,.btn-save,.btn-cancel{border-radius:999px;padding:11px 20px;border:none;cursor:pointer;font-size:16px;font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:8px;white-space:nowrap;}
        .btn-primary{background:linear-gradient(90deg,#1d4ed8,#2563eb);color:#f9fafb;box-shadow:0 14px 34px rgba(37,99,235,0.55);}
        .btn-save{background:#4b5563;color:#f9fafb;}
        .btn-cancel{background:#e5e7eb;color:#4b5563;}
        body.dark .btn-cancel{background:#111827;color:#e5e7eb;}
        .btn-primary:hover,.btn-save:hover,.btn-cancel:hover{filter:brightness(1.05);}
        .alert{padding:11px 13px;border-radius:12px;font-size:16px;margin-bottom:12px;font-weight:900;}
        .alert-error{background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.8);color:var(--danger);}
        .alert-success{background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.8);color:var(--accent-green);}

        /* ================================
           إطار + تمرير احترافي لجدول الاشتراكات (كمبيوتر + موبايل)
           ================================ */
        .table-wrapper{
            position:relative;
            margin-top:18px;
            padding:6px;
            border-radius:24px;
            border:1px solid transparent;
            overflow:hidden;
            background:
                linear-gradient(var(--card-bg),var(--card-bg)) padding-box,
                linear-gradient(135deg, rgba(37, 99, 235, 0.24), rgba(56, 189, 248, 0.10), rgba(34, 197, 94, 0.18)) border-box;
            box-shadow: 0 18px 45px rgba(15,23,42,0.10);
        }
        .table-wrapper::after{
            content:"";
            position:absolute;
            inset:6px;
            border-radius:18px;
            pointer-events:none;
            box-shadow:inset 0 0 0 1px rgba(148, 163, 184, 0.18);
        }
        body.dark .table-wrapper::after{
            box-shadow:inset 0 0 0 1px rgba(148, 163, 184, 0.12);
        }

        .table-scroll{
            position:relative;
            max-width:100%;
            max-height:70vh;
            overflow:auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-gutter: stable both-edges;
            border-radius:18px;
            background:var(--card-bg);
        }

        .table-scroll::-webkit-scrollbar{ width:12px; height:12px; }
        .table-scroll::-webkit-scrollbar-track{
            background: rgba(148, 163, 184, 0.18);
            border-radius: 999px;
            margin: 10px;
        }
        .table-scroll::-webkit-scrollbar-thumb{
            background: rgba(37,99,235,0.55);
            border-radius: 999px;
            border: 2px solid rgba(255,255,255,0.55);
        }
        body.dark .table-scroll::-webkit-scrollbar-track{
            background: rgba(255,255,255,0.08);
        }
        body.dark .table-scroll::-webkit-scrollbar-thumb{
            background: rgba(56, 189, 248, 0.55);
            border: 2px solid rgba(2, 6, 23, 0.6);
        }
        .table-scroll::-webkit-scrollbar-corner{
            background:transparent;
        }

        .table-scroll{
            scrollbar-width: thin;
            scrollbar-color: rgba(37,99,235,0.60) rgba(148,163,184,0.18);
        }
        body.dark .table-scroll{
            scrollbar-color: rgba(56,189,248,0.60) rgba(255,255,255,0.08);
        }

        table{width:100%;border-collapse:collapse;font-size:16px;min-width:1200px;}
        thead{background:rgba(15,23,42,0.03);}
        body.dark thead{background:rgba(15,23,42,0.9);}
        th,td{padding:11px 13px;border-bottom:1px solid var(--border);text-align:right;}
        th{font-weight:900;color:var(--text-muted);}
        td{font-weight:800;}

        thead th{
            position: sticky;
            top: 0;
            z-index: 3;
            background: rgba(15,23,42,0.03);
            backdrop-filter: blur(8px);
        }
        body.dark thead th{
            background: rgba(15,23,42,0.92);
        }

        tbody tr:hover{ background: rgba(37,99,235,0.06); }
        body.dark tbody tr:hover{ background: rgba(56,189,248,0.10); }

        .actions{display:flex;gap:8px;}
        .btn-edit-row,.btn-delete-row,.btn-subscribers{border-radius:999px;padding:9px 16px;border:none;cursor:pointer;font-size:14px;font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:6px;color:#f9fafb;}
        .btn-edit-row{background:#f59e0b;}
        .btn-delete-row{background:#ef4444;}
        .btn-subscribers{background:#22c55e;}
        .btn-edit-row:hover,.btn-delete-row:hover,.btn-subscribers:hover{filter:brightness(1.06);}
        .badge-discount{display:inline-flex;align-items:center;justify-content:center;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:900;background:rgba(59,130,246,0.18);color:#1d4ed8;}
        .empty{text-align:center;color:var(--text-muted);font-size:16px;padding:18px 0;font-weight:800;}

        @media (max-width: 768px){
            .table-wrapper{ padding:4px; border-radius:20px; }
            .table-wrapper::after{ inset:4px; border-radius:16px; }
            .table-scroll{ max-height:65vh; }
            table{ min-width: 1100px; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">إدارة الاشتراكات</div>
            <div class="title-sub">
                تسجيل أنواع الاشتراكات (المدة، عدد مرات التمرين، عدد الدعوات، أيام الـ Freeze، عدد جلسات السبا، المساج، الجاكوزي، الأسعار والخصومات) مع عرض عدد المشتركين في كل اشتراك.
            </div>
        </div>
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

        <?php if (!$isManager): ?>
            <div class="alert alert-error">
                لا تملك صلاحية تعديل الاشتراكات (الصلاحية المطلوبة: مدير).
            </div>
        <?php endif; ?>

        <!-- نموذج الاشتراكات -->
        <form method="post" action="" id="subscriptionForm">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="editId" value="">

            <div class="form-row-line">
                <div class="field">
                    <label for="name">اسم الاشتراك</label>
                    <input type="text" id="name" name="name" required <?php echo !$isManager ? 'disabled' : ''; ?>>
                </div>

                <div class="field">
                    <label for="subscription_category">تصنيف الاشتراك</label>
                    <input type="text" id="subscription_category" name="subscription_category" list="subscriptionCategoryList" placeholder="اكتب أو اختر تصنيفاً" <?php echo !$isManager ? 'disabled' : ''; ?>>
                    <datalist id="subscriptionCategoryList">
                        <?php foreach ($subscriptionCategories as $categoryOption): ?>
                            <option value="<?php echo htmlspecialchars((string)$categoryOption, ENT_QUOTES); ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="field">
                    <label for="days">عدد أيام الاشتراك</label>
                    <input type="number" id="days" name="days" min="1" required <?php echo !$isManager ? 'disabled' : ''; ?>>
                </div>

                <div class="field">
                    <label for="sessions">عدد مرات التمرين</label>
                    <input type="number" id="sessions" name="sessions" min="1" required <?php echo !$isManager ? 'disabled' : ''; ?>>
                </div>

                <div class="field">
                    <label for="invites">عدد الدعوات</label>
                    <input type="number" id="invites" name="invites" min="0" value="0" <?php echo !$isManager ? 'disabled' : ''; ?>>
                </div>

                <div class="field">
                    <label for="freeze_days">عدد أيام الـ Freeze</label>
                    <input type="number" id="freeze_days" name="freeze_days" min="0" value="0" <?php echo !$isManager ? 'disabled' : ''; ?>>
                </div>

                <div class="field">
                    <label for="spa_count">عدد جلسات السبا</label>
                    <input type="number" id="spa_count" name="spa_count" min="0" value="0" <?php echo !$isManager ? 'disabled' : ''; ?>>
                </div>
                <div class="field">
                    <label for="massage_count">عدد جلسات المساج</label>
                    <input type="number" id="massage_count" name="massage_count" min="0" value="0" <?php echo !$isManager ? 'disabled' : ''; ?>>
                </div>
                <div class="field">
                    <label for="jacuzzi_count">عدد جلسات الجاكوزي</label>
                    <input type="number" id="jacuzzi_count" name="jacuzzi_count" min="0" value="0" <?php echo !$isManager ? 'disabled' : ''; ?>>
                </div>

                <div class="field">
                    <label for="price">سعر الاشتراك</label>
                    <input type="number" step="0.01" id="price" name="price" min="0" required <?php echo !$isManager ? 'disabled' : ''; ?>>
                </div>

                <div class="field">
                    <label for="discount_percent">نسبة الخصم %</label>
                    <input type="number" step="0.01" id="discount_percent" name="discount_percent" min="0" max="100" value="0" <?php echo !$isManager ? 'disabled' : ''; ?>>
                </div>
            </div>

            <div class="form-row-line" style="grid-template-columns: repeat(4, minmax(0,1fr)) auto;">
                <div class="field">
                    <label for="price_after_discount">السعر بعد الخصم</label>
                    <input type="number" step="0.01" id="price_after_discount" name="price_after_discount" readonly style="background:#e5e7eb;">
                </div>

                <div class="field">
                    <label for="discount_end_date">تاريخ نهاية الخصم</label>
                    <input type="date" id="discount_end_date" name="discount_end_date" <?php echo !$isManager ? 'disabled' : ''; ?>>
                </div>

                <div class="field">
                    <label>&nbsp;</label>
                    <?php if ($isManager): ?>
                        <button type="submit" class="btn-primary" id="btnAdd">
                            <span>➕</span>
                            <span id="btnAddText">إضافة اشتراك</span>
                        </button>
                    <?php endif; ?>
                </div>

                <div class="field" id="editButtons" style="display:none;">
                    <label>&nbsp;</label>
                    <div style="display:flex;gap:8px;">
                        <button type="submit" class="btn-save">
                            <span>💾</span>
                            <span>حفظ التعديل</span>
                        </button>
                        <button type="button" class="btn-cancel" onclick="resetFormToAdd()">
                            إلغاء
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <!-- جدول الاشتراكات -->
        <div class="table-wrapper">
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>اسم الاشتراك</th>
                        <th>التصنيف</th>
                        <th>الأيام</th>
                        <th>مرات التمرين</th>
                        <th>الدعوات</th>
                        <th>أيام الـ Freeze</th>
                        <th>السبا</th>
                        <th>المساج</th>
                        <th>الجاكوزي</th>
                        <th>السعر الأساسي</th>
                        <th>الخصم</th>
                        <th>السعر الحالي</th>
                        <th>نهاية الخصم</th>
                        <th>عدد المشتركين</th>
                        <th>الإجراءات</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$subscriptions): ?>
                        <tr>
                            <td colspan="15" class="empty">لا توجد اشتراكات مسجلة حتى الآن.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($subscriptions as $s): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($s['name']); ?></td>
                                <td><?php echo htmlspecialchars($s['subscription_category'] ?: 'بدون تصنيف'); ?></td>
                                <td><?php echo (int)$s['days']; ?></td>
                                <td><?php echo (int)$s['sessions']; ?></td>
                                <td><?php echo (int)$s['invites']; ?></td>
                                <td><?php echo (int)$s['freeze_days']; ?></td>
                                <td><?php echo (int)$s['spa_count']; ?></td>
                                <td><?php echo (int)$s['massage_count']; ?></td>
                                <td><?php echo (int)$s['jacuzzi_count']; ?></td>
                                <td><?php echo number_format($s['price'], 2); ?></td>
                                <td>
                                    <span class="badge-discount">
                                        <?php echo number_format($s['discount_percent'], 2); ?>%
                                    </span>
                                </td>
                                <td><?php echo number_format($s['price_after_discount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($s['discount_end_date'] ?? '—'); ?></td>
                                <td>
                                    <?php
                                        $totalSubs  = (int)$s['subscribers_count'];
                                        $activeSubs = (int)$s['active_count'];
                                        $endedSubs  = (int)$s['ended_count'];
                                    ?>
                                    <button
                                        type="button"
                                        class="btn-subscribers"
                                        onclick="openSubscriptionMembers(<?php echo (int)$s['id']; ?>)"
                                        title="إجمالي: <?php echo $totalSubs; ?> | مستمر: <?php echo $activeSubs; ?> | منتهي: <?php echo $endedSubs; ?>"
                                    >
                                        <?php echo $totalSubs; ?> مشترك
                                    </button>
                                    <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
                                        مستمر: <?php echo $activeSubs; ?> | منتهي: <?php echo $endedSubs; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="actions">
                                        <?php if ($isManager): ?>
                                            <button
                                                type="button"
                                                class="btn-edit-row"
                                                onclick="fillEditForm(
                                                    <?php echo (int)$s['id']; ?>,
                                                    '<?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>',
                                                    '<?php echo htmlspecialchars((string)($s['subscription_category'] ?? ''), ENT_QUOTES); ?>',
                                                    <?php echo (int)$s['days']; ?>,
                                                    <?php echo (int)$s['sessions']; ?>,
                                                    <?php echo (int)$s['invites']; ?>,
                                                    <?php echo (int)$s['freeze_days']; ?>,
                                                    <?php echo (int)$s['spa_count']; ?>,
                                                    <?php echo (int)$s['massage_count']; ?>,
                                                    <?php echo (int)$s['jacuzzi_count']; ?>,
                                                    <?php echo (float)$s['price']; ?>,
                                                    <?php echo (float)$s['discount_percent']; ?>,
                                                    '<?php echo htmlspecialchars($s['discount_end_date'] ?? '', ENT_QUOTES); ?>'
                                                )"
                                            >
                                                ✏️ تعديل
                                            </button>
                                            <form method="post" action="" onsubmit="return confirm('هل أنت متأكد من حذف هذا الاشتراك؟');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                                <button type="submit" class="btn-delete-row">🗑 حذف</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="font-size:14px;color:var(--text-muted);">بدون صلاحية</span>
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

    // حساب السعر بعد الخصم تلقائياً في الواجهة
    const priceInput     = document.getElementById('price');
    const discountInput  = document.getElementById('discount_percent');
    const priceAfterEl   = document.getElementById('price_after_discount');
    // إضافات جديدة
    const spaInput       = document.getElementById('spa_count');
    const massageInput   = document.getElementById('massage_count');
    const jacuzziInput   = document.getElementById('jacuzzi_count');

    function updatePriceAfter() {
        const price    = parseFloat(priceInput.value) || 0;
        const discount = parseFloat(discountInput.value) || 0;
        const after    = price - (price * (discount / 100));
        priceAfterEl.value = after.toFixed(2);
    }

    if (priceInput && discountInput && priceAfterEl) {
        priceInput.addEventListener('input', updatePriceAfter);
        discountInput.addEventListener('input', updatePriceAfter);
    }

    // تعبئة نموذج التعديل
    function fillEditForm(id, name, category, days, sessions, invites, freezeDays, spaCount, massageCount, jacuzziCount, price, discount, discountEndDate) {
        const formAction = document.getElementById('formAction');
        const editId     = document.getElementById('editId');
        const nameInput  = document.getElementById('name');
        const categoryInput = document.getElementById('subscription_category');
        const daysInput  = document.getElementById('days');
        const sessionsIn = document.getElementById('sessions');
        const invitesIn  = document.getElementById('invites');
        const freezeIn   = document.getElementById('freeze_days');
        const spaIn      = document.getElementById('spa_count');
        const massageIn  = document.getElementById('massage_count');
        const jacuzziIn  = document.getElementById('jacuzzi_count');
        const priceIn    = document.getElementById('price');
        const discIn     = document.getElementById('discount_percent');
        const discDateIn = document.getElementById('discount_end_date');
        const btnAdd     = document.getElementById('btnAdd');
        const editBtns   = document.getElementById('editButtons');

        formAction.value       = 'edit';
        editId.value           = id;
        nameInput.value        = name;
        if (categoryInput) categoryInput.value = category || '';
        daysInput.value        = days;
        sessionsIn.value       = sessions;
        invitesIn.value        = invites;
        freezeIn.value         = freezeDays;
        spaIn.value            = spaCount;
        massageIn.value        = massageCount;
        jacuzziIn.value        = jacuzziCount;
        priceIn.value          = price;
        discIn.value           = discount;
        discDateIn.value       = discountEndDate || '';

        updatePriceAfter();

        if (btnAdd)   btnAdd.style.display   = 'none';
        if (editBtns) editBtns.style.display = 'block';

        nameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function resetFormToAdd() {
        const formAction = document.getElementById('formAction');
        const editId     = document.getElementById('editId');
        const nameInput  = document.getElementById('name');
        const categoryInput = document.getElementById('subscription_category');
        const daysInput  = document.getElementById('days');
        const sessionsIn = document.getElementById('sessions');
        const invitesIn  = document.getElementById('invites');
        const freezeIn   = document.getElementById('freeze_days');
        const spaIn      = document.getElementById('spa_count');
        const massageIn  = document.getElementById('massage_count');
        const jacuzziIn  = document.getElementById('jacuzzi_count');
        const priceIn    = document.getElementById('price');
        const discIn     = document.getElementById('discount_percent');
        const discDateIn = document.getElementById('discount_end_date');
        const btnAdd     = document.getElementById('btnAdd');
        const editBtns   = document.getElementById('editButtons');

        formAction.value    = 'add';
        editId.value        = '';
        nameInput.value     = '';
        if (categoryInput) categoryInput.value = '';
        daysInput.value     = '';
        sessionsIn.value    = '';
        invitesIn.value     = '0';
        freezeIn.value      = '0';
        spaIn.value         = '0';
        massageIn.value     = '0';
        jacuzziIn.value     = '0';
        priceIn.value       = '';
        discIn.value        = '0';
        discDateIn.value    = '';
        updatePriceAfter();

        if (btnAdd)   btnAdd.style.display   = 'inline-flex';
        if (editBtns) editBtns.style.display = 'none';
    }

    // بعد تحميل الصفحة وبدون أخطاء => نرجع لوضع "إضافة"
    <?php if (!$errors && !$success): ?>
    resetFormToAdd();
    <?php endif; ?>

    // فتح صفحة تعرض قائمة المشتركين لهذا الاشتراك
    function openSubscriptionMembers(subscriptionId) {
        if (!subscriptionId) return;
        window.location.href = 'subscription_members.php?subscription_id=' + encodeURIComponent(subscriptionId);
    }
</script>
</body>
</html>
