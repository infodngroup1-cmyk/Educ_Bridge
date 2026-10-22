<?php
require_once 'db.php';
require_once '_school-auth.php';
/*
| After this require:
|   $school_id, $school_name, $school_admin, $school_code, $initials, $short_school
| are already defined and the user is guaranteed logged in.
*/

$page_title = 'Events';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Events | Educ Bridge</title>
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
        --sidebar-bg:#0f172a;
        --sidebar-hover:rgba(255,255,255,.06);
        --sidebar-active:rgba(37,99,235,.22);
        --sidebar-text:#cbd5e1;
        --sidebar-text-active:#ffffff;
        --sidebar-border:rgba(255,255,255,.08);
        --sidebar-title:#64748b;
        --sidebar-badge-bg:rgba(37,99,235,.25);
        --sidebar-badge-fg:#93c5fd;
        --bg-body:#f8fafc;--bg-card:#ffffff;--bg-alt:#f1f5f9;
        --text-primary:#0f172a;--text-secondary:#475569;--text-muted:#64748b;
        --border-color:#e2e8f0;
        --shadow-md:0 4px 12px rgba(0,0,0,.07);
        --shadow-lg:0 20px 45px rgba(0,0,0,.08);
        --shadow-sidebar:0 0 30px rgba(0,0,0,.05);
        --transition:all .3s cubic-bezier(.4,0,.2,1);
    }
    [data-theme="dark"] {
        --primary:#3b82f6;--primary-dark:#2563eb;--primary-light:#1e3a5f;
        --success:#34d399;--success-light:rgba(16,185,129,.15);
        --warning:#fbbf24;--warning-light:rgba(245,158,11,.18);
        --danger:#f87171;--danger-light:rgba(239,68,68,.15);
        --sidebar-bg:#0b1120;
        --sidebar-hover:rgba(255,255,255,.06);
        --sidebar-active:rgba(59,130,246,.2);
        --sidebar-text:#cbd5e1;
        --sidebar-text-active:#ffffff;
        --sidebar-border:rgba(255,255,255,.08);
        --sidebar-title:#64748b;
        --sidebar-badge-bg:rgba(59,130,246,.25);
        --sidebar-badge-fg:#93c5fd;
        --bg-body:#0b1120;--bg-card:#1e293b;--bg-alt:#1a2332;
        --text-primary:#f1f5f9;--text-secondary:#cbd5e1;--text-muted:#94a3b8;
        --border-color:#334155;
        --shadow-md:0 4px 12px rgba(0,0,0,.4);
        --shadow-lg:0 20px 45px rgba(0,0,0,.5);
        --shadow-sidebar:0 0 30px rgba(0,0,0,.4);
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',sans-serif;background:var(--bg-body);color:var(--text-primary);transition:background .4s ease,color .4s ease;overflow-x:hidden}
    .layout{display:flex;min-height:100vh}

    /* SIDEBAR */
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

    /* MAIN */
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

    .content{padding:28px;flex:1;display:flex;align-items:center;justify-content:center;min-height:calc(100vh - 71px)}

    /* Maintenance card */
    .maint-card{
        max-width:640px;width:100%;
        background:var(--bg-card);border:1px solid var(--border-color);
        border-radius:24px;padding:48px 44px;
        text-align:center;position:relative;overflow:hidden;
        box-shadow:var(--shadow-lg);
    }
    .maint-card::before{
        content:'';position:absolute;top:-70px;right:-70px;
        width:220px;height:220px;border-radius:50%;
        background:radial-gradient(circle,rgba(37,99,235,.18) 0%,transparent 70%);
        pointer-events:none;
    }
    .maint-card::after{
        content:'';position:absolute;bottom:-90px;left:-60px;
        width:240px;height:240px;border-radius:50%;
        background:radial-gradient(circle,rgba(124,58,237,.14) 0%,transparent 70%);
        pointer-events:none;
    }
    .maint-icon{
        width:110px;height:110px;border-radius:50%;
        background:linear-gradient(135deg,#2563eb,#7c3aed);
        color:#fff;
        display:inline-flex;align-items:center;justify-content:center;
        font-size:46px;margin-bottom:22px;
        box-shadow:0 18px 40px rgba(37,99,235,.35);
        position:relative;z-index:1;
        animation:pulse 2.4s ease-in-out infinite;
    }
    @keyframes pulse{
        0%,100%{transform:scale(1);box-shadow:0 18px 40px rgba(37,99,235,.35)}
        50%{transform:scale(1.05);box-shadow:0 22px 48px rgba(37,99,235,.5)}
    }
    .maint-icon i{animation:sway 3s ease-in-out infinite}
    @keyframes sway{
        0%,100%{transform:rotate(0)}
        25%{transform:rotate(-8deg)}
        75%{transform:rotate(8deg)}
    }

    .status-badge{
        display:inline-flex;align-items:center;gap:8px;
        padding:8px 16px;border-radius:50px;
        background:var(--warning-light);
        color:#b45309;border:1px solid rgba(245,158,11,.35);
        font-size:12.5px;font-weight:700;letter-spacing:.3px;
        margin-bottom:20px;position:relative;z-index:1;
    }
    [data-theme="dark"] .status-badge{color:#fbbf24}
    .status-badge .dot{width:8px;height:8px;border-radius:50%;background:var(--warning);animation:blink 1.4s ease-in-out infinite}
    @keyframes blink{0%,100%{opacity:1}50%{opacity:.35}}

    .maint-card h1{font-family:'Poppins',sans-serif;font-size:26px;font-weight:700;color:var(--text-primary);margin-bottom:12px;position:relative;z-index:1;letter-spacing:-.4px}
    .maint-card p{font-size:15px;color:var(--text-secondary);line-height:1.7;max-width:480px;margin:0 auto 12px;position:relative;z-index:1}
    .maint-card p.small{font-size:13.5px;color:var(--text-muted);margin-top:6px}

    .maint-actions{margin-top:28px;position:relative;z-index:1;display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
    .maint-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:10px;font-size:14px;font-weight:700;text-decoration:none;border:1.5px solid var(--border-color);background:var(--bg-alt);color:var(--text-primary);transition:var(--transition);font-family:inherit;cursor:pointer}
    .maint-btn:hover{border-color:var(--primary);color:var(--primary);background:var(--bg-card)}
    .maint-btn.primary{background:var(--primary);color:#fff;border-color:var(--primary);box-shadow:0 6px 18px rgba(37,99,235,.32)}
    .maint-btn.primary:hover{background:var(--primary-dark);color:#fff}

    .overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:90;backdrop-filter:blur(2px)}
    .overlay.show{display:block}

    @media (max-width:900px){
        .sidebar{transform:translateX(-100%);transition:transform .3s}
        .sidebar.open{transform:translateX(0)}
        .main{margin-left:0}
        .hamburger{display:flex}
    }
    @media (max-width:640px){
        .content{padding:20px 16px}
        .topbar{padding:12px 16px}
        .maint-card{padding:36px 26px}
        .maint-card h1{font-size:22px}
        .maint-icon{width:90px;height:90px;font-size:38px}
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
            <a href="school-students.php" class="nav-item"><i class="fas fa-user-graduate nav-icon"></i> Students</a>
            <a href="school-teachers.php" class="nav-item"><i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers</a>
            <a href="school-classes.php" class="nav-item"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
            <a href="school-subjects.php" class="nav-item">
                <i class="fas fa-book nav-icon"></i> Subjects
                <?php if (!empty($subject_count)): ?><span class="nav-badge"><?= (int)$subject_count; ?></span><?php endif; ?>
            </a>

            <div class="nav-section-title">Communication</div>
            <a href="school-announcements.php" class="nav-item"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>
            <a href="school-events.php" class="nav-item active"><i class="fas fa-calendar-alt nav-icon"></i> Events</a>

            <div class="nav-section-title">System</div>
            <a href="school-reports.php" class="nav-item active"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
            <a href="school-reports.php" class="nav-item"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
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
                    <div class="page-title">Events</div>
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
            <div class="maint-card">
                <div class="maint-icon"><i class="fas fa-calendar-plus"></i></div>
                <div class="status-badge">
                    <span class="dot"></span>
                    System Update in Progress
                </div>

                <h1>We're improving Events</h1>

                <p>
                    Sorry — <strong>the Events module will be updated soon.</strong>
                    Our team is working to make it faster, cleaner and easier to use for your school.
                </p>
                <p class="small">
                    Check back shortly. Everything else — students, teachers, classes, subjects, announcements and reports — continues to work as usual.
                </p>

                <div class="maint-actions">
                    <a href="school-dashboard.php" class="maint-btn primary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <a href="mailto:info.dngroup1@gmail.com" class="maint-btn">
                        <i class="fas fa-headset"></i> Contact Support
                    </a>
                </div>
            </div>
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