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

$admin_id   = (int) $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'System Admin';

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
| HELPERS
|--------------------------------------------------------------------------
*/
function table_exists($conn, $t) {
    $t = mysqli_real_escape_string($conn, $t);
    $r = @mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
    return ($r && mysqli_num_rows($r) > 0);
}

function column_exists($conn, $table, $col) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $col);
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return ($r && mysqli_num_rows($r) > 0);
}

function col_count($conn, $table, $where = '') {
    if (!table_exists($conn, $table)) return 0;
    $sql = "SELECT COUNT(*) AS total FROM `{$table}`";
    if ($where !== '') $sql .= " WHERE {$where}";
    $r = @mysqli_query($conn, $sql);
    if (!$r) return 0;
    $row = mysqli_fetch_assoc($r);
    return (int)($row['total'] ?? 0);
}

function col_sum($conn, $table, $col, $where = '') {
    if (!table_exists($conn, $table) || !column_exists($conn, $table, $col)) return 0;
    $sql = "SELECT COALESCE(SUM(`{$col}`),0) AS s FROM `{$table}`";
    if ($where !== '') $sql .= " WHERE {$where}";
    $r = @mysqli_query($conn, $sql);
    if (!$r) return 0;
    $row = mysqli_fetch_assoc($r);
    return $row['s'] ?? 0;
}

/*
|--------------------------------------------------------------------------
| COLUMN DETECTION
|--------------------------------------------------------------------------
*/
/* Schools */
$school_name_col = 'school_name';
foreach (['school_name','name','title','school'] as $c) {
    if (column_exists($conn, 'schools', $c)) { $school_name_col = $c; break; }
}
$school_status_col = column_exists($conn, 'schools', 'status') ? 'status' : null;
$school_created_col = null;
foreach (['created_at','created','registered_at'] as $c) {
    if (column_exists($conn, 'schools', $c)) { $school_created_col = $c; break; }
}

/* Teachers */
$teacher_status_col = column_exists($conn, 'teachers', 'status') ? 'status' : null;

/* Students */
$student_name_col = 'name';
foreach (['name','full_name','student_name'] as $c) {
    if (column_exists($conn, 'students', $c)) { $student_name_col = $c; break; }
}
$student_status_col  = column_exists($conn, 'students', 'status') ? 'status' : null;
$student_class_col   = column_exists($conn, 'students', 'class_id') ? 'class_id' : null;
$student_school_col  = column_exists($conn, 'students', 'school_id') ? 'school_id' : null;

/* Parents */
$parent_name_col = 'name';
if (table_exists($conn, 'parents')) {
    foreach (['name','full_name','parent_name'] as $c) {
        if (column_exists($conn, 'parents', $c)) { $parent_name_col = $c; break; }
    }
}
$parent_school_col = table_exists($conn, 'parents') && column_exists($conn, 'parents', 'school_id') ? 'school_id' : null;

/* Classes / Subjects */
$class_level_col  = column_exists($conn, 'classes', 'level') ? 'level' : null;
$class_status_col = column_exists($conn, 'classes', 'status') ? 'status' : null;
$class_school_col = column_exists($conn, 'classes', 'school_id') ? 'school_id' : null;

$subject_status_col = column_exists($conn, 'subjects', 'status') ? 'status' : null;
$subject_school_col = column_exists($conn, 'subjects', 'school_id') ? 'school_id' : null;

/*
|--------------------------------------------------------------------------
| TOP-LEVEL KPIs
|--------------------------------------------------------------------------
*/
$total_schools   = col_count($conn, 'schools');
$active_schools  = $school_status_col ? col_count($conn, 'schools', "`{$school_status_col}` = 'active'") : $total_schools;

$total_teachers  = col_count($conn, 'teachers');
$active_teachers = $teacher_status_col ? col_count($conn, 'teachers', "`{$teacher_status_col}` = 'active'") : $total_teachers;

$total_students  = col_count($conn, 'students');
$active_students = $student_status_col ? col_count($conn, 'students', "`{$student_status_col}` = 'active'") : $total_students;

$total_parents   = col_count($conn, 'parents');
$total_classes   = col_count($conn, 'classes');
$total_subjects  = col_count($conn, 'subjects');

$total_admin_ann = col_count($conn, 'admin_announcements');
$total_school_ann = col_count($conn, 'school_announcements');

/* Recipient rows (delivered messages) */
$admin_recipients   = col_count($conn, 'admin_announcement_recipients');
$school_recipients  = col_count($conn, 'school_announcement_recipients');

/* Read rate */
$admin_read   = table_exists($conn, 'admin_announcement_recipients')
              ? col_count($conn, 'admin_announcement_recipients', "is_read = 1")
              : 0;
$school_read  = table_exists($conn, 'school_announcement_recipients')
              ? col_count($conn, 'school_announcement_recipients', "is_read = 1")
              : 0;
$total_recipients_all = $admin_recipients + $school_recipients;
$total_read_all       = $admin_read + $school_read;
$read_rate = $total_recipients_all > 0
    ? round(($total_read_all / $total_recipients_all) * 100, 1)
    : 0;

/* Students per school average */
$avg_students_per_school = $total_schools > 0
    ? round($total_students / $total_schools, 1)
    : 0;

/* Teachers per school average */
$avg_teachers_per_school = $total_schools > 0
    ? round($total_teachers / $total_schools, 1)
    : 0;

/*
|--------------------------------------------------------------------------
| CHART DATA — schools per month (last 12 months)
|--------------------------------------------------------------------------
*/
$schools_by_month = [];
$labels_month = [];
if ($school_created_col) {
    for ($i = 11; $i >= 0; $i--) {
        $labels_month[] = date('M Y', strtotime("-$i months"));
    }
    $res = mysqli_query($conn, "
        SELECT DATE_FORMAT(`{$school_created_col}`, '%b %Y') AS label,
               COUNT(*) AS total
        FROM schools
        GROUP BY YEAR(`{$school_created_col}`), MONTH(`{$school_created_col}`)
        ORDER BY `{$school_created_col}` ASC
    ");
    $found = [];
    if ($res) { while ($r = mysqli_fetch_assoc($res)) $found[$r['label']] = (int)$r['total']; }
    foreach ($labels_month as $lbl) {
        $schools_by_month[] = $found[$lbl] ?? 0;
    }
} else {
    $labels_month = ['No date column'];
    $schools_by_month = [0];
}

/*
|--------------------------------------------------------------------------
| CHART DATA — students per level (if students has class_id)
|--------------------------------------------------------------------------
*/
$students_per_level = [];
if ($student_class_col && $class_level_col) {
    $res = mysqli_query($conn, "
        SELECT c.`{$class_level_col}` AS level,
               COUNT(s.id) AS total
        FROM students s
        INNER JOIN classes c ON c.id = s.`{$student_class_col}`
        GROUP BY c.`{$class_level_col}`
    ");
    if ($res) { while ($r = mysqli_fetch_assoc($res)) $students_per_level[$r['level']] = (int)$r['total']; }
}

/* Normalize labels and colors */
$level_display = [
    'Nursery' => 'Nursery', 'Primary' => 'Primary',
    'O_Level' => "O' Level", 'A_Level' => "A' Level",
];
$level_colors = [
    'Nursery' => '#ec4899',
    'Primary' => '#2563eb',
    'O_Level' => '#10b981',
    'A_Level' => '#f59e0b',
];
$level_labels = [];
$level_values = [];
$level_bgs    = [];
foreach ($level_display as $enum => $disp) {
    $level_labels[] = $disp;
    $level_values[] = $students_per_level[$enum] ?? 0;
    $level_bgs[]    = $level_colors[$enum];
}

/*
|--------------------------------------------------------------------------
| CHART DATA — audience mix for platform announcements
|--------------------------------------------------------------------------
*/
$audience_mix = [];
if (table_exists($conn, 'admin_announcements') && column_exists($conn, 'admin_announcements', 'audience')) {
    $res = mysqli_query($conn, "
        SELECT audience, COUNT(*) AS total
        FROM admin_announcements
        GROUP BY audience
    ");
    if ($res) { while ($r = mysqli_fetch_assoc($res)) $audience_mix[$r['audience']] = (int)$r['total']; }
}

/*
|--------------------------------------------------------------------------
| TOP SCHOOLS BY STUDENTS
|--------------------------------------------------------------------------
*/
$top_schools = [];
if ($student_school_col && $school_name_col) {
    $res = mysqli_query($conn, "
        SELECT s.id, s.`{$school_name_col}` AS school_name, COUNT(st.id) AS student_count
        FROM schools s
        LEFT JOIN students st ON st.`{$student_school_col}` = s.id
        GROUP BY s.id
        ORDER BY student_count DESC, school_name ASC
        LIMIT 10
    ");
    if ($res) { while ($r = mysqli_fetch_assoc($res)) $top_schools[] = $r; }
} else {
    /* Fallback: just list schools */
    $res = mysqli_query($conn, "SELECT id, `{$school_name_col}` AS school_name, 0 AS student_count FROM schools ORDER BY school_name ASC LIMIT 10");
    if ($res) { while ($r = mysqli_fetch_assoc($res)) $top_schools[] = $r; }
}

/*
|--------------------------------------------------------------------------
| RECENT SCHOOLS
|--------------------------------------------------------------------------
*/
$recent_schools = [];
if ($school_created_col) {
    $res = mysqli_query($conn, "
        SELECT id, `{$school_name_col}` AS school_name, `{$school_created_col}` AS created_at
        FROM schools
        ORDER BY `{$school_created_col}` DESC
        LIMIT 8
    ");
    if ($res) { while ($r = mysqli_fetch_assoc($res)) $recent_schools[] = $r; }
}

/*
|--------------------------------------------------------------------------
| TEACHER LEVEL DISTRIBUTION
|--------------------------------------------------------------------------
*/
$teacher_levels = [];
if (table_exists($conn, 'teachers') && column_exists($conn, 'teachers', 'teaching_level')) {
    $res = mysqli_query($conn, "SELECT teaching_level FROM teachers WHERE teaching_level IS NOT NULL AND teaching_level <> ''");
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            foreach (array_map('trim', explode(',', $r['teaching_level'])) as $lv) {
                if ($lv === '') continue;
                $key = strtolower($lv);
                $map = [
                    'nursery' => 'Nursery', 'primary' => 'Primary',
                    'olevel'  => 'O_Level', 'o_level' => 'O_Level', 'o-level' => 'O_Level',
                    'alevel'  => 'A_Level', 'a_level' => 'A_Level', 'a-level' => 'A_Level',
                ];
                $enum = $map[$key] ?? null;
                if ($enum === null) continue;
                $teacher_levels[$enum] = ($teacher_levels[$enum] ?? 0) + 1;
            }
        }
    }
}
$teacher_level_labels = [];
$teacher_level_values = [];
foreach ($level_display as $enum => $disp) {
    $teacher_level_labels[] = $disp;
    $teacher_level_values[] = $teacher_levels[$enum] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports | Educ Bridge Admin</title>
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
            --info: #0ea5e9;
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(124,58,237,0.22);
            --sidebar-text: #cbd5e1;
            --sidebar-border: rgba(255,255,255,0.08);
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
            --info: #38bdf8;
            --sidebar-bg: #0b1120;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(167,139,250,0.2);
            --sidebar-text: #cbd5e1;
            --sidebar-border: rgba(255,255,255,0.08);
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

        .page-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:22px; flex-wrap:wrap; }
        .page-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .page-head h1 i { color:var(--primary); }
        .page-head .sub { font-size:13.5px; color:var(--text-muted); margin-top:4px; }

        /* KPI grid */
        .kpi-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:16px; margin-bottom:22px; }
        .kpi-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; padding:18px 20px; display:flex; align-items:center; gap:14px; transition:var(--transition); position:relative; overflow:hidden; }
        .kpi-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); border-color:var(--primary); }
        .kpi-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; flex-shrink:0; }
        .kpi-icon.violet { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .kpi-icon.blue   { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .kpi-icon.green  { background: linear-gradient(135deg, #10b981, #34d399); }
        .kpi-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .kpi-icon.red    { background: linear-gradient(135deg, #ef4444, #f87171); }
        .kpi-icon.info   { background: linear-gradient(135deg, #0ea5e9, #38bdf8); }
        .kpi-icon.pink   { background: linear-gradient(135deg, #ec4899, #f472b6); }
        .kpi-icon.slate  { background: linear-gradient(135deg, #475569, #94a3b8); }

        .kpi-info { flex:1; min-width:0; }
        .kpi-info .num { font-family:'Poppins',sans-serif; font-size:24px; font-weight:700; line-height:1.2; letter-spacing:-0.4px; }
        .kpi-info .lbl { font-size:12.5px; color:var(--text-muted); margin-top:3px; font-weight:500; }
        .kpi-info .sub { font-size:11.5px; color:var(--text-muted); margin-top:2px; }

        /* Chart cards */
        .charts-grid { display:grid; grid-template-columns: 2fr 1fr; gap:18px; margin-bottom:22px; }
        .charts-grid.two { grid-template-columns: 1fr 1fr; }
        @media (max-width: 1100px) { .charts-grid, .charts-grid.two { grid-template-columns: 1fr; } }

        .card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; overflow:hidden; }
        .card-head { padding:18px 22px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:12px; }
        .card-head h2 { font-family:'Poppins',sans-serif; font-size:16px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .card-head h2 i { color:var(--primary); }
        .card-head .sub { font-size:12.5px; color:var(--text-muted); margin-left:auto; }
        .card-body { padding:20px 22px; }

        .chart-wrap { position: relative; height: 300px; }
        .chart-wrap.small { height: 260px; }

        /* Table */
        .table-wrap { overflow-x:auto; }
        table.data { width:100%; border-collapse: collapse; }
        table.data th { text-align:left; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; color:var(--text-muted); padding:10px 14px; border-bottom:1px solid var(--border-color); background:var(--bg-alt); }
        table.data td { padding:12px 14px; font-size:13.5px; border-bottom:1px solid var(--border-color); color:var(--text-primary); }
        table.data tr:last-child td { border-bottom: none; }
        table.data tr:hover td { background: var(--bg-alt); }
        .rank { display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--bg-alt); color:var(--text-muted); font-weight:700; font-size:11.5px; }
        .rank.top1 { background: linear-gradient(135deg,#f59e0b,#fbbf24); color:#fff; }
        .rank.top2 { background: linear-gradient(135deg,#94a3b8,#cbd5e1); color:#fff; }
        .rank.top3 { background: linear-gradient(135deg,#a16207,#ca8a04); color:#fff; }
        .bar-mini { height:6px; background:var(--bg-alt); border-radius:4px; overflow:hidden; margin-top:6px; }
        .bar-mini > span { display:block; height:100%; background:linear-gradient(90deg,#7c3aed,#a78bfa); border-radius:4px; }

        .empty { padding:40px 20px; text-align:center; color:var(--text-muted); font-size:13.5px; }
        .empty i { font-size:36px; opacity:0.35; margin-bottom:10px; display:block; }

        .pill { display:inline-block; padding:3px 10px; border-radius:50px; font-size:11px; font-weight:700; }
        .pill.green  { background: rgba(16,185,129,0.12); color: #059669; }
        .pill.orange { background: rgba(245,158,11,0.15); color: #b45309; }
        .pill.gray   { background: rgba(100,116,139,0.14);color: #475569; }
        [data-theme="dark"] .pill.green  { color: #34d399; }
        [data-theme="dark"] .pill.orange { color: #fbbf24; }
        [data-theme="dark"] .pill.gray   { color: #cbd5e1; }

        .progress-block { display:flex; flex-direction:column; gap:14px; }
        .progress-row .pr-head { display:flex; justify-content:space-between; font-size:13px; margin-bottom:6px; }
        .progress-row .pr-head strong { color: var(--text-primary); }
        .progress-row .pr-head span { color: var(--text-muted); font-weight: 600; }
        .progress-row .bar { height:8px; background:var(--bg-alt); border-radius:6px; overflow:hidden; }
        .progress-row .bar > span { display:block; height:100%; border-radius:6px; transition: width 0.6s ease; }
        .bar-violet > span { background: linear-gradient(90deg,#7c3aed,#a78bfa); }
        .bar-blue > span   { background: linear-gradient(90deg,#2563eb,#3b82f6); }
        .bar-green > span  { background: linear-gradient(90deg,#10b981,#34d399); }
        .bar-orange > span { background: linear-gradient(90deg,#f59e0b,#fbbf24); }

        @media (max-width: 900px) {
            .sidebar { transform:translateX(-100%); transition: transform 0.3s; }
            .sidebar.open { transform:translateX(0); }
            .main { margin-left:0; }
            .hamburger { display:flex; }
        }
        @media (max-width: 600px) {
            .content { padding:20px 16px; }
            .topbar { padding:12px 16px; }
            .page-title { font-size:17px; }
            .page-head h1 { font-size:18px; }
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
                    <div class="page-title">Reports</div>
                    <div class="page-subtitle">Platform-wide usage summary</div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                <div class="topbar-avatar"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-chart-line"></i> System Report</h1>
                    <div class="sub">Live overview of how the platform is being used</div>
                </div>
            </div>

            <!-- KPI cards -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon violet"><i class="fas fa-school"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($total_schools); ?></div>
                        <div class="lbl">Schools</div>
                        <div class="sub"><?= number_format($active_schools); ?> active</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon green"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($total_teachers); ?></div>
                        <div class="lbl">Teachers</div>
                        <div class="sub"><?= number_format($active_teachers); ?> active</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon blue"><i class="fas fa-user-graduate"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($total_students); ?></div>
                        <div class="lbl">Students</div>
                        <div class="sub"><?= number_format($active_students); ?> active</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon orange"><i class="fas fa-user-friends"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($total_parents); ?></div>
                        <div class="lbl">Parents</div>
                        <div class="sub">Linked to students</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon pink"><i class="fas fa-layer-group"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($total_classes); ?></div>
                        <div class="lbl">Classes</div>
                        <div class="sub">Across all schools</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon info"><i class="fas fa-book"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($total_subjects); ?></div>
                        <div class="lbl">Subjects</div>
                        <div class="sub">Registered across schools</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon slate"><i class="fas fa-bullhorn"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($total_admin_ann + $total_school_ann); ?></div>
                        <div class="lbl">Announcements</div>
                        <div class="sub"><?= number_format($total_admin_ann); ?> platform · <?= number_format($total_school_ann); ?> school</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon red"><i class="fas fa-envelope-open-text"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= $read_rate; ?>%</div>
                        <div class="lbl">Read Rate</div>
                        <div class="sub"><?= number_format($total_read_all); ?> of <?= number_format($total_recipients_all); ?> deliveries</div>
                    </div>
                </div>
            </div>

            <!-- Charts row 1 -->
            <div class="charts-grid">
                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-chart-column"></i> New Schools — Last 12 Months</h2>
                        <div class="sub">Growth trend</div>
                    </div>
                    <div class="card-body">
                        <div class="chart-wrap">
                            <canvas id="chartSchoolsTrend"></canvas>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-chart-pie"></i> Students by Level</h2>
                        <div class="sub">Distribution</div>
                    </div>
                    <div class="card-body">
                        <div class="chart-wrap small">
                            <canvas id="chartStudentsLevel"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts row 2 -->
            <div class="charts-grid two">
                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-chart-bar"></i> Teachers by Level</h2>
                    </div>
                    <div class="card-body">
                        <div class="chart-wrap small">
                            <canvas id="chartTeachersLevel"></canvas>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-bullseye"></i> Platform Announcements by Audience</h2>
                    </div>
                    <div class="card-body">
                        <div class="chart-wrap small">
                            <canvas id="chartAudience"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Averages + top schools -->
            <div class="charts-grid two" style="margin-top:22px;">

                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-scale-balanced"></i> Platform Averages</h2>
                    </div>
                    <div class="card-body">
                        <div class="progress-block">

                            <div class="progress-row">
                                <div class="pr-head">
                                    <strong>Students per school</strong>
                                    <span><?= $avg_students_per_school; ?></span>
                                </div>
                                <div class="bar bar-blue"><span style="width: <?= min(100, $avg_students_per_school * 2); ?>%;"></span></div>
                            </div>

                            <div class="progress-row">
                                <div class="pr-head">
                                    <strong>Teachers per school</strong>
                                    <span><?= $avg_teachers_per_school; ?></span>
                                </div>
                                <div class="bar bar-green"><span style="width: <?= min(100, $avg_teachers_per_school * 6); ?>%;"></span></div>
                            </div>

                            <div class="progress-row">
                                <div class="pr-head">
                                    <strong>Active schools</strong>
                                    <span><?= $total_schools > 0 ? round(($active_schools / $total_schools) * 100, 1) : 0; ?>%</span>
                                </div>
                                <div class="bar bar-violet"><span style="width: <?= $total_schools > 0 ? round(($active_schools / $total_schools) * 100) : 0; ?>%;"></span></div>
                            </div>

                            <div class="progress-row">
                                <div class="pr-head">
                                    <strong>Active teachers</strong>
                                    <span><?= $total_teachers > 0 ? round(($active_teachers / $total_teachers) * 100, 1) : 0; ?>%</span>
                                </div>
                                <div class="bar bar-green"><span style="width: <?= $total_teachers > 0 ? round(($active_teachers / $total_teachers) * 100) : 0; ?>%;"></span></div>
                            </div>

                            <div class="progress-row">
                                <div class="pr-head">
                                    <strong>Active students</strong>
                                    <span><?= $total_students > 0 ? round(($active_students / $total_students) * 100, 1) : 0; ?>%</span>
                                </div>
                                <div class="bar bar-blue"><span style="width: <?= $total_students > 0 ? round(($active_students / $total_students) * 100) : 0; ?>%;"></span></div>
                            </div>

                            <div class="progress-row">
                                <div class="pr-head">
                                    <strong>Announcement read rate</strong>
                                    <span><?= $read_rate; ?>%</span>
                                </div>
                                <div class="bar bar-orange"><span style="width: <?= $read_rate; ?>%;"></span></div>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-trophy"></i> Top Schools by Students</h2>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (!empty($top_schools)): ?>
                            <div class="table-wrap">
                                <table class="data">
                                    <thead>
                                        <tr>
                                            <th style="width:60px;">#</th>
                                            <th>School</th>
                                            <th style="width:120px; text-align:right;">Students</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                            $max_students = 1;
                                            foreach ($top_schools as $s) $max_students = max($max_students, (int)$s['student_count']);
                                            $rank = 1;
                                            foreach ($top_schools as $s):
                                                $rankClass = $rank === 1 ? 'top1' : ($rank === 2 ? 'top2' : ($rank === 3 ? 'top3' : ''));
                                                $pct = round(((int)$s['student_count'] / $max_students) * 100);
                                        ?>
                                            <tr>
                                                <td><span class="rank <?= $rankClass; ?>"><?= $rank; ?></span></td>
                                                <td>
                                                    <div style="font-weight:600;"><?= htmlspecialchars($s['school_name']); ?></div>
                                                    <div class="bar-mini"><span style="width: <?= $pct; ?>%;"></span></div>
                                                </td>
                                                <td style="text-align:right; font-weight:700;"><?= number_format((int)$s['student_count']); ?></td>
                                            </tr>
                                        <?php $rank++; endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty">
                                <i class="fas fa-school"></i>
                                No schools registered yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- Recent schools -->
            <?php if (!empty($recent_schools)): ?>
            <div class="card" style="margin-top:22px;">
                <div class="card-head">
                    <h2><i class="fas fa-clock-rotate-left"></i> Recently Registered Schools</h2>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="table-wrap">
                        <table class="data">
                            <thead>
                                <tr>
                                    <th>School</th>
                                    <th style="width:200px;">Registered</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_schools as $s): ?>
                                    <tr>
                                        <td style="font-weight:600;"><?= htmlspecialchars($s['school_name']); ?></td>
                                        <td>
                                            <span class="pill gray">
                                                <i class="fas fa-calendar"></i>
                                                <?= date('M j, Y · g:i A', strtotime($s['created_at'])); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    /* Theme toggle */
    (function () {
        const html = document.documentElement;
        const t = document.getElementById('themeToggle');
        const i = t.querySelector('i');
        function apply(theme) {
            html.setAttribute('data-theme', theme);
            localStorage.setItem('educbridge-theme', theme);
            if (theme === 'dark') { i.classList.remove('fa-moon'); i.classList.add('fa-sun'); }
            else { i.classList.remove('fa-sun'); i.classList.add('fa-moon'); }
            setTimeout(rebuildCharts, 30);
        }
        apply(localStorage.getItem('educbridge-theme') || 'light');
        t.addEventListener('click', function () {
            apply(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
        });
    })();

    /* Sidebar (mobile) */
    (function () {
        const h = document.getElementById('hamburger');
        const s = document.getElementById('sidebar');
        if (h) h.addEventListener('click', function () { s.classList.toggle('open'); });
    })();

    /* Data injected from PHP */
    const DATA = {
        schoolsTrend: {
            labels: <?= json_encode($labels_month); ?>,
            values: <?= json_encode($schools_by_month); ?>
        },
        studentsLevel: {
            labels: <?= json_encode($level_labels); ?>,
            values: <?= json_encode($level_values); ?>,
            colors: <?= json_encode($level_bgs); ?>
        },
        teachersLevel: {
            labels: <?= json_encode($teacher_level_labels); ?>,
            values: <?= json_encode($teacher_level_values); ?>
        },
        audience: {
            labels: <?= json_encode(array_map(function($a){
                return [
                    'schools' => 'Schools',
                    'parents' => 'Parents',
                    'all'     => 'Everyone',
                ][$a] ?? $a;
            }, array_keys($audience_mix))); ?>,
            values: <?= json_encode(array_values($audience_mix)); ?>
        }
    };

    let charts = {};

    function themeColors() {
        const dark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            text: dark ? '#cbd5e1' : '#475569',
            grid: dark ? 'rgba(148,163,184,0.12)' : 'rgba(15,23,42,0.06)',
            tooltipBg: dark ? '#1e293b' : '#ffffff',
            tooltipFg: dark ? '#f1f5f9' : '#0f172a',
        };
    }

    function destroyAll() {
        Object.values(charts).forEach(c => { try { c.destroy(); } catch (e) {} });
        charts = {};
    }

    function buildCharts() {
        const C = themeColors();

        /* 1. Schools trend — line */
        const ctxTrend = document.getElementById('chartSchoolsTrend');
        if (ctxTrend) {
            const ctx = ctxTrend.getContext('2d');
            const grad = ctx.createLinearGradient(0, 0, 0, 260);
            grad.addColorStop(0, 'rgba(124,58,237,0.35)');
            grad.addColorStop(1, 'rgba(124,58,237,0.02)');
            charts.trend = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: DATA.schoolsTrend.labels,
                    datasets: [{
                        label: 'New schools',
                        data: DATA.schoolsTrend.values,
                        borderColor: '#7c3aed',
                        backgroundColor: grad,
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 4,
                        pointBackgroundColor: '#7c3aed',
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

        /* 2. Students by level — doughnut */
        const ctxLevel = document.getElementById('chartStudentsLevel');
        if (ctxLevel) {
            charts.level = new Chart(ctxLevel.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: DATA.studentsLevel.labels,
                    datasets: [{
                        data: DATA.studentsLevel.values,
                        backgroundColor: DATA.studentsLevel.colors,
                        borderWidth: 0,
                        hoverOffset: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: C.text,
                                boxWidth: 10,
                                boxHeight: 10,
                                padding: 12,
                                font: { size: 12 }
                            }
                        },
                        tooltip: {
                            backgroundColor: C.tooltipBg,
                            titleColor: C.tooltipFg,
                            bodyColor: C.tooltipFg,
                            borderColor: C.grid,
                            borderWidth: 1,
                            padding: 10,
                            cornerRadius: 8,
                        }
                    }
                }
            });
        }

        /* 3. Teachers by level — bar */
        const ctxTeachers = document.getElementById('chartTeachersLevel');
        if (ctxTeachers) {
            charts.teachers = new Chart(ctxTeachers.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: DATA.teachersLevel.labels,
                    datasets: [{
                        label: 'Teachers',
                        data: DATA.teachersLevel.values,
                        backgroundColor: ['#ec4899','#2563eb','#10b981','#f59e0b'],
                        borderRadius: 8,
                        barThickness: 42,
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

        /* 4. Audience mix — polar/doughnut */
        const ctxAud = document.getElementById('chartAudience');
        if (ctxAud) {
            charts.aud = new Chart(ctxAud.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: DATA.audience.labels.length ? DATA.audience.labels : ['No data'],
                    datasets: [{
                        data: DATA.audience.values.length ? DATA.audience.values : [1],
                        backgroundColor: ['#2563eb','#f59e0b','#7c3aed','#10b981'],
                        borderWidth: 0,
                        hoverOffset: 6,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: C.text,
                                boxWidth: 10,
                                boxHeight: 10,
                                padding: 12,
                                font: { size: 12 }
                            }
                        },
                        tooltip: {
                            backgroundColor: C.tooltipBg,
                            titleColor: C.tooltipFg,
                            bodyColor: C.tooltipFg,
                            borderColor: C.grid,
                            borderWidth: 1,
                            padding: 10,
                            cornerRadius: 8,
                        }
                    }
                }
            });
        }
    }

    function rebuildCharts() {
        destroyAll();
        buildCharts();
    }

    /* Initial render */
    if (typeof Chart !== 'undefined') {
        buildCharts();
    } else {
        window.addEventListener('load', buildCharts);
    }
</script>

</body>
</html>