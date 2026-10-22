<?php
session_start();
require_once 'db.php';

if (empty($_SESSION['parent_verified']) || empty($_SESSION['parent_student_id'])) {
    header("Location: parent-login.php");
    exit;
}

$student_id  = (int) $_SESSION['parent_student_id'];
$student_code= $_SESSION['parent_student_code'] ?? '';
$school_id   = (int) ($_SESSION['parent_school_id'] ?? 0);
$roles       = $_SESSION['parent_roles'] ?? [];

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
$student = null;
$st = mysqli_prepare($conn, "
    SELECT s.*, c.class_name, c.level AS class_level, c.room AS class_room
    FROM students s
    LEFT JOIN classes c ON c.id = s.class_id
    WHERE s.id = ? LIMIT 1
");
if ($st) {
    mysqli_stmt_bind_param($st, "i", $student_id);
    mysqli_stmt_execute($st);
    $r = mysqli_stmt_get_result($st);
    $student = mysqli_fetch_assoc($r);
    mysqli_stmt_close($st);
}
if (!$student) {
    session_destroy();
    header("Location: parent-login.php");
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

/* ---------- subjects + teachers ---------- */
$subjects = [];
if (!empty($student['class_id'])) {
    $stmt = mysqli_prepare($conn, "
        SELECT
            s.id, s.subject_name, s.subject_code,
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

/* ---------- marks ---------- */
$marks = [];
$marks_table = null;
foreach (['student_marks','marks','exam_marks'] as $candidate) {
    if (table_exists($conn, $candidate) && col_exists($conn, $candidate, 'student_id')) {
        $marks_table = $candidate; break;
    }
}
if ($marks_table) {
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

/* ---------- class teacher ---------- */
$class_teacher = null;
if (!empty($student['class_id']) && col_exists($conn, 'classes', 'class_teacher_id')) {
    $stmt = mysqli_prepare($conn, "
        SELECT t.name, t.email, t.phone
        FROM classes c LEFT JOIN teachers t ON t.id = c.class_teacher_id
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

/* ---------- initial for student avatar ---------- */
$student_initials = strtoupper(
    mb_substr($student['first_name'] ?? 'S', 0, 1) .
    mb_substr($student['last_name']  ?? '', 0, 1)
);
if ($student_initials === '') $student_initials = 'ST';

/* ---------- parent / guardian cards ---------- */
$parents = [
    'father'   => [
        'label' => 'Father',
        'icon'  => 'fa-user-tie',
        'name'  => $student['father_name']   ?? '',
        'phone' => $student['father_phone']  ?? '',
        'email' => $student['father_email']  ?? '',
        'color' => 'linear-gradient(135deg,#2563eb,#3b82f6)',
    ],
    'mother'   => [
        'label' => 'Mother',
        'icon'  => 'fa-user',
        'name'  => $student['mother_name']   ?? '',
        'phone' => $student['mother_phone']  ?? '',
        'email' => $student['mother_email']  ?? '',
        'color' => 'linear-gradient(135deg,#ec4899,#f472b6)',
    ],
    'guardian' => [
        'label' => 'Guardian',
        'icon'  => 'fa-user-shield',
        'name'  => $student['guardian_name']   ?? '',
        'phone' => $student['guardian_phone']  ?? '',
        'email' => $student['guardian_email']  ?? '',
        'color' => 'linear-gradient(135deg,#f59e0b,#fbbf24)',
    ],
];

$is_active = ($student['status'] === 'active');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Dashboard | Educ Bridge</title>
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
        body::before { content:''; position:fixed; inset:0; background: radial-gradient(circle at 10% 10%, rgba(16,185,129,0.08), transparent 45%), radial-gradient(circle at 90% 90%, rgba(37,99,235,0.08), transparent 45%); pointer-events:none; z-index:0; }

        .theme-toggle { position:fixed; top:20px; right:20px; width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; z-index:20; }
        .theme-toggle:hover { color:var(--primary); border-color:var(--primary); }

        .wrap { width:100%; max-width:1100px; margin:0 auto; position:relative; z-index:1; }

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
            box-shadow: 0 8px 22px rgba(37,99,235,0.35);
        }
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
            background: var(--bg-alt); border: 1px solid var(--border-color);
            padding: 2px 9px; border-radius: 50px; font-weight: 600;
            font-family: 'Courier New', monospace;
        }
        .topbar-right { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .status-badge { display:inline-flex; align-items:center; gap:7px; padding: 7px 14px; border-radius: 50px; font-size: 12px; font-weight: 700; }
        .status-badge.active { background: rgba(16,185,129,0.12); color:#059669; }
        [data-theme="dark"] .status-badge.active { color: #34d399; }
        .status-badge.inactive { background: rgba(245,158,11,0.14); color:#b45309; }
        [data-theme="dark"] .status-badge.inactive { color:#fbbf24; }
        .status-badge .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }

        .btn { display:inline-flex; align-items:center; gap:8px; padding: 11px 18px; border-radius: 10px; font-family: inherit; font-size: 13.5px; font-weight: 600; border: 1.5px solid var(--border-color); background: var(--bg-card); color: var(--text-primary); cursor: pointer; text-decoration: none; transition: var(--transition); }
        .btn:hover { border-color: var(--primary); color: var(--primary); }
        .btn.logout { color: var(--danger); border-color: rgba(239,68,68,0.35); }
        .btn.logout:hover { background: var(--danger); color: #fff; border-color: var(--danger); }

        .grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; align-items: start; }
        @media (max-width: 950px) { .grid { grid-template-columns: 1fr; } }

        .card { background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; box-shadow: var(--shadow-sm); overflow: hidden; margin-bottom: 20px; }
        .card:last-child { margin-bottom: 0; }
        .card-head { padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 12px; background: linear-gradient(135deg, rgba(37,99,235,0.04), rgba(124,58,237,0.02)); }
        .card-head h2 { font-family: 'Poppins', sans-serif; font-size: 15.5px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .card-head h2 i { color: var(--primary); }
        .card-head .count { margin-left: auto; background: var(--primary-light); color: var(--primary); font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 50px; }
        [data-theme="dark"] .card-head .count { background: rgba(59,130,246,0.18); color: #93c5fd; }
        .card-body { padding: 18px 20px; }
        .card-body.pad-0 { padding: 0; }

        /* ---------- Parent cards ---------- */
        .parent-grid { display:grid; grid-template-columns: repeat(3, 1fr); gap:14px; padding: 18px 20px 20px; }
        @media (max-width: 780px) { .parent-grid { grid-template-columns: 1fr; } }

        .parent-card {
            padding: 16px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            position: relative;
        }
        .parent-card.highlighted { border-color: var(--primary); background: linear-gradient(180deg, rgba(37,99,235,0.04), var(--bg-alt)); }
        [data-theme="dark"] .parent-card.highlighted { background: linear-gradient(180deg, rgba(59,130,246,0.10), var(--bg-alt)); }

        .parent-card .pc-top { display:flex; align-items:center; gap:12px; margin-bottom: 14px; }
        .parent-card .pc-avatar {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 18px;
            flex-shrink: 0;
        }
        .parent-card .pc-title { flex:1; min-width: 0; }
        .parent-card .pc-role { font-size: 10.5px; text-transform: uppercase; font-weight: 700; letter-spacing: 0.6px; color: var(--text-muted); }
        .parent-card .pc-name { font-family:'Poppins',sans-serif; font-size: 14.5px; font-weight: 700; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .parent-card .pc-name.empty { color: var(--text-muted); font-style: italic; font-weight: 500; font-size: 13px; }

        .parent-card .pc-line { display:flex; align-items:center; gap: 8px; font-size: 12.5px; color: var(--text-secondary); padding: 6px 0; border-top: 1px dashed var(--border-color); }
        .parent-card .pc-line:first-of-type { border-top: 0; padding-top: 0; }
        .parent-card .pc-line i { color: var(--text-muted); width: 14px; text-align: center; font-size: 12px; flex-shrink: 0; }
        .parent-card .pc-line span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .parent-card .pc-line.empty { color: var(--text-muted); font-style: italic; }

        .you-tag {
            position: absolute; top: 12px; right: 12px;
            background: var(--primary); color: #fff;
            font-size: 9.5px; font-weight: 700;
            padding: 2px 8px; border-radius: 50px;
            letter-spacing: 0.5px;
        }

        /* ---------- Student header ---------- */
        .student-header {
            display: flex; align-items: center; gap: 16px;
            padding: 20px;
        }
        .student-avatar {
            width: 84px; height: 84px;
            border-radius: 22px;
            background: linear-gradient(135deg, var(--primary), var(--purple));
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 32px; font-weight: 700; letter-spacing: 2px;
            flex-shrink: 0;
            box-shadow: 0 12px 30px rgba(37,99,235,0.35);
        }
        .student-header .sh-info { flex:1; min-width:0; }
        .student-header .sh-name {
            font-family: 'Poppins', sans-serif;
            font-size: 18px; font-weight: 700; margin-bottom: 6px;
        }
        .student-header .sh-meta {
            font-size: 12.5px; color: var(--text-muted);
            display: flex; flex-wrap: wrap; gap: 8px;
        }
        .student-header .sh-meta .tag {
            background: var(--bg-alt); border: 1px solid var(--border-color);
            padding: 3px 10px; border-radius: 50px;
            font-weight: 600;
        }

        .student-info-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            padding: 18px 20px 20px;
            border-top: 1px dashed var(--border-color);
        }
        .student-info-grid .ip {
            padding: 10px 12px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 10px;
        }
        .student-info-grid .ip .il { font-size: 10.5px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; margin-bottom: 3px; }
        .student-info-grid .ip .iv { font-size: 13.5px; font-weight: 600; word-break: break-word; }

        /* ---------- Subjects ---------- */
        .subject-list { padding: 14px 20px 20px; display: flex; flex-direction: column; gap: 10px; }
        .subject-item {
            padding: 14px 16px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            transition: var(--transition);
        }
        .subject-item:hover { border-color: var(--primary); box-shadow: var(--shadow-md); }
        .subject-item .si-name {
            font-family: 'Poppins', sans-serif;
            font-size: 14.5px; font-weight: 700;
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 6px;
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
            font-weight: 700; letter-spacing: 0.4px;
            margin-left: 44px; margin-bottom: 6px;
        }
        .subject-item .si-teacher {
            font-size: 12px; color: var(--text-muted);
            display: flex; align-items: center; gap: 6px;
            margin-left: 44px; margin-bottom: 10px;
        }
        .subject-item .si-meta { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8px; }
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

        /* ---------- Marks table ---------- */
        .marks-table { width: 100%; border-collapse: collapse; }
        .marks-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); padding: 10px 16px; background: var(--bg-alt); font-weight: 700; }
        .marks-table td { padding: 12px 16px; font-size: 13.5px; border-bottom: 1px solid var(--border-color); }
        .marks-table tr:last-child td { border-bottom: none; }
        .marks-table .mark-val { font-family:'Courier New',monospace; font-weight: 700; color: var(--primary); font-size: 15px; }

        .empty { padding: 40px 20px; text-align: center; color: var(--text-muted); }
        .empty i { font-size: 36px; opacity: 0.35; margin-bottom: 12px; display: block; }
        .empty h4 { font-family:'Poppins',sans-serif; font-size: 14px; font-weight: 700; color: var(--text-primary); margin-bottom: 6px; }
        .empty p { font-size: 12.5px; line-height: 1.55; }

        .foot { text-align: center; margin-top: 24px; font-size: 13px; color: var(--text-muted); }
    </style>
</head>
<body>

<button class="theme-toggle" id="themeToggle"><i class="fas fa-moon"></i></button>

<div class="wrap">

    <!-- ================= TOP BAR ================= -->
    <div class="topbar">
        <div class="topbar-left">
            <div class="avatar"><?= htmlspecialchars($student_initials); ?></div>
            <div class="topbar-info">
                <div class="nm"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                <div class="meta">
                    <span class="tag"><i class="fas fa-hashtag"></i> <?= htmlspecialchars($student['student_code']); ?></span>
                    <?php if (!empty($student['class_name'])): ?>
                        <span><i class="fas fa-layer-group"></i> <?= htmlspecialchars($student['class_name']); ?></span>
                    <?php endif; ?>
                    <span><i class="fas fa-school"></i> <?= htmlspecialchars($school['name'] ?? 'School'); ?></span>
                    <span><i class="fas fa-user-friends"></i> Parent view</span>
                </div>
            </div>
        </div>
        <div class="topbar-right">
            <span class="status-badge <?= $is_active ? 'active' : 'inactive'; ?>">
                <span class="dot"></span> <?= $is_active ? 'Active' : 'Pending Activation'; ?>
            </span>
            <a href="parent-logout.php" class="btn logout">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- ================= MAIN GRID ================= -->
    <div class="grid">

        <!-- ============ LEFT COLUMN ============ -->
        <div>

            <!-- PARENTS -->
            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-people-roof"></i> Parents / Guardian</h2>
                </div>
                <div class="parent-grid">
                    <?php foreach ($parents as $key => $p):
                        $is_empty = (trim($p['name']) === '' && trim($p['phone']) === '' && trim($p['email']) === '');
                        $you = in_array($key, $roles, true);
                    ?>
                        <div class="parent-card <?= $you ? 'highlighted' : '' ?>">
                            <?php if ($you): ?>
                                <span class="you-tag"><i class="fas fa-check"></i> YOU</span>
                            <?php endif; ?>
                            <div class="pc-top">
                                <div class="pc-avatar" style="background: <?= $p['color']; ?>">
                                    <i class="fas <?= $p['icon']; ?>"></i>
                                </div>
                                <div class="pc-title">
                                    <div class="pc-role"><?= htmlspecialchars($p['label']); ?></div>
                                    <div class="pc-name <?= trim($p['name']) === '' ? 'empty' : ''; ?>">
                                        <?= trim($p['name']) !== '' ? htmlspecialchars($p['name']) : 'Not provided'; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="pc-line <?= trim($p['phone']) === '' ? 'empty' : ''; ?>">
                                <i class="fas fa-phone"></i>
                                <span><?= trim($p['phone']) !== '' ? htmlspecialchars($p['phone']) : 'No phone on file'; ?></span>
                            </div>
                            <div class="pc-line <?= trim($p['email']) === '' ? 'empty' : ''; ?>">
                                <i class="fas fa-envelope"></i>
                                <span><?= trim($p['email']) !== '' ? htmlspecialchars($p['email']) : 'No email on file'; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- STUDENT INFORMATION -->
            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-user-graduate"></i> Student Information</h2>
                </div>
                <div class="student-header">
                    <div class="student-avatar"><?= htmlspecialchars($student_initials); ?></div>
                    <div class="sh-info">
                        <div class="sh-name"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                        <div class="sh-meta">
                            <span class="tag"><i class="fas fa-hashtag"></i> <?= htmlspecialchars($student['student_code']); ?></span>
                            <span class="tag"><i class="fas fa-<?= $student['sex'] === 'Female' ? 'venus' : 'mars'; ?>"></i> <?= htmlspecialchars($student['sex']); ?></span>
                            <span class="tag"><i class="fas fa-calendar"></i> <?= htmlspecialchars(date('M j, Y', strtotime($student['dob']))); ?></span>
                        </div>
                    </div>
                </div>
                <div class="student-info-grid">
                    <div class="ip">
                        <div class="il">Class</div>
                        <div class="iv"><?= htmlspecialchars($student['class_name'] ?? '—'); ?></div>
                    </div>
                    <div class="ip">
                        <div class="il">Level</div>
                        <div class="iv"><?= htmlspecialchars(str_replace('_', "' ", $student['class_level'] ?? $student['level'] ?? '—')); ?></div>
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
                        <div class="iv"><?= htmlspecialchars(trim(($student['sector'] ?? '') . ', ' . ($student['district'] ?? '') . ', ' . ($student['province'] ?? ''), ', ')) ?: '—'; ?></div>
                    </div>
                    <div class="ip">
                        <div class="il">Disability</div>
                        <div class="iv"><?= $student['disability'] === 'yes' ? 'Yes' : 'No'; ?></div>
                    </div>
                    <?php if (!empty($student['disability_note'])): ?>
                        <div class="ip">
                            <div class="il">Disability Notes</div>
                            <div class="iv"><?= htmlspecialchars($student['disability_note']); ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($student['previous_school'])): ?>
                        <div class="ip">
                            <div class="il">Previous School</div>
                            <div class="iv"><?= htmlspecialchars($student['previous_school']); ?></div>
                        </div>
                        <div class="ip">
                            <div class="il">Previous Marks</div>
                            <div class="iv"><?= htmlspecialchars($student['previous_marks'] ?: '—'); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SUBJECTS -->
            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-book"></i> Subjects</h2>
                    <span class="count"><?= count($subjects); ?></span>
                </div>
                <?php if (!empty($subjects)): ?>
                    <div class="subject-list">
                        <?php foreach ($subjects as $sub):
                            $initials = strtoupper(mb_substr($sub['subject_name'], 0, 2));
                        ?>
                            <div class="subject-item">
                                <div class="si-name">
                                    <span class="si-avatar"><?= htmlspecialchars($initials); ?></span>
                                    <span><?= htmlspecialchars($sub['subject_name']); ?></span>
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
                        <p>The school hasn't assigned subjects to this class yet.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- MARKS -->
            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-clipboard-list"></i> Marks</h2>
                    <span class="count"><?= count($marks); ?></span>
                </div>
                <div class="card-body pad-0">
                    <?php if (!empty($marks)): ?>
                        <table class="marks-table">
                            <thead><tr><th>Subject</th><th>Score</th></tr></thead>
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
                            <h4>No marks recorded yet</h4>
                            <p>Marks will appear here once the teachers record them.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- ============ RIGHT COLUMN ============ -->
        <div>

            <!-- CLASS -->
            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-layer-group"></i> Class</h2>
                </div>
                <div class="card-body">
                    <?php if (!empty($student['class_name'])): ?>
                        <div class="student-info-grid" style="padding: 0; border-top: none; grid-template-columns: 1fr;">
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
                            <p>Once the school assigns your child to a class, you'll see it here.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- SUMMARY -->
            <div class="card">
                <div class="card-head">
                    <h2><i class="fas fa-chart-simple"></i> At a Glance</h2>
                </div>
                <div class="card-body">
                    <div class="student-info-grid" style="padding: 0; border-top: none; grid-template-columns: 1fr;">
                        <div class="ip">
                            <div class="il">Subjects</div>
                            <div class="iv"><?= count($subjects); ?></div>
                        </div>
                        <div class="ip">
                            <div class="il">Marks Recorded</div>
                            <div class="iv"><?= count($marks); ?></div>
                        </div>
                        <div class="ip">
                            <div class="il">Status</div>
                            <div class="iv"><?= $is_active ? 'Active' : 'Inactive (pending)'; ?></div>
                        </div>
                    </div>
                </div>
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