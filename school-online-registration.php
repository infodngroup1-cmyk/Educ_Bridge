<?php
require_once 'db.php';
require_once '_school-auth.php';

/*
|--------------------------------------------------------------------------
| AUTO-CREATE online_registration_config TABLE
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS online_registration_config (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    school_id         INT NOT NULL UNIQUE,
    academic_year     VARCHAR(20)  NOT NULL DEFAULT '',
    levels            VARCHAR(120) NOT NULL DEFAULT '',
    mode              ENUM('closed','open','scheduled') NOT NULL DEFAULT 'closed',
    opens_at          DATETIME DEFAULT NULL,
    closes_at         DATETIME DEFAULT NULL,
    note              TEXT     DEFAULT NULL,
    allow_index_reg   TINYINT(1) DEFAULT 0,
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_orc_school (school_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Fetch school contact info (read-only reference for this page) */
$school_email = '';
$school_phone = '';
$sstmt = mysqli_prepare($conn, "SELECT email, phone FROM schools WHERE id = ? LIMIT 1");
if ($sstmt) {
    mysqli_stmt_bind_param($sstmt, "i", $school_id);
    mysqli_stmt_execute($sstmt);
    $sres = mysqli_stmt_get_result($sstmt);
    if ($row = mysqli_fetch_assoc($sres)) {
        $school_email = $row['email'] ?? '';
        $school_phone = $row['phone'] ?? '';
    }
    mysqli_stmt_close($sstmt);
}

/* Ensure a config row exists */
$st = mysqli_prepare($conn, "SELECT * FROM online_registration_config WHERE school_id = ? LIMIT 1");
mysqli_stmt_bind_param($st, "i", $school_id);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
if (mysqli_num_rows($res) === 0) {
    mysqli_stmt_close($st);
    $ins = mysqli_prepare($conn, "
        INSERT INTO online_registration_config (school_id, academic_year, levels, mode)
        VALUES (?, ?, ?, 'closed')
    ");
    $default_year   = (date('Y')) . '-' . (date('Y') + 1);
    $default_levels = '';
    mysqli_stmt_bind_param($ins, "iss", $school_id, $default_year, $default_levels);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);
} else {
    mysqli_stmt_close($st);
}

/* Load existing config */
$cfg = [
    'academic_year'   => '',
    'levels'          => '',
    'mode'            => 'closed',
    'opens_at'        => null,
    'closes_at'       => null,
    'note'            => '',
    'allow_index_reg' => 0,
];
$st = mysqli_prepare($conn, "SELECT * FROM online_registration_config WHERE school_id = ? LIMIT 1");
mysqli_stmt_bind_param($st, "i", $school_id);
mysqli_stmt_execute($st);
$res = mysqli_stmt_get_result($st);
if ($row = mysqli_fetch_assoc($res)) $cfg = $row;
mysqli_stmt_close($st);

$selected_levels = array_filter(array_map('trim', explode(',', (string)$cfg['levels'])));

/* -------------------------------------------------------------------------
   HANDLE SUBMIT
   ------------------------------------------------------------------------- */
$message = ""; $message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $academic_year   = trim($_POST['academic_year'] ?? '');
    $levels_in       = isset($_POST['levels']) && is_array($_POST['levels']) ? $_POST['levels'] : [];
    $mode            = $_POST['mode'] ?? 'closed';
    $opens_at_raw    = trim($_POST['opens_at'] ?? '');
    $closes_at_raw   = trim($_POST['closes_at'] ?? '');
    $note            = trim($_POST['note'] ?? '');
    $allow_index_reg = isset($_POST['allow_index_reg']) ? 1 : 0;

    $errors = [];

    if ($academic_year === '' || !preg_match('/^\d{4}(\s*-\s*\d{4})?$/', $academic_year)) {
        $errors[] = "Academic year must be in format YYYY or YYYY-YYYY.";
    }

    $valid_levels = ['Nursery','Primary','O_Level','A_Level'];
    $levels_clean = array_values(array_intersect(array_map('trim', $levels_in), $valid_levels));
    if (empty($levels_clean)) {
        $errors[] = "Select at least one level (Nursery, Primary, O' Level, A' Level).";
    }

    if (!in_array($mode, ['closed','open','scheduled'], true)) {
        $errors[] = "Invalid registration mode.";
    }

    $opens_at = null; $closes_at = null;
    if ($mode === 'scheduled') {
        if ($opens_at_raw === '' || $closes_at_raw === '') {
            $errors[] = "For scheduled mode, both open and close dates are required.";
        } else {
            $opens_ts  = strtotime($opens_at_raw);
            $closes_ts = strtotime($closes_at_raw);
            if ($opens_ts === false) $errors[] = "Invalid 'opens at' date.";
            elseif ($closes_ts === false) $errors[] = "Invalid 'closes at' date.";
            elseif ($closes_ts <= $opens_ts) $errors[] = "'Closes at' must be later than 'opens at'.";
            else {
                $opens_at  = date('Y-m-d H:i:s', $opens_ts);
                $closes_at = date('Y-m-d H:i:s', $closes_ts);
            }
        }
    }

    if (!empty($errors)) {
        $message = implode(" ", $errors);
        $message_type = "error";

        /* Preserve input on error */
        $cfg['academic_year']   = $academic_year;
        $cfg['levels']          = implode(',', $levels_clean);
        $cfg['mode']            = $mode;
        $cfg['opens_at']        = $opens_at_raw;
        $cfg['closes_at']       = $closes_at_raw;
        $cfg['note']            = $note;
        $cfg['allow_index_reg'] = $allow_index_reg;
        $selected_levels        = $levels_clean;

    } else {
        $levels_str = implode(',', $levels_clean);

        $upd = mysqli_prepare($conn, "
            UPDATE online_registration_config
            SET academic_year = ?, levels = ?, mode = ?, opens_at = ?, closes_at = ?, note = ?, allow_index_reg = ?
            WHERE school_id = ?
        ");
        mysqli_stmt_bind_param(
            $upd, "ssssssii",
            $academic_year, $levels_str, $mode, $opens_at, $closes_at, $note, $allow_index_reg, $school_id
        );

        if (mysqli_stmt_execute($upd)) {
            mysqli_stmt_close($upd);
            $message = "Online registration settings saved successfully.";
            $message_type = "success";

            /* Reload */
            $st = mysqli_prepare($conn, "SELECT * FROM online_registration_config WHERE school_id = ? LIMIT 1");
            mysqli_stmt_bind_param($st, "i", $school_id);
            mysqli_stmt_execute($st);
            $res = mysqli_stmt_get_result($st);
            if ($row = mysqli_fetch_assoc($res)) $cfg = $row;
            mysqli_stmt_close($st);
            $selected_levels = array_filter(array_map('trim', explode(',', (string)$cfg['levels'])));
        } else {
            $message = "Could not save settings: " . mysqli_error($conn);
            $message_type = "error";
            mysqli_stmt_close($upd);
        }
    }
}

/* Current effective status (open / closed / scheduled) */
$now = time();
$effective = $cfg['mode'];
if ($cfg['mode'] === 'scheduled') {
    $o = $cfg['opens_at']  ? strtotime($cfg['opens_at'])  : null;
    $c = $cfg['closes_at'] ? strtotime($cfg['closes_at']) : null;
    if ($o && $c) {
        if ($now < $o) $effective = 'scheduled_future';
        elseif ($now > $c) $effective = 'scheduled_past';
        else $effective = 'scheduled_open';
    }
}

$status_label = [
    'closed'           => ['Closed',           'red'],
    'open'             => ['Open',             'green'],
    'scheduled'        => ['Scheduled',        'orange'],
    'scheduled_future' => ['Scheduled (future)','orange'],
    'scheduled_past'   => ['Closed (past)',    'red'],
    'scheduled_open'   => ['Open (auto)',      'green'],
][$effective] ?? ['Unknown', 'gray'];

$level_map = ['Nursery'=>'Nursery','Primary'=>'Primary','O_Level'=>"O' Level",'A_Level'=>"A' Level"];
$year_options = [];
$y = (int) date('Y');
for ($i = -1; $i <= 2; $i++) {
    $yy = $y + $i;
    $year_options[] = $yy . '-' . ($yy + 1);
}

/* Convert stored datetime strings to the input[type=datetime-local] format */
function dt_local($db_val) {
    if (empty($db_val)) return '';
    $ts = strtotime($db_val);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Registration | Educ Bridge</title>
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
            --bg-body: #f8fafc; --bg-card: #ffffff; --bg-alt: #f1f5f9;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.07);
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
            --bg-body: #0b1120; --bg-card: #1e293b; --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
            --shadow-sidebar: 0 0 30px rgba(0,0,0,0.4);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { min-height: 100vh; }
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); transition:background 0.4s ease, color 0.4s ease; overflow-x:hidden; }
        .layout { display:flex; min-height:100vh; }

        .sidebar { width:260px; background:var(--sidebar-bg); color:var(--sidebar-text); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:100; transition:var(--transition); border-right:1px solid var(--sidebar-border); box-shadow:var(--shadow-sidebar); }
        .sidebar-header { padding:22px 22px 18px; border-bottom:1px solid var(--sidebar-border); display:flex; align-items:center; gap:12px; }
        .sidebar-header img { width:42px; height:42px; object-fit:contain; border-radius:50%; border:2px solid var(--sidebar-border); padding:2px; background:#fff; }
        .sidebar-brand { font-family:'Poppins',sans-serif; font-size:18px; font-weight:700; color:var(--text-primary); line-height:1.2; }
        .sidebar-brand span { color:var(--primary); }
        .sidebar-brand small { display:block; font-size:11px; font-weight:500; color:var(--text-muted); letter-spacing:0.4px; margin-top:2px; text-transform:uppercase; }
        .sidebar-nav { flex:1; padding:18px 12px; overflow-y:auto; }
        .nav-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--sidebar-title); padding:12px 14px 8px; }
        .nav-item { display:flex; align-items:center; gap:12px; padding:11px 14px; color:var(--sidebar-text); text-decoration:none; font-size:14px; font-weight:500; border-radius:10px; margin-bottom:4px; transition:var(--transition); position:relative; }
        .nav-item i.nav-icon { width:20px; text-align:center; font-size:15px; }
        .nav-item:hover { background:var(--sidebar-hover); color:var(--sidebar-text-active); }
        .nav-item:hover i.nav-icon { color:var(--primary); }
        .nav-item.active { background:var(--sidebar-active); color:var(--sidebar-text-active); }
        .nav-item.active i.nav-icon { color:var(--primary); }
        .nav-item.active::before { content:''; position:absolute; left:0; top:8px; bottom:8px; width:3px; background:var(--primary); border-radius:0 4px 4px 0; }
        .sidebar-profile { border-top:1px solid var(--sidebar-border); padding:16px 18px; display:flex; align-items:center; gap:12px; }
        .sidebar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:15px; font-weight:700; flex-shrink:0; }
        .sidebar-profile-info { flex:1; min-width:0; }
        .sidebar-profile-info .p-name { font-size:13.5px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-profile-info .p-role { font-size:11.5px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-logout { width:34px; height:34px; border-radius:8px; background:var(--danger-light); color:var(--danger); display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:13px; flex-shrink:0; transition:var(--transition); }
        .sidebar-logout:hover { background:var(--danger); color:#fff; }

        .main { flex:1; margin-left:260px; min-height:100vh; display:flex; flex-direction:column; transition:margin-left 0.3s ease; }
        .topbar { background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:16px; position:sticky; top:0; z-index:50; }
        .topbar-left { display:flex; align-items:center; gap:14px; }
        .hamburger { display:none; width:40px; height:40px; border-radius:10px; border:1.5px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); align-items:center; justify-content:center; cursor:pointer; font-size:15px; }
        .hamburger:hover { border-color:var(--primary); color:var(--primary); }
        .page-title { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; letter-spacing:-0.4px; }
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
        .alert-success { background: var(--success-light); color:#059669; border:1px solid rgba(16,185,129,0.25); }
        [data-theme="dark"] .alert-success { color:#34d399; }
        .alert-error { background: var(--danger-light); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        [data-theme="dark"] .alert-error { color:#f87171; }

        .page-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:22px; flex-wrap:wrap; }
        .page-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .page-head h1 i { color:var(--primary); }
        .page-head .sub { font-size:13.5px; color:var(--text-muted); margin-top:4px; }

        .card { max-width:900px; margin:0 auto; background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-lg); overflow:hidden; }
        .card-head { padding:26px 30px; border-bottom:1px solid var(--border-color); background:linear-gradient(135deg, rgba(37,99,235,0.06), rgba(124,58,237,0.04)); display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
        .card-head h2 { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; display:flex; align-items:center; gap:10px; margin-bottom:4px; }
        .card-head h2 i { color:var(--primary); }
        .card-head p { font-size:13.5px; color:var(--text-muted); }

        .status-pill {
            display:inline-flex; align-items:center; gap:8px;
            padding: 8px 16px; border-radius: 50px;
            font-size: 13px; font-weight: 700;
            background: var(--bg-alt);
        }
        .status-pill .dot { width: 10px; height: 10px; border-radius: 50%; background: var(--text-muted); }
        .status-pill.green { background: var(--success-light); color: #059669; }
        .status-pill.green .dot { background: var(--success); }
        [data-theme="dark"] .status-pill.green { color: #34d399; }
        .status-pill.red { background: var(--danger-light); color:#dc2626; }
        .status-pill.red .dot { background: var(--danger); }
        [data-theme="dark"] .status-pill.red { color:#f87171; }
        .status-pill.orange { background: var(--warning-light); color:#b45309; }
        .status-pill.orange .dot { background: var(--warning); }
        [data-theme="dark"] .status-pill.orange { color:#fbbf24; }

        .card-body { padding:28px 30px; }

        .section-title { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.7px; color:var(--text-muted); margin:4px 0 14px; padding-bottom:8px; border-bottom:1px dashed var(--border-color); display:flex; align-items:center; gap:8px; }
        .section-title i { color:var(--primary); font-size:12px; }

        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .form-group { margin-bottom:18px; }
        .form-group label { display:block; font-size:13.5px; font-weight:600; color:var(--text-primary); margin-bottom:8px; }
        .form-group label .required { color:#ef4444; }

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
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
            background: var(--bg-card);
        }
        .form-help { font-size:12.5px; color:var(--text-muted); margin-top:6px; line-height:1.5; }
        .form-group input[readonly] { background: var(--bg-body); color: var(--text-muted); cursor: not-allowed; }

        /* Level checkbox pills */
        .check-pills { display:flex; flex-wrap:wrap; gap:10px; }
        .check-pill {
            position:relative; display:inline-flex; align-items:center; gap:8px;
            padding: 11px 18px;
            border:1.5px solid var(--border-color);
            border-radius:10px;
            cursor:pointer;
            background: var(--bg-alt);
            font-size: 13.5px; font-weight:600;
            color: var(--text-primary);
            transition: var(--transition);
            user-select: none;
        }
        .check-pill input { position:absolute; opacity:0; pointer-events:none; }
        .check-pill .box {
            width:18px; height:18px;
            border-radius:6px;
            border:2px solid var(--border-color);
            background: var(--bg-card);
            display:flex; align-items:center; justify-content:center;
            transition: var(--transition);
            flex-shrink: 0;
        }
        .check-pill .box i { font-size:10px; color:#fff; opacity:0; transform: scale(0.4); transition: var(--transition); }
        .check-pill:hover { border-color: var(--primary); }
        .check-pill.selected { border-color: var(--primary); background: var(--primary-light); }
        .check-pill.selected .box { background: var(--primary); border-color: var(--primary); }
        .check-pill.selected .box i { opacity:1; transform: scale(1); }

        /* Mode radio pills */
        .radio-pills { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:12px; }
        .radio-pill {
            position:relative;
            display:flex; align-items:flex-start; gap:12px;
            padding:16px 18px;
            border:1.5px solid var(--border-color);
            border-radius:12px;
            cursor:pointer;
            background: var(--bg-alt);
            transition: var(--transition);
        }
        .radio-pill input { position:absolute; opacity:0; pointer-events:none; }
        .radio-pill .dot {
            width:18px; height:18px; border-radius:50%;
            border:2px solid var(--border-color);
            position:relative; transition: var(--transition);
            flex-shrink:0; margin-top:2px;
        }
        .radio-pill .dot::after {
            content:''; position:absolute; inset:3px;
            border-radius:50%; background: var(--primary);
            opacity:0; transform: scale(0.4); transition: var(--transition);
        }
        .radio-pill:hover { border-color: var(--primary); }
        .radio-pill.selected { border-color: var(--primary); background: var(--primary-light); }
        .radio-pill.selected .dot { border-color: var(--primary); }
        .radio-pill.selected .dot::after { opacity:1; transform: scale(1); }
        .radio-pill .rp-body .rp-title {
            font-size:14px; font-weight:700; color: var(--text-primary);
            display:flex; align-items:center; gap:8px; margin-bottom:3px;
        }
        .radio-pill .rp-body .rp-title i { font-size:14px; color: var(--text-muted); }
        .radio-pill.selected .rp-body .rp-title i { color: var(--primary); }
        .radio-pill .rp-body .rp-desc { font-size:12.5px; color: var(--text-muted); line-height: 1.5; }

        /* Toggle row */
        .switch-row {
            display:flex; align-items:center; gap:14px;
            padding: 14px 18px;
            background: var(--bg-alt);
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: var(--transition);
        }
        .switch-row:hover { border-color: var(--primary); }
        .switch-row.on { border-color: var(--primary); background: var(--primary-light); }
        .switch-row .sw {
            position: relative;
            width: 46px; height: 26px;
            border-radius: 50px;
            background: var(--border-color);
            transition: var(--transition);
            flex-shrink: 0;
        }
        .switch-row .sw::after {
            content:''; position:absolute; top:3px; left:3px;
            width: 20px; height:20px; border-radius:50%;
            background:#fff; box-shadow:0 2px 6px rgba(0,0,0,0.2);
            transition: var(--transition);
        }
        .switch-row.on .sw { background: var(--primary); }
        .switch-row.on .sw::after { transform: translateX(20px); }
        .switch-row .sr-text .t { font-size: 14px; font-weight: 700; color: var(--text-primary); }
        .switch-row .sr-text .d { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; }

        /* Preview block */
        .preview-box {
            background: var(--bg-alt);
            border: 1px dashed var(--border-color);
            border-radius: 12px;
            padding: 16px 18px;
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.65;
        }
        .preview-box strong { color: var(--text-primary); }
        .preview-box .prow { display:flex; gap:8px; margin-bottom:5px; }
        .preview-box .prow:last-child { margin-bottom:0; }
        .preview-box .prow .pl { min-width: 110px; color: var(--text-muted); font-weight: 600; }

        /* Actions */
        .form-actions { display:flex; gap:12px; justify-content:flex-end; padding-top:20px; border-top:1px solid var(--border-color); margin-top:12px; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:13px 24px; border-radius:10px; font-size:14.5px; font-weight:600; text-decoration:none; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); transition:var(--transition); font-family:'Inter',sans-serif; }
        .btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }
        .btn:disabled { opacity:0.7; cursor:not-allowed; transform:none; }

        /* Copy-link row */
        .copy-row { display:flex; gap:8px; align-items:center; margin-top:10px; }
        .copy-row input { flex:1; padding:11px 14px; font-size:13px; font-family:'Courier New',monospace;
            border:1.5px solid var(--border-color); border-radius:10px; background:var(--bg-alt); color:var(--text-primary); }
        .copy-row button { padding: 11px 16px; border-radius:10px; border:1.5px solid var(--primary);
            background: var(--primary); color:#fff; font-family:inherit; font-weight:700; cursor:pointer; font-size:13px; }
        .copy-row button:hover { background: var(--primary-dark); }

        .overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:90; backdrop-filter: blur(2px); }
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
            <a href="school-students.php" class="nav-item"><i class="fas fa-user-graduate nav-icon"></i> Students</a>
            <a href="school-teachers.php" class="nav-item"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>
            <a href="school-classes.php" class="nav-item"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
            <a href="school-subjects.php" class="nav-item"><i class="fas fa-book nav-icon"></i> Subjects</a>
            <div class="nav-section-title">Communication</div>
            <a href="school-announcements.php" class="nav-item"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>
            <a href="school-events.php" class="nav-item"><i class="fas fa-calendar-alt nav-icon"></i> Events</a>
            <div class="nav-section-title">System</div>
            <a href="school-settings.php" class="nav-item active"><i class="fas fa-cog nav-icon"></i> Settings</a>
        </nav>
        <div class="sidebar-profile">
            <div class="sidebar-avatar"><?= htmlspecialchars($initials); ?></div>
            <div class="sidebar-profile-info">
                <div class="p-name"><?= htmlspecialchars($school_admin); ?></div>
                <div class="p-role"><?= htmlspecialchars($short_school); ?></div>
            </div>
            <a href="school-logout.php" class="sidebar-logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="overlay" id="overlay"></div>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Online Registration</div>
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

            <a href="school-settings.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Settings</a>

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <div class="card">

                <div class="card-head">
                    <div>
                        <h2><i class="fas fa-globe"></i> Online Registration</h2>
                        <p>Configure how new students can register online for your school.</p>
                    </div>
                    <div class="status-pill <?= htmlspecialchars($status_label[1]); ?>">
                        <span class="dot"></span> <?= htmlspecialchars($status_label[0]); ?>
                    </div>
                </div>

                <div class="card-body">

                    <form method="POST" action="school-online-registration.php" autocomplete="off" id="orForm">

                        <!-- SCHOOL (read-only) -->
                        <div class="section-title"><i class="fas fa-school"></i> School (reference)</div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>School Name</label>
                                <input type="text" value="<?= htmlspecialchars($school_name); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>School Code</label>
                                <input type="text" value="<?= htmlspecialchars($school_code); ?>" readonly style="font-family:'Courier New',monospace;font-weight:700;">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>School Email</label>
                                <input type="text" value="<?= htmlspecialchars($school_email ?: '—'); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label>School Phone</label>
                                <input type="text" value="<?= htmlspecialchars($school_phone ?: '—'); ?>" readonly>
                            </div>
                        </div>

                        <div class="form-help" style="margin-bottom:22px;">
                            <i class="fas fa-info-circle"></i>
                            Only students who enter this school code will be able to register with your school.
                        </div>

                        <!-- ACADEMIC YEAR -->
                        <div class="section-title"><i class="fas fa-calendar-alt"></i> Academic Year</div>

                        <div class="form-group">
                            <label for="academic_year">Academic Year <span class="required">*</span></label>
                            <input type="text" id="academic_year" name="academic_year" maxlength="20"
                                   list="yearSuggest"
                                   value="<?= htmlspecialchars($cfg['academic_year']); ?>"
                                   placeholder="e.g. 2025-2026" required>
                            <datalist id="yearSuggest">
                                <?php foreach ($year_options as $yo): ?>
                                    <option value="<?= htmlspecialchars($yo); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="form-help">Format: YYYY or YYYY-YYYY. This is the year new students are registering for.</div>
                        </div>

                        <!-- LEVELS -->
                        <div class="section-title"><i class="fas fa-layer-group"></i> Academic Levels <span class="required" style="color:#ef4444;">*</span></div>

                        <div class="form-group">
                            <div class="check-pills" id="levelPills">
                                <?php
                                    $level_opts = [
                                        'Nursery' => 'Nursery',
                                        'Primary' => 'Primary',
                                        'O_Level' => "O' Level",
                                        'A_Level' => "A' Level",
                                    ];
                                    foreach ($level_opts as $val => $label):
                                        $checked = in_array($val, $selected_levels, true);
                                ?>
                                    <label class="check-pill <?= $checked ? 'selected' : ''; ?>">
                                        <input type="checkbox" name="levels[]" value="<?= $val; ?>" <?= $checked ? 'checked' : ''; ?>>
                                        <span class="box"><i class="fas fa-check"></i></span>
                                        <?= htmlspecialchars($label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-help">You can select one, several, or all levels. Students will only be able to register for the levels you select.</div>
                        </div>

                        <!-- REGISTRATION MODE -->
                        <div class="section-title" style="margin-top:14px;"><i class="fas fa-toggle-on"></i> Registration Mode <span class="required" style="color:#ef4444;">*</span></div>

                        <div class="form-group">
                            <div class="radio-pills" id="modePills">
                                <?php
                                    $modes = [
                                        'closed'    => ['Closed',    'fa-lock', 'fas', "No one can register. The public page will show the school as closed for registration."],
                                        'open'      => ['Open',      'fa-globe', 'fas', "Anyone with your school code can register at any time."],
                                        'scheduled' => ['Scheduled', 'fa-calendar-check', 'fas', "Registrations open and close automatically between the dates you choose."],
                                    ];
                                    foreach ($modes as $val => $m):
                                        $checked = ($cfg['mode'] === $val);
                                ?>
                                    <label class="radio-pill <?= $checked ? 'selected' : ''; ?>">
                                        <input type="radio" name="mode" value="<?= $val; ?>" <?= $checked ? 'checked' : ''; ?>>
                                        <span class="dot"></span>
                                        <span class="rp-body">
                                            <span class="rp-title"><i class="<?= $m[2] . ' ' . $m[1]; ?>"></i> <?= $m[0]; ?></span>
                                            <span class="rp-desc"><?= $m[3]; ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- SCHEDULE DATES -->
                        <div class="form-group" id="scheduleWrap" style="<?= $cfg['mode'] === 'scheduled' ? '' : 'display:none;'; ?>">
                            <div class="form-row">
                                <div class="form-group" style="margin-bottom:0;">
                                    <label for="opens_at">Opens At</label>
                                    <input type="datetime-local" id="opens_at" name="opens_at"
                                           value="<?= htmlspecialchars(dt_local($cfg['opens_at'])); ?>">
                                </div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <label for="closes_at">Closes At</label>
                                    <input type="datetime-local" id="closes_at" name="closes_at"
                                           value="<?= htmlspecialchars(dt_local($cfg['closes_at'])); ?>">
                                </div>
                            </div>
                            <div class="form-help">The public page opens automatically at the “opens at” time and closes automatically at the “closes at” time.</div>
                        </div>

                        <!-- INDEX REGISTRATION -->
                        <div class="section-title" style="margin-top:14px;"><i class="fas fa-id-card"></i> Index Registration</div>

                        <label class="switch-row <?= (int)$cfg['allow_index_reg'] === 1 ? 'on' : ''; ?>" id="indexRow" style="margin-bottom:18px;">
                            <span class="sw"></span>
                            <span class="sr-text">
                                <span class="t">Allow registration by index number</span>
                                <span class="d">When ON, students sent to your school can enter their index number and their details will be prefilled.</span>
                            </span>
                            <input type="checkbox" name="allow_index_reg" value="1" <?= (int)$cfg['allow_index_reg'] === 1 ? 'checked' : ''; ?> hidden>
                        </label>

                        <!-- NOTE -->
                        <div class="section-title" style="margin-top:14px;"><i class="fas fa-note-sticky"></i> Public Note (optional)</div>

                        <div class="form-group">
                            <label for="note">Message shown to students on the registration page</label>
                            <textarea id="note" name="note" maxlength="600"
                                      placeholder="e.g. Bring your ID and last school report on the first day."><?= htmlspecialchars($cfg['note']); ?></textarea>
                        </div>

                        <!-- PREVIEW -->
                        <div class="section-title" style="margin-top:14px;"><i class="fas fa-eye"></i> Live Preview</div>
                        <div class="preview-box" id="previewBox">
                            <div class="prow"><span class="pl">Academic Year:</span><span><strong><?= htmlspecialchars($cfg['academic_year'] ?: '—'); ?></strong></span></div>
                            <div class="prow"><span class="pl">Levels:</span><span><strong><?= $selected_levels ? htmlspecialchars(implode(', ', array_map(fn($l) => $level_map[$l] ?? $l, $selected_levels))) : '—'; ?></strong></span></div>
                            <div class="prow"><span class="pl">Status:</span><span><strong><?= htmlspecialchars($status_label[0]); ?></strong></span></div>
                            <?php if ($cfg['mode'] === 'scheduled' && $cfg['opens_at']): ?>
                                <div class="prow"><span class="pl">Opens:</span><span><?= htmlspecialchars(date('M j, Y · H:i', strtotime($cfg['opens_at']))); ?></span></div>
                            <?php endif; ?>
                            <?php if ($cfg['mode'] === 'scheduled' && $cfg['closes_at']): ?>
                                <div class="prow"><span class="pl">Closes:</span><span><?= htmlspecialchars(date('M j, Y · H:i', strtotime($cfg['closes_at']))); ?></span></div>
                            <?php endif; ?>
                        </div>

                        <!-- PUBLIC LINK -->
                        <div class="section-title" style="margin-top:20px;"><i class="fas fa-link"></i> Public Registration Link</div>
                        <div class="copy-row">
                            <input type="text" id="publicLink"
                                   value="<?= htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/online-registration.php?school=' . urlencode($school_code)); ?>"
                                   readonly onclick="this.select();">
                            <button type="button" onclick="copyLink()"><i class="fas fa-copy"></i> Copy</button>
                        </div>
                        <div class="form-help">Share this link or QR with parents so they can register.</div>

                        <!-- ACTIONS -->
                        <div class="form-actions">
                            <a href="school-settings.php" class="btn"><i class="fas fa-times"></i> Cancel</a>
                            <button type="submit" class="btn primary" id="saveBtn">
                                <i class="fas fa-save"></i> Save Settings
                            </button>
                        </div>

                    </form>

                </div>
            </div>

        </main>
    </div>
</div>

<script>
    // THEME
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

    // SIDEBAR
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

    // CHECK PILLS (levels)
    document.querySelectorAll('#levelPills .check-pill').forEach(function (pill) {
        pill.addEventListener('click', function (e) {
            const cb = pill.querySelector('input[type="checkbox"]');
            setTimeout(function () {
                pill.classList.toggle('selected', cb.checked);
                updatePreview();
            }, 0);
        });
    });

    // RADIO PILLS (mode)
    document.querySelectorAll('#modePills .radio-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('#modePills .radio-pill').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');
            const radio = pill.querySelector('input');
            if (radio) radio.checked = true;

            const mode = radio ? radio.value : 'closed';
            const sw = document.getElementById('scheduleWrap');
            sw.style.display = mode === 'scheduled' ? '' : 'none';
            updatePreview();
        });
    });

    // TOGGLE ROW (index reg)
    document.getElementById('indexRow').addEventListener('click', function (e) {
        if (e.target.closest('.sw')) e.preventDefault();
        const row = this;
        const cb = row.querySelector('input[type="checkbox"]');
        cb.checked = !cb.checked;
        row.classList.toggle('on', cb.checked);
    });

    // LIVE PREVIEW
    function updatePreview() {
        const year = document.getElementById('academic_year').value.trim() || '—';
        const levels = Array.from(document.querySelectorAll('#levelPills input[type="checkbox"]:checked'))
                            .map(c => c.value);
        const lvlMap = { Nursery:'Nursery', Primary:'Primary', O_Level:"O' Level", A_Level:"A' Level" };
        const lvlText = levels.length ? levels.map(v => lvlMap[v] || v).join(', ') : '—';

        const modeEl = document.querySelector('#modePills input[type="radio"]:checked');
        const mode = modeEl ? modeEl.value : 'closed';
        const labelMap = { closed:'Closed', open:'Open', scheduled:'Scheduled' };
        const statusText = labelMap[mode] || '—';

        const opens = document.getElementById('opens_at') ? document.getElementById('opens_at').value : '';
        const closes = document.getElementById('closes_at') ? document.getElementById('closes_at').value : '';

        let html = '';
        html += '<div class="prow"><span class="pl">Academic Year:</span><span><strong>' + escapeHtml(year) + '</strong></span></div>';
        html += '<div class="prow"><span class="pl">Levels:</span><span><strong>' + escapeHtml(lvlText) + '</strong></span></div>';
        html += '<div class="prow"><span class="pl">Status:</span><span><strong>' + escapeHtml(statusText) + '</strong></span></div>';
        if (mode === 'scheduled') {
            if (opens)  html += '<div class="prow"><span class="pl">Opens:</span><span>' + escapeHtml(formatDT(opens)) + '</span></div>';
            if (closes) html += '<div class="prow"><span class="pl">Closes:</span><span>' + escapeHtml(formatDT(closes)) + '</span></div>';
        }
        document.getElementById('previewBox').innerHTML = html;
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function formatDT(v) {
        try { const d = new Date(v); return d.toLocaleString(); } catch(e) { return v; }
    }
    ['academic_year','opens_at','closes_at'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', updatePreview);
    });

    // COPY LINK
    function copyLink() {
        const inp = document.getElementById('publicLink');
        inp.select();
        document.execCommand('copy');
        const btn = inp.nextElementSibling;
        const old = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(function () { btn.innerHTML = old; }, 1400);
    }

    // PREVENT DOUBLE SUBMIT
    document.getElementById('orForm').addEventListener('submit', function () {
        const btn = document.getElementById('saveBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    });
</script>

</body>
</html>