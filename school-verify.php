<?php
session_start();
require_once 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/*
|--------------------------------------------------------------------------
| ALREADY VERIFIED → GO TO DASHBOARD
|--------------------------------------------------------------------------
*/
if (!empty($_SESSION['school_id']) && !empty($_SESSION['school_verified'])) {
    header("Location: school-dashboard.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| REQUIRE PENDING REGISTRATION
|--------------------------------------------------------------------------
*/
$account_id = (int) ($_SESSION['reg_account_id'] ?? 0);

if ($account_id <= 0) {
    // No pending registration
    header("Location: school-register.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| LOAD ACCOUNT + SCHOOL
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare($conn, "
    SELECT a.id, a.school_id, a.otp_hash, a.otp_expires_at, a.otp_attempts, a.is_verified,
           s.school_code, s.name, s.admin_name, s.email, s.status
    FROM school_accounts a
    INNER JOIN schools s ON s.id = a.school_id
    WHERE a.id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, "i", $account_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($res) === 0) {
    unset($_SESSION['reg_account_id']);
    header("Location: school-register.php");
    exit;
}

$acc = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

// Already verified? Just log them in
if ((int) $acc['is_verified'] === 1) {
    $_SESSION['school_id']       = (int) $acc['school_id'];
    $_SESSION['school_name']     = $acc['name'];
    $_SESSION['school_admin']    = $acc['admin_name'];
    $_SESSION['school_email']    = $acc['email'];
    $_SESSION['school_code']     = $acc['school_code'];
    $_SESSION['school_verified'] = true;
    unset($_SESSION['reg_account_id']);
    header("Location: school-dashboard.php");
    exit;
}

$message      = "";
$message_type = "";

// Flash from redirect
if (isset($_GET['sent']) && $_GET['sent'] === '1') {
    $message = "We've emailed you a 6-digit verification code. Please check your inbox.";
    $message_type = "success";
} elseif (isset($_GET['sent']) && $_GET['sent'] === '0') {
    $reason = $_GET['reason'] ?? 'Unknown error.';
    $message = "Account created, but the verification email could not be sent: " . $reason
             . " — use the 'Resend code' button below.";
    $message_type = "error";
}

/*
|--------------------------------------------------------------------------
| HANDLE OTP SUBMIT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_otp') {

    $otp = trim($_POST['otp'] ?? '');
    $otp = preg_replace('/\D/', '', $otp); // digits only

    if (strlen($otp) !== 6) {
        $message = "Please enter the 6-digit code.";
        $message_type = "error";
    } elseif ((int) $acc['otp_attempts'] >= 5) {
        $message = "Too many incorrect attempts. Please request a new code.";
        $message_type = "error";
    } elseif (strtotime($acc['otp_expires_at']) < time()) {
        $message = "Your code has expired. Please request a new one.";
        $message_type = "error";
    } else {
        $hash = hash('sha256', $otp);

        if (hash_equals($acc['otp_hash'] ?? '', $hash)) {

            // Mark verified
            $upd = mysqli_prepare($conn, "
                UPDATE school_accounts
                SET is_verified = 1, verified_at = NOW(),
                    otp_hash = NULL, otp_expires_at = NULL, otp_attempts = 0
                WHERE id = ?
            ");
            mysqli_stmt_bind_param($upd, "i", $account_id);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

            // Log in
            $_SESSION['school_id']       = (int) $acc['school_id'];
            $_SESSION['school_name']     = $acc['name'];
            $_SESSION['school_admin']    = $acc['admin_name'];
            $_SESSION['school_email']    = $acc['email'];
            $_SESSION['school_code']     = $acc['school_code'];
            $_SESSION['school_verified'] = true;
            unset($_SESSION['reg_account_id']);

            header("Location: school-dashboard.php");
            exit;

        } else {
            // Bump attempts
            $upd = mysqli_prepare($conn, "UPDATE school_accounts SET otp_attempts = otp_attempts + 1 WHERE id = ?");
            mysqli_stmt_bind_param($upd, "i", $account_id);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

            $acc['otp_attempts'] = (int) $acc['otp_attempts'] + 1;
            $remaining = max(0, 5 - $acc['otp_attempts']);
            $message = "Incorrect code. You have {$remaining} attempt(s) left.";
            $message_type = "error";
        }
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE RESEND
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_otp') {

    // Rate limit: only allow resend every 45 seconds (based on otp_expires_at shifted)
    // Simplest: allow always, but here let's just enforce a soft wait via session
    $now = time();
    $last = $_SESSION['last_otp_sent'] ?? 0;

    if ($now - $last < 45) {
        $wait = 45 - ($now - $last);
        $message = "Please wait {$wait} second(s) before requesting a new code.";
        $message_type = "error";
    } else {
        $otp      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $otp_hash = hash('sha256', $otp);
        $otp_exp  = date('Y-m-d H:i:s', time() + 600);

        $upd = mysqli_prepare($conn, "
            UPDATE school_accounts
            SET otp_hash = ?, otp_expires_at = ?, otp_attempts = 0
            WHERE id = ?
        ");
        mysqli_stmt_bind_param($upd, "ssi", $otp_hash, $otp_exp, $account_id);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        // Refresh local copy
        $acc['otp_hash']       = $otp_hash;
        $acc['otp_expires_at'] = $otp_exp;
        $acc['otp_attempts']   = 0;

        // Send email
        $autoload = __DIR__ . '/vendor/autoload.php';
        $sent_ok = false;
        $sent_reason = '';

        if (!file_exists($autoload)) {
            $sent_reason = "PHPMailer is not installed.";
        } else {
            require_once $autoload;
            if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                $sent_reason = "PHPMailer class not found.";
            } else {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = "smtp.gmail.com";
                    $mail->SMTPAuth   = true;
                    $mail->Username   = "info.dngroup1@gmail.com";
                    $mail->Password   = "rsxm dxju dnvp yleu";
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;

                    $mail->setFrom("info.dngroup1@gmail.com", "Educ Bridge");
                    $mail->addAddress($acc['email'], $acc['name']);
                    $mail->addReplyTo("info.dngroup1@gmail.com", "Educ Bridge");

                    $mail->isHTML(true);
                    $mail->Subject = "Your Educ Bridge Verification Code — " . $acc['name'];

                    $safe_admin = htmlspecialchars($acc['admin_name'], ENT_QUOTES, 'UTF-8');
                    $safe_school= htmlspecialchars($acc['name'],       ENT_QUOTES, 'UTF-8');

                    $mail->Body = "
                        <div style='font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;padding:0;border:1px solid #e5e7eb;border-radius:12px;background:#fff;overflow:hidden;'>
                            <div style='background:linear-gradient(135deg,#2563eb,#7c3aed);padding:28px 32px;color:#fff;'>
                                <h1 style='margin:0;font-size:21px;font-weight:800;'>Educ Bridge</h1>
                                <p style='margin:6px 0 0;font-size:13px;opacity:0.92;'>New Verification Code</p>
                            </div>
                            <div style='padding:28px 32px;'>
                                <p style='font-size:16px;color:#0f172a;margin:0 0 10px;font-weight:600;'>
                                    Hello <strong>{$safe_admin}</strong>,
                                </p>
                                <p style='font-size:14.5px;color:#4b5563;line-height:1.7;margin:0 0 22px;'>
                                    Here is your new verification code for <strong>{$safe_school}</strong>.
                                    It expires in 10 minutes.
                                </p>
                                <div style='text-align:center;background:#f9fafb;border:1px dashed #d1d5db;border-radius:12px;padding:22px 18px;margin:0 0 24px;'>
                                    <div style='font-family:Courier,monospace;font-size:38px;font-weight:800;letter-spacing:11px;color:#2563eb;'>{$otp}</div>
                                </div>
                                <p style='font-size:14px;color:#4b5563;'>If you didn't request this, ignore this email.</p>
                                <p style='font-size:14px;color:#6b7280;margin-top:16px;'>— The Educ Bridge Team</p>
                            </div>
                            <div style='background:#f9fafb;border-top:1px solid #e5e7eb;padding:14px 32px;text-align:center;'>
                                <p style='font-size:12.5px;color:#6b7280;margin:0;'>Educ Bridge · Connecting Parents, Schools, and Education</p>
                            </div>
                        </div>
                    ";
                    $mail->AltBody = "Your Educ Bridge verification code is: {$otp}\nExpires in 10 minutes.";
                    $mail->send();
                    $sent_ok = true;
                } catch (Exception $e) {
                    $sent_reason = $mail->ErrorInfo;
                    file_put_contents(__DIR__ . '/school_register_email.log',
                        "[" . date('Y-m-d H:i:s') . "] Resend OTP #{$account_id} — {$sent_reason}\n", FILE_APPEND);
                }
            }
        }

        $_SESSION['last_otp_sent'] = $now;

        if ($sent_ok) {
            $message = "A new verification code has been sent to your email.";
            $message_type = "success";
        } else {
            $message = "Could not send email: " . $sent_reason;
            $message_type = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Account | Educ Bridge</title>
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

        .wrap { width: 100%; max-width: 500px; position: relative; z-index: 1; }

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
        .otp-icon {
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
        .card-body { padding: 26px 30px 30px; }

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

        .otp-inputs {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-bottom: 22px;
        }
        .otp-inputs input {
            width: 52px;
            height: 62px;
            text-align: center;
            font-size: 24px;
            font-weight: 800;
            font-family: 'Courier New', monospace;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-alt);
            color: var(--text-primary);
            outline: none;
            transition: var(--transition);
        }
        .otp-inputs input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
            background: var(--bg-card);
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
        .btn:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }

        .btn-ghost {
            background: transparent;
            color: var(--text-secondary);
            border: 1.5px solid var(--border-color);
            box-shadow: none;
            margin-top: 10px;
            font-weight: 600;
        }
        .btn-ghost:hover { background: var(--bg-alt); color: var(--primary); border-color: var(--primary); box-shadow: none; }

        .resend-line {
            text-align: center;
            font-size: 13.5px;
            color: var(--text-muted);
            margin-top: 16px;
        }
        .resend-line button {
            background: none;
            border: none;
            color: var(--primary);
            font-weight: 700;
            cursor: pointer;
            font-size: 13.5px;
            padding: 0;
            font-family: inherit;
        }
        .resend-line button:disabled { color: var(--text-muted); cursor: not-allowed; }
        .resend-line button:hover:not(:disabled) { text-decoration: underline; }

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
            .otp-inputs { gap: 7px; }
            .otp-inputs input { width: 44px; height: 54px; font-size: 20px; border-radius: 10px; }
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
            <div class="otp-icon"><i class="fas fa-envelope-open-text"></i></div>
            <h1>Verify Your Email</h1>
            <p>
                We sent a 6-digit code to<br>
                <strong><?= htmlspecialchars($acc['email']); ?></strong>
            </p>
        </div>

        <div class="card-body">

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="school-verify.php" id="otpForm" autocomplete="off">
                <input type="hidden" name="action" value="verify_otp">
                <input type="hidden" name="otp" id="otpHidden">

                <div class="otp-inputs" id="otpInputs">
                    <input type="text" inputmode="numeric" maxlength="1" data-index="0" autofocus>
                    <input type="text" inputmode="numeric" maxlength="1" data-index="1">
                    <input type="text" inputmode="numeric" maxlength="1" data-index="2">
                    <input type="text" inputmode="numeric" maxlength="1" data-index="3">
                    <input type="text" inputmode="numeric" maxlength="1" data-index="4">
                    <input type="text" inputmode="numeric" maxlength="1" data-index="5">
                </div>

                <button type="submit" class="btn" id="verifyBtn">
                    <i class="fas fa-check-circle"></i> Verify &amp; Continue
                </button>
            </form>

            <form method="POST" action="school-verify.php" style="margin-top:14px;">
                <input type="hidden" name="action" value="resend_otp">
                <button type="submit" class="btn btn-ghost" id="resendBtn">
                    <i class="fas fa-paper-plane"></i> Resend verification code
                </button>
            </form>

            <div class="resend-line">
                Wrong email? <a href="school-register.php?reset=1" style="color:var(--primary);font-weight:700;text-decoration:none;">Start over</a>
            </div>

        </div>

    </div>

    <div class="foot">
        Having trouble? <a href="mailto:info.dngroup1@gmail.com">Contact support</a>
    </div>

</div>

<script>
    // THEME
    (function () {
        const html = document.documentElement;
        const toggle = document.getElementById('themeToggle');
        const icon = toggle.querySelector('i');
        const saved = localStorage.getItem('educbridge-theme') || 'light';
        html.setAttribute('data-theme', saved);
        updateIcon(saved);

        toggle.addEventListener('click', function () {
            const next = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', next);
            localStorage.setItem('educbridge-theme', next);
            updateIcon(next);
        });

        function updateIcon(theme) {
            if (theme === 'dark') { icon.classList.remove('fa-moon'); icon.classList.add('fa-sun'); }
            else { icon.classList.remove('fa-sun'); icon.classList.add('fa-moon'); }
        }
    })();

    // OTP INPUT UX
    (function () {
        const inputs = Array.from(document.querySelectorAll('#otpInputs input'));
        const hidden = document.getElementById('otpHidden');
        const form   = document.getElementById('otpForm');

        function syncHidden() {
            hidden.value = inputs.map(i => i.value || '').join('');
        }

        inputs.forEach((input, idx) => {
            input.addEventListener('input', function (e) {
                let v = e.target.value.replace(/\D/g, '');
                if (v.length > 1) v = v.charAt(v.length - 1);
                e.target.value = v;
                syncHidden();
                if (v && idx < inputs.length - 1) {
                    inputs[idx + 1].focus();
                }
                if (inputs.every(i => i.value)) {
                    // auto submit
                    form.submit();
                }
            });

            input.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !e.target.value && idx > 0) {
                    inputs[idx - 1].focus();
                }
                if (e.key === 'ArrowLeft' && idx > 0) inputs[idx - 1].focus();
                if (e.key === 'ArrowRight' && idx < inputs.length - 1) inputs[idx + 1].focus();
            });

            input.addEventListener('paste', function (e) {
                e.preventDefault();
                const text = (e.clipboardData || window.clipboardData).getData('text') || '';
                const digits = text.replace(/\D/g, '').slice(0, 6).split('');
                digits.forEach((d, i) => {
                    if (inputs[i]) inputs[i].value = d;
                });
                syncHidden();
                const nextEmpty = inputs.find(i => !i.value);
                if (nextEmpty) nextEmpty.focus();
                if (inputs.every(i => i.value)) form.submit();
            });
        });
    })();
</script>

</body>
</html>