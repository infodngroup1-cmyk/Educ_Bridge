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
function init_of($name) {
    $out = "";
    foreach (explode(" ", trim((string)$name)) as $p) {
        if ($p !== "") { $out .= strtoupper($p[0]); if (strlen($out) >= 2) break; }
    }
    return $out !== "" ? $out : "S";
}

/*
|--------------------------------------------------------------------------
| HANDLE ACTION — toggle status
|--------------------------------------------------------------------------
*/
$flash = ""; $flash_type = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status') {
    $sid    = (int)($_POST['school_id'] ?? 0);
    $new    = strtolower((string)($_POST['new_status'] ?? ''));
    $allowed = ['active','inactive'];

    if ($sid <= 0 || !in_array($new, $allowed, true)) {
        $flash = "Invalid request.";
        $flash_type = "error";
    } elseif (!col_exists($conn, 'schools', 'status')) {
        $flash = "This schools table doesn't have a status column.";
        $flash_type = "error";
    } else {
        $upd = mysqli_prepare($conn, "UPDATE schools SET status = ? WHERE id = ?");
        if ($upd) {
            mysqli_stmt_bind_param($upd, "si", $new, $sid);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
            $flash = "School #{$sid} marked as " . ucfirst($new) . ".";
            $flash_type = "success";
        } else {
            $flash = "Could not update school: " . mysqli_error($conn);
            $flash_type = "error";
        }
    }
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$q      = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all','active','inactive'], true)) $status = 'all';

/*
|--------------------------------------------------------------------------
| LOAD SCHOOLS (with counts)
|--------------------------------------------------------------------------
| Name column is detected. Counts are computed from real tables, scoped
| by schools.id.
*/
$schools = [];

if (table_exists($conn, 'schools')) {
    $name_col = 'school_name';
    foreach (['school_name','name','title','school'] as $c) {
        if (col_exists($conn, 'schools', $c)) { $name_col = $c; break; }
    }
    $has_status    = col_exists($conn, 'schools', 'status');
    $has_admin     = col_exists($conn, 'schools', 'admin_name');
    $has_code      = col_exists($conn, 'schools', 'school_code');
    $has_email     = col_exists($conn, 'schools', 'email');
    $has_phone     = col_exists($conn, 'schools', 'phone');
    $has_country   = col_exists($conn, 'schools', 'country');
    $has_province  = col_exists($conn, 'schools', 'province');
    $has_district  = col_exists($conn, 'schools', 'district');
    $has_type      = col_exists($conn, 'schools', 'type');
    $has_created   = col_exists($conn, 'schools', 'created_at');

    $sel  = "SELECT id, `{$name_col}` AS school_name";
    if ($has_code)     $sel .= ", school_code";
    if ($has_admin)    $sel .= ", admin_name";
    if ($has_email)    $sel .= ", email";
    if ($has_phone)    $sel .= ", phone";
    if ($has_country)  $sel .= ", country";
    if ($has_province) $sel .= ", province";
    if ($has_district) $sel .= ", district";
    if ($has_type)     $sel .= ", type";
    if ($has_status)   $sel .= ", status";
    else               $sel .= ", 'active' AS status";
    if ($has_created)  $sel .= ", created_at";
    else               $sel .= ", NULL AS created_at";
    $sel .= " FROM schools";

    $where = [];
    $params = [];
    $types  = "";

    if ($q !== '') {
        $where[] = "(`{$name_col}` LIKE ? OR " . ($has_code ? "school_code LIKE ? OR " : "") . ($has_admin ? "admin_name LIKE ? OR " : "") . ($has_email ? "email LIKE ?" : "1=0") . ")";
        $like = "%{$q}%";
        $params[] = $like;
        if ($has_code)  $params[] = $like;
        if ($has_admin) $params[] = $like;
        if ($has_email) $params[] = $like;
        $types .= str_repeat('s', count($params));
    }
    if ($status !== 'all' && $has_status) {
        $where[] = "`status` = ?";
        $params[] = $status;
        $types .= "s";
    }

    if (!empty($where)) $sel .= " WHERE " . implode(" AND ", $where);
    $sel .= " ORDER BY `{$name_col}` ASC";

    $stmt = @mysqli_prepare($conn, $sel);
    if ($stmt) {
        if ($types !== "" && !empty($params)) mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        $classes_tbl  = table_exists($conn, 'classes');
        $teachers_tbl = table_exists($conn, 'teachers');
        $students_tbl = table_exists($conn, 'students');

        $cnt_classes = $cnt_teachers = $cnt_students = null;
        if ($classes_tbl  && col_exists($conn, 'classes', 'school_id'))  $cnt_classes  = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM classes  WHERE school_id = ?");
        if ($teachers_tbl && col_exists($conn, 'teachers', 'school_id')) $cnt_teachers = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM teachers WHERE school_id = ?");
        if ($students_tbl && col_exists($conn, 'students', 'school_id')) $cnt_students = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM students WHERE school_id = ?");

        while ($row = mysqli_fetch_assoc($res)) {
            $sid = (int)$row['id'];

            $get_count = function($stmt) use ($sid) {
                if (!$stmt) return 0;
                mysqli_stmt_bind_param($stmt, "i", $sid);
                mysqli_stmt_execute($stmt);
                $r = mysqli_stmt_get_result($stmt);
                $n = $r ? (int)mysqli_fetch_assoc($r)['total'] : 0;
                return $n;
            };
            $row['classes_count']  = $get_count($cnt_classes);
            $row['teachers_count'] = $get_count($cnt_teachers);
            $row['students_count'] = $get_count($cnt_students);
            $schools[] = $row;
        }
        mysqli_stmt_close($stmt);

        foreach ([$cnt_classes, $cnt_teachers, $cnt_students] as $s) if ($s) mysqli_stmt_close($s);
    }
}

$total_schools  = count($schools);
$total_classes  = 0;
$total_teachers = 0;
$total_students = 0;
$active_count   = 0;
foreach ($schools as $s) {
    $total_classes  += (int)$s['classes_count'];
    $total_teachers += (int)$s['teachers_count'];
    $total_students += (int)$s['students_count'];
    if (strtolower($schools[0]['status'] ?? '') === 'active' || ($s['status'] ?? 'active') === 'active') $active_count++;
}
// Recompute active_count properly
$active_count = 0;
foreach ($schools as $s) if (($s['status'] ?? 'active') === 'active') $active_count++;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>School Accounts | Educ Bridge Admin</title>
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

    .page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap}
    .page-head h1{font-family:'Poppins',sans-serif;font-size:22px;font-weight:700;display:flex;align-items:center;gap:10px}
    .page-head h1 i{color:var(--primary)}
    .page-head .sub{font-size:13.5px;color:var(--text-muted);margin-top:4px}

    .alert{padding:13px 16px;border-radius:10px;margin-bottom:20px;font-size:14px;display:flex;align-items:flex-start;gap:10px}
    .alert-success{background:var(--success-light);color:#059669;border:1px solid rgba(16,185,129,.25)}
    .alert-error{background:var(--danger-light);color:#dc2626;border:1px solid rgba(239,68,68,.25)}
    [data-theme="dark"] .alert-success{color:#34d399}
    [data-theme="dark"] .alert-error{color:#f87171}

    /* KPI */
    .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:22px}
    .kpi-card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;transition:var(--transition)}
    .kpi-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:var(--primary)}
    .kpi-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0}
    .kpi-icon.blue{background:linear-gradient(135deg,#2563eb,#3b82f6)}
    .kpi-icon.green{background:linear-gradient(135deg,#10b981,#34d399)}
    .kpi-icon.purple{background:linear-gradient(135deg,#7c3aed,#a78bfa)}
    .kpi-icon.orange{background:linear-gradient(135deg,#f59e0b,#fbbf24)}
    .kpi-info .num{font-family:'Poppins',sans-serif;font-size:24px;font-weight:700;line-height:1.2}
    .kpi-info .lbl{font-size:12.5px;color:var(--text-muted);margin-top:3px;font-weight:500}

    /* Filters */
    .filters{background:var(--bg-card);border:1px solid var(--border-color);border-radius:14px;padding:16px 18px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
    .filter-group{position:relative;display:flex;align-items:center;flex:1 1 220px;min-width:180px}
    .filter-group i{position:absolute;left:13px;color:var(--text-muted);font-size:13px;pointer-events:none}
    .filter-group input,.filter-group select{width:100%;padding:11px 14px 11px 36px;border:1.5px solid var(--border-color);border-radius:10px;background:var(--bg-alt);color:var(--text-primary);font-family:inherit;font-size:13.5px;outline:none;transition:var(--transition)}
    .filter-group input:focus,.filter-group select:focus{border-color:var(--primary);background:var(--bg-card);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
    .filter-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:10px;font-size:13.5px;font-weight:600;cursor:pointer;border:none;background:var(--primary);color:#fff;font-family:inherit}
    .filter-btn:hover{background:var(--primary-dark)}
    .filter-reset{display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:10px;font-size:13.5px;font-weight:600;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);text-decoration:none}

    /* School cards */
    .schools-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(400px,1fr));gap:18px}
    .school-card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:18px;padding:22px;transition:var(--transition);position:relative;overflow:hidden}
    .school-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--success)}
    .school-card.inactive::before{background:var(--danger)}
    .school-card:hover{box-shadow:var(--shadow-md);transform:translateY(-3px);border-color:var(--primary)}

    .sc-head{display:flex;align-items:flex-start;gap:14px;margin-bottom:16px}
    .sc-avatar{width:56px;height:56px;border-radius:14px;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-size:20px;font-weight:700;flex-shrink:0;box-shadow:0 8px 20px rgba(37,99,235,.28)}
    .sc-info{flex:1;min-width:0}
    .sc-name{font-family:'Poppins',sans-serif;font-size:17px;font-weight:700;line-height:1.3;margin-bottom:4px}
    .sc-meta{font-size:12.5px;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:8px;align-items:center}
    .sc-meta .tag{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:50px;background:var(--bg-alt);border:1px solid var(--border-color);font-weight:600}

    .sc-pills{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px}
    .pill{display:inline-block;padding:3px 10px;border-radius:50px;font-size:11.5px;font-weight:700;line-height:1.5}
    .pill.green{background:rgba(16,185,129,.12);color:#059669}
    .pill.red{background:rgba(239,68,68,.12);color:#dc2626}
    .pill.blue{background:rgba(37,99,235,.12);color:#2563eb}
    .pill.gray{background:rgba(100,116,139,.14);color:#475569}
    .pill.purple{background:rgba(124,58,237,.12);color:#7c3aed}
    .pill.orange{background:rgba(245,158,11,.15);color:#b45309}
    [data-theme="dark"] .pill.green{color:#34d399}
    [data-theme="dark"] .pill.red{color:#f87171}
    [data-theme="dark"] .pill.blue{color:#93c5fd}
    [data-theme="dark"] .pill.gray{color:#cbd5e1}
    [data-theme="dark"] .pill.purple{color:#a78bfa}
    [data-theme="dark"] .pill.orange{color:#fbbf24}

    .sc-counts{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;padding:14px 0;border-top:1px dashed var(--border-color);border-bottom:1px dashed var(--border-color);margin-bottom:14px}
    .sc-count{text-align:center}
    .sc-count .num{font-family:'Poppins',sans-serif;font-size:19px;font-weight:700;color:var(--text-primary)}
    .sc-count .lbl{font-size:10.5px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.6px;margin-top:3px}
    .sc-count.classes .num{color:var(--primary)}
    .sc-count.teachers .num{color:var(--success)}
    .sc-count.students .num{color:var(--warning)}

    .sc-actions{display:flex;gap:8px;flex-wrap:wrap}
    .sc-btn{flex:1 1 auto;display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 12px;border-radius:10px;font-size:12.5px;font-weight:700;text-decoration:none;transition:var(--transition);border:1.5px solid var(--border-color);background:var(--bg-alt);color:var(--text-primary);cursor:pointer;font-family:inherit;white-space:nowrap}
    .sc-btn:hover{border-color:var(--primary);color:var(--primary);background:var(--bg-card)}
    .sc-btn.primary{background:var(--primary);color:#fff;border-color:var(--primary)}
    .sc-btn.primary:hover{background:var(--primary-dark);color:#fff}
    .sc-btn.danger{color:var(--danger);border-color:rgba(239,68,68,.35)}
    .sc-btn.danger:hover{background:var(--danger);color:#fff;border-color:var(--danger)}
    .sc-btn.success{color:#059669;border-color:rgba(16,185,129,.35)}
    .sc-btn.success:hover{background:var(--success);color:#fff;border-color:var(--success)}

    .empty-state{text-align:center;padding:60px 20px;color:var(--text-muted);background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px}
    .empty-state i{font-size:50px;opacity:.35;margin-bottom:14px;display:block}
    .empty-state h3{font-family:'Poppins',sans-serif;font-size:17px;font-weight:700;color:var(--text-primary);margin-bottom:6px}
    .empty-state p{font-size:14px;line-height:1.6}

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
        .page-head h1{font-size:18px}
        .schools-grid{grid-template-columns:1fr}
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
            <a href="admin-teachers.php" class="nav-item"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>

            <div class="nav-section-title">Management</div>
            <a href="admin-school-account.php" class="nav-item active"><i class="fas fa-id-card nav-icon"></i> School Accounts</a>
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
                    <div class="page-title">School Accounts</div>
                    <div class="page-subtitle">Manage every registered school</div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme" title="Toggle theme"><i class="fas fa-moon"></i></button>
                <div class="topbar-avatar" title="<?= htmlspecialchars($admin_name); ?>"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-id-card"></i> School Accounts</h1>
                    <div class="sub">Every school that registered on the platform — with its classes, teachers and students.</div>
                </div>
                <a href="admin-add-school.php" class="filter-btn" style="text-decoration:none;">
                    <i class="fas fa-plus"></i> Add School
                </a>
            </div>

            <?php if ($flash !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($flash_type); ?>">
                    <i class="fas <?= $flash_type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($flash); ?></span>
                </div>
            <?php endif; ?>

            <!-- KPI -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon blue"><i class="fas fa-school"></i></div>
                    <div class="kpi-info"><div class="num"><?= number_format($total_schools); ?></div><div class="lbl">Total Schools</div></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon green"><i class="fas fa-check-circle"></i></div>
                    <div class="kpi-info"><div class="num"><?= number_format($active_count); ?></div><div class="lbl">Active</div></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon purple"><i class="fas fa-layer-group"></i></div>
                    <div class="kpi-info"><div class="num"><?= number_format($total_classes); ?></div><div class="lbl">Classes</div></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon orange"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="kpi-info"><div class="num"><?= number_format($total_teachers); ?></div><div class="lbl">Teachers</div></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon purple"><i class="fas fa-user-graduate"></i></div>
                    <div class="kpi-info"><div class="num"><?= number_format($total_students); ?></div><div class="lbl">Students</div></div>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" action="admin-school-account.php" class="filters">
                <div class="filter-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Search school name, code, admin, email…" value="<?= htmlspecialchars($q); ?>">
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
                <a href="admin-school-account.php" class="filter-reset"><i class="fas fa-rotate"></i> Reset</a>
            </form>

            <!-- School cards -->
            <?php if (empty($schools)): ?>
                <div class="empty-state">
                    <i class="fas fa-school"></i>
                    <h3>No schools found</h3>
                    <p>
                        <?php if ($q !== '' || $status !== 'all'): ?>
                            No schools match your filters.
                        <?php else: ?>
                            No schools have been registered yet.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="schools-grid">
                    <?php foreach ($schools as $s):
                        $sid   = (int)$s['id'];
                        $name  = $s['school_name'] ?? 'Unnamed school';
                        $code  = $s['school_code'] ?? '';
                        $adm   = $s['admin_name']  ?? '';
                        $email = $s['email']       ?? '';
                        $phone = $s['phone']       ?? '';
                        $type  = $s['type']        ?? '';
                        $cty   = $s['country']     ?? '';
                        $prv   = $s['province']    ?? '';
                        $dst   = $s['district']    ?? '';
                        $st    = strtolower((string)($s['status'] ?? 'active'));
                        $created = $s['created_at'] ?? null;
                        $initial = init_of($name);
                        $is_active = ($st === 'active');
                    ?>
                        <div class="school-card <?= $is_active ? '' : 'inactive'; ?>">

                            <div class="sc-head">
                                <div class="sc-avatar"><?= htmlspecialchars($initial); ?></div>
                                <div class="sc-info">
                                    <div class="sc-name"><?= htmlspecialchars($name); ?></div>
                                    <div class="sc-meta">
                                        <?php if ($code): ?>
                                            <span class="tag"><i class="fas fa-hashtag"></i> <?= htmlspecialchars($code); ?></span>
                                        <?php endif; ?>
                                        <?php if ($type): ?>
                                            <span class="tag"><i class="fas fa-tag"></i> <?= htmlspecialchars($type); ?></span>
                                        <?php endif; ?>
                                        <?php if ($dst): ?>
                                            <span class="tag"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($dst); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="sc-pills">
                                <?php if ($is_active): ?>
                                    <span class="pill green"><i class="fas fa-circle"></i> Active</span>
                                <?php else: ?>
                                    <span class="pill red"><i class="fas fa-circle"></i> Inactive</span>
                                <?php endif; ?>
                                <?php if ($adm): ?>
                                    <span class="pill purple"><i class="fas fa-user-tie"></i> <?= htmlspecialchars($adm); ?></span>
                                <?php endif; ?>
                                <?php if ($email): ?>
                                    <span class="pill blue"><i class="fas fa-envelope"></i> <?= htmlspecialchars($email); ?></span>
                                <?php endif; ?>
                                <?php if ($phone): ?>
                                    <span class="pill gray"><i class="fas fa-phone"></i> <?= htmlspecialchars($phone); ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="sc-counts">
                                <div class="sc-count classes">
                                    <div class="num"><?= (int)$s['classes_count']; ?></div>
                                    <div class="lbl">Classes</div>
                                </div>
                                <div class="sc-count teachers">
                                    <div class="num"><?= (int)$s['teachers_count']; ?></div>
                                    <div class="lbl">Teachers</div>
                                </div>
                                <div class="sc-count students">
                                    <div class="num"><?= (int)$s['students_count']; ?></div>
                                    <div class="lbl">Students</div>
                                </div>
                            </div>

                            <div class="sc-actions">
                                <a href="admin-school-view.php?id=<?= $sid; ?>" class="sc-btn primary">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="admin-edit-school.php?id=<?= $sid; ?>" class="sc-btn">
                                    <i class="fas fa-pen"></i> Edit
                                </a>

                                <?php if ($is_active): ?>
                                    <form method="POST" action="admin-school-account.php" style="display:contents;"
                                          onsubmit="return confirm('Deactivate <?= htmlspecialchars($name, ENT_QUOTES); ?>? Students and teachers will be blocked from logging in.');">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="school_id" value="<?= $sid; ?>">
                                        <input type="hidden" name="new_status" value="inactive">
                                        <button type="submit" class="sc-btn danger">
                                            <i class="fas fa-ban"></i> Deactivate
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="admin-school-account.php" style="display:contents;">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="school_id" value="<?= $sid; ?>">
                                        <input type="hidden" name="new_status" value="active">
                                        <button type="submit" class="sc-btn success">
                                            <i class="fas fa-check"></i> Activate
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
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