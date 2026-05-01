<?php

const EGYPT_COUNTRY_CODE = '20';
const EGYPT_COUNTRY_CODE_WITH_EXIT = '0020';
const EGYPT_LOCAL_MOBILE_LENGTH = 11;
const EGYPT_INTL_MOBILE_LENGTH = 12;
const EGYPT_INTL_MOBILE_LENGTH_WITH_EXIT = 14;
const EGYPT_MOBILE_LENGTH_WITHOUT_LEADING_ZERO = 10;
const NORMALIZED_DATE_MIN_YEAR = 1900;
const NORMALIZED_DATE_MAX_YEAR = 2100;

function normalizeLocalizedDigits(string $value): string
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
        '۰' => '0',
        '۱' => '1',
        '۲' => '2',
        '۳' => '3',
        '۴' => '4',
        '۵' => '5',
        '۶' => '6',
        '۷' => '7',
        '۸' => '8',
        '۹' => '9',
    ]);
}

function normalizePhoneForStorage($phone): string
{
    $digits = preg_replace('/\D+/', '', normalizeLocalizedDigits(trim((string)$phone)));

    if ($digits === '') {
        return '';
    }

    // نحافظ على نمط أرقام الموبايل المصري داخل النظام: 01XXXXXXXXX.
    if (strpos($digits, EGYPT_COUNTRY_CODE_WITH_EXIT) === 0 && strlen($digits) === EGYPT_INTL_MOBILE_LENGTH_WITH_EXIT) {
        $digits = '0' . substr($digits, 4);
    } elseif (strpos($digits, EGYPT_COUNTRY_CODE) === 0 && strlen($digits) === EGYPT_INTL_MOBILE_LENGTH) {
        $digits = '0' . substr($digits, 2);
    } elseif (strlen($digits) === EGYPT_MOBILE_LENGTH_WITHOUT_LEADING_ZERO && strpos($digits, '1') === 0) {
        $digits = '0' . $digits;
    }

    return $digits;
}

function normalizeFlexibleDateInput($value): ?string
{
    $value = trim(normalizeLocalizedDigits((string)$value));
    if ($value === '') {
        return null;
    }

    $value = str_replace('\\', '/', $value);
    $value = preg_replace('/\s*([\/-])\s*/', '$1', $value);

    if (preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/', $value, $matches)) {
        $year = (int)$matches[1];
        $month = (int)$matches[2];
        $day = (int)$matches[3];
    } elseif (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $value, $matches)) {
        // عند استخدام / أو - بصيغة قصيرة نعتمد الترتيب المحلي: يوم/شهر/سنة.
        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $year = (int)$matches[3];
    } else {
        return null;
    }

    if (
        $year < NORMALIZED_DATE_MIN_YEAR
        || $year > NORMALIZED_DATE_MAX_YEAR
        || $month < 1
        || $month > 12
        || $day < 1
        || $day > 31
        || !checkdate($month, $day, $year)
    ) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $year, $month, $day);
}

function normalizeMemberBarcodeForStorage($barcode): string
{
    return trim(normalizeLocalizedDigits((string)$barcode));
}

function normalizeGenderForStorage($gender): string
{
    $normalizedGender = trim((string)$gender);

    if (in_array($normalizedGender, ['أنثى', 'انثى', 'انثي', 'أنثي'], true)) {
        return 'أنثى';
    }

    return $normalizedGender;
}

function getExistingMemberBarcodeMap(PDO $pdo, ?int $excludeMemberId = null): array
{
    $sql = "
        SELECT id, barcode
        FROM members
        WHERE barcode IS NOT NULL
          AND barcode <> ''
    ";
    $params = [];

    if ($excludeMemberId !== null) {
        $sql .= " AND id <> :exclude_member_id";
        $params[':exclude_member_id'] = $excludeMemberId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $barcodes = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $normalizedBarcode = normalizeMemberBarcodeForStorage($row['barcode'] ?? '');
        if ($normalizedBarcode !== '') {
            $barcodes[$normalizedBarcode] = (int)($row['id'] ?? 0);
        }
    }

    return $barcodes;
}

function memberBarcodeExists(PDO $pdo, string $barcode, ?int $excludeMemberId = null): bool
{
    $normalizedBarcode = normalizeMemberBarcodeForStorage($barcode);
    if ($normalizedBarcode === '') {
        return false;
    }

    return array_key_exists($normalizedBarcode, getExistingMemberBarcodeMap($pdo, $excludeMemberId));
}

function acquireMemberBarcodeLock(PDO $pdo): void
{
    $stmt = $pdo->query("SELECT GET_LOCK('members_barcode_sequence', 10) AS barcode_lock");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ((int)($row['barcode_lock'] ?? 0) !== 1) {
        throw new RuntimeException('تعذر حجز تسلسل الباركود مؤقتاً بسبب إضافة أو تعديل مشترك آخر في نفس الوقت. حاول مرة أخرى بعد ثوانٍ.');
    }
}

function releaseMemberBarcodeLock(PDO $pdo): void
{
    try {
        $pdo->query("SELECT RELEASE_LOCK('members_barcode_sequence')");
    } catch (Exception $e) {
        error_log('Failed to release members barcode sequence lock: ' . $e->getMessage());
    }
}
