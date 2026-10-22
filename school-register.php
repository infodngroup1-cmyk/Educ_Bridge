<?php
session_start();
require_once 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/*
|--------------------------------------------------------------------------
| AUTO-CREATE school_accounts TABLE IF MISSING
|--------------------------------------------------------------------------
*/
$create_sql = "
CREATE TABLE IF NOT EXISTS school_accounts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    school_id       INT NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    otp_hash        VARCHAR(64)  DEFAULT NULL,
    otp_expires_at  DATETIME     DEFAULT NULL,
    otp_attempts    INT          DEFAULT 0,
    is_verified     TINYINT(1)   DEFAULT 0,
    created_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    verified_at     DATETIME     DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";
mysqli_query($conn, $create_sql);

/*
|--------------------------------------------------------------------------
| ALREADY VERIFIED → DASHBOARD
|--------------------------------------------------------------------------
*/
if (!empty($_SESSION['school_id']) && !empty($_SESSION['school_verified'])) {
    header("Location: school-dashboard.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| RESET REGISTRATION FLOW
|--------------------------------------------------------------------------
*/
if (isset($_GET['reset'])) {
    unset(
        $_SESSION['reg_step'],
        $_SESSION['reg_school'],
        $_SESSION['reg_school_id'],
        $_SESSION['reg_account_id'],
        $_SESSION['last_otp_sent']
    );
    header("Location: school-register.php");
    exit;
}

$message      = "";
$message_type = "";

/*
|--------------------------------------------------------------------------
| STEP 1: SUBMIT SCHOOL CODE → VALIDATE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_code') {

    $code = strtoupper(trim($_POST['school_code'] ?? ''));

    if ($code === '') {
        $message = "Please enter your school code.";
        $message_type = "error";
    } else {

        $stmt = mysqli_prepare($conn, "
            SELECT id, school_code, name, admin_name, country, province, district,
                   type, email, phone, status
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

                // Check status
                if (strtolower($s['status']) !== 'active') {
                    $message = "This school is currently deactivated. Please contact Educ Bridge support.";
                    $message_type = "error";
                } else {

                    // Check existing account
                    $chk = mysqli_prepare($conn, "SELECT id FROM school_accounts WHERE school_id = ? LIMIT 1");

                    if (!$chk) {
                        $message = "Database error: " . mysqli_error($conn);
                        $message_type = "error";
                    } else {
                        mysqli_stmt_bind_param($chk, "i", $s['id']);
                        mysqli_stmt_execute($chk);
                        $chk_res = mysqli_stmt_get_result($chk);

                        if (mysqli_num_rows($chk_res) > 0) {
                            $message = "An account already exists for this school. Please log in instead.";
                            $message_type = "error";
                            mysqli_stmt_close($chk);
                        } else {
                            mysqli_stmt_close($chk);

                            // Move to step 2
                            $_SESSION['reg_step']      = 2;
                            $_SESSION['reg_school']    = $s;
                            $_SESSION['reg_school_id'] = (int) $s['id'];
                        }
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| STEP 2: SUBMIT PASSWORD → INSERT ACCOUNT + SEND OTP
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_account') {

    if (empty($_SESSION['reg_school']) || empty($_SESSION['reg_school_id'])) {
        unset($_SESSION['reg_step'], $_SESSION['reg_school'], $_SESSION['reg_school_id']);
        header("Location: school-register.php");
        exit;
    }

    $school = $_SESSION['reg_school'];
    $sid    = (int) $_SESSION['reg_school_id'];

    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $message = "Password must be at least 8 characters long.";
        $message_type = "error";
    } elseif ($password !== $confirm) {
        $message = "Passwords do not match.";
        $message_type = "error";
    } else {

        // Re-check status
        $chk = mysqli_prepare($conn, "SELECT status FROM schools WHERE id = ? LIMIT 1");
        if (!$chk) {
            $message = "Database error: " . mysqli_error($conn);
            $message_type = "error";
        } else {
            mysqli_stmt_bind_param($chk, "i", $sid);
            mysqli_stmt_execute($chk);
            $chk_res = mysqli_stmt_get_result($chk);
            $row = mysqli_fetch_assoc($chk_res);
            mysqli_stmt_close($chk);

            if (!$row || strtolower($row['status']) !== 'active') {
                unset($_SESSION['reg_step'], $_SESSION['reg_school'], $_SESSION['reg_school_id']);
                $message = "This school is no longer active. Registration cannot continue.";
                $message_type = "error";
            } else {

                // Double-check account not exists
                $chk2 = mysqli_prepare($conn, "SELECT id FROM school_accounts WHERE school_id = ? LIMIT 1");
                if (!$chk2) {
                    $message = "Database error: " . mysqli_error($conn);
                    $message_type = "error";
                } else {
                    mysqli_stmt_bind_param($chk2, "i", $sid);
                    mysqli_stmt_execute($chk2);
                    $chk2_res = mysqli_stmt_get_result($chk2);

                    if (mysqli_num_rows($chk2_res) > 0) {
                        mysqli_stmt_close($chk2);
                        unset($_SESSION['reg_step'], $_SESSION['reg_school'], $_SESSION['reg_school_id']);
                        $message = "An account already exists for this school. Please log in.";
                        $message_type = "error";
                    } else {
                        mysqli_stmt_close($chk2);

                        $hash      = password_hash($password, PASSWORD_DEFAULT);
                        $otp       = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                        $otp_hash  = hash('sha256', $otp);
                        $otp_exp   = date('Y-m-d H:i:s', time() + 600); // 10 minutes

                        $ins = mysqli_prepare($conn, "
                            INSERT INTO school_accounts
                                (school_id, password_hash, otp_hash, otp_expires_at, otp_attempts, is_verified, created_at)
                            VALUES (?, ?, ?, ?, 0, 0, NOW())
                        ");

                        if (!$ins) {
                            $message = "Database error: " . mysqli_error($conn);
                            $message_type = "error";
                        } else {
                            mysqli_stmt_bind_param($ins, "isss", $sid, $hash, $otp_hash, $otp_exp);

                            if (mysqli_stmt_execute($ins)) {
                                $account_id = mysqli_insert_id($conn);
                                mysqli_stmt_close($ins);

                                /*
                                |--------------------------------------------------------------------------
                                | SEND OTP EMAIL
                                |--------------------------------------------------------------------------
                                */
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
                                            $mail->addAddress($school['email'], $school['name']);
                                            $mail->addReplyTo($smtp_from_email, $smtp_from_name);

                                            $mail->isHTML(true);
                                            $mail->Subject = "Your Educ Bridge Verification Code — " . $school['name'];

                                            $safe_school = htmlspecialchars($school['name'],       ENT_QUOTES, 'UTF-8');
                                            $safe_admin  = htmlspecialchars($school['admin_name'], ENT_QUOTES, 'UTF-8');
                                            $safe_code   = htmlspecialchars($school['school_code'],ENT_QUOTES, 'UTF-8');

                                            $mail->Body = "
                                                <div style='font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;padding:0;border:1px solid #e5e7eb;border-radius:12px;background:#ffffff;overflow:hidden;'>

                                                    <div style='background:linear-gradient(135deg,#2563eb,#7c3aed);padding:30px 34px;color:#ffffff;'>
                                                        <h1 style='margin:0;font-size:22px;font-weight:800;letter-spacing:-0.4px;'>Educ Bridge</h1>
                                                        <p style='margin:8px 0 0;font-size:13.5px;opacity:0.92;'>Account Verification</p>
                                                    </div>

                                                    <div style='padding:32px 34px;'>

                                                        <p style='font-size:17px;color:#0f172a;margin:0 0 10px;font-weight:600;'>
                                                            Hello <strong>{$safe_admin}</strong>,
                                                        </p>

                                                        <p style='font-size:14.5px;color:#4b5563;line-height:1.75;margin:0 0 22px;'>
                                                            Thank you for registering <strong>{$safe_school}</strong> on Educ Bridge.
                                                            Use the verification code below to activate your account. This code
                                                            expires in <strong>10 minutes</strong>.
                                                        </p>

                                                        <div style='text-align:center;background:#f9fafb;border:1px dashed #d1d5db;border-radius:14px;padding:26px 18px;margin:0 0 26px;'>
                                                            <p style='font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:0.8px;font-weight:700;margin:0 0 12px;'>
                                                                Your Verification Code
                                                            </p>
                                                            <div style='font-family:Courier,monospace;font-size:40px;font-weight:800;letter-spacing:12px;color:#2563eb;'>
                                                                {$otp}
                                                            </div>
                                                        </div>

                                                        <p style='font-size:14.5px;color:#4b5563;line-height:1.75;margin:0 0 20px;'>
                                                            If you did not attempt to register this school, please ignore this email.
                                                        </p>

                                                        <div style='background:#f9fafb;border-radius:12px;padding:16px 20px;margin:0 0 22px;border:1px solid #eef2f7;font-size:13.5px;color:#374151;'>
                                                            <p style='margin:0 0 6px;color:#6b7280;font-size:11.5px;text-transform:uppercase;letter-spacing:0.6px;font-weight:700;'>
                                                                Registration Details
                                                            </p>
                                                            <p style='margin:4px 0;'><strong>School Code:</strong> {$safe_code}</p>
                                                            <p style='margin:4px 0;'><strong>School:</strong> {$safe_school}</p>
                                                            <p style='margin:4px 0;'><strong>Administrator:</strong> {$safe_admin}</p>
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

                                            $mail->AltBody = "Hello {$school['admin_name']},\n\n"
                                                . "Your Educ Bridge verification code is: {$otp}\n\n"
                                                . "This code expires in 10 minutes.\n\n"
                                                . "School: {$school['name']}\n"
                                                . "School Code: {$school['school_code']}\n\n"
                                                . "— The Educ Bridge Team";

                                            $mail->send();
                                            $email_sent = true;

                                        } catch (Exception $e) {
                                            $email_sent   = false;
                                            $email_reason = $mail->ErrorInfo;
                                            file_put_contents(
                                                __DIR__ . '/school_register_email.log',
                                                "[" . date('Y-m-d H:i:s') . "] Account #{$account_id} ({$school['email']}) — " . $mail->ErrorInfo . "\n",
                                                FILE_APPEND
                                            );
                                        }
                                    }
                                }

                                /*
                                |--------------------------------------------------------------------------
                                | STORE PENDING ACCOUNT + REDIRECT TO VERIFY PAGE
                                |--------------------------------------------------------------------------
                                */
                                $_SESSION['reg_account_id'] = $account_id;
                                unset($_SESSION['reg_step'], $_SESSION['reg_school'], $_SESSION['reg_school_id']);

                                if ($email_sent) {
                                    header("Location: school-verify.php?sent=1");
                                } else {
                                    header("Location: school-verify.php?sent=0&reason=" . urlencode($email_reason));
                                }
                                exit;

                            } else {
                                $message = "Could not create the account. Please try again.";
                                $message_type = "error";
                                mysqli_stmt_close($ins);
                            }
                        }
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| DETERMINE CURRENT STEP
|--------------------------------------------------------------------------
*/
$step   = $_SESSION['reg_step'] ?? 1;
$school = $_SESSION['reg_school'] ?? null;

// If step is 2 but school missing → reset
if ($step === 2 && empty($school)) {
    unset($_SESSION['reg_step'], $_SESSION['reg_school'], $_SESSION['reg_school_id']);
    $step = 1;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Registration | Educ Bridge</title>
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

        .wrap {
            width: 100%;
            max-width: 540px;
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
            padding: 26px 30px 22px;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(135deg, rgba(37,99,235,0.07), rgba(124,58,237,0.05));
            text-align: center;
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
            line-height: 1.5;
        }
        .card-body { padding: 28px 30px 30px; }

        .steps {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 26px;
        }
        .step-dot {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .step-dot .num {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--bg-alt);
            border: 2px solid var(--border-color);
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12.5px;
            font-weight: 700;
            transition: var(--transition);
        }
        .step-dot.active .num {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
            box-shadow: 0 4px 12px rgba(37,99,235,0.35);
        }
        .step-dot.done .num {
            background: var(--success);
            border-color: var(--success);
            color: #fff;
        }
        .step-dot .lbl {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-muted);
        }
        .step-dot.active .lbl { color: var(--text-primary); }
        .step-line {
            width: 32px;
            height: 2px;
            background: var(--border-color);
            border-radius: 2px;
        }

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

        .school-preview {
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 22px;
        }
        .school-preview .sp-head {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px dashed var(--border-color);
        }
        .school-preview .sp-icon {
            width: 46px; height: 46px;
            border-radius: 12px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            box-shadow: 0 6px 16px rgba(37,99,235,0.35);
        }
        .school-preview .sp-name {
            font-size: 15.5px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.3;
        }
        .school-preview .sp-code {
            font-size: 12px;
            color: var(--primary);
            font-weight: 700;
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
            margin-top: 2px;
        }
        .school-preview .sp-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 16px;
        }
        .sp-item .lbl {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 700;
            margin-bottom: 3px;
        }
        .sp-item .val {
            font-size: 13.5px;
            color: var(--text-primary);
            font-weight: 600;
            word-break: break-word;
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
        .btn:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 8px 22px rgba(37,99,235,0.4); }
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
        .foot a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }
        .foot a:hover { text-decoration: underline; }

        @media (max-width: 480px) {
            .card-head { padding: 22px 20px 18px; }
            .card-body { padding: 22px 20px 24px; }
            .school-preview .sp-grid { grid-template-columns: 1fr; }
            .step-dot .lbl { display: none; }
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
            <h1>School Registration</h1>
            <p>
                <?php if ($step === 1): ?>
                    Enter your school code to begin registration.
                <?php else: ?>
                    Create a password for your school account.
                <?php endif; ?>
            </p>
        </div>

        <div class="card-body">

            <!-- STEPS -->
            <div class="steps">
                <div class="step-dot <?= $step === 1 ? 'active' : 'done'; ?>">
                    <span class="num">
                        <?php if ($step > 1): ?><i class="fas fa-check"></i><?php else: ?>1<?php endif; ?>
                    </span>
                    <span class="lbl">School Code</span>
                </div>
                <div class="step-line"></div>
                <div class="step-dot <?= $step === 2 ? 'active' : ''; ?>">
                    <span class="num">2</span>
                    <span class="lbl">Password</span>
                </div>
                <div class="step-line"></div>
                <div class="step-dot">
                    <span class="num">3</span>
                    <span class="lbl">Verify</span>
                </div>
            </div>

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($step === 1): ?>

                <!-- STEP 1: SCHOOL CODE -->
                <form method="POST" action="school-register.php" autocomplete="off">
                    <input type="hidden" name="action" value="check_code">

                    <div class="form-group">
                        <label for="school_code">School Code <span class="required">*</span></label>
                        <div class="input-icon">
                            <i class="fas fa-fingerprint"></i>
                            <input type="text" id="school_code" name="school_code"
                                   placeholder="e.g. GS-00001"
                                   maxlength="50" required
                                   value="<?= htmlspecialchars($_POST['school_code'] ?? ''); ?>">
                        </div>
                        <div class="form-help">
                            The code was provided by Educ Bridge when your school was onboarded.
                        </div>
                    </div>

                    <button type="submit" class="btn">
                        <i class="fas fa-search"></i> Verify School Code
                    </button>
                </form>

            <?php else: ?>

                <!-- STEP 2: PASSWORD -->
                <div class="school-preview">
                    <div class="sp-head">
                        <div class="sp-icon"><i class="fas fa-school"></i></div>
                        <div>
                            <div class="sp-name"><?= htmlspecialchars($school['name']); ?></div>
                            <div class="sp-code">
                                <i class="fas fa-fingerprint"></i>
                                <?= htmlspecialchars($school['school_code']); ?>
                            </div>
                        </div>
                    </div>
                    <div class="sp-grid">
                        <div class="sp-item">
                            <div class="lbl">Administrator</div>
                            <div class="val"><?= htmlspecialchars($school['admin_name']); ?></div>
                        </div>
                        <div class="sp-item">
                            <div class="lbl">Type</div>
                            <div class="val"><?= htmlspecialchars(ucfirst($school['type'])); ?></div>
                        </div>
                        <div class="sp-item">
                            <div class="lbl">Location</div>
                            <div class="val">
                                <?= htmlspecialchars($school['province']); ?>,
                                <?= htmlspecialchars($school['district']); ?>
                            </div>
                        </div>
                        <div class="sp-item">
                            <div class="lbl">Country</div>
                            <div class="val"><?= htmlspecialchars($school['country']); ?></div>
                        </div>
                        <div class="sp-item">
                            <div class="lbl">Email</div>
                            <div class="val"><?= htmlspecialchars($school['email']); ?></div>
                        </div>
                        <div class="sp-item">
                            <div class="lbl">Phone</div>
                            <div class="val"><?= htmlspecialchars($school['phone']); ?></div>
                        </div>
                    </div>
                </div>

                <form method="POST" action="school-register.php" autocomplete="off" id="accountForm">
                    <input type="hidden" name="action" value="create_account">

                    <div class="form-group">
                        <label for="password">Create Password <span class="required">*</span></label>
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="password" name="password"
                                   placeholder="Minimum 8 characters"
                                   minlength="8" required style="padding-right:42px;">
                            <button type="button" class="toggle-pass" data-target="password" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="form-help">Use at least 8 characters. Mix letters, numbers and symbols.</div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password <span class="required">*</span></label>
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="confirm_password" name="confirm_password"
                                   placeholder="Re-enter your password"
                                   minlength="8" required style="padding-right:42px;">
                            <button type="button" class="toggle-pass" data-target="confirm_password" tabindex="-1">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-help" style="margin-bottom:18px;">
                        <i class="fas fa-info-circle"></i>
                        A 6-digit verification code will be sent to
                        <strong><?= htmlspecialchars($school['email']); ?></strong>.
                    </div>

                    <button type="submit" class="btn" id="createBtn">
                        <i class="fas fa-user-plus"></i> Create Account &amp; Send Code
                    </button>

                    <a href="school-register.php?reset=1" class="btn btn-ghost">
                        <i class="fas fa-arrow-left"></i> Use a different school code
                    </a>
                </form>

            <?php endif; ?>

        </div>

    </div>

    <div class="foot">
        Already registered? <a href="school-login.php">Sign in here</a>
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
    const accForm = document.getElementById('accountForm');
    if (accForm) {
        accForm.addEventListener('submit', function () {
            const btn = document.getElementById('createBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating account...';
        });
    }
</script>

</body>
</html>