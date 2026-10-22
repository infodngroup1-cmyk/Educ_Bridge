<?php
session_start();
require_once 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (isset($_GET['reset'])) {
    unset($_SESSION['t_reset_teacher_id'], $_SESSION['t_reset_teacher'], $_SESSION['t_reset_last_sent']);
    header("Location: teacher-forgot-password.php");
    exit;
}

$message      = "";
$message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_otp') {

    $identifier = trim($_POST['identifier'] ?? '');

    if ($identifier === '') {
        $message = "Please enter your email or phone number.";
        $message_type = "error";
    } else {
        $stmt = mysqli_prepare($conn, "
            SELECT t.id, t.school_id, t.name, t.email, t.phone, t.registered, t.status,
                   s.name AS school_name, s.school_code, s.status AS school_status
            FROM teachers t
            INNER JOIN schools s ON s.id = t.school_id
            WHERE t.email = ? OR t.phone = ?
            LIMIT 1
        ");
        mysqli_stmt_bind_param($stmt, "ss", $identifier, $identifier);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        if (mysqli_num_rows($res) === 0) {
            $message = "No teacher account found with that email or phone number.";
            $message_type = "error";
            mysqli_stmt_close($stmt);
        } else {
            $t = mysqli_fetch_assoc($res);
            mysqli_stmt_close($stmt);

            if (strtolower($t['status']) !== 'active') {
                $message = "Your account is inactive. Please contact your school administrator.";
                $message_type = "error";
            } elseif (strtolower($t['school_status']) !== 'active') {
                $message = "Your school account is currently deactivated.";
                $message_type = "error";
            } elseif ((int)$t['registered'] !== 1) {
                $message = "Your account is not fully registered yet. Please register first.";
                $message_type = "error";
            } else {

                $otp      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $otp_hash = hash('sha256', $otp);
                $otp_exp  = date('Y-m-d H:i:s', time() + 600);

                $upd = mysqli_prepare($conn, "
                    UPDATE teachers
                    SET reset_hash = ?, reset_expires_at = ?, otp_attempts = 0
                    WHERE id = ?
                ");
                mysqli_stmt_bind_param($upd, "ssi", $otp_hash, $otp_exp, $t['id']);
                mysqli_stmt_execute($upd);
                mysqli_stmt_close($upd);

                $autoload = __DIR__ . '/vendor/autoload.php';
                $email_sent = false; $email_reason = '';

                if (file_exists($autoload)) {
                    require_once $autoload;
                    if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host = "smtp.gmail.com"; $mail->SMTPAuth = true;
                            $mail->Username = "info.dngroup1@gmail.com"; $mail->Password = "rsxm dxju dnvp yleu";
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; $mail->Port = 587;
                            $mail->setFrom("info.dngroup1@gmail.com", "Educ Bridge");
                            $mail->addAddress($t['email'], $t['name']);
                            $mail->isHTML(true);
                            $mail->Subject = "Password Reset Code — Educ Bridge";
                            $safe_name = htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8');
                            $mail->Body = "
                                <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;'>
                                    <div style='background:linear-gradient(135deg,#2563eb,#7c3aed);padding:28px 32px;color:#fff;'>
                                        <h1 style='margin:0;font-size:21px;font-weight:800;'>Educ Bridge</h1>
                                        <p style='margin:6px 0 0;font-size:13px;opacity:0.92;'>Password Reset Code</p>
                                    </div>
                                    <div style='padding:28px 32px;'>
                                        <p style='font-size:16px;margin:0 0 10px;font-weight:600;'>Hello <strong>{$safe_name}</strong>,</p>
                                        <p style='font-size:14.5px;color:#4b5563;margin:0 0 22px;'>Use this code to reset your password. It expires in 10 minutes.</p>
                                        <div style='text-align:center;background:#f9fafb;border:1px dashed #d1d5db;border-radius:12px;padding:22px;margin:0 0 20px;'>
                                            <div style='font-family:Courier,monospace;font-size:38px;font-weight:800;letter-spacing:11px;color:#2563eb;'>{$otp}</div>
                                        </div>
                                        <p style='font-size:13.5px;color:#6b7280;'>If you didn't request this, you can ignore this email.</p>
                                        <p style='font-size:13.5px;color:#6b7280;margin-top:14px;'>— The Educ Bridge Team</p>
                                    </div>
                                </div>
                            ";
                            $mail->AltBody = "Your Educ Bridge password reset code is: {$otp}";
                            $mail->send();
                            $email_sent = true;
                        } catch (Exception $e) {
                            $email_reason = $mail->ErrorInfo;
                        }
                    } else { $email_reason = "PHPMailer class not found."; }
                } else { $email_reason = "PHPMailer not installed."; }

                $_SESSION['t_reset_teacher_id'] = (int)$t['id'];
                $_SESSION['t_reset_teacher']    = [
                    'name'  => $t['name'],
                    'email' => $t['email'],
                    'phone' => $t['phone'],
                ];
                $_SESSION['t_reset_last_sent']  = time();

                if ($email_sent) {
                    header("Location: teacher-reset-verify.php?sent=1");
                } else {
                    header("Location: teacher-reset-verify.php?sent=0&reason=" . urlencode($email_reason));
                }
                exit;
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
            --success: #10b981; --danger: #ef4444;
            --bg-body: #f1f5f9; --bg-card: #ffffff; --bg-alt: #f8fafc;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --success: #34d399; --danger: #f87171;
            --bg-body: #0b1120; --bg-card: #1e293b; --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { min-height: 100vh; }
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); display:flex; align-items:center; justify-content:center; padding:24px 16px; transition: background 0.4s ease, color 0.4s ease; position: relative; }
        body::before { content:''; position:fixed; inset:0; background: radial-gradient(circle at 10% 10%, rgba(37,99,235,0.10), transparent 45%), radial-gradient(circle at 90% 90%, rgba(124,58,237,0.10), transparent 45%); pointer-events:none; z-index:0; }
        .theme-toggle { position:fixed; top:20px; right:20px; width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); z-index:20; }
        .theme-toggle:hover { color:var(--primary); border-color:var(--primary); transform: rotate(20deg); }

        .wrap { width:100%; max-width:480px; position:relative; z-index:1; }
        .brand { display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:22px; }
        .brand img { width:48px; height:48px; border-radius:50%; background:#fff; padding:3px; border:2px solid var(--border-color); object-fit:contain; }
        .brand-text { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; letter-spacing:-0.4px; }
        .brand-text span { color: var(--primary); }

        .card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 20px; box-shadow: var(--shadow-lg); overflow: hidden; }
        .card-head { padding: 30px 30px 22px; border-bottom: 1px solid var(--border-color); background: linear-gradient(135deg, rgba(37,99,235,0.07), rgba(124,58,237,0.05)); text-align: center; }
        .head-icon { width: 60px; height: 60px; border-radius: 50%; background: var(--primary-light); color: var(--primary); display: inline-flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 14px; }
        .card-head h1 { font-family: 'Poppins', sans-serif; font-size: 22px; font-weight: 700; margin-bottom: 6px; }
        .card-head p { font-size: 14px; color: var(--text-muted); line-height: 1.55; }
        .card-body { padding: 28px 30px 30px; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.5; }
        .alert-error { background: rgba(239,68,68,0.08); color: #dc2626; border: 1px solid rgba(239,68,68,0.25); }
        [data-theme="dark"] .alert-error { color: #f87171; }
        .alert-success { background: rgba(16,185,129,0.08); color: #059669; border: 1px solid rgba(16,185,129,0.25); }

        .form-group { margin-bottom: 18px; }
        .form-group label { display:block; font-size:13.5px; font-weight:600; color:var(--text-primary); margin-bottom:8px; }
        .form-group label .required { color: #ef4444; }

        .form-group input { width:100%; padding:13px 15px; border:1.5px solid var(--border-color); border-radius:10px; background:var(--bg-alt); color:var(--text-primary); font-family:'Inter',sans-serif; font-size:14.5px; outline:none; transition:var(--transition); }
        .form-group input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); background: var(--bg-card); }
        .form-help { font-size: 12.5px; color: var(--text-muted); margin-top: 6px; }

        .btn { width:100%; display:inline-flex; align-items:center; justify-content:center; gap:9px; padding:14px 22px; border-radius:10px; font-size:14.5px; font-weight:700; cursor:pointer; border:none; background:var(--primary); color:#fff; transition:var(--transition); font-family:'Inter',sans-serif; box-shadow:0 6px 18px rgba(37,99,235,0.32); text-decoration:none; }
        .btn:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
        .btn-ghost { background:transparent; color:var(--text-secondary); border:1.5px solid var(--border-color); box-shadow:none; margin-top:10px; font-weight:600; }
        .btn-ghost:hover { background:var(--bg-alt); color:var(--primary); border-color:var(--primary); box-shadow:none; }

        .foot { text-align:center; margin-top:20px; font-size:14px; color:var(--text-muted); }
        .foot a { color: var(--primary); font-weight: 600; text-decoration: none; }
        .foot a:hover { text-decoration: underline; }

        @media (max-width: 480px) {
            .card-head { padding:24px 20px 18px; }
            .card-body { padding:22px 20px 24px; }
        }
    </style>
</head>
<body>

<button class="theme-toggle" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>

<div class="wrap">

    <div class="brand">
        <img src="logo.png" alt="Educ Bridge">
        <div class="brand-text">Educ<span>Bridge</span></div>
    </div>

    <div class="card">
        <div class="card-head">
            <div class="head-icon"><i class="fas fa-key"></i></div>
            <h1>Forgot Password</h1>
            <p>Enter your email or phone number and we'll send a reset code.</p>
        </div>

        <div class="card-body">

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="teacher-forgot-password.php" autocomplete="off" id="fpForm">
                <input type="hidden" name="action" value="request_otp">

                <div class="form-group">
                    <label for="identifier">Email or Phone Number <span class="required">*</span></label>
                    <input type="text" id="identifier" name="identifier" placeholder="teacher@example.com or 07XX XXX XXX" required autofocus value="<?= htmlspecialchars($_POST['identifier'] ?? ''); ?>">
                    <div class="form-help">We'll email a 6-digit code to the address on your account.</div>
                </div>

                <button type="submit" class="btn" id="fpBtn">
                    <i class="fas fa-paper-plane"></i> Send Reset Code
                </button>

                <a href="teacher-login.php" class="btn btn-ghost">
                    <i class="fas fa-arrow-left"></i> Back to sign in
                </a>
            </form>

        </div>
    </div>

    <div class="foot">Need help? <a href="mailto:info.dngroup1@gmail.com">Contact support</a></div>

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

    document.getElementById('fpForm').addEventListener('submit', function () {
        const btn = document.getElementById('fpBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending code...';
    });
</script>

</body>
</html>