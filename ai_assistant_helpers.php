<?php

function aiAssistantDefaultSuggestedQuestions(): array
{
    return [
        'عدد الاشتراكات الجديدة اليوم؟',
        'أكثر الاشتراكات التي يشترك فيها المشتركين حتى الآن؟',
        'إجمالي الإيرادات خلال اليوم؟',
        'إجمالي الإيرادات خلال الأسبوع؟',
        'إجمالي الإيرادات خلال الشهر؟',
        'إجمالي المصروفات خلال اليوم؟',
        'إجمالي المبيعات خلال الشهر؟',
        'الموظف المثالي لهذا الشهر؟',
        'الموظف السيئ لهذا الشهر؟',
        'أسماء الموظفين الذين قبضوا مرتباتهم هذا الشهر؟',
        'أسماء الموظفين الذين لم يقبضوا مرتباتهم هذا الشهر؟',
        'ما هي سلف الموظفين هذا الشهر؟',
        'تفاصيل وعدد الأصناف الموجودة في الأصناف؟',
    ];
}

function aiAssistantNormalizeText(string $text): string
{
    $text = trim($text);
    $text = str_replace(
        ['أ', 'إ', 'آ', 'ٱ', 'ى', 'ؤ', 'ئ', 'ة', 'ـ', '؟', '?', '!', '،', ',', '.', '؛', ';', "\r", "\n", "\t"],
        ['ا', 'ا', 'ا', 'ا', 'ي', 'و', 'ي', 'ه', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' '],
        $text
    );
    $text = preg_replace('/\s+/u', ' ', $text);

    return trim((string)$text);
}

function aiAssistantContainsAny(string $text, array $needles): bool
{
    foreach ($needles as $needle) {
        $needle = aiAssistantNormalizeText((string)$needle);
        $position = aiAssistantStringPosition($text, $needle);
        if ($needle !== '' && $position !== false) {
            return true;
        }
    }

    return false;
}

function aiAssistantContainsAll(string $text, array $needles): bool
{
    foreach ($needles as $needle) {
        $needle = aiAssistantNormalizeText((string)$needle);
        $position = aiAssistantStringPosition($text, $needle);
        if ($needle === '' || $position === false) {
            return false;
        }
    }

    return true;
}

function aiAssistantFormatCurrency($amount): string
{
    return number_format((float)$amount, 2) . ' جنيه';
}

function aiAssistantStringPosition(string $haystack, string $needle)
{
    static $hasMbString = null;

    if ($hasMbString === null) {
        $hasMbString = function_exists('mb_strpos');
    }

    return $hasMbString ? mb_strpos($haystack, $needle) : strpos($haystack, $needle);
}

function aiAssistantGetDateRanges(): array
{
    $today = date('Y-m-d');

    return [
        'today' => [
            'start' => $today,
            'end' => $today,
            'label' => 'اليوم',
        ],
        'week' => [
            'start' => date('Y-m-d', strtotime('-6 days')),
            'end' => $today,
            'label' => 'الأسبوع',
        ],
        'month' => [
            'start' => date('Y-m-01'),
            'end' => $today,
            'label' => 'الشهر',
        ],
    ];
}

function aiAssistantDetectRangeKey(string $normalizedQuestion, string $default = 'today'): string
{
    if (aiAssistantContainsAny($normalizedQuestion, ['شهر', 'شهري', 'هذا الشهر'])) {
        return 'month';
    }

    if (aiAssistantContainsAny($normalizedQuestion, ['اسبوع', 'الأسبوع', 'الاسبوع', 'اسبوعي', 'خلال 7 ايام', 'خلال سبعه ايام'])) {
        return 'week';
    }

    return $default;
}

function aiAssistantQueryRow(PDO $pdo, string $sql, array $params, array $defaultRow): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : $defaultRow;
    } catch (Throwable $e) {
        return $defaultRow;
    }
}

function aiAssistantQueryAll(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function aiAssistantGetRangeStats(PDO $pdo, string $start, string $end): array
{
    $stats = [
        'new_subscriptions_count' => 0,
        'total_paid_for_new_subs' => 0.0,
        'partial_payments_count' => 0,
        'total_partial_payments' => 0.0,
        'renewals_count' => 0,
        'total_renewals_amount' => 0.0,
        'single_sessions_count' => 0,
        'total_single_sessions_amount' => 0.0,
        'sales_operations_count' => 0,
        'total_sales_amount' => 0.0,
        'regular_expenses' => 0.0,
        'employee_advances' => 0.0,
        'trainer_advances' => 0.0,
        'employee_salaries' => 0.0,
        'total_expenses' => 0.0,
    ];

    $row = aiAssistantQueryRow(
        $pdo,
        "SELECT COUNT(*) AS c, COALESCE(SUM(initial_paid_amount), 0) AS s
         FROM members
         WHERE DATE(created_at) BETWEEN :start AND :end",
        [':start' => $start, ':end' => $end],
        ['c' => 0, 's' => 0]
    );
    $stats['new_subscriptions_count'] = (int)($row['c'] ?? 0);
    $stats['total_paid_for_new_subs'] = (float)($row['s'] ?? 0);

    $row = aiAssistantQueryRow(
        $pdo,
        "SELECT COUNT(*) AS c, COALESCE(SUM(paid_amount), 0) AS s
         FROM partial_payments
         WHERE DATE(paid_at) BETWEEN :start AND :end",
        [':start' => $start, ':end' => $end],
        ['c' => 0, 's' => 0]
    );
    $stats['partial_payments_count'] = (int)($row['c'] ?? 0);
    $stats['total_partial_payments'] = (float)($row['s'] ?? 0);

    $row = aiAssistantQueryRow(
        $pdo,
        "SELECT
            COUNT(*) AS c,
            COALESCE(SUM(
                CASE
                    WHEN paid_now > 0 THEN paid_now
                    WHEN paid_amount > 0 THEN paid_amount
                    ELSE new_subscription_amount
                END
            ), 0) AS s
         FROM renewals_log
         WHERE DATE(renewed_at) BETWEEN :start AND :end",
        [':start' => $start, ':end' => $end],
        ['c' => 0, 's' => 0]
    );
    $stats['renewals_count'] = (int)($row['c'] ?? 0);
    $stats['total_renewals_amount'] = (float)($row['s'] ?? 0);

    $row = aiAssistantQueryRow(
        $pdo,
        "SELECT COUNT(*) AS c, COALESCE(SUM(single_paid), 0) AS s
         FROM attendance
         WHERE type = 'حصة_واحدة'
           AND DATE(created_at) BETWEEN :start AND :end",
        [':start' => $start, ':end' => $end],
        ['c' => 0, 's' => 0]
    );
    $stats['single_sessions_count'] = (int)($row['c'] ?? 0);
    $stats['total_single_sessions_amount'] = (float)($row['s'] ?? 0);

    $row = aiAssistantQueryRow(
        $pdo,
        "SELECT
            COUNT(DISTINCT invoice_number) AS operations_count,
            COALESCE(SUM(
                CASE
                    WHEN transaction_type = 'بيع' THEN total_amount
                    WHEN transaction_type = 'مرتجع' THEN -total_amount
                    ELSE 0
                END
            ), 0) AS net_sales_amount
         FROM sales
         WHERE sale_date BETWEEN :start AND :end",
        [':start' => $start, ':end' => $end],
        ['operations_count' => 0, 'net_sales_amount' => 0]
    );
    $stats['sales_operations_count'] = (int)($row['operations_count'] ?? 0);
    $stats['total_sales_amount'] = (float)($row['net_sales_amount'] ?? 0);

    $row = aiAssistantQueryRow(
        $pdo,
        "SELECT COALESCE(SUM(amount), 0) AS total_amount
         FROM expenses
         WHERE expense_date BETWEEN :start AND :end",
        [':start' => $start, ':end' => $end],
        ['total_amount' => 0]
    );
    $stats['regular_expenses'] = (float)($row['total_amount'] ?? 0);

    $row = aiAssistantQueryRow(
        $pdo,
        "SELECT COALESCE(SUM(amount), 0) AS total_amount
         FROM employee_advances
         WHERE advance_date BETWEEN :start AND :end",
        [':start' => $start, ':end' => $end],
        ['total_amount' => 0]
    );
    $stats['employee_advances'] = (float)($row['total_amount'] ?? 0);

    $row = aiAssistantQueryRow(
        $pdo,
        "SELECT COALESCE(SUM(base_amount), 0) AS total_amount
         FROM trainer_commissions
         WHERE source_type = 'advance_withdrawal'
           AND DATE(created_at) BETWEEN :start AND :end",
        [':start' => $start, ':end' => $end],
        ['total_amount' => 0]
    );
    $stats['trainer_advances'] = (float)($row['total_amount'] ?? 0);

    $row = aiAssistantQueryRow(
        $pdo,
        "SELECT COALESCE(SUM(amount), 0) AS total_amount
         FROM employee_payroll
         WHERE DATE(paid_at) BETWEEN :start AND :end",
        [':start' => $start, ':end' => $end],
        ['total_amount' => 0]
    );
    $stats['employee_salaries'] = (float)($row['total_amount'] ?? 0);

    $stats['total_expenses'] = $stats['regular_expenses']
        + $stats['employee_advances']
        + $stats['trainer_advances']
        + $stats['employee_salaries'];

    return $stats;
}

function aiAssistantGetRevenueTotal(array $stats): float
{
    return (float)$stats['total_paid_for_new_subs']
        + (float)$stats['total_partial_payments']
        + (float)$stats['total_renewals_amount']
        + (float)$stats['total_single_sessions_amount']
        + (float)$stats['total_sales_amount'];
}

function aiAssistantGetTopSubscription(PDO $pdo): ?array
{
    $row = aiAssistantQueryRow(
        $pdo,
        "SELECT
            s.name,
            COUNT(m.id) AS members_count
         FROM subscriptions s
         INNER JOIN members m ON m.subscription_id = s.id
         GROUP BY s.id, s.name
         ORDER BY members_count DESC, s.name ASC
         LIMIT 1",
        [],
        []
    );

    if (!$row || empty($row['name'])) {
        return null;
    }

    return [
        'name' => (string)$row['name'],
        'members_count' => (int)($row['members_count'] ?? 0),
    ];
}

function aiAssistantGetItemsSummary(PDO $pdo): array
{
    $summary = aiAssistantQueryRow(
        $pdo,
        "SELECT
            COUNT(*) AS items_count,
            COALESCE(SUM(CASE WHEN has_quantity = 1 THEN item_count ELSE 0 END), 0) AS quantity_units
         FROM items",
        [],
        ['items_count' => 0, 'quantity_units' => 0]
    );

    $rows = aiAssistantQueryAll(
        $pdo,
        "SELECT name, has_quantity, item_count, price
         FROM items
         ORDER BY name ASC"
    );

    return [
        'items_count' => (int)($summary['items_count'] ?? 0),
        'quantity_units' => (int)($summary['quantity_units'] ?? 0),
        'rows' => $rows,
    ];
}

function aiAssistantGetEmployeePerformance(PDO $pdo, string $start, string $end, string $mode = 'best'): ?array
{
    $baseSql = "
        SELECT
            e.name,
            COUNT(ea.id) AS recorded_days,
            COALESCE(SUM(CASE WHEN ea.attendance_status = 'في الموعد' AND COALESCE(ea.departure_status, '') = 'في الموعد' THEN 1 ELSE 0 END), 0) AS perfect_days,
            COALESCE(SUM(CASE WHEN ea.attendance_status = 'متأخر' THEN 1 ELSE 0 END), 0) AS late_count,
            COALESCE(SUM(CASE WHEN COALESCE(ea.departure_status, '') = 'انصراف مبكر' THEN 1 ELSE 0 END), 0) AS early_departure_count,
            COALESCE(SUM(CASE WHEN ea.attendance_status = 'متأخر' OR COALESCE(ea.departure_status, '') = 'انصراف مبكر' THEN 1 ELSE 0 END), 0) AS issue_count
         FROM employees e
         LEFT JOIN employee_attendance ea
           ON ea.employee_id = e.id
          AND ea.attendance_date BETWEEN :start AND :end
         GROUP BY e.id, e.name";

    if ($mode === 'worst') {
        $sql = $baseSql . "
         ORDER BY issue_count DESC, late_count DESC, early_departure_count DESC, perfect_days ASC, e.name ASC
         LIMIT 1";
    } else {
        $sql = $baseSql . "
         ORDER BY perfect_days DESC, recorded_days DESC, issue_count ASC, e.name ASC
         LIMIT 1";
    }

    $rows = aiAssistantQueryAll(
        $pdo,
        $sql,
        [':start' => $start, ':end' => $end]
    );

    if (!$rows) {
        return null;
    }

    $row = $rows[0];
    if ($mode === 'worst' && (int)($row['issue_count'] ?? 0) === 0) {
        return null;
    }

    if ($mode !== 'worst' && (int)($row['recorded_days'] ?? 0) === 0) {
        return null;
    }

    return [
        'name' => (string)($row['name'] ?? ''),
        'recorded_days' => (int)($row['recorded_days'] ?? 0),
        'perfect_days' => (int)($row['perfect_days'] ?? 0),
        'late_count' => (int)($row['late_count'] ?? 0),
        'early_departure_count' => (int)($row['early_departure_count'] ?? 0),
        'issue_count' => (int)($row['issue_count'] ?? 0),
    ];
}

function aiAssistantGetPayrollLists(PDO $pdo, string $monthStart): array
{
    $paidRows = aiAssistantQueryAll(
        $pdo,
        "SELECT e.name
         FROM employee_payroll ep
         INNER JOIN employees e ON e.id = ep.employee_id
         WHERE ep.payment_month = :payment_month
         ORDER BY e.name ASC",
        [':payment_month' => $monthStart]
    );

    $unpaidRows = aiAssistantQueryAll(
        $pdo,
        "SELECT e.name
         FROM employees e
         LEFT JOIN employee_payroll ep
           ON ep.employee_id = e.id
          AND ep.payment_month = :payment_month
         WHERE ep.id IS NULL
         ORDER BY e.name ASC",
        [':payment_month' => $monthStart]
    );

    return [
        'paid' => array_values(array_filter(array_map(static function ($row) {
            return trim((string)($row['name'] ?? ''));
        }, $paidRows))),
        'unpaid' => array_values(array_filter(array_map(static function ($row) {
            return trim((string)($row['name'] ?? ''));
        }, $unpaidRows))),
    ];
}

function aiAssistantGetEmployeeAdvances(PDO $pdo, string $start, string $end): array
{
    $rows = aiAssistantQueryAll(
        $pdo,
        "SELECT e.name, ea.amount, ea.advance_date
         FROM employee_advances ea
         INNER JOIN employees e ON e.id = ea.employee_id
         WHERE ea.advance_date BETWEEN :start AND :end
         ORDER BY ea.advance_date DESC, e.name ASC",
        [':start' => $start, ':end' => $end]
    );

    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float)($row['amount'] ?? 0);
    }

    return [
        'rows' => $rows,
        'count' => count($rows),
        'total' => $total,
    ];
}

function aiAssistantGetSystemGuidance(string $normalizedQuestion): ?string
{
    $guides = [
        [
            'keywords' => ['اشتراك', 'اشتراكات', 'باقة', 'باقات'],
            'answer' => "إدارة الاشتراكات تتم من صفحة الاشتراكات، ومن خلالها يمكنك إضافة الباقات وتعديل عدد الأيام والجلسات والسعر والخصم. ولو سؤالك عن تسجيل مشترك جديد أو تجديده فالمكان المناسب هو صفحات المشتركين وتجديد الاشتراكات.",
        ],
        [
            'keywords' => ['مشترك', 'مشتركين', 'تجديد', 'فريز', 'تجميد', 'freeze'],
            'answer' => "إدارة المشتركين تتم من صفحات المشتركين وتجديد الاشتراكات وFreeze. من هناك تقدر تسجل مشترك جديد، تتابع المدفوع والمتبقي، وتنفذ التجديد أو الإيقاف المؤقت للاشتراك.",
        ],
        [
            'keywords' => ['حضور', 'انصراف', 'بصمه', 'باركود', 'تمرينه واحده', 'حصة واحده'],
            'answer' => "الحضور في النظام ينقسم إلى حضور المشتركين وحضور الموظفين. حضور المشتركين من صفحة الحضور، وحضور الموظفين من صفحة حضور الموظفين مع متابعة التأخير والانصراف المبكر.",
        ],
        [
            'keywords' => ['مبيعات', 'فاتوره', 'فاتورة', 'كاشير', 'صنف', 'اصناف'],
            'answer' => "المبيعات مرتبطة بصفحات الأصناف والمبيعات والكاشير. تضيف الأصناف أولاً، ثم تسجل الفواتير من صفحة المبيعات، ويمكن متابعة العمليات حسب الكاشير ورقم الفاتورة.",
        ],
        [
            'keywords' => ['موظف', 'موظفين', 'مرتب', 'راتب', 'سلف'],
            'answer' => "إدارة الموظفين داخل النظام تشمل صفحات الموظفين، حضور الموظفين، سلف الموظفين، وقبض الموظفين. من خلالها تقدر تتابع الالتزام الشهري، السلف، والمرتبات المصروفة وغير المصروفة.",
        ],
        [
            'keywords' => ['مصروف', 'مصروفات'],
            'answer' => "المصروفات تُسجل من صفحة المصروفات، والنظام يجمع معها سلف الموظفين وسلف المدربين والمرتبات ضمن إجمالي المصروفات في الإحصائيات والتقفيل.",
        ],
        [
            'keywords' => ['اعدادات', 'الاعدادات', 'اسم الجيم', 'شعار', 'لوجو'],
            'answer' => "اسم الجيم والشعار وإعدادات الإيصال تتم من صفحة إعدادات الموقع. الرسالة الترحيبية للمساعد تعتمد على اسم الجيم المسجل في هذه الصفحة.",
        ],
        [
            'keywords' => ['تقفيل', 'اغلاق', 'اقفال', 'closing'],
            'answer' => "التقفيل في النظام متاح من زر التقفيل، وفيه تقفيل يومي وتقفيل شهر. النظام يعتمد على الحركات المسجلة ليحسب الإيرادات والمصروفات والمبيعات وصافي النتائج قبل حفظ التقفيل.",
        ],
    ];

    foreach ($guides as $guide) {
        if (aiAssistantContainsAny($normalizedQuestion, $guide['keywords'])) {
            return $guide['answer'];
        }
    }

    if (aiAssistantContainsAny($normalizedQuestion, ['مين انت', 'من انت', 'ايه تقدر', 'ماذا تستطيع', 'تساعدني'])) {
        return "أنا Megz، مساعدك الذكي داخل النظام. أستطيع الإجابة عن أسئلة الإدارة الخاصة بالاشتراكات والإيرادات والمصروفات والمبيعات والموظفين، وأشرح لك أماكن الصفحات الرئيسية داخل النظام بشكل سريع.";
    }

    return null;
}

function aiAssistantLooksLikeNavigationQuestion(string $normalizedQuestion): bool
{
    return (bool)preg_match('/(^|\s)(كيف|فين|اين|مكان|شرح|وظيفه|وظيفة|استخدم|ادير)(\s|$)/u', $normalizedQuestion);
}

function aiAssistantBuildReply(PDO $pdo, string $question, string $siteName): array
{
    $question = trim($question);
    $normalizedQuestion = aiAssistantNormalizeText($question);
    $ranges = aiAssistantGetDateRanges();
    $monthStart = $ranges['month']['start'];
    $suggestedQuestions = aiAssistantDefaultSuggestedQuestions();

    if ($normalizedQuestion === '') {
        return [
            'answer' => 'اكتب سؤالك وسأجيبك فوراً عن مؤشرات النظام أو أوضح لك القسم المناسب داخل السيستم.',
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAny($normalizedQuestion, ['مرحبا', 'اهلا', 'هلا', 'السلام', 'صباح', 'مساء'])) {
        return [
            'answer' => 'مرحباً، أنا Megz مساعدك الذكي لإدارة نظام جيم ' . $siteName . ".\nاسألني عن الإيرادات أو المصروفات أو المبيعات أو الموظفين أو أي جزء داخل النظام وسأعطيك الإجابة مباشرة.",
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAny($normalizedQuestion, ['اقتراح', 'اقتراحات', 'نصيحه للاداره', 'نصيحه للادارة'])) {
        $monthStats = aiAssistantGetRangeStats($pdo, $ranges['month']['start'], $ranges['month']['end']);
        $monthRevenue = aiAssistantGetRevenueTotal($monthStats);
        $topSubscription = aiAssistantGetTopSubscription($pdo);
        $paidLists = aiAssistantGetPayrollLists($pdo, $monthStart);

        $answer = "أهم اقتراحاتي للإدارة حالياً:";
        $answer .= "\n• راقب صافي الشهر الحالي: الإيرادات " . aiAssistantFormatCurrency($monthRevenue) . " مقابل المصروفات " . aiAssistantFormatCurrency($monthStats['total_expenses']) . '.';
        if ($topSubscription) {
            $answer .= "\n• أكثر باقة مطلوبة حالياً هي \"" . $topSubscription['name'] . '" بعدد ' . $topSubscription['members_count'] . ' مشترك، ففكّر في تعزيز تسويقها أو عمل عرض مرتبط بها.';
        }
        if (!empty($paidLists['unpaid'])) {
            $answer .= "\n• ما زال هناك " . count($paidLists['unpaid']) . " موظف لم يستلموا مرتباتهم هذا الشهر، فراجع خطة الصرف لتجنب التأخير.";
        } else {
            $answer .= "\n• جميع الموظفين المسجلين تم صرف مرتباتهم هذا الشهر، وهذا مؤشر جيد على انتظام الإدارة المالية.";
        }

        return [
            'answer' => $answer,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    $systemGuidance = aiAssistantGetSystemGuidance($normalizedQuestion);
    if ($systemGuidance !== null && aiAssistantLooksLikeNavigationQuestion($normalizedQuestion)) {
        return [
            'answer' => $systemGuidance,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAll($normalizedQuestion, ['اشتراك', 'جديد']) && aiAssistantContainsAny($normalizedQuestion, ['اليوم', 'النهارده', 'اليومي'])) {
        $stats = aiAssistantGetRangeStats($pdo, $ranges['today']['start'], $ranges['today']['end']);
        $count = (int)$stats['new_subscriptions_count'];
        $answer = 'عدد الاشتراكات الجديدة اليوم هو ' . $count . ' اشتراك';
        $answer .= '، وإجمالي المدفوع المبدئي منها ' . aiAssistantFormatCurrency($stats['total_paid_for_new_subs']) . '.';
        $answer .= $count > 0
            ? "\nاقتراح إداري: راجع مصادر هذه الاشتراكات الجديدة وكرّر القناة التسويقية الأكثر نجاحاً اليوم."
            : "\nاقتراح إداري: إذا ظل العدد صفراً حتى نهاية اليوم ففكّر في متابعة العملاء المحتملين أو عرض ترويجي سريع.";

        return [
            'answer' => $answer,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAny($normalizedQuestion, ['اكثر الاشتراكات', 'اكتر الاشتراكات', 'الاكثر اشتراكا', 'الاشتراك الاكثر']) || (aiAssistantContainsAny($normalizedQuestion, ['اشتراك', 'اشتراكات']) && aiAssistantContainsAny($normalizedQuestion, ['اكثر', 'اكتر', 'اعلي', 'اعلى']))) {
        $topSubscription = aiAssistantGetTopSubscription($pdo);
        if (!$topSubscription) {
            $answer = 'لا توجد بيانات كافية حالياً لتحديد أكثر الاشتراكات طلباً حتى الآن.';
        } else {
            $answer = 'أكثر الاشتراكات اشتراكاً فيه حتى الآن هو "' . $topSubscription['name'] . '" بعدد ' . $topSubscription['members_count'] . ' مشترك.';
            $answer .= "\nاقتراح إداري: وفّر مزايا إضافية أو حملة تجديد خاصة بهذه الباقة لأنها الأكثر جذباً للمشتركين.";
        }

        return [
            'answer' => $answer,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAny($normalizedQuestion, ['ايراد', 'ايرادات', 'دخل'])) {
        $rangeKey = aiAssistantDetectRangeKey($normalizedQuestion, 'today');
        $range = $ranges[$rangeKey];
        $stats = aiAssistantGetRangeStats($pdo, $range['start'], $range['end']);
        $revenue = aiAssistantGetRevenueTotal($stats);

        $answer = 'إجمالي الإيرادات خلال ' . $range['label'] . ' هو ' . aiAssistantFormatCurrency($revenue) . '.';
        $answer .= "\nتفصيل الإيرادات:";
        $answer .= "\n• اشتراكات جديدة: " . aiAssistantFormatCurrency($stats['total_paid_for_new_subs']);
        $answer .= "\n• سداد بواقي: " . aiAssistantFormatCurrency($stats['total_partial_payments']);
        $answer .= "\n• تجديدات: " . aiAssistantFormatCurrency($stats['total_renewals_amount']);
        $answer .= "\n• حصة واحدة: " . aiAssistantFormatCurrency($stats['total_single_sessions_amount']);
        $answer .= "\n• مبيعات الأصناف: " . aiAssistantFormatCurrency($stats['total_sales_amount']);
        $answer .= $stats['total_expenses'] > $revenue
            ? "\nاقتراح إداري: المصروفات أعلى من الإيرادات في هذه الفترة، فراجع بنود الصرف والتحصيل سريعاً."
            : "\nاقتراح إداري: حافظ على متابعة مصادر الدخل الأعلى وركّز على تعظيمها في نفس الفترة القادمة.";

        return [
            'answer' => $answer,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAny($normalizedQuestion, ['مصروف', 'مصروفات'])) {
        $rangeKey = aiAssistantDetectRangeKey($normalizedQuestion, 'today');
        $range = $ranges[$rangeKey];
        $stats = aiAssistantGetRangeStats($pdo, $range['start'], $range['end']);

        $answer = 'إجمالي المصروفات خلال ' . $range['label'] . ' هو ' . aiAssistantFormatCurrency($stats['total_expenses']) . '.';
        $answer .= "\nتفصيل المصروفات:";
        $answer .= "\n• مصروفات عامة: " . aiAssistantFormatCurrency($stats['regular_expenses']);
        $answer .= "\n• سلف موظفين: " . aiAssistantFormatCurrency($stats['employee_advances']);
        $answer .= "\n• سلف مدربين: " . aiAssistantFormatCurrency($stats['trainer_advances']);
        $answer .= "\n• مرتبات موظفين: " . aiAssistantFormatCurrency($stats['employee_salaries']);
        $answer .= "\nاقتراح إداري: تابع البند الأعلى تكلفة في هذه الفترة حتى تسيطر على هامش الربح.";

        return [
            'answer' => $answer,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAny($normalizedQuestion, ['مبيعات', 'فواتير', 'فاتوره', 'فاتورة'])) {
        $rangeKey = aiAssistantDetectRangeKey($normalizedQuestion, 'today');
        $range = $ranges[$rangeKey];
        $stats = aiAssistantGetRangeStats($pdo, $range['start'], $range['end']);

        $answer = 'إجمالي المبيعات من الفواتير المسجلة خلال ' . $range['label'] . ' هو ' . aiAssistantFormatCurrency($stats['total_sales_amount']) . '.';
        $answer .= "\nعدد عمليات البيع/المرتجع المسجلة في نفس الفترة: " . (int)$stats['sales_operations_count'] . ' فاتورة.';
        $answer .= $stats['total_sales_amount'] > 0
            ? "\nاقتراح إداري: راقب الأصناف الأعلى حركة وربطها بعروض الكاشير لزيادة متوسط قيمة الفاتورة."
            : "\nاقتراح إداري: إذا لم توجد مبيعات في هذه الفترة فراجع توفر الأصناف وتفعيل البيع من الكاشير.";

        return [
            'answer' => $answer,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAny($normalizedQuestion, ['صنف', 'اصناف', 'الأصناف', 'الاصناف'])) {
        $summary = aiAssistantGetItemsSummary($pdo);
        if ($summary['items_count'] === 0) {
            $answer = 'لا توجد أصناف مسجلة حالياً في صفحة الأصناف.';
        } else {
            $answer = 'عدد الأصناف المسجلة حالياً هو ' . $summary['items_count'] . ' صنف';
            if ($summary['quantity_units'] > 0) {
                $answer .= '، وإجمالي الكميات المتتبعة بالأعداد هو ' . $summary['quantity_units'] . ' وحدة.';
            } else {
                $answer .= '.';
            }

            $answer .= "\nتفاصيل الأصناف:";
            $displayRows = array_slice($summary['rows'], 0, 10);
            foreach ($displayRows as $row) {
                $line = "\n• " . (string)$row['name'];
                if ((int)($row['has_quantity'] ?? 0) === 1) {
                    $line .= ' — الكمية: ' . (int)($row['item_count'] ?? 0);
                } else {
                    $line .= ' — بدون تتبع كمية';
                }
                if (isset($row['price']) && $row['price'] !== null && $row['price'] !== '') {
                    $line .= ' — السعر: ' . aiAssistantFormatCurrency($row['price']);
                }
                $answer .= $line;
            }
            if (count($summary['rows']) > count($displayRows)) {
                $answer .= "\n• يوجد " . (count($summary['rows']) - count($displayRows)) . ' صنف إضافي داخل القائمة.';
            }
            $answer .= "\nاقتراح إداري: راجع الأصناف التي لها كمية منخفضة حتى لا تتوقف حركة البيع.";
        }

        return [
            'answer' => $answer,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAll($normalizedQuestion, ['الموظف']) && aiAssistantContainsAny($normalizedQuestion, ['مثالي', 'افضل', 'الأفضل', 'الافضل'])) {
        $bestEmployee = aiAssistantGetEmployeePerformance($pdo, $ranges['month']['start'], $ranges['month']['end'], 'best');
        if (!$bestEmployee) {
            $answer = 'لا توجد بيانات حضور كافية هذا الشهر لتحديد الموظف المثالي.';
        } else {
            $answer = 'الموظف المثالي لهذا الشهر هو ' . $bestEmployee['name'] . '.';
            $answer .= "\nسجل " . $bestEmployee['perfect_days'] . ' يوم التزام كامل من أصل ' . $bestEmployee['recorded_days'] . ' يوم حضور مسجل.';
            $answer .= "\nاقتراح إداري: كرّم هذا الموظف أو شارك أسباب التزامه مع باقي الفريق لرفع مستوى الانضباط.";
        }

        return [
            'answer' => $answer,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAll($normalizedQuestion, ['الموظف']) && aiAssistantContainsAny($normalizedQuestion, ['سيء', 'سئ', 'الاسوا', 'الأسوأ'])) {
        $worstEmployee = aiAssistantGetEmployeePerformance($pdo, $ranges['month']['start'], $ranges['month']['end'], 'worst');
        if (!$worstEmployee) {
            $answer = 'لا يوجد موظف سيئ واضح هذا الشهر حتى الآن؛ لا توجد حالات تأخير أو انصراف مبكر مسجلة بشكل مؤثر.';
        } else {
            $answer = 'الموظف الأقل التزاماً هذا الشهر هو ' . $worstEmployee['name'] . '.';
            $answer .= "\nإجمالي المخالفات المسجلة عليه: " . $worstEmployee['issue_count'] . '، منها ' . $worstEmployee['late_count'] . ' تأخير و' . $worstEmployee['early_departure_count'] . ' انصراف مبكر.';
            $answer .= "\nاقتراح إداري: حدّد جلسة متابعة سريعة مع هذا الموظف لمعالجة سبب التأخير أو الانصراف المبكر.";
        }

        return [
            'answer' => $answer,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAny($normalizedQuestion, ['قبضوا', 'استلموا', 'اخذوا', 'اخدوا']) && aiAssistantContainsAny($normalizedQuestion, ['مرتب', 'مرتبات', 'راتب', 'رواتب']) && !aiAssistantContainsAny($normalizedQuestion, ['لم يقبضوا', 'ما قبضوش', 'لم يستلموا', 'لسه ما قبضوش', 'غير المقبوض'])) {
        $lists = aiAssistantGetPayrollLists($pdo, $monthStart);
        if (!$lists['paid']) {
            $answer = 'لا يوجد موظفون تم تسجيل صرف مرتباتهم هذا الشهر حتى الآن.';
        } else {
            $answer = 'الموظفون الذين قبضوا مرتباتهم هذا الشهر هم: ' . implode('، ', $lists['paid']) . '.';
            $answer .= "\nإجمالي العدد: " . count($lists['paid']) . ' موظف.';
        }

        return [
            'answer' => $answer,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAny($normalizedQuestion, ['لم يقبضوا', 'ما قبضوش', 'لم يستلموا', 'لسه ما قبضوش', 'غير المقبوض', 'لم يقبض'])) {
        $lists = aiAssistantGetPayrollLists($pdo, $monthStart);
        if (!$lists['unpaid']) {
            $answer = 'جميع الموظفين المسجلين تم صرف مرتباتهم هذا الشهر.';
        } else {
            $answer = 'الموظفون الذين لم يقبضوا مرتباتهم هذا الشهر هم: ' . implode('، ', $lists['unpaid']) . '.';
            $answer .= "\nإجمالي العدد: " . count($lists['unpaid']) . ' موظف.';
            $answer .= "\nاقتراح إداري: راجع خطة الصرف لهؤلاء الموظفين حتى لا يتأثر الالتزام أو الرضا الوظيفي.";
        }

        return [
            'answer' => $answer,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if (aiAssistantContainsAny($normalizedQuestion, ['سلف', 'سلفه', 'سلف الموظفين', 'سلفه الموظفين']) && aiAssistantContainsAny($normalizedQuestion, ['موظف', 'موظفين', 'الشهر'])) {
        $advances = aiAssistantGetEmployeeAdvances($pdo, $ranges['month']['start'], $ranges['month']['end']);
        if ($advances['count'] === 0) {
            $answer = 'لا توجد سلف موظفين مسجلة هذا الشهر حتى الآن.';
        } else {
            $answer = 'سلف الموظفين هذا الشهر عددها ' . $advances['count'] . ' بإجمالي ' . aiAssistantFormatCurrency($advances['total']) . '.';
            $answer .= "\nالتفاصيل:";
            foreach (array_slice($advances['rows'], 0, 10) as $row) {
                $answer .= "\n• " . $row['name'] . ' — ' . aiAssistantFormatCurrency($row['amount']) . ' بتاريخ ' . $row['advance_date'];
            }
            if ($advances['count'] > 10) {
                $answer .= "\n• يوجد " . ($advances['count'] - 10) . ' سلفة إضافية في نفس الشهر.';
            }
            $answer .= "\nاقتراح إداري: راقب تكرار السلف حسب الموظف لتحديد أي ضغط مالي متكرر داخل الفريق.";
        }

        return [
            'answer' => $answer,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    if ($systemGuidance !== null) {
        return [
            'answer' => $systemGuidance,
            'suggested_questions' => $suggestedQuestions,
        ];
    }

    return [
        'answer' => "أفهم أسئلة الإدارة المتعلقة بالنظام مثل الاشتراكات والإيرادات والمصروفات والمبيعات والموظفين، كما أشرح لك صفحات السيستم الرئيسية.\nإذا أردت إجابة دقيقة فاسألني بصيغة مباشرة مثل: إجمالي الإيرادات خلال الشهر؟ أو الموظف المثالي لهذا الشهر؟",
        'suggested_questions' => $suggestedQuestions,
    ];
}
