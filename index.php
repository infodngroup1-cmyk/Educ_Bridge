<?php
session_start();

// System information
$systemName = "Educ Bridge";
$tagline = "Connecting Parents, Schools, and Education";

// Get current time for greeting
date_default_timezone_set('UTC');
$hour = date('G');
$greeting = "Good " . ($hour < 12 ? "Morning" : ($hour < 17 ? "Afternoon" : "Evening"));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Educ Bridge: Bridging Education with Technology">
    <title><?= htmlspecialchars($systemName); ?> | Unified Education Management Platform</title>
    <link rel="icon" type="image/png" href="logo.png">

    <!-- Google Fonts: Inter & Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* ===== CSS Variables & Theme System ===== */
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #dbeafe;
            --secondary: #7c3aed;
            --accent: #0ea5e9;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;

            --bg-body: #f8fafc;
            --bg-card: #ffffff;
            --bg-nav: rgba(255, 255, 255, 0.92);
            --bg-section: #ffffff;
            --bg-alt: #f1f5f9;
            --bg-footer: #0f172a;

            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --text-inverse: #ffffff;

            --border-color: #e2e8f0;
            --border-hover: #cbd5e1;

            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.07);
            --shadow-lg: 0 20px 45px rgba(0, 0, 0, 0.08);
            --shadow-xl: 0 25px 60px rgba(0, 0, 0, 0.10);
            --shadow-primary: 0 4px 14px rgba(37, 99, 235, 0.3);

            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 18px;
            --radius-xl: 28px;

            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-slow: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);

            --gradient-primary: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
            --gradient-hero: linear-gradient(145deg, #f0f4ff 0%, #ffffff 50%, #eef2ff 100%);
            --gradient-footer: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        }

        [data-theme="dark"] {
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --primary-light: #1e3a5f;
            --secondary: #a78bfa;
            --accent: #38bdf8;
            --success: #34d399;
            --warning: #fbbf24;
            --danger: #f87171;

            --bg-body: #0b1120;
            --bg-card: #1e293b;
            --bg-nav: rgba(15, 23, 42, 0.92);
            --bg-section: #1e293b;
            --bg-alt: #1a2332;
            --bg-footer: #020617;

            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --text-inverse: #0f172a;

            --border-color: #334155;
            --border-hover: #475569;

            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 20px 45px rgba(0, 0, 0, 0.5);
            --shadow-xl: 0 25px 60px rgba(0, 0, 0, 0.6);
            --shadow-primary: 0 4px 14px rgba(59, 130, 246, 0.35);

            --gradient-primary: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            --gradient-hero: linear-gradient(145deg, #0f172a 0%, #1e293b 50%, #0b1120 100%);
            --gradient-footer: linear-gradient(135deg, #020617 0%, #0f172a 100%);
        }

        /* ===== Base Reset ===== */
        *,
        *::before,
        *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            transition: background-color 0.4s ease, color 0.4s ease;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ===== Navigation ===== */
        .navbar {
            width: 100%;
            background: var(--bg-nav);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-color);
            padding: 12px 6%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1000;
            transition: var(--transition);
        }

        .navbar.scrolled {
            box-shadow: var(--shadow-md);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--text-primary);
            flex-shrink: 0;
        }

        .brand img {
            width: 42px;
            height: 42px;
            object-fit: contain;
            border-radius: 50%;
            border: 2px solid var(--border-color);
            padding: 2px;
            background: var(--bg-card);
            transition: var(--transition);
        }

        .brand:hover img {
            border-color: var(--primary);
            transform: rotate(-5deg) scale(1.05);
        }

        .brand-name {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .brand-name span {
            color: var(--primary);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text-secondary);
            padding: 9px 16px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 500;
            transition: var(--transition);
        }

        .nav-links a:hover {
            background: var(--bg-alt);
            color: var(--primary);
        }

        .login-btn {
            background: var(--primary) !important;
            color: #ffffff !important;
            font-weight: 600 !important;
            box-shadow: var(--shadow-primary);
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .login-btn:hover {
            background: var(--primary-dark) !important;
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
            transform: translateY(-1px);
        }

        /* Nav Date & Time */
        .nav-datetime {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 50px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            margin-left: 8px;
            transition: var(--transition);
            white-space: nowrap;
        }

        .nav-datetime:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow-sm);
        }

        .nav-datetime .dt-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-datetime .dt-item i {
            color: var(--primary);
            font-size: 13px;
        }

        .nav-datetime .dt-divider {
            width: 1px;
            height: 16px;
            background: var(--border-color);
        }

        .nav-datetime .live-time {
            font-family: 'Poppins', sans-serif;
            font-weight: 600;
            color: var(--text-primary);
            letter-spacing: 0.3px;
            font-variant-numeric: tabular-nums;
        }

        /* Theme Toggle */
        .theme-toggle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 15px;
            transition: var(--transition);
            margin-left: 6px;
            flex-shrink: 0;
        }

        .theme-toggle:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: rotate(20deg);
        }

        /* ===== Hero Section ===== */
        .hero {
            min-height: 680px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 80px 6%;
            background: var(--gradient-hero);
            position: relative;
            overflow: hidden;
            transition: background 0.4s ease;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.08) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(124, 58, 237, 0.06) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }

        .hero-container {
            width: 100%;
            max-width: 1200px;
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            align-items: center;
            gap: 60px;
            position: relative;
            z-index: 1;
        }

        .greeting {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            color: var(--primary);
            font-weight: 600;
            background: var(--primary-light);
            padding: 6px 16px;
            border-radius: 50px;
            margin-bottom: 20px;
            transition: var(--transition);
        }

        [data-theme="dark"] .greeting {
            background: rgba(59, 130, 246, 0.15);
        }

        .hero-text h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 58px;
            line-height: 1.08;
            color: var(--text-primary);
            margin-bottom: 24px;
            font-weight: 800;
            letter-spacing: -1.5px;
        }

        .hero-text h1 span {
            color: var(--primary);
            position: relative;
        }

        .hero-text h1 span::after {
            content: '';
            position: absolute;
            bottom: 4px;
            left: 0;
            width: 100%;
            height: 8px;
            background: rgba(37, 99, 235, 0.12);
            border-radius: 4px;
            z-index: -1;
        }

        [data-theme="dark"] .hero-text h1 span::after {
            background: rgba(59, 130, 246, 0.2);
        }

        .hero-text p {
            font-size: 18px;
            line-height: 1.75;
            color: var(--text-muted);
            max-width: 600px;
            margin-bottom: 36px;
            font-weight: 400;
        }

        .hero-buttons {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 15px 28px;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 600;
            font-size: 16px;
            transition: var(--transition);
            cursor: pointer;
            border: none;
            font-family: 'Inter', sans-serif;
        }

        .primary-btn {
            background: var(--primary);
            color: #ffffff;
            box-shadow: var(--shadow-primary);
        }

        .primary-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }

        .secondary-btn {
            background: var(--bg-card);
            color: var(--primary);
            border: 1.5px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }

        .secondary-btn:hover {
            background: var(--bg-alt);
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Hero Logo */
        .hero-logo {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-card {
            width: 380px;
            height: 380px;
            background: var(--bg-card);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 55px;
            border: 1px solid var(--border-color);
            transition: var(--transition-slow);
            animation: float 5s ease-in-out infinite;
        }

        .logo-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 0 30px 70px rgba(0, 0, 0, 0.12);
        }

        .logo-card img {
            width: 100%;
            max-width: 260px;
            height: auto;
            object-fit: contain;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-12px); }
        }

        /* ===== Stats Section ===== */
        .stats-container {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin-top: 40px;
            flex-wrap: wrap;
        }

        .stat-card {
            background: var(--bg-card);
            padding: 20px 32px;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            text-align: center;
            min-width: 140px;
            border: 1px solid var(--border-color);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--primary);
        }

        .stat-number {
            font-family: 'Poppins', sans-serif;
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ===== Role Section ===== */
        .role-section {
            padding: 100px 6%;
            background: var(--bg-section);
            transition: background 0.4s ease;
        }

        .section-title {
            text-align: center;
            margin-bottom: 55px;
        }

        .section-title h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 38px;
            color: var(--text-primary);
            margin-bottom: 14px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .section-title p {
            color: var(--text-muted);
            font-size: 17px;
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.7;
        }

        .role-grid {
            max-width: 1200px;
            margin: auto;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 28px;
        }

        .role-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 40px 32px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            text-decoration: none;
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .role-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .admin-card::before { background: linear-gradient(90deg, #2563eb, #3b82f6); }
        .teacher-card::before { background: linear-gradient(90deg, #10b981, #34d399); }
        .parent-card::before { background: linear-gradient(90deg, #0ea5e9, #38bdf8); }

        .role-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
            border-color: var(--border-hover);
        }

        .role-card:hover::before {
            opacity: 1;
        }

        .card-icon-container {
            position: relative;
            margin-bottom: 24px;
        }

        .card-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #ffffff;
            position: relative;
            z-index: 2;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
            transition: var(--transition);
        }

        .admin-card .card-icon { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .teacher-card .card-icon { background: linear-gradient(135deg, #10b981, #34d399); }
        .parent-card .card-icon { background: linear-gradient(135deg, #0ea5e9, #38bdf8); }

        .role-card:hover .card-icon {
            transform: scale(1.1) rotate(-5deg);
        }

        .card-icon::after {
            content: '';
            position: absolute;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: inherit;
            opacity: 0.15;
            z-index: 1;
        }

        .role-card h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 14px;
            color: var(--text-primary);
        }

        .role-card p {
            font-size: 15px;
            line-height: 1.7;
            color: var(--text-muted);
            margin-bottom: 24px;
            flex-grow: 1;
        }

        .card-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary);
            color: #ffffff;
            padding: 12px 24px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 15px;
            transition: var(--transition);
            margin-top: auto;
        }

        .role-card:hover .card-button {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(37, 99, 235, 0.3);
        }

        /* ===== Features Section ===== */
        .features-section {
            padding: 100px 6%;
            background: var(--bg-alt);
            transition: background 0.4s ease;
        }

        .features-grid {
            max-width: 1200px;
            margin: auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
        }

        .feature-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 32px 24px;
            text-align: center;
            transition: var(--transition);
        }

        .feature-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        .feature-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-md);
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin: 0 auto 18px;
            transition: var(--transition);
        }

        [data-theme="dark"] .feature-icon {
            background: rgba(59, 130, 246, 0.15);
        }

        .feature-item:hover .feature-icon {
            background: var(--primary);
            color: #ffffff;
            transform: scale(1.1);
        }

        .feature-item h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--text-primary);
        }

        .feature-item p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        /* ===== About Section ===== */
        .about-section {
            padding: 100px 6%;
            background: var(--bg-section);
            text-align: center;
            transition: background 0.4s ease;
        }

        .about-content {
            max-width: 800px;
            margin: 0 auto;
        }

        .about-content .section-title {
            margin-bottom: 30px;
        }

        .about-content p {
            color: var(--text-muted);
            font-size: 17px;
            line-height: 1.8;
            margin-bottom: 16px;
        }

        /* ===== Professional Footer ===== */
        .footer {
            background: var(--gradient-footer);
            color: #ffffff;
            padding: 70px 6% 30px;
            margin-top: auto;
            transition: background 0.4s ease;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
        }

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

        .footer-links-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

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

        .footer-links-list li a i {
            font-size: 11px;
            color: #3b82f6;
            transition: var(--transition);
        }

        .footer-links-list li a:hover {
            color: #ffffff;
            transform: translateX(5px);
        }

        .footer-links-list li a:hover i {
            color: #60a5fa;
        }

        /* Contact Info */
        .footer-contact {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

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

        .contact-item a {
            color: #cbd5e1;
            text-decoration: none;
            transition: var(--transition);
        }

        .contact-item a:hover {
            color: #60a5fa;
        }

        /* Social Media */
        .footer-social {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

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

        .social-link.facebook:hover {
            background: #1877f2;
            border-color: #1877f2;
        }

        /* ===== Footer Bottom — All on One Line ===== */
        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            padding-top: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
        }

        .footer-powered {
            font-size: 13.5px;
            color: #94a3b8;
            white-space: nowrap;
        }

        .footer-powered strong {
            color: #60a5fa;
            font-weight: 600;
        }

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

        .footer-bottom-links a:hover {
            color: #ffffff;
        }

        .footer-copyright {
            font-size: 13.5px;
            color: #94a3b8;
            white-space: nowrap;
        }

        /* ===== Animations ===== */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInRight {
            from { opacity: 0; transform: translateX(30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .animate-fade-up {
            animation: fadeInUp 0.7s ease forwards;
            opacity: 0;
        }

        .animate-fade-right {
            animation: fadeInRight 0.7s ease forwards;
            opacity: 0;
        }

        .reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.7s ease, transform 0.7s ease;
        }

        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }

        /* ===== Responsive ===== */
        @media (max-width: 1200px) {
            .nav-datetime .dt-item .dt-date-text { display: none; }
            .nav-datetime .dt-divider { display: none; }
            .footer-grid { grid-template-columns: 1.2fr 1fr 1fr; gap: 40px; }
            .footer-col.social-col { grid-column: span 2; }
        }

        @media (max-width: 1024px) {
            .hero-text h1 { font-size: 46px; }
            .role-grid { grid-template-columns: repeat(2, 1fr); }
            .features-grid { grid-template-columns: repeat(2, 1fr); }
            .nav-datetime { font-size: 12px; padding: 6px 12px; gap: 8px; }
            .footer-grid { grid-template-columns: 1fr 1fr; gap: 36px; }
            .footer-col.social-col { grid-column: auto; }
        }

        @media (max-width: 900px) {
            .hero-container {
                grid-template-columns: 1fr;
                text-align: center;
                gap: 40px;
            }
            .hero-text p { margin-left: auto; margin-right: auto; }
            .hero-buttons { justify-content: center; }
            .greeting { margin-left: auto; margin-right: auto; }
            .hero { min-height: auto; padding: 60px 6%; }
            .hero-text h1 { font-size: 42px; }
            .logo-card { width: 300px; height: 300px; padding: 45px; }
            .nav-links a:not(.login-btn):not(.nav-datetime a) { display: none; }
            .section-title h2 { font-size: 30px; }
            .footer-bottom {
                flex-direction: column;
                align-items: center;
                text-align: center;
                gap: 14px;
            }
            .footer-powered,
            .footer-copyright {
                white-space: normal;
            }
        }

        @media (max-width: 768px) {
            .role-grid { grid-template-columns: 1fr; }
            .features-grid { grid-template-columns: 1fr; }
            .stats-container { gap: 16px; }
            .stat-card { min-width: 120px; padding: 16px 24px; }
            .stat-number { font-size: 1.8rem; }
            .nav-datetime { display: none; }
            .footer-grid { grid-template-columns: 1fr; gap: 32px; }
            .footer { padding: 50px 6% 25px; }
        }

        @media (max-width: 480px) {
            .hero-text h1 { font-size: 34px; letter-spacing: -1px; }
            .hero-text p { font-size: 16px; }
            .logo-card { width: 250px; height: 250px; padding: 35px; }
            .btn { padding: 13px 22px; font-size: 15px; }
            .role-card { padding: 32px 24px; }
            .card-icon { width: 70px; height: 70px; font-size: 1.7rem; }
        }
    </style>
</head>

<body>

    <!-- Navigation -->
    <nav class="navbar" id="navbar">
        <a href="index.php" class="brand">
            <img src="logo.png" alt="Educ Bridge Logo">
            <span class="brand-name">Educ<span>Bridge</span></span>
        </a>

        <div class="nav-links">
            <!-- <a href="admin-login.php"> System Admin</a> -->
            <a href="#roles">Roles</a>
            <a href="#features">Features</a>
            <a href="online-registration.php">Online Services</a>
            <!-- <a href="about.php">About</a> -->

            <!-- Date & Time in Nav -->
            <div class="nav-datetime">
                <div class="dt-item">
                    <i class="far fa-calendar-alt"></i>
                    <span class="dt-date-text" id="navDate"><?= date('jS M Y'); ?></span>
                </div>
                <div class="dt-divider"></div>
                <div class="dt-item">
                    <i class="far fa-clock"></i>
                    <span class="live-time" id="navTime"><?= date('H:i:s'); ?></span>
                </div>
            </div>

            <a href="#roles" class="login-btn">
                <i class="fas fa-sign-in-alt" style="font-size: 13px;"></i>Login
            </a>
            <button class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode" title="Toggle theme">
                <i class="fas fa-moon"></i>
            </button>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-container">
            <div class="hero-text">
                <div class="greeting animate-fade-up">
                    <i class="fas fa-smile"></i> <?= $greeting; ?>! Welcome to Educ Bridge
                </div>
                <h1 class="animate-fade-up" style="animation-delay: 0.1s;">
                    Building a Bridge Between
                    <span>School & Family</span>
                </h1>
                <p class="animate-fade-up" style="animation-delay: 0.2s;">
                    Educ Bridge is a unified education management system designed to connect
                    parents, schools, administrators, and teachers through one simple platform.
                </p>
                <div class="hero-buttons animate-fade-up" style="animation-delay: 0.3s;">
                    <a href="#roles" class="btn primary-btn">
                        <i class="fas fa-rocket"></i> Get Started
                    </a>
                    <a href="#features" class="btn secondary-btn">
                        <i class="fas fa-arrow-down"></i> Explore Features
                    </a>
                </div>

                <div class="stats-container animate-fade-up" style="animation-delay: 0.4s;">
                    <div class="stat-card">
                        <div class="stat-number">3+</div>
                        <div class="stat-label">User Roles</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">24/7</div>
                        <div class="stat-label">Access</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number">100%</div>
                        <div class="stat-label">Secure</div>
                    </div>
                </div>
            </div>

            <div class="hero-logo animate-fade-right" style="animation-delay: 0.2s;">
                <div class="logo-card">
                    <img src="logo.png" alt="Educ Bridge">
                </div>
            </div>
        </div>
    </section>

    <!-- Role Selection -->
    <section class="role-section" id="roles">
        <div class="section-title reveal">
            <h2>Select Your Role</h2>
            <p>Choose how you want to access the Educ Bridge platform.</p>
        </div>

        <div class="role-grid">
            <a href="school-login.php" class="role-card admin-card reveal">
                <div class="card-icon-container">
                    <div class="card-icon"><i class="fas fa-user-shield"></i></div>
                </div>
                <h3> School Administrator</h3>
                <p>Full system control including user management, course configuration, system monitoring, and comprehensive reporting capabilities.</p>
                <span class="card-button">Enter <i class="fas fa-arrow-right"></i></span>
            </a>

            <a href="teacher-login.php" class="role-card teacher-card reveal" style="transition-delay: 0.1s;">
                <div class="card-icon-container">
                    <div class="card-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                </div>
                <h3>Teacher</h3>
                <p>Manage lessons, record student performance, track attendance, and communicate with parents through an intuitive dashboard.</p>
                <span class="card-button">Enter <i class="fas fa-arrow-right"></i></span>
            </a>

            <a href="parent-login.php" class="role-card parent-card reveal" style="transition-delay: 0.2s;">
                <div class="card-icon-container">
                    <div class="card-icon"><i class="fas fa-user-friends"></i></div>
                </div>
                <h3>Parent</h3>
                <p>Monitor your child's academic progress, receive real-time updates, communicate with teachers, and access school announcements.</p>
                <span class="card-button">Enter <i class="fas fa-arrow-right"></i></span>
            </a>
        </div>
    </section>

    <!-- Features -->
    <section class="features-section" id="features">
        <div class="section-title reveal">
            <h2>System Features</h2>
            <p>Everything you need for a seamless educational experience.</p>
        </div>

        <div class="features-grid">
            <div class="feature-item reveal">
                <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                <h4>Secure Access</h4>
                <p>Role-based authentication and data encryption for maximum security.</p>
            </div>
            <div class="feature-item reveal" style="transition-delay: 0.1s;">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <h4>Real-time Analytics</h4>
                <p>Comprehensive dashboards with actionable insights for all users.</p>
            </div>
            <div class="feature-item reveal" style="transition-delay: 0.2s;">
                <div class="feature-icon"><i class="fas fa-mobile-alt"></i></div>
                <h4>Mobile Responsive</h4>
                <p>Access from any device with a seamless mobile experience.</p>
            </div>
            <div class="feature-item reveal" style="transition-delay: 0.3s;">
                <div class="feature-icon"><i class="fas fa-comments"></i></div>
                <h4>Communication Tools</h4>
                <p>Built-in messaging and announcement system for effective communication.</p>
            </div>
        </div>
    </section>

    <!-- About -->
    <section class="about-section" id="about">
        <div class="about-content">
            <div class="section-title reveal">
                <h2>About Educ Bridge</h2>
                <p>A digital bridge connecting the school community.</p>
            </div>
            <p class="reveal">
                Educ Bridge was created with a simple mission: to make school communication
                easier, faster, and more effective for everyone involved. By bringing parents,
                teachers, and school administrators onto one platform, we help build stronger
                partnerships that support student success.
            </p>
        </div>
    </section>

    <!-- ===== Professional Footer ===== -->
    <footer class="footer">
        <div class="footer-container">
            <div class="footer-grid">

                <!-- Column 1: Quick Links (Internal) -->
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

                <!-- Column 2: Our Partners (NESA & REB) -->
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

                <!-- Column 4: Follow Us (Social Media) -->
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

            <!-- ===== Footer Bottom — One Line ===== -->
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
        // ===== Live Date & Time in Nav =====
        (function () {
            const dateEl = document.getElementById('navDate');
            const timeEl = document.getElementById('navTime');

            function getOrdinal(n) {
                const s = ['th', 'st', 'nd', 'rd'];
                const v = n % 100;
                return n + (s[(v - 20) % 10] || s[v] || s[0]);
            }

            function updateDateTime() {
                const now = new Date();
                const day = getOrdinal(now.getDate());
                const month = now.toLocaleString('en-US', { month: 'short' });
                const year = now.getFullYear();
                dateEl.textContent = `${day} ${month} ${year}`;

                const hours = String(now.getHours()).padStart(2, '0');
                const minutes = String(now.getMinutes()).padStart(2, '0');
                const seconds = String(now.getSeconds()).padStart(2, '0');
                timeEl.textContent = `${hours}:${minutes}:${seconds}`;
            }

            updateDateTime();
            setInterval(updateDateTime, 1000);
        })();

        // ===== Theme Toggle =====
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
                if (theme === 'dark') {
                    icon.classList.remove('fa-moon');
                    icon.classList.add('fa-sun');
                } else {
                    icon.classList.remove('fa-sun');
                    icon.classList.add('fa-moon');
                }
            }
        })();

        // ===== Navbar Shadow on Scroll =====
        window.addEventListener('scroll', function () {
            const navbar = document.getElementById('navbar');
            if (window.scrollY > 10) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // ===== Scroll Reveal =====
        (function () {
            const reveals = document.querySelectorAll('.reveal');
            const observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('active');
                        observer.unobserve(entry.target);
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            });
            reveals.forEach(function (el) { observer.observe(el); });
        })();

        // ===== Smooth Scroll =====
        document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
            anchor.addEventListener('click', function (e) {
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    const offset = 80;
                    const top = target.getBoundingClientRect().top + window.pageYOffset - offset;
                    window.scrollTo({ top: top, behavior: 'smooth' });
                }
            });
        });
    </script>

</body>
</html>