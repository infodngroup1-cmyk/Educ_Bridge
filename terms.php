<?php
session_start();
$systemName = "Educ Bridge";
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms &amp; Conditions | <?= htmlspecialchars($systemName); ?></title>
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
        .content-wrapper { max-width:900px; margin:0 auto; padding:70px 6%; flex:1; }
        .content-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:18px; padding:50px; box-shadow:var(--shadow-lg); }
        .last-updated { display:inline-block; background:var(--primary-light); color:var(--primary); padding:6px 16px; border-radius:50px; font-size:13px; font-weight:600; margin-bottom:24px; }
        [data-theme="dark"] .last-updated { background:rgba(59,130,246,0.15); }
        .content-card h2 { font-family:'Poppins',sans-serif; font-size:24px; font-weight:700; color:var(--text-primary); margin:32px 0 14px; letter-spacing:-0.4px; }
        .content-card h2:first-of-type { margin-top:0; }
        .content-card h2 i { color:var(--primary); margin-right:10px; font-size:20px; }
        .content-card p { color:var(--text-secondary); font-size:15.5px; line-height:1.8; margin-bottom:16px; }
        .content-card ul { list-style:none; margin:14px 0 18px; padding-left:0; }
        .content-card ul li { color:var(--text-secondary); font-size:15.5px; line-height:1.8; padding-left:28px; position:relative; margin-bottom:10px; }
        .content-card ul li::before { content:'\f00c'; font-family:'Font Awesome 6 Free'; font-weight:900; position:absolute; left:0; top:0; color:var(--primary); font-size:14px; }
        .warning-box { background:rgba(239,68,68,0.08); border-left:4px solid #ef4444; border-radius:10px; padding:20px 24px; margin:24px 0; }
        .warning-box p { margin:0; color:var(--text-secondary); font-size:15px; }
        .info-box { background:var(--bg-alt); border-left:4px solid var(--primary); border-radius:10px; padding:20px 24px; margin:24px 0; }
        .info-box p { margin:0; color:var(--text-secondary); font-size:15px; }

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

        @media (max-width: 1200px) {
            .footer-grid { grid-template-columns: 1.2fr 1fr 1fr; gap: 40px; }
            .footer-col.social-col { grid-column: span 2; }
        }
        @media (max-width: 1024px) {
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
            .page-header h1 { font-size:32px; }
            .content-card { padding:32px 24px; }
            .nav-links a:not(.login-btn) { display:none; }
            .content-card h2 { font-size:20px; }
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
    <h1>Terms &amp; <span>Conditions</span></h1>
    <p>Please read these terms carefully before using the Educ Bridge platform.</p>
    <div class="breadcrumb">
        <a href="index.php">Home</a> <i class="fas fa-chevron-right" style="font-size:10px;"></i> <span>Terms &amp; Conditions</span>
    </div>
</div>

<div class="content-wrapper">
    <div class="content-card">

        <span class="last-updated"><i class="far fa-calendar-alt"></i> Last Updated: <?= date('F j, Y'); ?></span>

        <h2><i class="fas fa-file-contract"></i> 1. Acceptance of Terms</h2>
        <p>
            By accessing or using Educ Bridge ("the Platform"), you agree to be bound by these
            Terms and Conditions. If you do not agree with any part of these terms, you must
            not use our services.
        </p>
        <p>
            These terms apply to all users, including parents, teachers, administrators, and any
            other individuals who access the Platform.
        </p>

        <h2><i class="fas fa-user-check"></i> 2. User Accounts</h2>
        <p>To use certain features of the Platform, you must register for an account. You agree to:</p>
        <ul>
            <li>Provide accurate, current, and complete information during registration</li>
            <li>Maintain the security of your password and account credentials</li>
            <li>Notify us immediately of any unauthorized use of your account</li>
            <li>Accept responsibility for all activities that occur under your account</li>
            <li>Not share your account with anyone else</li>
        </ul>

        <h2><i class="fas fa-gavel"></i> 3. Acceptable Use</h2>
        <p>You agree <strong>NOT</strong> to use the Platform to:</p>
        <ul>
            <li>Violate any applicable laws or regulations</li>
            <li>Infringe on the rights of others</li>
            <li>Transmit harmful, offensive, or inappropriate content</li>
            <li>Attempt to gain unauthorized access to other accounts or systems</li>
            <li>Interfere with or disrupt the Platform's operation</li>
            <li>Impersonate another person or entity</li>
            <li>Collect or store personal data of other users without consent</li>
        </ul>

        <div class="warning-box">
            <p><i class="fas fa-exclamation-triangle" style="color:#ef4444;margin-right:8px;"></i>
            <strong>Warning:</strong> Violation of these rules may result in immediate account suspension
            or termination without prior notice.</p>
        </div>

        <h2><i class="fas fa-user-shield"></i> 4. Roles and Responsibilities</h2>
        <p>Different users have different responsibilities on the Platform:</p>
        <ul>
            <li><strong>Administrators:</strong> Manage users, courses, and system settings responsibly.</li>
            <li><strong>Teachers:</strong> Provide accurate academic records and communicate professionally.</li>
            <li><strong>Parents:</strong> Monitor their children's progress and use the platform for legitimate educational purposes.</li>
        </ul>

        <h2><i class="fas fa-copyright"></i> 5. Intellectual Property</h2>
        <p>
            All content on Educ Bridge — including logos, text, graphics, software, and design — is
            the property of D-N Group and is protected by copyright and intellectual property laws.
            You may not reproduce, distribute, or create derivative works without our written permission.
        </p>

        <h2><i class="fas fa-ban"></i> 6. Termination</h2>
        <p>
            We reserve the right to suspend or terminate your account at any time, with or without
            notice, if you violate these Terms. Upon termination, your right to use the Platform
            will immediately cease.
        </p>

        <h2><i class="fas fa-exclamation-circle"></i> 7. Disclaimer of Warranties</h2>
        <p>
            Educ Bridge is provided "as is" and "as available" without any warranties, express or
            implied. We do not guarantee that the Platform will be error-free, uninterrupted, or
            completely secure.
        </p>

        <div class="info-box">
            <p><i class="fas fa-info-circle" style="color:var(--primary);margin-right:8px;"></i>
            While we work hard to keep the platform online 24/7, occasional maintenance may occur
            to ensure the best possible experience for all users.</p>
        </div>

        <h2><i class="fas fa-balance-scale"></i> 8. Limitation of Liability</h2>
        <p>
            To the maximum extent permitted by law, D-N Group shall not be liable for any indirect,
            incidental, special, or consequential damages arising from your use of the Platform.
        </p>

        <h2><i class="fas fa-sync-alt"></i> 9. Changes to Terms</h2>
        <p>
            We may update these Terms and Conditions from time to time. Continued use of the Platform
            after changes are posted constitutes your acceptance of the updated terms.
        </p>

        <h2><i class="fas fa-map-marker-alt"></i> 10. Governing Law</h2>
        <p>
            These Terms shall be governed by and construed in accordance with the laws of the Republic
            of Rwanda. Any disputes will be resolved in the appropriate courts of Rwanda.
        </p>

        <h2><i class="fas fa-envelope"></i> 11. Contact Information</h2>
        <p>For questions about these Terms, please contact us:</p>
        <ul>
            <li><strong>Email:</strong> info.dngroup1@gmail.com</li>
            <li><strong>Phone:</strong> +250 783 561 459</li>
            <li><strong>Address:</strong> KN 3 Ave, 30 St Kigali – Rwanda</li>
        </ul>

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