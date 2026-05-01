<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'يجب تسجيل الدخول أولاً.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once 'config.php';

$barcode = isset($_GET['barcode']) ? trim($_GET['barcode']) : '';

if ($barcode === '') {
    echo json_encode([
        'success' => false,
        'message' => 'لم يتم إدخال باركود.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM members WHERE barcode = :bc LIMIT 1");
    $stmt->execute([':bc' => $barcode]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        echo json_encode([
            'success' => false,
            'message' => 'لا يوجد مشترك بهذا الباركود.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $totalFreezeAllowed = (int)($member['freeze_days'] ?? 0);
    $freezeUsedTotal    = (int)($member['used_freeze_days'] ?? 0);
    $remaining          = max(0, $totalFreezeAllowed - $freezeUsedTotal);

    echo json_encode([
        'success'           => true,
        'name'              => $member['name'],
        'total_freeze'      => $totalFreezeAllowed,
        'used_freeze'       => $freezeUsedTotal,
        'remaining_freeze'  => $remaining,
        'status'            => $member['status'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ أثناء جلب البيانات.'
    ], JSON_UNESCAPED_UNICODE);
}