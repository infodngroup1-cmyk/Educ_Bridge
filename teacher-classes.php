<?php
require_once 'db.php';
require_once '_teacher-auth.php';
/*
| After this require:
|   $teacher_id, $teacher_name, $school_id, $school_name, $school_code, $initials, $short_school
| are already defined and the user is guaranteed logged in.
*/

/*
|--------------------------------------------------------------------------
| AUTO-CREATE students + attendance tables (idempotent)
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS students (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    school_id   INT NOT NULL,
    class_id    INT DEFAULT NULL,
    name        VARCHAR(150) NOT NULL,
    email       VARCHAR(150) DEFAULT NULL,
    phone       VARCHAR(30)  DEFAULT NULL,
    sex         ENUM('Male','Female') DEFAULT NULL,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_students_school (school_id),
    KEY idx_students_class  (class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS attendance (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    school_id   INT NOT NULL,
    class_id    INT NOT NULL,
    student_id  INT NOT NULL,
    teacher_id  INT NOT NULL,
    att_date    DATE NOT NULL,
    status      ENUM('present','absent','late','excused') NOT NULL,
    note        VARCHAR(255) DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_att (class_id, student_id, att_date),
    KEY idx_att_school (school_id),
    KEY idx_att_date   (att_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/*
|--------------------------------------------------------------------------
| FETCH CLASSES managed by this teacher
|--------------------------------------------------------------------------
*/
$classes = [];
$stmt = mysqli_prepare($conn, "
    SELECT c.*,
           (SELECT COUNT(*) FROM students s
             WHERE s.class_id = c.id) AS student_count
    FROM classes c
    WHERE c.school_id = ? AND c.class_teacher_id = ?
    ORDER BY c.level ASC, c.class_name ASC
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "ii", $school_id, $teacher_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $classes[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$total_classes  = count($classes);
$total_students = 0;
$active_classes = 0;
foreach ($classes as $c) {
    $total_students += (int)$c['student_count'];
    if ($c['status'] === 'active') $active_classes++;
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
    <title>My Classes | Educ Bridge</title>
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
            --sidebar-bg: #ffffff;
            --sidebar-hover: rgba(37, 99, 235, 0.06);
            --sidebar-active: rgba(37, 99, 235, 0.10);
            --sidebar-text: #475569;
            --sidebar-text-active: #0f172a;
            --sidebar-border: #e2e8f0;
            --sidebar-title: #94a3b8;
            --sidebar-badge-bg: rgba(37, 99, 235, 0.12);
            --sidebar-badge-fg: #2563eb;
            --bg-body: #f8fafc; --bg-card: #ffffff;
            --bg-alt: #f1f5f9;
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
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(59, 130, 246, 0.18);
            --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.08);
            --sidebar-title: #94a3b8;
            --sidebar-badge-bg: rgba(59, 130, 246, 0.2);
            --sidebar-badge-fg: #93c5fd;
            --bg-body: #0b1120; --bg-card: #1e293b;
            --bg-alt: #1a2332;
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

        .sidebar { width:260px; background:var(--sidebar-bg); color:var(--sidebar-text); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:100; transition:background 0.4s ease, color 0.4s ease, transform 0.3s ease, border-color 0.4s ease; border-right:1px solid var(--sidebar-border); box-shadow:var(--shadow-sidebar); }
        .sidebar-header { padding:22px 22px 18px; border-bottom:1px solid var(--sidebar-border); display:flex; align-items:center; gap:12px; }
        .sidebar-header img { width:42px; height:42px; object-fit:contain; border-radius:50%; border:2px solid var(--sidebar-border); padding:2px; background:#fff; }
        .sidebar-brand { font-family:'Poppins',sans-serif; font-size:18px; font-weight:700; color:var(--text-primary); line-height:1.2; }
        .sidebar-brand span { color:var(--primary); }
        .sidebar-brand small { display:block; font-size:11px; font-weight:500; color:var(--text-muted); letter-spacing:0.4px; margin-top:2px; text-transform:uppercase; }
        .sidebar-nav { flex:1; padding:18px 12px; overflow-y:auto; }
        .nav-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--sidebar-title); padding:12px 14px 8px; }
        .nav-item { display:flex; align-items:center; gap:12px; padding:11px 14px; color:var(--sidebar-text); text-decoration:none; font-size:14px; font-weight:500; border-radius:10px; margin-bottom:4px; transition:var(--transition); position:relative; }
        .nav-item i.nav-icon { width:20px; text-align:center; font-size:15px; color:var(--sidebar-text); transition:var(--transition); }
        .nav-item:hover { background:var(--sidebar-hover); color:var(--sidebar-text-active); }
        .nav-item:hover i.nav-icon { color:var(--primary); }
        .nav-item.active { background:var(--sidebar-active); color:var(--sidebar-text-active); }
        .nav-item.active i.nav-icon { color:var(--primary); }
        .nav-item.active::before { content:''; position:absolute; left:0; top:8px; bottom:8px; width:3px; background:var(--primary); border-radius:0 4px 4px 0; }
        .nav-item .nav-badge { margin-left:auto; background:var(--sidebar-badge-bg); color:var(--sidebar-badge-fg); font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:50px; line-height:1.5; }
        .sidebar-profile { border-top:1px solid var(--sidebar-border); padding:16px 18px; display:flex; align-items:center; gap:12px; }
        .sidebar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:15px; font-weight:700; flex-shrink:0; box-shadow:0 4px 12px rgba(59,130,246,0.35); }
        .sidebar-profile-info { flex:1; min-width:0; }
        .sidebar-profile-info .p-name { font-size:13.5px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-profile-info .p-role { font-size:11.5px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-logout { width:34px; height:34px; border-radius:8px; background:var(--danger-light); color:var(--danger); display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:13px; flex-shrink:0; transition:var(--transition); }
        .sidebar-logout:hover { background:var(--danger); color:#fff; }

        .main { flex:1; margin-left:260px; min-height:100vh; display:flex; flex-direction:column; transition:margin-left 0.3s ease; }
        .topbar { background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:16px; position:sticky; top:0; z-index:50; transition:background 0.4s ease, border-color 0.4s ease; }
        .topbar-left { display:flex; align-items:center; gap:14px; }
        .hamburger { display:none; width:40px; height:40px; border-radius:10px; border:1.5px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); align-items:center; justify-content:center; cursor:pointer; font-size:15px; }
        .hamburger:hover { border-color:var(--primary); color:var(--primary); }
        .page-title { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; color:var(--text-primary); letter-spacing:-0.4px; }
        .page-subtitle { font-size:13px; color:var(--text-muted); margin-top:2px; }
        .topbar-actions { display:flex; align-items:center; gap:10px; }
        .topbar-btn { width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); text-decoration:none; position:relative; }
        .topbar-btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .topbar-btn.theme-btn:hover { transform:rotate(20deg); }
        .topbar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:14px; font-weight:700; box-shadow:0 4px 12px rgba(59,130,246,0.3); cursor:pointer; }

        .content { padding:28px; flex:1; }

        .page-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:22px; flex-wrap:wrap; }
        .page-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .page-head h1 i { color:var(--primary); }
        .page-head .sub { font-size:13.5px; color:var(--text-muted); margin-top:4px; }

        .summaries { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:22px; }
        .sum-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; padding:18px 20px; display:flex; align-items:center; gap:14px; transition:var(--transition); }
        .sum-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); border-color:var(--primary); }
        .sum-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; flex-shrink:0; }
        .sum-icon.blue   { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .sum-icon.green  { background: linear-gradient(135deg, #10b981, #34d399); }
        .sum-icon.purple { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .sum-info .num { font-family:'Poppins',sans-serif; font-size:24px; font-weight:700; color:var(--text-primary); line-height:1.2; letter-spacing:-0.4px; }
        .sum-info .lbl { font-size:12.5px; color:var(--text-muted); margin-top:3px; font-weight:500; }

        .class-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:18px; }
        .class-card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; transition: var(--transition); display: flex; flex-direction: column; gap: 14px; }
        .class-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: var(--primary); }
        .class-card.active { border-left: 4px solid var(--success); }
        .class-card.inactive { border-left: 4px solid var(--danger); opacity: 0.85; }

        .class-head { display: flex; align-items: flex-start; gap: 14px; }
        .class-avatar { width: 56px; height: 56px; border-radius: 14px; background: linear-gradient(135deg, #2563eb, #7c3aed); color: #fff; display: flex; align-items: center; justify-content: center; font-family: 'Poppins', sans-serif; font-size: 20px; font-weight: 700; flex-shrink: 0; box-shadow: 0 8px 20px rgba(37,99,235,0.28); }
        .class-info { flex: 1; min-width: 0; }
        .class-info .n { font-family: 'Poppins', sans-serif; font-size: 17px; font-weight: 700; color: var(--text-primary); margin-bottom: 3px; }
        .class-info .sub { font-size: 12.5px; color: var(--text-muted); display: flex; flex-wrap: wrap; gap: 8px; }

        .class-pills { display: flex; flex-wrap: wrap; gap: 6px; }

        .class-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding-top: 12px; border-top: 1px dashed var(--border-color); }
        .class-meta .m-item .lbl { font-size: 10.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px; font-weight: 700; margin-bottom: 3px; }
        .class-meta .m-item .val { font-size: 13.5px; color: var(--text-primary); font-weight: 600; }
        .class-meta .m-item .val.mono { font-family: 'Courier New', monospace; font-weight: 700; }

        .class-actions { display: flex; gap: 8px; padding-top: 12px; border-top: 1px dashed var(--border-color); flex-wrap: wrap; }
        .class-actions a { flex: 1 1 auto; display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 14px; border-radius: 10px; font-size: 12.5px; font-weight: 700; text-decoration: none; transition: var(--transition); border: 1.5px solid var(--border-color); background: var(--bg-alt); color: var(--text-primary); }
        .class-actions a:hover { border-color: var(--primary); color: var(--primary); background: var(--bg-card); }
        .class-actions a.primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .class-actions a.primary:hover { background: var(--primary-dark); color: #fff; }

        .pill { display:inline-block; padding:3px 10px; border-radius:50px; font-size:11.5px; font-weight:700; letter-spacing:0.3px; line-height:1.5; }
        .pill.blue   { background: rgba(37,99,235,0.12);  color: #2563eb; }
        .pill.green  { background: rgba(16,185,129,0.12); color: #059669; }
        .pill.orange { background: rgba(245,158,11,0.15); color: #b45309; }
        .pill.purple { background: rgba(124,58,237,0.12); color: #7c3aed; }
        .pill.gray   { background: rgba(100,116,139,0.14);color: #475569; }
        .pill.red    { background: rgba(239,68,68,0.12);  color: #dc2626; }
        .pill.teal   { background: rgba(20,184,166,0.12); color: #0d9488; }
        .pill.pink   { background: rgba(236,72,153,0.12); color: #db2777; }
        [data-theme="dark"] .pill.blue   { color: #93c5fd; }
        [data-theme="dark"] .pill.green  { color: #34d399; }
        [data-theme="dark"] .pill.orange { color: #fbbf24; }
        [data-theme="dark"] .pill.purple { color: #a78bfa; }
        [data-theme="dark"] .pill.gray   { color: #cbd5e1; }
        [data-theme="dark"] .pill.red    { color: #f87171; }
        [data-theme="dark"] .pill.teal   { color: #2dd4bf; }
        [data-theme="dark"] .pill.pink   { color: #f9a8d4; }

        .empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; }
        .empty-state i { font-size:50px; opacity:0.35; margin-bottom:14px; display:block; }
        .empty-state h3 { font-family:'Poppins',sans-serif; font-size:17px; font-weight:700; color:var(--text-primary); margin-bottom:6px; }
        .empty-state p { font-size:14px; line-height:1.6; margin-bottom:18px; }

        .overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:90; backdrop-filter:blur(2px); }
        .overlay.show { display:block; }

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
            .class-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="layout">

    <!-- SIDEBAR -->
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
            <a href="teacher-classes.php" class="nav-item active"><i class="fas fa-layer-group nav-icon"></i>Classes
                <?php if ($total_classes > 0): ?><span class="nav-badge"><?= $total_classes; ?></span><?php endif; ?>
            </a>
            <a href="teacher-marks.php" class="nav-item"><i class="fas fa-clipboard-list nav-icon"></i> Marks</a>
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
            <a href="teacher-logout.php" class="sidebar-logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </aside>

    <div class="overlay" id="overlay"></div>

    <!-- MAIN -->
    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Classes</div>
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
                    <h1><i class="fas fa-layer-group"></i> Classes I Manage</h1>
                    <div class="sub">Classes where you are the assigned class teacher — manage students, attendance, and more.</div>
                </div>
            </div>

            <!-- STATS -->
            <div class="summaries">
                <div class="sum-card">
                    <div class="sum-icon blue"><i class="fas fa-layer-group"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_classes; ?></div>
                        <div class="lbl">Classes I Manage</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon green"><i class="fas fa-check-circle"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $active_classes; ?></div>
                        <div class="lbl">Active</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon purple"><i class="fas fa-user-graduate"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_students; ?></div>
                        <div class="lbl">Students (all classes)</div>
                    </div>
                </div>
            </div>

            <?php if (!empty($classes)): ?>
                <div class="class-grid">
                    <?php foreach ($classes as $c): ?>
                        <?php
                            $cid    = (int)$c['id'];
                            $cn     = $c['class_name'];
                            $clvl   = $c['level'];
                            $clv_lb = $level_map[$clvl] ?? $clvl;
                            $clv_cl = $level_colors[$clvl] ?? 'gray';

                            $cinit = "";
                            foreach (explode(" ", trim($cn)) as $p) {
                                if ($p !== "") { $cinit .= strtoupper($p[0]); if (strlen($cinit) >= 2) break; }
                            }
                            if ($cinit === "") $cinit = "C";

                            $card_cls = $c['status'] === 'active' ? 'active' : 'inactive';
                        ?>
                        <div class="class-card <?= $card_cls; ?>">

                            <div class="class-head">
                                <div class="class-avatar"><?= htmlspecialchars($cinit); ?></div>
                                <div class="class-info">
                                    <div class="n"><?= htmlspecialchars($cn); ?></div>
                                    <div class="sub">
                                        <?php if (!empty($c['year_of_study'])): ?>
                                            <span><i class="fas fa-calendar"></i> <?= htmlspecialchars($c['year_of_study']); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($c['room'])): ?>
                                            <span><i class="fas fa-door-open"></i> <?= htmlspecialchars($c['room']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="class-pills">
                                <span class="pill <?= $clv_cl; ?>"><?= htmlspecialchars($clv_lb); ?></span>
                                <?php if ($c['status'] === 'active'): ?>
                                    <span class="pill green">Active</span>
                                <?php else: ?>
                                    <span class="pill red">Inactive</span>
                                <?php endif; ?>
                                <span class="pill blue"><i class="fas fa-user-graduate"></i> <?= (int)$c['student_count']; ?> student<?= ((int)$c['student_count'] === 1) ? '' : 's'; ?></span>
                            </div>

                            <div class="class-meta">
                                <div class="m-item">
                                    <div class="lbl">Capacity</div>
                                    <div class="val mono">
                                        <?= $c['capacity'] !== null ? (int)$c['capacity'] : '—'; ?>
                                    </div>
                                </div>
                                <div class="m-item">
                                    <div class="lbl">Year of Study</div>
                                    <div class="val mono"><?= htmlspecialchars($c['year_of_study'] ?? '—'); ?></div>
                                </div>
                                <div class="m-item">
                                    <div class="lbl">Room</div>
                                    <div class="val"><?= !empty($c['room']) ? htmlspecialchars($c['room']) : '—'; ?></div>
                                </div>
                                <div class="m-item">
                                    <div class="lbl">Created</div>
                                    <div class="val"><?= date('M j, Y', strtotime($c['created_at'])); ?></div>
                                </div>
                            </div>

                            <div class="class-actions">
                                <a href="teacher-class-view.php?id=<?= $cid; ?>" class="primary">
                                    <i class="fas fa-chalkboard"></i> Open Class
                                </a>
                                <a href="teacher-attendance.php?class_id=<?= $cid; ?>">
                                    <i class="fas fa-calendar-check"></i> Attendance
                                </a>
                                <a href="teacher-class-messages.php?class_id=<?= $cid; ?>">
                                    <i class="fas fa-comments"></i> Message Class
                                </a>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-layer-group"></i>
                    <h3>You're not managing any class yet</h3>
                    <p>You are not assigned as the class teacher of any class. Your school administrator will assign you one.<br>
                       In the meantime, you can still see the <a href="teacher-subjects.php" style="color:var(--primary);font-weight:700;">subjects you teach</a>.</p>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<script>
    // THEME
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

    // SIDEBAR
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