<?php
require_once 'db.php';
require_once '_school-auth.php';
/*
| After the guard runs:
|   $school_id, $school_name, $school_admin, $school_code, $initials, $short_school
| are available and the user is guaranteed logged in.
*/

$message = "";
$message_type = "";

/*
|--------------------------------------------------------------------------
| Optional: quick status counts for the cards
|--------------------------------------------------------------------------
*/

/* Pending applications */
$pending_applications = 0;
$st = @mysqli_prepare($conn, "SELECT COUNT(*) AS t FROM applications WHERE school_id = ? AND status = 'pending'");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $school_id);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    $pending_applications = $r ? (int)mysqli_fetch_assoc($r)['t'] : 0;
    mysqli_stmt_close($st);
}

/* Registered students */
$students_count = 0;
$st = @mysqli_prepare($conn, "SELECT COUNT(*) AS t FROM students WHERE school_id = ?");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $school_id);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    $students_count = $r ? (int)mysqli_fetch_assoc($r)['t'] : 0;
    mysqli_stmt_close($st);
}

/* Classes count */
$classes_count = 0;
$st = @mysqli_prepare($conn, "SELECT COUNT(*) AS t FROM classes WHERE school_id = ?");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $school_id);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    $classes_count = $r ? (int)mysqli_fetch_assoc($r)['t'] : 0;
    mysqli_stmt_close($st);
}

/* Subjects count */
$subjects_count = 0;
$st = @mysqli_prepare($conn, "SELECT COUNT(*) AS t FROM subjects WHERE school_id = ?");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $school_id);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    $subjects_count = $r ? (int)mysqli_fetch_assoc($r)['t'] : 0;
    mysqli_stmt_close($st);
}

/* Settings row (may not exist yet) */
$settings = [
    'online_registration' => 0,
    'admission_open'      => 0,
    'marks_entry_open'    => 0,
    'reports_open'        => 0,
];
$st = @mysqli_prepare($conn, "SELECT * FROM school_settings WHERE school_id = ? LIMIT 1");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $school_id);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    if ($row = mysqli_fetch_assoc($r)) {
        $settings['online_registration'] = (int)$row['online_registration'];
        $settings['admission_open']      = (int)$row['admission_open'];
        $settings['marks_entry_open']    = (int)$row['marks_entry_open'];
        $settings['reports_open']        = (int)$row['reports_open'];
    }
    mysqli_stmt_close($st);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | Educ Bridge</title>
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
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(59, 130, 246, 0.18);
            --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.08);
            --sidebar-title: #94a3b8;
            --sidebar-badge-bg: rgba(59, 130, 246, 0.2);
            --sidebar-badge-fg: #93c5fd;
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

        /* SIDEBAR */
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

        /* MAIN */
        .main { flex:1; margin-left:260px; min-height:100vh; display:flex; flex-direction:column; transition:margin-left 0.3s ease; }
        .topbar { background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:16px; position:sticky; top:0; z-index:50; }
        .topbar-left { display:flex; align-items:center; gap:14px; }
        .hamburger { display:none; width:40px; height:40px; border-radius:10px; border:1.5px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); align-items:center; justify-content:center; cursor:pointer; font-size:15px; }
        .hamburger:hover { border-color:var(--primary); color:var(--primary); }
        .page-title { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; letter-spacing:-0.4px; }
        .page-subtitle { font-size:13px; color:var(--text-muted); margin-top:2px; }
        .topbar-actions { display:flex; align-items:center; gap:10px; }
        .topbar-btn { width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); text-decoration:none; position:relative; }
        .topbar-btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .topbar-btn.theme-btn:hover { transform:rotate(20deg); }
        .topbar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:14px; font-weight:700; box-shadow:0 4px 12px rgba(59,130,246,0.3); cursor:pointer; }

        .content { padding:28px; flex:1; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.5; }
        .alert-success { background: var(--success-light); color:#059669; border:1px solid rgba(16,185,129,0.25); }
        [data-theme="dark"] .alert-success { color:#34d399; }
        .alert-error { background: var(--danger-light); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        [data-theme="dark"] .alert-error { color:#f87171; }

        .page-head { margin-bottom:24px; }
        .page-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .page-head h1 i { color:var(--primary); }
        .page-head .sub { font-size:13.5px; color:var(--text-muted); margin-top:4px; }

        /* SETTINGS CARDS GRID */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        .settings-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            transition: var(--transition);
            text-decoration: none;
            color: inherit;
            position: relative;
            overflow: hidden;
        }
        .settings-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: var(--primary);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.35s ease;
        }
        .settings-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }
        .settings-card:hover::before { transform: scaleX(1); }

        .settings-card .sc-icon {
            width: 58px; height: 58px;
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; color: #fff;
            box-shadow: 0 10px 24px rgba(37,99,235,0.22);
            transition: var(--transition);
        }
        .settings-card:hover .sc-icon { transform: scale(1.05) rotate(-4deg); }

        .sc-icon.blue    { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .sc-icon.green   { background: linear-gradient(135deg, #10b981, #34d399); box-shadow: 0 10px 24px rgba(16,185,129,0.25); }
        .sc-icon.purple  { background: linear-gradient(135deg, #7c3aed, #a78bfa); box-shadow: 0 10px 24px rgba(124,58,237,0.25); }
        .sc-icon.orange  { background: linear-gradient(135deg, #f59e0b, #fbbf24); box-shadow: 0 10px 24px rgba(245,158,11,0.28); }

        .settings-card h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 17px; font-weight: 700;
            letter-spacing: -0.3px;
            color: var(--text-primary);
        }
        .settings-card p {
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.6;
            flex: 1;
        }

        .settings-card .sc-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding-top: 14px;
            border-top: 1px dashed var(--border-color);
        }
        .settings-card .sc-status {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 12px; font-weight: 700;
            padding: 4px 12px; border-radius: 50px;
            background: var(--bg-alt);
            color: var(--text-secondary);
        }
        .settings-card .sc-status .dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--text-muted);
        }
        .settings-card .sc-status.on {
            background: var(--success-light);
            color: #059669;
        }
        [data-theme="dark"] .settings-card .sc-status.on { color: #34d399; }
        .settings-card .sc-status.on .dot { background: var(--success); }

        .settings-card .sc-arrow {
            font-size: 13px;
            color: var(--primary);
            font-weight: 700;
            display: inline-flex; align-items: center; gap: 6px;
            transition: var(--transition);
        }
        .settings-card:hover .sc-arrow { gap: 10px; }

        /* Small info panel */
        .info-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px 22px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
        }
        .info-panel .ip-item .ip-num {
            font-family: 'Poppins', sans-serif;
            font-size: 22px; font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            letter-spacing: -0.4px;
        }
        .info-panel .ip-item .ip-lbl {
            font-size: 12.5px; color: var(--text-muted);
            margin-top: 3px; font-weight: 500;
        }

        .overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:90; backdrop-filter: blur(2px); }
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
            <a href="school-dashboard.php" class="nav-item"><i class="fas fa-th-large nav-icon"></i> Dashboard</a>
            <div class="nav-section-title">Academics</div>
            <a href="school-academic-levels.php" class="nav-item">
                <i class="fas fa-graduation-cap nav-icon"></i>
                Academic Level
            </a>
            <a href="school-students.php" class="nav-item"><i class="fas fa-user-graduate nav-icon"></i> Students</a>
            <a href="school-teachers.php" class="nav-item"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>
            <a href="school-classes.php" class="nav-item"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
            <a href="school-subjects.php" class="nav-item"><i class="fas fa-book nav-icon"></i> Subjects</a>
            <div class="nav-section-title">Communication</div>
            <a href="school-announcements.php" class="nav-item"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>
            <a href="school-events.php" class="nav-item"><i class="fas fa-calendar-alt nav-icon"></i> Events</a>
            <div class="nav-section-title">System</div>
            <a href="school-reports.php" class="nav-item active"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
            <a href="school-settings.php" class="nav-item active"><i class="fas fa-cog nav-icon"></i> Settings</a>
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

    <!-- MAIN -->
    <div class="main">

        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div>
                    <div class="page-title">Settings</div>
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

            <?php if ($message !== ""): ?>
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <div class="page-head">
                <h1><i class="fas fa-cog"></i> School Settings</h1>
                <div class="sub">Manage online services, applications, marks and reports for your school.</div>
            </div>

            <!-- QUICK INFO PANEL -->
            <div class="info-panel" style="margin-bottom:24px;">
                <div class="ip-item">
                    <div class="ip-num"><?= $students_count; ?></div>
                    <div class="ip-lbl">Students</div>
                </div>
                <div class="ip-item">
                    <div class="ip-num"><?= $classes_count; ?></div>
                    <div class="ip-lbl">Classes</div>
                </div>
                <div class="ip-item">
                    <div class="ip-num"><?= $subjects_count; ?></div>
                    <div class="ip-lbl">Subjects</div>
                </div>
                <div class="ip-item">
                    <div class="ip-num"><?= $pending_applications; ?></div>
                    <div class="ip-lbl">Pending Applications</div>
                </div>
            </div>

            <!-- SETTINGS CARDS -->
            <div class="settings-grid">

                <!-- 1. ONLINE REGISTRATION -->
                <a href="school-online-registration.php" class="settings-card">
                    <div class="sc-icon blue"><i class="fas fa-globe"></i></div>
                    <h3>Online Registration</h3>
                    <p>Let parents and students register online through the public registration page. Control who can register and when.</p>
                    <div class="sc-footer">
                        <span class="sc-status <?= !empty($settings['online_registration']) ? 'on' : ''; ?>">
                            <span class="dot"></span>
                            <?= !empty($settings['online_registration']) ? 'Enabled' : 'Disabled'; ?>
                        </span>
                        <span class="sc-arrow">Configure <i class="fas fa-arrow-right"></i></span>
                    </div>
                </a>

                <!-- 2. APPLICATION TO JOIN SCHOOL -->
                <a href="school-applications.php" class="settings-card">
                    <div class="sc-icon green"><i class="fas fa-user-plus"></i></div>
                    <h3>Apply to Join School</h3>
                    <p>Review applications from families who want to join your school. Accept, reject, or move applicants to the waitlist.</p>
                    <div class="sc-footer">
                        <span class="sc-status <?= $pending_applications > 0 ? 'on' : ''; ?>">
                            <span class="dot"></span>
                            <?= $pending_applications; ?> pending
                        </span>
                        <span class="sc-arrow">Open <i class="fas fa-arrow-right"></i></span>
                    </div>
                </a>

                <!-- 3. STUDENTS REPORT -->
                <a href="school-reports.php" class="settings-card">
                    <div class="sc-icon purple"><i class="fas fa-file-lines"></i></div>
                    <h3>Students Report</h3>
                    <p>Generate report cards, progress summaries, and export PDFs for each student or the whole class.</p>
                    <div class="sc-footer">
                        <span class="sc-status <?= !empty($settings['reports_open']) ? 'on' : ''; ?>">
                            <span class="dot"></span>
                            <?= !empty($settings['reports_open']) ? 'Enabled' : 'Disabled'; ?>
                        </span>
                        <span class="sc-arrow">Open <i class="fas fa-arrow-right"></i></span>
                    </div>
                </a>

                <!-- 4. MARK INSERTION -->
                <a href="school-marks.php" class="settings-card">
                    <div class="sc-icon orange"><i class="fas fa-clipboard-list"></i></div>
                    <h3>Mark Insertion</h3>
                    <p>Allow teachers to enter and submit marks for their classes and subjects. Set the periods when marks can be entered.</p>
                    <div class="sc-footer">
                        <span class="sc-status <?= !empty($settings['marks_entry_open']) ? 'on' : ''; ?>">
                            <span class="dot"></span>
                            <?= !empty($settings['marks_entry_open']) ? 'Enabled' : 'Disabled'; ?>
                        </span>
                        <span class="sc-arrow">Configure <i class="fas fa-arrow-right"></i></span>
                    </div>
                </a>

            </div>

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