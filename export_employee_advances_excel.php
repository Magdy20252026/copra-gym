<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'employee_advances_helpers.php';
require_once 'user_permissions_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ensureEmployeeAdvancesSchema($pdo);
ensureUserPermissionsSchema($pdo);

$role   = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$defaultPermissions = getDefaultUserPermissions();
$canViewPage = false;

if ($role === 'مدير') {
    $canViewPage = true;
} elseif ($role === 'مشرف' && $userId > 0) {
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $canViewPage = isset($rowPerm['can_view_employee_advances'])
                ? ((int)$rowPerm['can_view_employee_advances'] === 1)
                : ((int)$defaultPermissions['can_view_employee_advances'] === 1);
        } else {
            $canViewPage = (int)$defaultPermissions['can_view_employee_advances'] === 1;
        }
    } catch (Exception $e) {
        $canViewPage = false;
    }
}

if (!$canViewPage) {
    http_response_code(403);
    exit('غير مسموح.');
}

$selectedMonth = normalizeEmployeeAdvanceMonth($_GET['month'] ?? date('Y-m'));
$monthRange = getEmployeeAdvanceMonthDateRange($selectedMonth);

try {
    $rows = getEmployeeAdvanceRows($pdo, $selectedMonth);
} catch (Exception $e) {
    exit('تعذر استخراج كشف سلف الموظفين.');
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('سلف الموظفين');
$sheet->setRightToLeft(true);

$headers = [
    'A1' => 'تاريخ السلفة',
    'B1' => 'باركود الموظف',
    'C1' => 'اسم الموظف',
    'D1' => 'الوظيفة',
    'E1' => 'المبلغ',
    'F1' => 'الملاحظات',
    'G1' => 'تمت بواسطة',
    'H1' => 'وقت التسجيل',
    'I1' => 'شهر الكشف',
];

foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}

$rowNumber = 2;
foreach ($rows as $row) {
    $sheet->setCellValue('A' . $rowNumber, $row['advance_date']);
    $sheet->setCellValueExplicit('B' . $rowNumber, (string)$row['barcode'], DataType::TYPE_STRING);
    $sheet->setCellValue('C' . $rowNumber, $row['name']);
    $sheet->setCellValue('D' . $rowNumber, $row['job_title']);
    $sheet->setCellValue('E' . $rowNumber, (float)$row['amount']);
    $sheet->setCellValue('F' . $rowNumber, $row['notes'] ?: '');
    $sheet->setCellValue('G' . $rowNumber, $row['created_by_username'] ?: '');
    $sheet->setCellValue('H' . $rowNumber, $row['created_at']);
    $sheet->setCellValue('I' . $rowNumber, $selectedMonth);
    $rowNumber++;
}

if ($rowNumber === 2) {
    $sheet->setCellValue('A2', 'لا توجد بيانات');
    $sheet->setCellValue('I2', $selectedMonth);
}

foreach (range('A', 'I') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$filename = 'employee_advances_' . $selectedMonth . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=utf-8');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('X-Employee-Advances-Month-Start: ' . $monthRange['start']);
header('X-Employee-Advances-Month-End: ' . $monthRange['end']);

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
