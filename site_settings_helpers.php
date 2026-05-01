<?php

function ensureExtendedSiteSettingsSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `site_settings` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `site_name` varchar(255) NOT NULL,
            `logo_path` varchar(255) DEFAULT NULL,
            `receipt_paper_width_mm` int(11) DEFAULT NULL,
            `receipt_page_margin_mm` int(11) DEFAULT NULL,
            `receipt_footer_text` text DEFAULT NULL,
            `transfer_numbers_json` longtext DEFAULT NULL,
            `work_schedules_json` longtext DEFAULT NULL,
            `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $columns = [
        'receipt_paper_width_mm' => "ALTER TABLE `site_settings` ADD COLUMN `receipt_paper_width_mm` int(11) DEFAULT NULL AFTER `logo_path`",
        'receipt_page_margin_mm' => "ALTER TABLE `site_settings` ADD COLUMN `receipt_page_margin_mm` int(11) DEFAULT NULL AFTER `receipt_paper_width_mm`",
        'receipt_footer_text'    => "ALTER TABLE `site_settings` ADD COLUMN `receipt_footer_text` text DEFAULT NULL AFTER `receipt_page_margin_mm`",
        'transfer_numbers_json'  => "ALTER TABLE `site_settings` ADD COLUMN `transfer_numbers_json` longtext DEFAULT NULL AFTER `receipt_footer_text`",
        'work_schedules_json'    => "ALTER TABLE `site_settings` ADD COLUMN `work_schedules_json` longtext DEFAULT NULL AFTER `transfer_numbers_json`",
    ];

    foreach ($columns as $columnName => $sql) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `site_settings` LIKE :column_name");
        $stmt->execute([':column_name' => $columnName]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec($sql);
        }
    }

    $schemaReady = true;
}

function getFirstSiteSettingsId(PDO $pdo): ?int
{
    $stmt = $pdo->query("SELECT id FROM site_settings ORDER BY id ASC LIMIT 1");
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$row) {
        return null;
    }

    return (int)$row['id'];
}

function getSiteTransferTypeOptions(): array
{
    return [
        'wallet' => 'رقم محفظة',
        'instapay' => 'انستا باي',
    ];
}

function getSiteScheduleAudienceOptions(): array
{
    return [
        'men' => 'رجال فقط',
        'women' => 'سيدات فقط',
        'all' => 'رجال وسيدات',
    ];
}

function decodeSiteSettingsJsonList($value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return [];
    }

    return $decoded;
}
