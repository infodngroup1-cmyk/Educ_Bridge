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
| GET TEACHER (must belong to this school)
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

$t = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

// Teacher initials
$t_init = "";
foreach (explode(" ", trim($t['name'])) as $part) {
    if ($part !== "") {
        $t_init .= strtoupper($part[0]);
        if (strlen($t_init) >= 2) break;
    }
}
if ($t_init === "") $t_init = "T";

/*
|--------------------------------------------------------------------------
| FETCH SCHOOL'S ACTIVE ACADEMIC LEVELS (for nice labels)
|--------------------------------------------------------------------------
*/
$active_levels = []; // ['nursery' => 'Nursery', ...]
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

// Pretty display labels (ENUM → label)
$level_map = [
    'Nursery' => 'Nursery',
    'Primary' => 'Primary',
    'O_Level' => "O' Level",
    'A_Level' => "A' Level",
];

// Add any active level labels that might be customized by the school
foreach ($active_levels as $key => $label) {
    $enum_val = $LEVEL_KEY_TO_ENUM[$key] ?? null;
    if ($enum_val && !isset($level_map[$enum_val])) {
        $level_map[$enum_val] = $label;
    }
}

$t_levels = array_filter(array_map('trim', explode(',', (string) $t['teaching_level'])));

// Diploma color
$diploma_class = [
    'A2' => 'blue', 'A1' => 'blue', 'A0' => 'purple',
    'Masters' => 'orange', 'PhD' => 'red', 'Professor' => 'red',
][$t['diploma']] ?? 'gray';

// Safe values
$t_id_number = (string)($t['id_number'] ?? '');
$has_id      = $t_id_number !== '';
$has_dis     = ((int)$t['has_disability'] === 1);
$joined      = !empty($t['created_at']) ? date('F j, Y · H:i', strtotime($t['created_at'])) : '—';
$updated     = !empty($t['updated_at']) ? date('F j, Y · H:i', strtotime($t['updated_at'])) : '—';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Teacher | Educ Bridge</title>
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

        /* SIDEBAR */
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

        /* MAIN */
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

        .profile-card { max-width:820px; margin:0 auto; background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-lg); overflow:hidden; }

        /* HEAD */
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
        .profile-head::before {
            content: '';
            position: absolute;
            top: -60%; right: -10%;
            width: 320px; height: 320px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
            border-radius: 50%;
        }
        .profile-head::after {
            content: '';
            position: absolute;
            bottom: -60%; left: 20%;
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            border-radius: 50%;
        }
        .profile-avatar {
            width: 96px; height: 96px;
            border-radius: 50%;
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
        .profile-head-info h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 26px; font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
            word-break: break-word;
        }
        .profile-head-info .sub {
            font-size: 14px;
            opacity: 0.92;
            display: flex; flex-wrap: wrap; gap: 14px;
        }
        .profile-head-info .sub span { display: inline-flex; align-items: center; gap: 6px; }
        .profile-head-badges {
            margin-top: 12px;
            display: flex; flex-wrap: wrap; gap: 8px;
            position: relative; z-index: 1;
        }
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

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 24px;
        }
        .info-item .lbl {
            font-size: 11.5px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .info-item .val {
            font-size: 14.5px;
            color: var(--text-primary);
            font-weight: 600;
            word-break: break-word;
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        }
        .info-item.full { grid-column: 1 / -1; }

        /* Copy chip */
        .copy-chip {
            display: inline-flex;
            align-items: center; gap: 5px;
            font-size: 11px; font-weight: 700;
            background: var(--bg-alt);
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            padding: 2px 8px;
            border-radius: 50px;
            cursor: pointer;
            transition: var(--transition);
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .copy-chip:hover { border-color: var(--primary); color: var(--primary); }

        .mono { font-family: 'Courier New', monospace; letter-spacing: 0.5px; }

        .pill {
            display: inline-block;
            padding: 4px 12px; border-radius: 50px;
            font-size: 12px; font-weight: 700;
            letter-spacing: 0.3px; line-height: 1.4;
            margin-right: 4px; margin-bottom: 4px;
        }
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

        .empty-note {
            color: var(--text-muted);
            font-style: italic;
            font-size: 13.5px;
        }

        .disability-box {
            padding: 16px 18px;
            background: var(--warning-light);
            border: 1px solid rgba(245,158,11,0.25);
            border-radius: 12px;
        }
        [data-theme="dark"] .disability-box { background: rgba(245,158,11,0.12); }
        .disability-box .db-title {
            font-size: 12px;
            color: #b45309;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 700;
            margin-bottom: 6px;
            display: flex; align-items: center; gap: 8px;
        }
        [data-theme="dark"] .disability-box .db-title { color: #fbbf24; }
        .disability-box .db-text {
            font-size: 14px;
            color: var(--text-primary);
            line-height: 1.6;
        }

        .action-row {
            display: flex;
            gap: 10px;
            padding: 20px 32px;
            border-top: 1px solid var(--border-color);
            background: var(--bg-alt);
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:12px 20px; border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); transition:var(--transition); font-family:'Inter',sans-serif; }
        .btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }
        .btn.danger { background:transparent; color:var(--danger); border-color:rgba(239,68,68,0.35); }
        .btn.danger:hover { background:var(--danger); color:#fff; border-color:var(--danger); }
        .btn.print { background:var(--bg-card); color:var(--text-primary); }
        .btn.print:hover { border-color:var(--primary); color:var(--primary); }

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
        }
        @media (max-width: 600px) {
            .content { padding:20px 16px; }
            .topbar { padding:12px 16px; }
            .page-title { font-size:17px; }
            .profile-body { padding:22px 20px; }
            .action-row { padding: 16px 20px; }
        }

        @media print {
            .sidebar, .topbar, .back-link, .action-row, .overlay, .no-print, .copy-chip { display: none !important; }
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

    <!-- SIDEBAR -->
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

    <!-- MAIN -->
    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Teacher Details</div>
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

            <div class="profile-card">

                <!-- HEAD -->
                <div class="profile-head">
                    <div class="profile-avatar"><?= htmlspecialchars($t_init); ?></div>
                    <div class="profile-head-info">
                        <h1><?= htmlspecialchars($t['name']); ?></h1>
                        <div class="sub">
                            <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($t['email']); ?></span>
                            <span><i class="fas fa-phone"></i> <?= htmlspecialchars($t['phone']); ?></span>
                            <?php if ($has_id): ?>
                                <span><i class="fas fa-id-card"></i> <?= htmlspecialchars($t_id_number); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="profile-head-badges">
                            <span class="badge"><i class="fas fa-graduation-cap"></i> <?= htmlspecialchars($t['diploma']); ?></span>
                            <?php if (!empty($t_levels)): ?>
                                <?php foreach ($t_levels as $lv): ?>
                                    <span class="badge"><i class="fas fa-book-open"></i> <?= htmlspecialchars($level_map[$lv] ?? $lv); ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($t['status'] === 'active'): ?>
                                <span class="badge" style="background:rgba(16,185,129,0.28);border-color:rgba(16,185,129,0.5);"><i class="fas fa-check-circle"></i> Active</span>
                            <?php else: ?>
                                <span class="badge" style="background:rgba(239,68,68,0.28);border-color:rgba(239,68,68,0.5);"><i class="fas fa-ban"></i> Inactive</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- BODY -->
                <div class="profile-body">

                    <!-- PERSONAL -->
                    <div class="section-block">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="lbl">Full Name</div>
                                <div class="val">
                                    <?= htmlspecialchars($t['name']); ?>
                                    <span class="copy-chip no-print" onclick="copyText('<?= htmlspecialchars(addslashes($t['name'])); ?>', this)">
                                        <i class="fas fa-copy"></i> Copy
                                    </span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="lbl">Sex</div>
                                <div class="val"><?= htmlspecialchars($t['sex']); ?></div>
                            </div>

                            <div class="info-item">
                                <div class="lbl">Email</div>
                                <div class="val">
                                    <?= htmlspecialchars($t['email']); ?>
                                    <span class="copy-chip no-print" onclick="copyText('<?= htmlspecialchars(addslashes($t['email'])); ?>', this)">
                                        <i class="fas fa-copy"></i> Copy
                                    </span>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="lbl">Phone</div>
                                <div class="val">
                                    <?= htmlspecialchars($t['phone']); ?>
                                    <span class="copy-chip no-print" onclick="copyText('<?= htmlspecialchars(addslashes($t['phone'])); ?>', this)">
                                        <i class="fas fa-copy"></i> Copy
                                    </span>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="lbl">National ID Number</div>
                                <div class="val mono">
                                    <?php if ($has_id): ?>
                                        <?= htmlspecialchars($t_id_number); ?>
                                        <span class="copy-chip no-print" onclick="copyText('<?= htmlspecialchars(addslashes($t_id_number)); ?>', this)">
                                            <i class="fas fa-copy"></i> Copy
                                        </span>
                                    <?php else: ?>
                                        <span class="empty-note">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="lbl">Status</div>
                                <div class="val">
                                    <?php if ($t['status'] === 'active'): ?>
                                        <span class="pill green">Active</span>
                                    <?php else: ?>
                                        <span class="pill red">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="lbl">Joined</div>
                                <div class="val"><?= htmlspecialchars($joined); ?></div>
                            </div>
                            <div class="info-item">
                                <div class="lbl">Last Updated</div>
                                <div class="val"><?= htmlspecialchars($updated); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- PROFESSIONAL -->
                    <div class="section-block">
                        <h3><i class="fas fa-briefcase"></i> Professional</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <div class="lbl">Diploma</div>
                                <div class="val"><span class="pill <?= $diploma_class; ?>"><?= htmlspecialchars($t['diploma']); ?></span></div>
                            </div>
                            <div class="info-item">
                                <div class="lbl">Teaching Level(s)</div>
                                <div class="val">
                                    <?php if (!empty($t_levels)): ?>
                                        <?php foreach ($t_levels as $lv): ?>
                                            <span class="pill teal"><?= htmlspecialchars($level_map[$lv] ?? $lv); ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="empty-note">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PAYMENT -->
                    <div class="section-block">
                        <h3><i class="fas fa-credit-card"></i> Payment Information</h3>
                        <?php if (!empty($t['bank_type']) || !empty($t['account_name']) || !empty($t['account_number'])): ?>
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="lbl">Bank / Wallet</div>
                                    <div class="val"><?= htmlspecialchars($t['bank_type'] ?: '—'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="lbl">Account Name</div>
                                    <div class="val"><?= htmlspecialchars($t['account_name'] ?: '—'); ?></div>
                                </div>
                                <div class="info-item full">
                                    <div class="lbl">Account Number</div>
                                    <div class="val mono">
                                        <?= htmlspecialchars($t['account_number'] ?: '—'); ?>
                                        <?php if (!empty($t['account_number'])): ?>
                                            <span class="copy-chip no-print" onclick="copyText('<?= htmlspecialchars(addslashes($t['account_number'])); ?>', this)">
                                                <i class="fas fa-copy"></i> Copy
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <span class="empty-note">No payment information recorded.</span>
                        <?php endif; ?>
                    </div>

                    <!-- DISABILITY -->
                    <div class="section-block">
                        <h3><i class="fas fa-wheelchair"></i> Disability</h3>
                        <?php if ($has_dis): ?>
                            <div class="disability-box">
                                <div class="db-title"><i class="fas fa-circle-info"></i> This teacher has a disability</div>
                                <div class="db-text"><?= nl2br(htmlspecialchars($t['disability_details'] ?: '—')); ?></div>
                            </div>
                        <?php else: ?>
                            <span class="empty-note">No disability recorded.</span>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- ACTION ROW -->
                <div class="action-row no-print">
                    <button class="btn print" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <a href="school-edit-teacher.php?id=<?= (int)$t['id']; ?>" class="btn primary">
                        <i class="fas fa-pen"></i> Edit
                    </a>
                    <a href="school-delete-teacher.php?id=<?= (int)$t['id']; ?>" class="btn danger"
                       onclick="return confirm('Delete this teacher? This cannot be undone.');">
                        <i class="fas fa-trash"></i> Delete
                    </a>
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

    // COPY helper
    function copyText(text, chipEl) {
        if (!text) return;
        const done = function () {
            const original = chipEl.innerHTML;
            chipEl.innerHTML = '<i class="fas fa-check"></i> Copied';
            chipEl.style.borderColor = 'var(--success)';
            chipEl.style.color = 'var(--success)';
            setTimeout(function () {
                chipEl.innerHTML = original;
                chipEl.style.borderColor = '';
                chipEl.style.color = '';
            }, 1400);
        };

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done).catch(function () {
                fallbackCopy(text, done);
            });
        } else {
            fallbackCopy(text, done);
        }
    }
    function fallbackCopy(text, cb) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        if (cb) cb();
    }
</script>

</body>
</html>