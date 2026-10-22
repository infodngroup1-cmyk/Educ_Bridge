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
| GMAIL SMTP SETTINGS — SAME AS REGISTER
|--------------------------------------------------------------------------
| OTP is sent to the OFFICIAL admin inbox, NOT the applicant's email.
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

/*
|--------------------------------------------------------------------------
| REQUIRE SESSION EMAIL (the applicant's email stored at register time)
|--------------------------------------------------------------------------
| This identifies WHICH admin account we are verifying.
| The OTP itself is delivered to the official inbox above.
*/

if (empty($_SESSION["admin_verify_email"])) {
    header("Location: admin-register.php");
    exit;
}

$email = $_SESSION["admin_verify_email"];

$dev_otp = $_SESSION["admin_verify_otp_dev"] ?? null;

/*
|--------------------------------------------------------------------------
| HANDLE RESEND CODE
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["resend_otp"])) {

    // Fetch admin record to confirm it exists and isn't verified yet
    $sql = "SELECT id, name, email, phone, verified FROM admin WHERE email = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        $message = "Database error. Please try again.";
        $message_type = "error";
    } else {

        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($result) === 0) {
            $message = "No account found for this email.";
            $message_type = "error";
        } else {

            $admin = mysqli_fetch_assoc($result);

            if ((int) $admin["verified"] === 1) {
                $message = "Your account is already verified. You can log in.";
                $message_type = "success";
            } else {

                // Generate a fresh OTP valid 10 minutes
                $otp = str_pad((string) random_int(0, 999999), 6, "0", STR_PAD_LEFT);
                $otp_expires_at = date("Y-m-d H:i:s", time() + (10 * 60));

                $update_sql = "UPDATE admin SET otp_code = ?, otp_expires_at = ? WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_sql);

                if (!$update_stmt) {
                    $message = "Unable to resend the verification code. Please try again.";
                    $message_type = "error";
                } else {

                    mysqli_stmt_bind_param($update_stmt, "ssi", $otp, $otp_expires_at, $admin["id"]);

                    if (!mysqli_stmt_execute($update_stmt)) {
                        $message = "Unable to resend the verification code. Please try again.";
                        $message_type = "error";
                    } else {

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

                                // Always sent to OFFICIAL inbox (same as register)
                                $mail->addAddress($otp_recipient_email, "Educ Bridge Admin");

                                $mail->isHTML(true);
                                $mail->Subject = "Resent Admin Registration OTP - Educ Bridge";

                                $safe_name  = htmlspecialchars($admin["name"],  ENT_QUOTES, 'UTF-8');
                                $safe_email = htmlspecialchars($admin["email"], ENT_QUOTES, 'UTF-8');
                                $safe_phone = htmlspecialchars($admin["phone"], ENT_QUOTES, 'UTF-8');

                                $mail->Body = "
                                    <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:25px;border:1px solid #e5e7eb;border-radius:12px;background:#ffffff;'>
                                        <h2 style='color:#2563eb;margin-bottom:20px;'>Educ Bridge — Resent Admin OTP</h2>
                                        <p>This is a resent verification code for a pending administrator registration.</p>
                                        <table style='width:100%;margin:20px 0;border-collapse:collapse;'>
                                            <tr><td style='padding:8px 0;color:#6b7280;'>Name:</td><td style='padding:8px 0;font-weight:600;'>{$safe_name}</td></tr>
                                            <tr><td style='padding:8px 0;color:#6b7280;'>Email:</td><td style='padding:8px 0;font-weight:600;'>{$safe_email}</td></tr>
                                            <tr><td style='padding:8px 0;color:#6b7280;'>Phone:</td><td style='padding:8px 0;font-weight:600;'>{$safe_phone}</td></tr>
                                        </table>
                                        <p>New verification code:</p>
                                        <div style='font-size:34px;font-weight:bold;letter-spacing:10px;text-align:center;padding:22px;margin:20px 0;background:#f3f4f6;border-radius:10px;color:#111827;'>{$otp}</div>
                                        <p>This code expires in <strong>10 minutes</strong>.</p>
                                        <hr style='border:none;border-top:1px solid #e5e7eb;margin:25px 0;'>
                                        <p style='color:#6b7280;font-size:13px;'>Educ Bridge Security</p>
                                    </div>
                                ";

                                $mail->AltBody =
                                    "Resent admin registration OTP.\n" .
                                    "Name: {$admin['name']}\n" .
                                    "Email: {$admin['email']}\n" .
                                    "Phone: {$admin['phone']}\n" .
                                    "OTP: {$otp}\n" .
                                    "Expires in 10 minutes.";

                                $mail->send();
                                $email_sent = true;
                            } catch (Exception $e) {
                                $email_sent = false;
                            }
                        }

                        if (!$email_sent) {
                            $_SESSION["admin_verify_otp_dev"] = $otp;
                            $dev_otp = $otp;
                        } else {
                            unset($_SESSION["admin_verify_otp_dev"]);
                            $dev_otp = null;
                        }

                        $message = "A new verification code has been sent to our official inbox.";
                        $message_type = "success";
                    }

                    mysqli_stmt_close($update_stmt);
                }
            }
        }

        mysqli_stmt_close($stmt);
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE OTP SUBMISSION
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["otp"])) {

    $otp_input = trim($_POST["otp"] ?? "");

    if ($otp_input === "") {
        $message = "Please enter the verification code.";
        $message_type = "error";
    } elseif (!preg_match('/^[0-9]{6}$/', $otp_input)) {
        $message = "The verification code must be exactly 6 digits.";
        $message_type = "error";
    } else {

        $sql = "SELECT id, name, otp_code, otp_expires_at, verified FROM admin WHERE email = ? LIMIT 1";
        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            $message = "Database error. Please try again.";
            $message_type = "error";
        } else {

            mysqli_stmt_bind_param($stmt, "s", $email);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($result) === 0) {
                $message = "No account found for this email.";
                $message_type = "error";
            } else {

                $admin = mysqli_fetch_assoc($result);

                if ((int) $admin["verified"] === 1) {
                    unset($_SESSION["admin_verify_email"]);
                    unset($_SESSION["admin_verify_otp_dev"]);
                    $message = "Your account is already verified. You can log in.";
                    $message_type = "success";
                } elseif ($admin["otp_code"] !== $otp_input) {
                    $message = "The verification code is incorrect.";
                    $message_type = "error";
                } elseif (strtotime($admin["otp_expires_at"]) < time()) {
                    $message = "This verification code has expired. Please request a new one.";
                    $message_type = "error";
                } else {

                    $update_sql = "UPDATE admin SET verified = 1, otp_code = NULL, otp_expires_at = NULL WHERE id = ?";
                    $update_stmt = mysqli_prepare($conn, $update_sql);

                    if (!$update_stmt) {
                        $message = "Unable to verify account. Please try again.";
                        $message_type = "error";
                    } else {

                        mysqli_stmt_bind_param($update_stmt, "i", $admin["id"]);

                        if (mysqli_stmt_execute($update_stmt)) {
                            unset($_SESSION["admin_verify_email"]);
                            unset($_SESSION["admin_verify_otp_dev"]);
                            $message = "Account verified successfully! You can now log in.";
                            $message_type = "success";
                        } else {
                            $message = "Verification failed. Please try again.";
                            $message_type = "error";
                        }

                        mysqli_stmt_close($update_stmt);
                    }
                }
            }

            mysqli_stmt_close($stmt);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Admin Account | Educ Bridge</title>
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
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 15px;
            transition: background 0.4s ease, color 0.4s ease;
            position: relative;
            overflow-x: hidden;
        }
        body::before {
            content: '';
            position: fixed;
            top: -50%; right: -20%;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.08) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        body::after {
            content: '';
            position: fixed;
            bottom: -30%; left: -10%;
            width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(124, 58, 237, 0.06) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            width: 42px;
            height: 42px;
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
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }
        .theme-toggle:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: rotate(20deg);
        }

        .card {
            width: 100%;
            max-width: 460px;
            background: var(--bg-card);
            padding: 40px 38px;
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border-color);
            position: relative;
            z-index: 1;
            transition: var(--transition);
        }

        .logo {
            text-align: center;
            margin-bottom: 20px;
        }
        .logo img {
            width: 80px;
            height: 80px;
            object-fit: contain;
            border-radius: 50%;
            border: 2px solid var(--border-color);
            padding: 4px;
            background: var(--bg-card);
            transition: var(--transition);
        }
        .logo img:hover {
            border-color: var(--primary);
            transform: rotate(-5deg) scale(1.05);
        }

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
            margin-bottom: 8px;
            line-height: 1.6;
        }

        .email-badge {
            display: block;
            text-align: center;
            font-size: 13.5px;
            color: var(--primary);
            background: var(--primary-light);
            padding: 8px 14px;
            border-radius: 50px;
            margin: 0 auto 12px;
            max-width: fit-content;
            font-weight: 600;
            word-break: break-all;
        }
        [data-theme="dark"] .email-badge { background: rgba(59, 130, 246, 0.15); }

        .recipient-note {
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .message {
            padding: 13px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.5;
        }
        .error {
            background: rgba(239, 68, 68, 0.08);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.25);
        }
        [data-theme="dark"] .error { color: #f87171; }
        .success {
            background: rgba(16, 185, 129, 0.08);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }
        [data-theme="dark"] .success { color: #34d399; }

        .form-group { margin-bottom: 20px; }

        label {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
            text-align: center;
        }

        .otp-input {
            width: 100%;
            padding: 18px 16px;
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 12px;
            text-align: center;
            color: var(--text-primary);
            background: var(--bg-body);
            outline: none;
            transition: var(--transition);
        }
        .otp-input::placeholder {
            color: var(--text-muted);
            letter-spacing: 8px;
            font-weight: 500;
            opacity: 0.5;
        }
        .otp-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
        }

        button[type="submit"].primary-action {
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
        }
        button[type="submit"].primary-action:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }

        /* Resend button (secondary) */
        button[type="submit"].resend-action {
            width: 100%;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            padding: 13px;
            background: var(--bg-alt);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            margin-top: 12px;
        }
        button[type="submit"].resend-action:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--bg-card);
            transform: translateY(-1px);
        }
        button[type="submit"].resend-action i { font-size: 13px; }

        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 24px 0 20px;
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

        .back {
            text-align: center;
            font-size: 14px;
        }
        .back a {
            color: var(--primary);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
            transition: var(--transition);
        }
        .back a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .dev-otp {
            margin-top: 20px;
            padding: 14px 16px;
            background: rgba(245, 158, 11, 0.08);
            border: 1px dashed rgba(245, 158, 11, 0.4);
            border-radius: 10px;
            font-size: 13px;
            color: #b45309;
            text-align: center;
            line-height: 1.6;
        }
        [data-theme="dark"] .dev-otp { color: #fbbf24; }
        .dev-otp strong {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            letter-spacing: 4px;
            display: block;
            margin-top: 6px;
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

        @media (max-width: 480px) {
            .card { padding: 32px 24px; }
            h1 { font-size: 22px; }
            .otp-input { font-size: 22px; letter-spacing: 8px; padding: 15px 12px; }
        }
    </style>
</head>
<body>

<button class="theme-toggle" id="themeToggle" aria-label="Toggle theme" title="Toggle theme">
    <i class="fas fa-moon"></i>
</button>

<div class="card">

    <div class="logo">
        <img src="logo.png" alt="Educ Bridge Logo">
    </div>

    <h1>Verify <span>Your Account</span></h1>
    <p class="subtitle">Enter the 6-digit verification code sent to</p>

    <span class="email-badge">
        <i class="fas fa-envelope" style="font-size:12px;"></i>
        <?= htmlspecialchars($otp_recipient_email); ?>
    </span>

    <p class="recipient-note">
        <i class="fas fa-info-circle" style="font-size:11px;"></i>
        For account: <strong><?= htmlspecialchars($email); ?></strong>
    </p>

    <?php if ($message !== ""): ?>
        <div class="message <?= htmlspecialchars($message_type); ?>">
            <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
            <span><?= htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <?php if ($message_type === "success" && strpos($message, "verified successfully") !== false): ?>

        <div class="divider">ready to go</div>

        <div class="back">
            <a href="admin-login.php">
                <i class="fas fa-sign-in-alt"></i> Go to Admin Login
            </a>
        </div>

    <?php else: ?>

        <form method="POST" autocomplete="off" style="margin:0;">

            <div class="form-group">
                <label for="otp">Verification Code</label>
                <input
                    type="text"
                    id="otp"
                    name="otp"
                    class="otp-input"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    placeholder="••••••"
                    autofocus
                    required
                >
            </div>

            <button type="submit" class="primary-action">
                <i class="fas fa-check-circle"></i> Verify Account
            </button>

        </form>

        <form method="POST" autocomplete="off" style="margin:0;">
            <input type="hidden" name="resend_otp" value="1">
            <button type="submit" class="resend-action">
                <i class="fas fa-redo-alt"></i> Resend Verification Code
            </button>
        </form>

        <?php if (!empty($dev_otp)): ?>
            <div class="dev-otp">
                <i class="fas fa-tools"></i> Dev Mode — Email not sent (PHPMailer missing)
                <strong><?= htmlspecialchars($dev_otp); ?></strong>
            </div>
        <?php endif; ?>

        <div class="info-note">
            <i class="fas fa-clock"></i>
            <span>The code expires in <strong>10 minutes</strong>. If it expires, click <strong>Resend Verification Code</strong> to get a new one.</span>
        </div>

        <div class="divider">or</div>

        <div class="back">
            <a href="admin-register.php">
                <i class="fas fa-arrow-left"></i> Back to Registration
            </a>
        </div>

    <?php endif; ?>

</div>

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

        const otpInput = document.getElementById('otp');
        if (otpInput) {
            otpInput.addEventListener('input', function () {
                this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
            });
        }
    })();
</script>

</body>
</html>