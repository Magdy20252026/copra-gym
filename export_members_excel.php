<?php
// عرض الأخطاء أثناء التطوير (يمكنك إزالته لاحقاً)
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
require_once 'members_payment_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

ensureTrainersSchema($pdo);

ensureMembersPaymentTypeSchema($pdo);

// السماح فقط للمدير
$role      = $_SESSION['role'] ?? '';
$isManager = ($role === 'مدير');
if (!$isManager) {
    die('غير مسموح.');
}

// جلب البيانات من قاعدة البيانات
try {
    $stmt = $pdo->query("
        SELECT
            m.id,
            m.name,
            m.phone,
            m.barcode,
            m.age,
            m.gender,
            m.address,
            s.name AS subscription_name,
            t.name AS trainer_name,
            m.days,
            m.sessions,
            m.sessions_remaining,
            m.invites,
            m.freeze_days,
            m.subscription_amount,
            m.paid_amount,
            m.payment_type,
            m.remaining_amount,
            m.spa_count,
            m.massage_count,
            m.jacuzzi_count,
            m.start_date,
            m.end_date,
            m.status,
            COALESCE(tc.initial_trainer_amount, 0) AS trainer_amount,
            u.username AS created_by_username
        FROM members m
        JOIN subscriptions s ON s.id = m.subscription_id
        LEFT JOIN trainers t ON t.id = m.trainer_id
        LEFT JOIN users u ON u.id = m.created_by_user_id
        LEFT JOIN (
            SELECT member_id, SUM(commission_amount) AS initial_trainer_amount
            FROM trainer_commissions
            WHERE source_type = 'new_subscription'
            GROUP BY member_id
        ) tc ON tc.member_id = m.id
        ORDER BY m.id DESC
    ");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die('خطأ في جلب البيانات: ' . $e->getMessage());
}

// إنشاء ملف Excel
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('المشتركين');

// رؤوس الأعمدة بالعربية
$headers = [
    'A1' => 'رقم المشترك',
    'B1' => 'اسم المشترك',
    'C1' => 'رقم التليفون',
    'D1' => 'الباركود',
    'E1' => 'السن',
    'F1' => 'النوع',
    'G1' => 'العنوان',
    'H1' => 'اسم الاشتراك',
    'I1' => 'المدرب',
    'J1' => 'مبلغ المدرب',
    'K1' => 'عدد الأيام',
    'L1' => 'عدد مرات التمرين',
    'M1' => 'التمرينات المتبقية',
    'N1' => 'عدد الدعوات',
    'O1' => 'مبلغ الاشتراك',
    'P1' => 'المدفوع',
    'Q1' => 'نوع الدفع',
    'R1' => 'المتبقي',
    'S1' => 'تاريخ البداية',
    'T1' => 'تاريخ النهاية',
    'U1' => 'حالة الاشتراك',
    'V1' => 'أيام الـ Freeze',
    'W1' => 'سبا',
    'X1' => 'مساج',
    'Y1' => 'جاكوزي',
    'Z1' => 'أضيف بواسطة',
];

foreach ($headers as $cell => $text) {
    $sheet->setCellValue($cell, $text);
}

// تعبئة البيانات
$rowNum = 2;
foreach ($members as $m) {
    $sheet->setCellValue('A' . $rowNum, $m['id']);
    $sheet->setCellValue('B' . $rowNum, $m['name']);

    // رقم التليفون كنص للحفاظ على الصفر على اليسار
    $sheet->setCellValueExplicit('C' . $rowNum, $m['phone'], DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('D' . $rowNum, (string)$m['barcode'], DataType::TYPE_STRING);

    $sheet->setCellValue('E' . $rowNum, $m['age']);
    $sheet->setCellValue('F' . $rowNum, $m['gender']);
    $sheet->setCellValue('G' . $rowNum, $m['address']);
    $sheet->setCellValue('H' . $rowNum, $m['subscription_name']);
    $sheet->setCellValue('I' . $rowNum, $m['trainer_name'] ?: 'بدون مدرب');
    $sheet->setCellValue('J' . $rowNum, $m['trainer_amount']);
    $sheet->setCellValue('K' . $rowNum, $m['days']);
    $sheet->setCellValue('L' . $rowNum, $m['sessions']);
    $sheet->setCellValue('M' . $rowNum, $m['sessions_remaining']);
    $sheet->setCellValue('N' . $rowNum, $m['invites']);
    $sheet->setCellValue('O' . $rowNum, $m['subscription_amount']);
    $sheet->setCellValue('P' . $rowNum, $m['paid_amount']);
    $sheet->setCellValue('Q' . $rowNum, $m['payment_type'] ?: 'كاش');
    $sheet->setCellValue('R' . $rowNum, $m['remaining_amount']);
    $sheet->setCellValue('S' . $rowNum, $m['start_date']);
    $sheet->setCellValue('T' . $rowNum, $m['end_date']);
    $sheet->setCellValue('U' . $rowNum, $m['status']);
    $sheet->setCellValue('V' . $rowNum, $m['freeze_days']);
    $sheet->setCellValue('W' . $rowNum, $m['spa_count']);
    $sheet->setCellValue('X' . $rowNum, $m['massage_count']);
    $sheet->setCellValue('Y' . $rowNum, $m['jacuzzi_count']);
    $sheet->setCellValue('Z' . $rowNum, $m['created_by_username'] ?: '—');

    $rowNum++;
}

// جعل الأعمدة مناسبة
foreach (range('A', 'Z') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$filename = 'members_' . date('Y-m-d_H-i-s') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=utf-8');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
