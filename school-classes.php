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
        $_SESSION['school_id'], $_SESSION['school_name'], $_SESSION['school_admin'],
        $_SESSION['school_email'], $_SESSION['school_code'], $_SESSION['school_verified']
    );
    header("Location: school-login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| STANDARD LEVELS (matches academic_levels table)
|--------------------------------------------------------------------------
*/
$STANDARD_LEVELS = [
    'nursery' => [
        'enum'  => 'Nursery',
        'name'  => 'Nursery',
        'desc'  => 'Baby · Middle · Top class',
        'icon'  => 'fa-baby',
        'color' => 'pink',
    ],
    'primary' => [
        'enum'  => 'Primary',
        'name'  => 'Primary',
        'desc'  => 'P1 – P6',
        'icon'  => 'fa-child',
        'color' => 'blue',
    ],
    'olevel'  => [
        'enum'  => 'O_Level',
        'name'  => "O'Level",
        'desc'  => 'S1 – S3',
        'icon'  => 'fa-book-open',
        'color' => 'orange',
    ],
    'alevel'  => [
        'enum'  => 'A_Level',
        'name'  => "A'Level",
        'desc'  => 'S4 – S6',
        'icon'  => 'fa-graduation-cap',
        'color' => 'purple',
    ],
];

/*
|--------------------------------------------------------------------------
| AUTO-CREATE classes TABLE (if missing)
|--------------------------------------------------------------------------
*/
@mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS classes (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    school_id        INT NOT NULL,
    class_name       VARCHAR(120) NOT NULL,
    level            ENUM('Nursery','Primary','O_Level','A_Level') NOT NULL,
    capacity         INT DEFAULT NULL,
    room             VARCHAR(80) DEFAULT NULL,
    class_teacher_id INT DEFAULT NULL,
    status           ENUM('active','inactive') DEFAULT 'active',
    created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_classes_school (school_id),
    KEY idx_classes_level  (school_id, level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/*
|--------------------------------------------------------------------------
| FETCH ACTIVE ACADEMIC LEVELS FOR THIS SCHOOL
|--------------------------------------------------------------------------
*/
$active_level_keys = []; // ['nursery' => true, ...]
$active_levels     = []; // ['nursery' => ['name'=>..,'desc'=>..], ...]

$l_stmt = mysqli_prepare($conn, "
    SELECT level_key
    FROM academic_levels
    WHERE school_id = ? AND is_active = 1
");
if ($l_stmt) {
    mysqli_stmt_bind_param($l_stmt, "i", $school_id);
    mysqli_stmt_execute($l_stmt);
    $l_res = mysqli_stmt_get_result($l_stmt);
    while ($row = mysqli_fetch_assoc($l_res)) {
        $key = $row['level_key'];
        if (isset($STANDARD_LEVELS[$key])) {
            $active_level_keys[$key] = true;
            $active_levels[$key]     = $STANDARD_LEVELS[$key];
        }
    }
    mysqli_stmt_close($l_stmt);
}

/*
|--------------------------------------------------------------------------
| HANDLE DELETE
|--------------------------------------------------------------------------
*/
$message = ""; $message_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_class') {
    $del_id = (int) ($_POST['class_id'] ?? 0);
    if ($del_id > 0) {
        // Verify ownership
        $chk = mysqli_prepare($conn, "SELECT id FROM classes WHERE id = ? AND school_id = ? LIMIT 1");
        mysqli_stmt_bind_param($chk, "ii", $del_id, $school_id);
        mysqli_stmt_execute($chk);
        $chk_res = mysqli_stmt_get_result($chk);
        $owns = mysqli_num_rows($chk_res) > 0;
        mysqli_stmt_close($chk);

        if ($owns) {
            // Check students in this class (try both possible column names)
            $students_in_class = 0;

            $col_check = @mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'class_id'");
            $has_class_id = $col_check && mysqli_num_rows($col_check) > 0;

            $col_check2 = @mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'student_class_id'");
            $has_student_class_id = $col_check2 && mysqli_num_rows($col_check2) > 0;

            if ($has_class_id) {
                $s = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM students WHERE school_id = ? AND class_id = ?");
                if ($s) {
                    mysqli_stmt_bind_param($s, "ii", $school_id, $del_id);
                    mysqli_stmt_execute($s);
                    $r = mysqli_stmt_get_result($s);
                    $students_in_class = (int)(mysqli_fetch_assoc($r)['total'] ?? 0);
                    mysqli_stmt_close($s);
                }
            } elseif ($has_student_class_id) {
                $s = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM students WHERE school_id = ? AND student_class_id = ?");
                if ($s) {
                    mysqli_stmt_bind_param($s, "ii", $school_id, $del_id);
                    mysqli_stmt_execute($s);
                    $r = mysqli_stmt_get_result($s);
                    $students_in_class = (int)(mysqli_fetch_assoc($r)['total'] ?? 0);
                    mysqli_stmt_close($s);
                }
            }

            if ($students_in_class > 0) {
                $message = "Cannot delete this class — {$students_in_class} student(s) are still assigned to it. Move them first.";
                $message_type = "error";
            } else {
                $del = mysqli_prepare($conn, "DELETE FROM classes WHERE id = ? AND school_id = ?");
                mysqli_stmt_bind_param($del, "ii", $del_id, $school_id);
                if (mysqli_stmt_execute($del)) {
                    $message = "Class deleted successfully.";
                    $message_type = "success";
                } else {
                    $message = "Failed to delete class.";
                    $message_type = "error";
                }
                mysqli_stmt_close($del);
            }
        } else {
            $message = "Class not found.";
            $message_type = "error";
        }
    }
}

/* Flash from redirects */
if (isset($_GET['added']))   { $message = "Class created successfully.";  $message_type = "success"; }
if (isset($_GET['updated'])) { $message = "Class updated successfully.";  $message_type = "success"; }
if (isset($_GET['error']))   { $message = urldecode($_GET['error']);       $message_type = "error"; }

/*
|--------------------------------------------------------------------------
| FETCH ALL CLASSES OF THIS SCHOOL (+ teacher name)
|--------------------------------------------------------------------------
*/
$classes_by_level = []; // enum_level => [rows]
$total_classes    = 0;

$sql = "
    SELECT c.*, t.name AS teacher_name
    FROM classes c
    LEFT JOIN teachers t ON t.id = c.class_teacher_id
    WHERE c.school_id = ?
    ORDER BY c.class_name ASC
";
$stmt = mysqli_prepare($conn, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $school_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $classes_by_level[$row['level']][] = $row;
        $total_classes++;
    }
    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| COUNT STUDENTS PER CLASS (auto-detect column)
|--------------------------------------------------------------------------
*/
$students_per_class = [];

$col_check  = @mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'class_id'");
$has_class_id = $col_check && mysqli_num_rows($col_check) > 0;

$col_check2 = @mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'student_class_id'");
$has_student_class_id = $col_check2 && mysqli_num_rows($col_check2) > 0;

$student_col = $has_class_id ? 'class_id' : ($has_student_class_id ? 'student_class_id' : null);

if ($student_col) {
    $s = mysqli_prepare($conn, "SELECT {$student_col} AS cid, COUNT(*) AS total FROM students WHERE school_id = ? AND {$student_col} IS NOT NULL GROUP BY {$student_col}");
    if ($s) {
        mysqli_stmt_bind_param($s, "i", $school_id);
        mysqli_stmt_execute($s);
        $r = mysqli_stmt_get_result($s);
        while ($row = mysqli_fetch_assoc($r)) {
            $students_per_class[(int)$row['cid']] = (int)$row['total'];
        }
        mysqli_stmt_close($s);
    }
}

/*
|--------------------------------------------------------------------------
| CURRENT FILTER (from ?level=)
|--------------------------------------------------------------------------
*/
$current_level_key = isset($_GET['level']) ? trim($_GET['level']) : '';
if ($current_level_key !== '' && !isset($active_level_keys[$current_level_key])) {
    $current_level_key = '';
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Classes | Educ Bridge</title>
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
            --danger: #ef4444; --danger-light: rgba(239,68,68,0.1);
            --sidebar-bg: #ffffff; --sidebar-hover: rgba(37,99,235,0.06);
            --sidebar-active: rgba(37,99,235,0.10); --sidebar-text: #475569;
            --sidebar-text-active: #0f172a; --sidebar-border: #e2e8f0; --sidebar-title: #94a3b8;
            --sidebar-avatar-from: #2563eb; --sidebar-avatar-to: #7c3aed;
            --bg-body: #f8fafc; --bg-card: #ffffff; --bg-alt: #f1f5f9;
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
            --danger: #f87171; --danger-light: rgba(239,68,68,0.15);
            --sidebar-bg: #0f172a; --sidebar-hover: rgba(255,255,255,0.06);
            --sidebar-active: rgba(59,130,246,0.18); --sidebar-text: #cbd5e1;
            --sidebar-text-active: #ffffff; --sidebar-border: rgba(255,255,255,0.08); --sidebar-title: #94a3b8;
            --sidebar-avatar-from: #3b82f6; --sidebar-avatar-to: #8b5cf6;
            --bg-body: #0b1120; --bg-card: #1e293b; --bg-alt: #1a2332;
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

        /* SIDEBAR */
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
        .nav-item .nav-badge { margin-left:auto; background:var(--primary-light); color:var(--primary); font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:50px; line-height:1.5; }
        .sidebar-profile { border-top:1px solid var(--sidebar-border); padding:16px 18px; display:flex; align-items:center; gap:12px; }
        .sidebar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,var(--sidebar-avatar-from),var(--sidebar-avatar-to)); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:15px; font-weight:700; flex-shrink:0; box-shadow:0 4px 12px rgba(37,99,235,0.35); }
        .sidebar-profile-info { flex:1; min-width:0; }
        .sidebar-profile-info .p-name { font-size:13.5px; font-weight:600; color:var(--text-primary); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-profile-info .p-role { font-size:11.5px; color:var(--text-muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sidebar-logout { width:34px; height:34px; border-radius:8px; background:var(--danger-light); color:var(--danger); display:flex; align-items:center; justify-content:center; text-decoration:none; font-size:13px; flex-shrink:0; transition:var(--transition); }
        .sidebar-logout:hover { background:var(--danger); color:#fff; }

        /* MAIN */
        .main { flex:1; margin-left:260px; min-height:100vh; display:flex; flex-direction:column; transition:margin-left 0.3s ease; }
        .topbar { background:var(--bg-card); border-bottom:1px solid var(--border-color); padding:14px 28px; display:flex; align-items:center; justify-content:space-between; gap:16px; position:sticky; top:0; z-index:50; transition:background 0.4s ease, border-color 0.4s ease; }
        .topbar-left { display:flex; align-items:center; gap:14px; }
        .hamburger { display:none; width:40px; height:40px; border-radius:10px; border:1.5px solid var(--border-color); background:var(--bg-body); color:var(--text-secondary); align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); }
        .hamburger:hover { border-color:var(--primary); color:var(--primary); }
        .page-title { font-family:'Poppins',sans-serif; font-size:20px; font-weight:700; color:var(--text-primary); letter-spacing:-0.4px; }
        .page-subtitle { font-size:13px; color:var(--text-muted); margin-top:2px; }
        .topbar-actions { display:flex; align-items:center; gap:10px; }
        .topbar-btn { width:42px; height:42px; border-radius:50%; border:1.5px solid var(--border-color); background:var(--bg-card); color:var(--text-secondary); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:15px; transition:var(--transition); text-decoration:none; }
        .topbar-btn:hover { border-color:var(--primary); color:var(--primary); transform:translateY(-1px); }
        .topbar-btn.theme-btn:hover { transform:rotate(20deg); }
        .topbar-avatar { width:42px; height:42px; border-radius:50%; background:linear-gradient(135deg,#3b82f6,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-family:'Poppins',sans-serif; font-size:14px; font-weight:700; box-shadow:0 4px 12px rgba(59,130,246,0.3); cursor:pointer; }

        /* CONTENT */
        .content { padding:28px; flex:1; }

        /* Alerts */
        .alert { padding:14px 18px; border-radius:12px; font-size:14px; font-weight:500; margin-bottom:20px; display:flex; align-items:center; gap:10px; border:1px solid transparent; }
        .alert.success { background:var(--success-light); color:var(--success); border-color:rgba(16,185,129,0.25); }
        .alert.error   { background:var(--danger-light);  color:var(--danger);  border-color:rgba(239,68,68,0.25); }

        /* HEADER CARD */
        .page-header-card {
            background: linear-gradient(135deg, var(--primary), #7c3aed);
            border-radius: 18px; padding: 28px 32px; color:#fff; margin-bottom:24px;
            position: relative; overflow: hidden;
            box-shadow: 0 12px 36px rgba(37,99,235,0.25);
            display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap;
        }
        .page-header-card::before {
            content:''; position:absolute; top:-50%; right:-10%;
            width:320px; height:320px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
            border-radius:50%;
        }
        .page-header-card h2 {
            font-family:'Poppins',sans-serif; font-size:24px; font-weight:700;
            margin-bottom:8px; position:relative; z-index:1;
            letter-spacing:-0.5px; display:flex; align-items:center; gap:12px;
        }
        .page-header-card p {
            font-size:14.5px; opacity:0.92; position:relative; z-index:1;
            max-width:640px; line-height:1.6;
        }
        .header-add-btn {
            position: relative; z-index:1;
            display:inline-flex; align-items:center; gap:9px;
            padding: 12px 20px; border-radius: 11px;
            background: rgba(255,255,255,0.18); color:#fff;
            border: 1.5px solid rgba(255,255,255,0.35);
            font-size: 14px; font-weight: 700; text-decoration:none;
            transition: var(--transition); white-space: nowrap;
        }
        .header-add-btn:hover { background:#fff; color:var(--primary); }

        /* STATS ROW */
        .mini-stats {
            display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 14px; margin-bottom: 24px;
        }
        .mini-stat {
            background: var(--bg-card); border: 1px solid var(--border-color);
            border-radius: 14px; padding: 16px 18px;
            display:flex; align-items:center; gap: 14px;
            transition: var(--transition);
        }
        .mini-stat:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); border-color: var(--primary); }
        .mini-stat .ms-icon {
            width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0;
            display:flex; align-items:center; justify-content:center;
            color:#fff; font-size: 19px;
        }
        .mini-stat .ms-icon.blue   { background: linear-gradient(135deg,#2563eb,#3b82f6); }
        .mini-stat .ms-icon.green  { background: linear-gradient(135deg,#10b981,#34d399); }
        .mini-stat .ms-icon.orange { background: linear-gradient(135deg,#f59e0b,#fbbf24); }
        .mini-stat .ms-icon.purple { background: linear-gradient(135deg,#7c3aed,#a78bfa); }
        .mini-stat .ms-info .num { font-family:'Poppins',sans-serif; font-size: 22px; font-weight: 700; color: var(--text-primary); line-height:1.2; }
        .mini-stat .ms-info .lbl { font-size: 12.5px; color: var(--text-muted); margin-top: 2px; font-weight: 500; }

        /* LEVEL TABS */
        .level-tabs {
            display:flex; gap: 10px; flex-wrap: wrap;
            margin-bottom: 22px;
            padding: 6px; background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 14px;
        }
        .level-tab {
            display:inline-flex; align-items:center; gap: 9px;
            padding: 10px 16px; border-radius: 10px;
            font-size: 13.5px; font-weight: 600;
            color: var(--text-secondary); text-decoration: none;
            transition: var(--transition); white-space: nowrap;
        }
        .level-tab:hover { background: var(--bg-alt); color: var(--primary); }
        .level-tab.active { background: var(--primary); color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,0.3); }
        .level-tab i { font-size: 14px; }
        .level-tab .tab-count {
            margin-left: 4px;
            background: rgba(255,255,255,0.25);
            color: inherit; font-size: 11px; font-weight: 700;
            padding: 1.5px 8px; border-radius: 50px; line-height:1.4;
        }
        .level-tab:not(.active) .tab-count { background: var(--bg-alt); color: var(--text-muted); }

        /* LEVEL SECTION */
        .level-section { margin-bottom: 34px; }
        .level-section-head {
            display:flex; align-items:center; justify-content:space-between;
            gap: 14px; flex-wrap: wrap; margin-bottom: 16px;
        }
        .level-section-title {
            display:flex; align-items:center; gap: 14px;
        }
        .level-section-title .ls-icon {
            width: 48px; height: 48px; border-radius: 13px;
            display:flex; align-items:center; justify-content:center;
            color:#fff; font-size: 20px; flex-shrink: 0;
            box-shadow: 0 6px 16px rgba(0,0,0,0.12);
        }
        .ls-icon.pink   { background: linear-gradient(135deg,#ec4899,#f472b6); }
        .ls-icon.blue   { background: linear-gradient(135deg,#2563eb,#3b82f6); }
        .ls-icon.orange { background: linear-gradient(135deg,#f59e0b,#fbbf24); }
        .ls-icon.purple { background: linear-gradient(135deg,#7c3aed,#a78bfa); }
        .level-section-title h3 {
            font-family:'Poppins',sans-serif; font-size: 18px; font-weight: 700;
            color: var(--text-primary); letter-spacing:-0.3px;
            display:flex; align-items:center; gap: 10px;
        }
        .level-section-title h3 .badge {
            background: var(--primary-light); color: var(--primary);
            font-family:'Inter',sans-serif; font-size: 11.5px; font-weight: 700;
            padding: 3px 10px; border-radius: 50px;
        }
        .level-section-title .ls-desc {
            font-size: 12.5px; color: var(--text-muted); margin-top: 2px;
        }

        .add-class-btn {
            display:inline-flex; align-items:center; gap: 8px;
            padding: 10px 16px; border-radius: 10px;
            background: var(--primary); color:#fff; font-size: 13.5px; font-weight: 600;
            text-decoration:none; transition: var(--transition);
            box-shadow: 0 4px 12px rgba(37,99,235,0.25);
            white-space: nowrap;
        }
        .add-class-btn:hover { background: var(--primary-dark); transform: translateY(-1px); box-shadow: 0 6px 16px rgba(37,99,235,0.35); }

        /* CLASSES GRID */
        .classes-grid {
            display:grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
        }
        .class-card {
            background: var(--bg-card);
            border: 1.5px solid var(--border-color);
            border-radius: 16px; padding: 20px;
            transition: var(--transition);
            display:flex; flex-direction:column;
            position: relative;
        }
        .class-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); border-color: var(--primary); }
        .class-card.inactive { opacity: 0.72; }

        .class-card-top {
            display:flex; align-items:flex-start; justify-content:space-between;
            gap: 12px; margin-bottom: 14px;
        }
        .class-name {
            font-family:'Poppins',sans-serif; font-size: 19px; font-weight: 700;
            color: var(--text-primary); letter-spacing:-0.3px; line-height:1.25;
            word-break: break-word;
        }
        .class-status {
            flex-shrink: 0;
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 4px 10px; border-radius: 50px;
            display:inline-flex; align-items:center; gap: 4px;
        }
        .class-status.active   { background: var(--success-light); color: var(--success); }
        .class-status.inactive { background: var(--bg-alt); color: var(--text-muted); border: 1px solid var(--border-color); }
        .class-status i { font-size: 8px; }

        .class-meta {
            display:flex; flex-direction:column; gap: 8px;
            margin-bottom: 16px; flex: 1;
        }
        .cm-row {
            display:flex; align-items:center; gap: 9px;
            font-size: 13px; color: var(--text-secondary);
        }
        .cm-row i { width: 16px; text-align: center; color: var(--primary); font-size: 13px; flex-shrink: 0; }
        .cm-row .cm-label { color: var(--text-muted); }
        .cm-row .cm-value { color: var(--text-primary); font-weight: 600; }

        .class-actions {
            display:flex; gap: 8px; padding-top: 14px;
            border-top: 1px solid var(--border-color);
        }
        .ca-btn {
            flex: 1;
            display:inline-flex; align-items:center; justify-content:center; gap: 6px;
            padding: 9px 12px; border-radius: 9px;
            font-size: 12.5px; font-weight: 600;
            text-decoration:none; cursor:pointer;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card); color: var(--text-secondary);
            font-family: inherit; transition: var(--transition);
            white-space: nowrap;
        }
        .ca-btn:hover { border-color: var(--primary); color: var(--primary); }
        .ca-btn.view:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
        .ca-btn.del:hover { background: var(--danger); color: #fff; border-color: var(--danger); }

        /* Empty */
        .empty-block {
            background: var(--bg-alt); border: 1px dashed var(--border-color);
            border-radius: 16px; padding: 32px 24px; text-align: center;
            color: var(--text-muted); font-size: 14px;
        }
        .empty-block i { font-size: 36px; opacity: 0.35; display: block; margin-bottom: 12px; }
        .empty-block strong { color: var(--text-primary); font-weight: 700; }
        .empty-block .empty-cta { margin-top: 14px; }

        .no-levels-block {
            background: var(--warning-light); border: 1px solid rgba(245,158,11,0.35);
            border-radius: 14px; padding: 20px 22px; color: #b45309;
            display:flex; align-items:flex-start; gap: 12px; line-height: 1.6;
            font-size: 14px;
        }
        [data-theme="dark"] .no-levels-block { color: #fbbf24; }
        .no-levels-block i { font-size: 20px; flex-shrink: 0; margin-top: 2px; }
        .no-levels-block a { color: inherit; font-weight: 700; text-decoration: underline; }

        /* Overlay */
        .overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.5); z-index:90; backdrop-filter:blur(2px); }
        .overlay.show { display:block; }

        @media (max-width: 900px) {
            .sidebar { transform:translateX(-100%); }
            .sidebar.open { transform:translateX(0); box-shadow:0 0 40px rgba(0,0,0,0.2); }
            .main { margin-left:0; }
            .hamburger { display:flex; }
        }
        @media (max-width: 700px) {
            .page-header-card { padding: 22px 20px; }
            .page-header-card h2 { font-size: 19px; }
            .page-header-card p { font-size: 13.5px; }
            .header-add-btn { width: 100%; justify-content: center; }
            .classes-grid { grid-template-columns: 1fr; }
            .level-tabs { padding: 8px; }
            .level-tab { padding: 9px 12px; font-size: 12.5px; }
        }
        @media (max-width: 600px) {
            .content { padding: 20px 16px; }
            .topbar { padding: 12px 16px; }
            .page-title { font-size: 17px; }
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
                <i class="fas fa-graduation-cap nav-icon"></i> Academic Level
            </a>
            <a href="school-students.php" class="nav-item">
                <i class="fas fa-user-graduate nav-icon"></i> Students
            </a>
            <a href="school-teachers.php" class="nav-item">
                <i class="fas fa-chalkboard-teacher nav-icon"></i> Teachers
            </a>
            <a href="school-classes.php" class="nav-item active">
                <i class="fas fa-layer-group nav-icon"></i> Classes
                <?php if ($total_classes > 0): ?>
                    <span class="nav-badge"><?= $total_classes; ?></span>
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
            <a href="school-classes.php?logout=1" class="sidebar-logout" title="Logout" aria-label="Logout">
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
                    <div class="page-title">Classes</div>
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

            <!-- Header -->
            <div class="page-header-card">
                <div style="position:relative;z-index:1;">
                    <h2><i class="fas fa-layer-group"></i> Classes</h2>
                    <p>View and manage classes grouped by academic level. Click a class to edit or view its details.</p>
                </div>
                <?php if (!empty($active_levels)): ?>
                    <a href="school-add-class.php" class="header-add-btn">
                        <i class="fas fa-plus"></i> Add New Class
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($active_levels)): ?>
                <!-- No levels active at all -->
                <div class="no-levels-block">
                    <i class="fas fa-triangle-exclamation"></i>
                    <div>
                        <strong>No academic levels are active.</strong><br>
                        You must add at least one level before you can create classes.
                        Go to <a href="school-academic-levels.php">Academic Levels</a> and add a level first.
                    </div>
                </div>

            <?php else: ?>

                <!-- Mini stats -->
                <?php
                    $total_students_in_classes = array_sum($students_per_class);
                ?>
                <div class="mini-stats">
                    <div class="mini-stat">
                        <div class="ms-icon blue"><i class="fas fa-layer-group"></i></div>
                        <div class="ms-info">
                            <div class="num"><?= $total_classes; ?></div>
                            <div class="lbl">Total Classes</div>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="ms-icon green"><i class="fas fa-graduation-cap"></i></div>
                        <div class="ms-info">
                            <div class="num"><?= count($active_levels); ?></div>
                            <div class="lbl">Active Levels</div>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="ms-icon orange"><i class="fas fa-user-graduate"></i></div>
                        <div class="ms-info">
                            <div class="num"><?= $total_students_in_classes; ?></div>
                            <div class="lbl">Assigned Students</div>
                        </div>
                    </div>
                    <div class="mini-stat">
                        <div class="ms-icon purple"><i class="fas fa-chalkboard-teacher"></i></div>
                        <div class="ms-info">
                            <?php
                                $with_teacher = 0;
                                foreach ($classes_by_level as $rows) {
                                    foreach ($rows as $c) {
                                        if (!empty($c['class_teacher_id'])) $with_teacher++;
                                    }
                                }
                            ?>
                            <div class="num"><?= $with_teacher; ?></div>
                            <div class="lbl">With Teacher</div>
                        </div>
                    </div>
                </div>

                <!-- Level tabs -->
                <div class="level-tabs">
                    <a href="school-classes.php" class="level-tab <?= $current_level_key === '' ? 'active' : ''; ?>">
                        <i class="fas fa-th-large"></i> All Levels
                        <span class="tab-count"><?= $total_classes; ?></span>
                    </a>
                    <?php foreach ($active_levels as $key => $meta):
                        $count = isset($classes_by_level[$meta['enum']]) ? count($classes_by_level[$meta['enum']]) : 0;
                    ?>
                        <a href="school-classes.php?level=<?= urlencode($key); ?>"
                           class="level-tab <?= $current_level_key === $key ? 'active' : ''; ?>">
                            <i class="fas <?= $meta['icon']; ?>"></i>
                            <?= htmlspecialchars($meta['name']); ?>
                            <span class="tab-count"><?= $count; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Level sections -->
                <?php
                    $display_levels = $active_levels;
                    if ($current_level_key !== '') {
                        $display_levels = [$current_level_key => $active_levels[$current_level_key]];
                    }

                    foreach ($display_levels as $key => $meta):
                        $classes_in_level = $classes_by_level[$meta['enum']] ?? [];
                ?>
                    <div class="level-section">
                        <div class="level-section-head">
                            <div class="level-section-title">
                                <div class="ls-icon <?= $meta['color']; ?>">
                                    <i class="fas <?= $meta['icon']; ?>"></i>
                                </div>
                                <div>
                                    <h3>
                                        <?= htmlspecialchars($meta['name']); ?>
                                        <span class="badge"><?= count($classes_in_level); ?> class<?= count($classes_in_level) === 1 ? '' : 'es'; ?></span>
                                    </h3>
                                    <div class="ls-desc"><?= htmlspecialchars($meta['desc']); ?></div>
                                </div>
                            </div>
                            <a href="school-add-class.php" class="add-class-btn">
                                <i class="fas fa-plus"></i> Add Class in <?= htmlspecialchars($meta['name']); ?>
                            </a>
                        </div>

                        <?php if (count($classes_in_level) > 0): ?>
                            <div class="classes-grid">
                                <?php foreach ($classes_in_level as $c):
                                    $c_id = (int) $c['id'];
                                    $is_active = ($c['status'] ?? 'active') === 'active';
                                    $student_count = $students_per_class[$c_id] ?? 0;
                                ?>
                                    <div class="class-card <?= $is_active ? '' : 'inactive'; ?>">
                                        <div class="class-card-top">
                                            <div class="class-name"><?= htmlspecialchars($c['class_name']); ?></div>
                                            <span class="class-status <?= $is_active ? 'active' : 'inactive'; ?>">
                                                <i class="fas fa-circle"></i>
                                                <?= $is_active ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </div>

                                        <div class="class-meta">
                                            <div class="cm-row">
                                                <i class="fas fa-chalkboard-teacher"></i>
                                                <span class="cm-label">Teacher:</span>
                                                <span class="cm-value"><?= !empty($c['teacher_name']) ? htmlspecialchars($c['teacher_name']) : '—'; ?></span>
                                            </div>
                                            <div class="cm-row">
                                                <i class="fas fa-user-graduate"></i>
                                                <span class="cm-label">Students:</span>
                                                <span class="cm-value"><?= $student_count; ?></span>
                                            </div>
                                            <?php if (!empty($c['capacity'])): ?>
                                                <div class="cm-row">
                                                    <i class="fas fa-users"></i>
                                                    <span class="cm-label">Capacity:</span>
                                                    <span class="cm-value"><?= (int)$c['capacity']; ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!empty($c['room'])): ?>
                                                <div class="cm-row">
                                                    <i class="fas fa-door-open"></i>
                                                    <span class="cm-label">Room:</span>
                                                    <span class="cm-value"><?= htmlspecialchars($c['room']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="class-actions">
                                            <a href="school-class-view.php?id=<?= $c_id; ?>" class="ca-btn view" title="View">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="school-edit-class.php?id=<?= $c_id; ?>" class="ca-btn" title="Edit">
                                                <i class="fas fa-pen"></i> Edit
                                            </a>
                                            <form method="post" style="flex:1;display:flex;"
                                                  onsubmit="return confirm('Delete class “<?= htmlspecialchars(addslashes($c['class_name'])); ?>”? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete_class">
                                                <input type="hidden" name="class_id" value="<?= $c_id; ?>">
                                                <button type="submit" class="ca-btn del" title="Delete" style="width:100%;">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-block">
                                <i class="fas <?= $meta['icon']; ?>"></i>
                                <p>No classes in <strong><?= htmlspecialchars($meta['name']); ?></strong> yet.</p>
                                <div class="empty-cta">
                                    <a href="school-add-class.php" class="add-class-btn" style="display:inline-flex;">
                                        <i class="fas fa-plus"></i> Create the first class
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

            <?php endif; ?>

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
            if (theme === 'dark') { icon.classList.remove('fa-moon'); icon.classList.add('fa-sun'); }
            else { icon.classList.remove('fa-sun'); icon.classList.add('fa-moon'); }
        }
        applyTheme(localStorage.getItem('educbridge-theme') || 'light');
        toggle.addEventListener('click', function () {
            applyTheme(html.getAttribute('data-theme') === 'light' ? 'dark' : 'light');
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
</script>

</body>
</html>