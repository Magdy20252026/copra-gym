<?php

if (!function_exists('appArabicMeridiem')) {
    function appArabicMeridiem(string $meridiem): string
    {
        return strtoupper($meridiem) === 'PM' ? 'م' : 'ص';
    }
}

if (!function_exists('formatAppTime12Hour')) {
    function formatAppTime12Hour($timeValue, string $fallback = '—', bool $withSeconds = false): string
    {
        $timeText = trim((string)$timeValue);
        if ($timeText === '') {
            return $fallback;
        }

        $timezone = new DateTimeZone(appTimezoneName());
        $formats = ['H:i:s', 'H:i'];
        $dateTime = null;

        foreach ($formats as $format) {
            $candidate = DateTimeImmutable::createFromFormat('!' . $format, $timeText, $timezone);
            if ($candidate instanceof DateTimeImmutable) {
                $dateTime = $candidate;
                break;
            }
        }

        if (!$dateTime) {
            $timestamp = strtotime($timeText);
            if ($timestamp === false) {
                return $timeText;
            }

            $dateTime = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
        }

        $timeFormat = $withSeconds ? 'h:i:s' : 'h:i';

        return $dateTime->format($timeFormat) . ' ' . appArabicMeridiem($dateTime->format('A'));
    }
}

if (!function_exists('formatAppDateTime12Hour')) {
    function formatAppDateTime12Hour($dateTimeValue, string $fallback = '—', bool $withSeconds = true): string
    {
        $dateTimeText = trim((string)$dateTimeValue);
        if ($dateTimeText === '') {
            return $fallback;
        }

        $timestamp = strtotime($dateTimeText);
        if ($timestamp === false) {
            return $dateTimeText;
        }

        $timeFormat = $withSeconds ? 'h:i:s' : 'h:i';

        return date('Y-m-d', $timestamp) . ' ' . date($timeFormat, $timestamp) . ' ' . appArabicMeridiem(date('A', $timestamp));
    }
}
