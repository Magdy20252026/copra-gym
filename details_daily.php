<?php
session_start();
require_once 'config.php';
require_once 'sales_helpers.php';

ensureSalesSchema($pdo);

// حماية بسيطة للطلب (يجب أن يكون لديك صلاحية ومسجل دخول)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$type = $_GET['type'] ?? '';
$start = $_GET['start'] ?? '';
$end   = $_GET['end'] ?? '';
$export = isset($_GET['export']);

header('Content-Type: '.(!$export ? 'application/json; charset=utf-8' : 'application/vnd.ms-excel; charset=utf-8'));

switch ($type) {
    case 'newsubs':
        $stmt = $pdo->prepare("SELECT name, phone, initial_paid_amount AS 'المدفوع', created_at AS 'تاريخ التسجيل' FROM members WHERE created_at > :start AND created_at <= :end");
        $stmt->execute([':start'=>$start, ':end'=>$end]);
        $headings = ['اسم المشترك','رقم الهاتف','المدفوع','تاريخ التسجيل'];
        break;
    case 'partials':
        $stmt = $pdo->prepare("SELECT m.name AS 'اسم المشترك', m.phone AS 'رقم الهاتف', pp.paid_amount AS 'المدفوع', pp.paid_at AS 'تاريخ السداد' FROM partial_payments pp LEFT JOIN members m ON m.id=pp.member_id WHERE pp.paid_at > :start AND pp.paid_at <= :end");
        $stmt->execute([':start'=>$start, ':end'=>$end]);
        $headings = ['اسم المشترك','رقم الهاتف','المدفوع','تاريخ السداد'];
        break;
    case 'renewals':
        $stmt = $pdo->prepare("
            SELECT
                m.name AS 'اسم المشترك',
                m.phone AS 'رقم الهاتف',
                CASE
                    WHEN rl.paid_now > 0 THEN rl.paid_now
                    WHEN rl.paid_amount > 0 THEN rl.paid_amount
                    ELSE rl.new_subscription_amount
                END AS 'المدفوع',
                rl.renewed_at AS 'تاريخ التجديد'
            FROM renewals_log rl
            LEFT JOIN members m ON m.id=rl.member_id
            WHERE rl.renewed_at > :start AND rl.renewed_at <= :end
        ");
        $stmt->execute([':start'=>$start, ':end'=>$end]);
        $headings = ['اسم المشترك','رقم الهاتف','المدفوع','تاريخ التجديد'];
        break;
    case 'singles':
        $stmt = $pdo->prepare("SELECT name AS 'الاسم', phone AS 'الهاتف', single_paid AS 'المدفوع', created_at AS 'التاريخ' FROM attendance WHERE type='حصة_واحدة' AND created_at > :start AND created_at <= :end");
        $stmt->execute([':start'=>$start, ':end'=>$end]);
        $headings = ['الاسم','الهاتف','المدفوع','التاريخ'];
        break;
    case 'sales':
        $stmt = $pdo->prepare("SELECT transaction_type AS 'نوع العملية', cashier_name AS 'الكاشير', item_name AS 'الصنف', quantity AS 'العدد', unit_price AS 'سعر الوحدة', total_amount AS 'الإجمالي', created_at AS 'وقت العملية' FROM sales WHERE created_at > :start AND created_at <= :end ORDER BY created_at DESC, id DESC");
        $stmt->execute([':start'=>$start, ':end'=>$end]);
        $headings = ['نوع العملية','الكاشير','الصنف','العدد','سعر الوحدة','الإجمالي','وقت العملية'];
        break;
    case 'expenses':
        $stmt = $pdo->prepare("SELECT item AS 'البند', amount AS 'القيمة', expense_date AS 'تاريخ المصروف', created_at AS 'وقت الإدخال' FROM expenses WHERE created_at > :start AND created_at <= :end");
        $stmt->execute([':start'=>$start, ':end'=>$end]);
        $headings = ['البند','القيمة','تاريخ المصروف','وقت الإدخال'];
        break;
    default:
        http_response_code(400);
        echo json_encode(['error'=>'Invalid type']);
        exit;
}

$rows = $stmt->fetchAll($export ? PDO::FETCH_NUM : PDO::FETCH_ASSOC);

if ($export) {
    $filename = 'تفاصيل_'. $type .'_'.date('Ymd_His').'.xls';
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo "\xEF\xBB\xBF";
    echo implode("\t", $headings)."\n";
    foreach($rows as $row){ echo implode("\t", $row)."\n"; }
    exit;
}
echo json_encode(['headings'=>$headings, 'rows'=>$rows], JSON_UNESCAPED_UNICODE);
