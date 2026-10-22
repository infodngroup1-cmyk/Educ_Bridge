<?php
session_start();
require_once 'db.php';

/* AUTH */
if (empty($_SESSION["admin_id"]) || empty($_SESSION["admin_verified"])) {
    header("Location: admin-login.php");
    exit;
}

$admin_name = $_SESSION["admin_name"] ?? 'Administrator';
$initials = "";
foreach (explode(" ", trim($admin_name)) as $p) {
    if ($p !== "") { $initials .= strtoupper($p[0]); if (strlen($initials) >= 2) break; }
}
if ($initials === "") $initials = "A";

if (isset($_GET["logout"])) {
    session_unset(); session_destroy();
    header("Location: admin-login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function table_exists($conn, $t) {
    $t = mysqli_real_escape_string($conn, $t);
    $r = @mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
    return $r && mysqli_num_rows($r) > 0;
}
function col_exists($conn, $t, $c) {
    $t = mysqli_real_escape_string($conn, $t);
    $c = mysqli_real_escape_string($conn, $c);
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return $r && mysqli_num_rows($r) > 0;
}
function init_of($n) {
    $o = "";
    foreach (explode(" ", trim((string)$n)) as $p) {
        if ($p !== "") { $o .= strtoupper($p[0]); if (strlen($o) >= 2) break; }
    }
    return $o !== "" ? $o : "T";
}

/*
|--------------------------------------------------------------------------
| PARAMS
|--------------------------------------------------------------------------
*/
$school_id = (int)($_GET['school_id'] ?? 0);
$q         = trim($_GET['q'] ?? '');
$status    = $_GET['status'] ?? 'all';
if (!in_array($status, ['all','active','inactive'], true)) $status = 'all';

/*
|--------------------------------------------------------------------------
| COLUMN DETECTION
|--------------------------------------------------------------------------
*/
$school_name_col = 'school_name';
foreach (['school_name','name','title','school'] as $c) {
    if (col_exists($conn, 'schools', $c)) { $school_name_col = $c; break; }
}
$teacher_name_col = 'name';
foreach (['name','full_name','teacher_name','first_name'] as $c) {
    if (col_exists($conn, 'teachers', $c)) { $teacher_name_col = $c; break; }
}

/*
|--------------------------------------------------------------------------
| LOAD SCHOOL
|--------------------------------------------------------------------------
*/
$school = null;
if ($school_id > 0 && table_exists($conn, 'schools')) {
    $has_code   = col_exists($conn, 'schools', 'school_code');
    $has_admin  = col_exists($conn, 'schools', 'admin_name');
    $has_status = col_exists($conn, 'schools', 'status');
    $has_email  = col_exists($conn, 'schools', 'email');
    $has_phone  = col_exists($conn, 'schools', 'phone');

    $sel = "SELECT id, `{$school_name_col}` AS school_name";
    if ($has_code)   $sel .= ", school_code";
    if ($has_admin)  $sel .= ", admin_name";
    if ($has_email)  $sel .= ", email";
    if ($has_phone)  $sel .= ", phone";
    if ($has_status) $sel .= ", status";
    else             $sel .= ", 'active' AS status";
    $sel .= " FROM schools WHERE id = ? LIMIT 1";

    $stmt = @mysqli_prepare($conn, $sel);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $school_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) $school = $row;
        mysqli_stmt_close($stmt);
    }
}

/*
|--------------------------------------------------------------------------
| LOAD TEACHERS OF THIS SCHOOL
|--------------------------------------------------------------------------
*/
$teachers = [];
if ($school && table_exists($conn, 'teachers') && col_exists($conn, 'teachers', 'school_id')) {
    $has_email = col_exists($conn, 'teachers', 'email');
    $has_phone = col_exists($conn, 'teachers', 'phone');
    $has_stat  = col_exists($conn, 'teachers', 'status');
    $has_lev   = col_exists($conn, 'teachers', 'teaching_level');
    $has_code  = col_exists($conn, 'teachers', 'teacher_code');
    $has_created = col_exists($conn, 'teachers', 'created_at');

    $sel = "SELECT id, `{$teacher_name_col}` AS teacher_name";
    if ($has_code)  $sel .= ", teacher_code";
    if ($has_email) $sel .= ", email";
    if ($has_phone) $sel .= ", phone";
    if ($has_lev)   $sel .= ", teaching_level";
    if ($has_stat)  $sel .= ", status";
    else            $sel .= ", 'active' AS status";
    if ($has_created) $sel .= ", created_at";
    $sel .= " FROM teachers WHERE school_id = ?";

    $params = [$school_id];
    $types  = "i";

    if ($q !== '') {
        $sel .= " AND `{$teacher_name_col}` LIKE ?";
        $params[] = "%{$q}%";
        $types   .= "s";
    }
    if ($status !== 'all' && $has_stat) {
        $sel .= " AND `status` = ?";
        $params[] = $status;
        $types   .= "s";
    }
    $sel .= " ORDER BY `{$teacher_name_col}` ASC";

    $stmt = @mysqli_prepare($conn, $sel);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $teachers[] = $row;
        mysqli_stmt_close($stmt);
    }
}

$total_teachers  = count($teachers);
$active_teachers = 0;
$inactive_teachers = 0;
foreach ($teachers as $t) {
    if (strtolower($t['status'] ?? 'active') === 'active') $active_teachers++;
    else $inactive_teachers++;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>School Teachers | Educ Bridge Admin</title>
<link rel="icon" type="image/png" href="logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root{
        --primary:#2563eb;--primary-dark:#1d4ed8;--primary-light:#dbeafe;
        --success:#10b981;--success-light:rgba(16,185,129,.1);
        --warning:#f59e0b;--warning-light:rgba(245,158,11,.12);
        --danger:#ef4444;--danger-light:rgba(239,68,68,.1);
        --info:#0ea5e9;
        --sidebar-bg:#0f172a;--sidebar-hover:rgba(255,255,255,.06);--sidebar-active:rgba(37,99,235,.22);
        --sidebar-text:#cbd5e1;--sidebar-text-active:#fff;--sidebar-border:rgba(255,255,255,.08);
        --bg-body:#f8fafc;--bg-card:#fff;--bg-alt:#f1f5f9;
        --text-primary:#0f172a;--text-secondary:#475569;--text-muted:#64748b;
        --border-color:#e2e8f0;
        --shadow-sm:0 1px 3px rgba(0,0,0,.06);--shadow-md:0 4px 12px rgba(0,0,0,.07);
        --shadow-lg:0 20px 45px rgba(0,0,0,.08);
        --transition:all .3s cubic-bezier(.4,0,.2,1);
    }
    [data-theme="dark"]{
        --primary:#3b82f6;--primary-dark:#2563eb;--primary-light:#1e3a5f;
        --success:#34d399;--success-light:rgba(16,185,129,.15);
        --warning:#fbbf24;--warning-light:rgba(245,158,11,.18);
        --danger:#f87171;--danger-light:rgba(239,68,68,.15);
        --info:#38bdf8;
        --sidebar-bg:#020617;--sidebar-hover:rgba(255,255,255,.05);--sidebar-active:rgba(59,130,246,.2);
        --sidebar-text:#94a3b8;--sidebar-text-active:#fff;--sidebar-border:rgba(255,255,255,.06);
        --bg-body:#0b1120;--bg-card:#1e293b;--bg-alt:#1a2332;
        --text-primary:#f1f5f9;--text-secondary:#cbd5e1;--text-muted:#94a3b8;
        --border-color:#334155;
        --shadow-sm:0 1px 3px rgba(0,0,0,.3);--shadow-md:0 4px 12px rgba(0,0,0,.4);
        --shadow-lg:0 20px 45px rgba(0,0,0,.5);
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    html,body{min-height:100vh}
    body{font-family:'Inter',sans-serif;background:var(--bg-body);color:var(--text-primary);transition:background .4s ease,color .4s ease;overflow-x:hidden}
    .layout{display:flex;min-height:100vh}

    .sidebar{width:260px;background:var(--sidebar-bg);color:var(--sidebar-text);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;border-right:1px solid var(--sidebar-border);transition:transform .3s ease}
    .sidebar-header{padding:22px 22px 18px;border-bottom:1px solid var(--sidebar-border);display:flex;align-items:center;gap:12px}
    .sidebar-header img{width:42px;height:42px;object-fit:contain;border-radius:50%;border:2px solid rgba(255,255,255,.15);padding:2px;background:#fff}
    .sidebar-brand{font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;color:#fff;line-height:1.2}
    .sidebar-brand span{color:#60a5fa}
    .sidebar-brand small{display:block;font-size:11px;font-weight:500;color:var(--sidebar-text);letter-spacing:.4px;margin-top:2px;text-transform:uppercase}
    .sidebar-nav{flex:1;padding:18px 12px;overflow-y:auto}
    .nav-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--sidebar-text);opacity:.6;padding:12px 14px 8px}
    .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;color:var(--sidebar-text);text-decoration:none;font-size:14px;font-weight:500;border-radius:10px;margin-bottom:4px;transition:var(--transition);position:relative}
    .nav-item i.nav-icon{width:20px;text-align:center;font-size:15px}
    .nav-item:hover{background:var(--sidebar-hover);color:#fff}
    .nav-item:hover i.nav-icon{color:#60a5fa}
    .nav-item.active{background:var(--sidebar-active);color:#fff}
    .nav-item.active i.nav-icon{color:#60a5fa}
    .nav-item.active::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;background:#3b82f6;border-radius:0 4px 4px 0}
    .sidebar-profile{border-top:1px solid var(--sidebar-border);padding:16px 18px;display:flex;align-items:center;gap:12px;background:rgba(255,255,255,.02)}
    .sidebar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-size:15px;font-weight:700;flex-shrink:0;box-shadow:0 4px 12px rgba(59,130,246,.35)}
    .sidebar-profile-info{flex:1;min-width:0}
    .sidebar-profile-info .p-name{font-size:13.5px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-profile-info .p-role{font-size:11.5px;color:var(--sidebar-text);opacity:.75;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-logout{width:34px;height:34px;border-radius:8px;background:rgba(239,68,68,.12);color:#f87171;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:13px;flex-shrink:0;transition:var(--transition)}
    .sidebar-logout:hover{background:#ef4444;color:#fff}

    .main{flex:1;margin-left:260px;min-height:100vh;display:flex;flex-direction:column;transition:margin-left .3s ease}
    .topbar{background:var(--bg-card);border-bottom:1px solid var(--border-color);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50}
    .topbar-left{display:flex;align-items:center;gap:14px}
    .hamburger{display:none;width:40px;height:40px;border-radius:10px;border:1.5px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary);align-items:center;justify-content:center;cursor:pointer;font-size:15px}
    .hamburger:hover{border-color:var(--primary);color:var(--primary)}
    .page-title{font-family:'Poppins',sans-serif;font-size:20px;font-weight:700;letter-spacing:-.4px}
    .page-subtitle{font-size:13px;color:var(--text-muted);margin-top:2px}
    .topbar-actions{display:flex;align-items:center;gap:10px}
    .topbar-btn{width:42px;height:42px;border-radius:50%;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;text-decoration:none;transition:var(--transition)}
    .topbar-btn:hover{border-color:var(--primary);color:var(--primary);transform:translateY(-1px)}
    .topbar-btn.theme-btn:hover{transform:rotate(20deg)}
    .topbar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.3);cursor:pointer}

    .content{padding:28px;flex:1}

    .back-link{display:inline-flex;align-items:center;gap:8px;color:var(--primary);text-decoration:none;font-size:14px;font-weight:600;margin-bottom:18px;transition:var(--transition)}
    .back-link:hover{gap:11px}

    /* School header card */
    .school-hero{
        background:var(--bg-card);border:1px solid var(--border-color);
        border-radius:20px;padding:24px;margin-bottom:22px;
        display:flex;align-items:center;gap:20px;
        box-shadow:var(--shadow-md);position:relative;overflow:hidden;
    }
    .school-hero::before{content:'';position:absolute;left:0;top:0;bottom:0;width:5px;background:var(--success)}
    .school-hero.inactive::before{background:var(--danger)}
    .sh-avatar{width:76px;height:76px;border-radius:20px;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-size:28px;font-weight:700;flex-shrink:0;box-shadow:0 12px 26px rgba(37,99,235,.32)}
    .sh-info{flex:1;min-width:0}
    .sh-title{font-family:'Poppins',sans-serif;font-size:24px;font-weight:700;margin-bottom:8px;letter-spacing:-.4px}
    .sh-meta{display:flex;flex-wrap:wrap;gap:8px;font-size:12.5px;align-items:center}
    .sh-meta .tag{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:50px;background:var(--bg-alt);border:1px solid var(--border-color);font-weight:600;color:var(--text-secondary)}
    .sh-meta .tag.admin-tag{background:rgba(124,58,237,.10);border-color:rgba(124,58,237,.28);color:#7c3aed}
    .sh-meta .tag.green{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.28);color:#059669}
    .sh-meta .tag.red{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.28);color:#dc2626}
    [data-theme="dark"] .sh-meta .tag.admin-tag{color:#a78bfa;background:rgba(124,58,237,.2)}
    [data-theme="dark"] .sh-meta .tag.green{color:#34d399}
    [data-theme="dark"] .sh-meta .tag.red{color:#f87171}
    .sh-meta .tag i{font-size:11px}

    .sh-stats{display:flex;gap:10px;flex-shrink:0;flex-wrap:wrap}
    .sh-stat{
        display:flex;flex-direction:column;align-items:center;gap:2px;
        padding:12px 18px;border-radius:14px;
        background:var(--bg-alt);border:1px solid var(--border-color);
        min-width:90px;
    }
    .sh-stat .num{font-family:'Poppins',sans-serif;font-size:22px;font-weight:800;line-height:1.1}
    .sh-stat .lbl{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--text-muted)}
    .sh-stat.orange .num{color:#f59e0b}
    .sh-stat.green .num{color:var(--success)}
    .sh-stat.red .num{color:var(--danger)}

    /* Filters */
    .filters{background:var(--bg-card);border:1px solid var(--border-color);border-radius:14px;padding:16px 18px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
    .filter-group{position:relative;display:flex;align-items:center;flex:1 1 220px;min-width:180px}
    .filter-group i{position:absolute;left:13px;color:var(--text-muted);font-size:13px;pointer-events:none}
    .filter-group input,.filter-group select{width:100%;padding:11px 14px 11px 36px;border:1.5px solid var(--border-color);border-radius:10px;background:var(--bg-alt);color:var(--text-primary);font-family:inherit;font-size:13.5px;outline:none;transition:var(--transition)}
    .filter-group input:focus,.filter-group select:focus{border-color:var(--primary);background:var(--bg-card);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
    .filter-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:10px;font-size:13.5px;font-weight:600;cursor:pointer;border:none;background:var(--primary);color:#fff;font-family:inherit}
    .filter-btn:hover{background:var(--primary-dark)}
    .filter-reset{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:10px;font-size:13.5px;font-weight:600;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);text-decoration:none}

    /* Teachers list */
    .teachers-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px}
    .teacher-card{
        background:var(--bg-card);border:1px solid var(--border-color);
        border-radius:14px;padding:18px;
        display:flex;align-items:center;gap:14px;
        text-decoration:none;color:inherit;
        transition:var(--transition);position:relative;
    }
    .teacher-card::before{
        content:'';position:absolute;left:0;top:14px;bottom:14px;width:3px;
        background:var(--success);border-radius:0 4px 4px 0;
    }
    .teacher-card.inactive::before{background:var(--danger)}
    .teacher-card:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:var(--shadow-md)}
    .tc-avatar{
        width:52px;height:52px;border-radius:50%;
        background:linear-gradient(135deg,#3b82f6,#8b5cf6);
        color:#fff;display:flex;align-items:center;justify-content:center;
        font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;
        flex-shrink:0;box-shadow:0 6px 16px rgba(59,130,246,.28);
    }
    .tc-info{flex:1;min-width:0}
    .tc-name{font-size:15px;font-weight:700;color:var(--text-primary);margin-bottom:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .tc-meta{display:flex;flex-direction:column;gap:3px;font-size:12.5px;color:var(--text-muted)}
    .tc-meta span{display:inline-flex;align-items:center;gap:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .tc-meta i{width:12px;font-size:11px;color:var(--text-muted)}
    .tc-status{
        display:inline-flex;align-items:center;gap:6px;
        padding:4px 11px;border-radius:50px;
        font-size:11px;font-weight:700;
        background:rgba(16,185,129,.12);color:#059669;
        flex-shrink:0;
    }
    .tc-status .dot{width:6px;height:6px;border-radius:50%;background:var(--success)}
    .tc-status.inactive{background:rgba(239,68,68,.12);color:#dc2626}
    .tc-status.inactive .dot{background:var(--danger)}
    [data-theme="dark"] .tc-status{color:#34d399}
    [data-theme="dark"] .tc-status.inactive{color:#f87171}

    .empty-state{text-align:center;padding:60px 20px;color:var(--text-muted);background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px}
    .empty-state i{font-size:50px;opacity:.35;margin-bottom:14px;display:block}
    .empty-state h3{font-family:'Poppins',sans-serif;font-size:17px;font-weight:700;color:var(--text-primary);margin-bottom:6px}
    .empty-state p{font-size:14px;line-height:1.6;margin-bottom:16px}

    .overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:90;backdrop-filter:blur(2px)}
    .overlay.show{display:block}

    @media (max-width:900px){
        .sidebar{transform:translateX(-100%);box-shadow:0 0 40px rgba(0,0,0,.2)}
        .sidebar.open{transform:translateX(0)}
        .main{margin-left:0}
        .hamburger{display:flex}
        .school-hero{flex-direction:column;align-items:flex-start}
        .sh-stats{width:100%}
    }
    @media (max-width:600px){
        .content{padding:20px 16px}
        .topbar{padding:12px 16px}
        .page-title{font-size:17px}
        .school-hero{padding:18px}
        .sh-avatar{width:60px;height:60px;font-size:22px}
        .sh-title{font-size:19px}
        .teachers-list{grid-template-columns:1fr}
    }
</style>
</head>
<body>

<div class="layout">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="logo.png" alt="Educ Bridge">
            <div class="sidebar-brand">Educ<span>Bridge</span><small>Admin Panel</small></div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section-title">Main</div>
            <a href="admin-dashboard.php" class="nav-item"><i class="fas fa-th-large nav-icon"></i> Dashboard</a>
            <a href="admin-schools.php" class="nav-item"><i class="fas fa-school nav-icon"></i> Schools</a>
            <a href="admin-students.php" class="nav-item"><i class="fas fa-user-graduate nav-icon"></i> Students</a>
            <a href="admin-teachers.php" class="nav-item active"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>

            <div class="nav-section-title">Management</div>
            <a href="admin-school-account.php" class="nav-item"><i class="fas fa-id-card nav-icon"></i> School Accounts</a>
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
            <a href="admin-dashboard.php?logout=1" class="sidebar-logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="overlay" id="overlay"></div>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">School Teachers</div>
                    <div class="page-subtitle"><?= $school ? htmlspecialchars($school['school_name']) : 'School not found'; ?></div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme" title="Toggle theme"><i class="fas fa-moon"></i></button>
                <div class="topbar-avatar" title="<?= htmlspecialchars($admin_name); ?>"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <a href="admin-teachers.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Teachers</a>

            <?php if (!$school): ?>

                <div class="empty-state">
                    <i class="fas fa-school-circle-exclamation"></i>
                    <h3>School not found</h3>
                    <p>The school you are looking for doesn't exist or was removed.</p>
                    <a href="admin-teachers.php" class="filter-btn" style="text-decoration:none;">
                        <i class="fas fa-arrow-left"></i> Back to Teachers
                    </a>
                </div>

            <?php else:

                $sh_init = init_of($school['school_name']);
                $sh_stat = strtolower((string)($school['status'] ?? 'active'));
            ?>

                <!-- School hero -->
                <div class="school-hero <?= $sh_stat === 'active' ? '' : 'inactive'; ?>">
                    <div class="sh-avatar"><?= htmlspecialchars($sh_init); ?></div>
                    <div class="sh-info">
                        <div class="sh-title"><?= htmlspecialchars($school['school_name']); ?></div>
                        <div class="sh-meta">
                            <?php if (!empty($school['school_code'])): ?>
                                <span class="tag"><i class="fas fa-hashtag"></i> <?= htmlspecialchars($school['school_code']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($school['admin_name'])): ?>
                                <span class="tag admin-tag"><i class="fas fa-user-tie"></i> <?= htmlspecialchars($school['admin_name']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($school['email'])): ?>
                                <span class="tag"><i class="fas fa-envelope"></i> <?= htmlspecialchars($school['email']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($school['phone'])): ?>
                                <span class="tag"><i class="fas fa-phone"></i> <?= htmlspecialchars($school['phone']); ?></span>
                            <?php endif; ?>
                            <span class="tag <?= $sh_stat === 'active' ? 'green' : 'red'; ?>">
                                <i class="fas fa-circle"></i> <?= $sh_stat === 'active' ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="sh-stats">
                        <div class="sh-stat orange">
                            <div class="num"><?= $total_teachers; ?></div>
                            <div class="lbl">Teachers</div>
                        </div>
                        <div class="sh-stat green">
                            <div class="num"><?= $active_teachers; ?></div>
                            <div class="lbl">Active</div>
                        </div>
                        <?php if ($inactive_teachers > 0): ?>
                            <div class="sh-stat red">
                                <div class="num"><?= $inactive_teachers; ?></div>
                                <div class="lbl">Inactive</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Filters -->
                <form method="GET" action="admin-school-teachers.php" class="filters">
                    <input type="hidden" name="school_id" value="<?= $school_id; ?>">
                    <div class="filter-group">
                        <i class="fas fa-search"></i>
                        <input type="text" name="q" placeholder="Search teacher name…" value="<?= htmlspecialchars($q); ?>">
                    </div>
                    <div class="filter-group" style="flex:0 1 180px;">
                        <i class="fas fa-toggle-on"></i>
                        <select name="status">
                            <option value="all"      <?= $status === 'all'      ? 'selected' : ''; ?>>All Status</option>
                            <option value="active"   <?= $status === 'active'   ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?= $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Filter</button>
                    <a href="admin-school-teachers.php?school_id=<?= $school_id; ?>" class="filter-reset"><i class="fas fa-rotate"></i> Reset</a>
                </form>

                <!-- Teachers list -->
                <?php if (empty($teachers)): ?>
                    <div class="empty-state">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <h3>No teachers found</h3>
                        <p>
                            <?php if ($q !== '' || $status !== 'all'): ?>
                                No teachers match your filters.
                            <?php else: ?>
                                No teachers have been registered for this school yet.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="teachers-list">
                        <?php foreach ($teachers as $t):
                            $tid   = (int)$t['id'];
                            $tname = $t['teacher_name'] ?? 'Unnamed teacher';
                            $email = $t['email'] ?? '';
                            $phone = $t['phone'] ?? '';
                            $code  = $t['teacher_code'] ?? '';
                            $lev   = $t['teaching_level'] ?? '';
                            $tstat = strtolower((string)($t['status'] ?? 'active'));
                            $is_active = ($tstat === 'active');
                        ?>
                            <a href="admin-teacher-view.php?id=<?= $tid; ?>" class="teacher-card <?= $is_active ? '' : 'inactive'; ?>">
                                <div class="tc-avatar"><?= htmlspecialchars(init_of($tname)); ?></div>
                                <div class="tc-info">
                                    <div class="tc-name"><?= htmlspecialchars($tname); ?></div>
                                    <div class="tc-meta">
                                        <?php if ($code): ?>
                                            <span><i class="fas fa-hashtag"></i> <?= htmlspecialchars($code); ?></span>
                                        <?php endif; ?>
                                        <?php if ($email): ?>
                                            <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($email); ?></span>
                                        <?php endif; ?>
                                        <?php if ($phone): ?>
                                            <span><i class="fas fa-phone"></i> <?= htmlspecialchars($phone); ?></span>
                                        <?php endif; ?>
                                        <?php if ($lev): ?>
                                            <span><i class="fas fa-graduation-cap"></i> <?= htmlspecialchars($lev); ?></span>
                                        <?php endif; ?>
                                        <?php if (!$code && !$email && !$phone && !$lev): ?>
                                            <span><i class="fas fa-info-circle"></i> No additional info</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="tc-status <?= $is_active ? '' : 'inactive'; ?>">
                                    <span class="dot"></span>
                                    <?= $is_active ? 'Active' : 'Inactive'; ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php endif; ?>

        </main>
    </div>
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

    (function () {
        const h = document.getElementById('hamburger');
        const s = document.getElementById('sidebar');
        const o = document.getElementById('overlay');
        h.addEventListener('click', function () {
            s.classList.toggle('open');
            o.classList.toggle('show', s.classList.contains('open'));
        });
        o.addEventListener('click', function () {
            s.classList.remove('open');
            o.classList.remove('show');
        });
    })();
</script>

</body>
</html>