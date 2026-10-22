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

$admin_name = $_SESSION["admin_name"];

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
| RWANDA PROVINCES → DISTRICTS
|--------------------------------------------------------------------------
*/

$rwanda_provinces = [
    'Kigali City'         => ['Gasabo', 'Kicukiro', 'Nyarugenge'],
    'Southern Province'   => ['Gisagara', 'Huye', 'Kamonyi', 'Muhanga', 'Nyamagabe', 'Nyanza', 'Nyaruguru', 'Ruhango'],
    'Western Province'    => ['Karongi', 'Ngororero', 'Nyabihu', 'Nyamasheke', 'Rubavu', 'Rusizi', 'Rutsiro'],
    'Northern Province'   => ['Burera', 'Gakenke', 'Gicumbi', 'Musanze', 'Rulindo'],
    'Eastern Province'    => ['Bugesera', 'Gatsibo', 'Kayonza', 'Kirehe', 'Ngoma', 'Nyagatare', 'Rwamagana'],
];

/*
|--------------------------------------------------------------------------
| GET SCHOOL
|--------------------------------------------------------------------------
*/

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header("Location: admin-schools.php");
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM schools WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($res) === 0) {
    header("Location: admin-schools.php");
    exit;
}

$form = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

$original = $form;

/*
|--------------------------------------------------------------------------
| SCHOOL CODE (from database)
|--------------------------------------------------------------------------
*/

$school_code = !empty($form['school_code']) ? $form['school_code'] : '—';

/*
|--------------------------------------------------------------------------
| FORM SUBMISSION
|--------------------------------------------------------------------------
*/

$message      = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $form['name']       = trim($_POST['name'] ?? '');
    $form['admin_name'] = trim($_POST['admin_name'] ?? '');
    $form['country']    = trim($_POST['country'] ?? 'Rwanda');
    $form['province']   = trim($_POST['province'] ?? '');
    $form['district']   = trim($_POST['district'] ?? '');
    $form['type']       = trim($_POST['type'] ?? '');
    $form['email']      = trim($_POST['email'] ?? '');
    $form['phone']      = trim($_POST['phone'] ?? '');
    $form['status']     = trim($_POST['status'] ?? 'active');

    $notify_school = isset($_POST['notify_school']) && $_POST['notify_school'] == '1';

    // Validate
    if (
        $form['name'] === '' || $form['admin_name'] === '' ||
        $form['province'] === '' || $form['district'] === '' ||
        $form['type'] === '' || $form['email'] === '' || $form['phone'] === ''
    ) {
        $message = "Please fill in all required fields.";
        $message_type = "error";
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $message_type = "error";
    } elseif (!preg_match('/^[0-9+\s\-]{7,20}$/', $form['phone'])) {
        $message = "Please enter a valid phone number.";
        $message_type = "error";
    } elseif ($form['type'] !== 'public' && $form['type'] !== 'private') {
        $message = "Please select a valid school type.";
        $message_type = "error";
    } elseif (!in_array($form['status'], ['active', 'deactivated'])) {
        $message = "Please select a valid status.";
        $message_type = "error";
    } else {

        // Duplicate email check
        $chk = mysqli_prepare($conn, "SELECT id FROM schools WHERE email = ? AND id <> ? LIMIT 1");
        mysqli_stmt_bind_param($chk, "si", $form['email'], $id);
        mysqli_stmt_execute($chk);
        $chk_res = mysqli_stmt_get_result($chk);

        if (mysqli_num_rows($chk_res) > 0) {
            $message = "Another school is already using this email.";
            $message_type = "error";
            mysqli_stmt_close($chk);
        } else {
            mysqli_stmt_close($chk);

            // Build changes list
            $changes = [];
            $field_labels = [
                'name'       => 'School Name',
                'admin_name' => 'Administrator',
                'country'    => 'Country',
                'province'   => 'Province / Region',
                'district'   => 'District',
                'type'       => 'School Type',
                'email'      => 'Email',
                'phone'      => 'Phone',
                'status'     => 'Status',
            ];
            foreach ($field_labels as $field => $label) {
                $old_v = (string) ($original[$field] ?? '');
                $new_v = (string) ($form[$field] ?? '');
                if ($old_v !== $new_v) {
                    $changes[$label] = ['old' => $old_v, 'new' => $new_v];
                }
            }

            // UPDATE
            $upd = mysqli_prepare($conn, "
                UPDATE schools
                SET name = ?, admin_name = ?, country = ?, province = ?, district = ?,
                    type = ?, email = ?, phone = ?, status = ?
                WHERE id = ?
            ");
            mysqli_stmt_bind_param(
                $upd, "sssssssssi",
                $form['name'], $form['admin_name'], $form['country'],
                $form['province'], $form['district'], $form['type'],
                $form['email'], $form['phone'], $form['status'], $id
            );

            if (mysqli_stmt_execute($upd)) {
                mysqli_stmt_close($upd);

                $email_sent   = false;
                $email_reason = '';

                /*
                |--------------------------------------------------------------------------
                | SEND NOTIFICATION EMAIL
                |--------------------------------------------------------------------------
                */

                if ($notify_school) {

                    // Check PHPMailer
                    $autoload = __DIR__ . '/vendor/autoload.php';
                    if (!file_exists($autoload)) {
                        $email_reason = "PHPMailer is not installed (vendor/autoload.php missing).";
                    } else {
                        require_once $autoload;
                        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                            $email_reason = "PHPMailer class not found in vendor.";
                        } else {

                            $smtp_host       = "smtp.gmail.com";
                            $smtp_port       = 587;
                            $smtp_username   = "info.dngroup1@gmail.com";
                            $smtp_password   = "ikqj qkej hvgo ywvp";
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
                                $mail->addAddress($form['email'], $form['name']);
                                $mail->addReplyTo($smtp_from_email, $smtp_from_name);

                                $mail->isHTML(true);
                                $mail->Subject = "School Information Updated — " . $form['name'];

                                $safe_school   = htmlspecialchars($form['name'],        ENT_QUOTES, 'UTF-8');
                                $safe_admin    = htmlspecialchars($form['admin_name'],  ENT_QUOTES, 'UTF-8');
                                $safe_country  = htmlspecialchars($form['country'],     ENT_QUOTES, 'UTF-8');
                                $safe_province = htmlspecialchars($form['province'],    ENT_QUOTES, 'UTF-8');
                                $safe_district = htmlspecialchars($form['district'],    ENT_QUOTES, 'UTF-8');
                                $safe_type     = ucfirst($form['type']);
                                $safe_email    = htmlspecialchars($form['email'],       ENT_QUOTES, 'UTF-8');
                                $safe_phone    = htmlspecialchars($form['phone'],       ENT_QUOTES, 'UTF-8');
                                $safe_status   = ucfirst($form['status']);
                                $safe_code     = htmlspecialchars($school_code,         ENT_QUOTES, 'UTF-8');

                                // Changes table
                                $changes_html = "";
                                if (count($changes) > 0) {
                                    foreach ($changes as $label => $v) {
                                        $old_s = htmlspecialchars($v['old'] !== '' ? $v['old'] : '—', ENT_QUOTES, 'UTF-8');
                                        $new_s = htmlspecialchars($v['new'] !== '' ? $v['new'] : '—', ENT_QUOTES, 'UTF-8');
                                        $safe_label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                                        $changes_html .= "
                                            <tr>
                                                <td style='padding:10px 12px;font-size:13.5px;color:#6b7280;font-weight:600;border-bottom:1px solid #eef2f7;'>{$safe_label}</td>
                                                <td style='padding:10px 12px;font-size:13.5px;color:#94a3b8;text-decoration:line-through;border-bottom:1px solid #eef2f7;'>{$old_s}</td>
                                                <td style='padding:10px 12px;font-size:13.5px;color:#10b981;font-weight:600;border-bottom:1px solid #eef2f7;'>{$new_s}</td>
                                            </tr>
                                        ";
                                    }
                                } else {
                                    $changes_html = "
                                        <tr>
                                            <td colspan='3' style='padding:14px;text-align:center;font-size:13.5px;color:#94a3b8;'>
                                                Profile re-saved with no field changes.
                                            </td>
                                        </tr>
                                    ";
                                }

                                $mail->Body = "
                                    <div style='font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:auto;padding:0;border:1px solid #e5e7eb;border-radius:12px;background:#ffffff;overflow:hidden;'>

                                        <div style='background:linear-gradient(135deg,#2563eb,#7c3aed);padding:32px 34px;color:#ffffff;'>
                                            <h1 style='margin:0;font-size:22px;font-weight:800;letter-spacing:-0.4px;'>Educ Bridge</h1>
                                            <p style='margin:8px 0 0;font-size:13.5px;opacity:0.92;'>School Information Updated</p>
                                        </div>

                                        <div style='padding:32px 34px 8px;'>

                                            <p style='font-size:17px;color:#0f172a;margin:0 0 8px;font-weight:600;'>
                                                Hello <strong>{$safe_school}</strong>,
                                            </p>

                                            <p style='font-size:14.5px;color:#4b5563;line-height:1.75;margin:0 0 22px;'>
                                                This is to inform you that the information on your school's profile has been
                                                updated by an Educ Bridge administrator. Please review the changes below.
                                            </p>

                                            <div style='background:#f9fafb;border-radius:12px;padding:14px 6px 6px;margin:0 0 26px;border:1px solid #eef2f7;'>
                                                <p style='font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:0.6px;font-weight:700;margin:0 0 10px;padding:0 12px;'>
                                                    Changes Applied
                                                </p>
                                                <table style='width:100%;border-collapse:collapse;'>
                                                    <thead>
                                                        <tr>
                                                            <th style='text-align:left;padding:6px 12px;font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;'>Field</th>
                                                            <th style='text-align:left;padding:6px 12px;font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;'>Before</th>
                                                            <th style='text-align:left;padding:6px 12px;font-size:11px;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;'>Now</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {$changes_html}
                                                    </tbody>
                                                </table>
                                            </div>

                                            <p style='font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:0.6px;font-weight:700;margin:0 0 10px;'>
                                                Current School Profile
                                            </p>

                                            <div style='background:#f9fafb;border-radius:12px;padding:18px 22px;margin:0 0 26px;border:1px solid #eef2f7;'>
                                                <table style='width:100%;border-collapse:collapse;font-size:13.5px;color:#374151;'>
                                                    <tr>
                                                        <td style='padding:7px 0;width:160px;color:#6b7280;'>School Code</td>
                                                        <td style='padding:7px 0;font-weight:700;font-family:Courier,monospace;color:#2563eb;'>{$safe_code}</td>
                                                    </tr>
                                                    <tr>
                                                        <td style='padding:7px 0;color:#6b7280;'>School Name</td>
                                                        <td style='padding:7px 0;font-weight:600;'>{$safe_school}</td>
                                                    </tr>
                                                    <tr>
                                                        <td style='padding:7px 0;color:#6b7280;'>Administrator</td>
                                                        <td style='padding:7px 0;font-weight:600;'>{$safe_admin}</td>
                                                    </tr>
                                                    <tr>
                                                        <td style='padding:7px 0;color:#6b7280;'>Type</td>
                                                        <td style='padding:7px 0;font-weight:600;'>{$safe_type}</td>
                                                    </tr>
                                                    <tr>
                                                        <td style='padding:7px 0;color:#6b7280;'>Location</td>
                                                        <td style='padding:7px 0;font-weight:600;'>{$safe_province}, {$safe_district}, {$safe_country}</td>
                                                    </tr>
                                                    <tr>
                                                        <td style='padding:7px 0;color:#6b7280;'>Email</td>
                                                        <td style='padding:7px 0;font-weight:600;'>{$safe_email}</td>
                                                    </tr>
                                                    <tr>
                                                        <td style='padding:7px 0;color:#6b7280;'>Phone</td>
                                                        <td style='padding:7px 0;font-weight:600;'>{$safe_phone}</td>
                                                    </tr>
                                                    <tr>
                                                        <td style='padding:7px 0;color:#6b7280;'>Status</td>
                                                        <td style='padding:7px 0;font-weight:600;'>{$safe_status}</td>
                                                    </tr>
                                                </table>
                                            </div>

                                            <p style='font-size:14.5px;color:#4b5563;line-height:1.75;margin:0 0 22px;'>
                                                If any of the information above is incorrect, please reply to this email
                                                and let us know so we can fix it immediately.
                                            </p>

                                            <p style='font-size:15px;color:#0f172a;margin:0 0 4px;font-weight:700;'>
                                                Thank you!
                                            </p>
                                            <p style='font-size:13.5px;color:#6b7280;margin:0 0 24px;'>
                                                — The Educ Bridge Team
                                            </p>

                                        </div>

                                        <div style='padding:0 34px 30px;'>
                                            <hr style='border:none;border-top:1px solid #e5e7eb;margin:6px 0 22px;'>
                                            <p style='font-size:11.5px;color:#6b7280;text-transform:uppercase;letter-spacing:0.6px;font-weight:700;margin:0 0 12px;'>
                                                Need help?
                                            </p>
                                            <p style='font-size:13.5px;color:#4b5563;line-height:1.75;margin:0 0 6px;'>
                                                📧 Email: <a href='mailto:info.dngroup1@gmail.com' style='color:#2563eb;text-decoration:none;'>info.dngroup1@gmail.com</a>
                                            </p>
                                            <p style='font-size:13.5px;color:#4b5563;line-height:1.75;margin:0;'>
                                                📞 Phone: +250 783 561 459
                                            </p>
                                        </div>

                                        <div style='background:#f9fafb;border-top:1px solid #e5e7eb;padding:18px 34px;text-align:center;'>
                                            <p style='font-size:12.5px;color:#6b7280;margin:0;'>
                                                Educ Bridge · Connecting Parents, Schools, and Education
                                            </p>
                                        </div>

                                    </div>
                                ";

                                $alt = "Hello {$form['name']},\n\n";
                                $alt .= "Your school information on Educ Bridge has been updated.\n\n";
                                $alt .= "CHANGES APPLIED:\n";
                                if (count($changes) > 0) {
                                    foreach ($changes as $label => $v) {
                                        $alt .= "  {$label}: \"{$v['old']}\" → \"{$v['new']}\"\n";
                                    }
                                } else {
                                    $alt .= "  (No field changes — profile re-saved)\n";
                                }
                                $alt .= "\nCURRENT PROFILE:\n";
                                $alt .= "  School Code: {$school_code}\n";
                                $alt .= "  School Name: {$form['name']}\n";
                                $alt .= "  Administrator: {$form['admin_name']}\n";
                                $alt .= "  Type: {$safe_type}\n";
                                $alt .= "  Location: {$form['province']}, {$form['district']}, {$form['country']}\n";
                                $alt .= "  Email: {$form['email']}\n";
                                $alt .= "  Phone: {$form['phone']}\n";
                                $alt .= "  Status: {$safe_status}\n\n";
                                $alt .= "Thank you!\n— The Educ Bridge Team\n\n";
                                $alt .= "Educ Bridge · Connecting Parents, Schools, and Education";

                                $mail->AltBody = $alt;

                                $mail->send();
                                $email_sent = true;

                            } catch (Exception $e) {
                                $email_sent   = false;
                                $email_reason = $mail->ErrorInfo;
                                // Log it
                                file_put_contents(
                                    __DIR__ . '/edit_school_email.log',
                                    "[" . date('Y-m-d H:i:s') . "] School #{$id} ({$form['email']}) — " . $mail->ErrorInfo . "\n",
                                    FILE_APPEND
                                );
                            }
                        }
                    }
                }

                // Redirect with status flag
                if ($notify_school) {
                    if ($email_sent) {
                        header("Location: admin-schools.php?updated=1&notified=1");
                    } else {
                        header("Location: admin-schools.php?updated=1&notified=0&reason=" . urlencode($email_reason));
                    }
                } else {
                    header("Location: admin-schools.php?updated=1&notified=no");
                }
                exit;

            } else {
                $message = "Failed to update school. Please try again.";
                $message_type = "error";
                mysqli_stmt_close($upd);
            }
        }
    }
}

// Sidebar unread badge
$unread_count = 0;
$r = mysqli_query($conn, "SELECT COUNT(*) t FROM messages WHERE status='unread'");
if ($r) $unread_count = (int) mysqli_fetch_assoc($r)['t'];

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit School | Educ Bridge Admin</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --success: #10b981;
            --success-light: rgba(16, 185, 129, 0.1);
            --warning: #f59e0b;
            --warning-light: rgba(245, 158, 11, 0.12);
            --danger: #ef4444;
            --danger-light: rgba(239, 68, 68, 0.1);
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
            --success: #34d399;
            --success-light: rgba(16, 185, 129, 0.15);
            --warning: #fbbf24;
            --warning-light: rgba(245, 158, 11, 0.18);
            --danger: #f87171;
            --danger-light: rgba(239, 68, 68, 0.15);
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
        .nav-item .nav-badge {
            margin-left: auto;
            background: #ef4444;
            color: #fff;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 50px;
            line-height: 1.5;
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
            position: relative;
        }
        .topbar-btn:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
        .topbar-btn.theme-btn:hover { transform: rotate(20deg); }
        .topbar-btn .dot {
            position: absolute;
            top: 8px; right: 8px;
            width: 8px; height: 8px;
            background: #ef4444;
            border: 2px solid var(--bg-card);
            border-radius: 50%;
        }
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

        .card {
            max-width: 720px;
            margin: 0 auto;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }
        .card-head {
            padding: 26px 30px;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(135deg, rgba(37,99,235,0.06), rgba(124,58,237,0.04));
        }
        .card-head h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-head h2 i { color: var(--primary); }
        .card-head p {
            font-size: 14px;
            color: var(--text-muted);
        }
        .card-body { padding: 28px 30px; }

        .school-code-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-light);
            color: var(--primary);
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 13px;
            font-weight: 700;
            font-family: 'Courier New', monospace;
            margin-top: 8px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
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

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 13px 15px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-body);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            font-size: 14.5px;
            outline: none;
            transition: var(--transition);
        }
        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }
        .form-group select { cursor: pointer; }
        .form-help {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        /* Type selector */
        .type-select {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .type-option {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 18px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: var(--transition);
            background: var(--bg-body);
            text-align: center;
        }
        .type-option:hover { border-color: var(--primary); }
        .type-option input { position: absolute; opacity: 0; pointer-events: none; }
        .type-option i { font-size: 24px; color: var(--text-muted); transition: var(--transition); }
        .type-option span { font-size: 14.5px; font-weight: 700; color: var(--text-primary); }
        .type-option small { font-size: 12px; color: var(--text-muted); }
        .type-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        .type-option.selected i { color: var(--primary); }

        /* Status options */
        .status-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .status-option {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: var(--transition);
            background: var(--bg-body);
        }
        .status-option:hover { border-color: var(--primary); }
        .status-option input { position: absolute; opacity: 0; pointer-events: none; }
        .status-option .dot {
            width: 10px; height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .status-option.active .dot { background: var(--success); }
        .status-option.deactivated .dot { background: var(--danger); }
        .status-option .txt {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .status-option .sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-left: auto;
        }
        .status-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        /* Notify toggle */
        .notify-box {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 18px 20px;
            background: var(--bg-alt);
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 8px;
            position: relative;
        }
        .notify-box:hover { border-color: var(--primary); }
        .notify-box.checked {
            border-color: var(--primary);
            background: var(--primary-light);
        }
        .notify-box input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .notify-check {
            width: 22px; height: 22px;
            border-radius: 6px;
            border: 2px solid var(--border-color);
            background: var(--bg-card);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
            transition: var(--transition);
        }
        .notify-check i {
            color: #fff;
            font-size: 11px;
            opacity: 0;
            transform: scale(0.5);
            transition: var(--transition);
        }
        .notify-box.checked .notify-check {
            background: var(--primary);
            border-color: var(--primary);
        }
        .notify-box.checked .notify-check i {
            opacity: 1;
            transform: scale(1);
        }
        .notify-content h4 {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .notify-content p {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.55;
            margin: 0;
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

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            margin-top: 12px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 13px 24px;
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
        .btn.primary:hover { background: var(--primary-dark); color: #fff; }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); box-shadow: 0 0 40px rgba(0,0,0,0.2); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; }
            .hamburger { display: flex; }
        }
        @media (max-width: 700px) {
            .form-row { grid-template-columns: 1fr; }
            .type-select,
            .status-options { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .content { padding: 20px 16px; }
            .topbar { padding: 12px 16px; }
            .page-title { font-size: 17px; }
            .card-body { padding: 22px 20px; }
            .card-head { padding: 22px 20px; }
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
            <a href="admin-dashboard.php" class="nav-item"><i class="fas fa-th-large nav-icon"></i> Dashboard</a>
            <a href="admin-schools.php" class="nav-item"><i class="fas fa-school nav-icon"></i> Schools</a>
            <a href="admin-students.php" class="nav-item"><i class="fas fa-user-graduate nav-icon"></i> Students</a>
            <a href="admin-teachers.php" class="nav-item"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>

            <div class="nav-section-title">Management</div>
            <a href="admin-school-account.php" class="nav-item active"><i class="fas fa-id-card nav-icon"></i> School Accounts</a>
            <a href="admin-events.php" class="nav-item"><i class="fas fa-calendar-alt nav-icon"></i> Events</a>
            <a href="admin-announcements.php" class="nav-item"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>

            <div class="nav-section-title">System</div>
            <a href="admin-reports.php" class="nav-item"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
            <a href="admin-settings.php" class="nav-item"><i class="fas fa-cog nav-icon"></i> Settings</a>
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
                    <div class="page-title">Edit School</div>
                    <div class="page-subtitle">Update information for <?= htmlspecialchars($form['name']); ?></div>
                </div>
            </div>

            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme" title="Toggle theme">
                    <i class="fas fa-moon"></i>
                </button>
                <a href="admin-messages.php" class="topbar-btn" title="Messages">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread_count > 0): ?><span class="dot"></span><?php endif; ?>
                </a>
                <div class="topbar-avatar" title="<?= htmlspecialchars($admin_name); ?>">
                    <?= htmlspecialchars($initials); ?>
                </div>
            </div>
        </header>

        <main class="content">

            <a href="admin-schools.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Schools
            </a>

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <div class="card">

                <div class="card-head">
                    <h2><i class="fas fa-edit"></i> Edit School Information</h2>
                    <p>Update the details below and choose whether to notify the school via email.</p>
                    <div class="school-code-badge">
                        <i class="fas fa-fingerprint"></i> <?= htmlspecialchars($school_code); ?>
                    </div>
                </div>

                <div class="card-body">

                    <form method="POST" action="admin-edit-school.php?id=<?= (int) $id; ?>">

                        <!-- School Name -->
                        <div class="form-group">
                            <label for="name">School Name <span class="required">*</span></label>
                            <input type="text" id="name" name="name" maxlength="200"
                                   value="<?= htmlspecialchars($form['name']); ?>"
                                   placeholder="e.g. Green Hills Academy" required>
                        </div>

                        <!-- Admin Name -->
                        <div class="form-group">
                            <label for="admin_name">School Administrator Name <span class="required">*</span></label>
                            <input type="text" id="admin_name" name="admin_name" maxlength="150"
                                   value="<?= htmlspecialchars($form['admin_name']); ?>"
                                   placeholder="e.g. Jane Doe" required>
                        </div>

                        <!-- Country + Province -->
                        <div class="form-row">
                            <div class="form-group">
                                <label for="country">Country <span class="required">*</span></label>
                                <input type="text" id="country" name="country"
                                       value="<?= htmlspecialchars($form['country']); ?>"
                                       placeholder="e.g. Rwanda" required>
                                <div class="form-help">Change to anything other than Rwanda to type province/district manually</div>
                            </div>

                            <div class="form-group">
                                <label for="province">Province / Region <span class="required">*</span></label>
                                <select id="province" name="province" required>
                                    <option value="">— Select province —</option>
                                    <?php foreach (array_keys($rwanda_provinces) as $prov): ?>
                                        <option value="<?= htmlspecialchars($prov); ?>"
                                            <?= $form['province'] === $prov ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($prov); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- District -->
                        <div class="form-group">
                            <label for="district">District <span class="required">*</span></label>
                            <select id="district" name="district" required>
                                <option value="">— Select district —</option>
                            </select>
                        </div>

                        <!-- School Type -->
                        <div class="form-group">
                            <label>School Type <span class="required">*</span></label>
                            <div class="type-select">
                                <label class="type-option <?= $form['type'] === 'public' ? 'selected' : ''; ?>" onclick="selectType(this)">
                                    <input type="radio" name="type" value="public" <?= $form['type'] === 'public' ? 'checked' : ''; ?> required>
                                    <i class="fas fa-landmark"></i>
                                    <span>Public</span>
                                    <small>Government school</small>
                                </label>
                                <label class="type-option <?= $form['type'] === 'private' ? 'selected' : ''; ?>" onclick="selectType(this)">
                                    <input type="radio" name="type" value="private" <?= $form['type'] === 'private' ? 'checked' : ''; ?> required>
                                    <i class="fas fa-building"></i>
                                    <span>Private</span>
                                    <small>Independent school</small>
                                </label>
                            </div>
                        </div>

                        <!-- Status -->
                        <div class="form-group">
                            <label>Status <span class="required">*</span></label>
                            <div class="status-options">
                                <label class="status-option active <?= $form['status'] === 'active' ? 'selected' : ''; ?>" onclick="selectStatus(this)">
                                    <input type="radio" name="status" value="active" <?= $form['status'] === 'active' ? 'checked' : ''; ?> required>
                                    <span class="dot"></span>
                                    <span class="txt">Active</span>
                                    <span class="sub">Visible &amp; operational</span>
                                </label>
                                <label class="status-option deactivated <?= $form['status'] === 'deactivated' ? 'selected' : ''; ?>" onclick="selectStatus(this)">
                                    <input type="radio" name="status" value="deactivated" <?= $form['status'] === 'deactivated' ? 'checked' : ''; ?> required>
                                    <span class="dot"></span>
                                    <span class="txt">Deactivated</span>
                                    <span class="sub">Hidden from system</span>
                                </label>
                            </div>
                        </div>

                        <!-- Contact -->
                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">School Email <span class="required">*</span></label>
                                <input type="email" id="email" name="email" maxlength="150"
                                       value="<?= htmlspecialchars($form['email']); ?>"
                                       placeholder="school@example.com" required>
                            </div>
                            <div class="form-group">
                                <label for="phone">School Phone <span class="required">*</span></label>
                                <input type="tel" id="phone" name="phone" maxlength="20"
                                       value="<?= htmlspecialchars($form['phone']); ?>"
                                       placeholder="e.g. 0783 561 459" required>
                            </div>
                        </div>

                        <!-- Notify checkbox -->
                        <div class="form-group">
                            <label>Notification</label>
                            <label class="notify-box" id="notifyBox" onclick="toggleNotify(event, this)">
                                <input type="checkbox" name="notify_school" id="notify_school" value="1">
                                <span class="notify-check"><i class="fas fa-check"></i></span>
                                <div class="notify-content">
                                    <h4>Notify the school via email</h4>
                                    <p>
                                        When checked, the system sends an email to the school administrator
                                        explaining that their information has been updated. The email includes
                                        the school code, all current details, and the exact changes made.
                                    </p>
                                </div>
                            </label>
                        </div>

                        <div class="form-actions">
                            <a href="admin-schools.php" class="btn">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                            <button type="submit" class="btn primary">
                                <i class="fas fa-save"></i> Update School
                            </button>
                        </div>

                    </form>

                </div>

            </div>

        </main>
    </div>
</div>

<script>
    // RWANDA PROVINCES → DISTRICTS
    const provinces = <?= json_encode($rwanda_provinces); ?>;
    const preselectedDistrict = <?= json_encode($form['district']); ?>;

    const countryInput    = document.getElementById('country');
    const provinceSelect  = document.getElementById('province');
    const districtSelect  = document.getElementById('district');

    function refreshDistricts(province, selectedDistrict) {
        districtSelect.innerHTML = '<option value="">— Select district —</option>';
        if (provinces[province]) {
            provinces[province].forEach(function (district) {
                const opt = document.createElement('option');
                opt.value = district;
                opt.textContent = district;
                if (district === selectedDistrict) opt.selected = true;
                districtSelect.appendChild(opt);
            });
        }
    }

    if (provinceSelect.value) {
        refreshDistricts(provinceSelect.value, preselectedDistrict);
    }

    provinceSelect.addEventListener('change', function () {
        refreshDistricts(this.value, null);
    });

    // Non-Rwanda: free text
    countryInput.addEventListener('input', function () {
        const isRwanda = this.value.trim().toLowerCase() === 'rwanda';
        if (!isRwanda && provinceSelect.tagName === 'SELECT') {
            const pinput = document.createElement('input');
            pinput.type = 'text';
            pinput.id = 'province';
            pinput.name = 'province';
            pinput.placeholder = 'Type province / region';
            pinput.required = true;
            pinput.value = '<?= htmlspecialchars($form['province']); ?>';
            provinceSelect.replaceWith(pinput);

            const dinput = document.createElement('input');
            dinput.type = 'text';
            dinput.id = 'district';
            dinput.name = 'district';
            dinput.placeholder = 'Type district / city';
            dinput.required = true;
            dinput.value = '<?= htmlspecialchars($form['district']); ?>';
            districtSelect.replaceWith(dinput);
        }
    });

    // TYPE
    function selectType(el) {
        document.querySelectorAll('.type-option').forEach(o => o.classList.remove('selected'));
        el.classList.add('selected');
    }

    // STATUS
    function selectStatus(el) {
        document.querySelectorAll('.status-option').forEach(o => o.classList.remove('selected'));
        el.classList.add('selected');
    }

    // NOTIFY
    function toggleNotify(event, el) {
        event.preventDefault();
        const cb = el.querySelector('input[type="checkbox"]');
        cb.checked = !cb.checked;
        el.classList.toggle('checked', cb.checked);
    }

    // THEME
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