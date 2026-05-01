<?php
session_start();

// منع الدخول بدون تسجيل
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';

// التحقق من التاريخ المطلوب (افتراض اليوم إن لم يُرسل)
$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
    ? $_GET['date']
    : date('Y-m-d');

// جلب بيانات الحضور + بيانات المشترك من جدول members
$rows = [];
try {
    $sql = "
        SELECT
            a.id                  AS attendance_id,
            a.type                AS attendance_type,
            a.name                AS att_name,
            COALESCE(NULLIF(TRIM(a.name), ''), m_by_id.name, m_by_barcode.name) AS display_name,
            a.phone               AS att_phone,
            a.barcode             AS att_barcode,
            a.notes               AS att_notes,
            a.created_at          AS att_created_at,
            a.member_id,

            COALESCE(m_by_id.name, m_by_barcode.name) AS m_name,
            COALESCE(m_by_id.phone, m_by_barcode.phone) AS m_phone,
            COALESCE(m_by_id.barcode, m_by_barcode.barcode) AS m_barcode,
            COALESCE(m_by_id.age, m_by_barcode.age) AS m_age,
            COALESCE(m_by_id.gender, m_by_barcode.gender) AS m_gender,
            COALESCE(m_by_id.address, m_by_barcode.address) AS m_address,
            COALESCE(m_by_id.days, m_by_barcode.days) AS m_days,
            COALESCE(m_by_id.sessions, m_by_barcode.sessions) AS m_sessions,
            COALESCE(m_by_id.sessions_remaining, m_by_barcode.sessions_remaining) AS m_sessions_remaining,
            COALESCE(m_by_id.invites, m_by_barcode.invites) AS m_invites,
            COALESCE(m_by_id.subscription_amount, m_by_barcode.subscription_amount) AS m_subscription_amount,
            COALESCE(m_by_id.paid_amount, m_by_barcode.paid_amount) AS m_paid_amount,
            COALESCE(m_by_id.remaining_amount, m_by_barcode.remaining_amount) AS m_remaining_amount,
            COALESCE(m_by_id.start_date, m_by_barcode.start_date) AS m_start_date,
            COALESCE(m_by_id.end_date, m_by_barcode.end_date) AS m_end_date,
            COALESCE(m_by_id.status, m_by_barcode.status) AS m_status,
            s.name                AS m_subscription_name
        FROM attendance a
        LEFT JOIN members m_by_id ON m_by_id.id = a.member_id
        LEFT JOIN members m_by_barcode
            ON m_by_id.id IS NULL
           AND a.barcode IS NOT NULL
           AND a.barcode <> ''
           AND m_by_barcode.barcode = a.barcode
        LEFT JOIN subscriptions s
            ON s.id = COALESCE(m_by_id.subscription_id, m_by_barcode.subscription_id)
        WHERE DATE(a.created_at) = :d
        ORDER BY a.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':d' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "حدث خطأ أثناء جلب بيانات الحضور.\n";
    echo $e->getMessage();
    exit;
}

// تحويل نوع الحضور إلى نص عربي
function typeToArabic($type)
{
    switch ($type) {
        case 'مشترك':      return 'مشترك';
        case 'مدعو':        return 'مدعو';
        case 'حصة_واحدة':   return 'مشترك تمرينة واحدة';
        default:            return $type;
    }
}

// دالة مساعدة للهروب داخل XML
function xmlEscape($str)
{
    return htmlspecialchars($str ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// بناء sheet1.xml
$sheetRowsXml = "";

// عناوين الأعمدة (كل بيانات المشترك + بيانات الحضور)
$headers = [
    'رقم السجل',            // A
    'نوع الحضور',           // B
    'الاسم (من الحضور)',    // C
    'رقم الهاتف (من الحضور)',// D
    'الباركود (من الحضور)', // E
    'ملاحظات الحضور',       // F
    'تاريخ ووقت الحضور',    // G

    'الاسم في المشتركين',   // H
    'الهاتف في المشتركين',  // I
    'الباركود في المشتركين',// J
    'السن',                 // K
    'النوع',                // L
    'العنوان',              // M
    'اسم الاشتراك',         // N
    'أيام الاشتراك',        // O
    'إجمالي التمرينات',     // P
    'التمرينات المتبقية',   // Q
    'عدد الدعوات',          // R
    'مبلغ الاشتراك الكلي',  // S
    'المبلغ المدفوع',       // T
    'المتبقي',              // U
    'تاريخ بداية الاشتراك', // V
    'تاريخ نهاية الاشتراك', // W
    'حالة الاشتراك',        // X
];

$sheetRowsXml .= '<row r="1">';
$colIndex = 0;
foreach ($headers as $text) {
    $colLetter = chr(ord('A') + $colIndex); // A,B,C,...
    $sheetRowsXml .= '<c r="' . $colLetter . '1" t="inlineStr"><is><t>' . xmlEscape($text) . '</t></is></c>';
    $colIndex++;
}
$sheetRowsXml .= '</row>';

// الصفوف التالية: بيانات الحضور + بيانات المشترك
$rowNum = 2;
foreach ($rows as $r) {
    $sheetRowsXml .= '<row r="' . $rowNum . '">';

    // اختيار مصدر البيانات:
    // لو member_id موجود نستخدم بيانات members؛ وإلا نكتفي ببيانات attendance
    $memberName   = $r['m_name']   ?? '';
    $memberPhone  = $r['m_phone']  ?? '';
    $memberBarcode= $r['m_barcode']?? '';

    $dataCols = [
        (string)$r['attendance_id'],           // رقم السجل
        typeToArabic($r['attendance_type']),   // نوع الحضور
        (string)$r['display_name'],            // الاسم الظاهر
        (string)$r['att_phone'],               // الهاتف من attendance
        (string)$r['att_barcode'],             // الباركود من attendance
        (string)$r['att_notes'],               // ملاحظات الحضور
        (string)$r['att_created_at'],          // وقت الحضور

        (string)$memberName,                   // الاسم في المشتركين
        (string)$memberPhone,                  // الهاتف في المشتركين
        (string)$memberBarcode,                // الباركود في المشتركين
        (string)$r['m_age'],                   // السن
        (string)$r['m_gender'],                // النوع
        (string)$r['m_address'],               // العنوان
        (string)$r['m_subscription_name'],     // اسم الاشتراك
        (string)$r['m_days'],                  // أيام الاشتراك
        (string)$r['m_sessions'],              // إجمالي التمرينات
        (string)$r['m_sessions_remaining'],    // التمرينات المتبقية
        (string)$r['m_invites'],               // الدعوات
        (string)$r['m_subscription_amount'],   // مبلغ الاشتراك الكلي
        (string)$r['m_paid_amount'],           // المدفوع
        (string)$r['m_remaining_amount'],      // المتبقي
        (string)$r['m_start_date'],            // بداية الاشتراك
        (string)$r['m_end_date'],              // نهاية الاشتراك
        (string)$r['m_status'],                // حالة الاشتراك
    ];

    $colIndex = 0;
    foreach ($dataCols as $cellVal) {
        $colLetter = chr(ord('A') + $colIndex);
        $sheetRowsXml .= '<c r="' . $colLetter . $rowNum . '" t="inlineStr"><is><t>' . xmlEscape($cellVal) . '</t></is></c>';
        $colIndex++;
    }

    $sheetRowsXml .= '</row>';
    $rowNum++;
}

$sheetXml = '<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
           xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheetData>
' . $sheetRowsXml . '
  </sheetData>
</worksheet>';

// [Content_Types].xml
$contentTypesXml = '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
</Types>';

// _rels/.rels
$relsXml = '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
                Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
                Target="xl/workbook.xml"/>
</Relationships>';

// xl/_rels/workbook.xml.rels
$workbookRelsXml = '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <Relationship Id="rId1"
                Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"
                Target="worksheets/sheet1.xml"/>
</Relationships>';

// xl/workbook.xml
$workbookXml = '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="كشف الحضور" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';

// التأكد من وجود ZipArchive
if (!class_exists('ZipArchive')) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "امتداد ZipArchive غير متوفر على الخادم، لا يمكن إنشاء ملف Excel.";
    exit;
}

// إنشاء ملف ZIP (XLSX) مؤقت
$zip = new ZipArchive();
$filename = "attendance_" . $date . ".xlsx";
$tmpFile = tempnam(sys_get_temp_dir(), 'att_xlsx_');

if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "لا يمكن إنشاء ملف ZIP مؤقت.";
    exit;
}

// إضافة الملفات للـ ZIP
$zip->addFromString('[Content_Types].xml', $contentTypesXml);
$zip->addFromString('_rels/.rels', $relsXml);
$zip->addFromString('xl/workbook.xml', $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

$zip->close();

// إرسال الملف للمتصفح
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));

readfile($tmpFile);
unlink($tmpFile);
exit;
