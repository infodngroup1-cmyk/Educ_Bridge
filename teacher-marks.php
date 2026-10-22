<?php
session_start();
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| AUTH
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
function tname_initials($name) {
    $out = '';
    foreach (explode(' ', trim((string)$name)) as $p) {
        if ($p !== '') { $out .= strtoupper($p[0]); if (strlen($out) >= 2) break; }
    }
    return $out !== '' ? $out : 'S';
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$q     = trim($_GET['q'] ?? '');
$level = $_GET['level'] ?? '';

$level_map = [
    'Nursery' => 'Nursery',
    'Primary' => 'Primary',
    'O_Level' => "O' Level",
    'A_Level' => "A' Level",
];

/*
|--------------------------------------------------------------------------
| FETCH SUBJECTS THE TEACHER TEACHES
|--------------------------------------------------------------------------
| A subject is "mine" when there is at least one subject_class_teachers row
| for this teacher. The teacher can open ANY class of that subject — we
| return that list of classes below.
*/
$where  = ["s.school_id = ?"];
$params = [$school_id];
$types  = "i";

/* Only subjects this teacher has any sct row on */
$where[] = "EXISTS (
    SELECT 1 FROM subject_class_teachers sct
    WHERE sct.school_id = s.school_id
      AND sct.subject_id = s.id
      AND sct.teacher_id = ?
)";
$params[] = $teacher_id; $types .= "i";

if ($q !== '') {
    $where[] = "(s.subject_name LIKE ? OR s.subject_code LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like; $params[] = $like;
    $types   .= "ss";
}
if (in_array($level, ['Nursery','Primary','O_Level','A_Level'], true)) {
    $where[] = "EXISTS (
        SELECT 1 FROM subject_classes sc
        INNER JOIN classes c ON c.id = sc.class_id
        WHERE sc.school_id = s.school_id
          AND sc.subject_id = s.id
          AND c.level = ?
    )";
    $params[] = $level; $types .= "s";
}

$where_sql = implode(" AND ", $where);

$subjects = [];
$list_stmt = mysqli_prepare($conn, "
    SELECT s.*
    FROM subjects s
    WHERE $where_sql
    ORDER BY s.subject_name ASC
");
if ($list_stmt) {
    mysqli_stmt_bind_param($list_stmt, $types, ...$params);
    mysqli_stmt_execute($list_stmt);
    $res = mysqli_stmt_get_result($list_stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $row['classes'] = [];
        $subjects[(int)$row['id']] = $row;
    }
    mysqli_stmt_close($list_stmt);
}

/*
|--------------------------------------------------------------------------
| FETCH CLASSES PER SUBJECT
|--------------------------------------------------------------------------
| For each subject of the teacher, list ALL classes that study it
| (from subject_classes) and mark which ones the teacher explicitly owns
| (from subject_class_teachers). We only show the classes the teacher can
| actually open: those where they own the pair, OR — per the rule used
| in teacher-subjects.php — any class when the teacher owns the subject.
*/
if (!empty($subjects)) {
    $ids = array_keys($subjects);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $tc  = "i" . str_repeat('i', count($ids));
    $par = array_merge([$school_id], $ids);

    $csql = "
        SELECT
            sc.subject_id,
            sc.class_id,
            c.class_name,
            c.level,
            c.room,
            sct.teacher_id,
            t.name AS teacher_name
        FROM subject_classes sc
        INNER JOIN classes c ON c.id = sc.class_id AND c.school_id = sc.school_id
        LEFT JOIN subject_class_teachers sct
               ON sct.subject_id = sc.subject_id AND sct.class_id = sc.class_id
        LEFT JOIN teachers t ON t.id = sct.teacher_id AND t.school_id = sc.school_id
        WHERE sc.school_id = ? AND sc.subject_id IN ($ph)
        ORDER BY FIELD(c.level,'Nursery','Primary','O_Level','A_Level'), c.class_name ASC
    ";
    $cstmt = mysqli_prepare($conn, $csql);
    if ($cstmt) {
        mysqli_stmt_bind_param($cstmt, $tc, ...$par);
        mysqli_stmt_execute($cstmt);
        $cres = mysqli_stmt_get_result($cstmt);
        while ($c = mysqli_fetch_assoc($cres)) {
            $sid = (int)$c['subject_id'];
            $c['class_id']    = (int)$c['class_id'];
            $c['teacher_id']  = (int)($c['teacher_id'] ?? 0);
            $c['is_mine']     = ($c['teacher_id'] === $teacher_id);
            $c['has_teacher'] = ($c['teacher_id'] > 0);
            $subjects[$sid]['classes'][] = $c;
        }
        mysqli_stmt_close($cstmt);
    }
}

/*
|--------------------------------------------------------------------------
| COMPUTE FLAGS PER SUBJECT
|--------------------------------------------------------------------------
*/
foreach ($subjects as $sid => &$s) {
    $all_classes = $s['classes'];
    $mine        = 0;
    $others      = 0;

    foreach ($all_classes as $c) {
        if (!empty($c['is_mine']))    $mine++;
        elseif (!empty($c['has_teacher'])) $others++;
    }

    /* Teacher owns subject at all → can open all classes */
    $s['is_mine']       = ($mine > 0);
    $s['total_classes'] = count($all_classes);
    $s['mine_count']    = $mine;
    $s['other_count']   = $others;

    /* Display: only show classes teacher can open.
       Rule: is_mine (owner of pair) OR is_mine_subject (owns subject)
       — matches teacher-subjects.php behavior. */
    $display = [];
    foreach ($all_classes as $c) {
        if (!empty($c['is_mine'])) {
            $display[] = $c;
        } elseif ($s['is_mine']) {
            /* teacher owns the subject → can open any class of it */
            $display[] = $c;
        }
    }
    $s['display_classes'] = $display;
}
unset($s);

/* Drop subjects that have no displayable classes */
$subjects = array_filter($subjects, fn($s) => !empty($s['display_classes']));

/* Total openable pairs */
$total_pairs = 0;
foreach ($subjects as $s) $total_pairs += count($s['display_classes']);

$total_subjects = count($subjects);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Marks | Educ Bridge</title>
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
        --sidebar-bg:#fff;--sidebar-hover:rgba(37,99,235,.06);--sidebar-active:rgba(37,99,235,.10);
        --sidebar-text:#475569;--sidebar-text-active:#0f172a;--sidebar-border:#e2e8f0;--sidebar-title:#94a3b8;
        --sidebar-badge-bg:rgba(37,99,235,.12);--sidebar-badge-fg:#2563eb;
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
        --sidebar-bg:#0f172a;--sidebar-hover:rgba(255,255,255,.06);--sidebar-active:rgba(59,130,246,.18);
        --sidebar-text:#cbd5e1;--sidebar-text-active:#fff;--sidebar-border:rgba(255,255,255,.08);--sidebar-title:#94a3b8;
        --sidebar-badge-bg:rgba(59,130,246,.2);--sidebar-badge-fg:#93c5fd;
        --bg-body:#0b1120;--bg-card:#1e293b;--bg-alt:#1a2332;
        --text-primary:#f1f5f9;--text-secondary:#cbd5e1;--text-muted:#94a3b8;
        --border-color:#334155;
        --shadow-md:0 4px 12px rgba(0,0,0,.4);--shadow-lg:0 20px 45px rgba(0,0,0,.5);
        --shadow-sidebar:0 0 30px rgba(0,0,0,.4);
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    html,body{min-height:100vh}
    body{font-family:'Inter',sans-serif;background:var(--bg-body);color:var(--text-primary);overflow-x:hidden}
    .layout{display:flex;min-height:100vh}

    .sidebar{width:260px;background:var(--sidebar-bg);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;border-right:1px solid var(--sidebar-border);box-shadow:var(--shadow-sidebar)}
    .sidebar-header{padding:22px;border-bottom:1px solid var(--sidebar-border);display:flex;align-items:center;gap:12px}
    .sidebar-header img{width:42px;height:42px;border-radius:50%;background:#fff;padding:2px;border:2px solid var(--sidebar-border)}
    .sidebar-brand{font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;line-height:1.2}
    .sidebar-brand span{color:var(--primary)}
    .sidebar-brand small{display:block;font-size:11px;color:var(--text-muted);margin-top:2px;text-transform:uppercase;letter-spacing:.4px}
    .sidebar-nav{flex:1;padding:18px 12px;overflow-y:auto}
    .nav-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--sidebar-title);padding:12px 14px 8px}
    .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;color:var(--sidebar-text);text-decoration:none;font-size:14px;font-weight:500;border-radius:10px;margin-bottom:4px;transition:var(--transition);position:relative}
    .nav-item i.nav-icon{width:20px;text-align:center;font-size:15px}
    .nav-item:hover{background:var(--sidebar-hover);color:var(--sidebar-text-active)}
    .nav-item.active{background:var(--sidebar-active);color:var(--sidebar-text-active)}
    .nav-item.active i.nav-icon{color:var(--primary)}
    .nav-item.active::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;background:var(--primary);border-radius:0 4px 4px 0}
    .sidebar-profile{border-top:1px solid var(--sidebar-border);padding:16px 18px;display:flex;align-items:center;gap:12px}
    .sidebar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.35)}
    .sidebar-profile-info{flex:1;min-width:0}
    .sidebar-profile-info .p-name{font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-profile-info .p-role{font-size:11.5px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-logout{width:34px;height:34px;border-radius:8px;background:var(--danger-light);color:var(--danger);display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:13px;flex-shrink:0}

    .main{flex:1;margin-left:260px;min-height:100vh;display:flex;flex-direction:column}
    .topbar{background:var(--bg-card);border-bottom:1px solid var(--border-color);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50}
    .topbar-left{display:flex;align-items:center;gap:14px}
    .hamburger{display:none;width:40px;height:40px;border-radius:10px;border:1.5px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary);align-items:center;justify-content:center;cursor:pointer;font-size:15px}
    .page-title{font-family:'Poppins',sans-serif;font-size:20px;font-weight:700;letter-spacing:-.4px}
    .page-subtitle{font-size:13px;color:var(--text-muted);margin-top:2px}
    .topbar-actions{display:flex;align-items:center;gap:10px}
    .topbar-btn{width:42px;height:42px;border-radius:50%;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;transition:var(--transition);text-decoration:none}
    .topbar-btn:hover{border-color:var(--primary);color:var(--primary);transform:translateY(-1px)}
    .topbar-btn.theme-btn:hover{transform:rotate(20deg)}
    .topbar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.3)}

    .content{padding:28px;flex:1}

    .page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap}
    .page-head h1{font-family:'Poppins',sans-serif;font-size:22px;font-weight:700;display:flex;align-items:center;gap:10px}
    .page-head h1 i{color:var(--primary)}
    .page-head .sub{font-size:13.5px;color:var(--text-muted);margin-top:4px}

    .summaries{display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:16px;margin-bottom:22px}
    .sum-card{background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px;padding:18px 20px;display:flex;align-items:center;gap:14px;transition:var(--transition)}
    .sum-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);border-color:var(--primary)}
    .sum-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0}
    .sum-icon.blue{background:linear-gradient(135deg,#2563eb,#3b82f6)}
    .sum-icon.green{background:linear-gradient(135deg,#10b981,#34d399)}
    .sum-icon.orange{background:linear-gradient(135deg,#f59e0b,#fbbf24)}
    .sum-info .num{font-family:'Poppins',sans-serif;font-size:24px;font-weight:700;line-height:1.2;letter-spacing:-.4px}
    .sum-info .lbl{font-size:12.5px;color:var(--text-muted);margin-top:3px;font-weight:500}

    .filters{background:var(--bg-card);border:1px solid var(--border-color);border-radius:14px;padding:16px 18px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
    .filter-group{position:relative;display:flex;align-items:center;flex:1 1 180px;min-width:160px}
    .filter-group i{position:absolute;left:13px;color:var(--text-muted);font-size:13px;pointer-events:none}
    .filter-group input,.filter-group select{width:100%;padding:10px 14px 10px 36px;border:1.5px solid var(--border-color);border-radius:10px;background:var(--bg-alt);color:var(--text-primary);font-family:inherit;font-size:13.5px;outline:none;transition:var(--transition)}
    .filter-group input:focus,.filter-group select:focus{border-color:var(--primary);background:var(--bg-card);box-shadow:0 0 0 3px rgba(37,99,235,.1)}

    .subject-block{background:var(--bg-card);border:1px solid var(--border-color);border-radius:18px;margin-bottom:18px;overflow:hidden;transition:var(--transition)}
    .subject-block:hover{box-shadow:var(--shadow-md)}
    .sb-head{display:flex;align-items:center;gap:16px;padding:18px 22px;border-bottom:1px solid var(--border-color);background:linear-gradient(135deg,rgba(37,99,235,.05),rgba(124,58,237,.03))}
    .sb-avatar{width:52px;height:52px;border-radius:12px;background:linear-gradient(135deg,#2563eb,#7c3aed);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;flex-shrink:0}
    .sb-info{flex:1;min-width:0}
    .sb-info .name{font-family:'Poppins',sans-serif;font-size:16px;font-weight:700;color:var(--text-primary);line-height:1.3}
    .sb-info .meta{font-size:12.5px;color:var(--text-muted);margin-top:3px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
    .sb-info .meta .code{font-family:'Courier New',monospace;color:var(--primary);font-weight:700;letter-spacing:.4px}
    .sb-info .meta .dot{opacity:.4}

    .sb-count{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:50px;background:var(--success-light);color:#059669;font-size:12.5px;font-weight:800}
    [data-theme="dark"] .sb-count{color:#34d399}
    .sb-count i{font-size:11px}

    .sb-body{padding:18px 22px}
    .classes-grid{display:grid;grid-template-columns:repeat(auto-fill, minmax(240px, 1fr));gap:10px}

    .class-open-card{
        display:flex;align-items:center;gap:12px;
        padding:14px 16px;
        background:var(--bg-alt);
        border:1.5px solid var(--border-color);
        border-radius:12px;
        text-decoration:none;
        color:var(--text-primary);
        transition:var(--transition);
        position:relative;
        overflow:hidden;
    }
    .class-open-card:hover{
        transform:translateY(-2px);
        border-color:var(--primary);
        background:var(--primary-light);
        box-shadow:0 8px 20px rgba(37,99,235,.15);
    }
    [data-theme="dark"] .class-open-card:hover{background:rgba(59,130,246,.15)}
    .class-open-card.mine{
        border-left:4px solid var(--success);
    }
    .coc-avatar{
        width:42px;height:42px;border-radius:10px;
        background:linear-gradient(135deg,#2563eb,#7c3aed);
        color:#fff;display:flex;align-items:center;justify-content:center;
        font-family:'Poppins',sans-serif;font-size:14px;font-weight:700;
        flex-shrink:0;
    }
    .class-open-card.mine .coc-avatar{background:linear-gradient(135deg,#10b981,#34d399)}
    .coc-info{flex:1;min-width:0}
    .coc-name{font-size:14px;font-weight:700;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .coc-meta{font-size:11.5px;color:var(--text-muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .coc-arrow{
        width:32px;height:32px;border-radius:8px;
        background:var(--primary);
        color:#fff;
        display:flex;align-items:center;justify-content:center;
        font-size:12px;flex-shrink:0;
        transition:var(--transition);
    }
    .class-open-card:hover .coc-arrow{transform:translateX(3px)}

    .empty-state{text-align:center;padding:60px 20px;color:var(--text-muted);background:var(--bg-card);border:1px solid var(--border-color);border-radius:16px}
    .empty-state i{font-size:50px;opacity:.35;margin-bottom:14px;display:block}
    .empty-state h3{font-family:'Poppins',sans-serif;font-size:17px;font-weight:700;color:var(--text-primary);margin-bottom:6px}
    .empty-state p{font-size:14px;line-height:1.6;margin-bottom:18px}

    .overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:90;backdrop-filter:blur(2px)}
    .overlay.show{display:block}

    @media (max-width: 900px) { .sidebar { transform:translateX(-100%); } .sidebar.open { transform:translateX(0); } .main { margin-left:0; } .hamburger { display:flex; } }
    @media (max-width: 600px) { .content { padding:20px 16px; } .topbar { padding:12px 16px; } }
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
            <a href="teacher-marks.php" class="nav-item active"><i class="fas fa-clipboard-list nav-icon"></i> Marks</a>
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
                    <div class="page-title">Marks</div>
                    <div class="page-subtitle"><?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>
                <a href="teacher-messages.php" class="topbar-btn" title="Messages"><i class="fas fa-bell"></i></a>
                <div class="topbar-avatar" title="<?= htmlspecialchars($teacher_name); ?>"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-clipboard-list"></i> My subjects &amp; classes</h1>
                    <div class="sub">Open any class you teach to enter CAT, FAT and Practical marks</div>
                </div>
            </div>

            <div class="summaries">
                <div class="sum-card">
                    <div class="sum-icon blue"><i class="fas fa-book"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_subjects; ?></div>
                        <div class="lbl">Subjects you teach</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon green"><i class="fas fa-layer-group"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_pairs; ?></div>
                        <div class="lbl">Classes to open</div>
                    </div>
                </div>
            </div>

            <form method="GET" action="teacher-marks.php" class="filters">
                <div class="filter-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Search subject name or code" value="<?= htmlspecialchars($q); ?>">
                </div>
                <div class="filter-group">
                    <i class="fas fa-layer-group"></i>
                    <select name="level">
                        <option value="">All Levels</option>
                        <option value="Nursery"  <?= $level === 'Nursery'  ? 'selected' : ''; ?>>Nursery</option>
                        <option value="Primary"  <?= $level === 'Primary'  ? 'selected' : ''; ?>>Primary</option>
                        <option value="O_Level"  <?= $level === 'O_Level'  ? 'selected' : ''; ?>>O' Level</option>
                        <option value="A_Level"  <?= $level === 'A_Level'  ? 'selected' : ''; ?>>A' Level</option>
                    </select>
                </div>
                <button type="submit" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;border:none;background:var(--primary);color:#fff;font-family:inherit;">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="teacher-marks.php" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);text-decoration:none;">
                    <i class="fas fa-rotate"></i> Reset
                </a>
            </form>

            <?php if (!empty($subjects)): ?>
                <?php foreach ($subjects as $s):
                    $sid    = (int)$s['id'];
                    $sname  = $s['subject_name'];
                    $scode  = $s['subject_code'] ?? '';
                    $init   = "";
                    foreach (explode(" ", trim($sname)) as $p) {
                        if ($p !== "") { $init .= strtoupper($p[0]); if (strlen($init) >= 2) break; }
                    }
                    if ($init === "") $init = "S";
                    $display = $s['display_classes'];
                ?>
                    <div class="subject-block">
                        <div class="sb-head">
                            <div class="sb-avatar"><?= htmlspecialchars($init); ?></div>
                            <div class="sb-info">
                                <div class="name"><?= htmlspecialchars($sname); ?></div>
                                <div class="meta">
                                    <?php if ($scode): ?><span class="code"><?= htmlspecialchars($scode); ?></span><span class="dot">·</span><?php endif; ?>
                                    <span><?= count($display); ?> class<?= count($display) === 1 ? '' : 'es'; ?> to open</span>
                                </div>
                            </div>
                            <div class="sb-count"><i class="fas fa-layer-group"></i> <?= count($display); ?></div>
                        </div>
                        <div class="sb-body">
                            <div class="classes-grid">
                                <?php foreach ($display as $c):
                                    $cid   = (int)$c['class_id'];
                                    $cn    = $c['class_name'];
                                    $cinit = "";
                                    foreach (explode(" ", trim($cn)) as $p) {
                                        if ($p !== "") { $cinit .= strtoupper($p[0]); if (strlen($cinit) >= 2) break; }
                                    }
                                    if ($cinit === "") $cinit = "C";
                                    $c_mine = !empty($c['is_mine']);
                                ?>
                                    <a class="class-open-card <?= $c_mine ? 'mine' : ''; ?>"
                                       href="teacher-open-class.php?subject_id=<?= $sid; ?>&class_id=<?= $cid; ?>">
                                        <div class="coc-avatar"><?= htmlspecialchars($cinit); ?></div>
                                        <div class="coc-info">
                                            <div class="coc-name"><?= htmlspecialchars($cn); ?></div>
                                            <div class="coc-meta">
                                                <?= htmlspecialchars($level_map[$c['level']] ?? $c['level']); ?>
                                                <?php if (!empty($c['room'])): ?> · <?= htmlspecialchars($c['room']); ?><?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="coc-arrow"><i class="fas fa-arrow-right"></i></div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-clipboard-list"></i>
                    <h3>No marks to enter yet</h3>
                    <p>
                        You don't teach any subject with classes yet.
                        Go to <a href="teacher-subjects.php" style="color:var(--primary);font-weight:700;">My Subjects</a>
                        to claim a subject, then come back here.
                    </p>
                    <a href="teacher-subjects.php" class="btn primary" style="display:inline-flex;align-items:center;gap:8px;padding:11px 20px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;background:var(--primary);color:#fff;">
                        <i class="fas fa-book"></i> My Subjects
                    </a>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

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
        }
        apply(localStorage.getItem('educbridge-theme') || 'light');
        t.addEventListener('click', function () {
            apply(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
        });
    })();

    /* SIDEBAR (mobile) */
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
</script>

</body>
</html>