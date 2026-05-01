<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'user_permissions_helpers.php';
require_once 'whatsapp_helpers.php';

ensureUserPermissionsSchema($pdo);

$siteName = 'Gym System';
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {
}

$role = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$isManager = ($role === 'مدير');
$canViewPage = false;

if ($isManager) {
    $canViewPage = true;
} elseif ($role === 'مشرف' && $userId > 0) {
    try {
        $stmtPerm = $pdo->prepare("SELECT can_view_members FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $canViewPage = (int)$rowPerm['can_view_members'] === 1;
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
$success = '';
$resultSummary = null;
$processedResults = [];
$automationResult = [];
$launchQueue = [];
$launchMode = '';
$activeTab = 'promo';
$promoMessage = '';
$countryCode = trim((string)($_POST['country_code'] ?? '20'));
$delaySeconds = (string)WHATSAPP_AUTOMATION_DEFAULT_DELAY_SECONDS;
$browserLaunchExtraWaitMs = WHATSAPP_AUTOMATION_BETWEEN_MESSAGES_WAIT_MS;
$browserLaunchDelayMs = max(WHATSAPP_AUTOMATION_MIN_OPEN_CHAT_MS, ((int)$delaySeconds) * 1000)
    + $browserLaunchExtraWaitMs;
$isXmlHttpRequest = strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest';
$isAjaxPrepareRequest = $_SERVER['REQUEST_METHOD'] === 'POST'
    && $isXmlHttpRequest
    && isset($_POST['ajax_prepare'])
    && (string)$_POST['ajax_prepare'] === '1';
$desktopAutomationAvailability = getWhatsAppDesktopAutomationAvailability($_SERVER, $_POST);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actionType = $_POST['action_type'] ?? 'promo';
    $activeTab = $actionType === 'debts' ? 'debts' : 'promo';
    $promoMessage = trim((string)($_POST['promo_message'] ?? ''));

    $countryCodeDigits = preg_replace('/\D+/', '', $countryCode);
    if ($countryCodeDigits === '') {
        $errors[] = 'من فضلك أدخل كود الدولة بصيغة أرقام فقط مثل 20 أو 966.';
    } else {
        $countryCode = $countryCodeDigits;
    }

    $delaySeconds = (string)WHATSAPP_AUTOMATION_DEFAULT_DELAY_SECONDS;

    if ($actionType === 'promo' && $promoMessage === '') {
        $errors[] = 'من فضلك اكتب رسالة الدعاية أولاً.';
    }

    if (!$errors) {
        if ($actionType === 'debts') {
            $stmt = $pdo->query("
                SELECT name, phone, remaining_amount
                FROM members
                WHERE phone IS NOT NULL
                  AND phone <> ''
                  AND remaining_amount > 0
                  AND status IN ('مستمر', 'مجمد')
                ORDER BY name ASC
            ");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $prepared = prepareWhatsAppJobs(
                $rows,
                function (array $row) use ($siteName): string {
                    return buildDebtWhatsAppMessage((string)$row['name'], $siteName, (float)$row['remaining_amount']);
                },
                $countryCode
            );
        } else {
            $stmt = $pdo->query("
                SELECT name, phone
                FROM members
                WHERE phone IS NOT NULL
                  AND phone <> ''
                  AND status IN ('مستمر', 'مجمد')
                ORDER BY name ASC
            ");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $prepared = prepareWhatsAppJobs(
                $rows,
                function () use ($promoMessage): string {
                    return $promoMessage;
                },
                $countryCode
            );
        }

        $jobs = $prepared['jobs'];
        if (!$jobs) {
            $errors[] = 'لا توجد أرقام صالحة للإرسال بعد تنسيق الأرقام.';
        } else {
            if (($desktopAutomationAvailability['driver'] ?? '') === 'browser') {
                $launchQueue = buildWhatsAppLaunchQueue($jobs);
                $launchMode = 'browser';
                $processedResults = array_values(array_map(function (array $item): array {
                    return [
                        'phone' => (string)($item['phone'] ?? ''),
                        'member_name' => (string)($item['member_name'] ?? ''),
                        'message' => (string)($item['message'] ?? ''),
                        'status' => 'ready',
                    ];
                }, $launchQueue));
                $automationResult = [
                    'ok' => !empty($launchQueue),
                    'message' => 'تم تجهيز ' . count($launchQueue) . ' محادثة لفتحها بالتتابع داخل WhatsApp Desktop. سيتم فتح كل محادثة مع كتابة الرسالة، ثم تضغط أنت على إرسال داخل واتساب وبعدها ترجع للصفحة لفتح الرقم التالي.',
                    'sent_count' => 0,
                    'failed_count' => 0,
                    'results' => $processedResults,
                    'launch_queue' => $launchQueue,
                    'launch_mode' => $launchMode,
                ];
            } elseif (!empty($desktopAutomationAvailability['ok'])) {
                try {
                    $automationResult = runWhatsAppDesktopAutomation($jobs, (int)$delaySeconds);
                } catch (Throwable $e) {
                    $automationResult = [
                        'ok' => false,
                        'message' => 'حدث خطأ أثناء محاولة الإرسال التلقائي. أعد المحاولة وتأكد من أن WhatsApp Desktop مفتوح على نفس الجهاز.',
                        'sent_count' => 0,
                        'failed_count' => count($jobs),
                        'results' => [],
                    ];
                }
            } else {
                $automationResult = [
                    'ok' => false,
                    'message' => (string)($desktopAutomationAvailability['message'] ?? 'الإرسال التلقائي الكامل يحتاج تشغيل الصفحة من نفس جهاز Windows المثبت عليه WhatsApp Desktop.'),
                    'sent_count' => 0,
                    'failed_count' => count($jobs),
                    'results' => [],
                ];
            }
            if (!$processedResults) {
                $processedResults = $automationResult['results'] ?? [];
            }
            if (empty($launchQueue)) {
                $launchQueue = $automationResult['launch_queue'] ?? [];
            }
            if ($launchMode === '') {
                $launchMode = (string)($automationResult['launch_mode'] ?? '');
            }

            $resultSummary = [
                'total_rows' => count($rows),
                'ready_jobs' => count($jobs),
                'invalid_rows' => count($prepared['invalid_rows']),
                'sent_jobs' => (int)($automationResult['sent_count'] ?? 0),
                'failed_jobs' => (int)($automationResult['failed_count'] ?? 0),
            ];

            if (!empty($automationResult['ok'])) {
                $success = (string)($automationResult['message'] ?? '');
            } else {
                $errors[] = (string)($automationResult['message'] ?? 'تعذر تنفيذ الإرسال التلقائي حالياً.');
            }
        }
    }
}

if ($isAjaxPrepareRequest) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($errors ? 422 : 200);
    echo json_encode([
        'ok' => !$errors,
        'errors' => array_values($errors),
        'success' => $success,
        'result_summary' => $resultSummary,
        'processed_results' => array_values($processedResults),
        'launch_queue' => array_values($launchQueue),
        'launch_mode' => $launchMode,
        'browser_launch_delay_ms' => $browserLaunchDelayMs,
        'active_tab' => $activeTab,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>رسائل واتساب - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        body {
            margin: 0;
            min-height: 100vh;
            background: #f3f4f6;
            color: #111827;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .page {
            max-width: 1180px;
            margin: 0 auto;
            padding: 24px 18px 40px;
        }
        .header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            gap: 14px;
            align-items: center;
            margin-bottom: 20px;
        }
        .title-main {
            font-size: 28px;
            font-weight: 900;
        }
        .title-sub {
            margin-top: 6px;
            color: #4b5563;
            font-size: 15px;
            line-height: 1.8;
            font-weight: 700;
        }
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            background: #2563eb;
            color: #fff;
            padding: 12px 18px;
            border-radius: 999px;
            font-weight: 900;
            box-shadow: 0 16px 28px rgba(37, 99, 235, 0.28);
        }
        .note {
            margin-bottom: 18px;
            background: #ecfdf5;
            border: 1px solid #86efac;
            color: #166534;
            border-radius: 18px;
            padding: 14px 16px;
            line-height: 1.9;
            font-size: 15px;
            font-weight: 700;
        }
        .alerts {
            display: grid;
            gap: 10px;
            margin-bottom: 16px;
        }
        .alert {
            border-radius: 16px;
            padding: 12px 14px;
            line-height: 1.8;
            font-size: 15px;
            font-weight: 800;
        }
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
        }
        .alert-success {
            background: #ecfdf5;
            border: 1px solid #86efac;
            color: #166534;
        }
        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(320px, 0.8fr);
            gap: 18px;
            align-items: start;
        }
        .card {
            background: #fff;
            border-radius: 24px;
            padding: 20px;
            box-shadow: 0 20px 45px rgba(15,23,42,0.08);
        }
        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 18px;
        }
        .tab-btn {
            border: none;
            border-radius: 999px;
            padding: 11px 16px;
            background: #e5e7eb;
            color: #111827;
            font-weight: 900;
            cursor: pointer;
        }
        .tab-btn.active {
            background: #22c55e;
            color: #fff;
        }
        .field { margin-bottom: 14px; }
        .field label {
            display: block;
            margin-bottom: 6px;
            font-size: 15px;
            color: #4b5563;
            font-weight: 900;
        }
        input[type="text"], input[type="number"], textarea {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 16px;
            padding: 12px 14px;
            font-size: 15px;
            font-weight: 700;
            font-family: inherit;
        }
        textarea {
            min-height: 180px;
            resize: vertical;
            line-height: 1.9;
        }
        .field-help {
            margin-top: 6px;
            color: #6b7280;
            font-size: 13px;
            line-height: 1.8;
            font-weight: 700;
        }
        .submit-btn, .secondary-btn {
            border: none;
            border-radius: 999px;
            padding: 12px 18px;
            font-size: 15px;
            font-weight: 900;
            cursor: pointer;
        }
        .submit-btn[disabled], .secondary-btn[disabled] {
            cursor: not-allowed;
            opacity: 0.7;
        }
        .submit-btn {
            background: #16a34a;
            color: #fff;
            box-shadow: 0 16px 24px rgba(22, 163, 74, 0.24);
        }
        .secondary-btn {
            background: #2563eb;
            color: #fff;
        }
        .queue-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 14px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 10px;
            margin-top: 16px;
        }
        .stat {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 12px;
            text-align: center;
        }
        .stat strong {
            display: block;
            font-size: 24px;
            margin-bottom: 4px;
        }
        .list-box {
            max-height: 420px;
            overflow: auto;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 10px;
            background: #f8fafc;
        }
        .prepared-item {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            padding: 12px;
            margin-bottom: 10px;
        }
        .prepared-item:last-child {
            margin-bottom: 0;
        }
        .prepared-name {
            font-weight: 900;
            margin-bottom: 4px;
        }
        .prepared-phone {
            color: #4b5563;
            font-size: 13px;
            margin-bottom: 8px;
            font-weight: 800;
        }
        .prepared-message {
            white-space: pre-wrap;
            line-height: 1.8;
            font-size: 13px;
            color: #111827;
        }
        .prepared-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 900;
            margin-bottom: 10px;
        }
        .prepared-status-sent {
            background: #dcfce7;
            color: #166534;
        }
        .prepared-status-failed {
            background: #fee2e2;
            color: #991b1b;
        }
        .prepared-status-ready,
        .prepared-status-launched {
            background: #dbeafe;
            color: #1d4ed8;
        }
        .prepared-error {
            margin-top: 8px;
            padding: 10px 12px;
            border-radius: 12px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            font-size: 13px;
            line-height: 1.7;
            font-weight: 700;
        }
        .hidden { display: none; }
        @media (max-width: 900px) {
            .layout {
                grid-template-columns: minmax(0, 1fr);
            }
            .summary-grid {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div>
            <div class="title-main">رسائل واتساب</div>
            <div class="title-sub">إرسال دعاية لكل المشتركين الحاليين أو رسائل المبالغ المتبقية من خلال WhatsApp Desktop بحيث تُفتح كل محادثة مع الرسالة جاهزة ثم تضغط أنت على إرسال وتفتح الرقم التالي من الصفحة.</div>
        </div>
        <a href="dashboard.php" class="back-button">
            <span>↩️</span>
            <span>العودة إلى لوحة التحكم</span>
        </a>
    </div>

    <?php if (!empty($desktopAutomationAvailability['ok'])): ?>
        <div class="note">
            <?php if (($desktopAutomationAvailability['driver'] ?? '') === 'browser'): ?>
                <?php echo htmlspecialchars((string)($desktopAutomationAvailability['message'] ?? 'سيتم فتح كل محادثة بالتتابع داخل WhatsApp Desktop من نفس جهاز Windows الحالي عبر المتصفح.')); ?>
            <?php else: ?>
                بعد الضغط على زر الإرسال ستفتح الصفحة كل محادثة داخل <strong>WhatsApp Desktop</strong> مع كتابة الرسالة تلقائياً، ثم تضغط أنت على إرسال داخل واتساب وتعود للصفحة لفتح الرقم التالي.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="note">
            <?php echo htmlspecialchars((string)($desktopAutomationAvailability['message'] ?? 'الإرسال التلقائي الكامل يحتاج تشغيل الصفحة من نفس جهاز Windows المثبت عليه WhatsApp Desktop.')); ?>
        </div>
    <?php endif; ?>

        <div class="alerts<?php echo ($errors || $success) ? '' : ' hidden'; ?>" id="alertsBox">
            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
        </div>

    <div class="layout">
        <div class="card">
            <div class="tabs">
                <button type="button" class="tab-btn <?php echo $activeTab === 'promo' ? 'active' : ''; ?>" data-target="promo-pane">إرسال رسالة دعاية</button>
                <button type="button" class="tab-btn <?php echo $activeTab === 'debts' ? 'active' : ''; ?>" data-target="debts-pane">إرسال رسائل للمتبقي عليهم مبالغ</button>
            </div>

            <div id="promo-pane" class="<?php echo $activeTab === 'promo' ? '' : 'hidden'; ?>">
                <form method="post" action="" class="whatsapp-send-form">
                    <input type="hidden" name="action_type" value="promo">
                    <input type="hidden" name="delay_seconds" value="<?php echo htmlspecialchars($delaySeconds); ?>">
                    <input type="hidden" name="client_platform" class="client-platform-input" value="">
                    <div class="field">
                        <label for="promo_message">رسالة الدعاية</label>
                        <textarea id="promo_message" name="promo_message" placeholder="اكتب رسالة الدعاية التي سيتم إرسالها لكل المشتركين..."><?php echo htmlspecialchars($promoMessage); ?></textarea>
                    </div>

                    <div class="field">
                        <label for="country_code_promo">كود الدولة</label>
                        <input type="text" id="country_code_promo" name="country_code" value="<?php echo htmlspecialchars($countryCode); ?>" placeholder="مثال: 20">
                        <div class="field-help">سيتم تحويل الأرقام المحلية مثل 010... إلى الصيغة الدولية باستخدام هذا الكود.</div>
                    </div>

                    <div class="field-help">بعد فتح كل محادثة ستكون الرسالة مكتوبة تلقائياً داخل واتساب، ثم تضغط أنت على إرسال وتعود هنا لفتح الرقم التالي.</div>

                    <button type="submit" class="submit-btn">إرسال رسالة الدعاية</button>
                </form>
            </div>

            <div id="debts-pane" class="<?php echo $activeTab === 'debts' ? '' : 'hidden'; ?>">
                <form method="post" action="" class="whatsapp-send-form">
                    <input type="hidden" name="action_type" value="debts">
                    <input type="hidden" name="delay_seconds" value="<?php echo htmlspecialchars($delaySeconds); ?>">
                    <input type="hidden" name="client_platform" class="client-platform-input" value="">

                    <div class="field">
                        <label>نص الرسالة الثابتة</label>
                        <textarea readonly>مساء الخير كابتن [اسم المشترك] برجاء التوجه الي رسيبشن <?php echo htmlspecialchars($siteName); ?> لدفع المبلغ المتبقي من الاشتراك [المبلغ المتبقي]</textarea>
                        <div class="field-help">سيتم استبدال اسم المشترك والمبلغ المتبقي تلقائياً لكل رقم.</div>
                    </div>

                    <div class="field">
                        <label for="country_code_debts">كود الدولة</label>
                        <input type="text" id="country_code_debts" name="country_code" value="<?php echo htmlspecialchars($countryCode); ?>" placeholder="مثال: 20">
                        <div class="field-help">سيتم تحويل الأرقام المحلية مثل 010... إلى الصيغة الدولية باستخدام هذا الكود.</div>
                    </div>

                    <div class="field-help">بعد فتح كل محادثة ستكون الرسالة مكتوبة تلقائياً داخل واتساب، ثم تضغط أنت على إرسال وتعود هنا لفتح الرقم التالي.</div>

                    <button type="submit" class="submit-btn">إرسال رسائل المبالغ المتبقية</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="title-main" style="font-size:22px;">النتيجة</div>
            <div class="title-sub" style="margin-bottom:14px;">بعد التنفيذ ستجد هنا ملخص الرسائل التي تم تجهيزها وما إذا كانت أُرسلت بنجاح أو تعذر إرسالها.</div>

            <div id="resultEmptyState" class="field-help<?php echo $resultSummary ? ' hidden' : ''; ?>">اختر نوع الإرسال ثم نفّذ العملية لعرض الأرقام المحضّرة هنا.</div>

            <div class="summary-grid<?php echo $resultSummary ? '' : ' hidden'; ?>" id="resultSummaryGrid">
                <div class="stat">
                    <strong id="summaryTotalRows"><?php echo (int)($resultSummary['total_rows'] ?? 0); ?></strong>
                    <span>إجمالي المشتركين المطابقين</span>
                </div>
                <div class="stat">
                    <strong id="summaryReadyJobs"><?php echo (int)($resultSummary['ready_jobs'] ?? 0); ?></strong>
                    <span>أرقام صالحة</span>
                </div>
                <div class="stat">
                    <strong id="summaryInvalidRows"><?php echo (int)($resultSummary['invalid_rows'] ?? 0); ?></strong>
                    <span>أرقام مستبعدة</span>
                </div>
                <div class="stat">
                    <strong id="summarySentJobs"><?php echo (int)($resultSummary['sent_jobs'] ?? 0); ?></strong>
                    <span>تم إرسالها</span>
                </div>
                <div class="stat">
                    <strong id="summaryFailedJobs"><?php echo (int)($resultSummary['failed_jobs'] ?? 0); ?></strong>
                    <span>تعذر إرسالها</span>
                </div>
            </div>

            <div class="field-help<?php echo $processedResults ? '' : ' hidden'; ?>" id="executionStatus" style="margin-top:14px;">
                <?php if ($processedResults): ?>
                    <?php if ($launchMode === 'browser'): ?>
                        تم تجهيز الرسائل. بعد فتح كل محادثة داخل WhatsApp Desktop اضغط على إرسال هناك، ثم ارجع للصفحة لفتح الرقم التالي.
                    <?php else: ?>
                        اكتمل تنفيذ الإرسال التلقائي لهذه الدفعة. راجع النتائج التفصيلية بالأسفل.
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="queue-controls hidden" id="queueControls">
                <button type="button" class="secondary-btn" id="nextQueueButton">تم الإرسال، افتح الرقم التالي</button>
            </div>

            <div class="list-box<?php echo $processedResults ? '' : ' hidden'; ?>" id="preparedListBox" style="margin-top:14px;">
                <?php foreach ($processedResults as $item): ?>
                    <?php
                        $itemStatus = (string)($item['status'] ?? '');
                        if ($itemStatus === 'sent') {
                            $itemStatusClass = 'sent';
                            $itemStatusLabel = 'تم الإرسال';
                        } elseif ($itemStatus === 'launched') {
                            $itemStatusClass = 'launched';
                            $itemStatusLabel = 'تم الفتح - بانتظار الإرسال';
                        } elseif ($itemStatus === 'ready') {
                            $itemStatusClass = 'ready';
                            $itemStatusLabel = 'جاهز للفتح';
                        } else {
                            $itemStatusClass = 'failed';
                            $itemStatusLabel = 'فشل الإرسال';
                        }
                    ?>
                    <div class="prepared-item">
                        <div class="prepared-status prepared-status-<?php echo $itemStatusClass; ?>">
                            <?php echo $itemStatusLabel; ?>
                        </div>
                        <div class="prepared-name"><?php echo htmlspecialchars($item['member_name'] !== '' ? $item['member_name'] : 'بدون اسم'); ?></div>
                        <div class="prepared-phone"><?php echo htmlspecialchars($item['phone']); ?></div>
                        <div class="prepared-message"><?php echo htmlspecialchars($item['message']); ?></div>
                        <?php if (!empty($item['error'])): ?>
                            <div class="prepared-error"><?php echo htmlspecialchars($item['error']); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    class WhatsAppRequestError extends Error {
        constructor(message, responseData) {
            super(message);
            this.name = 'WhatsAppRequestError';
            this.responseData = responseData;
        }
    }

    const alertsBox = document.getElementById('alertsBox');
    const resultEmptyState = document.getElementById('resultEmptyState');
    const resultSummaryGrid = document.getElementById('resultSummaryGrid');
    const summaryTotalRows = document.getElementById('summaryTotalRows');
    const summaryReadyJobs = document.getElementById('summaryReadyJobs');
    const summaryInvalidRows = document.getElementById('summaryInvalidRows');
    const summarySentJobs = document.getElementById('summarySentJobs');
    const summaryFailedJobs = document.getElementById('summaryFailedJobs');
    const preparedListBox = document.getElementById('preparedListBox');
    const executionStatus = document.getElementById('executionStatus');
    const queueControls = document.getElementById('queueControls');
    const nextQueueButton = document.getElementById('nextQueueButton');
    let requestInProgress = false;
    let activeSubmitButton = null;
    let browserLaunchWindow = null;
    let browserLaunchWindowBlocked = false;
    const setExecutionStatus = function (message) {
        if (executionStatus) {
            executionStatus.textContent = message;
            executionStatus.classList.remove('hidden');
        }
    };

    const escapeHtml = function (value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    };

    const renderAlerts = function (errors, successMessage) {
        const alertItems = [];

        (Array.isArray(errors) ? errors : []).forEach(function (errorMessage) {
            alertItems.push('<div class="alert alert-error">' + escapeHtml(errorMessage) + '</div>');
        });

        if (successMessage) {
            alertItems.push('<div class="alert alert-success">' + escapeHtml(successMessage) + '</div>');
        }

        alertsBox.innerHTML = alertItems.join('');
        alertsBox.classList.toggle('hidden', alertItems.length === 0);
    };

    const renderPreparedResults = function (resultSummary, processedItems) {
        const hasResults = !!(resultSummary && typeof resultSummary === 'object');
        resultEmptyState.classList.toggle('hidden', hasResults);
        resultSummaryGrid.classList.toggle('hidden', !hasResults);

        if (hasResults) {
            summaryTotalRows.textContent = String(resultSummary.total_rows || 0);
            summaryReadyJobs.textContent = String(resultSummary.ready_jobs || 0);
            summaryInvalidRows.textContent = String(resultSummary.invalid_rows || 0);
            summarySentJobs.textContent = String(resultSummary.sent_jobs || 0);
            summaryFailedJobs.textContent = String(resultSummary.failed_jobs || 0);
        } else {
            summaryTotalRows.textContent = '0';
            summaryReadyJobs.textContent = '0';
            summaryInvalidRows.textContent = '0';
            summarySentJobs.textContent = '0';
            summaryFailedJobs.textContent = '0';
        }

        if (!Array.isArray(processedItems) || !processedItems.length) {
            preparedListBox.innerHTML = '';
            preparedListBox.classList.add('hidden');
            executionStatus.classList.add('hidden');
            return;
        }

        preparedListBox.innerHTML = processedItems.map(function (item) {
            const memberName = item.member_name && item.member_name !== '' ? item.member_name : 'بدون اسم';
            let itemStatus = 'فشل الإرسال';
            let statusClass = 'prepared-status-failed';
            if (item.status === 'sent') {
                itemStatus = 'تم الإرسال';
                statusClass = 'prepared-status-sent';
            } else if (item.status === 'launched') {
                itemStatus = 'تم الفتح - بانتظار الإرسال';
                statusClass = 'prepared-status-launched';
            } else if (item.status === 'ready') {
                itemStatus = 'جاهز للفتح';
                statusClass = 'prepared-status-ready';
            }
            const errorHtml = item.error
                ? '<div class="prepared-error">' + escapeHtml(item.error) + '</div>'
                : '';
            return ''
                + '<div class="prepared-item">'
                + '<div class="prepared-status ' + statusClass + '">' + itemStatus + '</div>'
                + '<div class="prepared-name">' + escapeHtml(memberName) + '</div>'
                + '<div class="prepared-phone">' + escapeHtml(item.phone || '') + '</div>'
                + '<div class="prepared-message">' + escapeHtml(item.message || '') + '</div>'
                + errorHtml
                + '</div>';
        }).join('');
        preparedListBox.classList.remove('hidden');
        executionStatus.classList.remove('hidden');
    };

    const setQueueControlsState = function (visible, buttonText, disabled) {
        if (!queueControls || !nextQueueButton) {
            return;
        }

        queueControls.classList.toggle('hidden', !visible);
        if (buttonText) {
            nextQueueButton.textContent = buttonText;
        }
        nextQueueButton.disabled = !!disabled;
    };

    const setSubmitButtonsBusy = function (busy, activeButtonText) {
        document.querySelectorAll('.submit-btn').forEach(function (button) {
            if (!button.dataset.originalText) {
                button.dataset.originalText = button.textContent;
            }

            button.disabled = busy;
            if (busy && activeSubmitButton && button === activeSubmitButton && activeButtonText) {
                button.textContent = activeButtonText;
            } else {
                button.textContent = button.dataset.originalText;
            }
        });
    };

    const detectClientPlatform = function () {
        if (navigator.userAgentData && typeof navigator.userAgentData.platform === 'string' && navigator.userAgentData.platform) {
            return navigator.userAgentData.platform;
        }
        if (typeof navigator.platform === 'string' && navigator.platform) {
            return navigator.platform;
        }
        if (typeof navigator.userAgent === 'string') {
            return navigator.userAgent;
        }
        return '';
    };

    const clientPlatform = detectClientPlatform();
    document.querySelectorAll('.client-platform-input').forEach(function (input) {
        input.value = clientPlatform;
    });

    const resetRequestState = function (message) {
        requestInProgress = false;
        setSubmitButtonsBusy(false);
        setExecutionStatus(message);
        setQueueControlsState(false, 'تم الإرسال، افتح الرقم التالي', false);
        activeSubmitButton = null;
    };

    const isSafeWhatsAppDesktopLink = function (value) {
        try {
            const desktopUrl = new URL(String(value || ''));
            return desktopUrl.protocol === 'whatsapp:'
                && desktopUrl.hostname === 'send'
                && desktopUrl.searchParams.has('phone')
                && desktopUrl.searchParams.has('text');
        } catch (error) {
            return false;
        }
    };

    const buildBrowserLaunchWindowMarkup = function (statusMessage) {
        return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>مشغل واتساب</title>'
            + '<style>body{margin:0;background:#0f172a;color:#fff;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;text-align:center}div{max-width:360px;line-height:1.9}strong{display:block;font-size:24px;margin-bottom:12px}span{color:#cbd5e1;font-size:15px;font-weight:700}</style>'
            + '</head><body><div><strong>WhatsApp Desktop</strong><span>' + escapeHtml(statusMessage) + '</span></div></body></html>';
    };

    const syncBrowserLaunchWindowStatus = function (statusMessage) {
        if (!browserLaunchWindow || browserLaunchWindow.closed) {
            return false;
        }

        try {
            browserLaunchWindow.document.open();
            browserLaunchWindow.document.write(buildBrowserLaunchWindowMarkup(statusMessage));
            browserLaunchWindow.document.close();
            return true;
        } catch (error) {
            return false;
        }
    };

    const ensureBrowserLaunchWindow = function () {
        if (browserLaunchWindow && !browserLaunchWindow.closed) {
            return browserLaunchWindow;
        }

        try {
            browserLaunchWindow = window.open('', 'whatsappDesktopLauncher', 'width=480,height=560,resizable=yes,scrollbars=yes');
        } catch (error) {
            browserLaunchWindow = null;
        }

        browserLaunchWindowBlocked = !browserLaunchWindow || browserLaunchWindow.closed;

        if (browserLaunchWindow && !browserLaunchWindow.closed) {
            syncBrowserLaunchWindowStatus('جارٍ تجهيز فتح محادثات WhatsApp Desktop...');
        }

        return browserLaunchWindow;
    };

    const closeBrowserLaunchWindow = function () {
        if (browserLaunchWindow && !browserLaunchWindow.closed) {
            browserLaunchWindow.close();
        }
        browserLaunchWindow = null;
    };

    const openWhatsAppDesktopLink = function (queueItem) {
        const desktopLink = queueItem && queueItem.desktop_link ? String(queueItem.desktop_link) : '';
        if (!desktopLink || !isSafeWhatsAppDesktopLink(desktopLink)) {
            return false;
        }

        const launchWindow = ensureBrowserLaunchWindow();
        if (launchWindow && !launchWindow.closed) {
            syncBrowserLaunchWindowStatus('جارٍ فتح المحادثة التالية داخل WhatsApp Desktop...');
            try {
                launchWindow.location.assign(desktopLink);
                return true;
            } catch (error) {
                // Some browsers may refuse protocol navigation from the helper window and require the anchor fallback below.
            }
        }

        const tempLink = document.createElement('a');
        tempLink.href = desktopLink;
        tempLink.style.display = 'none';
        document.body.appendChild(tempLink);
        try {
            tempLink.click();
        } catch (error) {
            // The browser may block launching the whatsapp:// protocol from this context.
            tempLink.remove();
            return false;
        }
        window.setTimeout(function () {
            tempLink.remove();
        }, 250);
        return true;
    };

    const buildLaunchCompletionMessage = function (launchQueue) {
        return 'اكتمل فتح ' + String((launchQueue || []).length) + ' محادثة داخل WhatsApp Desktop. إذا كان أي رقم غير مسجل على واتساب فستجده ضمن النتائج كرقم فشل فتحه.';
    };

    const waitForNextQueueStep = function (buttonLabel) {
        return new Promise(function (resolve) {
            if (!nextQueueButton) {
                resolve();
                return;
            }

            setQueueControlsState(true, buttonLabel || 'تم الإرسال، افتح الرقم التالي', false);
            const handleClick = function () {
                nextQueueButton.removeEventListener('click', handleClick);
                setQueueControlsState(false, buttonLabel || 'تم الإرسال، افتح الرقم التالي', false);
                resolve();
            };
            nextQueueButton.addEventListener('click', handleClick);
        });
    };

    const launchPreparedQueue = async function (launchQueue, resultSummary, processedItems, delayMs) {
        if (!Array.isArray(launchQueue) || !launchQueue.length) {
            return false;
        }

        requestInProgress = true;
        setSubmitButtonsBusy(true, 'جارٍ فتح المحادثات...');
        setExecutionStatus('جارٍ فتح محادثات WhatsApp Desktop بالتتابع. بعد إرسال الرسالة داخل واتساب اضغط زر فتح الرقم التالي هنا.');

        const queuedResults = Array.isArray(processedItems) && processedItems.length
            ? processedItems.map(function (item) {
                return Object.assign({}, item);
            })
            : launchQueue.map(function (item) {
                return {
                    phone: item.phone || '',
                    member_name: item.member_name || '',
                    message: item.message || '',
                    status: 'ready'
                };
            });

        renderPreparedResults(resultSummary || null, queuedResults);

        for (let index = 0; index < launchQueue.length; index += 1) {
            const queueItem = launchQueue[index];
            if (!queueItem) {
                continue;
            }
            const opened = openWhatsAppDesktopLink(queueItem);
            if (queuedResults[index]) {
                queuedResults[index].status = opened ? 'launched' : 'failed';
                if (!opened) {
                    queuedResults[index].error = 'تعذر فتح محادثة WhatsApp Desktop تلقائياً لهذا الرقم. تأكد من السماح لبروتوكول whatsapp:// والنوافذ المنبثقة لهذا الموقع ثم أعد المحاولة.';
                }
            }
            renderPreparedResults(resultSummary || null, queuedResults);

            if (!opened) {
                continue;
            }

            const isLastQueueItem = index === launchQueue.length - 1;
            const queueLabel = String(queueItem.member_name || queueItem.phone || ('المحادثة رقم ' + String(index + 1)));
            const statusMessage = isLastQueueItem
                ? 'تم فتح آخر محادثة مع كتابة الرسالة. اضغط على إرسال داخل واتساب، ثم ارجع لهذه الصفحة واضغط إنهاء.'
                : 'تم فتح المحادثة الخاصة بـ ' + queueLabel + ' مع كتابة الرسالة. اضغط على إرسال داخل واتساب، ثم ارجع لهذه الصفحة واضغط لفتح الرقم التالي.';
            syncBrowserLaunchWindowStatus(statusMessage);
            setExecutionStatus(statusMessage);
            await waitForNextQueueStep(isLastQueueItem ? 'تم إرسال آخر رسالة، إنهاء' : 'تم إرسال رسالة ' + queueLabel + '، افتح الرقم التالي');
        }

        const completionMessage = buildLaunchCompletionMessage(launchQueue);
        syncBrowserLaunchWindowStatus(completionMessage);
        renderAlerts([], completionMessage);
        resetRequestState(completionMessage);

        return true;
    };

    document.querySelectorAll('.whatsapp-send-form').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (requestInProgress) {
                event.preventDefault();
                return;
            }

            if (!window.fetch || !window.FormData) {
                return;
            }

            event.preventDefault();
            requestInProgress = true;
            activeSubmitButton = form.querySelector('.submit-btn');
            setSubmitButtonsBusy(true, 'جارٍ تجهيز المحادثات...');
            setExecutionStatus('جارٍ تجهيز الأرقام وتشغيل WhatsApp Desktop...');
            ensureBrowserLaunchWindow();

            const formData = new FormData(form);
            formData.append('ajax_prepare', '1');
            if (clientPlatform) {
                formData.set('client_platform', clientPlatform);
            }

            fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        let data = null;
                        try {
                            data = JSON.parse(text);
                        } catch (error) {
                            throw new Error('تعذر قراءة استجابة تجهيز الرسائل من الخادم.');
                        }

                        if (!response.ok || !data.ok) {
                            const firstError = Array.isArray(data.errors) && data.errors.length
                                ? data.errors[0]
                                : 'تعذر تجهيز الرسائل حالياً.';
                            return Promise.reject(new WhatsAppRequestError(firstError, data));
                        }

                        return data;
                    });
                })
                .then(function (data) {
                    renderAlerts([], data.success || '');
                    renderPreparedResults(data.result_summary || null, data.processed_results || []);
                    if (data.launch_mode === 'browser' && Array.isArray(data.launch_queue) && data.launch_queue.length) {
                        if (browserLaunchWindowBlocked) {
                            renderAlerts(
                                ['قام المتصفح بحظر النافذة المساعدة الخاصة بفتح محادثات واتساب. سنحاول الفتح المباشر، لكن يُفضّل السماح بالنوافذ المنبثقة لهذا الموقع لتحسين الانتقال بين الأرقام.'],
                                data.success || ''
                            );
                        }
                        return launchPreparedQueue(
                            data.launch_queue,
                            data.result_summary || null,
                            data.processed_results || [],
                            Number(data.browser_launch_delay_ms || 0)
                        );
                    }
                    closeBrowserLaunchWindow();
                    resetRequestState(data.success || 'اكتمل تنفيذ الإرسال التلقائي.');
                    return null;
                })
                .catch(function (error) {
                    closeBrowserLaunchWindow();
                    if (error.responseData) {
                        renderAlerts(error.responseData.errors || [error.message || 'حدث خطأ غير متوقع'], error.responseData.success || '');
                        renderPreparedResults(error.responseData.result_summary || null, error.responseData.processed_results || []);
                    } else {
                        renderAlerts([error.message || 'حدث خطأ غير متوقع'], '');
                    }
                    resetRequestState(error.message || 'حدث خطأ غير متوقع');
                });
        });
    });
})();
</script>

<script>
document.querySelectorAll('.tab-btn').forEach(function (button) {
    button.addEventListener('click', function () {
        const target = button.getAttribute('data-target');
        document.querySelectorAll('.tab-btn').forEach(function (btn) {
            btn.classList.remove('active');
        });
        document.querySelectorAll('#promo-pane, #debts-pane').forEach(function (pane) {
            pane.classList.add('hidden');
        });
        button.classList.add('active');
        document.getElementById(target).classList.remove('hidden');
    });
});
</script>
</body>
</html>
