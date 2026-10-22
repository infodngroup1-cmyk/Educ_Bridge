<?php
session_start();

require_once 'db.php';

$systemName = "Educ Bridge";

// Handle form submission
$formMessage = '';
$formStatus = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name === '' || $email === '' || $subject === '' || $message === '') {
        $formStatus = 'error';
        $formMessage = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formStatus = 'error';
        $formMessage = 'Please enter a valid email address.';
    } else {

        $insert_sql = "INSERT INTO messages (name, email, subject, message) VALUES (?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $insert_sql);

        if (!$stmt) {
            $formStatus = 'error';
            $formMessage = 'Database error. Please try again.';
        } else {

            mysqli_stmt_bind_param($stmt, "ssss", $name, $email, $subject, $message);

            if (mysqli_stmt_execute($stmt)) {
                $formStatus = 'success';
                $formMessage = 'Thank you, ' . htmlspecialchars($name) . '! Your message has been received. We will respond to you soon on your Email';
            } else {
                $formStatus = 'error';
                $formMessage = 'Could not save your message. Please try again.';
            }

            mysqli_stmt_close($stmt);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us | <?= htmlspecialchars($systemName); ?></title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --bg-body: #f8fafc; --bg-card: #ffffff; --bg-nav: rgba(255,255,255,0.92);
            --bg-alt: #f1f5f9;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
            --gradient-footer: linear-gradient(135deg,#0f172a 0%,#1e293b 100%);
        }
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --bg-body: #0b1120; --bg-card: #1e293b; --bg-nav: rgba(15,23,42,0.92);
            --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
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
        .page-header { background:linear-gradient(145deg,#f0f4ff 0%,#ffffff 50%,#eef2ff 100%); padding:80px 6% 60px; text-align:center; border-bottom:1px solid var(--border-color); transition:background 0.4s ease; }
        [data-theme="dark"] .page-header { background:linear-gradient(145deg,#0f172a 0%,#1e293b 50%,#0b1120 100%); }
        .page-header h1 { font-family:'Poppins',sans-serif; font-size:46px; font-weight:800; letter-spacing:-1.5px; margin-bottom:14px; }
        .page-header h1 span { color:var(--primary); }
        .page-header p { color:var(--text-muted); font-size:17px; max-width:600px; margin:0 auto; }
        .breadcrumb { display:flex; align-items:center; justify-content:center; gap:8px; margin-top:20px; font-size:14px; color:var(--text-muted); }
        .breadcrumb a { color:var(--primary); text-decoration:none; }
        .breadcrumb a:hover { text-decoration:underline; }
        .content-wrapper { max-width:1100px; margin:0 auto; padding:70px 6%; flex:1; width:100%; }
        .contact-grid { display:grid; grid-template-columns:1fr 1.3fr; gap:36px; }
        .contact-info-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:18px; padding:40px; box-shadow:var(--shadow-lg); }
        .contact-info-card h2 { font-family:'Poppins',sans-serif; font-size:24px; font-weight:700; margin-bottom:10px; }
        .contact-info-card > p { color:var(--text-muted); font-size:15px; margin-bottom:30px; line-height:1.7; }
        .contact-detail { display:flex; align-items:flex-start; gap:16px; margin-bottom:24px; }
        .contact-icon-box { width:46px; height:46px; border-radius:12px; background:var(--primary-light); color:var(--primary); display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
        [data-theme="dark"] .contact-icon-box { background:rgba(59,130,246,0.15); }
        .contact-detail-text h4 { font-family:'Poppins',sans-serif; font-size:15px; font-weight:600; margin-bottom:4px; color:var(--text-primary); }
        .contact-detail-text p, .contact-detail-text a { color:var(--text-muted); font-size:14.5px; text-decoration:none; transition:var(--transition); }
        .contact-detail-text a:hover { color:var(--primary); }
        .contact-social { display:flex; gap:12px; margin-top:30px; padding-top:30px; border-top:1px solid var(--border-color); }
        .contact-social a { width:44px; height:44px; border-radius:50%; background:var(--bg-alt); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:16px; transition:var(--transition); border:1px solid var(--border-color); }
        .contact-social a:hover { background:var(--primary); color:#fff; border-color:var(--primary); transform:translateY(-3px); }
        .contact-social a.instagram:hover { background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888); border-color:transparent; }
        .contact-social a.facebook:hover { background:#1877f2; border-color:#1877f2; }

        /* 24/7 Live Notice */
        .live-notice {
            margin-top: 24px;
            padding: 18px 20px;
            background: linear-gradient(135deg, rgba(16,185,129,0.08), rgba(59,130,246,0.08));
            border: 1px solid rgba(16,185,129,0.25);
            border-radius: 14px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }
        [data-theme="dark"] .live-notice {
            background: linear-gradient(135deg, rgba(16,185,129,0.12), rgba(59,130,246,0.1));
            border-color: rgba(16,185,129,0.3);
        }
        .live-notice .live-dot {
            position: relative;
            width: 12px;
            height: 12px;
            background: #10b981;
            border-radius: 50%;
            margin-top: 6px;
            flex-shrink: 0;
            box-shadow: 0 0 0 0 rgba(16,185,129,0.6);
            animation: pulse-green 2s infinite;
        }
        @keyframes pulse-green {
            0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.7); }
            70% { box-shadow: 0 0 0 10px rgba(16,185,129,0); }
            100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
        }
        .live-notice-text { flex: 1; }
        .live-notice-text h5 {
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            font-weight: 700;
            color: #10b981;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        [data-theme="dark"] .live-notice-text h5 { color: #34d399; }
        .live-notice-text p {
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.6;
            margin: 0;
        }

        .contact-form-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:18px; padding:40px; box-shadow:var(--shadow-lg); }
        .contact-form-card h2 { font-family:'Poppins',sans-serif; font-size:24px; font-weight:700; margin-bottom:10px; }
        .contact-form-card > p { color:var(--text-muted); font-size:15px; margin-bottom:28px; }
        .form-group { margin-bottom:20px; }
        .form-group label { display:block; font-size:14px; font-weight:600; color:var(--text-primary); margin-bottom:8px; }
        .form-group label .required { color:#ef4444; }
        .form-group input, .form-group textarea { width:100%; padding:13px 16px; border:1.5px solid var(--border-color); border-radius:10px; background:var(--bg-body); color:var(--text-primary); font-family:'Inter',sans-serif; font-size:15px; transition:var(--transition); outline:none; }
        .form-group input:focus, .form-group textarea:focus { border-color:var(--primary); box-shadow:0 0 0 3px rgba(37,99,235,0.1); }
        .form-group textarea { resize:vertical; min-height:130px; }
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
        .submit-btn { width:100%; padding:15px 28px; border-radius:10px; background:var(--primary); color:#fff; border:none; font-family:'Inter',sans-serif; font-size:16px; font-weight:600; cursor:pointer; transition:var(--transition); display:inline-flex; align-items:center; justify-content:center; gap:10px; box-shadow:0 4px 14px rgba(37,99,235,0.3); }
        .submit-btn:hover { background:var(--primary-dark); transform:translateY(-2px); box-shadow:0 6px 20px rgba(37,99,235,0.4); }
        .alert { padding:16px 20px; border-radius:10px; margin-bottom:24px; font-size:14.5px; display:flex; align-items:flex-start; gap:10px; }
        .alert-success { background:rgba(16,185,129,0.1); border-left:4px solid #10b981; color:#059669; }
        [data-theme="dark"] .alert-success { color:#34d399; }
        .alert-error { background:rgba(239,68,68,0.1); border-left:4px solid #ef4444; color:#dc2626; }
        [data-theme="dark"] .alert-error { color:#f87171; }
        .map-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:18px; padding:40px; box-shadow:var(--shadow-lg); margin-top:36px; text-align:center; }
        .map-card h3 { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; margin-bottom:10px; }
        .map-card p { color:var(--text-muted); font-size:15px; margin-bottom:20px; }
        .map-placeholder { width:100%; height:280px; border-radius:14px; background:linear-gradient(135deg, #e0e7ff, #dbeafe); display:flex; flex-direction:column; align-items:center; justify-content:center; color:var(--primary); gap:12px; border:1px solid var(--border-color); }
        [data-theme="dark"] .map-placeholder { background:linear-gradient(135deg, #1e3a5f, #1e293b); }
        .map-placeholder i { font-size:48px; opacity:0.6; }
        .map-placeholder span { font-weight:600; font-size:16px; }
        .map-placeholder small { color:var(--text-muted); font-size:13px; }

        /* ===== Professional Footer ===== */
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

        @media (max-width: 1200px) {
            .footer-grid { grid-template-columns: 1.2fr 1fr 1fr; gap: 40px; }
            .footer-col.social-col { grid-column: span 2; }
        }
        @media (max-width: 1024px) {
            .footer-grid { grid-template-columns: 1fr 1fr; gap: 36px; }
            .footer-col.social-col { grid-column: auto; }
        }
        @media (max-width: 900px) {
            .contact-grid { grid-template-columns:1fr; }
            .footer-bottom {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 14px;
            }
            .footer-powered, .footer-copyright { white-space: normal; }
        }
        @media (max-width: 768px) {
            .page-header h1 { font-size:32px; }
            .contact-info-card, .contact-form-card, .map-card { padding:28px 22px; }
            .nav-links a:not(.login-btn) { display:none; }
            .form-row { grid-template-columns:1fr; }
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
                <i class="fas fa-sign-in-alt" style="font-size: 13px;"></i>Login
            </a>
        
        <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode"><i class="fas fa-moon"></i></button>
    </div>
</nav>

<div class="page-header">
    <h1>Contact <span>Us</span></h1>
    <p>Have a question or need help? We'd love to hear from you.</p>
    <div class="breadcrumb">
        <a href="index.php">Home</a> <i class="fas fa-chevron-right" style="font-size:10px;"></i> <span>Contact</span>
    </div>
</div>

<div class="content-wrapper">

    <?php if ($formMessage): ?>
        <div class="alert alert-<?= $formStatus; ?>">
            <i class="fas <?= $formStatus === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>" style="margin-top:2px;"></i>
            <span><?= $formMessage; ?></span>
        </div>
    <?php endif; ?>

    <div class="contact-grid">

        <!-- Contact Info -->
        <div class="contact-info-card">
            <h2>Get in Touch</h2>
            <p>We're here to help. Reach out to us through any of the channels below, or send us a message using the form.</p>

            <div class="contact-detail">
                <div class="contact-icon-box"><i class="fas fa-map-marker-alt"></i></div>
                <div class="contact-detail-text">
                    <h4>Our Address</h4>
                    <p>KN 3 Ave, 30 St<br>Kigali – Rwanda</p>
                </div>
            </div>

            <div class="contact-detail">
                <div class="contact-icon-box"><i class="fas fa-phone-alt"></i></div>
                <div class="contact-detail-text">
                    <h4>Phone</h4>
                    <a href="tel:+250783561459">0783 561 459</a>
                </div>
            </div>

            <div class="contact-detail">
                <div class="contact-icon-box"><i class="fas fa-envelope"></i></div>
                <div class="contact-detail-text">
                    <h4>Email</h4>
                    <a href="mailto:info.dngroup1@gmail.com">info.dngroup1@gmail.com</a>
                </div>
            </div>

            <div class="contact-detail">
                <div class="contact-icon-box"><i class="fas fa-clock"></i></div>
                <div class="contact-detail-text">
                    <h4>Working Hours</h4>
                    <p>Mon – Fri: 8:00 AM – 6:00 PM<br>Sat: 9:00 AM – 2:00 PM</p>
                </div>
            </div>

            <!-- 24/7 Live Notice -->
            <div class="live-notice">
                <span class="live-dot"></span>
                <div class="live-notice-text">
                    <h5><i class="fas fa-circle" style="font-size:8px;"></i> System Live 24/7</h5>
                    <p>Our platform is always online. You can reach us or send a message any time — day or night — and we'll respond as soon as possible.</p>
                </div>
            </div>

            <div class="contact-social">
                <a href="https://www.instagram.com/dngroup.1?utm_source=ig_web_button_share_sheet&stkn=ZDNlZDc0MzIxNw==" target="_blank" rel="noopener noreferrer" class="instagram" title="Instagram"><i class="fab fa-instagram"></i></a>
                <a href="https://www.facebook.com/profile.php?id=61592747278836" target="_blank" rel="noopener noreferrer" class="facebook" title="Facebook"><i class="fab fa-facebook-f"></i></a>
            </div>
        </div>

        <!-- Contact Form -->
        <div class="contact-form-card">
            <h2>Send Us a Message</h2>
            <p>Fill out the form below and we'll get back to you as soon as possible.</p>

            <form method="POST" action="contact.php">
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Full Name <span class="required">*</span></label>
                        <input type="text" id="name" name="name" placeholder="Your full name" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address <span class="required">*</span></label>
                        <input type="email" id="email" name="email" placeholder="you@example.com" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="subject">Subject <span class="required">*</span></label>
                    <input type="text" id="subject" name="subject" placeholder="What is this regarding?" required>
                </div>

                <div class="form-group">
                    <label for="message">Message <span class="required">*</span></label>
                    <textarea id="message" name="message" placeholder="Write your message here..." required></textarea>
                </div>

                <button type="submit" class="submit-btn">
                    <i class="fas fa-paper-plane"></i> Send Message
                </button>
            </form>
        </div>

    </div>

    <!-- Map Placeholder -->
    <div class="map-card">
        <h3><i class="fas fa-map-marked-alt" style="color:var(--primary);margin-right:8px;"></i> Find Us</h3>
        <p>Visit our office at KN 3 Ave, 30 St, Kigali – Rwanda</p>
        <div class="map-placeholder">
            <i class="fas fa-map-marked-alt"></i>
            <span>Kigali – Rwanda</span>
            <small>KN 3 Ave, 30 St</small>
        </div>
    </div>

</div>

<!-- ===== Professional Footer ===== -->
<footer class="footer">
    <div class="footer-container">
        <div class="footer-grid">

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

            <div class="footer-col">
                <h4>Our Partners</h4>
                <ul class="footer-links-list">
                    <li><a href="https://www.nesa.gov.rw/" target="_blank" rel="noopener noreferrer"><i class="fas fa-chevron-right"></i> NESA</a></li>
                    <li><a href="https://www.reb.gov.rw/home" target="_blank" rel="noopener noreferrer"><i class="fas fa-chevron-right"></i> REB</a></li>
                    <li><a href="https://ur.ac.rw/" target="_blank" rel="noopener noreferrer"><i class="fas fa-chevron-right"></i> UR</a></li>
                    <li><a href="http://localhost/dngroup/index.php" target="_blank" rel="noopener noreferrer"><i class="fas fa-chevron-right"></i> DN-Group</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h4>Contact Us</h4>
                <div class="footer-contact">
                    <div class="contact-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <span>KN 3 Ave, 30 St Kigali – Rwanda</span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-phone-alt"></i>
                        <span><a href="tel:+250783561459">+250 783 561 459</a></span>
                    </div>
                    <div class="contact-item">
                        <i class="fas fa-envelope"></i>
                        <span><a href="mailto:info.dngroup1@gmail.com">info.dngroup1@gmail.com</a></span>
                    </div>
                </div>
            </div>

            <div class="footer-col social-col">
                <h4>Follow Us</h4>
                <div class="footer-social">
                    <a href="https://www.instagram.com/dngroup.1?utm_source=ig_web_button_share_sheet&stkn=ZDNlZDc0MzIxNw==" target="_blank" rel="noopener noreferrer" class="social-link instagram" title="Instagram" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="https://www.facebook.com/profile.php?id=61592747278836" target="_blank" rel="noopener noreferrer" class="social-link facebook" title="Facebook" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                </div>
                <p style="margin-top: 18px; font-size: 13px; color: #94a3b8; line-height: 1.6;">
                    Follow D-N Group for updates, news, and more from the Educ Bridge team.
                </p>
            </div>

        </div>

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