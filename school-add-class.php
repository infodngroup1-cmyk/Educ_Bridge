<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['school_id']) || empty($_SESSION['school_verified'])) {
    header("Location: school-login.php");
    exit;
}

$school_id    = (int) $_SESSION['school_id'];
$school_name  = $_SESSION['school_name']  ?? 'School';
$school_admin = $_SESSION['school_admin'] ?? 'Administrator';

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
| AUTO-CREATE / MIGRATE classes TABLE
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS classes (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    school_id        INT NOT NULL,
    class_name       VARCHAR(120) NOT NULL,
    level            ENUM('Nursery','Primary','O_Level','A_Level') NOT NULL,
    capacity         INT DEFAULT NULL,
    room             VARCHAR(80) DEFAULT NULL,
    class_teacher_id INT DEFAULT NULL,
    status           ENUM('active','inactive') DEFAULT 'active',
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_classes_school (school_id),
    KEY idx_classes_level  (school_id, level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* safe migrations */
@mysqli_query($conn, "ALTER TABLE classes ADD COLUMN room VARCHAR(80) DEFAULT NULL AFTER capacity");
@mysqli_query($conn, "ALTER TABLE classes ADD COLUMN class_teacher_id INT DEFAULT NULL AFTER room");

/*
|--------------------------------------------------------------------------
| FETCH ACTIVE ACADEMIC LEVELS
|--------------------------------------------------------------------------
*/
$active_levels = [];
$l_stmt = mysqli_prepare($conn, "
    SELECT level_key, level_name
    FROM academic_levels
    WHERE school_id = ? AND is_active = 1
    ORDER BY FIELD(level_key, 'nursery','primary','olevel','alevel')
");
if ($l_stmt) {
    mysqli_stmt_bind_param($l_stmt, "i", $school_id);
    mysqli_stmt_execute($l_stmt);
    $l_res = mysqli_stmt_get_result($l_stmt);
    while ($row = mysqli_fetch_assoc($l_res)) {
        $active_levels[$row['level_key']] = $row['level_name'];
    }
    mysqli_stmt_close($l_stmt);
}

$LEVEL_KEY_TO_ENUM = [
    'nursery' => 'Nursery',
    'primary' => 'Primary',
    'olevel'  => 'O_Level',
    'alevel'  => 'A_Level',
];

/*
|--------------------------------------------------------------------------
| FETCH TEACHERS
|--------------------------------------------------------------------------
*/
$teachers = [];
$t_stmt = mysqli_prepare($conn, "SELECT id, name FROM teachers WHERE school_id = ? AND status = 'active' ORDER BY name ASC");
if ($t_stmt) {
    mysqli_stmt_bind_param($t_stmt, "i", $school_id);
    mysqli_stmt_execute($t_stmt);
    $t_res = mysqli_stmt_get_result($t_stmt);
    while ($t = mysqli_fetch_assoc($t_res)) $teachers[] = $t;
    mysqli_stmt_close($t_stmt);
}

/*
|--------------------------------------------------------------------------
| PRESET CLASS NAME SUGGESTIONS
|--------------------------------------------------------------------------
*/
$preset_class_names = [
    'Nursery' => ['Nursery 1', 'Nursery 2', 'Nursery 3'],
    'Primary' => ['Primary 1', 'Primary 2', 'Primary 3', 'Primary 4', 'Primary 5', 'Primary 6'],
    'O_Level' => ['Senior 1', 'Senior 2', 'Senior 3'],
    'A_Level' => ['Senior 4', 'Senior 5', 'Senior 6', 'MCE', 'PCB', 'MCB', 'HEG', 'HGL', 'MPC'],
];

$existing_names = [];
$n_stmt = mysqli_prepare($conn, "SELECT DISTINCT class_name, level FROM classes WHERE school_id = ? ORDER BY class_name ASC");
if ($n_stmt) {
    mysqli_stmt_bind_param($n_stmt, "i", $school_id);
    mysqli_stmt_execute($n_stmt);
    $n_res = mysqli_stmt_get_result($n_stmt);
    while ($row = mysqli_fetch_assoc($n_res)) $existing_names[$row['level']][] = $row['class_name'];
    mysqli_stmt_close($n_stmt);
}

/*
|--------------------------------------------------------------------------
| DEFAULTS
|--------------------------------------------------------------------------
*/
$f = [
    'level'            => '',
    'class_name'       => '',
    'capacity'         => '',
    'room'             => '',
    'class_teacher_id' => '',
    'status'           => 'active',
];

$message = ""; $message_type = "";
if (isset($_GET['error'])) { $message = urldecode($_GET['error']); $message_type = "error"; }

/*
|--------------------------------------------------------------------------
| HANDLE SUBMIT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $f['level']            = trim($_POST['level'] ?? '');
    $f['class_name']       = trim($_POST['class_name'] ?? '');
    $f['capacity']         = trim($_POST['capacity'] ?? '');
    $f['room']             = trim($_POST['room'] ?? '');
    $f['class_teacher_id'] = trim($_POST['class_teacher_id'] ?? '');
    $f['status']           = trim($_POST['status'] ?? 'active');

    $errors = [];

    if (!in_array($f['level'], ['Nursery','Primary','O_Level','A_Level'], true)) {
        $errors[] = "Please select a valid level.";
    }
    if ($f['class_name'] === '') $errors[] = "Class name is required.";
    if (mb_strlen($f['class_name']) > 120) $errors[] = "Class name is too long (max 120 characters).";
    if ($f['capacity'] !== '' && (!ctype_digit($f['capacity']) || (int)$f['capacity'] < 1 || (int)$f['capacity'] > 500)) {
        $errors[] = "Capacity must be a number between 1 and 500 (or leave empty).";
    }
    if (!in_array($f['status'], ['active','inactive'], true)) {
        $errors[] = "Please select a valid status.";
    }

    // Duplicate check
    if (empty($errors)) {
        $chk = mysqli_prepare($conn, "SELECT id FROM classes WHERE school_id = ? AND class_name = ? AND level = ? LIMIT 1");
        mysqli_stmt_bind_param($chk, "iss", $school_id, $f['class_name'], $f['level']);
        mysqli_stmt_execute($chk);
        $chk_res = mysqli_stmt_get_result($chk);
        if (mysqli_num_rows($chk_res) > 0) {
            $errors[] = "A class named “{$f['class_name']}” already exists in {$f['level']}.";
        }
        mysqli_stmt_close($chk);
    }

    if (empty($errors)) {
        $capacity   = ($f['capacity'] === '') ? null : (int)$f['capacity'];
        $room       = ($f['room'] === '') ? null : $f['room'];
        $teacher_id = ($f['class_teacher_id'] === '') ? null : (int)$f['class_teacher_id'];

        $ins = mysqli_prepare($conn, "
            INSERT INTO classes (school_id, level, class_name, capacity, room, class_teacher_id, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$ins) {
            $message = "Database error: " . mysqli_error($conn);
            $message_type = "error";
        } else {
            mysqli_stmt_bind_param($ins, "issdisi",
                $school_id, $f['level'], $f['class_name'],
                $capacity, $room, $teacher_id, $f['status']
            );
            if (mysqli_stmt_execute($ins)) {
                mysqli_stmt_close($ins);
                header("Location: school-classes.php?added=1");
                exit;
            } else {
                $message = "Could not save class: " . mysqli_error($conn);
                $message_type = "error";
                mysqli_stmt_close($ins);
            }
        }
    } else {
        $message = implode(" ", $errors);
        $message_type = "error";
    }
}

$preset_json   = json_encode($preset_class_names, JSON_UNESCAPED_UNICODE);
$existing_json = json_encode($existing_names, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Class | Educ Bridge</title>
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
            --sidebar-bg: #ffffff; --sidebar-hover: rgba(37,99,235,0.06);
            --sidebar-active: rgba(37,99,235,0.10); --sidebar-text: #475569;
            --sidebar-text-active: #0f172a; --sidebar-border: #e2e8f0; --sidebar-title: #94a3b8;
            --bg-body: #f8fafc; --bg-card: #ffffff; --bg-alt: #f1f5f9;
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
            --sidebar-bg: #0f172a; --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(59,130,246,0.18); --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff; --sidebar-border: rgba(255,255,255,0.08); --sidebar-title: #94a3b8;
            --bg-body: #0b1120; --bg-card: #1e293b; --bg-alt: #1a2332;
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
        .topbar-btn { width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); text-decoration:none; }
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

        .card { max-width:780px; margin:0 auto; background:var(--bg-card); border:1px solid var(--border-color); border-radius:20px; box-shadow:var(--shadow-lg); overflow:hidden; }
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

        .input-icon { position:relative; }
        .input-icon > i { position:absolute; left:15px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:14px; pointer-events:none; }
        .input-icon input, .input-icon select { padding-left:42px; }

        .form-group input, .form-group select { width:100%; padding:13px 15px; border:1.5px solid var(--border-color); border-radius:10px; background:var(--bg-alt); color:var(--text-primary); font-family:'Inter',sans-serif; font-size:14.5px; outline:none; transition:var(--transition); }
        .form-group select { cursor:pointer; }
        .form-group input:focus, .form-group select:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,0.1); background:var(--bg-card); }
        .form-help { font-size:12.5px; color:var(--text-muted); margin-top:6px; line-height:1.5; }

        .radio-pills { display:flex; flex-wrap:wrap; gap:10px; }
        .radio-pill { position:relative; display:inline-flex; align-items:center; gap:8px; padding:12px 18px; border:1.5px solid var(--border-color); border-radius:10px; cursor:pointer; transition:var(--transition); background:var(--bg-alt); font-size:13.5px; font-weight:600; color:var(--text-primary); }
        .radio-pill input { position:absolute; opacity:0; pointer-events:none; }
        .radio-pill .dot { width:16px; height:16px; border-radius:50%; border:2px solid var(--border-color); position:relative; transition:var(--transition); flex-shrink:0; }
        .radio-pill .dot::after { content:''; position:absolute; inset:3px; border-radius:50%; background:var(--primary); opacity:0; transform:scale(0.4); transition:var(--transition); }
        .radio-pill:hover { border-color:var(--primary); }
        .radio-pill.selected { border-color:var(--primary); background:var(--primary-light); }
        .radio-pill.selected .dot { border-color:var(--primary); }
        .radio-pill.selected .dot::after { opacity:1; transform:scale(1); }
        .radio-pill i { color:var(--text-muted); font-size:14px; transition:var(--transition); }
        .radio-pill.selected i { color:var(--primary); }

        .no-levels {
            padding:16px 18px; background:var(--warning-light);
            border:1px solid rgba(245,158,11,0.35); border-radius:12px;
            color:#b45309; font-size:13.5px; line-height:1.6;
            display:flex; align-items:flex-start; gap:12px; margin-bottom:18px;
        }
        [data-theme="dark"] .no-levels { color:#fbbf24; }
        .no-levels i { font-size:18px; flex-shrink:0; margin-top:2px; }
        .no-levels a { color:inherit; font-weight:700; text-decoration:underline; }

        .preview-card {
            margin-top:14px; padding:14px 18px;
            background:linear-gradient(135deg, rgba(37,99,235,0.05), rgba(124,58,237,0.03));
            border:1px dashed rgba(37,99,235,0.30); border-radius:12px;
            display:flex; align-items:center; gap:14px; flex-wrap:wrap;
        }
        .preview-card .pc-icon { width:44px; height:44px; border-radius:12px; background:var(--primary); color:#fff; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; box-shadow:0 6px 14px rgba(37,99,235,0.30); }
        .preview-card .pc-info { flex:1; min-width:0; }
        .preview-card .pc-info .pc-title { font-size:11.5px; text-transform:uppercase; letter-spacing:0.6px; color:var(--text-muted); font-weight:700; }
        .preview-card .pc-info .pc-name { font-family:'Poppins',sans-serif; font-size:17px; font-weight:700; color:var(--text-primary); margin-top:3px; }
        .preview-card .pc-info .pc-sub { font-size:12.5px; color:var(--text-muted); margin-top:3px; }
        .preview-card.empty .pc-icon { background:var(--bg-alt); color:var(--text-muted); box-shadow:none; }

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
            <a href="school-teachers.php" class="nav-item"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>
            <a href="school-classes.php" class="nav-item active"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
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
                    <div class="page-title">Create New Class</div>
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

            <a href="school-classes.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Classes</a>

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-plus-square"></i> Create New Class</h2>
                    <p>Pick a level, type the class name, and save. Details below are optional.</p>
                </div>
                <div class="card-body">

                    <?php if (empty($active_levels)): ?>
                        <div class="no-levels">
                            <i class="fas fa-triangle-exclamation"></i>
                            <div>
                                <strong>No academic levels are active.</strong><br>
                                You must add at least one level before you can create classes.
                                Go to <a href="school-academic-levels.php">Academic Levels</a> and add a level first.
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="school-add-class.php" id="classForm" autocomplete="off">

                        <div class="section-title"><i class="fas fa-layer-group"></i> Class Information</div>

                        <div class="form-group">
                            <label>Academic Level <span class="required">*</span></label>
                            <div class="radio-pills" data-group="level">
                                <?php if (empty($active_levels)): ?>
                                    <span style="color:var(--text-muted);font-size:13.5px;">No active levels available.</span>
                                <?php else: ?>
                                    <?php
                                        $level_icons = [
                                            'nursery' => 'fa-baby',
                                            'primary' => 'fa-child',
                                            'olevel'  => 'fa-book-open',
                                            'alevel'  => 'fa-graduation-cap',
                                        ];
                                        foreach ($active_levels as $key => $label):
                                            $enum_val = $LEVEL_KEY_TO_ENUM[$key] ?? '';
                                            if ($enum_val === '') continue;
                                            $checked = $f['level'] === $enum_val;
                                    ?>
                                        <label class="radio-pill <?= $checked ? 'selected' : ''; ?>">
                                            <input type="radio" name="level" value="<?= htmlspecialchars($enum_val); ?>" <?= $checked ? 'checked' : ''; ?> required>
                                            <span class="dot"></span>
                                            <i class="fas <?= $level_icons[$key] ?? 'fa-layer-group'; ?>"></i>
                                            <?= htmlspecialchars($label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="class_name">Class Name <span class="required">*</span></label>
                            <div class="input-icon">
                                <i class="fas fa-tag"></i>
                                <input type="text" id="class_name" name="class_name" maxlength="120"
                                       list="classNameSuggest"
                                       placeholder="e.g. Senior 1, MCE, Primary 1"
                                       value="<?= htmlspecialchars($f['class_name']); ?>"
                                       autocomplete="off" required <?= empty($active_levels) ? 'disabled' : ''; ?>>
                                <datalist id="classNameSuggest"></datalist>
                            </div>
                            <div class="form-help">Type any name — suggestions appear based on the selected level.</div>
                        </div>

                        <div class="preview-card empty" id="previewCard">
                            <div class="pc-icon"><i class="fas fa-eye"></i></div>
                            <div class="pc-info">
                                <div class="pc-title">Preview</div>
                                <div class="pc-name" id="previewName">—</div>
                                <div class="pc-sub" id="previewSub">Pick a level and type a class name to see the preview.</div>
                            </div>
                        </div>

                        <div class="section-title" style="margin-top:20px;">
                            <i class="fas fa-users"></i> Optional Details
                        </div>

                        <div class="form-group">
                            <label for="class_teacher_id">Class Teacher <span class="opt">(optional)</span></label>
                            <div class="input-icon">
                                <i class="fas fa-chalkboard-teacher"></i>
                                <select id="class_teacher_id" name="class_teacher_id">
                                    <option value="">— Unassigned —</option>
                                    <?php foreach ($teachers as $t): ?>
                                        <option value="<?= (int)$t['id']; ?>" <?= (string)$f['class_teacher_id'] === (string)$t['id'] ? 'selected' : ''; ?>>
                                            <?= htmlspecialchars($t['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if (empty($teachers)): ?>
                                <div class="form-help">No active teachers yet. Add them from the <a href="school-teachers.php" style="color:var(--primary);font-weight:700;text-decoration:none;">Teachers</a> page.</div>
                            <?php endif; ?>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="capacity">Capacity <span class="opt">(optional)</span></label>
                                <div class="input-icon">
                                    <i class="fas fa-users"></i>
                                    <input type="number" id="capacity" name="capacity" min="1" max="500"
                                           placeholder="e.g. 40"
                                           value="<?= htmlspecialchars($f['capacity']); ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="room">Room / Location <span class="opt">(optional)</span></label>
                                <div class="input-icon">
                                    <i class="fas fa-door-open"></i>
                                    <input type="text" id="room" name="room" maxlength="80"
                                           placeholder="e.g. Block B, Room 12"
                                           value="<?= htmlspecialchars($f['room']); ?>">
                                </div>
                            </div>
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
                            <a href="school-classes.php" class="btn"><i class="fas fa-times"></i> Cancel</a>
                            <button type="submit" class="btn primary" id="saveBtn" <?= empty($active_levels) ? 'disabled' : ''; ?>>
                                <i class="fas fa-save"></i> Create Class
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
        const sidebar   = document.getElementById('sidebar');
        const overlay   = document.getElementById('overlay');
        function openSidebar()  { sidebar.classList.add('open');  overlay.classList.add('show'); }
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
                if (group.dataset.group === 'level') {
                    refreshClassSuggestions();
                    updatePreview();
                }
            });
        });
    });

    const PRESET_CLASS_NAMES   = <?= $preset_json; ?>;
    const EXISTING_CLASS_NAMES = <?= $existing_json; ?>;

    const classInput   = document.getElementById('class_name');
    const classSuggest = document.getElementById('classNameSuggest');
    const previewCard  = document.getElementById('previewCard');
    const previewName  = document.getElementById('previewName');
    const previewSub   = document.getElementById('previewSub');

    function getSelectedLevel() {
        const el = document.querySelector('input[name="level"]:checked');
        return el ? el.value : '';
    }
    function levelLabel(lvl) {
        if (lvl === 'O_Level') return "O' Level";
        if (lvl === 'A_Level') return "A' Level";
        return lvl;
    }
    function refreshClassSuggestions() {
        const lvl = getSelectedLevel();
        const preset   = PRESET_CLASS_NAMES[lvl]  || [];
        const existing = EXISTING_CLASS_NAMES[lvl] || [];
        const merged = []; const seen = new Set();
        preset.concat(existing).forEach(function (v) {
            const s = String(v).trim();
            if (s !== '' && !seen.has(s)) { seen.add(s); merged.push(s); }
        });
        classSuggest.innerHTML = '';
        merged.forEach(function (v) {
            const o = document.createElement('option');
            o.value = v;
            classSuggest.appendChild(o);
        });
    }
    function updatePreview() {
        const lvl  = getSelectedLevel();
        const name = classInput ? classInput.value.trim() : '';
        if (!lvl && !name) {
            previewCard.classList.add('empty');
            previewName.textContent = '—';
            previewSub.textContent = 'Pick a level and type a class name to see the preview.';
            return;
        }
        previewCard.classList.remove('empty');
        const parts = [];
        if (name) parts.push(name);
        if (lvl)  parts.push(levelLabel(lvl));
        previewName.textContent = parts.join(' · ') || '—';
        if (!lvl) previewSub.textContent = 'Select a level to complete the class.';
        else if (!name) previewSub.textContent = 'Type a class name (e.g. "Senior 1", "MCE").';
        else previewSub.textContent = 'Ready to create — click the button below.';
    }
    if (classInput) {
        classInput.addEventListener('input', updatePreview);
        classInput.addEventListener('change', updatePreview);
    }
    refreshClassSuggestions();
    updatePreview();

    const classForm = document.getElementById('classForm');
    if (classForm) {
        classForm.addEventListener('submit', function () {
            const btn = document.getElementById('saveBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
        });
    }
</script>

</body>
</html>