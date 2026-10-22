<?php

session_start();

require_once 'db.php';

$message = "";
$message_type = "";

/*
|--------------------------------------------------------------------------
| GUARD — must have verified OTP first
|--------------------------------------------------------------------------
*/

$email  = $_SESSION["admin_reset_email"] ?? "";
$step   = $_SESSION["admin_reset_step"]  ?? "";
$reset_id = $_SESSION["admin_reset_id"]  ?? 0;

if ($email === "" || $step !== "reset" || empty($reset_id)) {
    header("Location: admin-forgot-password.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $new_password     = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($new_password === "" || $confirm_password === "") {
        $message = "Please fill in both password fields.";
        $message_type = "error";
    } elseif (strlen($new_password) < 8) {
        $message = "Password must contain at least 8 characters.";
        $message_type = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "Passwords do not match.";
        $message_type = "error";
    } else {

        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $update_sql = "UPDATE admin SET password = ?, otp_code = NULL, otp_expires_at = NULL WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);

        if (!$update_stmt) {
            $message = "Unable to reset password. Please try again.";
            $message_type = "error";
        } else {

            mysqli_stmt_bind_param($update_stmt, "si", $hashed_password, $reset_id);

            if (mysqli_stmt_execute($update_stmt)) {

                unset($_SESSION["admin_reset_email"]);
                unset($_SESSION["admin_reset_step"]);
                unset($_SESSION["admin_reset_id"]);
                unset($_SESSION["admin_reset_otp_dev"]);

                $step = "done";

            } else {
                $message = "Failed to update password. Please try again.";
                $message_type = "error";
            }

            mysqli_stmt_close($update_stmt);
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
            --bg-body: #f8fafc; --bg-card: #ffffff;
            --bg-alt: #f1f5f9;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --bg-body: #0b1120; --bg-card: #1e293b;
            --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { min-height: 100vh; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            transition: background 0.4s ease, color 0.4s ease;
            position: relative;
            overflow-x: hidden;
        }
        body::before {
            content: '';
            position: fixed;
            top: -50%; right: -20%;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.10) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        body::after {
            content: '';
            position: fixed;
            bottom: -30%; left: -10%;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(124, 58, 237, 0.08) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .top-bar {
            width: 100%;
            padding: 16px 6%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            position: relative;
            z-index: 5;
        }
        .top-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            color: var(--text-primary);
        }
        .top-brand img {
            width: 40px; height: 40px;
            object-fit: contain;
            border-radius: 50%;
            border: 2px solid var(--border-color);
            padding: 2px;
            background: var(--bg-card);
            transition: var(--transition);
        }
        .top-brand:hover img {
            border-color: var(--primary);
            transform: rotate(-5deg) scale(1.05);
        }
        .top-brand-name {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.4px;
        }
        .top-brand-name span { color: var(--primary); }
        .top-actions { display: flex; align-items: center; gap: 10px; }
        .top-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 10px 18px;
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: var(--transition);
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            white-space: nowrap;
        }
        .top-btn i { font-size: 15px; }
        .top-btn:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
        .top-btn.icon-only { width: 42px; height: 42px; padding: 0; border-radius: 50%; }
        .top-btn.icon-only:hover { transform: rotate(20deg); }
        .main-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 15px 50px;
            position: relative;
            z-index: 1;
        }
        .card {
            width: 100%;
            max-width: 480px;
            background: var(--bg-card);
            padding: 42px 40px;
            border-radius: 22px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }
        .card-logo { text-align: center; margin-bottom: 18px; }
        .card-logo img {
            width: 82px; height: 82px;
            object-fit: contain;
            border-radius: 50%;
            border: 2px solid var(--border-color);
            padding: 4px;
            background: var(--bg-card);
            transition: var(--transition);
        }
        .card-logo img:hover {
            border-color: var(--primary);
            transform: rotate(-5deg) scale(1.05);
        }
        .icon-badge {
            width: 74px; height: 74px;
            border-radius: 50%;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin: 0 auto 18px;
        }
        [data-theme="dark"] .icon-badge { background: rgba(59, 130, 246, 0.15); }
        .icon-badge.success {
            background: rgba(16, 185, 129, 0.12);
            color: #10b981;
        }
        [data-theme="dark"] .icon-badge.success { background: rgba(16, 185, 129, 0.2); }
        h1 {
            font-family: 'Poppins', sans-serif;
            text-align: center;
            font-size: 26px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        h1 span { color: var(--primary); }
        .subtitle {
            text-align: center;
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 22px;
            line-height: 1.6;
        }
        .email-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--primary);
            background: var(--primary-light);
            padding: 7px 14px;
            border-radius: 50px;
            font-weight: 600;
            margin-bottom: 22px;
        }
        [data-theme="dark"] .email-badge { background: rgba(59, 130, 246, 0.15); }
        .email-badge-wrap { text-align: center; }
        .message {
            padding: 13px 16px;
            border-radius: 10px;
            margin-bottom: 22px;
            font-size: 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.5;
        }
        .error { background: rgba(239, 68, 68, 0.08); color: #dc2626; border: 1px solid rgba(239, 68, 68, 0.25); }
        [data-theme="dark"] .error { color: #f87171; }
        .success { background: rgba(16, 185, 129, 0.08); color: #059669; border: 1px solid rgba(16, 185, 129, 0.25); }
        [data-theme="dark"] .success { color: #34d399; }
        .form-group { margin-bottom: 18px; }
        label {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        label .required { color: #ef4444; }
        .input-wrapper { position: relative; }
        .input-wrapper i.input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 15px;
            pointer-events: none;
            transition: var(--transition);
        }
        .input-wrapper input {
            width: 100%;
            padding: 13px 15px 13px 44px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            font-family: 'Inter', sans-serif;
            font-size: 15px;
            color: var(--text-primary);
            background: var(--bg-body);
            outline: none;
            transition: var(--transition);
        }
        .input-wrapper input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        .input-wrapper input:focus + i.input-icon,
        .input-wrapper input:not(:placeholder-shown) + i.input-icon {
            color: var(--primary);
        }
        .input-wrapper input::placeholder { color: var(--text-muted); }
        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 15px;
            padding: 4px;
            transition: var(--transition);
        }
        .toggle-password:hover { color: var(--primary); }
        button[type="submit"] {
            width: 100%;
            border: none;
            border-radius: 10px;
            padding: 15px;
            background: var(--primary);
            color: #fff;
            font-family: 'Inter', sans-serif;
            font-size: 15.5px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 8px;
        }
        button[type="submit"]:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }
        .primary-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 15px;
            background: var(--primary);
            color: #fff;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15.5px;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
            transition: var(--transition);
            margin-top: 8px;
        }
        .primary-action:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }
        .info-note {
            margin-top: 22px;
            padding: 14px 16px;
            background: var(--bg-alt);
            border-radius: 10px;
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.6;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            border: 1px solid var(--border-color);
        }
        .info-note i {
            color: var(--primary);
            margin-top: 2px;
            font-size: 14px;
            flex-shrink: 0;
        }
        @media (max-width: 600px) {
            .top-bar { padding: 14px 4%; }
            .top-brand-name { display: none; }
        }
        @media (max-width: 480px) {
            .card { padding: 32px 24px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>

<header class="top-bar">
    <a href="index.php" class="top-brand">
        <img src="logo.png" alt="Educ Bridge Logo">
        <span class="top-brand-name">Educ<span>Bridge</span></span>
    </a>
    <div class="top-actions">
        <button class="top-btn icon-only" id="themeToggle" aria-label="Toggle theme" title="Toggle theme">
            <i class="fas fa-moon"></i>
        </button>
    </div>
</header>

<main class="main-wrap">
    <div class="card">

        <div class="card-logo">
            <img src="logo.png" alt="Educ Bridge Logo">
        </div>

        <?php if ($step === "done"): ?>

            <div class="icon-badge success"><i class="fas fa-check-circle"></i></div>
            <h1>Password <span>Reset!</span></h1>
            <p class="subtitle">
                Your password has been updated successfully.
                You can now log in with your new password.
            </p>

            <a href="admin-login.php" class="primary-action">
                <i class="fas fa-sign-in-alt"></i> Go to Admin Login
            </a>

        <?php else: ?>

            <div class="icon-badge"><i class="fas fa-lock"></i></div>
            <h1>Set New <span>Password</span></h1>
            <p class="subtitle">Create a new password for your Educ Bridge administrator account.</p>

            <div class="email-badge-wrap">
                <span class="email-badge">
                    <i class="fas fa-envelope" style="font-size:12px;"></i>
                    <?= htmlspecialchars($email); ?>
                </span>
            </div>

            <?php if ($message !== ""): ?>
                <div class="message <?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">

                <div class="form-group">
                    <label for="password">New Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" minlength="8" placeholder="At least 8 characters" required>
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" class="toggle-password" data-target="password" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password <span class="required">*</span></label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" minlength="8" placeholder="Re-enter new password" required>
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit">
                    <i class="fas fa-save"></i> Reset Password
                </button>
            </form>

            <div class="info-note">
                <i class="fas fa-shield-alt"></i>
                <span>Your new password must be at least <strong>8 characters</strong>. Use a strong, unique password.</span>
            </div>

        <?php endif; ?>

    </div>
</main>

<script>
    (function () {
        const html = document.documentElement;
        const toggle = document.getElementById('themeToggle');
        const icon = toggle.querySelector('i');
        const savedTheme = localStorage.getItem('educbridge-theme') || 'light';
        html.setAttribute('data-theme', savedTheme);
        updateIcon(savedTheme);
        toggle.addEventListener('click', function () {
            const current = html.getAttribute('data-theme');
            const next = current === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', next);
            localStorage.setItem('educbridge-theme', next);
            updateIcon(next);
        });
        function updateIcon(theme) {
            if (theme === 'dark') { icon.classList.remove('fa-moon'); icon.classList.add('fa-sun'); }
            else { icon.classList.remove('fa-sun'); icon.classList.add('fa-moon'); }
        }

        document.querySelectorAll('.toggle-password').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const target = document.getElementById(this.dataset.target);
                const icon = this.querySelector('i');
                if (!target) return;
                if (target.type === 'password') {
                    target.type = 'text';
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                } else {
                    target.type = 'password';
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            });
        });
    })();
</script>

</body>
</html>