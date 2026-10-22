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

$initials = "";
foreach (explode(" ", trim($school_admin)) as $part) {
    if ($part !== "") {
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) break;
    }
}
if ($initials === "") $initials = "S";
$short_school = function_exists('mb_strimwidth')
    ? mb_strimwidth($school_name, 0, 24, '…')
    : substr($school_name, 0, 24);

/*
|--------------------------------------------------------------------------
| AUTO-CREATE / MIGRATE TABLES
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS school_announcements (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    school_id     INT NOT NULL,
    sender_type   ENUM('system','school') NOT NULL DEFAULT 'system',
    sender_id     INT DEFAULT NULL,
    sender_name   VARCHAR(150) NOT NULL DEFAULT 'System',
    title         VARCHAR(200) NOT NULL,
    body          TEXT NOT NULL,
    priority      ENUM('normal','high','urgent') NOT NULL DEFAULT 'normal',
    audience      ENUM('all','teachers','parents','students','accounts') NOT NULL DEFAULT 'all',
    scope_type    ENUM('all','level','class','individual') NOT NULL DEFAULT 'all',
    scope_level   VARCHAR(20) DEFAULT NULL,
    scope_class   INT DEFAULT NULL,
    total_recipients INT NOT NULL DEFAULT 0,
    is_read       TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sa_school   (school_id),
    KEY idx_sa_sender   (sender_type),
    KEY idx_sa_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

@mysqli_query($conn, "ALTER TABLE school_announcements ADD COLUMN sender_id INT DEFAULT NULL AFTER sender_type");
@mysqli_query($conn, "ALTER TABLE school_announcements ADD COLUMN scope_type ENUM('all','level','class','individual') NOT NULL DEFAULT 'all' AFTER audience");
@mysqli_query($conn, "ALTER TABLE school_announcements ADD COLUMN scope_level VARCHAR(20) DEFAULT NULL AFTER scope_type");
@mysqli_query($conn, "ALTER TABLE school_announcements ADD COLUMN scope_class INT DEFAULT NULL AFTER scope_level");
@mysqli_query($conn, "ALTER TABLE school_announcements ADD COLUMN total_recipients INT NOT NULL DEFAULT 0 AFTER scope_class");
@mysqli_query($conn, "ALTER TABLE school_announcements ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER total_recipients");

@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS school_announcement_recipients (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    announcement_id   INT NOT NULL,
    school_id         INT NOT NULL,
    recipient_type    ENUM('teacher','parent','student','account') NOT NULL,
    recipient_id      INT NOT NULL,
    recipient_name    VARCHAR(150) DEFAULT NULL,
    is_read           TINYINT(1) NOT NULL DEFAULT 0,
    read_at           DATETIME DEFAULT NULL,
    delivered_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sar_ann     (announcement_id),
    KEY idx_sar_school  (school_id),
    KEY idx_sar_type    (recipient_type),
    KEY idx_sar_rec     (recipient_type, recipient_id),
    KEY idx_sar_read    (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* Admin-side tables exist only after the platform admin sends one */
$admin_tables_exist = false;
$chk = @mysqli_query($conn, "SHOW TABLES LIKE 'admin_announcement_recipients'");
if ($chk && mysqli_num_rows($chk) > 0) $admin_tables_exist = true;

/*
|--------------------------------------------------------------------------
| SUPPORTING DATA FOR TARGETING UI
|--------------------------------------------------------------------------
*/
$levels = [];
$lvl_res = mysqli_query($conn, "
    SELECT id, level_key, level_name
    FROM academic_levels
    WHERE school_id = $school_id AND is_active = 1
    ORDER BY id ASC
");
if ($lvl_res) { while ($row = mysqli_fetch_assoc($lvl_res)) $levels[] = $row; }

$classes = [];
$cls_res = mysqli_query($conn, "
    SELECT id, class_name, level, room
    FROM classes
    WHERE school_id = $school_id AND status = 'active'
    ORDER BY FIELD(level,'Nursery','Primary','O_Level','A_Level'), class_name ASC
");
if ($cls_res) { while ($row = mysqli_fetch_assoc($cls_res)) $classes[] = $row; }

$teachers = [];
$tea_res = mysqli_query($conn, "
    SELECT id, name
    FROM teachers
    WHERE school_id = $school_id AND status = 'active'
    ORDER BY name ASC
");
if ($tea_res) { while ($row = mysqli_fetch_assoc($tea_res)) $teachers[] = $row; }

/* Optional: parents */
$parents = [];
$chk = @mysqli_query($conn, "SHOW TABLES LIKE 'parents'");
if ($chk && mysqli_num_rows($chk) > 0) {
    $parent_name_col = 'name';
    foreach (['name','full_name','parent_name'] as $c) {
        $r = @mysqli_query($conn, "SHOW COLUMNS FROM parents LIKE '" . mysqli_real_escape_string($conn, $c) . "'");
        if ($r && mysqli_num_rows($r) > 0) { $parent_name_col = $c; break; }
    }
    $pres = mysqli_query($conn, "SELECT id, `{$parent_name_col}` AS name FROM parents ORDER BY `{$parent_name_col}` ASC");
    if ($pres) { while ($row = mysqli_fetch_assoc($pres)) $parents[] = $row; }
}

/* Optional: students */
$students = [];
$chk = @mysqli_query($conn, "SHOW TABLES LIKE 'students'");
if ($chk && mysqli_num_rows($chk) > 0) {
    $student_name_col = 'name';
    foreach (['name','full_name','student_name'] as $c) {
        $r = @mysqli_query($conn, "SHOW COLUMNS FROM students LIKE '" . mysqli_real_escape_string($conn, $c) . "'");
        if ($r && mysqli_num_rows($r) > 0) { $student_name_col = $c; break; }
    }
    $has_class = false;
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'class_id'");
    if ($r && mysqli_num_rows($r) > 0) $has_class = true;

    $ssql = $has_class
        ? "SELECT s.id, s.`{$student_name_col}` AS name, s.class_id, c.class_name
           FROM students s
           LEFT JOIN classes c ON c.id = s.class_id
           WHERE s.school_id = $school_id
           ORDER BY s.`{$student_name_col}` ASC"
        : "SELECT id, `{$student_name_col}` AS name, NULL AS class_id, NULL AS class_name
           FROM students WHERE school_id = $school_id ORDER BY `{$student_name_col}` ASC";
    $sres = mysqli_query($conn, $ssql);
    if ($sres) { while ($row = mysqli_fetch_assoc($sres)) $students[] = $row; }
}

/* Optional: accounts */
$accounts = [];
$chk = @mysqli_query($conn, "SHOW TABLES LIKE 'school_users'");
if ($chk && mysqli_num_rows($chk) > 0) {
    $ares = mysqli_query($conn, "SELECT id, name FROM school_users WHERE school_id = $school_id ORDER BY name ASC");
    if ($ares) { while ($row = mysqli_fetch_assoc($ares)) $accounts[] = $row; }
}

/*
|--------------------------------------------------------------------------
| FETCH ANNOUNCEMENTS (merged: school + admin)
|--------------------------------------------------------------------------
*/
$tab    = $_GET['tab'] ?? 'inbox';
$filter = $_GET['filter'] ?? 'all';
$q      = trim($_GET['q'] ?? '');

$announcements = [];

if ($tab === 'sent') {
    $where  = ["sa.school_id = ?", "sa.sender_type = 'school'"];
    $params = [$school_id];
    $types  = "i";

    if ($q !== '') {
        $where[] = "(sa.title LIKE ? OR sa.body LIKE ?)";
        $like = "%{$q}%";
        $params[] = $like; $params[] = $like;
        $types   .= "ss";
    }
    $where_sql = implode(" AND ", $where);

    $stmt = mysqli_prepare($conn, "
        SELECT
            sa.id,
            'local' AS origin,
            sa.sender_type,
            sa.sender_name,
            sa.title,
            sa.body,
            sa.priority,
            sa.audience,
            sa.scope_type,
            sa.scope_level,
            sa.scope_class,
            sa.total_recipients,
            1 AS is_read,
            sa.created_at
        FROM school_announcements sa
        WHERE $where_sql
        ORDER BY sa.created_at DESC
        LIMIT 100
    ");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $announcements[] = $row;
        mysqli_stmt_close($stmt);
    }

} else {
    /* ---------- Local school announcements ---------- */
    $where  = ["sa.school_id = ?"];
    $params = [$school_id];
    $types  = "i";

    if ($filter === 'system')     $where[] = "sa.sender_type = 'system'";
    elseif ($filter === 'school') $where[] = "sa.sender_type = 'school'";

    if ($q !== '') {
        $where[] = "(sa.title LIKE ? OR sa.body LIKE ?)";
        $like = "%{$q}%";
        $params[] = $like; $params[] = $like;
        $types   .= "ss";
    }

    $where_sql = implode(" AND ", $where);

    /* is_read is derived: an announcement is "read" only when all of its
       recipient rows are marked read. */
    $stmt = mysqli_prepare($conn, "
        SELECT
            sa.id,
            'local' AS origin,
            sa.sender_type,
            sa.sender_name,
            sa.title,
            sa.body,
            sa.priority,
            sa.audience,
            sa.scope_type,
            sa.scope_level,
            sa.scope_class,
            sa.total_recipients,
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM school_announcement_recipients sar
                    WHERE sar.announcement_id = sa.id
                      AND sar.school_id = sa.school_id
                      AND sar.is_read = 0
                ) THEN 0
                ELSE 1
            END AS is_read,
            sa.created_at
        FROM school_announcements sa
        WHERE $where_sql
        ORDER BY sa.created_at DESC
        LIMIT 100
    ");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) $announcements[] = $row;
        mysqli_stmt_close($stmt);
    }

    /* ---------- Admin (from platform) ---------- */
    if ($filter !== 'school' && $admin_tables_exist) {
        $admin_where  = ["1=1"];
        $admin_params = [$school_id];
        $admin_types  = "i";

        if ($q !== '') {
            $admin_where[] = "(aa.title LIKE ? OR aa.body LIKE ?)";
            $like = "%{$q}%";
            $admin_params[] = $like; $admin_params[] = $like;
            $admin_types   .= "ss";
        }

        $admin_where_sql = implode(" AND ", $admin_where);

        $astmt = mysqli_prepare($conn, "
            SELECT
                aa.id,
                'admin' AS origin,
                'system' AS sender_type,
                aa.sender_name,
                aa.title,
                aa.body,
                aa.priority,
                aa.audience,
                aa.scope_type,
                NULL AS scope_level,
                NULL AS scope_class,
                1 AS total_recipients,
                aar.is_read,
                aar.read_at,
                aa.created_at
            FROM admin_announcements aa
            INNER JOIN admin_announcement_recipients aar
                    ON aar.announcement_id = aa.id
                   AND aar.recipient_type  = 'school'
                   AND aar.recipient_id    = ?
            WHERE $admin_where_sql
            ORDER BY aa.created_at DESC
            LIMIT 100
        ");
        if ($astmt) {
            mysqli_stmt_bind_param($astmt, $admin_types, ...$admin_params);
            mysqli_stmt_execute($astmt);
            $ares = mysqli_stmt_get_result($astmt);
            while ($row = mysqli_fetch_assoc($ares)) {
                $row['origin']  = 'admin';
                $row['is_read'] = (int)$row['is_read'];
                $announcements[] = $row;
            }
            mysqli_stmt_close($astmt);
        }
    }

    usort($announcements, function ($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    if (count($announcements) > 100) $announcements = array_slice($announcements, 0, 100);
}

/*
|--------------------------------------------------------------------------
| COUNTS
|--------------------------------------------------------------------------
*/
function acount($conn, $sql, $types = "", $params = []) {
    $stmt = @mysqli_prepare($conn, $sql);
    if (!$stmt) return 0;
    if ($types !== "" && !empty($params)) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $n = $res ? (int) mysqli_fetch_assoc($res)['total'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

$total_local_system = acount($conn,
    "SELECT COUNT(*) AS total FROM school_announcements WHERE school_id = ? AND sender_type = 'system'",
    "i", [$school_id]);

$total_school = acount($conn,
    "SELECT COUNT(*) AS total FROM school_announcements WHERE school_id = ? AND sender_type = 'school'",
    "i", [$school_id]);

$total_admin = 0;
$total_admin_unread = 0;
if ($admin_tables_exist) {
    $total_admin = acount($conn, "
        SELECT COUNT(*) AS total
        FROM admin_announcement_recipients
        WHERE recipient_type = 'school' AND recipient_id = ?
    ", "i", [$school_id]);

    $total_admin_unread = acount($conn, "
        SELECT COUNT(*) AS total
        FROM admin_announcement_recipients
        WHERE recipient_type = 'school' AND recipient_id = ? AND is_read = 0
    ", "i", [$school_id]);
}

/* Local unread = number of announcements where at least one recipient row is unread */
$total_local_unread = acount($conn, "
    SELECT COUNT(DISTINCT sar.announcement_id) AS total
    FROM school_announcement_recipients sar
    INNER JOIN school_announcements sa ON sa.id = sar.announcement_id
    WHERE sar.school_id = ? AND sar.is_read = 0
", "i", [$school_id]);

$total_local = $total_local_system + $total_school;
$total_all   = $total_local + $total_admin;

$total_system  = $total_local_system + $total_admin;
$total_unread  = $total_admin_unread + $total_local_unread;

$level_map = [
    'all'      => 'Everyone',
    'teachers' => 'Teachers',
    'parents'  => 'Parents',
    'students' => 'Students',
    'accounts' => 'System Accounts',
    'schools'  => 'Schools',
];

$priority_map = [
    'normal' => ['label' => 'Normal', 'class' => 'gray'],
    'high'   => ['label' => 'High',   'class' => 'orange'],
    'urgent' => ['label' => 'Urgent', 'class' => 'red'],
];

$level_display = [
    'Nursery' => 'Nursery',
    'Primary' => 'Primary',
    'O_Level' => "O' Level",
    'A_Level' => "A' Level",
];

function level_key_to_enum($key) {
    $key = strtolower(trim($key));
    $map = [
        'nursery' => 'Nursery', 'primary' => 'Primary',
        'olevel'  => 'O_Level', 'o_level' => 'O_Level', 'o-level' => 'O_Level',
        'alevel'  => 'A_Level', 'a_level' => 'A_Level', 'a-level' => 'A_Level',
    ];
    return $map[$key] ?? null;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements | Educ Bridge</title>
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
            --purple: #7c3aed;  --purple-light: rgba(124,58,237,0.1);
            --sidebar-bg: #ffffff;
            --sidebar-hover: rgba(37, 99, 235, 0.06);
            --sidebar-active: rgba(37, 99, 235, 0.10);
            --sidebar-text: #475569;
            --sidebar-text-active: #0f172a;
            --sidebar-border: #e2e8f0;
            --sidebar-title: #94a3b8;
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
            --purple: #a78bfa;  --purple-light: rgba(167,139,250,0.15);
            --sidebar-bg: #0f172a;
            --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(59, 130, 246, 0.18);
            --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff;
            --sidebar-border: rgba(255,255,255,0.08);
            --sidebar-title: #94a3b8;
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
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); transition:background 0.4s ease, color 0.4s ease; overflow-x:hidden; }
        .layout { display:flex; min-height:100vh; }

        .sidebar { width:260px; background:var(--sidebar-bg); color:var(--sidebar-text); display:flex; flex-direction:column; position:fixed; top:0; left:0; height:100vh; z-index:100; transition:background 0.4s ease, color 0.4s ease, transform 0.3s ease, border-color 0.4s ease; border-right:1px solid var(--sidebar-border); box-shadow:var(--shadow-sidebar); }
        .sidebar-header { padding:22px 22px 18px; border-bottom:1px solid var(--sidebar-border); display:flex; align-items:center; gap:12px; }
        .sidebar-header img { width:42px; height:42px; object-fit:contain; border-radius:50%; border:2px solid var(--sidebar-border); padding:2px; background:#fff; }
        .sidebar-brand { font-family:'Poppins',sans-serif; font-size:18px; font-weight:700; color:var(--text-primary); line-height:1.2; }
        .sidebar-brand span { color:var(--primary); }
        .sidebar-brand small { display:block; font-size:11px; font-weight:500; color:var(--text-muted); letter-spacing:0.4px; margin-top:2px; text-transform:uppercase; }
        .sidebar-nav { flex:1; padding:18px 12px; overflow-y:auto; }
        .nav-section-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; color:var(--sidebar-title); padding:12px 14px 8px; }
        .nav-item { display:flex; align-items:center; gap:12px; padding:11px 14px; color:var(--sidebar-text); text-decoration:none; font-size:14px; font-weight:500; border-radius:10px; margin-bottom:4px; transition:var(--transition); position:relative; }
        .nav-item i.nav-icon { width:20px; text-align:center; font-size:15px; color:var(--sidebar-text); transition:var(--transition); }
        .nav-item:hover { background:var(--sidebar-hover); color:var(--sidebar-text-active); }
        .nav-item:hover i.nav-icon { color:var(--primary); }
        .nav-item.active { background:var(--sidebar-active); color:var(--sidebar-text-active); }
        .nav-item.active i.nav-icon { color:var(--primary); }
        .nav-item.active::before { content:''; position:absolute; left:0; top:8px; bottom:8px; width:3px; background:var(--primary); border-radius:0 4px 4px 0; }
        .sidebar-profile { border-top:1px solid var(--sidebar-border); padding:16px 18px; display:flex; align-items:center; gap:12px; }
        .sidebar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:15px; font-weight:700; flex-shrink:0; box-shadow:0 4px 12px rgba(59,130,246,0.35); }
        .sidebar-profile-info { flex:1; min-width:0; }
        .sidebar-profile-info .p-name { font-size:13.5px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-profile-info .p-role { font-size:11.5px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-logout { width:34px; height:34px; border-radius:8px; background:var(--danger-light); color:var(--danger); display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:13px; flex-shrink:0; transition:var(--transition); }
        .sidebar-logout:hover { background:var(--danger); color:#fff; }

        .main { flex:1; margin-left:260px; min-height:100vh; display:flex; flex-direction:column; transition:margin-left 0.3s ease; }
        .topbar { background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:16px; position:sticky; top:0; z-index:50; transition:background 0.4s ease, border-color 0.4s ease; }
        .topbar-left { display:flex; align-items:center; gap:14px; }
        .hamburger { display:none; width:40px; height:40px; border-radius:10px; border:1.5px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); align-items:center; justify-content:center; cursor:pointer; font-size:15px; }
        .hamburger:hover { border-color:var(--primary); color:var(--primary); }
        .page-title { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; color:var(--text-primary); letter-spacing:-0.4px; }
        .page-subtitle { font-size:13px; color:var(--text-muted); margin-top:2px; }
        .topbar-actions { display:flex; align-items:center; gap:10px; }
        .topbar-btn { width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); text-decoration:none; position:relative; }
        .topbar-btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .topbar-btn.theme-btn:hover { transform:rotate(20deg); }
        .topbar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:14px; font-weight:700; box-shadow:0 4px 12px rgba(59,130,246,0.3); cursor:pointer; }

        .content { padding:28px; flex:1; }

        .alert { padding:13px 16px; border-radius:10px; margin-bottom:20px; font-size:14px; display:flex; align-items:flex-start; gap:10px; line-height:1.5; }
        .alert-success { background:var(--success-light); color:#059669; border:1px solid rgba(16,185,129,0.25); }
        [data-theme="dark"] .alert-success { color:#34d399; }
        .alert-error { background:var(--danger-light); color:#dc2626; border:1px solid rgba(239,68,68,0.25); }
        [data-theme="dark"] .alert-error { color:#f87171; }

        .page-head { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:22px; flex-wrap:wrap; }
        .page-head h1 { font-family:'Poppins',sans-serif; font-size:22px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .page-head h1 i { color:var(--primary); }
        .page-head .sub { font-size:13.5px; color:var(--text-muted); margin-top:4px; }

        .summaries { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:22px; }
        .sum-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; padding:18px 20px; display:flex; align-items:center; gap:14px; transition:var(--transition); }
        .sum-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); border-color:var(--primary); }
        .sum-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:20px; color:#fff; flex-shrink:0; }
        .sum-icon.blue    { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .sum-icon.purple  { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .sum-icon.green   { background: linear-gradient(135deg, #10b981, #34d399); }
        .sum-icon.orange  { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .sum-info .num { font-family:'Poppins',sans-serif; font-size:24px; font-weight:700; color:var(--text-primary); line-height:1.2; letter-spacing:-0.4px; }
        .sum-info .lbl { font-size:12.5px; color:var(--text-muted); margin-top:3px; font-weight:500; }

        .ann-layout { display:grid; grid-template-columns: 1fr 460px; gap:22px; align-items:start; }
        @media (max-width: 1200px) { .ann-layout { grid-template-columns: 1fr; } }

        .panel { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; overflow:hidden; }
        .panel-head { padding:18px 22px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; gap:12px; background:linear-gradient(135deg, rgba(37,99,235,0.04), rgba(124,58,237,0.02)); }
        .panel-head h2 { font-family:'Poppins',sans-serif; font-size:16px; font-weight:700; display:flex; align-items:center; gap:10px; color:var(--text-primary); }
        .panel-head h2 i { color:var(--primary); }
        .panel-body { padding:20px 22px; }

        .tabs { display:flex; gap:6px; background:var(--bg-alt); padding:5px; border-radius:10px; margin-bottom:16px; }
        .tab { flex:1; padding:9px 14px; border-radius:8px; font-size:13px; font-weight:600; text-align:center; color:var(--text-secondary); text-decoration:none; transition:var(--transition); }
        .tab:hover { color:var(--text-primary); }
        .tab.active { background:var(--bg-card); color:var(--primary); box-shadow:var(--shadow-sm); }

        .filter-row { display:flex; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
        .filter-chip { display:inline-flex; align-items:center; gap:6px; padding:7px 13px; border-radius:8px; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); font-size:12.5px; font-weight:600; text-decoration:none; transition:var(--transition); }
        .filter-chip:hover { border-color:var(--primary); color:var(--primary); }
        .filter-chip.active { background:var(--primary); border-color:var(--primary); color:#fff; }
        .filter-chip .cnt { font-size:11px; font-weight:700; padding:1px 7px; border-radius:50px; background: rgba(0,0,0,0.08); }
        .filter-chip.active .cnt { background: rgba(255,255,255,0.25); }

        .search-box { position:relative; margin-bottom:16px; }
        .search-box i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:13px; pointer-events:none; }
        .search-box input { width:100%; padding:11px 14px 11px 38px; border:1.5px solid var(--border-color); border-radius:10px; background:var(--bg-alt); color:var(--text-primary); font-family:inherit; font-size:13.5px; outline:none; transition:var(--transition); }
        .search-box input:focus { border-color:var(--primary); background:var(--bg-card); box-shadow:0 0 0 3px rgba(37,99,235,0.1); }

        .ann-list { display:flex; flex-direction:column; gap:12px; max-height: 780px; overflow-y:auto; padding-right:6px; }
        .ann-item { background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:16px 18px; transition:var(--transition); cursor:pointer; user-select:none; }
        .ann-item:hover { border-color:var(--primary); transform:translateY(-1px); box-shadow:var(--shadow-md); }
        .ann-item.unread { border-left:4px solid var(--primary); }
        .ann-item.urgent { border-left:4px solid var(--danger); }
        .ann-item.high   { border-left:4px solid var(--warning); }
        .ann-item:not(.unread) { opacity: 0.94; }
        .ann-item:not(.unread) .ann-item-icon { filter: grayscale(0.35); opacity: 0.88; }
        .ann-item.sending { opacity: 0.6; pointer-events: none; }

        .ann-item-head { display:flex; align-items:flex-start; gap:12px; margin-bottom:10px; }
        .ann-item-icon { width:42px; height:42px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:17px; color:#fff; flex-shrink:0; }
        .ann-item-icon.system { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .ann-item-icon.school { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .ann-item-icon.admin  { background: linear-gradient(135deg, #7c3aed, #c4b5fd); }

        .ann-item-body { flex:1; min-width:0; }
        .ann-item-title { font-size:15px; font-weight:700; color:var(--text-primary); margin-bottom:4px; }
        .ann-item-meta { font-size:12px; color:var(--text-muted); display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .ann-item-meta .sender { font-weight:600; color:var(--text-secondary); }

        .ann-item-text { font-size:13.5px; color:var(--text-secondary); line-height:1.65; margin-top:8px; }
        .ann-item-foot { display:flex; align-items:center; gap:8px; margin-top:12px; flex-wrap:wrap; }

        .pill { display:inline-block; padding:3px 10px; border-radius:50px; font-size:11px; font-weight:700; letter-spacing:0.3px; line-height:1.5; }
        .pill.blue   { background: rgba(37,99,235,0.12);  color: #2563eb; }
        .pill.purple { background: rgba(124,58,237,0.12); color: #7c3aed; }
        .pill.green  { background: rgba(16,185,129,0.12); color: #059669; }
        .pill.orange { background: rgba(245,158,11,0.15); color: #b45309; }
        .pill.red    { background: rgba(239,68,68,0.12);  color: #dc2626; }
        .pill.gray   { background: rgba(100,116,139,0.14);color: #475569; }
        .pill.teal   { background: rgba(20,184,166,0.12); color: #0d9488; }
        .pill.violet { background: rgba(124,58,237,0.14); color: #7c3aed; }
        [data-theme="dark"] .pill.blue   { color: #93c5fd; }
        [data-theme="dark"] .pill.purple { color: #a78bfa; }
        [data-theme="dark"] .pill.green  { color: #34d399; }
        [data-theme="dark"] .pill.orange { color: #fbbf24; }
        [data-theme="dark"] .pill.red    { color: #f87171; }
        [data-theme="dark"] .pill.gray   { color: #cbd5e1; }
        [data-theme="dark"] .pill.teal   { color: #2dd4bf; }
        [data-theme="dark"] .pill.violet { color: #c4b5fd; }

        .empty-state { text-align:center; padding:50px 20px; color:var(--text-muted); }
        .empty-state i { font-size:44px; opacity:0.35; margin-bottom:14px; display:block; }
        .empty-state h3 { font-family:'Poppins',sans-serif; font-size:15.5px; font-weight:700; color:var(--text-primary); margin-bottom:6px; }
        .empty-state p { font-size:13px; line-height:1.6; }

        .compose-form .form-group { margin-bottom:16px; }
        .compose-form > .form-group > label { display:block; font-size:12.5px; font-weight:700; color:var(--text-secondary); margin-bottom:7px; text-transform:uppercase; letter-spacing:0.4px; }
        .compose-form > .form-group > label .required { color: #ef4444; }
        .compose-form input[type="text"],
        .compose-form textarea {
            width: 100%; padding: 12px 14px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 14px;
            outline: none;
            transition: var(--transition);
        }
        .compose-form textarea { resize: vertical; min-height: 130px; line-height: 1.55; }
        .compose-form input:focus,
        .compose-form textarea:focus {
            border-color: var(--primary);
            background: var(--bg-card);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }

        .audience-grid { display:grid; grid-template-columns: 1fr 1fr; gap:8px; }
        .audience-pill {
            position: relative;
            display: flex; align-items: center; gap: 10px;
            padding: 11px 12px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            cursor: pointer;
            transition: var(--transition);
            font-size: 13px; font-weight: 600; color: var(--text-primary);
        }
        .audience-pill input { position: absolute; opacity: 0; pointer-events: none; }
        .audience-pill .ap-icon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 13px; flex-shrink: 0; }
        .audience-pill .ap-icon.all       { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .audience-pill .ap-icon.teachers  { background: linear-gradient(135deg, #10b981, #34d399); }
        .audience-pill .ap-icon.parents   { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .audience-pill .ap-icon.students  { background: linear-gradient(135deg, #7c3aed, #a78bfa); }
        .audience-pill .ap-icon.accounts  { background: linear-gradient(135deg, #14b8a6, #2dd4bf); }
        .audience-pill:hover { border-color: var(--primary); }
        .audience-pill.selected { border-color: var(--primary); background: var(--primary-light); }
        [data-theme="dark"] .audience-pill.selected { background: rgba(59,130,246,0.18); }

        .scope-panel {
            margin-top: 12px;
            padding: 14px 16px;
            background: var(--bg-alt);
            border: 1.5px dashed var(--border-color);
            border-radius: 12px;
            display: none;
        }
        .scope-panel.show { display: block; }
        .scope-panel-title {
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px;
            color: var(--text-muted); margin-bottom: 10px;
            display: flex; align-items: center; gap: 6px;
        }
        .scope-modes { display:flex; gap:6px; margin-bottom: 12px; background: var(--bg-card); padding: 4px; border-radius: 8px; border: 1px solid var(--border-color); }
        .scope-mode { flex: 1; text-align: center; padding: 8px 10px; font-size: 12px; font-weight: 700; color: var(--text-secondary); border-radius: 6px; cursor: pointer; transition: var(--transition); }
        .scope-mode:hover { color: var(--text-primary); }
        .scope-mode.active { background: var(--primary); color: #fff; }

        .scope-body { display: none; }
        .scope-body.show { display: block; }
        .scope-body select {
            width: 100%; padding: 10px 12px;
            border: 1.5px solid var(--border-color);
            border-radius: 9px;
            background: var(--bg-card);
            color: var(--text-primary);
            font-family: inherit; font-size: 13.5px;
            outline: none; cursor: pointer;
            transition: var(--transition);
        }
        .scope-body select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }

        .selected-summary {
            margin-top: 10px; padding: 10px 12px;
            background: var(--primary-light);
            border-left: 3px solid var(--primary);
            border-radius: 6px;
            font-size: 12.5px; color: var(--text-secondary);
            line-height: 1.5;
        }
        [data-theme="dark"] .selected-summary { background: rgba(59,130,246,0.14); }
        .selected-summary strong { color: var(--primary); }

        .priority-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:8px; }
        .priority-pill {
            position: relative; display: flex; flex-direction: column; align-items: center; gap: 5px;
            padding: 10px 6px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            background: var(--bg-alt);
            cursor: pointer; transition: var(--transition);
            font-size: 12px; font-weight: 700; color: var(--text-secondary);
        }
        .priority-pill input { position: absolute; opacity: 0; pointer-events: none; }
        .priority-pill i { font-size: 15px; }
        .priority-pill.normal i { color: #64748b; }
        .priority-pill.high i   { color: #f59e0b; }
        .priority-pill.urgent i { color: #ef4444; }
        .priority-pill:hover { border-color: var(--primary); }
        .priority-pill.selected { border-color: var(--primary); background: var(--primary-light); color: var(--text-primary); }
        [data-theme="dark"] .priority-pill.selected { background: rgba(59,130,246,0.18); }

        .char-counter { font-size: 11.5px; color: var(--text-muted); text-align: right; margin-top: 5px; }

        .btn { display:inline-flex; align-items:center; gap:8px; padding:12px 22px; border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; cursor:pointer; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-primary); transition:var(--transition); font-family:'Inter',sans-serif; }
        .btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .btn.primary { background:var(--primary); color:#fff; border-color:var(--primary); box-shadow:0 6px 18px rgba(37,99,235,0.32); width:100%; justify-content:center; }
        .btn.primary:hover { background:var(--primary-dark); color:#fff; }
        .btn:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }

        .compose-note { font-size: 11.5px; color: var(--text-muted); line-height: 1.55; margin-top: 8px; display: flex; gap: 6px; align-items: flex-start; }
        .compose-note i { color: var(--primary); margin-top: 2px; }

        .overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:90; backdrop-filter:blur(2px); }
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
            .audience-grid { grid-template-columns: 1fr; }
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
            <a href="school-subjects.php" class="nav-item"><i class="fas fa-book nav-icon"></i> Subjects</a>
            <div class="nav-section-title">Communication</div>
            <a href="school-announcements.php" class="nav-item active"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>
            <a href="school-events.php" class="nav-item"><i class="fas fa-calendar-alt nav-icon"></i> Events</a>
            <div class="nav-section-title">System</div>
            <a href="school-reports.php" class="nav-item active"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
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
                    <div class="page-title">Announcements</div>
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

            <?php if (isset($_GET['sent']) && $_GET['sent'] === '1'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle" style="margin-top:2px;"></i>
                    <span>
                        Announcement sent successfully
                        <?php if (!empty($_GET['n'])): ?>
                            to <strong><?= (int)$_GET['n']; ?></strong> recipient<?= ((int)$_GET['n'] === 1 ? '' : 's'); ?>.
                        <?php else: ?> .<?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars(urldecode($_GET['error'])); ?></span>
                </div>
            <?php endif; ?>

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-bullhorn"></i> Announcements</h1>
                    <div class="sub">Receive platform announcements and broadcast to your school</div>
                </div>
            </div>

            <div class="summaries">
                <div class="sum-card">
                    <div class="sum-icon blue"><i class="fas fa-inbox"></i></div>
                    <div class="sum-info">
                        <div class="num" id="totalAllCount"><?= $total_all; ?></div>
                        <div class="lbl">Total Received</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon purple"><i class="fas fa-satellite-dish"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_system; ?></div>
                        <div class="lbl">From System</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon green"><i class="fas fa-paper-plane"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_school; ?></div>
                        <div class="lbl">From My School</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon orange"><i class="fas fa-envelope"></i></div>
                    <div class="sum-info">
                        <div class="num" id="unreadCount"><?= $total_unread; ?></div>
                        <div class="lbl">Unread</div>
                    </div>
                </div>
            </div>

            <div class="ann-layout">

                <!-- LEFT: INBOX -->
                <div class="panel">
                    <div class="panel-head">
                        <h2><i class="fas fa-inbox"></i> Inbox</h2>
                    </div>
                    <div class="panel-body">

                        <div class="tabs">
                            <a href="?tab=inbox&filter=<?= htmlspecialchars($filter); ?>&q=<?= urlencode($q); ?>"
                               class="tab <?= $tab === 'inbox' ? 'active' : ''; ?>">
                                <i class="fas fa-inbox"></i> Received
                            </a>
                            <a href="?tab=sent&q=<?= urlencode($q); ?>"
                               class="tab <?= $tab === 'sent' ? 'active' : ''; ?>">
                                <i class="fas fa-paper-plane"></i> Sent by Me
                            </a>
                        </div>

                        <?php if ($tab === 'inbox'): ?>
                            <div class="filter-row">
                                <a href="?tab=inbox&filter=all&q=<?= urlencode($q); ?>"
                                   class="filter-chip <?= $filter === 'all' ? 'active' : ''; ?>">
                                    All <span class="cnt"><?= $total_all; ?></span>
                                </a>
                                <a href="?tab=inbox&filter=system&q=<?= urlencode($q); ?>"
                                   class="filter-chip <?= $filter === 'system' ? 'active' : ''; ?>">
                                    <i class="fas fa-satellite-dish"></i> From System <span class="cnt"><?= $total_system; ?></span>
                                </a>
                                <a href="?tab=inbox&filter=school&q=<?= urlencode($q); ?>"
                                   class="filter-chip <?= $filter === 'school' ? 'active' : ''; ?>">
                                    <i class="fas fa-school"></i> From My School <span class="cnt"><?= $total_school; ?></span>
                                </a>
                            </div>
                        <?php endif; ?>

                        <form method="GET" class="search-box">
                            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab); ?>">
                            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter); ?>">
                            <i class="fas fa-search"></i>
                            <input type="text" name="q" placeholder="Search announcements..." value="<?= htmlspecialchars($q); ?>">
                        </form>

                        <?php if (!empty($announcements)): ?>
                            <div class="ann-list" id="annList">
                                <?php foreach ($announcements as $a):
                                    $origin    = $a['origin'] ?? 'local';
                                    $is_admin  = ($origin === 'admin');
                                    $is_system = ($a['sender_type'] === 'system');

                                    $priority  = $a['priority'] ?? 'normal';
                                    $prio_info = $priority_map[$priority] ?? $priority_map['normal'];
                                    $aud_label = $level_map[$a['audience']] ?? $a['audience'];

                                    $scope_label = '';
                                    $scope_type  = $a['scope_type'] ?? 'all';
                                    if ($scope_type === 'level' && !empty($a['scope_level'])) {
                                        $scope_label = $level_display[$a['scope_level']] ?? $a['scope_level'];
                                    } elseif ($scope_type === 'class' && !empty($a['scope_class'])) {
                                        foreach ($classes as $c) {
                                            if ((int)$c['id'] === (int)$a['scope_class']) { $scope_label = $c['class_name']; break; }
                                        }
                                    } elseif ($scope_type === 'individual') {
                                        $scope_label = 'Specific people';
                                    } elseif ($scope_type === 'school') {
                                        $scope_label = 'This school';
                                    }

                                    $item_class = '';
                                    if (empty($a['is_read'])) $item_class .= ' unread';
                                    if ($priority === 'urgent') $item_class .= ' urgent';
                                    elseif ($priority === 'high') $item_class .= ' high';
                                ?>
                                    <div class="ann-item<?= $item_class; ?>"
                                         data-ann-id="<?= (int)$a['id']; ?>"
                                         data-origin="<?= htmlspecialchars($origin); ?>"
                                         data-read="<?= empty($a['is_read']) ? '0' : '1'; ?>"
                                         title="Click to mark as read">
                                        <div class="ann-item-head">
                                            <div class="ann-item-icon <?= $is_admin ? 'admin' : ($is_system ? 'system' : 'school'); ?>">
                                                <i class="fas <?= $is_admin ? 'fa-shield-halved' : ($is_system ? 'fa-satellite-dish' : 'fa-school'); ?>"></i>
                                            </div>
                                            <div class="ann-item-body">
                                                <div class="ann-item-title">
                                                    <?= htmlspecialchars($a['title']); ?>
                                                    <?php if ($is_admin): ?>
                                                        <span class="pill violet" style="margin-left:6px;font-size:9.5px;">
                                                            <i class="fas fa-shield-halved"></i> From Platform Admin
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ann-item-meta">
                                                    <span class="sender">
                                                        <i class="fas <?= $is_admin || $is_system ? 'fa-shield-halved' : 'fa-user-tie'; ?>" style="font-size:11px;"></i>
                                                        <?= htmlspecialchars($a['sender_name']); ?>
                                                    </span>
                                                    <span>·</span>
                                                    <span><?= date('M j, Y · g:i A', strtotime($a['created_at'])); ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="ann-item-text">
                                            <?= nl2br(htmlspecialchars($a['body'])); ?>
                                        </div>

                                        <div class="ann-item-foot">
                                            <span class="pill <?= $is_admin ? 'violet' : ($is_system ? 'purple' : 'blue'); ?>">
                                                <i class="fas <?= $is_admin || $is_system ? 'fa-satellite-dish' : 'fa-school'; ?>"></i>
                                                <?= $is_admin ? 'System Admin' : ($is_system ? 'System' : 'School'); ?>
                                            </span>
                                            <span class="pill teal">
                                                <i class="fas fa-users"></i>
                                                <?= htmlspecialchars($aud_label); ?>
                                                <?= $scope_label !== '' ? ' · ' . htmlspecialchars($scope_label) : ''; ?>
                                            </span>
                                            <span class="pill <?= $prio_info['class']; ?>">
                                                <?= htmlspecialchars($prio_info['label']); ?>
                                            </span>
                                            <?php if (empty($a['is_read'])): ?>
                                                <span class="pill blue new-pill"><i class="fas fa-circle" style="font-size:6px;"></i> New</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <h3>
                                    <?= $tab === 'sent' ? 'You haven\'t sent any announcements yet' : 'No announcements yet'; ?>
                                </h3>
                                <p>
                                    <?= $tab === 'sent'
                                        ? 'Compose your first announcement on the right to reach your teachers, parents or students.'
                                        : 'When the system admin or your school sends an announcement, it will appear here.'; ?>
                                </p>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- RIGHT: COMPOSE -->
                <div class="panel">
                    <div class="panel-head">
                        <h2><i class="fas fa-paper-plane"></i> Compose Announcement</h2>
                    </div>
                    <div class="panel-body">

                        <form class="compose-form"
                              method="POST"
                              action="school-announcements-send.php"
                              id="composeForm">

                            <div class="form-group">
                                <label for="title">Title <span class="required">*</span></label>
                                <input type="text" id="title" name="title"
                                       maxlength="200" required
                                       placeholder="e.g. End of Term Exams Schedule">
                            </div>

                            <div class="form-group">
                                <label>Send To <span class="required">*</span></label>
                                <div class="audience-grid" id="audienceGrid">
                                    <label class="audience-pill selected" data-aud="all">
                                        <input type="radio" name="audience" value="all" checked>
                                        <span class="ap-icon all"><i class="fas fa-users"></i></span>
                                        Everyone
                                    </label>
                                    <label class="audience-pill" data-aud="teachers">
                                        <input type="radio" name="audience" value="teachers">
                                        <span class="ap-icon teachers"><i class="fas fa-chalkboard-teacher"></i></span>
                                        Teachers
                                    </label>
                                    <label class="audience-pill" data-aud="parents">
                                        <input type="radio" name="audience" value="parents">
                                        <span class="ap-icon parents"><i class="fas fa-user-friends"></i></span>
                                        Parents
                                    </label>
                                    <label class="audience-pill" data-aud="students">
                                        <input type="radio" name="audience" value="students">
                                        <span class="ap-icon students"><i class="fas fa-user-graduate"></i></span>
                                        Students
                                    </label>
                                    <label class="audience-pill" data-aud="accounts" style="grid-column: 1 / -1;">
                                        <input type="radio" name="audience" value="accounts">
                                        <span class="ap-icon accounts"><i class="fas fa-user-shield"></i></span>
                                        System Account Holders
                                    </label>
                                </div>

                                <div class="scope-panel" id="scopePanel">
                                    <div class="scope-panel-title">
                                        <i class="fas fa-crosshairs"></i>
                                        <span id="scopeTitle">Refine target</span>
                                    </div>

                                    <div class="scope-modes" id="scopeModes">
                                        <div class="scope-mode active" data-mode="all">All</div>
                                        <div class="scope-mode" data-mode="level">By Level</div>
                                        <div class="scope-mode" data-mode="class">By Class</div>
                                        <div class="scope-mode" data-mode="individual">Individual</div>
                                    </div>

                                    <div class="scope-body" data-mode="level">
                                        <select name="scope_level" id="scopeLevel">
                                            <option value="">— Choose a level —</option>
                                            <?php foreach ($levels as $lv):
                                                $enum = level_key_to_enum($lv['level_key']);
                                                if (!$enum) continue;
                                                $disp = $level_display[$enum] ?? $lv['level_name'];
                                            ?>
                                                <option value="<?= htmlspecialchars($enum); ?>"><?= htmlspecialchars($disp); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="scope-body" data-mode="class">
                                        <select name="scope_class" id="scopeClass">
                                            <option value="">— Choose a class —</option>
                                            <?php foreach ($classes as $c):
                                                $disp = $level_display[$c['level']] ?? $c['level'];
                                            ?>
                                                <option value="<?= (int)$c['id']; ?>" data-level="<?= htmlspecialchars($c['level']); ?>">
                                                    <?= htmlspecialchars($c['class_name']); ?> — <?= htmlspecialchars($disp); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="scope-body" data-mode="individual">
                                        <select name="scope_individual" id="scopeIndividual">
                                            <option value="">— Choose a person —</option>
                                        </select>
                                        <div class="selected-summary" id="individualHint" style="display:none;"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Priority</label>
                                <div class="priority-grid" id="priorityGrid">
                                    <label class="priority-pill normal selected">
                                        <input type="radio" name="priority" value="normal" checked>
                                        <i class="fas fa-circle-info"></i>
                                        Normal
                                    </label>
                                    <label class="priority-pill high">
                                        <input type="radio" name="priority" value="high">
                                        <i class="fas fa-triangle-exclamation"></i>
                                        High
                                    </label>
                                    <label class="priority-pill urgent">
                                        <input type="radio" name="priority" value="urgent">
                                        <i class="fas fa-bell"></i>
                                        Urgent
                                    </label>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="body">Message <span class="required">*</span></label>
                                <textarea id="body" name="body" required maxlength="3000"
                                          placeholder="Write your announcement..."></textarea>
                                <div class="char-counter"><span id="charCount">0</span> / 3000</div>
                            </div>

                            <div class="compose-note">
                                <i class="fas fa-info-circle"></i>
                                <span>The announcement is saved per recipient so you can track who has seen it.</span>
                            </div>

                            <div style="margin-top:16px;">
                                <button type="submit" class="btn primary" id="sendBtn">
                                    <i class="fas fa-paper-plane"></i> Send Announcement
                                </button>
                            </div>
                        </form>

                    </div>
                </div>

            </div>

        </main>
    </div>
</div>

<script>
    /* Theme */
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

    /* Sidebar */
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

    /* AUDIENCE + SCOPE */
    const audienceGrid = document.getElementById('audienceGrid');
    const scopePanel   = document.getElementById('scopePanel');
    const scopeTitle   = document.getElementById('scopeTitle');
    const scopeModes   = document.getElementById('scopeModes');
    const scopeBodies  = scopePanel.querySelectorAll('.scope-body');
    const indiSelect   = document.getElementById('scopeIndividual');
    const indiHint     = document.getElementById('individualHint');

    const teachersList = <?= json_encode(array_values($teachers)); ?>;
    const parentsList  = <?= json_encode(array_values($parents)); ?>;
    const studentsList = <?= json_encode(array_values($students)); ?>;
    const accountsList = <?= json_encode(array_values($accounts)); ?>;

    let currentAudience = 'all';
    let currentMode     = 'all';

    function buildIndividualOptions(aud) {
        indiSelect.innerHTML = '<option value="">— Choose a person —</option>';
        let list = [];
        let label = '';
        if (aud === 'teachers') { list = teachersList; label = 'Teacher'; }
        else if (aud === 'parents') { list = parentsList; label = 'Parent'; }
        else if (aud === 'students') { list = studentsList; label = 'Student'; }
        else if (aud === 'accounts') { list = accountsList; label = 'Account'; }

        if (list.length === 0) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = '— No ' + label.toLowerCase() + 's available —';
            opt.disabled = true;
            indiSelect.appendChild(opt);
            return;
        }

        list.forEach(function (p) {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.name || p.full_name || ('#' + p.id);
            indiSelect.appendChild(opt);
        });
    }

    function refreshScopeTitle() {
        const a = currentAudience;
        let label = 'Refine target';
        if (a === 'teachers') label = 'Which teachers?';
        else if (a === 'parents') label = 'Which parents?';
        else if (a === 'students') label = 'Which students?';
        else if (a === 'accounts') label = 'Which account holders?';
        else label = 'Everyone in your school';
        scopeTitle.textContent = label;
    }

    function showScopeIfNeeded() {
        if (currentAudience === 'all') {
            scopePanel.classList.remove('show');
            return;
        }
        scopePanel.classList.add('show');
        refreshScopeTitle();
        setMode('all');
        buildIndividualOptions(currentAudience);
    }

    function setMode(mode) {
        currentMode = mode;
        scopeModes.querySelectorAll('.scope-mode').forEach(function (el) {
            el.classList.toggle('active', el.dataset.mode === mode);
        });
        scopeBodies.forEach(function (body) {
            body.classList.toggle('show', body.dataset.mode === mode);
        });
        if (mode !== 'individual') {
            indiHint.style.display = 'none';
        }
    }

    audienceGrid.querySelectorAll('.audience-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            audienceGrid.querySelectorAll('.audience-pill').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');
            const radio = pill.querySelector('input');
            if (radio) radio.checked = true;
            currentAudience = radio ? radio.value : 'all';
            showScopeIfNeeded();
        });
    });

    scopeModes.querySelectorAll('.scope-mode').forEach(function (el) {
        el.addEventListener('click', function () {
            setMode(el.dataset.mode);
        });
    });

    indiSelect.addEventListener('change', function () {
        if (this.value) {
            const opt = this.options[this.selectedIndex];
            indiHint.style.display = 'block';
            indiHint.innerHTML = 'Selected: <strong>' + opt.textContent + '</strong>';
        } else {
            indiHint.style.display = 'none';
        }
    });

    document.querySelectorAll('#priorityGrid .priority-pill').forEach(function (pill) {
        pill.addEventListener('click', function () {
            document.querySelectorAll('#priorityGrid .priority-pill').forEach(p => p.classList.remove('selected'));
            pill.classList.add('selected');
            const radio = pill.querySelector('input');
            if (radio) radio.checked = true;
        });
    });

    (function () {
        const body = document.getElementById('body');
        const counter = document.getElementById('charCount');
        if (!body || !counter) return;
        function update() { counter.textContent = body.value.length; }
        body.addEventListener('input', update);
        update();
    })();

    document.getElementById('composeForm').addEventListener('submit', function (e) {
        const scopeLevel      = document.getElementById('scopeLevel');
        const scopeClass      = document.getElementById('scopeClass');
        const scopeIndividual = document.getElementById('scopeIndividual');

        let scopeTypeInput = document.getElementById('hiddenScopeType');
        if (!scopeTypeInput) {
            scopeTypeInput = document.createElement('input');
            scopeTypeInput.type = 'hidden';
            scopeTypeInput.id = 'hiddenScopeType';
            scopeTypeInput.name = 'scope_type';
            this.appendChild(scopeTypeInput);
        }
        scopeTypeInput.value = (currentAudience === 'all') ? 'all' : currentMode;

        if (currentMode !== 'level')      { scopeLevel.value = ''; }
        if (currentMode !== 'class')      { scopeClass.value = ''; }
        if (currentMode !== 'individual') { scopeIndividual.value = ''; }

        const btn = document.getElementById('sendBtn');
        if (btn.disabled) { e.preventDefault(); return; }
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    });

    showScopeIfNeeded();

    /* ---------- CLICK ANNOUNCEMENT → MARK AS READ (server-confirms-first) ---------- */
    (function () {
        const unreadEl = document.getElementById('unreadCount');

        function decrementUnread() {
            if (!unreadEl) return;
            const n = parseInt(unreadEl.textContent || '0', 10);
            unreadEl.textContent = Math.max(0, n - 1);
        }

        document.querySelectorAll('.ann-item').forEach(function (el) {
            el.addEventListener('click', function () {
                if (el.dataset.read === '1' || !el.classList.contains('unread')) return;
                if (el.dataset.sending === '1') return;
                el.dataset.sending = '1';
                el.classList.add('sending');

                const id     = el.dataset.annId;
                const origin = el.dataset.origin;

                const body = new URLSearchParams();
                body.append('announcement_id', id);
                body.append('origin', origin);

                fetch('school-announcements-mark-read.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: body.toString(),
                    credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || res.ok !== true) {
                        throw new Error(res && res.error ? res.error : 'Server did not confirm');
                    }
                    el.classList.remove('unread', 'sending');
                    el.dataset.read = '1';
                    el.dataset.sending = '0';
                    const p = el.querySelector('.new-pill');
                    if (p) p.remove();
                    decrementUnread();
                })
                .catch(function (err) {
                    el.classList.remove('sending');
                    el.dataset.sending = '0';
                    console.warn('Mark-read failed:', err.message);
                });
            });
        });
    })();
</script>

</body>
</html>