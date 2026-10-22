<?php
session_start();
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| ADMIN AUTH
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['admin_id']) || empty($_SESSION['admin_verified'])) {
    header("Location: admin-login.php");
    exit;
}

$admin_id    = (int) $_SESSION['admin_id'];
$admin_name  = $_SESSION['admin_name'] ?? 'System Admin';

$initials = "";
foreach (explode(" ", trim($admin_name)) as $part) {
    if ($part !== "") {
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) break;
    }
}
if ($initials === "") $initials = "A";

/*
|--------------------------------------------------------------------------
| AUTO-CREATE TABLES
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS admin_announcements (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    admin_id        INT DEFAULT NULL,
    sender_name     VARCHAR(150) NOT NULL DEFAULT 'System',
    title           VARCHAR(200) NOT NULL,
    body            TEXT NOT NULL,
    priority        ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
    audience        ENUM('schools','parents','all') NOT NULL DEFAULT 'all',
    scope_type      ENUM('all','school','parent') NOT NULL DEFAULT 'all',
    scope_school_id INT DEFAULT NULL,
    scope_parent_id INT DEFAULT NULL,
    total_recipients INT NOT NULL DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_aa_admin   (admin_id),
    KEY idx_aa_aud     (audience),
    KEY idx_aa_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS admin_announcement_recipients (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT NOT NULL,
    recipient_type  ENUM('school','parent') NOT NULL,
    recipient_id    INT NOT NULL,
    recipient_name  VARCHAR(150) DEFAULT NULL,
    is_read         TINYINT(1) NOT NULL DEFAULT 0,
    read_at         DATETIME DEFAULT NULL,
    delivered_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_aar_ann   (announcement_id),
    KEY idx_aar_type  (recipient_type),
    KEY idx_aar_rec   (recipient_type, recipient_id),
    KEY idx_aar_read  (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/*
|--------------------------------------------------------------------------
| DETECT ACTUAL COLUMN NAMES IN `schools`
|--------------------------------------------------------------------------
| Schools may be named school_name / name / title — detect once so the
| rest of the page never breaks.
*/
$school_name_col = 'school_name';
foreach (['school_name','name','title','school'] as $candidate) {
    $chk = @mysqli_query($conn, "SHOW COLUMNS FROM schools LIKE '" . mysqli_real_escape_string($conn, $candidate) . "'");
    if ($chk && mysqli_num_rows($chk) > 0) { $school_name_col = $candidate; break; }
}

$school_has_status = false;
$chk = @mysqli_query($conn, "SHOW COLUMNS FROM schools LIKE 'status'");
if ($chk && mysqli_num_rows($chk) > 0) $school_has_status = true;

/*
|--------------------------------------------------------------------------
| FETCH SCHOOLS
|--------------------------------------------------------------------------
*/
$schools = [];
$status_where = $school_has_status ? " WHERE status = 'active' " : "";
$sql = "SELECT id, `{$school_name_col}` AS school_name FROM schools {$status_where} ORDER BY `{$school_name_col}` ASC";
$res = mysqli_query($conn, $sql);
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        if (empty($r['school_name'])) $r['school_name'] = '(Unnamed school)';
        $schools[] = $r;
    }
}

/*
|--------------------------------------------------------------------------
| DETECT ACTUAL COLUMN NAMES IN `parents`
|--------------------------------------------------------------------------
*/
$parents = [];
$parents_table_exists = false;
$chk = @mysqli_query($conn, "SHOW TABLES LIKE 'parents'");
if ($chk && mysqli_num_rows($chk) > 0) {
    $parents_table_exists = true;

    $parent_name_col = 'name';
    foreach (['name','full_name','parent_name'] as $candidate) {
        $c = @mysqli_query($conn, "SHOW COLUMNS FROM parents LIKE '" . mysqli_real_escape_string($conn, $candidate) . "'");
        if ($c && mysqli_num_rows($c) > 0) { $parent_name_col = $candidate; break; }
    }

    $has_school_col = false;
    $c = @mysqli_query($conn, "SHOW COLUMNS FROM parents LIKE 'school_id'");
    if ($c && mysqli_num_rows($c) > 0) $has_school_col = true;

    if ($has_school_col) {
        $psql = "
            SELECT p.id, p.`{$parent_name_col}` AS name, p.school_id, s.`{$school_name_col}` AS school_name
            FROM parents p
            LEFT JOIN schools s ON s.id = p.school_id
            ORDER BY p.`{$parent_name_col}` ASC
        ";
    } else {
        $psql = "SELECT id, `{$parent_name_col}` AS name, NULL AS school_id, NULL AS school_name FROM parents ORDER BY `{$parent_name_col}` ASC";
    }

    $pres = mysqli_query($conn, $psql);
    if ($pres) {
        while ($r = mysqli_fetch_assoc($pres)) {
            if (empty($r['name'])) $r['name'] = '(Unnamed parent)';
            $parents[] = $r;
        }
    }
}

/*
|--------------------------------------------------------------------------
| FILTERS / LIST
|--------------------------------------------------------------------------
*/
$filter = $_GET['filter'] ?? 'all';
$q      = trim($_GET['q'] ?? '');

$where  = ["1=1"];
$params = [];
$types  = "";

if ($filter === 'schools')      $where[] = "audience = 'schools'";
elseif ($filter === 'parents')  $where[] = "audience = 'parents'";
elseif ($filter === 'every')    $where[] = "audience = 'all'";

if ($q !== '') {
    $where[] = "(title LIKE ? OR body LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like; $params[] = $like;
    $types   .= "ss";
}

$where_sql = implode(" AND ", $where);
$announcements = [];
$list_stmt = mysqli_prepare($conn, "
    SELECT * FROM admin_announcements
    WHERE $where_sql
    ORDER BY created_at DESC
    LIMIT 100
");
if ($list_stmt) {
    if ($types !== '') mysqli_stmt_bind_param($list_stmt, $types, ...$params);
    mysqli_stmt_execute($list_stmt);
    $rs = mysqli_stmt_get_result($list_stmt);
    while ($row = mysqli_fetch_assoc($rs)) $announcements[] = $row;
    mysqli_stmt_close($list_stmt);
}

/* Counts */
function acount($conn, $sql, $types = "", $params = []) {
    $stmt = @mysqli_prepare($conn, $sql);
    if (!$stmt) return 0;
    if ($types !== "" && !empty($params)) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $n = $r ? (int) mysqli_fetch_assoc($r)['total'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

$total_all     = acount($conn, "SELECT COUNT(*) AS total FROM admin_announcements");
$total_schools = acount($conn, "SELECT COUNT(*) AS total FROM admin_announcements WHERE audience = 'schools'");
$total_parents = acount($conn, "SELECT COUNT(*) AS total FROM admin_announcements WHERE audience = 'parents'");
$total_every   = acount($conn, "SELECT COUNT(*) AS total FROM admin_announcements WHERE audience = 'all'");

$priority_map = [
    'normal' => ['label' => 'Normal', 'class' => 'gray'],
    'high'   => ['label' => 'High',   'class' => 'orange'],
    'urgent' => ['label' => 'Urgent', 'class' => 'red'],
];

/* Quick lookup for pretty names in the list */
$school_lookup = [];
foreach ($schools as $s) $school_lookup[(int)$s['id']] = $s['school_name'];
$parent_lookup = [];
foreach ($parents as $p) $parent_lookup[(int)$p['id']] = $p['name'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Announcements | Educ Bridge</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #7c3aed; --primary-dark: #6d28d9; --primary-light: #ede9fe;
            --success: #10b981; --success-light: rgba(16,185,129,0.1);
            --warning: #f59e0b; --warning-light: rgba(245,158,11,0.12);
            --danger: #ef4444;  --danger-light: rgba(239,68,68,0.1);
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(124,58,237,0.22);
            --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.08);
            --sidebar-title: #94a3b8;
            --bg-body: #f8fafc; --bg-card: #ffffff;
            --bg-alt: #f1f5f9;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.07);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary: #a78bfa; --primary-dark: #8b5cf6; --primary-light: #2e1065;
            --success: #34d399; --success-light: rgba(16,185,129,0.15);
            --warning: #fbbf24; --warning-light: rgba(245,158,11,0.18);
            --danger: #f87171;  --danger-light: rgba(239,68,68,0.15);
            --sidebar-bg: #0b1120;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(167,139,250,0.2);
            --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.08);
            --sidebar-title: #94a3b8;
            --bg-body: #0b1120; --bg-card: #1e293b;
            --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); transition:background 0.4s ease, color 0.4s ease; }
        .layout { display:flex; min-height:100vh; }

        .sidebar { width:260px; background:var(--sidebar-bg); color:var(--sidebar-text); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:100; border-right:1px solid var(--sidebar-border); }
        .sidebar-header { padding:22px 22px 18px; border-bottom:1px solid var(--sidebar-border); display:flex; align-items:center; gap:12px; }
        .sidebar-header img { width:42px; height:42px; object-fit:contain; border-radius:50%; border:2px solid var(--sidebar-border); padding:2px; background:#fff; }
        .sidebar-brand { font-family:'Poppins',sans-serif; font-size:18px; font-weight:700; color:#fff; line-height:1.2; }
        .sidebar-brand span { color: var(--primary); }
        .sidebar-brand small { display:block; font-size:11px; color:#94a3b8; letter-spacing:0.4px; margin-top:2px; text-transform:uppercase; }
        .sidebar-nav { flex:1; padding:18px 12px; overflow-y:auto; }
        .nav-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:#64748b; padding:12px 14px 8px; }
        .nav-item { display:flex; align-items:center; gap:12px; padding:11px 14px; color:var(--sidebar-text); text-decoration:none; font-size:14px; font-weight:500; border-radius:10px; margin-bottom:4px; transition:var(--transition); position:relative; }
        .nav-item i.nav-icon { width:20px; text-align:center; font-size:15px; }
        .nav-item:hover { background:var(--sidebar-hover); color:#fff; }
        .nav-item:hover i.nav-icon { color:var(--primary); }
        .nav-item.active { background:var(--sidebar-active); color:#fff; }
        .nav-item.active i.nav-icon { color: #c4b5fd; }
        .nav-item.active::before { content:''; position:absolute; left:0; top:8px; bottom:8px; width:3px; background:var(--primary); border-radius:0 4px 4px 0; }
        .sidebar-profile { border-top:1px solid var(--sidebar-border); padding:16px 18px; display:flex; align-items:center; gap:12px; }
        .sidebar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#7c3aed,#a78bfa); color:#fff; display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; }
        .sidebar-profile-info .p-name { font-size:13.5px; font-weight:600; color:#fff; }
        .sidebar-profile-info .p-role { font-size:11.5px; color:#94a3b8; }
        .sidebar-logout { width:34px; height:34px; border-radius:8px; background:rgba(239,68,68,0.15); color:#f87171; display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:13px; }
        .sidebar-logout:hover { background:var(--danger); color:#fff; }

        .main { flex:1; margin-left:260px; min-height:100vh; display:flex; flex-direction:column; }
        .topbar { background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:16px; position:sticky; top:0; z-index:50; }
        .page-title { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; }
        .page-subtitle { font-size:13px; color:var(--text-muted); margin-top:2px; }
        .topbar-actions { display:flex; align-items:center; gap:10px; }
        .topbar-btn { width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); text-decoration:none; }
        .topbar-btn:hover { border-color:var(--primary); color:var(--primary); }
        .topbar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#7c3aed,#a78bfa); color:#fff; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:700; }
        .hamburger { display:none; width:40px; height:40px; border-radius:10px; border:1.5px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); align-items:center; justify-content:center; cursor:pointer; }

        .content { padding:28px; flex:1; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.5; }
        .alert-success { background:var(--success-light); color:#059669; border:1px solid rgba(16,185,129,0.25); }
        [data-theme="dark"] .alert-success { color:#34d399; }
        .alert-error { background:var(--danger-light); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        [data-theme="dark"] .alert-error { color:#f87171; }

        .page-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:22px; flex-wrap:wrap; }
        .page-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .page-head h1 i { color:var(--primary); }
        .page-head .sub { font-size:13.5px; color:var(--text-muted); margin-top:4px; }

        .summaries { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:22px; }
        .sum-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; padding:18px 20px; display:flex; align-items:center; gap:14px; transition:var(--transition); }
        .sum-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); border-color:var(--primary); }
        .sum-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; flex-shrink:0; }
        .sum-icon.violet { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .sum-icon.blue   { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .sum-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .sum-icon.teal   { background: linear-gradient(135deg, #14b8a6, #2dd4bf); }
        .sum-info .num { font-family:'Poppins',sans-serif; font-size:24px; font-weight:700; }
        .sum-info .lbl { font-size:12.5px; color:var(--text-muted); margin-top:3px; }

        .ann-layout { display:grid; grid-template-columns: 1fr 460px; gap:22px; align-items:start; }
        @media (max-width: 1200px) { .ann-layout { grid-template-columns: 1fr; } }

        .panel { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; overflow:hidden; }
        .panel-head { padding:18px 22px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:12px; background:linear-gradient(135deg, rgba(124,58,237,0.05), rgba(59,130,246,0.02)); }
        .panel-head h2 { font-family:'Poppins',sans-serif; font-size:16px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .panel-head h2 i { color:var(--primary); }
        .panel-body { padding:20px 22px; }

        .filter-row { display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
        .filter-chip { display:inline-flex; align-items:center; gap:6px; padding:7px 13px; border-radius:8px; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); font-size:12.5px; font-weight:600; text-decoration:none; transition:var(--transition); }
        .filter-chip:hover { border-color:var(--primary); color:var(--primary); }
        .filter-chip.active { background:var(--primary); border-color:var(--primary); color:#fff; }
        .filter-chip .cnt { font-size:11px; font-weight:700; padding:1px 7px; border-radius:50px; background: rgba(0,0,0,0.08); }
        .filter-chip.active .cnt { background: rgba(255,255,255,0.25); }

        .search-box { position:relative; margin-bottom:16px; }
        .search-box i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:13px; pointer-events:none; }
        .search-box input { width:100%; padding:11px 14px 11px 38px; border:1.5px solid var(--border-color); border-radius:10px; background:var(--bg-alt); color:var(--text-primary); font-family:inherit; font-size:13.5px; outline:none; transition:var(--transition); }
        .search-box input:focus { border-color:var(--primary); background:var(--bg-card); box-shadow:0 0 0 3px rgba(124,58,237,0.12); }

        .ann-list { display:flex; flex-direction:column; gap:12px; max-height: 780px; overflow-y:auto; padding-right:6px; }
        .ann-item { background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:16px 18px; transition:var(--transition); }
        .ann-item:hover { border-color:var(--primary); transform:translateY(-1px); box-shadow:var(--shadow-md); }
        .ann-item.high   { border-left:4px solid var(--warning); }
        .ann-item.urgent { border-left:4px solid var(--danger); }

        .ann-item-head { display:flex; align-items:flex-start; gap:12px; margin-bottom:10px; }
        .ann-item-icon { width:42px; height:42px; border-radius:11px; background:linear-gradient(135deg, #7c3aed, #a78bfa); color:#fff; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
        .ann-item-title { font-size:15px; font-weight:700; margin-bottom:4px; }
        .ann-item-meta { font-size:12px; color:var(--text-muted); display:flex; gap:8px; flex-wrap:wrap; }
        .ann-item-text { font-size:13.5px; color:var(--text-secondary); line-height:1.65; margin-top:8px; }
        .ann-item-foot { display:flex; align-items:center; gap:8px; margin-top:12px; flex-wrap:wrap; }

        .pill { display:inline-block; padding:3px 10px; border-radius:50px; font-size:11px; font-weight:700; }
        .pill.violet { background: rgba(124,58,237,0.12); color: #7c3aed; }
        .pill.blue   { background: rgba(37,99,235,0.12);  color: #2563eb; }
        .pill.orange { background: rgba(245,158,11,0.15); color: #b45309; }
        .pill.red    { background: rgba(239,68,68,0.12);  color: #dc2626; }
        .pill.gray   { background: rgba(100,116,139,0.14);color: #475569; }
        .pill.teal   { background: rgba(20,184,166,0.12); color: #0d9488; }
        .pill.green  { background: rgba(16,185,129,0.12); color: #059669; }
        [data-theme="dark"] .pill.violet { color: #c4b5fd; }
        [data-theme="dark"] .pill.blue   { color: #93c5fd; }
        [data-theme="dark"] .pill.orange { color: #fbbf24; }
        [data-theme="dark"] .pill.red    { color: #f87171; }
        [data-theme="dark"] .pill.gray   { color: #cbd5e1; }
        [data-theme="dark"] .pill.teal   { color: #2dd4bf; }
        [data-theme="dark"] .pill.green  { color: #34d399; }

        .empty-state { text-align:center; padding:50px 20px; color:var(--text-muted); }
        .empty-state i { font-size:44px; opacity:0.35; margin-bottom:14px; display:block; }
        .empty-state h3 { font-family:'Poppins',sans-serif; font-size:15.5px; font-weight:700; color:var(--text-primary); margin-bottom:6px; }
        .empty-state p { font-size:13px; line-height:1.6; }

        .compose-form .form-group { margin-bottom:16px; }
        .compose-form > .form-group > label { display:block; font-size:12.5px; font-weight:700; color:var(--text-secondary); margin-bottom:7px; text-transform:uppercase; letter-spacing:0.4px; }
        .compose-form > .form-group > label .required { color: #ef4444; }
        .compose-form input[type="text"],
        .compose-form textarea {
            width: 100%; padding: 12px 14px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 14px;
            outline: none;
            transition: var(--transition);
        }
        .compose-form textarea { resize: vertical; min-height: 130px; line-height: 1.55; }
        .compose-form input:focus,
        .compose-form textarea:focus {
            border-color: var(--primary);
            background: var(--bg-card);
            box-shadow: 0 0 0 3px rgba(124,58,237,0.12);
        }

        .audience-grid { display:grid; grid-template-columns: 1fr 1fr; gap:8px; }
        .audience-pill {
            position: relative;
            display: flex; align-items: center; gap: 10px;
            padding: 11px 12px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            cursor: pointer;
            transition: var(--transition);
            font-size: 13px; font-weight: 600;
        }
        .audience-pill input { position: absolute; opacity: 0; pointer-events: none; }
        .audience-pill .ap-icon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 13px; flex-shrink: 0; }
        .audience-pill .ap-icon.schools { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .audience-pill .ap-icon.parents { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .audience-pill .ap-icon.all     { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .audience-pill:hover { border-color: var(--primary); }
        .audience-pill.selected { border-color: var(--primary); background: var(--primary-light); }
        [data-theme="dark"] .audience-pill.selected { background: rgba(167,139,250,0.18); }

        .scope-panel {
            margin-top: 12px; padding: 14px 16px;
            background: var(--bg-alt);
            border: 1.5px dashed var(--border-color);
            border-radius: 12px;
        }
        .scope-panel-title {
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;
            color: var(--text-muted); margin-bottom: 10px;
            display: flex; align-items: center; gap: 6px;
        }
        .scope-modes { display:flex; gap:6px; margin-bottom: 12px; background: var(--bg-card); padding: 4px; border-radius: 8px; border: 1px solid var(--border-color); }
        .scope-mode { flex: 1; text-align: center; padding: 8px 10px; font-size: 12px; font-weight: 700; color: var(--text-secondary); border-radius: 6px; cursor: pointer; transition: var(--transition); }
        .scope-mode.active { background: var(--primary); color: #fff; }
        .scope-body { display: none; }
        .scope-body.show { display: block; }
        .scope-body select,
        .scope-body input[type="text"] {
            width: 100%; padding: 10px 12px;
            border: 1.5px solid var(--border-color);
            border-radius: 9px;
            background: var(--bg-card);
            color: var(--text-primary);
            font-family: inherit; font-size: 13.5px;
            outline: none;
        }
        .scope-body select:focus,
        .scope-body input[type="text"]:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(124,58,237,0.12); }
        .scope-body .hint { font-size: 11.5px; color: var(--text-muted); margin-top: 6px; }
        .scope-body .list-count {
            font-size: 11px;
            color: var(--primary);
            font-weight: 700;
            margin-top: 6px;
        }

        .priority-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:8px; }
        .priority-pill {
            position: relative; display: flex; flex-direction: column; align-items: center; gap: 5px;
            padding: 10px 6px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            cursor: pointer;
            font-size: 12px; font-weight: 700; color: var(--text-secondary);
        }
        .priority-pill input { position: absolute; opacity: 0; pointer-events: none; }
        .priority-pill i { font-size: 15px; }
        .priority-pill.normal i { color: #64748b; }
        .priority-pill.high i   { color: #f59e0b; }
        .priority-pill.urgent i { color: #ef4444; }
        .priority-pill.selected { border-color: var(--primary); background: var(--primary-light); color: var(--text-primary); }

        .char-counter { font-size: 11.5px; color: var(--text-muted); text-align: right; margin-top: 5px; }

        .btn { display:inline-flex; align-items:center; gap:8px; padding:12px 22px; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); transition:var(--transition); font-family:inherit; }
        .btn:hover { border-color:var(--primary); color:var(--primary); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(124,58,237,0.32); width:100%; justify-content:center; }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; }

        .compose-note { font-size: 11.5px; color: var(--text-muted); line-height: 1.55; margin-top: 8px; display: flex; gap: 6px; align-items: flex-start; }
        .compose-note i { color: var(--primary); margin-top: 2px; }

        .data-warning {
            margin-top: 8px;
            padding: 8px 10px;
            background: var(--warning-light);
            border-left: 3px solid var(--warning);
            border-radius: 6px;
            font-size: 11.5px;
            color: #b45309;
            line-height: 1.5;
        }
        [data-theme="dark"] .data-warning { color: #fbbf24; }

        @media (max-width: 900px) {
            .sidebar { transform:translateX(-100%); }
            .sidebar.open { transform:translateX(0); box-shadow:0 0 40px rgba(0,0,0,0.2); }
            .main { margin-left:0; }
            .hamburger { display:flex; }
        }
        @media (max-width: 600px) {
            .content { padding:20px 16px; }
            .topbar { padding:12px 16px; }
            .page-title { font-size:17px; }
            .page-head h1 { font-size:18px; }
            .audience-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="layout">

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="logo.png" alt="Educ Bridge">
            <div class="sidebar-brand">Educ<span>Bridge</span><small>System Admin</small></div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section-title">Overview</div>
           <a href="admin-dashboard.php" class="nav-item">
                <i class="fas fa-th-large nav-icon"></i> Dashboard
            </a>
            
            <a href="admin-schools.php" class="nav-item active">
                <i class="fas fa-school nav-icon"></i> Schools
            </a>
            <a href="admin-students.php" class="nav-item">
                <i class="fas fa-user-graduate nav-icon"></i> Students
            </a>
            <a href="admin-teachers.php" class="nav-item">
                <i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers
            </a>

            <div class="nav-section-title">Management</div>
            <a href="admin-school-account.php" class="nav-item">
                <i class="fas fa-id-card nav-icon"></i> School Accounts
            </a>
            <a href="admin-events.php" class="nav-item">
                <i class="fas fa-calendar-alt nav-icon"></i> Events
            </a>
            <a href="admin-announcements.php" class="nav-item">
                <i class="fas fa-bullhorn nav-icon"></i> Announcements
            </a>

            <div class="nav-section-title">System</div>
            <a href="admin-reports.php" class="nav-item">
                <i class="fas fa-chart-line nav-icon"></i> Reports
            </a>
            <a href="admin-settings.php" class="nav-item">
                <i class="fas fa-cog nav-icon"></i> Settings
            </a>
        </nav>
        <div class="sidebar-profile">
            <div class="sidebar-avatar"><?= htmlspecialchars($initials); ?></div>
            <div class="sidebar-profile-info">
                <div class="p-name"><?= htmlspecialchars($admin_name); ?></div>
                <div class="p-role">System Admin</div>
            </div>
            <a href="admin-dashboard.php?logout=1" class="sidebar-logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="main">

        <header class="topbar">
            <div style="display:flex;align-items:center;gap:14px;">
                <button class="hamburger" id="hamburger"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">System Announcements</div>
                    <div class="page-subtitle">Broadcast platform-wide messages</div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                <div class="topbar-avatar"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <?php if (isset($_GET['sent']) && $_GET['sent'] === '1'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle" style="margin-top:2px;"></i>
                    <span>
                        Announcement sent
                        <?php if (!empty($_GET['n'])): ?>
                            to <strong><?= (int)$_GET['n']; ?></strong> recipient<?= ((int)$_GET['n'] === 1 ? '' : 's'); ?>.
                        <?php else: ?> successfully.<?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars(urldecode($_GET['error'])); ?></span>
                </div>
            <?php endif; ?>

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-bullhorn"></i> System Announcements</h1>
                    <div class="sub">Send messages to all schools, one school, all parents or a specific parent</div>
                </div>
            </div>

            <div class="summaries">
                <div class="sum-card">
                    <div class="sum-icon violet"><i class="fas fa-envelope-open-text"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_all; ?></div>
                        <div class="lbl">Total Sent</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon blue"><i class="fas fa-school"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_schools; ?></div>
                        <div class="lbl">To Schools</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon orange"><i class="fas fa-user-friends"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_parents; ?></div>
                        <div class="lbl">To Parents</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon teal"><i class="fas fa-globe"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_every; ?></div>
                        <div class="lbl">To Everyone</div>
                    </div>
                </div>
            </div>

            <div class="ann-layout">

                <!-- LEFT: SENT HISTORY -->
                <div class="panel">
                    <div class="panel-head"><h2><i class="fas fa-paper-plane"></i> Sent Announcements</h2></div>
                    <div class="panel-body">

                        <div class="filter-row">
                            <a href="?filter=all&q=<?= urlencode($q); ?>" class="filter-chip <?= $filter === 'all' ? 'active' : ''; ?>">
                                <i class="fas fa-list"></i> All <span class="cnt"><?= $total_all; ?></span>
                            </a>
                            <a href="?filter=schools&q=<?= urlencode($q); ?>" class="filter-chip <?= $filter === 'schools' ? 'active' : ''; ?>">
                                <i class="fas fa-school"></i> Schools <span class="cnt"><?= $total_schools; ?></span>
                            </a>
                            <a href="?filter=parents&q=<?= urlencode($q); ?>" class="filter-chip <?= $filter === 'parents' ? 'active' : ''; ?>">
                                <i class="fas fa-user-friends"></i> Parents <span class="cnt"><?= $total_parents; ?></span>
                            </a>
                            <a href="?filter=every&q=<?= urlencode($q); ?>" class="filter-chip <?= $filter === 'every' ? 'active' : ''; ?>">
                                <i class="fas fa-globe"></i> Everyone <span class="cnt"><?= $total_every; ?></span>
                            </a>
                        </div>

                        <form method="GET" class="search-box">
                            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter); ?>">
                            <i class="fas fa-search"></i>
                            <input type="text" name="q" placeholder="Search announcements..." value="<?= htmlspecialchars($q); ?>">
                        </form>

                        <?php if (!empty($announcements)): ?>
                            <div class="ann-list">
                                <?php foreach ($announcements as $a):
                                    $prio = $priority_map[$a['priority']] ?? $priority_map['normal'];
                                    $item_class = '';
                                    if ($a['priority'] === 'urgent') $item_class = ' urgent';
                                    elseif ($a['priority'] === 'high') $item_class = ' high';
                                ?>
                                    <div class="ann-item<?= $item_class; ?>">
                                        <div class="ann-item-head">
                                            <div class="ann-item-icon"><i class="fas fa-bullhorn"></i></div>
                                            <div style="flex:1;min-width:0;">
                                                <div class="ann-item-title"><?= htmlspecialchars($a['title']); ?></div>
                                                <div class="ann-item-meta">
                                                    <span><i class="fas fa-shield-halved" style="font-size:11px;"></i> <?= htmlspecialchars($a['sender_name']); ?></span>
                                                    <span>·</span>
                                                    <span><?= date('M j, Y · g:i A', strtotime($a['created_at'])); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="ann-item-text"><?= nl2br(htmlspecialchars(mb_strimwidth($a['body'], 0, 300, '…'))); ?></div>
                                        <div class="ann-item-foot">
                                            <?php if ($a['audience'] === 'schools'): ?>
                                                <span class="pill blue"><i class="fas fa-school"></i> Schools</span>
                                            <?php elseif ($a['audience'] === 'parents'): ?>
                                                <span class="pill orange"><i class="fas fa-user-friends"></i> Parents</span>
                                            <?php else: ?>
                                                <span class="pill violet"><i class="fas fa-globe"></i> Everyone</span>
                                            <?php endif; ?>

                                            <?php if ($a['scope_type'] === 'school' && !empty($a['scope_school_id'])):
                                                $sn = $school_lookup[(int)$a['scope_school_id']] ?? ('School #' . $a['scope_school_id']);
                                            ?>
                                                <span class="pill gray"><i class="fas fa-filter"></i> <?= htmlspecialchars($sn); ?></span>
                                            <?php elseif ($a['scope_type'] === 'parent' && !empty($a['scope_parent_id'])):
                                                $pn = $parent_lookup[(int)$a['scope_parent_id']] ?? ('Parent #' . $a['scope_parent_id']);
                                            ?>
                                                <span class="pill gray"><i class="fas fa-user"></i> <?= htmlspecialchars($pn); ?></span>
                                            <?php endif; ?>

                                            <span class="pill <?= $prio['class']; ?>"><?= $prio['label']; ?></span>

                                            <?php if (!empty($a['total_recipients'])): ?>
                                                <span class="pill teal"><i class="fas fa-user-check"></i> <?= (int)$a['total_recipients']; ?> recipient<?= ((int)$a['total_recipients'] === 1 ? '' : 's'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-paper-plane"></i>
                                <h3>No announcements yet</h3>
                                <p>Use the compose panel to send your first message.</p>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- RIGHT: COMPOSE -->
                <div class="panel">
                    <div class="panel-head"><h2><i class="fas fa-paper-plane"></i> Compose Announcement</h2></div>
                    <div class="panel-body">

                        <form class="compose-form" method="POST" action="admin-announcements-send.php" id="composeForm">

                            <div class="form-group">
                                <label for="title">Title <span class="required">*</span></label>
                                <input type="text" id="title" name="title" maxlength="200" required placeholder="e.g. Scheduled Maintenance">
                            </div>

                            <!-- AUDIENCE -->
                            <div class="form-group">
                                <label>Send To <span class="required">*</span></label>
                                <div class="audience-grid" id="audienceGrid">
                                    <label class="audience-pill selected" data-aud="schools">
                                        <input type="radio" name="audience" value="schools" checked>
                                        <span class="ap-icon schools"><i class="fas fa-school"></i></span>
                                        Schools
                                    </label>
                                    <label class="audience-pill" data-aud="parents">
                                        <input type="radio" name="audience" value="parents">
                                        <span class="ap-icon parents"><i class="fas fa-user-friends"></i></span>
                                        Parents
                                    </label>
                                    <label class="audience-pill" data-aud="all" style="grid-column: 1 / -1;">
                                        <input type="radio" name="audience" value="all">
                                        <span class="ap-icon all"><i class="fas fa-globe"></i></span>
                                        Everyone (Schools + Parents)
                                    </label>
                                </div>

                                <!-- NESTED SCOPE -->
                                <div class="scope-panel" id="scopePanel">
                                    <div class="scope-panel-title">
                                        <i class="fas fa-crosshairs"></i>
                                        <span id="scopeTitle">Choose a target</span>
                                    </div>

                                    <div class="scope-modes" id="scopeModes">
                                        <div class="scope-mode active" data-mode="all">All</div>
                                        <div class="scope-mode" data-mode="specific">Specific</div>
                                    </div>

                                    <div class="scope-body" data-mode="all">
                                        <div class="hint" id="allHint">Sending to all.</div>
                                    </div>

                                    <div class="scope-body" data-mode="specific">
                                        <input type="text" id="targetSearch" placeholder="Type to search...">
                                        <select name="scope_target" id="scopeTarget" size="6" style="margin-top:8px;"></select>
                                        <div class="hint" id="specificHint">Pick exactly one recipient.</div>
                                        <div class="list-count" id="targetCount"></div>
                                    </div>
                                </div>

                                <?php if (empty($schools) && empty($parents)): ?>
                                    <div class="data-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        No schools or parents found in the database yet.
                                        Schools are loaded from the <strong>schools</strong> table.
                                    </div>
                                <?php elseif (empty($schools)): ?>
                                    <div class="data-warning">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        No schools found in the <strong>schools</strong> table yet.
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Priority -->
                            <div class="form-group">
                                <label>Priority</label>
                                <div class="priority-grid" id="priorityGrid">
                                    <label class="priority-pill normal selected">
                                        <input type="radio" name="priority" value="normal" checked>
                                        <i class="fas fa-circle-info"></i>
                                        Normal
                                    </label>
                                    <label class="priority-pill high">
                                        <input type="radio" name="priority" value="high">
                                        <i class="fas fa-triangle-exclamation"></i>
                                        High
                                    </label>
                                    <label class="priority-pill urgent">
                                        <input type="radio" name="priority" value="urgent">
                                        <i class="fas fa-bell"></i>
                                        Urgent
                                    </label>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="body">Message <span class="required">*</span></label>
                                <textarea id="body" name="body" required maxlength="3000" placeholder="Write your announcement..."></textarea>
                                <div class="char-counter"><span id="charCount">0</span> / 3000</div>
                            </div>

                            <div class="compose-note">
                                <i class="fas fa-info-circle"></i>
                                <span>System announcements can only be delivered to schools and parents.</span>
                            </div>

                            <div style="margin-top:16px;">
                                <button type="submit" class="btn primary" id="sendBtn">
                                    <i class="fas fa-paper-plane"></i> Send Announcement
                                </button>
                            </div>
                        </form>

                    </div>
                </div>

            </div>

        </main>
    </div>
</div>

<script>
    /* Theme */
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

    /* Sidebar */
    (function () {
        const h = document.getElementById('hamburger');
        const s = document.getElementById('sidebar');
        if (h) h.addEventListener('click', function () { s.classList.toggle('open'); });
    })();

    /* Data from PHP */
    const schoolsList = <?= json_encode(array_values($schools)); ?>;
    const parentsList = <?= json_encode(array_values($parents)); ?>;

    const audienceGrid = document.getElementById('audienceGrid');
    const scopeTitle   = document.getElementById('scopeTitle');
    const scopeModes   = document.getElementById('scopeModes');
    const scopeBodies  = document.querySelectorAll('#scopePanel .scope-body');
    const scopeTarget  = document.getElementById('scopeTarget');
    const targetSearch = document.getElementById('targetSearch');
    const specificHint = document.getElementById('specificHint');
    const allHint      = document.getElementById('allHint');
    const targetCount  = document.getElementById('targetCount');

    let currentAudience = 'schools';
    let currentMode     = 'all';
    let targetPool      = []; // [{value, label}]

    function buildPool() {
        targetPool = [];
        if (currentAudience === 'schools') {
            schoolsList.forEach(s => targetPool.push({ value: 's:' + s.id, label: s.school_name }));
        } else if (currentAudience === 'parents') {
            parentsList.forEach(p => targetPool.push({ value: 'p:' + p.id, label: p.name + (p.school_name ? ' — ' + p.school_name : '') }));
        } else {
            schoolsList.forEach(s => targetPool.push({ value: 's:' + s.id, label: '🏫 ' + s.school_name }));
            parentsList.forEach(p => targetPool.push({ value: 'p:' + p.id, label: '👤 ' + p.name + (p.school_name ? ' — ' + p.school_name : '') }));
        }
    }

    function renderTargets(filter) {
        const q = (filter || '').toLowerCase().trim();
        scopeTarget.innerHTML = '';
        let shown = 0;
        targetPool.forEach(function (t) {
            if (q && t.label.toLowerCase().indexOf(q) === -1) return;
            const o = document.createElement('option');
            o.value = t.value;
            o.textContent = t.label;
            scopeTarget.appendChild(o);
            shown++;
        });
        if (shown === 0) {
            const o = document.createElement('option');
            o.value = '';
            o.textContent = targetPool.length === 0
                ? '— No recipients available —'
                : '— No match for search —';
            o.disabled = true;
            scopeTarget.appendChild(o);
        }
        targetCount.textContent = shown + ' of ' + targetPool.length + ' shown';
    }

    function refreshLabels() {
        if (currentAudience === 'schools') {
            scopeTitle.textContent = 'Which schools?';
            allHint.textContent = 'Send to every school on the platform.';
            specificHint.textContent = 'Pick exactly one school.';
        } else if (currentAudience === 'parents') {
            scopeTitle.textContent = 'Which parents?';
            allHint.textContent = 'Send to every parent on the platform.';
            specificHint.textContent = 'Pick exactly one parent.';
        } else {
            scopeTitle.textContent = 'Everyone — optional refinement';
            allHint.textContent = 'Send to every school and every parent.';
            specificHint.textContent = 'Pick one school or one parent.';
        }
    }

    function showScope() {
        refreshLabels();
        buildPool();
        renderTargets('');
        setMode('all');
    }

    function setMode(mode) {
        currentMode = mode;
        scopeModes.querySelectorAll('.scope-mode').forEach(function (el) {
            el.classList.toggle('active', el.dataset.mode === mode);
        });
        scopeBodies.forEach(function (b) {
            b.classList.toggle('show', b.dataset.mode === mode);
        });
        if (mode === 'specific') {
            targetSearch.value = '';
            renderTargets('');
        }
    }

    /* Audience pills */
    audienceGrid.querySelectorAll('.audience-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            audienceGrid.querySelectorAll('.audience-pill').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');
            const radio = pill.querySelector('input');
            if (radio) radio.checked = true;
            currentAudience = radio ? radio.value : 'schools';
            showScope();
        });
    });

    /* Scope modes */
    scopeModes.querySelectorAll('.scope-mode').forEach(function (el) {
        el.addEventListener('click', function () { setMode(el.dataset.mode); });
    });

    /* Search inside specific */
    targetSearch.addEventListener('input', function () { renderTargets(this.value); });

    /* Priority pills */
    document.querySelectorAll('#priorityGrid .priority-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('#priorityGrid .priority-pill').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');
            const radio = pill.querySelector('input');
            if (radio) radio.checked = true;
        });
    });

    /* Char counter */
    (function () {
        const b = document.getElementById('body');
        const c = document.getElementById('charCount');
        function u() { c.textContent = b.value.length; }
        b.addEventListener('input', u); u();
    })();

    /* Submit — resolve scope_type + IDs */
    document.getElementById('composeForm').addEventListener('submit', function (e) {
        function ensureHidden(name, id) {
            let el = document.getElementById(id);
            if (!el) {
                el = document.createElement('input');
                el.type = 'hidden';
                el.name = name;
                el.id = id;
                this.appendChild(el);
            }
            return el;
        }
        const hScopeType = ensureHidden.call(this, 'scope_type', 'hiddenScopeType');
        const hSchoolId  = ensureHidden.call(this, 'scope_school_id', 'hiddenScopeSchoolId');
        const hParentId  = ensureHidden.call(this, 'scope_parent_id', 'hiddenScopeParentId');

        hScopeType.value = currentMode;
        hSchoolId.value  = '';
        hParentId.value  = '';

        if (currentMode === 'specific') {
            const val = scopeTarget.value;
            if (!val) {
                e.preventDefault();
                alert('Please choose a specific recipient.');
                return;
            }
            if (val.indexOf('s:') === 0) {
                hSchoolId.value = val.substring(2);
            } else if (val.indexOf('p:') === 0) {
                hParentId.value = val.substring(2);
            } else if (currentAudience === 'schools') {
                hSchoolId.value = val;
            } else if (currentAudience === 'parents') {
                hParentId.value = val;
            }
        }

        const btn = document.getElementById('sendBtn');
        if (btn.disabled) { e.preventDefault(); return; }
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    });

    showScope();
</script>

</body>
</html>