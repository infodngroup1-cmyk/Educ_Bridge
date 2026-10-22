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
    if ($part !== "") { $initials .= strtoupper($part[0]); if (strlen($initials) >= 2) break; }
}
if ($initials === "") $initials = "T";
$short_school = function_exists('mb_strimwidth')
    ? mb_strimwidth($school_name, 0, 24, '…')
    : substr($school_name, 0, 24);

/*
|--------------------------------------------------------------------------
| PARAMS
|--------------------------------------------------------------------------
*/
$class_id   = (int)($_GET['class_id']   ?? 0);
$subject_id = (int)($_GET['subject_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/
function initials_from_name($name) {
    $out = '';
    foreach (explode(' ', trim((string)$name)) as $p) {
        if ($p !== '') { $out .= strtoupper($p[0]); if (strlen($out) >= 2) break; }
    }
    return $out !== '' ? $out : 'S';
}
function fmt_mark($v) {
    if ($v === null || $v === '') return '';
    return rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
}
function students_name_column($conn) {
    foreach (['full_name','student_name','name','first_name'] as $c) {
        $t = mysqli_real_escape_string($conn, 'students');
        $cc = mysqli_real_escape_string($conn, $c);
        $r = @mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$cc}'");
        if ($r && mysqli_num_rows($r) > 0) return $c;
    }
    return null;
}
function students_reg_column($conn) {
    foreach (['admission_no','reg_no','student_code','code','registration_no'] as $c) {
        $t = mysqli_real_escape_string($conn, 'students');
        $cc = mysqli_real_escape_string($conn, $c);
        $r = @mysqli_query($conn, "SHOW COLUMNS FROM `{$t}` LIKE '{$cc}'");
        if ($r && mysqli_num_rows($r) > 0) return $c;
    }
    return null;
}
function render_blocked($title, $body) {
    ?>
    <!DOCTYPE html><html lang="en" data-theme="light"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($title); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body{font-family:'Inter',sans-serif;background:#f8fafc;color:#0f172a;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
        .box{max-width:480px;background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:36px 30px;text-align:center;box-shadow:0 20px 45px rgba(0,0,0,.08)}
        .ico{width:64px;height:64px;border-radius:50%;background:rgba(239,68,68,.1);color:#ef4444;display:inline-flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:16px}
        h1{font-family:'Poppins',sans-serif;font-size:22px;margin-bottom:10px}
        p{color:#64748b;line-height:1.65;font-size:14.5px;word-break:break-word}
        a.btn{display:inline-flex;align-items:center;gap:8px;margin-top:22px;padding:12px 22px;background:#2563eb;color:#fff;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px}
        code{background:#f1f5f9;padding:2px 6px;border-radius:6px;font-family:'Courier New',monospace;color:#dc2626;font-size:13px}
    </style></head><body>
    <div class="box"><?= $body; ?></div>
    </body></html>
    <?php exit;
}
function db_err($title, $conn) {
    render_blocked($title,
        '<div class="ico"><i class="fas fa-database"></i></div>
         <h1>' . htmlspecialchars($title) . '</h1>
         <p>' . htmlspecialchars(mysqli_error($conn)) . '</p>');
}

/*
|--------------------------------------------------------------------------
| VALIDATE PARAMS
|--------------------------------------------------------------------------
*/
if ($subject_id <= 0 || $class_id <= 0) {
    render_blocked('Missing parameters',
        '<div class="ico"><i class="fas fa-triangle-exclamation"></i></div>
         <h1>Missing class or subject</h1>
         <p>This page needs a valid class and subject in the URL.</p>
         <a class="btn" href="teacher-subjects.php"><i class="fas fa-arrow-left"></i> My Subjects</a>');
}

/*
|--------------------------------------------------------------------------
| LOAD SUBJECT
|--------------------------------------------------------------------------
*/
$subject = null;
$sql = "SELECT id, subject_name, subject_code, status FROM subjects WHERE id = ? AND school_id = ? LIMIT 1";
$s = mysqli_prepare($conn, $sql);
if (!$s) db_err('Could not read subject', $conn);
mysqli_stmt_bind_param($s, "ii", $subject_id, $school_id);
mysqli_stmt_execute($s);
$r = mysqli_stmt_get_result($s);
if ($row = mysqli_fetch_assoc($r)) $subject = $row;
mysqli_stmt_close($s);

if (!$subject) {
    render_blocked('Subject not found',
        '<div class="ico"><i class="fas fa-book"></i></div>
         <h1>Subject not found</h1>
         <p>This subject does not exist in your school.</p>
         <a class="btn" href="teacher-subjects.php"><i class="fas fa-arrow-left"></i> My Subjects</a>');
}

/*
|--------------------------------------------------------------------------
| LOAD CLASS
|--------------------------------------------------------------------------
*/
$class = null;
$sql = "SELECT id, class_name, level, room FROM classes WHERE id = ? AND school_id = ? LIMIT 1";
$s = mysqli_prepare($conn, $sql);
if (!$s) db_err('Could not read class', $conn);
mysqli_stmt_bind_param($s, "ii", $class_id, $school_id);
mysqli_stmt_execute($s);
$r = mysqli_stmt_get_result($s);
if ($row = mysqli_fetch_assoc($r)) $class = $row;
mysqli_stmt_close($s);

if (!$class) {
    render_blocked('Class not found',
        '<div class="ico"><i class="fas fa-layer-group"></i></div>
         <h1>Class not found</h1>
         <p>This class does not exist in your school.</p>
         <a class="btn" href="teacher-subjects.php"><i class="fas fa-arrow-left"></i> My Subjects</a>');
}

/*
|--------------------------------------------------------------------------
| OWNERSHIP GATE
|--------------------------------------------------------------------------
| Same logic as teacher-subjects.php:
|   1) The (subject, class) pair may be owned by this teacher in
|      `subject_class_teachers`.  → primary path.
|
|   2) OR — teacher teaches the subject at all (has any row for it), and
|      the class is one of the classes that study the subject.
|      This mirrors `teacher-subjects.php` where a subject-level `is_mine`
|      unlocks every class chip of that subject.
|
| Both cases require the class to be linked to the subject in
| `subject_classes` (or, if that table is empty, we fall back to allowing
| any class as long as the pair exists in subject_class_teachers).
*/

/* 1) Does the class study this subject? (subject_classes) */
$class_studies_subject = false;
$sql = "SELECT 1 FROM subject_classes WHERE school_id = ? AND subject_id = ? AND class_id = ? LIMIT 1";
$s = mysqli_prepare($conn, $sql);
if ($s) {
    mysqli_stmt_bind_param($s, "iii", $school_id, $subject_id, $class_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $class_studies_subject = $r && mysqli_num_rows($r) > 0;
    mysqli_stmt_close($s);
}

/* 2a) Is this (subject, class) pair owned by the teacher? */
$owns_pair = false;
$sql = "SELECT 1 FROM subject_class_teachers
        WHERE school_id = ? AND subject_id = ? AND class_id = ? AND teacher_id = ?
        LIMIT 1";
$s = mysqli_prepare($conn, $sql);
if ($s) {
    mysqli_stmt_bind_param($s, "iiii", $school_id, $subject_id, $class_id, $teacher_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $owns_pair = $r && mysqli_num_rows($r) > 0;
    mysqli_stmt_close($s);
}

/* 2b) Does the teacher teach the subject at all? */
$owns_subject = false;
$sql = "SELECT 1 FROM subject_class_teachers
        WHERE school_id = ? AND subject_id = ? AND teacher_id = ?
        LIMIT 1";
$s = mysqli_prepare($conn, $sql);
if ($s) {
    mysqli_stmt_bind_param($s, "iii", $school_id, $subject_id, $teacher_id);
    mysqli_stmt_execute($s);
    $r = mysqli_stmt_get_result($s);
    $owns_subject = $r && mysqli_num_rows($r) > 0;
    mysqli_stmt_close($s);
}

/*
| Decide:
|   allowed if (owns_pair)                                          → teacher explicitly assigned to this class
|           OR (owns_subject AND class_studies_subject)             → teacher owns the subject and class studies it
*/
$allowed = $owns_pair || ($owns_subject && $class_studies_subject);

if (!$allowed) {
    render_blocked('Permission denied',
        '<div class="ico"><i class="fas fa-lock"></i></div>
         <h1>You can\'t open this class</h1>
         <p>You are not assigned to teach <strong>' . htmlspecialchars($subject['subject_name']) . '</strong> in <strong>' . htmlspecialchars($class['class_name']) . '</strong>.</p>
         <a class="btn" href="teacher-subjects.php"><i class="fas fa-arrow-left"></i> My Subjects</a>');
}

/*
|--------------------------------------------------------------------------
| HELP TABLES
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS marks (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    school_id     INT NOT NULL,
    student_id    INT NOT NULL,
    class_id      INT NOT NULL,
    subject_id    INT NOT NULL,
    term          TINYINT NOT NULL,
    cat           DECIMAL(6,2) NULL,
    fat           DECIMAL(6,2) NULL,
    practical     DECIMAL(6,2) NULL,
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mark (student_id, class_id, subject_id, term)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS attendance (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    school_id     INT NOT NULL,
    class_id      INT NOT NULL,
    subject_id    INT NOT NULL,
    student_id    INT NOT NULL,
    date          DATE NOT NULL,
    status        ENUM('present','absent') NOT NULL DEFAULT 'present',
    updated_at    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_att (student_id, class_id, subject_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/*
|--------------------------------------------------------------------------
| ROSTER — students of this class
|--------------------------------------------------------------------------
*/
$name_col = students_name_column($conn);
$reg_col  = students_reg_column($conn);

if ($name_col === null) {
    render_blocked('Students table not usable',
        '<div class="ico"><i class="fas fa-table"></i></div>
         <h1>Could not find a name column</h1>
         <p>Your <code>students</code> table has none of the usual name columns
            (<code>full_name</code>, <code>student_name</code>, <code>name</code>, <code>first_name</code>).</p>
         <a class="btn" href="teacher-subjects.php"><i class="fas fa-arrow-left"></i> My Subjects</a>');
}

$name_expr = "`{$name_col}` AS full_name";
$reg_expr  = $reg_col ? ", `{$reg_col}` AS admission_no" : ", '' AS admission_no";

$students = [];
$sql = "SELECT id, {$name_expr}{$reg_expr}
        FROM students
        WHERE school_id = ? AND class_id = ?
        ORDER BY `{$name_col}` ASC";
$s = mysqli_prepare($conn, $sql);
if (!$s) db_err('Could not read students', $conn);
mysqli_stmt_bind_param($s, "ii", $school_id, $class_id);
mysqli_stmt_execute($s);
$r = mysqli_stmt_get_result($s);
while ($row = mysqli_fetch_assoc($r)) $students[] = $row;
mysqli_stmt_close($s);

/*
|--------------------------------------------------------------------------
| EXISTING MARKS
|--------------------------------------------------------------------------
*/
$marks_map = [];
if (!empty($students)) {
    $ids = array_map(fn($x) => (int)$x['id'], $students);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $tc  = "iii" . str_repeat('i', count($ids));
    $params = array_merge([$school_id, $class_id, $subject_id], $ids);

    $sql = "SELECT student_id, term, cat, fat, practical
            FROM marks
            WHERE school_id = ? AND class_id = ? AND subject_id = ? AND student_id IN ($ph)";
    $q = mysqli_prepare($conn, $sql);
    if ($q) {
        mysqli_stmt_bind_param($q, $tc, ...$params);
        mysqli_stmt_execute($q);
        $qr = mysqli_stmt_get_result($q);
        while ($row = mysqli_fetch_assoc($qr)) {
            $marks_map[(int)$row['student_id']][(int)$row['term']] = [
                'cat'       => $row['cat'],
                'fat'       => $row['fat'],
                'practical' => $row['practical'],
            ];
        }
        mysqli_stmt_close($q);
    }
}

/*
|--------------------------------------------------------------------------
| ATTENDANCE FOR SELECTED DATE
|--------------------------------------------------------------------------
*/
$att_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $att_date)) $att_date = date('Y-m-d');

$attendance_map = [];
if (!empty($students)) {
    $ids = array_map(fn($x) => (int)$x['id'], $students);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $tc  = "iiis" . str_repeat('i', count($ids));
    $params = array_merge([$school_id, $class_id, $subject_id, $att_date], $ids);

    $sql = "SELECT student_id, status
            FROM attendance
            WHERE school_id = ? AND class_id = ? AND subject_id = ? AND date = ? AND student_id IN ($ph)";
    $q = mysqli_prepare($conn, $sql);
    if ($q) {
        mysqli_stmt_bind_param($q, $tc, ...$params);
        mysqli_stmt_execute($q);
        $qr = mysqli_stmt_get_result($q);
        while ($row = mysqli_fetch_assoc($qr)) $attendance_map[(int)$row['student_id']] = $row['status'];
        mysqli_stmt_close($q);
    }
}

/*
|--------------------------------------------------------------------------
| POST — SAVE MARKS / ATTENDANCE
|--------------------------------------------------------------------------
*/
$flash = "";
$flash_type = "success";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (($_POST['action'] ?? '') === 'save_marks') {
        $rows = $_POST['marks'] ?? [];

        if (is_array($rows) && !empty($rows)) {
            $allowed_ids = [];
            foreach ($students as $st) $allowed_ids[(int)$st['id']] = true;

            $upd = mysqli_prepare($conn, "
                INSERT INTO marks (school_id, student_id, class_id, subject_id, term, cat, fat, practical)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    cat = VALUES(cat),
                    fat = VALUES(fat),
                    practical = VALUES(practical)
            ");
            if ($upd) {
                $saved = 0;
                foreach ($rows as $sid => $terms) {
                    $sid = (int)$sid;
                    if ($sid <= 0 || !isset($allowed_ids[$sid])) continue;
                    if (!is_array($terms)) continue;

                    foreach ([1, 2, 3] as $term) {
                        $cat  = isset($terms[$term]['cat'])       && $terms[$term]['cat']       !== '' ? (float)$terms[$term]['cat']       : 0;
                        $fat  = isset($terms[$term]['fat'])       && $terms[$term]['fat']       !== '' ? (float)$terms[$term]['fat']       : 0;
                        $prac = isset($terms[$term]['practical']) && $terms[$term]['practical'] !== '' ? (float)$terms[$term]['practical'] : 0;

                        mysqli_stmt_bind_param($upd, "iiiiiddd",
                            $school_id, $sid, $class_id, $subject_id, $term, $cat, $fat, $prac);
                        mysqli_stmt_execute($upd);
                        $saved++;
                    }
                }
                mysqli_stmt_close($upd);
                $flash = "Marks saved ({$saved} entries).";
            } else {
                $flash = "Could not prepare marks save.";
                $flash_type = "error";
            }
        } else {
            $flash = "Nothing to save.";
            $flash_type = "error";
        }
    }

    if (($_POST['action'] ?? '') === 'save_attendance') {
        $rows = $_POST['attendance'] ?? [];
        $att_date = $_POST['date'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $att_date)) $att_date = date('Y-m-d');

        if (is_array($rows)) {
            $allowed_ids = [];
            foreach ($students as $st) $allowed_ids[(int)$st['id']] = true;

            $upd = mysqli_prepare($conn, "
                INSERT INTO attendance (school_id, class_id, subject_id, student_id, date, status)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status)
            ");
            if ($upd) {
                $cnt = 0;
                foreach ($rows as $sid => $status) {
                    $sid = (int)$sid;
                    if ($sid <= 0 || !isset($allowed_ids[$sid])) continue;
                    $status = strtolower((string)$status);
                    if (!in_array($status, ['present','absent'], true)) continue;

                    mysqli_stmt_bind_param($upd, "iiiiss",
                        $school_id, $class_id, $subject_id, $sid, $att_date, $status);
                    mysqli_stmt_execute($upd);
                    $cnt++;
                }
                mysqli_stmt_close($upd);
                $flash = "Attendance saved for {$cnt} students on {$att_date}.";
            } else {
                $flash = "Could not prepare attendance save.";
                $flash_type = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($subject['subject_name']); ?> — <?= htmlspecialchars($class['class_name']); ?> | Educ Bridge</title>
<link rel="icon" type="image/png" href="logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    :root{
        --primary:#2563eb;--primary-dark:#1d4ed8;--primary-light:#dbeafe;
        --success:#10b981;--success-light:rgba(16,185,129,.1);
        --warning:#f59e0b;--warning-light:rgba(245,158,11,.12);
        --danger:#ef4444;--danger-light:rgba(239,68,68,.1);
        --sidebar-bg:#fff;--sidebar-hover:rgba(37,99,235,.06);--sidebar-active:rgba(37,99,235,.10);
        --sidebar-text:#475569;--sidebar-text-active:#0f172a;--sidebar-border:#e2e8f0;--sidebar-title:#94a3b8;
        --sidebar-badge-bg:rgba(37,99,235,.12);--sidebar-badge-fg:#2563eb;
        --bg-body:#f8fafc;--bg-card:#fff;--bg-alt:#f1f5f9;
        --text-primary:#0f172a;--text-secondary:#475569;--text-muted:#64748b;
        --border-color:#e2e8f0;
        --shadow-md:0 4px 12px rgba(0,0,0,.07);--shadow-lg:0 20px 45px rgba(0,0,0,.08);
        --shadow-sidebar:0 0 30px rgba(0,0,0,.05);
        --transition:all .3s cubic-bezier(.4,0,.2,1);
    }
    [data-theme="dark"]{
        --primary:#3b82f6;--primary-dark:#2563eb;--primary-light:#1e3a5f;
        --success:#34d399;--success-light:rgba(16,185,129,.15);
        --warning:#fbbf24;--warning-light:rgba(245,158,11,.18);
        --danger:#f87171;--danger-light:rgba(239,68,68,.15);
        --sidebar-bg:#0f172a;--sidebar-hover:rgba(255,255,255,.06);--sidebar-active:rgba(59,130,246,.18);
        --sidebar-text:#cbd5e1;--sidebar-text-active:#fff;--sidebar-border:rgba(255,255,255,.08);--sidebar-title:#94a3b8;
        --sidebar-badge-bg:rgba(59,130,246,.2);--sidebar-badge-fg:#93c5fd;
        --bg-body:#0b1120;--bg-card:#1e293b;--bg-alt:#1a2332;
        --text-primary:#f1f5f9;--text-secondary:#cbd5e1;--text-muted:#94a3b8;
        --border-color:#334155;
        --shadow-md:0 4px 12px rgba(0,0,0,.4);--shadow-lg:0 20px 45px rgba(0,0,0,.5);
        --shadow-sidebar:0 0 30px rgba(0,0,0,.4);
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
    html,body{min-height:100vh}
    body{font-family:'Inter',sans-serif;background:var(--bg-body);color:var(--text-primary);overflow-x:hidden}
    .layout{display:flex;min-height:100vh}

    .sidebar{width:260px;background:var(--sidebar-bg);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;border-right:1px solid var(--sidebar-border);box-shadow:var(--shadow-sidebar)}
    .sidebar-header{padding:22px;border-bottom:1px solid var(--sidebar-border);display:flex;align-items:center;gap:12px}
    .sidebar-header img{width:42px;height:42px;border-radius:50%;background:#fff;padding:2px;border:2px solid var(--sidebar-border)}
    .sidebar-brand{font-family:'Poppins',sans-serif;font-size:18px;font-weight:700;line-height:1.2}
    .sidebar-brand span{color:var(--primary)}
    .sidebar-brand small{display:block;font-size:11px;color:var(--text-muted);margin-top:2px;text-transform:uppercase;letter-spacing:.4px}
    .sidebar-nav{flex:1;padding:18px 12px;overflow-y:auto}
    .nav-section-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--sidebar-title);padding:12px 14px 8px}
    .nav-item{display:flex;align-items:center;gap:12px;padding:11px 14px;color:var(--sidebar-text);text-decoration:none;font-size:14px;font-weight:500;border-radius:10px;margin-bottom:4px;transition:var(--transition);position:relative}
    .nav-item i.nav-icon{width:20px;text-align:center;font-size:15px}
    .nav-item:hover{background:var(--sidebar-hover);color:var(--sidebar-text-active)}
    .nav-item.active{background:var(--sidebar-active);color:var(--sidebar-text-active)}
    .nav-item.active i.nav-icon{color:var(--primary)}
    .nav-item.active::before{content:'';position:absolute;left:0;top:8px;bottom:8px;width:3px;background:var(--primary);border-radius:0 4px 4px 0}
    .sidebar-profile{border-top:1px solid var(--sidebar-border);padding:16px 18px;display:flex;align-items:center;gap:12px}
    .sidebar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.35)}
    .sidebar-profile-info{flex:1;min-width:0}
    .sidebar-profile-info .p-name{font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-profile-info .p-role{font-size:11.5px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .sidebar-logout{width:34px;height:34px;border-radius:8px;background:var(--danger-light);color:var(--danger);display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:13px;flex-shrink:0}

    .main{flex:1;margin-left:260px;min-height:100vh;display:flex;flex-direction:column}
    .topbar{background:var(--bg-card);border-bottom:1px solid var(--border-color);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:50}
    .topbar-left{display:flex;align-items:center;gap:14px}
    .hamburger{display:none;width:40px;height:40px;border-radius:10px;border:1.5px solid var(--border-color);background:var(--bg-body);color:var(--text-secondary);align-items:center;justify-content:center;cursor:pointer;font-size:15px}
    .page-title{font-family:'Poppins',sans-serif;font-size:20px;font-weight:700;letter-spacing:-.4px}
    .page-subtitle{font-size:13px;color:var(--text-muted);margin-top:2px}
    .topbar-actions{display:flex;align-items:center;gap:10px}
    .topbar-btn{width:42px;height:42px;border-radius:50%;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-secondary);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:15px;transition:var(--transition);text-decoration:none}
    .topbar-btn:hover{border-color:var(--primary);color:var(--primary);transform:translateY(-1px)}
    .topbar-btn.theme-btn:hover{transform:rotate(20deg)}
    .topbar-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;box-shadow:0 4px 12px rgba(59,130,246,.3)}

    .content{padding:28px;flex:1}

    .alert{padding:13px 16px;border-radius:10px;margin-bottom:20px;font-size:14px;display:flex;align-items:flex-start;gap:10px}
    .alert-success{background:var(--success-light);color:#059669;border:1px solid rgba(16,185,129,.25)}
    [data-theme="dark"] .alert-success{color:#34d399}
    .alert-error{background:var(--danger-light);color:#dc2626;border:1px solid rgba(239,68,68,.25)}
    [data-theme="dark"] .alert-error{color:#f87171}

    .page-head{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px;flex-wrap:wrap}
    .page-head h1{font-family:'Poppins',sans-serif;font-size:22px;font-weight:700;display:flex;align-items:center;gap:10px}
    .page-head h1 i{color:var(--primary)}
    .page-head .sub{font-size:13.5px;color:var(--text-muted);margin-top:4px}
    .page-head .sub strong{color:var(--text-primary)}

    .crumbs{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--text-muted);margin-bottom:14px;flex-wrap:wrap}
    .crumbs a{color:var(--primary);text-decoration:none;font-weight:600}
    .crumbs a:hover{text-decoration:underline}
    .crumbs i.sep{font-size:9px;opacity:.6}

    .card{max-width:1240px;margin:0 auto 28px;background:var(--bg-card);border:1px solid var(--border-color);border-radius:20px;box-shadow:var(--shadow-md);overflow:hidden}
    .card-head{padding:20px 24px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;background:linear-gradient(135deg,rgba(37,99,235,.05),rgba(124,58,237,.03))}
    .card-head h2{font-family:'Poppins',sans-serif;font-size:16px;font-weight:700;display:flex;align-items:center;gap:10px}
    .card-head h2 i{color:var(--primary)}
    .card-body{padding:22px 24px}

    .btn{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:10px;font-size:13.5px;font-weight:700;cursor:pointer;border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);text-decoration:none;transition:var(--transition);font-family:inherit}
    .btn:hover{border-color:var(--primary);color:var(--primary)}
    .btn.primary{background:var(--primary);color:#fff;border-color:var(--primary);box-shadow:0 6px 18px rgba(37,99,235,.32)}
    .btn.primary:hover{background:var(--primary-dark);color:#fff}
    .btn.success{background:var(--success);color:#fff;border-color:var(--success);box-shadow:0 6px 18px rgba(16,185,129,.32)}
    .btn.success:hover{background:#059669;color:#fff}
    .btn.ghost{background:transparent}
    .btn:disabled{opacity:.55;cursor:not-allowed}

    .mode-toggle{display:inline-flex;align-items:center;gap:10px;padding:6px 8px 6px 14px;border:1.5px solid var(--border-color);border-radius:50px;background:var(--bg-card);font-size:13px;font-weight:700;color:var(--text-secondary);cursor:pointer;user-select:none}
    .mode-toggle .label{letter-spacing:.4px}
    .mode-toggle .switch{position:relative;width:44px;height:24px;border-radius:50px;background:var(--bg-alt);transition:var(--transition)}
    .mode-toggle .switch::after{content:'';position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,.2);transition:var(--transition)}
    .mode-toggle.on{color:var(--primary);border-color:var(--primary);background:var(--primary-light)}
    .mode-toggle.on .switch{background:var(--primary)}
    .mode-toggle.on .switch::after{left:24px}

    .table-wrap{overflow-x:auto;border:1px solid var(--border-color);border-radius:12px}
    table.marks{width:100%;border-collapse:collapse;font-size:13.5px;min-width:1040px}
    table.marks thead th{
        text-align:left;padding:12px 12px;
        background:var(--bg-alt);
        font-size:11.5px;font-weight:800;color:var(--text-muted);
        text-transform:uppercase;letter-spacing:.6px;
        border-bottom:1px solid var(--border-color);white-space:nowrap
    }
    table.marks thead tr.group th{border-bottom:1px dashed var(--border-color);background:var(--bg-card);text-align:center}
    table.marks tbody td{padding:11px 12px;border-bottom:1px solid var(--border-color);vertical-align:middle}
    table.marks tbody tr:last-child td{border-bottom:none}
    table.marks tbody tr:hover{background:var(--sidebar-hover)}
    table.marks td.center{text-align:center}

    .student-cell{display:flex;align-items:center;gap:10px}
    .student-avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0}
    .student-name{font-weight:600}
    .student-sub{font-size:11.5px;color:var(--text-muted)}

    .mark-field{position:relative}
    .mark-field label{position:absolute;top:-6px;left:6px;padding:0 4px;font-size:9.5px;font-weight:800;color:var(--text-muted);background:var(--bg-card);letter-spacing:.6px}
    table.marks tbody tr:hover .mark-field label{background:var(--sidebar-hover)}
    .mark-field input{
        width:100%;padding:8px 8px;font-family:inherit;font-size:13px;font-weight:700;
        border:1.5px solid var(--border-color);border-radius:8px;background:var(--bg-card);
        color:var(--text-primary);text-align:center;outline:none;transition:var(--transition)
    }
    .mark-field input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
    .mark-field input[readonly]{background:var(--bg-alt);border-style:dashed;cursor:default}

    .att-controls{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    .att-controls input[type=date]{padding:10px 12px;border:1.5px solid var(--border-color);border-radius:10px;background:var(--bg-card);font-family:inherit;font-size:14px;outline:none}
    .att-controls input[type=date]:focus{border-color:var(--primary)}

    .radio-group{display:flex;gap:6px;justify-content:center}
    .radio-group label{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;border:1.5px solid var(--border-color);cursor:pointer;font-size:12.5px;font-weight:700;color:var(--text-secondary);background:var(--bg-card);transition:var(--transition)}
    .radio-group input{display:none}
    .radio-group label.p:hover{border-color:var(--success);color:var(--success)}
    .radio-group label.a:hover{border-color:var(--danger);color:var(--danger)}
    .radio-group label.p.selected{background:var(--success-light);border-color:var(--success);color:var(--success)}
    .radio-group label.a.selected{background:var(--danger-light);border-color:var(--danger);color:var(--danger)}
    .radio-group input:disabled + label{opacity:.6;cursor:not-allowed}

    .empty{padding:40px 20px;text-align:center;color:var(--text-muted)}
    .empty i{font-size:28px;display:block;margin-bottom:10px;opacity:.6}

    .overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:90;backdrop-filter:blur(2px)}
    .overlay.show{display:block}

    @media (max-width: 900px) { .sidebar { transform:translateX(-100%); } .sidebar.open { transform:translateX(0); } .main { margin-left:0; } .hamburger { display:flex; } }
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
            
            <a href="teacher-subjects.php" class="nav-item active"><i class="fas fa-book nav-icon"></i> Subjects</a>
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
                    <div class="page-title"><?= htmlspecialchars($subject['subject_name']); ?></div>
                    <div class="page-subtitle"><?= htmlspecialchars($class['class_name']); ?> · <?= htmlspecialchars($school_name); ?></div>
                </div>
            </div>
            <div class="topbar-actions">
                <div class="mode-toggle" id="modeToggle" title="Switch between view and edit mode">
                    <span class="label" id="modeLabel">View mode</span>
                    <span class="switch"></span>
                </div>
                <button class="topbar-btn theme-btn" id="themeToggle" aria-label="Toggle theme"><i class="fas fa-moon"></i></button>
                <div class="topbar-avatar" title="<?= htmlspecialchars($teacher_name); ?>"><?= htmlspecialchars($initials); ?></div>
            </div>
        </header>

        <main class="content">

            <div class="crumbs">
                <a href="teacher-subjects.php"><i class="fas fa-book"></i> Subjects</a>
                <i class="fas fa-chevron-right sep"></i>
                <span><?= htmlspecialchars($subject['subject_name']); ?></span>
                <i class="fas fa-chevron-right sep"></i>
                <span><?= htmlspecialchars($class['class_name']); ?></span>
            </div>

            <?php if ($flash !== ''): ?>
                <div class="alert alert-<?= $flash_type === 'error' ? 'error' : 'success'; ?>">
                    <i class="fas <?= $flash_type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>" style="margin-top:2px;"></i>
                    <span><?= htmlspecialchars($flash); ?></span>
                </div>
            <?php endif; ?>

            <div class="page-head">
                <div>
                    <h1><i class="fas fa-pen-to-square"></i> Marks &amp; attendance</h1>
                    <div class="sub">
                        <strong><?= count($students); ?></strong> student<?= count($students) === 1 ? '' : 's'; ?>
                        · 3 terms · CAT + FAT · Practical
                        · Flip the <strong>View / Edit</strong> toggle to unlock editing.
                    </div>
                </div>
                <a href="teacher-subjects.php" class="btn ghost"><i class="fas fa-arrow-left"></i> Back to subjects</a>
            </div>

            <!-- ======================= MARKS ======================= -->
            <form method="POST" action="teacher-open-class.php?class_id=<?= $class_id; ?>&subject_id=<?= $subject_id; ?>" id="marksForm">
                <input type="hidden" name="action" value="save_marks">

                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-table-list"></i> Student marks</h2>
                        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                            <span class="student-sub">3 terms · CAT · FAT · Practical</span>
                            <button type="submit" class="btn success" id="saveMarksBtn" disabled>
                                <i class="fas fa-floppy-disk"></i> Save marks
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($students)): ?>
                            <div class="empty">
                                <i class="fas fa-user-graduate"></i>
                                No students in this class yet.
                            </div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="marks">
                                    <thead>
                                        <tr class="group">
                                            <th rowspan="2" style="text-align:left;vertical-align:bottom;">Student</th>
                                            <th colspan="2" style="text-align:center;">Term 1</th>
                                            <th colspan="2" style="text-align:center;">Term 2</th>
                                            <th colspan="2" style="text-align:center;">Term 3</th>
                                            <th rowspan="2" style="text-align:center;vertical-align:bottom;">Practical</th>
                                        </tr>
                                        <tr class="group">
                                            <th style="text-align:center;">CAT</th><th style="text-align:center;">FAT</th>
                                            <th style="text-align:center;">CAT</th><th style="text-align:center;">FAT</th>
                                            <th style="text-align:center;">CAT</th><th style="text-align:center;">FAT</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($students as $st):
                                        $sid = (int)$st['id'];
                                        $m = $marks_map[$sid] ?? [];
                                    ?>
                                        <tr>
                                            <td>
                                                <div class="student-cell">
                                                    <div class="student-avatar"><?= htmlspecialchars(initials_from_name($st['full_name'])); ?></div>
                                                    <div>
                                                        <div class="student-name"><?= htmlspecialchars($st['full_name']); ?></div>
                                                        <div class="student-sub">
                                                            <?= !empty($st['admission_no']) ? 'Adm: ' . htmlspecialchars($st['admission_no']) : ('#' . $sid); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>

                                            <?php foreach ([1,2,3] as $term):
                                                $cat = fmt_mark($m[$term]['cat'] ?? null);
                                                $fat = fmt_mark($m[$term]['fat'] ?? null);
                                            ?>
                                                <td class="center">
                                                    <div class="mark-field">
                                                        <label>CAT</label>
                                                        <input type="number" step="0.01" min="0" max="100"
                                                               name="marks[<?= $sid; ?>][<?= $term; ?>][cat]"
                                                               value="<?= htmlspecialchars($cat); ?>"
                                                               placeholder="—" readonly>
                                                    </div>
                                                </td>
                                                <td class="center">
                                                    <div class="mark-field">
                                                        <label>FAT</label>
                                                        <input type="number" step="0.01" min="0" max="100"
                                                               name="marks[<?= $sid; ?>][<?= $term; ?>][fat]"
                                                               value="<?= htmlspecialchars($fat); ?>"
                                                               placeholder="—" readonly>
                                                    </div>
                                                </td>
                                            <?php endforeach; ?>

                                            <?php $practical = fmt_mark($m[1]['practical'] ?? ($m[2]['practical'] ?? ($m[3]['practical'] ?? null))); ?>
                                            <td class="center">
                                                <div class="mark-field">
                                                    <label>PRAC</label>
                                                    <input type="number" step="0.01" min="0" max="100"
                                                           name="marks[<?= $sid; ?>][1][practical]"
                                                           value="<?= htmlspecialchars($practical); ?>"
                                                           placeholder="—" readonly>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <!-- ======================= ATTENDANCE ======================= -->
            <form method="POST" action="teacher-open-class.php?class_id=<?= $class_id; ?>&subject_id=<?= $subject_id; ?>" id="attendanceForm">
                <input type="hidden" name="action" value="save_attendance">

                <div class="card">
                    <div class="card-head">
                        <h2><i class="fas fa-clipboard-user"></i> Attendance</h2>
                        <div class="att-controls">
                            <label for="att_date" style="font-size:12.5px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;">Date</label>
                            <input type="date" id="att_date" name="date" value="<?= htmlspecialchars($att_date); ?>">
                            <button type="submit" class="btn primary" id="saveAttBtn" disabled>
                                <i class="fas fa-floppy-disk"></i> Save attendance
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($students)): ?>
                            <div class="empty">
                                <i class="fas fa-users-slash"></i>
                                No students in this class yet.
                            </div>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table class="marks" style="min-width:auto;">
                                    <thead>
                                        <tr>
                                            <th style="text-align:left;">Student</th>
                                            <th style="text-align:center;">Attendance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $st):
                                            $sid = (int)$st['id'];
                                            $cur = $attendance_map[$sid] ?? '';
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="student-cell">
                                                        <div class="student-avatar"><?= htmlspecialchars(initials_from_name($st['full_name'])); ?></div>
                                                        <div>
                                                            <div class="student-name"><?= htmlspecialchars($st['full_name']); ?></div>
                                                            <div class="student-sub">
                                                                <?= !empty($st['admission_no']) ? 'Adm: ' . htmlspecialchars($st['admission_no']) : ('#' . $sid); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="center">
                                                    <div class="radio-group">
                                                        <input type="radio"
                                                               id="att_p_<?= $sid; ?>"
                                                               name="attendance[<?= $sid; ?>]"
                                                               value="present"
                                                               <?= $cur === 'present' ? 'checked' : ''; ?>
                                                               disabled>
                                                        <label for="att_p_<?= $sid; ?>" class="p <?= $cur === 'present' ? 'selected' : ''; ?>">
                                                            <i class="fas fa-check"></i> Present
                                                        </label>

                                                        <input type="radio"
                                                               id="att_a_<?= $sid; ?>"
                                                               name="attendance[<?= $sid; ?>]"
                                                               value="absent"
                                                               <?= $cur === 'absent' ? 'checked' : ''; ?>
                                                               disabled>
                                                        <label for="att_a_<?= $sid; ?>" class="a <?= $cur === 'absent' ? 'selected' : ''; ?>">
                                                            <i class="fas fa-xmark"></i> Absent
                                                        </label>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

        </main>
    </div>
</div>

<script>
    /* THEME */
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

    /* SIDEBAR (mobile) */
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

    /* EDIT MODE TOGGLE */
    const modeToggle   = document.getElementById('modeToggle');
    const modeLabel    = document.getElementById('modeLabel');
    const marksForm    = document.getElementById('marksForm');
    const attendanceForm = document.getElementById('attendanceForm');
    const saveMarksBtn = document.getElementById('saveMarksBtn');
    const saveAttBtn   = document.getElementById('saveAttBtn');

    function setEditMode(on) {
        modeToggle.classList.toggle('on', on);
        modeLabel.textContent = on ? 'Edit mode' : 'View mode';

        document.querySelectorAll('.mark-field input').forEach(inp => {
            inp.readOnly = !on;
        });

        document.querySelectorAll('.radio-group input[type="radio"]').forEach(r => {
            r.disabled = !on;
        });

        saveMarksBtn.disabled = !on;
        saveAttBtn.disabled   = !on;
    }

    modeToggle.addEventListener('click', () => {
        setEditMode(!modeToggle.classList.contains('on'));
    });

    /* Radio label styling */
    document.querySelectorAll('.radio-group').forEach(group => {
        group.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', () => {
                group.querySelectorAll('label').forEach(l => l.classList.remove('selected'));
                const lbl = group.querySelector('label[for="' + radio.id + '"]');
                if (lbl) lbl.classList.add('selected');
            });
        });
    });

    /* Submit guard */
    marksForm?.addEventListener('submit', function(){
        saveMarksBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        saveMarksBtn.disabled = true;
    });
    attendanceForm?.addEventListener('submit', function(){
        saveAttBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        saveAttBtn.disabled = true;
    });

    /* Date change reloads the page so attendance for that date can be loaded */
    const attDate = document.getElementById('att_date');
    if (attDate) {
        attDate.addEventListener('change', function(){
            const url = new URL(window.location.href);
            url.searchParams.set('date', this.value);
            window.location.href = url.toString();
        });
    }
</script>

</body>
</html>