<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (empty($_SESSION['delete_all_members_token'])) {
    $_SESSION['delete_all_members_token'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['settle_all_members_remaining_token'])) {
    $_SESSION['settle_all_members_remaining_token'] = bin2hex(random_bytes(32));
}

require_once 'config.php';
require_once 'trainers_helpers.php';
require_once 'members_payment_helpers.php';
require_once 'input_normalization_helpers.php';

ensureSubscriptionCategorySchema($pdo);
ensureTrainersSchema($pdo);

function ensureMembersDiscountSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM `members` LIKE :column_name");
    $stmt->execute([':column_name' => 'member_discount_amount']);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->exec("ALTER TABLE `members` ADD COLUMN `member_discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `subscription_amount`");
    }

    $schemaReady = true;
}

ensureMembersDiscountSchema($pdo);

ensureMembersPaymentTypeSchema($pdo);
ensurePartialPaymentsPaymentTypeSchema($pdo);
ensureRenewalsLogPaymentTypeSchema($pdo);

define('MEMBERS_PAGE_SIZE', 100);

$siteName = "Gym System";
$gymLogoPath = null; // سنستخدمه كصورة افتراضية للعميل إذا لم توجد صورة للعميل

try {
    $stmt = $pdo->query("SELECT site_name, logo_path FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
        $gymLogoPath = $row['logo_path'] ?? null;
    }
} catch (Exception $e) {}

$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? '';
$userId   = (int)($_SESSION['user_id'] ?? 0);

$isManager    = ($role === 'مدير');
$isSupervisor = ($role === 'مشرف');

// صلاحية هذه الصفحة مرتبطة بـ can_view_members
$canViewMembers = false;

if ($isManager) {
    $canViewMembers = true;
} elseif ($isSupervisor && $userId > 0) {
    try {
        $stmtPerm = $pdo->prepare("SELECT can_view_members FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $canViewMembers = (int)$rowPerm['can_view_members'] === 1;
        } else {
            $canViewMembers = false;
        }
    } catch (Exception $e) {
        $canViewMembers = false;
    }
}

// منع الدخول إذا لم يكن له صلاحية رؤية الصفحة
if (!$canViewMembers) {
    header("Location: dashboard.php");
    exit;
}

// من الآن فصاعداً:
// - المدير يمكنه الإضافة/التعديل/الحذف
// - المشرف الذي لديه can_view_members = 1 يمكنه أيضاً
$canManageMembers = ($isManager || ($isSupervisor && $canViewMembers));

$errors  = [];
$success = "";
$deleteAllMembersToken = (string)($_SESSION['delete_all_members_token'] ?? '');
$settleAllMembersRemainingToken = (string)($_SESSION['settle_all_members_remaining_token'] ?? '');

// ===============================
// إعدادات رفع صور العملاء (15MB)
// ===============================
$memberUploadDir = 'uploads/members/';
$maxMemberImageSize = 15 * 1024 * 1024; // 15MB

if (!is_dir($memberUploadDir)) {
    @mkdir($memberUploadDir, 0777, true);
}

// دالة: التأكد أن الملف صورة "حقيقية" (يدعم كل أنواع الصور التي يتعرف عليها السيرفر)
// وعدم الاعتماد على $_FILES['type'] لأنه غير موثوق
function isRealImageFile($tmpPath) {
    if (!is_file($tmpPath)) return false;
    $info = @getimagesize($tmpPath);
    return ($info !== false);
}

function safeExtFromOriginalName($originalName) {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    // لو مفيش امتداد أو امتداد غريب، نخليه jpg افتراضياً
    if ($ext === '' || strlen($ext) > 10) {
        return 'jpg';
    }
    // تنظيف الامتداد (حروف/أرقام فقط)
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    if ($ext === '') return 'jpg';
    return $ext;
}

function memberDisplayImage($memberPhotoPath, $gymLogoPath) {
    // لو العضو لديه صورة
    if (!empty($memberPhotoPath)) {
        return $memberPhotoPath;
    }
    // لو لا يوجد صورة للعضو، نستخدم لوجو الجيم من الإعدادات
    if (!empty($gymLogoPath)) {
        return $gymLogoPath;
    }
    // لو لا يوجد لوجو أيضاً
    return null;
}

function resetMemberDataFromClosingTable(PDO $pdo, string $tableName): void
{
    $allowedClosingTables = [
        'daily_closings' => '`daily_closings`',
        'weekly_closings' => '`weekly_closings`',
    ];

    if (!isset($allowedClosingTables[$tableName])) {
        throw new InvalidArgumentException('Unsupported closing table.');
    }

    $quotedTableName = $allowedClosingTables[$tableName];
    $pdo->exec("
        UPDATE {$quotedTableName}
        SET
            new_subscriptions_count = 0,
            total_paid_for_new_subs = 0.00,
            partial_payments_count = 0,
            total_partial_payments = 0.00,
            renewals_count = 0,
            total_renewals_amount = 0.00,
            net_total = (COALESCE(total_single_sessions_amount, 0) + COALESCE(total_sales_amount, 0)) - COALESCE(total_expenses, 0)
        WHERE
            new_subscriptions_count <> 0
            OR total_paid_for_new_subs <> 0
            OR partial_payments_count <> 0
            OR total_partial_payments <> 0
            OR renewals_count <> 0
            OR total_renewals_amount <> 0
    ");
}

function deleteAllMembersAndRelatedData(PDO $pdo): void
{
    $pdo->exec("DELETE FROM member_service_usage");
    $pdo->exec("DELETE FROM attendance WHERE member_id IS NOT NULL");
    $pdo->exec("DELETE FROM partial_payments");
    $pdo->exec("DELETE FROM renewals_log");
    $pdo->exec("DELETE FROM trainer_commissions");
    $pdo->exec("DELETE FROM member_freeze");
    $pdo->exec("DELETE FROM member_freeze_log");
    $pdo->exec("DELETE FROM member_payments");
    $pdo->exec("DELETE FROM member_renewals");
    $pdo->exec("DELETE FROM members");

    resetMemberDataFromClosingTable($pdo, 'daily_closings');
    resetMemberDataFromClosingTable($pdo, 'weekly_closings');
}

function applyMemberRemainingPayment(PDO $pdo, int $memberId, float $payMore, int $paidByUserId): array
{
    $stmt = $pdo->prepare("
        SELECT paid_amount, remaining_amount, trainer_id, payment_type
        FROM members
        WHERE id = :id
        FOR UPDATE
    ");
    $stmt->execute([':id' => $memberId]);
    $memberRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$memberRow) {
        throw new RuntimeException("المشترك غير موجود.");
    }

    $oldPaid = (float)$memberRow['paid_amount'];
    $oldRemaining = (float)$memberRow['remaining_amount'];
    $memberTrainerId = normalizeTrainerId($memberRow['trainer_id'] ?? null);
    $memberPaymentType = trim((string)($memberRow['payment_type'] ?? getDefaultMemberPaymentType()));
    if ($memberPaymentType === '' || !in_array($memberPaymentType, getAllowedMemberPaymentTypes(), true)) {
        $memberPaymentType = getDefaultMemberPaymentType();
    }

    if ($payMore > $oldRemaining || $payMore <= 0) {
        throw new RuntimeException("مبلغ السداد أكبر من المتبقي أو غير صالح.");
    }

    $newPaid = $oldPaid + $payMore;
    $newRemaining = $oldRemaining - $payMore;

    $stmt = $pdo->prepare("
        UPDATE members
        SET paid_amount = :newPaid,
            remaining_amount = :newRemaining
        WHERE id = :id
    ");
    $stmt->execute([
        ':newPaid' => $newPaid,
        ':newRemaining' => $newRemaining,
        ':id' => $memberId,
    ]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException("فشل تحديث بيانات المشترك.");
    }

    $stmt = $pdo->prepare("
        INSERT INTO partial_payments
            (member_id, paid_amount, payment_type, old_remaining, new_remaining, paid_by_user_id)
        VALUES
            (:member_id, :paid_amount, :payment_type, :old_remaining, :new_remaining, :paid_by_user_id)
    ");
    $stmt->execute([
        ':member_id' => $memberId,
        ':paid_amount' => $payMore,
        ':payment_type' => $memberPaymentType,
        ':old_remaining' => $oldRemaining,
        ':new_remaining' => $newRemaining,
        ':paid_by_user_id' => $paidByUserId,
    ]);

    addTrainerCommission(
        $pdo,
        $memberTrainerId,
        $memberId,
        'partial_payment',
        (int)$pdo->lastInsertId(),
        $payMore
    );

    return [
        'paid_amount' => $payMore,
        'old_remaining' => $oldRemaining,
        'new_remaining' => $newRemaining,
    ];
}

// جلب قائمة الاشتراكات مرة واحدة (مع freeze_days, spa, massage, jacuzzi)
$subscriptions = [];
try {
    $stmt = $pdo->query("
        SELECT
            id,
            name,
            subscription_category,
            days,
            sessions,
            invites,
            price,
            price_after_discount,
            freeze_days,
            spa_count,
            massage_count,
            jacuzzi_count
        FROM subscriptions
        ORDER BY name ASC
    ");
    $subscriptions = $stmt->fetchAll();
} catch (Exception $e) {}

$subscriptionCategories = [];
$hasUncategorizedSubscriptions = false;
foreach ($subscriptions as $subscriptionRow) {
    $categoryName = trim((string)($subscriptionRow['subscription_category'] ?? ''));
    if ($categoryName === '') {
        $hasUncategorizedSubscriptions = true;
        continue;
    }

    $subscriptionCategories[$categoryName] = $categoryName;
}
ksort($subscriptionCategories, SORT_NATURAL | SORT_FLAG_CASE);

$trainers = [];
$trainersById = [];
try {
    $trainers = getAllTrainers($pdo);
    foreach ($trainers as $trainerRow) {
        $trainersById[(int)$trainerRow['id']] = $trainerRow;
    }
} catch (Exception $e) {}

// رسائل الاستيراد من Excel
if (isset($_SESSION['import_error'])) {
    $errors[] = $_SESSION['import_error'];
    unset($_SESSION['import_error']);
}
if (isset($_SESSION['import_success'])) {
    $success .= ($success ? ' ' : '') . $_SESSION['import_success'];
    unset($_SESSION['import_success']);
}

// معالجة الإضافة / التعديل / السداد / الحذف / التمديد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageMembers) {
    $action = $_POST['action'] ?? '';

    // =============================
    // تمديد اشتراك (بدون تغيير تاريخ البداية) + دفع إضافي الآن (اختياري)
    // =============================
    if ($action === 'extend_subscription') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $newSubId = (int)($_POST['new_subscription_id'] ?? 0);
        $payNow   = (float)($_POST['extend_pay_now'] ?? 0);
        $notes    = trim($_POST['extend_notes'] ?? '');

        if ($memberId <= 0 || $newSubId <= 0) {
            $errors[] = "بيانات التمديد غير صحيحة.";
        } elseif ($payNow < 0) {
            $errors[] = "قيمة المبلغ المدفوع الآن لا يمكن أن تكون سالبة.";
        } else {
            try {
                $pdo->beginTransaction();

                // قفل صف العضو أثناء التمديد
                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        subscription_id,
                        trainer_id,
                        payment_type,
                        start_date,
                        end_date,
                        paid_amount,
                        remaining_amount,
                        subscription_amount,
                        status
                    FROM members
                    WHERE id = :id
                    FOR UPDATE
                ");
                $stmt->execute([':id' => $memberId]);
                $memberRow = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$memberRow) {
                    $pdo->rollBack();
                    $errors[] = "المشترك غير موجود.";
                } else {
                    $oldSubId          = (int)$memberRow['subscription_id'];
                    $memberTrainerId   = normalizeTrainerId($memberRow['trainer_id'] ?? null);
                    $memberPaymentType = trim((string)($memberRow['payment_type'] ?? getDefaultMemberPaymentType()));
                    if ($memberPaymentType === '' || !in_array($memberPaymentType, getAllowedMemberPaymentTypes(), true)) {
                        $memberPaymentType = getDefaultMemberPaymentType();
                    }
                    $extendPaymentType = trim((string)($_POST['extend_payment_type'] ?? $memberPaymentType));
                    if ($payNow <= 0
                        || $extendPaymentType === ''
                        || !in_array($extendPaymentType, getAllowedMemberPaymentTypes(), true)
                    ) {
                        $extendPaymentType = $memberPaymentType;
                    }

                    $startDate         = $memberRow['start_date'];
                    $oldEndDate        = $memberRow['end_date'];
                    $oldPaidAmount     = (float)$memberRow['paid_amount'];
                    $oldRemaining      = (float)$memberRow['remaining_amount'];
                    $oldSubAmount      = (float)$memberRow['subscription_amount'];

                    if ($startDate === null || $startDate === '') {
                        $pdo->rollBack();
                        $errors[] = "لا يمكن تنفيذ التمديد لأن تاريخ بداية الاشتراك غير مسجل للمشترك.";
                    } else {
                        // جلب الاشتراك الجديد
                        $stmt = $pdo->prepare("
                            SELECT
                                id,
                                name,
                                days,
                                sessions,
                                invites,
                                price_after_discount,
                                freeze_days,
                                spa_count,
                                massage_count,
                                jacuzzi_count
                            FROM subscriptions
                            WHERE id = :sid
                            LIMIT 1
                        ");
                        $stmt->execute([':sid' => $newSubId]);
                        $newSub = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$newSub) {
                            $pdo->rollBack();
                            $errors[] = "الاشتراك الجديد غير موجود.";
                        } else {
                            $newDays     = (int)$newSub['days'];
                            $newSessions = (int)$newSub['sessions'];
                            $newInvites  = (int)$newSub['invites'];
                            $newAmount   = (float)$newSub['price_after_discount'];

                            $newFreezeDays   = (int)($newSub['freeze_days'] ?? 0);
                            $newSpaCount     = (int)($newSub['spa_count'] ?? 0);
                            $newMassageCount = (int)($newSub['massage_count'] ?? 0);
                            $newJacuzziCount = (int)($newSub['jacuzzi_count'] ?? 0);

                            // حساب نهاية جديدة من نفس تاريخ البداية
                            $newEndDate = date('Y-m-d', strtotime($startDate . ' + ' . $newDays . ' days'));

                            // احتساب المدفوع الحالي + المدفوع الآن كرصيد ضمن الاشتراك الجديد
                            $newPaidAmount = $oldPaidAmount + $payNow;

                            // المتبقي الجديد
                            $newRemainingAmount = $newAmount - $newPaidAmount;

                            // لا نسمح أن يبقى المتبقي سالب، ونمنع أيضاً أن يدفع الآن أكثر من اللازم
                            // (لو دفع زيادة، نعتبرها خطأ حتى لا يضيع فلوسه)
                            if ($newRemainingAmount < 0) {
                                $pdo->rollBack();
                                $maxAllowedPayNow = $newAmount - $oldPaidAmount;
                                if ($maxAllowedPayNow < 0) $maxAllowedPayNow = 0;
                                $errors[] = "المبلغ المدفوع الآن أكبر من المطلوب. الحد الأقصى المسموح به الآن: " . number_format($maxAllowedPayNow, 2);
                            } else {
                                // تحديث العضو (بدون تغيير start_date)
                                $stmt = $pdo->prepare("
                                    UPDATE members
                                    SET subscription_id = :new_sub_id,
                                        days = :new_days,
                                        sessions = :new_sessions,
                                        sessions_remaining = :new_sessions_remaining,
                                        invites = :new_invites,
                                        freeze_days = :new_freeze_days,
                                        subscription_amount = :new_amount,
                                        paid_amount = :new_paid_amount,
                                        remaining_amount = :new_remaining,
                                        end_date = :new_end_date,
                                        payment_type = :payment_type,
                                        status = 'مستمر',
                                        spa_count = :spa,
                                        massage_count = :massage,
                                        jacuzzi_count = :jacuzzi
                                    WHERE id = :mid
                                ");
                                $stmt->execute([
                                    ':new_sub_id'             => $newSubId,
                                    ':new_days'               => $newDays,
                                    ':new_sessions'           => $newSessions,
                                    ':new_sessions_remaining' => $newSessions,
                                    ':new_invites'            => $newInvites,
                                    ':new_freeze_days'        => $newFreezeDays,
                                    ':new_amount'             => $newAmount,
                                    ':new_paid_amount'        => $newPaidAmount,
                                    ':new_remaining'          => $newRemainingAmount,
                                    ':new_end_date'           => $newEndDate,
                                    ':payment_type'           => $extendPaymentType,
                                    ':spa'                    => $newSpaCount,
                                    ':massage'                => $newMassageCount,
                                    ':jacuzzi'                => $newJacuzziCount,
                                    ':mid'                    => $memberId,
                                ]);

                                // تسجيل العملية في member_extensions (لو الجدول موجود)
                                // إذا لم تنفذ SQL الخاص بالجدول، سيظهر خطأ هنا، لذلك الأفضل تنفذه أولاً.
                                $stmt = $pdo->prepare("
                                    INSERT INTO member_extensions
                                        (member_id, old_subscription_id, new_subscription_id,
                                         old_end_date, new_end_date,
                                         old_subscription_amount, new_subscription_amount,
                                         old_paid_amount, old_remaining_amount,
                                         new_paid_amount, new_remaining_amount,
                                         created_by_user_id, notes)
                                    VALUES
                                        (:member_id, :old_sub, :new_sub,
                                         :old_end, :new_end,
                                         :old_amt, :new_amt,
                                         :old_paid, :old_rem,
                                         :new_paid, :new_rem,
                                         :by_user, :notes)
                                ");
                                $stmt->execute([
                                    ':member_id' => $memberId,
                                    ':old_sub'   => $oldSubId,
                                    ':new_sub'   => $newSubId,
                                    ':old_end'   => $oldEndDate,
                                    ':new_end'   => $newEndDate,
                                    ':old_amt'   => $oldSubAmount,
                                    ':new_amt'   => $newAmount,
                                    ':old_paid'  => $oldPaidAmount,
                                    ':old_rem'   => $oldRemaining,
                                    ':new_paid'  => $newPaidAmount,
                                    ':new_rem'   => $newRemainingAmount,
                                    ':by_user'   => ($userId > 0 ? $userId : null),
                                    ':notes'     => ($notes !== '' ? $notes : null),
                                ]);

                                // لو تم دفع مبلغ الآن، نسجله أيضاً في partial_payments كعملية سداد
                                if ($payNow > 0) {
                                    $stmt = $pdo->prepare("
                                        INSERT INTO partial_payments
                                            (member_id, paid_amount, payment_type, old_remaining, new_remaining, paid_by_user_id)
                                        VALUES
                                            (:member_id, :paid_amount, :payment_type, :old_remaining, :new_remaining, :paid_by_user_id)
                                    ");
                                    $stmt->execute([
                                        ':member_id'       => $memberId,
                                        ':paid_amount'     => $payNow,
                                        ':payment_type'    => $extendPaymentType,
                                        ':old_remaining'   => $oldRemaining,
                                        ':new_remaining'   => $newRemainingAmount,
                                        ':paid_by_user_id' => ($userId > 0 ? $userId : 0),
                                    ]);

                                    addTrainerCommission(
                                        $pdo,
                                        $memberTrainerId,
                                        $memberId,
                                        'partial_payment',
                                        (int)$pdo->lastInsertId(),
                                        $payNow
                                    );
                                }

                                $pdo->commit();

                                if ($payNow > 0) {
                                    $success = "تم تمديد الاشتراك بنجاح بدون تغيير تاريخ البداية، وتم تسجيل مبلغ مدفوع الآن ضمن الاشتراك الجديد وتحديث المتبقي.";
                                } else {
                                    $success = "تم تمديد الاشتراك بنجاح بدون تغيير تاريخ البداية. تم احتساب المدفوع السابق ضمن الاشتراك الجديد وتحديث المتبقي.";
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "حدث خطأ أثناء عملية تمديد الاشتراك.";
            }
        }
    }

    // إضافة أو تعديل مشترك
    if ($action === 'add_member' || $action === 'edit_member') {
        $memberId = (int)($_POST['member_id'] ?? 0);

        $name    = trim($_POST['name'] ?? '');
        $phone   = normalizePhoneForStorage($_POST['phone'] ?? '');
        $barcode = normalizeMemberBarcodeForStorage($_POST['barcode'] ?? '');
        $ageRaw  = trim((string)($_POST['age'] ?? ''));
        $age     = $ageRaw === '' ? 0 : filter_var($ageRaw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0],
        ]);
        $gender  = normalizeGenderForStorage($_POST['gender'] ?? '');
        $addr    = trim($_POST['address'] ?? '');
        $subId   = (int)($_POST['subscription_id'] ?? 0);
        $trainerId = normalizeTrainerId($_POST['trainer_id'] ?? 0);
        $paid    = (float)($_POST['paid_amount'] ?? 0);
        $paymentType = trim((string)($_POST['payment_type'] ?? 'كاش'));
        $postedDiscountAmount = (float)($_POST['member_discount_amount'] ?? 0);
        $startDateInput = normalizeFlexibleDateInput($_POST['start_date'] ?? '') ?? '';

        // NEW: spa, massage, jacuzzi from POST if you want to allow manual edit (OR can always use from sub)
        $spa_count     = isset($_POST['spa_count']) ? (int)$_POST['spa_count'] : 0;
        $massage_count = isset($_POST['massage_count']) ? (int)$_POST['massage_count'] : 0;
        $jacuzzi_count = isset($_POST['jacuzzi_count']) ? (int)$_POST['jacuzzi_count'] : 0;

        // =========================
        // رفع صورة العميل
        // =========================
        $uploadedPhotoPath = null;
        $hasNewPhotoUpload = false;

        if (isset($_FILES['member_photo']) && !empty($_FILES['member_photo']['name'])) {
            $file = $_FILES['member_photo'];

            if ($file['error'] === UPLOAD_ERR_OK) {
                if ((int)$file['size'] > $maxMemberImageSize) {
                    $errors[] = "حجم صورة العميل يجب ألا يزيد عن 15 ميجابايت.";
                } else {
                    if (!isRealImageFile($file['tmp_name'])) {
                        $errors[] = "الملف المرفوع ليس صورة صحيحة.";
                    } else {
                        $ext = safeExtFromOriginalName($file['name']);
                        $newFileName = 'member_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
                        $targetPath = $memberUploadDir . $newFileName;

                        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                            $uploadedPhotoPath = $targetPath;
                            $hasNewPhotoUpload = true;
                        } else {
                            $errors[] = "فشل رفع صورة العميل.";
                        }
                    }
                }
            } else {
                $errors[] = "حدث خطأ أثناء رفع صورة العميل.";
            }
        }

        if ($name === '' || $phone === '' || $barcode === '' || $age === false || !in_array($gender, ['ذكر','أنثى'], true)) {
            $errors[] = "من فضلك أدخل بيانات المشترك الأساسية بشكل صحيح.";
        } elseif ($subId <= 0) {
            $errors[] = "من فضلك اختر اشتراكاً صالحاً.";
        } elseif ($trainerId !== null && !isset($trainersById[$trainerId])) {
            $errors[] = "من فضلك اختر مدرباً صالحاً أو اختر بدون مدرب.";
        } elseif (!in_array($paymentType, getAllowedMemberPaymentTypes(), true)) {
            $errors[] = "من فضلك اختر نوع دفع صحيح.";
        } elseif ($startDateInput === '') {
            $errors[] = "من فضلك اختر تاريخ بداية الاشتراك.";
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDateInput)) {
            $errors[] = "تنسيق تاريخ البداية غير صحيح.";
        } else {
            try {
                if (memberBarcodeExists($pdo, $barcode, $action === 'edit_member' ? $memberId : null)) {
                    $errors[] = "لا يمكن تسجيل مشترك بنفس الباركود الموجود بالفعل.";
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء التحقق من الباركود.";
            }

            if (empty($errors)) {
                $subRow = null;
                foreach ($subscriptions as $s) {
                    if ((int)$s['id'] === $subId) {
                        $subRow = $s;
                        break;
                    }
                }
                if (!$subRow) {
                    $errors[] = "الاشتراك المحدد غير موجود.";
                } else {
                    $currentMember = null;
                    if ($action === 'edit_member') {
                        if ($memberId <= 0) {
                            $errors[] = "معرّف المشترك غير صحيح.";
                        } else {
                            $stmtCurrentMember = $pdo->prepare("
                                SELECT subscription_id, member_discount_amount
                                FROM members
                                WHERE id = :id
                                LIMIT 1
                            ");
                            $stmtCurrentMember->execute([':id' => $memberId]);
                            $currentMember = $stmtCurrentMember->fetch(PDO::FETCH_ASSOC);
                            if (!$currentMember) {
                                $errors[] = "المشترك غير موجود.";
                            }
                        }
                    }

                    $days     = (int)$subRow['days'];
                    $sessions = (int)$subRow['sessions'];
                    $invites  = (int)$subRow['invites'];
                    $baseAmount = round((float)$subRow['price_after_discount'], 2);
                    $memberFreezeDays = (int)($subRow['freeze_days'] ?? 0); // عدد أيام الفريز للمشترك (من الاشتراك)
                    $discountAmount = 0.00;

                    if ($isManager) {
                        $discountAmount = round($postedDiscountAmount, 2);
                        if ($discountAmount < 0) {
                            $errors[] = "قيمة الخصم لا يمكن أن تكون سالبة.";
                        } elseif ($discountAmount > $baseAmount) {
                            $errors[] = "قيمة الخصم لا يمكن أن تكون أكبر من مبلغ الاشتراك.";
                        }
                    } elseif ($action === 'edit_member' && $currentMember) {
                        $currentDiscountAmount = (float)($currentMember['member_discount_amount'] ?? 0);
                        if ((int)$currentMember['subscription_id'] === $subId) {
                            $discountAmount = min(max($currentDiscountAmount, 0), $baseAmount);
                        }
                    }

                    $amount = round($baseAmount - $discountAmount, 2);

                    // Default for new member: spa, massage, jacuzzi
                    $spa_count     = (int)($subRow['spa_count'] ?? 0);
                    $massage_count = (int)($subRow['massage_count'] ?? 0);
                    $jacuzzi_count = (int)($subRow['jacuzzi_count'] ?? 0);

                    if ($paid < 0 || $paid > $amount) {
                        $errors[] = "مبلغ المدفوع يجب أن يكون بين 0 وقيمة الاشتراك.";
                    } else {
                        $remaining         = $amount - $paid;
                        $sessionsRemaining = $sessions;

                        // استخدام التاريخ الذي أدخله المستخدم كبداية للاشتراك
                        $startDate = $startDateInput;
                        // حساب تاريخ النهاية حسب عدد الأيام في الاشتراك
                        $endDate   = date('Y-m-d', strtotime($startDate . ' + ' . $days . ' days'));

                        $status = 'مستمر';

                        $barcodeLockAcquired = false;
                        try {
                            if ($action === 'add_member') {
                                $stmt = $pdo->prepare("
                                    INSERT INTO members
                                    (name, phone, barcode, age, gender, address, subscription_id,
                                     trainer_id,
                                     days, sessions, sessions_remaining, invites, freeze_days,
                                     subscription_amount, member_discount_amount, initial_paid_amount, paid_amount, payment_type, remaining_amount,
                                     start_date, end_date, status,
                                     spa_count, massage_count, jacuzzi_count,
                                     photo_path, created_by_user_id
                                    )
                                    VALUES
                                    (:n,:ph,:bc,:a,:g,:ad,:sid,
                                     :trainer_id,
                                     :d,:s,:sr,:i,:fz,
                                     :amt,:member_discount_amount,:init_paid,:paid,:payment_type,:rem,
                                     :sd,:ed,:st,
                                     :spa,:massage,:jacuzzi,
                                     :photo_path, :created_by
                                    )
                                ");
                            } else { // edit_member
                                if ($memberId <= 0) {
                                    $errors[] = "معرّف المشترك غير صحيح.";
                                    goto skip_member_execute;
                                }

                                if ($hasNewPhotoUpload) {
                                    $stmt = $pdo->prepare("
                                        UPDATE members
                                        SET name = :n,
                                            phone = :ph,
                                            barcode = :bc,
                                            age = :a,
                                            gender = :g,
                                            address = :ad,
                                            subscription_id = :sid,
                                            trainer_id = :trainer_id,
                                            days = :d,
                                            sessions = :s,
                                            sessions_remaining = :sr,
                                            invites = :i,
                                            freeze_days = :fz,
                                            subscription_amount = :amt,
                                            member_discount_amount = :member_discount_amount,
                                            paid_amount = :paid,
                                            payment_type = :payment_type,
                                            remaining_amount = :rem,
                                            start_date = :sd,
                                            end_date = :ed,
                                            status = :st,
                                            spa_count = :spa,
                                            massage_count = :massage,
                                            jacuzzi_count = :jacuzzi,
                                            photo_path = :photo_path
                                        WHERE id = :mid
                                    ");
                                } else {
                                    $stmt = $pdo->prepare("
                                        UPDATE members
                                        SET name = :n,
                                            phone = :ph,
                                            barcode = :bc,
                                            age = :a,
                                            gender = :g,
                                            address = :ad,
                                            subscription_id = :sid,
                                            trainer_id = :trainer_id,
                                            days = :d,
                                            sessions = :s,
                                            sessions_remaining = :sr,
                                            invites = :i,
                                            freeze_days = :fz,
                                            subscription_amount = :amt,
                                            member_discount_amount = :member_discount_amount,
                                            paid_amount = :paid,
                                            payment_type = :payment_type,
                                            remaining_amount = :rem,
                                            start_date = :sd,
                                            end_date = :ed,
                                            status = :st,
                                            spa_count = :spa,
                                            massage_count = :massage,
                                            jacuzzi_count = :jacuzzi
                                        WHERE id = :mid
                                    ");
                                }

                                $stmt->bindValue(':mid', $memberId, PDO::PARAM_INT);
                            }

                            if (empty($errors)) {
                                $pdo->beginTransaction();
                                acquireMemberBarcodeLock($pdo);
                                $barcodeLockAcquired = true;
                                if (memberBarcodeExists($pdo, $barcode, $action === 'edit_member' ? $memberId : null)) {
                                    throw new RuntimeException("لا يمكن تسجيل مشترك بنفس الباركود الموجود بالفعل.");
                                }
                                $stmt->bindValue(':n',   $name);
                                $stmt->bindValue(':ph',  $phone);
                                $stmt->bindValue(':bc',  $barcode);
                                $stmt->bindValue(':a',   $age, PDO::PARAM_INT);
                                $stmt->bindValue(':g',   $gender);
                                $stmt->bindValue(':ad',  $addr);
                                $stmt->bindValue(':sid', $subId, PDO::PARAM_INT);
                                if ($trainerId !== null) {
                                    $stmt->bindValue(':trainer_id', $trainerId, PDO::PARAM_INT);
                                } else {
                                    $stmt->bindValue(':trainer_id', null, PDO::PARAM_NULL);
                                }
                                $stmt->bindValue(':d',   $days, PDO::PARAM_INT);
                                $stmt->bindValue(':s',   $sessions, PDO::PARAM_INT);
                                $stmt->bindValue(':sr',  $sessionsRemaining, PDO::PARAM_INT);
                                $stmt->bindValue(':i',   $invites, PDO::PARAM_INT);
                                $stmt->bindValue(':fz',  $memberFreezeDays, PDO::PARAM_INT);
                                $stmt->bindValue(':amt', $amount);
                                $stmt->bindValue(':member_discount_amount', $discountAmount);
                                $stmt->bindValue(':paid',$paid);
                                $stmt->bindValue(':payment_type', $paymentType);
                                $stmt->bindValue(':rem', $remaining);
                                $stmt->bindValue(':sd',  $startDate);
                                $stmt->bindValue(':ed',  $endDate);
                                $stmt->bindValue(':st',  $status);
                                $stmt->bindValue(':spa',     $spa_count, PDO::PARAM_INT);
                                $stmt->bindValue(':massage', $massage_count, PDO::PARAM_INT);
                                $stmt->bindValue(':jacuzzi', $jacuzzi_count, PDO::PARAM_INT);

                                if ($action === 'add_member') {
                                    $stmt->bindValue(':init_paid', $paid);
                                    $stmt->bindValue(':photo_path', $uploadedPhotoPath);

                                    if ($userId > 0) {
                                        $stmt->bindValue(':created_by', $userId, PDO::PARAM_INT);
                                    } else {
                                        $stmt->bindValue(':created_by', null, PDO::PARAM_NULL);
                                    }
                                } else {
                                    if ($hasNewPhotoUpload) {
                                        $stmt->bindValue(':photo_path', $uploadedPhotoPath);
                                    }
                                }

                                $stmt->execute();

                                if ($action === 'add_member') {
                                    $newMemberId = (int)$pdo->lastInsertId();
                                    addTrainerCommission(
                                        $pdo,
                                        $trainerId,
                                        $newMemberId,
                                        'new_subscription',
                                        $newMemberId,
                                        $paid
                                    );
                                    $pdo->commit();
                                    $success = "تم إضافة المشترك بنجاح. الاسم: {$name} - الباركود: {$barcode}";
                                } else {
                                    $pdo->commit();
                                    $success = "تم تعديل بيانات المشترك بنجاح.";
                                }
                            }
                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            if ($e instanceof RuntimeException) {
                                $errors[] = $e->getMessage();
                            } else {
                                $errors[] = "حدث خطأ أثناء حفظ بيانات المشترك.";
                            }
                        } finally {
                            if ($barcodeLockAcquired) {
                                releaseMemberBarcodeLock($pdo);
                            }
                        }
                    }
                }
            }
        }
        skip_member_execute:;
    }

    // سداد الباقي
    if ($action === 'pay_rest') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        $payMore  = (float)($_POST['pay_amount'] ?? 0);

        if ($memberId <= 0 || $payMore <= 0) {
            $errors[] = "بيانات السداد غير صحيحة.";
        } else {
            try {
                $pdo->beginTransaction();
                applyMemberRemainingPayment($pdo, $memberId, $payMore, (int)($_SESSION['user_id'] ?? 0));
                $pdo->commit();
                $success = "تم سداد جزء من المبلغ بنجاح.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof RuntimeException) {
                    $errors[] = $e->getMessage();
                } else {
                    $errors[] = "حدث خطأ أثناء عملية السداد.";
                }
            }
        }
    }

    // حذف مشترك
    if ($action === 'delete_member') {
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId <= 0) {
            $errors[] = "معرّف المشترك غير صحيح.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("DELETE FROM member_service_usage WHERE member_id = :id");
                $stmt->execute([':id' => $memberId]);

                $stmt = $pdo->prepare("DELETE FROM attendance WHERE member_id = :id");
                $stmt->execute([':id' => $memberId]);

                $stmt = $pdo->prepare("DELETE FROM partial_payments WHERE member_id = :id");
                $stmt->execute([':id' => $memberId]);

                $stmt = $pdo->prepare("DELETE FROM renewals_log WHERE member_id = :id");
                $stmt->execute([':id' => $memberId]);

                $stmt = $pdo->prepare("DELETE FROM trainer_commissions WHERE member_id = :id");
                $stmt->execute([':id' => $memberId]);

                $stmt = $pdo->prepare("DELETE FROM members WHERE id = :id");
                $stmt->execute([':id' => $memberId]);

                $pdo->commit();
                $success = "تم حذف المشترك بنجاح.";
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "حدث خطأ أثناء حذف المشترك.";
            }
        }
    }

    if ($action === 'settle_all_remaining_balances') {
        if (!$isManager) {
            $errors[] = "غير مسموح لك بتسديد المتبقي لجميع المشتركين.";
        } else {
            $settleAllConfirmation = trim((string)($_POST['settle_all_confirmation'] ?? ''));
            $submittedSettleToken = (string)($_POST['settle_all_members_remaining_token'] ?? '');
            if (
                $settleAllConfirmation !== 'yes'
                || $settleAllMembersRemainingToken === ''
                || $submittedSettleToken === ''
                || !hash_equals($settleAllMembersRemainingToken, $submittedSettleToken)
            ) {
                $errors[] = "من فضلك أكد تسديد المتبقي لجميع المشتركين.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $stmt = $pdo->query("SELECT id, remaining_amount FROM members WHERE remaining_amount > 0 ORDER BY id ASC FOR UPDATE");
                    $membersWithRemaining = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    $settledMembersCount = 0;
                    $paidByUserId = (int)($_SESSION['user_id'] ?? 0);
                    foreach ($membersWithRemaining as $memberWithRemaining) {
                        $memberId = (int)($memberWithRemaining['id'] ?? 0);
                        $remainingAmount = (float)($memberWithRemaining['remaining_amount'] ?? 0);
                        if ($memberId <= 0 || $remainingAmount <= 0) {
                            continue;
                        }

                        applyMemberRemainingPayment($pdo, $memberId, $remainingAmount, $paidByUserId);
                        $settledMembersCount++;
                    }

                    $pdo->commit();
                    $_SESSION['settle_all_members_remaining_token'] = bin2hex(random_bytes(32));
                    $settleAllMembersRemainingToken = (string)$_SESSION['settle_all_members_remaining_token'];

                    if ($settledMembersCount > 0) {
                        $success = "تم تسديد جميع المبالغ المتبقية لـ {$settledMembersCount} مشترك بنجاح.";
                    } else {
                        $success = "لا يوجد أي مبالغ متبقية على المشتركين.";
                    }
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($e instanceof RuntimeException) {
                        $errors[] = $e->getMessage();
                    } else {
                        $errors[] = "حدث خطأ أثناء تسديد المتبقي لجميع المشتركين.";
                    }
                }
            }
        }
    }

    if ($action === 'delete_all_members') {
        if (!$isManager) {
            $errors[] = "غير مسموح لك بحذف جميع المشتركين.";
        } else {
            $deleteAllConfirmation = trim((string)($_POST['delete_all_confirmation'] ?? ''));
            $submittedDeleteToken = (string)($_POST['delete_all_members_token'] ?? '');
            if (
                $deleteAllConfirmation !== 'yes'
                || $deleteAllMembersToken === ''
                || $submittedDeleteToken === ''
                || !hash_equals($deleteAllMembersToken, $submittedDeleteToken)
            ) {
                $errors[] = "من فضلك أكد حذف جميع المشتركين.";
            } else {
                try {
                    $pdo->beginTransaction();
                    deleteAllMembersAndRelatedData($pdo);
                    $pdo->commit();
                    $_SESSION['delete_all_members_token'] = bin2hex(random_bytes(32));
                    $deleteAllMembersToken = (string)$_SESSION['delete_all_members_token'];
                    $success = "تم حذف جميع المشتركين والبيانات المرتبطة بهم بنجاح.";
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = "حدث خطأ أثناء حذف جميع المشتركين.";
                }
            }
        }
    }
}

// تحديث حالة الاشتراك (منتهي إذا انتهى التاريخ أو استنفذ التمرينات)
try {
    $today = date('Y-m-d');
    $pdo->query("
        UPDATE members
        SET status = 'منتهي'
        WHERE (end_date IS NOT NULL AND end_date < '$today')
           OR sessions_remaining <= 0
    ");
} catch (Exception $e) {}

$managerPaymentPeriodStats = [];
if ($isManager) {
    $sevenDaysAgoStart = date('Y-m-d', strtotime('-6 days'));
    $monthStart = date('Y-m-01');
    try {
        $managerPaymentPeriodStats = [
            'يومي' => getMemberPaymentTotalsByRange($pdo, $today, $today),
            'أسبوعي (آخر 7 أيام)' => getMemberPaymentTotalsByRange($pdo, $sevenDaysAgoStart, $today),
            'شهري' => getMemberPaymentTotalsByRange($pdo, $monthStart, $today),
        ];
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// =============================
// جلب جدول المشتركين + الفلاتر
// =============================
$q = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status_filter'] ?? '';
$debtsFilter  = $_GET['debts_filter']  ?? '';
$page = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'default' => 1]]
);
$page = max(1, min((int)$page, 100000));
$membersPerPage = MEMBERS_PAGE_SIZE;
$members      = [];

$currentPageMembersCount = 0; // عدد المشتركين الظاهرين في الصفحة الحالية
$totalMembers            = 0; // عدد المشتركين المطابقين للفلاتر
$totalPages              = 1;
$totalWithDebtsCount     = 0;   // عدد من عليهم مبالغ متبقية ضمن النتيجة
$totalDebtsAmount        = 0.0; // مجموع المبالغ المتبقية ضمن النتيجة

try {
    $params = [];
    $where  = [];

    if ($q !== '') {
        $where[]      = "(m.name LIKE :q OR m.phone LIKE :q OR m.barcode LIKE :q)";
        $params[':q'] = '%' . $q . '%';
    }

    // فلتر الحالة
    if ($statusFilter === 'active') {
        $where[] = "m.status = 'مستمر'";
    } elseif ($statusFilter === 'ended') {
        $where[] = "m.status = 'منتهي'";
    } elseif ($statusFilter === 'frozen') {
        $where[] = "m.status = 'مجمد'";
    }

    // فلتر المدفوع/المتبقي
    if ($debtsFilter === 'with_debts') {
        $where[] = "m.remaining_amount > 0";
    } elseif ($debtsFilter === 'no_debts') {
        $where[] = "m.remaining_amount <= 0";
    }

    $whereSql = $where ? " WHERE " . implode(" AND ", $where) : '';

    $statsSql = "
        SELECT
            COUNT(*) AS total_members,
            SUM(CASE WHEN m.remaining_amount > 0 THEN 1 ELSE 0 END) AS total_with_debts_count,
            COALESCE(SUM(CASE WHEN m.remaining_amount > 0 THEN m.remaining_amount ELSE 0 END), 0) AS total_debts_amount
        FROM members m
        JOIN subscriptions s ON s.id = m.subscription_id
        $whereSql
    ";

    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute($params);
    $statsRow = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalMembers = (int)($statsRow['total_members'] ?? 0);
    $totalWithDebtsCount = (int)($statsRow['total_with_debts_count'] ?? 0);
    $totalDebtsAmount = (float)($statsRow['total_debts_amount'] ?? 0);
    $totalPages = max(1, (int)ceil($totalMembers / $membersPerPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $membersPerPage;

    $sql = "
        SELECT
            m.id,
            m.name,
            m.phone,
            m.barcode,
            m.age,
            m.gender,
            m.address,
            s.name AS subscription_name,
            m.subscription_id,
            m.trainer_id,
            m.days,
            m.sessions,
            m.sessions_remaining,
            m.invites,
            m.freeze_days,
            m.subscription_amount,
            m.member_discount_amount,
            m.paid_amount,
            m.payment_type,
            m.remaining_amount,
            m.start_date,
            m.end_date,
            m.status,
            m.spa_count,
            m.massage_count,
            m.jacuzzi_count,
            m.photo_path,
            m.created_by_user_id,
            u.username AS created_by_username,
            t.name AS trainer_name
        FROM members m
        JOIN subscriptions s ON s.id = m.subscription_id
        LEFT JOIN users u ON u.id = m.created_by_user_id
        LEFT JOIN trainers t ON t.id = m.trainer_id
    ";

    $sql .= $whereSql;
    $sql .= " ORDER BY m.id DESC LIMIT :members_limit OFFSET :members_offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':members_limit', $membersPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':members_offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $currentPageMembersCount = count($members);
} catch (Exception $e) {
    // يمكن لاحقاً عرض رسالة خطأ إن أردت
}

$paginationBaseParams = [
    'q' => $q,
    'status_filter' => $statusFilter,
    'debts_filter' => $debtsFilter,
];

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>المشتركين - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        /* نفس الـ CSS السابق */
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
        body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--text-main);
            font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; font-weight: 900; font-size: 20px; }
        .page { max-width: 1300px; margin: 30px auto 50px; padding: 0 22px; }
        .header-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
        .title-main{font-size:30px;font-weight:900;}
        .back-button{
            display:inline-flex;align-items:center;justify-content:center;gap:10px;
            padding:12px 24px;border-radius:999px;border:none;cursor:pointer;
            font-size:18px;font-weight:900;
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 18px 40px rgba(79,70,229,0.55);text-decoration:none;
        }
        .back-button:hover{filter:brightness(1.06);}
        .card{background:var(--card-bg);border-radius:28px;padding:22px 24px 24px;
            box-shadow:0 24px 60px rgba(15,23,42,0.24),0 0 0 1px rgba(255,255,255,0.7);}
        .theme-toggle{display:flex;justify-content:flex-end;margin-bottom:16px;}
        .theme-switch{
            position:relative;width:80px;height:38px;border-radius:999px;
            background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.95);
            cursor:pointer;display:flex;align-items:center;justify-content:space-between;
            padding:0 9px;font-size:18px;color:#6b7280;font-weight:900;
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
        .controls { display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;gap:12px; }
        .btn-main{
            border-radius:999px;padding:12px 24px;border:none;cursor:pointer;font-size:18px;
            font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:8px;
            background:linear-gradient(90deg,#22c55e,#16a34a);color:#f9fafb;
            box-shadow:0 20px 44px rgba(22,163,74,0.8);text-decoration:none;
        }
        .btn-main:hover{filter:brightness(1.06);}
        .alert{padding:12px 14px;border-radius:14px;font-size:18px;margin-bottom:14px;font-weight:900;}
        .alert-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.9);color:var(--danger);}
        .alert-success{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.9);color:var(--accent-green);}
        .table-wrapper{margin-top:12px;border-radius:22px;border:1px solid var(--border);overflow:auto;max-height:540px;}
        table{width:100%;border-collapse:collapse;font-size:16px;}
        thead{background:rgba(15,23,42,0.04);}
        body.dark thead{background:rgba(15,23,42,0.95);}
        th,td{padding:10px 12px;border-bottom:1px solid var(--border);text-align:right;white-space:nowrap;}
        th{font-weight:900;color:var(--text-muted);font-size:16px;}
        td{font-weight:800;font-size:16px;}
        .btn-pay,.btn-small-danger,.btn-small-edit,.btn-small-extend,.btn-small-print{
            border-radius:999px;padding:7px 14px;border:none;cursor:pointer;
            font-size:14px;font-weight:900;color:#f9fafb;
        }
        .btn-pay{background:#22c55e;}
        .btn-small-danger{background:#ef4444;}
        .btn-small-edit{background:#f59e0b;}
        .btn-small-extend{background:#2563eb;}
        .btn-small-print{background:#0f766e;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;}
        .btn-pay:hover,.btn-small-danger:hover,.btn-small-edit:hover,.btn-small-extend:hover,.btn-small-print:hover{filter:brightness(1.07);}
        .badge-remaining-positive{color:#b91c1c;font-weight:900;font-size:16px;}
        .badge-remaining-zero{color:#16a34a;font-weight:900;font-size:16px;}

        /* صورة العميل في الجدول */
        .member-avatar {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            object-fit: cover;
            border: 1px solid var(--border);
            box-shadow: 0 10px 26px rgba(15,23,42,0.18);
            background: #fff;
            cursor: pointer;
        }

        /* نافذة عرض الصورة بالحجم الطبيعي */
        .img-viewer-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.72);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 18px;
        }
        .img-viewer {
            background: var(--card-bg);
            border-radius: 18px;
            max-width: 95vw;
            max-height: 92vh;
            width: auto;
            box-shadow: 0 30px 90px rgba(0,0,0,0.55);
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .img-viewer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
        }
        .img-viewer-title {
            font-weight: 900;
            font-size: 16px;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 70vw;
        }
        .img-viewer-close {
            border: none;
            background: transparent;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
            font-weight: 900;
            line-height: 1;
        }
        .img-viewer-body {
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            max-width: 95vw;
            max-height: 82vh;
        }
        .img-viewer-body img {
            display: block;
            max-width: 95vw;
            max-height: 82vh;
            width: auto;
            height: auto;
            object-fit: contain;
            background: #000;
        }

        .modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,0.5);display:none;align-items:center;justify-content:center;z-index:30;}
        .modal{background:var(--card-bg);border-radius:26px;max-width:640px;width:100%;max-height:90vh;display:flex;flex-direction:column;
            box-shadow:0 26px 70px rgba(15,23,42,0.8);}
        .modal-header{display:flex;justify-content:space-between;align-items:center;padding:16px 20px 8px;}
        .modal-title{font-size:24px;font-weight:900;}
        .modal-close{border:none;background:transparent;font-size:24px;cursor:pointer;color:var(--text-muted);}
        .modal-body{padding:0 20px 16px;overflow-y:auto;}
        .field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px;}
        .field label{font-size:16px;color:var(--text-muted);font-weight:900;}
        input[type="text"],input[type="number"],select,input[type="date"],input[type="file"],textarea{
            width:100%;padding:10px 13px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:18px;font-weight:800;color:var(--text-main);
        }
        textarea{border-radius:18px;min-height:70px;}
        input:focus,select:focus,textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft);}
        .muted{font-size:14px;color:var(--text-muted);font-weight:700;}
        .extend-summary{
            border:1px dashed var(--border);
            padding:10px 12px;
            border-radius:16px;
            margin-top:8px;
            font-size:15px;
            font-weight:900;
        }
        .extend-summary .row{display:flex;justify-content:space-between;gap:12px;margin:4px 0;flex-wrap:wrap;}
        .extend-summary .label{color:var(--text-muted);font-weight:900;}
        .extend-summary .val{color:var(--text-main);font-weight:900;}
        .manager-payment-stats{margin-bottom:18px;}
        .manager-payment-stats-title{
            margin:0 0 12px;
            font-size:22px;
            font-weight:900;
        }
        .payment-stats-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
            gap:12px;
        }
        .payment-stat-card{
            border:1px solid var(--border);
            border-radius:22px;
            padding:16px 18px;
            background:linear-gradient(180deg, rgba(37,99,235,0.07), rgba(34,197,94,0.07));
        }
        .payment-stat-card h3{
            margin:0 0 10px;
            font-size:20px;
            font-weight:900;
        }
        .payment-stat-list{
            display:flex;
            flex-direction:column;
            gap:8px;
        }
        .payment-stat-row{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            font-size:16px;
            font-weight:900;
        }
        .payment-stat-row .label{color:var(--text-muted);}
        .payment-stat-row.total-row{
            margin-top:4px;
            padding-top:8px;
            border-top:1px dashed var(--border);
        }
        .pagination{
            display:flex;
            justify-content:center;
            align-items:center;
            gap:8px;
            flex-wrap:wrap;
            margin-top:14px;
        }
        .pagination-link,
        .pagination-current{
            min-width:44px;
            padding:10px 14px;
            border-radius:999px;
            text-align:center;
            font-size:15px;
            font-weight:900;
            border:1px solid var(--border);
            text-decoration:none;
        }
        .pagination-link{
            background:var(--card-bg);
            color:var(--text-main);
        }
        .pagination-link:hover{filter:brightness(1.05);}
        .pagination-current{
            background:linear-gradient(90deg,#2563eb,#22c55e);
            color:#f9fafb;
            border-color:transparent;
        }
        .pagination-summary{
            width:100%;
            text-align:center;
            color:var(--text-muted);
            font-size:14px;
            font-weight:900;
        }
    </style>
</head>
<body>

<!-- عارض الصورة بالحجم الطبيعي -->
<div class="img-viewer-backdrop" id="imgViewerBackdrop" aria-hidden="true">
    <div class="img-viewer" role="dialog" aria-modal="true" aria-label="عارض الصورة">
        <div class="img-viewer-header">
            <div class="img-viewer-title" id="imgViewerTitle">صورة العميل</div>
            <button type="button" class="img-viewer-close" id="imgViewerCloseBtn">×</button>
        </div>
        <div class="img-viewer-body">
            <img src="" alt="صورة بالحجم الطبيعي" id="imgViewerImg">
        </div>
    </div>
</div>

<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">إدارة المشتركين</div>
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

        <!-- فورم البحث -->
        <form method="get" action="" style="margin-bottom:12px; display:flex; gap:8px;">
            <input type="text" name="q"
                   placeholder="ابحث بالاسم أو الباركود أو رقم التليفون"
                   value="<?php echo htmlspecialchars($q ?? ''); ?>"
                   style="flex:1; padding:10px 13px; border-radius:999px; border:1px solid var(--border);">
            <button type="submit" class="btn-main">بحث</button>
        </form>

        <?php
        // قراءة الفلاتر من GET (تمت قراءتها أعلى الملف أيضاً للاستخدام في الاستعلام)
        $statusFilter = $_GET['status_filter'] ?? $statusFilter ?? '';
        $debtsFilter  = $_GET['debts_filter']  ?? $debtsFilter  ?? '';
        ?>
        <!-- فلاتر إضافية -->
        <form method="get" action="" style="margin-bottom:12px; display:flex; gap:8px; flex-wrap:wrap;">
            <input type="hidden" name="q" value="<?php echo htmlspecialchars($q ?? ''); ?>">

            <select name="status_filter"
                    style="padding:10px 13px; border-radius:999px; border:1px solid var(--border); font-weight:800;">
                <option value="">كل الحالات</option>
                <option value="active"   <?php echo ($statusFilter === 'active'   ? 'selected' : ''); ?>>مشتركين مستمرين فقط</option>
                <option value="ended"    <?php echo ($statusFilter === 'ended'    ? 'selected' : ''); ?>>مشتركين منتهية</option>
                <option value="frozen"   <?php echo ($statusFilter === 'frozen'   ? 'selected' : ''); ?>>مشتركين مجمدين</option>
            </select>

            <select name="debts_filter"
                    style="padding:10px 13px; border-radius:999px; border:1px solid var(--border); font-weight:800;">
                <option value="">الكل (بغض النظر عن المتبقي)</option>
                <option value="with_debts" <?php echo ($debtsFilter === 'with_debts' ? 'selected' : ''); ?>>
                    فقط من عليهم مبالغ متبقية
                </option>
                <option value="no_debts"   <?php echo ($debtsFilter === 'no_debts' ? 'selected' : ''); ?>>
                    فقط من لا يوجد عليهم متبقي
                </option>
            </select>

            <button type="submit" class="btn-main">تطبيق الفلاتر</button>
        </form>

        <div class="controls">
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php if ($canManageMembers): ?>
                    <a href="export_members_excel.php" class="btn-main">
                        <span>📥</span>
                        <span>تصدير المشتركين (Excel)</span>
                    </a>
                    <a href="download_members_template.php" class="btn-main">
                        <span>📄</span>
                        <span>تحميل نموذج إدخال الأسماء (Excel)</span>
                    </a>
                <?php endif; ?>
            </div>

            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php if ($canManageMembers): ?>
                    <button type="button" class="btn-main" id="btnShowAddForm">
                        <span>➕</span>
                        <span>إضافة مشترك جديد</span>
                    </button>
                <?php endif; ?>
                <?php if ($isManager): ?>
                    <form method="post" action="" onsubmit="return confirm('هل أنت متأكد من تسديد كل المبالغ المتبقية لجميع المشتركين؟');">
                        <input type="hidden" name="action" value="settle_all_remaining_balances">
                        <input type="hidden" name="settle_all_confirmation" value="yes">
                        <input type="hidden" name="settle_all_members_remaining_token" value="<?php echo htmlspecialchars($settleAllMembersRemainingToken, ENT_QUOTES); ?>">
                        <button type="submit" class="btn-pay" style="height:100%;padding:12px 18px;">
                            تسديد المتبقي لجميع المشتركين
                        </button>
                    </form>
                    <form method="post" action="" onsubmit="return confirm('هل أنت متأكد من حذف جميع المشتركين؟ سيتم حذف بياناتهم من الإحصائيات والتقفيل اليومي والشهري.');">
                        <input type="hidden" name="action" value="delete_all_members">
                        <input type="hidden" name="delete_all_confirmation" value="yes">
                        <input type="hidden" name="delete_all_members_token" value="<?php echo htmlspecialchars($deleteAllMembersToken, ENT_QUOTES); ?>">
                        <button type="submit" class="btn-small-danger" style="height:100%;padding:12px 18px;">
                            حذف جميع المشتركين
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($canManageMembers): ?>
            <div style="margin-bottom:16px;">
                <form method="post" action="import_members_excel.php" enctype="multipart/form-data">
                    <input type="file" name="members_file" accept=".xlsx,.xls" required
                           style="margin-bottom:10px;">
                    <button type="submit" class="btn-main">
                        <span>📤</span>
                        <span>استيراد المشتركين</span>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($isManager && $managerPaymentPeriodStats): ?>
            <div class="manager-payment-stats">
                <h2 class="manager-payment-stats-title">إحصائيات المدفوعات حسب نوع الدفع (للمدير فقط)</h2>
                <div class="payment-stats-grid">
                    <?php foreach ($managerPaymentPeriodStats as $periodLabel => $periodTotals): ?>
                        <div class="payment-stat-card">
                            <h3><?php echo htmlspecialchars($periodLabel); ?></h3>
                            <div class="payment-stat-list">
                                <?php foreach (getAllowedMemberPaymentTypes() as $paymentTypeLabel): ?>
                                    <div class="payment-stat-row">
                                        <span class="label"><?php echo htmlspecialchars($paymentTypeLabel); ?></span>
                                        <span><?php echo number_format((float)($periodTotals[$paymentTypeLabel] ?? 0), 2); ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <div class="payment-stat-row total-row">
                                    <span class="label">الإجمالي</span>
                                    <span><?php echo number_format((float)($periodTotals['الإجمالي'] ?? 0), 2); ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- إحصائيات بناءً على الفلاتر الحالية -->
        <div style="margin-bottom:10px; display:flex; flex-wrap:wrap; gap:12px;">
            <div class="alert alert-success" style="margin:0;">
                <div>عدد المشتركين المطابقين للفلاتر:
                    <strong><?php echo (int)$totalMembers; ?></strong>
                </div>
                <div>عدد المشتركين المعروضين في الصفحة الحالية:
                    <strong><?php echo (int)$currentPageMembersCount; ?></strong>
                </div>
            </div>

            <div class="alert alert-error" style="margin:0;">
                <div>عدد المشتركين الذين عليهم مبالغ متبقية ضمن كل النتائج:
                    <strong><?php echo (int)$totalWithDebtsCount; ?></strong>
                </div>
                <?php if ($isManager): ?>
                    <div>إجمالي المبالغ المتبقية ضمن كل النتائج:
                        <strong><?php echo number_format($totalDebtsAmount, 2); ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>الصورة</th>
                    <th>الاسم</th>
                    <th>الهاتف</th>
                    <th>الباركود</th>
                    <th>السن</th>
                    <th>النوع</th>
                    <th>الاشتراك</th>
                    <th>المدرب</th>
                    <th>الأيام</th>
                    <th>أيام الـ Freeze</th>
                    <th>التمارين المتبقية</th>
                    <th>الدعوات</th>
                    <th>نوع الدفع</th>
                    <th>المتبقي</th>
                    <th>سبا</th>
                    <th>مساج</th>
                    <th>جاكوزي</th>
                    <th>تاريخ البداية</th>
                    <th>تاريخ النهاية</th>
                    <th>الحالة</th>
                    <th>أضيف بواسطة</th>
                    <th>سداد</th>
                    <th>إجراءات</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$members): ?>
                    <tr>
                        <td colspan="23" style="text-align:center;color:var(--text-muted);font-weight:800;">
                            لا يوجد مشتركين مسجلين بالمعايير الحالية.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($members as $m): ?>
                        <?php
                            $imgPath = memberDisplayImage($m['photo_path'] ?? null, $gymLogoPath);
                            $imgTitle = 'صورة: ' . ($m['name'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <?php if ($imgPath): ?>
                                    <img
                                        class="member-avatar js-member-avatar"
                                        src="<?php echo htmlspecialchars($imgPath); ?>"
                                        alt="صورة العميل"
                                        data-full="<?php echo htmlspecialchars($imgPath); ?>"
                                        data-title="<?php echo htmlspecialchars($imgTitle); ?>"
                                    >
                                <?php else: ?>
                                    <div class="member-avatar" style="display:flex;align-items:center;justify-content:center;">
                                        👤
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($m['name']); ?></td>
                            <td><?php echo htmlspecialchars($m['phone']); ?></td>
                            <td><?php echo htmlspecialchars($m['barcode']); ?></td>
                            <td><?php echo (int)$m['age'] > 0 ? (int)$m['age'] : '—'; ?></td>
                            <td><?php echo htmlspecialchars($m['gender']); ?></td>
                            <td><?php echo htmlspecialchars($m['subscription_name']); ?></td>
                            <td><?php echo htmlspecialchars($m['trainer_name'] ?: 'بدون مدرب'); ?></td>
                            <td><?php echo (int)$m['days']; ?></td>
                            <td><?php echo (int)$m['freeze_days']; ?></td>
                            <td><?php echo (int)$m['sessions_remaining']; ?></td>
                            <td><?php echo (int)$m['invites']; ?></td>
                            <td><?php echo htmlspecialchars($m['payment_type'] ?: 'كاش'); ?></td>
                            <td>
                                <?php if ($m['remaining_amount'] > 0): ?>
                                    <span class="badge-remaining-positive">
                                        <?php echo number_format($m['remaining_amount'], 2); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge-remaining-zero">0.00</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)$m['spa_count']; ?></td>
                            <td><?php echo (int)$m['massage_count']; ?></td>
                            <td><?php echo (int)$m['jacuzzi_count']; ?></td>
                            <td><?php echo htmlspecialchars($m['start_date']); ?></td>
                            <td><?php echo htmlspecialchars($m['end_date']); ?></td>
                            <td><?php echo htmlspecialchars($m['status']); ?></td>
                            <td><?php echo htmlspecialchars($m['created_by_username'] ?? '—'); ?></td>
                            <td>
                                <?php if ($m['remaining_amount'] > 0 && $canManageMembers): ?>
                                    <form method="post" action="" style="display:flex;gap:6px;align-items:center;">
                                        <input type="hidden" name="action" value="pay_rest">
                                        <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                                        <input type="number" step="0.01" name="pay_amount" min="0.01"
                                               max="<?php echo (float)$m['remaining_amount']; ?>"
                                               style="width:110px;padding:7px 10px;border-radius:999px;border:1px solid var(--border);font-size:15px;font-weight:800;">
                                        <button type="submit" class="btn-pay">تسديد</button>
                                    </form>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="subscription_receipt.php?id=<?php echo (int)$m['id']; ?>&print=1"
                                   class="btn-small-print"
                                   target="_blank"
                                   rel="noopener">طباعة</a>
                                <?php if ($canManageMembers): ?>
                                    <button
                                        type="button"
                                        class="btn-small-edit"
                                        onclick="openEditModal(
                                            <?php echo (int)$m['id']; ?>,
                                            '<?php echo htmlspecialchars($m['name'], ENT_QUOTES); ?>',
                                            '<?php echo htmlspecialchars($m['phone'], ENT_QUOTES); ?>',
                                            '<?php echo htmlspecialchars($m['barcode'], ENT_QUOTES); ?>',
                                            <?php echo (int)$m['age']; ?>,
                                            '<?php echo htmlspecialchars($m['gender'], ENT_QUOTES); ?>',
                                            '<?php echo htmlspecialchars($m['address'], ENT_QUOTES); ?>',
                                            <?php echo (int)$m['subscription_id']; ?>,
                                            <?php echo (int)($m['trainer_id'] ?? 0); ?>,
                                            <?php echo (int)$m['days']; ?>,
                                            <?php echo (int)$m['freeze_days']; ?>,
                                            <?php echo (int)$m['sessions_remaining']; ?>,
                                            <?php echo (float)$m['subscription_amount']; ?>,
                                            <?php echo (float)($m['member_discount_amount'] ?? 0); ?>,
                                            <?php echo (float)$m['paid_amount']; ?>,
                                            '<?php echo htmlspecialchars($m['payment_type'] ?: 'كاش', ENT_QUOTES); ?>',
                                            '<?php echo htmlspecialchars($m['start_date'], ENT_QUOTES); ?>',
                                            <?php echo (int)$m['spa_count']; ?>,
                                            <?php echo (int)$m['massage_count']; ?>,
                                            <?php echo (int)$m['jacuzzi_count']; ?>
                                        )"
                                    >تعديل</button>

                                    <button
                                        type="button"
                                        class="btn-small-extend"
                                        onclick="openExtendModal(
                                            <?php echo (int)$m['id']; ?>,
                                            '<?php echo htmlspecialchars($m['name'], ENT_QUOTES); ?>',
                                            <?php echo (int)$m['subscription_id']; ?>,
                                            '<?php echo htmlspecialchars($m['subscription_name'], ENT_QUOTES); ?>',
                                            '<?php echo htmlspecialchars($m['start_date'] ?? '', ENT_QUOTES); ?>',
                                            '<?php echo htmlspecialchars($m['end_date'] ?? '', ENT_QUOTES); ?>',
                                            <?php echo (float)$m['subscription_amount']; ?>,
                                            <?php echo (float)$m['paid_amount']; ?>,
                                            <?php echo (float)$m['remaining_amount']; ?>,
                                            '<?php echo htmlspecialchars($m['payment_type'] ?: getDefaultMemberPaymentType(), ENT_QUOTES); ?>'
                                        )"
                                    >تمديد</button>

                                    <form method="post" action=""
                                          style="display:inline-block;margin-right:6px;"
                                          onsubmit="return confirm('هل أنت متأكد من حذف هذا المشترك؟');">
                                        <input type="hidden" name="action" value="delete_member">
                                        <input type="hidden" name="member_id" value="<?php echo (int)$m['id']; ?>">
                                        <button type="submit" class="btn-small-danger">حذف</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
            ?>
            <div class="pagination">
                <div class="pagination-summary">
                   <?php echo (int)$page; ?> من <?php echo (int)$totalPages; ?> 
                </div>

                <?php if ($page > 1): ?>
                    <a class="pagination-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => 1]))); ?>">الأولى</a>
                    <a class="pagination-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => $page - 1]))); ?>">السابق</a>
                <?php endif; ?>

                <?php for ($pageNumber = $startPage; $pageNumber <= $endPage; $pageNumber++): ?>
                    <?php if ($pageNumber === $page): ?>
                        <span class="pagination-current"><?php echo (int)$pageNumber; ?></span>
                    <?php else: ?>
                        <a class="pagination-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => $pageNumber]))); ?>"><?php echo (int)$pageNumber; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a class="pagination-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => $page + 1]))); ?>">التالي</a>
                    <a class="pagination-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($paginationBaseParams, ['page' => $totalPages]))); ?>">الأخيرة</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManageMembers): ?>
<!-- مودال إضافة/تعديل -->
<div class="modal-backdrop" id="memberModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle">إضافة مشترك جديد</div>
            <button type="button" class="modal-close" id="btnCloseModal">×</button>
        </div>
        <div class="modal-body">
            <form method="post" action="" id="memberForm" enctype="multipart/form-data">
                <input type="hidden" name="action" id="formAction" value="add_member">
                <input type="hidden" name="member_id" id="memberId" value="">

                <div class="field">
                    <label for="m_name">اسم المشترك</label>
                    <input type="text" id="m_name" name="name" required>
                </div>

                <div class="field">
                    <label for="m_phone">رقم التليفون</label>
                    <input type="text" id="m_phone" name="phone" required>
                </div>

                <div class="field">
                    <label for="m_barcode">باركود المشترك</label>
                    <input type="text" id="m_barcode" name="barcode" value="" required pattern=".*\S.*" title="من فضلك اكتب باركود المشترك.">
                    <div class="muted">اكتب الباركود يدوياً لكل مشترك، ويجب أن يكون غير مكرر.</div>
                </div>

                <div class="field">
                    <label for="m_age">السن</label>
                    <input type="number" id="m_age" name="age" min="0">
                </div>

                <div class="field">
                    <label for="m_gender">النوع</label>
                    <select id="m_gender" name="gender">
                        <option value="ذكر">ذكر</option>
                        <option value="أنثى">أنثى</option>
                    </select>
                </div>

                <div class="field">
                    <label for="m_address">العنوان</label>
                    <input type="text" id="m_address" name="address">
                </div>

                <div class="field">
                    <label for="m_subscription_category">تصنيف الاشتراك</label>
                    <select id="m_subscription_category">
                        <option value="">اختر تصنيف الاشتراك...</option>
                        <?php foreach ($subscriptionCategories as $categoryOption): ?>
                            <option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES); ?>">
                                <?php echo htmlspecialchars($categoryOption); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if ($hasUncategorizedSubscriptions): ?>
                            <option value="__uncategorized__">بدون تصنيف</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="m_subscription_id">الاشتراك</label>
                    <select id="m_subscription_id" name="subscription_id" required>
                        <option value="">اختر تصنيف الاشتراك أولاً...</option>
                        <?php foreach ($subscriptions as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"
                                    data-category="<?php echo htmlspecialchars(trim((string)($s['subscription_category'] ?? '')), ENT_QUOTES); ?>"
                                    data-days="<?php echo (int)$s['days']; ?>"
                                    data-sessions="<?php echo (int)$s['sessions']; ?>"
                                    data-invites="<?php echo (int)$s['invites']; ?>"
                                    data-freeze="<?php echo (int)$s['freeze_days']; ?>"
                                    data-amount="<?php echo (float)$s['price_after_discount']; ?>"
                                    data-spa="<?php echo (int)$s['spa_count']; ?>"
                                    data-massage="<?php echo (int)$s['massage_count']; ?>"
                                    data-jacuzzi="<?php echo (int)$s['jacuzzi_count']; ?>"
                                >
                                <?php echo htmlspecialchars($s['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="m_trainer_id">المدرب</label>
                    <select id="m_trainer_id" name="trainer_id">
                        <option value="0">بدون مدرب</option>
                        <?php foreach ($trainers as $trainer): ?>
                            <option
                                value="<?php echo (int)$trainer['id']; ?>"
                                data-percentage="<?php echo (float)$trainer['commission_percentage']; ?>"
                            >
                                <?php echo htmlspecialchars($trainer['name']); ?>
                                — <?php echo number_format((float)$trainer['commission_percentage'], 2); ?>%
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="muted">اختر مدرباً من القائمة أو اتركها بدون مدرب.</div>
                </div>

                <div class="field">
                    <label for="m_start_date">تاريخ بداية الاشتراك</label>
                    <input type="date" id="m_start_date" name="start_date" required>
                    <div class="muted">اختر تاريخ بداية الاشتراك من التقويم لسهولة الإدخال.</div>
                </div>

                <div class="field">
                    <label>عدد الأيام</label>
                    <input type="number" id="m_sub_days" disabled>
                </div>

                <div class="field">
                    <label>عدد مرات التمرين</label>
                    <input type="number" id="m_sub_sessions" disabled>
                </div>

                <div class="field">
                    <label>عدد أيام الـ Freeze</label>
                    <input type="number" id="m_sub_freeze" disabled>
                </div>

                <?php if ($isManager): ?>
                    <div class="field">
                        <label>سعر الاشتراك قبل الخصم الإضافي</label>
                        <input type="number" id="m_sub_base_amount" disabled>
                    </div>
                    <div class="field">
                        <label for="m_member_discount_amount">خصم إضافي على الاشتراك (اختياري)</label>
                        <input type="number" step="0.01" id="m_member_discount_amount" name="member_discount_amount" min="0" value="0" disabled>
                        <div class="muted">هذا الحقل متاح للمدير فقط، ويتم خصم المبلغ المُدخل من سعر الاشتراك.</div>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label>مبلغ الاشتراك النهائي</label>
                    <input type="number" step="0.01" id="m_sub_amount" disabled>
                </div>

                <div class="field">
                    <label for="m_paid_amount">المدفوع</label>
                    <input type="number" step="0.01" id="m_paid_amount" name="paid_amount" min="0">
                </div>

                <div class="field">
                    <label for="m_payment_type">نوع الدفع</label>
                    <select id="m_payment_type" name="payment_type" required>
                        <?php foreach (getAllowedMemberPaymentTypes() as $allowedPaymentType): ?>
                            <option value="<?php echo htmlspecialchars($allowedPaymentType); ?>">
                                <?php echo htmlspecialchars($allowedPaymentType); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="m_trainer_amount">مبلغ المدرب من هذا الاشتراك</label>
                    <input type="number" step="0.01" id="m_trainer_amount" value="0.00" readonly style="background:#e5e7eb;">
                </div>

                <div class="field">
                    <label for="m_spa_count">عدد جلسات السبا</label>
                    <input type="number" id="m_spa_count" name="spa_count" min="0" readonly style="background:#e5e7eb;">
                </div>
                <div class="field">
                    <label for="m_massage_count">عدد جلسات المساج</label>
                    <input type="number" id="m_massage_count" name="massage_count" min="0" readonly style="background:#e5e7eb;">
                </div>
                <div class="field">
                    <label for="m_jacuzzi_count">عدد جلسات الجاكوزي</label>
                    <input type="number" id="m_jacuzzi_count" name="jacuzzi_count" min="0" readonly style="background:#e5e7eb;">
                </div>

                <div class="field">
                    <label for="m_member_photo">صورة العميل (اختياري - حتى 15MB)</label>
                    <input type="file" id="m_member_photo" name="member_photo" accept="image/*">
                    <div class="muted">يدعم كل أنواع الصور (يتم التحقق من أن الملف صورة حقيقية). إذا لم ترفع صورة سيتم استخدام شعار الجيم كصورة افتراضية في الجدول.</div>
                </div>

                <button type="submit" class="btn-main" style="margin-top:8px;">
                    <span>💾</span>
                    <span id="modalSaveText">حفظ المشترك</span>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- مودال تمديد الاشتراك + دفع الآن -->
<div class="modal-backdrop" id="extendModal">
    <div class="modal">
        <div class="modal-header">
            <div class="modal-title" id="extendModalTitle">تمديد اشتراك</div>
            <button type="button" class="modal-close" id="btnCloseExtendModal">×</button>
        </div>
        <div class="modal-body">
            <form method="post" action="" id="extendForm">
                <input type="hidden" name="action" value="extend_subscription">
                <input type="hidden" name="member_id" id="extend_member_id" value="">

                <div class="field">
                    <label>اسم المشترك</label>
                    <input type="text" id="extend_member_name" disabled>
                </div>

                <div class="field">
                    <label>الاشتراك الحالي</label>
                    <input type="text" id="extend_old_sub_name" disabled>
                </div>

                <div class="field">
                    <label>تاريخ البداية (لن يتغير)</label>
                    <input type="text" id="extend_start_date" disabled>
                </div>

                <div class="field">
                    <label>تاريخ النهاية الحالي</label>
                    <input type="text" id="extend_old_end_date" disabled>
                </div>

                <div class="field">
                    <label for="extend_new_subscription_id">اختر الاشتراك الجديد (للتمديد)</label>
                    <select id="extend_new_subscription_id" name="new_subscription_id" required>
                        <option value="">اختر اشتراكاً...</option>
                        <?php foreach ($subscriptions as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>"
                                    data-days="<?php echo (int)$s['days']; ?>"
                                    data-amount="<?php echo (float)$s['price_after_discount']; ?>"
                                >
                                <?php echo htmlspecialchars($s['name']); ?>
                                (<?php echo (int)$s['days']; ?> يوم - <?php echo number_format((float)$s['price_after_discount'], 2); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="muted">سيتم احتساب النهاية الجديدة من نفس تاريخ البداية، وسيتم احتساب المدفوع الحالي ضمن السعر الجديد.</div>
                </div>

                <div class="field">
                    <label for="extend_pay_now">دفع الآن (اختياري)</label>
                    <input type="number" step="0.01" min="0" id="extend_pay_now" name="extend_pay_now" value="0">
                    <div class="muted">لو العميل سيدفع الفرق فوراً اكتب المبلغ هنا. سيتم إضافته إلى المدفوع وتسجيله في سجل السداد.</div>
                </div>

                <div class="field">
                    <label for="extend_payment_type">نوع الدفع للمبلغ المدفوع الآن</label>
                    <select id="extend_payment_type" name="extend_payment_type">
                        <?php foreach (getAllowedMemberPaymentTypes() as $allowedPaymentType): ?>
                            <option value="<?php echo htmlspecialchars($allowedPaymentType); ?>">
                                <?php echo htmlspecialchars($allowedPaymentType); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="muted">سيُستخدم هذا النوع في إحصائية نوع الدفع عند تسجيل مبلغ التجديد المبكر، ويُفعّل فقط عند إدخال مبلغ أكبر من صفر.</div>
                </div>

                <div class="extend-summary" id="extendSummary">
                    <div class="row"><span class="label">سعر الاشتراك الحالي:</span><span class="val" id="sum_old_amount">—</span></div>
                    <div class="row"><span class="label">المدفوع الحالي (سيُخصم من الجديد):</span><span class="val" id="sum_old_paid">—</span></div>
                    <div class="row"><span class="label">سعر الاشتراك الجديد:</span><span class="val" id="sum_new_amount">—</span></div>
                    <div class="row"><span class="label">تاريخ النهاية الجديد:</span><span class="val" id="sum_new_end">—</span></div>
                    <div class="row"><span class="label">المتبقي بعد التمديد:</span><span class="val" id="sum_new_remaining">—</span></div>
                </div>

                <div class="field">
                    <label for="extend_notes">ملاحظات (اختياري)</label>
                    <textarea id="extend_notes" name="extend_notes" placeholder="مثال: ترقية من شهر إلى 3 شهور..."></textarea>
                </div>

                <button type="submit" class="btn-main" style="margin-top:8px;">
                    <span>⏳</span>
                    <span>تنفيذ التمديد</span>
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

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

    // عارض الصورة
    const imgViewerBackdrop = document.getElementById('imgViewerBackdrop');
    const imgViewerImg      = document.getElementById('imgViewerImg');
    const imgViewerTitle    = document.getElementById('imgViewerTitle');
    const imgViewerCloseBtn = document.getElementById('imgViewerCloseBtn');

    function openImageViewer(src, titleText) {
        if (!imgViewerBackdrop || !imgViewerImg) return;
        imgViewerImg.src = src;
        if (imgViewerTitle) imgViewerTitle.textContent = titleText || 'صورة العميل';
        imgViewerBackdrop.style.display = 'flex';
        imgViewerBackdrop.setAttribute('aria-hidden', 'false');
    }

    function closeImageViewer() {
        if (!imgViewerBackdrop || !imgViewerImg) return;
        imgViewerBackdrop.style.display = 'none';
        imgViewerBackdrop.setAttribute('aria-hidden', 'true');
        imgViewerImg.src = '';
    }

    document.addEventListener('click', function (e) {
        const t = e.target;
        if (t && t.classList && t.classList.contains('js-member-avatar')) {
            const full = t.getAttribute('data-full') || t.getAttribute('src');
            const title = t.getAttribute('data-title') || 'صورة العميل';
            if (full) openImageViewer(full, title);
        }
    });

    if (imgViewerCloseBtn) imgViewerCloseBtn.addEventListener('click', closeImageViewer);
    if (imgViewerBackdrop) {
        imgViewerBackdrop.addEventListener('click', function (e) {
            if (e.target === imgViewerBackdrop) closeImageViewer();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeImageViewer();
    });

    <?php if ($canManageMembers): ?>
    const modal      = document.getElementById('memberModal');
    const showBtn    = document.getElementById('btnShowAddForm');
    const closeBtn   = document.getElementById('btnCloseModal');
    const form       = document.getElementById('memberForm');
    const formAction = document.getElementById('formAction');
    const memberIdEl = document.getElementById('memberId');
    const modalTitle = document.getElementById('modalTitle');
    const modalSaveText = document.getElementById('modalSaveText');
    const barcodeInput = document.getElementById('m_barcode');

    const categorySelect = document.getElementById('m_subscription_category');
    const subSelect      = document.getElementById('m_subscription_id');
    const trainerSelect  = document.getElementById('m_trainer_id');
    const daysInput      = document.getElementById('m_sub_days');
    const sessInput      = document.getElementById('m_sub_sessions');
    const freezeInput    = document.getElementById('m_sub_freeze');
    const baseAmountInput = document.getElementById('m_sub_base_amount');
    const amountInput    = document.getElementById('m_sub_amount');
    const discountInput  = document.getElementById('m_member_discount_amount');
    const paidInput      = document.getElementById('m_paid_amount');
    const paymentTypeInput = document.getElementById('m_payment_type');
    const trainerAmountInput = document.getElementById('m_trainer_amount');
    const startDateInput = document.getElementById('m_start_date');

    const spaInput       = document.getElementById('m_spa_count');
    const massageInput   = document.getElementById('m_massage_count');
    const jacuzziInput   = document.getElementById('m_jacuzzi_count');
    const allSubscriptionOptions = subSelect ? Array.from(subSelect.options).slice(1).map(option => option.cloneNode(true)) : [];

    function openModal() {
        modal.style.display = 'flex';
    }
    function closeModal() {
        modal.style.display = 'none';
        formAction.value = 'add_member';
        memberIdEl.value = '';
        modalTitle.textContent = 'إضافة مشترك جديد';
        modalSaveText.textContent = 'حفظ المشترك';
        form.reset();
        if (barcodeInput) {
            barcodeInput.value = '';
            barcodeInput.readOnly = false;
        }
        if (categorySelect) categorySelect.value = '';
        daysInput.value   = '';
        sessInput.value   = '';
        freezeInput.value = '';
        if (baseAmountInput) baseAmountInput.value = '';
        amountInput.value = '';
        if (discountInput) {
            discountInput.value = '0';
            discountInput.disabled = true;
            discountInput.removeAttribute('max');
        }
        if (paymentTypeInput) paymentTypeInput.value = 'كاش';
        if (trainerSelect) trainerSelect.value = '0';
        if (trainerAmountInput) trainerAmountInput.value = '0.00';
        if(spaInput) spaInput.value = '';
        if(massageInput) massageInput.value = '';
        if(jacuzziInput) jacuzziInput.value = '';
        rebuildSubscriptionOptions('');
    }

    function showStartDatePicker() {
        if (!startDateInput || typeof startDateInput.showPicker !== 'function') {
            return;
        }

        try {
            startDateInput.showPicker();
        } catch (error) {
            // Some browsers prevent programmatically opening the date picker in certain circumstances.
        }
    }

    function getNormalizedSubscriptionCategory(categoryValue) {
        return categoryValue === '__uncategorized__' ? '' : (categoryValue || '');
    }

    function rebuildSubscriptionOptions(selectedCategory = '', selectedSubscriptionId = '') {
        if (!subSelect) return;

        const normalizedCategory = getNormalizedSubscriptionCategory(selectedCategory);
        const currentValue = selectedSubscriptionId !== '' && selectedSubscriptionId !== null
            ? String(selectedSubscriptionId)
            : '';

        subSelect.innerHTML = '';

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = selectedCategory
            ? 'اختر اشتراكاً...'
            : 'اختر تصنيف الاشتراك أولاً...';
        subSelect.appendChild(placeholder);

        let matchedCount = 0;
        allSubscriptionOptions.forEach((option) => {
            const optionCategory = (option.getAttribute('data-category') || '').trim();
            if (!selectedCategory || optionCategory !== normalizedCategory) {
                return;
            }

            const clonedOption = option.cloneNode(true);
            if (currentValue !== '' && clonedOption.value === currentValue) {
                clonedOption.selected = true;
            }
            subSelect.appendChild(clonedOption);
            matchedCount += 1;
        });

        if (!matchedCount) {
            placeholder.textContent = selectedCategory
                ? 'لا توجد اشتراكات داخل هذا التصنيف.'
                : 'اختر تصنيف الاشتراك أولاً...';
        }
    }

    function updateSubscriptionAmountPreview(customBaseAmount = null, customDiscountAmount = null) {
        let baseAmount = customBaseAmount;
        if (baseAmount === null) {
            const selectedOption = subSelect && subSelect.selectedIndex >= 0 ? subSelect.options[subSelect.selectedIndex] : null;
            const selectedValue = selectedOption ? selectedOption.value : '';
            if (!selectedValue) {
                if (baseAmountInput) baseAmountInput.value = '';
                amountInput.value = '';
                if (discountInput) {
                    discountInput.disabled = true;
                    discountInput.removeAttribute('max');
                }
                return;
            }
            baseAmount = parseFloat(selectedOption.getAttribute('data-amount') || '0') || 0;
        }

        let discountAmount = customDiscountAmount;
        if (discountAmount === null) {
            discountAmount = discountInput ? (parseFloat(discountInput.value || '0') || 0) : 0;
        }

        discountAmount = Math.max(0, Math.min(discountAmount, baseAmount));

        if (baseAmountInput) baseAmountInput.value = baseAmount.toFixed(2);
        if (discountInput) {
            discountInput.disabled = false;
            discountInput.max = baseAmount.toFixed(2);
        }
        if (discountInput && customDiscountAmount !== null) {
            discountInput.value = discountAmount.toFixed(2);
        }
        amountInput.value = (baseAmount - discountAmount).toFixed(2);
    }

    function updateTrainerAmountPreview() {
        if (!trainerSelect || !trainerAmountInput) return;

        const trainerOption = trainerSelect.options[trainerSelect.selectedIndex];
        const percentage = parseFloat((trainerOption && trainerOption.getAttribute('data-percentage')) || '0');
        const paidValue = parseFloat(paidInput && paidInput.value ? paidInput.value : '0');
        const trainerAmount = paidValue > 0 && percentage > 0 ? ((paidValue * percentage) / 100) : 0;

        trainerAmountInput.value = trainerAmount.toFixed(2);
    }

    if (showBtn) showBtn.addEventListener('click', () => {
        closeModal();
        openModal();
    });

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
    }

    if (subSelect) {
        subSelect.addEventListener('change', function () {
            const opt    = this.options[this.selectedIndex];
            const days   = opt.getAttribute('data-days')    || '';
            const sess   = opt.getAttribute('data-sessions')|| '';
            const freeze = opt.getAttribute('data-freeze')  || '';
            const amount = opt.getAttribute('data-amount')  || '';
            const spa     = opt.getAttribute('data-spa')     || '0';
            const massage = opt.getAttribute('data-massage') || '0';
            const jacuzzi = opt.getAttribute('data-jacuzzi') || '0';

            daysInput.value    = days;
            sessInput.value    = sess;
            freezeInput.value  = freeze;
            updateSubscriptionAmountPreview(parseFloat(amount || '0') || 0);
            if (!paidInput.value) paidInput.value = '0';

            if (spaInput) spaInput.value = spa;
            if (massageInput) massageInput.value = massage;
            if (jacuzziInput) jacuzziInput.value = jacuzzi;
            updateTrainerAmountPreview();
        });
    }

    if (categorySelect) {
        categorySelect.addEventListener('change', function () {
            rebuildSubscriptionOptions(this.value);
            if (daysInput) daysInput.value = '';
            if (sessInput) sessInput.value = '';
            if (freezeInput) freezeInput.value = '';
            if (baseAmountInput) baseAmountInput.value = '';
            if (amountInput) amountInput.value = '';
            if (discountInput) {
                discountInput.value = '0';
                discountInput.disabled = true;
                discountInput.removeAttribute('max');
            }
            if (spaInput) spaInput.value = '';
            if (massageInput) massageInput.value = '';
            if (jacuzziInput) jacuzziInput.value = '';
            updateTrainerAmountPreview();
        });
    }

    if (trainerSelect) {
        trainerSelect.addEventListener('change', updateTrainerAmountPreview);
    }
    if (paidInput) {
        paidInput.addEventListener('input', updateTrainerAmountPreview);
    }

    if (discountInput) {
        discountInput.addEventListener('input', function () {
            updateSubscriptionAmountPreview();
        });
    }

    if (startDateInput) {
        startDateInput.addEventListener('focus', showStartDatePicker);
        startDateInput.addEventListener('click', showStartDatePicker);
    }

    function openEditModal(id, name, phone, barcode, age, gender, address, subscriptionId, trainerId, days, freezeDays, sessionsRemaining, amount, discountAmount, paid, paymentType, startDate, spa_count, massage_count, jacuzzi_count) {
        formAction.value = 'edit_member';
        memberIdEl.value = id;
        modalTitle.textContent = 'تعديل بيانات المشترك';
        modalSaveText.textContent = 'حفظ التعديل';

        document.getElementById('m_name').value    = name;
        document.getElementById('m_phone').value   = phone;
        if (barcodeInput) {
            barcodeInput.value = barcode;
            barcodeInput.readOnly = false;
        }
        document.getElementById('m_age').value     = age > 0 ? age : '';
        document.getElementById('m_gender').value  = gender;
        document.getElementById('m_address').value = address;

        const selectedOption = allSubscriptionOptions.find((option) => option.value === String(subscriptionId));
        if (categorySelect) {
            categorySelect.value = selectedOption && (selectedOption.getAttribute('data-category') || '').trim() !== ''
                ? (selectedOption.getAttribute('data-category') || '').trim()
                : '__uncategorized__';
        }
        rebuildSubscriptionOptions(categorySelect ? categorySelect.value : '', subscriptionId);
        subSelect.value = subscriptionId;
        if (trainerSelect) trainerSelect.value = trainerId || '0';

        daysInput.value    = days;
        freezeInput.value  = freezeDays;
        sessInput.value    = sessionsRemaining;
        const finalAmount = parseFloat(amount || 0) || 0;
        const memberDiscountAmount = parseFloat(discountAmount || 0) || 0;
        updateSubscriptionAmountPreview(finalAmount + memberDiscountAmount, memberDiscountAmount);
        paidInput.value    = paid;
        if (paymentTypeInput) paymentTypeInput.value = paymentType || 'كاش';

        if (startDateInput && startDate) {
            startDateInput.value = startDate;
        }

        if(spaInput) spaInput.value = spa_count || '0';
        if(massageInput) massageInput.value = massage_count || '0';
        if(jacuzziInput) jacuzziInput.value = jacuzzi_count || '0';
        updateTrainerAmountPreview();

        openModal();
    }
    window.openEditModal = openEditModal;

    rebuildSubscriptionOptions(categorySelect ? categorySelect.value : '');

    // =========================
    // تمديد الاشتراك (واجهة) + دفع الآن
    // =========================
    const extendModal = document.getElementById('extendModal');
    const btnCloseExtendModal = document.getElementById('btnCloseExtendModal');
    const extendMemberIdEl = document.getElementById('extend_member_id');
    const extendMemberNameEl = document.getElementById('extend_member_name');
    const extendOldSubNameEl = document.getElementById('extend_old_sub_name');
    const extendStartDateEl  = document.getElementById('extend_start_date');
    const extendOldEndDateEl = document.getElementById('extend_old_end_date');
    const extendNewSubSelect = document.getElementById('extend_new_subscription_id');
    const extendPayNowInput  = document.getElementById('extend_pay_now');
    const extendPaymentTypeSelect = document.getElementById('extend_payment_type');

    const sumOldAmount = document.getElementById('sum_old_amount');
    const sumOldPaid = document.getElementById('sum_old_paid');
    const sumNewAmount = document.getElementById('sum_new_amount');
    const sumNewEnd = document.getElementById('sum_new_end');
    const sumNewRemaining = document.getElementById('sum_new_remaining');

    let EXT_OLD_AMOUNT = 0;
    let EXT_OLD_PAID = 0;
    let EXT_START_DATE = '';

    function openExtendModal(memberId, memberName, oldSubId, oldSubName, startDate, oldEndDate, oldAmount, oldPaid, oldRemaining, currentPaymentType) {
        if (!extendModal) return;

        extendMemberIdEl.value = memberId;
        extendMemberNameEl.value = memberName || '';
        extendOldSubNameEl.value = oldSubName || '';
        extendStartDateEl.value = startDate || '';
        extendOldEndDateEl.value = oldEndDate || '';

        EXT_OLD_AMOUNT = parseFloat(oldAmount || 0);
        EXT_OLD_PAID = parseFloat(oldPaid || 0);
        EXT_START_DATE = startDate || '';

        if (extendPayNowInput) extendPayNowInput.value = '0';
        if (extendPaymentTypeSelect) {
            extendPaymentTypeSelect.value = currentPaymentType || '<?php echo addslashes(getDefaultMemberPaymentType()); ?>';
            extendPaymentTypeSelect.disabled = true;
        }

        if (sumOldAmount) sumOldAmount.textContent = EXT_OLD_AMOUNT.toFixed(2);
        if (sumOldPaid) sumOldPaid.textContent = EXT_OLD_PAID.toFixed(2);
        if (sumNewAmount) sumNewAmount.textContent = '—';
        if (sumNewEnd) sumNewEnd.textContent = '—';
        if (sumNewRemaining) sumNewRemaining.textContent = '—';

        if (extendNewSubSelect) extendNewSubSelect.value = '';

        extendModal.style.display = 'flex';
    }

    function closeExtendModal() {
        if (!extendModal) return;
        extendModal.style.display = 'none';
    }

    window.openExtendModal = openExtendModal;

    if (btnCloseExtendModal) btnCloseExtendModal.addEventListener('click', closeExtendModal);
    if (extendModal) {
        extendModal.addEventListener('click', function(e){
            if (e.target === extendModal) closeExtendModal();
        });
    }

    function addDaysToDate(dateStr, days) {
        if (!dateStr) return '';
        const parts = dateStr.split('-');
        if (parts.length !== 3) return '';
        const y = parseInt(parts[0], 10);
        const m = parseInt(parts[1], 10) - 1;
        const d = parseInt(parts[2], 10);
        const dt = new Date(y, m, d);
        if (isNaN(dt.getTime())) return '';
        dt.setDate(dt.getDate() + parseInt(days || 0, 10));
        const yy = dt.getFullYear();
        const mm = String(dt.getMonth() + 1).padStart(2, '0');
        const dd = String(dt.getDate()).padStart(2, '0');
        return yy + '-' + mm + '-' + dd;
    }

    function updateExtendSummary() {
        if (!extendNewSubSelect) return;
        const opt = extendNewSubSelect.options[extendNewSubSelect.selectedIndex];
        if (!opt) return;

        const newDays = parseInt(opt.getAttribute('data-days') || '0', 10);
        const newAmount = parseFloat(opt.getAttribute('data-amount') || '0');

        const payNow = extendPayNowInput ? (parseFloat(extendPayNowInput.value || '0') || 0) : 0;

        const newEnd = addDaysToDate(EXT_START_DATE, newDays);

        let newRemaining = newAmount - (EXT_OLD_PAID + payNow);
        if (newRemaining < 0) newRemaining = 0;

        if (sumNewAmount) sumNewAmount.textContent = newAmount.toFixed(2);
        if (sumNewEnd) sumNewEnd.textContent = newEnd || '—';
        if (sumNewRemaining) sumNewRemaining.textContent = newRemaining.toFixed(2);
        if (extendPaymentTypeSelect) {
            extendPaymentTypeSelect.disabled = payNow <= 0;
        }
    }

    if (extendNewSubSelect) extendNewSubSelect.addEventListener('change', updateExtendSummary);
    if (extendPayNowInput) extendPayNowInput.addEventListener('input', updateExtendSummary);

    <?php endif; ?>
</script>
</body>
</html>
