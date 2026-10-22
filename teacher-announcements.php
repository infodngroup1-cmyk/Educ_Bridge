<?php
session_start();
require_once 'db.php';

/*
|--------------------------------------------------------------------------
| AUTH
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['teacher_id']) || empty($_SESSION['teacher_verified'])) {
    header("Location: teacher-login.php");
    exit;
}

$teacher_id    = (int) $_SESSION['teacher_id'];
$teacher_name  = $_SESSION['teacher_name']       ?? 'Teacher';
$school_id     = (int) ($_SESSION['teacher_school_id'] ?? 0);
$school_name   = $_SESSION['teacher_school_name'] ?? 'School';

$initials = "";
foreach (explode(" ", trim($teacher_name)) as $part) {
    if ($part !== "") {
        $initials .= strtoupper($part[0]);
        if (strlen($initials) >= 2) break;
    }
}
if ($initials === "") $initials = "T";
$short_school = function_exists('mb_strimwidth')
    ? mb_strimwidth($school_name, 0, 24, '…')
    : substr($school_name, 0, 24);

if ($school_id <= 0) {
    header("Location: teacher-login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| ENSURE TABLE
|--------------------------------------------------------------------------
*/
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

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$tab    = $_GET['tab'] ?? 'inbox';   // inbox | unread
$q      = trim($_GET['q'] ?? '');

/*
|--------------------------------------------------------------------------
| FETCH ANNOUNCEMENTS FOR THIS TEACHER
|--------------------------------------------------------------------------
| One row per announcement where this teacher is a recipient.
*/
$where  = ["sar.recipient_type = 'teacher'", "sar.recipient_id = ?", "sar.school_id = ?"];
$params = [$teacher_id, $school_id];
$types  = "ii";

if ($tab === 'unread') {
    $where[] = "sar.is_read = 0";
}

if ($q !== '') {
    $where[] = "(sa.title LIKE ? OR sa.body LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like; $params[] = $like;
    $types   .= "ss";
}

$where_sql = implode(" AND ", $where);

$announcements = [];
$stmt = mysqli_prepare($conn, "
    SELECT
        sa.id,
        sa.sender_type,
        sa.sender_name,
        sa.title,
        sa.body,
        sa.priority,
        sa.audience,
        sa.scope_type,
        sa.scope_level,
        sa.scope_class,
        sa.created_at,
        sar.id          AS recipient_row_id,
        sar.is_read,
        sar.read_at
    FROM school_announcements sa
    INNER JOIN school_announcement_recipients sar
            ON sar.announcement_id = sa.id
           AND sar.recipient_type  = 'teacher'
           AND sar.recipient_id    = ?
           AND sar.school_id       = ?
    WHERE sa.school_id = ?
      " . (($tab === 'unread') ? "AND sar.is_read = 0" : "") . "
      " . (($q !== '') ? "AND (sa.title LIKE ? OR sa.body LIKE ?)" : "") . "
    ORDER BY sa.created_at DESC
    LIMIT 200
");
if ($stmt) {
    /* Rebuild the bind list because the JOIN already consumes the first
       two placeholders. */
    $bind_types  = "iii";
    $bind_params = [$teacher_id, $school_id, $school_id];

    if ($q !== '') {
        $like = "%{$q}%";
        $bind_types   .= "ss";
        $bind_params[] = $like;
        $bind_params[] = $like;
    }
    mysqli_stmt_bind_param($stmt, $bind_types, ...$bind_params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) $announcements[] = $row;
    mysqli_stmt_close($stmt);
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

$total_all = acount($conn, "
    SELECT COUNT(*) AS total
    FROM school_announcement_recipients
    WHERE recipient_type = 'teacher' AND recipient_id = ? AND school_id = ?
", "ii", [$teacher_id, $school_id]);

$total_unread = acount($conn, "
    SELECT COUNT(*) AS total
    FROM school_announcement_recipients
    WHERE recipient_type = 'teacher' AND recipient_id = ? AND school_id = ?
      AND is_read = 0
", "ii", [$teacher_id, $school_id]);

$total_school = acount($conn, "
    SELECT COUNT(*) AS total
    FROM school_announcement_recipients sar
    INNER JOIN school_announcements sa ON sa.id = sar.announcement_id
    WHERE sar.recipient_type = 'teacher'
      AND sar.recipient_id = ?
      AND sar.school_id = ?
      AND sa.sender_type = 'school'
", "ii", [$teacher_id, $school_id]);

$total_system = $total_all - $total_school;

$priority_map = [
    'normal' => ['label' => 'Normal', 'class' => 'gray'],
    'high'   => ['label' => 'High',   'class' => 'orange'],
    'urgent' => ['label' => 'Urgent', 'class' => 'red'],
];

$audience_map = [
    'all'      => 'Everyone',
    'teachers' => 'Teachers',
    'parents'  => 'Parents',
    'students' => 'Students',
    'accounts' => 'System Accounts',
];

$level_display = [
    'Nursery' => 'Nursery',
    'Primary' => 'Primary',
    'O_Level' => "O' Level",
    'A_Level' => "A' Level",
];

/* For pretty scope label */
$class_lookup = [];
$cres = mysqli_query($conn, "SELECT id, class_name FROM classes WHERE school_id = $school_id");
if ($cres) { while ($c = mysqli_fetch_assoc($cres)) $class_lookup[(int)$c['id']] = $c['class_name']; }
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
            --purple: #a78bfa;  --purple-light: rgba(167,139,250,0.15);
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
        .nav-item .nav-badge { margin-left:auto; background:var(--sidebar-badge-bg); color:var(--sidebar-badge-fg); font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:50px; line-height:1.5; }
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

        .tabs { display:flex; gap:6px; background:var(--bg-alt); padding:5px; border-radius:10px; margin-bottom:16px; }
        .tab { flex:1; padding:9px 14px; border-radius:8px; font-size:13px; font-weight:600; text-align:center; color:var(--text-secondary); text-decoration:none; transition:var(--transition); }
        .tab:hover { color:var(--text-primary); }
        .tab.active { background:var(--bg-card); color:var(--primary); box-shadow:var(--shadow-sm); }

        .search-box { position:relative; margin-bottom:16px; }
        .search-box i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:13px; pointer-events:none; }
        .search-box input { width:100%; padding:11px 14px 11px 38px; border:1.5px solid var(--border-color); border-radius:10px; background:var(--bg-alt); color:var(--text-primary); font-family:inherit; font-size:13.5px; outline:none; transition:var(--transition); }
        .search-box input:focus { border-color:var(--primary); background:var(--bg-card); box-shadow:0 0 0 3px rgba(37,99,235,0.1); }

        .ann-list { display:flex; flex-direction:column; gap:12px; }
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
        [data-theme="dark"] .pill.blue   { color: #93c5fd; }
        [data-theme="dark"] .pill.purple { color: #a78bfa; }
        [data-theme="dark"] .pill.green  { color: #34d399; }
        [data-theme="dark"] .pill.orange { color: #fbbf24; }
        [data-theme="dark"] .pill.red    { color: #f87171; }
        [data-theme="dark"] .pill.gray   { color: #cbd5e1; }
        [data-theme="dark"] .pill.teal   { color: #2dd4bf; }

        .empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; }
        .empty-state i { font-size:50px; opacity:0.35; margin-bottom:14px; display:block; }
        .empty-state h3 { font-family:'Poppins',sans-serif; font-size:17px; font-weight:700; color:var(--text-primary); margin-bottom:6px; }
        .empty-state p { font-size:14px; line-height:1.6; }

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
            <a href="teacher-dashboard.php" class="nav-item"><i class="fas fa-th-large nav-icon"></i> Dashboard</a>
            <div class="nav-section-title">Teaching</div>

            <a href="teacher-subjects.php" class="nav-item"><i class="fas fa-book nav-icon"></i> Subjects</a>
            <a href="teacher-classes.php" class="nav-item"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
            <a href="teacher-marks.php" class="nav-item"><i class="fas fa-clipboard-list nav-icon"></i> Marks</a>
            <div class="nav-section-title">Communication</div>
            <a href="teacher-announcements.php" class="nav-item active"><i class="fas fa-bullhorn nav-icon"></i> Announcements
                <?php if ($total_unread > 0): ?><span class="nav-badge"><?= $total_unread; ?></span><?php endif; ?>
            </a>
        
            <a href="teacher-events.php" class="nav-item"><i class="fas fa-calendar-alt nav-icon"></i> Events</a>
            <div class="nav-section-title">System</div>
            <a href="teacher-reports.php" class="nav-item"><i class="fas fa-chart-line nav-icon"></i> Reports</a>
            <a href="teacher-settings.php" class="nav-item"><i class="fas fa-cog nav-icon"></i> Settings</a>
        </nav>
        <div class="sidebar-profile">
            <div class="sidebar-avatar"><?= htmlspecialchars($initials); ?></div>
            <div class="sidebar-profile-info">
                <div class="p-name"><?= htmlspecialchars($teacher_name); ?></div>
                <div class="p-role">Teacher · <?= htmlspecialchars($short_school); ?></div>
            </div>
            <a href="teacher-dashboard.php?logout=1" class="sidebar-logout" title="Logout"><i class="fas fa-sign-out-alt"></i></a>
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
                <a href="teacher-announcements.php" class="topbar-btn" title="Announcements"><i class="fas fa-bell"></i></a>
                <div class="topbar-avatar" title="<?= htmlspecialchars($teacher_name); ?>"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-bullhorn"></i> Announcements</h1>
                    <div class="sub">Messages sent to you by your school and by the platform</div>
                </div>
            </div>

            <div class="summaries">
                <div class="sum-card">
                    <div class="sum-icon blue"><i class="fas fa-inbox"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_all; ?></div>
                        <div class="lbl">Total</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon orange"><i class="fas fa-envelope"></i></div>
                    <div class="sum-info">
                        <div class="num" id="unreadCount"><?= $total_unread; ?></div>
                        <div class="lbl">Unread</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon green"><i class="fas fa-school"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_school; ?></div>
                        <div class="lbl">From My School</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon purple"><i class="fas fa-satellite-dish"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $total_system; ?></div>
                        <div class="lbl">From System</div>
                    </div>
                </div>
            </div>

            <div class="tabs">
                <a href="?tab=inbox&q=<?= urlencode($q); ?>" class="tab <?= $tab === 'inbox' ? 'active' : ''; ?>">
                    <i class="fas fa-inbox"></i> All
                </a>
                <a href="?tab=unread&q=<?= urlencode($q); ?>" class="tab <?= $tab === 'unread' ? 'active' : ''; ?>">
                    <i class="fas fa-circle" style="font-size:7px;"></i> Unread (<?= $total_unread; ?>)
                </a>
            </div>

            <form method="GET" class="search-box">
                <input type="hidden" name="tab" value="<?= htmlspecialchars($tab); ?>">
                <i class="fas fa-search"></i>
                <input type="text" name="q" placeholder="Search announcements..." value="<?= htmlspecialchars($q); ?>">
            </form>

            <?php if (!empty($announcements)): ?>
                <div class="ann-list" id="annList">
                    <?php foreach ($announcements as $a):
                        $is_system = ($a['sender_type'] === 'system');
                        $priority  = $a['priority'] ?? 'normal';
                        $prio_info = $priority_map[$priority] ?? $priority_map['normal'];
                        $aud_label = $audience_map[$a['audience']] ?? $a['audience'];

                        $scope_label = '';
                        $scope_type  = $a['scope_type'] ?? 'all';
                        if ($scope_type === 'level' && !empty($a['scope_level'])) {
                            $scope_label = $level_display[$a['scope_level']] ?? $a['scope_level'];
                        } elseif ($scope_type === 'class' && !empty($a['scope_class'])) {
                            $scope_label = $class_lookup[(int)$a['scope_class']] ?? '';
                        } elseif ($scope_type === 'individual') {
                            $scope_label = 'Individual';
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
                             data-read="<?= empty($a['is_read']) ? '0' : '1'; ?>"
                             title="Click to mark as read">
                            <div class="ann-item-head">
                                <div class="ann-item-icon <?= $is_system ? 'system' : 'school'; ?>">
                                    <i class="fas <?= $is_system ? 'fa-satellite-dish' : 'fa-school'; ?>"></i>
                                </div>
                                <div class="ann-item-body">
                                    <div class="ann-item-title"><?= htmlspecialchars($a['title']); ?></div>
                                    <div class="ann-item-meta">
                                        <span class="sender">
                                            <i class="fas <?= $is_system ? 'fa-shield-halved' : 'fa-user-tie'; ?>" style="font-size:11px;"></i>
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
                                <span class="pill <?= $is_system ? 'purple' : 'blue'; ?>">
                                    <i class="fas <?= $is_system ? 'fa-satellite-dish' : 'fa-school'; ?>"></i>
                                    <?= $is_system ? 'System' : 'School'; ?>
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
                    <h3><?= $tab === 'unread' ? 'No unread announcements' : 'No announcements yet'; ?></h3>
                    <p>
                        <?= $tab === 'unread'
                            ? 'You have read every announcement. Great job staying up to date!'
                            : 'When your school or the platform sends an announcement to you, it will appear here.'; ?>
                    </p>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<script>
    /* Theme */
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

    /* Sidebar */
    (function () {
        const h = document.getElementById('hamburger');
        const s = document.getElementById('sidebar');
        const o = document.getElementById('overlay');
        if (!h) return;
        function open()  { s.classList.add('open'); o.classList.add('show'); }
        function close() { s.classList.remove('open'); o.classList.remove('show'); }
        h.addEventListener('click', function () { s.classList.contains('open') ? close() : open(); });
        o.addEventListener('click', close);
    })();

    /* Click announcement → toggle read state on the server */
    (function () {
        const unreadEl = document.getElementById('unreadCount');

        function bumpCounter(delta) {
            if (!unreadEl) return;
            const n = parseInt(unreadEl.textContent || '0', 10);
            unreadEl.textContent = Math.max(0, n + delta);
        }

        document.querySelectorAll('.ann-item').forEach(function (el) {
            el.addEventListener('click', function () {
                if (el.dataset.sending === '1') return;
                el.dataset.sending = '1';
                el.classList.add('sending');

                const id       = el.dataset.annId;
                const wasRead  = el.dataset.read === '1';
                const newState = wasRead ? 0 : 1;   // toggle

                const body = new URLSearchParams();
                body.append('announcement_id', id);
                body.append('state', newState);

                fetch('teacher-announcements-mark-read.php', {
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

                    el.classList.remove('sending');
                    el.dataset.sending = '0';

                    if (newState === 1) {
                        el.classList.remove('unread');
                        el.dataset.read = '1';
                        const p = el.querySelector('.new-pill');
                        if (p) p.remove();
                        bumpCounter(-1);
                    } else {
                        el.classList.add('unread');
                        el.dataset.read = '0';
                        if (!el.querySelector('.new-pill')) {
                            const pill = document.createElement('span');
                            pill.className = 'pill blue new-pill';
                            pill.innerHTML = '<i class="fas fa-circle" style="font-size:6px;"></i> New';
                            el.querySelector('.ann-item-foot').appendChild(pill);
                        }
                        bumpCounter(+1);
                    }
                })
                .catch(function (err) {
                    el.classList.remove('sending');
                    el.dataset.sending = '0';
                    console.warn('Toggle read failed:', err.message);
                });
            });
        });
    })();
</script>

</body>
</html>