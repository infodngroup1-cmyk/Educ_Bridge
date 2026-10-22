<?php

session_start();

require_once 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Redirect if not logged in
if (empty($_SESSION["admin_id"]) || empty($_SESSION["admin_verified"])) {
    header("Location: admin-login.php");
    exit;
}

$admin_id    = $_SESSION["admin_id"];
$admin_name  = $_SESSION["admin_name"];
$admin_email = $_SESSION["admin_email"];

// Initials
$initials = "";
foreach (explode(" ", trim($admin_name)) as $part) {
    if ($part !== "") {
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) break;
    }
}
if ($initials === "") $initials = "A";

/*
|--------------------------------------------------------------------------
| FETCH THE MESSAGE
|--------------------------------------------------------------------------
*/

$msg_id = isset($_GET["id"]) ? (int) $_GET["id"] : 0;

if ($msg_id <= 0) {
    header("Location: admin-messages.php");
    exit;
}

$stmt = mysqli_prepare($conn, "
    SELECT id, name, email, subject, message, reply, replied_at, status, created_at
    FROM messages
    WHERE id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, "i", $msg_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) === 0) {
    header("Location: admin-messages.php");
    exit;
}

$message = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| MARK AS READ
|--------------------------------------------------------------------------
*/

if ($message["status"] === "unread") {
    $mark = mysqli_prepare($conn, "UPDATE messages SET status = 'read' WHERE id = ?");
    mysqli_stmt_bind_param($mark, "i", $msg_id);
    mysqli_stmt_execute($mark);
    mysqli_stmt_close($mark);
    $message["status"] = "read";
}

/*
|--------------------------------------------------------------------------
| HANDLE REPLY
|--------------------------------------------------------------------------
*/

$success = "";
$error   = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $reply_body = trim($_POST["reply"] ?? "");

    if ($reply_body === "") {
        $error = "Please write your reply before sending.";
    } else {

        // Check PHPMailer availability
        $phpmailer_available = false;
        if (file_exists(__DIR__ . '/vendor/autoload.php')) {
            require_once __DIR__ . '/vendor/autoload.php';
            if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                $phpmailer_available = true;
            }
        }

        if (!$phpmailer_available) {
            $error = "PHPMailer is not installed. Please run <code>composer require phpmailer/phpmailer</code> in your project folder.";
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
                $mail->addAddress($message["email"], $message["name"]);
                $mail->addReplyTo($smtp_from_email, $smtp_from_name);

                $mail->isHTML(true);
                $mail->Subject = "Re: " . $message["subject"];

                $safe_name     = htmlspecialchars($message["name"], ENT_QUOTES, 'UTF-8');
                $safe_email    = htmlspecialchars($message["email"], ENT_QUOTES, 'UTF-8');
                $safe_body     = nl2br(htmlspecialchars($reply_body, ENT_QUOTES, 'UTF-8'));
                $safe_original = nl2br(htmlspecialchars($message["message"], ENT_QUOTES, 'UTF-8'));
                $safe_subject  = htmlspecialchars($message["subject"], ENT_QUOTES, 'UTF-8');

                $mail->Body = "
                    <div style='font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:auto;padding:0;border:1px solid #e5e7eb;border-radius:12px;background:#ffffff;overflow:hidden;'>
                        <div style='background:linear-gradient(135deg,#2563eb,#7c3aed);padding:26px 30px;color:#ffffff;'>
                            <h2 style='margin:0;font-size:21px;font-weight:700;letter-spacing:-0.3px;'>Educ Bridge</h2>
                            <p style='margin:6px 0 0;font-size:13px;opacity:0.92;'>Official Response</p>
                        </div>
                        <div style='padding:26px 30px 0;'>
                            <table style='width:100%;border-collapse:collapse;font-size:14px;color:#4b5563;'>
                                <tr><td style='padding:6px 0;width:90px;color:#6b7280;'><strong>From:</strong></td><td style='padding:6px 0;'>{$smtp_from_email}</td></tr>
                                <tr><td style='padding:6px 0;color:#6b7280;'><strong>To:</strong></td><td style='padding:6px 0;'>{$safe_name} &lt;{$safe_email}&gt;</td></tr>
                                <tr><td style='padding:6px 0;color:#6b7280;'><strong>Subject:</strong></td><td style='padding:6px 0;'>Re: {$safe_subject}</td></tr>
                            </table>
                            <hr style='border:none;border-top:1px solid #e5e7eb;margin:22px 0 0;'>
                        </div>
                        <div style='padding:24px 30px 10px;'>
                            <p style='font-size:15px;color:#0f172a;margin:0 0 16px;'>Hello <strong>{$safe_name}</strong>,</p>
                            <div style='padding:18px 20px;background:#f9fafb;border-left:4px solid #2563eb;border-radius:8px;margin:0 0 24px;line-height:1.85;font-size:14.5px;color:#1f2937;'>
                                {$safe_body}
                            </div>
                            <p style='font-size:15px;color:#0f172a;margin:0 0 6px;font-weight:600;'>Thank you! For any other messages sent will be responded Through Gmail External App </p>
                            <p style='font-size:13.5px;color:#6b7280;margin:0 0 24px;'>— Educ Bridge Team</p>
                        </div>
                        <div style='padding:0 30px 26px;'>
                            <hr style='border:none;border-top:1px solid #e5e7eb;margin:6px 0 20px;'>
                            <p style='font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:0.6px;margin:0 0 10px;font-weight:600;'>Your original message</p>
                            <div style='font-size:13.5px;color:#4b5563;line-height:1.75;padding:14px 18px;background:#f3f4f6;border-radius:8px;'>
                                <strong>Subject:</strong> {$safe_subject}<br><br>
                                {$safe_original}
                            </div>
                        </div>
                        <div style='background:#f9fafb;border-top:1px solid #e5e7eb;padding:18px 30px;text-align:center;'>
                            <p style='font-size:12.5px;color:#6b7280;margin:0;'>Educ Bridge · Connecting Parents, Schools, and Education</p>
                        </div>
                    </div>
                ";

                $mail->AltBody =
                    "From: {$smtp_from_email}\n" .
                    "To: {$message['name']} <{$message['email']}>\n" .
                    "Subject: Re: {$message['subject']}\n\n" .
                    "Hello {$message['name']},\n\n" .
                    "{$reply_body}\n\n" .
                    "Thank you!\n\n---\nYour original message:\n{$message['message']}\n\n" .
                    "Educ Bridge · Connecting Parents, Schools, and Education";

                $mail->send();

                /*
                |--------------------------------------------------------------------------
                | SAVE THE REPLY INTO THE messages TABLE
                |--------------------------------------------------------------------------
                */

                $replied_at = date("Y-m-d H:i:s");

                $save = mysqli_prepare($conn, "
                    UPDATE messages
                    SET reply = ?, replied_at = ?, replied_by = ?
                    WHERE id = ?
                ");
                mysqli_stmt_bind_param($save, "ssii", $reply_body, $replied_at, $admin_id, $msg_id);
                mysqli_stmt_execute($save);
                mysqli_stmt_close($save);

                // Update the in-memory record
                $message["reply"]      = $reply_body;
                $message["replied_at"] = $replied_at;

                $success = "Your reply has been sent and saved. Delivered to " . htmlspecialchars($message["email"]) . ".";

            } catch (Exception $e) {
                $error = "Failed to send reply. " . htmlspecialchars($mail->ErrorInfo);
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
    <title>Reply Message | Educ Bridge Admin</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(37, 99, 235, 0.18);
            --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.08);

            --bg-body: #f8fafc; --bg-card: #ffffff;
            --bg-alt: #f1f5f9;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.07);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --sidebar-bg: #020617;
            --sidebar-hover: rgba(255,255,255,0.05);
            --sidebar-active: rgba(59, 130, 246, 0.2);
            --sidebar-text: #94a3b8;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.06);

            --bg-body: #0b1120; --bg-card: #1e293b;
            --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { min-height: 100vh; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            transition: background 0.4s ease, color 0.4s ease;
            overflow-x: hidden;
        }
        .layout { display: flex; min-height: 100vh; }

        /* SIDEBAR */
        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            z-index: 100;
            transition: transform 0.3s ease;
            border-right: 1px solid var(--sidebar-border);
        }
        .sidebar-header {
            padding: 22px 22px 18px;
            border-bottom: 1px solid var(--sidebar-border);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sidebar-header img {
            width: 42px; height: 42px;
            object-fit: contain;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.15);
            padding: 2px;
            background: #fff;
        }
        .sidebar-brand {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }
        .sidebar-brand span { color: #60a5fa; }
        .sidebar-brand small {
            display: block;
            font-size: 11px;
            font-weight: 500;
            color: var(--sidebar-text);
            letter-spacing: 0.4px;
            margin-top: 2px;
            text-transform: uppercase;
        }
        .sidebar-nav { flex: 1; padding: 18px 12px; overflow-y: auto; }
        .nav-section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--sidebar-text);
            opacity: 0.6;
            padding: 12px 14px 8px;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            color: var(--sidebar-text);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-radius: 10px;
            margin-bottom: 4px;
            transition: var(--transition);
            position: relative;
        }
        .nav-item i.nav-icon {
            width: 20px;
            text-align: center;
            font-size: 15px;
            color: var(--sidebar-text);
            transition: var(--transition);
        }
        .nav-item:hover { background: var(--sidebar-hover); color: var(--sidebar-text-active); }
        .nav-item:hover i.nav-icon { color: #60a5fa; }
        .nav-item.active { background: var(--sidebar-active); color: var(--sidebar-text-active); }
        .nav-item.active i.nav-icon { color: #60a5fa; }
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0; top: 8px; bottom: 8px;
            width: 3px;
            background: #3b82f6;
            border-radius: 0 4px 4px 0;
        }
        .sidebar-profile {
            border-top: 1px solid var(--sidebar-border);
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.02);
        }
        .sidebar-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 700;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35);
        }
        .sidebar-profile-info { flex: 1; min-width: 0; }
        .sidebar-profile-info .p-name {
            font-size: 13.5px;
            font-weight: 600;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-profile-info .p-role {
            font-size: 11.5px;
            color: var(--sidebar-text);
            opacity: 0.75;
        }
        .sidebar-logout {
            width: 34px; height: 34px;
            border-radius: 8px;
            background: rgba(239, 68, 68, 0.12);
            color: #f87171;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 13px;
            flex-shrink: 0;
            transition: var(--transition);
        }
        .sidebar-logout:hover { background: #ef4444; color: #fff; }

        /* MAIN */
        .main {
            flex: 1;
            margin-left: 260px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-left 0.3s ease;
        }
        .topbar {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
            padding: 14px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .hamburger {
            display: none;
            width: 40px; height: 40px;
            border-radius: 10px;
            border: 1.5px solid var(--border-color);
            background: var(--bg-body);
            color: var(--text-secondary);
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 15px;
            transition: var(--transition);
        }
        .hamburger:hover { border-color: var(--primary); color: var(--primary); }
        .page-title {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.4px;
        }
        .page-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .topbar-actions { display: flex; align-items: center; gap: 10px; }
        .topbar-btn {
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
            text-decoration: none;
        }
        .topbar-btn:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
        .topbar-btn.theme-btn:hover { transform: rotate(20deg); }
        .topbar-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
            cursor: pointer;
        }

        /* CONTENT */
        .content { padding: 28px; flex: 1; }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 18px;
            transition: var(--transition);
        }
        .back-link:hover { color: var(--primary-dark); gap: 11px; }

        .reply-layout {
            display: grid;
            grid-template-columns: 1fr 1.3fr;
            gap: 24px;
            align-items: flex-start;
        }

        .panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 26px;
            box-shadow: var(--shadow-sm);
        }
        .panel-title {
            font-family: 'Poppins', sans-serif;
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.3px;
        }
        .panel-title i { color: var(--primary); font-size: 16px; }

        /* Sender */
        .sender {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        .sender-avatar {
            width: 54px; height: 54px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 700;
            flex-shrink: 0;
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
        }
        .sender-name {
            font-family: 'Poppins', sans-serif;
            font-size: 16px;
            font-weight: 700;
        }
        .sender-email {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .sender-date {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .orig-subject {
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--text-primary);
        }
        .orig-body {
            font-size: 14.5px;
            color: var(--text-secondary);
            line-height: 1.75;
            white-space: pre-wrap;
            word-wrap: break-word;
            background: var(--bg-alt);
            border-radius: 10px;
            padding: 16px 18px;
            border: 1px solid var(--border-color);
        }

        /* Reply form */
        .reply-form .form-group { margin-bottom: 18px; }
        .reply-form label {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        .reply-form textarea {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-body);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            font-size: 14.5px;
            line-height: 1.7;
            outline: none;
            resize: vertical;
            min-height: 220px;
            transition: var(--transition);
        }
        .reply-form textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 13px 22px;
            border-radius: 10px;
            font-size: 14.5px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-primary);
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
        }
        .btn:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
        .btn.primary {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
        }
        .btn.primary:hover { background: var(--primary-dark); color: #fff; transform: translateY(-2px); }
        .btn.danger { color: #dc2626; border-color: rgba(239,68,68,0.3); }
        .btn.danger:hover { background: #ef4444; color: #fff; border-color: #ef4444; }

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
        .alert-success {
            background: rgba(16, 185, 129, 0.08);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }
        [data-theme="dark"] .alert-success { color: #34d399; }
        .alert-error {
            background: rgba(239, 68, 68, 0.08);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.25);
        }
        [data-theme="dark"] .alert-error { color: #f87171; }

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            z-index: 90;
            backdrop-filter: blur(2px);
        }
        .overlay.show { display: block; }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); box-shadow: 0 0 40px rgba(0,0,0,0.2); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; }
            .hamburger { display: flex; }
            .reply-layout { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .content { padding: 20px 16px; }
            .topbar { padding: 12px 16px; }
            .panel { padding: 20px; }
            .page-title { font-size: 17px; }
        }
    </style>
</head>
<body>

<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="logo.png" alt="Educ Bridge">
            <div class="sidebar-brand">
                Educ<span>Bridge</span>
                <small>Admin Panel</small>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-title">Main</div>
            <a href="admin-dashboard.php" class="nav-item">
                <i class="fas fa-th-large nav-icon"></i> Dashboard
            </a>
            <a href="admin-messages.php" class="nav-item active">
                <i class="fas fa-envelope nav-icon"></i> Messages
            </a>
            <a href="admin-schools.php" class="nav-item">
                <i class="fas fa-school nav-icon"></i> Schools
            </a>
            <a href="admin-students.php" class="nav-item">
                <i class="fas fa-user-graduate nav-icon"></i> Students
            </a>
            <a href="admin-teachers.php" class="nav-item">
                <i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers
            </a>

            <div class="nav-section-title">Management</div>
            <a href="admin-courses.php" class="nav-item">
                <i class="fas fa-book nav-icon"></i> Courses
            </a>
            <a href="admin-events.php" class="nav-item">
                <i class="fas fa-calendar-alt nav-icon"></i> Events
            </a>
            <a href="admin-announcements.php" class="nav-item">
                <i class="fas fa-bullhorn nav-icon"></i> Announcements
            </a>

            <div class="nav-section-title">System</div>
            <a href="admin-reports.php" class="nav-item">
                <i class="fas fa-chart-line nav-icon"></i> Reports
            </a>
            <a href="admin-settings.php" class="nav-item">
                <i class="fas fa-cog nav-icon"></i> Settings
            </a>
        </nav>

        <div class="sidebar-profile">
            <div class="sidebar-avatar"><?= htmlspecialchars($initials); ?></div>
            <div class="sidebar-profile-info">
                <div class="p-name"><?= htmlspecialchars($admin_name); ?></div>
                <div class="p-role">Administrator</div>
            </div>
            <a href="logout.php" class="sidebar-logout" title="Logout" aria-label="Logout">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </aside>

    <div class="overlay" id="overlay"></div>

    <!-- MAIN -->
    <div class="main">

        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <div class="page-title">Reply to Message</div>
                    <div class="page-subtitle">Message #<?= (int) $message["id"]; ?></div>
                </div>
            </div>

            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme" title="Toggle theme">
                    <i class="fas fa-moon"></i>
                </button>
                <div class="topbar-avatar" title="<?= htmlspecialchars($admin_name); ?>">
                    <?= htmlspecialchars($initials); ?>
                </div>
            </div>
        </header>

        <main class="content">

            <a href="admin-messages.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Inbox
            </a>

            <?php if ($success !== ""): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle" style="margin-top:2px;"></i>
                    <span><?= $success; ?></span>
                </div>
            <?php endif; ?>

            <?php if ($error !== ""): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle" style="margin-top:2px;"></i>
                    <span><?= $error; ?></span>
                </div>
            <?php endif; ?>

            <div class="reply-layout">

                <!-- Original Message Panel -->
                <div class="panel">
                    <div class="panel-title">
                        <i class="fas fa-envelope-open-text"></i> Original Message
                    </div>

                    <div class="sender">
                        <div class="sender-avatar">
                            <?= strtoupper(substr($message['name'], 0, 1)); ?>
                        </div>
                        <div>
                            <div class="sender-name"><?= htmlspecialchars($message["name"]); ?></div>
                            <div class="sender-email">
                                <i class="fas fa-envelope" style="font-size:11px;"></i>
                                <?= htmlspecialchars($message["email"]); ?>
                            </div>
                            <div class="sender-date">
                                <i class="far fa-clock"></i>
                                <?= date('F j, Y · g:i A', strtotime($message["created_at"])); ?>
                            </div>
                        </div>
                    </div>

                    <div class="orig-subject">
                        <?= htmlspecialchars($message["subject"]); ?>
                    </div>

                    <div class="orig-body"><?= htmlspecialchars($message["message"]); ?></div>
                </div>

                <!-- Reply Panel -->
                <!-- Reply Panel -->
<div class="panel">
    <div class="panel-title">
        <i class="fas fa-reply"></i> <?= !empty($message['reply']) ? 'Your Reply' : 'Send a Reply'; ?>
    </div>

    <?php if (!empty($message['reply'])): ?>
        <div style="
            padding:16px 18px;
            background: var(--primary-light);
            border-left: 4px solid var(--primary);
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14.5px;
            color: var(--text-primary);
            line-height: 1.75;
            white-space: pre-wrap;
            word-wrap: break-word;
        ">
            <?= htmlspecialchars($message['reply']); ?>

            <div style="
                margin-top: 12px;
                padding-top: 10px;
                border-top: 1px solid rgba(37,99,235,0.25);
                font-size: 12px;
                color: var(--primary);
                font-weight: 600;
                display: flex;
                align-items: center;
                gap: 6px;
            ">
                <i class="far fa-check-circle"></i>
                Sent
                <?php if (!empty($message['replied_at'])): ?>
                    · <?= date('M j, Y · g:i A', strtotime($message['replied_at'])); ?>
                <?php endif; ?>
            </div>
        </div>

        <div style="
            font-size: 13.5px;
            color: var(--text-muted);
            margin-bottom: 14px;
        ">
            <i class="fas fa-info-circle"></i>
            You can send another reply below if needed.
        </div>
    <?php endif; ?>

    <form method="POST" class="reply-form">
                        <div class="form-group">
                            <label for="reply">Your Reply</label>
                            <textarea
                                id="reply"
                                name="reply"
                                placeholder="Write your reply to <?= htmlspecialchars($message['name']); ?>..."
                                required
                            ></textarea>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn primary">
                                <i class="fas fa-paper-plane"></i> Send Reply
                            </button>
                            <a href="mailto:<?= htmlspecialchars($message['email']); ?>?subject=Re: <?= urlencode($message['subject']); ?>" class="btn">
                                <i class="fas fa-envelope"></i> Open in Mail App
                            </a>
                            <a href="admin-messages.php?delete=<?= (int) $message['id']; ?>" class="btn danger" onclick="return confirm('Delete this message?');">
                                <i class="fas fa-trash"></i> Delete
                            </a>
                        </div>
                    </form>
                </div>

            </div>

        </main>
    </div>
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

        const hamburger = document.getElementById('hamburger');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');

        function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('show'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }

        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) closeSidebar();
            else openSidebar();
        });
        overlay.addEventListener('click', closeSidebar);
    })();
</script>

</body>
</html>