<?php
session_start();
$systemName = "Educ Bridge";
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us | <?= htmlspecialchars($systemName); ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --secondary: #7c3aed;
            --bg-body: #f8fafc; --bg-card: #ffffff; --bg-nav: rgba(255,255,255,0.92);
            --bg-alt: #f1f5f9;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.07);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
            --gradient-footer: linear-gradient(135deg,#0f172a 0%,#1e293b 100%);
        }
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --secondary: #a78bfa;
            --bg-body: #0b1120; --bg-card: #1e293b; --bg-nav: rgba(15,23,42,0.92);
            --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
            --gradient-footer: linear-gradient(135deg,#020617 0%,#0f172a 100%);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); line-height:1.6; min-height:100vh; display:flex; flex-direction:column; transition:background 0.4s ease,color 0.4s ease; }
        .navbar { width:100%; background:var(--bg-nav); backdrop-filter:blur(16px); border-bottom:1px solid var(--border-color); padding:12px 6%; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:1000; }
        .brand { display:flex; align-items:center; gap:12px; text-decoration:none; color:var(--text-primary); }
        .brand img { width:42px; height:42px; border-radius:50%; border:2px solid var(--border-color); padding:2px; background:var(--bg-card); }
        .brand-name { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; }
        .brand-name span { color:var(--primary); }
        .nav-links { display:flex; align-items:center; gap:6px; }
        .nav-links a { text-decoration:none; color:var(--text-secondary); padding:9px 16px; border-radius:8px; font-size:14px; font-weight:500; transition:var(--transition); }
        .nav-links a:hover { background:var(--bg-alt); color:var(--primary); }
        .login-btn { background:var(--primary)!important; color:#fff!important; font-weight:600!important; }
        .theme-toggle { width:40px; height:40px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); margin-left:6px; }
        .theme-toggle:hover { border-color:var(--primary); color:var(--primary); transform:rotate(20deg); }

        /* Page Header */
        .page-header { background:linear-gradient(145deg,#f0f4ff 0%,#ffffff 50%,#eef2ff 100%); padding:80px 6% 60px; text-align:center; border-bottom:1px solid var(--border-color); transition:background 0.4s ease; }
        [data-theme="dark"] .page-header { background:linear-gradient(145deg,#0f172a 0%,#1e293b 50%,#0b1120 100%); }
        .page-header h1 { font-family:'Poppins',sans-serif; font-size:46px; font-weight:800; letter-spacing:-1.5px; margin-bottom:14px; }
        .page-header h1 span { color:var(--primary); }
        .page-header p { color:var(--text-muted); font-size:17px; max-width:640px; margin:0 auto; }
        .breadcrumb { display:flex; align-items:center; justify-content:center; gap:8px; margin-top:20px; font-size:14px; color:var(--text-muted); }
        .breadcrumb a { color:var(--primary); text-decoration:none; }
        .breadcrumb a:hover { text-decoration:underline; }

        /* Content */
        .content-wrapper { max-width:1100px; margin:0 auto; padding:70px 6%; flex:1; width:100%; }

        .content-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 50px;
            box-shadow: var(--shadow-lg);
            margin-bottom: 36px;
        }
        .content-card h2 { font-family:'Poppins',sans-serif; font-size:26px; font-weight:700; color:var(--text-primary); margin-bottom:16px; letter-spacing:-0.4px; }
        .content-card h2 i { color:var(--primary); margin-right:10px; font-size:22px; }
        .content-card p { color:var(--text-secondary); font-size:15.5px; line-height:1.8; margin-bottom:16px; }
        .content-card p:last-child { margin-bottom:0; }
        .content-card ul { list-style:none; margin:14px 0 18px; padding-left:0; }
        .content-card ul li { color:var(--text-secondary); font-size:15.5px; line-height:1.8; padding-left:28px; position:relative; margin-bottom:10px; }
        .content-card ul li::before { content:'\f00c'; font-family:'Font Awesome 6 Free'; font-weight:900; position:absolute; left:0; top:0; color:var(--primary); font-size:14px; }

        /* Mission Highlight */
        .mission-highlight {
            background: linear-gradient(135deg, rgba(37,99,235,0.08), rgba(124,58,237,0.06));
            border-left: 4px solid var(--primary);
            border-radius: 14px;
            padding: 28px 32px;
            margin: 24px 0;
            position: relative;
        }
        [data-theme="dark"] .mission-highlight {
            background: linear-gradient(135deg, rgba(59,130,246,0.12), rgba(139,92,246,0.1));
        }
        .mission-highlight i.quote {
            position: absolute;
            top: 20px;
            right: 28px;
            font-size: 40px;
            color: var(--primary);
            opacity: 0.15;
        }
        .mission-highlight p {
            font-size: 16px;
            font-style: italic;
            color: var(--text-primary);
            line-height: 1.7;
            margin: 0;
            font-weight: 500;
        }

        /* Stats Grid */
        .stats-highlight {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin: 30px 0 0;
        }
        .stat-box {
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 26px 20px;
            text-align: center;
            transition: var(--transition);
        }
        .stat-box:hover {
            transform: translateY(-4px);
            border-color: var(--primary);
            box-shadow: var(--shadow-md);
        }
        .stat-box .num {
            font-family: 'Poppins', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }
        .stat-box .lbl {
            font-size: 12.5px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* Role Cards Section */
        .role-cards-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
            margin-top: 24px;
        }
        .role-mini-card {
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px 24px;
            text-align: center;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        .role-mini-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .role-mini-card.admin::before { background: linear-gradient(90deg, #2563eb, #3b82f6); }
        .role-mini-card.teacher::before { background: linear-gradient(90deg, #10b981, #34d399); }
        .role-mini-card.parent::before { background: linear-gradient(90deg, #0ea5e9, #38bdf8); }
        .role-mini-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-md); border-color: var(--primary); }
        .role-mini-card:hover::before { opacity: 1; }
        .role-mini-card .mini-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.5rem;
            box-shadow: 0 6px 16px rgba(0,0,0,0.12);
        }
        .role-mini-card.admin .mini-icon { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .role-mini-card.teacher .mini-icon { background: linear-gradient(135deg, #10b981, #34d399); }
        .role-mini-card.parent .mini-icon { background: linear-gradient(135deg, #0ea5e9, #38bdf8); }
        .role-mini-card h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        .role-mini-card p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
            margin: 0;
        }

        /* Value Cards */
        .values-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-top: 24px;
        }
        .value-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 22px;
            transition: var(--transition);
        }
        .value-item:hover {
            transform: translateY(-3px);
            border-color: var(--primary);
            box-shadow: var(--shadow-sm);
        }
        .value-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        [data-theme="dark"] .value-icon { background: rgba(59,130,246,0.15); }
        .value-text h5 {
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 4px;
        }
        .value-text p {
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.6;
            margin: 0;
        }

        /* Partners Row */
        .partners-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 24px;
        }
        .partner-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-secondary);
            text-decoration: none;
            transition: var(--transition);
        }
        .partner-chip:hover {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .partner-chip i {
            color: var(--primary);
            font-size: 13px;
            transition: var(--transition);
        }
        .partner-chip:hover i { color: #fff; }

        /* CTA Section */
        .cta-card {
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            border-radius: 18px;
            padding: 50px;
            text-align: center;
            color: #fff;
            margin-bottom: 36px;
            box-shadow: 0 20px 45px rgba(37,99,235,0.25);
        }
        .cta-card h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }
        .cta-card p {
            font-size: 16px;
            opacity: 0.92;
            margin-bottom: 26px;
            max-width: 560px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.7;
        }
        .cta-buttons {
            display: flex;
            gap: 14px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .cta-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            font-size: 15px;
            transition: var(--transition);
        }
        .cta-btn-primary {
            background: #fff;
            color: var(--primary);
            box-shadow: 0 4px 14px rgba(0,0,0,0.15);
        }
        .cta-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.2);
        }
        .cta-btn-secondary {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border: 1.5px solid rgba(255,255,255,0.35);
        }
        .cta-btn-secondary:hover {
            background: rgba(255,255,255,0.25);
            transform: translateY(-2px);
        }

        /* ===== Professional Footer (matching index.php) ===== */
        .footer {
            background: var(--gradient-footer);
            color: #ffffff;
            padding: 70px 6% 30px;
            margin-top: auto;
            transition: background 0.4s ease;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }
        .footer-container { max-width: 1200px; margin: 0 auto; }
        .footer-grid {
            display: grid;
            grid-template-columns: 1.3fr 1fr 1.3fr 1fr;
            gap: 48px;
            margin-bottom: 50px;
        }
        .footer-col h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            color: #60a5fa;
            margin-bottom: 22px;
            position: relative;
            padding-bottom: 10px;
        }
        .footer-col h4::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 32px;
            height: 3px;
            background: linear-gradient(90deg, #3b82f6, #8b5cf6);
            border-radius: 2px;
        }
        .footer-links-list { list-style: none; display: flex; flex-direction: column; gap: 12px; }
        .footer-links-list li a {
            color: #cbd5e1;
            text-decoration: none;
            font-size: 14.5px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: var(--transition);
            line-height: 1.5;
        }
        .footer-links-list li a i { font-size: 11px; color: #3b82f6; transition: var(--transition); }
        .footer-links-list li a:hover { color: #ffffff; transform: translateX(5px); }
        .footer-links-list li a:hover i { color: #60a5fa; }
        .footer-contact { display: flex; flex-direction: column; gap: 16px; }
        .contact-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            font-size: 14.5px;
            color: #cbd5e1;
            line-height: 1.6;
        }
        .contact-item i {
            color: #3b82f6;
            font-size: 15px;
            margin-top: 3px;
            flex-shrink: 0;
            width: 18px;
            text-align: center;
        }
        .contact-item a { color: #cbd5e1; text-decoration: none; transition: var(--transition); }
        .contact-item a:hover { color: #60a5fa; }
        .footer-social { display: flex; gap: 12px; flex-wrap: wrap; }
        .social-link {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 16px;
            transition: var(--transition);
        }
        .social-link:hover {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }
        .social-link.instagram:hover {
            background: linear-gradient(45deg, #f09433, #e6683c, #dc2743, #cc2366, #bc1888);
            border-color: transparent;
        }
        .social-link.facebook:hover { background: #1877f2; border-color: #1877f2; }

        /* Footer Bottom — One Line */
        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }
        .footer-powered { font-size: 13.5px; color: #94a3b8; white-space: nowrap; }
        .footer-powered strong { color: #60a5fa; font-weight: 600; }
        .footer-bottom-links {
            display: flex;
            align-items: center;
            gap: 22px;
            flex-wrap: wrap;
            justify-content: center;
        }
        .footer-bottom-links a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            transition: var(--transition);
            white-space: nowrap;
        }
        .footer-bottom-links a:hover { color: #ffffff; }
        .footer-copyright { font-size: 13.5px; color: #94a3b8; white-space: nowrap; }

        /* ===== Responsive ===== */
        @media (max-width: 1024px) {
            .role-cards-grid { grid-template-columns: repeat(2, 1fr); }
            .footer-grid { grid-template-columns: 1fr 1fr; gap: 36px; }
            .footer-col.social-col { grid-column: auto; }
        }
        @media (max-width: 900px) {
            .footer-bottom {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 14px;
            }
            .footer-powered, .footer-copyright { white-space: normal; }
        }
        @media (max-width: 768px) {
            .page-header h1 { font-size: 32px; }
            .content-card { padding: 32px 24px; }
            .content-card h2 { font-size: 22px; }
            .cta-card { padding: 36px 24px; }
            .cta-card h3 { font-size: 22px; }
            .nav-links a:not(.login-btn) { display: none; }
            .role-cards-grid { grid-template-columns: 1fr; }
            .footer-grid { grid-template-columns: 1fr; gap: 32px; }
            .footer { padding: 50px 6% 25px; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="index.php" class="brand">
        <img src="logo.png" alt="Educ Bridge Logo">
        <span class="brand-name">Educ<span>Bridge</span></span>
    </a>
    <div class="nav-links">
        <a href="index.php">Home</a>
        <a href="index.php #roles" class="login-btn">
                <i class="fas fa-sign-in-alt" style="font-size: 13px;"></i> Login
            </a>
        
        <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode"><i class="fas fa-moon"></i></button>
    </div>
</nav>

<div class="page-header">
    <h1>About <span>Educ Bridge</span></h1>
    <p>Bridging the gap between parents, teachers, and school administrators through one unified platform.</p>
    <div class="breadcrumb">
        <a href="index.php">Home</a> <i class="fas fa-chevron-right" style="font-size:10px;"></i> <span>About</span>
    </div>
</div>

<div class="content-wrapper">

    <!-- Our Mission -->
    <div class="content-card">
        <h2><i class="fas fa-bullseye"></i> Our Mission</h2>
        <p>
            Educ Bridge was created with a simple yet powerful mission: to make school communication
            easier, faster, and more effective for everyone involved. By bringing parents, teachers,
            and school administrators onto one unified platform, we help build stronger partnerships
            that support student success and educational excellence across Rwanda.
        </p>

        <div class="mission-highlight">
            <i class="fas fa-quote-right quote"></i>
            <p>Building a bridge between school and family — one connection at a time.</p>
        </div>
    </div>

    <!-- Our Vision -->
    <div class="content-card">
        <h2><i class="fas fa-eye"></i> Our Vision</h2>
        <p>
            We envision a future where every school community is digitally connected — where parents
            are actively engaged in their children's education, teachers have the tools they need
            to inspire learning, and administrators can run efficient, transparent, and successful
            institutions.
        </p>

        <div class="stats-highlight">
            <div class="stat-box">
                <div class="num">3+</div>
                <div class="lbl">User Roles</div>
            </div>
            <div class="stat-box">
                <div class="num">24/7</div>
                <div class="lbl">System Live</div>
            </div>
            <div class="stat-box">
                <div class="num">100%</div>
                <div class="lbl">Secure</div>
            </div>
            <div class="stat-box">
                <div class="num">1</div>
                <div class="lbl">Unified Platform</div>
            </div>
        </div>
    </div>

    <!-- Who We Serve -->
    <div class="content-card">
        <h2><i class="fas fa-users"></i> Who We Serve</h2>
        <p>
            Educ Bridge is designed for every member of the school community. Each role gets a
            tailored experience built around their specific needs.
        </p>

        <div class="role-cards-grid">
            <div class="role-mini-card admin">
                <div class="mini-icon"><i class="fas fa-user-shield"></i></div>
                <h4>School Administrators</h4>
                <p>Manage students, staff, academic records, announcements, and full school operations from one powerful dashboard.</p>
            </div>
            <div class="role-mini-card teacher">
                <div class="mini-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                <h4>Teachers</h4>
                <p>Record lessons, track student performance, mark attendance, and communicate with parents through an intuitive interface.</p>
            </div>
            <div class="role-mini-card parent">
                <div class="mini-icon"><i class="fas fa-user-friends"></i></div>
                <h4>Parents</h4>
                <p>Monitor your child's academic progress, receive real-time updates, and stay connected with teachers and the school.</p>
            </div>
        </div>
    </div>

    <!-- Why Choose Us -->
    <div class="content-card">
        <h2><i class="fas fa-star"></i> Why Choose Educ Bridge?</h2>
        <p>
            We combine thoughtful design with practical tools to give every user a seamless,
            secure, and meaningful experience.
        </p>

        <div class="values-grid">
            <div class="value-item">
                <div class="value-icon"><i class="fas fa-shield-alt"></i></div>
                <div class="value-text">
                    <h5>Secure Access</h5>
                    <p>Role-based authentication and encrypted data keep every account protected.</p>
                </div>
            </div>
            <div class="value-item">
                <div class="value-icon"><i class="fas fa-chart-line"></i></div>
                <div class="value-text">
                    <h5>Real-time Analytics</h5>
                    <p>Actionable dashboards give parents and staff clarity at a glance.</p>
                </div>
            </div>
            <div class="value-item">
                <div class="value-icon"><i class="fas fa-mobile-alt"></i></div>
                <div class="value-text">
                    <h5>Fully Responsive</h5>
                    <p>Works beautifully on desktop, tablet, and mobile devices.</p>
                </div>
            </div>
            <div class="value-item">
                <div class="value-icon"><i class="fas fa-comments"></i></div>
                <div class="value-text">
                    <h5>Communication Tools</h5>
                    <p>Built-in messaging and announcements connect home and school.</p>
                </div>
            </div>
            <div class="value-item">
                <div class="value-icon"><i class="fas fa-clock"></i></div>
                <div class="value-text">
                    <h5>Always Online</h5>
                    <p>Our platform runs 24/7 so you can access it anytime, anywhere.</p>
                </div>
            </div>
            <div class="value-item">
                <div class="value-icon"><i class="fas fa-moon"></i></div>
                <div class="value-text">
                    <h5>Light &amp; Dark Theme</h5>
                    <p>Choose the interface that's easiest on your eyes, day or night.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Our Partners -->
    <div class="content-card">
        <h2><i class="fas fa-handshake"></i> Our Partners</h2>
        <p>
            We collaborate with trusted educational institutions and government bodies to ensure
            Educ Bridge meets the highest standards of quality and compliance.
        </p>

        <div class="partners-row">
            <a href="https://www.nesa.gov.rw/" target="_blank" rel="noopener noreferrer" class="partner-chip">
                <i class="fas fa-external-link-alt"></i> NESA
            </a>
            <a href="https://www.reb.gov.rw/home" target="_blank" rel="noopener noreferrer" class="partner-chip">
                <i class="fas fa-external-link-alt"></i> REB
            </a>
            <a href="https://ur.ac.rw/" target="_blank" rel="noopener noreferrer" class="partner-chip">
                <i class="fas fa-external-link-alt"></i> University of Rwanda
            </a>
            <a href="http://localhost/dngroup/index.php" target="_blank" rel="noopener noreferrer" class="partner-chip">
                <i class="fas fa-external-link-alt"></i> D-N Group
            </a>
        </div>
    </div>

    <!-- CTA -->
    <div class="cta-card">
        <h3>Ready to bridge the gap?</h3>
        <p>Join the Educ Bridge community today — whether you're a parent, teacher, or administrator, we've got a place for you.</p>
        <div class="cta-buttons">
            
            <a href="contact.php" class="cta-btn cta-btn-secondary">
                <i class="fas fa-envelope"></i> Contact Us
            </a>
        </div>
    </div>

</div>

<!-- ===== Professional Footer (matching index.php) ===== -->
<footer class="footer">
    <div class="footer-container">
        <div class="footer-grid">

            <!-- Column 1: Quick Links -->
            <div class="footer-col">
                <h4>Quick Links</h4>
                <ul class="footer-links-list">
                    <li><a href="index.php"><i class="fas fa-chevron-right"></i> Home</a></li>
                    <li><a href="index.php#roles"><i class="fas fa-chevron-right"></i> Login</a></li>
                    <li><a href="index.php#features"><i class="fas fa-chevron-right"></i> System Features</a></li>
                    <li><a href="about.php"><i class="fas fa-chevron-right"></i> About Us</a></li>
                    
                    <li><a href="contact.php"><i class="fas fa-chevron-right"></i> Contact Us</a></li>
                </ul>
            </div>

            <!-- Column 2: Our Partners -->
            <div class="footer-col">
                <h4>Our Partners</h4>
                <ul class="footer-links-list">
                    <li>
                        <a href="https://www.nesa.gov.rw/" target="_blank" rel="noopener noreferrer">
                            <i class="fas fa-chevron-right"></i> NESA
                        </a>
                    </li>
                    <li>
                        <a href="https://www.reb.gov.rw/home" target="_blank" rel="noopener noreferrer">
                            <i class="fas fa-chevron-right"></i> REB
                        </a>
                    </li>
                    <li>
                        <a href="https://ur.ac.rw/" target="_blank" rel="noopener noreferrer">
                            <i class="fas fa-chevron-right"></i> UR
                        </a>
                    </li>
                    <li>
                        <a href="http://localhost/dngroup/index.php" target="_blank" rel="noopener noreferrer">
                            <i class="fas fa-chevron-right"></i> DN-Group
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Column 3: Contact Us -->
            <div class="footer-col">
                <h4>Contact Us</h4>
                <div class="footer-contact">
                    <div class="contact-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span>KN 3 Ave, 30 St Kigali – Rwanda</span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-phone-alt"></i>
                        <span>
                            <a href="tel:+250783561459">+250 783 561 459</a>
                        </span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-envelope"></i>
                        <span>
                            <a href="mailto:info.dngroup1@gmail.com">info.dngroup1@gmail.com</a>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Column 4: Follow Us -->
            <div class="footer-col social-col">
                <h4>Follow Us</h4>
                <div class="footer-social">
                    <a href="https://www.instagram.com/dngroup.1?utm_source=ig_web_button_share_sheet&stkn=ZDNlZDc0MzIxNw==" target="_blank" rel="noopener noreferrer" class="social-link instagram" title="Instagram" aria-label="Instagram">
                        <i class="fab fa-instagram"></i>
                    </a>
                    <a href="https://www.facebook.com/profile.php?id=61592747278836" target="_blank" rel="noopener noreferrer" class="social-link facebook" title="Facebook" aria-label="Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                </div>
                <p style="margin-top: 18px; font-size: 13px; color: #94a3b8; line-height: 1.6;">
                    Follow D-N Group for updates, news, and more from the Educ Bridge team.
                </p>
            </div>

        </div>

        <!-- Footer Bottom — One Line -->
        <div class="footer-bottom">
            <div class="footer-powered">
                Powered by <strong>D-N Group</strong>
            </div>

            <div class="footer-bottom-links">
                <a href="privacy.php">Privacy Policy</a>
                <a href="terms.php">Terms &amp; Conditions</a>
                <!-- <a href="contact.php">Contact</a>
                <a href="about.php">About</a> -->
            </div>

            <div class="footer-copyright">
                &copy; <?= date('Y'); ?> Educ Bridge. All rights reserved.
            </div>
        </div>
    </div>
</footer>

<script>
    (function () {
        const html = document.documentElement;
        const toggle = document.getElementById('themeToggle');
        const icon = toggle.querySelector('i');
        const savedTheme = localStorage.getItem('educbridge-theme') || 'light';
        html.setAttribute('data-theme', savedTheme);
        updateIcon(savedTheme);
        toggle.addEventListener('click', function () {
            const current = html.getAttribute('data-theme');
            const next = current === 'light' ? 'dark' : 'light';
            html.setAttribute('data-theme', next);
            localStorage.setItem('educbridge-theme', next);
            updateIcon(next);
        });
        function updateIcon(theme) {
            if (theme === 'dark') { icon.classList.remove('fa-moon'); icon.classList.add('fa-sun'); }
            else { icon.classList.remove('fa-sun'); icon.classList.add('fa-moon'); }
        }
    })();
</script>

</body>
</html>