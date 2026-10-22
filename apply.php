<?php
require_once 'db.php';
require_once 'mailer.php';

/* helpers */
function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function normalize_level_enum($raw) {
    $k = strtolower(trim((string) $raw));
    $k = str_replace(['-', '_', ' ', "'", '’'], '', $k);
    switch ($k) {
        case 'nursery': return 'Nursery';
        case 'primary': return 'Primary';
        case 'olevel':  return 'O_Level';
        case 'alevel':  return 'A_Level';
    }
    return null;
}
$LEVEL_LABELS = ['Nursery'=>'Nursery','Primary'=>'Primary','O_Level'=>"O' Level",'A_Level'=>"A' Level"];
$LEVEL_ORDER  = ['Nursery','Primary','O_Level','A_Level'];

/* ------------------------------------------------------------------ *
 |  1. Resolve school
 * ------------------------------------------------------------------ */
$school_code = strtoupper(trim((string)($_GET['school'] ?? '')));
$school      = null;

if ($school_code !== '') {
    $s = mysqli_prepare($conn, "
        SELECT id, school_code, name, admin_name, country, province, district, type, status
        FROM schools WHERE school_code = ? LIMIT 1
    ");
    if ($s) {
        mysqli_stmt_bind_param($s, "s", $school_code);
        mysqli_stmt_execute($s);
        $r = mysqli_stmt_get_result($s);
        if ($row = mysqli_fetch_assoc($r)) $school = $row;
        mysqli_stmt_close($s);
    }
}

function render_blocked($title, $body) {
    ?>
    <!DOCTYPE html><html lang="en" data-theme="light"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= esc($title); ?> | Educ Bridge</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{--primary:#2563eb;--danger:#ef4444;--warning:#f59e0b;--bg:#f8fafc;--card:#fff;--text:#0f172a;--muted:#64748b;--border:#e2e8f0}
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
        .box{max-width:480px;width:100%;background:var(--card);border:1px solid var(--border);border-radius:20px;padding:36px 30px;text-align:center;box-shadow:0 20px 45px rgba(0,0,0,.08)}
        .box .ico{width:64px;height:64px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:16px}
        .ico.red{background:rgba(239,68,68,.1);color:var(--danger)}
        .ico.orange{background:rgba(245,158,11,.12);color:var(--warning)}
        h1{font-family:'Poppins',sans-serif;font-size:22px;margin-bottom:10px}
        p{color:var(--muted);line-height:1.65;font-size:14.5px}
        .btn{display:inline-flex;align-items:center;gap:8px;margin-top:22px;padding:12px 22px;background:var(--primary);color:#fff;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px;box-shadow:0 6px 18px rgba(37,99,235,.3)}
    </style></head><body>
    <div class="box"><?= $body; ?></div></body></html>
    <?php exit;
}

if (!$school) {
    render_blocked('School not found',
        '<div class="ico red"><i class="fas fa-school-circle-xmark"></i></div>
         <h1>School not found</h1>
         <p>The school you are trying to apply to does not exist or the link is invalid.</p>
         <a class="btn" href="online-registration.php"><i class="fas fa-rotate-left"></i> Verify another code</a>');
}
if (strtolower($school['status']) !== 'active') {
    render_blocked('School unavailable',
        '<div class="ico red"><i class="fas fa-ban"></i></div>
         <h1>Applications unavailable</h1>
         <p>This school is currently inactive. Please try again later.</p>');
}

/* ------------------------------------------------------------------ *
 |  2. Intake settings
 * ------------------------------------------------------------------ */
$cfg = null;
$st = mysqli_prepare($conn, "SELECT * FROM application_settings WHERE school_id = ? LIMIT 1");
mysqli_stmt_bind_param($st, "i", $school['id']);
mysqli_stmt_execute($st);
$sr = mysqli_stmt_get_result($st);
if ($row = mysqli_fetch_assoc($sr)) $cfg = $row;
mysqli_stmt_close($st);

if (!$cfg) {
    render_blocked('Applications closed',
        '<div class="ico orange"><i class="fas fa-lock"></i></div>
         <h1>Applications not open</h1>
         <p>This school has not opened an intake yet. Please check back later.</p>');
}

$now = time();
$intake_open   = false;
$intake_reason = '';
switch ($cfg['mode']) {
    case 'open': $intake_open = true; break;
    case 'scheduled':
        $o = !empty($cfg['opens_at'])  ? strtotime($cfg['opens_at'])  : null;
        $c = !empty($cfg['closes_at']) ? strtotime($cfg['closes_at']) : null;
        if ($o && $c) {
            if ($now < $o)     $intake_reason = "Applications open on " . date('d M Y, H:i', $o) . ".";
            elseif ($now > $c) $intake_reason = "Applications closed on " . date('d M Y, H:i', $c) . ".";
            else               $intake_open = true;
        } else {
            $intake_reason = "The application window is not fully scheduled yet.";
        }
        break;
    default:
        $intake_reason = "This school is not accepting applications right now.";
}

if (!$intake_open) {
    render_blocked('Applications closed',
        '<div class="ico orange"><i class="fas fa-lock"></i></div>
         <h1>Applications closed</h1>
         <p>' . esc($intake_reason) . '</p>
         <a class="btn" href="online-school-application.php?school=' . urlencode($school['school_code']) . '"><i class="fas fa-arrow-left"></i> Back</a>');
}

/* ------------------------------------------------------------------ *
 |  3. Levels + classes
 * ------------------------------------------------------------------ */
$open_levels    = array_values(array_filter(array_map('trim', explode(',', (string)$cfg['levels']))));
$open_class_ids = array_values(array_filter(array_map('intval', explode(',', (string)$cfg['class_ids']))));

if (empty($open_levels) || empty($open_class_ids)) {
    render_blocked('Applications closed',
        '<div class="ico orange"><i class="fas fa-lock"></i></div>
         <h1>Applications not open</h1>
         <p>The school has not published any classes yet.</p>');
}

$KEY_TO_ENUM = [
    'nursery'=>'Nursery','primary'=>'Primary',
    'olevel'=>'O_Level','o_level'=>'O_Level','o-level'=>'O_Level',
    'alevel'=>'A_Level','a_level'=>'A_Level','a-level'=>'A_Level',
];
$school_levels = [];
$lvl_stmt = mysqli_prepare($conn, "
    SELECT level_key, level_name FROM academic_levels
    WHERE school_id = ? AND is_active = 1 ORDER BY id ASC
");
mysqli_stmt_bind_param($lvl_stmt, "i", $school['id']);
mysqli_stmt_execute($lvl_stmt);
$lvl_res = mysqli_stmt_get_result($lvl_stmt);
while ($row = mysqli_fetch_assoc($lvl_res)) {
    $enum = $KEY_TO_ENUM[strtolower(trim((string)$row['level_key']))] ?? null;
    if (!$enum || !in_array($enum, $open_levels, true)) continue;
    $label = trim((string)$row['level_name']);
    if ($label === '' || $label === $enum) $label = $LEVEL_LABELS[$enum];
    $school_levels[$enum] = $label;
}
mysqli_stmt_close($lvl_stmt);

$classes_by_level = [];
$class_lookup     = [];
if (!empty($open_class_ids) && !empty($school_levels)) {
    $ph = implode(',', array_fill(0, count($open_class_ids), '?'));
    $tc = "i" . str_repeat('i', count($open_class_ids));
    $cparams = array_merge([$school['id']], $open_class_ids);
    $cstmt = mysqli_prepare($conn, "
        SELECT id, class_name, level FROM classes
        WHERE school_id = ? AND status = 'active' AND id IN ($ph)
        ORDER BY id ASC
    ");
    mysqli_stmt_bind_param($cstmt, $tc, ...$cparams);
    mysqli_stmt_execute($cstmt);
    $cres = mysqli_stmt_get_result($cstmt);
    while ($c = mysqli_fetch_assoc($cres)) {
        $enum = normalize_level_enum($c['level']);
        if (!$enum || !isset($school_levels[$enum])) continue;
        $classes_by_level[$enum][] = ['id'=>(int)$c['id'],'name'=>$c['class_name']];
        $class_lookup[(int)$c['id']] = ['name'=>$c['class_name'],'level'=>$enum];
    }
    mysqli_stmt_close($cstmt);
}

$ordered_levels = [];
foreach ($LEVEL_ORDER as $enum) {
    if (isset($school_levels[$enum]) && !empty($classes_by_level[$enum])) {
        $ordered_levels[$enum] = $school_levels[$enum];
    }
}
if (empty($ordered_levels)) {
    render_blocked('Applications closed',
        '<div class="ico orange"><i class="fas fa-lock"></i></div>
         <h1>Applications not open</h1>
         <p>No classes are currently open for applications.</p>');
}

/* ------------------------------------------------------------------ *
 |  4. Handle POST
 * ------------------------------------------------------------------ */
$form = [
    'student_name'   => '',
    'dob'            => '',
    'sex'            => '',
    'level'          => '',
    'class_id'       => '',
    'recent_school'  => '',
    'recent_marks'   => '',
    'response_email' => '',
    'phone'          => '',
];
$errors       = [];
$duplicate_of = null;
$email_sent   = false;
$email_error  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? ''));

    if ($form['student_name'] === '' || mb_strlen($form['student_name']) < 3)
        $errors['student_name'] = 'Enter the full name of the student.';
    if ($form['dob'] === '') {
        $errors['dob'] = 'Date of birth is required.';
    } else {
        $ts = strtotime($form['dob']);
        if (!$ts || $ts > time()) $errors['dob'] = 'Enter a valid date of birth.';
    }
    if (!in_array(strtolower($form['sex']), ['male','female'], true))
        $errors['sex'] = 'Select the student sex.';
    if (!isset($ordered_levels[$form['level']]))
        $errors['level'] = 'Select the level.';

    $cid = (int)$form['class_id'];
    if (!$cid || !isset($class_lookup[$cid])) {
        $errors['class_id'] = 'Select the class.';
    } elseif (isset($ordered_levels[$form['level']]) && $class_lookup[$cid]['level'] !== $form['level']) {
        $errors['class_id'] = 'That class does not belong to the selected level.';
    }

    if ($form['response_email'] === '' || !filter_var($form['response_email'], FILTER_VALIDATE_EMAIL))
        $errors['response_email'] = 'Enter a valid response email.';

    if ($form['phone'] === '' || !preg_match('/^[0-9+\-\s()]{7,30}$/', $form['phone']))
        $errors['phone'] = 'Enter a valid phone number.';

    /* Duplicate check (name + recent_school + recent_marks at same school) */
    if (empty($errors)) {
        $dup = mysqli_prepare($conn, "
            SELECT id, reference_no, status, created_at
            FROM applications
            WHERE school_id = ?
              AND LOWER(TRIM(student_name))  = LOWER(TRIM(?))
              AND LOWER(TRIM(recent_school)) = LOWER(TRIM(?))
              AND LOWER(TRIM(recent_marks))  = LOWER(TRIM(?))
            LIMIT 1
        ");
        if ($dup) {
            mysqli_stmt_bind_param($dup, "isss",
                $school['id'],
                $form['student_name'],
                $form['recent_school'],
                $form['recent_marks']
            );
            mysqli_stmt_execute($dup);
            $dr = mysqli_stmt_get_result($dup);
            if ($row = mysqli_fetch_assoc($dr)) $duplicate_of = $row;
            mysqli_stmt_close($dup);
        }
    }

    /* Insert */
    if (empty($errors) && !$duplicate_of) {

        /* Reference: APP-XX-#### (XX from school code, #### random) */
        $prefix_digits = preg_replace('/\D/', '', (string)$school['school_code']);
        $prefix2       = str_pad(substr($prefix_digits, 0, 2), 2, '0', STR_PAD_LEFT);
        $ref           = '';
        for ($i = 0; $i < 12; $i++) {
            $rand4 = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
            $cand  = 'APP-' . $prefix2 . '-' . $rand4;
            $chk   = mysqli_prepare($conn, "SELECT id FROM applications WHERE reference_no = ? LIMIT 1");
            mysqli_stmt_bind_param($chk, "s", $cand);
            mysqli_stmt_execute($chk);
            $cr = mysqli_stmt_get_result($chk);
            $exists = $cr && mysqli_num_rows($cr) > 0;
            mysqli_stmt_close($chk);
            if (!$exists) { $ref = $cand; break; }
        }

        if ($ref === '') {
            $errors['__db'] = 'Could not generate a reference number. Please try again.';
        } else {
            $ins = mysqli_prepare($conn, "
                INSERT INTO applications
                    (school_id, reference_no, student_name, dob, sex, level, class_id,
                     recent_school, recent_marks, response_email, phone, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            if ($ins) {
                $dob = date('Y-m-d', strtotime($form['dob']));
                $sex = ucfirst(strtolower($form['sex']));
                mysqli_stmt_bind_param($ins, "isssssissss",
                    $school['id'],
                    $ref,
                    $form['student_name'],
                    $dob,
                    $sex,
                    $form['level'],
                    $cid,
                    $form['recent_school'],
                    $form['recent_marks'],
                    $form['response_email'],
                    $form['phone']
                );
                if (mysqli_stmt_execute($ins)) {
                    mysqli_stmt_close($ins);

                    /* ---- Send email ---- */
                    $check_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                               . '://' . $_SERVER['HTTP_HOST']
                               . dirname($_SERVER['SCRIPT_NAME'])
                               . '/online-school-application.php?school=' . urlencode($school['school_code'])
                               . '&ref=' . urlencode($ref);

                    [$ok, $err] = send_application_email([
                        'to_email'     => $form['response_email'],
                        'to_name'      => $form['student_name'],
                        'reference'    => $ref,
                        'school_name'  => $school['name'],
                        'school_code'  => $school['school_code'],
                        'student_name' => $form['student_name'],
                        'level_label'  => $LEVEL_LABELS[$form['level']] ?? $form['level'],
                        'class_name'   => $class_lookup[$cid]['name'] ?? '',
                        'phone'        => $form['phone'],
                        'check_url'    => $check_url,
                    ]);

                    if (!$ok) {
                        error_log('[apply] mail error: ' . $err);
                        /* Not fatal — the application is saved. We surface it on the success page. */
                        $_SESSION['apply_mail_warning'] = $err;
                    }

                    header("Location: apply-success.php?ref=" . urlencode($ref) . "&school=" . urlencode($school['school_code']));
                    exit;
                }
                $errors['__db'] = 'Could not submit the application. Please try again.';
                mysqli_stmt_close($ins);
            } else {
                $errors['__db'] = 'Server error. Please try again later.';
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
<title>Apply — <?= esc($school['name']); ?> | Educ Bridge</title>
<link rel="icon" type="image/png" href="logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root{
        --primary:#2563eb;--primary-dark:#1d4ed8;--primary-light:#dbeafe;
        --success:#10b981;--success-light:rgba(16,185,129,.1);
        --danger:#ef4444;--danger-light:rgba(239,68,68,.1);
        --warning:#f59e0b;--warning-light:rgba(245,158,11,.12);
        --bg:#f1f5f9;--card:#fff;--alt:#f8fafc;
        --text:#0f172a;--muted:#64748b;--soft:#475569;--border:#e2e8f0;
        --shadow:0 20px 45px rgba(0,0,0,.08);
        --t:all .3s cubic-bezier(.4,0,.2,1);
    }
    [data-theme="dark"]{
        --primary:#3b82f6;--primary-dark:#2563eb;--primary-light:#1e3a5f;
        --success:#34d399;--danger:#f87171;--warning:#fbbf24;
        --bg:#0b1120;--card:#1e293b;--alt:#1a2332;
        --text:#f1f5f9;--muted:#94a3b8;--soft:#cbd5e1;--border:#334155;
        --shadow:0 20px 45px rgba(0,0,0,.5);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    html,body{min-height:100vh}
    body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);padding:32px 16px;transition:background .3s,color .3s}
    .wrap{max-width:760px;margin:0 auto}

    .theme-toggle{position:fixed;top:20px;right:20px;width:42px;height:42px;border-radius:50%;border:1.5px solid var(--border);background:var(--card);color:var(--soft);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;z-index:20;transition:var(--t)}
    .theme-toggle:hover{color:var(--primary);border-color:var(--primary);transform:rotate(20deg)}

    .topnav{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap}
    .topnav a{display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:10px;background:var(--card);border:1.5px solid var(--border);color:var(--soft);text-decoration:none;font-size:13px;font-weight:600;transition:var(--t)}
    .topnav a:hover{border-color:var(--primary);color:var(--primary)}

    .brand{display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:22px}
    .brand img{width:46px;height:46px;border-radius:50%;background:#fff;padding:3px;border:2px solid var(--border)}
    .brand-text{font-family:'Poppins',sans-serif;font-size:20px;font-weight:700}
    .brand-text span{color:var(--primary)}

    .school-head{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:18px 22px;margin-bottom:16px;display:flex;align-items:center;gap:16px;box-shadow:0 4px 12px rgba(0,0,0,.04)}
    .school-head .ico{width:52px;height:52px;border-radius:50%;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:20px}
    .school-head h1{font-family:'Poppins',sans-serif;font-size:18px;font-weight:700}
    .school-head p{font-size:13px;color:var(--muted);margin-top:3px}

    .status{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;border-radius:12px;margin-bottom:18px;font-size:13.5px;font-weight:600}
    .status.green{background:var(--success-light);color:#059669;border:1px solid rgba(16,185,129,.25)}
    [data-theme="dark"] .status.green{color:#34d399}

    .card{background:var(--card);border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow);overflow:hidden}
    .card-head{padding:22px 26px 18px;border-bottom:1px solid var(--border);background:linear-gradient(135deg,rgba(37,99,235,.05),rgba(124,58,237,.03))}
    .card-head h2{font-family:'Poppins',sans-serif;font-size:18px;font-weight:700}
    .card-head p{font-size:13px;color:var(--muted);margin-top:5px;line-height:1.55}
    .card-body{padding:24px 26px 28px}

    .form-group{margin-bottom:18px}
    label{display:block;font-size:13px;font-weight:600;margin-bottom:7px}
    label .req{color:var(--danger)}
    input,select,textarea{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;background:var(--alt);color:var(--text);font-family:inherit;font-size:14.5px;outline:none;transition:var(--t)}
    input:focus,select:focus,textarea:focus{border-color:var(--primary);background:var(--card);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
    input.err,select.err,textarea.err{border-color:var(--danger);background:rgba(239,68,68,.04)}
    .hint{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.55}
    .hint strong{color:var(--text)}
    .err-msg{font-size:12.5px;color:var(--danger);margin-top:6px;font-weight:500}

    .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media (max-width:640px){.row{grid-template-columns:1fr}}

    .btn{width:100%;display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:14px 22px;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;border:none;background:var(--primary);color:#fff;transition:var(--t);font-family:inherit;box-shadow:0 6px 18px rgba(37,99,235,.32);margin-top:6px;text-decoration:none}
    .btn:hover{background:var(--primary-dark);transform:translateY(-1px);box-shadow:0 8px 22px rgba(37,99,235,.4)}
    .btn:disabled{opacity:.7;cursor:not-allowed;transform:none}

    .alert{padding:13px 16px;border-radius:10px;margin-bottom:18px;font-size:14px;display:flex;align-items:flex-start;gap:10px;line-height:1.55}
    .alert-error{background:var(--danger-light);color:#dc2626;border:1px solid rgba(239,68,68,.25)}
    [data-theme="dark"] .alert-error{color:#f87171}

    .dup-card{background:var(--warning-light);border:1px solid rgba(245,158,11,.35);border-radius:14px;padding:20px 22px;margin-bottom:20px}
    .dup-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:14px}
    .dup-icon{width:48px;height:48px;border-radius:50%;background:rgba(245,158,11,.18);color:var(--warning);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
    .dup-title{font-family:'Poppins',sans-serif;font-size:16px;font-weight:700;color:#92400e;margin-bottom:4px}
    [data-theme="dark"] .dup-title{color:#fbbf24}
    .dup-sub{font-size:13.5px;color:#92400e;line-height:1.6}
    [data-theme="dark"] .dup-sub{color:#fcd34d}
    .dup-ref{display:inline-block;margin-top:12px;padding:10px 18px;background:var(--card);border:1px dashed rgba(245,158,11,.55);border-radius:10px;font-family:'Poppins',sans-serif;font-size:16px;font-weight:800;letter-spacing:1.2px;color:var(--warning)}
    .dup-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
    .dup-actions a{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:10px;text-decoration:none;font-weight:700;font-size:13.5px;transition:var(--t)}
    .dup-actions a.primary{background:var(--warning);color:#fff;box-shadow:0 6px 18px rgba(245,158,11,.32)}
    .dup-actions a.primary:hover{background:#d97706}
    .dup-actions a.soft{background:var(--card);color:var(--soft);border:1.5px solid var(--border)}
    .dup-actions a.soft:hover{border-color:var(--primary);color:var(--primary)}

    footer.foot{text-align:center;margin-top:22px;font-size:12.5px;color:var(--muted)}
    footer.foot a{color:var(--primary);text-decoration:none;font-weight:600}
</style>
</head>
<body>

<button class="theme-toggle" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>

<div class="wrap">

    <div class="topnav">
        <a href="online-school-application.php?school=<?= urlencode($school['school_code']); ?>">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <a href="index.php"><i class="fas fa-house"></i> Home</a>
    </div>

    <div class="brand">
        <img src="logo.png" alt="Educ Bridge">
        <div class="brand-text">Educ<span>Bridge</span></div>
    </div>

    <div class="school-head">
        <div class="ico"><i class="fas fa-school"></i></div>
        <div>
            <h1><?= esc($school['name']); ?></h1>
            <p>
                <?php if (!empty($school['district'])): ?><?= esc($school['district']); ?>, <?php endif; ?>
                <?php if (!empty($school['province'])): ?><?= esc($school['province']); ?>, <?php endif; ?>
                <?= esc($school['country']); ?>
                &middot; <?= esc($school['type']); ?>
            </p>
        </div>
    </div>

    <div class="status green">
        <span><i class="fas fa-door-open"></i> Applications are <strong>open</strong>.</span>
        <?php if (!empty($cfg['academic_year'])): ?>
            <span style="font-weight:700;">Intake <?= esc($cfg['academic_year']); ?></span>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Student Application Form</h2>
            <p>Fill in the details below. Fields marked <span style="color:var(--danger);">*</span> are required.</p>
        </div>
        <div class="card-body">

            <?php if (!empty($errors['__db'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-circle-exclamation" style="margin-top:2px;"></i>
                    <span><?= esc($errors['__db']); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($duplicate_of): ?>
                <div class="dup-card">
                    <div class="dup-head">
                        <div class="dup-icon"><i class="fas fa-circle-info"></i></div>
                        <div>
                            <div class="dup-title">You have already applied</div>
                            <div class="dup-sub">
                                We found an existing application for
                                <strong><?= esc($form['student_name']); ?></strong>
                                from <strong><?= esc($form['recent_school'] ?: 'the same recent school'); ?></strong>
                                with the same recent marks. Your reference number is below — save it to check your admission status.
                            </div>
                        </div>
                    </div>
                    <div class="dup-ref"><?= esc($duplicate_of['reference_no']); ?></div>
                    <div class="dup-actions">
                        <a class="primary"
                           href="online-school-application.php?school=<?= urlencode($school['school_code']); ?>&ref=<?= urlencode($duplicate_of['reference_no']); ?>">
                            <i class="fas fa-magnifying-glass"></i> Check admission
                        </a>
                        <a class="soft"
                           href="online-school-application.php?school=<?= urlencode($school['school_code']); ?>">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    </div>
                </div>

            <?php else: ?>

                <form method="POST" id="applyForm" autocomplete="off" novalidate>

                    <div class="form-group">
                        <label for="student_name">Student Full Name <span class="req">*</span></label>
                        <input type="text" id="student_name" name="student_name"
                               value="<?= esc($form['student_name']); ?>"
                               placeholder="e.g. Aline Uwase"
                               class="<?= isset($errors['student_name']) ? 'err' : ''; ?>">
                        <?php if (isset($errors['student_name'])): ?>
                            <div class="err-msg"><i class="fas fa-circle-exclamation"></i> <?= esc($errors['student_name']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="row">
                        <div class="form-group">
                            <label for="dob">Date of Birth <span class="req">*</span></label>
                            <input type="date" id="dob" name="dob"
                                   max="<?= date('Y-m-d'); ?>"
                                   value="<?= esc($form['dob']); ?>"
                                   class="<?= isset($errors['dob']) ? 'err' : ''; ?>">
                            <?php if (isset($errors['dob'])): ?>
                                <div class="err-msg"><i class="fas fa-circle-exclamation"></i> <?= esc($errors['dob']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="sex">Sex <span class="req">*</span></label>
                            <select id="sex" name="sex" class="<?= isset($errors['sex']) ? 'err' : ''; ?>">
                                <option value="">— Select —</option>
                                <option value="Male"   <?= strtolower($form['sex']) === 'male'   ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?= strtolower($form['sex']) === 'female' ? 'selected' : ''; ?>>Female</option>
                            </select>
                            <?php if (isset($errors['sex'])): ?>
                                <div class="err-msg"><i class="fas fa-circle-exclamation"></i> <?= esc($errors['sex']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="form-group">
                            <label for="level">Level Applying For <span class="req">*</span></label>
                            <select id="level" name="level" class="<?= isset($errors['level']) ? 'err' : ''; ?>">
                                <option value="">— Select level —</option>
                                <?php foreach ($ordered_levels as $enum => $lbl): ?>
                                    <option value="<?= esc($enum); ?>" <?= $form['level'] === $enum ? 'selected' : ''; ?>>
                                        <?= esc($lbl); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (isset($errors['level'])): ?>
                                <div class="err-msg"><i class="fas fa-circle-exclamation"></i> <?= esc($errors['level']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label for="class_id">Class Applying For <span class="req">*</span></label>
                            <select id="class_id" name="class_id" class="<?= isset($errors['class_id']) ? 'err' : ''; ?>" disabled>
                                <option value="">— Select level first —</option>
                            </select>
                            <?php if (isset($errors['class_id'])): ?>
                                <div class="err-msg"><i class="fas fa-circle-exclamation"></i> <?= esc($errors['class_id']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="response_email">Response Email <span class="req">*</span></label>
                        <input type="email" id="response_email" name="response_email"
                               value="<?= esc($form['response_email']); ?>"
                               placeholder="parent@example.com"
                               class="<?= isset($errors['response_email']) ? 'err' : ''; ?>">
                        <div class="hint">
                            <i class="fas fa-circle-info" style="color:var(--primary);"></i>
                            This is the email the school will use to send the admission decision
                            (approve or reject). <strong>Many students may share the same parent email.</strong>
                        </div>
                        <?php if (isset($errors['response_email'])): ?>
                            <div class="err-msg"><i class="fas fa-circle-exclamation"></i> <?= esc($errors['response_email']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="phone">Phone Number <span class="req">*</span></label>
                        <input type="tel" id="phone" name="phone"
                               value="<?= esc($form['phone']); ?>"
                               placeholder="e.g. 0788 123 456"
                               class="<?= isset($errors['phone']) ? 'err' : ''; ?>">
                        <div class="hint">
                            <i class="fas fa-circle-info" style="color:var(--primary);"></i>
                            The school may call this number about your application.
                        </div>
                        <?php if (isset($errors['phone'])): ?>
                            <div class="err-msg"><i class="fas fa-circle-exclamation"></i> <?= esc($errors['phone']); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="row">
                        <div class="form-group">
                            <label for="recent_school">Recent School</label>
                            <input type="text" id="recent_school" name="recent_school"
                                   value="<?= esc($form['recent_school']); ?>"
                                   placeholder="Previous school name">
                            <div class="hint">Used to prevent duplicate applications.</div>
                        </div>
                        <div class="form-group">
                            <label for="recent_marks">Recent Marks / Grade</label>
                            <input type="text" id="recent_marks" name="recent_marks"
                                   value="<?= esc($form['recent_marks']); ?>"
                                   placeholder="e.g. 78% or Division II">
                            <div class="hint">Also used to prevent duplicates.</div>
                        </div>
                    </div>

                    <button type="submit" class="btn" id="submitBtn">
                        <i class="fas fa-paper-plane"></i> Submit Application
                    </button>
                </form>

            <?php endif; ?>
        </div>
    </div>

    <footer class="foot">
        Powered by <a href="#">Educ Bridge</a> &middot; <a href="mailto:info.dngroup1@gmail.com">Need help?</a>
    </footer>
</div>

<script>
    (function(){
        const html = document.documentElement;
        const t = document.getElementById('themeToggle');
        const i = t.querySelector('i');
        function apply(theme){
            html.setAttribute('data-theme', theme);
            localStorage.setItem('educbridge-theme', theme);
            if (theme === 'dark'){ i.classList.remove('fa-moon'); i.classList.add('fa-sun'); }
            else { i.classList.remove('fa-sun'); i.classList.add('fa-moon'); }
        }
        apply(localStorage.getItem('educbridge-theme') || 'light');
        t.addEventListener('click', () => apply(html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark'));
    })();

    const CLASSES_BY_LEVEL = <?= json_encode($classes_by_level); ?>;
    const PRE_CLASS = <?= (int)($form['class_id'] ?: 0); ?>;
    const levelSel = document.getElementById('level');
    const classSel = document.getElementById('class_id');
    function fillClasses() {
        if (!levelSel || !classSel) return;
        const lvl = levelSel.value;
        const items = CLASSES_BY_LEVEL[lvl] || [];
        classSel.innerHTML = '';
        if (!lvl) {
            classSel.disabled = true;
            classSel.innerHTML = '<option value="">— Select level first —</option>';
            return;
        }
        if (items.length === 0) {
            classSel.disabled = true;
            classSel.innerHTML = '<option value="">No classes available at this level</option>';
            return;
        }
        classSel.disabled = false;
        classSel.innerHTML = '<option value="">— Select class —</option>';
        items.forEach(function(c){
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name;
            if (PRE_CLASS && PRE_CLASS === c.id) opt.selected = true;
            classSel.appendChild(opt);
        });
    }
    if (levelSel) { levelSel.addEventListener('change', fillClasses); fillClasses(); }

    const form = document.getElementById('applyForm');
    if (form) {
        form.addEventListener('submit', function(){
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        });
    }
</script>
</body>
</html>