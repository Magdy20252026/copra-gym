<?php
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
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

ensureTrainersSchema($pdo);

$role      = $_SESSION['role'] ?? '';
$isManager = ($role === 'مدير');
if (!$isManager) {
    die('غير مسموح.');
}

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('نموذج المشتركين');

$headers = [
    'A1' => 'اسم المشترك',
    'B1' => 'رقم التليفون',
    'C1' => 'الباركود',
    'D1' => 'السن',
    'E1' => 'النوع',
    'F1' => 'العنوان',
    'G1' => 'اسم الاشتراك',
    'H1' => 'المدفوع',
    'I1' => 'المدرب',
    'J1' => 'مبلغ المدرب',
    'K1' => 'تاريخ بداية الاشتراك (اختياري: YYYY-MM-DD أو يوم/شهر/سنة مثل 1/2/2026)',
];

foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}

// صف مثال توضيحي
$sheet->setCellValue('A2', 'أحمد علي');
$sheet->setCellValueExplicit('B2', '01001234567', DataType::TYPE_STRING);
$sheet->setCellValueExplicit('C2', 'BRC0001', DataType::TYPE_STRING);
$sheet->setCellValue('D2', 25);
$sheet->setCellValue('E2', 'ذكر');
$sheet->setCellValue('F2', 'القاهرة');
$sheet->setCellValue('G2', 'شهر'); // يجب أن يطابق اسم اشتراك في جدول subscriptions
$sheet->setCellValue('H2', 0);
$sheet->setCellValue('I2', 'بدون مدرب');
$sheet->setCellValue('J2', 0);
$sheet->setCellValueExplicit('K2', '1/2/2026', DataType::TYPE_STRING);

$sheet->getStyle('B:B')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
$sheet->getStyle('K:K')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

// جعل الأعمدة مناسبة
foreach (range('A', 'K') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// تجهيز التحميل
$filename = 'members_template_' . date('Y-m-d_H-i-s') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=utf-8');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
