<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'trainers_helpers.php';
require_once 'user_permissions_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

ensureTrainersSchema($pdo);
ensureUserPermissionsSchema($pdo);

$role = $_SESSION['role'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$isManager = ($role === 'مدير');
$isSupervisor = ($role === 'مشرف');
$canViewPage = false;

if ($isManager) {
    $canViewPage = true;
} elseif ($isSupervisor && $userId > 0) {
    try {
        $stmtPerm = $pdo->prepare("SELECT * FROM user_permissions WHERE user_id = :uid LIMIT 1");
        $stmtPerm->execute([':uid' => $userId]);
        if ($rowPerm = $stmtPerm->fetch(PDO::FETCH_ASSOC)) {
            $canViewPage = isset($rowPerm['can_view_trainers']) && ((int)$rowPerm['can_view_trainers'] === 1);
        }
    } catch (Exception $e) {
        $canViewPage = false;
    }
}

if (!$canViewPage) {
    die('غير مسموح.');
}

function buildTrainerBalanceSheetTitle(string $trainerName, int $trainerId, array &$usedTitles): string
{
    $baseTitle = trim($trainerName);
    if ($baseTitle === '') {
        $baseTitle = 'مدرب ' . $trainerId;
    }

    $baseTitle = str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $baseTitle);
    $baseTitle = preg_replace('/\s+/', ' ', $baseTitle);
    $baseTitle = trim((string)$baseTitle);
    if ($baseTitle === '') {
        $baseTitle = 'مدرب ' . $trainerId;
    }

    // Excel sheet titles have a maximum length of 31 characters.
    $title = mb_substr($baseTitle, 0, 31);
    if ($title === '') {
        $title = 'مدرب ' . $trainerId;
    }

    $candidate = $title;
    $suffix = 2;
    while (isset($usedTitles[$candidate])) {
        $suffixText = ' ' . $suffix;
        $candidate = mb_substr($title, 0, max(1, 31 - mb_strlen($suffixText))) . $suffixText;
        $suffix++;
    }

    $usedTitles[$candidate] = true;
    return $candidate;
}

$requestedTrainerId = (int)($_GET['trainer_id'] ?? 0);
$trainersToExport = [];

if ($requestedTrainerId > 0) {
    $trainer = getTrainerById($pdo, $requestedTrainerId);
    if (!$trainer) {
        die('المدرب المطلوب غير موجود.');
    }
    $trainersToExport[] = $trainer;
} else {
    $trainersToExport = getAllTrainers($pdo);
}

if (!$trainersToExport) {
    die('لا يوجد مدربين متاحين للتصدير.');
}

$headers = [
    'التاريخ',
    'نوع الحركة',
    'البيان',
    'اسم المشترك',
    'كود المشترك',
    'نوع الاشتراك',
    'المبلغ المدفوع',
    'تاريخ بداية الاشتراك',
    'تاريخ نهاية الاشتراك',
    'قيمة الحركة',
];

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('The Club Gym')
    ->setTitle('تفاصيل حركات أرصدة المدربين')
    ->setSubject('تفاصيل الحركات المسجلة على الرصيد');

$usedSheetTitles = [];
$sheetIndex = 0;

foreach ($trainersToExport as $trainer) {
    $sheet = $sheetIndex === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
    $sheet->setTitle(buildTrainerBalanceSheetTitle((string)($trainer['name'] ?? ''), (int)$trainer['id'], $usedSheetTitles));
    $sheet->setRightToLeft(true);
    $sheet->freezePane('A2');

    foreach ($headers as $index => $header) {
        $cell = Coordinate::stringFromColumnIndex($index + 1) . '1';
        $sheet->setCellValue($cell, $header);
    }

    $movementRows = getTrainerBalanceMovementsDetailed($pdo, (int)$trainer['id'], null);
    $rowNumber = 2;

    if (!$movementRows) {
        $sheet->setCellValue('A2', 'لا توجد حركات مسجلة على رصيد هذا المدرب.');
        $sheet->mergeCells('A2:J2');
    } else {
        foreach ($movementRows as $movement) {
            $movementDisplay = formatTrainerMovementForDisplay($movement);

            $sheet->setCellValue('A' . $rowNumber, $movementDisplay['created_at']);
            $sheet->setCellValue('B' . $rowNumber, $movementDisplay['movement_type_label']);
            $sheet->setCellValue('C' . $rowNumber, $movementDisplay['statement']);
            $sheet->setCellValue('D' . $rowNumber, $movementDisplay['member_name']);
            $sheet->setCellValue('E' . $rowNumber, $movementDisplay['member_code']);
            $sheet->setCellValue('F' . $rowNumber, $movementDisplay['subscription_name']);
            $sheet->setCellValue('G' . $rowNumber, $movementDisplay['paid_amount'] !== null ? (float)$movementDisplay['paid_amount'] : '');
            $sheet->setCellValue('H' . $rowNumber, $movementDisplay['subscription_start_date']);
            $sheet->setCellValue('I' . $rowNumber, $movementDisplay['subscription_end_date']);
            $sheet->setCellValue('J' . $rowNumber, (float)$movementDisplay['commission_amount']);
            $rowNumber++;
        }

        $sheet->getStyle('G2:G' . ($rowNumber - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('J2:J' . ($rowNumber - 1))->getNumberFormat()->setFormatCode('#,##0.00');
    }

    $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle('A1:' . $lastColumn . '1')->getFont()->setBold(true);
    $sheet->getStyle('A1:' . $lastColumn . '1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A1:' . $lastColumn . max(2, $rowNumber - 1))->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    $sheet->getStyle('A1:' . $lastColumn . max(2, $rowNumber - 1))->getAlignment()->setWrapText(true);

    for ($columnIndex = 1; $columnIndex <= count($headers); $columnIndex++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
    }

    $sheetIndex++;
}

$spreadsheet->setActiveSheetIndex(0);

$filenameBase = $requestedTrainerId > 0 ? 'trainer_balance_' . $requestedTrainerId : 'trainers_balance';
$filename = $filenameBase . '_' . date('Y-m-d_H-i-s') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
