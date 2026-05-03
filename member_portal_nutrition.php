<?php
session_start();

require_once 'config.php';
require_once 'member_portal_nutrition_helpers.php';

function memberPortalNutritionGetRequestData(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function memberPortalNutritionJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$request = memberPortalNutritionGetRequestData();
$action = trim((string)($request['action'] ?? ''));
$phone = trim((string)($request['phone'] ?? ''));
$branchSelection = resolvePublicBranchSelection($pdo, (int)($request['branch_id'] ?? 0));
$selectedBranchId = (int)$branchSelection['selected_branch_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    memberPortalNutritionJson(['ok' => false, 'message' => 'طريقة الطلب غير مدعومة.'], 405);
}

if ($selectedBranchId <= 0) {
    memberPortalNutritionJson(['ok' => false, 'message' => 'من فضلك اختر الفرع أولاً.'], 422);
}

if ($phone === '') {
    memberPortalNutritionJson(['ok' => false, 'message' => 'رقم الهاتف مطلوب.'], 422);
}

try {
    $stmt = $pdo->query("SELECT logo_path FROM site_settings ORDER BY id ASC LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $logoPath = $settings['logo_path'] ?? null;
} catch (Exception $e) {
    $logoPath = null;
}

try {
    $memberData = memberPortalFindMemberData($pdo, $phone, $logoPath);
} catch (Exception $e) {
    memberPortalNutritionJson(['ok' => false, 'message' => 'حدث خطأ أثناء جلب بيانات المشترك.'], 500);
}

if (!$memberData) {
    memberPortalNutritionJson(['ok' => false, 'message' => 'لا يوجد مشترك بهذا رقم الهاتف داخل الفرع المحدد.'], 404);
}

if ($action !== 'generate_plan') {
    memberPortalNutritionJson(['ok' => false, 'message' => 'الإجراء المطلوب غير معروف.'], 422);
}

$validation = memberPortalValidateNutritionInputs($request['age'] ?? '', $request['weight'] ?? '', $request['body_fat'] ?? '');
if (($validation['ok'] ?? false) !== true) {
    memberPortalNutritionJson(['ok' => false, 'message' => $validation['message'] ?? 'البيانات غير صحيحة.'], 422);
}

$plan = memberPortalBuildNutritionPlan(
    $memberData,
    (int)$validation['age'],
    (float)$validation['weight'],
    (float)$validation['body_fat']
);

if ($action === 'generate_plan') {
    memberPortalNutritionJson([
        'ok' => true,
        'coach_name' => 'كابتن MO',
        'member_name' => $plan['member_name'],
        'phone' => $memberData['phone'] ?? '',
        'subscription_name' => $memberData['subscription_name'] ?? '',
        'age' => $plan['age'],
        'weight' => number_format((float)$plan['weight'], 1, '.', ''),
        'body_fat' => number_format((float)$plan['body_fat'], 1, '.', ''),
        'goal_label' => $plan['goal_label'],
        'goal_note' => $plan['goal_note'],
        'daily_calories' => $plan['daily_calories'],
        'protein_grams' => $plan['protein_grams'],
        'carbs_grams' => $plan['carbs_grams'],
        'fat_grams' => $plan['fat_grams'],
        'water_liters' => number_format((float)$plan['water_liters'], 1, '.', ''),
        'meals' => $plan['meals'],
        'tips' => $plan['tips'],
        'summary_lines' => $plan['summary_lines'],
    ]);
}
