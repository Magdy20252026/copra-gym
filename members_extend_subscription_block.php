// =============================
// تمديد اشتراك (بدون تغيير تاريخ البداية) + دفع إضافي الآن (اختياري)
// + NEW: خصم التمارين المستهلكة من الاشتراك القديم
// =============================
if ($action === 'extend_subscription') {
    $memberId = (int)($_POST['member_id'] ?? 0);
    $newSubId = (int)($_POST['new_subscription_id'] ?? 0);
    $payNow   = (float)($_POST['extend_pay_now'] ?? 0);
    $notes    = trim($_POST['extend_notes'] ?? '');

    if ($memberId <= 0 || $newSubId <= 0) {
        $errors[] = "بيانات التمديد غير صحيحة.";
    } elseif ($payNow < 0) {
        $errors[] = "قيمة المبلغ المدفوع الآن لا يمكن أن تكون سالبة.";
    } else {
        try {
            $pdo->beginTransaction();

            // قفل صف العضو أثناء التمديد
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    subscription_id,
                    start_date,
                    end_date,
                    paid_amount,
                    remaining_amount,
                    subscription_amount,
                    status,
                    sessions,
                    sessions_remaining
                FROM members
                WHERE id = :id
                FOR UPDATE
            ");
            $stmt->execute([':id' => $memberId]);
            $memberRow = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$memberRow) {
                $pdo->rollBack();
                $errors[] = "المشترك غير موجود.";
            } else {
                $oldSubId          = (int)$memberRow['subscription_id'];
                $startDate         = $memberRow['start_date'];
                $oldEndDate        = $memberRow['end_date'];
                $oldPaidAmount     = (float)$memberRow['paid_amount'];
                $oldRemaining      = (float)$memberRow['remaining_amount'];
                $oldSubAmount      = (float)$memberRow['subscription_amount'];

                // NEW: حساب التمارين المستهلكة في الاشتراك القديم
                $oldTotalSessions      = (int)($memberRow['sessions'] ?? 0);
                $oldSessionsRemaining  = (int)($memberRow['sessions_remaining'] ?? 0);
                $consumedSessions      = $oldTotalSessions - $oldSessionsRemaining;
                if ($consumedSessions < 0) $consumedSessions = 0;

                if ($startDate === null || $startDate === '') {
                    $pdo->rollBack();
                    $errors[] = "لا يمكن تنفيذ التمديد لأن تاريخ بداية الاشتراك غير مسجل للمشترك.";
                } else {
                    // جلب الاشتراك الجديد
                    $stmt = $pdo->prepare("
                        SELECT
                            id,
                            name,
                            days,
                            sessions,
                            invites,
                            price_after_discount,
                            freeze_days,
                            spa_count,
                            massage_count,
                            jacuzzi_count
                        FROM subscriptions
                        WHERE id = :sid
                        LIMIT 1
                    ");
                    $stmt->execute([':sid' => $newSubId]);
                    $newSub = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$newSub) {
                        $pdo->rollBack();
                        $errors[] = "الاشتراك الجديد غير موجود.";
                    } else {
                        $newDays     = (int)$newSub['days'];
                        $newSessions = (int)$newSub['sessions'];
                        $newInvites  = (int)$newSub['invites'];
                        $newAmount   = (float)$newSub['price_after_discount'];

                        $newFreezeDays   = (int)($newSub['freeze_days'] ?? 0);
                        $newSpaCount     = (int)($newSub['spa_count'] ?? 0);
                        $newMassageCount = (int)($newSub['massage_count'] ?? 0);
                        $newJacuzziCount = (int)($newSub['jacuzzi_count'] ?? 0);

                        // حساب نهاية جديدة من نفس تاريخ البداية
                        $newEndDate = date('Y-m-d', strtotime($startDate . ' + ' . $newDays . ' days'));

                        // احتساب المدفوع الحالي + المدفوع الآن كرصيد ضمن الاشتراك الجديد
                        $newPaidAmount = $oldPaidAmount + $payNow;

                        // المتبقي الجديد
                        $newRemainingAmount = $newAmount - $newPaidAmount;

                        // لا نسمح أن يبقى المتبقي سالب، ونمنع أيضاً أن يدفع الآن أكثر من اللازم
                        if ($newRemainingAmount < 0) {
                            $pdo->rollBack();
                            $maxAllowedPayNow = $newAmount - $oldPaidAmount;
                            if ($maxAllowedPayNow < 0) $maxAllowedPayNow = 0;
                            $errors[] = "المبلغ المدفوع الآن أكبر من المطلوب. الحد الأقصى المسموح به الآن: " . number_format($maxAllowedPayNow, 2);
                        } else {
                            // NEW: خصم التمارين المستهلكة من الاشتراك الجديد
                            $newSessionsRemaining = $newSessions - $consumedSessions;
                            if ($newSessionsRemaining < 0) $newSessionsRemaining = 0;

                            // تحديث العضو (بدون تغيير start_date)
                            $stmt = $pdo->prepare("
                                UPDATE members
                                SET subscription_id = :new_sub_id,
                                    days = :new_days,
                                    sessions = :new_sessions,
                                    sessions_remaining = :new_sessions_remaining,
                                    invites = :new_invites,
                                    freeze_days = :new_freeze_days,
                                    subscription_amount = :new_amount,
                                    paid_amount = :new_paid_amount,
                                    remaining_amount = :new_remaining,
                                    end_date = :new_end_date,
                                    status = 'مستمر',
                                    spa_count = :spa,
                                    massage_count = :massage,
                                    jacuzzi_count = :jacuzzi
                                WHERE id = :mid
                            ");
                            $stmt->execute([
                                ':new_sub_id'             => $newSubId,
                                ':new_days'               => $newDays,
                                ':new_sessions'           => $newSessions,
                                ':new_sessions_remaining' => $newSessionsRemaining, // <-- NEW
                                ':new_invites'            => $newInvites,
                                ':new_freeze_days'        => $newFreezeDays,
                                ':new_amount'             => $newAmount,
                                ':new_paid_amount'        => $newPaidAmount,
                                ':new_remaining'          => $newRemainingAmount,
                                ':new_end_date'           => $newEndDate,
                                ':spa'                    => $newSpaCount,
                                ':massage'                => $newMassageCount,
                                ':jacuzzi'                => $newJacuzziCount,
                                ':mid'                    => $memberId,
                            ]);

                            // تسجيل العملية في member_extensions (لو الجدول موجود)
                            $stmt = $pdo->prepare("
                                INSERT INTO member_extensions
                                    (member_id, old_subscription_id, new_subscription_id,
                                     old_end_date, new_end_date,
                                     old_subscription_amount, new_subscription_amount,
                                     old_paid_amount, old_remaining_amount,
                                     new_paid_amount, new_remaining_amount,
                                     created_by_user_id, notes)
                                VALUES
                                    (:member_id, :old_sub, :new_sub,
                                     :old_end, :new_end,
                                     :old_amt, :new_amt,
                                     :old_paid, :old_rem,
                                     :new_paid, :new_rem,
                                     :by_user, :notes)
                            ");
                            $stmt->execute([
                                ':member_id' => $memberId,
                                ':old_sub'   => $oldSubId,
                                ':new_sub'   => $newSubId,
                                ':old_end'   => $oldEndDate,
                                ':new_end'   => $newEndDate,
                                ':old_amt'   => $oldSubAmount,
                                ':new_amt'   => $newAmount,
                                ':old_paid'  => $oldPaidAmount,
                                ':old_rem'   => $oldRemaining,
                                ':new_paid'  => $newPaidAmount,
                                ':new_rem'   => $newRemainingAmount,
                                ':by_user'   => ($userId > 0 ? $userId : null),
                                ':notes'     => ($notes !== '' ? $notes : null),
                            ]);

                            // لو تم دفع مبلغ الآن، نسجله أيضاً في partial_payments كعملية سداد
                            if ($payNow > 0) {
                                $stmt = $pdo->prepare("
                                    INSERT INTO partial_payments
                                        (member_id, paid_amount, old_remaining, new_remaining, paid_by_user_id)
                                    VALUES
                                        (:member_id, :paid_amount, :old_remaining, :new_remaining, :paid_by_user_id)
                                ");
                                $stmt->execute([
                                    ':member_id'       => $memberId,
                                    ':paid_amount'     => $payNow,
                                    ':old_remaining'   => $oldRemaining,
                                    ':new_remaining'   => $newRemainingAmount,
                                    ':paid_by_user_id' => ($userId > 0 ? $userId : 0),
                                ]);
                            }

                            $pdo->commit();

                            $success = "تم تمديد الاشتراك بنجاح بدون تغيير تاريخ البداية. "
                                     . "تم خصم التمارين المستهلكة (" . (int)$consumedSessions . ") من رصيد التمارين في الاشتراك الجديد. "
                                     . ($payNow > 0 ? "وتم تسجيل مبلغ مدفوع الآن وتحديث المتبقي." : "وتم تحديث المتبقي.");
                        }
                    }
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = "حدث خطأ أثناء عملية تمديد الاشتراك.";
        }
    }
}