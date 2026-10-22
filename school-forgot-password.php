<?php
session_start();
require_once 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/*
|--------------------------------------------------------------------------
| CLEAR PREVIOUS RESET FLOW
|--------------------------------------------------------------------------
*/
if (isset($_GET['reset'])) {
    unset(
        $_SESSION['pwreset_account_id'],
        $_SESSION['pwreset_school_id'],
        $_SESSION['pwreset_school_name'],
        $_SESSION['pwreset_email'],
        $_SESSION['pwreset_admin'],
        $_SESSION['pwreset_last_sent']
    );
    header("Location: school-forgot-password.php");
    exit;
}

$message      = "";
$message_type = "";

/*
|--------------------------------------------------------------------------
| HANDLE SUBMIT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_otp') {

    $code = strtoupper(trim($_POST['school_code'] ?? ''));

    if ($code === '') {
        $message = "Please enter your school code.";
        $message_type = "error";
    } else {

        $stmt = mysqli_prepare($conn, "
            SELECT id, school_code, name, admin_name, email, phone, status
            FROM schools
            WHERE school_code = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $message = "Database error: " . mysqli_error($conn);
            $message_type = "error";
        } else {
            mysqli_stmt_bind_param($stmt, "s", $code);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);

            if (mysqli_num_rows($res) === 0) {
                $message = "No school found with that code. Please check and try again.";
                $message_type = "error";
                mysqli_stmt_close($stmt);
            } else {
                $s = mysqli_fetch_assoc($res);
                mysqli_stmt_close($stmt);

                if (strtolower($s['status']) !== 'active') {
                    $message = "This school is currently deactivated. Please contact Educ Bridge support.";
                    $message_type = "error";
                } else {

                    // Load account
                    $acc_stmt = mysqli_prepare($conn, "
                        SELECT id, is_verified
                        FROM school_accounts
                        WHERE school_id = ?
                        LIMIT 1
                    ");

                    if (!$acc_stmt) {
                        $message = "Database error: " . mysqli_error($conn);
                        $message_type = "error";
                    } else {
                        mysqli_stmt_bind_param($acc_stmt, "i", $s['id']);
                        mysqli_stmt_execute($acc_stmt);
                        $acc_res = mysqli_stmt_get_result($acc_stmt);

                        if (mysqli_num_rows($acc_res) === 0) {
                            $message = "No account exists for this school yet. Please register first.";
                            $message_type = "error";
                            mysqli_stmt_close($acc_stmt);
                        } else {
                            $acc = mysqli_fetch_assoc($acc_res);
                            mysqli_stmt_close($acc_stmt);

                            if ((int) $acc['is_verified'] !== 1) {
                                // Not verified — send them to verify first
                                $_SESSION['reg_account_id'] = (int) $acc['id'];
                                header("Location: school-verify.php");
                                exit;
                            }

                            // Generate OTP
                            $otp      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                            $otp_hash = hash('sha256', $otp);
                            $otp_exp  = date('Y-m-d H:i:s', time() + 600); // 10 min

                            $upd = mysqli_prepare($conn, "
                                UPDATE school_accounts
                                SET otp_hash = ?, otp_expires_at = ?, otp_attempts = 0
                                WHERE id = ?
                            ");
                            mysqli_stmt_bind_param($upd, "ssi", $otp_hash, $otp_exp, $acc['id']);
                            mysqli_stmt_execute($upd);
                            mysqli_stmt_close($upd);

                            // Send email
                            $autoload = __DIR__ . '/vendor/autoload.php';
                            $email_sent   = false;
                            $email_reason = '';

                            if (!file_exists($autoload)) {
                                $email_reason = "PHPMailer is not installed (vendor/autoload.php missing).";
                            } else {
                                require_once $autoload;
                                if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                                    $email_reason = "PHPMailer class not found.";
                                } else {

                                    $smtp_host       = "smtp.gmail.com";
                                    $smtp_port       = 587;
                                    $smtp_username   = "info.dngroup1@gmail.com";
                                    $smtp_password   = "rsxm dxju dnvp yleu";
                                    $smtp_from_email = "info.dngroup1@gmail.com";
                                    $smtp_from_name  = "Educ Bridge";

                                    $mail = new PHPMailer(true);

                                    try {
                                        $mail->isSMTP();
                                        $mail->Host       = $smtp_host;
                                        $mail->SMTPAuth   = true;
                                        $mail->Username   = $smtp_username;
                                        $mail->Password   = $smtp_password;
                                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                                        $mail->Port       = $smtp_port;

                                        $mail->setFrom($smtp_from_email, $smtp_from_name);
                                        $mail->addAddress($s['email'], $s['name']);
                                        $mail->addReplyTo($smtp_from_email, $smtp_from_name);

                                        $mail->isHTML(true);
                                        $mail->Subject = "Password Reset Code — " . $s['name'];

                                        $safe_school = htmlspecialchars($s['name'],       ENT_QUOTES, 'UTF-8');
                                        $safe_admin  = htmlspecialchars($s['admin_name'], ENT_QUOTES, 'UTF-8');
                                        $safe_code   = htmlspecialchars($s['school_code'],ENT_QUOTES, 'UTF-8');

                                        $mail->Body = "
                                            <div style='font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;padding:0;border:1px solid #e5e7eb;border-radius:12px;background:#ffffff;overflow:hidden;'>

                                                <div style='background:linear-gradient(135deg,#2563eb,#7c3aed);padding:30px 34px;color:#ffffff;'>
                                                    <h1 style='margin:0;font-size:22px;font-weight:800;letter-spacing:-0.4px;'>Educ Bridge</h1>
                                                    <p style='margin:8px 0 0;font-size:13.5px;opacity:0.92;'>Password Reset Request</p>
                                                </div>

                                                <div style='padding:32px 34px;'>

                                                    <p style='font-size:17px;color:#0f172a;margin:0 0 10px;font-weight:600;'>
                                                        Hello <strong>{$safe_admin}</strong>,
                                                    </p>

                                                    <p style='font-size:14.5px;color:#4b5563;line-height:1.75;margin:0 0 22px;'>
                                                        We received a request to reset the password for
                                                        <strong>{$safe_school}</strong> on Educ Bridge. Use the code
                                                        below to continue. It expires in <strong>10 minutes</strong>.
                                                    </p>

                                                    <div style='text-align:center;background:#f9fafb;border:1px dashed #d1d5db;border-radius:14px;padding:26px 18px;margin:0 0 26px;'>
                                                        <p style='font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:0.8px;font-weight:700;margin:0 0 12px;'>
                                                            Your Password Reset Code
                                                        </p>
                                                        <div style='font-family:Courier,monospace;font-size:40px;font-weight:800;letter-spacing:12px;color:#2563eb;'>
                                                            {$otp}
                                                        </div>
                                                    </div>

                                                    <p style='font-size:14.5px;color:#4b5563;line-height:1.75;margin:0 0 20px;'>
                                                        If you did not request a password reset, you can safely ignore this
                                                        email — your password will remain unchanged.
                                                    </p>

                                                    <div style='background:#f9fafb;border-radius:12px;padding:16px 20px;margin:0 0 22px;border:1px solid #eef2f7;font-size:13.5px;color:#374151;'>
                                                        <p style='margin:0 0 6px;color:#6b7280;font-size:11.5px;text-transform:uppercase;letter-spacing:0.6px;font-weight:700;'>
                                                            Request Details
                                                        </p>
                                                        <p style='margin:4px 0;'><strong>School Code:</strong> {$safe_code}</p>
                                                        <p style='margin:4px 0;'><strong>School:</strong> {$safe_school}</p>
                                                    </div>

                                                    <p style='font-size:14.5px;color:#4b5563;line-height:1.75;margin:0 0 6px;'>
                                                        Thank you!
                                                    </p>
                                                    <p style='font-size:13.5px;color:#6b7280;margin:0;'>
                                                        — The Educ Bridge Team
                                                    </p>

                                                </div>

                                                <div style='background:#f9fafb;border-top:1px solid #e5e7eb;padding:16px 34px;text-align:center;'>
                                                    <p style='font-size:12.5px;color:#6b7280;margin:0;'>
                                                        Educ Bridge · Connecting Parents, Schools, and Education
                                                    </p>
                                                </div>

                                            </div>
                                        ";

                                        $mail->AltBody = "Hello {$s['admin_name']},\n\n"
                                            . "Your Educ Bridge password reset code is: {$otp}\n\n"
                                            . "This code expires in 10 minutes.\n\n"
                                            . "School: {$s['name']}\n"
                                            . "School Code: {$s['school_code']}\n\n"
                                            . "If you didn't request this, ignore this email.\n\n"
                                            . "— The Educ Bridge Team";

                                        $mail->send();
                                        $email_sent = true;

                                    } catch (Exception $e) {
                                        $email_sent   = false;
                                        $email_reason = $mail->ErrorInfo;
                                        file_put_contents(
                                            __DIR__ . '/school_password_reset.log',
                                            "[" . date('Y-m-d H:i:s') . "] Account #{$acc['id']} ({$s['email']}) — " . $mail->ErrorInfo . "\n",
                                            FILE_APPEND
                                        );
                                    }
                                }
                            }

                            // Store pending reset info
                            $_SESSION['pwreset_account_id'] = (int) $acc['id'];
                            $_SESSION['pwreset_school_id']  = (int) $s['id'];
                            $_SESSION['pwreset_school_name']= $s['name'];
                            $_SESSION['pwreset_admin']      = $s['admin_name'];
                            $_SESSION['pwreset_email']      = $s['email'];
                            $_SESSION['pwreset_last_sent']  = time();

                            if ($email_sent) {
                                header("Location: school-reset-verify.php?sent=1");
                            } else {
                                header("Location: school-reset-verify.php?sent=0&reason=" . urlencode($email_reason));
                            }
                            exit;
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
    <title>Forgot Password | Educ Bridge</title>
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
            font-size: 22px; font-weight: 700; margin-bottom: 6px;
        }
        .card-head p {
            font-size: 14px; color: var(--text-muted); line-height: 1.55;
        }
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
        .input-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
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
            <div class="head-icon"><i class="fas fa-key"></i></div>
            <h1>Forgot Password</h1>
            <p>Enter your school code and we'll email a 6-digit code to reset your password.</p>
        </div>

        <div class="card-body">

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="school-forgot-password.php" autocomplete="off" id="fpForm">
                <input type="hidden" name="action" value="request_otp">

                <div class="form-group">
                    <label for="school_code">School Code <span class="required">*</span></label>
                    <div class="input-icon">
                        <i class="fas fa-fingerprint"></i>
                        <input type="text" id="school_code" name="school_code"
                               placeholder="e.g. GS-00001"
                               maxlength="50" required autofocus
                               value="<?= htmlspecialchars($_POST['school_code'] ?? ''); ?>">
                    </div>
                    <div class="form-help">
                        The code was given to your school when it was onboarded to Educ Bridge.
                    </div>
                </div>

                <button type="submit" class="btn" id="fpBtn">
                    <i class="fas fa-paper-plane"></i> Send Reset Code
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
            applyTheme(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
        });
    })();

    document.getElementById('fpForm').addEventListener('submit', function () {
        const btn = document.getElementById('fpBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending code...';
    });
</script>

</body>
</html>