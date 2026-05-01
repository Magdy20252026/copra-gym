<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

// اسم الموقع
$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? '';
$isManagerOrSupervisor = ($role === 'مدير' || $role === 'مشرف');

$errors  = [];
$success = "";

// لعرض الأيام المتاحة في الفورم بعد تنفيذ العملية
$availableFreezeDaysForInput = null;

/*
    ملاحظات على قاعدة البيانات:

    جدول members يحتوي الأعمدة التالية الخاصة بالفريز:
    - freeze_days         : عدد أيام الـ Freeze المسموح بها للمشترك
    - freeze_days_used    : عدد أيام الفريز التي تم استهلاكها (قديم)
    - freeze_end_date     : تاريخ نهاية الإيقاف المؤقت الحالي إن وجد
    - freeze_start        : تاريخ بداية آخر إيقاف مؤقت
    - freeze_end          : تاريخ نهاية آخر إيقاف مؤقت
    - freeze_used_days    : عدد أيام الفريز التي تم استهلاكها في آخر إيقاف
    - used_freeze_days    : مجموع أيام الـ Freeze التي تم استخدامها

    سنستخدم:
      - freeze_days        كمجموع الأيام المتاحة
      - used_freeze_days   كمجموع الأيام التي تم استهلاكها
      - member_freeze / member_freeze_log للجداول التفصيلية (يمكن استخدامها لاحقاً إن أحببت)
*/

// معالجة POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isManagerOrSupervisor) {
    $action = $_POST['action'] ?? '';

    // 1) عمل فريز لعميل بالباركود
    if ($action === 'do_freeze') {
        $barcode      = trim($_POST['barcode'] ?? '');
        $freezeDays   = (int)($_POST['freeze_days'] ?? 0);

        if ($barcode === '') {
            $errors[] = "من فضلك أدخل باركود المشترك.";
        } elseif ($freezeDays <= 0) {
            $errors[] = "من فضلك أدخل عدد أيام إيقاف مؤقت صحيح (> 0).";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT * FROM members WHERE barcode = :bc LIMIT 1");
                $stmt->execute([':bc' => $barcode]);
                $member = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$member) {
                    $errors[] = "لا يوجد مشترك بهذا الباركود.";
                } else {
                    $memberId = (int)$member['id'];

                    // إجمالي أيام الفريز المسموح بها
                    $totalFreezeAllowed = (int)($member['freeze_days'] ?? 0);

                    // مجموع الأيام التي تم استخدامها من قبل
                    $freezeUsedTotal = (int)($member['used_freeze_days'] ?? 0);

                    // حالة الفريز الحالية:
                    // سنعتبر أن هناك فريز حالي إذا كانت الحالة 'مجمد'
                    $isCurrentlyFrozen = ($member['status'] === 'مجمد');

                    // الأيام المتبقية من رصيد الفريز
                    $remaining = max(0, $totalFreezeAllowed - $freezeUsedTotal);

                    // نخزن المتبقي للعرض في الفورم
                    $availableFreezeDaysForInput = $remaining;

                    if ($totalFreezeAllowed <= 0) {
                        $errors[] = "هذا الاشتراك لا يحتوي أي أيام فريز متاحة.";
                    } elseif ($isCurrentlyFrozen) {
                        $errors[] = "هذا المشترك لديه فريز مُفعّل حالياً حتى تاريخ " . htmlspecialchars($member['freeze_end_date'] ?? '') . ".";
                    } elseif ($freezeDays > $remaining) {
                        $errors[] = "عدد الأيام المطلوب أكبر من المتبقي من أيام الفريز ({$remaining} يوم متبقٍ).";
                    } elseif ($member['status'] !== 'مستمر') {
                        $errors[] = "لا يمكن إيقاف اشتراك غير مستمر.";
                    } else {
                        $today       = date('Y-m-d');
                        $freezeFrom  = $today;
                        $freezeTo    = date('Y-m-d', strtotime($today . ' + ' . $freezeDays . ' days'));

                        // نحدث جدول members:
                        // - نزيد used_freeze_days
                        // - نخزن freeze_start / freeze_end
                        // - نغيّر status إلى 'مجمد'
                        $stmt = $pdo->prepare("
                            UPDATE members
                            SET used_freeze_days = used_freeze_days + :fd,
                                freeze_start     = :fs,
                                freeze_end       = :fe,
                                status           = 'مجمد'
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':fd' => $freezeDays,
                            ':fs' => $freezeFrom,
                            ':fe' => $freezeTo,
                            ':id' => $memberId,
                        ]);

                        if ($stmt->rowCount() > 0) {
                            // نُسجّل العملية في جدول member_freeze
                            try {
                                $stmtFreeze = $pdo->prepare("
                                    INSERT INTO member_freeze (member_id, start_date, end_date, days, status)
                                    VALUES (:mid, :fs, :fe, :days, 'نشط')
                                ");
                                $stmtFreeze->execute([
                                    ':mid'  => $memberId,
                                    ':fs'   => $freezeFrom,
                                    ':fe'   => $freezeTo,
                                    ':days' => $freezeDays,
                                ]);
                            } catch (Exception $e) {
                                // لو فشل اللوج لا نوقف العملية الأساسية
                            }

                            // نُسجّل في جدول member_freeze_log أيضاً (اختياري)
                            try {
                                $remainingAfter = $remaining - $freezeDays;
                                $createdBy      = (int)($_SESSION['user_id'] ?? 0);

                                $stmtLog = $pdo->prepare("
                                    INSERT INTO member_freeze_log 
                                        (member_id, freeze_from, freeze_to, freeze_days, remaining_freeze, created_by)
                                    VALUES
                                        (:mid, :fs, :fe, :days, :remain_after, :uid)
                                ");
                                $stmtLog->execute([
                                    ':mid'          => $memberId,
                                    ':fs'           => $freezeFrom,
                                    ':fe'           => $freezeTo,
                                    ':days'         => $freezeDays,
                                    ':remain_after' => max(0, $remainingAfter),
                                    ':uid'          => $createdBy,
                                ]);
                            } catch (Exception $e) {
                                // تجاهل خطأ اللوج
                            }

                            $success = "تم إيقاف اشتراك المشترك مؤقتاً لمدة {$freezeDays} يوم/أيام حتى {$freezeTo}.";
                            // نحدّث المتبقي بعد العملية للعرض
                            $availableFreezeDaysForInput = max(0, $remaining - $freezeDays);
                        } else {
                            $errors[] = "تعذر تحديث بيانات المشترك للفريز.";
                        }
                    }
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء تنفيذ عملية الفريز.";
            }
        }
    }

    // 2) تحديث حالات الفريز المنتهي تلقائياً
    if ($action === 'update_freeze') {
        try {
            $today = date('Y-m-d');

            // نجلب كل من لديهم فريز نشط وانتهى وقته (من جدول member_freeze)
            $stmt = $pdo->prepare("
                SELECT mf.id AS freeze_id, mf.member_id, mf.days, m.start_date, m.end_date
                FROM member_freeze mf
                JOIN members m ON m.id = mf.member_id
                WHERE mf.status = 'نشط'
                  AND mf.end_date <= :today
            ");
            $stmt->execute([':today' => $today]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                $success = "لا توجد اشتراكات بحاجة لتحديث الفريز الآن.";
            } else {
                $pdo->beginTransaction();

                // تحديث بيانات المشترك
                $updateMember = $pdo->prepare("
                    UPDATE members
                    SET start_date   = DATE_ADD(start_date, INTERVAL :days DAY),
                        end_date     = DATE_ADD(end_date,   INTERVAL :days DAY),
                        status       = 'مستمر',
                        freeze_start = NULL,
                        freeze_end   = NULL
                    WHERE id = :mid
                ");

                // تحديث جدول member_freeze إلى منتهي
                $updateFreeze = $pdo->prepare("
                    UPDATE member_freeze
                    SET status = 'منتهي'
                    WHERE id = :fid
                ");

                foreach ($rows as $r) {
                    $daysToShift = (int)$r['days'];
                    if ($daysToShift <= 0) {
                        // حتى لو 0 أيام، ننهي الفريز فقط
                        $updateFreeze->execute([':fid' => (int)$r['freeze_id']]);
                        continue;
                    }

                    $updateMember->execute([
                        ':days' => $daysToShift,
                        ':mid'  => (int)$r['member_id'],
                    ]);

                    $updateFreeze->execute([
                        ':fid' => (int)$r['freeze_id'],
                    ]);
                }

                $pdo->commit();
                $success = "تم تحديث حالات الفريز لجميع الاشتراكات التي انتهى وقت إيقافها المؤقت.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = "حدث خطأ أثناء تحديث حالات الفريز.";
        }
    }
}

// لعرض آخر حالة بحثنا عنها
$lastMember = null;
if (isset($_POST['action']) && $_POST['action'] === 'do_freeze' && empty($errors)) {
    // تمت العملية بنجاح، نعرض بياناته بعد التحديث
    try {
        $stmt = $pdo->prepare("SELECT * FROM members WHERE barcode = :bc LIMIT 1");
        $stmt->execute([':bc' => trim($_POST['barcode'] ?? '')]);
        $lastMember = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة الفريز (إيقاف مؤقت) - <?php echo htmlspecialchars($siteName); ?></title>
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
        .page { max-width: 1100px; margin: 30px auto 40px; padding: 0 20px; }
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
            transition:transform .25s.ease,background .25s.ease,box-shadow .25s.ease;
        }
        body.dark .theme-switch{background:#020617;box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);color:#e5e7eb;}
        body.dark .theme-thumb{transform:translateX(-36px);background:#0f172a;box-shadow:0 4px 12px rgba(15,23,42,0.9);}
        .alert{padding:11px 13px;border-radius:12px;font-size:16px;margin-bottom:12px;font-weight:900;}
        .alert-error{background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.9);color:var(--danger);}
        .alert-success{background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.9);color:var(--accent-green);}
        .field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px;}
        .field label{font-size:16px;color:var(--text-muted);font-weight:900;}
        input[type="text"],input[type="number"]{
            width:100%;padding:10px 14px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:17px;font-weight:800;color:var(--text-main);
        }
        input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft);}
        .btn-main{
            border-radius:999px;padding:10px 22px;border:none;cursor:pointer;font-size:17px;
            font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:8px;
            background:linear-gradient(90deg,#22c55e,#16a34a);color:#f9fafb;
            box-shadow:0 18px 40px rgba(22,163,74,0.7);text-decoration:none;
        }
        .btn-main:hover{filter:brightness(1.06);}
        .btn-secondary{
            border-radius:999px;padding:9px 18px;border:none;cursor:pointer;font-size:15px;
            font-weight:900;background:#e5e7eb;color:#374151;
        }
        body.dark .btn-secondary{background:#111827;color:#e5e7eb;}
        .muted{font-size:14px;color:var(--text-muted);font-weight:700;}
        table{width:100%;border-collapse:collapse;margin-top:10px;font-size:15px;}
        th,td{padding:8px 10px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;}
        th{font-weight:900;color:var(--text-muted);background:rgba(15,23,42,0.03);}
        body.dark th{background:rgba(15,23,42,0.5);}
        #available-freeze-msg { margin-top:4px; font-size:14px; font-weight:800; }
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div class="title-main">إدارة الإيقاف المؤقت (Freeze)</div>
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

        <?php if (!$isManagerOrSupervisor): ?>
            <div class="alert alert-error">
                لا تملك صلاحية إدارة الفريز (الصلاحية المطلوبة: مدير أو مشرف).
            </div>
        <?php else: ?>
            <form method="post" action="" style="margin-bottom:16px;">
                <input type="hidden" name="action" value="do_freeze">
                <div class="field">
                    <label for="barcode">باركود المشترك الذي تريد عمل فريز له</label>
                    <input type="text" id="barcode" name="barcode" required>
                    <div id="available-freeze-msg" class="muted"></div>
                </div>
                <div class="field">
                    <label for="freeze_days">عدد أيام الإيقاف المؤقت (Freeze)</label>
                    <input type="number" id="freeze_days" name="freeze_days" min="1" required>
                    <div class="muted">
                        سيتم خصم هذه الأيام من إجمالي أيام الفريز المتاحة للمشترك.
                        <?php if ($availableFreezeDaysForInput !== null): ?>
                            <br>الأيام المتاحة حالياً لهذا المشترك بعد آخر عملية:
                            <strong><?php echo (int)$availableFreezeDaysForInput; ?> يوم</strong>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="submit" class="btn-main">
                    <span>⏸️</span>
                    <span>تنفيذ الفريز</span>
                </button>
            </form>

            <form method="post" action="">
                <input type="hidden" name="action" value="update_freeze">
                <button type="submit" class="btn-secondary">
                    <span>🔄</span>
                    <span>تحديث حالات الفريز المنتهي وإرجاع الاشتراكات إلى مستمرة</span>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($lastMember): ?>
        <div class="card">
            <h3 style="margin-top:0;margin-bottom:10px;font-size:20px;">بيانات آخر مشترك تم التعامل معه</h3>
            <table>
                <tr>
                    <th>الاسم</th>
                    <td><?php echo htmlspecialchars($lastMember['name']); ?></td>
                    <th>الباركود</th>
                    <td><?php echo htmlspecialchars($lastMember['barcode']); ?></td>
                </tr>
                <tr>
                    <th>بداية الاشتراك</th>
                    <td><?php echo htmlspecialchars($lastMember['start_date']); ?></td>
                    <th>نهاية الاشتراك</th>
                    <td><?php echo htmlspecialchars($lastMember['end_date']); ?></td>
                </tr>
                <tr>
                    <th>إجمالي أيام الفريز المسموحة</th>
                    <td><?php echo (int)$lastMember['freeze_days']; ?></td>
                    <th>أيام الفريز المستخدمة (إجمالي)</th>
                    <td><?php echo (int)($lastMember['used_freeze_days'] ?? 0); ?></td>
                </tr>
                <tr>
                    <th>آخر بداية فريز</th>
                    <td><?php echo htmlspecialchars($lastMember['freeze_start'] ?? ''); ?></td>
                    <th>آخر نهاية فريز</th>
                    <td><?php echo htmlspecialchars($lastMember['freeze_end'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>حالة الاشتراك</th>
                    <td colspan="3"><?php echo htmlspecialchars($lastMember['status']); ?></td>
                </tr>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
    const body = document.body;
    const switchEl = document.getElementById('themeSwitch');
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

    // ***** إظهار أيام الفريز المتاحة بمجرد كتابة الباركود (AJAX) *****
    const barcodeInput = document.getElementById('barcode');
    const msgEl = document.getElementById('available-freeze-msg');
    let barcodeTimeout = null;

    function fetchFreezeInfo(barcode) {
        if (!barcode) {
            msgEl.textContent = '';
            return;
        }
        msgEl.textContent = 'جاري البحث عن المشترك...';

        fetch('ajax_get_freeze_days.php?barcode=' + encodeURIComponent(barcode))
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    msgEl.textContent = data.message || 'لم يتم العثور على مشترك بهذا الباركود.';
                    return;
                }
                msgEl.textContent =
                    'المشترك: ' + data.name +
                    ' | إجمالي الفريز: ' + data.total_freeze +
                    ' يوم | المستخدم: ' + data.used_freeze +
                    ' يوم | المتبقي: ' + data.remaining_freeze + ' يوم';
            })
            .catch(() => {
                msgEl.textContent = 'حدث خطأ أثناء جلب بيانات الفريز.';
            });
    }

    if (barcodeInput && msgEl) {
        barcodeInput.addEventListener('input', () => {
            clearTimeout(barcodeTimeout);
            barcodeTimeout = setTimeout(() => {
                fetchFreezeInfo(barcodeInput.value.trim());
            }, 400); // ينتظر 0.4 ثانية بعد توقف الكتابة
        });

        barcodeInput.addEventListener('blur', () => {
            fetchFreezeInfo(barcodeInput.value.trim());
        });
    }
</script>
</body>
</html>