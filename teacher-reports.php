<?php
session_start();
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| AUTH — teacher
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['teacher_id']) || empty($_SESSION['teacher_verified'])) {
    header("Location: teacher-login.php");
    exit;
}

$teacher_id    = (int) $_SESSION['teacher_id'];
$teacher_name  = $_SESSION['teacher_name']  ?? 'Teacher';
$school_id     = (int) ($_SESSION['teacher_school_id'] ?? 0);
$school_name   = $_SESSION['teacher_school_name'] ?? 'School';

$initials = "";
foreach (explode(" ", trim($teacher_name)) as $part) {
    if ($part !== "") { $initials .= strtoupper($part[0]); if (strlen($initials) >= 2) break; }
}
if ($initials === "") $initials = "T";
$short_school = function_exists('mb_strimwidth')
    ? mb_strimwidth($school_name, 0, 24, '…')
    : substr($school_name, 0, 24);

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
function c_count($conn, $sql, $types = "", $params = []) {
    $s = @mysqli_prepare($conn, $sql);
    if (!$s) return 0;
    if ($types !== "" && !empty($params)) mysqli_stmt_bind_param($s, $types, ...$params);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $n = $r ? (int) mysqli_fetch_assoc($r)['total'] : 0;
    mysqli_stmt_close($s);
    return $n;
}

/* Level display */
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
| KPI 1 — My classes / students
|--------------------------------------------------------------------------
| A teacher "manages" a class when classes.class_teacher_id = teacher_id.
*/
$my_classes_count = 0;
$my_students_count = 0;
if (table_exists($conn, 'classes') && col_exists($conn, 'classes', 'class_teacher_id')) {
    $my_classes_count = c_count($conn,
        "SELECT COUNT(*) AS total FROM classes WHERE school_id = ? AND class_teacher_id = ?",
        "ii", [$school_id, $teacher_id]);

    $my_students_count = c_count($conn, "
        SELECT COUNT(*) AS total FROM students s
        INNER JOIN classes c ON c.id = s.class_id
        WHERE c.school_id = ? AND c.class_teacher_id = ?
    ", "ii", [$school_id, $teacher_id]);
}

/*
|--------------------------------------------------------------------------
| KPI 2 — My subjects (via subject_class_teachers)
|--------------------------------------------------------------------------
*/
$my_subjects_count = 0;
if (table_exists($conn, 'subject_class_teachers') && table_exists($conn, 'subjects')) {
    $my_subjects_count = c_count($conn, "
        SELECT COUNT(DISTINCT s.id) AS total
        FROM subjects s
        INNER JOIN subject_class_teachers sct
                ON sct.subject_id = s.id AND sct.school_id = s.school_id
        WHERE s.school_id = ? AND sct.teacher_id = ?
    ", "ii", [$school_id, $teacher_id]);
}

/*
|--------------------------------------------------------------------------
| KPI 3 — Marks I entered
|--------------------------------------------------------------------------
*/
$marks_total = 0;
$has_marks   = table_exists($conn, 'marks');
$marks_has_teacher = $has_marks && col_exists($conn, 'marks', 'teacher_id');

if ($has_marks && col_exists($conn, 'marks', 'class_id') && col_exists($conn, 'marks', 'subject_id')) {
    if ($marks_has_teacher) {
        $marks_total = c_count($conn,
            "SELECT COUNT(*) AS total FROM marks WHERE school_id = ? AND teacher_id = ?",
            "ii", [$school_id, $teacher_id]);
    } else {
        /* Fallback: count marks in classes this teacher manages */
        $marks_total = c_count($conn, "
            SELECT COUNT(*) AS total FROM marks m
            INNER JOIN classes c ON c.id = m.class_id
            WHERE m.school_id = ? AND c.class_teacher_id = ?
        ", "ii", [$school_id, $teacher_id]);
    }
}

/*
|--------------------------------------------------------------------------
| KPI 4 — Attendance entries I recorded
|--------------------------------------------------------------------------
*/
$attendance_total = 0;
$attendance_has_teacher = false;
if (table_exists($conn, 'attendance')) {
    $attendance_has_teacher = col_exists($conn, 'attendance', 'teacher_id');
    if ($attendance_has_teacher) {
        $attendance_total = c_count($conn,
            "SELECT COUNT(*) AS total FROM attendance WHERE school_id = ? AND teacher_id = ?",
            "ii", [$school_id, $teacher_id]);
    } elseif (col_exists($conn, 'attendance', 'class_id')) {
        $attendance_total = c_count($conn, "
            SELECT COUNT(*) AS total FROM attendance a
            INNER JOIN classes c ON c.id = a.class_id
            WHERE a.school_id = ? AND c.class_teacher_id = ?
        ", "ii", [$school_id, $teacher_id]);
    }
}

/*
|--------------------------------------------------------------------------
| KPI 5 — Messages
|--------------------------------------------------------------------------
*/
$messages_sent     = 0;
$messages_received = 0;
if (table_exists($conn, 'messages')) {
    $messages_sent = c_count($conn,
        "SELECT COUNT(*) AS total FROM messages WHERE school_id = ? AND sender_teacher_id = ?",
        "ii", [$school_id, $teacher_id]);
    $messages_received = c_count($conn,
        "SELECT COUNT(*) AS total FROM messages WHERE school_id = ? AND teacher_id = ?",
        "ii", [$school_id, $teacher_id]);
}

/*
|--------------------------------------------------------------------------
| CHART 1 — My activity last 12 months (attendance + marks + messages)
|--------------------------------------------------------------------------
| Each point counts how many actions this teacher did that month.
*/
$labels_month = [];
for ($i = 11; $i >= 0; $i--) $labels_month[] = date('M Y', strtotime("-$i months"));

$activity_attendance = array_fill(0, 12, 0);
$activity_marks      = array_fill(0, 12, 0);
$activity_messages   = array_fill(0, 12, 0);

if (table_exists($conn, 'attendance') && col_exists($conn, 'attendance', 'att_date')) {
    $date_col = 'att_date';
    $where_t  = col_exists($conn, 'attendance', 'teacher_id') ? " AND teacher_id = ? " : "";
    $types    = "ii" . ($where_t ? "i" : "");
    $params   = $where_t ? [$school_id, strtotime('-11 months'), $teacher_id]
                         : [$school_id, strtotime('-11 months')];
    /* Simpler: build a query grouping by month and pull in one pass */
    if (col_exists($conn, 'attendance', 'teacher_id')) {
        $q = mysqli_prepare($conn, "
            SELECT DATE_FORMAT(`{$date_col}`, '%b %Y') AS label, COUNT(*) AS total
            FROM attendance
            WHERE school_id = ? AND teacher_id = ?
              AND `{$date_col}` >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY YEAR(`{$date_col}`), MONTH(`{$date_col}`)
        ");
        if ($q) {
            mysqli_stmt_bind_param($q, "ii", $school_id, $teacher_id);
            mysqli_stmt_execute($q);
            $res = mysqli_stmt_get_result($q);
            $map = [];
            while ($r = mysqli_fetch_assoc($res)) $map[$r['label']] = (int)$r['total'];
            foreach ($labels_month as $idx => $lbl) $activity_attendance[$idx] = $map[$lbl] ?? 0;
            mysqli_stmt_close($q);
        }
    }
}

if ($has_marks && col_exists($conn, 'marks', 'created_at')) {
    $date_col = 'created_at';
    if ($marks_has_teacher) {
        $q = mysqli_prepare($conn, "
            SELECT DATE_FORMAT(`{$date_col}`, '%b %Y') AS label, COUNT(*) AS total
            FROM marks
            WHERE school_id = ? AND teacher_id = ?
              AND `{$date_col}` >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY YEAR(`{$date_col}`), MONTH(`{$date_col}`)
        ");
        if ($q) {
            mysqli_stmt_bind_param($q, "ii", $school_id, $teacher_id);
            mysqli_stmt_execute($q);
            $res = mysqli_stmt_get_result($q);
            $map = [];
            while ($r = mysqli_fetch_assoc($res)) $map[$r['label']] = (int)$r['total'];
            foreach ($labels_month as $idx => $lbl) $activity_marks[$idx] = $map[$lbl] ?? 0;
            mysqli_stmt_close($q);
        }
    }
}

if (table_exists($conn, 'messages') && col_exists($conn, 'messages', 'created_at')) {
    $has_sender = col_exists($conn, 'messages', 'sender_teacher_id');
    $has_receiver = col_exists($conn, 'messages', 'teacher_id');
    if ($has_sender || $has_receiver) {
        $field = $has_sender ? 'sender_teacher_id' : 'teacher_id';
        $q = mysqli_prepare($conn, "
            SELECT DATE_FORMAT(created_at, '%b %Y') AS label, COUNT(*) AS total
            FROM messages
            WHERE school_id = ? AND `{$field}` = ?
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY YEAR(created_at), MONTH(created_at)
        ");
        if ($q) {
            mysqli_stmt_bind_param($q, "ii", $school_id, $teacher_id);
            mysqli_stmt_execute($q);
            $res = mysqli_stmt_get_result($q);
            $map = [];
            while ($r = mysqli_fetch_assoc($res)) $map[$r['label']] = (int)$r['total'];
            foreach ($labels_month as $idx => $lbl) $activity_messages[$idx] = $map[$lbl] ?? 0;
            mysqli_stmt_close($q);
        }
    }
}

/*
|--------------------------------------------------------------------------
| CHART 2 — Attendance mix over the last 30 days (present/late/excused/absent)
|--------------------------------------------------------------------------
*/
$att_mix = ['present' => 0, 'late' => 0, 'excused' => 0, 'absent' => 0];
if (table_exists($conn, 'attendance') && col_exists($conn, 'attendance', 'status')) {
    $has_teacher_col = col_exists($conn, 'attendance', 'teacher_id');

    $sql = $has_teacher_col
        ? "SELECT status, COUNT(*) AS total FROM attendance
           WHERE school_id = ? AND teacher_id = ?
             AND att_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
           GROUP BY status"
        : "SELECT status, COUNT(*) AS total FROM attendance
           WHERE school_id = ?
             AND att_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
           GROUP BY status";

    $q = mysqli_prepare($conn, $sql);
    if ($q) {
        if ($has_teacher_col) mysqli_stmt_bind_param($q, "ii", $school_id, $teacher_id);
        else                  mysqli_stmt_bind_param($q, "i",  $school_id);
        mysqli_stmt_execute($q);
        $res = mysqli_stmt_get_result($q);
        while ($r = mysqli_fetch_assoc($res)) {
            $k = strtolower($r['status']);
            if (isset($att_mix[$k])) $att_mix[$k] = (int)$r['total'];
        }
        mysqli_stmt_close($q);
    }
}
$att_mix_labels = ['Present','Late','Excused','Absent'];
$att_mix_values = [$att_mix['present'], $att_mix['late'], $att_mix['excused'], $att_mix['absent']];
$att_mix_colors = ['#10b981','#f59e0b','#2563eb','#ef4444'];
$att_total_period = array_sum($att_mix_values);

/*
|--------------------------------------------------------------------------
| CHART 3 — My classes by student count
|--------------------------------------------------------------------------
*/
$classes_chart_labels = [];
$classes_chart_values = [];
$classes_chart_colors = [];

if (!empty($my_classes_count)) {
    $q = mysqli_prepare($conn, "
        SELECT c.id, c.class_name, c.level,
               (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS student_count
        FROM classes c
        WHERE c.school_id = ? AND c.class_teacher_id = ?
        ORDER BY c.class_name ASC
        LIMIT 10
    ");
    if ($q) {
        mysqli_stmt_bind_param($q, "ii", $school_id, $teacher_id);
        mysqli_stmt_execute($q);
        $res = mysqli_stmt_get_result($q);
        while ($r = mysqli_fetch_assoc($res)) {
            $classes_chart_labels[] = $r['class_name'];
            $classes_chart_values[] = (int)$r['student_count'];
            $classes_chart_colors[] = $level_colors[$r['level']] ?? '#7c3aed';
        }
        mysqli_stmt_close($q);
    }
}

/*
|--------------------------------------------------------------------------
| CHART 4 — Marks per class (subject entries)
|--------------------------------------------------------------------------
*/
$marks_chart_labels = [];
$marks_chart_values = [];

if ($has_marks && col_exists($conn, 'marks', 'class_id')) {
    $has_teacher_col = col_exists($conn, 'marks', 'teacher_id');
    $sql = $has_teacher_col
        ? "SELECT c.class_name, COUNT(m.id) AS total
           FROM marks m
           INNER JOIN classes c ON c.id = m.class_id
           WHERE m.school_id = ? AND m.teacher_id = ?
           GROUP BY c.id
           ORDER BY total DESC
           LIMIT 10"
        : "SELECT c.class_name, COUNT(m.id) AS total
           FROM marks m
           INNER JOIN classes c ON c.id = m.class_id
           WHERE m.school_id = ? AND c.class_teacher_id = ?
           GROUP BY c.id
           ORDER BY total DESC
           LIMIT 10";

    $q = mysqli_prepare($conn, $sql);
    if ($q) {
        mysqli_stmt_bind_param($q, "ii", $school_id, $teacher_id);
        mysqli_stmt_execute($q);
        $res = mysqli_stmt_get_result($q);
        while ($r = mysqli_fetch_assoc($res)) {
            $marks_chart_labels[] = $r['class_name'];
            $marks_chart_values[] = (int)$r['total'];
        }
        mysqli_stmt_close($q);
    }
}

/*
|--------------------------------------------------------------------------
| AVERAGES / PERCENTAGES
|--------------------------------------------------------------------------
*/
$avg_students_per_class = $my_classes_count > 0
    ? round($my_students_count / $my_classes_count, 1)
    : 0;

$attendance_completeness = ($my_students_count > 0)
    ? min(100, round(($attendance_total / max(1, $my_students_count * 30)) * 100, 1))
    : 0;

$marks_completeness = ($my_students_count > 0)
    ? min(100, round(($marks_total / max(1, $my_students_count * 3)) * 100, 1))
    : 0;

$present_rate = $att_total_period > 0
    ? round(($att_mix['present'] / $att_total_period) * 100, 1)
    : 0;

/*
|--------------------------------------------------------------------------
| PER-CLASS BREAKDOWN TABLE
|--------------------------------------------------------------------------
| One row per class the teacher manages, with students / attendance / marks.
*/
$class_table = [];
if (!empty($my_classes_count)) {
    $q = mysqli_prepare($conn, "
        SELECT c.id, c.class_name, c.level,
               (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS student_count,
               (SELECT COUNT(*) FROM attendance a WHERE a.class_id = c.id) AS attendance_count,
               (SELECT COUNT(*) FROM marks m      WHERE m.class_id = c.id) AS marks_count
        FROM classes c
        WHERE c.school_id = ? AND c.class_teacher_id = ?
        ORDER BY c.class_name ASC
    ");
    if ($q) {
        mysqli_stmt_bind_param($q, "ii", $school_id, $teacher_id);
        mysqli_stmt_execute($q);
        $res = mysqli_stmt_get_result($q);
        while ($r = mysqli_fetch_assoc($res)) $class_table[] = $r;
        mysqli_stmt_close($q);
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports | Educ Bridge Teacher</title>
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
        --sidebar-bg:#fff;--sidebar-hover:rgba(37,99,235,.06);--sidebar-active:rgba(37,99,235,.10);
        --sidebar-text:#475569;--sidebar-text-active:#0f172a;--sidebar-border:#e2e8f0;--sidebar-title:#94a3b8;
        --sidebar-badge-bg:rgba(37,99,235,.12);--sidebar-badge-fg:#2563eb;
        --bg-body:#f8fafc;--bg-card:#fff;--bg-alt:#f1f5f9;
        --text-primary:#0f172a;--text-secondary:#475569;--text-muted:#64748b;
        --border-color:#e2e8f0;
        --shadow-sm:0 1px 3px rgba(0,0,0,.06);--shadow-md:0 4px 12px rgba(0,0,0,.07);
        --shadow-lg:0 20px 45px rgba(0,0,0,.08);--shadow-sidebar:0 0 30px rgba(0,0,0,.05);
        --transition:all .3s cubic-bezier(.4,0,.2,1);
    }
    [data-theme="dark"] {
        --primary:#3b82f6;--primary-dark:#2563eb;--primary-light:#1e3a5f;
        --success:#34d399;--success-light:rgba(16,185,129,.15);
        --warning:#fbbf24;--warning-light:rgba(245,158,11,.18);
        --danger:#f87171;--danger-light:rgba(239,68,68,.15);
        --info:#38bdf8;
        --sidebar-bg:#0f172a;--sidebar-hover:rgba(255,255,255,.06);--sidebar-active:rgba(59,130,246,.18);
        --sidebar-text:#cbd5e1;--sidebar-text-active:#fff;--sidebar-border:rgba(255,255,255,.08);--sidebar-title:#94a3b8;
        --sidebar-badge-bg:rgba(59,130,246,.2);--sidebar-badge-fg:#93c5fd;
        --bg-body:#0b1120;--bg-card:#1e293b;--bg-alt:#1a2332;
        --text-primary:#f1f5f9;--text-secondary:#cbd5e1;--text-muted:#94a3b8;
        --border-color:#334155;
        --shadow-sm:0 1px 3px rgba(0,0,0,.3);--shadow-md:0 4px 12px rgba(0,0,0,.4);
        --shadow-lg:0 20px 45px rgba(0,0,0,.5);--shadow-sidebar:0 0 30px rgba(0,0,0,.4);
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',sans-serif;background:var(--bg-body);color:var(--text-primary);transition:background .4s ease,color .4s ease}
    .layout{display:flex;min-height:100vh}

    .sidebar{width:260px;background:var(--sidebar-bg);color:var(--sidebar-text);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;border-right:1px solid var(--sidebar-border);box-shadow:var(--shadow-sidebar)}
    .sidebar-header{padding:22px 22px 18px;border-bottom:1px solid var(--sidebar-border);display:flex;align-items:center;gap:12px}
    .sidebar-header img{width:42px;height:42px;object-fit:contain;border-radius:50%;border:2px solid var(--sidebar-border);padding:2px;background:#fff}
    .sidebar-brand{font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;color:var(--text-primary);line-height:1.2}
    .sidebar-brand span{color:var(--primary)}
    .sidebar-brand small{display:block;font-size:11px;font-weight:500;color:var(--text-muted);letter-spacing:.4px;margin-top:2px;text-transform:uppercase}
    .sidebar-nav{flex:1;padding:18px 12px;overflow-y:auto}
    .nav-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--sidebar-title);padding:12px 14px 8px}
    .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;color:var(--sidebar-text);text-decoration:none;font-size:14px;font-weight:500;border-radius:10px;margin-bottom:4px;transition:var(--transition);position:relative}
    .nav-item i.nav-icon{width:20px;text-align:center;font-size:15px;color:var(--sidebar-text)}
    .nav-item:hover{background:var(--sidebar-hover);color:var(--sidebar-text-active)}
    .nav-item:hover i.nav-icon{color:var(--primary)}
    .nav-item.active{background:var(--sidebar-active);color:var(--sidebar-text-active)}
    .nav-item.active i.nav-icon{color:var(--primary)}
    .nav-item.active::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;background:var(--primary);border-radius:0 4px 4px 0}
    .sidebar-profile{border-top:1px solid var(--sidebar-border);padding:16px 18px;display:flex;align-items:center;gap:12px}
    .sidebar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.35)}
    .sidebar-profile-info{flex:1;min-width:0}
    .sidebar-profile-info .p-name{font-size:13.5px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-profile-info .p-role{font-size:11.5px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-logout{width:34px;height:34px;border-radius:8px;background:var(--danger-light);color:var(--danger);display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:13px;flex-shrink:0}

    .main{flex:1;margin-left:260px;min-height:100vh;display:flex;flex-direction:column}
    .topbar{background:var(--bg-card);border-bottom:1px solid var(--border-color);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50}
    .topbar-left{display:flex;align-items:center;gap:14px}
    .hamburger{display:none;width:40px;height:40px;border-radius:10px;border:1.5px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary);align-items:center;justify-content:center;cursor:pointer}
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

    /* KPI grid */
    .kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin-bottom:22px}
    .kpi-card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;transition:var(--transition);position:relative;overflow:hidden}
    .kpi-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:var(--primary)}
    .kpi-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0}
    .kpi-icon.violet{background:linear-gradient(135deg,#7c3aed,#a78bfa)}
    .kpi-icon.blue{background:linear-gradient(135deg,#2563eb,#3b82f6)}
    .kpi-icon.green{background:linear-gradient(135deg,#10b981,#34d399)}
    .kpi-icon.orange{background:linear-gradient(135deg,#f59e0b,#fbbf24)}
    .kpi-icon.red{background:linear-gradient(135deg,#ef4444,#f87171)}
    .kpi-icon.info{background:linear-gradient(135deg,#0ea5e9,#38bdf8)}
    .kpi-icon.pink{background:linear-gradient(135deg,#ec4899,#f472b6)}
    .kpi-info{flex:1;min-width:0}
    .kpi-info .num{font-family:'Poppins',sans-serif;font-size:24px;font-weight:700;line-height:1.2;letter-spacing:-.4px}
    .kpi-info .lbl{font-size:12.5px;color:var(--text-muted);margin-top:3px;font-weight:500}
    .kpi-info .sub{font-size:11.5px;color:var(--text-muted);margin-top:2px}

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
    .pill.red{background:rgba(239,68,68,.12);color:#dc2626}
    [data-theme="dark"] .pill.green{color:#34d399}
    [data-theme="dark"] .pill.orange{color:#fbbf24}
    [data-theme="dark"] .pill.gray{color:#cbd5e1}
    [data-theme="dark"] .pill.blue{color:#93c5fd}
    [data-theme="dark"] .pill.red{color:#f87171}

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
            <a href="teacher-dashboard.php" class="nav-item"><i class="fas fa-th-large nav-icon"></i> Dashboard</a>
            <div class="nav-section-title">Teaching</div>
            <a href="teacher-subjects.php" class="nav-item"><i class="fas fa-book nav-icon"></i> Subjects</a>
            <a href="teacher-classes.php" class="nav-item"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
            <a href="teacher-marks.php" class="nav-item"><i class="fas fa-clipboard-list nav-icon"></i> Marks</a>
            <div class="nav-section-title">Communication</div>
            <a href="teacher-announcements.php" class="nav-item"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>
            <a href="teacher-events.php" class="nav-item"><i class="fas fa-calendar-alt nav-icon"></i> Events</a>
            <div class="nav-section-title">System</div>
            <a href="teacher-reports.php" class="nav-item active"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
            <a href="teacher-settings.php" class="nav-item"><i class="fas fa-cog nav-icon"></i> Settings</a>
        </nav>
        <div class="sidebar-profile">
            <div class="sidebar-avatar"><?= htmlspecialchars($initials); ?></div>
            <div class="sidebar-profile-info">
                <div class="p-name"><?= htmlspecialchars($teacher_name); ?></div>
                <div class="p-role">Teacher · <?= htmlspecialchars($short_school); ?></div>
            </div>
            <a href="teacher-dashboard.php?logout=1" class="sidebar-logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="overlay" id="overlay"></div>

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Reports</div>
                    <div class="page-subtitle"><?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle"><i class="fas fa-moon"></i></button>
                <a href="teacher-messages.php" class="topbar-btn" title="Messages"><i class="fas fa-bell"></i></a>
                <div class="topbar-avatar"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-chart-line"></i> My Teaching Report</h1>
                    <div class="sub">Live overview of your classes, students, marks and attendance</div>
                </div>
            </div>

            <!-- KPI cards -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-icon violet"><i class="fas fa-layer-group"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($my_classes_count); ?></div>
                        <div class="lbl">My Classes</div>
                        <div class="sub">You are class teacher</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon blue"><i class="fas fa-user-graduate"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($my_students_count); ?></div>
                        <div class="lbl">My Students</div>
                        <div class="sub">Across my classes</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon green"><i class="fas fa-book"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($my_subjects_count); ?></div>
                        <div class="lbl">My Subjects</div>
                        <div class="sub">Linked through my classes</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon orange"><i class="fas fa-clipboard-list"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($marks_total); ?></div>
                        <div class="lbl">Marks Entered</div>
                        <div class="sub">Total mark entries</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon pink"><i class="fas fa-calendar-check"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($attendance_total); ?></div>
                        <div class="lbl">Attendance Records</div>
                        <div class="sub">Total entries recorded</div>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-icon info"><i class="fas fa-envelope"></i></div>
                    <div class="kpi-info">
                        <div class="num"><?= number_format($messages_sent + $messages_received); ?></div>
                        <div class="lbl">Messages</div>
                        <div class="sub"><?= number_format($messages_sent); ?> sent · <?= number_format($messages_received); ?> received</div>
                    </div>
                </div>
            </div>

            <!-- Charts row 1 -->
            <div class="charts-grid">
                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-chart-column"></i> My Activity — Last 12 Months</h2>
                        <div class="sub">Attendance · Marks · Messages</div>
                    </div>
                    <div class="card-body">
                        <div class="chart-wrap">
                            <canvas id="chartActivity"></canvas>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-chart-pie"></i> Attendance Mix</h2>
                        <div class="sub">Last 30 days</div>
                    </div>
                    <div class="card-body">
                        <div class="chart-wrap small">
                            <canvas id="chartAttendanceMix"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts row 2 -->
            <div class="charts-grid two">
                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-users"></i> Students per Class</h2>
                    </div>
                    <div class="card-body">
                        <div class="chart-wrap small">
                            <canvas id="chartStudentsPerClass"></canvas>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-chart-bar"></i> Marks Entered per Class</h2>
                    </div>
                    <div class="card-body">
                        <div class="chart-wrap small">
                            <canvas id="chartMarksPerClass"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Averages + per-class table -->
            <div class="charts-grid two" style="margin-top:22px;">

                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-scale-balanced"></i> My Averages</h2>
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
                                    <strong>Attendance completeness</strong>
                                    <span><?= $attendance_completeness; ?>%</span>
                                </div>
                                <div class="bar bar-violet"><span style="width: <?= $attendance_completeness; ?>%;"></span></div>
                            </div>

                            <div class="progress-row">
                                <div class="pr-head">
                                    <strong>Marks completeness</strong>
                                    <span><?= $marks_completeness; ?>%</span>
                                </div>
                                <div class="bar bar-green"><span style="width: <?= $marks_completeness; ?>%;"></span></div>
                            </div>

                            <div class="progress-row">
                                <div class="pr-head">
                                    <strong>Present rate (30 days)</strong>
                                    <span><?= $present_rate; ?>%</span>
                                </div>
                                <div class="bar bar-orange"><span style="width: <?= $present_rate; ?>%;"></span></div>
                            </div>

                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-trophy"></i> My Classes — Ranked</h2>
                    </div>
                    <div class="card-body" style="padding:0;">
                        <?php if (!empty($class_table)): ?>
                            <div class="table-wrap">
                                <table class="data">
                                    <thead>
                                        <tr>
                                            <th style="width:60px;">#</th>
                                            <th>Class</th>
                                            <th style="width:90px; text-align:right;">Students</th>
                                            <th style="width:110px; text-align:right;">Attendance</th>
                                            <th style="width:90px; text-align:right;">Marks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                            $max_s = 1;
                                            foreach ($class_table as $c) $max_s = max($max_s, (int)$c['student_count']);
                                            $rank = 1;
                                            foreach ($class_table as $c):
                                                $rankClass = $rank === 1 ? 'top1' : ($rank === 2 ? 'top2' : ($rank === 3 ? 'top3' : ''));
                                                $pct = round(((int)$c['student_count'] / $max_s) * 100);
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
                                                <td style="text-align:right; color:var(--text-muted);"><?= (int)$c['attendance_count']; ?></td>
                                                <td style="text-align:right; color:var(--text-muted);"><?= (int)$c['marks_count']; ?></td>
                                            </tr>
                                        <?php $rank++; endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty">
                                <i class="fas fa-layer-group"></i>
                                You are not the class teacher of any classes yet.
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
            if (o) o.classList.toggle('show', s.classList.contains('open'));
        });
        if (o) o.addEventListener('click', function () {
            s.classList.remove('open');
            o.classList.remove('show');
        });
    })();

    /* DATA injected from PHP */
    const DATA = {
        activity: {
            labels: <?= json_encode($labels_month); ?>,
            attendance: <?= json_encode($activity_attendance); ?>,
            marks: <?= json_encode($activity_marks); ?>,
            messages: <?= json_encode($activity_messages); ?>
        },
        attMix: {
            labels: <?= json_encode($att_mix_labels); ?>,
            values: <?= json_encode($att_mix_values); ?>,
            colors: <?= json_encode($att_mix_colors); ?>
        },
        studentsPerClass: {
            labels: <?= json_encode($classes_chart_labels); ?>,
            values: <?= json_encode($classes_chart_values); ?>,
            colors: <?= json_encode($classes_chart_colors); ?>
        },
        marksPerClass: {
            labels: <?= json_encode($marks_chart_labels); ?>,
            values: <?= json_encode($marks_chart_values); ?>
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
            borderWidth: 1,
            padding: 10,
            cornerRadius: 8,
        };

        /* 1 — My activity: multi-line */
        const ctxA = document.getElementById('chartActivity');
        if (ctxA) {
            charts.activity = new Chart(ctxA.getContext('2d'), {
                type: 'line',
                data: {
                    labels: DATA.activity.labels,
                    datasets: [
                        {
                            label: 'Attendance',
                            data: DATA.activity.attendance,
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37,99,235,0.08)',
                            borderWidth: 2.5,
                            tension: 0.35,
                            fill: true,
                            pointRadius: 3,
                            pointBackgroundColor: '#2563eb',
                        },
                        {
                            label: 'Marks',
                            data: DATA.activity.marks,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16,185,129,0.06)',
                            borderWidth: 2.5,
                            tension: 0.35,
                            fill: true,
                            pointRadius: 3,
                            pointBackgroundColor: '#10b981',
                        },
                        {
                            label: 'Messages',
                            data: DATA.activity.messages,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245,158,11,0.05)',
                            borderWidth: 2.5,
                            tension: 0.35,
                            fill: true,
                            pointRadius: 3,
                            pointBackgroundColor: '#f59e0b',
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { color: C.text, boxWidth: 10, boxHeight: 10, padding: 14, font: { size: 12 } }
                        },
                        tooltip
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: C.text, font: { size: 11 } } },
                        y: { beginAtZero: true, grid: { color: C.grid }, ticks: { color: C.text, precision: 0, font: { size: 11 } } }
                    }
                }
            });
        }

        /* 2 — Attendance mix: doughnut */
        const ctxM = document.getElementById('chartAttendanceMix');
        if (ctxM) {
            charts.attMix = new Chart(ctxM.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: DATA.attMix.labels,
                    datasets: [{
                        data: DATA.attMix.values,
                        backgroundColor: DATA.attMix.colors,
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
                            labels: { color: C.text, boxWidth: 10, boxHeight: 10, padding: 12, font: { size: 12 } }
                        },
                        tooltip
                    }
                }
            });
        }

        /* 3 — Students per class: horizontal-ish bar */
        const ctxS = document.getElementById('chartStudentsPerClass');
        if (ctxS) {
            charts.studentsPerClass = new Chart(ctxS.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: DATA.studentsPerClass.labels.length ? DATA.studentsPerClass.labels : ['No classes'],
                    datasets: [{
                        label: 'Students',
                        data: DATA.studentsPerClass.values.length ? DATA.studentsPerClass.values : [0],
                        backgroundColor: DATA.studentsPerClass.colors.length ? DATA.studentsPerClass.colors : ['#7c3aed'],
                        borderRadius: 8,
                        barThickness: 34,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: C.text, font: { size: 11 } } },
                        y: { beginAtZero: true, grid: { color: C.grid }, ticks: { color: C.text, precision: 0, font: { size: 11 } } }
                    }
                }
            });
        }

        /* 4 — Marks per class: bar */
        const ctxMk = document.getElementById('chartMarksPerClass');
        if (ctxMk) {
            charts.marksPerClass = new Chart(ctxMk.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: DATA.marksPerClass.labels.length ? DATA.marksPerClass.labels : ['No entries'],
                    datasets: [{
                        label: 'Marks',
                        data: DATA.marksPerClass.values.length ? DATA.marksPerClass.values : [0],
                        backgroundColor: ['#2563eb','#7c3aed','#10b981','#f59e0b','#ec4899','#0ea5e9'],
                        borderRadius: 8,
                        barThickness: 34,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: C.text, font: { size: 11 } } },
                        y: { beginAtZero: true, grid: { color: C.grid }, ticks: { color: C.text, precision: 0, font: { size: 11 } } }
                    }
                }
            });
        }
    }

    function rebuildCharts() {
        destroyAll();
        buildCharts();
    }

    if (typeof Chart !== 'undefined') {
        buildCharts();
    } else {
        window.addEventListener('load', buildCharts);
    }
</script>

</body>
</html>