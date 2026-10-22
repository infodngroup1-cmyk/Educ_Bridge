<?php
session_start();
require_once 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/*
|--------------------------------------------------------------------------
| ALREADY LOGGED IN → DASHBOARD
|--------------------------------------------------------------------------
*/
if (!empty($_SESSION['school_id']) && !empty($_SESSION['school_verified'])) {
    header("Location: school-dashboard.php");
    exit;
}

$message      = "";
$message_type = "";

/*
|--------------------------------------------------------------------------
| HANDLE LOGIN
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {

    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $message = "Please enter your email/phone and password.";
        $message_type = "error";
    } else {

        /*
        |------------------------------------------------------------------
        | Find the school by email OR phone
        |------------------------------------------------------------------
        */
        $stmt = mysqli_prepare($conn, "
            SELECT id, school_code, name, admin_name, country, province, district,
                   type, email, phone, status
            FROM schools
            WHERE email = ? OR phone = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $message = "Database error: " . mysqli_error($conn);
            $message_type = "error";
        } else {
            mysqli_stmt_bind_param($stmt, "ss", $identifier, $identifier);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($res) === 0) {
                $message = "No school found with that email or phone number.";
                $message_type = "error";
                mysqli_stmt_close($stmt);
            } else {
                $school = mysqli_fetch_assoc($res);
                $sid    = (int) $school['id'];
                mysqli_stmt_close($stmt);

                /*
                |----------------------------------------------------------
                | School must be active
                |----------------------------------------------------------
                */
                if (strtolower($school['status']) !== 'active') {
                    $message = "This school account is deactivated. Please contact Educ Bridge support.";
                    $message_type = "error";
                } else {

                    /*
                    |------------------------------------------------------
                    | Load linked account
                    |------------------------------------------------------
                    */
                    $acc_stmt = mysqli_prepare($conn, "
                        SELECT id, password_hash, is_verified
                        FROM school_accounts
                        WHERE school_id = ?
                        LIMIT 1
                    ");

                    if (!$acc_stmt) {
                        $message = "Database error: " . mysqli_error($conn);
                        $message_type = "error";
                    } else {
                        mysqli_stmt_bind_param($acc_stmt, "i", $sid);
                        mysqli_stmt_execute($acc_stmt);
                        $acc_res = mysqli_stmt_get_result($acc_stmt);

                        if (mysqli_num_rows($acc_res) === 0) {
                            $message = "This school has not registered an account yet. Please register first.";
                            $message_type = "error";
                            mysqli_stmt_close($acc_stmt);
                        } else {
                            $acc = mysqli_fetch_assoc($acc_res);
                            mysqli_stmt_close($acc_stmt);

                            /*
                            |----------------------------------------------
                            | Account must be verified
                            |----------------------------------------------
                            */
                            if ((int) $acc['is_verified'] !== 1) {

                                // Resume verification flow
                                $_SESSION['reg_account_id'] = (int) $acc['id'];
                                header("Location: school-verify.php");
                                exit;

                            } elseif (!password_verify($password, $acc['password_hash'])) {

                                $message = "Incorrect password. Please try again.";
                                $message_type = "error";

                            } else {

                                /*
                                |------------------------------------------
                                | SUCCESS → log the school in
                                |------------------------------------------
                                */
                                session_regenerate_id(true);

                                $_SESSION['school_id']       = $sid;
                                $_SESSION['school_name']     = $school['name'];
                                $_SESSION['school_admin']    = $school['admin_name'];
                                $_SESSION['school_email']    = $school['email'];
                                $_SESSION['school_code']     = $school['school_code'];
                                $_SESSION['school_verified'] = true;

                                header("Location: school-dashboard.php");
                                exit;
                            }
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Login | Educ Bridge</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            position: relative;
            overflow-x: hidden;
        }
        body::before {
            content: '';
            position: fixed;
            inset: 0;
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
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 15px;
            transition: var(--transition);
            z-index: 20;
        }
        .theme-toggle:hover {
            color: var(--primary);
            border-color: var(--primary);
            transform: rotate(20deg);
        }

        .wrap {
            width: 100%;
            max-width: 480px;
            position: relative;
            z-index: 1;
        }

        .brand {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 22px;
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
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.4px;
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
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .card-head p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.55;
        }
        .card-body { padding: 28px 30px 30px; }

        .alert {
            padding: 13px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
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
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
            pointer-events: none;
        }
        .input-icon input {
            padding-left: 42px;
        }

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
            right: 12px;
            top: 50%;
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

        .form-row-between {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: -6px 0 18px;
            font-size: 13px;
        }
        .form-row-between a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }
        .form-row-between a:hover { text-decoration: underline; }

        .btn {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 14px 22px;
            border-radius: 10px;
            font-size: 14.5px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            background: var(--primary);
            color: #fff;
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
            box-shadow: 0 6px 18px rgba(37,99,235,0.32);
            text-decoration: none;
        }
        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 8px 22px rgba(37,99,235,0.4);
        }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 20px 0;
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border-color);
        }

        .foot {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: var(--text-muted);
        }
        .foot a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }
        .foot a:hover { text-decoration: underline; }

        .foot-secondary {
            text-align: center;
            margin-top: 10px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .foot-secondary a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }
        .foot-secondary a:hover { text-decoration: underline; }

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
            <div class="head-icon"><i class="fas fa-school"></i></div>
            <h1>School Sign In</h1>
            <p>Sign in with your school email or phone number to access your dashboard.</p>
        </div>

        <div class="card-body">

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="school-login.php" autocomplete="off" id="loginForm">
                <input type="hidden" name="action" value="login">

                <div class="form-group">
                    <label for="identifier">Email or Phone Number <span class="required">*</span></label>
                    <div class="input-icon">
                        <i class="fas fa-user"></i>
                        <input type="text" id="identifier" name="identifier"
                               placeholder="school@example.com or 07XX XXX XXX"
                               required autofocus
                               value="<?= htmlspecialchars($_POST['identifier'] ?? ''); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password <span class="required">*</span></label>
                    <div class="input-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password"
                               placeholder="Enter your password"
                               required style="padding-right:42px;">
                        <button type="button" class="toggle-pass" data-target="password" tabindex="-1">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="form-row-between">
                    <span></span>
                    <a href="school-forgot-password.php">Forgot password?</a>
                </div>

                <button type="submit" class="btn" id="loginBtn">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>

            <div class="divider">New to Educ Bridge?</div>

            <a href="school-register.php" class="btn" style="background:transparent;color:var(--primary);border:1.5px solid var(--primary);box-shadow:none;">
                <i class="fas fa-user-plus"></i> Register your school
            </a>

        </div>

    </div>

    <div class="foot">
        Are you system admin? <a href="admin-login.php">Admin sign in</a>
    </div>
    <div class="foot-secondary">
        Need help? <a href="mailto:info.dngroup1@gmail.com">Contact support</a>
    </div>

</div>

<script>
    // THEME
    (function () {
        const html = document.documentElement;
        const toggle = document.getElementById('themeToggle');
        const icon = toggle.querySelector('i');

        function applyTheme(theme) {
            html.setAttribute('data-theme', theme);
            localStorage.setItem('educbridge-theme', theme);
            if (theme === 'dark') {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            }
        }

        applyTheme(localStorage.getItem('educbridge-theme') || 'light');

        toggle.addEventListener('click', function () {
            const current = html.getAttribute('data-theme');
            applyTheme(current === 'light' ? 'dark' : 'light');
        });
    })();

    // TOGGLE PASSWORD
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

    // PREVENT DOUBLE SUBMIT
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', function () {
            const btn = document.getElementById('loginBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in...';
        });
    }
</script>

</body>
</html>