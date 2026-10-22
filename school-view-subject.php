<?php
session_start();
require_once 'db.php';

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
| GET SUBJECT
|--------------------------------------------------------------------------
*/
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) { header("Location: school-subjects.php"); exit; }

$stmt = mysqli_prepare($conn, "SELECT * FROM subjects WHERE id = ? AND school_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "ii", $id, $school_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($res) === 0) {
    header("Location: school-subjects.php?error=" . urlencode("Subject not found."));
    exit;
}

$s = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

/* Subject initials */
$s_init = "";
foreach (explode(" ", trim($s['subject_name'])) as $part) {
    if ($part !== "") { $s_init .= strtoupper($part[0]); if (strlen($s_init) >= 2) break; }
}
if ($s_init === "") $s_init = "S";

/*
|--------------------------------------------------------------------------
| FETCH ASSIGNED CLASSES + TEACHERS + NUMBERS
|--------------------------------------------------------------------------
*/
$assigned = [];
$stmt = mysqli_prepare($conn, "
    SELECT
        sc.class_id,
        sc.credits,
        sc.week_hours,
        sc.max_marks,
        c.class_name,
        c.level,
        c.room,
        sct.teacher_id,
        t.name AS teacher_name,
        t.email AS teacher_email,
        t.phone AS teacher_phone
    FROM subject_classes sc
    INNER JOIN classes c ON c.id = sc.class_id
    LEFT JOIN subject_class_teachers sct
           ON sct.subject_id = sc.subject_id
          AND sct.class_id = sc.class_id
    LEFT JOIN teachers t ON t.id = sct.teacher_id AND t.school_id = sc.school_id
    WHERE sc.school_id = ? AND sc.subject_id = ?
    ORDER BY FIELD(c.level,'Nursery','Primary','O_Level','A_Level'), c.class_name ASC
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "ii", $school_id, $id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) {
        $assigned[] = $row;
    }
    mysqli_stmt_close($stmt);
}

/* Group by level */
$by_level = [];
foreach ($assigned as $row) {
    $lv = $row['level'] ?: 'Unknown';
    $by_level[$lv][] = $row;
}

/* Compute coverage & number ranges */
$total_classes = count($assigned);
$unassigned    = 0;
foreach ($assigned as $row) {
    if (empty($row['teacher_name'])) $unassigned++;
}
$covered = $total_classes - $unassigned;

$cov_status = 'none';
$cov_label  = 'No classes assigned';
$cov_icon   = 'fa-circle-exclamation';

if ($total_classes > 0) {
    if ($unassigned === 0) {
        $cov_status = 'full';    $cov_label = 'Fully covered';      $cov_icon = 'fa-circle-check';
    } elseif ($covered === 0) {
        $cov_status = 'none';    $cov_label = 'No teacher assigned'; $cov_icon = 'fa-circle-exclamation';
    } else {
        $cov_status = 'partial'; $cov_label = "{$covered}/{$total_classes} covered"; $cov_icon = 'fa-circle-half-stroke';
    }
}

/* Level maps */
$level_map = [
    'Nursery' => 'Nursery',
    'Primary' => 'Primary',
    'O_Level' => "O' Level",
    'A_Level' => "A' Level",
];
$level_colors = [
    'Nursery' => 'pink',
    'Primary' => 'blue',
    'O_Level' => 'green',
    'A_Level' => 'orange',
];
$level_icons = [
    'Nursery' => 'fa-baby',
    'Primary' => 'fa-book',
    'O_Level' => 'fa-book-open',
    'A_Level' => 'fa-graduation-cap',
];

function fmt_num($v) {
    if ($v === null || $v === '') return null;
    return rtrim(rtrim(number_format((float)$v, 1), '0'), '.');
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Subject | Educ Bridge</title>
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
            --danger: #ef4444; --danger-light: rgba(239,68,68,0.1);
            --sidebar-bg: #ffffff;
            --sidebar-hover: rgba(37, 99, 235, 0.06);
            --sidebar-active: rgba(37, 99, 235, 0.10);
            --sidebar-text: #475569;
            --sidebar-text-active: #0f172a;
            --sidebar-border: #e2e8f0;
            --sidebar-title: #94a3b8;
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
            --danger: #f87171; --danger-light: rgba(239,68,68,0.15);
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(59, 130, 246, 0.18);
            --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.08);
            --sidebar-title: #94a3b8;
            --bg-body: #0b1120; --bg-card: #1e293b;
            --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
            --shadow-sidebar: 0 0 30px rgba(0,0,0,0.4);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        html, body { min-height: 100vh; }
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); transition:background 0.4s ease, color 0.4s ease; overflow-x:hidden; }
        .layout { display:flex; min-height:100vh; }

        .sidebar { width:260px; background:var(--sidebar-bg); color:var(--sidebar-text); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:100; transition:background 0.4s ease, color 0.4s ease, transform 0.3s ease, border-color 0.4s ease; border-right:1px solid var(--sidebar-border); box-shadow:var(--shadow-sidebar); }
        .sidebar-header { padding:22px 22px 18px; border-bottom:1px solid var(--sidebar-border); display:flex; align-items:center; gap:12px; }
        .sidebar-header img { width:42px; height:42px; object-fit:contain; border-radius:50%; border:2px solid var(--sidebar-border); padding:2px; background:#fff; }
        .sidebar-brand { font-family:'Poppins',sans-serif; font-size:18px; font-weight:700; color:var(--text-primary); line-height:1.2; }
        .sidebar-brand span { color:var(--primary); }
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

        .profile-card { max-width:960px; margin:0 auto; background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-lg); overflow:hidden; }

        /* HEADER */
        .profile-head {
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            padding: 36px 32px;
            color: #fff;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            gap: 22px;
        }
        .profile-head::before { content:''; position:absolute; top:-60%; right:-10%; width:320px; height:320px; background:radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%); border-radius:50%; }
        .profile-head::after { content:''; position:absolute; bottom:-60%; left:20%; width:260px; height:260px; background:radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%); border-radius:50%; }

        .profile-avatar {
            width: 96px; height: 96px;
            border-radius: 20px;
            background: rgba(255,255,255,0.18);
            backdrop-filter: blur(4px);
            border: 3px solid rgba(255,255,255,0.4);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 34px; font-weight: 700;
            flex-shrink: 0;
            position: relative;
            z-index: 1;
        }
        .profile-head-info { position: relative; z-index: 1; flex: 1; min-width: 0; }
        .profile-head-info h1 { font-family:'Poppins',sans-serif; font-size:26px; font-weight:700; letter-spacing:-0.5px; margin-bottom:6px; }
        .profile-head-info .sub { font-size:14px; opacity:0.9; display:flex; flex-wrap:wrap; gap:14px; }
        .profile-head-info .sub span { display:inline-flex; align-items:center; gap:6px; }
        .profile-head-badges { margin-top:12px; display:flex; flex-wrap:wrap; gap:8px; position:relative; z-index:1; }
        .profile-head-badges .badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.35);
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 12px; font-weight: 700;
            letter-spacing: 0.3px;
        }

        .profile-body { padding: 28px 32px; }
        .section-block { margin-bottom: 26px; }
        .section-block:last-child { margin-bottom: 0; }
        .section-block h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 12px; font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            color: var(--text-muted);
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px dashed var(--border-color);
            display: flex; align-items: center; gap: 8px;
        }
        .section-block h3 i { color: var(--primary); }
        .section-block h3 .count-pill {
            margin-left: auto;
            background: var(--primary-light);
            color: var(--primary);
            font-size: 10.5px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 50px;
            letter-spacing: 0;
        }

        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; }
        .info-item .lbl { font-size:11.5px; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.6px; font-weight:700; margin-bottom:4px; }
        .info-item .val { font-size:14.5px; color:var(--text-primary); font-weight:600; word-break:break-word; }
        .info-item.full { grid-column: 1 / -1; }

        .pill { display:inline-block; padding:4px 12px; border-radius:50px; font-size:12px; font-weight:700; letter-spacing:0.3px; line-height:1.4; }
        .pill.blue   { background: rgba(37,99,235,0.12);  color: #2563eb; }
        .pill.green  { background: rgba(16,185,129,0.12); color: #059669; }
        .pill.orange { background: rgba(245,158,11,0.15); color: #b45309; }
        .pill.purple { background: rgba(124,58,237,0.12); color: #7c3aed; }
        .pill.gray   { background: rgba(100,116,139,0.14);color: #475569; }
        .pill.red    { background: rgba(239,68,68,0.12);  color: #dc2626; }
        .pill.teal   { background: rgba(20,184,166,0.12); color: #0d9488; }
        .pill.pink   { background: rgba(236,72,153,0.12); color: #db2777; }
        [data-theme="dark"] .pill.blue   { color: #93c5fd; }
        [data-theme="dark"] .pill.green  { color: #34d399; }
        [data-theme="dark"] .pill.orange { color: #fbbf24; }
        [data-theme="dark"] .pill.purple { color: #a78bfa; }
        [data-theme="dark"] .pill.gray   { color: #cbd5e1; }
        [data-theme="dark"] .pill.red    { color: #f87171; }
        [data-theme="dark"] .pill.teal   { color: #2dd4bf; }
        [data-theme="dark"] .pill.pink   { color: #f9a8d4; }

        /* Coverage badge */
        .coverage-banner {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 20px;
            border-radius: 14px;
            margin-bottom: 20px;
            border: 1.5px solid;
        }
        .coverage-banner.full {
            background: var(--success-light);
            border-color: rgba(16,185,129,0.3);
            color: #059669;
        }
        .coverage-banner.partial {
            background: var(--warning-light);
            border-color: rgba(245,158,11,0.35);
            color: #b45309;
        }
        .coverage-banner.none {
            background: var(--danger-light);
            border-color: rgba(239,68,68,0.3);
            color: #dc2626;
        }
        [data-theme="dark"] .coverage-banner.full    { color: #34d399; }
        [data-theme="dark"] .coverage-banner.partial { color: #fbbf24; }
        [data-theme="dark"] .coverage-banner.none    { color: #f87171; }

        .coverage-banner .cb-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; flex-shrink: 0;
            background: rgba(255,255,255,0.55);
            color: inherit;
        }
        [data-theme="dark"] .coverage-banner .cb-icon { background: rgba(0,0,0,0.25); }
        .coverage-banner .cb-text { flex: 1; min-width: 0; }
        .coverage-banner .cb-text .t {
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -0.2px;
        }
        .coverage-banner .cb-text .d {
            font-size: 13px;
            opacity: 0.9;
            margin-top: 3px;
        }

        /* Level groups + class rows */
        .level-group { margin-bottom: 22px; }
        .level-group:last-child { margin-bottom: 0; }

        .lg-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-bottom: 10px;
        }
        .lg-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; color: #fff;
            flex-shrink: 0;
        }
        .lg-icon.pink   { background: linear-gradient(135deg, #ec4899, #f472b6); }
        .lg-icon.blue   { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .lg-icon.green  { background: linear-gradient(135deg, #10b981, #34d399); }
        .lg-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .lg-icon.gray   { background: linear-gradient(135deg, #64748b, #94a3b8); }
        .lg-info h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 15px; font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        .lg-info .s {
            font-size: 12.5px; color: var(--text-muted);
        }

        .class-row {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 14px 16px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-bottom: 8px;
            transition: var(--transition);
        }
        .class-row:last-child { margin-bottom: 0; }
        .class-row:hover { border-color: var(--primary); }
        .class-row.unassigned {
            border-color: rgba(245,158,11,0.4);
            background: linear-gradient(90deg, rgba(245,158,11,0.06), var(--bg-alt));
        }

        .class-row .cr-main {
            display: flex; align-items: center; gap: 12px;
            flex: 1 1 200px; min-width: 180px;
        }
        .class-row .cr-avatar {
            width: 40px; height: 40px;
            border-radius: 10px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif; font-size: 13px; font-weight: 700;
            flex-shrink: 0;
        }
        .class-row .cr-info { flex: 1; min-width: 0; }
        .class-row .cr-name {
            font-size: 14px; font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        .class-row .cr-meta {
            font-size: 11.5px; color: var(--text-muted);
            display: flex; gap: 8px; flex-wrap: wrap;
        }

        .class-row .cr-numbers {
            display: flex; gap: 16px; flex-wrap: wrap;
            flex: 1 1 260px;
        }
        .class-row .num-block {
            display: flex; flex-direction: column;
            min-width: 70px;
        }
        .class-row .num-block .nb-lbl {
            font-size: 10px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 3px;
        }
        .class-row .num-block .nb-val {
            font-family: 'Poppins', sans-serif;
            font-size: 14px; font-weight: 700;
            color: var(--text-primary);
        }
        .class-row .num-block .nb-val.muted {
            color: var(--text-muted);
            font-weight: 500;
            font-family: 'Inter', sans-serif;
            font-style: italic;
        }

        .class-row .cr-teacher {
            display: flex; align-items: center; gap: 10px;
            flex: 1 1 220px;
            padding-left: 16px;
            border-left: 1px dashed var(--border-color);
        }
        .class-row .cr-teacher .t-avatar {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif; font-size: 13px; font-weight: 700;
            flex-shrink: 0;
        }
        .class-row .cr-teacher .t-avatar.unassigned {
            background: var(--bg-card);
            color: var(--text-muted);
            border: 1.5px dashed var(--border-color);
        }
        .class-row .cr-teacher .t-info { flex: 1; min-width: 0; }
        .class-row .cr-teacher .t-name {
            font-size: 13.5px; font-weight: 700;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .class-row .cr-teacher .t-name.unassigned {
            color: #b45309;
            font-style: italic;
            font-weight: 600;
        }
        [data-theme="dark"] .class-row .cr-teacher .t-name.unassigned { color: #fbbf24; }
        .class-row .cr-teacher .t-sub {
            font-size: 11.5px;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .empty-note { color:var(--text-muted); font-style:italic; font-size:13.5px; }

        .desc-box { padding: 14px 16px; background: var(--bg-alt); border: 1px solid var(--border-color); border-radius: 12px; font-size: 14px; color: var(--text-primary); line-height: 1.65; }

        .action-row { display:flex; gap:10px; padding:20px 32px; border-top:1px solid var(--border-color); background:var(--bg-alt); flex-wrap:wrap; justify-content:flex-end; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:12px 20px; border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); transition:var(--transition); font-family:'Inter',sans-serif; }
        .btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }
        .btn.danger { background:transparent; color:var(--danger); border-color:rgba(239,68,68,0.35); }
        .btn.danger:hover { background:var(--danger); color:#fff; border-color:var(--danger); }
        .btn.warn { background:transparent; color:#b45309; border-color:rgba(245,158,11,0.35); }
        .btn.warn:hover { background:#f59e0b; color:#fff; border-color:#f59e0b; }
        .btn.activate { background:transparent; color:#059669; border-color:rgba(16,185,129,0.35); }
        .btn.activate:hover { background:#059669; color:#fff; border-color:#059669; }

        .overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:90; backdrop-filter:blur(2px); }
        .overlay.show { display:block; }

        @media (max-width: 900px) {
            .sidebar { transform:translateX(-100%); }
            .sidebar.open { transform:translateX(0); box-shadow:0 0 40px rgba(0,0,0,0.2); }
            .main { margin-left:0; }
            .hamburger { display:flex; }
        }
        @media (max-width: 700px) {
            .info-grid { grid-template-columns:1fr; }
            .profile-head { flex-direction: column; text-align: center; padding: 26px 20px; }
            .profile-head-info .sub { justify-content: center; }
            .profile-head-badges { justify-content: center; }
            .class-row { flex-direction: column; align-items: flex-start; gap: 12px; }
            .class-row .cr-teacher { border-left: none; border-top: 1px dashed var(--border-color); padding-left: 0; padding-top: 10px; width: 100%; }
        }
        @media (max-width: 600px) {
            .content { padding:20px 16px; }
            .topbar { padding:12px 16px; }
            .page-title { font-size:17px; }
            .profile-body { padding:22px 20px; }
            .action-row { padding: 16px 20px; }
        }

        @media print {
            .sidebar, .topbar, .back-link, .action-row, .overlay, .no-print { display: none !important; }
            .main { margin-left: 0 !important; }
            .content { padding: 0 !important; }
            .layout { display: block; }
            body { background: #fff !important; }
            .profile-card { box-shadow: none !important; border: 1px solid #ddd !important; }
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
            <a href="school-teachers.php" class="nav-item"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>
            <a href="school-classes.php" class="nav-item"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
            <a href="school-subjects.php" class="nav-item active"><i class="fas fa-book nav-icon"></i> Subjects</a>
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
                    <div class="page-title">Subject Details</div>
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

            <a href="school-subjects.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Subjects</a>

            <div class="profile-card">

                <div class="profile-head">
                    <div class="profile-avatar"><?= htmlspecialchars($s_init); ?></div>
                    <div class="profile-head-info">
                        <h1><?= htmlspecialchars($s['subject_name']); ?></h1>
                        <div class="sub">
                            <?php if (!empty($s['subject_code'])): ?>
                                <span><i class="fas fa-barcode"></i> <?= htmlspecialchars($s['subject_code']); ?></span>
                            <?php endif; ?>
                            <span><i class="fas fa-layer-group"></i> <?= count($assigned); ?> class<?= count($assigned) === 1 ? '' : 'es'; ?></span>
                        </div>
                        <div class="profile-head-badges">
                            <?php if ($s['status'] === 'active'): ?>
                                <span class="badge" style="background:rgba(16,185,129,0.28);border-color:rgba(16,185,129,0.5);"><i class="fas fa-check-circle"></i> Active</span>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(239,68,68,0.28);border-color:rgba(239,68,68,0.5);"><i class="fas fa-ban"></i> Inactive</span>
                            <?php endif; ?>
                            <?php foreach (array_keys($by_level) as $lv): ?>
                                <?php if (isset($level_map[$lv])): ?>
                                    <span class="badge"><i class="fas <?= $level_icons[$lv] ?? 'fa-layer-group'; ?>"></i> <?= htmlspecialchars($level_map[$lv]); ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="profile-body">

                    <!-- COVERAGE BANNER -->
                    <?php if ($total_classes > 0): ?>
                        <div class="coverage-banner <?= $cov_status; ?>">
                            <div class="cb-icon"><i class="fas <?= $cov_icon; ?>"></i></div>
                            <div class="cb-text">
                                <div class="t"><?= htmlspecialchars($cov_label); ?></div>
                                <div class="d">
                                    <?php if ($unassigned === 0): ?>
                                        Every class that studies this subject has a teacher assigned.
                                    <?php elseif ($covered === 0): ?>
                                        None of the <?= $total_classes; ?> classes have a teacher yet. Add one to get started.
                                    <?php else: ?>
                                        <?= $unassigned; ?> of <?= $total_classes; ?> classes still need a teacher.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- SUBJECT INFO -->
                    <div class="section-block">
                        <h3><i class="fas fa-book"></i> Subject Information</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="lbl">Subject Name</div>
                                <div class="val"><?= htmlspecialchars($s['subject_name']); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="lbl">Subject Code</div>
                                <div class="val"><?= !empty($s['subject_code']) ? htmlspecialchars($s['subject_code']) : '<span class="empty-note">Not set</span>'; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="lbl">Status</div>
                                <div class="val">
                                    <?php if ($s['status'] === 'active'): ?>
                                        <span class="pill green">Active</span>
                                    <?php else: ?>
                                        <span class="pill red">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="lbl">Classes</div>
                                <div class="val"><?= $total_classes; ?></div>
                            </div>
                            <div class="info-item">
                                <div class="lbl">Created</div>
                                <div class="val"><?= date('F j, Y', strtotime($s['created_at'])); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="lbl">Last Updated</div>
                                <div class="val"><?= date('F j, Y', strtotime($s['updated_at'])); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- ASSIGNED CLASSES + TEACHERS + NUMBERS -->
                    <div class="section-block">
                        <h3>
                            <i class="fas fa-layer-group"></i>
                            Classes &amp; Teachers
                            <span class="count-pill"><?= $total_classes; ?> class<?= $total_classes === 1 ? '' : 'es'; ?></span>
                        </h3>

                        <?php if (empty($assigned)): ?>
                            <div class="desc-box" style="text-align:center;">
                                <span class="empty-note">This subject is not assigned to any class yet. Edit the subject to add classes.</span>
                            </div>
                        <?php else: ?>
                            <?php foreach ($by_level as $lv => $rows): ?>
                                <div class="level-group">
                                    <div class="lg-header">
                                        <div class="lg-icon <?= $level_colors[$lv] ?? 'gray'; ?>">
                                            <i class="fas <?= $level_icons[$lv] ?? 'fa-layer-group'; ?>"></i>
                                        </div>
                                        <div class="lg-info">
                                            <h4><?= htmlspecialchars($level_map[$lv] ?? $lv); ?></h4>
                                            <div class="s"><?= count($rows); ?> class<?= count($rows) === 1 ? '' : 'es'; ?> studying this subject</div>
                                        </div>
                                    </div>

                                    <?php foreach ($rows as $row):
                                        $has_teacher = !empty($row['teacher_name']);
                                        $row_class = $has_teacher ? '' : 'unassigned';

                                        $t_init = "";
                                        if ($has_teacher) {
                                            foreach (explode(" ", trim($row['teacher_name'])) as $p) {
                                                if ($p !== "") { $t_init .= strtoupper($p[0]); if (strlen($t_init) >= 2) break; }
                                            }
                                        }
                                        if ($t_init === "") $t_init = "T";

                                        $cr_init = "";
                                        foreach (explode(" ", trim($row['class_name'])) as $p) {
                                            if ($p !== "") { $cr_init .= strtoupper($p[0]); if (strlen($cr_init) >= 2) break; }
                                        }
                                        if ($cr_init === "") $cr_init = "C";
                                    ?>
                                        <div class="class-row <?= $row_class; ?>">
                                            <div class="cr-main">
                                                <div class="cr-avatar"><?= htmlspecialchars($cr_init); ?></div>
                                                <div class="cr-info">
                                                    <div class="cr-name"><?= htmlspecialchars($row['class_name']); ?></div>
                                                    <div class="cr-meta">
                                                        <?php if (!empty($row['room'])): ?>
                                                            <span><i class="fas fa-door-open"></i> <?= htmlspecialchars($row['room']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="cr-numbers">
                                                <div class="num-block">
                                                    <div class="nb-lbl">Credits</div>
                                                    <div class="nb-val <?= $row['credits'] === null ? 'muted' : ''; ?>">
                                                        <?= $row['credits'] !== null ? htmlspecialchars(fmt_num($row['credits'])) : '—'; ?>
                                                    </div>
                                                </div>
                                                <div class="num-block">
                                                    <div class="nb-lbl">Week Hrs</div>
                                                    <div class="nb-val <?= $row['week_hours'] === null ? 'muted' : ''; ?>">
                                                        <?= $row['week_hours'] !== null ? htmlspecialchars(fmt_num($row['week_hours'])) : '—'; ?>
                                                    </div>
                                                </div>
                                                <div class="num-block">
                                                    <div class="nb-lbl">Max Marks</div>
                                                    <div class="nb-val <?= $row['max_marks'] === null ? 'muted' : ''; ?>">
                                                        <?= $row['max_marks'] !== null ? (int)$row['max_marks'] : '—'; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="cr-teacher">
                                                <div class="t-avatar <?= $has_teacher ? '' : 'unassigned'; ?>">
                                                    <?php if ($has_teacher): ?>
                                                        <?= htmlspecialchars($t_init); ?>
                                                    <?php else: ?>
                                                        <i class="fas fa-user-slash" style="font-size:11px;"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="t-info">
                                                    <?php if ($has_teacher): ?>
                                                        <div class="t-name"><?= htmlspecialchars($row['teacher_name']); ?></div>
                                                        <?php if (!empty($row['teacher_email'])): ?>
                                                            <div class="t-sub"><?= htmlspecialchars($row['teacher_email']); ?></div>
                                                        <?php elseif (!empty($row['teacher_phone'])): ?>
                                                            <div class="t-sub"><?= htmlspecialchars($row['teacher_phone']); ?></div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="t-name unassigned">No teacher assigned</div>
                                                        <div class="t-sub">Assign one from Edit subject</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($s['description'])): ?>
                    <div class="section-block">
                        <h3><i class="fas fa-align-left"></i> Description</h3>
                        <div class="desc-box"><?= nl2br(htmlspecialchars($s['description'])); ?></div>
                    </div>
                    <?php endif; ?>

                </div>

                <div class="action-row no-print">
                    <button class="btn" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <a href="school-edit-subject.php?id=<?= (int)$s['id']; ?>" class="btn primary">
                        <i class="fas fa-pen"></i> Edit
                    </a>

                    <?php if ($s['status'] === 'active'): ?>
                        <a href="school-toggle-subject.php?id=<?= (int)$s['id']; ?>&action=deactivate"
                           class="btn warn"
                           onclick="return confirm('Deactivate this subject?');">
                            <i class="fas fa-ban"></i> Deactivate
                        </a>
                    <?php else: ?>
                        <a href="school-toggle-subject.php?id=<?= (int)$s['id']; ?>&action=activate"
                           class="btn activate"
                           onclick="return confirm('Activate this subject?');">
                            <i class="fas fa-check-circle"></i> Activate
                        </a>
                    <?php endif; ?>

                    <a href="school-delete-subject.php?id=<?= (int)$s['id']; ?>" class="btn danger"
                       onclick="return confirm('Delete this subject? This cannot be undone.');">
                        <i class="fas fa-trash"></i> Delete
                    </a>
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
</script>

</body>
</html>