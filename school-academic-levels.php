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
| STANDARD LEVELS
|--------------------------------------------------------------------------
*/
$STANDARD_LEVELS = [
    'nursery' => [
        'name'  => 'Nursery',
        'desc'  => 'Early childhood education (Baby, Middle, Top class)',
        'icon'  => 'fa-baby',
        'color' => 'pink',
    ],
    'primary' => [
        'name'  => 'Primary',
        'desc'  => 'Primary education (P1 – P6)',
        'icon'  => 'fa-child',
        'color' => 'blue',
    ],
    'olevel'  => [
        'name'  => "O'Level",
        'desc'  => 'Ordinary Level (S1 – S3)',
        'icon'  => 'fa-book-open',
        'color' => 'orange',
    ],
    'alevel'  => [
        'name'  => "A'Level",
        'desc'  => 'Advanced Level (S4 – S6)',
        'icon'  => 'fa-graduation-cap',
        'color' => 'purple',
    ],
];

/* Map between the level_key used here and the ENUM used in classes.level */
$KEY_TO_ENUM = [
    'nursery' => 'Nursery',
    'primary' => 'Primary',
    'olevel'  => 'O_Level',
    'alevel'  => 'A_Level',
];
$ENUM_TO_KEY = array_flip($KEY_TO_ENUM);

/*
|--------------------------------------------------------------------------
| ENSURE TABLE EXISTS
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
    CREATE TABLE IF NOT EXISTS academic_levels (
        id INT AUTO_INCREMENT PRIMARY KEY,
        school_id INT NOT NULL,
        level_key VARCHAR(30) NOT NULL,
        level_name VARCHAR(60) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_school_level (school_id, level_key),
        KEY idx_school (school_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/*
|--------------------------------------------------------------------------
| HANDLE ACTIONS (Add / Remove)
|--------------------------------------------------------------------------
*/
$message      = "";
$message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ---- ADD a level ---- */
    if ($action === 'add_level') {
        $key = trim($_POST['level_key'] ?? '');
        if (!isset($STANDARD_LEVELS[$key])) {
            $message = "Invalid academic level.";
            $message_type = "error";
        } else {
            $lvl_name = $STANDARD_LEVELS[$key]['name'];
            $stmt = mysqli_prepare($conn, "
                INSERT INTO academic_levels (school_id, level_key, level_name, is_active)
                VALUES (?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE is_active = 1, level_name = VALUES(level_name)
            ");
            mysqli_stmt_bind_param($stmt, "iss", $school_id, $key, $lvl_name);
            if (mysqli_stmt_execute($stmt)) {
                $message = "{$lvl_name} has been added to your school.";
                $message_type = "success";
            } else {
                $message = "Failed to add level. Please try again.";
                $message_type = "error";
            }
            mysqli_stmt_close($stmt);
        }
    }

    /* ---- REMOVE a level ---- */
    if ($action === 'remove_level') {
        $key = trim($_POST['level_key'] ?? '');
        if (!isset($STANDARD_LEVELS[$key])) {
            $message = "Invalid academic level.";
            $message_type = "error";
        } else {
            // Check real classes count using ENUM
            $enum_val = $KEY_TO_ENUM[$key] ?? '';
            $has_classes = 0;

            $chk = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM classes WHERE school_id = ? AND level = ?");
            if ($chk) {
                mysqli_stmt_bind_param($chk, "is", $school_id, $enum_val);
                mysqli_stmt_execute($chk);
                $r = mysqli_stmt_get_result($chk);
                $has_classes = (int) (mysqli_fetch_assoc($r)['total'] ?? 0);
                mysqli_stmt_close($chk);
            }

            if ($has_classes > 0) {
                $message = "You cannot remove this level — it still has {$has_classes} class(es). Delete the classes first.";
                $message_type = "error";
            } else {
                $stmt = mysqli_prepare($conn, "DELETE FROM academic_levels WHERE school_id = ? AND level_key = ?");
                mysqli_stmt_bind_param($stmt, "is", $school_id, $key);
                if (mysqli_stmt_execute($stmt)) {
                    $message = "{$STANDARD_LEVELS[$key]['name']} has been removed from your school.";
                    $message_type = "success";
                } else {
                    $message = "Failed to remove level. Please try again.";
                    $message_type = "error";
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| FETCH SCHOOL'S ACTIVE LEVELS
|--------------------------------------------------------------------------
*/
$active_levels = [];
$stmt = mysqli_prepare($conn, "SELECT * FROM academic_levels WHERE school_id = ? AND is_active = 1");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $school_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $active_levels[$row['level_key']] = $row;
    }
    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| ✅ REAL CLASS COUNTS PER LEVEL  (from `classes` table using ENUM)
|--------------------------------------------------------------------------
| This is the fix. Previously we looked for a non-existent `level_key`
| column. Now we read the real `level` ENUM and map it to our key.
*/
$class_counts = [];

$stmt = mysqli_prepare($conn, "
    SELECT level, COUNT(*) AS total
    FROM classes
    WHERE school_id = ?
    GROUP BY level
");
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $school_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $enum_val = $row['level'];
        $key = $ENUM_TO_KEY[$enum_val] ?? null;
        if ($key !== null) {
            $class_counts[$key] = (int) $row['total'];
        }
    }
    mysqli_stmt_close($stmt);
}

/* Total classes across all levels (for the header pill) */
$total_classes_all = array_sum($class_counts);

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Levels | Educ Bridge</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* =========================================================
           LIGHT THEME
           ========================================================= */
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --success: #10b981; --success-light: rgba(16, 185, 129, 0.1);
            --warning: #f59e0b; --warning-light: rgba(245, 158, 11, 0.12);
            --danger: #ef4444; --danger-light: rgba(239, 68, 68, 0.1);

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
           DARK THEME
           ========================================================= */
        [data-theme="dark"] {
            --primary: #3b82f6; --primary-dark: #2563eb; --primary-light: #1e3a5f;
            --success: #34d399; --success-light: rgba(16, 185, 129, 0.15);
            --warning: #fbbf24; --warning-light: rgba(245, 158, 11, 0.18);
            --danger: #f87171; --danger-light: rgba(239, 68, 68, 0.15);

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
        }
        .sidebar-header img {
            width: 42px; height: 42px;
            object-fit: contain;
            border-radius: 50%;
            border: 2px solid var(--sidebar-border);
            padding: 2px;
            background: #fff;
        }
        .sidebar-brand {
            font-family: 'Poppins', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
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
        }
        .sidebar-nav { flex: 1; padding: 18px 12px; overflow-y: auto; }
        .nav-section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--sidebar-title);
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
        }
        .sidebar-profile {
            border-top: 1px solid var(--sidebar-border);
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
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
        }
        .sidebar-profile-info { flex: 1; min-width: 0; }
        .sidebar-profile-info .p-name {
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sidebar-profile-info .p-role {
            font-size: 11.5px;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
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

        /* Alerts */
        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid transparent;
        }
        .alert.success {
            background: var(--success-light);
            color: var(--success);
            border-color: rgba(16,185,129,0.25);
        }
        .alert.error {
            background: var(--danger-light);
            color: var(--danger);
            border-color: rgba(239,68,68,0.25);
        }

        /* Page header card */
        .page-header-card {
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            border-radius: 18px;
            padding: 28px 32px;
            color: #fff;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 36px rgba(37, 99, 235, 0.25);
        }
        .page-header-card::before {
            content: '';
            position: absolute;
            top: -50%; right: -10%;
            width: 320px; height: 320px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
            border-radius: 50%;
        }
        .page-header-card h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-header-card p {
            font-size: 14.5px;
            opacity: 0.92;
            position: relative;
            z-index: 1;
            max-width: 680px;
            line-height: 1.6;
        }
        .page-header-stats {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
            margin-top: 18px;
            position: relative;
            z-index: 1;
        }
        .page-header-stat {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            background: rgba(255,255,255,0.16);
            border: 1px solid rgba(255,255,255,0.28);
            border-radius: 50px;
            font-size: 12.5px;
            font-weight: 600;
        }
        .page-header-stat i { font-size: 13px; opacity: 0.92; }
        .page-header-stat strong { font-weight: 800; }

        /* Section title */
        .section-title {
            font-family: 'Poppins', sans-serif;
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 8px 0 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-title i { color: var(--primary); font-size: 15px; }
        .section-title .count-pill {
            background: var(--primary-light);
            color: var(--primary);
            font-size: 11.5px;
            font-weight: 700;
            padding: 3px 10px;
            border-radius: 50px;
            font-family: 'Inter', sans-serif;
        }

        /* Levels grid */
        .levels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 34px;
        }

        .level-card {
            background: var(--bg-card);
            border: 1.5px solid var(--border-color);
            border-radius: 18px;
            padding: 24px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .level-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }
        .level-card.is-active {
            border-color: var(--primary);
            box-shadow: 0 8px 24px rgba(37, 99, 235, 0.12);
        }
        .level-card.is-inactive {
            opacity: 0.92;
        }

        .level-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }

        .level-icon {
            width: 58px; height: 58px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #fff;
            flex-shrink: 0;
            box-shadow: 0 8px 20px rgba(0,0,0,0.12);
        }
        .level-icon.pink   { background: linear-gradient(135deg, #ec4899, #f472b6); }
        .level-icon.blue   { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .level-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .level-icon.purple { background: linear-gradient(135deg, #7c3aed, #a78bfa); }

        .level-status {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            padding: 5px 12px;
            border-radius: 50px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .level-status.active {
            background: var(--success-light);
            color: var(--success);
        }
        .level-status.inactive {
            background: var(--bg-alt);
            color: var(--text-muted);
            border: 1px solid var(--border-color);
        }
        .level-status i { font-size: 9px; }

        .level-card h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 19px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 6px;
            letter-spacing: -0.3px;
        }
        .level-card .level-desc {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.55;
            margin-bottom: 18px;
            flex: 1;
        }

        /* Meta strip: classes count */
        .level-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            margin-bottom: 16px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .level-meta .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }
        .level-meta .meta-item i {
            color: var(--primary);
            font-size: 14px;
        }
        .level-meta .meta-item strong {
            color: var(--text-primary);
            font-size: 14.5px;
        }
        .level-meta .meta-pill {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 50px;
            background: var(--primary-light);
            color: var(--primary);
            letter-spacing: 0.3px;
        }
        .level-meta .meta-pill.empty {
            background: var(--bg-alt);
            color: var(--text-muted);
            border: 1px solid var(--border-color);
        }

        /* Buttons */
        .btn-row { display: flex; gap: 10px; flex-wrap: wrap; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 16px;
            border-radius: 11px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            border: 1.5px solid transparent;
            transition: var(--transition);
            text-decoration: none;
            font-family: inherit;
            white-space: nowrap;
        }
        .btn-primary {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
            flex: 1;
        }
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.35);
        }
        .btn-outline {
            background: var(--bg-card);
            color: var(--text-secondary);
            border-color: var(--border-color);
            flex: 1;
        }
        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
        }
        .btn-danger {
            background: var(--danger-light);
            color: var(--danger);
            border-color: rgba(239,68,68,0.2);
        }
        .btn-danger:hover {
            background: var(--danger);
            color: #fff;
            border-color: var(--danger);
        }
        .btn-sm { padding: 8px 12px; font-size: 12.5px; border-radius: 9px; }

        /* Info panel */
        .info-panel {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 22px 24px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-top: 8px;
        }
        .info-panel .ip-icon {
            width: 46px; height: 46px;
            border-radius: 12px;
            background: var(--primary-light);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .info-panel h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 6px;
        }
        .info-panel p {
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.65;
        }
        .info-panel p strong { color: var(--text-primary); }

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

        /* Responsive */
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.open { transform: translateX(0); box-shadow: 0 0 40px rgba(0,0,0,0.2); }
            .main { margin-left: 0; }
            .hamburger { display: flex; }
        }
        @media (max-width: 600px) {
            .content { padding: 20px 16px; }
            .topbar { padding: 12px 16px; }
            .page-title { font-size: 17px; }
            .page-header-card { padding: 22px 20px; }
            .page-header-card h2 { font-size: 19px; }
            .page-header-card p { font-size: 13.5px; }
            .levels-grid { grid-template-columns: 1fr; gap: 16px; }
            .level-card { padding: 20px; }
            .btn-row { flex-direction: column; }
            .btn { width: 100%; }
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
            <a href="school-dashboard.php" class="nav-item">
                <i class="fas fa-th-large nav-icon"></i> Dashboard
            </a>

            <div class="nav-section-title">Academics</div>
            <a href="school-academic-levels.php" class="nav-item active">
                <i class="fas fa-graduation-cap nav-icon"></i> Academic Level
            </a>
            <a href="school-students.php" class="nav-item">
                <i class="fas fa-user-graduate nav-icon"></i> Students
            </a>
            <a href="school-teachers.php" class="nav-item">
                <i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers
            </a>
            <a href="school-classes.php" class="nav-item">
                <i class="fas fa-layer-group nav-icon"></i> Classes
                <?php if ($total_classes_all > 0): ?>
                    <span class="nav-badge"><?= $total_classes_all; ?></span>
                <?php endif; ?>
            </a>
            <a href="school-subjects.php" class="nav-item">
                <i class="fas fa-book nav-icon"></i> Subjects
            </a>

            <div class="nav-section-title">Communication</div>
            <a href="school-announcements.php" class="nav-item">
                <i class="fas fa-bullhorn nav-icon"></i> Announcements
            </a>
            <a href="school-events.php" class="nav-item">
                <i class="fas fa-calendar-alt nav-icon"></i> Events
            </a>

            <div class="nav-section-title">System</div>
            <a href="school-reports.php" class="nav-item active"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
            <a href="school-settings.php" class="nav-item">
                <i class="fas fa-cog nav-icon"></i> Settings
            </a>
        </nav>

        <div class="sidebar-profile">
            <div class="sidebar-avatar"><?= htmlspecialchars($initials); ?></div>
            <div class="sidebar-profile-info">
                <div class="p-name"><?= htmlspecialchars($school_admin); ?></div>
                <div class="p-role"><?= htmlspecialchars($short_school); ?></div>
            </div>
            <a href="school-academic-levels.php?logout=1" class="sidebar-logout" title="Logout" aria-label="Logout">
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
                    <div class="page-title">Academic Levels</div>
                    <div class="page-subtitle"><?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>

            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" title="Toggle theme" aria-label="Toggle theme">
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

            <?php if ($message !== ""): ?>
                <div class="alert <?= $message_type === 'success' ? 'success' : 'error'; ?>">
                    <i class="fas <?= $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                    <?= htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <!-- Page header -->
            <div class="page-header-card">
                <h2><i class="fas fa-graduation-cap"></i> Academic Levels</h2>
                <p>
                    Choose the academic levels your school offers. Once a level is added,
                    you can create and manage its classes.
                </p>

                <div class="page-header-stats">
                    <div class="page-header-stat">
                        <i class="fas fa-layer-group"></i>
                        <span>Active Levels: <strong><?= count($active_levels); ?></strong></span>
                    </div>
                    <div class="page-header-stat">
                        <i class="fas fa-graduation-cap"></i>
                        <span>Total Classes: <strong><?= $total_classes_all; ?></strong></span>
                    </div>
                </div>
            </div>

            <!-- Active Levels -->
            <?php if (count($active_levels) > 0): ?>
                <div class="section-title">
                    <i class="fas fa-circle-check"></i>
                    Active Levels
                    <span class="count-pill"><?= count($active_levels); ?> active</span>
                </div>

                <div class="levels-grid">
                    <?php foreach ($STANDARD_LEVELS as $key => $meta): ?>
                        <?php if (!isset($active_levels[$key])) continue; ?>
                        <?php
                            $class_count = $class_counts[$key] ?? 0;
                        ?>
                        <div class="level-card is-active">
                            <div class="level-card-top">
                                <div class="level-icon <?= $meta['color']; ?>">
                                    <i class="fas <?= $meta['icon']; ?>"></i>
                                </div>
                                <span class="level-status active">
                                    <i class="fas fa-circle"></i> Active
                                </span>
                            </div>

                            <h3><?= htmlspecialchars($meta['name']); ?></h3>
                            <p class="level-desc"><?= htmlspecialchars($meta['desc']); ?></p>

                            <div class="level-meta">
                                <div class="meta-item">
                                    <i class="fas fa-layer-group"></i>
                                    Classes: <strong><?= $class_count; ?></strong>
                                </div>
                                <?php if ($class_count > 0): ?>
                                    <span class="meta-pill"><?= $class_count; ?> created</span>
                                <?php else: ?>
                                    <span class="meta-pill empty">No classes yet</span>
                                <?php endif; ?>
                            </div>

                            <div class="btn-row">
                                <a href="school-classes.php?level=<?= urlencode($key); ?>" class="btn btn-primary">
                                    <i class="fas fa-eye"></i>
                                    <?= $class_count > 0 ? 'View Classes' : 'Add First Class'; ?>
                                </a>
                                <form method="post" onsubmit="return confirm('Remove this level? Any classes under it must be deleted first.');" style="flex:1;display:flex;">
                                    <input type="hidden" name="action" value="remove_level">
                                    <input type="hidden" name="level_key" value="<?= htmlspecialchars($key); ?>">
                                    <button type="submit" class="btn btn-danger" style="width:100%;">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Available Levels (to add) -->
            <?php
                $available = array_filter($STANDARD_LEVELS, function ($k) use ($active_levels) {
                    return !isset($active_levels[$k]);
                }, ARRAY_FILTER_USE_KEY);
            ?>

            <div class="section-title">
                <i class="fas fa-plus-circle"></i>
                <?= count($active_levels) > 0 ? 'Add More Levels' : 'Available Levels'; ?>
                <?php if (count($available) > 0): ?>
                    <span class="count-pill"><?= count($available); ?> available</span>
                <?php endif; ?>
            </div>

            <?php if (count($available) > 0): ?>
                <div class="levels-grid">
                    <?php foreach ($available as $key => $meta): ?>
                        <div class="level-card is-inactive">
                            <div class="level-card-top">
                                <div class="level-icon <?= $meta['color']; ?>">
                                    <i class="fas <?= $meta['icon']; ?>"></i>
                                </div>
                                <span class="level-status inactive">
                                    Not added
                                </span>
                            </div>

                            <h3><?= htmlspecialchars($meta['name']); ?></h3>
                            <p class="level-desc"><?= htmlspecialchars($meta['desc']); ?></p>

                            <div class="btn-row">
                                <form method="post" style="flex:1;display:flex;">
                                    <input type="hidden" name="action" value="add_level">
                                    <input type="hidden" name="level_key" value="<?= htmlspecialchars($key); ?>">
                                    <button type="submit" class="btn btn-primary" style="width:100%;">
                                        <i class="fas fa-plus"></i> Add <?= htmlspecialchars($meta['name']); ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="info-panel">
                    <div class="ip-icon"><i class="fas fa-circle-check"></i></div>
                    <div>
                        <h4>All levels are active</h4>
                        <p>Your school already offers all available academic levels. You can now manage classes inside each level.</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Info panel -->
            <div class="info-panel" style="margin-top: 24px;">
                <div class="ip-icon"><i class="fas fa-circle-info"></i></div>
                <div>
                    <h4>How it works</h4>
                    <p>
                        1. Add the levels your school offers (<strong>Nursery, Primary, O'Level, A'Level</strong>).<br>
                        2. Inside each level, create the classes you teach (e.g., <em>P1, P2, S1, S2…</em>).<br>
                        3. Assign students and teachers to those classes.
                    </p>
                </div>
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
            if (theme === 'dark') {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            } else {
                icon.classList.remove('fa-sun');
                icon.classList.add('fa-moon');
            }
        }

        const savedTheme = localStorage.getItem('educbridge-theme') || 'light';
        applyTheme(savedTheme);

        toggle.addEventListener('click', function () {
            const current = html.getAttribute('data-theme');
            applyTheme(current === 'light' ? 'dark' : 'light');
        });
    })();

    // SIDEBAR MOBILE
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