<?php

function memberPortalNormalizeDigits(string $value): string
{
    return strtr($value, [
        '٠' => '0',
        '١' => '1',
        '٢' => '2',
        '٣' => '3',
        '٤' => '4',
        '٥' => '5',
        '٦' => '6',
        '٧' => '7',
        '٨' => '8',
        '٩' => '9',
        '٫' => '.',
        '،' => '.',
    ]);
}

function memberPortalNormalizeNumericInput($value): string
{
    $normalized = memberPortalNormalizeDigits(trim((string)$value));
    $sanitized = preg_replace('/[^0-9.]/', '', $normalized);
    if ($sanitized === null) {
        return '';
    }
    $normalized = $sanitized;

    if ($normalized === '') {
        return '';
    }

    $segments = explode('.', $normalized);
    if (count($segments) > 2) {
        $normalized = array_shift($segments) . '.' . implode('', $segments);
    }

    return $normalized;
}

function memberPortalValidateNutritionInputs($ageInput, $weightInput, $bodyFatInput): array
{
    $ageNormalized = memberPortalNormalizeNumericInput((string)$ageInput);
    $weightNormalized = memberPortalNormalizeNumericInput((string)$weightInput);
    $bodyFatNormalized = memberPortalNormalizeNumericInput((string)$bodyFatInput);

    if ($ageNormalized === '' || $weightNormalized === '' || $bodyFatNormalized === '') {
        return ['ok' => false, 'message' => 'من فضلك أدخل السن والوزن ونسبة الدهون كاملة.'];
    }

    $age = (int)round((float)$ageNormalized);
    $weight = round((float)$weightNormalized, 1);
    $bodyFat = round((float)$bodyFatNormalized, 1);

    if ($age < 12 || $age > 80) {
        return ['ok' => false, 'message' => 'السن يجب أن يكون بين 12 و 80 سنة.'];
    }

    if ($weight < 35 || $weight > 250) {
        return ['ok' => false, 'message' => 'الوزن يجب أن يكون بين 35 و 250 كجم.'];
    }

    if ($bodyFat < 4 || $bodyFat > 60) {
        return ['ok' => false, 'message' => 'نسبة الدهون يجب أن تكون بين 4% و 60%.'];
    }

    return [
        'ok' => true,
        'age' => $age,
        'weight' => $weight,
        'body_fat' => $bodyFat,
    ];
}

function memberPortalFindMemberData(PDO $pdo, string $phoneInput, ?string $logoPath = null): ?array
{
    $phoneInput = trim($phoneInput);
    if ($phoneInput === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            m.*,
            s.name AS subscription_name
        FROM members m
        JOIN subscriptions s ON s.id = m.subscription_id
        WHERE m.phone = :ph
        LIMIT 1
    ");
    $stmt->execute([':ph' => $phoneInput]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        return null;
    }

    $today = new DateTime(date('Y-m-d'));
    $endDate = $member['end_date'] ? new DateTime($member['end_date']) : null;
    $daysLeft = null;

    if ($endDate) {
        $diff = $today->diff($endDate);
        $daysLeft = ($endDate >= $today) ? $diff->days : 0;
    }

    $freezeAllowed = (int)($member['freeze_days'] ?? 0);
    $freezeUsed = (int)($member['used_freeze_days'] ?? 0);
    $freezeRemain = max(0, $freezeAllowed - $freezeUsed);

    $spaCount = isset($member['spa_count']) ? (int)$member['spa_count'] : 0;
    $massageCount = isset($member['massage_count']) ? (int)$member['massage_count'] : 0;
    $jacuzziCount = isset($member['jacuzzi_count']) ? (int)$member['jacuzzi_count'] : 0;

    $memberPhotoPath = $member['photo_path'] ?? null;
    if (!empty($memberPhotoPath)) {
        $displayPhoto = $memberPhotoPath;
    } elseif (!empty($logoPath)) {
        $displayPhoto = $logoPath;
    } else {
        $displayPhoto = null;
    }

    return [
        'id' => (int)$member['id'],
        'name' => $member['name'],
        'phone' => $member['phone'],
        'barcode' => $member['barcode'],
        'subscription_name' => $member['subscription_name'],
        'start_date' => $member['start_date'],
        'end_date' => $member['end_date'],
        'days_left' => $daysLeft,
        'sessions_remaining' => (int)$member['sessions_remaining'],
        'freeze_days' => $freezeAllowed,
        'freeze_used_days' => $freezeUsed,
        'freeze_left_days' => $freezeRemain,
        'paid_amount' => (float)$member['paid_amount'],
        'remaining_amount' => (float)$member['remaining_amount'],
        'status' => $member['status'],
        'spa_count' => $spaCount,
        'massage_count' => $massageCount,
        'jacuzzi_count' => $jacuzziCount,
        'photo_path' => $memberPhotoPath,
        'display_photo' => $displayPhoto,
    ];
}

function memberPortalGetNutritionAgeBand(int $age): string
{
    if ($age <= 19) {
        return 'teen';
    }

    if ($age <= 34) {
        return 'young';
    }

    if ($age <= 49) {
        return 'adult';
    }

    return 'mature';
}

function memberPortalGetNutritionWeightBand(float $weight): string
{
    if ($weight < 60) {
        return 'light';
    }

    if ($weight < 80) {
        return 'medium';
    }

    if ($weight < 100) {
        return 'heavy';
    }

    return 'very-heavy';
}

function memberPortalGetNutritionBodyFatBand(float $bodyFat): string
{
    if ($bodyFat <= 10) {
        return 'very-low';
    }

    if ($bodyFat <= 15) {
        return 'low';
    }

    if ($bodyFat <= 22) {
        return 'moderate';
    }

    if ($bodyFat <= 30) {
        return 'high';
    }

    return 'very-high';
}

function memberPortalGetNutritionMealTier(int $dailyCalories): string
{
    if ($dailyCalories <= 1700) {
        return 'slim';
    }

    if ($dailyCalories <= 2050) {
        return 'light';
    }

    if ($dailyCalories <= 2400) {
        return 'medium';
    }

    if ($dailyCalories <= 2800) {
        return 'high';
    }

    return 'athlete';
}

function memberPortalBuildNutritionPlan(array $memberData, int $age, float $weight, float $bodyFat): array
{
    $ageBand = memberPortalGetNutritionAgeBand($age);
    $weightBand = memberPortalGetNutritionWeightBand($weight);
    $bodyFatBand = memberPortalGetNutritionBodyFatBand($bodyFat);
    $leanMass = round(max(30, $weight * (1 - ($bodyFat / 100))), 1);

    $goalKey = 'recomp';
    $goalLabel = 'إعادة تركيب الجسم وخفض الدهون مع الحفاظ على العضلات';
    $goalNote = 'سنستخدم توزيعًا متوازنًا للسعرات حتى يتحسن شكل الجسم ويظل الأداء جيدًا أثناء التمرين.';
    $calorieFactor = 29;
    $proteinFactor = 1.9;
    $leanMassProteinFactor = 2.2;
    $fatFactor = 0.8;
    $carbFloor = 120;

    if ($bodyFat >= 31) {
        $goalKey = 'cut-hard';
        $goalLabel = 'خفض الدهون بشكل واضح';
        $goalNote = 'رفعت البروتين وخففت النشويات نسبيًا لأن نسبة الدهون مرتفعة ونحتاج نزولًا أوضح مع الحفاظ على الكتلة العضلية.';
        $calorieFactor = 23;
        $proteinFactor = 2.0;
        $leanMassProteinFactor = 2.4;
        $fatFactor = 0.65;
        $carbFloor = 95;
    } elseif ($bodyFat >= 24) {
        $goalKey = 'cut';
        $goalLabel = 'خفض الدهون تدريجيًا';
        $goalNote = 'الخطة هنا تقلل الفائض غير الضروري وتبقي الأكل عمليًا حتى تنزل دهونك بثبات بدون هبوط طاقة كبير.';
        $calorieFactor = 26;
        $proteinFactor = 1.95;
        $leanMassProteinFactor = 2.3;
        $fatFactor = 0.7;
        $carbFloor = 110;
    } elseif ($bodyFat <= 11) {
        $goalKey = 'lean-gain';
        $goalLabel = 'زيادة عضلية نظيفة';
        $goalNote = 'أنت في نسبة دهون منخفضة، لذلك رفعت السعرات تدريجيًا لدعم البناء العضلي مع التحكم في الزيادة الدهنية.';
        $calorieFactor = 34;
        $proteinFactor = 1.85;
        $leanMassProteinFactor = 2.1;
        $fatFactor = 0.9;
        $carbFloor = 170;
    } elseif ($bodyFat <= 15 && $weight <= 70) {
        $goalKey = 'lean-gain';
        $goalLabel = 'زيادة عضلية محسوبة';
        $goalNote = 'وزنك الحالي مع نسبة الدهون المنخفضة يسمحان بزيادة سعرات محسوبة لدعم الامتلاء العضلي بدون فقدان الشكل الرياضي.';
        $calorieFactor = 32;
        $proteinFactor = 1.85;
        $leanMassProteinFactor = 2.15;
        $fatFactor = 0.85;
        $carbFloor = 155;
    }

    if ($ageBand === 'teen') {
        $calorieFactor += 2;
        $fatFactor += 0.05;
    } elseif ($ageBand === 'adult') {
        $calorieFactor -= 1;
    } elseif ($ageBand === 'mature') {
        $calorieFactor -= 2;
        $fatFactor += 0.05;
    }

    $isCutGoal = ($goalKey === 'cut' || $goalKey === 'cut-hard');

    if ($weightBand === 'light' && $goalKey === 'lean-gain') {
        $calorieFactor += 1;
    } elseif ($weightBand === 'very-heavy' && $isCutGoal) {
        $proteinFactor += 0.1;
        $leanMassProteinFactor += 0.1;
    }

    if ($bodyFatBand === 'very-high') {
        $carbFloor = 90;
    } elseif ($bodyFatBand === 'very-low') {
        $carbFloor += 15;
    }

    $dailyCalories = (int)(round(($weight * $calorieFactor) / 50) * 50);
    $proteinGrams = (int)round(max($weight * $proteinFactor, $leanMass * $leanMassProteinFactor));
    $fatGrams = (int)max(45, round($weight * $fatFactor));
    $carbsGrams = (int)max($carbFloor, round(($dailyCalories - ($proteinGrams * 4) - ($fatGrams * 9)) / 4));

    $macroCalories = ($proteinGrams * 4) + ($carbsGrams * 4) + ($fatGrams * 9);
    if ($macroCalories > $dailyCalories) {
        $dailyCalories = (int)(round($macroCalories / 50) * 50);
    }

    $waterLiters = round(max(2.5, $weight * 0.035), 1);
    $mealTier = memberPortalGetNutritionMealTier($dailyCalories);

    $portions = [
        'slim' => [
            'bread' => 'نصف رغيف بلدي أو 2 توست سن',
            'oats' => '3 ملاعق شوفان',
            'rice' => '5 ملاعق أرز أو 180 جم بطاطس',
            'proteinLunch' => '140 جم',
            'proteinDinner' => '110 جم',
            'fruit' => 'ثمرة واحدة صغيرة',
            'nuts' => '6 حبات لوز أو 1 ملعقة فول سوداني',
            'dates' => '2 تمر',
            'dairy' => 'علبة زبادي أو 200 مل لبن',
        ],
        'light' => [
            'bread' => '1 رغيف بلدي صغير',
            'oats' => '4 ملاعق شوفان',
            'rice' => '6 ملاعق أرز أو 220 جم بطاطس',
            'proteinLunch' => '150 جم',
            'proteinDinner' => '120 جم',
            'fruit' => 'ثمرة واحدة',
            'nuts' => '8 حبات لوز أو 1 ملعقة فول سوداني',
            'dates' => '2 تمر',
            'dairy' => 'علبة زبادي أو 250 مل لبن',
        ],
        'medium' => [
            'bread' => '1.5 رغيف بلدي صغير',
            'oats' => '5 ملاعق شوفان',
            'rice' => '8 ملاعق أرز أو 280 جم بطاطس',
            'proteinLunch' => '180 جم',
            'proteinDinner' => '150 جم',
            'fruit' => 'ثمرة إلى ثمرتين',
            'nuts' => '10 حبات لوز أو 2 ملعقة فول سوداني',
            'dates' => '3 تمرات',
            'dairy' => 'علبة زبادي كبيرة أو 300 مل لبن',
        ],
        'high' => [
            'bread' => '2 رغيف بلدي صغير',
            'oats' => '6 ملاعق شوفان',
            'rice' => '10 ملاعق أرز أو 350 جم بطاطس',
            'proteinLunch' => '200 جم',
            'proteinDinner' => '180 جم',
            'fruit' => '2 ثمرة',
            'nuts' => '12 حبة لوز أو 2 ملعقة فول سوداني',
            'dates' => '4 تمرات',
            'dairy' => '300 مل لبن + علبة زبادي',
        ],
        'athlete' => [
            'bread' => '2.5 رغيف بلدي صغير أو 4 توست سن',
            'oats' => '7 ملاعق شوفان',
            'rice' => '12 ملعقة أرز أو 420 جم بطاطس',
            'proteinLunch' => '220 جم',
            'proteinDinner' => '190 جم',
            'fruit' => '2 ثمرة كبيرة',
            'nuts' => '14 حبة لوز أو 3 ملاعق فول سوداني',
            'dates' => '5 تمرات',
            'dairy' => '350 مل لبن + علبة زبادي',
        ],
    ][$mealTier];

    $wholeEggs = 3;
    $eggWhites = 1;
    if ($goalKey === 'cut-hard') {
        $wholeEggs = 2;
        $eggWhites = 3;
    } elseif ($goalKey === 'cut') {
        $wholeEggs = 2;
        $eggWhites = 2;
    } elseif ($goalKey === 'lean-gain') {
        $wholeEggs = 4;
        $eggWhites = 2;
    }

    if ($weightBand === 'very-heavy' && $goalKey !== 'cut-hard') {
        $eggWhites += 1;
    }

    $cheeseAmount = '60 جم';
    if ($weightBand === 'light') {
        $cheeseAmount = '50 جم';
    } elseif ($weightBand === 'heavy') {
        $cheeseAmount = '80 جم';
    } elseif ($weightBand === 'very-heavy') {
        $cheeseAmount = '90 جم';
    }

    $breakfastProtein = $wholeEggs . ' بيضة كاملة';
    if ($eggWhites > 0) {
        $breakfastProtein .= ' + ' . $eggWhites . ' بياض';
    }
    $breakfastProtein .= ' + ' . $cheeseAmount . ' جبنة قريش أو زبادي يوناني';

    $breakfastCarb = $portions['bread'] . ' أو ' . $portions['oats'];
    if ($goalKey === 'cut-hard') {
        $breakfastCarb .= ' ويفضل الشوفان في الأيام قليلة الحركة';
    } elseif ($goalKey === 'lean-gain') {
        $breakfastCarb .= ' مع قرفة وحليب قليل الدسم';
    } else {
        $breakfastCarb .= ' مع قرفة';
    }

    $snackFruit = 'ثمرة فاكهة موسمية';
    if ($goalKey === 'cut-hard') {
        $snackFruit = 'تفاحة أو جوافة أو برتقالة';
    } elseif ($goalKey === 'lean-gain') {
        $snackFruit = 'موزة أو مانجو صغيرة أو تمر إضافي في أيام التمرين';
    }

    $lunchProteinSource = 'من صدور الدجاج أو السمك البلطي أو اللحم الأحمر الخالي من الدهون';
    if ($ageBand === 'mature') {
        $lunchProteinSource = 'من السمك أو الدجاج المشوي أو اللحم الأحمر الخالي من الدهون';
    }

    $lunchCarbNote = $portions['rice'];
    if ($goalKey === 'cut-hard') {
        $lunchCarbNote .= ' ويفضل الأرز أو البطاطس على المكرونة';
    } elseif ($goalKey === 'lean-gain') {
        $lunchCarbNote .= ' ويمكن التبديل أحيانًا بمكرونة مسلوقة';
    }

    $preWorkoutFruit = 'موزة متوسطة';
    if ($goalKey === 'cut-hard') {
        $preWorkoutFruit = 'نصف موزة إلى موزة حسب شدة التمرين';
    } elseif ($goalKey === 'lean-gain') {
        $preWorkoutFruit = 'موزة كبيرة';
    }

    $trainingProtein = 'علبة تونة مصفاة أو كوب لبن رايب أو سكوب بروتين إن وجد';
    if ($ageBand === 'teen') {
        $trainingProtein = 'كوب لبن ' . ($goalKey === 'lean-gain' ? 'كبير' : 'أو زبادي') . ' + ساندوتش جبنة قريش أو سكوب بروتين إن وجد';
    } elseif ($goalKey === 'cut-hard') {
        $trainingProtein = 'علبة تونة مصفاة أو زبادي يوناني أو سكوب بروتين إن وجد';
    }

    $dinnerProtein = $portions['proteinDinner'] . ' من التونة أو الجبنة القريش أو 2 بيضة كاملة + 2 بياض';
    $dinnerSides = 'سلطة خضراء كبيرة أو شوربة خضار';
    $dinnerExtra = 'ثمرة فاكهة خفيفة عند الحاجة مثل الجوافة أو البرتقال';
    $dinnerAlternative = 'بديل مشبع: زبادي + شوفان + قرفة + تفاحة صغيرة.';

    if ($goalKey === 'cut-hard') {
        $dinnerProtein = $portions['proteinDinner'] . ' من التونة أو الجبنة القريش أو صدور الدجاج المشوي';
        $dinnerSides = 'سلطة كبيرة جدًا + شوربة خضار أو خضار سوتيه';
        $dinnerExtra = 'ابتعد عن الخبز ليلًا إلا إذا كان التمرين متأخرًا.';
        $dinnerAlternative = 'بديل خفيف: علبة زبادي يوناني + خيار + 2 توست سن.';
    } elseif ($goalKey === 'lean-gain') {
        $dinnerProtein = $portions['proteinDinner'] . ' من الدجاج أو التونة أو الجبنة القريش';
        $dinnerSides = 'سلطة خضراء + ' . $portions['bread'] . ' أو 3 ملاعق شوفان إذا كان التمرين مساءً';
        $dinnerExtra = 'ثمرة فاكهة أو كوب لبن قبل النوم إذا كان الجوع مرتفعًا.';
        $dinnerAlternative = 'بديل بناء عضلي: سندوتشات تونة أو جبنة قريش في خبز بلدي/سن + زبادي.';
    } elseif ($ageBand === 'mature') {
        $dinnerExtra = 'اختر وجبة أخف على الهضم مساءً مثل الزبادي أو الجبنة القريش مع الخضار.';
        $dinnerAlternative = 'بديل مناسب: شوربة عدس خفيفة + جبنة قريش + سلطة.';
    }

    $meals = [
        [
            'name' => 'الفطار',
            'time' => '8:00 - 10:00 صباحًا',
            'items' => [
                $breakfastProtein,
                $breakfastCarb,
                'خيار + طماطم + جرجير أو خس',
                $portions['fruit'] . ' من الفاكهة المصرية مثل الموز أو البرتقال أو الجوافة',
            ],
            'alternatives' => $goalKey === 'lean-gain'
                ? 'بديل سريع: فول بدون سمن + بيض مسلوق + خبز بلدي + كوب لبن.'
                : 'بديل سريع: فول بدون سمن + بيض مسلوق + خبز بلدي أو 2 توست سن.',
        ],
        [
            'name' => 'سناك 1',
            'time' => '12:00 - 1:00 ظهرًا',
            'items' => [
                $portions['dairy'],
                $portions['nuts'],
                $snackFruit . ' مثل التفاح أو الفراولة أو الكنتالوب حسب الموسم',
            ],
            'alternatives' => $goalKey === 'cut-hard'
                ? 'بديل: كوب لبن رايب خالي أو قليل الدسم + ثمرة فاكهة منخفضة السكر.'
                : 'بديل: كوب لبن رايب + ثمرة موز أو تمرات حسب احتياجك اليومي.',
        ],
        [
            'name' => 'الغداء',
            'time' => '3:00 - 5:00 عصرًا',
            'items' => [
                $portions['proteinLunch'] . ' ' . $lunchProteinSource,
                $lunchCarbNote,
                'طبق سلطة كبير + خضار مطبوخ مثل الكوسة أو الفاصوليا أو الملوخية بدون سمن كثير',
            ],
            'alternatives' => $goalKey === 'cut-hard'
                ? 'بديل مصري ممتاز: طاجن خضار + بروتين مشوي + كمية النشويات المذكورة فقط بدون إضافات.'
                : ($goalKey === 'lean-gain'
                    ? 'بديل مصري ممتاز: أرز + بطاطس + بروتين مشوي + سلطة، مع تجنب الدهون الزائدة.'
                    : 'بديل مصري ممتاز: طاجن خضار + بروتين مشوي + النشويات المقترحة حسب حصتك.'),
        ],
        [
            'name' => 'سناك قبل/بعد التمرين',
            'time' => 'قبل أو بعد التمرين بساعة',
            'items' => [
                $preWorkoutFruit,
                $portions['dates'],
                $trainingProtein,
            ],
            'alternatives' => $goalKey === 'cut-hard'
                ? 'بديل: ساندوتش جبنة قريش في نصف رغيف بلدي أو 2 توست سن + قهوة بدون سكر.'
                : 'بديل: ساندوتش جبنة قريش أو تونة + قهوة بدون سكر أو شاي.',
        ],
        [
            'name' => 'العشاء',
            'time' => '8:00 - 10:30 مساءً',
            'items' => [
                $dinnerProtein,
                $dinnerSides,
                $dinnerExtra,
            ],
            'alternatives' => $dinnerAlternative,
        ],
    ];

    $tips = [
        'اشرب ' . number_format($waterLiters, 1) . ' لتر ماء يوميًا على الأقل.',
        'استخدم الشوي أو السلق أو القلي الهوائي بدلًا من القلي التقليدي كلما أمكن.',
        'حافظ على طبق سلطة يومي لأن الأكل المصري غالبًا يحتاج ألياف إضافية للشبع وتنظيم الهضم.',
        $isCutGoal
            ? 'لو هتأكل أكلة مصرية دسمة مثل كشري أو محشي، اجعلها بدل الغداء وخفف النشويات في باقي اليوم.'
            : 'لو هتأكل أكلة مصرية دسمة مثل كشري أو محشي، حاول موازنتها ببروتين أعلى ودهون أقل في الوجبات الأخرى.',
        'اختيارات الفاكهة المناسبة في مصر: موز، برتقال، جوافة، تفاح، فراولة، كنتالوب حسب الموسم.',
    ];

    if ($ageBand === 'teen') {
        $tips[] = 'لأن سنك صغير نسبيًا، لا تقلل الأكل بعنف؛ ركز على البروتين والنوم 7-9 ساعات لدعم البناء والتعافي.';
    } elseif ($ageBand === 'mature') {
        $tips[] = 'مع التقدم في السن يفضَّل توزيع البروتين على اليوم كله وتقليل الوجبات الثقيلة آخر الليل لتحسين الهضم والاستشفاء.';
    } else {
        $tips[] = 'حاول تثبيت مواعيد الوجبات 80% من الأسبوع لأن الالتزام أهم من المثالية.';
    }

    if ($bodyFatBand === 'very-high' || $bodyFatBand === 'high') {
        $tips[] = 'استهدف 7-10 آلاف خطوة يوميًا مع 2-4 حصص كارديو خفيفة أسبوعيًا لتسريع خفض الدهون بدون ضغط مبالغ.';
    } elseif ($bodyFatBand === 'very-low' || $bodyFatBand === 'low') {
        $tips[] = 'لا ترفع الكارديو بدون داعٍ، وركز على التمرين المقاوم وزيادة الأحمال تدريجيًا حتى تستفيد من الخطة العضلية.';
    } else {
        $tips[] = 'وازن بين أيام التدريب والراحة؛ التزم أكثر بالبروتين والخضار إذا قلت حركتك في يوم معين.';
    }

    $memberName = trim((string)($memberData['name'] ?? 'البطل'));
    $summaryLines = [
        'يا ' . $memberName . '، أنا كابتن MO وجهزت لك نظامًا غذائيًا مناسبًا لسن ' . $age . ' سنة ووزن ' . number_format($weight, 1) . ' كجم ونسبة دهون ' . number_format($bodyFat, 1) . '٪.',
        'اعتمدت التوصية على كتلة جسم خالية من الدهون تقارب ' . number_format($leanMass, 1) . ' كجم مع هدف: ' . $goalLabel . '.',
        'سعراتك اليومية المقترحة حوالي ' . number_format($dailyCalories) . ' سعرة حرارية موزعة بطريقة تناسب سنك الحالي ونسبة دهونك.',
        'التوزيع اليومي: بروتين ' . $proteinGrams . ' جم، كربوهيدرات ' . $carbsGrams . ' جم، دهون ' . $fatGrams . ' جم.',
        'اخترت وجبات مصرية عملية مع تعديل كمية النشويات والبروتين ومرونة العشاء حسب حالتك بدل استخدام قالب واحد للجميع.',
    ];

    return [
        'member_name' => $memberName,
        'age' => $age,
        'weight' => $weight,
        'body_fat' => $bodyFat,
        'goal_key' => $goalKey,
        'goal_label' => $goalLabel,
        'goal_note' => $goalNote,
        'daily_calories' => $dailyCalories,
        'protein_grams' => $proteinGrams,
        'carbs_grams' => $carbsGrams,
        'fat_grams' => $fatGrams,
        'water_liters' => $waterLiters,
        'meals' => $meals,
        'tips' => $tips,
        'summary_lines' => $summaryLines,
    ];
}
