<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'employee_payroll_helpers.php';
require_once 'user_permissions_helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ensureEmployeePayrollSchema($pdo);
ensureUserPermissionsSchema($pdo);

$role = $_SESSION['role'] ?? '';
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
            $canViewPage = isset($rowPerm['can_view_employee_payroll'])
                ? ((int)$rowPerm['can_view_employee_payroll'] === 1)
                : ((int)($defaultPermissions['can_view_employee_payroll'] ?? 0) === 1);
        } else {
            $canViewPage = ((int)($defaultPermissions['can_view_employee_payroll'] ?? 0) === 1);
        }
    } catch (Exception $e) {
        $canViewPage = false;
    }
}

if (!$canViewPage) {
    http_response_code(403);
    exit('غير مسموح.');
}

$selectedMonth = normalizeEmployeePayrollMonth($_GET['month'] ?? date('Y-m'));
$monthRange = getEmployeePayrollMonthDateRange($selectedMonth);

try {
    $rows = getEmployeePayrollRows($pdo, $selectedMonth);
} catch (Exception $e) {
    exit('تعذر استخراج كشف رواتب الموظفين.');
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('رواتب الموظفين');
$sheet->setRightToLeft(true);

$headers = [
    'A1' => 'شهر الصرف',
    'B1' => 'باركود الموظف',
    'C1' => 'اسم الموظف',
    'D1' => 'الوظيفة',
    'E1' => 'المرتب المصروف',
    'F1' => 'الملاحظات',
    'G1' => 'تم الصرف بواسطة',
    'H1' => 'وقت الصرف',
    'I1' => 'بداية الشهر',
    'J1' => 'نهاية الشهر',
];

foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}

$rowNumber = 2;
foreach ($rows as $row) {
    $sheet->setCellValue('A' . $rowNumber, substr((string)$row['payment_month'], 0, 7));
    $sheet->setCellValueExplicit('B' . $rowNumber, (string)$row['barcode'], DataType::TYPE_STRING);
    $sheet->setCellValue('C' . $rowNumber, $row['name']);
    $sheet->setCellValue('D' . $rowNumber, $row['job_title']);
    $sheet->setCellValue('E' . $rowNumber, (float)$row['amount']);
    $sheet->setCellValue('F' . $rowNumber, $row['notes'] ?: '');
    $sheet->setCellValue('G' . $rowNumber, $row['paid_by_username'] ?: '');
    $sheet->setCellValue('H' . $rowNumber, $row['paid_at']);
    $sheet->setCellValue('I' . $rowNumber, $monthRange['start']);
    $sheet->setCellValue('J' . $rowNumber, $monthRange['end']);
    $rowNumber++;
}

if ($rowNumber === 2) {
    $sheet->setCellValue('A2', $selectedMonth);
    $sheet->setCellValue('B2', 'لا توجد بيانات');
    $sheet->setCellValue('I2', $monthRange['start']);
    $sheet->setCellValue('J2', $monthRange['end']);
}

foreach (range('A', 'J') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

$filename = 'employee_payroll_' . $selectedMonth . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=utf-8');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('X-Employee-Payroll-Month-Start: ' . $monthRange['start']);
header('X-Employee-Payroll-Month-End: ' . $monthRange['end']);

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
