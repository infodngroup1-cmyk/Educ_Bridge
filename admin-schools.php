<?php
session_start();
require_once 'db.php';

if (empty($_SESSION["admin_id"]) || empty($_SESSION["admin_verified"])) {
    header("Location: admin-login.php");
    exit;
}

$admin_name = $_SESSION["admin_name"];

/* INITIALS */
$initials = "";
foreach (explode(" ", trim($admin_name)) as $part) {
    if ($part !== "") { $initials .= strtoupper($part[0]); if (strlen($initials) >= 2) break; }
}
if ($initials === "") $initials = "A";

/*
|--------------------------------------------------------------------------
| ACTIONS
|--------------------------------------------------------------------------
*/
$message = ""; $message_type = "";

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = (int) $_GET['delete'];
    $d = mysqli_prepare($conn, "DELETE FROM schools WHERE id = ?");
    mysqli_stmt_bind_param($d, "i", $id);
    mysqli_stmt_execute($d);
    mysqli_stmt_close($d);
    header("Location: admin-schools.php?deleted=1");
    exit;
}

if (isset($_GET['toggle']) && is_numeric($_GET['toggle'])) {
    $id = (int) $_GET['toggle'];
    $t = mysqli_prepare($conn, "
        UPDATE schools
        SET status = CASE WHEN status = 'active' THEN 'deactivated' ELSE 'active' END
        WHERE id = ?
    ");
    mysqli_stmt_bind_param($t, "i", $id);
    mysqli_stmt_execute($t);
    mysqli_stmt_close($t);
    header("Location: admin-schools.php?toggled=1");
    exit;
}

/*
|--------------------------------------------------------------------------
| SEARCH + FILTER
|--------------------------------------------------------------------------
*/
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';

$where  = [];
$params = [];
$types  = "";

if ($search !== '') {
    $where[] = "(name LIKE ? OR admin_name LIKE ? OR province LIKE ? OR district LIKE ? OR email LIKE ? OR phone LIKE ? OR school_code LIKE ?)";
    $like = "%{$search}%";
    $params = array_merge($params, [$like,$like,$like,$like,$like,$like,$like]);
    $types .= "sssssss";
}
if ($filter === 'active')            $where[] = "status = 'active'";
elseif ($filter === 'deactivated')   $where[] = "status = 'deactivated'";
elseif ($filter === 'public')        $where[] = "type = 'public'";
elseif ($filter === 'private')       $where[] = "type = 'private'";

$where_sql = count($where) ? "WHERE " . implode(" AND ", $where) : "";

/*
|--------------------------------------------------------------------------
| FETCH SCHOOLS
|--------------------------------------------------------------------------
*/
$schools = [];
$sql = "SELECT * FROM schools $where_sql ORDER BY created_at DESC";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    if (!empty($params)) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) $schools[] = $row;
    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| GLOBAL COUNTS
|--------------------------------------------------------------------------
*/
function gcount($conn, $sql) {
    $r = @mysqli_query($conn, $sql);
    if (!$r) return 0;
    return (int) mysqli_fetch_assoc($r)['t'];
}

$total_schools   = gcount($conn, "SELECT COUNT(*) t FROM schools");
$active_schools  = gcount($conn, "SELECT COUNT(*) t FROM schools WHERE status='active'");
$public_schools  = gcount($conn, "SELECT COUNT(*) t FROM schools WHERE type='public'");
$private_schools = gcount($conn, "SELECT COUNT(*) t FROM schools WHERE type='private'");

$total_classes  = gcount($conn, "SELECT COUNT(*) t FROM classes");
$total_teachers = gcount($conn, "SELECT COUNT(*) t FROM teachers");
$total_students = gcount($conn, "SELECT COUNT(*) t FROM students");

$unread_count = 0;
$r = @mysqli_query($conn, "SELECT COUNT(*) t FROM messages WHERE status='unread'");
if ($r) $unread_count = (int) mysqli_fetch_assoc($r)['t'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Schools | Educ Bridge Admin</title>
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
        --sidebar-bg:#0f172a;--sidebar-hover:rgba(255,255,255,.06);--sidebar-active:rgba(37,99,235,.18);
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
    .nav-item i.nav-icon{width:20px;text-align:center;font-size:15px;color:var(--sidebar-text)}
    .nav-item:hover{background:var(--sidebar-hover);color:var(--sidebar-text-active)}
    .nav-item:hover i.nav-icon{color:#60a5fa}
    .nav-item.active{background:var(--sidebar-active);color:var(--sidebar-text-active)}
    .nav-item.active i.nav-icon{color:#60a5fa}
    .nav-item.active::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;background:#3b82f6;border-radius:0 4px 4px 0}
    .sidebar-profile{border-top:1px solid var(--sidebar-border);padding:16px 18px;display:flex;align-items:center;gap:12px;background:rgba(255,255,255,.02)}
    .sidebar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;flex-shrink:0;box-shadow:0 4px 12px rgba(59,130,246,.35)}
    .sidebar-profile-info{flex:1;min-width:0}
    .sidebar-profile-info .p-name{font-size:13.5px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-profile-info .p-role{font-size:11.5px;color:var(--sidebar-text);opacity:.75}
    .sidebar-logout{width:34px;height:34px;border-radius:8px;background:rgba(239,68,68,.12);color:#f87171;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:13px;flex-shrink:0}
    .sidebar-logout:hover{background:#ef4444;color:#fff}

    .main{flex:1;margin-left:260px;min-height:100vh;display:flex;flex-direction:column;transition:margin-left .3s ease}
    .topbar{background:var(--bg-card);border-bottom:1px solid var(--border-color);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50}
    .topbar-left{display:flex;align-items:center;gap:14px}
    .hamburger{display:none;width:40px;height:40px;border-radius:10px;border:1.5px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary);align-items:center;justify-content:center;cursor:pointer;font-size:15px}
    .hamburger:hover{border-color:var(--primary);color:var(--primary)}
    .page-title{font-family:'Poppins',sans-serif;font-size:20px;font-weight:700;letter-spacing:-.4px}
    .page-subtitle{font-size:13px;color:var(--text-muted);margin-top:2px}
    .topbar-actions{display:flex;align-items:center;gap:10px}
    .topbar-btn{width:42px;height:42px;border-radius:50%;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;text-decoration:none;position:relative}
    .topbar-btn:hover{border-color:var(--primary);color:var(--primary);transform:translateY(-1px)}
    .topbar-btn.theme-btn:hover{transform:rotate(20deg)}
    .topbar-btn .dot{position:absolute;top:8px;right:8px;width:8px;height:8px;background:#ef4444;border:2px solid var(--bg-card);border-radius:50%}
    .topbar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.3);cursor:pointer}

    .content{padding:28px;flex:1}

    /* KPI */
    .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:22px}
    .kpi-card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;transition:var(--transition);position:relative;overflow:hidden}
    .kpi-card::after{content:'';position:absolute;top:0;left:0;bottom:0;width:4px;background:var(--primary)}
    .kpi-card.blue::after{background:#2563eb}
    .kpi-card.green::after{background:#10b981}
    .kpi-card.purple::after{background:#7c3aed}
    .kpi-card.orange::after{background:#f59e0b}
    .kpi-card.teal::after{background:#14b8a6}
    .kpi-card.pink::after{background:#ec4899}
    .kpi-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md)}
    .kpi-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0}
    .kpi-icon.blue{background:linear-gradient(135deg,#2563eb,#3b82f6)}
    .kpi-icon.green{background:linear-gradient(135deg,#10b981,#34d399)}
    .kpi-icon.purple{background:linear-gradient(135deg,#7c3aed,#a78bfa)}
    .kpi-icon.orange{background:linear-gradient(135deg,#f59e0b,#fbbf24)}
    .kpi-icon.teal{background:linear-gradient(135deg,#14b8a6,#2dd4bf)}
    .kpi-icon.pink{background:linear-gradient(135deg,#ec4899,#f472b6)}
    .kpi-info{flex:1;min-width:0}
    .kpi-info .num{font-family:'Poppins',sans-serif;font-size:24px;font-weight:700;line-height:1.15}
    .kpi-info .lbl{font-size:12.5px;color:var(--text-muted);margin-top:3px;font-weight:500}

    .page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px;flex-wrap:wrap}
    .page-head h1{font-family:'Poppins',sans-serif;font-size:22px;font-weight:700;display:flex;align-items:center;gap:10px}
    .page-head h1 i{color:var(--primary)}

    .btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:10px;font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);transition:var(--transition);font-family:inherit}
    .btn:hover{border-color:var(--primary);color:var(--primary)}
    .btn.primary{background:var(--primary);color:#fff;border-color:var(--primary);box-shadow:0 4px 14px rgba(37,99,235,.3)}
    .btn.primary:hover{background:var(--primary-dark);color:#fff}

    .alert{padding:13px 16px;border-radius:10px;margin-bottom:20px;font-size:14px;display:flex;align-items:flex-start;gap:10px;line-height:1.55}
    .alert-success{background:var(--success-light);color:#059669;border:1px solid rgba(16,185,129,.25)}
    [data-theme="dark"] .alert-success{color:#34d399}

    .search-bar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
    .search-wrapper{position:relative;flex:1;max-width:520px;min-width:220px}
    .search-wrapper i{position:absolute;left:15px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:14px}
    .search-wrapper input{width:100%;padding:12px 15px 12px 42px;border:1.5px solid var(--border-color);border-radius:10px;background:var(--bg-card);color:var(--text-primary);font-family:inherit;font-size:14.5px;outline:none;transition:var(--transition)}
    .search-wrapper input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.1)}

    .filter-chips{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
    .filter-chips a{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:50px;font-size:13.5px;font-weight:600;text-decoration:none;background:var(--bg-card);color:var(--text-secondary);border:1.5px solid var(--border-color);transition:var(--transition)}
    .filter-chips a:hover{border-color:var(--primary);color:var(--primary)}
    .filter-chips a.active{background:var(--primary);color:#fff;border-color:var(--primary)}
    .filter-chips a .cnt{font-size:11.5px;font-weight:800;background:rgba(0,0,0,.08);padding:1px 8px;border-radius:50px}
    .filter-chips a.active .cnt{background:rgba(255,255,255,.25)}

    /* Table */
    .table-wrap{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;overflow:visible}
    .table-scroll{overflow-x:auto;border-radius:16px}
    table{width:100%;border-collapse:collapse;min-width:1050px}
    thead{background:var(--bg-alt);border-bottom:1px solid var(--border-color)}
    th{text-align:left;padding:16px 18px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);white-space:nowrap}
    td{padding:16px 18px;font-size:14px;color:var(--text-secondary);border-top:1px solid var(--border-color);vertical-align:middle}
    tbody tr{transition:var(--transition)}
    tbody tr:hover{background:var(--bg-alt)}

    .school-cell{display:flex;align-items:center;gap:12px}
    .school-avatar{width:42px;height:42px;border-radius:11px;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-weight:700;font-size:15px;flex-shrink:0;box-shadow:0 6px 14px rgba(37,99,235,.28)}
    .school-name{font-weight:700;color:var(--text-primary);font-size:14.5px}

    .school-code{display:inline-flex;align-items:center;gap:6px;font-family:'Courier New',monospace;font-size:13px;font-weight:700;color:var(--primary);background:var(--primary-light);padding:6px 12px;border-radius:8px;letter-spacing:1px;white-space:nowrap;cursor:pointer;transition:var(--transition)}
    .school-code:hover{background:var(--primary);color:#fff}
    .school-code i{font-size:10px;opacity:.7}
    .school-code-empty{font-size:12px;color:var(--text-muted);font-style:italic}

    .badge{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:50px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.3px}
    .badge.active{background:var(--success-light);color:#059669}
    .badge.deactivated{background:var(--danger-light);color:#dc2626}
    .badge.public{background:rgba(37,99,235,.12);color:var(--primary)}
    .badge.private{background:var(--warning-light);color:#b45309}
    [data-theme="dark"] .badge.active{color:#34d399}
    [data-theme="dark"] .badge.deactivated{color:#f87171}
    [data-theme="dark"] .badge.public{color:#93c5fd}
    [data-theme="dark"] .badge.private{color:#fbbf24}

    /* ============================================================
       THREE-DOT MENU — cleaner, larger, with labels & sections
       ============================================================ */
    .menu-wrap{position:relative;display:inline-block}
    .menu-btn{
        width:38px;height:38px;border-radius:10px;
        border:1.5px solid var(--border-color);
        background:var(--bg-card);color:var(--text-secondary);
        cursor:pointer;display:flex;align-items:center;justify-content:center;
        font-size:15px;transition:var(--transition);
    }
    .menu-btn:hover{border-color:var(--primary);color:var(--primary);background:var(--primary-light)}
    .menu-btn.open{border-color:var(--primary);color:var(--primary);background:var(--primary-light)}

    .dropdown{
        position:absolute;top:calc(100% + 8px);right:0;
        min-width:250px;
        background:var(--bg-card);
        border:1px solid var(--border-color);
        border-radius:14px;
        box-shadow:0 18px 45px rgba(0,0,0,.16);
        padding:8px;
        z-index:100;
        opacity:0;
        transform:translateY(-8px) scale(.98);
        pointer-events:none;
        transition:opacity .18s ease, transform .18s ease;
        transform-origin:top right;
    }
    [data-theme="dark"] .dropdown{box-shadow:0 18px 45px rgba(0,0,0,.6)}
    .dropdown.open{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}

    .dropdown-section{
        font-size:10.5px;font-weight:800;text-transform:uppercase;
        letter-spacing:.8px;color:var(--text-muted);
        padding:8px 12px 6px;
    }
    .dropdown a{
        display:flex;align-items:center;gap:12px;
        padding:11px 12px;
        border-radius:9px;
        font-size:13.5px;font-weight:600;
        color:var(--text-primary);text-decoration:none;
        transition:var(--transition);
        line-height:1.2;
    }
    .dropdown a .icon{
        width:28px;height:28px;border-radius:8px;
        display:flex;align-items:center;justify-content:center;
        flex-shrink:0;
        font-size:12px;
    }
    .dropdown a:hover{background:var(--bg-alt)}
    .dropdown a.danger{color:#dc2626}
    .dropdown a.danger:hover{background:var(--danger-light)}
    .dropdown a.success{color:#059669}
    .dropdown a.success:hover{background:var(--success-light)}
    .dropdown a.warning{color:#b45309}
    .dropdown a.warning:hover{background:var(--warning-light)}
    .dropdown a.copy-action{color:var(--primary)}
    .dropdown a.copy-action:hover{background:var(--primary-light)}

    /* Icon backgrounds */
    .dropdown a .icon.default { background:rgba(37,99,235,.10); color:#2563eb; }
    .dropdown a .icon.success { background:rgba(16,185,129,.12); color:#059669; }
    .dropdown a .icon.warn    { background:rgba(245,158,11,.14); color:#b45309; }
    .dropdown a .icon.danger  { background:rgba(239,68,68,.10);  color:#dc2626; }
    [data-theme="dark"] .dropdown a .icon.default { background:rgba(37,99,235,.22); color:#93c5fd; }
    [data-theme="dark"] .dropdown a .icon.success { background:rgba(16,185,129,.22); color:#34d399; }
    [data-theme="dark"] .dropdown a .icon.warn    { background:rgba(245,158,11,.22); color:#fbbf24; }
    [data-theme="dark"] .dropdown a .icon.danger  { background:rgba(239,68,68,.22);  color:#f87171; }

    .dropdown-divider{height:1px;background:var(--border-color);margin:6px 8px}

    /* Footer card in dropdown */
    .dropdown-footer{
        display:flex;align-items:center;gap:8px;
        padding:8px 12px 4px;
        font-size:11px;color:var(--text-muted);
    }
    .dropdown-footer i{color:var(--primary)}

    .empty-state{text-align:center;padding:60px 20px;color:var(--text-muted)}
    .empty-state i{font-size:48px;opacity:.35;margin-bottom:14px;display:block}
    .empty-state p{font-size:15px;line-height:1.6}

    .overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:90;backdrop-filter:blur(2px)}
    .overlay.show{display:block}

    .copy-toast{position:fixed;bottom:30px;left:50%;transform:translateX(-50%) translateY(100px);background:var(--text-primary);color:var(--bg-card);padding:12px 22px;border-radius:50px;font-size:14px;font-weight:600;box-shadow:0 10px 30px rgba(0,0,0,.2);z-index:999;opacity:0;transition:all .4s cubic-bezier(.4,0,.2,1);display:flex;align-items:center;gap:10px;pointer-events:none}
    .copy-toast.show{transform:translateX(-50%) translateY(0);opacity:1}
    .copy-toast i{color:var(--success)}

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
        .page-head h1{font-size:18px}
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
            <a href="admin-schools.php" class="nav-item active"><i class="fas fa-school nav-icon"></i> Schools</a>
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
            <a href="logout.php" class="sidebar-logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="overlay" id="overlay"></div>

    <div class="main">

        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Schools</div>
                    <div class="page-subtitle"><?= $total_schools; ?> schools · <?= $active_schools; ?> active · <?= number_format($total_students); ?> students · <?= number_format($total_teachers); ?> teachers</div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>
                <a href="admin-messages.php" class="topbar-btn" title="Messages"><i class="fas fa-bell"></i><?php if ($unread_count > 0): ?><span class="dot"></span><?php endif; ?></a>
                <div class="topbar-avatar" title="<?= htmlspecialchars($admin_name); ?>"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <?php if (isset($_GET['added'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle" style="margin-top:2px;"></i>
                    <span>School added successfully. Welcome email sent.</span>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['updated'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle" style="margin-top:2px;"></i>
                    <span>School updated successfully.</span>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle" style="margin-top:2px;"></i>
                    <span>School deleted successfully.</span>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['toggled'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle" style="margin-top:2px;"></i>
                    <span>School status updated.</span>
                </div>
            <?php endif; ?>

            <!-- KPI -->
            <div class="kpi-grid">
                <div class="kpi-card blue">
                    <div class="kpi-icon blue"><i class="fas fa-school"></i></div>
                    <div class="kpi-info"><div class="num"><?= number_format($total_schools); ?></div><div class="lbl">Total Schools</div></div>
                </div>
                <div class="kpi-card green">
                    <div class="kpi-icon green"><i class="fas fa-circle-check"></i></div>
                    <div class="kpi-info"><div class="num"><?= number_format($active_schools); ?></div><div class="lbl">Active Schools</div></div>
                </div>
                <div class="kpi-card purple">
                    <div class="kpi-icon purple"><i class="fas fa-layer-group"></i></div>
                    <div class="kpi-info"><div class="num"><?= number_format($total_classes); ?></div><div class="lbl">Total Classes</div></div>
                </div>
                <div class="kpi-card teal">
                    <div class="kpi-icon teal"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="kpi-info"><div class="num"><?= number_format($total_teachers); ?></div><div class="lbl">Total Teachers</div></div>
                </div>
                <div class="kpi-card orange">
                    <div class="kpi-icon orange"><i class="fas fa-user-graduate"></i></div>
                    <div class="kpi-info"><div class="num"><?= number_format($total_students); ?></div><div class="lbl">Total Students</div></div>
                </div>
                <div class="kpi-card pink">
                    <div class="kpi-icon pink"><i class="fas fa-landmark"></i></div>
                    <div class="kpi-info"><div class="num"><?= number_format($public_schools); ?> · <?= number_format($private_schools); ?></div><div class="lbl">Public · Private</div></div>
                </div>
            </div>

            <div class="page-head">
                <h1><i class="fas fa-school"></i> All Schools</h1>
                <a href="admin-add-school.php" class="btn primary"><i class="fas fa-plus"></i> Add School</a>
            </div>

            <form method="GET" class="search-bar">
                <?php if ($filter !== 'all'): ?>
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter); ?>">
                <?php endif; ?>
                <div class="search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Search school by code, name, admin, location, email, phone…" value="<?= htmlspecialchars($search); ?>">
                </div>
                <button type="submit" class="btn primary"><i class="fas fa-search"></i> Search</button>
                <?php if ($search !== ""): ?>
                    <a href="admin-schools.php" class="btn"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </form>

            <div class="filter-chips">
                <a href="admin-schools.php" class="<?= $filter==='all'?'active':''; ?>"><i class="fas fa-list"></i> All <span class="cnt"><?= $total_schools; ?></span></a>
                <a href="admin-schools.php?filter=active" class="<?= $filter==='active'?'active':''; ?>"><i class="fas fa-circle-check"></i> Active <span class="cnt"><?= $active_schools; ?></span></a>
                <a href="admin-schools.php?filter=deactivated" class="<?= $filter==='deactivated'?'active':''; ?>"><i class="fas fa-ban"></i> Deactivated</a>
                <a href="admin-schools.php?filter=public" class="<?= $filter==='public'?'active':''; ?>"><i class="fas fa-landmark"></i> Public <span class="cnt"><?= $public_schools; ?></span></a>
                <a href="admin-schools.php?filter=private" class="<?= $filter==='private'?'active':''; ?>"><i class="fas fa-building"></i> Private <span class="cnt"><?= $private_schools; ?></span></a>
            </div>

            <div class="table-wrap">
                <?php if (!empty($schools)): ?>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>School</th>
                                    <th>School Code</th>
                                    <th>Administrator</th>
                                    <th>Location</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schools as $s):
                                    $initial = strtoupper(substr($s['name'], 0, 1));
                                    $has_code = !empty($s['school_code']);
                                    $is_active = ($s['status'] === 'active');
                                ?>
                                    <tr>
                                        <td>
                                            <div class="school-cell">
                                                <div class="school-avatar"><?= htmlspecialchars($initial); ?></div>
                                                <div class="school-name"><?= htmlspecialchars($s['name']); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($has_code): ?>
                                                <span class="school-code" onclick="copyCode('<?= htmlspecialchars($s['school_code']); ?>')" title="Click to copy">
                                                    <i class="fas fa-copy"></i> <?= htmlspecialchars($s['school_code']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="school-code-empty">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($s['admin_name']); ?></td>
                                        <td>
                                            <div><?= htmlspecialchars($s['province']); ?> · <?= htmlspecialchars($s['district']); ?></div>
                                            <div style="font-size:12px;color:var(--text-muted);margin-top:2px;"><?= htmlspecialchars($s['country']); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge <?= $s['type']==='public'?'public':'private'; ?>">
                                                <i class="fas <?= $s['type']==='public'?'fa-landmark':'fa-building'; ?>"></i>
                                                <?= ucfirst($s['type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?= $is_active?'active':'deactivated'; ?>">
                                                <i class="fas <?= $is_active?'fa-circle':'fa-ban'; ?>" style="font-size:8px;"></i>
                                                <?= $is_active?'Active':'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right;">
                                            <div class="menu-wrap">
                                                <button type="button" class="menu-btn" onclick="toggleMenu(event, <?= (int)$s['id']; ?>)" aria-label="Actions">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <div class="dropdown" id="dropdown-<?= (int)$s['id']; ?>">

                                                    <div class="dropdown-section">Quick actions</div>

                                                    <?php if ($has_code): ?>
                                                        <a href="#" class="copy-action" onclick="copyCode('<?= htmlspecialchars($s['school_code']); ?>'); return false;">
                                                            <span class="icon default"><i class="fas fa-copy"></i></span>
                                                            Copy School Code
                                                        </a>
                                                    <?php endif; ?>

                                                    <a href="admin-school-view.php?id=<?= (int)$s['id']; ?>">
                                                        <span class="icon default"><i class="fas fa-eye"></i></span>
                                                        View School
                                                    </a>

                                                    <a href="admin-edit-school.php?id=<?= (int)$s['id']; ?>">
                                                        <span class="icon default"><i class="fas fa-pen"></i></span>
                                                        Edit School Details
                                                    </a>

                                                    <a href="admin-print-school.php?id=<?= (int)$s['id']; ?>" target="_blank">
                                                        <span class="icon default"><i class="fas fa-print"></i></span>
                                                        Print Certificate
                                                    </a>

                                                    <div class="dropdown-divider"></div>
                                                    <div class="dropdown-section">Account status</div>

                                                    <a href="admin-schools.php?toggle=<?= (int)$s['id']; ?>" class="<?= $is_active ? 'warning' : 'success'; ?>">
                                                        <span class="icon <?= $is_active ? 'warn' : 'success'; ?>">
                                                            <i class="fas <?= $is_active ? 'fa-ban' : 'fa-circle-check'; ?>"></i>
                                                        </span>
                                                        <?= $is_active ? 'Deactivate School' : 'Activate School'; ?>
                                                    </a>

                                                    <div class="dropdown-divider"></div>
                                                    <div class="dropdown-section">Danger zone</div>

                                                    <a href="admin-schools.php?delete=<?= (int)$s['id']; ?>" class="danger"
                                                       onclick="return confirm('Delete this school permanently? This cannot be undone.');">
                                                        <span class="icon danger"><i class="fas fa-trash"></i></span>
                                                        Delete School
                                                    </a>

                                                    <div class="dropdown-footer">
                                                        <i class="fas fa-circle-info"></i>
                                                        <span>School ID #<?= (int)$s['id']; ?></span>
                                                    </div>

                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-school"></i>
                        <p>
                            <?php if ($search !== ""): ?>
                                No schools match your search.
                            <?php elseif ($filter !== 'all'): ?>
                                No schools in this category yet.
                            <?php else: ?>
                                No schools have been registered yet. Click <strong>Add School</strong> to get started.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<div class="copy-toast" id="copyToast">
    <i class="fas fa-check-circle"></i>
    <span id="copyToastText">School code copied!</span>
</div>

<script>
    /* THREE-DOT MENU */
    function toggleMenu(e, id) {
        e.stopPropagation();
        const dd = document.getElementById('dropdown-' + id);
        const btn = dd.parentElement.querySelector('.menu-btn');
        const wasOpen = dd.classList.contains('open');
        document.querySelectorAll('.dropdown.open').forEach(m => m.classList.remove('open'));
        document.querySelectorAll('.menu-btn.open').forEach(b => b.classList.remove('open'));
        if (!wasOpen) {
            dd.classList.add('open');
            btn.classList.add('open');
        }
    }
    document.addEventListener('click', () => {
        document.querySelectorAll('.dropdown.open').forEach(m => m.classList.remove('open'));
        document.querySelectorAll('.menu-btn.open').forEach(b => b.classList.remove('open'));
    });

    /* COPY SCHOOL CODE */
    function copyCode(code) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(() => showToast(code)).catch(() => fallbackCopy(code));
        } else {
            fallbackCopy(code);
        }
    }
    function fallbackCopy(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); showToast(text); } catch (e) {}
        document.body.removeChild(ta);
    }
    function showToast(code) {
        const toast = document.getElementById('copyToast');
        document.getElementById('copyToastText').textContent = 'Copied: ' + code;
        toast.classList.add('show');
        clearTimeout(window._copyToastTimer);
        window._copyToastTimer = setTimeout(() => toast.classList.remove('show'), 2000);
    }

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
        }
        apply(localStorage.getItem('educbridge-theme') || 'light');
        toggle.addEventListener('click', () => apply(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light'));

        const hamburger = document.getElementById('hamburger');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('show'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
        hamburger.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
        overlay.addEventListener('click', closeSidebar);
    })();
</script>

</body>
</html>