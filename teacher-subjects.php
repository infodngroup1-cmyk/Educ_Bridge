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
$teacher_name  = $_SESSION['teacher_name']  ?? 'Teacher';
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

/*
|--------------------------------------------------------------------------
| AUTO-CREATE TABLES
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS subject_classes (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    school_id    INT NOT NULL,
    subject_id   INT NOT NULL,
    class_id     INT NOT NULL,
    credits      FLOAT DEFAULT NULL,
    week_hours   FLOAT DEFAULT NULL,
    max_marks    INT DEFAULT NULL,
    assigned_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sc_school  (school_id),
    KEY idx_sc_subject (subject_id),
    KEY idx_sc_class   (class_id),
    UNIQUE KEY uq_subject_class (subject_id, class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS subject_class_teachers (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    school_id    INT NOT NULL,
    subject_id   INT NOT NULL,
    class_id     INT NOT NULL,
    teacher_id   INT DEFAULT NULL,
    assigned_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sct_school  (school_id),
    KEY idx_sct_subject (subject_id),
    KEY idx_sct_class   (class_id),
    KEY idx_sct_teacher (teacher_id),
    UNIQUE KEY uq_subject_class_teacher (subject_id, class_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

@mysqli_query($conn, "
    DELETE sct FROM subject_class_teachers sct
    LEFT JOIN subjects s ON s.id = sct.subject_id
    LEFT JOIN classes  c ON c.id = sct.class_id
    WHERE s IS NULL OR c IS NULL
");
@mysqli_query($conn, "
    DELETE sc FROM subject_classes sc
    LEFT JOIN subjects s ON s.id = sc.subject_id
    LEFT JOIN classes  c ON c.id = sc.class_id
    WHERE s IS NULL OR c IS NULL
");

/*
|--------------------------------------------------------------------------
| HELPER: does this teacher teach this SUBJECT at all?
|--------------------------------------------------------------------------
| True when the teacher has at least one subject_class_teachers row for the
| subject within their school. This is the scope that lets them open ANY
| class that studies this subject.
*/
function teacher_owns_subject($conn, $teacher_id, $school_id, $subject_id) {
    $sql = "
        SELECT 1
        FROM subject_class_teachers sct
        WHERE sct.school_id = ?
          AND sct.teacher_id = ?
          AND sct.subject_id = ?
        LIMIT 1
    ";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, "iii", $school_id, $teacher_id, $subject_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ok = ($res && mysqli_num_rows($res) > 0);
    mysqli_stmt_close($stmt);
    return $ok;
}

/*
|--------------------------------------------------------------------------
| CLAIM SUBJECT
|--------------------------------------------------------------------------
*/
if (isset($_GET['claim']) && ctype_digit((string)$_GET['claim'])) {
    $sid = (int) $_GET['claim'];

    $chk = mysqli_prepare($conn, "SELECT id FROM subjects WHERE id = ? AND school_id = ? AND status = 'active' LIMIT 1");
    mysqli_stmt_bind_param($chk, "ii", $sid, $school_id);
    mysqli_stmt_execute($chk);
    $res_chk = mysqli_stmt_get_result($chk);
    if (mysqli_num_rows($res_chk) === 0) {
        mysqli_stmt_close($chk);
        header("Location: teacher-subjects.php?error=" . urlencode("Subject not found or inactive."));
        exit;
    }
    mysqli_stmt_close($chk);

    $class_ids = [];
    $cstmt = mysqli_prepare($conn, "SELECT class_id FROM subject_classes WHERE school_id = ? AND subject_id = ?");
    if ($cstmt) {
        mysqli_stmt_bind_param($cstmt, "ii", $school_id, $sid);
        mysqli_stmt_execute($cstmt);
        $cres = mysqli_stmt_get_result($cstmt);
        while ($row = mysqli_fetch_assoc($cres)) {
            $class_ids[] = (int)$row['class_id'];
        }
        mysqli_stmt_close($cstmt);
    }

    $link_stmt = mysqli_prepare($conn, "
        INSERT INTO subject_class_teachers (school_id, subject_id, class_id, teacher_id)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id), assigned_at = CURRENT_TIMESTAMP
    ");

    $claimed = 0;
    foreach ($class_ids as $cid) {
        if ($link_stmt) {
            mysqli_stmt_bind_param($link_stmt, "iiii", $school_id, $sid, $cid, $teacher_id);
            if (mysqli_stmt_execute($link_stmt)) $claimed++;
        }
    }
    if ($link_stmt) mysqli_stmt_close($link_stmt);

    if ($claimed > 0) {
        header("Location: teacher-subjects.php?claimed=1&scope=mine");
        exit;
    }
    header("Location: teacher-subjects.php?error=" . urlencode("No classes to claim for this subject."));
    exit;
}

/*
|--------------------------------------------------------------------------
| UNCLAIM SUBJECT
|--------------------------------------------------------------------------
*/
if (isset($_GET['unclaim']) && ctype_digit((string)$_GET['unclaim'])) {
    $sid = (int) $_GET['unclaim'];

    $upd = mysqli_prepare($conn, "
        UPDATE subject_class_teachers
        SET teacher_id = NULL, assigned_at = CURRENT_TIMESTAMP
        WHERE school_id = ? AND subject_id = ? AND teacher_id = ?
    ");
    mysqli_stmt_bind_param($upd, "iii", $school_id, $sid, $teacher_id);

    if (mysqli_stmt_execute($upd)) {
        mysqli_stmt_close($upd);
        header("Location: teacher-subjects.php?unclaimed=1&scope=mine");
        exit;
    }
    mysqli_stmt_close($upd);
    header("Location: teacher-subjects.php?error=" . urlencode("Could not unclaim subject."));
    exit;
}

/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/
$message      = "";
$message_type = "";

if (($_GET['claimed']   ?? '') === '1') { $message = "Subject claimed. You can now open any class that studies this subject."; $message_type = "success"; }
if (($_GET['unclaimed'] ?? '') === '1') { $message = "You have been removed as teacher for all classes of this subject.";  $message_type = "success"; }
if (isset($_GET['error'])) { $message = urldecode($_GET['error']); $message_type = "error"; }

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$q     = trim($_GET['q'] ?? '');
$level = $_GET['level'] ?? '';
$scope = $_GET['scope'] ?? 'mine';

/*
|--------------------------------------------------------------------------
| FETCH SUBJECTS
|--------------------------------------------------------------------------
*/
$where  = ["s.school_id = ?"];
$params = [$school_id];
$types  = "i";

if ($q !== '') {
    $where[] = "(s.subject_name LIKE ? OR s.subject_code LIKE ?)";
    $like = "%{$q}%";
    $params[] = $like; $params[] = $like;
    $types   .= "ss";
}
if (in_array($level, ['Nursery','Primary','O_Level','A_Level'], true)) {
    $where[] = "EXISTS (
        SELECT 1 FROM subject_classes sc
        INNER JOIN classes c ON c.id = sc.class_id
        WHERE sc.school_id = s.school_id
          AND sc.subject_id = s.id
          AND c.level = ?
    )";
    $params[] = $level; $types .= "s";
}

if ($scope === 'mine') {
    $where[] = "EXISTS (
        SELECT 1 FROM subject_class_teachers sct
        WHERE sct.school_id = s.school_id
          AND sct.subject_id = s.id
          AND sct.teacher_id = ?
    )";
    $params[] = $teacher_id; $types .= "i";
} elseif ($scope === 'available') {
    $where[] = "EXISTS (
        SELECT 1 FROM subject_classes sc
        WHERE sc.school_id = s.school_id AND sc.subject_id = s.id
    )";
    $where[] = "NOT EXISTS (
        SELECT 1 FROM subject_class_teachers sct
        WHERE sct.school_id = s.school_id
          AND sct.subject_id = s.id
          AND sct.teacher_id IS NOT NULL
          AND sct.teacher_id > 0
    )";
}

$where_sql = implode(" AND ", $where);

$subjects = [];
$list_stmt = mysqli_prepare($conn, "
    SELECT s.*
    FROM subjects s
    WHERE $where_sql
    ORDER BY s.subject_name ASC
");
if ($list_stmt) {
    mysqli_stmt_bind_param($list_stmt, $types, ...$params);
    mysqli_stmt_execute($list_stmt);
    $res = mysqli_stmt_get_result($list_stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $row['classes'] = [];
        $subjects[(int)$row['id']] = $row;
    }
    mysqli_stmt_close($list_stmt);
}

/*
|--------------------------------------------------------------------------
| FETCH CLASSES (all classes that study each subject)
|--------------------------------------------------------------------------
*/
if (!empty($subjects)) {
    $ids = array_keys($subjects);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $tc  = "i" . str_repeat('i', count($ids));
    $par = array_merge([$school_id], $ids);

    $csql = "
        SELECT
            sc.subject_id,
            sc.class_id,
            sc.credits,
            sc.week_hours,
            sc.max_marks,
            c.class_name,
            c.level,
            c.room,
            sct.teacher_id,
            t.name AS teacher_name
        FROM subject_classes sc
        INNER JOIN classes c ON c.id = sc.class_id AND c.school_id = sc.school_id
        LEFT JOIN subject_class_teachers sct
               ON sct.subject_id = sc.subject_id AND sct.class_id = sc.class_id
        LEFT JOIN teachers t ON t.id = sct.teacher_id AND t.school_id = sc.school_id
        WHERE sc.school_id = ? AND sc.subject_id IN ($ph)
        ORDER BY FIELD(c.level,'Nursery','Primary','O_Level','A_Level'), c.class_name ASC
    ";
    $cstmt = mysqli_prepare($conn, $csql);
    if ($cstmt) {
        mysqli_stmt_bind_param($cstmt, $tc, ...$par);
        mysqli_stmt_execute($cstmt);
        $cres = mysqli_stmt_get_result($cstmt);
        while ($c = mysqli_fetch_assoc($cres)) {
            $sid = (int)$c['subject_id'];
            $c['class_id']    = (int)$c['class_id'];
            $c['teacher_id']  = (int)($c['teacher_id'] ?? 0);
            $c['is_mine']     = ($c['teacher_id'] === $teacher_id);
            $c['has_teacher'] = ($c['teacher_id'] > 0);
            $subjects[$sid]['classes'][] = $c;
        }
        mysqli_stmt_close($cstmt);
    }
}

/*
|--------------------------------------------------------------------------
| COMPUTE FLAGS
|--------------------------------------------------------------------------
| SUBJECT-LEVEL ownership:
|   is_mine = teacher has ANY sct row for this subject
| That is what lets them open ANY class of the subject.
*/
foreach ($subjects as $sid => &$s) {
    $all_classes       = $s['classes'];
    $teachers_on_subj  = [];
    $my_owned_classes  = 0;
    $unassigned_count  = 0;

    foreach ($all_classes as $c) {
        if (!empty($c['teacher_id']) && $c['teacher_id'] > 0) {
            $teachers_on_subj[$c['teacher_id']] = $c['teacher_name'];
        } else {
            $unassigned_count++;
        }
        if (!empty($c['is_mine'])) $my_owned_classes++;
    }

    $s['total_classes']    = count($all_classes);
    $s['mine_count']       = $my_owned_classes;
    $s['unassigned_count'] = $unassigned_count;
    $s['teachers_on_subj'] = $teachers_on_subj;

    // Teach subject at all → can open any of its classes
    $s['is_mine']      = ($my_owned_classes > 0);
    $s['is_available'] = (count($all_classes) > 0 && count($teachers_on_subj) === 0);
    $s['no_classes']   = (count($all_classes) === 0);

    $s['display_classes'] = $all_classes;
}
unset($s);

/*
|--------------------------------------------------------------------------
| STATS
|--------------------------------------------------------------------------
*/
function tcount($conn, $sql, $types = "", $params = []) {
    $stmt = @mysqli_prepare($conn, $sql);
    if (!$stmt) return 0;
    if ($types !== "" && !empty($params)) mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $n = $res ? (int) mysqli_fetch_assoc($res)['total'] : 0;
    mysqli_stmt_close($stmt);
    return $n;
}

$my_subjects_total = tcount($conn, "
    SELECT COUNT(DISTINCT s.id) AS total
    FROM subjects s
    INNER JOIN subject_class_teachers sct
            ON sct.subject_id = s.id AND sct.school_id = s.school_id
    WHERE s.school_id = ? AND sct.teacher_id = ?
", "ii", [$school_id, $teacher_id]);

$my_active_total = tcount($conn, "
    SELECT COUNT(DISTINCT s.id) AS total
    FROM subjects s
    INNER JOIN subject_class_teachers sct
            ON sct.subject_id = s.id AND sct.school_id = s.school_id
    WHERE s.school_id = ? AND sct.teacher_id = ? AND s.status = 'active'
", "ii", [$school_id, $teacher_id]);

$available_total = tcount($conn, "
    SELECT COUNT(DISTINCT s.id) AS total
    FROM subjects s
    INNER JOIN subject_classes sc
            ON sc.subject_id = s.id AND sc.school_id = s.school_id
    WHERE s.school_id = ?
      AND s.status = 'active'
      AND NOT EXISTS (
        SELECT 1 FROM subject_class_teachers sct
        WHERE sct.school_id = s.school_id
          AND sct.subject_id = s.id
          AND sct.teacher_id IS NOT NULL
          AND sct.teacher_id > 0
      )
", "i", [$school_id]);

$all_total = tcount($conn, "SELECT COUNT(*) AS total FROM subjects WHERE school_id = ?", "i", [$school_id]);

$my_no_class_total = tcount($conn, "
    SELECT COUNT(*) AS total
    FROM subjects s
    WHERE s.school_id = ?
      AND EXISTS (
        SELECT 1 FROM subject_class_teachers sct
        WHERE sct.school_id = s.school_id
          AND sct.subject_id = s.id
          AND sct.teacher_id = ?
      )
      AND NOT EXISTS (
        SELECT 1 FROM subject_classes sc
        WHERE sc.school_id = s.school_id AND sc.subject_id = s.id
      )
", "ii", [$school_id, $teacher_id]);

$level_map = [
    'Nursery' => 'Nursery', 'Primary' => 'Primary',
    'O_Level' => "O' Level", 'A_Level' => "A' Level",
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Subjects | Educ Bridge</title>
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
        .sum-icon.blue   { background: linear-gradient(135deg, #2563eb, #3b82f6); }
        .sum-icon.green  { background: linear-gradient(135deg, #10b981, #34d399); }
        .sum-icon.orange { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .sum-icon.teal   { background: linear-gradient(135deg, #14b8a6, #2dd4bf); }
        .sum-info .num { font-family:'Poppins',sans-serif; font-size:24px; font-weight:700; color:var(--text-primary); line-height:1.2; letter-spacing:-0.4px; }
        .sum-info .lbl { font-size:12.5px; color:var(--text-muted); margin-top:3px; font-weight:500; }

        .scope-tabs { display:flex; align-items:center; gap:8px; background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; padding:6px; margin-bottom:18px; flex-wrap:wrap; }
        .scope-tab { display:inline-flex; align-items:center; gap:8px; padding:10px 18px; border-radius:8px; font-size:13.5px; font-weight:600; color:var(--text-secondary); text-decoration:none; transition:var(--transition); }
        .scope-tab .cnt { background:var(--bg-alt); color:var(--text-muted); font-size:11.5px; font-weight:700; padding:1px 8px; border-radius:50px; margin-left:4px; }
        .scope-tab:hover { background:var(--bg-alt); color:var(--text-primary); }
        .scope-tab.active { background:var(--primary); color:#fff; box-shadow:0 4px 12px rgba(37,99,235,0.28); }
        .scope-tab.active .cnt { background:rgba(255,255,255,0.25); color:#fff; }

        .filters { background:var(--bg-card); border:1px solid var(--border-color); border-radius:14px; padding:16px 18px; margin-bottom:20px; display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .filter-group { position:relative; display:flex; align-items:center; flex:1 1 180px; min-width:160px; }
        .filter-group i { position:absolute; left:13px; color:var(--text-muted); font-size:13px; pointer-events:none; }
        .filter-group input, .filter-group select { width:100%; padding:10px 14px 10px 36px; border:1.5px solid var(--border-color); border-radius:10px; background:var(--bg-alt); color:var(--text-primary); font-family:inherit; font-size:13.5px; outline:none; transition:var(--transition); }
        .filter-group select { padding-left:36px; cursor:pointer; }
        .filter-group input:focus, .filter-group select:focus { border-color:var(--primary); background:var(--bg-card); box-shadow:0 0 0 3px rgba(37,99,235,0.1); }

        .subjects-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(380px, 1fr)); gap:18px; margin-bottom:22px; }
        .subject-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:16px; padding:20px; transition:var(--transition); position:relative; }
        .subject-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); border-color:var(--primary); }
        .subject-card.mine { border-left:4px solid var(--success); }
        .subject-card.unassigned { border-left:4px solid var(--warning); }

        .subj-head { display:flex; align-items:flex-start; gap:14px; margin-bottom:16px; padding-right:46px; }
        .subj-avatar { width:52px; height:52px; border-radius:12px; background:linear-gradient(135deg, #2563eb, #7c3aed); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:18px; font-weight:700; flex-shrink:0; }
        .subj-info { flex:1; min-width:0; }
        .subj-info .n { font-size:16px; font-weight:700; color:var(--text-primary); margin-bottom:3px; }
        .subj-info .c { font-size:12.5px; color:var(--primary); font-family:'Courier New',monospace; font-weight:700; letter-spacing:0.5px; }

        .subj-actions { position:absolute; top:14px; right:14px; }
        .subj-pills { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px; }

        .classes-block { margin-top:14px; padding-top:14px; border-top:1px dashed var(--border-color); }
        .classes-block .cb-title {
            font-size: 11px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex; align-items: center; gap: 6px;
        }
        .classes-block .cb-title .cnt {
            background: var(--bg-alt);
            color: var(--text-secondary);
            font-size: 10.5px;
            padding: 1px 8px;
            border-radius: 50px;
        }
        .classes-block .cb-title.mine .cnt {
            background: var(--success-light);
            color: #059669;
        }
        [data-theme="dark"] .classes-block .cb-title.mine .cnt { color: #34d399; }

        .class-chip-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .class-chip {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            background: var(--bg-alt);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            text-decoration: none;
            color: var(--text-primary);
            transition: var(--transition);
            font-size: 12.5px;
            font-weight: 600;
            flex: 1 1 180px;
            min-width: 0;
        }
        .class-chip:hover {
            border-color: var(--primary);
            background: var(--primary-light);
            transform: translateY(-1px);
        }
        [data-theme="dark"] .class-chip:hover {
            background: rgba(59,130,246,0.15);
        }
        .class-chip.mine {
            background: linear-gradient(90deg, rgba(16,185,129,0.08), var(--bg-alt));
            border-color: rgba(16,185,129,0.35);
        }
        .class-chip.mine:hover {
            background: rgba(16,185,129,0.15);
            border-color: #10b981;
        }
        .class-chip .cc-avatar {
            width: 30px; height: 30px;
            border-radius: 8px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-family: 'Poppins', sans-serif;
            font-size: 11.5px; font-weight: 700;
            flex-shrink: 0;
        }
        .class-chip.mine .cc-avatar {
            background: linear-gradient(135deg, #10b981, #34d399);
        }
        .class-chip .cc-info { flex: 1; min-width: 0; }
        .class-chip .cc-name {
            font-size: 13px; font-weight: 700; color: var(--text-primary);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .class-chip .cc-meta {
            font-size: 11px; color: var(--text-muted);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            margin-top: 1px;
        }
        .class-chip .cc-arrow {
            font-size: 11px;
            color: var(--primary);
            opacity: 0.7;
            flex-shrink: 0;
        }
        .class-chip:hover .cc-arrow { opacity: 1; }

        .no-classes-note {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 14px;
            background: var(--bg-alt);
            border: 1.5px dashed var(--border-color);
            border-radius: 10px;
            color: var(--text-muted);
            font-size: 12.5px;
            font-style: italic;
        }
        .no-classes-note i { font-size: 15px; flex-shrink: 0; }

        .pill { display:inline-block; padding:3px 10px; border-radius:50px; font-size:11.5px; font-weight:700; letter-spacing:0.3px; line-height:1.5; }
        .pill.blue   { background: rgba(37,99,235,0.12);  color: #2563eb; }
        .pill.green  { background: rgba(16,185,129,0.12); color: #059669; }
        .pill.orange { background: rgba(245,158,11,0.15); color: #b45309; }
        .pill.gray   { background: rgba(100,116,139,0.14);color: #475569; }
        .pill.red    { background: rgba(239,68,68,0.12);  color: #dc2626; }
        [data-theme="dark"] .pill.blue   { color: #93c5fd; }
        [data-theme="dark"] .pill.green  { color: #34d399; }
        [data-theme="dark"] .pill.orange { color: #fbbf24; }
        [data-theme="dark"] .pill.gray   { color: #cbd5e1; }
        [data-theme="dark"] .pill.red    { color: #f87171; }

        .actions-btn {
            width: 36px; height: 36px;
            border-radius: 10px;
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
            background: var(--primary-light);
        }
        [data-theme="dark"] .actions-btn:hover,
        [data-theme="dark"] .actions-btn.open {
            background: rgba(59,130,246,0.18);
        }

        .actions-menu {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            min-width: 240px;
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
            width: 18px; text-align: center;
            font-size: 13px;
            color: var(--text-muted);
            transition: var(--transition);
            flex-shrink: 0;
        }
        .actions-menu a:hover { background: var(--bg-alt); }
        .actions-menu a:hover i { color: var(--primary); }
        .actions-menu a.claim i { color: #059669 !important; }
        .actions-menu a.claim:hover { background: var(--success-light); }
        .actions-menu a.unclaim i { color: #f59e0b !important; }
        .actions-menu a.unclaim:hover { background: var(--warning-light); }
        .actions-menu .divider { height: 1px; background: var(--border-color); margin: 4px 6px; }
        .actions-menu .menu-label {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--text-muted);
            padding: 8px 12px 4px;
        }

        .empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); background: var(--bg-card); border: 1px solid var(--border-color); border-radius: 16px; }
        .empty-state i { font-size:50px; opacity:0.35; margin-bottom:14px; display:block; }
        .empty-state h3 { font-family:'Poppins',sans-serif; font-size:17px; font-weight:700; color:var(--text-primary); margin-bottom:6px; }
        .empty-state p { font-size:14px; line-height:1.6; margin-bottom:18px; }

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
            .subjects-grid { grid-template-columns: 1fr; }
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

            <a href="teacher-subjects.php" class="nav-item active"><i class="fas fa-book nav-icon"></i> Subjects
                <?php if ($my_subjects_total > 0): ?><span class="nav-badge"><?= $my_subjects_total; ?></span><?php endif; ?>
            </a>
            <a href="teacher-classes.php" class="nav-item"><i class="fas fa-layer-group nav-icon"></i> Classes</a>
            <a href="teacher-marks.php" class="nav-item"><i class="fas fa-clipboard-list nav-icon"></i> Marks</a>
            <div class="nav-section-title">Communication</div>
            <a href="teacher-announcements.php" class="nav-item"><i class="fas fa-bullhorn nav-icon"></i> Announcements</a>
           
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
                    <div class="page-title">My Subjects</div>
                    <div class="page-subtitle"><?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>
            <div class="topbar-actions">
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>
                <a href="teacher-messages.php" class="topbar-btn" title="Messages"><i class="fas fa-bell"></i></a>
                <div class="topbar-avatar" title="<?= htmlspecialchars($teacher_name); ?>"><?= htmlspecialchars($initials); ?></div>
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
                    <h1><i class="fas fa-book"></i> Subjects &amp; Classes</h1>
                    <div class="sub">Open any class that studies a subject you teach — view students, take attendance and record marks</div>
                </div>
            </div>

            <div class="summaries">
                <div class="sum-card">
                    <div class="sum-icon blue"><i class="fas fa-book"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $my_subjects_total; ?></div>
                        <div class="lbl">My Subjects</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon green"><i class="fas fa-check-circle"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $my_active_total; ?></div>
                        <div class="lbl">Active</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon orange"><i class="fas fa-unlink"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $my_no_class_total; ?></div>
                        <div class="lbl">No Class Linked</div>
                    </div>
                </div>
                <div class="sum-card">
                    <div class="sum-icon teal"><i class="fas fa-inbox"></i></div>
                    <div class="sum-info">
                        <div class="num"><?= $available_total; ?></div>
                        <div class="lbl">Available to Claim</div>
                    </div>
                </div>
            </div>

            <div class="scope-tabs">
                <a href="teacher-subjects.php?scope=mine&<?= http_build_query(['q'=>$q,'level'=>$level]) ?>" class="scope-tab <?= $scope === 'mine' ? 'active' : ''; ?>">
                    <i class="fas fa-user-check"></i> My Subjects
                    <span class="cnt"><?= $my_subjects_total; ?></span>
                </a>
                <a href="teacher-subjects.php?scope=available&<?= http_build_query(['q'=>$q,'level'=>$level]) ?>" class="scope-tab <?= $scope === 'available' ? 'active' : ''; ?>">
                    <i class="fas fa-inbox"></i> Available to Claim
                    <span class="cnt"><?= $available_total; ?></span>
                </a>
                <a href="teacher-subjects.php?scope=all&<?= http_build_query(['q'=>$q,'level'=>$level]) ?>" class="scope-tab <?= $scope === 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i> All Subjects
                    <span class="cnt"><?= $all_total; ?></span>
                </a>
            </div>

            <form method="GET" action="teacher-subjects.php" class="filters">
                <input type="hidden" name="scope" value="<?= htmlspecialchars($scope); ?>">

                <div class="filter-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="q" placeholder="Search subject name or code" value="<?= htmlspecialchars($q); ?>">
                </div>

                <div class="filter-group">
                    <i class="fas fa-layer-group"></i>
                    <select name="level">
                        <option value="">All Levels</option>
                        <option value="Nursery" <?= $level === 'Nursery' ? 'selected' : ''; ?>>Nursery</option>
                        <option value="Primary" <?= $level === 'Primary' ? 'selected' : ''; ?>>Primary</option>
                        <option value="O_Level" <?= $level === 'O_Level' ? 'selected' : ''; ?>>O' Level</option>
                        <option value="A_Level" <?= $level === 'A_Level' ? 'selected' : ''; ?>>A' Level</option>
                    </select>
                </div>

                <button type="submit" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;border:none;background:var(--primary);color:#fff;font-family:inherit;">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="teacher-subjects.php?scope=<?= htmlspecialchars($scope); ?>" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);text-decoration:none;">
                    <i class="fas fa-rotate"></i> Reset
                </a>
            </form>

            <?php if (!empty($subjects)): ?>
                <div class="subjects-grid">
                    <?php foreach ($subjects as $s): ?>
                        <?php
                            $sid         = (int)$s['id'];
                            $is_mine     = !empty($s['is_mine']);   // I teach this subject at all
                            $is_avail    = !empty($s['is_available']);
                            $total_cls   = (int)$s['total_classes'];
                            $display_cls = $s['display_classes'];

                            $subj_init = "";
                            foreach (explode(" ", trim($s['subject_name'])) as $p) {
                                if ($p !== "") { $subj_init .= strtoupper($p[0]); if (strlen($subj_init) >= 2) break; }
                            }
                            if ($subj_init === "") $subj_init = "S";

                            $card_class = '';
                            if ($is_mine) $card_class = 'mine';
                            elseif ($is_avail) $card_class = 'unassigned';
                        ?>
                        <div class="subject-card <?= $card_class; ?>">

                            <div class="subj-actions">
                                <button type="button" class="actions-btn" title="More options">
                                    <i class="fas fa-ellipsis-vertical"></i>
                                </button>

                                <div class="actions-menu">
                                    <?php if ($is_mine): ?>
                                        <a href="teacher-marks.php?subject_id=<?= $sid; ?>">
                                            <i class="fas fa-clipboard-list"></i> Open Marks
                                        </a>
                                        <a href="teacher-students.php?subject_id=<?= $sid; ?>">
                                            <i class="fas fa-user-graduate"></i> View Students
                                        </a>
                                    <?php endif; ?>

                                    <?php if (!empty($display_cls)): ?>
                                        <div class="menu-label">Classes studying this subject</div>
                                        <?php foreach ($display_cls as $c): ?>
                                            <a href="teacher-open-class.php?subject_id=<?= $sid; ?>&class_id=<?= (int)$c['class_id']; ?>">
                                                <i class="fas fa-layer-group"></i>
                                                Open <?= htmlspecialchars($c['class_name']); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>

                                    <div class="divider"></div>

                                    <?php if ($is_avail): ?>
                                        <a href="teacher-subjects.php?claim=<?= $sid; ?>&scope=<?= htmlspecialchars($scope); ?>"
                                           class="claim"
                                           onclick="return confirm('Claim this subject? You will become its teacher in all its classes.');">
                                            <i class="fas fa-hand-holding-heart"></i> Claim Subject
                                        </a>
                                    <?php elseif ($is_mine): ?>
                                        <a href="teacher-subjects.php?unclaim=<?= $sid; ?>&scope=<?= htmlspecialchars($scope); ?>"
                                           class="unclaim"
                                           onclick="return confirm('Remove yourself as the teacher in all classes of this subject?');">
                                            <i class="fas fa-user-slash"></i> Unclaim (all my classes)
                                        </a>
                                    <?php else: ?>
                                        <a href="#" onclick="return false;" style="opacity:0.6;cursor:not-allowed;">
                                            <i class="fas fa-user-tie"></i> Assigned to other teachers
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="subj-head">
                                <div class="subj-avatar"><?= htmlspecialchars($subj_init); ?></div>
                                <div class="subj-info">
                                    <div class="n"><?= htmlspecialchars($s['subject_name']); ?></div>
                                    <?php if (!empty($s['subject_code'])): ?>
                                        <div class="c"><?= htmlspecialchars($s['subject_code']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="subj-pills">
                                <?php if ($is_mine): ?>
                                    <span class="pill green"><i class="fas fa-check-circle"></i> You teach this subject</span>
                                <?php elseif ($is_avail): ?>
                                    <span class="pill orange"><i class="fas fa-hourglass-half"></i> Unclaimed</span>
                                <?php else: ?>
                                    <span class="pill gray"><i class="fas fa-user-tie"></i> Assigned to others</span>
                                <?php endif; ?>
                                <span class="pill blue"><i class="fas fa-layer-group"></i> <?= $total_cls; ?> class<?= $total_cls === 1 ? '' : 'es'; ?></span>
                                <?php if ($s['status'] === 'inactive'): ?>
                                    <span class="pill red">Inactive</span>
                                <?php endif; ?>
                            </div>

                            <div class="classes-block">
                                <div class="cb-title <?= $is_mine ? 'mine' : ''; ?>">
                                    <i class="fas fa-layer-group"></i>
                                    Classes studying this subject
                                    <span class="cnt"><?= count($display_cls); ?></span>
                                </div>

                                <?php if (!empty($display_cls)): ?>
                                    <div class="class-chip-grid">
                                        <?php foreach ($display_cls as $c):
                                            $cid   = (int)$c['class_id'];
                                            $cn    = $c['class_name'];
                                            $cinit = "";
                                            foreach (explode(" ", trim($cn)) as $p) {
                                                if ($p !== "") { $cinit .= strtoupper($p[0]); if (strlen($cinit) >= 2) break; }
                                            }
                                            if ($cinit === "") $cinit = "C";

                                            $chip_mine  = !empty($c['is_mine']);
                                            $chip_class = $chip_mine ? 'mine' : '';

                                            // KEY CHANGE: every class of a subject I teach is clickable.
                                            $can_open = $is_mine;
                                            if ($can_open) {
                                                $chip_url = 'teacher-open-class.php?subject_id=' . $sid . '&class_id=' . $cid;
                                            } else {
                                                $chip_url = '#';
                                            }
                                        ?>
                                            <a href="<?= $chip_url; ?>"
                                               class="class-chip <?= $chip_class; ?>"
                                               <?= $can_open ? '' : 'onclick="return false;" title="You do not teach this subject"'; ?>>
                                                <div class="cc-avatar"><?= htmlspecialchars($cinit); ?></div>
                                                <div class="cc-info">
                                                    <div class="cc-name"><?= htmlspecialchars($cn); ?></div>
                                                    <div class="cc-meta">
                                                        <?= htmlspecialchars($level_map[$c['level']] ?? $c['level']); ?>
                                                        <?php if (!empty($c['room'])): ?>
                                                            · <?= htmlspecialchars($c['room']); ?>
                                                        <?php endif; ?>
                                                        <?php if ($chip_mine): ?>
                                                            · You
                                                        <?php elseif (!empty($c['teacher_name'])): ?>
                                                            · <?= htmlspecialchars($c['teacher_name']); ?>
                                                        <?php else: ?>
                                                            · No teacher
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if ($can_open): ?>
                                                    <i class="fas fa-arrow-right cc-arrow"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-lock" style="font-size:11px;color:var(--text-muted);"></i>
                                                <?php endif; ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="no-classes-note">
                                        <i class="fas fa-info-circle"></i>
                                        <?php if ($is_avail): ?>
                                            Claim this subject to start teaching it.
                                        <?php else: ?>
                                            No classes linked to this subject yet.
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-book"></i>
                    <h3>
                        <?php if ($scope === 'mine'): ?>
                            No subjects assigned yet
                        <?php elseif ($scope === 'available'): ?>
                            No subjects available to claim
                        <?php else: ?>
                            No subjects found
                        <?php endif; ?>
                    </h3>
                    <p>
                        <?php if ($scope === 'mine'): ?>
                            You don't teach any subject yet. Check "Available to Claim" or ask your school administrator.
                        <?php elseif ($scope === 'available'): ?>
                            All subjects already have teachers. Try "All Subjects".
                        <?php else: ?>
                            No subjects matched your filters.
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

        </main>
    </div>
</div>

<div id="menuPortal" style="position: fixed; inset: 0; pointer-events: none; z-index: 9999;"></div>

<script>
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
            wrap.style.top = top + 'px';
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
                const card = btn.closest('.subject-card');
                const template = card ? card.querySelector('.actions-menu') : null;
                if (!template) return;
                const btnRect = btn.getBoundingClientRect();
                const menu = buildMenu(template, btnRect);
                btn.classList.add('open');
                currentBtn = btn;
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