<?php
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| PUBLIC PAGE
| URL: online-school-application.php?school=SCHOOL_CODE
|--------------------------------------------------------------------------
*/

/* ---------------- helpers ---------------- */
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

$LEVEL_LABELS = [
    'Nursery' => 'Nursery',
    'Primary' => 'Primary',
    'O_Level' => "O' Level",
    'A_Level' => "A' Level",
];

/* ---------------- 1. resolve school ---------------- */
$school_code  = strtoupper(trim((string)($_GET['school'] ?? '')));
$school       = null;
$code_message = "";
$code_type    = "error";

if ($school_code !== '') {
    $s = mysqli_prepare($conn, "
        SELECT id, school_code, name, admin_name, country, province, district, type, status
        FROM schools
        WHERE school_code = ?
        LIMIT 1
    ");
    if ($s) {
        mysqli_stmt_bind_param($s, "s", $school_code);
        mysqli_stmt_execute($s);
        $r = mysqli_stmt_get_result($s);
        if ($row = mysqli_fetch_assoc($r)) {
            if (strtolower((string)$row['status']) !== 'active') {
                $code_message = "This school is currently unavailable for online services.";
                $code_type    = "error";
            } else {
                $school = $row;
            }
        } else {
            $code_message = "We couldn't find a school with that code. Please check with the school.";
            $code_type    = "error";
        }
        mysqli_stmt_close($s);
    } else {
        $code_message = "Database error: " . mysqli_error($conn);
        $code_type    = "error";
    }
}

/* ---------------- 2. intake status ---------------- */
$cfg           = null;
$intake_open   = false;
$intake_status = 'closed';
$intake_reason = '';
$open_levels   = [];

if ($school) {
    $st = mysqli_prepare($conn, "SELECT * FROM application_settings WHERE school_id = ? LIMIT 1");
    mysqli_stmt_bind_param($st, "i", $school['id']);
    mysqli_stmt_execute($st);
    $sr = mysqli_stmt_get_result($st);
    if ($row = mysqli_fetch_assoc($sr)) $cfg = $row;
    mysqli_stmt_close($st);

    if ($cfg) {
        $mode = $cfg['mode'] ?? 'closed';
        $now  = time();

        if ($mode === 'open') {
            $intake_open   = true;
            $intake_status = 'open';
        } elseif ($mode === 'scheduled') {
            $o = !empty($cfg['opens_at'])  ? strtotime($cfg['opens_at'])  : null;
            $c = !empty($cfg['closes_at']) ? strtotime($cfg['closes_at']) : null;
            if ($o && $c) {
                if ($now < $o) {
                    $intake_status = 'future';
                    $intake_reason = "Applications open on " . date('M j, Y · H:i', $o) . ".";
                } elseif ($now > $c) {
                    $intake_status = 'past';
                    $intake_reason = "Applications closed on " . date('M j, Y · H:i', $c) . ".";
                } else {
                    $intake_open   = true;
                    $intake_status = 'open';
                }
            } else {
                $intake_reason = "The application window is not fully scheduled yet.";
            }
        } else {
            $intake_reason = "This school is not accepting applications at the moment.";
        }

        $open_levels = array_values(array_filter(array_map('trim', explode(',', (string)($cfg['levels'] ?? '')))));
    } else {
        $intake_reason = "This school hasn't opened an intake yet.";
    }
}

/* ---------------- 3. reference lookup ---------------- */
$lookup_ref = strtoupper(trim((string)($_GET['ref'] ?? $_POST['ref'] ?? '')));
$lookup_hit = null;
$lookup_err = '';

if ($school && $lookup_ref !== '') {
    if (!preg_match('/^[A-Z0-9\-]{4,40}$/', $lookup_ref)) {
        $lookup_err = "That reference number format looks invalid.";
    } else {
        $q = mysqli_prepare($conn, "
            SELECT id, reference_no, student_name, dob, sex, level, class_id,
                   recent_school, recent_marks, response_email, status,
                   decided_at, decision_note, created_at
            FROM applications
            WHERE school_id = ? AND reference_no = ?
            LIMIT 1
        ");
        if ($q) {
            mysqli_stmt_bind_param($q, "is", $school['id'], $lookup_ref);
            mysqli_stmt_execute($q);
            $qr = mysqli_stmt_get_result($q);
            if ($row = mysqli_fetch_assoc($qr)) $lookup_hit = $row;
            mysqli_stmt_close($q);
        }
        if (!$lookup_hit) $lookup_err = "No application found with that reference number at this school.";
    }
}

/* Class name for a hit */
$lookup_class_name = '';
if ($lookup_hit && !empty($lookup_hit['class_id'])) {
    $cn = mysqli_prepare($conn, "SELECT class_name FROM classes WHERE id = ? AND school_id = ? LIMIT 1");
    $cid = (int)$lookup_hit['class_id'];
    $sid = (int)$school['id'];
    mysqli_stmt_bind_param($cn, "ii", $cid, $sid);
    mysqli_stmt_execute($cn);
    $cr = mysqli_stmt_get_result($cn);
    if ($crow = mysqli_fetch_assoc($cr)) $lookup_class_name = $crow['class_name'];
    mysqli_stmt_close($cn);
}

function status_chip($status) {
    $s = strtolower((string)$status);
    switch ($s) {
        case 'approved': return ['Approved','green','fa-circle-check'];
        case 'rejected': return ['Rejected','red','fa-circle-xmark'];
        case 'pending':  return ['Pending Review','orange','fa-hourglass-half'];
        case 'review':   return ['In Review','orange','fa-magnifying-glass'];
        default:         return [ucfirst($s ?: 'Unknown'),'gray','fa-circle-info'];
    }
}
function intake_chip($status) {
    switch ($status) {
        case 'open':   return ['Applications Open','green'];
        case 'future': return ['Opens Soon','orange'];
        case 'past':   return ['Closed','red'];
        default:       return ['Closed','red'];
    }
}
[$intake_label, $intake_color] = intake_chip($intake_status);

function initials_from_name($name) {
    $out = '';
    foreach (explode(' ', trim((string)$name)) as $p) {
        if ($p !== '') { $out .= strtoupper($p[0]); if (strlen($out) >= 2) break; }
    }
    return $out !== '' ? $out : 'S';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Apply or Check Admission | Educ Bridge</title>
<link rel="icon" type="image/png" href="logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
        --primary:#2563eb; --primary-dark:#1d4ed8; --primary-light:#dbeafe;
        --success:#10b981; --warning:#f59e0b; --danger:#ef4444;
        --bg-body:#f1f5f9; --bg-card:#ffffff; --bg-alt:#f8fafc;
        --text-primary:#0f172a; --text-secondary:#475569; --text-muted:#64748b;
        --border-color:#e2e8f0;
        --shadow-lg:0 20px 45px rgba(0,0,0,0.08);
        --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    }
    [data-theme="dark"] {
        --primary:#3b82f6; --primary-dark:#2563eb; --primary-light:#1e3a5f;
        --success:#34d399; --warning:#fbbf24; --danger:#f87171;
        --bg-body:#0b1120; --bg-card:#1e293b; --bg-alt:#1a2332;
        --text-primary:#f1f5f9; --text-secondary:#cbd5e1; --text-muted:#94a3b8;
        --border-color:#334155;
        --shadow-lg:0 20px 45px rgba(0,0,0,0.5);
    }
    *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
    html, body { min-height:100vh; }
    body {
        font-family:'Inter',sans-serif;
        background:var(--bg-body); color:var(--text-primary);
        display:flex; align-items:center; justify-content:center;
        padding:24px 16px; position:relative;
    }
    body::before {
        content:''; position:fixed; inset:0;
        background: radial-gradient(circle at 10% 10%, rgba(37,99,235,0.10), transparent 45%),
                    radial-gradient(circle at 90% 90%, rgba(124,58,237,0.10), transparent 45%);
        pointer-events:none; z-index:0;
    }
    .theme-toggle {
        position:fixed; top:20px; right:20px;
        width:42px; height:42px; border-radius:50%;
        border:1.5px solid var(--border-color); background:var(--bg-card);
        color:var(--text-secondary); display:flex; align-items:center; justify-content:center;
        cursor:pointer; font-size:15px; z-index:20;
    }
    .theme-toggle:hover { color:var(--primary); border-color:var(--primary); }

    .wrap { width:100%; max-width:760px; position:relative; z-index:1; }

    .topnav {
        display:flex; align-items:center; justify-content:space-between;
        gap:12px; margin-bottom:16px; flex-wrap:wrap;
    }
    .topnav a {
        display:inline-flex; align-items:center; gap:8px;
        padding:9px 14px; border-radius:10px;
        background:var(--bg-card); border:1.5px solid var(--border-color);
        color:var(--text-secondary); text-decoration:none;
        font-size:13px; font-weight:600;
        transition: var(--transition);
    }
    .topnav a:hover { border-color:var(--primary); color:var(--primary); }

    .brand { display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:22px; }
    .brand img { width:48px; height:48px; border-radius:50%; background:#fff; padding:3px; border:2px solid var(--border-color); }
    .brand-text { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; letter-spacing:-0.4px; }
    .brand-text span { color: var(--primary); }

    .card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-lg); overflow:hidden; }
    .card-head { padding:30px 30px 22px; border-bottom:1px solid var(--border-color); background:linear-gradient(135deg, rgba(37,99,235,0.07), rgba(124,58,237,0.05)); text-align:center; }
    .card-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; margin-bottom:8px; }
    .card-head p { font-size:14px; color:var(--text-muted); line-height:1.55; }
    .card-body { padding:28px 30px 30px; }

    .school-identity {
        display:flex; align-items:center; gap:16px;
        padding:18px 20px;
        background:var(--bg-alt); border:1px solid var(--border-color);
        border-radius:14px; margin-bottom:20px;
    }
    .school-identity .si-avatar {
        width:60px; height:60px; border-radius:16px;
        background:linear-gradient(135deg, var(--primary), #7c3aed);
        color:#fff; display:flex; align-items:center; justify-content:center;
        font-family:'Poppins',sans-serif; font-size:24px; font-weight:700;
        flex-shrink:0;
    }
    .school-identity .si-body { flex:1; min-width:0; }
    .school-identity .si-name { font-family:'Poppins',sans-serif; font-size:17px; font-weight:700; line-height:1.3; margin-bottom:4px; }
    .school-identity .si-meta { font-size:12.5px; color:var(--text-muted); display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .school-identity .si-meta .tag { display:inline-flex; align-items:center; gap:5px; background:var(--bg-card); border:1px solid var(--border-color); padding:3px 9px; border-radius:50px; font-weight:600; }
    .school-identity .si-meta .tag i { font-size:11px; }

    .status-pill {
        display:inline-flex; align-items:center; gap:8px;
        padding:6px 14px; border-radius:50px;
        font-size:12.5px; font-weight:700;
        margin-top:12px;
    }
    .status-pill .dot { width:8px; height:8px; border-radius:50%; }
    .status-pill.green { background:rgba(16,185,129,0.14); color:#059669; }
    .status-pill.green .dot { background:var(--success); }
    [data-theme="dark"] .status-pill.green { color:#34d399; }
    .status-pill.red { background:rgba(239,68,68,0.12); color:#dc2626; }
    .status-pill.red .dot { background:var(--danger); }
    [data-theme="dark"] .status-pill.red { color:#f87171; }
    .status-pill.orange { background:rgba(245,158,11,0.14); color:#b45309; }
    .status-pill.orange .dot { background:var(--warning); }
    [data-theme="dark"] .status-pill.orange { color:#fbbf24; }
    .status-pill.gray { background:rgba(148,163,184,0.15); color:#475569; }
    .status-pill.gray .dot { background:#94a3b8; }

    .alert { padding:13px 16px; border-radius:10px; margin-bottom:18px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.55; }
    .alert-error { background:rgba(239,68,68,0.08); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
    .alert-info  { background:rgba(37,99,235,0.08); color:#1d4ed8; border:1px solid rgba(37,99,235,0.25); }
    .alert-warn  { background:rgba(245,158,11,0.10); color:#b45309; border:1px solid rgba(245,158,11,0.28); }
    [data-theme="dark"] .alert-error { color:#f87171; }
    [data-theme="dark"] .alert-info  { color:#93c5fd; }
    [data-theme="dark"] .alert-warn  { color:#fbbf24; }

    .path-card {
        background:var(--bg-alt);
        border:1.5px solid var(--border-color);
        border-radius:16px;
        padding:22px 22px 20px;
        margin-bottom:18px;
        transition: var(--transition);
    }
    .path-card:last-of-type { margin-bottom:0; }
    .path-card .pc-head { display:flex; align-items:center; gap:12px; margin-bottom:14px; }
    .path-card .pc-icon {
        width:44px; height:44px; border-radius:12px;
        display:flex; align-items:center; justify-content:center;
        font-size:18px; color:#fff; flex-shrink:0;
    }
    .path-card .pc-icon.blue  { background: linear-gradient(135deg,#2563eb,#3b82f6); }
    .path-card .pc-icon.green { background: linear-gradient(135deg,#10b981,#14b8a6); }
    .path-card .pc-title { font-family:'Poppins',sans-serif; font-size:15.5px; font-weight:700; }
    .path-card .pc-sub   { font-size:12.5px; color:var(--text-muted); margin-top:2px; }
    .path-card .pc-body  { font-size:13.5px; color:var(--text-secondary); line-height:1.6; margin-bottom:14px; }

    .btn {
        display:inline-flex; align-items:center; justify-content:center; gap:9px;
        padding:13px 22px; border-radius:10px;
        font-size:14.5px; font-weight:700; cursor:pointer;
        border:none; background:var(--primary); color:#fff;
        transition:var(--transition); font-family:inherit;
        box-shadow:0 6px 18px rgba(37,99,235,0.32);
        text-decoration:none;
    }
    .btn:hover { background:var(--primary-dark); transform:translateY(-1px); box-shadow:0 8px 22px rgba(37,99,235,0.4); }
    .btn:disabled { opacity:0.55; cursor:not-allowed; transform:none; box-shadow:none; }
    .btn.block { width:100%; }
    .btn.soft  { background: transparent; color: var(--primary); border:1.5px solid var(--primary); box-shadow:none; }
    .btn.soft:hover { background: var(--primary); color:#fff; }

    .lookup-row { display:flex; gap:8px; }
    .lookup-row input {
        flex:1; padding:13px 14px;
        border:1.5px solid var(--border-color); border-radius:10px;
        background:var(--bg-card); color:var(--text-primary);
        font-family:inherit; font-size:15px;
        text-transform:uppercase; letter-spacing:1.1px; font-weight:700;
        outline:none; transition: var(--transition);
    }
    .lookup-row input:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,0.1); }
    .lookup-row .btn { padding:13px 22px; }
    .lookup-hint { font-size:12px; color:var(--text-muted); margin-top:10px; }

    /* admission result card */
    .admission-result {
        margin-top:16px;
        padding:20px;
        border-radius:14px;
        border:1.5px solid var(--border-color);
        background:var(--bg-card);
    }
    .admission-result .ar-head { display:flex; align-items:flex-start; gap:14px; margin-bottom:14px; }
    .admission-result .ar-icon {
        width:52px; height:52px; border-radius:50%;
        display:flex; align-items:center; justify-content:center;
        font-size:22px; flex-shrink:0;
    }
    .admission-result .ar-icon.green  { background: rgba(16,185,129,0.14); color:#059669; }
    .admission-result .ar-icon.red    { background: rgba(239,68,68,0.12);  color:#dc2626; }
    .admission-result .ar-icon.orange { background: rgba(245,158,11,0.14); color:#b45309; }
    .admission-result .ar-icon.gray   { background: rgba(148,163,184,0.18); color:#475569; }
    [data-theme="dark"] .admission-result .ar-icon.green  { color:#34d399; }
    [data-theme="dark"] .admission-result .ar-icon.red    { color:#f87171; }
    [data-theme="dark"] .admission-result .ar-icon.orange { color:#fbbf24; }
    .admission-result .ar-text { flex:1; min-width:0; }
    .admission-result .ar-title { font-family:'Poppins',sans-serif; font-size:16px; font-weight:700; margin-bottom:4px; }
    .admission-result .ar-sub   { font-size:13px; color:var(--text-muted); line-height:1.5; }

    .kv-grid { display:grid; grid-template-columns: 140px 1fr; gap:6px 14px; font-size:13.5px; margin-top:6px; }
    .kv-grid .k { color:var(--text-muted); font-weight:600; }
    .kv-grid .v { color:var(--text-primary); font-weight:600; }

    .decision-note {
        margin-top:14px; padding:12px 14px;
        border-radius:10px; background:var(--bg-alt);
        border-left:3px solid var(--primary);
        font-size:13px; color:var(--text-secondary); line-height:1.6;
    }
    .decision-note strong { color:var(--text-primary); }

    /* code entry */
    .code-form { display:flex; flex-direction:column; gap:10px; }
    .code-form label { font-size:12.5px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; }
    .code-row { display:flex; gap:8px; }
    .code-row input {
        flex:1; padding:14px 16px;
        border:1.5px solid var(--border-color); border-radius:10px;
        background:var(--bg-alt); color:var(--text-primary);
        font-family:inherit; font-size:16px;
        text-transform:uppercase; letter-spacing:1.2px; font-weight:700;
        text-align:center; outline:none; transition: var(--transition);
    }
    .code-row input:focus { border-color:var(--primary); background: var(--bg-card); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
    .code-row .btn { padding:14px 24px; }

    .foot { text-align:center; margin-top:20px; font-size:13px; color:var(--text-muted); }
    .foot a { color:var(--primary); font-weight:600; text-decoration:none; }
</style>
</head>
<body>

<button class="theme-toggle" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>

<div class="wrap">

    <div class="topnav">
        <a href="online-registration.php"><i class="fas fa-arrow-left"></i> Change school</a>
        <a href="index.php"><i class="fas fa-house"></i> Home</a>
    </div>

    <div class="brand">
        <img src="logo.png" alt="Educ Bridge">
        <div class="brand-text">Educ<span>Bridge</span></div>
    </div>

    <div class="card">
        <div class="card-head">
            <h1>Apply or Check Admission</h1>
            <p>
                <?php if ($school): ?>
                    Start a new application for this school or check the decision on one you already submitted.
                <?php else: ?>
                    Enter your school code to get started. Your school provides the code.
                <?php endif; ?>
            </p>
            <?php if ($school): ?>
                <div class="status-pill <?= esc($intake_color); ?>">
                    <span class="dot"></span> <?= esc($intake_label); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-body">

            <?php if ($code_message !== ""): ?>
                <div class="alert alert-<?= esc($code_type); ?>">
                    <i class="fas fa-circle-exclamation" style="margin-top:2px;"></i>
                    <span><?= esc($code_message); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$school): ?>

                <!-- ============ CODE ENTRY ============ -->
                <form class="code-form" method="GET" action="online-school-application.php" autocomplete="off">
                    <label for="schoolCode">School Code</label>
                    <div class="code-row">
                        <input type="text" id="schoolCode" name="school"
                               value="<?= esc($school_code); ?>"
                               placeholder="e.g. 5092022026"
                               maxlength="40" required autofocus>
                        <button type="submit" class="btn">
                            <i class="fas fa-arrow-right"></i> Verify
                        </button>
                    </div>
                    <div class="lookup-hint">
                        <i class="fas fa-circle-info"></i>
                        The code is case-insensitive. Ask your school if you don't have it.
                    </div>
                </form>

            <?php else: ?>

                <!-- ============ SCHOOL IDENTITY ============ -->
                <div class="school-identity">
                    <div class="si-avatar"><?= esc(initials_from_name($school['name'])); ?></div>
                    <div class="si-body">
                        <div class="si-name"><?= esc($school['name']); ?></div>
                        <div class="si-meta">
                            <?php if (!empty($school['admin_name'])): ?>
                                <span class="tag"><i class="fas fa-user-tie"></i> <?= esc($school['admin_name']); ?></span>
                            <?php endif; ?>
                            <span class="tag"><i class="fas fa-hashtag"></i> <?= esc($school['school_code']); ?></span>
                        </div>
                    </div>
                </div>

                <?php if (!empty($cfg['note'])): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-circle-info" style="margin-top:2px;"></i>
                        <span><?= nl2br(esc($cfg['note'])); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($open_levels)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-graduation-cap" style="margin-top:2px;"></i>
                        <span>
                            <strong>Levels open for applications:</strong>
                            <?= esc(implode(', ', array_map(fn($l) => $LEVEL_LABELS[$l] ?? $l, $open_levels))); ?>
                            <?php if (!empty($cfg['academic_year'])): ?>
                                &middot; Intake <strong><?= esc($cfg['academic_year']); ?></strong>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endif; ?>

                <!-- ============ PATH 1: CHECK ADMISSION ============ -->
                <div class="path-card">
                    <div class="pc-head">
                        <div class="pc-icon blue"><i class="fas fa-magnifying-glass"></i></div>
                        <div>
                            <div class="pc-title">Check admission status</div>
                            <div class="pc-sub">Use the reference number you received after applying.</div>
                        </div>
                    </div>

                    <form method="GET" action="online-school-application.php" class="lookup-row" autocomplete="off">
                        <input type="hidden" name="school" value="<?= esc($school['school_code']); ?>">
                        <input type="text" name="ref" id="refInput"
                               value="<?= esc($lookup_ref); ?>"
                               placeholder="EB-2025-XXXXXX"
                               maxlength="40" required>
                        <button type="submit" class="btn"><i class="fas fa-arrow-right"></i> Check</button>
                    </form>
                    <div class="lookup-hint">
                        <i class="fas fa-circle-info"></i>
                        Reference numbers look like <strong>EB-2025-A1B2C3</strong>.
                    </div>

                    <?php if ($lookup_err !== ''): ?>
                        <div class="alert alert-error" style="margin-top:14px;margin-bottom:0;">
                            <i class="fas fa-circle-exclamation" style="margin-top:2px;"></i>
                            <span><?= esc($lookup_err); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($lookup_hit):
                        [$chip_lbl, $chip_col, $chip_ico] = status_chip($lookup_hit['status'] ?? 'pending');
                    ?>
                        <div class="admission-result">
                            <div class="ar-head">
                                <div class="ar-icon <?= esc($chip_col); ?>">
                                    <i class="fas <?= esc($chip_ico); ?>"></i>
                                </div>
                                <div class="ar-text">
                                    <div class="ar-title"><?= esc($chip_lbl); ?></div>
                                    <div class="ar-sub">
                                        Application <strong><?= esc($lookup_hit['reference_no']); ?></strong>
                                        submitted on
                                        <?= esc($lookup_hit['created_at'] ? date('d M Y, H:i', strtotime($lookup_hit['created_at'])) : '—'); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="kv-grid">
                                <div class="k">Student</div>
                                <div class="v"><?= esc($lookup_hit['student_name']); ?></div>

                                <div class="k">Level</div>
                                <div class="v">
                                    <?php
                                        $lv = normalize_level_enum($lookup_hit['level']);
                                        echo esc($LEVEL_LABELS[$lv] ?? $lookup_hit['level']);
                                    ?>
                                </div>

                                <div class="k">Class</div>
                                <div class="v"><?= esc($lookup_class_name ?: '—'); ?></div>

                                <?php if (!empty($lookup_hit['dob'])): ?>
                                    <div class="k">Date of birth</div>
                                    <div class="v"><?= esc(date('d M Y', strtotime($lookup_hit['dob']))); ?></div>
                                <?php endif; ?>

                                <?php if (!empty($lookup_hit['recent_school'])): ?>
                                    <div class="k">Recent school</div>
                                    <div class="v"><?= esc($lookup_hit['recent_school']); ?></div>
                                <?php endif; ?>

                                <?php if (!empty($lookup_hit['recent_marks'])): ?>
                                    <div class="k">Marks / grade</div>
                                    <div class="v"><?= esc($lookup_hit['recent_marks']); ?></div>
                                <?php endif; ?>

                                <?php if (!empty($lookup_hit['decided_at'])): ?>
                                    <div class="k">Decision at</div>
                                    <div class="v"><?= esc(date('d M Y, H:i', strtotime($lookup_hit['decided_at']))); ?></div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($lookup_hit['decision_note'])): ?>
                                <div class="decision-note">
                                    <strong>Note from the school:</strong><br>
                                    <?= nl2br(esc($lookup_hit['decision_note'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ============ PATH 2: START NEW APPLICATION ============ -->
                <div class="path-card">
                    <div class="pc-head">
                        <div class="pc-icon green"><i class="fas fa-user-plus"></i></div>
                        <div>
                            <div class="pc-title">Start a new application</div>
                            <div class="pc-sub">
                                <?= $intake_open ? 'Open now — you can apply.' : 'Currently closed.'; ?>
                            </div>
                        </div>
                    </div>

                    <div class="pc-body">
                        You'll fill in the student's details and choose a level + class that the school has
                        opened for this intake. You'll get a reference number at the end — save it, you'll
                        need it for the "Check admission" step above.
                    </div>

                    <?php if ($intake_open): ?>
                        <a href="apply.php?school=<?= urlencode($school['school_code']); ?>" class="btn block">
                            <i class="fas fa-paper-plane"></i> Start new application
                        </a>
                    <?php else: ?>
                        <button class="btn block" disabled>
                            <i class="fas fa-lock"></i> Applications closed
                        </button>
                        <div class="lookup-hint" style="margin-top:10px;">
                            <?= esc($intake_reason ?: 'This school is not accepting applications right now.'); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ============ ACTION BAR ============ -->
                <div class="topnav" style="margin-top:22px;margin-bottom:0;">
                    <a href="online-registration.php" style="flex:1 1 200px;justify-content:center;">
                        <i class="fas fa-rotate-left"></i> Use a different code
                    </a>
                    <a href="student-login.php" style="flex:1 1 200px;justify-content:center;">
                        <i class="fas fa-sign-in-alt"></i> Student login
                    </a>
                </div>

            <?php endif; ?>

        </div>
    </div>

    <div class="foot">Powered by <a href="#">Educ Bridge</a></div>

</div>

<script>
    (function () {
        const html = document.documentElement;
        const t = document.getElementById('themeToggle');
        const i = t.querySelector('i');
        function apply(theme) {
            html.setAttribute('data-theme', theme);
            localStorage.setItem('educbridge-theme', theme);
            if (theme === 'dark') { i.classList.remove('fa-moon'); i.classList.add('fa-sun'); }
            else { i.classList.remove('fa-sun'); i.classList.add('fa-moon'); }
        }
        apply(localStorage.getItem('educbridge-theme') || 'light');
        t.addEventListener('click', function () {
            apply(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
        });
    })();
</script>

</body>
</html>