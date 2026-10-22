<?php
session_start();
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| REQUIRE VERIFIED RESET
|--------------------------------------------------------------------------
*/
$account_id = (int) ($_SESSION['pwreset_account_id'] ?? 0);

if ($account_id <= 0 || empty($_SESSION['pwreset_verified'])) {
    header("Location: school-forgot-password.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| LOAD ACCOUNT
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare($conn, "
    SELECT a.id, s.name, s.admin_name, s.email, s.school_code
    FROM school_accounts a
    INNER JOIN schools s ON s.id = a.school_id
    WHERE a.id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, "i", $account_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($res) === 0) {
    unset($_SESSION['pwreset_account_id'], $_SESSION['pwreset_verified']);
    header("Location: school-forgot-password.php");
    exit;
}

$acc = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

$message      = "";
$message_type = "";

/*
|--------------------------------------------------------------------------
| HANDLE NEW PASSWORD
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {

    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $message = "Password must be at least 8 characters long.";
        $message_type = "error";
    } elseif ($password !== $confirm) {
        $message = "Passwords do not match.";
        $message_type = "error";
    } else {

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $upd = mysqli_prepare($conn, "
            UPDATE school_accounts
            SET password_hash = ?,
                otp_hash = NULL,
                otp_expires_at = NULL,
                otp_attempts = 0
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($upd, "si", $hash, $account_id);

        if (mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);

            // Clear reset session keys
            unset(
                $_SESSION['pwreset_account_id'],
                $_SESSION['pwreset_school_id'],
                $_SESSION['pwreset_school_name'],
                $_SESSION['pwreset_admin'],
                $_SESSION['pwreset_email'],
                $_SESSION['pwreset_last_sent'],
                $_SESSION['pwreset_verified']
            );

            // Flash message to login
            $_SESSION['login_flash']      = "Your password has been reset successfully. Please sign in with your new password.";
            $_SESSION['login_flash_type'] = "success";

            header("Location: school-login.php");
            exit;

        } else {
            $message = "Failed to update your password. Please try again.";
            $message_type = "error";
            mysqli_stmt_close($upd);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Educ Bridge</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --success: #10b981;
            --danger: #ef4444;
            --bg-body: #f1f5f9; --bg-card: #ffffff;
            --bg-alt: #f8fafc;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --success: #34d399;
            --danger: #f87171;
            --bg-body: #0b1120; --bg-card: #1e293b;
            --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { min-height: 100vh; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            transition: background 0.4s ease, color 0.4s ease;
            display: flex; align-items: center; justify-content: center;
            padding: 24px 16px;
            position: relative;
            overflow-x: hidden;
        }
        body::before {
            content: '';
            position: fixed; inset: 0;
            background:
                radial-gradient(circle at 10% 10%, rgba(37,99,235,0.10), transparent 45%),
                radial-gradient(circle at 90% 90%, rgba(124,58,237,0.10), transparent 45%);
            pointer-events: none;
            z-index: 0;
        }
        .theme-toggle {
            position: fixed;
            top: 20px; right: 20px;
            width: 42px; height: 42px;
            border-radius: 50%;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 15px;
            transition: var(--transition);
            z-index: 20;
        }
        .theme-toggle:hover { color: var(--primary); border-color: var(--primary); transform: rotate(20deg); }

        .wrap { width: 100%; max-width: 480px; position: relative; z-index: 1; }

        .brand {
            display: flex; align-items: center; justify-content: center;
            gap: 12px; margin-bottom: 22px;
        }
        .brand img {
            width: 48px; height: 48px;
            border-radius: 50%;
            background: #fff;
            padding: 3px;
            border: 2px solid var(--border-color);
            object-fit: contain;
        }
        .brand-text {
            font-family: 'Poppins', sans-serif;
            font-size: 22px; font-weight: 700; letter-spacing: -0.4px;
        }
        .brand-text span { color: var(--primary); }

        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }
        .card-head {
            padding: 30px 30px 22px;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(135deg, rgba(37,99,235,0.07), rgba(124,58,237,0.05));
            text-align: center;
        }
        .head-icon {
            width: 60px; height: 60px;
            border-radius: 50%;
            background: var(--primary-light);
            color: var(--primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 14px;
        }
        .card-head h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 22px; font-weight: 700; margin-bottom: 8px;
        }
        .card-head p {
            font-size: 14px; color: var(--text-muted); line-height: 1.55;
        }
        .card-head p strong { color: var(--text-primary); }
        .card-body { padding: 28px 30px 30px; }

        .alert {
            padding: 13px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex; align-items: flex-start; gap: 10px;
            line-height: 1.5;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.08);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.25);
        }
        [data-theme="dark"] .alert-error { color: #f87171; }
        .alert-success {
            background: rgba(16, 185, 129, 0.08);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }
        [data-theme="dark"] .alert-success { color: #34d399; }

        .school-preview {
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 16px 18px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .school-preview .sp-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 6px 16px rgba(37,99,235,0.35);
        }
        .school-preview .sp-name {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .school-preview .sp-code {
            font-size: 12px;
            color: var(--primary);
            font-weight: 700;
            font-family: 'Courier New', monospace;
            margin-top: 2px;
        }

        .form-group { margin-bottom: 18px; }
        .form-group label {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        .form-group label .required { color: #ef4444; }

        .input-icon { position: relative; }
        .input-icon > i {
            position: absolute;
            left: 15px; top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
            pointer-events: none;
        }
        .input-icon input { padding-left: 42px; }

        .form-group input {
            width: 100%;
            padding: 13px 15px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            font-size: 14.5px;
            outline: none;
            transition: var(--transition);
        }
        .form-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
            background: var(--bg-card);
        }
        .form-help {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 6px;
            line-height: 1.5;
        }

        .toggle-pass {
            position: absolute;
            right: 12px; top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 14px;
            padding: 4px;
            transition: var(--transition);
        }
        .toggle-pass:hover { color: var(--primary); }

        .strength {
            margin-top: 8px;
            height: 5px;
            background: var(--border-color);
            border-radius: 50px;
            overflow: hidden;
        }
        .strength-bar {
            height: 100%;
            width: 0;
            background: var(--danger);
            transition: width 0.3s ease, background 0.3s ease;
            border-radius: 50px;
        }
        .strength-text {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .btn {
            width: 100%;
            display: inline-flex; align-items: center; justify-content: center;
            gap: 9px;
            padding: 14px 22px;
            border-radius: 10px;
            font-size: 14.5px; font-weight: 700;
            cursor: pointer; border: none;
            background: var(--primary);
            color: #fff;
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
            box-shadow: 0 6px 18px rgba(37,99,235,0.32);
            text-decoration: none;
        }
        .btn:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

        .btn-ghost {
            background: transparent;
            color: var(--text-secondary);
            border: 1.5px solid var(--border-color);
            box-shadow: none;
            margin-top: 10px;
            font-weight: 600;
        }
        .btn-ghost:hover { background: var(--bg-alt); color: var(--primary); border-color: var(--primary); box-shadow: none; }

        .foot {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: var(--text-muted);
        }
        .foot a { color: var(--primary); font-weight: 600; text-decoration: none; }
        .foot a:hover { text-decoration: underline; }

        @media (max-width: 480px) {
            .card-head { padding: 24px 20px 18px; }
            .card-body { padding: 22px 20px 24px; }
        }
    </style>
</head>
<body>

<button class="theme-toggle" id="themeToggle" aria-label="Toggle theme" title="Toggle theme">
    <i class="fas fa-moon"></i>
</button>

<div class="wrap">

    <div class="brand">
        <img src="logo.png" alt="Educ Bridge">
        <div class="brand-text">Educ<span>Bridge</span></div>
    </div>

    <div class="card">

        <div class="card-head">
            <div class="head-icon"><i class="fas fa-lock"></i></div>
            <h1>Set New Password</h1>
            <p>Choose a new password for<br><strong><?= htmlspecialchars($acc['name']); ?></strong></p>
        </div>

        <div class="card-body">

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <div class="school-preview">
                <div class="sp-icon"><i class="fas fa-school"></i></div>
                <div>
                    <div class="sp-name"><?= htmlspecialchars($acc['admin_name']); ?></div>
                    <div class="sp-code"><?= htmlspecialchars($acc['school_code']); ?></div>
                </div>
            </div>

            <form method="POST" action="school-reset-password.php" autocomplete="off" id="resetForm">
                <input type="hidden" name="action" value="reset_password">

                <div class="form-group">
                    <label for="password">New Password <span class="required">*</span></label>
                    <div class="input-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password"
                               placeholder="Minimum 8 characters"
                               minlength="8" required autofocus
                               style="padding-right:42px;">
                        <button type="button" class="toggle-pass" data-target="password" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="strength"><div class="strength-bar" id="strengthBar"></div></div>
                    <div class="strength-text" id="strengthText">Use at least 8 characters. Mix letters, numbers &amp; symbols.</div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password <span class="required">*</span></label>
                    <div class="input-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="confirm_password" name="confirm_password"
                               placeholder="Re-enter your new password"
                               minlength="8" required
                               style="padding-right:42px;">
                        <button type="button" class="toggle-pass" data-target="confirm_password" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn" id="resetBtn">
                    <i class="fas fa-check"></i> Reset Password
                </button>

                <a href="school-login.php" class="btn btn-ghost">
                    <i class="fas fa-arrow-left"></i> Back to sign in
                </a>
            </form>

        </div>

    </div>

    <div class="foot">
        Need help? <a href="mailto:info.dngroup1@gmail.com">Contact support</a>
    </div>

</div>

<script>
    (function () {
        const html = document.documentElement;
        const toggle = document.getElementById('themeToggle');
        const icon = toggle.querySelector('i');
        function applyTheme(theme) {
            html.setAttribute('data-theme', theme);
            localStorage.setItem('educbridge-theme', theme);
            if (theme === 'dark') { icon.classList.remove('fa-moon'); icon.classList.add('fa-sun'); }
            else { icon.classList.remove('fa-sun'); icon.classList.add('fa-moon'); }
        }
        applyTheme(localStorage.getItem('educbridge-theme') || 'light');
        toggle.addEventListener('click', function () {
            applyTheme(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
        });
    })();

    document.querySelectorAll('.toggle-pass').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById(this.dataset.target);
            const icon  = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });
    });

    // Password strength
    (function () {
        const input = document.getElementById('password');
        const bar   = document.getElementById('strengthBar');
        const text  = document.getElementById('strengthText');

        input.addEventListener('input', function () {
            const v = this.value;
            let score = 0;
            if (v.length >= 8) score++;
            if (v.length >= 12) score++;
            if (/[a-z]/.test(v) && /[A-Z]/.test(v)) score++;
            if (/\d/.test(v)) score++;
            if (/[^A-Za-z0-9]/.test(v)) score++;

            const pct = Math.min(100, (score / 5) * 100);
            let color = '#ef4444', label = 'Weak';
            if (score >= 4) { color = '#10b981'; label = 'Strong'; }
            else if (score === 3) { color = '#f59e0b'; label = 'Medium'; }
            else if (score === 2) { color = '#f97316'; label = 'Fair'; }

            bar.style.width  = pct + '%';
            bar.style.background = color;
            text.textContent = v ? 'Password strength: ' + label : 'Use at least 8 characters. Mix letters, numbers & symbols.';
            text.style.color = v ? color : 'var(--text-muted)';
        });
    })();

    document.getElementById('resetForm').addEventListener('submit', function () {
        const btn = document.getElementById('resetBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Resetting...';
    });
</script>

</body>
</html>