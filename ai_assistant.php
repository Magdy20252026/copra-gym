<?php
session_start();

if (!defined('AI_ASSISTANT_DEFAULT_SITE_NAME')) {
    define('AI_ASSISTANT_DEFAULT_SITE_NAME', 'نظام الجيم');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'message' => 'يجب تسجيل الدخول أولاً.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'مدير') {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'message' => 'هذه الخدمة متاحة لحساب المدير فقط.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai_assistant_helpers.php';

$rawInput = file_get_contents('php://input');
$payload = json_decode((string)$rawInput, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$question = trim((string)($payload['question'] ?? ''));
$siteName = AI_ASSISTANT_DEFAULT_SITE_NAME;

try {
    $stmt = $pdo->prepare("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $siteName = (string)($row['site_name'] ?? $siteName);
    }
} catch (Throwable $e) {
}

$reply = aiAssistantBuildReply($pdo, $question, $siteName);

echo json_encode([
    'ok' => true,
    'answer' => (string)($reply['answer'] ?? ''),
    'suggested_questions' => $reply['suggested_questions'] ?? aiAssistantDefaultSuggestedQuestions(),
], JSON_UNESCAPED_UNICODE);
