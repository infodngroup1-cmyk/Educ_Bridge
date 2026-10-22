<?php
require_once 'db.php';
require_once '_school-auth.php';
/*
| After this require:
|   $school_id, $school_name, $school_admin, $school_code, $initials, $short_school
| are already defined and the user is guaranteed logged in.
*/

/*
|--------------------------------------------------------------------------
| HELPERS — defensive DB access
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
function s_count($conn, $sql, $types = "", $params = []) {
    $s = @mysqli_prepare($conn, $sql);
    if (!$s) return 0;
    if ($types !== "" && !empty($params)) mysqli_stmt_bind_param($s, $types, ...$params);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $n = $r ? (int) mysqli_fetch_assoc($r)['total'] : 0;
    mysqli_stmt_close($s);
    return $n;
}

$level_map = [
    'Nursery'=>'Nursery','Primary'=>'Primary',
    'O_Level'=>"O' Level",'A_Level'=>"A' Level",
];
$level_colors = [
    'Nursery'=>'#ec4899','Primary'=>'#2563eb',
    'O_Level'=>'#10b981','A_Level'=>'#f59e0b',
];

/*
|--------------------------------------------------------------------------
| KPI CARDS
|--------------------------------------------------------------------------
*/
$total_students = table_exists($conn, 'students')
    ? s_count($conn, "SELECT COUNT(*) AS total FROM students WHERE school_id = ?", "i", [$school_id])
    : 0;

$active_students = (table_exists($conn, 'students') && col_exists($conn, 'students', 'status'))
    ? s_count($conn, "SELECT COUNT(*) AS total FROM students WHERE school_id = ? AND status = 'active'", "i", [$school_id])
    : $total_students;

$total_teachers = table_exists($conn, 'teachers')
    ? s_count($conn, "SELECT COUNT(*) AS total FROM teachers WHERE school_id = ?", "i", [$school_id])
    : 0;

$active_teachers = (table_exists($conn, 'teachers') && col_exists($conn, 'teachers', 'status'))
    ? s_count($conn, "SELECT COUNT(*) AS total FROM teachers WHERE school_id = ? AND status = 'active'", "i", [$school_id])
    : $total_teachers;

$total_classes = table_exists($conn, 'classes')
    ? s_count($conn, "SELECT COUNT(*) AS total FROM classes WHERE school_id = ?", "i", [$school_id])
    : 0;

$active_classes = (table_exists($conn, 'classes') && col_exists($conn, 'classes', 'status'))
    ? s_count($conn, "SELECT COUNT(*) AS total FROM classes WHERE school_id = ? AND status = 'active'", "i", [$school_id])
    : $total_classes;

$total_subjects = table_exists($conn, 'subjects')
    ? s_count($conn, "SELECT COUNT(*) AS total FROM subjects WHERE school_id = ?", "i", [$school_id])
    : 0;

$total_announcements = table_exists($conn, 'announcements')
    ? s_count($conn, "SELECT COUNT(*) AS total FROM announcements WHERE school_id = ?", "i", [$school_id])
    : 0;

$total_events = table_exists($conn, 'events')
    ? s_count($conn, "SELECT COUNT(*) AS total FROM events WHERE school_id = ?", "i", [$school_id])
    : 0;

/*
|--------------------------------------------------------------------------
| AVERAGES
|--------------------------------------------------------------------------
*/
$avg_students_per_class  = $total_classes  > 0 ? round($total_students  / $total_classes, 1)  : 0;
$avg_students_per_teacher= $total_teachers > 0 ? round($total_students  / $total_teachers, 1) : 0;

$teacher_ratio  = $total_students > 0 ? round($total_students  / max(1, $total_teachers), 1) : 0;
$class_fill_pct = $total_classes > 0
    ? min(100, round(($total_students / max(1, $total_classes * 40)) * 100, 1))
    : 0;

/*
|--------------------------------------------------------------------------
| CHART 1 — Students per level
|--------------------------------------------------------------------------
*/
$students_per_level = ['Nursery'=>0,'Primary'=>0,'O_Level'=>0,'A_Level'=>0];
if (table_exists($conn, 'students')
    && col_exists($conn, 'students', 'class_id')
    && table_exists($conn, 'classes')
    && col_exists($conn, 'classes', 'level')) {
    $q = mysqli_prepare($conn, "
        SELECT c.level, COUNT(s.id) AS total
        FROM students s
        INNER JOIN classes c ON c.id = s.class_id
        WHERE s.school_id = ? AND c.school_id = ?
        GROUP BY c.level
    ");
    if ($q) {
        mysqli_stmt_bind_param($q, "ii", $school_id, $school_id);
        mysqli_stmt_execute($q);
        $res = mysqli_stmt_get_result($q);
        while ($r = mysqli_fetch_assoc($res)) {
            if (isset($students_per_level[$r['level']])) {
                $students_per_level[$r['level']] = (int)$r['total'];
            }
        }
        mysqli_stmt_close($q);
    }
}
$students_level_labels = [];
$students_level_values = [];
$students_level_colors = [];
foreach ($level_map as $enum => $disp) {
    $students_level_labels[] = $disp;
    $students_level_values[] = $students_per_level[$enum] ?? 0;
    $students_level_colors[] = $level_colors[$enum] ?? '#7c3aed';
}

/*
|--------------------------------------------------------------------------
| CHART 2 — Classes per level
|--------------------------------------------------------------------------
*/
$classes_per_level = ['Nursery'=>0,'Primary'=>0,'O_Level'=>0,'A_Level'=>0];
if (table_exists($conn, 'classes') && col_exists($conn, 'classes', 'level')) {
    $q = mysqli_prepare($conn, "SELECT level, COUNT(*) AS total FROM classes WHERE school_id = ? GROUP BY level");
    if ($q) {
        mysqli_stmt_bind_param($q, "i", $school_id);
        mysqli_stmt_execute($q);
        $res = mysqli_stmt_get_result($q);
        while ($r = mysqli_fetch_assoc($res)) {
            if (isset($classes_per_level[$r['level']])) {
                $classes_per_level[$r['level']] = (int)$r['total'];
            }
        }
        mysqli_stmt_close($q);
    }
}
$classes_level_labels = [];
$classes_level_values = [];
$classes_level_colors = [];
foreach ($level_map as $enum => $disp) {
    $classes_level_labels[] = $disp;
    $classes_level_values[] = $classes_per_level[$enum] ?? 0;
    $classes_level_colors[] = $level_colors[$enum] ?? '#7c3aed';
}

/*
|--------------------------------------------------------------------------
| CHART 3 — Teacher hiring trend (last 12 months)
|--------------------------------------------------------------------------
*/
$labels_month       = [];
$teachers_by_month  = [];
for ($i = 11; $i >= 0; $i--) $labels_month[] = date('M Y', strtotime("-$i months"));

if (table_exists($conn, 'teachers') && col_exists($conn, 'teachers', 'created_at')) {
    $q = mysqli_prepare($conn, "
        SELECT DATE_FORMAT(created_at, '%b %Y') AS label, COUNT(*) AS total
        FROM teachers
        WHERE school_id = ?
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY YEAR(created_at), MONTH(created_at)
    ");
    if ($q) {
        mysqli_stmt_bind_param($q, "i", $school_id);
        mysqli_stmt_execute($q);
        $res = mysqli_stmt_get_result($q);
        $map = [];
        while ($r = mysqli_fetch_assoc($res)) $map[$r['label']] = (int)$r['total'];
        mysqli_stmt_close($q);
        foreach ($labels_month as $lbl) $teachers_by_month[] = $map[$lbl] ?? 0;
    } else {
        $teachers_by_month = array_fill(0, 12, 0);
    }
} else {
    $teachers_by_month = array_fill(0, 12, 0);
}

/*
|--------------------------------------------------------------------------
| CHART 4 — Student enrollment trend (last 12 months)
|--------------------------------------------------------------------------
*/
$students_by_month = [];
if (table_exists($conn, 'students') && col_exists($conn, 'students', 'created_at')) {
    $q = mysqli_prepare($conn, "
        SELECT DATE_FORMAT(created_at, '%b %Y') AS label, COUNT(*) AS total
        FROM students
        WHERE school_id = ?
          AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY YEAR(created_at), MONTH(created_at)
    ");
    if ($q) {
        mysqli_stmt_bind_param($q, "i", $school_id);
        mysqli_stmt_execute($q);
        $res = mysqli_stmt_get_result($q);
        $map = [];
        while ($r = mysqli_fetch_assoc($res)) $map[$r['label']] = (int)$r['total'];
        mysqli_stmt_close($q);
        foreach ($labels_month as $lbl) $students_by_month[] = $map[$lbl] ?? 0;
    } else {
        $students_by_month = array_fill(0, 12, 0);
    }
} else {
    $students_by_month = array_fill(0, 12, 0);
}

/*
|--------------------------------------------------------------------------
| TOP CLASSES BY STUDENTS
|--------------------------------------------------------------------------
*/
$top_classes = [];
if (table_exists($conn, 'classes') && table_exists($conn, 'students') && col_exists($conn, 'students', 'class_id')) {
    $q = mysqli_prepare($conn, "
        SELECT c.id, c.class_name, c.level,
               (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS student_count
        FROM classes c
        WHERE c.school_id = ?
        ORDER BY student_count DESC, c.class_name ASC
        LIMIT 10
    ");
    if ($q) {
        mysqli_stmt_bind_param($q, "i", $school_id);
        mysqli_stmt_execute($q);
        $res = mysqli_stmt_get_result($q);
        while ($r = mysqli_fetch_assoc($res)) $top_classes[] = $r;
        mysqli_stmt_close($q);
    }
}

/*
|--------------------------------------------------------------------------
| TEACHER DISTRIBUTION BY LEVEL (teaching_level column)
|--------------------------------------------------------------------------
*/
$teacher_levels = ['Nursery'=>0,'Primary'=>0,'O_Level'=>0,'A_Level'=>0];
if (table_exists($conn, 'teachers') && col_exists($conn, 'teachers', 'teaching_level')) {
    $r = @mysqli_query($conn, "SELECT teaching_level FROM teachers WHERE school_id = " . (int)$school_id . " AND teaching_level IS NOT NULL AND teaching_level <> ''");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            foreach (array_map('trim', explode(',', $row['teaching_level'])) as $lv) {
                if ($lv === '') continue;
                $k = strtolower(str_replace(['-', ' '], ['', ''], $lv));
                switch ($k) {
                    case 'nursery': $teacher_levels['Nursery']++; break;
                    case 'primary': $teacher_levels['Primary']++; break;
                    case 'olevel':  $teacher_levels['O_Level']++; break;
                    case 'alevel':  $teacher_levels['A_Level']++; break;
                }
            }
        }
    }
}
$teacher_level_labels = [];
$teacher_level_values = [];
$teacher_level_colors = [];
foreach ($level_map as $enum => $disp) {
    $teacher_level_labels[] = $disp;
    $teacher_level_values[] = $teacher_levels[$enum] ?? 0;
    $teacher_level_colors[] = $level_colors[$enum] ?? '#7c3aed';
}

/*
|--------------------------------------------------------------------------
| RECENT ACTIVITY
|--------------------------------------------------------------------------
*/
$recent_students = [];
if (table_exists($conn, 'students') && col_exists($conn, 'students', 'created_at')) {
    $name_col = 'name';
    foreach (['name','full_name','student_name','first_name'] as $c) {
        if (col_exists($conn, 'students', $c)) { $name_col = $c; break; }
    }
    $q = mysqli_prepare($conn, "
        SELECT id, `{$name_col}` AS student_name, created_at
        FROM students
        WHERE school_id = ?
        ORDER BY created_at DESC
        LIMIT 6
    ");
    if ($q) {
        mysqli_stmt_bind_param($q, "i", $school_id);
        mysqli_stmt_execute($q);
        $res = mysqli_stmt_get_result($q);
        while ($r = mysqli_fetch_assoc($res)) $recent_students[] = $r;
        mysqli_stmt_close($q);
    }
}

$recent_teachers = [];
if (table_exists($conn, 'teachers') && col_exists($conn, 'teachers', 'created_at')) {
    $name_col = 'name';
    foreach (['name','full_name','teacher_name'] as $c) {
        if (col_exists($conn, 'teachers', $c)) { $name_col = $c; break; }
    }
    $q = mysqli_prepare($conn, "
        SELECT id, `{$name_col}` AS teacher_name, created_at
        FROM teachers
        WHERE school_id = ?
        ORDER BY created_at DESC
        LIMIT 6
    ");
    if ($q) {
        mysqli_stmt_bind_param($q, "i", $school_id);
        mysqli_stmt_execute($q);
        $res = mysqli_stmt_get_result($q);
        while ($r = mysqli_fetch_assoc($res)) $recent_teachers[] = $r;
        mysqli_stmt_close($q);
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports | Educ Bridge</title>
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
        --sidebar-title:#64748b;--sidebar-badge-bg:rgba(37,99,235,.25);--sidebar-badge-fg:#93c5fd;
        --bg-body:#f8fafc;--bg-card:#fff;--bg-alt:#f1f5f9;
        --text-primary:#0f172a;--text-secondary:#475569;--text-muted:#64748b;
        --border-color:#e2e8f0;
        --shadow-md:0 4px 12px rgba(0,0,0,.07);--shadow-lg:0 20px 45px rgba(0,0,0,.08);
        --shadow-sidebar:0 0 30px rgba(0,0,0,.05);
        --transition:all .3s cubic-bezier(.4,0,.2,1);
    }
    [data-theme="dark"]{
        --primary:#3b82f6;--primary-dark:#2563eb;--primary-light:#1e3a5f;
        --success:#34d399;--success-light:rgba(16,185,129,.15);
        --warning:#fbbf24;--warning-light:rgba(245,158,11,.18);
        --danger:#f87171;--danger-light:rgba(239,68,68,.15);
        --info:#38bdf8;
        --sidebar-bg:#0b1120;--sidebar-hover:rgba(255,255,255,.06);--sidebar-active:rgba(59,130,246,.2);
        --sidebar-text:#cbd5e1;--sidebar-text-active:#fff;--sidebar-border:rgba(255,255,255,.08);
        --sidebar-title:#64748b;--sidebar-badge-bg:rgba(59,130,246,.25);--sidebar-badge-fg:#93c5fd;
        --bg-body:#0b1120;--bg-card:#1e293b;--bg-alt:#1a2332;
        --text-primary:#f1f5f9;--text-secondary:#cbd5e1;--text-muted:#94a3b8;
        --border-color:#334155;
        --shadow-md:0 4px 12px rgba(0,0,0,.4);--shadow-lg:0 20px 45px rgba(0,0,0,.5);
        --shadow-sidebar:0 0 30px rgba(0,0,0,.4);
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',sans-serif;background:var(--bg-body);color:var(--text-primary);transition:background .4s ease,color .4s ease;overflow-x:hidden}
    .layout{display:flex;min-height:100vh}

    .sidebar{width:260px;background:var(--sidebar-bg);color:var(--sidebar-text);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;border-right:1px solid var(--sidebar-border);box-shadow:var(--shadow-sidebar)}
    .sidebar-header{padding:22px 22px 18px;border-bottom:1px solid var(--sidebar-border);display:flex;align-items:center;gap:12px}
    .sidebar-header img{width:42px;height:42px;object-fit:contain;border-radius:50%;border:2px solid var(--sidebar-border);padding:2px;background:#fff}
    .sidebar-brand{font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;color:#fff;line-height:1.2}
    .sidebar-brand span{color:var(--primary)}
    .sidebar-brand small{display:block;font-size:11px;color:#94a3b8;letter-spacing:.4px;margin-top:2px;text-transform:uppercase}
    .sidebar-nav{flex:1;padding:18px 12px;overflow-y:auto}
    .nav-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--sidebar-title);padding:12px 14px 8px}
    .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;color:var(--sidebar-text);text-decoration:none;font-size:14px;font-weight:500;border-radius:10px;margin-bottom:4px;transition:var(--transition);position:relative}
    .nav-item i.nav-icon{width:20px;text-align:center;font-size:15px}
    .nav-item:hover{background:var(--sidebar-hover);color:#fff}
    .nav-item:hover i.nav-icon{color:var(--primary)}
    .nav-item.active{background:var(--sidebar-active);color:#fff}
    .nav-item.active i.nav-icon{color:#93c5fd}
    .nav-item.active::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;background:var(--primary);border-radius:0 4px 4px 0}
    .nav-item .nav-badge{margin-left:auto;background:var(--sidebar-badge-bg);color:var(--sidebar-badge-fg);font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:50px;line-height:1.5}
    .sidebar-profile{border-top:1px solid var(--sidebar-border);padding:16px 18px;display:flex;align-items:center;gap:12px}
    .sidebar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.35)}
    .sidebar-profile-info{flex:1;min-width:0}
    .sidebar-profile-info .p-name{font-size:13.5px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-profile-info .p-role{font-size:11.5px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-logout{width:34px;height:34px;border-radius:8px;background:var(--danger-light);color:var(--danger);display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:13px;flex-shrink:0}
    .sidebar-logout:hover{background:var(--danger);color:#fff}

    .main{flex:1;margin-left:260px;min-height:100vh;display:flex;flex-direction:column}
    .topbar{background:var(--bg-card);border-bottom:1px solid var(--border-color);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50}
    .topbar-left{display:flex;align-items:center;gap:14px}
    .hamburger{display:none;width:40px;height:40px;border-radius:10px;border:1.5px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary);align-items:center;justify-content:center;cursor:pointer;font-size:15px}
    .page-title{font-family:'Poppins',sans-serif;font-size:20px;font-weight:700;color:var(--text-primary);letter-spacing:-.4px}
    .page-subtitle{font-size:13px;color:var(--text-muted);margin-top:2px}
    .topbar-actions{display:flex;align-items:center;gap:10px}
    .topbar-btn{width:42px;height:42px;border-radius:50%;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;text-decoration:none;transition:var(--transition)}
    .topbar-btn:hover{border-color:var(--primary);color:var(--primary)}
    .topbar-btn.theme-btn:hover{transform:rotate(20deg)}
    .topbar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.3)}

    .content{padding:28px;flex:1}

    .page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap}
    .page-head h1{font-family:'Poppins',sans-serif;font-size:22px;font-weight:700;display:flex;align-items:center;gap:10px}
    .page-head h1 i{color:var(--primary)}
    .page-head .sub{font-size:13.5px;color:var(--text-muted);margin-top:4px}

    /* KPI */
    .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:22px}
    .kpi-card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;transition:var(--transition)}
    .kpi-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:var(--primary)}
    .kpi-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0}
    .kpi-icon.violet{background:linear-gradient(135deg,#7c3aed,#a78bfa)}
    .kpi-icon.blue{background:linear-gradient(135deg,#2563eb,#3b82f6)}
    .kpi-icon.green{background:linear-gradient(135deg,#10b981,#34d399)}
    .kpi-icon.orange{background:linear-gradient(135deg,#f59e0b,#fbbf24)}
    .kpi-icon.pink{background:linear-gradient(135deg,#ec4899,#f472b6)}
    .kpi-icon.info{background:linear-gradient(135deg,#0ea5e9,#38bdf8)}
    .kpi-info .num{font-family:'Poppins',sans-serif;font-size:24px;font-weight:700;line-height:1.2}
    .kpi-info .lbl{font-size:12.5px;color:var(--text-muted);margin-top:3px;font-weight:500}
    .kpi-info .sub{font-size:11.5px;color:var(--text-muted);margin-top:2px}

    /* Charts */
    .charts-grid{display:grid;grid-template-columns:2fr 1fr;gap:18px;margin-bottom:22px}
    .charts-grid.two{grid-template-columns:1fr 1fr}
    @media (max-width:1100px){.charts-grid,.charts-grid.two{grid-template-columns:1fr}}

    .card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;overflow:hidden}
    .card-head{padding:18px 22px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:12px}
    .card-head h2{font-family:'Poppins',sans-serif;font-size:16px;font-weight:700;display:flex;align-items:center;gap:10px}
    .card-head h2 i{color:var(--primary)}
    .card-head .sub{font-size:12.5px;color:var(--text-muted);margin-left:auto}
    .card-body{padding:20px 22px}
    .chart-wrap{position:relative;height:300px}
    .chart-wrap.small{height:260px}

    .table-wrap{overflow-x:auto}
    table.data{width:100%;border-collapse:collapse}
    table.data th{text-align:left;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);padding:10px 14px;border-bottom:1px solid var(--border-color);background:var(--bg-alt)}
    table.data td{padding:12px 14px;font-size:13.5px;border-bottom:1px solid var(--border-color);color:var(--text-primary)}
    table.data tr:last-child td{border-bottom:none}
    table.data tr:hover td{background:var(--bg-alt)}
    .rank{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:var(--bg-alt);color:var(--text-muted);font-weight:700;font-size:11.5px}
    .rank.top1{background:linear-gradient(135deg,#f59e0b,#fbbf24);color:#fff}
    .rank.top2{background:linear-gradient(135deg,#94a3b8,#cbd5e1);color:#fff}
    .rank.top3{background:linear-gradient(135deg,#a16207,#ca8a04);color:#fff}
    .bar-mini{height:6px;background:var(--bg-alt);border-radius:4px;overflow:hidden;margin-top:6px}
    .bar-mini > span{display:block;height:100%;background:linear-gradient(90deg,#2563eb,#3b82f6);border-radius:4px}

    .empty{padding:40px 20px;text-align:center;color:var(--text-muted);font-size:13.5px}
    .empty i{font-size:36px;opacity:.35;margin-bottom:10px;display:block}

    .pill{display:inline-block;padding:3px 10px;border-radius:50px;font-size:11px;font-weight:700}
    .pill.green{background:rgba(16,185,129,.12);color:#059669}
    .pill.orange{background:rgba(245,158,11,.15);color:#b45309}
    .pill.gray{background:rgba(100,116,139,.14);color:#475569}
    .pill.blue{background:rgba(37,99,235,.12);color:#2563eb}
    [data-theme="dark"] .pill.green{color:#34d399}
    [data-theme="dark"] .pill.orange{color:#fbbf24}
    [data-theme="dark"] .pill.gray{color:#cbd5e1}
    [data-theme="dark"] .pill.blue{color:#93c5fd}

    .progress-block{display:flex;flex-direction:column;gap:14px}
    .progress-row .pr-head{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px}
    .progress-row .pr-head strong{color:var(--text-primary)}
    .progress-row .pr-head span{color:var(--text-muted);font-weight:600}
    .progress-row .bar{height:8px;background:var(--bg-alt);border-radius:6px;overflow:hidden}
    .progress-row .bar > span{display:block;height:100%;border-radius:6px;transition:width .6s ease}
    .bar-blue > span{background:linear-gradient(90deg,#2563eb,#3b82f6)}
    .bar-green > span{background:linear-gradient(90deg,#10b981,#34d399)}
    .bar-violet > span{background:linear-gradient(90deg,#7c3aed,#a78bfa)}
    .bar-orange > span{background:linear-gradient(90deg,#f59e0b,#fbbf24)}

    .overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:90;backdrop-filter:blur(2px)}
    .overlay.show{display:block}

    @media (max-width:900px){.sidebar{transform:translateX(-100%);transition:transform .3s}.sidebar.open{transform:translateX(0)}.main{margin-left:0}.hamburger{display:flex}}
    @media (max-width:600px){.content{padding:20px 16px}.topbar{padding:12px 16px}.page-title{font-size:17px}.page-head h1{font-size:18px}}
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
                    <div class="page-title">Reports</div>
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

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-chart-line"></i> School Reports</h1>
                    <div class="sub">Live overview of students, teachers, classes, subjects and activity</div>
                </div>
            </div>

            <!-- KPI CARDS -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon blue"><i class="fas fa-user-graduate"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($total_students); ?></div>
                        <div class="lbl">Students</div>
                        <div class="sub"><?= number_format($active_students); ?> active</div>
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
                    <div class="kpi-icon violet"><i class="fas fa-layer-group"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($total_classes); ?></div>
                        <div class="lbl">Classes</div>
                        <div class="sub"><?= number_format($active_classes); ?> active</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon orange"><i class="fas fa-book"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($total_subjects); ?></div>
                        <div class="lbl">Subjects</div>
                        <div class="sub">Registered in your school</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon pink"><i class="fas fa-bullhorn"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($total_announcements); ?></div>
                        <div class="lbl">Announcements</div>
                        <div class="sub">Published to your school</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon info"><i class="fas fa-calendar-alt"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($total_events); ?></div>
                        <div class="lbl">Events</div>
                        <div class="sub">Scheduled</div>
                    </div>
                </div>
            </div>

            <!-- CHARTS ROW 1 -->
            <div class="charts-grid">
                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-chart-column"></i> Enrollment Trend — Last 12 Months</h2>
                        <div class="sub">Students</div>
                    </div>
                    <div class="card-body">
                        <div class="chart-wrap">
                            <canvas id="chartEnrollment"></canvas>
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

            <!-- CHARTS ROW 2 -->
            <div class="charts-grid two">
                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-chart-bar"></i> Classes by Level</h2>
                    </div>
                    <div class="card-body">
                        <div class="chart-wrap small">
                            <canvas id="chartClassesLevel"></canvas>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-chalkboard-teacher"></i> Teachers by Level</h2>
                    </div>
                    <div class="card-body">
                        <div class="chart-wrap small">
                            <canvas id="chartTeachersLevel"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- AVERAGES + TOP CLASSES -->
            <div class="charts-grid two" style="margin-top:22px;">

                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-scale-balanced"></i> School Averages</h2>
                    </div>
                    <div class="card-body">
                        <div class="progress-block">

                            <div class="progress-row">
                                <div class="pr-head">
                                    <strong>Students per class</strong>
                                    <span><?= $avg_students_per_class; ?></span>
                                </div>
                                <div class="bar bar-blue"><span style="width: <?= min(100, $avg_students_per_class * 2); ?>%;"></span></div>
                            </div>

                            <div class="progress-row">
                                <div class="pr-head">
                                    <strong>Students per teacher</strong>
                                    <span><?= $avg_students_per_teacher; ?></span>
                                </div>
                                <div class="bar bar-green"><span style="width: <?= min(100, $avg_students_per_teacher * 3); ?>%;"></span></div>
                            </div>

                            <div class="progress-row">
                                <div class="pr-head">
                                    <strong>Class fill rate</strong>
                                    <span><?= $class_fill_pct; ?>%</span>
                                </div>
                                <div class="bar bar-violet"><span style="width: <?= $class_fill_pct; ?>%;"></span></div>
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
                                    <strong>Active teachers</strong>
                                    <span><?= $total_teachers > 0 ? round(($active_teachers / $total_teachers) * 100, 1) : 0; ?>%</span>
                                </div>
                                <div class="bar bar-green"><span style="width: <?= $total_teachers > 0 ? round(($active_teachers / $total_teachers) * 100) : 0; ?>%;"></span></div>
                            </div>

                            <div class="progress-row">
                                <div class="pr-head">
                                    <strong>Active classes</strong>
                                    <span><?= $total_classes > 0 ? round(($active_classes / $total_classes) * 100, 1) : 0; ?>%</span>
                                </div>
                                <div class="bar bar-orange"><span style="width: <?= $total_classes > 0 ? round(($active_classes / $total_classes) * 100) : 0; ?>%;"></span></div>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-trophy"></i> Top Classes by Students</h2>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (!empty($top_classes)): ?>
                            <div class="table-wrap">
                                <table class="data">
                                    <thead>
                                        <tr>
                                            <th style="width:60px;">#</th>
                                            <th>Class</th>
                                            <th style="width:110px; text-align:right;">Students</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                            $max = 1;
                                            foreach ($top_classes as $c) $max = max($max, (int)$c['student_count']);
                                            $rank = 1;
                                            foreach ($top_classes as $c):
                                                $rankClass = $rank === 1 ? 'top1' : ($rank === 2 ? 'top2' : ($rank === 3 ? 'top3' : ''));
                                                $pct = round(((int)$c['student_count'] / $max) * 100);
                                                $lv_col = $level_colors[$c['level']] ?? 'gray';
                                        ?>
                                            <tr>
                                                <td><span class="rank <?= $rankClass; ?>"><?= $rank; ?></span></td>
                                                <td>
                                                    <div style="font-weight:600;"><?= htmlspecialchars($c['class_name']); ?></div>
                                                    <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($level_map[$c['level']] ?? $c['level']); ?></div>
                                                    <div class="bar-mini"><span style="width: <?= $pct; ?>%;"></span></div>
                                                </td>
                                                <td style="text-align:right; font-weight:700;"><?= (int)$c['student_count']; ?></td>
                                            </tr>
                                        <?php $rank++; endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty">
                                <i class="fas fa-layer-group"></i>
                                No classes registered yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- RECENT ACTIVITY -->
            <div class="charts-grid two" style="margin-top:22px;">

                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-user-graduate"></i> Recently Added Students</h2>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (!empty($recent_students)): ?>
                            <div class="table-wrap">
                                <table class="data">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th style="width:180px;">Added</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_students as $s): ?>
                                            <tr>
                                                <td style="font-weight:600;"><?= htmlspecialchars($s['student_name']); ?></td>
                                                <td><span class="pill gray"><i class="fas fa-calendar"></i> <?= date('M j, Y', strtotime($s['created_at'])); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty">
                                <i class="fas fa-user-graduate"></i>
                                No students added yet.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-chalkboard-teacher"></i> Recently Added Teachers</h2>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (!empty($recent_teachers)): ?>
                            <div class="table-wrap">
                                <table class="data">
                                    <thead>
                                        <tr>
                                            <th>Teacher</th>
                                            <th style="width:180px;">Added</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_teachers as $t): ?>
                                            <tr>
                                                <td style="font-weight:600;"><?= htmlspecialchars($t['teacher_name']); ?></td>
                                                <td><span class="pill gray"><i class="fas fa-calendar"></i> <?= date('M j, Y', strtotime($t['created_at'])); ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty">
                                <i class="fas fa-chalkboard-teacher"></i>
                                No teachers added yet.
                            </div>
                        <?php endif; ?>
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

    /* SIDEBAR */
    (function () {
        const h = document.getElementById('hamburger');
        const s = document.getElementById('sidebar');
        const o = document.getElementById('overlay');
        if (h) h.addEventListener('click', function () {
            s.classList.toggle('open');
            o.classList.toggle('show', s.classList.contains('open'));
        });
        if (o) o.addEventListener('click', function () {
            s.classList.remove('open');
            o.classList.remove('show');
        });
    })();

    /* DATA */
    const DATA = {
        months:           <?= json_encode($labels_month); ?>,
        studentsTrend:    <?= json_encode($students_by_month); ?>,
        teachersTrend:    <?= json_encode($teachers_by_month); ?>,
        studentsLevel: {
            labels: <?= json_encode($students_level_labels); ?>,
            values: <?= json_encode($students_level_values); ?>,
            colors: <?= json_encode($students_level_colors); ?>
        },
        classesLevel: {
            labels: <?= json_encode($classes_level_labels); ?>,
            values: <?= json_encode($classes_level_values); ?>,
            colors: <?= json_encode($classes_level_colors); ?>
        },
        teachersLevel: {
            labels: <?= json_encode($teacher_level_labels); ?>,
            values: <?= json_encode($teacher_level_values); ?>,
            colors: <?= json_encode($teacher_level_colors); ?>
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
        Object.values(charts).forEach(c => { try { c.destroy(); } catch(e){} });
        charts = {};
    }
    function buildCharts() {
        const C = themeColors();
        const tooltip = {
            backgroundColor: C.tooltipBg,
            titleColor: C.tooltipFg,
            bodyColor: C.tooltipFg,
            borderColor: C.grid,
            borderWidth: 1, padding: 10, cornerRadius: 8
        };

        /* Enrollment trend */
        const el1 = document.getElementById('chartEnrollment');
        if (el1) {
            const ctx = el1.getContext('2d');
            const g1 = ctx.createLinearGradient(0, 0, 0, 260);
            g1.addColorStop(0, 'rgba(37,99,235,0.35)');
            g1.addColorStop(1, 'rgba(37,99,235,0.02)');
            const g2 = ctx.createLinearGradient(0, 0, 0, 260);
            g2.addColorStop(0, 'rgba(16,185,129,0.30)');
            g2.addColorStop(1, 'rgba(16,185,129,0.02)');

            charts.enroll = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: DATA.months,
                    datasets: [
                        { label: 'Students', data: DATA.studentsTrend, borderColor:'#2563eb', backgroundColor:g1, borderWidth:2.5, tension:.35, fill:true, pointRadius:3, pointBackgroundColor:'#2563eb' },
                        { label: 'Teachers', data: DATA.teachersTrend, borderColor:'#10b981', backgroundColor:g2, borderWidth:2.5, tension:.35, fill:true, pointRadius:3, pointBackgroundColor:'#10b981' }
                    ]
                },
                options: {
                    responsive:true, maintainAspectRatio:false,
                    plugins:{
                        legend:{ position:'bottom', labels:{ color:C.text, boxWidth:10, boxHeight:10, padding:14, font:{ size:12 } } },
                        tooltip
                    },
                    scales:{
                        x:{ grid:{ display:false }, ticks:{ color:C.text, font:{ size:11 } } },
                        y:{ beginAtZero:true, grid:{ color:C.grid }, ticks:{ color:C.text, precision:0, font:{ size:11 } } }
                    }
                }
            });
        }

        /* Students by level */
        const el2 = document.getElementById('chartStudentsLevel');
        if (el2) {
            charts.stuLvl = new Chart(el2.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: DATA.studentsLevel.labels,
                    datasets: [{ data: DATA.studentsLevel.values, backgroundColor: DATA.studentsLevel.colors, borderWidth:0, hoverOffset:6 }]
                },
                options: {
                    responsive:true, maintainAspectRatio:false, cutout:'62%',
                    plugins:{
                        legend:{ position:'bottom', labels:{ color:C.text, boxWidth:10, boxHeight:10, padding:12, font:{ size:12 } } },
                        tooltip
                    }
                }
            });
        }

        /* Classes by level */
        const el3 = document.getElementById('chartClassesLevel');
        if (el3) {
            charts.clsLvl = new Chart(el3.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: DATA.classesLevel.labels,
                    datasets: [{ label:'Classes', data: DATA.classesLevel.values, backgroundColor: DATA.classesLevel.colors, borderRadius:8, barThickness:42 }]
                },
                options: {
                    responsive:true, maintainAspectRatio:false,
                    plugins:{ legend:{ display:false }, tooltip },
                    scales:{
                        x:{ grid:{ display:false }, ticks:{ color:C.text, font:{ size:11 } } },
                        y:{ beginAtZero:true, grid:{ color:C.grid }, ticks:{ color:C.text, precision:0, font:{ size:11 } } }
                    }
                }
            });
        }

        /* Teachers by level */
        const el4 = document.getElementById('chartTeachersLevel');
        if (el4) {
            charts.tchLvl = new Chart(el4.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: DATA.teachersLevel.labels,
                    datasets: [{ label:'Teachers', data: DATA.teachersLevel.values, backgroundColor: DATA.teachersLevel.colors, borderRadius:8, barThickness:42 }]
                },
                options: {
                    responsive:true, maintainAspectRatio:false,
                    plugins:{ legend:{ display:false }, tooltip },
                    scales:{
                        x:{ grid:{ display:false }, ticks:{ color:C.text, font:{ size:11 } } },
                        y:{ beginAtZero:true, grid:{ color:C.grid }, ticks:{ color:C.text, precision:0, font:{ size:11 } } }
                    }
                }
            });
        }
    }
    function rebuildCharts() { destroyAll(); buildCharts(); }

    if (typeof Chart !== 'undefined') buildCharts();
    else window.addEventListener('load', buildCharts);
</script>

</body>
</html>