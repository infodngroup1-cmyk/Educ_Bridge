<?php
/*
|--------------------------------------------------------------------------
| Shared PHPMailer bootstrap
|--------------------------------------------------------------------------
| Install PHPMailer first (composer or manual):
|   composer require phpmailer/phpmailer
| Or drop the PHPMailer folder somewhere and require its autoloader below.
*/

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/autoload.php';   // adjust if you installed manually

/* =========================================================================
 |  Shared SMTP config (keep in one place)
 * ======================================================================= */
if (!defined('EB_SMTP_HOST'))     define('EB_SMTP_HOST',     'smtp.gmail.com');
if (!defined('EB_SMTP_USER'))     define('EB_SMTP_USER',     'info.dngroup1@gmail.com');
if (!defined('EB_SMTP_PASS'))     define('EB_SMTP_PASS',     'rsxm dxju dnvp yleu');  // App Password
if (!defined('EB_SMTP_PORT'))     define('EB_SMTP_PORT',     587);
if (!defined('EB_SMTP_SECURE'))   define('EB_SMTP_SECURE',   PHPMailer::ENCRYPTION_STARTTLS);
if (!defined('EB_FROM_EMAIL'))    define('EB_FROM_EMAIL',    'info.dngroup1@gmail.com');
if (!defined('EB_FROM_NAME'))     define('EB_FROM_NAME',     'Educ Bridge');

/* =========================================================================
 |  Internal helper — creates a pre-configured PHPMailer instance
 * ======================================================================= */
function eb_mailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = EB_SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = EB_SMTP_USER;
    $mail->Password   = EB_SMTP_PASS;
    $mail->SMTPSecure = EB_SMTP_SECURE;
    $mail->Port       = EB_SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(EB_FROM_EMAIL, EB_FROM_NAME);
    return $mail;
}

/* =========================================================================
 |  Shared wrapper
 * ======================================================================= */
function eb_email_wrapper(string $inner_html, string $footer_text = ''): string {
    return "
    <div style=\"font-family:Inter,Arial,sans-serif;background:#f1f5f9;padding:24px;\">
      <div style=\"max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;\">

        <div style=\"padding:26px 28px 20px;background:linear-gradient(135deg,rgba(37,99,235,.08),rgba(124,58,237,.05));border-bottom:1px solid #e2e8f0;\">
          <div style=\"font-family:Poppins,Arial;font-size:20px;font-weight:700;color:#0f172a;\">
            Educ<span style=\"color:#2563eb;\">Bridge</span>
          </div>
        </div>

        <div style=\"padding:26px 28px;\">
          {$inner_html}
        </div>

        <div style=\"padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;font-size:12px;color:#94a3b8;\">
          Powered by Educ Bridge" . ($footer_text !== '' ? ' · ' . htmlspecialchars($footer_text, ENT_QUOTES, 'UTF-8') : '') . "
        </div>
      </div>
    </div>";
}

/* =========================================================================
 |  1. APPLICATION RECEIVED
 * ======================================================================= */
/**
 * Sends the "application received" email.
 * Returns [true, ''] on success, [false, 'error message'] on failure.
 */
function send_application_email(array $opts): array {
    $to_email     = $opts['to_email']     ?? '';
    $to_name      = $opts['to_name']      ?? '';
    $ref          = $opts['reference']    ?? '';
    $school_name  = $opts['school_name']  ?? '';
    $school_code  = $opts['school_code']  ?? '';
    $student_name = $opts['student_name'] ?? '';
    $level_label  = $opts['level_label']  ?? '';
    $class_name   = $opts['class_name']   ?? '';
    $phone        = $opts['phone']        ?? '';
    $check_url    = $opts['check_url']    ?? '';

    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'No valid recipient email.'];
    }

    $safe = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    try {
        $mail = eb_mailer();
        $mail->addAddress($to_email, $to_name ?: $student_name);

        $mail->isHTML(true);
        $mail->Subject = "Application received — {$ref}";

        $inner = "
          <p style=\"font-size:15px;color:#0f172a;margin:0 0 12px;\">
            Dear <strong>{$safe($student_name)}</strong>,
          </p>
          <p style=\"font-size:14px;color:#475569;line-height:1.65;margin:0 0 18px;\">
            Your application to <strong>{$safe($school_name)}</strong> has been received.
            Keep the reference number below — you will need it to check your admission status.
          </p>

          <div style=\"padding:20px;border-radius:12px;background:#f8fafc;border:1px dashed #cbd5e1;text-align:center;margin:0 0 20px;\">
            <div style=\"font-size:11px;font-weight:800;letter-spacing:1.2px;color:#64748b;text-transform:uppercase;margin-bottom:8px;\">
              Your reference number
            </div>
            <div style=\"font-family:Poppins,Arial;font-size:28px;font-weight:800;letter-spacing:2px;color:#2563eb;\">
              {$safe($ref)}
            </div>
          </div>

          <table style=\"width:100%;border-collapse:collapse;font-size:13.5px;color:#0f172a;\">
            <tr><td style=\"padding:6px 0;color:#64748b;font-weight:600;width:130px;\">Student</td><td style=\"padding:6px 0;font-weight:600;\">{$safe($student_name)}</td></tr>
            <tr><td style=\"padding:6px 0;color:#64748b;font-weight:600;\">Level</td><td style=\"padding:6px 0;font-weight:600;\">{$safe($level_label)}</td></tr>
            <tr><td style=\"padding:6px 0;color:#64748b;font-weight:600;\">Class</td><td style=\"padding:6px 0;font-weight:600;\">{$safe($class_name)}</td></tr>
            <tr><td style=\"padding:6px 0;color:#64748b;font-weight:600;\">School</td><td style=\"padding:6px 0;font-weight:600;\">{$safe($school_name)}</td></tr>
            " . ($phone !== '' ? "<tr><td style=\"padding:6px 0;color:#64748b;font-weight:600;\">Contact phone</td><td style=\"padding:6px 0;font-weight:600;\">{$safe($phone)}</td></tr>" : "") . "
            <tr><td style=\"padding:6px 0;color:#64748b;font-weight:600;\">Status</td><td style=\"padding:6px 0;font-weight:600;color:#f59e0b;\">Pending review</td></tr>
          </table>

          " . ($check_url !== '' ? "
          <div style=\"text-align:center;margin:24px 0 6px;\">
            <a href=\"{$safe($check_url)}\"
               style=\"display:inline-block;padding:13px 26px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px;\">
              Check admission status
            </a>
          </div>
          " : "") . "

          <p style=\"font-size:12.5px;color:#94a3b8;line-height:1.6;margin:20px 0 0;\">
            If you didn't submit this application, please ignore this email.
          </p>
        ";

        $mail->Body    = eb_email_wrapper($inner, "School code {$school_code}");
        $mail->AltBody = "Application received.\nReference: {$ref}\nStudent: {$student_name}\nSchool: {$school_name}\nLevel: {$level_label}\nClass: {$class_name}\nStatus: Pending review";

        $mail->send();
        return [true, ''];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}

/* =========================================================================
 |  2. ADMISSION DECISION (approved / rejected)
 * ======================================================================= */
/**
 * Sends the admission decision email.
 * Returns [true, ''] on success, [false, 'error message'] on failure.
 *
 * Required keys:
 *   to_email, to_name, reference, school_name, school_code,
 *   student_name, level_label, class_name, decision ('approved'|'rejected'),
 *   note (optional), check_url (optional)
 */
function send_decision_email(array $opts): array {
    $to_email     = $opts['to_email']     ?? '';
    $to_name      = $opts['to_name']      ?? '';
    $ref          = $opts['reference']    ?? '';
    $school_name  = $opts['school_name']  ?? '';
    $school_code  = $opts['school_code']  ?? '';
    $student_name = $opts['student_name'] ?? '';
    $level_label  = $opts['level_label']  ?? '';
    $class_name   = $opts['class_name']   ?? '';
    $decision     = strtolower($opts['decision'] ?? '');   // 'approved' | 'rejected'
    $note         = $opts['note']         ?? '';
    $check_url    = $opts['check_url']    ?? '';

    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return [false, 'No valid recipient email.'];
    }
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        return [false, 'Invalid decision value.'];
    }

    $is_approved = ($decision === 'approved');
    $safe = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    try {
        $mail = eb_mailer();
        $mail->addAddress($to_email, $to_name ?: $student_name);

        $mail->isHTML(true);
        $mail->Subject = $is_approved
            ? "Admission approved — {$ref}"
            : "Admission decision — {$ref}";

        $headline = $is_approved
            ? "Congratulations, you have been admitted!"
            : "Update on your application";

        $blurb = $is_approved
            ? "We are pleased to inform you that <strong>{$safe($student_name)}</strong> has been <strong>admitted</strong> to <strong>{$safe($school_name)}</strong>."
            : "We have reviewed the application of <strong>{$safe($student_name)}</strong> for <strong>{$safe($school_name)}</strong>. Unfortunately, we are unable to offer a place at this time.";

        $tint    = $is_approved ? '#10b981' : '#ef4444';
        $tint_bg = $is_approved ? 'rgba(16,185,129,.10)' : 'rgba(239,68,68,.10)';
        $badge   = $is_approved ? 'ADMITTED' : 'NOT ADMITTED';
        $status_label = $is_approved ? 'Admitted' : 'Not admitted';

        $note_block = '';
        if (trim($note) !== '') {
            $note_block = "
              <div style=\"margin:18px 0 0;padding:14px 16px;border-radius:10px;background:#f8fafc;border-left:4px solid {$tint};font-size:13.5px;color:#475569;line-height:1.6;\">
                <strong style=\"color:#0f172a;\">Note from the school:</strong><br>" . nl2br($safe($note)) . "
              </div>";
        }

        $inner = "
          <div style=\"display:inline-block;padding:6px 14px;border-radius:50px;background:{$tint_bg};color:{$tint};font-size:11.5px;font-weight:800;letter-spacing:1.2px;margin-bottom:14px;\">
            {$badge}
          </div>

          <h1 style=\"font-family:Poppins,Arial;font-size:20px;font-weight:700;color:#0f172a;margin:0 0 12px;\">
            {$headline}
          </h1>

          <p style=\"font-size:14px;color:#475569;line-height:1.65;margin:0 0 18px;\">
            {$blurb}
          </p>

          <div style=\"padding:16px;border-radius:12px;background:#f8fafc;border:1px dashed #cbd5e1;text-align:center;margin:20px 0;\">
            <div style=\"font-size:11px;font-weight:800;letter-spacing:1.2px;color:#64748b;text-transform:uppercase;margin-bottom:6px;\">
              Reference number
            </div>
            <div style=\"font-family:Poppins,Arial;font-size:22px;font-weight:800;letter-spacing:1.5px;color:#2563eb;\">
              {$safe($ref)}
            </div>
          </div>

          <table style=\"width:100%;border-collapse:collapse;font-size:13.5px;color:#0f172a;\">
            <tr><td style=\"padding:6px 0;color:#64748b;font-weight:600;width:130px;\">Student</td><td style=\"padding:6px 0;font-weight:600;\">{$safe($student_name)}</td></tr>
            <tr><td style=\"padding:6px 0;color:#64748b;font-weight:600;\">School</td><td style=\"padding:6px 0;font-weight:600;\">{$safe($school_name)}</td></tr>
            <tr><td style=\"padding:6px 0;color:#64748b;font-weight:600;\">Level</td><td style=\"padding:6px 0;font-weight:600;\">{$safe($level_label)}</td></tr>
            <tr><td style=\"padding:6px 0;color:#64748b;font-weight:600;\">Class</td><td style=\"padding:6px 0;font-weight:600;\">{$safe($class_name)}</td></tr>
            <tr><td style=\"padding:6px 0;color:#64748b;font-weight:600;\">Status</td><td style=\"padding:6px 0;font-weight:800;color:{$tint};\">{$status_label}</td></tr>
          </table>

          {$note_block}

          " . ($check_url !== '' ? "
          <div style=\"text-align:center;margin:26px 0 6px;\">
            <a href=\"{$safe($check_url)}\"
               style=\"display:inline-block;padding:13px 26px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px;\">
              View application status
            </a>
          </div>
          " : "") . "

          <p style=\"font-size:12.5px;color:#94a3b8;line-height:1.6;margin:20px 0 0;\">
            For any questions, contact the school directly.
          </p>
        ";

        $mail->Body    = eb_email_wrapper($inner, "School code {$school_code}");
        $mail->AltBody =
            ($is_approved ? "ADMITTED\n\n" : "NOT ADMITTED\n\n") .
            "Student: {$student_name}\n" .
            "School: {$school_name}\n" .
            "Reference: {$ref}\n" .
            "Level: {$level_label}\n" .
            "Class: {$class_name}\n" .
            "Status: {$status_label}\n" .
            ($note !== '' ? "\nNote from the school:\n{$note}\n" : '');

        $mail->send();
        return [true, ''];
    } catch (Exception $e) {
        return [false, $mail->ErrorInfo ?: $e->getMessage()];
    }
}