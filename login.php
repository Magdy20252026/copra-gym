<?php
session_start();
require_once 'config.php';

// منع التخزين المؤقت لصفحة تسجيل الدخول
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// إذا كان المستخدم مسجّل دخول بالفعل، لا نسمح له برؤية صف��ة تسجيل الدخول
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

// جلب اسم الموقع والشعار
$siteName = "Gym System";
$logoPath = null;

try {
    $stmt = $pdo->query("SELECT site_name, logo_path FROM site_settings ORDER BY id ASC LIMIT 1");
    if ($row = $stmt->fetch()) {
        $siteName = $row['site_name'];
        $logoPath = $row['logo_path'];
    }
} catch (Exception $e) {
    // يمكن تجاهل الخطأ هنا
}

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = "من فضلك أدخل اسم المستخدم وكلمة السر";
    } else {
        $sql = "SELECT * FROM users WHERE username = :username AND password = MD5(:password) LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':username' => $username,
            ':password' => $password
        ]);
        $user = $stmt->fetch();

        if ($user) {
            // إنشاء السيشن
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role']      = $user['role'];

            // تحويل للوحة التحكم
            header("Location: dashboard.php");
            exit;
        } else {
            $error = "بيانات الدخول غير صحيحة";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تسجيل الدخول - <?php echo htmlspecialchars($siteName); ?></title>
    <style>
        * {
            box-sizing: border-box;
            -webkit-font-smoothing: antialiased;
        }

        :root {
            --bg-gradient: radial-gradient(circle at top, #dbeafe, #c4b5fd, #a5b4fc);
            --card-bg: #f9fafb;
            --card-border: rgba(148, 163, 184, 0.35);
            --text-main: #111827;
            --text-muted: #4b5563;
            --accent-main: #22c55e;
            --accent-main-2: #16a34a;
            --accent-blue: #3b82f6;
            --input-bg: #eef2ff;
            --input-border: rgba(148, 163, 184, 0.7);
            --error: #dc2626;
        }

        body.dark {
            --bg-gradient: radial-gradient(circle at top, #020617, #020617, #020617);
            --card-bg: #020617;
            --card-border: rgba(55, 65, 81, 0.9);
            --text-main: #ffffff;
            --text-muted: #e5e7eb;
            --accent-main: #22c55e;
            --accent-main-2: #16a34a;
            --accent-blue: #38bdf8;
            --input-bg: #020617;
            --input-border: rgba(75, 85, 99, 0.95);
            --error: #fb923c;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-weight: 600;
            background: var(--bg-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: var(--text-main);
            transition: background 0.4s ease, color 0.3s ease;
        }

        .card-wrapper {
            width: 100%;
            max-width: 460px;
            display: flex;
            justify-content: center;
        }

        .login-card {
            background: var(--card-bg);
            border-radius: 24px;
            padding: 26px 28px 22px;
            box-shadow:
                0 24px 60px rgba(15, 23, 42, 0.45),
                0 0 0 1px rgba(255, 255, 255, 0.5);
            border: 1px solid var(--card-border);
            position: relative;
            overflow: hidden;
        }

        .login-card::before {
            content: "";
            position: absolute;
            inset: -40%;
            background: radial-gradient(circle at top left, rgba(96,165,250,0.18), transparent 60%);
            opacity: 0.9;
            pointer-events: none;
        }

        .card-inner {
            position: relative;
            z-index: 1;
        }

        /* سويتش الثيم (أيقونات فقط) */
        .theme-toggle {
            position: absolute;
            top: 18px;
            left: 22px;
            z-index: 2;
        }

        .theme-switch {
            position: relative;
            width: 60px;
            height: 28px;
            border-radius: 999px;
            background: #e5e7eb;
            box-shadow: inset 0 0 0 1px rgba(148, 163, 184, 0.7);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 6px;
            font-size: 13px;
            color: #6b7280;
            font-weight: 600;
        }

        .theme-switch span {
            z-index: 2;
            user-select: none;
        }

        .theme-thumb {
            position: absolute;
            top: 3px;
            right: 3px;
            width: 22px;
            height: 22px;
            border-radius: 999px;
            background: #facc15;
            box-shadow: 0 4px 10px rgba(250, 204, 21, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            transition: transform 0.25s ease, background 0.25s ease, box-shadow 0.25s ease;
        }

        body.dark .theme-switch {
            background: #020617;
            box-shadow: inset 0 0 0 1px rgba(37, 99, 235, 0.9);
            color: #e5e7eb;
        }

        body.dark .theme-thumb {
            transform: translateX(-30px);
            background: #0f172a;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.9);
        }

        .header {
            text-align: center;
            margin-top: 18px;
            margin-bottom: 26px;
        }

        /* الشعار أكبر وخلفيته بيضاء */
        .logo-circle {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            margin: 0 auto 14px;
            background: #ffffff;
            box-shadow:
                0 16px 30px rgba(15,23,42,0.15),
                0 0 0 6px rgba(148,163,184,0.25);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .logo-circle img {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
        }

        .logo-circle span {
            font-size: 52px;
        }

        .title-main {
            margin: 6px 0 4px;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 0.04em;
        }

        .title-sub {
            margin: 0;
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            margin-bottom: 4px;
            color: var(--text-muted);
            font-weight: 700;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            right: 10px;
            font-size: 15px;
            color: var(--text-muted);
            pointer-events: none;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px 38px 12px 10px;
            border-radius: 12px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            font-size: 14px;
            font-weight: 700;
            color: var(--text-main);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s.ease;
        }

        input[type="text"]::placeholder,
        input[type="password"]::placeholder {
            color: rgba(148, 163, 184, 0.9);
            font-weight: 500;
        }

        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: var(--accent-blue);
            box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.55);
            background-color: #ffffff;
        }

        body.dark input[type="text"]:focus,
        body.dark input[type="password"]:focus {
            background-color: #020617;
        }

        .btn-submit {
            width: 100%;
            margin-top: 8px;
            padding: 13px;
            border-radius: 14px;
            border: none;
            background: linear-gradient(90deg, var(--accent-blue), var(--accent-main));
            color: #f9fafb;
            font-weight: 800;
            font-size: 15px;
            letter-spacing: 0.03em;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow:
                0 16px 40px rgba(37,99,235,0.45),
                0 0 0 1px rgba(248, 250, 252, 0.6);
            transition: transform 0.12s ease, box-shadow 0.12s.ease, filter 0.12s.ease;
        }

        .btn-submit span.icon {
            font-size: 18px;
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow:
                0 20px 46px rgba(37,99,235,0.55),
                0 0 0 1px rgba(248, 250, 252, 0.8);
            filter: brightness(1.04);
        }

        .btn-submit:active {
            transform: translateY(1px);
            box-shadow:
                0 10px 26px rgba(37,99,235,0.4),
                0 0 0 1px rgba(248, 250, 252, 0.6);
            filter: brightness(0.97);
        }

        .error-box {
            margin-bottom: 12px;
            padding: 9px 11px;
            border-radius: 10px;
            background: rgba(248,113,113,0.08);
            border: 1px solid rgba(248,113,113,0.7);
            color: var(--error);
            font-size: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .hint {
            margin-top: 10px;
            font-size: 11px;
            text-align: center;
            color: var(--text-muted);
            font-weight: 600;
        }

        .hint code {
            background: rgba(209, 213, 219, 0.7);
            padding: 2px 6px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }

        body.dark .hint code {
            background: rgba(31, 41, 55, 0.95);
            color: #f9fafb;
        }
    </style>
</head>
<body>
<div class="card-wrapper">
    <div class="login-card">
        <div class="theme-toggle">
            <div class="theme-switch" id="themeSwitch">
                <span>🌙</span>
                <span>☀️</span>
                <div class="theme-thumb" id="themeThumb">☀️</div>
            </div>
        </div>

        <div class="card-inner">
            <div class="header">
                <div class="logo-circle">
                    <?php if ($logoPath): ?>
                        <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="شعار الموقع">
                    <?php else: ?>
                        <span>🏋️‍♂️</span>
                    <?php endif; ?>
                </div>
                <h1 class="title-main"><?php echo htmlspecialchars($siteName); ?></h1>
                <p class="title-sub">لوحة إدارة نظام الجيم</p>
            </div>

            <?php if ($error): ?>
                <div class="error-box">
                    <span>⚠️</span>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <div class="form-group">
                    <label class="form-label" for="username">اسم المستخدم</label>
                    <div class="input-wrapper">
                        <span class="input-icon">👤</span>
                        <input type="text" id="username" name="username" required autocomplete="username" placeholder="ادخل اسم المستخدم">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">كلمة السر</label>
                    <div class="input-wrapper">
                        <span class="input-icon">🔒</span>
                        <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="ادخل كلمة السر">
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <span class="icon">📊</span>
                    <span>تسجيل الدخول إلى لوحة التحكم</span>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    const body = document.body;
    const switchEl = document.getElementById('themeSwitch');
    const savedTheme = localStorage.getItem('gymTheme') || 'light';

    function applyTheme(mode) {
        if (mode === 'dark') {
            body.classList.add('dark');
        } else {
            body.classList.remove('dark');
        }
        localStorage.setItem('gymTheme', mode);
    }

    applyTheme(savedTheme);

    if (switchEl) {
        switchEl.addEventListener('click', () => {
            const isDark = body.classList.contains('dark');
            applyTheme(isDark ? 'light' : 'dark');
        });
    }
</script>
</body>
</html>