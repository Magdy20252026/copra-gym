<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'config.php';
require_once 'sales_helpers.php';

ensureSalesSchema($pdo);

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$siteName = "Gym System";
try {
    $stmt = $pdo->query("SELECT site_name FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
    }
} catch (Exception $e) {}

$username = $_SESSION['username'] ?? '';
$role     = $_SESSION['role'] ?? '';
$userId   = (int)($_SESSION['user_id'] ?? 0);

if ($role !== 'مدير') {
    header("Location: dashboard.php");
    exit;
}

$errors  = [];
$records = [];

try {
    $stmt = $pdo->query("
        SELECT
            w.*,
            u.username AS closed_by_username
        FROM weekly_closings w
        LEFT JOIN users u ON u.id = w.closed_by_user_id
        ORDER BY w.closed_at DESC
    ");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = 'حدث خطأ أثناء جلب سجل التقفيلات الشهرية.';
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>سجل التقفيلات الشهرية - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        :root {
            --bg: #f3f4f6;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #6b7280;
            --primary: #2563eb;
            --danger: #ef4444;
            --border: #e5e7eb;
        }
        body.dark {
            --bg: #020617;
            --card-bg: #020617;
            --text-main: #ffffff;
            --text-muted: #e5e7eb;
            --primary: #38bdf8;
            --danger: #fb7185;
            --border: #1f2937;
        }
        body { margin:0; min-height:100vh; background:var(--bg); color:var(--text-main); font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; font-weight:900; font-size:16px;}
        .page {max-width:1100px;margin:30px auto 50px;padding:0 20px;}
        .header-bar {display:flex;justify-content:space-between;align-items:center;margin-bottom:22px;}
        .title-main { font-size:24px;font-weight:900; }
        .title-sub { margin-top:6px;font-size:14px;color:var(--text-muted);font-weight:800; }
        .back-button {
            display:inline-flex;align-items:center;justify-content:center;gap:8px;
            padding:9px 18px;border-radius:999px;border:none;cursor:pointer;
            font-size:14px;font-weight:900;
            background:linear-gradient(90deg,#6366f1,#22c55e);color:#f9fafb;
            box-shadow:0 10px 25px rgba(79,70,229,0.45);
            text-decoration:none;
        }
        .card {
            background:var(--card-bg);
            border-radius:20px;
            padding:18px 18px 20px;
            box-shadow:0 18px 50px rgba(15,23,42,0.2),
                       0 0 0 1px rgba(255,255,255,0.6);
        }
        .alert {
            padding:10px 12px;
            border-radius:12px;
            font-size:14px;
            margin-bottom:12px;
            font-weight:900;
        }
        .alert-error {
            background:rgba(239,68,68,0.08);
            border:1px solid rgba(239,68,68,0.8);
            color:var(--danger);
        }
        table {width:100%;border-collapse:collapse;margin-top:10px;font-size:14px;}
        th, td {padding:8px 6px;border-bottom:1px solid var(--border);text-align:center;}
        th {background:rgba(15,23,42,0.03);font-weight:900;font-size:13px;}
        body.dark th {background:rgba(15,23,42,0.4);}
        tbody tr:hover {background:rgba(15,23,42,0.02);}
        body.dark tbody tr:hover {background:rgba(15,23,42,0.5);}
        .badge-user {display:inline-block;padding:3px 8px;border-radius:999px;background:rgba(37,99,235,0.1);color:#1d4ed8;font-size:12px;}
        body.dark .badge-user {background:rgba(59,130,246,0.2);color:#bfdbfe;}
        .theme-toggle {display:flex;justify-content:flex-end;margin-bottom:10px;}
        .theme-switch {position:relative;width:64px;height:30px;border-radius:999px;background:#e5e7eb;box-shadow:inset 0 0 0 1px rgba(148,163,184,0.9);cursor:pointer;display:flex;align-items:center;justify-content:space-between;padding:0 6px;font-size:14px;color:#6b7280;font-weight:900;}
        .theme-switch span { z-index:2;user-select:none; }
        .theme-thumb {position:absolute;top:3px;right:3px;width:24px;height:24px;border-radius:999px;background:#facc15;box-shadow:0 4px 10px rgba(250,204,21,0.7);display:flex;align-items:center;justify-content:center;font-size:14px;transition:transform .25s ease,background .25s.ease,box-shadow .25s.ease;}
        body.dark .theme-switch {background:#020617;box-shadow:inset 0 0 0 1px rgba(30,64,175,0.9);color:#e5e7eb;}
        body.dark .theme-thumb {transform:translateX(-32px);background:#0f172a;box-shadow:0 4px 12px rgba(15,23,42,0.9);}
        .detail-btn {display:inline-block;margin-bottom:0;padding:2px 13px 2px 13px;border-radius:8px;border:none;color:#fff;background:#0ea5e9;cursor:pointer;font-size:13px;margin-right:4px}
        .detail-btn:hover {background:#0369a1}
        #modal-overlay {position:fixed;top:0;left:0;right:0;bottom:0;z-index:201;background:rgba(0,0,0,0.32);display:none;align-items:center;justify-content:center;}
        #modal-content {background:#fff;color:#222;direction:rtl;min-width:340px;max-width:95vw;padding:23px 11px 14px 11px;border-radius:20px;box-shadow:0 10px 35px rgba(0,0,0,0.17)}
        body.dark #modal-content {background:#252528;color:#fff;}
        /* تمرير احترافي لصندوق التفاصيل */
        #modalbody {
            max-height: 370px;
            overflow-y: auto;
            margin-bottom:10px;
            border-radius:8px;
            scrollbar-width: thin;
            scrollbar-color: #2563eb #e5e7eb;
        }
        #modalbody::-webkit-scrollbar {
            height: 8px;
            width: 9px;
            background: #f3f4f6;
            border-radius: 8px;
        }
        #modalbody::-webkit-scrollbar-thumb {
            background: #2563eb;
            border-radius: 8px;
        }
        #modalbody::-webkit-scrollbar-track {
            background: #e5e7eb;
            border-radius: 8px;
        }
        body.dark #modalbody::-webkit-scrollbar {
            background: #020617;
        }
        body.dark #modalbody::-webkit-scrollbar-thumb {
            background: #38bdf8;
        }
        body.dark #modalbody::-webkit-scrollbar-track {
            background: #1f2937;
        }
        body.dark #modalbody {
            scrollbar-color: #38bdf8 #1f2937;
        }
        #modal-content table{width:100%;font-size:15px;}
        #modal-content th,#modal-content td{border-bottom:1px solid #eee;padding:4px 5px}
        .modal-head{font-size:19px;font-weight:900;margin-bottom:12px;}
        #close-modal{float:left;font-size:24px;appearance:none;border:none;background:transparent;font-weight:900;cursor:pointer;}
        .modal-excel-btn{padding:5px 19px;border-radius:9px;background:#22c55e;color:white;border:none;font-size:16px;font-weight:800;margin-top:10px}
    </style>
</head>
<body>
<div class="page">
    <div class="header-bar">
        <div>
            <div class="title-main">سجل التقفيلات الشهرية</div>
            <div class="title-sub">
                هنا يمكنك مراجعة كل عملية تقفيل شهرية تمت، مع الأرقام التي تم تسجيلها في وقتها.
            </div>
        </div>
        <div>
            <a href="close_month.php" class="back-button">
                <span>🔙</span>
                <span>العودة لصفحة التقفيل الشهري</span>
            </a>
        </div>
    </div>

    <div class="card">
        <div class="theme-toggle">
            <div class="theme-switch" id="themeSwitch">
                <span>🌙</span>
                <span>☀️</span>
                <div class="theme-thumb" id="themeThumb"></div>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?>
                    <div>• <?php echo htmlspecialchars($e); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$errors && empty($records)): ?>
            <div class="alert">
                لا توجد أي تقفيلات شهرية مسجلة حتى الآن.
            </div>
        <?php endif; ?>

        <?php if (!empty($records)): ?>
            <?php
                // تجهيز فترة كل تقفيل (from تقفيل سابق to الحالي)
                $periods = [];
                for ($i=0; $i<count($records); $i++) {
                    $rec = $records[$i];
                    $end = $rec['closed_at'];
                    $begin = isset($records[$i+1]) ? $records[$i+1]['closed_at'] : '1970-01-01 00:00:00';
                    $periods[$rec['id']] = ['start'=>$begin, 'end'=>$end];
                }
            ?>
            <table>
                <thead>
                <tr>
                    <th>وقت التقفيل الفعلي</th>
                    <th>المستخدم</th>
                    <th>اشتراكات جديدة (عدد / إجمالي)</th>
                    <th>سداد البواقي (عدد / إجمالي)</th>
                    <th>تجديدات (عدد / إجمالي)</th>
                    <th>حصص واحدة (عدد / إجمالي)</th>
                    <th>المبيعات (عدد / إجمالي)</th>
                    <th>إجمالي المصروفات</th>
                    <th>صافي الشهر</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($records as $c): ?>
                    <?php
                        $rowNet = (float)$c['net_total'];
                        if ($rowNet == 0.0) {
                            $rowNet = (
                                (float)$c['total_paid_for_new_subs'] +
                                (float)$c['total_renewals_amount'] +
                                (float)$c['total_single_sessions_amount'] +
                                (float)$c['total_partial_payments'] +
                                (float)($c['total_sales_amount'] ?? 0)
                            ) - (float)$c['total_expenses'];
                        }
                        $pid = $c['id'];
                        $period = $periods[$pid];
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($c['closed_at']); ?></td>
                        <td>
                            <?php if (!empty($c['closed_by_username'])): ?>
                                <span class="badge-user"><?php echo htmlspecialchars($c['closed_by_username']); ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            echo (int)$c['new_subscriptions_count'] . ' / ' . number_format((float)$c['total_paid_for_new_subs'], 2);
                            ?>
                            <button class="detail-btn" onclick="detailsModal('newsubs', <?php echo $pid; ?>)">تفاصيل</button>
                        </td>
                        <td>
                            <?php
                            echo (int)$c['partial_payments_count'] . ' / ' . number_format((float)$c['total_partial_payments'], 2);
                            ?>
                            <button class="detail-btn" onclick="detailsModal('partials', <?php echo $pid; ?>)">تفاصيل</button>
                        </td>
                        <td>
                            <?php
                            echo (int)$c['renewals_count'] . ' / ' . number_format((float)$c['total_renewals_amount'], 2);
                            ?>
                            <button class="detail-btn" onclick="detailsModal('renewals', <?php echo $pid; ?>)">تفاصيل</button>
                        </td>
                        <td>
                            <?php
                            echo (int)$c['single_sessions_count'] . ' / ' . number_format((float)$c['total_single_sessions_amount'], 2);
                            ?>
                            <button class="detail-btn" onclick="detailsModal('singles', <?php echo $pid; ?>)">تفاصيل</button>
                        </td>
                        <td>
                            <?php echo (int)($c['sales_operations_count'] ?? 0) . ' / ' . number_format((float)($c['total_sales_amount'] ?? 0), 2); ?>
                            <button class="detail-btn" onclick="detailsModal('sales', <?php echo $pid; ?>)">تفاصيل</button>
                        </td>
                        <td>
                            <?php echo number_format((float)$c['total_expenses'], 2); ?>
                            <button class="detail-btn" onclick="detailsModal('expenses', <?php echo $pid; ?>)">تفاصيل</button>
                        </td>
                        <td>
                            <?php echo number_format($rowNet, 2); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <script>
            var periods = <?php echo json_encode($periods); ?>;
            </script>
        <?php endif; ?>
    </div>
</div>

<!-- مودال التفاصيل -->
<div id="modal-overlay" onclick="closeModal(event)">
    <div id="modal-content" onclick="event.stopPropagation()">
        <button id="close-modal" onclick="closeModal(event)">&times;</button>
        <div class="modal-head" id="modaltitle">التفاصيل</div>
        <div id="modalbody" style="overflow-x:auto;">جاري التحميل ...</div>
        <button style="display:none" id="modal-excel-btn" class="modal-excel-btn">تصدير Excel</button>
    </div>
</div>

<script>
const body = document.body;
const switchEl = document.getElementById('themeSwitch');
const savedTheme = localStorage.getItem('gymDashboardTheme') || 'light';

function applyTheme(mode) {
    if (mode === 'dark') body.classList.add('dark');
    else body.classList.remove('dark');
    localStorage.setItem('gymDashboardTheme', mode);
}
applyTheme(savedTheme);

if (switchEl) {
    switchEl.addEventListener('click', () => {
        const isDark = body.classList.contains('dark');
        applyTheme(isDark ? 'light' : 'dark');
    });
}

// تفاصيل كل تقفيل (monthly closing)
let lastType = '', lastPid = '';
function detailsModal(type, pid) {
    lastType = type;
    lastPid = pid;
    document.getElementById('modaltitle').innerText = 'تفاصيل';
    document.getElementById('modal-overlay').style.display='flex';
    document.getElementById('modalbody').innerHTML = 'جاري التحميل...';
    document.getElementById('modal-excel-btn').style.display='none';

    let p = periods[pid];
    fetch('details_monthly.php?type='+type+'&start='+encodeURIComponent(p.start)+'&end='+encodeURIComponent(p.end))
        .then(r=>r.json())
        .then(d=>{
            let html = "<table><thead><tr>";
            d.headings.forEach(h=>html+="<th>"+h+"</th>");
            html+="</tr></thead><tbody>";
            if(d.rows.length===0) html+='<tr><td colspan="'+d.headings.length+'">لا يوجد بيانات</td></tr>';
            d.rows.forEach(row=>{
                html+="<tr>";
                Object.values(row).forEach(v=>html+="<td>"+v+"</td>");
                html+="</tr>";
            });
            html+="</tbody></table>";
            document.getElementById('modalbody').innerHTML = html;
            document.getElementById('modal-excel-btn').style.display='inline-block';
        });
}

function closeModal(e){if(e)e.preventDefault(); document.getElementById('modal-overlay').style.display='none';}
document.getElementById('modal-excel-btn').onclick = function(){
    let p = periods[lastPid];
    window.open('details_monthly.php?type='+lastType+'&start='+encodeURIComponent(p.start)+'&end='+encodeURIComponent(p.end)+'&export=1','_blank');
}
</script>
</body>
</html>