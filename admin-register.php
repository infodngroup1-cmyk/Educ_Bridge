<?php

session_start();

require_once 'db.php';

$phpmailer_available = false;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        $phpmailer_available = true;
    }
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/*
|--------------------------------------------------------------------------
| GMAIL SMTP SETTINGS
|--------------------------------------------------------------------------
*/

$smtp_host = "smtp.gmail.com";
$smtp_port = 587;
$smtp_username = "info.dngroup1@gmail.com";
$smtp_password = "rsxm dxju dnvp yleu";
$smtp_from_email = "info.dngroup1@gmail.com";
$smtp_from_name = "Educ Bridge";

$otp_recipient_email = "info.dngroup1@gmail.com";

$message = "";
$message_type = "";

$name = "";
$email = "";
$phone = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($name === "" || $email === "" || $phone === "" || $password === "" || $confirm_password === "") {
        $message = "Please fill in all fields.";
        $message_type = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $message_type = "error";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $message = "Phone number must contain exactly 10 digits.";
        $message_type = "error";
    } elseif (strlen($password) < 8) {
        $message = "Password must contain at least 8 characters.";
        $message_type = "error";
    } elseif ($password !== $confirm_password) {
        $message = "Passwords do not match.";
        $message_type = "error";
    } else {

        $check_sql = "SELECT id, email, phone FROM admin WHERE email = ? OR phone = ? LIMIT 1";
        $check_stmt = mysqli_prepare($conn, $check_sql);

        if (!$check_stmt) {
            $message = "Database error. Please try again.";
            $message_type = "error";
        } else {

            mysqli_stmt_bind_param($check_stmt, "ss", $email, $phone);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);

            if (mysqli_num_rows($check_result) > 0) {
                $existing = mysqli_fetch_assoc($check_result);
                if ($existing["email"] === $email) {
                    $message = "This email address is already registered.";
                } else {
                    $message = "This phone number is already registered.";
                }
                $message_type = "error";
            } else {

                $otp = str_pad((string) random_int(0, 999999), 6, "0", STR_PAD_LEFT);
                $otp_expires_at = date("Y-m-d H:i:s", time() + (10 * 60));

                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $verified = 0;

                $insert_sql = "
                    INSERT INTO admin
                    (name, email, phone, password, verified, otp_code, otp_expires_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ";

                $insert_stmt = mysqli_prepare($conn, $insert_sql);

                if (!$insert_stmt) {
                    $message = "Unable to create administrator account.";
                    $message_type = "error";
                } else {

                    mysqli_stmt_bind_param(
                        $insert_stmt,
                        "ssssiss",
                        $name,
                        $email,
                        $phone,
                        $hashed_password,
                        $verified,
                        $otp,
                        $otp_expires_at
                    );

                    if (mysqli_stmt_execute($insert_stmt)) {

                        $email_sent = false;

                        if ($phpmailer_available) {
                            $mail = new PHPMailer(true);
                            try {
                                $mail->isSMTP();
                                $mail->Host = $smtp_host;
                                $mail->SMTPAuth = true;
                                $mail->Username = $smtp_username;
                                $mail->Password = $smtp_password;
                                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                                $mail->Port = $smtp_port;

                                $mail->setFrom($smtp_from_email, $smtp_from_name);
                                $mail->addAddress($otp_recipient_email, "Educ Bridge Admin");

                                $mail->isHTML(true);
                                $mail->Subject = "New Admin Registration OTP - Educ Bridge";

                                $safe_name  = htmlspecialchars($name,  ENT_QUOTES, 'UTF-8');
                                $safe_email = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
                                $safe_phone = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');

                                $mail->Body = "
                                    <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:25px;border:1px solid #e5e7eb;border-radius:12px;background:#ffffff;'>
                                        <h2 style='color:#2563eb;margin-bottom:20px;'>Educ Bridge — New Admin Registration</h2>
                                        <p>A new administrator has registered and needs verification.</p>
                                        <table style='width:100%;margin:20px 0;border-collapse:collapse;'>
                                            <tr><td style='padding:8px 0;color:#6b7280;'>Name:</td><td style='padding:8px 0;font-weight:600;'>{$safe_name}</td></tr>
                                            <tr><td style='padding:8px 0;color:#6b7280;'>Email:</td><td style='padding:8px 0;font-weight:600;'>{$safe_email}</td></tr>
                                            <tr><td style='padding:8px 0;color:#6b7280;'>Phone:</td><td style='padding:8px 0;font-weight:600;'>{$safe_phone}</td></tr>
                                        </table>
                                        <p>Verification code:</p>
                                        <div style='font-size:34px;font-weight:bold;letter-spacing:10px;text-align:center;padding:22px;margin:20px 0;background:#f3f4f6;border-radius:10px;color:#111827;'>{$otp}</div>
                                        <p>This code expires in <strong>10 minutes</strong>.</p>
                                        <hr style='border:none;border-top:1px solid #e5e7eb;margin:25px 0;'>
                                        <p style='color:#6b7280;font-size:13px;'>Educ Bridge Security</p>
                                    </div>
                                ";

                                $mail->AltBody = "New admin registration.\nName: {$name}\nEmail: {$email}\nPhone: {$phone}\nOTP: {$otp}\nExpires in 10 minutes.";

                                $mail->send();
                                $email_sent = true;
                            } catch (Exception $e) {
                                $email_sent = false;
                            }
                        }

                        $_SESSION["admin_verify_email"] = $email;

                        if (!$email_sent) {
                            $_SESSION["admin_verify_otp_dev"] = $otp;
                        } else {
                            unset($_SESSION["admin_verify_otp_dev"]);
                        }

                        header("Location: admin-verify.php");
                        exit;

                    } else {
                        $message = "Registration failed. Please try again.";
                        $message_type = "error";
                    }

                    mysqli_stmt_close($insert_stmt);
                }
            }

            mysqli_stmt_close($check_stmt);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Registration | Educ Bridge</title>
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

        /* ===== TOP BAR ===== */
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
            background: transparent;
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

        .top-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

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
        .top-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-1px);
        }
        .top-btn.icon-only {
            width: 42px; height: 42px;
            padding: 0;
            border-radius: 50%;
        }
        .top-btn.icon-only:hover { transform: rotate(20deg); }

        /* ===== MAIN ===== */
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
            max-width: 500px;
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

        h1 {
            font-family: 'Poppins', sans-serif;
            text-align: center;
            font-size: 27px;
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
            margin-bottom: 28px;
            line-height: 1.6;
        }

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

        /* Divider */
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 26px 0 20px;
            color: var(--text-muted);
            font-size: 12.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border-color);
        }

        /* Bottom actions: Login + Forgot Password */
        .bottom-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .bottom-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            text-align: center;
        }
        .bottom-btn i { font-size: 13px; }
        .bottom-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }
        .bottom-btn.login-btn {
            background: var(--bg-alt);
            color: var(--text-primary);
            border-color: var(--border-color);
        }
        .bottom-btn.login-btn:hover {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
        }
        .bottom-btn.forgot-btn {
            background: transparent;
            color: var(--text-secondary);
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

        .card-back {
            text-align: center;
            margin-top: 22px;
            font-size: 14px;
        }
        .card-back a {
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
        }
        .card-back a:hover {
            color: var(--primary);
        }

        @media (max-width: 600px) {
            .top-bar { padding: 14px 4%; }
            .top-brand-name { display: none; }
        }
        @media (max-width: 480px) {
            .card { padding: 32px 24px; }
            h1 { font-size: 22px; }
            .bottom-actions { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ===== TOP BAR (Brand + Theme toggle only) ===== -->
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

<!-- ===== REGISTRATION CARD ===== -->
<main class="main-wrap">
    <div class="card">

        <div class="card-logo">
            <img src="logo.png" alt="Educ Bridge Logo">
        </div>

        <h1>Admin <span>Registration</span></h1>
        <p class="subtitle">Create the main Educ Bridge administrator account</p>

        <?php if ($message !== ""): ?>
            <div class="message <?= htmlspecialchars($message_type); ?>">
                <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                <span><?= htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">

            <div class="form-group">
                <label for="name">Full Name <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="text" id="name" name="name" maxlength="150" placeholder="e.g. John Doe" value="<?= htmlspecialchars($name); ?>" required>
                    <i class="fas fa-user input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="email">Email Address <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="email" id="email" name="email" maxlength="150" placeholder="you@example.com" value="<?= htmlspecialchars($email); ?>" required>
                    <i class="fas fa-envelope input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="phone">Phone Number <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="tel" id="phone" name="phone" maxlength="10" pattern="[0-9]{10}" placeholder="10 digits (e.g. 0783561459)" value="<?= htmlspecialchars($phone); ?>" required>
                    <i class="fas fa-phone input-icon"></i>
                </div>
            </div>

            <div class="form-group">
                <label for="password">Password <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" minlength="8" placeholder="At least 8 characters" required>
                    <i class="fas fa-lock input-icon"></i>
                    <button type="button" class="toggle-password" data-target="password" aria-label="Show password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                <div class="input-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" minlength="8" placeholder="Re-enter your password" required>
                    <i class="fas fa-lock input-icon"></i>
                    <button type="button" class="toggle-password" data-target="confirm_password" aria-label="Show password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit">
                <i class="fas fa-user-plus"></i> Register Administrator
            </button>

        </form>

        <div class="info-note">
            <i class="fas fa-shield-alt"></i>
            <span>A <strong>6-digit verification code</strong> will be sent to our secure inbox. It expires in 10 minutes.</span>
        </div>

        <!-- ===== Login + Forgot Password (inside the card, after register) ===== -->
        <div class="divider">Already have an account?</div>

        <div class="bottom-actions">
            <a href="admin-login.php" class="bottom-btn login-btn">
                <i class="fas fa-sign-in-alt"></i> Login
            </a>
            <a href="admin-forgot-password.php" class="bottom-btn forgot-btn">
                <i class="fas fa-key"></i> Forgot Password
            </a>
        </div>

        <div class="card-back">
            <a href="index.php">
                <i class="fas fa-arrow-left"></i> Back to Educ Bridge
            </a>
        </div>

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