<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'employees_helpers.php';
require_once 'user_permissions_helpers.php';

ensureEmployeesSchema($pdo);
ensureEmployeeAttendanceSchema($pdo);
ensureUserPermissionsSchema($pdo);

$date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])
    ? (string)$_GET['date']
    : date('Y-m-d');

$role = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$defaultPermissions = getDefaultUserPermissions();
$pagePermissions = $defaultPermissions;
$canViewPage = ($role === 'مدير');
$canExportAttendanceExcel = ($role === 'مدير');

if (!$canViewPage && $role === 'مشرف' && $userId > 0) {
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            foreach ($pagePermissions as $key => $value) {
                if (isset($rowPerm[$key])) {
                    $pagePermissions[$key] = (int)$rowPerm[$key];
                }
            }
            $canViewPage = (int)$pagePermissions['can_view_employee_attendance'] === 1;
            $canExportAttendanceExcel = $canViewPage
                && ((int)($pagePermissions['can_view_employee_attendance_report'] ?? 0) === 1)
                && ((int)($pagePermissions['can_export_employee_attendance_excel'] ?? 0) === 1);
        } else {
            $canViewPage = (int)$defaultPermissions['can_view_employee_attendance'] === 1;
            $canExportAttendanceExcel = $canViewPage
                && ((int)($defaultPermissions['can_view_employee_attendance_report'] ?? 0) === 1)
                && ((int)($defaultPermissions['can_export_employee_attendance_excel'] ?? 0) === 1);
        }
    } catch (Exception $e) {
        $canViewPage = false;
        $canExportAttendanceExcel = false;
    }
}

if (!$canViewPage || !$canExportAttendanceExcel) {
    http_response_code(403);
    echo 'غير مسموح بالدخول إلى هذه الصفحة.';
    exit;
}

function xmlEscapeEmployeeReport($str)
{
    return htmlspecialchars($str ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function formatEmployeeReportMoment($dateTime)
{
    if (!$dateTime) {
        return '';
    }

    $timestamp = strtotime((string)$dateTime);
    if ($timestamp === false) {
        return (string)$dateTime;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

try {
    $rows = getEmployeeAttendanceReportRows($pdo, $date);
} catch (Exception $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "حدث خطأ أثناء تجهيز كشف حضور الموظفين.\n";
    echo $e->getMessage();
    exit;
}

$headers = [
    'رقم الموظف',
    'الباركود',
    'اسم الموظف',
    'رقم الهاتف',
    'الوظيفة',
    'اليوم',
    'أيام الإجازة',
    'ميعاد الحضور',
    'وقت الحضور الفعلي',
    'حالة الحضور',
    'ميعاد الانصراف',
    'وقت الانصراف الفعلي',
    'حالة الانصراف',
    'حالة اليوم',
];

$sheetRowsXml = '<row r="1">';
foreach ($headers as $index => $text) {
    $column = chr(ord('A') + $index);
    $sheetRowsXml .= '<c r="' . $column . '1" t="inlineStr"><is><t>' . xmlEscapeEmployeeReport($text) . '</t></is></c>';
}
$sheetRowsXml .= '</row>';

$rowNumber = 2;
foreach ($rows as $row) {
    $dataCols = [
        (string)$row['employee_id'],
        (string)$row['barcode'],
        (string)$row['name'],
        (string)$row['phone'],
        (string)$row['job_title'],
        (string)$row['day_name'],
        formatEmployeeOffDays($row['off_days'] ?? ''),
        formatEmployeeTime($row['scheduled_attendance_display']),
        formatEmployeeReportMoment($row['attendance_at']),
        (string)($row['attendance_status'] ?: '—'),
        formatEmployeeTime($row['scheduled_departure_display']),
        formatEmployeeReportMoment($row['departure_at']),
        (string)($row['departure_status'] ?: '—'),
        (string)$row['day_status'],
    ];

    $sheetRowsXml .= '<row r="' . $rowNumber . '">';
    foreach ($dataCols as $index => $cellValue) {
        $column = chr(ord('A') + $index);
        $sheetRowsXml .= '<c r="' . $column . $rowNumber . '" t="inlineStr"><is><t>' . xmlEscapeEmployeeReport($cellValue) . '</t></is></c>';
    }
    $sheetRowsXml .= '</row>';
    $rowNumber++;
}

$sheetXml = '<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
           xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheetData>
' . $sheetRowsXml . '
  </sheetData>
</worksheet>';

$contentTypesXml = '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
</Types>';

$relsXml = '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
                Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
                Target="xl/workbook.xml"/>
</Relationships>';

$workbookRelsXml = '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <Relationship Id="rId1"
                Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"
                Target="worksheets/sheet1.xml"/>
</Relationships>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="كشف حضور الموظفين" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>';

if (!class_exists('ZipArchive')) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'امتداد ZipArchive غير متوفر على الخادم، لا يمكن إنشاء ملف Excel.';
    exit;
}

$zip = new ZipArchive();
$filename = 'employee_attendance_' . $date . '.xlsx';
$tmpFile = tempnam(sys_get_temp_dir(), 'employee_att_xlsx_');

if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'لا يمكن إنشاء ملف Excel مؤقت.';
    exit;
}

$zip->addFromString('[Content_Types].xml', $contentTypesXml);
$zip->addFromString('_rels/.rels', $relsXml);
$zip->addFromString('xl/workbook.xml', $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));

readfile($tmpFile);
unlink($tmpFile);
exit;
