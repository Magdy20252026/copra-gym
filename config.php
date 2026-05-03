<?php
if (!function_exists('appTimezoneName')) {
    function appTimezoneName(): string
    {
        return 'Africa/Cairo';
    }
}

if (!function_exists('appMysqlSessionTimeZoneOffset')) {
    function appMysqlSessionTimeZoneOffset(): string
    {
        $timezone = new DateTimeZone(appTimezoneName());
        $offsetInSeconds = $timezone->getOffset(new DateTimeImmutable('now', $timezone));
        $sign = $offsetInSeconds < 0 ? '-' : '+';
        $offsetInSeconds = abs($offsetInSeconds);
        $hours = intdiv($offsetInSeconds, 3600);
        $minutes = intdiv($offsetInSeconds % 3600, 60);

        return sprintf('%s%02d:%02d', $sign, $hours, $minutes);
    }
}

date_default_timezone_set(appTimezoneName());

require_once __DIR__ . '/datetime_format_helpers.php';
require_once __DIR__ . '/branches_helpers.php';

$host     = "sql202.infinityfree.com";
$dbname   = "if0_41408267_club_01";
$dbUsername = "if0_41408267";
$password = "U1MRUeEqZsu6";
$port     = 3306;

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdo = new BranchAwarePDO($dsn, $dbUsername, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("SET time_zone = " . $pdo->quote(appMysqlSessionTimeZoneOffset()));
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

require_once __DIR__ . '/single_session_helpers.php';
ensureBranchesSchema($pdo);
ensureUserBranchesSchema($pdo);
ensureBranchScopedTablesSchema($pdo);
ensureSingleSessionSchema($pdo);

function ensureSubscriptionCategorySchema(PDO $pdo): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `subscriptions` LIKE :column_name");
        $stmt->execute([':column_name' => 'subscription_category']);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("
                ALTER TABLE `subscriptions`
                ADD COLUMN `subscription_category` varchar(255) DEFAULT NULL COMMENT 'تصنيف الاشتراك'
                AFTER `name`
            ");
        }
    } catch (Exception $e) {
        error_log('Subscription category schema migration skipped: ' . $e->getMessage());
    }

    $schemaReady = true;
}

function getSubscriptionCategoryOptions(PDO $pdo): array
{
    ensureSubscriptionCategorySchema($pdo);

    try {
        $stmt = $pdo->query("
            SELECT DISTINCT TRIM(subscription_category) AS subscription_category
            FROM subscriptions
            WHERE subscription_category IS NOT NULL
              AND TRIM(subscription_category) <> ''
            ORDER BY TRIM(subscription_category) ASC
        ");

        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    } catch (Exception $e) {
        return [];
    }
}
?>
