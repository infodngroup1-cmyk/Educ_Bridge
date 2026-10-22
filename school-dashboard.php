<?php
session_start();
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| AUTH CHECK
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['school_id']) || empty($_SESSION['school_verified'])) {
    header("Location: school-login.php");
    exit;
}

$school_id    = (int) $_SESSION['school_id'];
$school_name  = $_SESSION['school_name']  ?? 'School';
$school_admin = $_SESSION['school_admin'] ?? 'Administrator';
$school_email = $_SESSION['school_email'] ?? '';
$school_code  = $_SESSION['school_code']  ?? '';

// Initials
$initials = "";
foreach (explode(" ", trim($school_admin)) as $part) {
    if ($part !== "") {
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) break;
    }
}
if ($initials === "") $initials = "S";

$short_school = mb_strimwidth($school_name, 0, 24, '…');

/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/
if (isset($_GET['logout'])) {
    unset(
        $_SESSION['school_id'],
        $_SESSION['school_name'],
        $_SESSION['school_admin'],
        $_SESSION['school_email'],
        $_SESSION['school_code'],
        $_SESSION['school_verified']
    );
    header("Location: school-login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| STATS
|--------------------------------------------------------------------------
*/
function count_for_school($conn, $sql, $school_id) {
    $stmt = @mysqli_prepare($conn, $sql);
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, "i", $school_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    return $row ? (int) $row['total'] : 0;
}

$students_count  = count_for_school($conn, "SELECT COUNT(*) AS total FROM students WHERE school_id = ?", $school_id);
$teachers_count  = count_for_school($conn, "SELECT COUNT(*) AS total FROM teachers WHERE school_id = ?", $school_id);
$classes_count   = count_for_school($conn, "SELECT COUNT(*) AS total FROM classes  WHERE school_id = ?", $school_id);
$subjects_count  = count_for_school($conn, "SELECT COUNT(*) AS total FROM subjects WHERE school_id = ?", $school_id);
$events_count    = count_for_school($conn, "SELECT COUNT(*) AS total FROM events   WHERE school_id = ?", $school_id);
$announce_count  = count_for_school($conn, "SELECT COUNT(*) AS total FROM announcements WHERE school_id = ?", $school_id);

/*
|--------------------------------------------------------------------------
| RECENT ANNOUNCEMENTS
|--------------------------------------------------------------------------
*/
$recent_announcements = null;
$stmt = @mysqli_prepare($conn, "
    SELECT id, title, body, created_at
    FROM announcements
    WHERE school_id = ?
    ORDER BY created_at DESC
    LIMIT 4
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $school_id);
    mysqli_stmt_execute($stmt);
    $recent_announcements = mysqli_stmt_get_result($stmt);
}

/*
|--------------------------------------------------------------------------
| UPCOMING EVENTS
|--------------------------------------------------------------------------
*/
$upcoming_events = null;
$stmt = @mysqli_prepare($conn, "
    SELECT id, title, description, event_date, location
    FROM events
    WHERE school_id = ? AND event_date >= CURDATE()
    ORDER BY event_date ASC
    LIMIT 4
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $school_id);
    mysqli_stmt_execute($stmt);
    $upcoming_events = mysqli_stmt_get_result($stmt);
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Dashboard | Educ Bridge</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* =========================================================
           LIGHT THEME — SIDEBAR IS LIGHT TOO
           ========================================================= */
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --success: #10b981;
            --success-light: rgba(16, 185, 129, 0.1);
            --warning: #f59e0b;
            --warning-light: rgba(245, 158, 11, 0.12);
            --danger: #ef4444;
            --danger-light: rgba(239, 68, 68, 0.1);

            /* Sidebar (LIGHT) */
            --sidebar-bg: #ffffff;
            --sidebar-hover: rgba(37, 99, 235, 0.06);
            --sidebar-active: rgba(37, 99, 235, 0.10);
            --sidebar-text: #475569;
            --sidebar-text-active: #0f172a;
            --sidebar-border: #e2e8f0;
            --sidebar-title: #94a3b8;
            --sidebar-avatar-from: #2563eb;
            --sidebar-avatar-to: #7c3aed;
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

        /* =========================================================
           DARK THEME — SIDEBAR IS DARK
           ========================================================= */
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --success: #34d399;
            --success-light: rgba(16, 185, 129, 0.15);
            --warning: #fbbf24;
            --warning-light: rgba(245, 158, 11, 0.18);
            --danger: #f87171;
            --danger-light: rgba(239, 68, 68, 0.15);

            /* Sidebar (DARK) */
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(59, 130, 246, 0.18);
            --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.08);
            --sidebar-title: #94a3b8;
            --sidebar-avatar-from: #3b82f6;
            --sidebar-avatar-to: #8b5cf6;
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
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            transition: background 0.4s ease, color 0.4s ease;
            overflow-x: hidden;
        }
        .layout { display: flex; min-height: 100vh; }

        /* ===== SIDEBAR ===== */
        .sidebar {
            width: 260px;
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0;
            height: 100vh;
            z-index: 100;
            transition: background 0.4s ease, color 0.4s ease, transform 0.3s ease, border-color 0.4s ease;
            border-right: 1px solid var(--sidebar-border);
            box-shadow: var(--shadow-sidebar);
        }
        .sidebar-header {
            padding: 22px 22px 18px;
            border-bottom: 1px solid var(--sidebar-border);
            display: flex;
            align-items: center;
            gap: 12px;
            transition: border-color 0.4s ease;
        }
        .sidebar-header img {
            width: 42px; height: 42px;
            object-fit: contain;
            border-radius: 50%;
            border: 2px solid var(--sidebar-border);
            padding: 2px;
            background: #fff;
            transition: border-color 0.4s ease;
        }
        .sidebar-brand {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            transition: color 0.4s ease;
        }
        .sidebar-brand span { color: var(--primary); }
        .sidebar-brand small {
            display: block;
            font-size: 11px;
            font-weight: 500;
            color: var(--text-muted);
            letter-spacing: 0.4px;
            margin-top: 2px;
            text-transform: uppercase;
            transition: color 0.4s ease;
        }
        .sidebar-nav { flex: 1; padding: 18px 12px; overflow-y: auto; }
        .nav-section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--sidebar-title);
            padding: 12px 14px 8px;
            transition: color 0.4s ease;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            color: var(--sidebar-text);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-radius: 10px;
            margin-bottom: 4px;
            transition: var(--transition);
            position: relative;
        }
        .nav-item i.nav-icon {
            width: 20px;
            text-align: center;
            font-size: 15px;
            color: var(--sidebar-text);
            transition: var(--transition);
        }
        .nav-item:hover {
            background: var(--sidebar-hover);
            color: var(--sidebar-text-active);
        }
        .nav-item:hover i.nav-icon { color: var(--primary); }
        .nav-item.active {
            background: var(--sidebar-active);
            color: var(--sidebar-text-active);
        }
        .nav-item.active i.nav-icon { color: var(--primary); }
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0; top: 8px; bottom: 8px;
            width: 3px;
            background: var(--primary);
            border-radius: 0 4px 4px 0;
        }
        .nav-item .nav-badge {
            margin-left: auto;
            background: var(--sidebar-badge-bg);
            color: var(--sidebar-badge-fg);
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 50px;
            line-height: 1.5;
            transition: background 0.4s ease, color 0.4s ease;
        }
        .sidebar-profile {
            border-top: 1px solid var(--sidebar-border);
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: border-color 0.4s ease;
        }
        .sidebar-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--sidebar-avatar-from), var(--sidebar-avatar-to));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 700;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
            transition: background 0.4s ease;
        }
        .sidebar-profile-info { flex: 1; min-width: 0; }
        .sidebar-profile-info .p-name {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: color 0.4s ease;
        }
        .sidebar-profile-info .p-role {
            font-size: 11.5px;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: color 0.4s ease;
        }
        .sidebar-logout {
            width: 34px; height: 34px;
            border-radius: 8px;
            background: var(--danger-light);
            color: var(--danger);
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 13px;
            flex-shrink: 0;
            transition: var(--transition);
        }
        .sidebar-logout:hover { background: var(--danger); color: #fff; }

        /* ===== MAIN ===== */
        .main {
            flex: 1;
            margin-left: 260px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: margin-left 0.3s ease;
        }
        .topbar {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
            padding: 14px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            position: sticky;
            top: 0;
            z-index: 50;
            transition: background 0.4s ease, border-color 0.4s ease;
        }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .hamburger {
            display: none;
            width: 40px; height: 40px;
            border-radius: 10px;
            border: 1.5px solid var(--border-color);
            background: var(--bg-body);
            color: var(--text-secondary);
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 15px;
            transition: var(--transition);
        }
        .hamburger:hover { border-color: var(--primary); color: var(--primary); }
        .page-title {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.4px;
            transition: color 0.4s ease;
        }
        .page-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 2px;
            transition: color 0.4s ease;
        }
        .topbar-actions { display: flex; align-items: center; gap: 10px; }
        .topbar-btn {
            width: 42px; height: 42px;
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
            position: relative;
            text-decoration: none;
        }
        .topbar-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-1px);
        }
        .topbar-btn.theme-btn:hover { transform: rotate(20deg); }
        .topbar-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
            cursor: pointer;
        }

        /* ===== CONTENT ===== */
        .content { padding: 28px; flex: 1; }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            border-radius: 18px;
            padding: 32px 36px;
            color: #fff;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 36px rgba(37, 99, 235, 0.25);
            transition: background 0.4s ease;
        }
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%; right: -10%;
            width: 320px; height: 320px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
            border-radius: 50%;
        }
        .welcome-banner::after {
            content: '';
            position: absolute;
            bottom: -60%; left: 20%;
            width: 260px; height: 260px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            border-radius: 50%;
        }
        .welcome-banner h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
            letter-spacing: -0.5px;
        }
        .welcome-banner p {
            font-size: 15px;
            opacity: 0.92;
            position: relative;
            z-index: 1;
            max-width: 640px;
        }
        .welcome-code {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.16);
            border: 1px solid rgba(255,255,255,0.28);
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 12.5px;
            font-weight: 700;
            font-family: 'Courier New', monospace;
            letter-spacing: 0.6px;
            margin-top: 14px;
            position: relative;
            z-index: 1;
        }

        /* STATS */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 20px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 22px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: var(--transition);
            text-decoration: none;
            color: inherit;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }
        .stat-icon {
            width: 54px; height: 54px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: #fff;
            flex-shrink: 0;
        }
        .stat-icon.blue   { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .stat-icon.green  { background: linear-gradient(135deg, #10b981, #34d399); }
        .stat-icon.purple { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .stat-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .stat-icon.pink   { background: linear-gradient(135deg, #ec4899, #f472b6); }
        .stat-icon.teal   { background: linear-gradient(135deg, #14b8a6, #2dd4bf); }
        .stat-info .num {
            font-family: 'Poppins', sans-serif;
            font-size: 26px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            letter-spacing: -0.5px;
            transition: color 0.4s ease;
        }
        .stat-info .lbl {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 3px;
            font-weight: 500;
            transition: color 0.4s ease;
        }

        /* SECTION CARD */
        .section-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 26px;
            margin-bottom: 24px;
            transition: var(--transition);
        }
        .section-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            gap: 12px;
            flex-wrap: wrap;
        }
        .section-card-header h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: color 0.4s ease;
        }
        .section-card-header h3 i { color: var(--primary); font-size: 17px; }
        .section-link {
            font-size: 13.5px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
        }
        .section-link:hover { color: var(--primary-dark); gap: 9px; }

        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .list-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 14px 16px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            text-decoration: none;
            color: inherit;
            transition: var(--transition);
            margin-bottom: 10px;
        }
        .list-item:last-child { margin-bottom: 0; }
        .list-item:hover { border-color: var(--primary); transform: translateX(4px); }
        .list-item .li-icon {
            width: 40px; height: 40px;
            border-radius: 10px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
            transition: background 0.4s ease, color 0.4s ease;
        }
        .list-item .li-icon.warn {
            background: var(--warning-light);
            color: var(--warning);
        }
        .list-item .li-body { flex: 1; min-width: 0; }
        .list-item .li-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 3px;
            transition: color 0.4s ease;
        }
        .list-item .li-meta {
            font-size: 12.5px;
            color: var(--text-muted);
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            transition: color 0.4s ease;
        }
        .list-item .li-preview {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.5;
            margin-top: 4px;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        .empty-state {
            text-align: center;
            padding: 26px 16px;
            color: var(--text-muted);
            transition: color 0.4s ease;
        }
        .empty-state i {
            font-size: 38px;
            opacity: 0.35;
            margin-bottom: 10px;
            display: block;
        }
        .empty-state p { font-size: 13.5px; line-height: 1.6; }

        /* Quick actions */
        .quick-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
        }
        .quick-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 18px;
            background: var(--bg-alt);
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            text-decoration: none;
            color: var(--text-primary);
            font-size: 14px;
            font-weight: 600;
            transition: var(--transition);
        }
        .quick-btn i {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
            transition: var(--transition);
        }
        .quick-btn:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .quick-btn:hover i {
            background: var(--primary);
            color: #fff;
        }

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.5);
            z-index: 90;
            backdrop-filter: blur(2px);
        }
        .overlay.show { display: block; }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); box-shadow: 0 0 40px rgba(0,0,0,0.2); }
            .main { margin-left: 0; }
            .hamburger { display: flex; }
        }
        @media (max-width: 700px) {
            .two-col { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .content { padding: 20px 16px; }
            .topbar { padding: 12px 16px; }
            .page-title { font-size: 17px; }
            .welcome-banner { padding: 24px 22px; }
            .welcome-banner h2 { font-size: 20px; }
            .welcome-banner p { font-size: 14px; }
            .stat-card { padding: 18px; }
            .stat-icon { width: 46px; height: 46px; font-size: 19px; }
            .stat-info .num { font-size: 22px; }
        }
    </style>
</head>
<body>

<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar" id="sidebar">

        <div class="sidebar-header">
            <img src="logo.png" alt="Educ Bridge">
            <div class="sidebar-brand">
                Educ<span>Bridge</span>
                <small><?= htmlspecialchars($short_school); ?></small>
            </div>
        </div>

        <nav class="sidebar-nav">

            <div class="nav-section-title">Overview</div>

            <a href="school-dashboard.php" class="nav-item active">
                <i class="fas fa-th-large nav-icon"></i>
                Dashboard
            </a>

            <div class="nav-section-title">Academics</div>

            <!-- ✅ NEW: Academic Level (before Students) -->
            <a href="school-academic-levels.php" class="nav-item">
                <i class="fas fa-graduation-cap nav-icon"></i>
                Academic Level
            </a>

            <a href="school-students.php" class="nav-item">
                <i class="fas fa-user-graduate nav-icon"></i>
                Students
                <?php if ($students_count > 0): ?>
                    <span class="nav-badge"><?= $students_count; ?></span>
                <?php endif; ?>
            </a>

            <a href="school-teachers.php" class="nav-item">
                <i class="fas fa-chalkboard-teacher nav-icon"></i>
                Teachers
                <?php if ($teachers_count > 0): ?>
                    <span class="nav-badge"><?= $teachers_count; ?></span>
                <?php endif; ?>
            </a>

            <a href="school-classes.php" class="nav-item">
                <i class="fas fa-layer-group nav-icon"></i>
                Classes
            </a>

            <a href="school-subjects.php" class="nav-item">
                <i class="fas fa-book nav-icon"></i>
                Subjects
            </a>

            <div class="nav-section-title">Communication</div>

            <a href="school-announcements.php" class="nav-item">
                <i class="fas fa-bullhorn nav-icon"></i>
                Announcements
                <?php if ($announce_count > 0): ?>
                    <span class="nav-badge"><?= $announce_count; ?></span>
                <?php endif; ?>
            </a>

            <a href="school-events.php" class="nav-item">
                <i class="fas fa-calendar-alt nav-icon"></i>
                Events
            </a>

            <div class="nav-section-title">System</div>
<a href="school-reports.php" class="nav-item active"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
            <a href="school-settings.php" class="nav-item">
                <i class="fas fa-cog nav-icon"></i>
                Settings
            </a>

        </nav>

        <div class="sidebar-profile">
            <div class="sidebar-avatar"><?= htmlspecialchars($initials); ?></div>
            <div class="sidebar-profile-info">
                <div class="p-name"><?= htmlspecialchars($school_admin); ?></div>
                <div class="p-role"><?= htmlspecialchars($short_school); ?></div>
            </div>
            <a href="school-dashboard.php?logout=1" class="sidebar-logout" title="Logout" aria-label="Logout">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>

    </aside>

    <div class="overlay" id="overlay"></div>

    <!-- MAIN -->
    <div class="main">

        <header class="topbar">
            <div class="topbar-left">
                <button class="hamburger" id="hamburger" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div>
                    <div class="page-title">Dashboard</div>
                    <div class="page-subtitle"><?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>

            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme" title="Toggle theme">
                    <i class="fas fa-moon"></i>
                </button>
                <a href="school-announcements.php" class="topbar-btn" title="Announcements">
                    <i class="fas fa-bell"></i>
                </a>
                <div class="topbar-avatar" title="<?= htmlspecialchars($school_admin); ?>">
                    <?= htmlspecialchars($initials); ?>
                </div>
            </div>
        </header>

        <main class="content">

            <!-- Welcome banner -->
            <div class="welcome-banner">
                <h2><?= htmlspecialchars($school_name); ?></h2>
                <p>
                    Welcome back, <strong><?= htmlspecialchars($school_admin); ?></strong>.
                    Here's an overview of your school's activity on Educ Bridge.
                </p>
                <?php if (!empty($school_code)): ?>
                    <div class="welcome-code">
                        <i class="fas fa-fingerprint"></i>
                        <?= htmlspecialchars($school_code); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- STATS -->
            <div class="stats-grid">
                <a href="school-students.php" class="stat-card">
                    <div class="stat-icon blue"><i class="fas fa-user-graduate"></i></div>
                    <div class="stat-info">
                        <div class="num"><?= $students_count; ?></div>
                        <div class="lbl">Students</div>
                    </div>
                </a>
                <a href="school-teachers.php" class="stat-card">
                    <div class="stat-icon green"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="stat-info">
                        <div class="num"><?= $teachers_count; ?></div>
                        <div class="lbl">Teachers</div>
                    </div>
                </a>
                <a href="school-classes.php" class="stat-card">
                    <div class="stat-icon purple"><i class="fas fa-layer-group"></i></div>
                    <div class="stat-info">
                        <div class="num"><?= $classes_count; ?></div>
                        <div class="lbl">Classes</div>
                    </div>
                </a>
                <a href="school-subjects.php" class="stat-card">
                    <div class="stat-icon orange"><i class="fas fa-book"></i></div>
                    <div class="stat-info">
                        <div class="num"><?= $subjects_count; ?></div>
                        <div class="lbl">Subjects</div>
                    </div>
                </a>
                <a href="school-announcements.php" class="stat-card">
                    <div class="stat-icon pink"><i class="fas fa-bullhorn"></i></div>
                    <div class="stat-info">
                        <div class="num"><?= $announce_count; ?></div>
                        <div class="lbl">Announcements</div>
                    </div>
                </a>
                <a href="school-events.php" class="stat-card">
                    <div class="stat-icon teal"><i class="fas fa-calendar-alt"></i></div>
                    <div class="stat-info">
                        <div class="num"><?= $events_count; ?></div>
                        <div class="lbl">Events</div>
                    </div>
                </a>
            </div>

            <!-- Two columns -->
            <div class="two-col">

                <!-- Announcements -->
                <div class="section-card">
                    <div class="section-card-header">
                        <h3><i class="fas fa-bullhorn"></i> Recent Announcements</h3>
                        <a href="school-announcements.php" class="section-link">
                            View all <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>

                    <?php if ($recent_announcements && mysqli_num_rows($recent_announcements) > 0): ?>
                        <?php while ($a = mysqli_fetch_assoc($recent_announcements)): ?>
                            <a href="school-announcements.php?id=<?= (int) $a['id']; ?>" class="list-item">
                                <div class="li-icon"><i class="fas fa-bullhorn"></i></div>
                                <div class="li-body">
                                    <div class="li-title"><?= htmlspecialchars($a['title']); ?></div>
                                    <div class="li-meta">
                                        <span><i class="far fa-clock"></i> <?= date('M j, Y · H:i', strtotime($a['created_at'])); ?></span>
                                    </div>
                                    <div class="li-preview"><?= htmlspecialchars($a['body']); ?></div>
                                </div>
                            </a>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <p>No announcements yet.<br>Publish your first announcement to inform students, teachers and parents.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Events -->
                <div class="section-card">
                    <div class="section-card-header">
                        <h3><i class="fas fa-calendar-alt"></i> Upcoming Events</h3>
                        <a href="school-events.php" class="section-link">
                            View all <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>

                    <?php if ($upcoming_events && mysqli_num_rows($upcoming_events) > 0): ?>
                        <?php while ($e = mysqli_fetch_assoc($upcoming_events)): ?>
                            <a href="school-events.php?id=<?= (int) $e['id']; ?>" class="list-item">
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
                        <div class="empty-state">
                            <i class="fas fa-calendar-alt"></i>
                            <p>No upcoming events.<br>Schedule an event to keep everyone informed.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <!-- Quick actions -->
            <div class="section-card">
                <div class="section-card-header">
                    <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                </div>
                <div class="quick-grid">
                    <a href="school-academic-levels.php?action=add" class="quick-btn">
                        <i class="fas fa-graduation-cap"></i> Add Academic Level
                    </a>
                    <a href="school-students.php?action=add" class="quick-btn">
                        <i class="fas fa-user-plus"></i> Add Student
                    </a>
                    <a href="school-teachers.php?action=add" class="quick-btn">
                        <i class="fas fa-chalkboard-teacher"></i> Add Teacher
                    </a>
                    <a href="school-classes.php?action=add" class="quick-btn">
                        <i class="fas fa-layer-group"></i> Create Class
                    </a>
                    <a href="school-announcements.php?action=new" class="quick-btn">
                        <i class="fas fa-bullhorn"></i> New Announcement
                    </a>
                    <a href="school-events.php?action=new" class="quick-btn">
                        <i class="fas fa-calendar-plus"></i> New Event
                    </a>
                    <a href="school-settings.php" class="quick-btn">
                        <i class="fas fa-cog"></i> Settings
                    </a>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
    // ===== THEME (applies to EVERYTHING including sidebar) =====
    (function () {
        const html = document.documentElement;
        const toggle = document.getElementById('themeToggle');
        const icon = toggle.querySelector('i');

        function applyTheme(theme) {
            html.setAttribute('data-theme', theme);
            localStorage.setItem('educbridge-theme', theme);
            if (theme === 'dark') {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            }
        }

        // Initial
        const savedTheme = localStorage.getItem('educbridge-theme') || 'light';
        applyTheme(savedTheme);

        toggle.addEventListener('click', function () {
            const current = html.getAttribute('data-theme');
            applyTheme(current === 'light' ? 'dark' : 'light');
        });
    })();

    // ===== SIDEBAR TOGGLE (mobile) =====
    (function () {
        const hamburger = document.getElementById('hamburger');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');

        function openSidebar() {
            sidebar.classList.add('open');
            overlay.classList.add('show');
        }
        function closeSidebar() {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        }
        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) closeSidebar();
            else openSidebar();
        });
        overlay.addEventListener('click', closeSidebar);
    })();
</script>

</body>
</html>