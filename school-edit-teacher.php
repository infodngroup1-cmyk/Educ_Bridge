<?php
session_start();
require_once 'db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['school_id']) || empty($_SESSION['school_verified'])) {
    header("Location: school-login.php");
    exit;
}

$school_id    = (int) $_SESSION['school_id'];
$school_name  = $_SESSION['school_name']  ?? 'School';
$school_admin = $_SESSION['school_admin'] ?? 'Administrator';
$school_code  = $_SESSION['school_code']  ?? '';

// Initials
$initials = "";
foreach (explode(" ", trim($school_admin)) as $part) {
    if ($part !== "") {
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) break;
    }
}
if ($initials === "") $initials = "S";
$short_school = mb_strimwidth($school_name, 0, 24, '…');

/*
|--------------------------------------------------------------------------
| GET TEACHER
|--------------------------------------------------------------------------
*/
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header("Location: school-teachers.php");
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT * FROM teachers WHERE id = ? AND school_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $school_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($res) === 0) {
    header("Location: school-teachers.php?error=" . urlencode("Teacher not found."));
    exit;
}

$teacher = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

$original = $teacher;

/*
|--------------------------------------------------------------------------
| FETCH SCHOOL'S ACTIVE ACADEMIC LEVELS
|--------------------------------------------------------------------------
*/
$active_levels = [];
$lvl_stmt = mysqli_prepare($conn, "
    SELECT level_key, level_name
    FROM academic_levels
    WHERE school_id = ? AND is_active = 1
    ORDER BY FIELD(level_key, 'nursery','primary','olevel','alevel')
");
if ($lvl_stmt) {
    mysqli_stmt_bind_param($lvl_stmt, "i", $school_id);
    mysqli_stmt_execute($lvl_stmt);
    $lvl_res = mysqli_stmt_get_result($lvl_stmt);
    while ($row = mysqli_fetch_assoc($lvl_res)) {
        $active_levels[$row['level_key']] = $row['level_name'];
    }
    mysqli_stmt_close($lvl_stmt);
}

$LEVEL_KEY_TO_ENUM = [
    'nursery' => 'Nursery',
    'primary' => 'Primary',
    'olevel'  => 'O_Level',
    'alevel'  => 'A_Level',
];

$valid_level_enums = [];
$level_display     = [];

foreach ($active_levels as $key => $label_in) {
    $enum_val = $LEVEL_KEY_TO_ENUM[$key] ?? null;
    if ($enum_val !== null) {
        $valid_level_enums[] = $enum_val;
        $label = $label_in;
        if ($enum_val === 'O_Level') $label = "O' Level";
        if ($enum_val === 'A_Level') $label = "A' Level";
        if ($enum_val === 'Nursery') $label = "Nursery";
        if ($enum_val === 'Primary') $label = "Primary";
        $level_display[$enum_val] = $label;
    }
}

/*
|--------------------------------------------------------------------------
| PRESET BANK TYPES
|--------------------------------------------------------------------------
*/
$bank_presets = [
    'Mobile Money (MoMo)',
    'AirtelMoney',
    'BK Bank',
    "I&M Bank",
    'Equity',
    'Urwego Bank',
    'Umwalimu Sacco',
];

/*
|--------------------------------------------------------------------------
| RWANDAN PHONE HELPERS
|--------------------------------------------------------------------------
*/
function clean_phone($raw) {
    return preg_replace('/[^0-9+]/', '', (string)$raw);
}

function is_valid_rwanda_phone($raw) {
    $c = clean_phone($raw);
    if ($c === '') return false;
    if (preg_match('/^07\d{8}$/', $c)) return true;
    if (preg_match('/^\+2507\d{8}$/', $c)) return true;
    if (preg_match('/^2507\d{8}$/', $c)) return true;
    return false;
}

function normalize_rwanda_phone($raw) {
    $c = clean_phone($raw);
    if (preg_match('/^\+250(7\d{8})$/', $c, $m)) return '0' . $m[1];
    if (preg_match('/^250(7\d{8})$/', $c, $m))  return '0' . $m[1];
    if (preg_match('/^(07\d{8})$/', $c, $m))    return $m[1];
    return null;
}

function is_mobile_money_type($bank) {
    $b = strtolower(trim((string)$bank));
    if ($b === '') return false;
    return (strpos($b, 'momo') !== false
        ||  strpos($b, 'mobile money') !== false
        ||  strpos($b, 'airtel') !== false);
}

/*
|--------------------------------------------------------------------------
| RWANDAN NATIONAL ID HELPERS
|--------------------------------------------------------------------------
*/
function clean_id_number($raw) {
    return preg_replace('/[^0-9]/', '', (string)$raw);
}

function is_valid_rwanda_id($raw) {
    $c = clean_id_number($raw);
    return preg_match('/^\d{16}$/', $c) === 1;
}

/*
|--------------------------------------------------------------------------
| Existing teaching levels (array)
|--------------------------------------------------------------------------
*/
$existing_levels = array_filter(array_map('trim', explode(',', (string) $teacher['teaching_level'])));

/*
|--------------------------------------------------------------------------
| Bank type: preset vs custom?
|--------------------------------------------------------------------------
*/
$current_bank   = (string) $teacher['bank_type'];
$bank_is_preset = in_array($current_bank, $bank_presets, true);
$bank_choice    = $bank_is_preset ? $current_bank : ($current_bank !== '' ? 'Other' : '');
$bank_custom    = $bank_is_preset ? '' : $current_bank;

/*
|--------------------------------------------------------------------------
| FORM STATE
|--------------------------------------------------------------------------
*/
$f = [
    'name'               => $teacher['name'],
    'email'              => $teacher['email'],
    'phone'              => $teacher['phone'],
    'id_number'          => (string)($teacher['id_number'] ?? ''),
    'diploma'            => $teacher['diploma'],
    'teaching_levels'    => $existing_levels,
    'sex'                => $teacher['sex'],
    'bank_type_choice'   => $bank_choice,
    'bank_type_custom'   => $bank_custom,
    'account_name'       => (string) $teacher['account_name'],
    'account_number'     => (string) $teacher['account_number'],
    'has_disability'     => (int) $teacher['has_disability'],
    'disability_details' => (string) $teacher['disability_details'],
    'status'             => $teacher['status'],
];

$message      = "";
$message_type = "";

/*
|--------------------------------------------------------------------------
| HANDLE SUBMIT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $f['name']               = trim($_POST['name'] ?? '');
    $f['email']              = trim($_POST['email'] ?? '');
    $f['phone']              = trim($_POST['phone'] ?? '');
    $f['id_number']          = trim($_POST['id_number'] ?? '');
    $f['diploma']            = trim($_POST['diploma'] ?? '');
    $f['teaching_levels']    = isset($_POST['teaching_level']) && is_array($_POST['teaching_level'])
                                 ? array_values(array_unique(array_map('trim', $_POST['teaching_level'])))
                                 : [];
    $f['sex']                = trim($_POST['sex'] ?? '');
    $f['bank_type_choice']   = trim($_POST['bank_type_choice'] ?? '');
    $f['bank_type_custom']   = trim($_POST['bank_type_custom'] ?? '');
    $f['account_name']       = trim($_POST['account_name'] ?? '');
    $f['account_number']     = trim($_POST['account_number'] ?? '');
    $f['has_disability']     = isset($_POST['has_disability']) && $_POST['has_disability'] == '1' ? 1 : 0;
    $f['disability_details'] = trim($_POST['disability_details'] ?? '');
    $f['status']             = trim($_POST['status'] ?? 'active');

    $errors = [];

    if ($f['name'] === '' || mb_strlen($f['name']) < 3) {
        $errors[] = "Full name is required (at least 3 characters).";
    }
    if ($f['email'] === '' || !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email address is required.";
    }

    /* Phone */
    $normalized_phone = null;
    if ($f['phone'] === '') {
        $errors[] = "Phone number is required.";
    } elseif (!is_valid_rwanda_phone($f['phone'])) {
        $errors[] = "Phone must be a Rwandan number: 07XXXXXXXX (10 digits) or +2507XXXXXXXX.";
    } else {
        $normalized_phone = normalize_rwanda_phone($f['phone']);
    }

    /* National ID (optional) */
    $normalized_id = null;
    if ($f['id_number'] !== '') {
        if (!is_valid_rwanda_id($f['id_number'])) {
            $errors[] = "National ID must be exactly 16 digits (e.g. 1199870012345678).";
        } else {
            $normalized_id = clean_id_number($f['id_number']);
        }
    }

    if (!in_array($f['diploma'], ['A2','A1','A0','Masters','PhD','Professor'], true)) {
        $errors[] = "Please select a valid diploma.";
    }

    $chosen_levels = array_values(array_intersect($f['teaching_levels'], $valid_level_enums));
    if (empty($chosen_levels)) {
        if (empty($valid_level_enums)) {
            $errors[] = "Your school has no active academic levels. Please add a level first.";
        } else {
            $errors[] = "Please select at least one teaching level.";
        }
    }

    if (!in_array($f['sex'], ['Male','Female'], true)) {
        $errors[] = "Please select the sex.";
    }
    if (!in_array($f['status'], ['active','inactive'], true)) {
        $errors[] = "Please select a valid status.";
    }
    if ($f['has_disability'] === 1 && $f['disability_details'] === '') {
        $errors[] = "Please describe the disability since you selected 'Yes'.";
    }

    /* Bank type */
    $final_bank_type = '';
    if ($f['bank_type_choice'] !== '') {
        if ($f['bank_type_choice'] === 'Other') {
            if ($f['bank_type_custom'] === '') {
                $errors[] = "Please type your bank/wallet name since you chose 'Other'.";
            } else {
                $final_bank_type = $f['bank_type_custom'];
            }
        } elseif (in_array($f['bank_type_choice'], $bank_presets, true)) {
            $final_bank_type = $f['bank_type_choice'];
        } else {
            $errors[] = "Please select a valid bank type.";
        }
    }

    /* Mobile Money / Airtel — account number must be valid Rwandan phone */
    $normalized_account_number = $f['account_number'];
    if (is_mobile_money_type($final_bank_type)) {
        if ($f['account_number'] === '') {
            $errors[] = "For Mobile Money / Airtel Money, the account number (phone) is required.";
        } elseif (!is_valid_rwanda_phone($f['account_number'])) {
            $errors[] = "For Mobile Money / Airtel Money, the account number must be a Rwandan phone (07XXXXXXXX or +2507XXXXXXXX).";
        } else {
            $normalized_account_number = normalize_rwanda_phone($f['account_number']);
        }
    }

    /* Duplicate email */
    if (empty($errors)) {
        $chk = mysqli_prepare($conn, "SELECT id FROM teachers WHERE school_id = ? AND email = ? AND id <> ? LIMIT 1");
        mysqli_stmt_bind_param($chk, "isi", $school_id, $f['email'], $id);
        mysqli_stmt_execute($chk);
        $chk_res = mysqli_stmt_get_result($chk);
        if (mysqli_num_rows($chk_res) > 0) {
            $errors[] = "Another teacher with this email already exists in your school.";
        }
        mysqli_stmt_close($chk);
    }

    /* Duplicate phone (normalized, excluding this teacher) */
    if (empty($errors) && $normalized_phone !== null) {
        $dup = mysqli_prepare($conn, "SELECT id, phone FROM teachers WHERE school_id = ? AND id <> ?");
        if ($dup) {
            mysqli_stmt_bind_param($dup, "ii", $school_id, $id);
            mysqli_stmt_execute($dup);
            $dup_res = mysqli_stmt_get_result($dup);
            $dup_found = false;
            while ($row = mysqli_fetch_assoc($dup_res)) {
                $existing_norm = normalize_rwanda_phone($row['phone']);
                if ($existing_norm !== null && $existing_norm === $normalized_phone) {
                    $dup_found = true;
                    break;
                }
            }
            mysqli_stmt_close($dup);
            if ($dup_found) {
                $errors[] = "Another teacher with this phone number already exists in your school.";
            }
        }
    }

    /* Duplicate National ID (excluding this teacher) */
    if (empty($errors) && $normalized_id !== null) {
        $dup = mysqli_prepare($conn, "SELECT id FROM teachers WHERE school_id = ? AND id_number = ? AND id <> ? LIMIT 1");
        if ($dup) {
            mysqli_stmt_bind_param($dup, "isi", $school_id, $normalized_id, $id);
            mysqli_stmt_execute($dup);
            $dup_res = mysqli_stmt_get_result($dup);
            if (mysqli_num_rows($dup_res) > 0) {
                $errors[] = "Another teacher with this National ID already exists in your school.";
            }
            mysqli_stmt_close($dup);
        }
    }

    if (!empty($errors)) {
        $message = implode(" ", $errors);
        $message_type = "error";
    } else {

        /* Normalize */
        $f['phone'] = $normalized_phone;
        $f['id_number'] = ($normalized_id !== null) ? $normalized_id : '';
        if (is_mobile_money_type($final_bank_type)) {
            $f['account_number'] = $normalized_account_number;
        }

        /* Order levels */
        $ordered = [];
        foreach (['Nursery','Primary','O_Level','A_Level'] as $lvl) {
            if (in_array($lvl, $chosen_levels, true)) $ordered[] = $lvl;
        }
        $teaching_level_str = implode(',', $ordered);

        /* Build changes list */
        $changes = [];
        $field_labels = [
            'name'            => 'Name',
            'email'           => 'Email',
            'phone'           => 'Phone',
            'id_number'       => 'National ID',
            'diploma'         => 'Diploma',
            'teaching_level'  => 'Teaching Level(s)',
            'sex'             => 'Sex',
            'bank_type'       => 'Bank / Wallet',
            'account_name'    => 'Account Name',
            'account_number'  => 'Account Number',
            'has_disability'  => 'Has Disability',
            'disability_details' => 'Disability Details',
            'status'          => 'Status',
        ];

        $new_values = [
            'name'            => $f['name'],
            'email'           => $f['email'],
            'phone'           => $f['phone'],
            'id_number'       => $f['id_number'],
            'diploma'         => $f['diploma'],
            'teaching_level'  => $teaching_level_str,
            'sex'             => $f['sex'],
            'bank_type'       => $final_bank_type,
            'account_name'    => $f['account_name'],
            'account_number'  => $f['account_number'],
            'has_disability'  => $f['has_disability'],
            'disability_details' => $f['has_disability'] === 1 ? $f['disability_details'] : '',
            'status'          => $f['status'],
        ];

        foreach ($field_labels as $field => $label) {
            $old_v = (string) ($original[$field] ?? '');
            $new_v = (string) ($new_values[$field] ?? '');
            if ($old_v !== $new_v) {
                $changes[$label] = ['old' => $old_v, 'new' => $new_v];
            }
        }

        /* UPDATE */
        $upd = mysqli_prepare($conn, "
            UPDATE teachers
            SET name = ?, email = ?, phone = ?, id_number = ?,
                diploma = ?, teaching_level = ?, sex = ?,
                bank_type = ?, account_name = ?, account_number = ?,
                has_disability = ?, disability_details = ?, status = ?
            WHERE id = ? AND school_id = ?
        ");

        $disability_details = $f['has_disability'] === 1 ? $f['disability_details'] : null;
        $id_number_value    = $f['id_number'] === '' ? null : $f['id_number'];

        mysqli_stmt_bind_param(
            $upd,
            "ssssssssssissii",
            $f['name'],
            $f['email'],
            $f['phone'],
            $id_number_value,
            $f['diploma'],
            $teaching_level_str,
            $f['sex'],
            $final_bank_type,
            $f['account_name'],
            $f['account_number'],
            $f['has_disability'],
            $disability_details,
            $f['status'],
            $id,
            $school_id
        );

        if (mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);

            /* Notify teacher if something changed */
            $email_sent   = false;
            $email_reason = '';

            if (!empty($changes)) {

                $autoload = __DIR__ . '/vendor/autoload.php';
                if (!file_exists($autoload)) {
                    $email_reason = "PHPMailer is not installed.";
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
                            $mail->addAddress($f['email'], $f['name']);
                            $mail->addReplyTo($smtp_from_email, $smtp_from_name);

                            $mail->isHTML(true);
                            $mail->Subject = "Your Teacher Profile Was Updated — " . $school_name;

                            $safe_name   = htmlspecialchars($f['name'],   ENT_QUOTES, 'UTF-8');
                            $safe_school = htmlspecialchars($school_name, ENT_QUOTES, 'UTF-8');

                            $changes_html = "";
                            if (count($changes) > 0) {
                                foreach ($changes as $label => $v) {
                                    if ($label === 'Teaching Level(s)') {
                                        $old_lvls = array_filter(array_map('trim', explode(',', $v['old'])));
                                        $new_lvls = array_filter(array_map('trim', explode(',', $v['new'])));
                                        $old_disp = []; $new_disp = [];
                                        foreach ($old_lvls as $lv) $old_disp[] = $level_display[$lv] ?? $lv;
                                        foreach ($new_lvls as $lv) $new_disp[] = $level_display[$lv] ?? $lv;
                                        $old_s = htmlspecialchars(implode(', ', $old_disp) ?: '—', ENT_QUOTES, 'UTF-8');
                                        $new_s = htmlspecialchars(implode(', ', $new_disp) ?: '—', ENT_QUOTES, 'UTF-8');
                                    } elseif ($label === 'Has Disability') {
                                        $old_s = ((int)$v['old'] === 1) ? 'Yes' : 'No';
                                        $new_s = ((int)$v['new'] === 1) ? 'Yes' : 'No';
                                    } else {
                                        $old_s = htmlspecialchars($v['old'] !== '' ? $v['old'] : '—', ENT_QUOTES, 'UTF-8');
                                        $new_s = htmlspecialchars($v['new'] !== '' ? $v['new'] : '—', ENT_QUOTES, 'UTF-8');
                                    }
                                    $safe_label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                                    $changes_html .= "
                                        <tr>
                                            <td style='padding:10px 12px;font-size:13.5px;color:#6b7280;font-weight:600;border-bottom:1px solid #eef2f7;'>{$safe_label}</td>
                                            <td style='padding:10px 12px;font-size:13.5px;color:#94a3b8;text-decoration:line-through;border-bottom:1px solid #eef2f7;'>{$old_s}</td>
                                            <td style='padding:10px 12px;font-size:13.5px;color:#10b981;font-weight:600;border-bottom:1px solid #eef2f7;'>{$new_s}</td>
                                        </tr>
                                    ";
                                }
                            }

                            $mail->Body = "
                                <div style='font-family:Arial,Helvetica,sans-serif;max-width:640px;margin:auto;padding:0;border:1px solid #e5e7eb;border-radius:12px;background:#ffffff;overflow:hidden;'>

                                    <div style='background:linear-gradient(135deg,#2563eb,#7c3aed);padding:32px 34px;color:#ffffff;'>
                                        <h1 style='margin:0;font-size:22px;font-weight:800;letter-spacing:-0.4px;'>Educ Bridge</h1>
                                        <p style='margin:8px 0 0;font-size:13.5px;opacity:0.92;'>Your Teacher Profile Was Updated</p>
                                    </div>

                                    <div style='padding:32px 34px 8px;'>

                                        <p style='font-size:17px;color:#0f172a;margin:0 0 8px;font-weight:600;'>
                                            Hello <strong>{$safe_name}</strong>,
                                        </p>

                                        <p style='font-size:14.5px;color:#4b5563;line-height:1.75;margin:0 0 22px;'>
                                            Your teacher profile at <strong>{$safe_school}</strong> has been updated by
                                            the school administrator. Here are the changes that were made:
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

                                        <p style='font-size:14.5px;color:#4b5563;line-height:1.75;margin:0 0 22px;'>
                                            If anything looks incorrect, please contact your school administrator.
                                        </p>

                                        <p style='font-size:15px;color:#0f172a;margin:0 0 4px;font-weight:700;'>
                                            Best regards,
                                        </p>
                                        <p style='font-size:13.5px;color:#6b7280;margin:0 0 24px;'>
                                            — The Educ Bridge Team
                                        </p>

                                    </div>

                                    <div style='background:#f9fafb;border-top:1px solid #e5e7eb;padding:18px 34px;text-align:center;'>
                                        <p style='font-size:12.5px;color:#6b7280;margin:0;'>
                                            Educ Bridge · Connecting Parents, Schools, and Education
                                        </p>
                                    </div>

                                </div>
                            ";

                            $alt = "Hello {$f['name']},\n\n";
                            $alt .= "Your teacher profile at {$school_name} has been updated.\n\n";
                            $alt .= "CHANGES APPLIED:\n";
                            foreach ($changes as $label => $v) {
                                $alt .= "  {$label}: \"{$v['old']}\" → \"{$v['new']}\"\n";
                            }
                            $alt .= "\n— The Educ Bridge Team\n\n";
                            $alt .= "Educ Bridge · Connecting Parents, Schools, and Education";

                            $mail->AltBody = $alt;
                            $mail->send();
                            $email_sent = true;

                        } catch (Exception $e) {
                            $email_sent   = false;
                            $email_reason = $mail->ErrorInfo;
                            file_put_contents(
                                __DIR__ . '/teacher_update_email.log',
                                "[" . date('Y-m-d H:i:s') . "] Teacher #{$id} ({$f['email']}) — " . $mail->ErrorInfo . "\n",
                                FILE_APPEND
                            );
                        }
                    }
                }
            }

            $redirect = "school-teachers.php?updated=1";
            if (!empty($changes)) {
                $redirect .= "&mailed=" . ($email_sent ? '1' : '0');
                if (!$email_sent && $email_reason !== '') {
                    $redirect .= "&reason=" . urlencode($email_reason);
                }
            }
            header("Location: " . $redirect);
            exit;

        } else {
            $message = "Could not update teacher: " . mysqli_error($conn);
            $message_type = "error";
            mysqli_stmt_close($upd);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Teacher | Educ Bridge</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --success: #10b981; --success-light: rgba(16,185,129,0.1);
            --warning: #f59e0b; --warning-light: rgba(245,158,11,0.12);
            --danger: #ef4444;  --danger-light: rgba(239,68,68,0.1);
            --sidebar-bg: #ffffff;
            --sidebar-hover: rgba(37, 99, 235, 0.06);
            --sidebar-active: rgba(37, 99, 235, 0.10);
            --sidebar-text: #475569;
            --sidebar-text-active: #0f172a;
            --sidebar-border: #e2e8f0;
            --sidebar-title: #94a3b8;
            --sidebar-badge-bg: rgba(37, 99, 235, 0.12);
            --sidebar-badge-fg: #2563eb;
            --bg-body: #f8fafc; --bg-card: #ffffff;
            --bg-alt: #f1f5f9;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.08);
            --shadow-sidebar: 0 0 30px rgba(0,0,0,0.05);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --success: #34d399; --success-light: rgba(16,185,129,0.15);
            --warning: #fbbf24; --warning-light: rgba(245,158,11,0.18);
            --danger: #f87171;  --danger-light: rgba(239,68,68,0.15);
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(59, 130, 246, 0.18);
            --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.08);
            --sidebar-title: #94a3b8;
            --sidebar-badge-bg: rgba(59, 130, 246, 0.2);
            --sidebar-badge-fg: #93c5fd;
            --bg-body: #0b1120; --bg-card: #1e293b;
            --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
            --shadow-sidebar: 0 0 30px rgba(0,0,0,0.4);
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

        .sidebar { width: 260px; background: var(--sidebar-bg); color: var(--sidebar-text); display: flex; flex-direction: column; position: fixed; top:0; left:0; height:100vh; z-index:100; transition: background 0.4s ease, color 0.4s ease, transform 0.3s ease, border-color 0.4s ease; border-right: 1px solid var(--sidebar-border); box-shadow: var(--shadow-sidebar); }
        .sidebar-header { padding: 22px 22px 18px; border-bottom: 1px solid var(--sidebar-border); display:flex; align-items:center; gap:12px; }
        .sidebar-header img { width:42px; height:42px; object-fit:contain; border-radius:50%; border:2px solid var(--sidebar-border); padding:2px; background:#fff; }
        .sidebar-brand { font-family:'Poppins',sans-serif; font-size:18px; font-weight:700; color:var(--text-primary); line-height:1.2; }
        .sidebar-brand span { color: var(--primary); }
        .sidebar-brand small { display:block; font-size:11px; font-weight:500; color:var(--text-muted); letter-spacing:0.4px; margin-top:2px; text-transform:uppercase; }
        .sidebar-nav { flex:1; padding:18px 12px; overflow-y:auto; }
        .nav-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--sidebar-title); padding:12px 14px 8px; }
        .nav-item { display:flex; align-items:center; gap:12px; padding:11px 14px; color:var(--sidebar-text); text-decoration:none; font-size:14px; font-weight:500; border-radius:10px; margin-bottom:4px; transition:var(--transition); position:relative; }
        .nav-item i.nav-icon { width:20px; text-align:center; font-size:15px; color:var(--sidebar-text); transition:var(--transition); }
        .nav-item:hover { background:var(--sidebar-hover); color:var(--sidebar-text-active); }
        .nav-item:hover i.nav-icon { color:var(--primary); }
        .nav-item.active { background:var(--sidebar-active); color:var(--sidebar-text-active); }
        .nav-item.active i.nav-icon { color:var(--primary); }
        .nav-item.active::before { content:''; position:absolute; left:0; top:8px; bottom:8px; width:3px; background:var(--primary); border-radius:0 4px 4px 0; }
        .sidebar-profile { border-top:1px solid var(--sidebar-border); padding:16px 18px; display:flex; align-items:center; gap:12px; }
        .sidebar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:15px; font-weight:700; flex-shrink:0; box-shadow:0 4px 12px rgba(59,130,246,0.35); }
        .sidebar-profile-info { flex:1; min-width:0; }
        .sidebar-profile-info .p-name { font-size:13.5px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-profile-info .p-role { font-size:11.5px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-logout { width:34px; height:34px; border-radius:8px; background:var(--danger-light); color:var(--danger); display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:13px; flex-shrink:0; transition:var(--transition); }
        .sidebar-logout:hover { background:var(--danger); color:#fff; }

        .main { flex:1; margin-left:260px; min-height:100vh; display:flex; flex-direction:column; transition:margin-left 0.3s ease; }
        .topbar { background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:16px; position:sticky; top:0; z-index:50; transition:background 0.4s ease, border-color 0.4s ease; }
        .topbar-left { display:flex; align-items:center; gap:14px; }
        .hamburger { display:none; width:40px; height:40px; border-radius:10px; border:1.5px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); align-items:center; justify-content:center; cursor:pointer; font-size:15px; }
        .hamburger:hover { border-color:var(--primary); color:var(--primary); }
        .page-title { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; color:var(--text-primary); letter-spacing:-0.4px; }
        .page-subtitle { font-size:13px; color:var(--text-muted); margin-top:2px; }
        .topbar-actions { display:flex; align-items:center; gap:10px; }
        .topbar-btn { width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); text-decoration:none; position:relative; }
        .topbar-btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .topbar-btn.theme-btn:hover { transform:rotate(20deg); }
        .topbar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:14px; font-weight:700; box-shadow:0 4px 12px rgba(59,130,246,0.3); cursor:pointer; }

        .content { padding:28px; flex:1; }

        .back-link { display:inline-flex; align-items:center; gap:8px; color:var(--primary); text-decoration:none; font-size:14px; font-weight:600; margin-bottom:18px; transition:var(--transition); }
        .back-link:hover { color:var(--primary-dark); gap:11px; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.5; }
        .alert-success { background:var(--success-light); color:#059669; border:1px solid rgba(16,185,129,0.25); }
        [data-theme="dark"] .alert-success { color:#34d399; }
        .alert-error { background:var(--danger-light); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        [data-theme="dark"] .alert-error { color:#f87171; }

        .card { max-width:820px; margin:0 auto; background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-lg); overflow:hidden; }
        .card-head { padding:26px 30px; border-bottom:1px solid var(--border-color); background:linear-gradient(135deg, rgba(37,99,235,0.06), rgba(124,58,237,0.04)); }
        .card-head h2 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; margin-bottom:6px; }
        .card-head h2 i { color:var(--primary); }
        .card-head p { font-size:14px; color:var(--text-muted); }
        .card-body { padding:28px 30px; }

        .section-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.7px; color:var(--text-muted); margin:4px 0 14px; padding-bottom:8px; border-bottom:1px dashed var(--border-color); display:flex; align-items:center; gap:8px; }
        .section-title i { color:var(--primary); font-size:12px; }

        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .form-group { margin-bottom:18px; }
        .form-group label { display:block; font-size:13.5px; font-weight:600; color:var(--text-primary); margin-bottom:8px; }
        .form-group label .required { color:#ef4444; }
        .form-group label .opt { color: var(--text-muted); font-weight: 500; font-size: 12px; margin-left: 4px; }

        /* CLEAN INPUTS — no icons inside */
        .form-group input,
        .form-group select,
        .form-group textarea {
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
        .form-group textarea { resize: vertical; min-height: 90px; }
        .form-group select { cursor:pointer; }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
            background: var(--bg-card);
        }
        .form-help { font-size:12.5px; color:var(--text-muted); margin-top:6px; line-height:1.5; }

        .radio-pills { display:flex; flex-wrap:wrap; gap:10px; }
        .radio-pill { position:relative; display:inline-flex; align-items:center; gap:8px; padding:10px 16px; border:1.5px solid var(--border-color); border-radius:10px; cursor:pointer; transition:var(--transition); background:var(--bg-alt); font-size:13.5px; font-weight:600; color:var(--text-primary); }
        .radio-pill input { position:absolute; opacity:0; pointer-events:none; }
        .radio-pill .dot { width:16px; height:16px; border-radius:50%; border:2px solid var(--border-color); position:relative; transition:var(--transition); flex-shrink:0; }
        .radio-pill .dot::after { content:''; position:absolute; inset:3px; border-radius:50%; background:var(--primary); opacity:0; transform:scale(0.4); transition:var(--transition); }
        .radio-pill:hover { border-color:var(--primary); }
        .radio-pill.selected { border-color:var(--primary); background:var(--primary-light); }
        .radio-pill.selected .dot { border-color:var(--primary); }
        .radio-pill.selected .dot::after { opacity:1; transform:scale(1); }

        .check-pills { display:flex; flex-wrap:wrap; gap:10px; }
        .check-pill { position:relative; display:inline-flex; align-items:center; gap:8px; padding:10px 16px; border:1.5px solid var(--border-color); border-radius:10px; cursor:pointer; transition:var(--transition); background:var(--bg-alt); font-size:13.5px; font-weight:600; color:var(--text-primary); user-select:none; }
        .check-pill input { position:absolute; opacity:0; pointer-events:none; }
        .check-pill .box { width:18px; height:18px; border-radius:6px; border:2px solid var(--border-color); background:var(--bg-card); display:flex; align-items:center; justify-content:center; transition:var(--transition); flex-shrink:0; }
        .check-pill .box i { font-size:10px; color:#fff; opacity:0; transform:scale(0.4); transition:var(--transition); }
        .check-pill:hover { border-color:var(--primary); }
        .check-pill.selected { border-color:var(--primary); background:var(--primary-light); }
        .check-pill.selected .box { background:var(--primary); border-color:var(--primary); }
        .check-pill.selected .box i { opacity:1; transform:scale(1); }

        .switch-row { display:flex; align-items:center; gap:14px; padding:14px 18px; background:var(--bg-alt); border:1.5px solid var(--border-color); border-radius:12px; }
        .switch { position:relative; width:46px; height:26px; border-radius:50px; background:var(--border-color); cursor:pointer; transition:var(--transition); flex-shrink:0; }
        .switch::after { content:''; position:absolute; top:3px; left:3px; width:20px; height:20px; border-radius:50%; background:#fff; box-shadow:0 2px 6px rgba(0,0,0,0.2); transition:var(--transition); }
        .switch.on { background:var(--primary); }
        .switch.on::after { transform:translateX(20px); }
        .switch-row .sr-text { flex:1; }
        .switch-row .sr-text .t { font-size:14px; font-weight:700; color:var(--text-primary); }
        .switch-row .sr-text .d { font-size:12.5px; color:var(--text-muted); margin-top:2px; }
        .switch-row .sr-state { font-size:12px; font-weight:700; padding:3px 10px; border-radius:50px; background:var(--border-color); color:var(--text-secondary); transition:var(--transition); }
        .switch-row.on { border-color:var(--primary); background:var(--primary-light); }
        .switch-row.on .sr-state { background:var(--primary); color:#fff; }

        .no-levels-box {
            padding: 16px 18px;
            background: var(--warning-light);
            border: 1.5px solid rgba(245,158,11,0.35);
            border-radius: 12px;
            color: #b45309;
            font-size: 13.5px;
            line-height: 1.6;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        [data-theme="dark"] .no-levels-box { color: #fbbf24; }
        .no-levels-box i { font-size: 18px; flex-shrink: 0; margin-top: 2px; }
        .no-levels-box a { color: inherit; font-weight: 700; text-decoration: underline; }

        #bankCustomWrap { margin-top:12px; }

        .form-actions { display:flex; gap:12px; justify-content:flex-end; padding-top:20px; border-top:1px solid var(--border-color); margin-top:12px; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:13px 24px; border-radius:10px; font-size:14.5px; font-weight:600; text-decoration:none; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); transition:var(--transition); font-family:'Inter',sans-serif; }
        .btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }
        .btn:disabled { opacity:0.7; cursor:not-allowed; transform:none; }

        .overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:90; backdrop-filter:blur(2px); }
        .overlay.show { display:block; }

        @media (max-width: 900px) {
            .sidebar { transform:translateX(-100%); }
            .sidebar.open { transform:translateX(0); box-shadow:0 0 40px rgba(0,0,0,0.2); }
            .main { margin-left:0; }
            .hamburger { display:flex; }
        }
        @media (max-width: 700px) { .form-row { grid-template-columns:1fr; } }
        @media (max-width: 600px) {
            .content { padding:20px 16px; }
            .topbar { padding:12px 16px; }
            .page-title { font-size:17px; }
            .card-body { padding:22px 20px; }
            .card-head { padding:22px 20px; }
        }
    </style>
</head>
<body>

<div class="layout">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="logo.png" alt="Educ Bridge">
            <div class="sidebar-brand">Educ<span>Bridge</span><small><?= htmlspecialchars($short_school); ?></small></div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section-title">Overview</div>
            <a href="school-dashboard.php" class="nav-item"><i class="fas fa-th-large nav-icon"></i> Dashboard</a>
            <div class="nav-section-title">Academics</div>
            <a href="school-academic-levels.php" class="nav-item"><i class="fas fa-graduation-cap nav-icon"></i> Academic Level</a>
            <a href="school-students.php" class="nav-item"><i class="fas fa-user-graduate nav-icon"></i> Students</a>
            <a href="school-teachers.php" class="nav-item active"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>
            <a href="school-classes.php" class="nav-item"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
            <a href="school-subjects.php" class="nav-item"><i class="fas fa-book nav-icon"></i> Subjects</a>
            <div class="nav-section-title">Communication</div>
            <a href="school-announcements.php" class="nav-item"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>
            <a href="school-events.php" class="nav-item"><i class="fas fa-calendar-alt nav-icon"></i> Events</a>
            <div class="nav-section-title">System</div>
            <a href="school-reports.php" class="nav-item active"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
            <a href="school-settings.php" class="nav-item"><i class="fas fa-cog nav-icon"></i> Settings</a>
        </nav>
        <div class="sidebar-profile">
            <div class="sidebar-avatar"><?= htmlspecialchars($initials); ?></div>
            <div class="sidebar-profile-info">
                <div class="p-name"><?= htmlspecialchars($school_admin); ?></div>
                <div class="p-role"><?= htmlspecialchars($short_school); ?></div>
            </div>
            <a href="school-dashboard.php?logout=1" class="sidebar-logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="overlay" id="overlay"></div>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Edit Teacher</div>
                    <div class="page-subtitle"><?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>
                <a href="school-announcements.php" class="topbar-btn" title="Announcements"><i class="fas fa-bell"></i></a>
                <div class="topbar-avatar" title="<?= htmlspecialchars($school_admin); ?>"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <a href="school-teachers.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Teachers</a>

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-pen"></i> Edit Teacher</h2>
                    <p>Update the details for <strong><?= htmlspecialchars($teacher['name']); ?></strong>. An email will be sent to them if anything changes.</p>
                </div>
                <div class="card-body">
                    <form method="POST" action="school-edit-teacher.php?id=<?= (int)$id; ?>" id="teacherForm" autocomplete="off">

                        <div class="section-title"><i class="fas fa-user"></i> Personal Information</div>

                        <div class="form-group">
                            <label for="name">Full Name <span class="required">*</span></label>
                            <input type="text" id="name" name="name" maxlength="150" value="<?= htmlspecialchars($f['name']); ?>" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="email">Email <span class="required">*</span></label>
                                <input type="email" id="email" name="email" maxlength="150" value="<?= htmlspecialchars($f['email']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="phone">Phone Number <span class="required">*</span></label>
                                <input type="tel" id="phone" name="phone" maxlength="20"
                                       placeholder="e.g. 0783561459 or +250783561459"
                                       value="<?= htmlspecialchars($f['phone']); ?>" required>
                                <div class="form-help">Rwandan format: <strong>07XXXXXXXX</strong> (10 digits) or <strong>+2507XXXXXXXX</strong>.</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="id_number">National ID Number <span class="opt">(optional)</span></label>
                            <input type="text" id="id_number" name="id_number" maxlength="25"
                                   placeholder="e.g. 1199870012345678"
                                   value="<?= htmlspecialchars($f['id_number']); ?>"
                                   inputmode="numeric">
                            <div class="form-help">Rwandan National ID — exactly <strong>16 digits</strong>. Spaces and dashes are ignored.</div>
                        </div>

                        <div class="form-group">
                            <label>Sex <span class="required">*</span></label>
                            <div class="radio-pills" data-group="sex">
                                <label class="radio-pill <?= $f['sex'] === 'Male'   ? 'selected' : ''; ?>">
                                    <input type="radio" name="sex" value="Male"   <?= $f['sex'] === 'Male'   ? 'checked' : ''; ?> required>
                                    <span class="dot"></span> Male
                                </label>
                                <label class="radio-pill <?= $f['sex'] === 'Female' ? 'selected' : ''; ?>">
                                    <input type="radio" name="sex" value="Female" <?= $f['sex'] === 'Female' ? 'checked' : ''; ?> required>
                                    <span class="dot"></span> Female
                                </label>
                            </div>
                        </div>

                        <div class="section-title" style="margin-top:14px;"><i class="fas fa-briefcase"></i> Professional</div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="diploma">Diploma <span class="required">*</span></label>
                                <select id="diploma" name="diploma" required>
                                    <option value="">— Select diploma —</option>
                                    <?php foreach (['A2','A1','A0','Masters','PhD','Professor'] as $d): ?>
                                        <option value="<?= $d; ?>" <?= $f['diploma'] === $d ? 'selected' : ''; ?>><?= $d; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Teaching Level(s) <span class="required">*</span></label>

                                <?php if (empty($valid_level_enums)): ?>
                                    <div class="no-levels-box">
                                        <i class="fas fa-triangle-exclamation"></i>
                                        <div>
                                            <strong>No active academic levels.</strong><br>
                                            Your school hasn't added any levels yet. Go to
                                            <a href="school-academic-levels.php">Academic Levels</a>
                                            and add at least one level before assigning teachers.
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="check-pills" data-group="teaching_level">
                                        <?php foreach ($valid_level_enums as $val):
                                            $label   = $level_display[$val] ?? $val;
                                            $checked = in_array($val, $f['teaching_levels'], true);
                                        ?>
                                            <label class="check-pill <?= $checked ? 'selected' : ''; ?>">
                                                <input type="checkbox" name="teaching_level[]" value="<?= htmlspecialchars($val); ?>" <?= $checked ? 'checked' : ''; ?>>
                                                <span class="box"><i class="fas fa-check"></i></span>
                                                <?= htmlspecialchars($label); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="form-help">Select one or more levels this teacher can teach.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="section-title" style="margin-top:14px;"><i class="fas fa-credit-card"></i> Payment Account</div>

                        <div class="form-group">
                            <label for="bank_type_choice">Bank / Wallet Type</label>
                            <select id="bank_type_choice" name="bank_type_choice">
                                <option value="">— Select bank / wallet —</option>
                                <?php foreach ($bank_presets as $b): ?>
                                    <option value="<?= htmlspecialchars($b); ?>" <?= $f['bank_type_choice'] === $b ? 'selected' : ''; ?>>
                                        <?= htmlspecialchars($b); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="Other" <?= $f['bank_type_choice'] === 'Other' ? 'selected' : ''; ?>>Other (type your own)</option>
                            </select>
                        </div>

                        <div class="form-group" id="bankCustomWrap" style="<?= $f['bank_type_choice'] === 'Other' ? '' : 'display:none;'; ?>">
                            <label for="bank_type_custom">Bank / Wallet Name <span class="required">*</span></label>
                            <input type="text" id="bank_type_custom" name="bank_type_custom" maxlength="120" value="<?= htmlspecialchars($f['bank_type_custom']); ?>">
                            <div class="form-help">Type the name of your bank or wallet since it wasn't in the list.</div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="account_name">Account Name</label>
                                <input type="text" id="account_name" name="account_name" maxlength="150" value="<?= htmlspecialchars($f['account_name']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="account_number">Account Number <span class="opt">(MoMo/Airtel: phone)</span></label>
                                <input type="text" id="account_number" name="account_number" maxlength="80"
                                       placeholder="e.g. 0001234567890 or 0783561459"
                                       value="<?= htmlspecialchars($f['account_number']); ?>">
                                <div class="form-help">For Mobile Money / Airtel Money, this must be a valid Rwandan phone number.</div>
                            </div>
                        </div>

                        <div class="section-title" style="margin-top:14px;"><i class="fas fa-wheelchair"></i> Disability</div>

                        <div class="switch-row <?= (int)$f['has_disability'] === 1 ? 'on' : ''; ?>" id="disabilityRow">
                            <div class="switch <?= (int)$f['has_disability'] === 1 ? 'on' : ''; ?>" id="disabilitySwitch" onclick="toggleDisability()"></div>
                            <div class="sr-text">
                                <div class="t">Has a disability?</div>
                                <div class="d">Turn this on if the teacher has a disability, then provide details below.</div>
                            </div>
                            <div class="sr-state" id="disabilityState"><?= (int)$f['has_disability'] === 1 ? 'YES' : 'NO'; ?></div>
                        </div>
                        <input type="hidden" name="has_disability" id="has_disability" value="<?= (int)$f['has_disability']; ?>">

                        <div class="form-group" id="disabilityDetailsWrap" style="margin-top:16px; <?= (int)$f['has_disability'] === 1 ? '' : 'display:none;'; ?>">
                            <label for="disability_details">Disability Details</label>
                            <textarea id="disability_details" name="disability_details" maxlength="1000"><?= htmlspecialchars($f['disability_details']); ?></textarea>
                            <div class="form-help">Required if "Has a disability?" is set to Yes.</div>
                        </div>

                        <div class="section-title" style="margin-top:14px;"><i class="fas fa-toggle-on"></i> Status</div>

                        <div class="form-group">
                            <div class="radio-pills" data-group="status">
                                <label class="radio-pill <?= $f['status'] === 'active'   ? 'selected' : ''; ?>">
                                    <input type="radio" name="status" value="active"   <?= $f['status'] === 'active'   ? 'checked' : ''; ?> required>
                                    <span class="dot"></span> Active
                                </label>
                                <label class="radio-pill <?= $f['status'] === 'inactive' ? 'selected' : ''; ?>">
                                    <input type="radio" name="status" value="inactive" <?= $f['status'] === 'inactive' ? 'checked' : ''; ?> required>
                                    <span class="dot"></span> Inactive
                                </label>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="school-teachers.php" class="btn"><i class="fas fa-times"></i> Cancel</a>
                            <button type="submit" class="btn primary" id="saveBtn" <?= empty($valid_level_enums) ? 'disabled' : ''; ?>>
                                <i class="fas fa-save"></i> Save Changes
                            </button>
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

    (function () {
        const hamburger = document.getElementById('hamburger');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('show'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) closeSidebar(); else openSidebar();
        });
        overlay.addEventListener('click', closeSidebar);
    })();

    document.querySelectorAll('.radio-pills').forEach(function (group) {
        group.querySelectorAll('.radio-pill').forEach(function (pill) {
            pill.addEventListener('click', function () {
                group.querySelectorAll('.radio-pill').forEach(p => p.classList.remove('selected'));
                pill.classList.add('selected');
                const radio = pill.querySelector('input');
                if (radio) radio.checked = true;
            });
        });
    });

    document.querySelectorAll('.check-pills').forEach(function (group) {
        group.querySelectorAll('.check-pill').forEach(function (pill) {
            pill.addEventListener('click', function () {
                const cb = pill.querySelector('input[type="checkbox"]');
                setTimeout(function () { pill.classList.toggle('selected', cb.checked); }, 0);
            });
        });
    });

    function toggleDisability() {
        const sw    = document.getElementById('disabilitySwitch');
        const row   = document.getElementById('disabilityRow');
        const state = document.getElementById('disabilityState');
        const input = document.getElementById('has_disability');
        const wrap  = document.getElementById('disabilityDetailsWrap');

        const next = input.value !== '1';
        input.value = next ? '1' : '0';
        sw.classList.toggle('on', next);
        row.classList.toggle('on', next);
        state.textContent = next ? 'YES' : 'NO';
        wrap.style.display = next ? '' : 'none';

        const details = document.getElementById('disability_details');
        if (!next) { details.value = ''; } else { details.focus(); }
    }

    (function () {
        const select = document.getElementById('bank_type_choice');
        const wrap   = document.getElementById('bankCustomWrap');
        const input  = document.getElementById('bank_type_custom');

        function refresh() {
            if (select.value === 'Other') {
                wrap.style.display = '';
                input.required = true;
            } else {
                wrap.style.display = 'none';
                input.required = false;
                input.value = '';
            }
        }
        select.addEventListener('change', refresh);
        refresh();
    })();

    document.getElementById('teacherForm').addEventListener('submit', function () {
        const btn = document.getElementById('saveBtn');
        if (btn.disabled) return;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    });
</script>

</body>
</html>