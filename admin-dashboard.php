<?php
session_start();
require_once 'db.php';

/* AUTH */
if (empty($_SESSION["admin_id"]) || empty($_SESSION["admin_verified"])) {
    header("Location: admin-login.php");
    exit;
}

$admin_id    = (int) $_SESSION["admin_id"];
$admin_name  = $_SESSION["admin_name"]  ?? 'Administrator';
$admin_email = $_SESSION["admin_email"] ?? '';

$initials = "";
foreach (explode(" ", trim($admin_name)) as $part) {
    if ($part !== "") { $initials .= strtoupper($part[0]); if (strlen($initials) >= 2) break; }
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
function safe_count($conn, $sql) {
    $r = @mysqli_query($conn, $sql);
    if (!$r) return 0;
    $row = mysqli_fetch_assoc($r);
    return (int)($row['total'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| STATS
|--------------------------------------------------------------------------
*/
$schools_count  = table_exists($conn, 'schools')  ? safe_count($conn, "SELECT COUNT(*) AS total FROM schools")  : 0;
$active_schools = (table_exists($conn, 'schools') && col_exists($conn, 'schools', 'status'))
                  ? safe_count($conn, "SELECT COUNT(*) AS total FROM schools WHERE status = 'active'")
                  : $schools_count;

$students_count = table_exists($conn, 'students') ? safe_count($conn, "SELECT COUNT(*) AS total FROM students") : 0;
$teachers_count = table_exists($conn, 'teachers') ? safe_count($conn, "SELECT COUNT(*) AS total FROM teachers") : 0;

$announcements_count = table_exists($conn, 'admin_announcements') ? safe_count($conn, "SELECT COUNT(*) AS total FROM admin_announcements") : 0;
$events_count        = table_exists($conn, 'admin_events')        ? safe_count($conn, "SELECT COUNT(*) AS total FROM admin_events")        : 0;

$avg_students_per_school = $schools_count > 0 ? round($students_count / $schools_count, 1) : 0;
$avg_teachers_per_school = $schools_count > 0 ? round($teachers_count / $schools_count, 1) : 0;

/*
|--------------------------------------------------------------------------
| SCHOOLS BY MONTH — last 12 months (for chart)
|--------------------------------------------------------------------------
*/
$labels_month     = [];
$schools_by_month = [];
for ($i = 11; $i >= 0; $i--) $labels_month[] = date('M Y', strtotime("-$i months"));

if (table_exists($conn, 'schools')) {
    $date_col = null;
    foreach (['created_at','created','registered_at'] as $c) {
        if (col_exists($conn, 'schools', $c)) { $date_col = $c; break; }
    }
    if ($date_col) {
        $res = mysqli_query($conn, "
            SELECT DATE_FORMAT(`{$date_col}`, '%b %Y') AS label, COUNT(*) AS total
            FROM schools
            WHERE `{$date_col}` >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY YEAR(`{$date_col}`), MONTH(`{$date_col}`)
        ");
        $map = [];
        if ($res) { while ($r = mysqli_fetch_assoc($res)) $map[$r['label']] = (int)$r['total']; }
        foreach ($labels_month as $lbl) $schools_by_month[] = $map[$lbl] ?? 0;
    } else {
        $schools_by_month = array_fill(0, 12, 0);
    }
} else {
    $schools_by_month = array_fill(0, 12, 0);
}

/*
|--------------------------------------------------------------------------
| RECENT SCHOOLS
|--------------------------------------------------------------------------
*/
$recent_schools = null;
if (table_exists($conn, 'schools')) {
    $name_col = 'school_name';
    foreach (['school_name','name','title','school'] as $c) {
        if (col_exists($conn, 'schools', $c)) { $name_col = $c; break; }
    }
    $order_col = null;
    foreach (['created_at','created','registered_at'] as $c) {
        if (col_exists($conn, 'schools', $c)) { $order_col = $c; break; }
    }
    $sql = "SELECT id, `{$name_col}` AS school_name";
    if ($order_col) $sql .= ", `{$order_col}` AS created_at";
    $sql .= " FROM schools";
    if ($order_col) $sql .= " ORDER BY `{$order_col}` DESC";
    $sql .= " LIMIT 5";
    $recent_schools = mysqli_query($conn, $sql);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard | Educ Bridge</title>
<link rel="icon" type="image/png" href="logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root {
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
    [data-theme="dark"] {
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

    /* SIDEBAR */
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

    /* MAIN */
    .main{flex:1;margin-left:260px;min-height:100vh;display:flex;flex-direction:column;transition:margin-left .3s ease}
    .topbar{background:var(--bg-card);border-bottom:1px solid var(--border-color);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50}
    .topbar-left{display:flex;align-items:center;gap:14px}
    .hamburger{display:none;width:40px;height:40px;border-radius:10px;border:1.5px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary);align-items:center;justify-content:center;cursor:pointer;font-size:15px}
    .hamburger:hover{border-color:var(--primary);color:var(--primary)}
    .page-title{font-family:'Poppins',sans-serif;font-size:20px;font-weight:700;color:var(--text-primary);letter-spacing:-.4px}
    .page-subtitle{font-size:13px;color:var(--text-muted);margin-top:2px}
    .topbar-actions{display:flex;align-items:center;gap:10px}
    .topbar-btn{width:42px;height:42px;border-radius:50%;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;transition:var(--transition);text-decoration:none}
    .topbar-btn:hover{border-color:var(--primary);color:var(--primary);transform:translateY(-1px)}
    .topbar-btn.theme-btn:hover{transform:rotate(20deg)}
    .topbar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.3);cursor:pointer}

    .content{padding:28px;flex:1}

    /* WELCOME */
    .welcome-banner{background:linear-gradient(135deg,#2563eb,#7c3aed);border-radius:18px;padding:32px 36px;color:#fff;margin-bottom:28px;position:relative;overflow:hidden;box-shadow:0 12px 36px rgba(37,99,235,.25)}
    .welcome-banner::before{content:'';position:absolute;top:-50%;right:-10%;width:320px;height:320px;background:radial-gradient(circle,rgba(255,255,255,.15) 0%,transparent 70%);border-radius:50%}
    .welcome-banner::after{content:'';position:absolute;bottom:-60%;left:20%;width:260px;height:260px;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);border-radius:50%}
    .welcome-banner h2{font-family:'Poppins',sans-serif;font-size:26px;font-weight:700;margin-bottom:8px;position:relative;z-index:1;letter-spacing:-.5px}
    .welcome-banner p{font-size:15px;opacity:.92;position:relative;z-index:1;max-width:620px}

    /* KPI */
    .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px;margin-bottom:28px}
    .stat-card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;padding:22px;display:flex;align-items:center;gap:16px;transition:var(--transition);text-decoration:none;color:inherit;position:relative;overflow:hidden}
    .stat-card::after{content:'';position:absolute;top:0;left:0;bottom:0;width:4px;background:var(--primary);opacity:0;transition:var(--transition)}
    .stat-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-md);border-color:var(--primary)}
    .stat-card:hover::after{opacity:1}
    .stat-icon{width:54px;height:54px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0}
    .stat-icon.green{background:linear-gradient(135deg,#10b981,#34d399)}
    .stat-icon.purple{background:linear-gradient(135deg,#7c3aed,#a78bfa)}
    .stat-icon.orange{background:linear-gradient(135deg,#f59e0b,#fbbf24)}
    .stat-info{flex:1;min-width:0}
    .stat-info .num{font-family:'Poppins',sans-serif;font-size:26px;font-weight:700;line-height:1.2;letter-spacing:-.5px}
    .stat-info .lbl{font-size:13px;color:var(--text-muted);margin-top:3px;font-weight:500}

    /* Cards */
    .card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;overflow:hidden;margin-bottom:22px}
    .card-head{padding:18px 22px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
    .card-head h3{font-family:'Poppins',sans-serif;font-size:16px;font-weight:700;display:flex;align-items:center;gap:10px}
    .card-head h3 i{color:var(--primary)}
    .card-body{padding:22px}
    .card-link{font-size:13px;color:var(--primary);text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:6px;transition:var(--transition)}
    .card-link:hover{color:var(--primary-dark);gap:9px}

    .two-col{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:22px}
    @media (max-width:1000px){.two-col{grid-template-columns:1fr}}

    /* School list */
    .school-mini{display:flex;align-items:center;gap:12px;padding:12px 14px;border-radius:10px;background:var(--bg-alt);border:1px solid var(--border-color);text-decoration:none;color:inherit;transition:var(--transition);margin-bottom:8px}
    .school-mini:last-child{margin-bottom:0}
    .school-mini:hover{border-color:var(--primary);transform:translateX(4px)}
    .school-mini .sm-avatar{width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-size:13px;font-weight:700;flex-shrink:0}
    .school-mini .sm-info{flex:1;min-width:0}
    .school-mini .sm-name{font-size:13.5px;font-weight:700}
    .school-mini .sm-meta{font-size:11.5px;color:var(--text-muted);margin-top:2px}

    /* Quick actions */
    .quick-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}
    .quick-btn{display:flex;align-items:center;gap:12px;padding:14px 16px;background:var(--bg-alt);border:1.5px solid var(--border-color);border-radius:12px;text-decoration:none;color:var(--text-primary);font-size:13.5px;font-weight:600;transition:var(--transition)}
    .quick-btn i{width:36px;height:36px;border-radius:10px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;transition:var(--transition)}
    .quick-btn:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:var(--shadow-md)}
    .quick-btn:hover i{background:var(--primary);color:#fff}

    /* Chart */
    .chart-wrap{position:relative;height:280px}

    /* Empty state */
    .empty-state{text-align:center;padding:30px 16px;color:var(--text-muted)}
    .empty-state i{font-size:42px;opacity:.35;margin-bottom:12px;display:block}
    .empty-state p{font-size:14px;line-height:1.6}

    /* Overlay */
    .overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:90;backdrop-filter:blur(2px)}
    .overlay.show{display:block}

    @media (max-width:900px){
        .sidebar{transform:translateX(-100%);box-shadow:0 0 40px rgba(0,0,0,.2)}
        .sidebar.open{transform:translateX(0)}
        .main{margin-left:0}
        .hamburger{display:flex}
    }
    @media (max-width:600px){
        .content{padding:20px 16px}
        .topbar{padding:12px 16px}
        .page-title{font-size:17px}
        .welcome-banner{padding:24px 22px}
        .welcome-banner h2{font-size:20px}
        .welcome-banner p{font-size:14px}
        .stat-card{padding:18px}
        .stat-icon{width:46px;height:46px;font-size:19px}
        .stat-info .num{font-size:22px}
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
            <a href="admin-dashboard.php" class="nav-item active"><i class="fas fa-th-large nav-icon"></i> Dashboard</a>
            <a href="admin-schools.php" class="nav-item"><i class="fas fa-school nav-icon"></i> Schools</a>
            <a href="admin-students.php" class="nav-item"><i class="fas fa-user-graduate nav-icon"></i> Students</a>
            <a href="admin-teachers.php" class="nav-item"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>

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
            <a href="admin-dashboard.php?logout=1" class="sidebar-logout" title="Logout" aria-label="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="overlay" id="overlay"></div>

    <div class="main">

        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Dashboard</div>
                    <div class="page-subtitle">Welcome back to Educ Bridge Admin</div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme" title="Toggle theme"><i class="fas fa-moon"></i></button>
                <div class="topbar-avatar" title="<?= htmlspecialchars($admin_name); ?>"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <!-- Welcome banner -->
            <div class="welcome-banner">
                <h2>Hello, <?= htmlspecialchars($admin_name); ?> 👋</h2>
                <p>You are signed in as an Educ Bridge administrator. Here's a quick overview of your system today.</p>
            </div>

            <!-- KPI CARDS — only 3 -->
            <div class="stats-grid">
                <a href="admin-schools.php" class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-school"></i></div>
                    <div class="stat-info">
                        <div class="num"><?= number_format($schools_count); ?></div>
                        <div class="lbl">Registered Schools</div>
                    </div>
                </a>
                <a href="admin-students.php" class="stat-card">
                    <div class="stat-icon purple"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-info">
                        <div class="num"><?= number_format($students_count); ?></div>
                        <div class="lbl">Students</div>
                    </div>
                </a>
                <a href="admin-teachers.php" class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="stat-info">
                        <div class="num"><?= number_format($teachers_count); ?></div>
                        <div class="lbl">Teachers</div>
                    </div>
                </a>
            </div>

            <!-- TWO COLUMNS: Growth chart + Recent schools -->
            <div class="two-col">

                <div class="card" style="margin-bottom:0;">
                    <div class="card-head">
                        <h3><i class="fas fa-chart-line"></i> School Growth</h3>
                        <span style="font-size:12.5px;color:var(--text-muted);">Last 12 months</span>
                    </div>
                    <div class="card-body">
                        <div class="chart-wrap">
                            <canvas id="growthChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-bottom:0;">
                    <div class="card-head">
                        <h3><i class="fas fa-school"></i> Recently Registered Schools</h3>
                        <a href="admin-schools.php" class="card-link">Manage <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="card-body">
                        <?php if ($recent_schools && mysqli_num_rows($recent_schools) > 0): ?>
                            <?php while ($s = mysqli_fetch_assoc($recent_schools)):
                                $s_init = "";
                                foreach (explode(" ", trim($s['school_name'])) as $p) {
                                    if ($p !== "") { $s_init .= strtoupper($p[0]); if (strlen($s_init) >= 2) break; }
                                }
                                if ($s_init === "") $s_init = "S";
                            ?>
                                <a href="admin-school-view.php?id=<?= (int)$s['id']; ?>" class="school-mini">
                                    <div class="sm-avatar"><?= htmlspecialchars($s_init); ?></div>
                                    <div class="sm-info">
                                        <div class="sm-name"><?= htmlspecialchars($s['school_name']); ?></div>
                                        <div class="sm-meta">
                                            <?php if (!empty($s['created_at'])): ?>
                                                <i class="far fa-clock"></i> <?= date('M j, Y', strtotime($s['created_at'])); ?>
                                            <?php else: ?>
                                                Registered school
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <i class="fas fa-chevron-right" style="color:var(--text-muted);font-size:12px;"></i>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-school"></i>
                                <p>No schools registered yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- Averages -->
            <div class="card">
                <div class="card-head">
                    <h3><i class="fas fa-scale-balanced"></i> Platform Averages</h3>
                </div>
                <div class="card-body">
                    <div class="two-col" style="margin-bottom:0;gap:28px;">

                        <div>
                            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                                <strong>Students per school</strong>
                                <span style="color:var(--text-muted);font-weight:600;"><?= $avg_students_per_school; ?></span>
                            </div>
                            <div style="height:8px;background:var(--bg-alt);border-radius:6px;overflow:hidden;">
                                <span style="display:block;height:100%;width:<?= min(100, $avg_students_per_school * 2); ?>%;background:linear-gradient(90deg,#2563eb,#3b82f6);border-radius:6px;"></span>
                            </div>
                        </div>

                        <div>
                            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                                <strong>Teachers per school</strong>
                                <span style="color:var(--text-muted);font-weight:600;"><?= $avg_teachers_per_school; ?></span>
                            </div>
                            <div style="height:8px;background:var(--bg-alt);border-radius:6px;overflow:hidden;">
                                <span style="display:block;height:100%;width:<?= min(100, $avg_teachers_per_school * 6); ?>%;background:linear-gradient(90deg,#10b981,#34d399);border-radius:6px;"></span>
                            </div>
                        </div>

                        <div>
                            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                                <strong>Active schools</strong>
                                <span style="color:var(--text-muted);font-weight:600;">
                                    <?= $schools_count > 0 ? round(($active_schools / $schools_count) * 100, 1) : 0; ?>%
                                </span>
                            </div>
                            <div style="height:8px;background:var(--bg-alt);border-radius:6px;overflow:hidden;">
                                <span style="display:block;height:100%;width:<?= $schools_count > 0 ? round(($active_schools / $schools_count) * 100) : 0; ?>%;background:linear-gradient(90deg,#7c3aed,#a78bfa);border-radius:6px;"></span>
                            </div>
                        </div>

                        <div>
                            <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
                                <strong>Announcements</strong>
                                <span style="color:var(--text-muted);font-weight:600;"><?= number_format($announcements_count); ?> total</span>
                            </div>
                            <div style="height:8px;background:var(--bg-alt);border-radius:6px;overflow:hidden;">
                                <span style="display:block;height:100%;width:<?= $announcements_count > 0 ? 100 : 0; ?>%;background:linear-gradient(90deg,#f59e0b,#fbbf24);border-radius:6px;"></span>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Quick actions -->
            <div class="card">
                <div class="card-head">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="card-body">
                    <div class="quick-grid">
                        <a href="admin-add-school.php" class="quick-btn">
                            <i class="fas fa-plus"></i> Add School
                        </a>
                        <a href="admin-announcements.php" class="quick-btn">
                            <i class="fas fa-bullhorn"></i> Send Announcement
                        </a>
                        <a href="admin-events.php" class="quick-btn">
                            <i class="fas fa-calendar-alt"></i> Manage Events
                        </a>
                        <a href="admin-reports.php" class="quick-btn">
                            <i class="fas fa-chart-line"></i> View Reports
                        </a>
                        <a href="admin-settings.php" class="quick-btn">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    /* THEME */
    (function () {
        const html = document.documentElement;
        const toggle = document.getElementById('themeToggle');
        const icon = toggle.querySelector('i');
        function apply(theme) {
            html.setAttribute('data-theme', theme);
            localStorage.setItem('educbridge-theme', theme);
            if (theme === 'dark') { icon.classList.remove('fa-moon'); icon.classList.add('fa-sun'); }
            else { icon.classList.remove('fa-sun'); icon.classList.add('fa-moon'); }
            setTimeout(rebuildChart, 30);
        }
        apply(localStorage.getItem('educbridge-theme') || 'light');
        toggle.addEventListener('click', function () {
            apply(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
        });
    })();

    /* SIDEBAR */
    (function () {
        const hamburger = document.getElementById('hamburger');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        function openSidebar()  { sidebar.classList.add('open');  overlay.classList.add('show'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) closeSidebar(); else openSidebar();
        });
        overlay.addEventListener('click', closeSidebar);
    })();

    /* GROWTH CHART */
    const CHART_DATA = {
        labels: <?= json_encode($labels_month); ?>,
        values: <?= json_encode($schools_by_month); ?>
    };
    let growthChart = null;

    function themeColors() {
        const dark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            text: dark ? '#cbd5e1' : '#475569',
            grid: dark ? 'rgba(148,163,184,0.12)' : 'rgba(15,23,42,0.06)',
            tooltipBg: dark ? '#1e293b' : '#ffffff',
            tooltipFg: dark ? '#f1f5f9' : '#0f172a',
        };
    }

    function buildChart() {
        const el = document.getElementById('growthChart');
        if (!el || typeof Chart === 'undefined') return;

        const C = themeColors();
        const ctx = el.getContext('2d');
        const grad = ctx.createLinearGradient(0, 0, 0, 280);
        grad.addColorStop(0, 'rgba(37,99,235,0.35)');
        grad.addColorStop(1, 'rgba(37,99,235,0.02)');

        growthChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: CHART_DATA.labels,
                datasets: [{
                    label: 'New schools',
                    data: CHART_DATA.values,
                    borderColor: '#2563eb',
                    backgroundColor: grad,
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.35,
                    pointRadius: 4,
                    pointBackgroundColor: '#2563eb',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: C.tooltipBg,
                        titleColor: C.tooltipFg,
                        bodyColor: C.tooltipFg,
                        borderColor: C.grid,
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 8,
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: C.text, font: { size: 11 } } },
                    y: { beginAtZero: true, grid: { color: C.grid }, ticks: { color: C.text, precision: 0, font: { size: 11 } } }
                }
            }
        });
    }

    function rebuildChart() {
        if (growthChart) { try { growthChart.destroy(); } catch(e){} growthChart = null; }
        buildChart();
    }

    if (typeof Chart !== 'undefined') buildChart();
    else window.addEventListener('load', buildChart);
</script>

</body>
</html>