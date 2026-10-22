<?php

session_start();

require_once 'db.php';

// Redirect if not logged in
if (empty($_SESSION["admin_id"]) || empty($_SESSION["admin_verified"])) {
    header("Location: admin-login.php");
    exit;
}

$admin_name = $_SESSION["admin_name"];

// Initials
$initials = "";
foreach (explode(" ", trim($admin_name)) as $part) {
    if ($part !== "") {
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) break;
    }
}
if ($initials === "") $initials = "A";

// Handle delete
if (isset($_GET["delete"]) && is_numeric($_GET["delete"])) {
    $del_id = (int) $_GET["delete"];
    $del_stmt = mysqli_prepare($conn, "DELETE FROM messages WHERE id = ?");
    mysqli_stmt_bind_param($del_stmt, "i", $del_id);
    mysqli_stmt_execute($del_stmt);
    mysqli_stmt_close($del_stmt);
    header("Location: admin-messages.php?deleted=1");
    exit;
}

// Mark all as read
if (isset($_GET["mark_all_read"])) {
    mysqli_query($conn, "UPDATE messages SET status = 'read' WHERE status = 'unread'");
    header("Location: admin-messages.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| FETCH MESSAGES (with optional search + filter)
|--------------------------------------------------------------------------
| We select the reply columns as well so we can display the latest
| activity (the reply if it exists, otherwise the original message).
| Ordering uses COALESCE(replied_at, created_at) so replied threads
| float to the top just like a chat.
*/

$search = trim($_GET["search"] ?? "");
$filter = $_GET["filter"] ?? "all";

$where = [];
$params = [];
$types  = "";

if ($search !== "") {
    $where[] = "(name LIKE ? OR email LIKE ? OR subject LIKE ? OR message LIKE ? OR reply LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sssss";
}

if ($filter === "unread") {
    $where[] = "status = 'unread'";
} elseif ($filter === "read") {
    $where[] = "status = 'read'";
} elseif ($filter === "replied") {
    $where[] = "reply IS NOT NULL AND reply <> ''";
} elseif ($filter === "unreplied") {
    $where[] = "(reply IS NULL OR reply = '')";
}

$where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

$sql = "SELECT id, name, email, subject, message, reply, replied_at, status, created_at,
        COALESCE(replied_at, created_at) AS last_activity
        FROM messages
        $where_sql
        ORDER BY last_activity DESC";

$stmt = mysqli_prepare($conn, $sql);

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$messages = mysqli_stmt_get_result($stmt);

/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/

$total_count  = 0;
$unread_count = 0;
$replied_count = 0;

$r1 = mysqli_query($conn, "SELECT COUNT(*) AS t FROM messages");
if ($r1) { $total_count = (int) mysqli_fetch_assoc($r1)['t']; }

$r2 = mysqli_query($conn, "SELECT COUNT(*) AS t FROM messages WHERE status = 'unread'");
if ($r2) { $unread_count = (int) mysqli_fetch_assoc($r2)['t']; }

$r3 = mysqli_query($conn, "SELECT COUNT(*) AS t FROM messages WHERE reply IS NOT NULL AND reply <> ''");
if ($r3) { $replied_count = (int) mysqli_fetch_assoc($r3)['t']; }

$read_count = $total_count - $unread_count;

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages | Educ Bridge Admin</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --success: #10b981;
            --success-light: rgba(16, 185, 129, 0.1);
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(37, 99, 235, 0.18);
            --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.08);

            --bg-body: #f8fafc; --bg-card: #ffffff;
            --bg-alt: #f1f5f9;
            --text-primary: #0f172a; --text-secondary: #475569; --text-muted: #64748b;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.07);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --success: #34d399;
            --success-light: rgba(16, 185, 129, 0.15);
            --sidebar-bg: #020617;
            --sidebar-hover: rgba(255,255,255,0.05);
            --sidebar-active: rgba(59, 130, 246, 0.2);
            --sidebar-text: #94a3b8;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.06);

            --bg-body: #0b1120; --bg-card: #1e293b;
            --bg-alt: #1a2332;
            --text-primary: #f1f5f9; --text-secondary: #cbd5e1; --text-muted: #94a3b8;
            --border-color: #334155;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
            --shadow-lg: 0 20px 45px rgba(0,0,0,0.5);
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

        /* SIDEBAR */
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
            transition: transform 0.3s ease;
            border-right: 1px solid var(--sidebar-border);
        }
        .sidebar-header {
            padding: 22px 22px 18px;
            border-bottom: 1px solid var(--sidebar-border);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sidebar-header img {
            width: 42px; height: 42px;
            object-fit: contain;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.15);
            padding: 2px;
            background: #fff;
        }
        .sidebar-brand {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: #fff;
            line-height: 1.2;
        }
        .sidebar-brand span { color: #60a5fa; }
        .sidebar-brand small {
            display: block;
            font-size: 11px;
            font-weight: 500;
            color: var(--sidebar-text);
            letter-spacing: 0.4px;
            margin-top: 2px;
            text-transform: uppercase;
        }
        .sidebar-nav { flex: 1; padding: 18px 12px; overflow-y: auto; }
        .nav-section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--sidebar-text);
            opacity: 0.6;
            padding: 12px 14px 8px;
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
        .nav-item:hover { background: var(--sidebar-hover); color: var(--sidebar-text-active); }
        .nav-item:hover i.nav-icon { color: #60a5fa; }
        .nav-item.active { background: var(--sidebar-active); color: var(--sidebar-text-active); }
        .nav-item.active i.nav-icon { color: #60a5fa; }
        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0; top: 8px; bottom: 8px;
            width: 3px;
            background: #3b82f6;
            border-radius: 0 4px 4px 0;
        }
        .nav-item .nav-badge {
            margin-left: auto;
            background: #ef4444;
            color: #fff;
            font-size: 10.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 50px;
            line-height: 1.5;
        }
        .sidebar-profile {
            border-top: 1px solid var(--sidebar-border);
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.02);
        }
        .sidebar-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 700;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35);
        }
        .sidebar-profile-info { flex: 1; min-width: 0; }
        .sidebar-profile-info .p-name {
            font-size: 13.5px;
            font-weight: 600;
            color: #fff;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-profile-info .p-role {
            font-size: 11.5px;
            color: var(--sidebar-text);
            opacity: 0.75;
        }
        .sidebar-logout {
            width: 34px; height: 34px;
            border-radius: 8px;
            background: rgba(239, 68, 68, 0.12);
            color: #f87171;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 13px;
            flex-shrink: 0;
            transition: var(--transition);
        }
        .sidebar-logout:hover { background: #ef4444; color: #fff; }

        /* MAIN */
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
        }
        .page-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 2px;
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
            text-decoration: none;
            position: relative;
        }
        .topbar-btn:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
        .topbar-btn.theme-btn:hover { transform: rotate(20deg); }
        .topbar-btn .dot {
            position: absolute;
            top: 8px; right: 8px;
            width: 8px; height: 8px;
            background: #ef4444;
            border: 2px solid var(--bg-card);
            border-radius: 50%;
        }
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

        /* CONTENT */
        .content { padding: 28px; flex: 1; }

        .page-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }
        .page-head h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-head h1 i { color: var(--primary); }
        .page-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 18px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-primary);
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
        }
        .btn:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
        .btn.primary {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
        }
        .btn.primary:hover { background: var(--primary-dark); color: #fff; }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 4px;
            margin-bottom: 18px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 6px;
            width: fit-content;
            flex-wrap: wrap;
        }
        .tabs a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 16px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            color: var(--text-secondary);
            transition: var(--transition);
        }
        .tabs a:hover { background: var(--bg-alt); color: var(--primary); }
        .tabs a.active {
            background: var(--primary);
            color: #fff;
        }
        .tabs a .tab-count {
            font-size: 11px;
            padding: 1px 7px;
            border-radius: 50px;
            background: rgba(0,0,0,0.08);
        }
        .tabs a.active .tab-count {
            background: rgba(255,255,255,0.25);
        }

        /* Search */
        .search-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .search-wrapper {
            position: relative;
            flex: 1;
            max-width: 480px;
            min-width: 220px;
        }
        .search-wrapper i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 14px;
        }
        .search-wrapper input {
            width: 100%;
            padding: 12px 15px 12px 42px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-card);
            color: var(--text-primary);
            font-family: 'Inter', sans-serif;
            font-size: 14.5px;
            outline: none;
            transition: var(--transition);
        }
        .search-wrapper input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }

        /* Message list */
        .msg-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .msg-item {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            padding: 18px 20px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            text-decoration: none;
            color: inherit;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        .msg-item:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }
        .msg-item.unread {
            border-left: 4px solid var(--primary);
        }
        .msg-item.replied {
            border-left: 4px solid var(--success);
        }
        .msg-item.unread .msg-subject {
            color: var(--primary);
        }
        .msg-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 17px;
            font-weight: 700;
            flex-shrink: 0;
            position: relative;
        }
        .msg-avatar .replied-check {
            position: absolute;
            bottom: -2px; right: -2px;
            width: 18px; height: 18px;
            border-radius: 50%;
            background: var(--success);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            border: 2px solid var(--bg-card);
        }
        .msg-body {
            flex: 1;
            min-width: 0;
        }
        .msg-row-1 {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }
        .msg-name {
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .msg-email {
            font-size: 12.5px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .msg-date {
            font-size: 12.5px;
            color: var(--text-muted);
            white-space: nowrap;
        }
        .msg-subject {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-primary);
            margin: 8px 0 4px;
        }
        .msg-preview {
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.55;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }

        /* Reply indicator in the preview */
        .reply-indicator {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            font-size: 13.5px;
            color: var(--text-secondary);
            line-height: 1.55;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        .reply-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--success-light);
            color: var(--success);
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 50px;
            flex-shrink: 0;
            margin-right: 4px;
        }
        .reply-pill i {
            font-size: 9px;
        }

        .msg-chevron {
            color: var(--text-muted);
            font-size: 14px;
            align-self: center;
            flex-shrink: 0;
            transition: var(--transition);
        }
        .msg-item:hover .msg-chevron {
            color: var(--primary);
            transform: translateX(3px);
        }

        /* Empty */
        .empty-state {
            text-align: center;
            padding: 70px 20px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            color: var(--text-muted);
        }
        .empty-state i {
            font-size: 54px;
            opacity: 0.35;
            margin-bottom: 16px;
            display: block;
        }
        .empty-state p { font-size: 15px; line-height: 1.6; }

        /* Alert */
        .alert {
            padding: 13px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: rgba(16, 185, 129, 0.08);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.25);
        }
        [data-theme="dark"] .alert { color: #34d399; }

        /* Overlay */
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
            .sidebar { transform: translateX(-100%); box-shadow: 0 0 40px rgba(0,0,0,0.2); }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; }
            .hamburger { display: flex; }
        }
        @media (max-width: 600px) {
            .content { padding: 20px 16px; }
            .topbar { padding: 12px 16px; }
            .page-title { font-size: 17px; }
            .msg-item { padding: 15px 16px; }
            .msg-avatar { width: 42px; height: 42px; font-size: 15px; }
            .page-head h1 { font-size: 20px; }
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
                <small>Admin Panel</small>
            </div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-title">Main</div>
            <a href="admin-dashboard.php" class="nav-item">
                <i class="fas fa-th-large nav-icon"></i> Dashboard
            </a>
            <a href="admin-messages.php" class="nav-item active">
                <i class="fas fa-envelope nav-icon"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span class="nav-badge"><?= $unread_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="admin-schools.php" class="nav-item">
                <i class="fas fa-school nav-icon"></i> Schools
            </a>
            <a href="admin-students.php" class="nav-item">
                <i class="fas fa-user-graduate nav-icon"></i> Students
            </a>
            <a href="admin-teachers.php" class="nav-item">
                <i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers
            </a>

            <div class="nav-section-title">Management</div>
            <a href="admin-courses.php" class="nav-item">
                <i class="fas fa-book nav-icon"></i> Courses
            </a>
            <a href="admin-events.php" class="nav-item">
                <i class="fas fa-calendar-alt nav-icon"></i> Events
            </a>
            <a href="admin-announcements.php" class="nav-item">
                <i class="fas fa-bullhorn nav-icon"></i> Announcements
            </a>

            <div class="nav-section-title">System</div>
            <a href="admin-reports.php" class="nav-item">
                <i class="fas fa-chart-line nav-icon"></i> Reports
            </a>
            <a href="admin-settings.php" class="nav-item">
                <i class="fas fa-cog nav-icon"></i> Settings
            </a>
        </nav>

        <div class="sidebar-profile">
            <div class="sidebar-avatar"><?= htmlspecialchars($initials); ?></div>
            <div class="sidebar-profile-info">
                <div class="p-name"><?= htmlspecialchars($admin_name); ?></div>
                <div class="p-role">Administrator</div>
            </div>
            <a href="logout.php" class="sidebar-logout" title="Logout" aria-label="Logout">
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
                    <div class="page-title">Messages</div>
                    <div class="page-subtitle">Inbox · <?= $total_count; ?> total · <?= $replied_count; ?> replied</div>
                </div>
            </div>

            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme" title="Toggle theme">
                    <i class="fas fa-moon"></i>
                </button>
                <a href="admin-messages.php" class="topbar-btn" title="Notifications">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread_count > 0): ?><span class="dot"></span><?php endif; ?>
                </a>
                <div class="topbar-avatar" title="<?= htmlspecialchars($admin_name); ?>">
                    <?= htmlspecialchars($initials); ?>
                </div>
            </div>
        </header>

        <main class="content">

            <?php if (isset($_GET['deleted'])): ?>
                <div class="alert">
                    <i class="fas fa-check-circle" style="margin-top:2px;"></i>
                    <span>Message deleted successfully.</span>
                </div>
            <?php endif; ?>

            <!-- Page heading -->
            <div class="page-head">
                <h1><i class="fas fa-envelope"></i> All Messages</h1>

                <div class="page-actions">
                    <?php if ($unread_count > 0): ?>
                        <a href="admin-messages.php?mark_all_read=1" class="btn">
                            <i class="fas fa-check-double"></i> Mark All Read
                        </a>
                    <?php endif; ?>
                    <a href="admin-dashboard.php" class="btn primary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <a href="admin-messages.php" class="<?= $filter === 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-inbox"></i> All
                    <span class="tab-count"><?= $total_count; ?></span>
                </a>
                <a href="admin-messages.php?filter=unread" class="<?= $filter === 'unread' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope"></i> Unread
                    <span class="tab-count"><?= $unread_count; ?></span>
                </a>
                <a href="admin-messages.php?filter=read" class="<?= $filter === 'read' ? 'active' : ''; ?>">
                    <i class="fas fa-envelope-open"></i> Read
                    <span class="tab-count"><?= $read_count; ?></span>
                </a>
                <a href="admin-messages.php?filter=replied" class="<?= $filter === 'replied' ? 'active' : ''; ?>">
                    <i class="fas fa-reply"></i> Replied
                    <span class="tab-count"><?= $replied_count; ?></span>
                </a>
                <a href="admin-messages.php?filter=unreplied" class="<?= $filter === 'unreplied' ? 'active' : ''; ?>">
                    <i class="fas fa-clock"></i> Awaiting
                    <span class="tab-count"><?= $total_count - $replied_count; ?></span>
                </a>
            </div>

            <!-- Search -->
            <form method="GET" class="search-bar">
                <?php if ($filter !== 'all'): ?>
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter); ?>">
                <?php endif; ?>
                <div class="search-wrapper">
                    <i class="fas fa-search"></i>
                    <input
                        type="text"
                        name="search"
                        placeholder="Search by name, email, subject, content, or reply..."
                        value="<?= htmlspecialchars($search); ?>"
                    >
                </div>
                <button type="submit" class="btn primary">
                    <i class="fas fa-search"></i> Search
                </button>
                <?php if ($search !== ""): ?>
                    <a href="admin-messages.php<?= $filter !== 'all' ? '?filter=' . $filter : ''; ?>" class="btn">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>

            <!-- Messages -->
            <?php if ($messages && mysqli_num_rows($messages) > 0): ?>
                <div class="msg-list">
                    <?php while ($msg = mysqli_fetch_assoc($messages)):
                        $has_reply = !empty($msg['reply']);
                        $card_class = $has_reply ? 'replied' : ($msg['status'] === 'unread' ? 'unread' : '');
                        $display_time = $has_reply ? $msg['replied_at'] : $msg['created_at'];
                    ?>
                        <a href="admin-reply-message.php?id=<?= $msg['id']; ?>" class="msg-item <?= $card_class; ?>">
                            <div class="msg-avatar">
                                <?= strtoupper(substr($msg['name'], 0, 1)); ?>
                                <?php if ($has_reply): ?>
                                    <span class="replied-check"><i class="fas fa-check"></i></span>
                                <?php endif; ?>
                            </div>
                            <div class="msg-body">
                                <div class="msg-row-1">
                                    <div>
                                        <div class="msg-name"><?= htmlspecialchars($msg['name']); ?></div>
                                        <div class="msg-email"><?= htmlspecialchars($msg['email']); ?></div>
                                    </div>
                                    <div class="msg-date">
                                        <i class="far fa-clock"></i>
                                        <?= date('M j, Y · H:i', strtotime($display_time)); ?>
                                    </div>
                                </div>
                                <div class="msg-subject"><?= htmlspecialchars($msg['subject']); ?></div>

                                <?php if ($has_reply): ?>
                                    <!-- Show the admin's reply as the latest message -->
                                    <div class="msg-preview">
                                        <span class="reply-pill">
                                            <i class="fas fa-reply"></i> YOU REPLIED
                                        </span>
                                        <?= htmlspecialchars($msg['reply']); ?>
                                    </div>
                                <?php else: ?>
                                    <!-- No reply yet — show the visitor's original message -->
                                    <div class="msg-preview"><?= htmlspecialchars($msg['message']); ?></div>
                                <?php endif; ?>
                            </div>
                            <i class="fas fa-chevron-right msg-chevron"></i>
                        </a>
                    <?php endwhile; ?>
                </div>
            <?php else: ?>

                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>
                        <?php if ($search !== ""): ?>
                            No messages match your search "<strong><?= htmlspecialchars($search); ?></strong>".
                        <?php elseif ($filter === 'unread'): ?>
                            No unread messages. You're all caught up!
                        <?php elseif ($filter === 'read'): ?>
                            No read messages yet.
                        <?php elseif ($filter === 'replied'): ?>
                            You haven't replied to any messages yet.
                        <?php elseif ($filter === 'unreplied'): ?>
                            Every message has been replied to. Great job!
                        <?php else: ?>
                            No messages yet. When visitors use the contact form, their messages will appear here.
                        <?php endif; ?>
                    </p>
                </div>

            <?php endif; ?>

        </main>
    </div>
</div>

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

        const hamburger = document.getElementById('hamburger');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');

        function openSidebar() { sidebar.classList.add('open'); overlay.classList.add('show'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }

        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) closeSidebar();
            else openSidebar();
        });
        overlay.addEventListener('click', closeSidebar);
    })();
</script>

</body>
</html>