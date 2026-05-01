<?php
// عرض الأخطاء أثناء التطوير (يمكنك إزالته لاحقاً في الإنتاج)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'trainers_helpers.php';
require_once 'input_normalization_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

// سماحية بسيطة لفرق التقريب بمقدار قرش واحد عند حساب عمولة المدرب داخل Excel/PHP.
define('TRAINER_COMMISSION_ROUNDING_TOLERANCE_CENTS', 1);

ensureTrainersSchema($pdo);

// السماح فقط للمدير
$role      = $_SESSION['role'] ?? '';
$isManager = ($role === 'مدير');
if (!$isManager) {
    die('غير مسموح.');
}

// التحقق من الملف
if (!isset($_FILES['members_file']) || $_FILES['members_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['import_error'] = 'حدث خطأ أثناء رفع الملف.';
    header('Location: members.php');
    exit;
}

// مسار مؤقت
$tmpFilePath = $_FILES['members_file']['tmp_name'];

try {
    $spreadsheet = IOFactory::load($tmpFilePath);
    $sheet       = $spreadsheet->getActiveSheet();
} catch (Exception $e) {
    $_SESSION['import_error'] = 'الملف غير صالح كملف Excel.';
    header('Location: members.php');
    exit;
}

// قراءة الرؤوس من الصف الأول لتحديد الأعمدة
$headerRow = 1;
$highestColumn      = $sheet->getHighestColumn();
$highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

$columnsMap = []; // "اسم العمود بالعربي" => رقم العمود (1,2,...)
for ($colIndex = 1; $colIndex <= $highestColumnIndex; $colIndex++) {
    $colLetter = Coordinate::stringFromColumnIndex($colIndex);
    $value     = trim((string)$sheet->getCell($colLetter . $headerRow)->getValue());
    if ($value !== '') {
        $columnsMap[$value] = $colIndex;
    }
}

// الأعمدة المطلوبة
$requiredHeaders = [
    'اسم المشترك',
    'رقم التليفون',
    'الباركود',
    'السن',
    'النوع',
    'العنوان',
    'اسم الاشتراك',
    'المدفوع',
];

foreach ($requiredHeaders as $h) {
    if (!isset($columnsMap[$h])) {
        $_SESSION['import_error'] = 'ملف Excel لا يحتوي على العمود المطلوب: ' . $h;
        header('Location: members.php');
        exit;
    }
}

// عمود تاريخ البداية (اختياري) — ندعم اسم القالب الحالي والاسم الأقدم للتوافق.
$startHeaderAliases = [
    'تاريخ بداية الاشتراك (اختياري: YYYY-MM-DD أو يوم/شهر/سنة مثل 1/2/2026)',
    'تاريخ بداية الاشتراك (YYYY-MM-DD اختياري)',
];
$startHeader = null;
foreach ($startHeaderAliases as $candidateHeader) {
    if (isset($columnsMap[$candidateHeader])) {
        $startHeader = $candidateHeader;
        break;
    }
}
$hasStartDateCol = ($startHeader !== null);
$trainerHeader   = 'المدرب';
$trainerAmountHeader = 'مبلغ المدرب';
$hasTrainerCol   = isset($columnsMap[$trainerHeader]);
$hasTrainerAmountCol = isset($columnsMap[$trainerAmountHeader]);

// تحميل الاشتراكات (اسم -> بيانات)
$subscriptionsMap = [];
try {
    $stmt = $pdo->query("SELECT id, name, days, sessions, invites, price_after_discount FROM subscriptions");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $subscriptionsMap[$row['name']] = $row;
    }
} catch (Exception $e) {
    $_SESSION['import_error'] = 'تعذر جلب بيانات الاشتراكات من قاعدة البيانات.';
    header('Location: members.php');
    exit;
}

$trainersMap = [];
try {
    $trainers = getAllTrainers($pdo);
    foreach ($trainers as $trainerRow) {
        $trainersMap[$trainerRow['name']] = $trainerRow;
    }
} catch (Exception $e) {
    $_SESSION['import_error'] = 'تعذر جلب بيانات المدربين من قاعدة البيانات.';
    header('Location: members.php');
    exit;
}

$highestRow    = $sheet->getHighestRow();
$importedCount = 0;
$errors        = [];

// تحميل كل الباركودات الحالية دفعة واحدة لتسريع التحقق
$existingBarcodes = [];
try {
    $existingBarcodes = getExistingMemberBarcodeMap($pdo);
} catch (Exception $e) {
    // في حالة الخطأ نستمر بدون هذا التحسين، لكن سنفشل لاحقاً في الإضافة إذا كان هناك مشكلة
}

// دالة مساعدة للحصول على قيمة خلية حسب اسم العمود بالعربي
function getCellValueByHeader(Worksheet $sheet, array $columnsMap, string $header, int $row)
{
    $colIndex  = $columnsMap[$header];
    $colLetter = Coordinate::stringFromColumnIndex($colIndex);
    return $sheet->getCell($colLetter . $row)->getValue();
}

for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
    $name            = trim((string)getCellValueByHeader($sheet, $columnsMap, 'اسم المشترك', $row));
    $phoneRaw        = getCellValueByHeader($sheet, $columnsMap, 'رقم التليفون', $row);
    $barcode         = normalizeMemberBarcodeForStorage(getCellValueByHeader($sheet, $columnsMap, 'الباركود', $row));
    $ageCellValue    = getCellValueByHeader($sheet, $columnsMap, 'السن', $row);
    $ageRaw          = trim((string)$ageCellValue);
    $age             = ($ageRaw === '') ? 0 : (int)$ageCellValue;
    $gender          = normalizeGenderForStorage(getCellValueByHeader($sheet, $columnsMap, 'النوع', $row));
    $address         = trim((string)getCellValueByHeader($sheet, $columnsMap, 'العنوان', $row));
    $subscriptionName= trim((string)getCellValueByHeader($sheet, $columnsMap, 'اسم الاشتراك', $row));

    // المدفوع نحسبه كقيمة محسوبة
    $paidColIndex  = $columnsMap['المدفوع'];
    $paidColLetter = Coordinate::stringFromColumnIndex($paidColIndex);
    $paidAmountVal = $sheet->getCell($paidColLetter . $row)->getCalculatedValue();

    // تاريخ البداية (اختياري)
    $startDateCell = null;
    if ($hasStartDateCol) {
        $startDateCell = getCellValueByHeader($sheet, $columnsMap, $startHeader, $row);
    }

    $trainerName = '';
    if ($hasTrainerCol) {
        $trainerName = trim((string)getCellValueByHeader($sheet, $columnsMap, $trainerHeader, $row));
    }

    $trainerAmountValue = null;
    if ($hasTrainerAmountCol) {
        $trainerAmountCell = getCellValueByHeader($sheet, $columnsMap, $trainerAmountHeader, $row);
        $trainerAmountValue = ($trainerAmountCell === '' || $trainerAmountCell === null) ? null : (float)$trainerAmountCell;
    }

    // إذا كان الصف فارغ تقريباً نتجاوزه
    if ($name === '' && $phoneRaw === null && $subscriptionName === '') {
        continue;
    }

    // تحويل رقم الهاتف لنص
    $phone = normalizePhoneForStorage($phoneRaw);

    // تحقق أساسي من البيانات
    if (
        $name === ''
        || $phone === ''
        || ($ageRaw !== '' && (!is_numeric($ageRaw) || $age <= 0))
        || !in_array($gender, ['ذكر','أنثى'], true)
    ) {
        $errors[] = "سطر $row: بيانات أساسية غير صحيحة.";
        continue;
    }

    if ($subscriptionName === '' || !isset($subscriptionsMap[$subscriptionName])) {
        $errors[] = "سطر $row: اسم الاشتراك غير موجود في النظام.";
        continue;
    }

    $trainerId = null;
    if ($trainerName !== '' && $trainerName !== 'بدون مدرب') {
        if (!isset($trainersMap[$trainerName])) {
            $errors[] = "سطر $row: اسم المدرب غير موجود في صفحة المدربين.";
            continue;
        }
        $trainerId = (int)$trainersMap[$trainerName]['id'];
    }

    // منع تكرار الباركود إن وُجد
    if ($barcode !== '') {
        // تحقق من الباركود المكرر في الملف نفسه
        if (isset($existingBarcodes[$barcode])) {
            $errors[] = "سطر $row: لا يمكن استيراد مشترك بباركود مكرر ({$barcode}) موجود بالفعل في النظام.";
            continue;
        }
    }

    $sub = $subscriptionsMap[$subscriptionName];

    $days     = (int)$sub['days'];
    $sessions = (int)$sub['sessions'];
    $invites  = (int)$sub['invites'];
    $amount   = (float)$sub['price_after_discount'];

    $paidAmount = (float)$paidAmountVal;
    if ($paidAmount < 0 || $paidAmount > $amount) {
        $errors[] = "سطر $row: مبلغ المدفوع يجب أن يكون بين 0 وقيمة الاشتراك.";
        continue;
    }

    if ($trainerId !== null && $trainerAmountValue !== null) {
        $expectedTrainerAmount = calculateTrainerCommissionAmount(
            $paidAmount,
            (float)$trainersMap[$trainerName]['commission_percentage']
        );
        $expectedTrainerAmountCents = (int)round($expectedTrainerAmount * 100);
        $trainerAmountValueCents = (int)round(((float)$trainerAmountValue) * 100);

        if (abs($expectedTrainerAmountCents - $trainerAmountValueCents) > TRAINER_COMMISSION_ROUNDING_TOLERANCE_CENTS) {
            $errors[] = "سطر $row: مبلغ المدرب لا يطابق النسبة المحددة للمدرب. المتوقع: "
                . number_format($expectedTrainerAmount, 2)
                . "، والمدخل: "
                . number_format((float)$trainerAmountValue, 2);
            continue;
        }
    }

    $remaining         = $amount - $paidAmount;
    $sessionsRemaining = $sessions;

    // معالجة تاريخ البداية بشكل صحيح (يدعم أرقام تواريخ إكسل أو نص)
    if ($startDateCell !== null && $startDateCell !== '') {
        if (is_numeric($startDateCell)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject($startDateCell);
                $startDate = $dt->format('Y-m-d');
            } catch (Exception $e) {
                $startDate = normalizeFlexibleDateInput($startDateCell);
            }
        } else {
            $startDate = normalizeFlexibleDateInput($startDateCell);
        }

        if ($startDate === null) {
            $errors[] = "سطر $row: تاريخ بداية الاشتراك غير صحيح.";
            continue;
        }
    } else {
        $startDate = date('Y-m-d');
    }

    $endDate = date('Y-m-d', strtotime($startDate . ' + ' . $days . ' days'));
    $status  = 'مستمر';

    $barcodeLockAcquired = false;
    try {
        $pdo->beginTransaction();
        if ($barcode !== '') {
            acquireMemberBarcodeLock($pdo);
            $barcodeLockAcquired = true;

            if (memberBarcodeExists($pdo, $barcode)) {
                throw new RuntimeException("سطر $row: لا يمكن استيراد مشترك بباركود مكرر ({$barcode}) موجود بالفعل في النظام.");
            }
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO members
            (name, phone, barcode, age, gender, address, subscription_id, trainer_id,
             days, sessions, sessions_remaining, invites,
             subscription_amount, initial_paid_amount, paid_amount, remaining_amount,
             start_date, end_date, status)
            VALUES
            (:n,:ph,:bc,:a,:g,:ad,:sid,:trainer_id,
             :d,:s,:sr,:i,
             :amt,:initial_paid,:paid,:rem,
             :sd,:ed,:st)
        ");

        $stmtIns->execute([
            ':n'   => $name,
            ':ph'  => $phone,
            ':bc'  => $barcode,
            ':a'   => $age,
            ':g'   => $gender,
            ':ad'  => $address,
            ':sid' => $sub['id'],
            ':trainer_id' => $trainerId,
            ':d'   => $days,
            ':s'   => $sessions,
            ':sr'  => $sessionsRemaining,
            ':i'   => $invites,
            ':amt' => $amount,
            ':initial_paid' => $paidAmount,
            ':paid'=> $paidAmount,
            ':rem' => $remaining,
            ':sd'  => $startDate,
            ':ed'  => $endDate,
            ':st'  => $status,
        ]);

        $newMemberId = (int)$pdo->lastInsertId();
        addTrainerCommission(
            $pdo,
            $trainerId,
            $newMemberId,
            'new_subscription',
            $newMemberId,
            $paidAmount
        );
        $pdo->commit();

        // في حالة نجاح الإدخال، نسجل الباركود في القائمة لمنع تكراره داخل نفس الملف
        if ($barcode !== '') {
            $existingBarcodes[$barcode] = true;
        }

        $importedCount++;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof RuntimeException) {
            $errors[] = $e->getMessage();
        } else {
            $errors[] = "سطر $row: حدث خطأ أثناء إضافة المشترك.";
        }
        continue;
    } finally {
        if ($barcodeLockAcquired) {
            releaseMemberBarcodeLock($pdo);
        }
    }
}

// حفظ النتائج في السيشن لإظهارها في members.php
if ($importedCount > 0) {
    $_SESSION['import_success'] = "تم استيراد $importedCount مشترك بنجاح.";
}
if (!empty($errors)) {
    $_SESSION['import_error'] = implode(" / ", $errors);
}

header('Location: members.php');
exit;
