<?php
session_start();
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| AUTH
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
| AUTO-CREATE / MIGRATE teachers TABLE
|--------------------------------------------------------------------------
*/
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS teachers (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    school_id         INT NOT NULL,
    name              VARCHAR(150) NOT NULL,
    email             VARCHAR(150) NOT NULL,
    phone             VARCHAR(30)  NOT NULL,
    id_number         VARCHAR(30)  DEFAULT NULL,
    diploma           ENUM('A2','A1','A0','Masters','PhD','Professor') NOT NULL,
    teaching_level    VARCHAR(120) NOT NULL,
    sex               ENUM('Male','Female') NOT NULL,
    bank_type         VARCHAR(120) DEFAULT NULL,
    account_name      VARCHAR(150) DEFAULT NULL,
    account_number    VARCHAR(80)  DEFAULT NULL,
    has_disability    TINYINT(1)   DEFAULT 0,
    disability_details TEXT        DEFAULT NULL,
    status            ENUM('active','inactive') DEFAULT 'active',
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_teachers_school (school_id),
    KEY idx_teachers_email  (school_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

@mysqli_query($conn, "ALTER TABLE teachers ADD COLUMN id_number VARCHAR(30) DEFAULT NULL AFTER phone");
@mysqli_query($conn, "ALTER TABLE teachers ADD COLUMN bank_type VARCHAR(120) DEFAULT NULL AFTER sex");
@mysqli_query($conn, "ALTER TABLE teachers MODIFY teaching_level VARCHAR(120) NOT NULL");

/*
|--------------------------------------------------------------------------
| FLASH MESSAGES
|--------------------------------------------------------------------------
*/
$message      = "";
$message_type = "";

if (($_GET['added'] ?? '') === '1') {
    $message = "Teacher added successfully.";
    $message_type = "success";
    if (isset($_GET['mailed'])) {
        if ($_GET['mailed'] === '1') {
            $message .= " A welcome email with their profile and payment info was sent.";
        } elseif ($_GET['mailed'] === '0') {
            $reason = urldecode($_GET['reason'] ?? 'Unknown error');
            $message .= " However the welcome email could NOT be sent: " . $reason;
            $message_type = "error";
        }
    }
}
if (($_GET['updated'] ?? '') === '1') { $message = "Teacher updated successfully."; $message_type = "success"; }
if (($_GET['deleted'] ?? '') === '1') { $message = "Teacher removed successfully."; $message_type = "success"; }
if (isset($_GET['error']) && ($_GET['added'] ?? '') !== '1') {
    $message = urldecode($_GET['error']);
    $message_type = "error";
}

/*
|--------------------------------------------------------------------------
| FETCH SCHOOL'S ACTIVE ACADEMIC LEVELS
|--------------------------------------------------------------------------
| These drive the stats, filters, and level badges.
*/
$active_levels = []; // enum_value => ['key'=>..., 'label'=>...]

$lvl_stmt = mysqli_prepare($conn, "
    SELECT level_key, level_name
    FROM academic_levels
    WHERE school_id = ? AND is_active = 1
    ORDER BY FIELD(level_key, 'nursery','primary','olevel','alevel')
");
if ($lvl_stmt) {
    mysqli_stmt_bind_param($lvl_stmt, "i", $school_id);
    mysqli_stmt_execute($lvl_stmt);
    $lvl_res = mysqli_stmt_get_result($lvl_stmt);
    while ($row = mysqli_fetch_assoc($lvl_res)) {
        $active_levels[$row['level_key']] = $row['level_name'];
    }
    mysqli_stmt_close($lvl_stmt);
}

$LEVEL_KEY_TO_ENUM = [
    'nursery' => 'Nursery',
    'primary' => 'Primary',
    'olevel'  => 'O_Level',
    'alevel'  => 'A_Level',
];

/* Dynamic list of valid ENUM values the school has activated */
$valid_level_enums = [];  // e.g. ['Nursery','Primary','O_Level','A_Level']
$level_map         = [];  // ENUM value => pretty label
$level_key_by_enum = [];  // ENUM value => level_key ('nursery', ...)

foreach ($active_levels as $key => $label_in) {
    $enum_val = $LEVEL_KEY_TO_ENUM[$key] ?? null;
    if ($enum_val !== null) {
        $valid_level_enums[] = $enum_val;
        $level_key_by_enum[$enum_val] = $key;

        $label = $label_in;
        if ($enum_val === 'O_Level') $label = "O' Level";
        if ($enum_val === 'A_Level') $label = "A' Level";
        if ($enum_val === 'Nursery') $label = "Nursery";
        if ($enum_val === 'Primary') $label = "Primary";
        $level_map[$enum_val] = $label;
    }
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$q       = trim($_GET['q'] ?? '');
$level   = $_GET['level']   ?? '';
$diploma = $_GET['diploma'] ?? '';
$sex     = $_GET['sex']     ?? '';
$status  = $_GET['status']  ?? '';

$where  = ["school_id = ?"];
$params = [$school_id];
$types  = "i";

if ($q !== '') {
    $where[] = "(name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= "sss";
}
if (in_array($level, $valid_level_enums, true)) {
    $where[] = "FIND_IN_SET(?, teaching_level)";
    $params[] = $level; $types .= "s";
} else {
    $level = ''; // invalid filter
}
if (in_array($diploma, ['A2','A1','A0','Masters','PhD','Professor'], true)) {
    $where[] = "diploma = ?"; $params[] = $diploma; $types .= "s";
} else {
    $diploma = '';
}
if (in_array($sex, ['Male','Female'], true)) {
    $where[] = "sex = ?"; $params[] = $sex; $types .= "s";
} else {
    $sex = '';
}
if (in_array($status, ['active','inactive'], true)) {
    $where[] = "status = ?"; $params[] = $status; $types .= "s";
} else {
    $status = '';
}

$where_sql = implode(" AND ", $where);

/*
|--------------------------------------------------------------------------
| PAGINATION
|--------------------------------------------------------------------------
*/
$per_page = 10;
$page     = max(1, (int) ($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$count_stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM teachers WHERE $where_sql");
$total = 0;
if ($count_stmt) {
    mysqli_stmt_bind_param($count_stmt, $types, ...$params);
    mysqli_stmt_execute($count_stmt);
    $cr = mysqli_stmt_get_result($count_stmt);
    $total = $cr ? (int) mysqli_fetch_assoc($cr)['total'] : 0;
    mysqli_stmt_close($count_stmt);
}
$total_pages = max(1, (int) ceil($total / $per_page));

$list_stmt = mysqli_prepare($conn, "SELECT * FROM teachers WHERE $where_sql ORDER BY created_at DESC LIMIT ? OFFSET ?");
$teachers_res = null;
if ($list_stmt) {
    $types_full  = $types . "ii";
    $params_full = array_merge($params, [$per_page, $offset]);
    mysqli_stmt_bind_param($list_stmt, $types_full, ...$params_full);
    mysqli_stmt_execute($list_stmt);
    $teachers_res = mysqli_stmt_get_result($list_stmt);
}

/*
|--------------------------------------------------------------------------
| STATS
|--------------------------------------------------------------------------
*/
function stat_count($conn, $school_id, $field = null, $value = null) {
    if ($field === null) {
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM teachers WHERE school_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $school_id);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM teachers WHERE school_id = ? AND $field = ?");
        mysqli_stmt_bind_param($stmt, "is", $school_id, $value);
    }
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $n   = $res ? (int) mysqli_fetch_assoc($res)['total'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

function stat_level_count($conn, $school_id, $level) {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM teachers WHERE school_id = ? AND FIND_IN_SET(?, teaching_level)");
    mysqli_stmt_bind_param($stmt, "is", $school_id, $level);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $n   = $res ? (int) mysqli_fetch_assoc($res)['total'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

$total_teachers   = stat_count($conn, $school_id);
$active_teachers  = stat_count($conn, $school_id, 'status', 'active');
$inactive_teachers= stat_count($conn, $school_id, 'status', 'inactive');
$male_teachers    = stat_count($conn, $school_id, 'sex', 'Male');
$female_teachers  = stat_count($conn, $school_id, 'sex', 'Female');

/* Dynamic level counts (only levels the school activated) */
$level_counts = [];
foreach ($valid_level_enums as $enum_val) {
    $level_counts[$enum_val] = stat_level_count($conn, $school_id, $enum_val);
}

$diploma_counts = [];
foreach (['A2','A1','A0','Masters','PhD','Professor'] as $d) {
    $diploma_counts[$d] = stat_count($conn, $school_id, 'diploma', $d);
}

/*
|--------------------------------------------------------------------------
| EXPORT QUERY STRING (respects current filters)
|--------------------------------------------------------------------------
*/
$export_qs = http_build_query([
    'q'       => $q,
    'level'   => $level,
    'diploma' => $diploma,
    'sex'     => $sex,
    'status'  => $status,
]);
$has_filters = ($q !== '' || $level !== '' || $diploma !== '' || $sex !== '' || $status !== '');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teachers | Educ Bridge</title>
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
            display: flex; flex-direction: column;
            position: fixed; top: 0; left: 0;
            height: 100vh; z-index: 100;
            transition: background 0.4s ease, color 0.4s ease, transform 0.3s ease, border-color 0.4s ease;
            border-right: 1px solid var(--sidebar-border);
            box-shadow: var(--shadow-sidebar);
        }
        .sidebar-header {
            padding: 22px 22px 18px;
            border-bottom: 1px solid var(--sidebar-border);
            display: flex; align-items: center; gap: 12px;
        }
        .sidebar-header img {
            width: 42px; height: 42px;
            object-fit: contain; border-radius: 50%;
            border: 2px solid var(--sidebar-border);
            padding: 2px; background: #fff;
        }
        .sidebar-brand {
            font-family: 'Poppins', sans-serif;
            font-size: 18px; font-weight: 700;
            color: var(--text-primary); line-height: 1.2;
        }
        .sidebar-brand span { color: var(--primary); }
        .sidebar-brand small {
            display: block; font-size: 11px; font-weight: 500;
            color: var(--text-muted); letter-spacing: 0.4px;
            margin-top: 2px; text-transform: uppercase;
        }
        .sidebar-nav { flex: 1; padding: 18px 12px; overflow-y: auto; }
        .nav-section-title {
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.8px;
            color: var(--sidebar-title); padding: 12px 14px 8px;
        }
        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 14px; color: var(--sidebar-text);
            text-decoration: none; font-size: 14px; font-weight: 500;
            border-radius: 10px; margin-bottom: 4px;
            transition: var(--transition); position: relative;
        }
        .nav-item i.nav-icon {
            width: 20px; text-align: center; font-size: 15px;
            color: var(--sidebar-text); transition: var(--transition);
        }
        .nav-item:hover { background: var(--sidebar-hover); color: var(--sidebar-text-active); }
        .nav-item:hover i.nav-icon { color: var(--primary); }
        .nav-item.active { background: var(--sidebar-active); color: var(--sidebar-text-active); }
        .nav-item.active i.nav-icon { color: var(--primary); }
        .nav-item.active::before {
            content: ''; position: absolute;
            left: 0; top: 8px; bottom: 8px; width: 3px;
            background: var(--primary); border-radius: 0 4px 4px 0;
        }
        .nav-item .nav-badge {
            margin-left: auto; background: var(--sidebar-badge-bg);
            color: var(--sidebar-badge-fg); font-size: 10.5px;
            font-weight: 700; padding: 2px 8px; border-radius: 50px;
            line-height: 1.5;
        }
        .sidebar-profile {
            border-top: 1px solid var(--sidebar-border);
            padding: 16px 18px; display: flex; align-items: center;
            gap: 12px;
        }
        .sidebar-avatar {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif; font-size: 15px; font-weight: 700;
            flex-shrink: 0; box-shadow: 0 4px 12px rgba(59,130,246,0.35);
        }
        .sidebar-profile-info { flex: 1; min-width: 0; }
        .sidebar-profile-info .p-name {
            font-size: 13.5px; font-weight: 600; color: var(--text-primary);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sidebar-profile-info .p-role {
            font-size: 11.5px; color: var(--text-muted);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .sidebar-logout {
            width: 34px; height: 34px; border-radius: 8px;
            background: var(--danger-light); color: var(--danger);
            display: flex; align-items: center; justify-content: center;
            text-decoration: none; font-size: 13px; flex-shrink: 0;
            transition: var(--transition);
        }
        .sidebar-logout:hover { background: var(--danger); color: #fff; }

        /* MAIN */
        .main {
            flex: 1; margin-left: 260px; min-height: 100vh;
            display: flex; flex-direction: column;
            transition: margin-left 0.3s ease;
        }
        .topbar {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
            padding: 14px 28px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; position: sticky; top: 0; z-index: 50;
            transition: background 0.4s ease, border-color 0.4s ease;
        }
        .topbar-left { display: flex; align-items: center; gap: 14px; }
        .hamburger {
            display: none; width: 40px; height: 40px;
            border-radius: 10px; border: 1.5px solid var(--border-color);
            background: var(--bg-body); color: var(--text-secondary);
            align-items: center; justify-content: center;
            cursor: pointer; font-size: 15px;
        }
        .hamburger:hover { border-color: var(--primary); color: var(--primary); }
        .page-title {
            font-family: 'Poppins', sans-serif;
            font-size: 20px; font-weight: 700;
            color: var(--text-primary); letter-spacing: -0.4px;
        }
        .page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
        .topbar-actions { display: flex; align-items: center; gap: 10px; }
        .topbar-btn {
            width: 42px; height: 42px; border-radius: 50%;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card); color: var(--text-secondary);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 15px;
            transition: var(--transition); text-decoration: none;
            position: relative;
        }
        .topbar-btn:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
        .topbar-btn.theme-btn:hover { transform: rotate(20deg); }
        .topbar-avatar {
            width: 42px; height: 42px; border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif; font-size: 14px; font-weight: 700;
            box-shadow: 0 4px 12px rgba(59,130,246,0.3); cursor: pointer;
        }

        /* CONTENT */
        .content { padding: 28px; flex: 1; }

        .alert {
            padding: 13px 16px; border-radius: 10px;
            margin-bottom: 20px; font-size: 14px;
            display: flex; align-items: flex-start; gap: 10px;
            line-height: 1.5;
        }
        .alert-success {
            background: var(--success-light); color: #059669;
            border: 1px solid rgba(16,185,129,0.25);
        }
        [data-theme="dark"] .alert-success { color: #34d399; }
        .alert-error {
            background: var(--danger-light); color: #dc2626;
            border: 1px solid rgba(239,68,68,0.25);
        }
        [data-theme="dark"] .alert-error { color: #f87171; }

        .page-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; margin-bottom: 22px; flex-wrap: wrap;
        }
        .page-head h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 22px; font-weight: 700;
            display: flex; align-items: center; gap: 10px;
        }
        .page-head h1 i { color: var(--primary); }
        .page-head .sub {
            font-size: 13.5px; color: var(--text-muted); margin-top: 4px;
        }
        .page-head .head-actions {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }

        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 20px; border-radius: 10px;
            font-size: 14px; font-weight: 600;
            text-decoration: none; cursor: pointer;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card); color: var(--text-primary);
            transition: var(--transition);
            font-family: 'Inter', sans-serif;
        }
        .btn:hover { border-color: var(--primary); color: var(--primary); transform: translateY(-1px); }
        .btn.primary {
            background: var(--primary); color: #fff;
            border-color: var(--primary);
            box-shadow: 0 6px 18px rgba(37,99,235,0.32);
        }
        .btn.primary:hover { background: var(--primary-dark); color: #fff; }
        .btn.pdf { border-color: rgba(220,38,38,0.35); color: #dc2626; }
        .btn.pdf:hover { background: #dc2626; color: #fff; border-color: #dc2626; }
        .btn.excel { border-color: rgba(5,150,105,0.35); color: #059669; }
        .btn.excel:hover { background: #059669; color: #fff; border-color: #059669; }

        /* SUMMARY CARDS */
        .summaries {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 22px;
        }
        .sum-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 18px 20px;
            display: flex; align-items: center; gap: 14px;
            transition: var(--transition);
        }
        .sum-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }
        .sum-icon {
            width: 48px; height: 48px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; color: #fff; flex-shrink: 0;
        }
        .sum-icon.blue    { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .sum-icon.green   { background: linear-gradient(135deg, #10b981, #34d399); }
        .sum-icon.pink    { background: linear-gradient(135deg, #ec4899, #f472b6); }
        .sum-icon.orange  { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .sum-icon.purple  { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .sum-icon.red     { background: linear-gradient(135deg, #ef4444, #f87171); }

        .sum-info .num {
            font-family: 'Poppins', sans-serif;
            font-size: 24px; font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
            letter-spacing: -0.4px;
        }
        .sum-info .lbl {
            font-size: 12.5px; color: var(--text-muted);
            margin-top: 3px; font-weight: 500;
        }

        /* Breakdown */
        .breakdown-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 22px;
        }
        .breakdown-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 20px 22px;
        }
        .breakdown-card h3 {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            color: var(--text-primary);
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 14px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-size: 12px;
        }
        .breakdown-card h3 i { color: var(--primary); font-size: 13px; }

        .bar-item { margin-bottom: 12px; }
        .bar-item:last-child { margin-bottom: 0; }
        .bar-top {
            display: flex; justify-content: space-between;
            font-size: 12.5px; margin-bottom: 5px;
            color: var(--text-secondary); font-weight: 600;
        }
        .bar-top .bar-count { color: var(--text-primary); font-weight: 700; }
        .bar-track {
            height: 7px; border-radius: 50px;
            background: var(--bg-alt);
            overflow: hidden;
        }
        .bar-fill {
            height: 100%; border-radius: 50px;
            background: linear-gradient(90deg, #2563eb, #7c3aed);
            transition: width 0.6s ease;
        }
        .bar-fill.green  { background: linear-gradient(90deg, #10b981, #34d399); }
        .bar-fill.orange { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .bar-fill.pink   { background: linear-gradient(90deg, #ec4899, #f472b6); }
        .bar-fill.teal   { background: linear-gradient(90deg, #14b8a6, #2dd4bf); }

        /* FILTERS */
        .filters {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px; padding: 16px 18px;
            margin-bottom: 20px;
            display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
        }
        .filter-group {
            position: relative; display: flex; align-items: center;
            flex: 1 1 180px; min-width: 160px;
        }
        .filter-group i {
            position: absolute; left: 13px;
            color: var(--text-muted); font-size: 13px;
            pointer-events: none;
        }
        .filter-group input,
        .filter-group select {
            width: 100%; padding: 10px 14px 10px 36px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px; background: var(--bg-alt);
            color: var(--text-primary); font-family: inherit;
            font-size: 13.5px; outline: none;
            transition: var(--transition);
        }
        .filter-group select { padding-left: 36px; cursor: pointer; }
        .filter-group input:focus,
        .filter-group select:focus {
            border-color: var(--primary);
            background: var(--bg-card);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }

        .filter-summary {
            display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
            padding: 10px 14px;
            background: var(--primary-light);
            border: 1px solid rgba(37,99,235,0.25);
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 13px;
            color: var(--primary);
            font-weight: 600;
        }
        [data-theme="dark"] .filter-summary { background: rgba(59,130,246,0.12); color: #93c5fd; }
        .filter-summary .fs-tag {
            display: inline-flex; align-items: center; gap: 5px;
            background: rgba(255,255,255,0.55);
            padding: 3px 10px; border-radius: 50px;
            font-size: 12px; font-weight: 700;
        }
        [data-theme="dark"] .filter-summary .fs-tag { background: rgba(0,0,0,0.25); }

        /* TABLE */
        .table-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: visible;
        }
        .table-wrap { overflow-x: auto; }
        .table-wrap, .table-card { overflow: visible !important; }

        table {
            width: 100%; border-collapse: collapse;
            min-width: 960px;
        }
        thead th {
            background: var(--bg-alt);
            text-align: left;
            font-size: 11.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.6px;
            color: var(--text-muted);
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        tbody td {
            padding: 14px 16px;
            font-size: 13.5px;
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        tbody tr:hover { background: var(--bg-alt); }
        tbody tr:last-child td { border-bottom: none; }

        .t-name { display: flex; align-items: center; gap: 12px; }
        .t-avatar {
            width: 38px; height: 38px; border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif; font-size: 13.5px; font-weight: 700;
            flex-shrink: 0;
        }
        .t-name-info .n {
            font-weight: 600; font-size: 14px; color: var(--text-primary);
        }
        .t-name-info .s {
            font-size: 12px; color: var(--text-muted); margin-top: 1px;
        }

        .pill {
            display: inline-block;
            padding: 3px 10px; border-radius: 50px;
            font-size: 11.5px; font-weight: 700;
            letter-spacing: 0.3px; line-height: 1.5;
        }
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

        .level-pills {
            display: flex; flex-wrap: wrap; gap: 4px;
        }

        .bank-info { font-size: 12.5px; }
        .bank-info .bt {
            font-weight: 700;
            font-size: 12px;
            color: var(--primary);
            margin-bottom: 3px;
            display: flex; align-items: center; gap: 5px;
        }
        .bank-info .an { font-weight: 600; color: var(--text-primary); }
        .bank-info .num {
            color: var(--text-muted);
            font-family: 'Courier New', monospace;
            font-size: 11.5px;
        }

        /* THREE-DOT ACTIONS MENU */
        .actions-cell {
            position: relative;
            text-align: right;
            overflow: visible;
        }
        .actions-btn {
            width: 36px; height: 36px;
            border-radius: 8px;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            display: inline-flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 16px;
            transition: var(--transition);
            padding: 0;
        }
        .actions-btn:hover,
        .actions-btn.open {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--bg-alt);
        }
        .actions-menu {
            position: absolute;
            top: calc(100% + 4px);
            right: 0;
            min-width: 180px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            padding: 6px;
            z-index: 9999;
            display: none;
        }
        .actions-menu.open { display: block; }
        .actions-menu a {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 600;
            color: var(--text-primary);
            transition: var(--transition);
            white-space: nowrap;
        }
        .actions-menu a i {
            width: 16px; text-align: center;
            font-size: 13px;
            color: var(--text-muted);
            transition: var(--transition);
        }
        .actions-menu a:hover { background: var(--bg-alt); }
        .actions-menu a:hover i { color: var(--primary); }
        .actions-menu a.danger { color: var(--danger); }
        .actions-menu a.danger i { color: var(--danger); }
        .actions-menu a.danger:hover { background: var(--danger-light); }
        .actions-menu .divider {
            height: 1px;
            background: var(--border-color);
            margin: 4px 6px;
        }

        .empty-state {
            text-align: center; padding: 60px 20px;
            color: var(--text-muted);
        }
        .empty-state i { font-size: 50px; opacity: 0.35; margin-bottom: 14px; display: block; }
        .empty-state h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 17px; font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 6px;
        }
        .empty-state p { font-size: 14px; line-height: 1.6; margin-bottom: 18px; }

        /* Pagination */
        .pagination {
            display: flex; justify-content: space-between;
            align-items: center; gap: 12px;
            padding: 16px 20px;
            border-top: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        .pagination .info { font-size: 13px; color: var(--text-muted); }
        .pagination .pages { display: flex; gap: 6px; }
        .pagination .pages a,
        .pagination .pages span {
            min-width: 36px; height: 36px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 8px; padding: 0 10px;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            text-decoration: none; font-size: 13px; font-weight: 600;
            transition: var(--transition);
        }
        .pagination .pages a:hover { border-color: var(--primary); color: var(--primary); }
        .pagination .pages .active {
            background: var(--primary); color: #fff;
            border-color: var(--primary);
        }
        .pagination .pages .disabled {
            opacity: 0.5; cursor: not-allowed;
        }

        .overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(15,23,42,0.5); z-index: 90;
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
            .breakdown-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 600px) {
            .content { padding: 20px 16px; }
            .topbar { padding: 12px 16px; }
            .page-title { font-size: 17px; }
            .page-head h1 { font-size: 18px; }
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
            <a href="school-academic-levels.php" class="nav-item">
                <i class="fas fa-graduation-cap nav-icon"></i>
                Academic Level
            </a>
            <a href="school-students.php" class="nav-item">
                <i class="fas fa-user-graduate nav-icon"></i> Students
            </a>
            <a href="school-teachers.php" class="nav-item active">
                <i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers
                <?php if ($total_teachers > 0): ?>
                    <span class="nav-badge"><?= $total_teachers; ?></span>
                <?php endif; ?>
            </a>
            <a href="school-classes.php" class="nav-item">
                <i class="fas fa-layer-group nav-icon"></i> Classes
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
            <a href="school-dashboard.php?logout=1" class="sidebar-logout" title="Logout">
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
                    <div class="page-title">Teachers</div>
                    <div class="page-subtitle"><?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>

            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme">
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
                <div class="alert alert-<?= htmlspecialchars($message_type); ?>">
                    <i class="fas <?= $message_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($message); ?></span>
                </div>
            <?php endif; ?>

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-chalkboard-teacher"></i> Teachers</h1>
                    <div class="sub">Overview and list of all teachers at <?= htmlspecialchars($school_name); ?></div>
                </div>

                <div class="head-actions">
                    <a href="school-export-teachers.php?type=pdf&<?= htmlspecialchars($export_qs); ?>"
                       class="btn pdf" target="_blank"
                       title="Export <?= $has_filters ? 'filtered' : 'all' ?> teachers to PDF">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>

                    <a href="school-export-teachers.php?type=excel&<?= htmlspecialchars($export_qs); ?>"
                       class="btn excel"
                       title="Export <?= $has_filters ? 'filtered' : 'all' ?> teachers to Excel">
                        <i class="fas fa-file-excel"></i> Excel
                    </a>

                    <a href="school-add-teacher.php" class="btn primary">
                        <i class="fas fa-plus"></i> Add New Teacher
                    </a>
                </div>
            </div>

            <!-- FILTER SUMMARY -->
            <?php if ($has_filters): ?>
                <div class="filter-summary">
                    <i class="fas fa-filter"></i>
                    <span>Active filters (PDF/Excel will use these):</span>
                    <?php if ($q !== ''): ?>
                        <span class="fs-tag"><i class="fas fa-search"></i> "<?= htmlspecialchars($q); ?>"</span>
                    <?php endif; ?>
                    <?php if ($level !== ''): ?>
                        <span class="fs-tag"><i class="fas fa-book-open"></i> <?= htmlspecialchars($level_map[$level] ?? $level); ?></span>
                    <?php endif; ?>
                    <?php if ($diploma !== ''): ?>
                        <span class="fs-tag"><i class="fas fa-graduation-cap"></i> <?= htmlspecialchars($diploma); ?></span>
                    <?php endif; ?>
                    <?php if ($sex !== ''): ?>
                        <span class="fs-tag"><i class="fas fa-venus-mars"></i> <?= htmlspecialchars($sex); ?></span>
                    <?php endif; ?>
                    <?php if ($status !== ''): ?>
                        <span class="fs-tag"><i class="fas fa-toggle-on"></i> <?= ucfirst(htmlspecialchars($status)); ?></span>
                    <?php endif; ?>
                    <a href="school-teachers.php" style="color:inherit;text-decoration:underline;margin-left:auto;font-weight:700;font-size:12.5px;">
                        Clear
                    </a>
                </div>
            <?php endif; ?>

            <!-- SUMMARY CARDS -->
            <div class="summaries">
                <div class="sum-card">
                    <div class="sum-icon blue"><i class="fas fa-chalkboard-teacher"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_teachers; ?></div>
                        <div class="lbl">Total Teachers</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon green"><i class="fas fa-user-check"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $active_teachers; ?></div>
                        <div class="lbl">Active</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon red"><i class="fas fa-user-slash"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $inactive_teachers; ?></div>
                        <div class="lbl">Inactive</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon pink"><i class="fas fa-mars"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $male_teachers; ?></div>
                        <div class="lbl">Male</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon purple"><i class="fas fa-venus"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $female_teachers; ?></div>
                        <div class="lbl">Female</div>
                    </div>
                </div>
            </div>

            <!-- BREAKDOWN (dynamic based on school's active levels) -->
            <div class="breakdown-row">
                <div class="breakdown-card">
                    <h3><i class="fas fa-book-open"></i> By Teaching Level</h3>

                    <?php if (empty($valid_level_enums)): ?>
                        <p style="font-size:13.5px;color:var(--text-muted);margin:0;">
                            No academic levels have been added for your school yet.
                            <a href="school-academic-levels.php" style="color:var(--primary);font-weight:700;text-decoration:none;">Add levels</a>
                            to see a breakdown here.
                        </p>
                    <?php else: ?>
                        <?php
                            $max_level = max(array_values($level_counts));
                            if ($max_level < 1) $max_level = 1;
                            $bar_colors = ['', 'green', 'orange', 'pink', 'teal'];
                        ?>
                        <?php $i = 0; foreach ($valid_level_enums as $enum_val):
                            $label = $level_map[$enum_val] ?? $enum_val;
                            $count = $level_counts[$enum_val] ?? 0;
                            $color = $bar_colors[$i % count($bar_colors)];
                            $i++;
                        ?>
                            <div class="bar-item">
                                <div class="bar-top">
                                    <span><?= htmlspecialchars($label); ?></span>
                                    <span class="bar-count"><?= $count; ?></span>
                                </div>
                                <div class="bar-track">
                                    <div class="bar-fill <?= $color; ?>"
                                         style="width: <?= (int) round(($count / $max_level) * 100); ?>%;"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="breakdown-card">
                    <h3><i class="fas fa-graduation-cap"></i> By Diploma</h3>
                    <?php $max_dip = max(array_values($diploma_counts)) ?: 1; ?>
                    <?php foreach ($diploma_counts as $label => $count): ?>
                        <div class="bar-item">
                            <div class="bar-top"><span><?= htmlspecialchars($label); ?></span><span class="bar-count"><?= $count; ?></span></div>
                            <div class="bar-track"><div class="bar-fill pink" style="width: <?= (int) round(($count / $max_dip) * 100); ?>%;"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- FILTERS -->
            <form method="GET" action="school-teachers.php" class="filters">
                <div class="filter-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Search name, email or phone" value="<?= htmlspecialchars($q); ?>">
                </div>

                <div class="filter-group">
                    <i class="fas fa-book-open"></i>
                    <select name="level">
                        <option value="">All Levels</option>
                        <?php foreach ($valid_level_enums as $enum_val): ?>
                            <option value="<?= htmlspecialchars($enum_val); ?>" <?= $level === $enum_val ? 'selected' : ''; ?>>
                                <?= htmlspecialchars($level_map[$enum_val] ?? $enum_val); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <i class="fas fa-graduation-cap"></i>
                    <select name="diploma">
                        <option value="">All Diplomas</option>
                        <?php foreach (['A2','A1','A0','Masters','PhD','Professor'] as $d): ?>
                            <option value="<?= $d; ?>" <?= $diploma === $d ? 'selected' : ''; ?>><?= $d; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <i class="fas fa-venus-mars"></i>
                    <select name="sex">
                        <option value="">All Sex</option>
                        <option value="Male"   <?= $sex === 'Male'   ? 'selected' : ''; ?>>Male</option>
                        <option value="Female" <?= $sex === 'Female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>

                <div class="filter-group">
                    <i class="fas fa-toggle-on"></i>
                    <select name="status">
                        <option value="">All Status</option>
                        <option value="active"   <?= $status === 'active'   ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <button type="submit" class="btn primary"><i class="fas fa-filter"></i> Filter</button>
                <a href="school-teachers.php" class="btn"><i class="fas fa-rotate"></i> Reset</a>
            </form>

            <!-- TABLE -->
            <div class="table-card">
                <?php if ($teachers_res && mysqli_num_rows($teachers_res) > 0): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Teacher</th>
                                    <th>Contact</th>
                                    <th>Diploma</th>
                                    <th>Teaching Level(s)</th>
                                    <th>Sex</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($t = mysqli_fetch_assoc($teachers_res)): ?>
                                    <?php
                                        $t_init = "";
                                        foreach (explode(" ", trim($t['name'])) as $part) {
                                            if ($part !== "") {
                                                $t_init .= strtoupper($part[0]);
                                                if (strlen($t_init) >= 2) break;
                                            }
                                        }
                                        if ($t_init === "") $t_init = "T";

                                        $t_levels = array_filter(array_map('trim', explode(',', (string) $t['teaching_level'])));

                                        $diploma_class = [
                                            'A2' => 'blue', 'A1' => 'blue', 'A0' => 'purple',
                                            'Masters' => 'orange', 'PhD' => 'red', 'Professor' => 'red',
                                        ][$t['diploma']] ?? 'gray';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="t-name">
                                                <div class="t-avatar"><?= htmlspecialchars($t_init); ?></div>
                                                <div class="t-name-info">
                                                    <div class="n"><?= htmlspecialchars($t['name']); ?></div>
                                                    <div class="s">Joined <?= date('M j, Y', strtotime($t['created_at'])); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size:13px;">
                                                <div><i class="fas fa-envelope" style="color:var(--text-muted);margin-right:6px;"></i><?= htmlspecialchars($t['email']); ?></div>
                                                <div style="margin-top:3px;"><i class="fas fa-phone" style="color:var(--text-muted);margin-right:6px;"></i><?= htmlspecialchars($t['phone']); ?></div>
                                            </div>
                                        </td>
                                        <td><span class="pill <?= $diploma_class; ?>"><?= htmlspecialchars($t['diploma']); ?></span></td>
                                        <td>
                                            <div class="level-pills">
                                                <?php if (!empty($t_levels)): ?>
                                                    <?php foreach ($t_levels as $lv): ?>
                                                        <span class="pill teal"><?= htmlspecialchars($level_map[$lv] ?? $lv); ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span style="color:var(--text-muted);font-size:12px;">—</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($t['sex']); ?></td>
                                        <td>
                                            <?php $has_bank = !empty($t['bank_type']) || !empty($t['account_name']) || !empty($t['account_number']); ?>
                                            <?php if ($has_bank): ?>
                                                <div class="bank-info">
                                                    <?php if (!empty($t['bank_type'])): ?>
                                                        <div class="bt"><i class="fas fa-university"></i> <?= htmlspecialchars($t['bank_type']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($t['account_name'])): ?>
                                                        <div class="an"><?= htmlspecialchars($t['account_name']); ?></div>
                                                    <?php endif; ?>
                                                    <?php if (!empty($t['account_number'])): ?>
                                                        <div class="num"><?= htmlspecialchars($t['account_number']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color:var(--text-muted);font-size:12.5px;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($t['status'] === 'active'): ?>
                                                <span class="pill green">Active</span>
                                            <?php else: ?>
                                                <span class="pill red">Inactive</span>
                                            <?php endif; ?>
                                            <?php if ((int)$t['has_disability'] === 1): ?>
                                                <span class="pill orange" title="Has disability" style="margin-left:4px;">
                                                    <i class="fas fa-wheelchair"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions-cell">
                                            <button type="button" class="actions-btn" data-id="<?= (int)$t['id']; ?>" title="More options">
                                                <i class="fas fa-ellipsis-vertical"></i>
                                            </button>

                                            <div class="actions-menu" data-menu-for="<?= (int)$t['id']; ?>">
                                                <a href="school-edit-teacher.php?id=<?= (int)$t['id']; ?>">
                                                    <i class="fas fa-pen"></i> Edit
                                                </a>
                                                <a href="school-view-teacher.php?id=<?= (int)$t['id']; ?>">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                                <div class="divider"></div>
                                                <a href="school-delete-teacher.php?id=<?= (int)$t['id']; ?>"
                                                   class="danger"
                                                   onclick="return confirm('Delete this teacher? This cannot be undone.');">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <div class="info">
                                Showing <?= $offset + 1; ?>–<?= min($offset + $per_page, $total); ?> of <?= $total; ?> teacher(s)
                            </div>
                            <div class="pages">
                                <?php
                                    $qs = $_GET;
                                    $build = function ($p) use ($qs) {
                                        $qs['page'] = $p;
                                        return 'school-teachers.php?' . http_build_query($qs);
                                    };
                                ?>
                                <?php if ($page > 1): ?>
                                    <a href="<?= htmlspecialchars($build($page - 1)); ?>"><i class="fas fa-chevron-left"></i></a>
                                <?php else: ?>
                                    <span class="disabled"><i class="fas fa-chevron-left"></i></span>
                                <?php endif; ?>

                                <?php
                                    $start = max(1, $page - 2);
                                    $end   = min($total_pages, $page + 2);
                                    if ($start > 1) {
                                        echo '<a href="' . htmlspecialchars($build(1)) . '">1</a>';
                                        if ($start > 2) echo '<span class="disabled">…</span>';
                                    }
                                    for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <?php if ($i === $page): ?>
                                        <span class="active"><?= $i; ?></span>
                                    <?php else: ?>
                                        <a href="<?= htmlspecialchars($build($i)); ?>"><?= $i; ?></a>
                                    <?php endif; ?>
                                <?php
                                    endfor;
                                    if ($end < $total_pages) {
                                        if ($end < $total_pages - 1) echo '<span class="disabled">…</span>';
                                        echo '<a href="' . htmlspecialchars($build($total_pages)) . '">' . $total_pages . '</a>';
                                    }
                                ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="<?= htmlspecialchars($build($page + 1)); ?>"><i class="fas fa-chevron-right"></i></a>
                                <?php else: ?>
                                    <span class="disabled"><i class="fas fa-chevron-right"></i></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <h3>No teachers yet</h3>
                        <p>
                            <?= $has_filters
                                ? 'No teachers matched your filters. Try adjusting them or reset to see all.'
                                : 'Add your first teacher to start building your school\'s staff list.'; ?>
                        </p>
                        <?php if ($has_filters): ?>
                            <a href="school-teachers.php" class="btn primary">
                                <i class="fas fa-rotate"></i> Clear filters
                            </a>
                        <?php else: ?>
                            <a href="school-add-teacher.php" class="btn primary">
                                <i class="fas fa-plus"></i> Add New Teacher
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

        </main>
    </div>
</div>

<!-- Floating menu portal -->
<div id="menuPortal" style="position: fixed; inset: 0; pointer-events: none; z-index: 9999;"></div>

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
            applyTheme(html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
        });
    })();

    // SIDEBAR
    (function () {
        const hamburger = document.getElementById('hamburger');
        const sidebar   = document.getElementById('sidebar');
        const overlay   = document.getElementById('overlay');
        function openSidebar()  { sidebar.classList.add('open');  overlay.classList.add('show'); }
        function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) closeSidebar(); else openSidebar();
        });
        overlay.addEventListener('click', closeSidebar);
    })();

    // THREE-DOT MENU
    (function () {
        const portal = document.getElementById('menuPortal');
        let currentMenu = null;
        let currentBtn  = null;

        function closeMenu() {
            if (currentMenu) { currentMenu.remove(); currentMenu = null; }
            if (currentBtn)  { currentBtn.classList.remove('open'); currentBtn = null; }
        }

        function buildMenu(template, btnRect) {
            const wrap = document.createElement('div');
            wrap.className = 'actions-menu open';
            wrap.style.position = 'fixed';
            wrap.style.zIndex = '10000';
            wrap.style.pointerEvents = 'auto';
            wrap.innerHTML = template.innerHTML;

            portal.appendChild(wrap);

            const menuRect = wrap.getBoundingClientRect();
            const spaceBelow = window.innerHeight - btnRect.bottom;
            const spaceAbove = btnRect.top;

            let top;
            if (spaceBelow < menuRect.height + 12 && spaceAbove > menuRect.height + 12) {
                top = btnRect.top - menuRect.height - 6;
            } else {
                top = btnRect.bottom + 6;
            }

            let left = btnRect.right - menuRect.width;
            if (left < 8) left = 8;
            if (left + menuRect.width > window.innerWidth - 8) {
                left = window.innerWidth - menuRect.width - 8;
            }

            wrap.style.top  = top + 'px';
            wrap.style.left = left + 'px';

            return wrap;
        }

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.actions-btn');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();

                if (currentBtn === btn) { closeMenu(); return; }
                closeMenu();

                const cell = btn.closest('.actions-cell');
                const template = cell ? cell.querySelector('.actions-menu') : null;
                if (!template) return;

                const btnRect = btn.getBoundingClientRect();
                const menu = buildMenu(template, btnRect);

                btn.classList.add('open');
                currentBtn  = btn;
                currentMenu = menu;

                template.classList.remove('open');
                return;
            }

            if (e.target.closest('#menuPortal .actions-menu')) return;
            closeMenu();
        });

        window.addEventListener('scroll', closeMenu, { passive: true });
        window.addEventListener('resize', closeMenu);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeMenu();
        });
    })();
</script>

</body>
</html>