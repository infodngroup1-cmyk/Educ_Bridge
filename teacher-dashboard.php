<?php
session_start();
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| AUTH — teacher must be logged in
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['teacher_id']) || empty($_SESSION['teacher_verified'])) {
    header("Location: teacher-login.php");
    exit;
}

$teacher_id    = (int) $_SESSION['teacher_id'];
$teacher_name  = $_SESSION['teacher_name']  ?? 'Teacher';
$teacher_email = $_SESSION['teacher_email'] ?? '';
$teacher_phone = $_SESSION['teacher_phone'] ?? '';
$school_id     = (int) ($_SESSION['teacher_school_id'] ?? 0);
$school_name   = $_SESSION['teacher_school_name'] ?? 'School';
$school_code   = $_SESSION['teacher_school_code'] ?? '';

/* Initials */
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
| LOGOUT
|--------------------------------------------------------------------------
*/
if (isset($_GET['logout'])) {
    foreach ([
        'teacher_id','teacher_name','teacher_email','teacher_phone',
        'teacher_school_id','teacher_school_name','teacher_school_code','teacher_verified'
    ] as $k) unset($_SESSION[$k]);
    header("Location: teacher-login.php");
    exit;
}

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
function safe_count($conn, $sql, $types = "", $params = []) {
    $stmt = @mysqli_prepare($conn, $sql);
    if (!$stmt) return 0;
    if ($types !== "" && !empty($params)) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $n   = $res ? (int) mysqli_fetch_assoc($res)['total'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

/*
|--------------------------------------------------------------------------
| STATS — school-scoped
|--------------------------------------------------------------------------
*/
$students_count   = table_exists($conn, 'students')
    ? safe_count($conn, "SELECT COUNT(*) AS total FROM students WHERE school_id = ?", "i", [$school_id])
    : 0;

$subjects_count   = table_exists($conn, 'subjects')
    ? safe_count($conn, "SELECT COUNT(*) AS total FROM subjects WHERE school_id = ?", "i", [$school_id])
    : 0;

$classes_count    = table_exists($conn, 'classes')
    ? safe_count($conn, "SELECT COUNT(*) AS total FROM classes WHERE school_id = ?", "i", [$school_id])
    : 0;

$active_classes   = table_exists($conn, 'classes')
    ? safe_count($conn, "SELECT COUNT(*) AS total FROM classes WHERE school_id = ? AND status = 'active'", "i", [$school_id])
    : 0;

/*
| Teacher-specific stats
|--------------------------------------------------------------------------
| Subjects: a teacher is linked to subjects via subject_class_teachers.
| Classes:  a teacher is linked to classes via classes.class_teacher_id.
*/
$my_subjects_count = 0;
if (table_exists($conn, 'subject_class_teachers') && table_exists($conn, 'subjects')) {
    $my_subjects_count = safe_count($conn, "
        SELECT COUNT(DISTINCT s.id) AS total
        FROM subjects s
        INNER JOIN subject_class_teachers sct
                ON sct.subject_id = s.id AND sct.school_id = s.school_id
        WHERE s.school_id = ? AND sct.teacher_id = ?
    ", "ii", [$school_id, $teacher_id]);
}

$my_classes_count = 0;
if (table_exists($conn, 'classes') && col_exists($conn, 'classes', 'class_teacher_id')) {
    $my_classes_count = safe_count($conn,
        "SELECT COUNT(*) AS total FROM classes WHERE school_id = ? AND class_teacher_id = ?",
        "ii", [$school_id, $teacher_id]);
}

$announcements_count = table_exists($conn, 'announcements')
    ? safe_count($conn, "SELECT COUNT(*) AS total FROM announcements WHERE school_id = ?", "i", [$school_id])
    : 0;

$events_count = table_exists($conn, 'events')
    ? safe_count($conn, "SELECT COUNT(*) AS total FROM events WHERE school_id = ?", "i", [$school_id])
    : 0;

/* Unread messages */
$unread_messages = 0;
if (table_exists($conn, 'messages')) {
    $unread_messages = safe_count($conn, "
        SELECT COUNT(*) AS total FROM messages
        WHERE school_id = ? AND (teacher_id = ? OR teacher_id IS NULL) AND status = 'unread'
    ", "ii", [$school_id, $teacher_id]);
}

/* Marks entered by this teacher (only if table has teacher_id) */
$marks_count = 0;
if (table_exists($conn, 'marks') && col_exists($conn, 'marks', 'teacher_id')) {
    $marks_count = safe_count($conn,
        "SELECT COUNT(*) AS total FROM marks WHERE school_id = ? AND teacher_id = ?",
        "ii", [$school_id, $teacher_id]);
}

/*
|--------------------------------------------------------------------------
| RECENT ANNOUNCEMENTS (latest 3)
|--------------------------------------------------------------------------
*/
$recent_announcements = null;
if (table_exists($conn, 'announcements')) {
    $ann_stmt = @mysqli_prepare($conn, "
        SELECT id, title, body, created_at
        FROM announcements
        WHERE school_id = ?
        ORDER BY created_at DESC
        LIMIT 3
    ");
    if ($ann_stmt) {
        mysqli_stmt_bind_param($ann_stmt, "i", $school_id);
        mysqli_stmt_execute($ann_stmt);
        $recent_announcements = mysqli_stmt_get_result($ann_stmt);
    }
}

/*
|--------------------------------------------------------------------------
| UPCOMING EVENTS (next 3)
|--------------------------------------------------------------------------
*/
$upcoming_events = null;
if (table_exists($conn, 'events')) {
    $ev_stmt = @mysqli_prepare($conn, "
        SELECT id, title, description, event_date, location
        FROM events
        WHERE school_id = ? AND event_date >= CURDATE()
        ORDER BY event_date ASC
        LIMIT 3
    ");
    if ($ev_stmt) {
        mysqli_stmt_bind_param($ev_stmt, "i", $school_id);
        mysqli_stmt_execute($ev_stmt);
        $upcoming_events = mysqli_stmt_get_result($ev_stmt);
    }
}

/*
|--------------------------------------------------------------------------
| MY SUBJECTS — via subject_class_teachers (real schema)
|--------------------------------------------------------------------------
*/
$my_subjects = null;
if (table_exists($conn, 'subject_class_teachers') && table_exists($conn, 'subjects')) {
    $ms_stmt = @mysqli_prepare($conn, "
        SELECT DISTINCT s.id, s.subject_name, s.subject_code, s.level,
                        s.credits, s.week_hours, s.max_marks, s.status
        FROM subjects s
        INNER JOIN subject_class_teachers sct
                ON sct.subject_id = s.id AND sct.school_id = s.school_id
        WHERE s.school_id = ? AND sct.teacher_id = ?
        ORDER BY s.subject_name ASC
        LIMIT 6
    ");
    if ($ms_stmt) {
        mysqli_stmt_bind_param($ms_stmt, "ii", $school_id, $teacher_id);
        mysqli_stmt_execute($ms_stmt);
        $my_subjects = mysqli_stmt_get_result($ms_stmt);
    }
}

/*
|--------------------------------------------------------------------------
| MY CLASSES — via classes.class_teacher_id
|--------------------------------------------------------------------------
*/
$my_classes = null;
if (table_exists($conn, 'classes') && col_exists($conn, 'classes', 'class_teacher_id')) {
    $has_year = col_exists($conn, 'classes', 'year_of_study');
    $year_sel = $has_year ? ", c.year_of_study" : ", '' AS year_of_study";

    $mc_stmt = @mysqli_prepare($conn, "
        SELECT c.id, c.class_name, c.level, c.room, c.status,
               (SELECT COUNT(*) FROM students s WHERE s.class_id = c.id) AS student_count
               {$year_sel}
        FROM classes c
        WHERE c.school_id = ? AND c.class_teacher_id = ?
        ORDER BY c.class_name ASC
        LIMIT 6
    ");
    if ($mc_stmt) {
        mysqli_stmt_bind_param($mc_stmt, "ii", $school_id, $teacher_id);
        mysqli_stmt_execute($mc_stmt);
        $my_classes = mysqli_stmt_get_result($mc_stmt);
    }
}

/*
|--------------------------------------------------------------------------
| MY PROFILE (for welcome banner)
|--------------------------------------------------------------------------
*/
$my_diploma = '';
$my_levels  = '';
if (table_exists($conn, 'teachers')) {
    $cols = [];
    if (col_exists($conn, 'teachers', 'diploma'))         $cols[] = 'diploma';
    if (col_exists($conn, 'teachers', 'teaching_level'))  $cols[] = 'teaching_level';

    if (!empty($cols)) {
        $p_stmt = @mysqli_prepare($conn, "SELECT " . implode(',', $cols) . " FROM teachers WHERE id = ? LIMIT 1");
        if ($p_stmt) {
            mysqli_stmt_bind_param($p_stmt, "i", $teacher_id);
            mysqli_stmt_execute($p_stmt);
            $p_res = mysqli_stmt_get_result($p_stmt);
            if ($p_res && ($row = mysqli_fetch_assoc($p_res))) {
                $my_diploma = $row['diploma'] ?? '';
                $my_levels  = $row['teaching_level'] ?? '';
            }
            mysqli_stmt_close($p_stmt);
        }
    }
}

$level_map = [
    'Nursery' => 'Nursery', 'Primary' => 'Primary',
    'O_Level' => "O' Level", 'A_Level' => "A' Level",
];
$level_colors = [
    'Nursery' => 'pink', 'Primary' => 'blue',
    'O_Level' => 'green', 'A_Level' => 'orange',
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard | Educ Bridge</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* [same CSS as your file — unchanged] */
        :root {
            --primary:#2563eb;--primary-dark:#1d4ed8;--primary-light:#dbeafe;
            --success:#10b981;--success-light:rgba(16,185,129,.1);
            --warning:#f59e0b;--warning-light:rgba(245,158,11,.12);
            --danger:#ef4444;--danger-light:rgba(239,68,68,.1);
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
        html,body{min-height:100vh}
        body{font-family:'Inter',sans-serif;background:var(--bg-body);color:var(--text-primary);transition:background .4s ease,color .4s ease;overflow-x:hidden}
        .layout{display:flex;min-height:100vh}

        .sidebar{width:260px;background:var(--sidebar-bg);color:var(--sidebar-text);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;transition:background .4s ease,color .4s ease,transform .3s ease,border-color .4s ease;border-right:1px solid var(--sidebar-border);box-shadow:var(--shadow-sidebar)}
        .sidebar-header{padding:22px 22px 18px;border-bottom:1px solid var(--sidebar-border);display:flex;align-items:center;gap:12px}
        .sidebar-header img{width:42px;height:42px;object-fit:contain;border-radius:50%;border:2px solid var(--sidebar-border);padding:2px;background:#fff}
        .sidebar-brand{font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;color:var(--text-primary);line-height:1.2}
        .sidebar-brand span{color:var(--primary)}
        .sidebar-brand small{display:block;font-size:11px;font-weight:500;color:var(--text-muted);letter-spacing:.4px;margin-top:2px;text-transform:uppercase}
        .sidebar-nav{flex:1;padding:18px 12px;overflow-y:auto}
        .nav-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--sidebar-title);padding:12px 14px 8px}
        .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;color:var(--sidebar-text);text-decoration:none;font-size:14px;font-weight:500;border-radius:10px;margin-bottom:4px;transition:var(--transition);position:relative}
        .nav-item i.nav-icon{width:20px;text-align:center;font-size:15px;color:var(--sidebar-text);transition:var(--transition)}
        .nav-item:hover{background:var(--sidebar-hover);color:var(--sidebar-text-active)}
        .nav-item:hover i.nav-icon{color:var(--primary)}
        .nav-item.active{background:var(--sidebar-active);color:var(--sidebar-text-active)}
        .nav-item.active i.nav-icon{color:var(--primary)}
        .nav-item.active::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;background:var(--primary);border-radius:0 4px 4px 0}
        .nav-item .nav-badge{margin-left:auto;background:var(--sidebar-badge-bg);color:var(--sidebar-badge-fg);font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:50px;line-height:1.5}

        .sidebar-profile{border-top:1px solid var(--sidebar-border);padding:16px 18px;display:flex;align-items:center;gap:12px}
        .sidebar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-size:15px;font-weight:700;flex-shrink:0;box-shadow:0 4px 12px rgba(59,130,246,.35)}
        .sidebar-profile-info{flex:1;min-width:0}
        .sidebar-profile-info .p-name{font-size:13.5px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .sidebar-profile-info .p-role{font-size:11.5px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .sidebar-logout{width:34px;height:34px;border-radius:8px;background:var(--danger-light);color:var(--danger);display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:13px;flex-shrink:0;transition:var(--transition)}
        .sidebar-logout:hover{background:var(--danger);color:#fff}

        .main{flex:1;margin-left:260px;min-height:100vh;display:flex;flex-direction:column;transition:margin-left .3s ease}
        .topbar{background:var(--bg-card);border-bottom:1px solid var(--border-color);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50}
        .topbar-left{display:flex;align-items:center;gap:14px}
        .hamburger{display:none;width:40px;height:40px;border-radius:10px;border:1.5px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary);align-items:center;justify-content:center;cursor:pointer;font-size:15px}
        .page-title{font-family:'Poppins',sans-serif;font-size:20px;font-weight:700;color:var(--text-primary);letter-spacing:-.4px}
        .page-subtitle{font-size:13px;color:var(--text-muted);margin-top:2px}
        .topbar-actions{display:flex;align-items:center;gap:10px}
        .topbar-btn{width:42px;height:42px;border-radius:50%;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;transition:var(--transition);text-decoration:none;position:relative}
        .topbar-btn:hover{border-color:var(--primary);color:var(--primary);transform:translateY(-1px)}
        .topbar-btn.theme-btn:hover{transform:rotate(20deg)}
        .topbar-btn .dot{position:absolute;top:8px;right:8px;width:8px;height:8px;background:#ef4444;border:2px solid var(--bg-card);border-radius:50%}
        .topbar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.3);cursor:pointer}

        .content{padding:28px;flex:1}

        .welcome-banner{background:linear-gradient(135deg,#2563eb,#7c3aed);border-radius:18px;padding:32px 36px;color:#fff;margin-bottom:28px;position:relative;overflow:hidden;box-shadow:0 12px 36px rgba(37,99,235,.25)}
        .welcome-banner::before{content:'';position:absolute;top:-50%;right:-10%;width:320px;height:320px;background:radial-gradient(circle,rgba(255,255,255,.15) 0%,transparent 70%);border-radius:50%}
        .welcome-banner::after{content:'';position:absolute;bottom:-60%;left:20%;width:260px;height:260px;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);border-radius:50%}
        .welcome-banner h2{font-family:'Poppins',sans-serif;font-size:26px;font-weight:700;margin-bottom:8px;position:relative;z-index:1;letter-spacing:-.5px}
        .welcome-banner p{font-size:15px;opacity:.92;position:relative;z-index:1;max-width:680px}
        .welcome-meta{margin-top:14px;display:flex;flex-wrap:wrap;gap:8px;position:relative;z-index:1}
        .welcome-meta .chip{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);padding:5px 12px;border-radius:50px;font-size:12px;font-weight:700;letter-spacing:.3px}

        .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:20px;margin-bottom:28px}
        .stat-card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;padding:22px;display:flex;align-items:center;gap:16px;transition:var(--transition);text-decoration:none;color:inherit}
        .stat-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-md);border-color:var(--primary)}
        .stat-icon{width:54px;height:54px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;flex-shrink:0}
        .stat-icon.blue{background:linear-gradient(135deg,#2563eb,#3b82f6)}
        .stat-icon.green{background:linear-gradient(135deg,#10b981,#34d399)}
        .stat-icon.purple{background:linear-gradient(135deg,#7c3aed,#a78bfa)}
        .stat-icon.orange{background:linear-gradient(135deg,#f59e0b,#fbbf24)}
        .stat-icon.pink{background:linear-gradient(135deg,#ec4899,#f472b6)}
        .stat-icon.teal{background:linear-gradient(135deg,#14b8a6,#2dd4bf)}
        .stat-info .num{font-family:'Poppins',sans-serif;font-size:26px;font-weight:700;color:var(--text-primary);line-height:1.2;letter-spacing:-.5px}
        .stat-info .lbl{font-size:13px;color:var(--text-muted);margin-top:3px;font-weight:500}

        .section-card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;padding:26px;margin-bottom:24px;transition:var(--transition)}
        .section-card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;gap:12px;flex-wrap:wrap}
        .section-card-header h3{font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;color:var(--text-primary);letter-spacing:-.3px;display:flex;align-items:center;gap:10px}
        .section-card-header h3 i{color:var(--primary);font-size:17px}
        .section-link{font-size:13.5px;color:var(--primary);text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:6px;transition:var(--transition)}
        .section-link:hover{color:var(--primary-dark);gap:9px}

        .two-col{display:grid;grid-template-columns:1fr 1fr;gap:24px}

        .list-item{display:flex;align-items:flex-start;gap:14px;padding:14px 16px;background:var(--bg-alt);border:1px solid var(--border-color);border-radius:12px;text-decoration:none;color:inherit;transition:var(--transition);margin-bottom:10px}
        .list-item:last-child{margin-bottom:0}
        .list-item:hover{border-color:var(--primary);transform:translateX(4px)}
        .list-item .li-icon{width:40px;height:40px;border-radius:10px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
        .list-item .li-icon.warn{background:var(--warning-light);color:var(--warning)}
        .list-item .li-icon.ok{background:var(--success-light);color:var(--success)}
        .list-item .li-body{flex:1;min-width:0}
        .list-item .li-title{font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:3px}
        .list-item .li-meta{font-size:12.5px;color:var(--text-muted);display:flex;gap:12px;flex-wrap:wrap}
        .list-item .li-preview{font-size:13px;color:var(--text-muted);line-height:1.5;margin-top:4px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}

        .empty-state{text-align:center;padding:26px 16px;color:var(--text-muted)}
        .empty-state i{font-size:38px;opacity:.35;margin-bottom:10px;display:block}
        .empty-state p{font-size:13.5px;line-height:1.6}

        .pill{display:inline-block;padding:3px 10px;border-radius:50px;font-size:11.5px;font-weight:700;letter-spacing:.3px;line-height:1.5}
        .pill.blue{background:rgba(37,99,235,.12);color:#2563eb}
        .pill.green{background:rgba(16,185,129,.12);color:#059669}
        .pill.orange{background:rgba(245,158,11,.15);color:#b45309}
        .pill.purple{background:rgba(124,58,237,.12);color:#7c3aed}
        .pill.gray{background:rgba(100,116,139,.14);color:#475569}
        .pill.red{background:rgba(239,68,68,.12);color:#dc2626}
        .pill.teal{background:rgba(20,184,166,.12);color:#0d9488}
        .pill.pink{background:rgba(236,72,153,.12);color:#db2777}
        [data-theme="dark"] .pill.blue{color:#93c5fd}
        [data-theme="dark"] .pill.green{color:#34d399}
        [data-theme="dark"] .pill.orange{color:#fbbf24}
        [data-theme="dark"] .pill.purple{color:#a78bfa}
        [data-theme="dark"] .pill.gray{color:#cbd5e1}
        [data-theme="dark"] .pill.red{color:#f87171}
        [data-theme="dark"] .pill.teal{color:#2dd4bf}
        [data-theme="dark"] .pill.pink{color:#f9a8d4}

        .quick-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}
        .quick-btn{display:flex;align-items:center;gap:12px;padding:16px 18px;background:var(--bg-alt);border:1.5px solid var(--border-color);border-radius:12px;text-decoration:none;color:var(--text-primary);font-size:14px;font-weight:600;transition:var(--transition)}
        .quick-btn i{width:36px;height:36px;border-radius:10px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;transition:var(--transition)}
        .quick-btn:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:var(--shadow-md)}
        .quick-btn:hover i{background:var(--primary);color:#fff}

        .overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:90;backdrop-filter:blur(2px)}
        .overlay.show{display:block}

        @media (max-width:900px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0);box-shadow:0 0 40px rgba(0,0,0,.2)}.main{margin-left:0}.hamburger{display:flex}}
        @media (max-width:700px){.two-col{grid-template-columns:1fr}}
        @media (max-width:600px){.content{padding:20px 16px}.topbar{padding:12px 16px}.page-title{font-size:17px}.welcome-banner{padding:24px 22px}.welcome-banner h2{font-size:20px}.welcome-banner p{font-size:14px}.stat-card{padding:18px}.stat-icon{width:46px;height:46px;font-size:19px}.stat-info .num{font-size:22px}}
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
            <a href="teacher-dashboard.php" class="nav-item active"><i class="fas fa-th-large nav-icon"></i> Dashboard</a>
            <div class="nav-section-title">Teaching</div>
            <a href="teacher-subjects.php" class="nav-item">
                <i class="fas fa-book nav-icon"></i> Subjects
                <?php if ($my_subjects_count > 0): ?><span class="nav-badge"><?= $my_subjects_count; ?></span><?php endif; ?>
            </a>
            <a href="teacher-classes.php" class="nav-item">
                <i class="fas fa-layer-group nav-icon"></i> Classes
                <?php if ($my_classes_count > 0): ?><span class="nav-badge"><?= $my_classes_count; ?></span><?php endif; ?>
            </a>
            <a href="teacher-marks.php" class="nav-item">
                <i class="fas fa-clipboard-list nav-icon"></i> Marks
                <?php if ($marks_count > 0): ?><span class="nav-badge"><?= $marks_count; ?></span><?php endif; ?>
            </a>
            <div class="nav-section-title">Communication</div>
            <a href="teacher-announcements.php" class="nav-item"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>
            <a href="teacher-events.php" class="nav-item"><i class="fas fa-calendar-alt nav-icon"></i> Events</a>
            <div class="nav-section-title">System</div>
            <a href="teacher-reports.php" class="nav-item"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
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
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Dashboard</div>
                    <div class="page-subtitle"><?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>
                <a href="teacher-messages.php" class="topbar-btn" title="Messages">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread_messages > 0): ?><span class="dot"></span><?php endif; ?>
                </a>
                <div class="topbar-avatar" title="<?= htmlspecialchars($teacher_name); ?>"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <!-- WELCOME -->
            <div class="welcome-banner">
                <h2>Hello, <?= htmlspecialchars($teacher_name); ?>!</h2>
                <p>Welcome back to <strong><?= htmlspecialchars($school_name); ?></strong>. Here's a quick overview of your teaching activity today.</p>
                <div class="welcome-meta">
                    <?php if (!empty($school_code)): ?>
                        <span class="chip"><i class="fas fa-fingerprint"></i> <?= htmlspecialchars($school_code); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($my_diploma)): ?>
                        <span class="chip"><i class="fas fa-graduation-cap"></i> <?= htmlspecialchars($my_diploma); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($my_levels)): ?>
                        <?php foreach (array_filter(array_map('trim', explode(',', $my_levels))) as $lvl): ?>
                            <span class="chip"><i class="fas fa-book-open"></i> <?= htmlspecialchars($level_map[$lvl] ?? $lvl); ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- STATS -->
            <div class="stats-grid">
                <a href="teacher-students.php" class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-info"><div class="num"><?= $students_count; ?></div><div class="lbl">Students</div></div>
                </a>
                <a href="teacher-subjects.php" class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-book"></i></div>
                    <div class="stat-info"><div class="num"><?= $my_subjects_count; ?></div><div class="lbl">My Subjects</div></div>
                </a>
                <a href="teacher-classes.php" class="stat-card">
                    <div class="stat-icon purple"><i class="fas fa-layer-group"></i></div>
                    <div class="stat-info"><div class="num"><?= $my_classes_count; ?></div><div class="lbl">My Classes</div></div>
                </a>
                <a href="teacher-marks.php" class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-clipboard-list"></i></div>
                    <div class="stat-info"><div class="num"><?= $marks_count; ?></div><div class="lbl">Marks Entered</div></div>
                </a>
                <a href="teacher-announcements.php" class="stat-card">
                    <div class="stat-icon pink"><i class="fas fa-bullhorn"></i></div>
                    <div class="stat-info"><div class="num"><?= $announcements_count; ?></div><div class="lbl">Announcements</div></div>
                </a>
                <a href="teacher-events.php" class="stat-card">
                    <div class="stat-icon teal"><i class="fas fa-calendar-alt"></i></div>
                    <div class="stat-info"><div class="num"><?= $events_count; ?></div><div class="lbl">Events</div></div>
                </a>
            </div>

            <!-- ANNOUNCEMENTS + EVENTS -->
            <div class="two-col">
                <div class="section-card">
                    <div class="section-card-header">
                        <h3><i class="fas fa-bullhorn"></i> Recent Announcements</h3>
                        <a href="teacher-announcements.php" class="section-link">View all <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <?php if ($recent_announcements && mysqli_num_rows($recent_announcements) > 0): ?>
                        <?php while ($a = mysqli_fetch_assoc($recent_announcements)): ?>
                            <a href="teacher-announcements.php?id=<?= (int)$a['id']; ?>" class="list-item">
                                <div class="li-icon"><i class="fas fa-bullhorn"></i></div>
                                <div class="li-body">
                                    <div class="li-title"><?= htmlspecialchars($a['title']); ?></div>
                                    <div class="li-meta"><span><i class="far fa-clock"></i> <?= date('M j, Y · H:i', strtotime($a['created_at'])); ?></span></div>
                                    <div class="li-preview"><?= htmlspecialchars($a['body']); ?></div>
                                </div>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-bullhorn"></i><p>No announcements yet.</p></div>
                    <?php endif; ?>
                </div>

                <div class="section-card">
                    <div class="section-card-header">
                        <h3><i class="fas fa-calendar-alt"></i> Upcoming Events</h3>
                        <a href="teacher-events.php" class="section-link">View all <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <?php if ($upcoming_events && mysqli_num_rows($upcoming_events) > 0): ?>
                        <?php while ($e = mysqli_fetch_assoc($upcoming_events)): ?>
                            <a href="teacher-events.php?id=<?= (int)$e['id']; ?>" class="list-item">
                                <div class="li-icon warn"><i class="fas fa-calendar-day"></i></div>
                                <div class="li-body">
                                    <div class="li-title"><?= htmlspecialchars($e['title']); ?></div>
                                    <div class="li-meta">
                                        <span><i class="far fa-calendar"></i> <?= date('M j, Y', strtotime($e['event_date'])); ?></span>
                                        <?php if (!empty($e['location'])): ?>
                                            <span><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($e['location']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($e['description'])): ?>
                                        <div class="li-preview"><?= htmlspecialchars($e['description']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-calendar-alt"></i><p>No upcoming events scheduled.</p></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- MY SUBJECTS -->
            <div class="section-card">
                <div class="section-card-header">
                    <h3><i class="fas fa-book"></i> My Subjects</h3>
                    <a href="teacher-subjects.php" class="section-link">View all <i class="fas fa-arrow-right"></i></a>
                </div>
                <?php if ($my_subjects && mysqli_num_rows($my_subjects) > 0): ?>
                    <div class="quick-grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
                        <?php while ($s = mysqli_fetch_assoc($my_subjects)): ?>
                            <?php $lv_col = $level_colors[$s['level']] ?? 'gray'; ?>
                            <a href="teacher-subjects.php?id=<?= (int)$s['id']; ?>" class="list-item" style="margin:0;">
                                <div class="li-icon ok"><i class="fas fa-book"></i></div>
                                <div class="li-body">
                                    <div class="li-title"><?= htmlspecialchars($s['subject_name']); ?></div>
                                    <div class="li-meta">
                                        <span class="pill <?= $lv_col; ?>"><?= htmlspecialchars($level_map[$s['level']] ?? $s['level']); ?></span>
                                        <?php if (!empty($s['subject_code'])): ?>
                                            <span><i class="fas fa-hashtag"></i> <?= htmlspecialchars($s['subject_code']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-book"></i><p>You have no subjects assigned yet.</p></div>
                <?php endif; ?>
            </div>

            <!-- MY CLASSES -->
            <div class="section-card">
                <div class="section-card-header">
                    <h3><i class="fas fa-layer-group"></i> My Classes</h3>
                    <a href="teacher-classes.php" class="section-link">View all <i class="fas fa-arrow-right"></i></a>
                </div>
                <?php if ($my_classes && mysqli_num_rows($my_classes) > 0): ?>
                    <div class="quick-grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));">
                        <?php while ($c = mysqli_fetch_assoc($my_classes)): ?>
                            <?php $lv_col = $level_colors[$c['level']] ?? 'gray'; ?>
                            <a href="teacher-classes.php?id=<?= (int)$c['id']; ?>" class="list-item" style="margin:0;">
                                <div class="li-icon"><i class="fas fa-layer-group"></i></div>
                                <div class="li-body">
                                    <div class="li-title"><?= htmlspecialchars($c['class_name']); ?></div>
                                    <div class="li-meta">
                                        <span class="pill <?= $lv_col; ?>"><?= htmlspecialchars($level_map[$c['level']] ?? $c['level']); ?></span>
                                        <span><i class="fas fa-user-graduate"></i> <?= (int)$c['student_count']; ?></span>
                                        <?php if (!empty($c['room'])): ?>
                                            <span><i class="fas fa-door-open"></i> <?= htmlspecialchars($c['room']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state"><i class="fas fa-layer-group"></i><p>You're not assigned as class teacher for any classes yet.</p></div>
                <?php endif; ?>
            </div>

            <!-- QUICK ACTIONS -->
            <div class="section-card">
                <div class="section-card-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="quick-grid">
                    <a href="teacher-students.php" class="quick-btn"><i class="fas fa-user-graduate"></i> View Students</a>
                    <a href="teacher-marks.php" class="quick-btn"><i class="fas fa-clipboard-check"></i> Enter Marks</a>
                    <a href="teacher-subjects.php" class="quick-btn"><i class="fas fa-book"></i> My Subjects</a>
                    <a href="teacher-classes.php" class="quick-btn"><i class="fas fa-layer-group"></i> My Classes</a>
                    <a href="teacher-messages.php" class="quick-btn"><i class="fas fa-envelope"></i> Messages</a>
                    <a href="teacher-reports.php" class="quick-btn"><i class="fas fa-chart-line"></i> Reports</a>
                    <a href="teacher-announcements.php" class="quick-btn"><i class="fas fa-bullhorn"></i> Announcements</a>
                    <a href="teacher-settings.php" class="quick-btn"><i class="fas fa-cog"></i> Settings</a>
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
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        function openSidebar()  { sidebar.classList.add('open');  overlay.classList.add('show'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) closeSidebar(); else openSidebar();
        });
        overlay.addEventListener('click', closeSidebar);
    })();
</script>

</body>
</html>