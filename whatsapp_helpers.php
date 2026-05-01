<?php

const WHATSAPP_AUTOMATION_DEFAULT_DELAY_SECONDS = 8;
const WHATSAPP_AUTOMATION_MIN_REQUEST_SECONDS = 60;
const WHATSAPP_AUTOMATION_SECONDS_PER_JOB = 5;
const WHATSAPP_AUTOMATION_DELAY_BUFFER_SECONDS = 4;
const WHATSAPP_AUTOMATION_FINAL_BUFFER_SECONDS = 30;
const WHATSAPP_AUTOMATION_INITIAL_APP_WAIT_MS = 2500;
const WHATSAPP_AUTOMATION_MIN_OPEN_CHAT_MS = 6500;
const WHATSAPP_AUTOMATION_PRE_SEND_WAIT_MS = 1600;
const WHATSAPP_AUTOMATION_SEND_RETRY_WAIT_MS = 1600;
const WHATSAPP_AUTOMATION_RETRY_ACTIVATION_WAIT_MS = 1100;
const WHATSAPP_AUTOMATION_RETRY_ACTIVATION_ATTEMPTS = 6;
const WHATSAPP_AUTOMATION_SEND_ATTEMPTS = 1;
const WHATSAPP_AUTOMATION_POST_SEND_WAIT_MS = 1800;
const WHATSAPP_AUTOMATION_BETWEEN_MESSAGES_WAIT_MS = 1800;
const WHATSAPP_AUTOMATION_REACTIVATION_FAILURE_MESSAGE = 'تم فتح المحادثة لكن تعذر إعادة تنشيط نافذة WhatsApp Desktop لإكمال الإرسال التلقائي. زِد مدة الانتظار أو تأكد أن نافذة واتساب ظاهرة في المقدمة.';

function normalizeWhatsAppPhone(string $phone, string $countryCode): ?string
{
    $digits = preg_replace('/\D+/', '', $phone);
    $countryCodeDigits = preg_replace('/\D+/', '', $countryCode);

    if ($digits === '') {
        return null;
    }

    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }

    if ($countryCodeDigits !== '' && strpos($digits, '0') === 0) {
        $digits = $countryCodeDigits . ltrim($digits, '0');
    }

    if (strlen($digits) < 8 || strlen($digits) > 16) {
        return null;
    }

    return $digits;
}

function buildDebtWhatsAppMessage(string $memberName, string $siteName, float $remainingAmount): string
{
    return 'مساء الخير كابتن ' . trim($memberName)
        . ' برجاء التوجه الي رسيبشن ' . trim($siteName)
        . ' لدفع المبلغ المتبقي من الاشتراك ' . number_format($remainingAmount, 2);
}

function prepareWhatsAppJobs(array $rows, callable $messageBuilder, string $countryCode): array
{
    $jobs = [];
    $invalidRows = [];
    $sentPhones = [];

    foreach ($rows as $row) {
        $normalizedPhone = normalizeWhatsAppPhone((string)($row['phone'] ?? ''), $countryCode);
        if ($normalizedPhone === null) {
            $invalidRows[] = $row;
            continue;
        }

        if (isset($sentPhones[$normalizedPhone])) {
            continue;
        }

        $message = trim((string)$messageBuilder($row));
        if ($message === '') {
            continue;
        }

        $sentPhones[$normalizedPhone] = true;
        $jobs[] = [
            'phone' => $normalizedPhone,
            'message' => $message,
            'member_name' => (string)($row['name'] ?? ''),
        ];
    }

    return [
        'jobs' => $jobs,
        'invalid_rows' => $invalidRows,
    ];
}

function buildWhatsAppDeepLink(string $phone, string $message): string
{
    return 'https://wa.me/' . rawurlencode($phone) . '?text=' . rawurlencode($message);
}

function buildWhatsAppDesktopLink(string $phone, string $message): string
{
    return 'whatsapp://send?phone=' . rawurlencode($phone) . '&text=' . rawurlencode($message);
}

function buildWhatsAppLaunchQueue(array $jobs): array
{
    $queue = [];

    foreach ($jobs as $job) {
        $phone = trim((string)($job['phone'] ?? ''));
        $message = trim((string)($job['message'] ?? ''));

        if ($phone === '' || $message === '') {
            continue;
        }

        $queue[] = [
            'phone' => $phone,
            'message' => $message,
            'member_name' => (string)($job['member_name'] ?? ''),
            'desktop_link' => buildWhatsAppDesktopLink($phone, $message),
            'web_link' => buildWhatsAppDeepLink($phone, $message),
        ];
    }

    return $queue;
}

function getWhatsAppDesktopWindowTitles(): array
{
    return ['WhatsApp', 'واتساب', 'WhatsApp Beta'];
}

function getWhatsAppClientPlatformContext(array $server = [], array $input = []): array
{
    if ($server === []) {
        $server = $_SERVER;
    }

    $platformCandidates = [];
    foreach ([
        (string)($input['client_platform'] ?? ''),
        (string)($server['HTTP_X_CLIENT_PLATFORM'] ?? ''),
        (string)($server['HTTP_SEC_CH_UA_PLATFORM'] ?? ''),
    ] as $candidate) {
        $candidate = trim($candidate, " \t\n\r\0\x0B\"'");
        if ($candidate !== '') {
            $platformCandidates[] = $candidate;
        }
    }

    $normalizedPlatform = '';
    foreach ($platformCandidates as $candidate) {
        if (strcasecmp($candidate, 'Windows') === 0 || stripos($candidate, 'Win') === 0) {
            $normalizedPlatform = 'Windows';
            break;
        }
        if ($normalizedPlatform === '') {
            $normalizedPlatform = $candidate;
        }
    }

    $userAgent = trim((string)($server['HTTP_USER_AGENT'] ?? ''));
    $isWindows = strcasecmp($normalizedPlatform, 'Windows') === 0;
    if (!$isWindows && $userAgent !== '' && stripos($userAgent, 'Windows NT') !== false) {
        $normalizedPlatform = 'Windows';
        $isWindows = true;
    }

    return [
        'platform' => $normalizedPlatform,
        'is_windows' => $isWindows,
        'user_agent' => $userAgent,
    ];
}

function getWhatsAppPowerShellCommand(): ?string
{
    static $cachedCommand = false;

    if ($cachedCommand !== false) {
        return $cachedCommand ?: null;
    }

    if (PHP_OS_FAMILY !== 'Windows' || !function_exists('proc_open')) {
        $cachedCommand = '';
        return null;
    }

    $commands = ['powershell.exe', 'powershell', 'pwsh.exe', 'pwsh'];
    foreach ($commands as $command) {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            $command . ' -NoLogo -NoProfile -NonInteractive -Command "$PSVersionTable.PSVersion.ToString()"',
            $descriptorSpec,
            $pipes
        );

        if (!is_resource($process)) {
            continue;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode === 0 && trim((string)$stdout) !== '') {
            $cachedCommand = $command;
            return $cachedCommand;
        }
    }

    $cachedCommand = '';

    return null;
}

function getWhatsAppDesktopAutomationAvailability(array $server = [], array $input = []): array
{
    $clientPlatformContext = getWhatsAppClientPlatformContext($server, $input);

    if (!empty($clientPlatformContext['is_windows'])) {
        return [
            'ok' => true,
            'driver' => 'browser',
            'message' => 'بعد الضغط على زر الإرسال سيتم فتح كل محادثة داخل WhatsApp Desktop مع كتابة الرسالة تلقائياً، ثم تضغط أنت على إرسال داخل واتساب وبعدها تعود للصفحة لفتح الرقم التالي.',
        ];
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        return [
            'ok' => false,
            'message' => 'الإرسال التلقائي الكامل داخل WhatsApp Desktop يحتاج تشغيل PHP على نفس جهاز Windows المثبت عليه واتساب مع توفر Windows PowerShell أو COM.',
        ];
    }

    $powerShellCommand = getWhatsAppPowerShellCommand();
    if ($powerShellCommand !== null) {
        return [
            'ok' => true,
            'driver' => 'powershell',
            'command' => $powerShellCommand,
            'message' => 'يمكن تشغيل WhatsApp Desktop مباشرة من نفس جهاز Windows الحالي عبر Windows PowerShell.',
        ];
    }

    if (class_exists('COM')) {
        return [
            'ok' => true,
            'driver' => 'com',
            'message' => 'يمكن تشغيل WhatsApp Desktop مباشرة من نفس جهاز Windows الحالي.',
        ];
    }

    return [
        'ok' => false,
        'message' => 'امتداد COM/DOTNET غير مفعّل في PHP على هذا الجهاز ولا يتوفر Windows PowerShell المناسب، لذلك لا يمكن إرسال الرسائل تلقائياً داخل WhatsApp Desktop.',
    ];
}

function pauseWhatsAppDesktopAutomation(int $milliseconds): void
{
    if ($milliseconds > 0) {
        usleep($milliseconds * 1000);
    }
}

function isSequentialWhatsAppArray(array $items): bool
{
    return array_keys($items) === range(0, count($items) - 1);
}

function normalizeWhatsAppAutomationResults($results): array
{
    if (!is_array($results)) {
        return [];
    }

    if ($results !== [] && !isSequentialWhatsAppArray($results)) {
        $looksLikeSingleResult = isset($results['phone'])
            || isset($results['member_name'])
            || isset($results['message'])
            || isset($results['status'])
            || isset($results['error']);
        $results = $looksLikeSingleResult ? [$results] : [];
    }

    return array_values(array_filter($results, function ($resultItem): bool {
        return is_array($resultItem)
            && (
                isset($resultItem['phone'])
                || isset($resultItem['member_name'])
                || isset($resultItem['message'])
                || isset($resultItem['status'])
                || isset($resultItem['error'])
            );
    }));
}

function logWhatsAppAutomationWarning(string $message): void
{
    if (function_exists('error_log')) {
        @error_log('[WhatsApp Desktop] ' . $message);
    }
}

/**
 * @param COM $shell WScript.Shell COM instance created from PHP
 */
function activateWhatsAppDesktopWindow($shell, int $waitMs = 900, int $attempts = 6): bool
{
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        foreach (getWhatsAppDesktopWindowTitles() as $title) {
            try {
                if ($shell->AppActivate($title)) {
                    pauseWhatsAppDesktopAutomation($waitMs);
                    return true;
                }
            } catch (Throwable $e) {
                logWhatsAppAutomationWarning(
                    'تعذر تنشيط نافذة WhatsApp Desktop (المحاولة '
                    . ($attempt + 1)
                    . ', العنوان: '
                    . $title
                    . '): '
                    . $e->getMessage()
                );
            }
        }
        pauseWhatsAppDesktopAutomation($waitMs);
    }

    return false;
}

/**
 * @param COM $shell WScript.Shell COM instance created from PHP
 */
function sendWhatsAppDesktopEnterKey($shell): void
{
    try {
        $shell->SendKeys('{ENTER}');
    } catch (Throwable $e) {
        throw new RuntimeException('تعذر إرسال أمر Enter إلى نافذة WhatsApp Desktop: ' . $e->getMessage(), 0, $e);
    }
}

/**
 * @param COM $shell WScript.Shell COM instance created from PHP
 */
function sendWhatsAppDesktopMessage($shell): void
{
    if (!activateWhatsAppDesktopWindow($shell)) {
        throw new RuntimeException('تعذر تنشيط نافذة WhatsApp Desktop على الجهاز الحالي. تأكد من أن البرنامج مفتوح ومرئي على الشاشة.');
    }

    pauseWhatsAppDesktopAutomation(WHATSAPP_AUTOMATION_PRE_SEND_WAIT_MS);

    for ($attempt = 1; $attempt <= WHATSAPP_AUTOMATION_SEND_ATTEMPTS; $attempt++) {
        sendWhatsAppDesktopEnterKey($shell);

        if ($attempt < WHATSAPP_AUTOMATION_SEND_ATTEMPTS) {
            pauseWhatsAppDesktopAutomation(WHATSAPP_AUTOMATION_SEND_RETRY_WAIT_MS);

            if (!activateWhatsAppDesktopWindow(
                $shell,
                WHATSAPP_AUTOMATION_RETRY_ACTIVATION_WAIT_MS,
                WHATSAPP_AUTOMATION_RETRY_ACTIVATION_ATTEMPTS
            )) {
                throw new RuntimeException(WHATSAPP_AUTOMATION_REACTIVATION_FAILURE_MESSAGE);
            }
        }
    }

    pauseWhatsAppDesktopAutomation(WHATSAPP_AUTOMATION_POST_SEND_WAIT_MS);
}

function runWhatsAppPowerShellAutomation(array $jobs, int $delaySeconds, string $powerShellCommand): array
{
    $payloadPlaceholder = '__WHATSAPP_PAYLOAD_BASE64_3F1A7C0E9D2B__';

    if (!function_exists('proc_open')) {
        return [
            'ok' => false,
            'message' => 'تعذر تشغيل Windows PowerShell من PHP لأن الدالة proc_open غير متاحة على هذا الخادم.',
            'sent_count' => 0,
            'failed_count' => count($jobs),
            'results' => [],
        ];
    }

    $payload = [
        'jobs' => array_values(array_map(function (array $job): array {
            $phone = trim((string)($job['phone'] ?? ''));
            $message = trim((string)($job['message'] ?? ''));

            return [
                'phone' => $phone,
                'member_name' => (string)($job['member_name'] ?? ''),
                'message' => $message,
                'desktop_link' => $phone !== '' && $message !== ''
                    ? buildWhatsAppDesktopLink($phone, $message)
                    : '',
            ];
        }, $jobs)),
        'messages' => [
            'reactivationFailure' => WHATSAPP_AUTOMATION_REACTIVATION_FAILURE_MESSAGE,
        ],
        'windowTitles' => getWhatsAppDesktopWindowTitles(),
        'timings' => [
            'initialAppWaitMs' => WHATSAPP_AUTOMATION_INITIAL_APP_WAIT_MS,
            'openChatWaitMs' => max(WHATSAPP_AUTOMATION_MIN_OPEN_CHAT_MS, $delaySeconds * 1000),
            'activationWaitMs' => 900,
            'activationAttempts' => 6,
            'preSendWaitMs' => WHATSAPP_AUTOMATION_PRE_SEND_WAIT_MS,
            'sendRetryWaitMs' => WHATSAPP_AUTOMATION_SEND_RETRY_WAIT_MS,
            'retryActivationWaitMs' => WHATSAPP_AUTOMATION_RETRY_ACTIVATION_WAIT_MS,
            'retryActivationAttempts' => WHATSAPP_AUTOMATION_RETRY_ACTIVATION_ATTEMPTS,
            'sendAttempts' => WHATSAPP_AUTOMATION_SEND_ATTEMPTS,
            'postSendWaitMs' => WHATSAPP_AUTOMATION_POST_SEND_WAIT_MS,
            'betweenMessagesWaitMs' => WHATSAPP_AUTOMATION_BETWEEN_MESSAGES_WAIT_MS,
        ],
    ];

    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        return [
            'ok' => false,
            'message' => 'تعذر تجهيز بيانات الرسائل قبل تشغيل Windows PowerShell: ' . json_last_error_msg(),
            'sent_count' => 0,
            'failed_count' => count($jobs),
            'results' => [],
        ];
    }

    $payloadEncoded = base64_encode($payloadJson);
    $command = $powerShellCommand
        . ' -NoLogo -NoProfile -NonInteractive -Command -';
    $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$payloadEncoded = '__WHATSAPP_PAYLOAD_BASE64_3F1A7C0E9D2B__'
if ([string]::IsNullOrWhiteSpace($payloadEncoded)) {
    throw 'لم يتم استلام بيانات التشغيل الخاصة برسائل واتساب.'
}

$payloadJson = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String($payloadEncoded))
$payload = $payloadJson | ConvertFrom-Json -Depth 8
$timings = $payload.timings
$messages = $payload.messages
$windowTitles = @($payload.windowTitles)
$reactivationFailureMessage = [string]$messages.reactivationFailure
$shell = New-Object -ComObject WScript.Shell
$results = New-Object System.Collections.ArrayList
$sentCount = 0
$failedCount = 0

function Pause-WhatsApp([int]$milliseconds) {
    if ($milliseconds -gt 0) {
        Start-Sleep -Milliseconds $milliseconds
    }
}

function Get-WhatsAppProcessIds() {
    try {
        return @(Get-Process -Name 'WhatsApp*' -ErrorAction SilentlyContinue | Sort-Object StartTime -Descending | Select-Object -ExpandProperty Id)
    } catch {
        return @()
    }
}

function Activate-WhatsApp($shellInstance, [int]$waitMs, [int]$attempts, [array]$titleCandidates) {
    for ($attempt = 0; $attempt -lt $attempts; $attempt++) {
        foreach ($processId in @(Get-WhatsAppProcessIds)) {
            try {
                if ($shellInstance.AppActivate([int]$processId)) {
                    Pause-WhatsApp $waitMs
                    return $true
                }
            } catch {
            }
        }
        foreach ($title in $titleCandidates) {
            try {
                if (-not [string]::IsNullOrWhiteSpace([string]$title) -and $shellInstance.AppActivate([string]$title)) {
                    Pause-WhatsApp $waitMs
                    return $true
                }
            } catch {
            }
        }
        Pause-WhatsApp $waitMs
    }

    return $false
}

function Send-WhatsAppMessage($shellInstance, $timingValues, [array]$titleCandidates) {
    if (-not (Activate-WhatsApp $shellInstance ([int]$timingValues.activationWaitMs) ([int]$timingValues.activationAttempts) $titleCandidates)) {
        throw 'تعذر تنشيط نافذة WhatsApp Desktop على الجهاز الحالي. تأكد من أن البرنامج مفتوح ومرئي على الشاشة.'
    }

    Pause-WhatsApp ([int]$timingValues.preSendWaitMs)
    for ($sendAttempt = 1; $sendAttempt -le ([int]$timingValues.sendAttempts); $sendAttempt++) {
        $shellInstance.SendKeys('{ENTER}')

        if ($sendAttempt -lt ([int]$timingValues.sendAttempts)) {
            Pause-WhatsApp ([int]$timingValues.sendRetryWaitMs)

            if (-not (Activate-WhatsApp $shellInstance ([int]$timingValues.retryActivationWaitMs) ([int]$timingValues.retryActivationAttempts) $titleCandidates)) {
                throw $reactivationFailureMessage
            }
        }
    }

    Pause-WhatsApp ([int]$timingValues.postSendWaitMs)
}

try {
    try {
        Start-Process -FilePath 'whatsapp://' | Out-Null
    } catch {
    }

    Pause-WhatsApp ([int]$timings.initialAppWaitMs)

    foreach ($job in @($payload.jobs)) {
        $phone = [string]$job.phone
        $message = [string]$job.message
        $memberName = [string]$job.member_name
        $desktopLink = [string]$job.desktop_link

        if ([string]::IsNullOrWhiteSpace($phone) -or [string]::IsNullOrWhiteSpace($message) -or [string]::IsNullOrWhiteSpace($desktopLink)) {
            $failedCount++
            [void]$results.Add([ordered]@{
                phone = $phone
                member_name = $memberName
                message = $message
                status = 'failed'
                error = 'الرقم أو نص الرسالة غير مكتمل.'
            })
            continue
        }

        try {
            Start-Process -FilePath $desktopLink | Out-Null
            Pause-WhatsApp ([int]$timings.openChatWaitMs)
            Send-WhatsAppMessage $shell $timings $windowTitles

            $sentCount++
            [void]$results.Add([ordered]@{
                phone = $phone
                member_name = $memberName
                message = $message
                status = 'sent'
            })
        } catch {
            $failedCount++
            [void]$results.Add([ordered]@{
                phone = $phone
                member_name = $memberName
                message = $message
                status = 'failed'
                error = $_.Exception.Message
            })
        }

        Pause-WhatsApp ([int]$timings.betweenMessagesWaitMs)
    }

    if ($sentCount -gt 0 -and $failedCount -eq 0) {
        $messageText = 'تم فتح WhatsApp Desktop وإرسال ' + $sentCount + ' رسالة بالتتابع تلقائياً من نفس ضغطة الزر.'
    } elseif ($sentCount -gt 0) {
        $messageText = 'تم إرسال ' + $sentCount + ' رسالة، وتعذر إرسال ' + $failedCount + ' رسالة. راجع النتائج بالأسفل.'
    } else {
        $messageText = 'تعذر إكمال الإرسال التلقائي على هذا الجهاز. راجع النتائج بالأسفل.'
    }

    [ordered]@{
        ok = ($sentCount -gt 0)
        message = $messageText
        sent_count = $sentCount
        failed_count = $failedCount
        results = $results
    } | ConvertTo-Json -Depth 8 -Compress
} catch {
    [ordered]@{
        ok = $false
        message = $_.Exception.Message
        sent_count = 0
        failed_count = @($payload.jobs).Count
        results = @()
    } | ConvertTo-Json -Depth 8 -Compress
}
POWERSHELL;
    $script = str_replace($payloadPlaceholder, $payloadEncoded, $script);
    if (strpos($script, $payloadPlaceholder) !== false) {
        return [
            'ok' => false,
            'message' => 'تعذر تجهيز نص تشغيل Windows PowerShell قبل إرسال رسائل واتساب.',
            'sent_count' => 0,
            'failed_count' => count($jobs),
            'results' => [],
        ];
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($command, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return [
            'ok' => false,
            'message' => 'تعذر تشغيل Windows PowerShell من PHP على هذا الجهاز.',
            'sent_count' => 0,
            'failed_count' => count($jobs),
            'results' => [],
        ];
    }

    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $decoded = json_decode(trim((string)$stdout), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'ok' => false,
            'message' => 'تعذر قراءة نتيجة Windows PowerShell الخاصة بإرسال واتساب.'
                . ' JSON error: ' . json_last_error_msg()
                . ($stderr !== '' ? ' | PowerShell output: ' . trim($stderr) : ''),
            'sent_count' => 0,
            'failed_count' => count($jobs),
            'results' => [],
        ];
    }

    $decoded['sent_count'] = (int)($decoded['sent_count'] ?? 0);
    $decoded['failed_count'] = (int)($decoded['failed_count'] ?? 0);
    $decoded['results'] = normalizeWhatsAppAutomationResults($decoded['results'] ?? []);
    $decoded['message'] = (string)($decoded['message'] ?? 'تمت محاولة الإرسال عبر Windows PowerShell.');
    $decoded['ok'] = !empty($decoded['ok']);

    if ($exitCode !== 0 && !$decoded['ok'] && $stderr !== '') {
        $decoded['message'] .= ' ' . trim($stderr);
    }

    return $decoded;
}

function runWhatsAppDesktopAutomation(array $jobs, int $delaySeconds): array
{
    $availability = getWhatsAppDesktopAutomationAvailability();
    if (!$availability['ok']) {
        return $availability + [
            'sent_count' => 0,
            'failed_count' => 0,
            'results' => [],
        ];
    }

    if (!$jobs) {
        return [
            'ok' => false,
            'message' => 'لا توجد رسائل صالحة للإرسال.',
            'sent_count' => 0,
            'failed_count' => 0,
            'results' => [],
        ];
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(max(
            WHATSAPP_AUTOMATION_MIN_REQUEST_SECONDS,
            (count($jobs) * max(WHATSAPP_AUTOMATION_SECONDS_PER_JOB, $delaySeconds + WHATSAPP_AUTOMATION_DELAY_BUFFER_SECONDS))
                + WHATSAPP_AUTOMATION_FINAL_BUFFER_SECONDS
        ));
    }

    if (($availability['driver'] ?? '') === 'powershell') {
        return runWhatsAppPowerShellAutomation(
            $jobs,
            $delaySeconds,
            (string)($availability['command'] ?? 'powershell.exe')
        );
    }

    try {
        $shell = new COM('WScript.Shell');
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'تعذر تشغيل WScript.Shell من PHP على هذا الجهاز: ' . $e->getMessage(),
            'sent_count' => 0,
            'failed_count' => count($jobs),
            'results' => [],
        ];
    }

    $results = [];
    $sentCount = 0;
    $failedCount = 0;
    $openChatMs = max(WHATSAPP_AUTOMATION_MIN_OPEN_CHAT_MS, $delaySeconds * 1000);

    try {
        $shell->Run('whatsapp://', 1, false);
    } catch (Throwable $e) {
        // WhatsApp Desktop may already be open, so keep going and let the per-chat launch decide the final result.
        logWhatsAppAutomationWarning('تعذر تشغيل واتساب ديسكتوب مبدئياً (قد يكون مفتوحاً بالفعل): ' . $e->getMessage());
    }
    pauseWhatsAppDesktopAutomation(WHATSAPP_AUTOMATION_INITIAL_APP_WAIT_MS);

    foreach ($jobs as $job) {
        $phone = trim((string)($job['phone'] ?? ''));
        $message = trim((string)($job['message'] ?? ''));
        $memberName = (string)($job['member_name'] ?? '');

        if ($phone === '' || $message === '') {
            $failedCount++;
            $results[] = [
                'phone' => $phone,
                'member_name' => $memberName,
                'message' => $message,
                'status' => 'failed',
                'error' => 'الرقم أو نص الرسالة غير مكتمل.',
            ];
            continue;
        }

        try {
            $shell->Run(buildWhatsAppDesktopLink($phone, $message), 1, false);
            pauseWhatsAppDesktopAutomation($openChatMs);
            sendWhatsAppDesktopMessage($shell);

            $sentCount++;
            $results[] = [
                'phone' => $phone,
                'member_name' => $memberName,
                'message' => $message,
                'status' => 'sent',
            ];
        } catch (Throwable $e) {
            $failedCount++;
            $results[] = [
                'phone' => $phone,
                'member_name' => $memberName,
                'message' => $message,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
        }

        pauseWhatsAppDesktopAutomation(WHATSAPP_AUTOMATION_BETWEEN_MESSAGES_WAIT_MS);
    }

    if ($sentCount > 0 && $failedCount === 0) {
        $message = 'تم فتح WhatsApp Desktop وإرسال ' . $sentCount . ' رسالة بالتتابع تلقائياً من نفس ضغطة الزر.';
    } elseif ($sentCount > 0) {
        $message = 'تم إرسال ' . $sentCount . ' رسالة، وتعذر إرسال ' . $failedCount . ' رسالة. راجع النتائج بالأسفل.';
    } else {
        $message = 'تعذر إكمال الإرسال التلقائي على هذا الجهاز. راجع النتائج بالأسفل.';
    }

    return [
        'ok' => $sentCount > 0,
        'message' => $message,
        'sent_count' => $sentCount,
        'failed_count' => $failedCount,
        'results' => $results,
    ];
}
