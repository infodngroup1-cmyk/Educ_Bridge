<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['student_id']) || empty($_SESSION['student_verified'])) {
    header("Location: student-login.php");
    exit;
}

$student_id  = (int) $_SESSION['student_id'];
$student_code= $_SESSION['student_code'] ?? '';
$student_name= $_SESSION['student_name'] ?? 'Student';
$school_id   = (int) ($_SESSION['student_school_id'] ?? 0);

/* ---------- helpers ---------- */
function col_exists($conn, $table, $col) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $col);
    $r = @mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return ($r && mysqli_num_rows($r) > 0);
}
function table_exists($conn, $table) {
    $t = mysqli_real_escape_string($conn, $table);
    $r = @mysqli_query($conn, "SHOW TABLES LIKE '{$t}'");
    return ($r && mysqli_num_rows($r) > 0);
}

/* ---------- load student ---------- */
$st = mysqli_prepare($conn, "
    SELECT s.*, c.class_name, c.level AS class_level, c.room AS class_room
    FROM students s
    LEFT JOIN classes c ON c.id = s.class_id
    WHERE s.id = ? LIMIT 1
");
$student = null;
if ($st) {
    mysqli_stmt_bind_param($st, "i", $student_id);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    $student = mysqli_fetch_assoc($r);
    mysqli_stmt_close($st);
}

if (!$student) {
    session_destroy();
    header("Location: student-login.php");
    exit;
}

/* ---------- load school ---------- */
$school = null;
$sst = mysqli_prepare($conn, "SELECT id, name, school_code FROM schools WHERE id = ? LIMIT 1");
if ($sst) {
    mysqli_stmt_bind_param($sst, "i", $school_id);
    mysqli_stmt_execute($sst);
    $rr = mysqli_stmt_get_result($sst);
    $school = mysqli_fetch_assoc($rr);
    mysqli_stmt_close($sst);
}

/* ---------- initials for avatar ---------- */
$initials = strtoupper(
    mb_substr($student['first_name'] ?? 'S', 0, 1) .
    mb_substr($student['last_name']  ?? '', 0, 1)
);
if ($initials === '') $initials = 'ST';

/* ---------- subjects + teachers for this student's class ---------- */
$subjects = [];
if (!empty($student['class_id'])) {
    $stmt = mysqli_prepare($conn, "
        SELECT
            s.id, s.subject_name, s.subject_code, s.description,
            sc.credits, sc.week_hours, sc.max_marks,
            t.name AS teacher_name, t.email AS teacher_email, t.phone AS teacher_phone
        FROM subjects s
        INNER JOIN subject_classes sc ON sc.subject_id = s.id AND sc.class_id = ?
        LEFT JOIN subject_class_teachers sct
               ON sct.subject_id = s.id AND sct.class_id = sc.class_id
        LEFT JOIN teachers t ON t.id = sct.teacher_id
        WHERE s.school_id = ? AND s.status = 'active'
        ORDER BY s.subject_name ASC
    ");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $student['class_id'], $school_id);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($r)) $subjects[] = $row;
        mysqli_stmt_close($stmt);
    }
}

/* ---------- class teacher (from classes.class_teacher_id if exists) ---------- */
$class_teacher = null;
if (!empty($student['class_id']) && col_exists($conn, 'classes', 'class_teacher_id')) {
    $stmt = mysqli_prepare($conn, "
        SELECT t.name, t.email, t.phone
        FROM classes c
        LEFT JOIN teachers t ON t.id = c.class_teacher_id
        WHERE c.id = ? LIMIT 1
    ");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $student['class_id']);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        $class_teacher = mysqli_fetch_assoc($r);
        mysqli_stmt_close($stmt);
    }
}

/* ---------- announcements for this student ---------- */
$announcements = [];
if (table_exists($conn, 'school_announcements') && table_exists($conn, 'school_announcement_recipients')) {
    $stmt = mysqli_prepare($conn, "
        SELECT sa.id, sa.sender_name, sa.title, sa.body, sa.priority,
               sa.created_at, sar.is_read
        FROM school_announcements sa
        INNER JOIN school_announcement_recipients sar
                ON sar.announcement_id = sa.id
               AND sar.recipient_type = 'student'
               AND sar.recipient_id   = ?
        WHERE sa.school_id = ?
        ORDER BY sa.created_at DESC
        LIMIT 20
    ");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $student_id, $school_id);
        mysqli_stmt_execute($stmt);
        $r = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($r)) $announcements[] = $row;
        mysqli_stmt_close($stmt);
    }
}

/* ---------- marks (if a marks table exists) ---------- */
$marks = [];
$marks_available = false;
$marks_table = null;
foreach (['student_marks','marks','exam_marks'] as $candidate) {
    if (table_exists($conn, $candidate)) { $marks_table = $candidate; break; }
}
if ($marks_table && col_exists($conn, $marks_table, 'student_id')) {
    $marks_available = true;
    $subject_col = col_exists($conn, $marks_table, 'subject_id') ? 'subject_id' : null;
    $score_col = null;
    foreach (['mark','score','marks_obtained','value'] as $c) {
        if (col_exists($conn, $marks_table, $c)) { $score_col = $c; break; }
    }
    if ($subject_col && $score_col) {
        $stmt = mysqli_prepare($conn, "
            SELECT m.`{$subject_col}` AS subject_id, m.`{$score_col}` AS score,
                   s.subject_name
            FROM `{$marks_table}` m
            LEFT JOIN subjects s ON s.id = m.`{$subject_col}`
            WHERE m.student_id = ?
            ORDER BY m.id DESC
            LIMIT 100
        ");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $student_id);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($r)) $marks[] = $row;
            mysqli_stmt_close($stmt);
        }
    }
}

$is_active = ($student['status'] === 'active');

$priority_colors = [
    'normal' => '#64748b',
    'high'   => '#f59e0b',
    'urgent' => '#ef4444',
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard | Educ Bridge</title>
    <link rel="icon" type="image/png" href="logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary:#2563eb; --primary-dark:#1d4ed8; --primary-light:#dbeafe;
            --success:#10b981; --warning:#f59e0b; --danger:#ef4444; --info:#0ea5e9;
            --purple:#7c3aed;
            --bg-body:#f1f5f9; --bg-card:#ffffff; --bg-alt:#f8fafc;
            --text-primary:#0f172a; --text-secondary:#475569; --text-muted:#64748b;
            --border-color:#e2e8f0;
            --shadow-lg:0 20px 45px rgba(0,0,0,0.08);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.07);
            --transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
        }
        [data-theme="dark"] {
            --primary:#3b82f6; --primary-dark:#2563eb; --primary-light:#1e3a5f;
            --success:#34d399; --warning:#fbbf24; --danger:#f87171; --info:#38bdf8;
            --purple:#a78bfa;
            --bg-body:#0b1120; --bg-card:#1e293b; --bg-alt:#1a2332;
            --text-primary:#f1f5f9; --text-secondary:#cbd5e1; --text-muted:#94a3b8;
            --border-color:#334155;
            --shadow-lg:0 20px 45px rgba(0,0,0,0.5);
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.4);
        }
        *,*::before,*::after { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:var(--bg-body); color:var(--text-primary); padding:24px 16px 60px; position:relative; min-height:100vh; }
        body::before { content:''; position:fixed; inset:0; background: radial-gradient(circle at 10% 10%, rgba(37,99,235,0.08), transparent 45%), radial-gradient(circle at 90% 90%, rgba(124,58,237,0.08), transparent 45%); pointer-events:none; z-index:0; }

        .theme-toggle { position:fixed; top:20px; right:20px; width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; z-index:20; }
        .theme-toggle:hover { color:var(--primary); border-color:var(--primary); }

        .wrap { width:100%; max-width:1100px; margin:0 auto; position:relative; z-index:1; }

        /* ----- Header bar ----- */
        .topbar {
            display:flex; align-items:center; justify-content:space-between;
            gap:16px; flex-wrap:wrap;
            padding:18px 22px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 22px;
        }
        .topbar-left { display:flex; align-items:center; gap:14px; min-width:0; }
        .avatar {
            width: 60px; height: 60px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--primary), var(--purple));
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 22px; font-weight: 700;
            letter-spacing: 1px;
            flex-shrink: 0;
            overflow: hidden;
            box-shadow: 0 8px 22px rgba(37,99,235,0.35);
        }
        .avatar img { width:100%; height:100%; object-fit:cover; }
        .topbar-info { min-width:0; }
        .topbar-info .nm {
            font-family: 'Poppins', sans-serif;
            font-size: 17px; font-weight: 700;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .topbar-info .meta {
            font-size: 12.5px; color: var(--text-muted);
            display: flex; align-items: center; gap: 8px; margin-top: 3px; flex-wrap: wrap;
        }
        .topbar-info .meta .tag {
            display: inline-flex; align-items: center; gap: 4px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            padding: 2px 9px; border-radius: 50px;
            font-weight: 600;
            font-family: 'Courier New', monospace;
        }
        .topbar-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .status-badge {
            display:inline-flex; align-items:center; gap:7px;
            padding: 7px 14px; border-radius: 50px;
            font-size: 12px; font-weight: 700;
        }
        .status-badge.active { background: rgba(16,185,129,0.12); color:#059669; }
        [data-theme="dark"] .status-badge.active { color: #34d399; }
        .status-badge.inactive { background: rgba(245,158,11,0.14); color:#b45309; }
        [data-theme="dark"] .status-badge.inactive { color:#fbbf24; }
        .status-badge .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }

        .btn {
            display:inline-flex; align-items:center; gap:8px;
            padding: 11px 18px; border-radius: 10px;
            font-family: inherit; font-size: 13.5px; font-weight: 600;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card); color: var(--text-primary);
            cursor: pointer; text-decoration: none;
            transition: var(--transition);
        }
        .btn:hover { border-color: var(--primary); color: var(--primary); }
        .btn.logout { color: var(--danger); border-color: rgba(239,68,68,0.35); }
        .btn.logout:hover { background: var(--danger); color: #fff; border-color: var(--danger); }

        /* ----- Pending banner ----- */
        .pending-banner {
            display:flex; align-items:flex-start; gap: 14px;
            padding: 16px 20px;
            background: rgba(245,158,11,0.10);
            border: 1.5px solid rgba(245,158,11,0.35);
            border-radius: 14px;
            margin-bottom: 22px;
        }
        [data-theme="dark"] .pending-banner { background: rgba(245,158,11,0.15); }
        .pending-banner i { color: var(--warning); font-size: 22px; margin-top: 2px; flex-shrink: 0; }
        .pending-banner .pb-body { flex: 1; }
        .pending-banner .pb-title { font-family:'Poppins',sans-serif; font-weight: 700; font-size: 14.5px; color:#b45309; margin-bottom: 4px; }
        [data-theme="dark"] .pending-banner .pb-title { color: #fbbf24; }
        .pending-banner .pb-desc { font-size: 13px; color: var(--text-secondary); line-height: 1.6; }

        /* ----- Layout grid ----- */
        .grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; align-items: start; }
        @media (max-width: 950px) { .grid { grid-template-columns: 1fr; } }

        .card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 20px; }
        .card:last-child { margin-bottom: 0; }
        .card-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; gap: 12px;
            background: linear-gradient(135deg, rgba(37,99,235,0.04), rgba(124,58,237,0.02));
        }
        .card-head h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 15.5px; font-weight: 700;
            display: flex; align-items: center; gap: 10px;
        }
        .card-head h2 i { color: var(--primary); }
        .card-head .count {
            margin-left: auto;
            background: var(--primary-light); color: var(--primary);
            font-size: 11px; font-weight: 700;
            padding: 3px 10px; border-radius: 50px;
        }
        [data-theme="dark"] .card-head .count { background: rgba(59,130,246,0.18); color: #93c5fd; }
        .card-body { padding: 18px 20px; }
        .card-body.pad-0 { padding: 0; }

        /* ----- Profile block ----- */
        .profile-row {
            display: flex; align-items: center; gap: 16px;
            padding: 18px 20px;
        }
        .profile-avatar-lg {
            width: 84px; height: 84px;
            border-radius: 22px;
            background: linear-gradient(135deg, var(--primary), var(--purple));
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 32px; font-weight: 700; letter-spacing: 2px;
            flex-shrink: 0;
            box-shadow: 0 12px 30px rgba(37,99,235,0.35);
            overflow: hidden;
        }
        .profile-avatar-lg img { width: 100%; height: 100%; object-fit: cover; }
        .profile-details { flex: 1; min-width: 0; }
        .profile-details .pn {
            font-family: 'Poppins', sans-serif;
            font-size: 18px; font-weight: 700;
            margin-bottom: 6px;
        }
        .profile-details .pm {
            font-size: 12.5px; color: var(--text-muted);
            display: flex; flex-wrap: wrap; gap: 8px;
        }
        .profile-details .pm .tag {
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            padding: 3px 10px; border-radius: 50px;
            font-weight: 600;
        }

        .info-pairs {
            display: grid; grid-template-columns: 1fr 1fr;
            gap: 12px;
            padding: 16px 20px 20px;
            border-top: 1px dashed var(--border-color);
        }
        @media (max-width: 620px) { .info-pairs { grid-template-columns: 1fr; } }
        .info-pairs .ip {
            padding: 10px 12px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 10px;
        }
        .info-pairs .ip .il { font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin-bottom: 3px; }
        .info-pairs .ip .iv { font-size: 13.5px; font-weight: 600; word-break: break-word; }

        /* ----- Subject cards ----- */
        .subject-list { padding: 14px 20px 20px; display: flex; flex-direction: column; gap: 10px; }
        .subject-item {
            padding: 14px 16px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            transition: var(--transition);
        }
        .subject-item:hover { border-color: var(--primary); box-shadow: var(--shadow-md); }
        .subject-item .si-top {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 12px; margin-bottom: 8px;
        }
        .subject-item .si-name {
            font-family: 'Poppins', sans-serif;
            font-size: 14.5px; font-weight: 700;
            display: flex; align-items: center; gap: 10px;
        }
        .subject-item .si-avatar {
            width: 34px; height: 34px;
            border-radius: 10px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700;
            flex-shrink: 0;
        }
        .subject-item .si-code {
            font-size: 11.5px; color: var(--primary);
            font-family: 'Courier New', monospace;
            font-weight: 700;
            letter-spacing: 0.4px;
            margin-top: 3px;
            margin-left: 44px;
        }
        .subject-item .si-teacher {
            font-size: 12px; color: var(--text-muted);
            display: flex; align-items: center; gap: 6px;
            margin-left: 44px; margin-bottom: 10px;
        }
        .subject-item .si-meta {
            display: grid; grid-template-columns: 1fr 1fr 1fr;
            gap: 8px;
        }
        @media (max-width: 520px) { .subject-item .si-meta { grid-template-columns: 1fr 1fr; } }
        .subject-item .si-meta .mm {
            text-align: center;
            padding: 8px 6px;
            background: var(--bg-card);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        .subject-item .si-meta .mm .ml { font-size: 10px; text-transform: uppercase; color: var(--text-muted); font-weight: 700; letter-spacing: 0.5px; }
        .subject-item .si-meta .mm .mv { font-family:'Poppins',sans-serif; font-size: 14px; font-weight: 700; color: var(--primary); }

        /* ----- Announcements ----- */
        .ann-list { padding: 14px 20px 20px; display: flex; flex-direction: column; gap: 10px; }
        .ann-item {
            padding: 14px 16px;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            background: var(--bg-alt);
            position: relative;
        }
        .ann-item.unread { border-left: 4px solid var(--primary); }
        .ann-item .an-title { font-family:'Poppins',sans-serif; font-size: 14px; font-weight: 700; margin-bottom: 5px; }
        .ann-item .an-meta { font-size: 11.5px; color: var(--text-muted); display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 8px; }
        .ann-item .an-text { font-size: 13px; color: var(--text-secondary); line-height: 1.6; }

        /* ----- Marks ----- */
        .marks-table { width: 100%; border-collapse: collapse; }
        .marks-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); padding: 10px 16px; background: var(--bg-alt); font-weight: 700; }
        .marks-table td { padding: 12px 16px; font-size: 13.5px; border-bottom: 1px solid var(--border-color); }
        .marks-table tr:last-child td { border-bottom: none; }
        .marks-table .mark-val {
            font-family: 'Courier New', monospace;
            font-weight: 700;
            color: var(--primary);
            font-size: 15px;
        }

        .empty {
            padding: 40px 20px; text-align: center;
            color: var(--text-muted);
        }
        .empty i { font-size: 36px; opacity: 0.35; margin-bottom: 12px; display: block; }
        .empty h4 { font-family:'Poppins',sans-serif; font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 6px; }
        .empty p { font-size: 12.5px; line-height: 1.55; }

        .pill {
            display: inline-block; padding: 3px 10px; border-radius: 50px;
            font-size: 10.5px; font-weight: 700; line-height: 1.5;
        }
        .pill.gray   { background: rgba(100,116,139,0.14); color: #475569; }
        .pill.orange { background: rgba(245,158,11,0.15); color: #b45309; }
        .pill.red    { background: rgba(239,68,68,0.12); color: #dc2626; }
        .pill.blue   { background: rgba(37,99,235,0.12); color: #2563eb; }
        [data-theme="dark"] .pill.gray   { color: #cbd5e1; }
        [data-theme="dark"] .pill.orange { color: #fbbf24; }
        [data-theme="dark"] .pill.red    { color: #f87171; }
        [data-theme="dark"] .pill.blue   { color: #93c5fd; }

        .foot { text-align: center; margin-top: 24px; font-size: 13px; color: var(--text-muted); }
    </style>
</head>
<body>

<button class="theme-toggle" id="themeToggle"><i class="fas fa-moon"></i></button>

<div class="wrap">

    <!-- ================= TOP BAR ================= -->
    <div class="topbar">
        <div class="topbar-left">
            <div class="avatar">
                <?php if (!empty($student['avatar'])): ?>
                    <img src="<?= htmlspecialchars($student['avatar']); ?>" alt="">
                <?php else: ?>
                    <?= htmlspecialchars($initials); ?>
                <?php endif; ?>
            </div>
            <div class="topbar-info">
                <div class="nm"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                <div class="meta">
                    <span class="tag"><i class="fas fa-hashtag"></i> <?= htmlspecialchars($student['student_code']); ?></span>
                    <?php if (!empty($student['class_name'])): ?>
                        <span><i class="fas fa-layer-group"></i> <?= htmlspecialchars($student['class_name']); ?></span>
                    <?php endif; ?>
                    <span><i class="fas fa-school"></i> <?= htmlspecialchars($school['name'] ?? 'School'); ?></span>
                </div>
            </div>
        </div>
        <div class="topbar-right">
            <span class="status-badge <?= $is_active ? 'active' : 'inactive'; ?>">
                <span class="dot"></span> <?= $is_active ? 'Active Student' : 'Pending Activation'; ?>
            </span>
            <a href="student-logout.php" class="btn logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- ================= PENDING BANNER ================= -->
    <?php if (!$is_active): ?>
        <div class="pending-banner">
            <i class="fas fa-circle-exclamation"></i>
            <div class="pb-body">
                <div class="pb-title">Account Not Yet Activated</div>
                <div class="pb-desc">
                    Your registration is complete, but the school hasn't activated your account yet.
                    Please visit the school office with your student code
                    (<strong><?= htmlspecialchars($student['student_code']); ?></strong>)
                    to confirm enrollment. Once activated, you'll be able to appear in class lists, receive marks, and get announcements.
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ================= MAIN GRID ================= -->
    <div class="grid">

        <!-- ============ LEFT COLUMN ============ -->
        <div>

            <!-- PROFILE -->
            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-user-circle"></i> My Profile</h2>
                </div>
                <div class="profile-row">
                    <div class="profile-avatar-lg">
                        <?php if (!empty($student['avatar'])): ?>
                            <img src="<?= htmlspecialchars($student['avatar']); ?>" alt="">
                        <?php else: ?>
                            <?= htmlspecialchars($initials); ?>
                        <?php endif; ?>
                    </div>
                    <div class="profile-details">
                        <div class="pn"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                        <div class="pm">
                            <span class="tag"><i class="fas fa-hashtag"></i> <?= htmlspecialchars($student['student_code']); ?></span>
                            <span class="tag"><i class="fas fa-<?= $student['sex'] === 'Female' ? 'venus' : 'mars'; ?>"></i> <?= htmlspecialchars($student['sex']); ?></span>
                            <span class="tag"><i class="fas fa-calendar"></i> <?= htmlspecialchars(date('M j, Y', strtotime($student['dob']))); ?></span>
                        </div>
                    </div>
                </div>
                <div class="info-pairs">
                    <div class="ip">
                        <div class="il">Class</div>
                        <div class="iv"><?= htmlspecialchars($student['class_name'] ?? '—'); ?></div>
                    </div>
                    <div class="ip">
                        <div class="il">Level</div>
                        <div class="iv"><?= htmlspecialchars(str_replace('_', "' ", $student['level'] ?? '—')); ?></div>
                    </div>
                    <div class="ip">
                        <div class="il">Phone</div>
                        <div class="iv"><?= htmlspecialchars($student['phone'] ?: '—'); ?></div>
                    </div>
                    <div class="ip">
                        <div class="il">Email</div>
                        <div class="iv"><?= htmlspecialchars($student['email'] ?: '—'); ?></div>
                    </div>
                    <div class="ip">
                        <div class="il">Address</div>
                        <div class="iv"><?= htmlspecialchars(trim(($student['sector'] ?? '') . ', ' . ($student['district'] ?? '') . ', ' . ($student['province'] ?? ''), ', ')); ?></div>
                    </div>
                    <div class="ip">
                        <div class="il">Preferred Contact</div>
                        <div class="iv"><?= $student['preferred_contact'] === 'email' ? 'Email' : 'Phone'; ?></div>
                    </div>
                </div>
            </div>

            <!-- SUBJECTS + TEACHERS -->
            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-book"></i> My Subjects</h2>
                    <span class="count"><?= count($subjects); ?></span>
                </div>
                <?php if (!empty($subjects)): ?>
                    <div class="subject-list">
                        <?php foreach ($subjects as $sub):
                            $subj_initials = strtoupper(substr($sub['subject_name'], 0, 2));
                        ?>
                            <div class="subject-item">
                                <div class="si-top">
                                    <div class="si-name">
                                        <span class="si-avatar"><?= htmlspecialchars($subj_initials); ?></span>
                                        <span><?= htmlspecialchars($sub['subject_name']); ?></span>
                                    </div>
                                </div>
                                <?php if (!empty($sub['subject_code'])): ?>
                                    <div class="si-code"><?= htmlspecialchars($sub['subject_code']); ?></div>
                                <?php endif; ?>
                                <div class="si-teacher">
                                    <i class="fas fa-chalkboard-teacher"></i>
                                    <?= htmlspecialchars($sub['teacher_name'] ?? 'No teacher assigned yet'); ?>
                                </div>
                                <div class="si-meta">
                                    <div class="mm">
                                        <div class="ml">Credits</div>
                                        <div class="mv"><?= htmlspecialchars($sub['credits'] ?? '—'); ?></div>
                                    </div>
                                    <div class="mm">
                                        <div class="ml">Week Hours</div>
                                        <div class="mv"><?= htmlspecialchars($sub['week_hours'] ?? '—'); ?></div>
                                    </div>
                                    <div class="mm">
                                        <div class="ml">Max Marks</div>
                                        <div class="mv"><?= htmlspecialchars($sub['max_marks'] ?? '—'); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <i class="fas fa-book-open"></i>
                        <h4>No subjects yet</h4>
                        <p><?= $is_active ? 'Your school hasn\'t assigned subjects to your class yet.' : 'Once your account is activated, your subjects will appear here.'; ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- MARKS -->
            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-clipboard-list"></i> My Marks</h2>
                    <span class="count"><?= count($marks); ?></span>
                </div>
                <div class="card-body pad-0">
                    <?php if (!empty($marks)): ?>
                        <table class="marks-table">
                            <thead>
                                <tr>
                                    <th>Subject</th>
                                    <th>Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($marks as $m): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($m['subject_name'] ?? '—'); ?></td>
                                        <td class="mark-val"><?= htmlspecialchars($m['score']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty">
                            <i class="fas fa-clipboard"></i>
                            <h4>No marks yet</h4>
                            <p><?= $marks_available ? 'Your teachers haven\'t entered any marks yet.' : 'Marks will be shown here once your school starts recording them.'; ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ============ RIGHT COLUMN ============ -->
        <div>

            <!-- CLASS & TEACHER -->
            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-layer-group"></i> My Class</h2>
                </div>
                <div class="card-body">
                    <?php if (!empty($student['class_name'])): ?>
                        <div class="info-pairs" style="padding: 0; border-top: none; grid-template-columns: 1fr;">
                            <div class="ip">
                                <div class="il">Class</div>
                                <div class="iv"><?= htmlspecialchars($student['class_name']); ?></div>
                            </div>
                            <div class="ip">
                                <div class="il">Level</div>
                                <div class="iv"><?= htmlspecialchars(str_replace('_', "' ", $student['class_level'] ?? '—')); ?></div>
                            </div>
                            <?php if (!empty($student['class_room'])): ?>
                                <div class="ip">
                                    <div class="il">Room</div>
                                    <div class="iv"><?= htmlspecialchars($student['class_room']); ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($class_teacher['name'])): ?>
                                <div class="ip">
                                    <div class="il">Class Teacher</div>
                                    <div class="iv"><?= htmlspecialchars($class_teacher['name']); ?></div>
                                </div>
                                <?php if (!empty($class_teacher['phone'])): ?>
                                    <div class="ip">
                                        <div class="il">Teacher Phone</div>
                                        <div class="iv"><?= htmlspecialchars($class_teacher['phone']); ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty">
                            <i class="fas fa-layer-group"></i>
                            <h4>No class assigned</h4>
                            <p>Your class will appear once the school assigns you one.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ANNOUNCEMENTS -->
            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-bullhorn"></i> Announcements</h2>
                    <span class="count"><?= count($announcements); ?></span>
                </div>
                <?php if (!empty($announcements)): ?>
                    <div class="ann-list">
                        <?php foreach ($announcements as $a):
                            $prio = $a['priority'] ?? 'normal';
                            $prio_pill = 'gray';
                            if ($prio === 'high') $prio_pill = 'orange';
                            if ($prio === 'urgent') $prio_pill = 'red';
                        ?>
                            <div class="ann-item <?= empty($a['is_read']) ? 'unread' : ''; ?>">
                                <div class="an-title"><?= htmlspecialchars($a['title']); ?></div>
                                <div class="an-meta">
                                    <i class="fas fa-user-tie"></i> <?= htmlspecialchars($a['sender_name']); ?>
                                    <span>·</span>
                                    <i class="fas fa-clock"></i> <?= htmlspecialchars(date('M j, Y', strtotime($a['created_at']))); ?>
                                    <?php if ($prio !== 'normal'): ?>
                                        <span class="pill <?= $prio_pill; ?>"><?= ucfirst($prio); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="an-text"><?= nl2br(htmlspecialchars(mb_strimwidth($a['body'], 0, 220, '…'))); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <i class="fas fa-bullhorn"></i>
                        <h4>No announcements</h4>
                        <p>Messages from your school and the platform will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    </div>

    <div class="foot">Powered by Educ Bridge</div>
</div>

<script>
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
</script>

</body>
</html>