<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'single_session_helpers.php';
require_once 'input_normalization_helpers.php';

ensureSingleSessionSchema($pdo);

function ensureAttendanceMembersSchema(PDO $pdo)
{
    static $schemaReady = false;
    if ($schemaReady) {
        return;
    }

    $requiredColumns = [
        'photo_path' => "ALTER TABLE `members` ADD COLUMN `photo_path` varchar(255) DEFAULT NULL",
        'spa_count' => "ALTER TABLE `members` ADD COLUMN `spa_count` int(11) NOT NULL DEFAULT 0 COMMENT 'عدد جلسات السبا'",
        'massage_count' => "ALTER TABLE `members` ADD COLUMN `massage_count` int(11) NOT NULL DEFAULT 0 COMMENT 'عدد جلسات المساج'",
        'jacuzzi_count' => "ALTER TABLE `members` ADD COLUMN `jacuzzi_count` int(11) NOT NULL DEFAULT 0 COMMENT 'عدد جلسات الجاكوزي'",
    ];

    foreach ($requiredColumns as $columnName => $alterSql) {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `members` LIKE :column_name");
            $stmt->execute([':column_name' => $columnName]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $pdo->exec($alterSql);
            }
        } catch (Exception $e) {
            error_log('Attendance members schema migration skipped for ' . $columnName . ': ' . $e->getMessage());
        }
    }

    $schemaReady = true;
}

ensureAttendanceMembersSchema($pdo);

/**
 * Builds the local attendance day boundaries used by same-day attendance checks and listings.
 *
 * @param DateTimeImmutable|null $reference Optional reference time; when omitted the current app time is used.
 * @return array{date:string,start:string,end:string} The local calendar day and its start/end timestamps.
 */
function getAttendanceDayRange(?DateTimeImmutable $reference = null): array
{
    $reference = $reference ?? new DateTimeImmutable('now', new DateTimeZone(appTimezoneName()));
    $dayStart = $reference->setTime(0, 0, 0);
    $dayEnd = $reference->setTime(23, 59, 59);

    return [
        'date' => $dayStart->format('Y-m-d'),
        'start' => $dayStart->format('Y-m-d H:i:s'),
        'end' => $dayEnd->format('Y-m-d H:i:s'),
    ];
}

$siteName = "Gym System";
$gymLogoPath = null;

try {
    $stmt = $pdo->query("SELECT site_name, logo_path FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
        $gymLogoPath = $row['logo_path'] ?? null;
    }
} catch (Exception $e) {}

$username  = $_SESSION['username'] ?? '';
$userId    = $_SESSION['user_id'] ?? 0;
$role      = $_SESSION['role'] ?? '';
$isManager = ($role === 'مدير' || $role === 'مشرف');

$errors  = [];
$success = "";
$singleSessionForm = [
    'option_id' => 0,
    'name'      => '',
    'phone'     => '',
    'paid'      => '',
];

// دالة اختيار صورة العرض (صورة المشترك -> وإلا لوجو الجيم -> وإلا null)
function memberDisplayImage($memberPhotoPath, $gymLogoPath) {
    if (!empty($memberPhotoPath)) return $memberPhotoPath;
    if (!empty($gymLogoPath)) return $gymLogoPath;
    return null;
}

$singleSessionOptions = [];
$singleSessionOptionsById = [];
try {
    $singleSessionOptions = getSingleSessionOptions($pdo);
    foreach ($singleSessionOptions as $singleSessionRow) {
        $singleSessionOptionsById[(int)$singleSessionRow['id']] = $singleSessionRow;
    }
} catch (Exception $e) {}

$singleSessionSubmitAttrs = !$singleSessionOptions
    ? 'disabled aria-describedby="single_session_option_help" aria-label="لا يمكن تسجيل حصة واحدة لأن قائمة التمرينات فارغة"'
    : '';

$singleSessionTypeValues = getSingleSessionAttendanceTypes();
$singleSessionTypeSql = buildSqlQuotedStringList($singleSessionTypeValues);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isManager) {
    $action = $_POST['action'] ?? '';

    // حضور مشترك عادي بالباركود
    if ($action === 'attendance_member') {
        $barcode    = normalizeMemberBarcodeForStorage($_POST['barcode'] ?? '');
        $useInvite  = isset($_POST['use_invite']) ? (int)$_POST['use_invite'] : 0;
        $guestName  = trim($_POST['guest_name'] ?? '');
        $guestPhone = normalizePhoneForStorage($_POST['guest_phone'] ?? '');

        if ($barcode === '') {
            $errors[] = "من فضلك أدخل الباركود.";
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT id, name, phone, barcode, status, sessions_remaining, invites
                    FROM members
                    WHERE barcode = :bc
                    LIMIT 1
                ");
                $stmt->execute([':bc' => $barcode]);
                $member = $stmt->fetch();

                if (!$member) {
                    $errors[] = "لم يتم العثور على مشترك بهذا الباركود.";
                } else {
                    $memberName = trim((string)($member['name'] ?? ''));
                    $memberPhone = normalizePhoneForStorage($member['phone'] ?? '');
                    $memberBarcode = normalizeMemberBarcodeForStorage($member['barcode'] ?? '');
                    $attendanceBarcode = $memberBarcode !== '' ? $memberBarcode : $barcode;

                    if ($memberName === '') {
                        $errors[] = "بيانات المشترك غير مكتملة: الاسم غير مسجل في ملف المشترك.";
                    } elseif (in_array((string)($member['status'] ?? ''), ['موقّف', 'مجمد'], true)) {
                        $errors[] = "اشتراك هذا المشترك في حالة إيقاف مؤقت (Freeze)، لا يمكن تسجيل الحضور الآن.";
                    } else {
                        $memberId = (int)$member['id'];
                        $attendanceDayRange = getAttendanceDayRange();

                        // تحقق وجود حضور سابق لهذا العضو اليوم
                        $stmt = $pdo->prepare("
                            SELECT id,type FROM attendance
                            WHERE member_id = :mid AND created_at BETWEEN :today_start AND :today_end
                            LIMIT 1
                        ");
                        $stmt->execute([
                            ':mid' => $memberId,
                            ':today_start' => $attendanceDayRange['start'],
                            ':today_end' => $attendanceDayRange['end']
                        ]);
                        $attendanceRow = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($attendanceRow && $useInvite === 0) {
                            $errors[] = "تم تسجيل حضور هذا المشترك اليوم بالفعل ولا يمكن تسجيله مرة أخرى إلا إذا استخدمت دعوة.";
                        } else {
                            if ($useInvite === 1) {
                                if ($member['invites'] <= 0) {
                                    $errors[] = "لا يوجد رصيد دعوات لهذا المشترك.";
                                } elseif ($guestName === '' || $guestPhone === '') {
                                    $errors[] = "من فضلك أدخل اسم المدعو ورقم هاتفه لاستخدام الدعوة.";
                                } else {
                                    $pdo->beginTransaction();

                                    $stmt = $pdo->prepare("
                                        UPDATE members
                                        SET invites = invites - 1
                                        WHERE id = :id AND invites > 0
                                    ");
                                    $stmt->execute([':id' => $memberId]);

                                    if ($stmt->rowCount() === 0) {
                                        $pdo->rollBack();
                                        $errors[] = "تعذر خصم الدعوة، ربما لا يوجد رصيد دعوات كافٍ.";
                                    } else {
                                        $stmt = $pdo->prepare("
                                            INSERT INTO attendance (member_id, type, name, phone, barcode, is_guest, notes, single_paid, created_at)
                                            VALUES (:mid, 'مدعو', :n, :ph, :bc, 1, 'حضور باستخدام دعوة', 0, NOW())
                                        ");
                                        $stmt->execute([
                                            ':mid' => $memberId,
                                            ':n'   => $guestName,
                                            ':ph'  => $guestPhone,
                                            ':bc'  => $attendanceBarcode,
                                        ]);

                                        $pdo->commit();
                                        $success = "تم تسجيل حضور المدعو باستخدام دعوة من المشترك بنجاح.";
                                    }
                                }
                            } else {
                                if ($member['sessions_remaining'] <= 0) {
                                    $errors[] = "لا يوجد رصيد تمرينات متبقي لهذا المشترك.";
                                } elseif ($member['status'] !== 'مستمر') {
                                    $errors[] = "اشتراك هذا المشترك غير مستمر، لا يمكن تسجيل الحضور.";
                                } else {
                                    if ($attendanceRow) {
                                        $errors[] = "تم تسجيل حضور هذا المشترك اليوم بالفعل.";
                                    } else {
                                        $pdo->beginTransaction();

                                        $stmt = $pdo->prepare("
                                            UPDATE members
                                            SET sessions_remaining = sessions_remaining - 1
                                            WHERE id = :id AND sessions_remaining > 0
                                        ");
                                        $stmt->execute([':id' => $memberId]);

                                        if ($stmt->rowCount() === 0) {
                                            $pdo->rollBack();
                                            $errors[] = "تعذر خصم التمرينة، ربما لا يوجد رصيد تمرينات كافٍ.";
                                        } else {
                                            $stmt = $pdo->prepare("
                                                INSERT INTO attendance (member_id, type, name, phone, barcode, is_guest, notes, single_paid, created_at)
                                                VALUES (:mid, 'مشترك', :n, :ph, :bc, 0, NULL, 0, NOW())
                                            ");
                                            $stmt->execute([
                                                ':mid' => $memberId,
                                                ':n'   => $memberName,
                                                ':ph'  => $memberPhone,
                                                ':bc'  => $attendanceBarcode,
                                            ]);

                                            $pdo->commit();
                                            $success = "تم تسجيل حضور المشترك وخصم تمرينة واحدة بنجاح.";
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "حدث خطأ أثناء تسجيل الحضور.";
            }
        }
    }

    // تسجيل مشترك حصة واحدة
    if ($action === 'single_session') {
        $singleSessionOptionId = (int)($_POST['single_session_option_id'] ?? 0);
        $name                  = trim($_POST['single_name'] ?? '');
        $phone                 = normalizePhoneForStorage($_POST['single_phone'] ?? '');
        $paid                  = (float)($_POST['single_paid'] ?? 0);
        $selectedSession       = $singleSessionOptionsById[$singleSessionOptionId] ?? null;
        $singleSessionForm = [
            'option_id' => $singleSessionOptionId,
            'name'      => $name,
            'phone'     => $phone,
            'paid'      => ($_POST['single_paid'] ?? ''),
        ];

        if (!$selectedSession) {
            $errors[] = "من فضلك اختر التمرينة.";
        } elseif ($name === '' || $phone === '') {
            $errors[] = "من فضلك أدخل اسم ورقم هاتف المشترك للحصة الواحدة.";
        } elseif ($paid <= 0) {
            $errors[] = "المبلغ المدفوع يجب أن يكون أكبر من صفر.";
        } elseif ((float)$selectedSession['price'] <= 0) {
            $errors[] = "سعر التمرينة المختارة غير مضبوط. من فضلك حدده أولاً.";
        } else {
            try {
                $oldDebt = 0.0;

                $stmt = $pdo->prepare("
                    SELECT notes, single_paid FROM attendance
                    WHERE phone = :ph AND type IN ($singleSessionTypeSql)
                    ORDER BY id DESC LIMIT 1
                ");
                $stmt->execute([':ph' => $phone]);
                if ($row = $stmt->fetch()) {
                    if (preg_match('/المتبقي=([0-9.]+)/u', $row['notes'], $m)) {
                        $oldDebt = (float)$m[1];
                    }
                }

                $sessionName = trim((string)$selectedSession['session_name']);
                $totalPrice = (float)$selectedSession['price'];
                $required   = $totalPrice + $oldDebt;

                if ($oldDebt > 0 && $paid < $required) {
                    $errors[] = "تنبيه: يوجد مبلغ متبقي سابق قدره {$oldDebt}. يجب سداد المبلغ القديم + الجديد ({$required}) قبل تسجيل الحضور.";
                }

                if (empty($errors)) {
                    $remaining = max(0, $required - $paid);
                    $notes = "حصة واحدة: التمرينة={$sessionName}, السعر={$totalPrice}, المتبقي_قديم={$oldDebt}, المدفوع={$paid}, المتبقي={$remaining}";

                    $stmt = $pdo->prepare("
                        INSERT INTO attendance (
                            member_id, type, name, phone, barcode, is_guest, notes, single_paid,
                            single_session_price_id, single_session_name, single_session_price, created_at
                        )
                        VALUES (
                            NULL, 'حصة_واحدة', :n, :ph, NULL, 0, :nt, :paid,
                            :session_id, :session_name, :session_price, NOW()
                        )
                    ");
                    $stmt->execute([
                        ':n'             => $name,
                        ':ph'            => $phone,
                        ':nt'            => $notes,
                        ':paid'          => $paid,
                        ':session_id'    => (int)$selectedSession['id'],
                        ':session_name'  => $sessionName,
                        ':session_price' => $totalPrice,
                    ]);

                    $success = "تم تسجيل حضور مشترك حصة واحدة بنجاح.";
                }
            } catch (Exception $e) {
                $errors[] = "حدث خطأ أثناء تسجيل مشترك الحصة الواحدة.";
            }
        }
    }

    // حذف حضور (مع استرجاع الخدمات اليومية إذا استخدمت)
    if ($action === 'delete_attendance' && $role === 'مدير') {
        $attId = (int)($_POST['attendance_id'] ?? 0);

        if ($attId <= 0) {
            $errors[] = "معرّف الحضور غير صحيح.";
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT id, member_id, type, single_paid, created_at
                    FROM attendance
                    WHERE id = :id
                    FOR UPDATE
                ");
                $stmt->execute([':id' => $attId]);
                $att = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$att) {
                    $pdo->rollBack();
                    $errors[] = "سجل الحضور غير موجود.";
                } else {
                    $memberId   = (int)$att['member_id'];
                    $attType    = $att['type'];
                    $createdAt  = $att['created_at'];

                    if ($attType === 'مشترك' && $memberId > 0) {
                        $stmt = $pdo->prepare("UPDATE members SET sessions_remaining = sessions_remaining + 1 WHERE id = :mid");
                        $stmt->execute([':mid' => $memberId]);
                    }

                    // استرجاع رصيد السبا/المساج/الجاكوزي المستهلك
                    $counts = ['spa'=>0, 'massage'=>0, 'jacuzzi'=>0];
                    if ($memberId > 0) {
                        $usage_date = date('Y-m-d', strtotime($createdAt));
                        $stmt = $pdo->prepare("
                            SELECT id, service_type
                            FROM member_service_usage
                            WHERE member_id = :mid AND usage_date = :ud
                        ");
                        $stmt->execute([
                            ':mid' => $memberId,
                            ':ud'  => $usage_date
                        ]);
                        $usageRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        $ids = [];
                        foreach ($usageRows as $u) {
                            $ids[] = (int)$u['id'];
                            if (isset($counts[$u['service_type']])) $counts[$u['service_type']]++;
                        }
                        if ($counts['spa'] > 0)     $pdo->prepare("UPDATE members SET spa_count = spa_count + :c WHERE id = :mid")->execute([':c'=>$counts['spa'],':mid'=>$memberId]);
                        if ($counts['massage'] > 0) $pdo->prepare("UPDATE members SET massage_count = massage_count + :c WHERE id = :mid")->execute([':c'=>$counts['massage'],':mid'=>$memberId]);
                        if ($counts['jacuzzi'] > 0) $pdo->prepare("UPDATE members SET jacuzzi_count = jacuzzi_count + :c WHERE id = :mid")->execute([':c'=>$counts['jacuzzi'],':mid'=>$memberId]);
                        if ($ids) $pdo->query("DELETE FROM member_service_usage WHERE id IN (" . implode(',', $ids) . ")");
                    }

                    $stmt = $pdo->prepare("DELETE FROM attendance WHERE id = :id");
                    $stmt->execute([':id' => $attId]);

                    $pdo->commit();
                    $success = "تم حذف سجل الحضور بنجاح." .
                        (($counts['spa']||$counts['massage']||$counts['jacuzzi']) ? "<br>تم استرجاع الجلسات المستهلكة اليوم." : "");
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = "حدث خطأ أثناء حذف الحضور.";
            }
        }
    }

    // خصم سبا/مساج/جاكوزي مع تسجيل الاستخدام
    if (in_array($action, ['deduct_spa', 'deduct_massage', 'deduct_jacuzzi']) && $isManager) {
        $memberId = (int)($_POST['member_id'] ?? 0);
        if ($memberId <= 0) {
            $errors[] = "معرّف المشترك غير صحيح.";
        } else {
            try {
                $niceText = '';
                $col = '';
                $serviceType = '';
                if ($action === "deduct_spa") {
                    $col = 'spa_count';
                    $niceText = 'سبا';
                    $serviceType = 'spa';
                } elseif ($action === "deduct_massage") {
                    $col = 'massage_count';
                    $niceText = 'جلسة مساج';
                    $serviceType = 'massage';
                } elseif ($action === "deduct_jacuzzi") {
                    $col = 'jacuzzi_count';
                    $niceText = 'جلسة جاكوزي';
                    $serviceType = 'jacuzzi';
                }
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT $col,name FROM members WHERE id = :id FOR UPDATE");
                $stmt->execute([':id' => $memberId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    $pdo->rollBack();
                    $errors[] = "المشترك غير موجود.";
                } elseif ((int)$row[$col] <= 0) {
                    $pdo->rollBack();
                    $errors[] = "لا يوجد رصيد " . $niceText . " متبقي لهذا المشترك.";
                } else {
                    $stmt = $pdo->prepare("UPDATE members SET $col = $col-1 WHERE id = :id AND $col>0");
                    $stmt->execute([':id' => $memberId]);

                    $stmt = $pdo->prepare("
                        INSERT INTO member_service_usage
                        (member_id, service_type, usage_date, created_by)
                        VALUES (:mid, :stype, :udate, :uid)
                    ");
                    $stmt->execute([
                        ':mid' => $memberId,
                        ':stype' => $serviceType,
                        ':udate' => date('Y-m-d'),
                        ':uid' => $userId
                    ]);

                    $pdo->commit();
                    $success = "تم خصم " . $niceText . " من رصيد المشترك (" . htmlspecialchars($row['name']) . ") بنجاح.";
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = "حدث خطأ أثناء الخصم.";
            }
        }
    }
}

// جلب جدول حضور اليوم مع كل بيانات المشتركين
$attendanceDayRange = getAttendanceDayRange();
$today = $attendanceDayRange['date'];
$todayStart = $attendanceDayRange['start'];
$todayEnd   = $attendanceDayRange['end'];
$attendanceList = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.member_id,
            a.type,
            a.name          AS att_name,
            COALESCE(NULLIF(TRIM(a.name), ''), NULLIF(TRIM(a.single_session_name), ''), m_by_id.name, m_by_barcode.name) AS display_name,
            a.phone         AS att_phone,
            a.barcode       AS att_barcode,
            a.is_guest,
            a.notes,
            a.created_at,

            COALESCE(m_by_id.name, m_by_barcode.name) AS m_name,
            COALESCE(m_by_id.phone, m_by_barcode.phone) AS m_phone,
            COALESCE(m_by_id.barcode, m_by_barcode.barcode) AS m_barcode,
            COALESCE(m_by_id.age, m_by_barcode.age) AS m_age,
            COALESCE(m_by_id.gender, m_by_barcode.gender) AS m_gender,
            COALESCE(m_by_id.address, m_by_barcode.address) AS m_address,
            COALESCE(m_by_id.days, m_by_barcode.days) AS m_days,
            COALESCE(m_by_id.sessions, m_by_barcode.sessions) AS m_sessions,
            COALESCE(m_by_id.sessions_remaining, m_by_barcode.sessions_remaining) AS m_sessions_remaining,
            COALESCE(m_by_id.invites, m_by_barcode.invites) AS m_invites,
            COALESCE(m_by_id.freeze_days, m_by_barcode.freeze_days) AS m_freeze_days,
            COALESCE(m_by_id.subscription_amount, m_by_barcode.subscription_amount) AS m_subscription_amount,
            COALESCE(m_by_id.paid_amount, m_by_barcode.paid_amount) AS m_paid_amount,
            COALESCE(m_by_id.remaining_amount, m_by_barcode.remaining_amount) AS m_remaining_amount,
            COALESCE(m_by_id.start_date, m_by_barcode.start_date) AS m_start_date,
            COALESCE(m_by_id.end_date, m_by_barcode.end_date) AS m_end_date,
            COALESCE(m_by_id.status, m_by_barcode.status) AS m_status,
            COALESCE(m_by_id.photo_path, m_by_barcode.photo_path) AS m_photo_path,
            s.name          AS m_subscription_name,
            COALESCE(m_by_id.spa_count, m_by_barcode.spa_count) AS m_spa_count,
            COALESCE(m_by_id.massage_count, m_by_barcode.massage_count) AS m_massage_count,
            COALESCE(m_by_id.jacuzzi_count, m_by_barcode.jacuzzi_count) AS m_jacuzzi_count
        FROM attendance a
        LEFT JOIN members m_by_id ON m_by_id.id = a.member_id
        LEFT JOIN members m_by_barcode
            ON m_by_id.id IS NULL
           AND a.barcode IS NOT NULL
           AND a.barcode <> ''
           AND m_by_barcode.barcode = a.barcode
        LEFT JOIN subscriptions s
            ON s.id = COALESCE(m_by_id.subscription_id, m_by_barcode.subscription_id)
        WHERE a.created_at BETWEEN :today_start AND :today_end
        ORDER BY a.id DESC
    ");
    $stmt->execute([
        ':today_start' => $todayStart,
        ':today_end' => $todayEnd
    ]);
    $attendanceList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('Attendance list query failed: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>حضور المشتركين - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        :root {
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --primary: #22c55e;
            --primary-soft: rgba(34,197,94,0.15);
            --danger: #ef4444;
            --border: #e5e7eb;
            --input-bg: #f9fafb;
        }
        body.dark {
            --bg: #020617;
            --card-bg: #020617;
            --text-main: #ffffff;
            --text-muted: #e5e7eb;
            --primary: #38bdf8;
            --primary-soft: rgba(56,189,248,0.25);
            --danger: #fb7185;
            --border: #1f2937;
            --input-bg: #020617;
        }
        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--text-main);
            font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            font-weight: 900;
            font-size: 18px;
        }
        .page { max-width: 1400px; margin: 30px auto 40px; padding: 0 20px; }
        .header-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; }
        .title-main { font-size:30px; font-weight:900; }
        .back-button {
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            padding:12px 24px;border-radius:999px;border:none;cursor:pointer;
            font-size:18px;font-weight:900;
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 18px 40px rgba(79,70,229,0.55);text-decoration:none;
        }
        .back-button:hover { filter:brightness(1.05); }
        .card {
            background:var(--card-bg);border-radius:26px;padding:22px 24px 24px;
            box-shadow:0 24px 60px rgba(15,23,42,0.22),0 0 0 1px rgba(255,255,255,0.6);
            margin-bottom:20px;
        }
        .theme-toggle{display:flex;justify-content:flex-end;margin-bottom:14px;}
        .theme-switch{
            position:relative;width:78px;height:36px;border-radius:999px;
            background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);
            cursor:pointer;display:flex;align-items:center;justify-content:space-between;
            padding:0 8px;font-size:17px;color:#6b7280;font-weight:900;
        }
        .theme-switch span{z-index:2;user-select:none;}
        .theme-thumb{
            position:absolute;top:4px;right:4px;width:28px;height:28px;border-radius:999px;
            background:#facc15;box-shadow:0 4px 10px rgba(250,204,21,0.7);
            display:flex;align-items:center;justify-content:center;font-size:17px;
            transition:transform .25s ease,background .25s ease,box-shadow .25s.ease;
        }
        body.dark .theme-switch{background:#020617;box-shadow:inset 0 0 0 1pxrgba(30,64,175,0.9);color:#e5e7eb;}
        body.dark .theme-thumb{transform:translateX(-38px);background:#0f172a;box-shadow:0 4px 12px rgba(15,23,42,0.9);}
        .alert{padding:12px 14px;border-radius:14px;font-size:18px;margin-bottom:12px;font-weight:900;}
        .alert-error{background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.9);color:var(--danger);}
        .alert-success{background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.9);color:var(--primary);}
        .row { display:flex;flex-wrap:wrap;gap:18px; }
        .col-half { flex:1 1 420px; }
        .field{display:flex;flex-direction:column;gap:6px;margin-bottom:10px;}
        .field label{font-size:17px;color:var(--text-muted);font-weight:900;}
        input[type="text"],input[type="number"],input[type="tel"],select{
            width:100%;padding:11px 15px;border-radius:999px;border:1px solid var(--border);
            background:var(--input-bg);font-size:18px;font-weight:800;color:var(--text-main);
        }
        input:focus,select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 2px var(--primary-soft);}
        .btn-main{
            border-radius:999px;padding:11px 22px;border:none;cursor:pointer;font-size:18px;
            font-weight:900;display:inline-flex;align-items:center;justify-content:center;gap:8px;
            background:linear-gradient(90deg,#22c55e,#16a34a);color:#f9fafb;
            box-shadow:0 20px 44px rgba(22,163,74,0.7);text-decoration:none;
        }
        .btn-main:hover{filter:brightness(1.06);}
        .btn-secondary{
            border-radius:999px;padding:10px 18px;border:none;cursor:pointer;font-size:16px;
            font-weight:900;background:#e5e7eb;color:#374151;
        }
        body.dark .btn-secondary{background:#111827;color:#e5e7eb;}
        .table-wrapper{
            margin-top:12px;
            border-radius:24px;
            border:1px solid var(--border);
            overflow:auto;
            max-height:650px;
        }
        table{
            width:100%;
            border-collapse:collapse;
            font-size:16px;
            min-width:1300px;
        }
        thead{background:rgba(15,23,42,0.04);}
        body.dark thead{background:rgba(15,23,42,0.9);}
        th,td{
            padding:11px 13px;
            border-bottom:1px solid var(--border);
            text-align:right;
            white-space:nowrap;
        }
        th{
            font-weight:900;
            color:var(--text-muted);
            font-size:15px;
        }
        td{
            font-weight:800;
            font-size:15px;
        }
        .tag-type{
            border-radius:999px;
            padding:4px 10px;
            font-size:13px;
            font-weight:900;
            display:inline-block;
        }
        .tag-member{background:rgba(34,197,94,0.16);color:#15803d;}
        .tag-guest{background:rgba(59,130,246,0.18);color:#1d4ed8;}
        .tag-single{background:rgba(249,115,22,0.2);color:#c2410c;}
        .small-muted{font-size:14px;color:var(--text-muted);font-weight:700;}
        #cameraArea { margin-top:10px; display:none; }
        #reader { width: 100%; max-width: 380px; margin-top:8px; }
        #stopScanBtn { margin-top:8px; }
        .deduct-btn {
            border:none;padding:6px 12px;border-radius:999px;font-size:13px;margin:0 2px;cursor:pointer;color:#fff;font-weight:900;
        }
        .btn-spa     { background:#2563eb; }
        .btn-massage { background:#c026d3; }
        .btn-jacuzzi { background:#f59e0b; }
        .deduct-btn:disabled { opacity:0.55;cursor:not-allowed; }

        /* NEW: صورة المشترك في جدول الحضور مثل صفحة العملاء */
        .member-avatar {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            object-fit: cover;
            border: 1px solid var(--border);
            box-shadow: 0 10px 26px rgba(15,23,42,0.18);
            background: #fff;
            cursor: pointer;
            display: inline-block;
        }

        /* NEW: نافذة عرض الصورة بالحجم الطبيعي */
        .img-viewer-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.72);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 18px;
        }
        .img-viewer {
            background: var(--card-bg);
            border-radius: 18px;
            max-width: 95vw;
            max-height: 92vh;
            width: auto;
            box-shadow: 0 30px 90px rgba(0,0,0,0.55);
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .img-viewer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
        }
        .img-viewer-title {
            font-weight: 900;
            font-size: 16px;
            color: var(--text-main);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 70vw;
        }
        .img-viewer-close {
            border: none;
            background: transparent;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-muted);
            font-weight: 900;
            line-height: 1;
        }
        .img-viewer-body {
            background: #000;
            display: flex;
            align-items: center;
            justify-content: center;
            max-width: 95vw;
            max-height: 82vh;
        }
        .img-viewer-body img {
            display: block;
            max-width: 95vw;
            max-height: 82vh;
            width: auto;
            height: auto;
            object-fit: contain;
            background: #000;
        }
    </style>
    <script src="assets/html5-qrcode.min.js"></script>
</head>
<body>

<!-- NEW: عارض الصورة بالحجم الطبيعي -->
<div class="img-viewer-backdrop" id="imgViewerBackdrop" aria-hidden="true">
    <div class="img-viewer" role="dialog" aria-modal="true" aria-label="عارض الصورة">
        <div class="img-viewer-header">
            <div class="img-viewer-title" id="imgViewerTitle">صورة المشترك</div>
            <button type="button" class="img-viewer-close" id="imgViewerCloseBtn">×</button>
        </div>
        <div class="img-viewer-body">
            <img src="" alt="صورة بالحجم الطبيعي" id="imgViewerImg">
        </div>
    </div>
</div>

<div class="page">
    <div class="header-bar">
        <div class="title-main">حضور المشتركين</div>
        <div>
            <a href="dashboard.php" class="back-button">
                <span>📊</span>
                <span>العودة إلى لوحة التحكم</span>
            </a>
        </div>
    </div>

    <div class="card">
        <div class="theme-toggle">
            <div class="theme-switch" id="themeSwitch">
                <span>🌙</span>
                <span>☀️</span>
                <div class="theme-thumb" id="themeThumb">☀️</div>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?>
                    <div>• <?php echo htmlspecialchars($e); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo $success; ?></div>
        <?php endif; ?>

        <div class="row">
            <!-- حضور مشترك / دعوة -->
            <div class="col-half">
                <h3 style="margin:0 0 10px;font-size:20px;">تسجيل حضور مشترك بالباركود</h3>
                <form method="post" action="" autocomplete="off">
                    <input type="hidden" name="action" value="attendance_member">

                    <div class="field">
                        <label for="barcode">باركود المشترك</label>
                        <input type="text" id="barcode" name="barcode"
                               placeholder="اكتب أو امسح الباركود ثم اضغط Enter"
                               autofocus>
                        <div class="small-muted"></div>
                    </div>

                    <div class="field">
                        <label>
                            <input type="checkbox" name="use_invite" value="1" id="useInviteCheckbox">
                            استخدام دعوة من رصيد المشترك (تسجيل مدعو)
                        </label>
                    </div>

                    <div id="guestFields" style="display:none;">
                        <div class="field">
                            <label for="guest_name">اسم المدعو</label>
                            <input type="text" id="guest_name" name="guest_name">
                        </div>
                        <div class="field">
                            <label for="guest_phone">رقم هاتف المدعو</label>
                            <input type="tel" id="guest_phone" name="guest_phone">
                        </div>
                    </div>

                    <button type="submit" class="btn-main" style="margin-top:8px;">
                        <span>✅</span>
                        <span>تسجيل الحضور</span>
                    </button>
                    <button type="button" class="btn-secondary" id="btnOpenCamera" style="margin-right:8px;">
                        📷 مسح الباركود بالكاميرا
                    </button>

                    <div id="cameraArea">
                        <div class="small-muted">
                            وجّه الكاميرا إلى الباركود، وعند قراءة الكود سيتم تعبئة حقل الباركود تلقائياً.
                        </div>
                        <div id="reader"></div>
                        <button type="button" class="btn-secondary" id="stopScanBtn">
                            إيقاف الكاميرا
                        </button>
                    </div>
                </form>
            </div>

            <!-- مشترك حصة واحدة -->
            <div class="col-half">
                <h3 style="margin:0 0 10px;font-size:20px;">تسجيل مشترك حصة واحدة</h3>
                <form method="post" action="">
                    <input type="hidden" name="action" value="single_session">

                    <div class="field">
                        <label for="single_session_option_id">اختيار التمرينة</label>
                        <select id="single_session_option_id" name="single_session_option_id" required>
                            <option value="">اختر التمرينة</option>
                            <?php foreach ($singleSessionOptions as $singleSessionRow): ?>
                                <option
                                    value="<?php echo (int)$singleSessionRow['id']; ?>"
                                    data-price="<?php echo htmlspecialchars((string)$singleSessionRow['price']); ?>"
                                    <?php echo ($singleSessionForm['option_id'] > 0 && $singleSessionForm['option_id'] === (int)$singleSessionRow['id']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($singleSessionRow['session_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="small-muted" id="single_session_option_help">
                            <?php if ($singleSessionOptions): ?>
                                اختر التمرينة ليظهر سعرها تلقائيًا.
                            <?php else: ?>
                                لا توجد تمرينات مسجلة. من فضلك أضف تمرينة أولاً من صفحة تمرينات الحصة الواحدة.
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="field">
                        <label for="single_session_price_display">سعر التمرينة المختارة</label>
                        <input type="number" step="0.01" id="single_session_price_display" value="0.00" readonly aria-label="سعر التمرينة المختارة" aria-describedby="single_session_option_help">
                    </div>

                    <div class="field">
                        <label for="single_name">اسم المشترك</label>
                        <input type="text" id="single_name" name="single_name" value="<?php echo htmlspecialchars($singleSessionForm['name']); ?>" required>
                    </div>

                    <div class="field">
                        <label for="single_phone">رقم الهاتف</label>
                        <input type="tel" id="single_phone" name="single_phone" value="<?php echo htmlspecialchars($singleSessionForm['phone']); ?>" required>
                    </div>

                    <div class="field" id="single_debt_alert" style="display:none;">
                        <div class="alert alert-error" style="margin-bottom:0;">
                            <span id="single_debt_text"></span>
                        </div>
                    </div>

                    <div class="field">
                        <label for="single_paid">المبلغ المدفوع</label>
                        <input type="number" step="0.01" id="single_paid" name="single_paid" min="0.01" value="<?php echo htmlspecialchars((string)$singleSessionForm['paid']); ?>" required>
                    </div>

                    <button type="submit" class="btn-main" style="margin-top:8px;background:linear-gradient(90deg,#f97316,#ea580c);box-shadow:0 20px 44px rgba(234,88,12,0.7);" <?php echo $singleSessionSubmitAttrs; ?>>
                        <span>💪</span>
                        <span>تسجيل حصة واحدة</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- جدول حضور اليوم + تصدير إكسل -->
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <div style="font-size:18px;">
                سجل الحضور ليوم:
                <strong><?php echo htmlspecialchars($today); ?></strong>
            </div>
            <form method="get" action="export_attendance_excel.php" style="margin:0;">
                <input type="hidden" name="date" value="<?php echo htmlspecialchars($today); ?>">
                <button type="submit" class="btn-secondary">
                    📥 تصدير كشف الحضور (Excel)
                </button>
            </form>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>الصورة</th>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>الهاتف</th>
                    <th>الباركود</th>
                    <th>النوع</th>
                    <th>ملاحظات</th>
                    <th>وقت الحضور</th>
                    <th>السن</th>
                    <th>النوع </th>
                    <th>العنوان</th>
                    <th>اسم الاشتراك</th>
                    <th>الأيام</th>
                    <th>أيام الـ Freeze</th>
                    <th>إجمالي التمرينات</th>
                    <th>التمرينات المتبقية</th>
                    <th>الدعوات</th>
                    <th>السبا</th>
                    <th>المساج</th>
                    <th>الجاكوزي</th>
                    <th>مبلغ الاشتراك</th>
                    <th>المدفوع</th>
                    <th>المتبقي</th>
                    <th>تاريخ البداية</th>
                    <th>تاريخ النهاية</th>
                    <th>حالة الاشتراك</th>
                    <?php if ($role === 'مدير' || $role === 'مشرف'): ?>
                        <th>الإجراءات</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php
                if ($attendanceList) {
                    foreach ($attendanceList as $row) {
                        $memberId  = (int)$row['member_id'];

                        // صورة المشترك (لو موجود) وإلا لوجو الجيم (مثل العملاء) - فقط لو type=مشترك و member_id موجود
                        $imgPath = null;
                        $imgTitle = 'صورة المش��رك';
                        if (!empty($row['member_id'])) {
                            $imgPath = memberDisplayImage($row['m_photo_path'] ?? null, $gymLogoPath);
                            $imgTitle = 'صورة: ' . $row['display_name'];
                        }
                        ?>
                        <tr>
                            <td>
                                <?php if ($imgPath): ?>
                                    <img
                                        class="member-avatar js-member-avatar"
                                        src="<?php echo htmlspecialchars($imgPath); ?>"
                                        alt="صورة المشترك"
                                        data-full="<?php echo htmlspecialchars($imgPath); ?>"
                                        data-title="<?php echo htmlspecialchars($imgTitle); ?>"
                                    >
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>

                            <td><?php echo (int)$row['id']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($row['display_name'] ?? ''); ?>
                                <?php if (!empty($row['m_name']) && !empty($row['att_name']) && $row['m_name'] !== $row['att_name']): ?>
                                    <div class="small-muted">
                                        (في المشتركين: <?php echo htmlspecialchars($row['m_name']); ?>)
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($row['att_phone']); ?>
                                <?php if (!empty($row['m_phone']) && $row['m_phone'] !== $row['att_phone']): ?>
                                    <div class="small-muted">
                                        (في المشتركين: <?php echo htmlspecialchars($row['m_phone']); ?>)
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($row['att_barcode']); ?>
                                <?php if (!empty($row['m_barcode']) && $row['m_barcode'] !== $row['att_barcode']): ?>
                                    <div class="small-muted">
                                        (في المشتركين: <?php echo htmlspecialchars($row['m_barcode']); ?>)
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($row['type'] === 'مشترك'): ?>
                                    <span class="tag-type tag-member">مشترك</span>
                                <?php elseif ($row['type'] === 'مدعو'): ?>
                                    <span class="tag-type tag-guest">مدعو</span>
                                <?php else: ?>
                                    <span class="tag-type tag-single">حصة واحدة</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['notes'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars(formatAppDateTime12Hour($row['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($row['m_age'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_gender'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_address'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_subscription_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_days'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_freeze_days'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_sessions'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_sessions_remaining'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_invites'] ?? ''); ?></td>
                            <td><?php echo (int)($row['m_spa_count'] ?? 0); ?></td>
                            <td><?php echo (int)($row['m_massage_count'] ?? 0); ?></td>
                            <td><?php echo (int)($row['m_jacuzzi_count'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars($row['m_subscription_amount'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_paid_amount'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_remaining_amount'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_start_date'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_end_date'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['m_status'] ?? ''); ?></td>
                            <?php if ($role === 'مدير' || $role === 'مشرف'): ?>
                                <td>
                                    <?php if (!empty($row['member_id']) && $row['type'] === 'مشترك'): ?>
                                        <form method="post" action="" style="display:inline;margin-left:2px;">
                                            <input type="hidden" name="member_id" value="<?php echo (int)$row['member_id']; ?>">
                                            <input type="hidden" name="action" value="deduct_spa">
                                            <button type="submit" class="deduct-btn btn-spa"
                                                <?php if ((int)$row['m_spa_count'] <= 0) echo "disabled"; ?>>
                                                سبا -
                                            </button>
                                        </form>
                                        <form method="post" action="" style="display:inline;margin-left:2px;">
                                            <input type="hidden" name="member_id" value="<?php echo (int)$row['member_id']; ?>">
                                            <input type="hidden" name="action" value="deduct_massage">
                                            <button type="submit" class="deduct-btn btn-massage"
                                                <?php if ((int)$row['m_massage_count'] <= 0) echo "disabled"; ?>>
                                                مساج -
                                            </button>
                                        </form>
                                        <form method="post" action="" style="display:inline;margin-left:2px;">
                                            <input type="hidden" name="member_id" value="<?php echo (int)$row['member_id']; ?>">
                                            <input type="hidden" name="action" value="deduct_jacuzzi">
                                            <button type="submit" class="deduct-btn btn-jacuzzi"
                                                <?php if ((int)$row['m_jacuzzi_count'] <= 0) echo "disabled"; ?>>
                                                جاكوزي -
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="small-muted">غير متاح</span>
                                    <?php endif; ?>
                                    <?php if ($role === 'مدير'): ?>
                                        <form method="post" action=""
                                              onsubmit="return confirm('هل أنت متأكد من حذف هذا الحضور؟');"
                                              style="display:inline;">
                                            <input type="hidden" name="action" value="delete_attendance">
                                            <input type="hidden" name="attendance_id" value="<?php echo (int)$row['id']; ?>">
                                            <button type="submit"
                                                    style="border:none;border-radius:999px;padding:6px 12px;
                                                           background:#ef4444;color:#fff;font-weight:900;
                                                           cursor:pointer;font-size:13px;">
                                                حذف
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php
                    }
                } else {
                    ?>
                    <tr>
                        <td colspan="<?php echo ($role === 'مدير' || $role === 'مشرف') ? 27 : 26; ?>"
                            style="text-align:center;color:var(--text-muted);font-weight:800;font-size:18px;padding:18px 0;">
                            لا يوجد حضور مسجل اليوم حتى الآن.
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
    const body      = document.body;
    const switchEl  = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';

    function applyTheme(mode) {
        if (mode === 'dark') body.classList.add('dark'); else body.classList.remove('dark');
        localStorage.setItem('gymDashboardTheme', mode);
    }
    applyTheme(savedTheme);

    if (switchEl) {
        switchEl.addEventListener('click', () => {
            const isDark = body.classList.contains('dark');
            applyTheme(isDark ? 'light' : 'dark');
        });
    }

    // NEW: عارض الصورة بالحجم الطبيعي (فتح/غلق)
    const imgViewerBackdrop = document.getElementById('imgViewerBackdrop');
    const imgViewerImg      = document.getElementById('imgViewerImg');
    const imgViewerTitle    = document.getElementById('imgViewerTitle');
    const imgViewerCloseBtn = document.getElementById('imgViewerCloseBtn');

    function openImageViewer(src, titleText) {
        if (!imgViewerBackdrop || !imgViewerImg) return;
        imgViewerImg.src = src;
        if (imgViewerTitle) imgViewerTitle.textContent = titleText || 'صورة المشترك';
        imgViewerBackdrop.style.display = 'flex';
        imgViewerBackdrop.setAttribute('aria-hidden', 'false');
    }

    function closeImageViewer() {
        if (!imgViewerBackdrop || !imgViewerImg) return;
        imgViewerBackdrop.style.display = 'none';
        imgViewerBackdrop.setAttribute('aria-hidden', 'true');
        imgViewerImg.src = '';
    }

    document.addEventListener('click', function (e) {
        const t = e.target;
        if (t && t.classList && t.classList.contains('js-member-avatar')) {
            const full = t.getAttribute('data-full') || t.getAttribute('src');
            const title = t.getAttribute('data-title') || 'صورة المشترك';
            if (full) openImageViewer(full, title);
        }
    });

    if (imgViewerCloseBtn) {
        imgViewerCloseBtn.addEventListener('click', closeImageViewer);
    }
    if (imgViewerBackdrop) {
        imgViewerBackdrop.addEventListener('click', function (e) {
            if (e.target === imgViewerBackdrop) closeImageViewer();
        });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeImageViewer();
    });

    // إظهار / إخفاء حقول المدعو عند استخدام الدعوة
    const useInviteCheckbox = document.getElementById('useInviteCheckbox');
    const guestFields       = document.getElementById('guestFields');
    if (useInviteCheckbox && guestFields) {
        const toggleGuestFields = () => {
            guestFields.style.display = useInviteCheckbox.checked ? 'block' : 'none';
        };
        useInviteCheckbox.addEventListener('change', toggleGuestFields);
        toggleGuestFields();
    }

    let html5QrCode = null;
    const btnOpenCamera = document.getElementById('btnOpenCamera');
    const cameraArea    = document.getElementById('cameraArea');
    const stopScanBtn   = document.getElementById('stopScanBtn');
    const barcodeInput  = document.getElementById('barcode');
    const attendanceForm = barcodeInput ? barcodeInput.form : null;

    if (barcodeInput && attendanceForm) {
        barcodeInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                attendanceForm.submit();
            }
        });
    }

    function startScanner() {
        if (!window.Html5Qrcode) {
            alert("مكتبة قراءة الباركود بالكاميرا غير محملة. تأكد من وجود ملف html5-qrcode.min.js في مجلد assets واستدعائه بشكل صحيح.");
            return;
        }

        cameraArea.style.display = 'block';

        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("reader");
        }

        const config = {
            fps: 10,
            qrbox: { width: 250, height: 250 }
        };

        Html5Qrcode.getCameras().then(devices => {
            if (!devices || devices.length === 0) {
                alert("لم يتم العثور على أي كاميرا على هذا الجهاز.");
                return;
            }
            let cameraId = devices[0].id;
            const backCam = devices.find(d =>
                /back|rear|environment/i.test(d.label || '')
            );
            if (backCam) {
                cameraId = backCam.id;
            } else {
                if (devices.length > 1) {
                    cameraId = devices[1].id;
                }
            }
            html5QrCode.start(
                cameraId,
                config,
                (decodedText, decodedResult) => {
                    barcodeInput.value = decodedText;
                    stopScanner();
                    if (attendanceForm) {
                        attendanceForm.submit();
                    }
                },
                (errorMessage) => {
                }
            ).catch(err => {
                console.error(err);
                alert("تعذر تشغيل الكاميرا. تأكد من منح الصلاحية في المتصفح.");
            });
        }).catch(err => {
            console.error(err);
            alert("تعذر الوصول إلى الكاميرا. تأكد من منح الصلاحية في المتصفح.");
        });
    }

    function stopScanner() {
        if (html5QrCode) {
            html5QrCode.stop().then(() => {
                html5QrCode.clear();
            }).catch(err => {
                console.error(err);
            });
        }
        cameraArea.style.display = 'none';
    }

    if (btnOpenCamera) {
        btnOpenCamera.addEventListener('click', () => {
            startScanner();
        });
    }
    if (stopScanBtn) {
        stopScanBtn.addEventListener('click', () => {
            stopScanner();
        });
    }

    const singlePhoneInput = document.getElementById('single_phone');
    const singlePaidInput  = document.getElementById('single_paid');
    const singleSessionSelect = document.getElementById('single_session_option_id');
    const singleSessionPriceDisplay = document.getElementById('single_session_price_display');
    const debtAlertBox     = document.getElementById('single_debt_alert');
    const debtAlertText    = document.getElementById('single_debt_text');

    let currentOldDebt = 0;

    function fetchSingleSessionDebt(phone) {
        if (!phone || phone.trim() === '') {
            currentOldDebt = 0;
            if (debtAlertBox) debtAlertBox.style.display = 'none';
            return;
        }
        fetch('get_single_session_debt.php?phone=' + encodeURIComponent(phone))
            .then(res => res.json())
            .then(data => {
                currentOldDebt = parseFloat(data.old_debt || 0);

                if (currentOldDebt > 0) {
                    if (debtAlertBox && debtAlertText) {
                        debtAlertText.textContent =
                            "تنبيه: يوجد مبلغ متبقي سابق قدره " +
                            currentOldDebt.toFixed(2) +
                            " جنيه. من فضلك خذ هذا في الاعتبار قبل كتابة المبلغ الجديد.";
                        debtAlertBox.style.display = 'block';
                    }
                } else {
                    if (debtAlertBox) debtAlertBox.style.display = 'none';
                }
            })
            .catch(err => {
                console.error(err);
            });
    }
    if (singlePhoneInput) {
        singlePhoneInput.addEventListener('blur', function () {
            fetchSingleSessionDebt(singlePhoneInput.value);
        });
        singlePhoneInput.addEventListener('change', function () {
            fetchSingleSessionDebt(singlePhoneInput.value);
        });
    }

    function updateSingleSessionPrice() {
        if (!singleSessionSelect || !singleSessionPriceDisplay) {
            return;
        }

        const selectedOption = singleSessionSelect.options[singleSessionSelect.selectedIndex];
        const price = selectedOption ? parseFloat(selectedOption.getAttribute('data-price') || '0') : 0;
        singleSessionPriceDisplay.value = price.toFixed(2);
    }

    if (singleSessionSelect) {
        singleSessionSelect.addEventListener('change', updateSingleSessionPrice);
        updateSingleSessionPrice();
    }
</script>
</body>
</html>
