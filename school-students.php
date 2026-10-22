<?php
require_once 'db.php';
require_once '_school-auth.php';

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$level_filter   = $_GET['level']  ?? '';
$class_filter   = (int)($_GET['class'] ?? 0);
$q              = trim($_GET['q'] ?? '');
$status_filter  = $_GET['status'] ?? '';
$sex_filter     = $_GET['sex'] ?? '';

$level_map    = ['Nursery'=>'Nursery','Primary'=>'Primary','O_Level'=>"O' Level",'A_Level'=>"A' Level"];
$level_colors = ['Nursery'=>'pink','Primary'=>'blue','O_Level'=>'green','A_Level'=>'orange'];
$level_order  = ['Nursery','Primary','O_Level','A_Level'];

/*
|--------------------------------------------------------------------------
| FETCH CLASSES
|--------------------------------------------------------------------------
*/
$classes_all = [];
$cstmt = mysqli_prepare($conn, "
    SELECT c.id, c.class_name, c.level, c.academic_year
    FROM classes c
    WHERE c.school_id = ?
    ORDER BY FIELD(c.level,'Nursery','Primary','O_Level','A_Level'), c.class_name ASC
");
if ($cstmt) {
    mysqli_stmt_bind_param($cstmt, "i", $school_id);
    mysqli_stmt_execute($cstmt);
    $cres = mysqli_stmt_get_result($cstmt);
    while ($c = mysqli_fetch_assoc($cres)) $classes_all[] = $c;
    mysqli_stmt_close($cstmt);
}

/*
|--------------------------------------------------------------------------
| STATS
|--------------------------------------------------------------------------
*/
function level_count($conn, $school_id, $level) {
    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS t
        FROM students s
        LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id
        WHERE s.school_id = ?
          AND (s.level = ? OR c.level = ?)
    ");
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, "iss", $school_id, $level, $level);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $n = $r ? (int)mysqli_fetch_assoc($r)['t'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

function total_count($conn, $school_id) {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS t FROM students WHERE school_id = ?");
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, "i", $school_id);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $n = $r ? (int)mysqli_fetch_assoc($r)['t'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

function recent_count($conn, $school_id, $days = 7) {
    $stmt = mysqli_prepare($conn, "
        SELECT COUNT(*) AS t
        FROM students
        WHERE school_id = ?
          AND created_at >= (NOW() - INTERVAL ? DAY)
    ");
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, "ii", $school_id, $days);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    $n = $r ? (int)mysqli_fetch_assoc($r)['t'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

$counts_per_level = [];
foreach ($level_order as $lvl) {
    $counts_per_level[$lvl] = level_count($conn, $school_id, $lvl);
}
$total_students  = total_count($conn, $school_id);
$recent_students = recent_count($conn, $school_id, 7);

/*
|--------------------------------------------------------------------------
| FETCH STUDENTS — newest first, all in one list
|--------------------------------------------------------------------------
*/
$students = [];
$where  = ["s.school_id = ?"];
$params = [$school_id];
$types  = "i";

if ($class_filter > 0) {
    $where[] = "s.class_id = ?";
    $params[] = $class_filter;
    $types .= "i";
} elseif (in_array($level_filter, $level_order, true)) {
    $where[] = "(s.level = ? OR c.level = ?)";
    $params[] = $level_filter;
    $params[] = $level_filter;
    $types .= "ss";
}

if (in_array($status_filter, ['active','inactive'], true)) {
    $where[] = "s.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (in_array($sex_filter, ['Male','Female'], true)) {
    $where[] = "s.sex = ?";
    $params[] = $sex_filter;
    $types .= "s";
}

if ($q !== '') {
    $where[] = "(
        s.first_name LIKE ? OR s.last_name LIKE ? OR
        s.email LIKE ? OR s.phone LIKE ? OR
        s.student_code LIKE ? OR s.father_name LIKE ? OR
        s.mother_name LIKE ? OR s.guardian_name LIKE ? OR
        s.previous_school LIKE ?
    )";
    $like = "%{$q}%";
    for ($i = 0; $i < 9; $i++) { $params[] = $like; $types .= "s"; }
}

/* KEY CHANGE: ORDER BY id DESC — newest registrations at the top */
$sql = "
    SELECT
        s.id, s.student_code, s.first_name, s.last_name,
        s.sex, s.dob, s.class_id, s.level,
        s.status, s.preferred_contact,
        s.phone, s.email,
        s.father_phone, s.mother_phone, s.guardian_phone,
        s.disability, s.disability_note, s.avatar, s.created_at,
        c.class_name, c.level AS class_level
    FROM students s
    LEFT JOIN classes c ON c.id = s.class_id AND c.school_id = s.school_id
    WHERE " . implode(" AND ", $where) . "
    ORDER BY s.id DESC
";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $r = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($r)) $students[] = $row;
    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function full_name($row) {
    $fn = trim((string)($row['first_name'] ?? ''));
    $ln = trim((string)($row['last_name']  ?? ''));
    $n  = trim($fn . ' ' . $ln);
    return $n !== '' ? $n : 'Unnamed';
}

function initials_of($name) {
    $out = '';
    foreach (preg_split('/\s+/', trim($name)) as $p) {
        if ($p === '') continue;
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
        if (mb_strlen($out) >= 2) break;
    }
    return $out === '' ? 'S' : $out;
}

function fmt_dob($dob) {
    if (empty($dob)) return ['—', ''];
    $ts = strtotime($dob);
    if (!$ts) return ['—', ''];
    $age = (new DateTime($dob))->diff(new DateTime())->y;
    $age_txt = ($age >= 0 && $age < 100) ? '(' . $age . 'y)' : '';
    return [date('M j, Y', $ts), $age_txt];
}

function time_ago($ts) {
    if (empty($ts)) return '';
    $diff = time() - strtotime($ts);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($ts));
}

$message = ""; $message_type = "";
if (($_GET['deleted'] ?? '') === '1') { $message = "Student deleted."; $message_type = "success"; }
if (($_GET['activated'] ?? '') === '1') { $message = "Student activated."; $message_type = "success"; }
if (($_GET['deactivated'] ?? '') === '1') { $message = "Student deactivated."; $message_type = "success"; }
if (isset($_GET['error'])) { $message = urldecode($_GET['error']); $message_type = "error"; }
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students | Educ Bridge</title>
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
            --purple: #7c3aed;
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
            --purple: #a78bfa;
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

        .main { flex:1; margin-left:260px; min-height:100vh; display:flex; flex-direction:column; }
        .topbar { background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:16px; position:sticky; top:0; z-index:50; }
        .topbar-left { display:flex; align-items:center; gap:14px; }
        .hamburger { display:none; width:40px; height:40px; border-radius:10px; border:1.5px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); align-items:center; justify-content:center; cursor:pointer; font-size:15px; }
        .hamburger:hover { border-color:var(--primary); color:var(--primary); }
        .page-title { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; letter-spacing:-0.4px; }
        .page-subtitle { font-size:13px; color:var(--text-muted); margin-top:2px; }
        .topbar-actions { display:flex; align-items:center; gap:10px; }
        .topbar-btn { width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); text-decoration:none; }
        .topbar-btn:hover { border-color:var(--primary); color:var(--primary); }
        .topbar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:14px; font-weight:700; box-shadow:0 4px 12px rgba(59,130,246,0.3); cursor:pointer; }

        .content { padding:28px; flex:1; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; }
        .alert-success { background: var(--success-light); color:#059669; border:1px solid rgba(16,185,129,0.25); }
        [data-theme="dark"] .alert-success { color:#34d399; }
        .alert-error { background: var(--danger-light); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        [data-theme="dark"] .alert-error { color:#f87171; }

        .page-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:22px; flex-wrap:wrap; }
        .page-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .page-head h1 i { color:var(--primary); }
        .page-head .sub { font-size:13.5px; color:var(--text-muted); margin-top:4px; }

        .summaries { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:22px; }
        .sum-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; padding:18px; display:flex; align-items:center; gap:14px; transition:var(--transition); text-decoration:none; color:inherit; position:relative; overflow:hidden; }
        .sum-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--primary); }
        .sum-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); border-color:var(--primary); }
        .sum-card.active { border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,0.12); }
        .sum-card.pink::before   { background: linear-gradient(180deg, #ec4899, #f472b6); }
        .sum-card.blue::before   { background: linear-gradient(180deg, #2563eb, #3b82f6); }
        .sum-card.green::before  { background: linear-gradient(180deg, #10b981, #34d399); }
        .sum-card.orange::before { background: linear-gradient(180deg, #f59e0b, #fbbf24); }
        .sum-card.slate::before  { background: linear-gradient(180deg, #475569, #64748b); }
        .sum-card.violet::before { background: linear-gradient(180deg, #7c3aed, #a78bfa); }

        .sum-icon { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:19px; color:#fff; flex-shrink:0; }
        .sum-icon.pink   { background: linear-gradient(135deg, #ec4899, #f472b6); }
        .sum-icon.blue   { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .sum-icon.green  { background: linear-gradient(135deg, #10b981, #34d399); }
        .sum-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .sum-icon.slate  { background: linear-gradient(135deg, #475569, #64748b); }
        .sum-icon.violet { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .sum-info .num { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; line-height:1.1; }
        .sum-info .lbl { font-size:12.5px; color:var(--text-muted); margin-top:2px; }

        .filters { background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:14px 16px; margin-bottom:20px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .filter-group { position:relative; display:flex; align-items:center; flex:1 1 180px; min-width:150px; }
        .filter-group i { position:absolute; left:13px; color:var(--text-muted); font-size:13px; pointer-events:none; }
        .filter-group input, .filter-group select { width:100%; padding:10px 14px 10px 36px; border:1.5px solid var(--border-color); border-radius:10px; background:var(--bg-alt); color:var(--text-primary); font-family:inherit; font-size:13.5px; outline:none; transition:var(--transition); }
        .filter-group select { padding-left:36px; cursor:pointer; }
        .filter-group input:focus, .filter-group select:focus { border-color:var(--primary); background:var(--bg-card); box-shadow:0 0 0 3px rgba(37,99,235,0.1); }

        .btn { display:inline-flex; align-items:center; gap:8px; padding:11px 20px; border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); transition:var(--transition); font-family:'Inter',sans-serif; }
        .btn:hover { border-color:var(--primary); color:var(--primary); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }

        .card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; overflow:hidden; }
        .students-table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse: collapse; min-width: 900px; }
        thead th { background:var(--bg-alt); text-align:left; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.6px; color:var(--text-muted); padding:12px 16px; border-bottom:1px solid var(--border-color); white-space:nowrap; }
        tbody td { padding:12px 16px; font-size:13.5px; color:var(--text-primary); border-bottom:1px solid var(--border-color); vertical-align: middle; }
        tbody tr:hover { background: var(--bg-alt); }
        tbody tr:last-child td { border-bottom: none; }

        /* Highlight the newest rows */
        tbody tr.is-new {
            background: linear-gradient(90deg, rgba(16,185,129,0.08), transparent 45%);
        }
        tbody tr.is-new td:first-child { border-left: 3px solid var(--success); padding-left: 13px; }

        .st-cell { display:flex; align-items:center; gap:12px; }
        .st-avatar { width:38px; height:38px; border-radius:50%; background:linear-gradient(135deg,#2563eb,#7c3aed); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:13px; font-weight:700; flex-shrink:0; overflow:hidden; }
        .st-avatar img { width:100%; height:100%; object-fit:cover; }
        .st-info .n { font-weight:600; font-size:14px; }
        .st-info .t { font-size:11px; color:var(--text-muted); margin-top:1px; }

        .code-badge { display:inline-block; padding:4px 10px; border-radius:6px; font-family:'Courier New',monospace; font-size:12.5px; font-weight:700; letter-spacing:0.5px; background:rgba(37,99,235,0.10); color:var(--primary); border:1px dashed rgba(37,99,235,0.35); }
        [data-theme="dark"] .code-badge { color:#93c5fd; border-color:rgba(147,197,253,0.4); background:rgba(59,130,246,0.15); }

        .pill { display:inline-block; padding:3px 10px; border-radius:50px; font-size:11.5px; font-weight:700; }
        .pill.blue   { background: rgba(37,99,235,0.12);  color: #2563eb; }
        .pill.green  { background: rgba(16,185,129,0.12); color: #059669; }
        .pill.orange { background: rgba(245,158,11,0.15); color: #b45309; }
        .pill.red    { background: rgba(239,68,68,0.12);  color: #dc2626; }
        .pill.gray   { background: rgba(100,116,139,0.14);color: #475569; }
        .pill.pink   { background: rgba(236,72,153,0.12); color: #db2777; }
        .pill.new    { background: rgba(16,185,129,0.15); color: #047857; }
        [data-theme="dark"] .pill.blue   { color: #93c5fd; }
        [data-theme="dark"] .pill.green  { color: #34d399; }
        [data-theme="dark"] .pill.orange { color: #fbbf24; }
        [data-theme="dark"] .pill.red    { color: #f87171; }
        [data-theme="dark"] .pill.gray   { color: #cbd5e1; }
        [data-theme="dark"] .pill.pink   { color: #f9a8d4; }
        [data-theme="dark"] .pill.new    { color: #34d399; }

        .sex-cell { display:inline-flex; align-items:center; gap:6px; font-weight:600; }
        .sex-cell.male   { color:#2563eb; }
        .sex-cell.female { color:#db2777; }
        .sex-cell i { font-size:13px; }
        [data-theme="dark"] .sex-cell.male   { color:#93c5fd; }
        [data-theme="dark"] .sex-cell.female { color:#f9a8d4; }

        .dob-cell { display:inline-flex; align-items:center; gap:6px; font-size:13px; font-family:'Courier New',monospace; font-weight:600; }
        .dob-cell i { color:var(--text-muted); font-size:11px; }
        .dob-age { font-size:11.5px; color:var(--text-muted); margin-left:4px; }

        .action-menu-wrap { position:relative; display:inline-block; }
        .action-dots { width:32px; height:32px; border-radius:8px; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-muted); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:14px; transition:var(--transition); }
        .action-dots:hover { border-color:var(--primary); color:var(--primary); background:var(--primary-light); }
        .action-dots.open { border-color:var(--primary); background:var(--primary-light); color:var(--primary); }
        .action-dropdown { display:none; position:fixed; min-width:220px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; box-shadow:var(--shadow-lg); z-index:1000; overflow:hidden; }
        .action-dropdown.show { display:block; }
        .action-dropdown a { display:flex; align-items:center; gap:10px; padding:11px 16px; font-size:13.5px; color:var(--text-primary); text-decoration:none; transition:var(--transition); border-bottom:1px solid var(--border-color); }
        .action-dropdown a:last-child { border-bottom:none; }
        .action-dropdown a:hover { background:var(--bg-alt); }
        .action-dropdown a i { width:18px; text-align:center; font-size:14px; }
        .action-dropdown a.danger { color:var(--danger); }
        .action-dropdown a.danger:hover { background:var(--danger-light); }

        .empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; }
        .empty-state i { font-size:50px; opacity:0.35; margin-bottom:14px; display:block; }
        .empty-state h3 { font-family:'Poppins',sans-serif; font-size:17px; font-weight:700; color:var(--text-primary); margin-bottom:6px; }
        .empty-state p { font-size:14px; line-height:1.6; margin-bottom:18px; }

        .overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:90; }
        .overlay.show { display:block; }

        @media (max-width: 900px) {
            .sidebar { transform:translateX(-100%); }
            .sidebar.open { transform:translateX(0); }
            .main { margin-left:0; }
            .hamburger { display:flex; }
        }
        @media (max-width: 600px) {
            .content { padding:20px 16px; }
            .topbar { padding:12px 16px; }
            .page-title { font-size:17px; }
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
            <a href="school-students.php" class="nav-item active"><i class="fas fa-user-graduate nav-icon"></i> Students</a>
            <a href="school-teachers.php" class="nav-item"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>
            <a href="school-classes.php" class="nav-item"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
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
            <a href="school-logout.php" class="sidebar-logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="overlay" id="overlay"></div>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Students</div>
                    <div class="page-subtitle"><?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                <div class="topbar-avatar"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-user-graduate"></i> Students</h1>
                    <div class="sub">
                        <strong><?= $total_students; ?></strong> registered ·
                        <strong><?= $recent_students; ?></strong> new in the last 7 days
                    </div>
                </div>
                <a href="school-add-student.php" class="btn primary">
                    <i class="fas fa-plus"></i> Add Student
                </a>
            </div>

            <!-- Summary cards -->
            <div class="summaries">
                <a href="school-students.php" class="sum-card slate <?= $level_filter === '' ? 'active' : ''; ?>">
                    <div class="sum-icon slate"><i class="fas fa-users"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_students; ?></div>
                        <div class="lbl">All Students</div>
                    </div>
                </a>
                <a href="?recent=1" class="sum-card violet">
                    <div class="sum-icon violet"><i class="fas fa-bolt"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $recent_students; ?></div>
                        <div class="lbl">New (7 days)</div>
                    </div>
                </a>
                <?php foreach ($level_order as $lvl): ?>
                    <a href="?level=<?= urlencode($lvl); ?>"
                       class="sum-card <?= $level_colors[$lvl]; ?> <?= $level_filter === $lvl ? 'active' : ''; ?>">
                        <div class="sum-icon <?= $level_colors[$lvl]; ?>">
                            <?php $ic = ['Nursery'=>'fa-baby','Primary'=>'fa-book','O_Level'=>'fa-book-open','A_Level'=>'fa-graduation-cap']; ?>
                            <i class="fas <?= $ic[$lvl]; ?>"></i>
                        </div>
                        <div class="sum-info">
                            <div class="num"><?= $counts_per_level[$lvl]; ?></div>
                            <div class="lbl"><?= htmlspecialchars($level_map[$lvl]); ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Filters -->
            <form method="GET" class="filters">
                <div class="filter-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Search name, code, phone, parent" value="<?= htmlspecialchars($q); ?>">
                </div>

                <div class="filter-group">
                    <i class="fas fa-graduation-cap"></i>
                    <select name="level">
                        <option value="">All Levels</option>
                        <?php foreach ($level_order as $lvl): ?>
                            <option value="<?= $lvl; ?>" <?= $level_filter === $lvl ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($level_map[$lvl]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <i class="fas fa-chalkboard"></i>
                    <select name="class">
                        <option value="0">All Classes</option>
                        <?php foreach ($classes_all as $c): ?>
                            <option value="<?= (int)$c['id']; ?>" <?= $class_filter === (int)$c['id'] ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($c['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <i class="fas fa-venus-mars"></i>
                    <select name="sex">
                        <option value="">Any Sex</option>
                        <option value="Male"   <?= $sex_filter === 'Male'   ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?= $sex_filter === 'Female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>

                <div class="filter-group">
                    <i class="fas fa-toggle-on"></i>
                    <select name="status">
                        <option value="">Any Status</option>
                        <option value="active"   <?= $status_filter === 'active'   ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <button type="submit" class="btn primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="school-students.php" class="btn"><i class="fas fa-rotate"></i> Reset</a>
            </form>

            <!-- All students — newest first -->
            <?php if (!empty($students)): ?>
                <div class="card">
                    <div class="students-table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Class</th>
                                    <th>Sex</th>
                                    <th>Date of Birth</th>
                                    <th>Status</th>
                                    <th style="width:60px; text-align:center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($students as $s):
                                    $name = full_name($s);
                                    $initials_s = initials_of($name);
                                    $code = !empty($s['student_code']) ? $s['student_code'] : ('STD-' . str_pad($s['id'], 5, '0', STR_PAD_LEFT));
                                    $cls  = $s['class_name'] ?: 'Unassigned';

                                    $row_level  = $s['class_level'] ?: ($s['level'] ?? '');
                                    $row_lv_col = $level_colors[$row_level] ?? 'gray';

                                    list($dob_display, $age_txt) = fmt_dob($s['dob'] ?? '');

                                    $has_dis = (($s['disability'] ?? 'none') === 'yes');
                                    $status_class = ($s['status'] === 'active') ? 'green' : 'orange';

                                    /* Row is "new" if registered within the last 7 days */
                                    $is_new = false;
                                    if (!empty($s['created_at'])) {
                                        $is_new = (time() - strtotime($s['created_at'])) < 7 * 86400;
                                    }
                                ?>
                                    <tr class="<?= $is_new ? 'is-new' : ''; ?>">
                                        <td style="color:var(--text-muted);font-weight:600;"><?= $i++; ?></td>

                                        <td><span class="code-badge"><?= htmlspecialchars($code); ?></span></td>

                                        <td>
                                            <div class="st-cell">
                                                <div class="st-avatar">
                                                    <?php if (!empty($s['avatar'])): ?>
                                                        <img src="<?= htmlspecialchars($s['avatar']); ?>" alt="">
                                                    <?php else: ?>
                                                        <?= htmlspecialchars($initials_s); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="st-info">
                                                    <div class="n">
                                                        <?= htmlspecialchars($name); ?>
                                                        <?php if ($has_dis): ?>
                                                            <span class="pill orange" style="margin-left:6px;font-size:10px;"
                                                                  title="<?= htmlspecialchars($s['disability_note'] ?? ''); ?>">
                                                                <i class="fas fa-wheelchair"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($is_new): ?>
                                                            <span class="pill new" style="margin-left:6px;font-size:10px;">
                                                                <i class="fas fa-bolt"></i> NEW
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="t">
                                                        <i class="fas fa-clock" style="font-size:9px;opacity:0.6;"></i>
                                                        Registered <?= htmlspecialchars(time_ago($s['created_at'] ?? '')); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>

                                        <td><span class="pill <?= $row_lv_col; ?>"><?= htmlspecialchars($cls); ?></span></td>

                                        <td>
                                            <?php if ($s['sex'] === 'Male' || $s['sex'] === 'Female'): ?>
                                                <span class="sex-cell <?= strtolower($s['sex']); ?>">
                                                    <i class="fas fa-<?= $s['sex'] === 'Female' ? 'venus' : 'mars'; ?>"></i>
                                                    <?= htmlspecialchars($s['sex']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color:var(--text-muted);">—</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span class="dob-cell">
                                                <i class="fas fa-cake-candles"></i>
                                                <?= htmlspecialchars($dob_display); ?>
                                            </span>
                                            <?php if ($age_txt): ?>
                                                <span class="dob-age"><?= $age_txt; ?></span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <span class="pill <?= $status_class; ?>">
                                                <?= htmlspecialchars(ucfirst($s['status'])); ?>
                                            </span>
                                        </td>

                                        <td style="text-align:center;">
                                            <div class="action-menu-wrap">
                                                <button type="button" class="action-dots"
                                                        onclick="toggleActionMenu(event, this)"
                                                        aria-label="Actions">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <div class="action-dropdown">
                                                    <a href="school-view-student.php?id=<?= (int)$s['id']; ?>">
                                                        <i class="fas fa-eye" style="color:var(--primary);"></i>
                                                        <span>View</span>
                                                    </a>
                                                    <a href="school-edit-student.php?id=<?= (int)$s['id']; ?>">
                                                        <i class="fas fa-pen" style="color:var(--purple);"></i>
                                                        <span>Edit</span>
                                                    </a>
                                                    <?php if ($s['status'] === 'inactive'): ?>
                                                        <a href="school-activate-student.php?id=<?= (int)$s['id']; ?>">
                                                            <i class="fas fa-check-circle" style="color:var(--success);"></i>
                                                            <span>Activate</span>
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="school-deactivate-student.php?id=<?= (int)$s['id']; ?>">
                                                            <i class="fas fa-ban" style="color:var(--warning);"></i>
                                                            <span>Deactivate</span>
                                                        </a>
                                                    <?php endif; ?>
                                                    <a href="javascript:void(0)" class="danger"
                                                       onclick="confirmDelete(<?= (int)$s['id']; ?>, '<?= htmlspecialchars(addslashes($name)); ?>')">
                                                        <i class="fas fa-trash-alt"></i>
                                                        <span>Delete</span>
                                                    </a>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-user-graduate"></i>
                    <h3>No students found</h3>
                    <p>
                        <?php if ($q !== '' || $class_filter > 0 || $status_filter !== '' || $sex_filter !== '' || $level_filter !== ''): ?>
                            No students matched your filters.
                        <?php else: ?>
                            No students are registered in this school yet.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

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

    let openDropdown = null;
    let openBtn = null;

    function closeActionMenu() {
        if (openDropdown) {
            openDropdown.classList.remove('show');
            openDropdown.style.top = '';
            openDropdown.style.left = '';
        }
        if (openBtn) openBtn.classList.remove('open');
        openDropdown = null;
        openBtn = null;
    }

    function toggleActionMenu(e, btn) {
        e.stopPropagation();
        if (openBtn === btn) { closeActionMenu(); return; }
        closeActionMenu();

        const dropdown = btn.nextElementSibling;
        const rect = btn.getBoundingClientRect();

        dropdown.classList.add('show');
        const ddRect = dropdown.getBoundingClientRect();

        let top  = rect.bottom + 6;
        let left = rect.right - ddRect.width;

        if (top + ddRect.height > window.innerHeight - 8) top = rect.top - ddRect.height - 6;
        if (top < 8) top = 8;
        if (left < 8) left = 8;
        if (left + ddRect.width > window.innerWidth - 8) left = window.innerWidth - ddRect.width - 8;

        dropdown.style.top = top + 'px';
        dropdown.style.left = left + 'px';

        btn.classList.add('open');
        openBtn = btn;
        openDropdown = dropdown;
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.action-menu-wrap')) closeActionMenu();
    });
    window.addEventListener('scroll', closeActionMenu, true);
    window.addEventListener('resize', closeActionMenu);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeActionMenu();
    });

    function confirmDelete(id, name) {
        closeActionMenu();
        if (confirm('Delete ' + name + '? This cannot be undone.')) {
            window.location.href = 'school-delete-student.php?id=' + id;
        }
    }
</script>

</body>
</html>